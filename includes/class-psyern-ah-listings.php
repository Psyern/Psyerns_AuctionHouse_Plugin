<?php
/**
 * Listings service for Psyerns AuctionHouse.
 *
 * Owns CRUD + full-sync of the listings mirror table and serves the REST
 * handlers registered by Psyern_AH_Api for public listing browsing and for
 * per-user own-listings / own-bids lookups.
 *
 * @package Psyerns_AuctionHouse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Psyern_AH_Listings
 *
 * Handles listing-mirror persistence and read-side REST endpoints. Write-side
 * endpoints (purchase/bid/cancel) live in Psyern_AH_Pending_Actions. Listings
 * originate from the DayZ mod (PascalCase JSON) and are upserted by the
 * internal /upload handler (Agent 6). Full-sync semantics per README §13 #15:
 * every push replaces the active listing set for that seller-scope.
 */
class Psyern_AH_Listings {

	/**
	 * Option key holding the admin-maintained item → icon/metadata map.
	 *
	 * Expected shape (see README §14):
	 *   [
	 *     'default_icon_url' => 'https://...',
	 *     'items' => [
	 *       'ItemClassName' => [ 'icon_url' => '...', 'rarity' => '...' ],
	 *       ...
	 *     ],
	 *   ]
	 *
	 * Older/simpler layout is also tolerated:
	 *   [ 'ItemClassName' => 'https://...' ]
	 */
	const ITEM_MAP_OPTION = 'psyern_ah_item_map';

	/**
	 * Option key holding the category definition list.
	 *
	 * Tolerated shapes (see assumptions note at the bottom of this file):
	 *  - Flat map:    [ '1' => 'Weapons', '2' => 'Clothing' ]
	 *  - List of obj: [ [ 'id' => 1, 'label' => 'Weapons' ], ... ]
	 *  - Flat labels: [ 'Weapons', 'Clothing' ]   (id = array key)
	 */
	const CATEGORIES_OPTION = 'psyern_ah_categories';

	/**
	 * Whitelist of allowed orderby values for public queries.
	 *
	 * @var array
	 */
	protected static $allowed_orderby = array(
		'price_asc',
		'price_desc',
		'time_asc',
		'time_desc',
		'newest',
		'bid_count',
	);

	/* =====================================================================
	 * CRUD / sync
	 * ===================================================================== */

	/**
	 * Upsert a single listing row.
	 *
	 * Maps the mod-side PascalCase payload to snake_case DB columns:
	 *
	 * | JSON key (from mod) | DB column            | Notes                       |
	 * |---------------------|----------------------|-----------------------------|
	 * | ListingID           | listing_id           | string unique               |
	 * | SellerUID           | seller_uid           | string                      |
	 * | SellerName          | seller_name          | string                      |
	 * | ItemClassName       | item_class           | string                      |
	 * | ItemDisplayName     | item_display         | string                      |
	 * | CategoryID          | category_id          | int                         |
	 * | ListingType         | listing_type         | 0 BuyNow / 1 Auction / 2 Both |
	 * | StartPrice          | start_price          | bigint                      |
	 * | BuyNowPrice         | buy_now_price        | bigint                      |
	 * | CurrentBid          | current_bid          | bigint                      |
	 * | CurrentBidderUID    | current_bidder_uid   | string                      |
	 * | CurrentBidderName   | current_bidder_name  | string                      |
	 * | BidCount            | bid_count            | int                         |
	 * | CreatedTimestamp    | created_ts           | bigint (unix)               |
	 * | ExpiresTimestamp    | expires_ts           | bigint (unix)               |
	 * | Status              | status               | 0 Active / 1 Sold / 2 Exp / 3 Canc |
	 *
	 * Performs INSERT ... ON DUPLICATE KEY UPDATE keyed on listing_id (UNIQUE).
	 * Sets last_sync = current_time('mysql') on every write.
	 *
	 * @param array $payload PascalCase payload from mod.
	 * @return int|false Row id on success, false on failure or missing listing_id.
	 */
	public function upsert_listing( array $payload ) {
		global $wpdb;

		$row = $this->map_payload_to_row( $payload );

		if ( '' === $row['listing_id'] ) {
			return false;
		}

		$table = Psyern_AH_Database::get_table_name( 'listings' );

		$columns      = array_keys( $row );
		$placeholders = array();
		$values       = array();

		foreach ( $columns as $col ) {
			$placeholders[] = $this->placeholder_for_column( $col );
			$values[]       = $row[ $col ];
		}

		// Build the UPDATE clause (skip listing_id — that's the unique key).
		$update_parts = array();
		foreach ( $columns as $col ) {
			if ( 'listing_id' === $col ) {
				continue;
			}
			$update_parts[] = '`' . $col . '` = VALUES(`' . $col . '`)';
		}

		$sql = 'INSERT INTO `' . $table . '` (`' . implode( '`, `', $columns ) . '`) '
			. 'VALUES (' . implode( ', ', $placeholders ) . ') '
			. 'ON DUPLICATE KEY UPDATE ' . implode( ', ', $update_parts );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared = $wpdb->prepare( $sql, $values );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query( $prepared );

		if ( false === $result ) {
			return false;
		}

		// Fetch the row id (insert_id is 0 on pure update with no change).
		if ( ! empty( $wpdb->insert_id ) ) {
			return (int) $wpdb->insert_id;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM `' . $table . '` WHERE listing_id = %s LIMIT 1',
				$row['listing_id']
			)
		);

		return $id ? (int) $id : false;
	}

	/**
	 * Replace the full listing set (per README §13 #15 full-sync semantics).
	 *
	 * 1. Upserts every payload entry
	 * 2. Deletes every listing_id NOT in the new set
	 * 3. Empty payload → deletes ALL rows (seller uploaded empty set)
	 *
	 * Runs inside a $wpdb transaction when the storage engine supports it;
	 * falls back to sequential execution on MyISAM.
	 *
	 * @param array $listings_payload Array of PascalCase listing payloads.
	 * @return array { upserted: int, removed: int }
	 */
	public function full_sync( array $listings_payload ) {
		global $wpdb;

		$table = Psyern_AH_Database::get_table_name( 'listings' );

		$use_tx = $this->begin_transaction();

		$upserted = 0;
		$new_ids  = array();

		foreach ( $listings_payload as $payload ) {
			if ( ! is_array( $payload ) ) {
				continue;
			}
			$listing_id = isset( $payload['ListingID'] ) ? sanitize_text_field( (string) $payload['ListingID'] ) : '';
			if ( '' === $listing_id ) {
				continue;
			}
			$ok = $this->upsert_listing( $payload );
			if ( false !== $ok ) {
				++$upserted;
				$new_ids[] = $listing_id;
			}
		}

		if ( empty( $new_ids ) ) {
			// Empty payload → drop everything.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$removed = $wpdb->query( 'DELETE FROM `' . $table . '`' );
			$removed = false === $removed ? 0 : (int) $removed;
		} else {
			$placeholders = implode( ', ', array_fill( 0, count( $new_ids ), '%s' ) );
			$sql          = 'DELETE FROM `' . $table . '` WHERE listing_id NOT IN (' . $placeholders . ')';
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$prepared = $wpdb->prepare( $sql, $new_ids );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$removed = $wpdb->query( $prepared );
			$removed = false === $removed ? 0 : (int) $removed;
		}

		if ( $use_tx ) {
			$this->commit_transaction();
		}

		return array(
			'upserted' => (int) $upserted,
			'removed'  => (int) $removed,
		);
	}

	/**
	 * Look up a listing by its mod-generated listing_id string.
	 *
	 * @param string $listing_id Mod-generated listing id (e.g. "1712233412_84592").
	 * @return array|null Row plus enrichment, or null if not found.
	 */
	public function get_listing_by_id( $listing_id ) {
		global $wpdb;

		$listing_id = sanitize_text_field( (string) $listing_id );
		if ( '' === $listing_id ) {
			return null;
		}

		$table = Psyern_AH_Database::get_table_name( 'listings' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM `' . $table . '` WHERE listing_id = %s LIMIT 1', $listing_id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return $this->enrich_row( $row );
	}

	/**
	 * Look up a listing by its auto-increment PK.
	 *
	 * @param int $id Primary key.
	 * @return array|null Row plus enrichment, or null if not found.
	 */
	public function get_listing_by_numeric_id( $id ) {
		global $wpdb;

		$id = absint( $id );
		if ( 0 === $id ) {
			return null;
		}

		$table = Psyern_AH_Database::get_table_name( 'listings' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM `' . $table . '` WHERE id = %d LIMIT 1', $id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return $this->enrich_row( $row );
	}

	/* =====================================================================
	 * Query
	 * ===================================================================== */

	/**
	 * Public listing query. Applies filters, sorts, paginates, enriches rows.
	 *
	 * @param array $args {
	 *     @type int|null    $category_id   Filter by category id.
	 *     @type int|string  $listing_type  0|1|2 or 'all' (default 'all').
	 *     @type int|null    $price_min     Inclusive min price.
	 *     @type int|null    $price_max     Inclusive max price.
	 *     @type string      $search        Substring match on item_display.
	 *     @type int         $status        Default 0 (Active).
	 *     @type string      $orderby       price_asc|price_desc|time_asc|time_desc|newest|bid_count.
	 *     @type int         $page          Default 1.
	 *     @type int         $per_page      Default 20, max 100.
	 * }
	 * @return array { items, total, page, per_page, total_pages }
	 */
	public function get_listings( array $args ) {
		global $wpdb;

		/**
		 * Filter the listings query args before execution.
		 *
		 * @param array $args Incoming args.
		 */
		$args = apply_filters( 'psyerns_ah/listings_query_args', $args );

		$table = Psyern_AH_Database::get_table_name( 'listings' );

		$page     = isset( $args['page'] ) ? max( 1, absint( $args['page'] ) ) : 1;
		$per_page = isset( $args['per_page'] ) ? absint( $args['per_page'] ) : 20;
		if ( $per_page < 1 ) {
			$per_page = 20;
		}
		if ( $per_page > 100 ) {
			$per_page = 100;
		}
		$offset = ( $page - 1 ) * $per_page;

		$where  = array();
		$values = array();

		// Status (admin may override, default = Active).
		if ( isset( $args['status'] ) && '' !== $args['status'] && null !== $args['status'] ) {
			$where[]  = 'status = %d';
			$values[] = absint( $args['status'] );
		} else {
			$where[]  = 'status = %d';
			$values[] = 0;
		}

		// Category.
		if ( isset( $args['category_id'] ) && '' !== $args['category_id'] && null !== $args['category_id'] ) {
			$where[]  = 'category_id = %d';
			$values[] = absint( $args['category_id'] );
		}

		// Listing type.
		if ( isset( $args['listing_type'] ) && 'all' !== $args['listing_type'] && '' !== $args['listing_type'] && null !== $args['listing_type'] ) {
			$lt = absint( $args['listing_type'] );
			if ( $lt >= 0 && $lt <= 2 ) {
				$where[]  = 'listing_type = %d';
				$values[] = $lt;
			}
		}

		// Price range — compare against "effective price" depending on type.
		// listing_type 0 (BuyNow) → buy_now_price.
		// otherwise          → GREATEST(current_bid, start_price).
		$price_expr = 'CASE WHEN listing_type = 0 THEN buy_now_price ELSE GREATEST(current_bid, start_price) END';

		if ( isset( $args['price_min'] ) && '' !== $args['price_min'] && null !== $args['price_min'] ) {
			$where[]  = '(' . $price_expr . ') >= %d';
			$values[] = absint( $args['price_min'] );
		}
		if ( isset( $args['price_max'] ) && '' !== $args['price_max'] && null !== $args['price_max'] ) {
			$where[]  = '(' . $price_expr . ') <= %d';
			$values[] = absint( $args['price_max'] );
		}

		// Search (LIKE on item_display).
		if ( isset( $args['search'] ) && '' !== $args['search'] ) {
			$search   = sanitize_text_field( (string) $args['search'] );
			$where[]  = 'item_display LIKE %s';
			$values[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$where_sql = '';
		if ( ! empty( $where ) ) {
			$where_sql = ' WHERE ' . implode( ' AND ', $where );
		}

		// Sort.
		$orderby     = isset( $args['orderby'] ) ? (string) $args['orderby'] : 'newest';
		$orderby_sql = $this->build_orderby_sql( $orderby, $price_expr );

		// Count total.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$count_sql = 'SELECT COUNT(*) FROM `' . $table . '`' . $where_sql;
		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$count_prepared = $wpdb->prepare( $count_sql, $values );
		} else {
			$count_prepared = $count_sql;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var( $count_prepared );

		// Fetch page.
		$select_sql    = 'SELECT * FROM `' . $table . '`' . $where_sql . $orderby_sql . ' LIMIT %d OFFSET %d';
		$select_values = array_merge( $values, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$select_prepared = $wpdb->prepare( $select_sql, $select_values );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $select_prepared, ARRAY_A );

		$items = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$items[] = $this->enrich_row( $row );
			}
		}

		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 0;

		return array(
			'items'       => $items,
			'total'       => (int) $total,
			'page'        => (int) $page,
			'per_page'    => (int) $per_page,
			'total_pages' => (int) $total_pages,
		);
	}

	/* =====================================================================
	 * REST handlers
	 * ===================================================================== */

	/**
	 * GET /public/listings — marketplace browse.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function handle_get_public( WP_REST_Request $request ) {
		$args = array(
			'category_id'  => $this->request_int_or_null( $request, 'category_id' ),
			'listing_type' => $this->request_listing_type( $request ),
			'price_min'    => $this->request_int_or_null( $request, 'price_min' ),
			'price_max'    => $this->request_int_or_null( $request, 'price_max' ),
			'search'       => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			'orderby'      => $this->request_orderby( $request ),
			'page'         => max( 1, absint( $request->get_param( 'page' ) ) ),
			'per_page'     => absint( $request->get_param( 'per_page' ) ),
			'status'       => 0, // public = active only.
		);

		$result = $this->get_listings( $args );

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * GET /public/listings/{id} — single listing.
	 *
	 * The id path param can be either the numeric auto-inc PK or the
	 * mod-generated listing_id string.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function handle_get_single( WP_REST_Request $request ) {
		$raw = (string) $request->get_param( 'id' );
		$raw = sanitize_text_field( $raw );

		$listing = null;

		// Numeric first (PK) — only if the param is purely digits.
		if ( ctype_digit( $raw ) ) {
			$listing = $this->get_listing_by_numeric_id( (int) $raw );
		}

		// Fall back to listing_id string.
		if ( null === $listing ) {
			$listing = $this->get_listing_by_id( $raw );
		}

		if ( null === $listing ) {
			return new WP_REST_Response(
				array(
					'code'    => 'listing_not_found',
					'message' => __( 'Listing not found.', 'psyerns-auctionhouse' ),
				),
				404
			);
		}

		return new WP_REST_Response( $listing, 200 );
	}

	/**
	 * GET /public/categories — category list for the marketplace filter UI.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function handle_get_categories( WP_REST_Request $request ) {
		unset( $request );
		$categories = $this->get_categories_list();
		return new WP_REST_Response( $categories, 200 );
	}

	/**
	 * GET /user/listings — listings owned by the current WP user's Steam UID.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function handle_get_user_listings( WP_REST_Request $request ) {
		unset( $request );
		global $wpdb;

		$steam_uid = Psyern_AH_Auth::get_current_steam_uid();
		if ( '' === $steam_uid ) {
			return new WP_REST_Response(
				array(
					'code'    => 'steam_not_linked',
					'message' => __( 'Your WordPress account is not linked to a Steam UID.', 'psyerns-auctionhouse' ),
				),
				403
			);
		}

		$table = Psyern_AH_Database::get_table_name( 'listings' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM `' . $table . '` WHERE seller_uid = %s ORDER BY created_ts DESC',
				$steam_uid
			),
			ARRAY_A
		);

		$items = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$items[] = $this->enrich_row( $row );
			}
		}

		return new WP_REST_Response(
			array(
				'items' => $items,
				'total' => count( $items ),
			),
			200
		);
	}

	/**
	 * GET /user/bids — listings the current user has bid on, with status label.
	 *
	 * Status label logic:
	 *   - status = 0 (Active) & current_bidder_uid = me → "Führend"
	 *   - status = 0 (Active) & current_bidder_uid != me → "Überboten"
	 *   - status = 1 (Sold) & current_bidder_uid = me → "Gewonnen"
	 *   - status = 1 (Sold) & current_bidder_uid != me → "Verloren"
	 *
	 * NOTE: this returns listings where the user is CURRENT bidder. A bid
	 * history table would be needed to track rows where the user was outbid
	 * and the current bidder has since changed; that lives in a future phase
	 * (see cross-agent note in the report).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function handle_get_user_bids( WP_REST_Request $request ) {
		unset( $request );
		global $wpdb;

		$steam_uid = Psyern_AH_Auth::get_current_steam_uid();
		if ( '' === $steam_uid ) {
			return new WP_REST_Response(
				array(
					'code'    => 'steam_not_linked',
					'message' => __( 'Your WordPress account is not linked to a Steam UID.', 'psyerns-auctionhouse' ),
				),
				403
			);
		}

		$table = Psyern_AH_Database::get_table_name( 'listings' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM `' . $table . '` WHERE current_bidder_uid = %s ORDER BY expires_ts ASC',
				$steam_uid
			),
			ARRAY_A
		);

		$items = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$enriched = $this->enrich_row( $row );

				$status_code  = isset( $row['status'] ) ? (int) $row['status'] : 0;
				$is_mine      = isset( $row['current_bidder_uid'] ) && $row['current_bidder_uid'] === $steam_uid;

				if ( 1 === $status_code ) {
					$label = $is_mine
						? __( 'Gewonnen', 'psyerns-auctionhouse' )
						: __( 'Verloren', 'psyerns-auctionhouse' );
				} else {
					$label = $is_mine
						? __( 'Führend', 'psyerns-auctionhouse' )
						: __( 'Überboten', 'psyerns-auctionhouse' );
				}

				$enriched['bid_status_label'] = $label;
				$items[]                      = $enriched;
			}
		}

		return new WP_REST_Response(
			array(
				'items' => $items,
				'total' => count( $items ),
			),
			200
		);
	}

	/* =====================================================================
	 * Internal helpers
	 * ===================================================================== */

	/**
	 * Map a mod-side PascalCase payload to the snake_case DB row shape.
	 *
	 * Missing keys default to ''/0. Unknown extra keys are ignored. Strings are
	 * sanitized with sanitize_text_field(); ints via absint().
	 *
	 * @param array $payload Incoming PascalCase payload.
	 * @return array Row suitable for insert/update on the listings table.
	 */
	private function map_payload_to_row( array $payload ) {
		return array(
			'listing_id'          => isset( $payload['ListingID'] ) ? sanitize_text_field( (string) $payload['ListingID'] ) : '',
			'seller_uid'          => isset( $payload['SellerUID'] ) ? sanitize_text_field( (string) $payload['SellerUID'] ) : '',
			'seller_name'         => isset( $payload['SellerName'] ) ? sanitize_text_field( (string) $payload['SellerName'] ) : '',
			'item_class'          => isset( $payload['ItemClassName'] ) ? sanitize_text_field( (string) $payload['ItemClassName'] ) : '',
			'item_display'        => isset( $payload['ItemDisplayName'] ) ? sanitize_text_field( (string) $payload['ItemDisplayName'] ) : '',
			'category_id'         => isset( $payload['CategoryID'] ) ? absint( $payload['CategoryID'] ) : 0,
			'listing_type'        => isset( $payload['ListingType'] ) ? absint( $payload['ListingType'] ) : 0,
			'start_price'         => isset( $payload['StartPrice'] ) ? absint( $payload['StartPrice'] ) : 0,
			'buy_now_price'       => isset( $payload['BuyNowPrice'] ) ? absint( $payload['BuyNowPrice'] ) : 0,
			'current_bid'         => isset( $payload['CurrentBid'] ) ? absint( $payload['CurrentBid'] ) : 0,
			'current_bidder_uid'  => isset( $payload['CurrentBidderUID'] ) ? sanitize_text_field( (string) $payload['CurrentBidderUID'] ) : '',
			'current_bidder_name' => isset( $payload['CurrentBidderName'] ) ? sanitize_text_field( (string) $payload['CurrentBidderName'] ) : '',
			'bid_count'           => isset( $payload['BidCount'] ) ? absint( $payload['BidCount'] ) : 0,
			'created_ts'          => isset( $payload['CreatedTimestamp'] ) ? absint( $payload['CreatedTimestamp'] ) : 0,
			'expires_ts'          => isset( $payload['ExpiresTimestamp'] ) ? absint( $payload['ExpiresTimestamp'] ) : 0,
			'status'              => isset( $payload['Status'] ) ? absint( $payload['Status'] ) : 0,
			'last_sync'           => current_time( 'mysql' ),
		);
	}

	/**
	 * Return the wpdb prepare() placeholder for a given DB column.
	 *
	 * @param string $col Column name.
	 * @return string %d, %s, or %s for datetime strings.
	 */
	private function placeholder_for_column( $col ) {
		$int_cols = array(
			'category_id',
			'listing_type',
			'start_price',
			'buy_now_price',
			'current_bid',
			'bid_count',
			'created_ts',
			'expires_ts',
			'status',
		);
		if ( in_array( $col, $int_cols, true ) ) {
			return '%d';
		}
		return '%s';
	}

	/**
	 * Enrich a raw DB row with icon_url + category_label and cast numeric fields.
	 *
	 * @param array $row Raw DB row (ARRAY_A).
	 * @return array Enriched row.
	 */
	private function enrich_row( array $row ) {
		// Cast numeric columns so JSON consumers see ints, not strings.
		$int_cols = array(
			'id',
			'category_id',
			'listing_type',
			'start_price',
			'buy_now_price',
			'current_bid',
			'bid_count',
			'created_ts',
			'expires_ts',
			'status',
		);
		foreach ( $int_cols as $c ) {
			if ( array_key_exists( $c, $row ) ) {
				$row[ $c ] = (int) $row[ $c ];
			}
		}

		$row['icon_url']       = $this->resolve_icon_url( isset( $row['item_class'] ) ? (string) $row['item_class'] : '' );
		$row['category_label'] = $this->resolve_category_label( isset( $row['category_id'] ) ? (int) $row['category_id'] : 0 );

		return $row;
	}

	/**
	 * Resolve the icon URL for an item class from the item map option.
	 *
	 * @param string $item_class Item class name.
	 * @return string URL or empty string.
	 */
	private function resolve_icon_url( $item_class ) {
		if ( '' === $item_class ) {
			return '';
		}

		$map = get_option( self::ITEM_MAP_OPTION, array() );
		if ( ! is_array( $map ) ) {
			return '';
		}

		// Shape 1: nested under 'items' key with per-entry 'icon_url'.
		if ( isset( $map['items'] ) && is_array( $map['items'] ) && isset( $map['items'][ $item_class ] ) ) {
			$entry = $map['items'][ $item_class ];
			if ( is_array( $entry ) && isset( $entry['icon_url'] ) && '' !== $entry['icon_url'] ) {
				return esc_url_raw( (string) $entry['icon_url'] );
			}
			if ( is_string( $entry ) && '' !== $entry ) {
				return esc_url_raw( $entry );
			}
		}

		// Shape 2: flat map at top level.
		if ( isset( $map[ $item_class ] ) ) {
			$entry = $map[ $item_class ];
			if ( is_array( $entry ) && isset( $entry['icon_url'] ) && '' !== $entry['icon_url'] ) {
				return esc_url_raw( (string) $entry['icon_url'] );
			}
			if ( is_string( $entry ) && '' !== $entry ) {
				return esc_url_raw( $entry );
			}
		}

		// Fallback to default_icon_url if present.
		if ( isset( $map['default_icon_url'] ) && '' !== $map['default_icon_url'] ) {
			return esc_url_raw( (string) $map['default_icon_url'] );
		}

		return '';
	}

	/**
	 * Resolve a category label from its id via the categories option.
	 *
	 * @param int $category_id Category id.
	 * @return string Label (empty string if unknown).
	 */
	private function resolve_category_label( $category_id ) {
		$list = $this->get_categories_list();
		foreach ( $list as $cat ) {
			if ( isset( $cat['id'] ) && (int) $cat['id'] === (int) $category_id ) {
				return isset( $cat['label'] ) ? (string) $cat['label'] : '';
			}
		}
		return '';
	}

	/**
	 * Normalize the categories option into a list of { id, label }.
	 *
	 * Tolerates all three known storage shapes (see CATEGORIES_OPTION PHPDoc).
	 *
	 * @return array List of associative arrays with keys id, label.
	 */
	private function get_categories_list() {
		$raw = get_option( self::CATEGORIES_OPTION, array() );
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $key => $val ) {
			if ( is_array( $val ) && isset( $val['id'] ) ) {
				$out[] = array(
					'id'    => (int) $val['id'],
					'label' => isset( $val['label'] ) ? (string) $val['label'] : '',
				);
				continue;
			}
			if ( is_string( $val ) ) {
				$out[] = array(
					'id'    => is_numeric( $key ) ? (int) $key : 0,
					'label' => $val,
				);
				continue;
			}
		}
		return $out;
	}

	/**
	 * Build the ORDER BY clause from a whitelisted orderby key.
	 *
	 * @param string $orderby    Whitelisted orderby key.
	 * @param string $price_expr SQL expression for "effective price".
	 * @return string ' ORDER BY ...' fragment (always starts with a space).
	 */
	private function build_orderby_sql( $orderby, $price_expr ) {
		if ( ! in_array( $orderby, self::$allowed_orderby, true ) ) {
			$orderby = 'newest';
		}

		switch ( $orderby ) {
			case 'price_asc':
				return ' ORDER BY (' . $price_expr . ') ASC';
			case 'price_desc':
				return ' ORDER BY (' . $price_expr . ') DESC';
			case 'time_asc':
				return ' ORDER BY expires_ts ASC';
			case 'time_desc':
				return ' ORDER BY expires_ts DESC';
			case 'bid_count':
				return ' ORDER BY bid_count DESC';
			case 'newest':
			default:
				return ' ORDER BY created_ts DESC';
		}
	}

	/**
	 * Read an int request param, or null if absent/empty.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $key     Param key.
	 * @return int|null
	 */
	private function request_int_or_null( WP_REST_Request $request, $key ) {
		$val = $request->get_param( $key );
		if ( null === $val || '' === $val ) {
			return null;
		}
		return absint( $val );
	}

	/**
	 * Read the listing_type param, returning 'all' when missing/invalid.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return int|string 0|1|2 or 'all'.
	 */
	private function request_listing_type( WP_REST_Request $request ) {
		$val = $request->get_param( 'listing_type' );
		if ( null === $val || '' === $val || 'all' === $val ) {
			return 'all';
		}
		$int = absint( $val );
		if ( $int >= 0 && $int <= 2 ) {
			return $int;
		}
		return 'all';
	}

	/**
	 * Read + whitelist the orderby param.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string Whitelisted orderby key.
	 */
	private function request_orderby( WP_REST_Request $request ) {
		$val = (string) $request->get_param( 'orderby' );
		if ( in_array( $val, self::$allowed_orderby, true ) ) {
			return $val;
		}
		return 'newest';
	}

	/**
	 * Open a DB transaction if the listings table's storage engine supports it.
	 *
	 * @return bool True if a transaction was started, false on fallback.
	 */
	private function begin_transaction() {
		global $wpdb;

		$table  = Psyern_AH_Database::get_table_name( 'listings' );
		$engine = '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
				$table
			),
			ARRAY_A
		);

		if ( is_array( $row ) && isset( $row['engine'] ) ) {
			$engine = strtolower( (string) $row['engine'] );
		}

		if ( 'innodb' !== $engine ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'SET autocommit = 0' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'START TRANSACTION' );
		return true;
	}

	/**
	 * Commit the transaction started by begin_transaction() and re-enable autocommit.
	 *
	 * @return void
	 */
	private function commit_transaction() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'COMMIT' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'SET autocommit = 1' );
	}
}
