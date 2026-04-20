<?php
/**
 * Pending-Actions service for Psyerns AuctionHouse.
 *
 * Owns the async command pipeline between the WordPress frontend and the DayZ
 * mod. Handles the full state-machine:
 *
 *     queued -> dispatched -> executing -> (success | failed_*)
 *
 * Each row in {wp_prefix}psyern_ah_pending_actions represents one buy/bid/
 * cancel request created by a logged-in user (or admin). The DayZ mod polls
 * GET /internal/pending to claim queued actions atomically, executes them,
 * and then reports the result via PATCH /internal/pending/{uuid}.
 *
 * @package Psyerns_AuctionHouse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Psyern_AH_Pending_Actions
 *
 * Accepted action_type values (enqueue side):
 *   - purchase      BuyNow request
 *   - bid           Place a bid on an auction
 *   - cancel        Seller cancels own listing
 *   - admin_cancel  Admin-forced cancel (returns items to seller on mod side).
 *                   Caller (Agent 10's admin UI) is responsible for enforcing
 *                   current_user_can('manage_options'); this service trusts
 *                   whoever calls enqueue('admin_cancel', ...).
 *
 * Status vocabulary stored in the `status` column:
 *
 *   Internal (set by this class):
 *     queued              freshly inserted, waiting to be picked up
 *     dispatched          claimed by the mod via GET /internal/pending
 *     executing           optional mid-state the mod may report
 *
 *   Terminal statuses reported by PF_AH_ActionExecutor (mod side). This class
 *   stores these verbatim — it does NOT validate against a whitelist because
 *   the mod is authenticated via API-key and is the source of truth for the
 *   result code. They are listed here so Agent 10's admin filter dropdown can
 *   render them:
 *     success
 *     failed_not_enough_money
 *     failed_listing_not_found
 *     failed_listing_expired
 *     failed_bid_too_low
 *     failed_max_listings_reached
 *     failed_max_bids_reached
 *     failed_item_not_in_inventory
 *     failed_cannot_cancel_with_bids
 *     failed_own_listing
 *     failed_invalid_price
 *     failed_server_error
 *     failed_unknown_type
 *     failed_null_action
 *     failed_dme_ah_missing
 *     failed_executor_missing
 *     failed_unknown
 */
class Psyern_AH_Pending_Actions {

	/**
	 * Accepted action_type values at enqueue() time.
	 *
	 * @var string[]
	 */
	const ALLOWED_TYPES = array( 'purchase', 'bid', 'cancel', 'admin_cancel' );

	/**
	 * Rate-limit window (seconds) and max requests per window per (user, type).
	 */
	const RATE_LIMIT_WINDOW = 60;
	const RATE_LIMIT_MAX    = 10;

	/**
	 * Terminal statuses — once set, complete() is idempotent.
	 *
	 * @var string[]
	 */
	const TERMINAL_PREFIXES = array( 'success', 'failed_' );

	/**
	 * Pending-actions table name (fully prefixed).
	 *
	 * @return string
	 */
	protected function table() {
		return Psyern_AH_Database::get_table_name( 'pending_actions' );
	}

	/**
	 * Users table name (fully prefixed).
	 *
	 * @return string
	 */
	protected function users_table() {
		return Psyern_AH_Database::get_table_name( 'users' );
	}

	// ----------------------------------------------------------------------
	// Enqueue / Dispatch / Complete (core state-machine)
	// ----------------------------------------------------------------------

	/**
	 * Insert a new pending action row in "queued" state.
	 *
	 * @param string $type       One of ALLOWED_TYPES.
	 * @param string $player_uid Steam-UID of the requesting player.
	 * @param string $listing_id Mod-side listing ID the action targets.
	 * @param int    $amount     For "bid": bid amount. For "purchase":
	 *                           BuyNow price snapshot. For "cancel": 0.
	 * @param string $nonce      WP nonce that was used to submit the action,
	 *                           stored only for audit/debug.
	 * @return string|WP_Error   Freshly generated UUID, or WP_Error on invalid
	 *                           input / DB failure.
	 */
	public function enqueue( $type, $player_uid, $listing_id, $amount, $nonce ) {
		global $wpdb;

		$type       = is_string( $type ) ? trim( $type ) : '';
		$player_uid = is_string( $player_uid ) ? trim( $player_uid ) : '';
		$listing_id = is_string( $listing_id ) ? trim( $listing_id ) : '';
		$amount     = (int) $amount;
		$nonce      = is_string( $nonce ) ? substr( trim( $nonce ), 0, 64 ) : '';

		if ( ! in_array( $type, self::ALLOWED_TYPES, true ) ) {
			return new WP_Error( 'invalid_type', 'Unknown action type.' );
		}
		if ( '' === $player_uid ) {
			return new WP_Error( 'invalid_player', 'Missing player UID.' );
		}
		if ( '' === $listing_id ) {
			return new WP_Error( 'invalid_listing', 'Missing listing ID.' );
		}
		if ( $amount < 0 || $amount > ( PHP_INT_MAX / 2 ) ) {
			return new WP_Error( 'invalid_amount', 'Amount out of range.' );
		}

		$uuid = wp_generate_uuid4();
		$now  = current_time( 'mysql', 1 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert(
			$this->table(),
			array(
				'action_uuid'  => $uuid,
				'action_type'  => $type,
				'player_uid'   => $player_uid,
				'listing_id'   => $listing_id,
				'amount'       => $amount,
				'nonce'        => $nonce,
				'status'       => 'queued',
				'result_code'  => '',
				'result_message' => '',
				'created_at'   => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error( 'db_error', 'Could not insert pending action.' );
		}

		return $uuid;
	}

	/**
	 * Pull up to $limit queued actions, atomically move them to "dispatched"
	 * state, and return them in the shape README §7 /internal/pending expects.
	 *
	 * Concurrency strategy (the "MyISAM/WP-safe" pattern): we do NOT rely on
	 * transactions or SELECT ... FOR UPDATE, since MyISAM doesn't support them
	 * and InnoDB's SKIP LOCKED is not portable across the WP ecosystem. Instead:
	 *   1. Record a microsecond-precision cut-off timestamp.
	 *   2. UPDATE ... SET status='dispatched', dispatched_at=NOW() WHERE
	 *      status='queued' ORDER BY id ASC LIMIT N. The engine serializes the
	 *      write so each row is claimed exactly once.
	 *   3. SELECT the rows we just claimed by filtering on
	 *      `dispatched_at >= cut-off` AND status='dispatched'. This is race-
	 *      safe because any concurrent dispatcher would have written a later
	 *      `dispatched_at` for rows it claimed.
	 *
	 * @param int $limit Max actions to hand out. Clamped to [1, 100].
	 * @return array[]  List of dispatch payloads:
	 *                  [ { action_uuid, type, player_uid, listing_id, amount, created_at } ]
	 */
	public function dispatch_batch( $limit = 25 ) {
		global $wpdb;

		$limit = max( 1, min( 100, (int) $limit ) );
		$table = $this->table();

		// Microsecond-precision cut-off. Must be set BEFORE the UPDATE runs so
		// rows claimed by this call have dispatched_at >= $cutoff. MySQL's NOW(6)
		// returns the statement start-time with microsecond resolution.
		$cutoff = current_time( 'mysql', 1 );

		$update_sql = 'UPDATE ' . $table
			. " SET status='dispatched', dispatched_at=%s"
			. " WHERE status='queued'"
			. ' ORDER BY id ASC LIMIT %d';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$affected = $wpdb->query( $wpdb->prepare( $update_sql, $cutoff, $limit ) );

		if ( false === $affected || (int) $affected <= 0 ) {
			return array();
		}

		$select_sql = 'SELECT action_uuid, action_type, player_uid, listing_id, amount, created_at'
			. ' FROM ' . $table
			. " WHERE status='dispatched' AND dispatched_at >= %s"
			. ' ORDER BY id ASC LIMIT %d';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( $select_sql, $cutoff, $limit ),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'action_uuid' => (string) $row['action_uuid'],
				'type'        => (string) $row['action_type'], // Field mapping: DB action_type -> JSON type.
				'player_uid'  => (string) $row['player_uid'],
				'listing_id'  => (string) $row['listing_id'],
				'amount'      => (int) $row['amount'],
				'created_at'  => (string) $row['created_at'],
			);
		}

		return $out;
	}

	/**
	 * Mark a pending action as completed/failed.
	 *
	 * Idempotent: if the row is already in a terminal state (success or
	 * failed_*), the UPDATE is a no-op and the method returns true without
	 * touching the row. This protects against mod-side retries.
	 *
	 * @param string $action_uuid    UUID of the row.
	 * @param string $status         Status string reported by the mod.
	 * @param string $result_code    Short machine-readable code. Optional.
	 * @param string $result_message Human-readable message. Optional.
	 * @return bool True on success (including idempotent no-op). False if row
	 *              does not exist at all.
	 */
	public function complete( $action_uuid, $status, $result_code = '', $result_message = '' ) {
		global $wpdb;

		$action_uuid    = is_string( $action_uuid ) ? trim( $action_uuid ) : '';
		$status         = is_string( $status ) ? substr( trim( $status ), 0, 32 ) : '';
		$result_code    = is_string( $result_code ) ? substr( trim( $result_code ), 0, 32 ) : '';
		$result_message = is_string( $result_message ) ? substr( (string) $result_message, 0, 65535 ) : '';

		if ( '' === $action_uuid || '' === $status ) {
			return false;
		}

		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, status FROM ' . $table . ' WHERE action_uuid = %s LIMIT 1',
				$action_uuid
			),
			ARRAY_A
		);

		if ( empty( $existing ) ) {
			return false;
		}

		$current = isset( $existing['status'] ) ? (string) $existing['status'] : '';
		if ( $this->is_terminal( $current ) ) {
			return true;
		}

		$set_completed_at = $this->is_terminal( $status );
		$now              = current_time( 'mysql', 1 );

		if ( $set_completed_at ) {
			$sql    = 'UPDATE ' . $table
				. ' SET status = %s, result_code = %s, result_message = %s, completed_at = %s'
				. ' WHERE action_uuid = %s';
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->query(
				$wpdb->prepare( $sql, $status, $result_code, $result_message, $now, $action_uuid )
			);
		} else {
			$sql    = 'UPDATE ' . $table
				. ' SET status = %s, result_code = %s, result_message = %s'
				. ' WHERE action_uuid = %s';
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->query(
				$wpdb->prepare( $sql, $status, $result_code, $result_message, $action_uuid )
			);
		}

		return false !== $result;
	}

	/**
	 * Check whether a status string represents a terminal state.
	 *
	 * @param string $status Status string.
	 * @return bool
	 */
	protected function is_terminal( $status ) {
		if ( 'success' === $status ) {
			return true;
		}
		if ( 0 === strpos( $status, 'failed_' ) || 'failed' === $status ) {
			return true;
		}
		return false;
	}

	// ----------------------------------------------------------------------
	// Internal helpers: steam-UID lookup, rate-limit, listing validation
	// ----------------------------------------------------------------------

	/**
	 * Sliding-window rate-limit using WordPress transients.
	 *
	 * Key: psyern_ah_rl_{wp_user_id}_{action_type}.
	 * Increments a counter; returns false when the counter exceeds
	 * RATE_LIMIT_MAX in a RATE_LIMIT_WINDOW window.
	 *
	 * @param int    $wp_user_id WordPress user ID.
	 * @param string $action_type One of ALLOWED_TYPES.
	 * @return bool True if allowed, false if rate-limited.
	 */
	private function rate_limit_check( $wp_user_id, $action_type ) {
		$wp_user_id  = (int) $wp_user_id;
		$action_type = preg_replace( '/[^a-z_]/', '', (string) $action_type );

		if ( $wp_user_id <= 0 || '' === $action_type ) {
			return false;
		}

		$key   = 'psyern_ah_rl_' . $wp_user_id . '_' . $action_type;
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT_MAX ) {
			return false;
		}

		++$count;
		set_transient( $key, $count, self::RATE_LIMIT_WINDOW );
		return true;
	}

	/**
	 * Fetch the current listing row and validate it is active + not expired.
	 * Also guards the dependency on Psyern_AH_Listings (built by Agent 4).
	 *
	 * @param string $listing_id Mod-side listing ID.
	 * @return array|WP_Error Listing row on success, WP_Error on failure.
	 */
	private function validate_listing_active_buyable( $listing_id ) {
		if ( ! class_exists( 'Psyern_AH_Listings' ) ) {
			return new WP_Error( 'listings_service_missing', 'Listings service unavailable.' );
		}

		$svc = new Psyern_AH_Listings();

		if ( ! method_exists( $svc, 'get_listing_by_id' ) ) {
			return new WP_Error( 'listings_service_missing', 'Listings service missing get_listing_by_id.' );
		}

		$listing = $svc->get_listing_by_id( $listing_id );
		if ( empty( $listing ) || ! is_array( $listing ) ) {
			return new WP_Error( 'listing_not_found', 'Listing not found.' );
		}

		$status = isset( $listing['status'] ) ? (int) $listing['status'] : -1;
		if ( 0 !== $status ) {
			return new WP_Error( 'listing_not_active', 'Listing is not active.' );
		}

		$expires = isset( $listing['expires_ts'] ) ? (int) $listing['expires_ts'] : 0;
		if ( $expires > 0 && $expires <= time() ) {
			return new WP_Error( 'listing_expired', 'Listing has expired.' );
		}

		return $listing;
	}

	// ----------------------------------------------------------------------
	// REST handlers — /user/*
	// ----------------------------------------------------------------------

	/**
	 * POST /user/purchase — enqueue a BuyNow action.
	 *
	 * Body JSON: { nonce, listing_id, expected_price }
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function handle_enqueue_purchase( WP_REST_Request $request ) {
		$auth = $this->require_linked_user();
		if ( $auth instanceof WP_REST_Response ) {
			return $auth;
		}
		list( $wp_user_id, $steam_uid ) = $auth;

		$params = $this->read_body( $request );
		$nonce  = isset( $params['nonce'] ) ? (string) $params['nonce'] : '';

		if ( ! wp_verify_nonce( $nonce, 'psyern-ah-purchase' ) ) {
			return $this->error_response( 'invalid_nonce', 'Security check failed.', 403 );
		}

		if ( ! $this->rate_limit_check( $wp_user_id, 'purchase' ) ) {
			return $this->rate_limited();
		}

		$listing_id     = isset( $params['listing_id'] ) ? sanitize_text_field( (string) $params['listing_id'] ) : '';
		$expected_price = isset( $params['expected_price'] ) ? (int) $params['expected_price'] : -1;

		if ( '' === $listing_id ) {
			return $this->error_response( 'missing_listing_id', 'Missing listing_id.', 400 );
		}
		if ( $expected_price < 0 || $expected_price > ( PHP_INT_MAX / 2 ) ) {
			return $this->error_response( 'invalid_expected_price', 'expected_price out of range.', 400 );
		}

		$listing = $this->validate_listing_active_buyable( $listing_id );
		if ( $listing instanceof WP_Error ) {
			return $this->error_from_wp_error( $listing, 409 );
		}

		$listing_type  = isset( $listing['listing_type'] ) ? (int) $listing['listing_type'] : -1;
		$buy_now_price = isset( $listing['buy_now_price'] ) ? (int) $listing['buy_now_price'] : 0;

		// BuyNow requires listing_type 0 (BuyNow) or 2 (AuctionWithBuyNow).
		if ( 0 !== $listing_type && 2 !== $listing_type ) {
			return $this->error_response( 'not_buyable', 'Listing does not support BuyNow.', 409 );
		}
		if ( $buy_now_price <= 0 ) {
			return $this->error_response( 'no_buy_now_price', 'Listing has no BuyNow price.', 409 );
		}

		// Stale-price guard.
		if ( $buy_now_price !== $expected_price ) {
			return new WP_REST_Response(
				array(
					'error'    => 'price_mismatch',
					'current'  => $buy_now_price,
					'expected' => $expected_price,
				),
				409
			);
		}

		// Don't let players buy their own listings at the REST layer either
		// (the mod will also reject this, but fail fast).
		$seller_uid = isset( $listing['seller_uid'] ) ? (string) $listing['seller_uid'] : '';
		if ( '' !== $seller_uid && $seller_uid === $steam_uid ) {
			return $this->error_response( 'own_listing', 'Cannot purchase your own listing.', 409 );
		}

		$uuid = $this->enqueue( 'purchase', $steam_uid, $listing_id, $expected_price, $nonce );
		if ( $uuid instanceof WP_Error ) {
			return $this->error_from_wp_error( $uuid, 400 );
		}

		return new WP_REST_Response(
			array(
				'action_uuid' => $uuid,
				'status'      => 'queued',
			),
			202
		);
	}

	/**
	 * POST /user/bid — enqueue a Bid action.
	 *
	 * Body JSON: { nonce, listing_id, amount }
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function handle_enqueue_bid( WP_REST_Request $request ) {
		$auth = $this->require_linked_user();
		if ( $auth instanceof WP_REST_Response ) {
			return $auth;
		}
		list( $wp_user_id, $steam_uid ) = $auth;

		$params = $this->read_body( $request );
		$nonce  = isset( $params['nonce'] ) ? (string) $params['nonce'] : '';

		if ( ! wp_verify_nonce( $nonce, 'psyern-ah-bid' ) ) {
			return $this->error_response( 'invalid_nonce', 'Security check failed.', 403 );
		}

		if ( ! $this->rate_limit_check( $wp_user_id, 'bid' ) ) {
			return $this->rate_limited();
		}

		$listing_id = isset( $params['listing_id'] ) ? sanitize_text_field( (string) $params['listing_id'] ) : '';
		$amount     = isset( $params['amount'] ) ? (int) $params['amount'] : -1;

		if ( '' === $listing_id ) {
			return $this->error_response( 'missing_listing_id', 'Missing listing_id.', 400 );
		}
		if ( $amount < 0 || $amount > ( PHP_INT_MAX / 2 ) ) {
			return $this->error_response( 'invalid_amount', 'amount out of range.', 400 );
		}

		$listing = $this->validate_listing_active_buyable( $listing_id );
		if ( $listing instanceof WP_Error ) {
			return $this->error_from_wp_error( $listing, 409 );
		}

		$listing_type = isset( $listing['listing_type'] ) ? (int) $listing['listing_type'] : -1;
		if ( 1 !== $listing_type && 2 !== $listing_type ) {
			return $this->error_response( 'not_auction', 'Listing does not accept bids.', 409 );
		}

		$start_price = isset( $listing['start_price'] ) ? (int) $listing['start_price'] : 0;
		$current_bid = isset( $listing['current_bid'] ) ? (int) $listing['current_bid'] : 0;
		$min_bid     = max( $current_bid + 1, $start_price );

		if ( $amount < $min_bid ) {
			return new WP_REST_Response(
				array(
					'error' => 'bid_too_low',
					'min'   => $min_bid,
				),
				400
			);
		}

		$seller_uid = isset( $listing['seller_uid'] ) ? (string) $listing['seller_uid'] : '';
		if ( '' !== $seller_uid && $seller_uid === $steam_uid ) {
			return $this->error_response( 'own_listing', 'Cannot bid on your own listing.', 409 );
		}

		$uuid = $this->enqueue( 'bid', $steam_uid, $listing_id, $amount, $nonce );
		if ( $uuid instanceof WP_Error ) {
			return $this->error_from_wp_error( $uuid, 400 );
		}

		return new WP_REST_Response(
			array(
				'action_uuid' => $uuid,
				'status'      => 'queued',
			),
			202
		);
	}

	/**
	 * POST /user/cancel — seller cancels own listing.
	 *
	 * Body JSON: { nonce, listing_id }
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function handle_enqueue_cancel( WP_REST_Request $request ) {
		$auth = $this->require_linked_user();
		if ( $auth instanceof WP_REST_Response ) {
			return $auth;
		}
		list( $wp_user_id, $steam_uid ) = $auth;

		$params = $this->read_body( $request );
		$nonce  = isset( $params['nonce'] ) ? (string) $params['nonce'] : '';

		if ( ! wp_verify_nonce( $nonce, 'psyern-ah-cancel' ) ) {
			return $this->error_response( 'invalid_nonce', 'Security check failed.', 403 );
		}

		if ( ! $this->rate_limit_check( $wp_user_id, 'cancel' ) ) {
			return $this->rate_limited();
		}

		$listing_id = isset( $params['listing_id'] ) ? sanitize_text_field( (string) $params['listing_id'] ) : '';
		if ( '' === $listing_id ) {
			return $this->error_response( 'missing_listing_id', 'Missing listing_id.', 400 );
		}

		$listing = $this->validate_listing_active_buyable( $listing_id );
		if ( $listing instanceof WP_Error ) {
			return $this->error_from_wp_error( $listing, 409 );
		}

		$seller_uid = isset( $listing['seller_uid'] ) ? (string) $listing['seller_uid'] : '';
		if ( $seller_uid !== $steam_uid ) {
			return $this->error_response( 'not_owner', 'You do not own this listing.', 403 );
		}

		$uuid = $this->enqueue( 'cancel', $steam_uid, $listing_id, 0, $nonce );
		if ( $uuid instanceof WP_Error ) {
			return $this->error_from_wp_error( $uuid, 400 );
		}

		return new WP_REST_Response(
			array(
				'action_uuid' => $uuid,
				'status'      => 'queued',
			),
			202
		);
	}

	/**
	 * GET /user/me — return current user's Steam profile, balances, recent
	 * pending actions, and fresh action-specific nonces so the JS client can
	 * refresh its token without a full page reload.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_user_me( WP_REST_Request $request ) {
		global $wpdb;

		$wp_user_id = (int) get_current_user_id();
		if ( $wp_user_id <= 0 ) {
			return $this->error_response( 'not_logged_in', 'Login required.', 401 );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$user_row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT wp_user_id, steam_uid, steam_name, avatar_url, linked_at, last_login FROM '
					. $this->users_table() . ' WHERE wp_user_id = %d LIMIT 1',
				$wp_user_id
			),
			ARRAY_A
		);

		$steam_uid  = ! empty( $user_row['steam_uid'] ) ? (string) $user_row['steam_uid'] : '';
		$steam_name = ! empty( $user_row['steam_name'] ) ? (string) $user_row['steam_name'] : '';
		$avatar_url = ! empty( $user_row['avatar_url'] ) ? (string) $user_row['avatar_url'] : '';

		$balances = array();
		if ( '' !== $steam_uid && class_exists( 'Psyern_AH_Balances' ) ) {
			$bal_svc  = new Psyern_AH_Balances();
			$balances = $bal_svc->get_all_balances_for_uid( $steam_uid );
		}

		$pending = array();
		if ( '' !== $steam_uid ) {
			$table = $this->table();
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT action_uuid, action_type, listing_id, amount, status, result_code, result_message,'
						. ' created_at, dispatched_at, completed_at FROM ' . $table
						. ' WHERE player_uid = %s ORDER BY id DESC LIMIT 10',
					$steam_uid
				),
				ARRAY_A
			);
			if ( ! empty( $rows ) ) {
				foreach ( $rows as $row ) {
					$pending[] = array(
						'action_uuid'    => (string) $row['action_uuid'],
						'type'           => (string) $row['action_type'],
						'listing_id'     => (string) $row['listing_id'],
						'amount'         => (int) $row['amount'],
						'status'         => (string) $row['status'],
						'result_code'    => (string) $row['result_code'],
						'result_message' => (string) $row['result_message'],
						'created_at'     => (string) $row['created_at'],
						'dispatched_at'  => isset( $row['dispatched_at'] ) ? (string) $row['dispatched_at'] : '',
						'completed_at'   => isset( $row['completed_at'] ) ? (string) $row['completed_at'] : '',
					);
				}
			}
		}

		$response = array(
			'wp_user_id'      => $wp_user_id,
			'steam_uid'       => $steam_uid,
			'steam_name'      => $steam_name,
			'avatar_url'      => $avatar_url,
			'balances'        => (object) $balances,
			'pending_actions' => $pending,
			'nonces'          => array(
				'purchase' => wp_create_nonce( 'psyern-ah-purchase' ),
				'bid'      => wp_create_nonce( 'psyern-ah-bid' ),
				'cancel'   => wp_create_nonce( 'psyern-ah-cancel' ),
			),
		);

		return new WP_REST_Response( $response, 200 );
	}

	// ----------------------------------------------------------------------
	// REST handlers — /internal/*
	// ----------------------------------------------------------------------

	/**
	 * GET /internal/pending — mod polls for queued actions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_dispatch( WP_REST_Request $request ) {
		$limit = $request->get_param( 'limit' );
		$limit = null === $limit ? 25 : (int) $limit;
		$limit = max( 1, min( 100, $limit ) );

		$actions = $this->dispatch_batch( $limit );

		return new WP_REST_Response(
			array( 'actions' => $actions ),
			200
		);
	}

	/**
	 * POST/PATCH /internal/pending/{uuid} — mod reports the result of an action.
	 *
	 * Dual-verb guard: the DayZ engine only supports POST, so a POST is
	 * accepted when its JSON body carries a `_method` marker equal to "PATCH".
	 * PATCH is accepted directly for curl / admin tooling.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_complete( WP_REST_Request $request ) {
		$uuid   = sanitize_text_field( (string) $request->get_param( 'uuid' ) );
		$params = $this->read_body( $request );
		$method = strtoupper( (string) $request->get_method() );

		if ( 'PATCH' !== $method ) {
			$marker = isset( $params['_method'] ) ? strtoupper( (string) $params['_method'] ) : '';
			if ( 'POST' === $method ) {
				if ( 'PATCH' !== $marker ) {
					return new WP_REST_Response(
						array(
							'error' => 'missing_method_override',
							'hint'  => 'POST requires _method=PATCH marker',
						),
						400
					);
				}
			} else {
				return $this->error_response( 'method_not_allowed', 'Method not allowed.', 405 );
			}
		}

		if ( '' === $uuid ) {
			return $this->error_response( 'missing_uuid', 'Missing uuid.', 400 );
		}

		$status  = isset( $params['status'] ) ? sanitize_text_field( (string) $params['status'] ) : '';
		$code    = isset( $params['result_code'] ) ? sanitize_text_field( (string) $params['result_code'] ) : '';
		$message = isset( $params['result_message'] ) ? wp_kses_post( (string) $params['result_message'] ) : '';

		if ( '' === $status ) {
			return $this->error_response( 'missing_status', 'Missing status.', 400 );
		}

		$ok = $this->complete( $uuid, $status, $code, $message );
		if ( ! $ok ) {
			return $this->error_response( 'unknown_uuid', 'Unknown action uuid.', 404 );
		}

		return new WP_REST_Response(
			array(
				'ok'   => true,
				'uuid' => $uuid,
			),
			200
		);
	}

	// ----------------------------------------------------------------------
	// Small helpers
	// ----------------------------------------------------------------------

	/**
	 * Read the request body. Prefers JSON params, falls back to form params.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	private function read_body( WP_REST_Request $request ) {
		$json = $request->get_json_params();
		if ( is_array( $json ) && ! empty( $json ) ) {
			return $json;
		}
		$any = $request->get_params();
		return is_array( $any ) ? $any : array();
	}

	/**
	 * Defense-in-depth: re-assert that the user is logged in and Steam-linked.
	 * Returns [ wp_user_id, steam_uid ] tuple on success, or a WP_REST_Response
	 * error to short-circuit.
	 *
	 * @return array|WP_REST_Response
	 */
	private function require_linked_user() {
		$wp_user_id = (int) get_current_user_id();
		if ( $wp_user_id <= 0 ) {
			return $this->error_response( 'not_logged_in', 'Login required.', 401 );
		}
		$steam_uid = Psyern_AH_Auth::get_current_steam_uid();
		if ( '' === $steam_uid ) {
			return $this->error_response( 'not_linked', 'Steam account not linked.', 403 );
		}
		return array( $wp_user_id, $steam_uid );
	}

	/**
	 * Build a rate-limited response with the correct headers.
	 *
	 * @return WP_REST_Response
	 */
	private function rate_limited() {
		$response = new WP_REST_Response(
			array(
				'error'       => 'rate_limited',
				'retry_after' => self::RATE_LIMIT_WINDOW,
			),
			429
		);
		$response->header( 'Retry-After', (string) self::RATE_LIMIT_WINDOW );
		return $response;
	}

	/**
	 * Short-hand to build a JSON error response.
	 *
	 * @param string $code Machine-readable error code.
	 * @param string $msg  Human-readable description.
	 * @param int    $http HTTP status code.
	 * @return WP_REST_Response
	 */
	private function error_response( $code, $msg, $http ) {
		return new WP_REST_Response(
			array(
				'error'   => $code,
				'message' => $msg,
			),
			(int) $http
		);
	}

	/**
	 * Convert a WP_Error into a REST error response.
	 *
	 * @param WP_Error $err   Source error.
	 * @param int      $http  Default HTTP status.
	 * @return WP_REST_Response
	 */
	private function error_from_wp_error( WP_Error $err, $http ) {
		return new WP_REST_Response(
			array(
				'error'   => $err->get_error_code(),
				'message' => $err->get_error_message(),
			),
			(int) $http
		);
	}
}
