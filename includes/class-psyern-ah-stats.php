<?php
/**
 * Stats + Price-History service for Psyerns AuctionHouse.
 *
 * Handles the /public/stats and /public/price-history REST endpoints. All
 * aggregations run against the `psyern_ah_transactions` table and are
 * cached via Transients with a 5-minute TTL.
 *
 * @package Psyerns_AuctionHouse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Psyern_AH_Stats
 *
 * Aggregation rules:
 *   - Only real sales count towards stats (type IN (0,1) — BuyNow & AuctionWon).
 *     Expired (2) and Cancelled (3) transactions are excluded.
 *   - Period filter is applied on the unix `timestamp` column (bigint),
 *     never via MySQL DATE() functions — the column is indexed as raw int.
 *   - Bucket size in price-history SQL is injected from a strict whitelist,
 *     never from user input.
 */
class Psyern_AH_Stats {

	/**
	 * Transient TTL for cached stats / price-history responses (seconds).
	 */
	const CACHE_TTL = 300;

	/**
	 * Period whitelist used by every public entry-point.
	 *
	 * @var string[]
	 */
	const PERIODS = array( '24h', '7d', '30d', 'all' );

	/**
	 * Section whitelist for handle_get_stats().
	 *
	 * @var string[]
	 */
	const SECTIONS = array( 'all', 'top_sellers', 'popular', 'avg_prices' );

	/**
	 * Get the transactions table name (fully prefixed).
	 *
	 * @return string
	 */
	protected function table() {
		return Psyern_AH_Database::get_table_name( 'transactions' );
	}

	/**
	 * Top sellers by total revenue within a period.
	 *
	 * @param string $period 24h|7d|30d|all.
	 * @param int    $limit  Row limit.
	 * @return array[]
	 */
	public function get_top_sellers( $period, $limit = 10 ) {
		global $wpdb;

		$limit = max( 1, min( 100, (int) $limit ) );
		$table = $this->table();

		$where_ts = $this->where_time_clause( $period );

		$sql = 'SELECT seller_uid, MAX(seller_name) AS seller_name, SUM(final_price) AS total, COUNT(*) AS sales'
			. ' FROM ' . $table
			. ' WHERE type IN (0,1)' . $where_ts['sql']
			. ' AND seller_uid <> \'\''
			. ' GROUP BY seller_uid'
			. ' ORDER BY total DESC'
			. ' LIMIT %d';

		$args   = array_merge( $where_ts['args'], array( $limit ) );
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		$result = array();

		if ( ! is_array( $rows ) ) {
			return $result;
		}

		foreach ( $rows as $row ) {
			$result[] = array(
				'seller_uid'  => (string) $row['seller_uid'],
				'seller_name' => (string) $row['seller_name'],
				'total'       => (int) $row['total'],
				'sales'       => (int) $row['sales'],
			);
		}

		return $result;
	}

	/**
	 * Most popular items within a period (by sale count).
	 *
	 * @param string $period 24h|7d|30d|all.
	 * @param int    $limit  Row limit.
	 * @return array[]
	 */
	public function get_popular_items( $period, $limit = 10 ) {
		global $wpdb;

		$limit = max( 1, min( 100, (int) $limit ) );
		$table = $this->table();

		$where_ts = $this->where_time_clause( $period );

		$sql = 'SELECT item_class, MAX(item_display) AS item_display, COUNT(*) AS sales, AVG(final_price) AS avg_price'
			. ' FROM ' . $table
			. ' WHERE type IN (0,1)' . $where_ts['sql']
			. ' AND item_class <> \'\''
			. ' GROUP BY item_class'
			. ' ORDER BY sales DESC, avg_price DESC'
			. ' LIMIT %d';

		$args = array_merge( $where_ts['args'], array( $limit ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		$out  = array();

		if ( ! is_array( $rows ) ) {
			return $out;
		}

		foreach ( $rows as $row ) {
			$out[] = array(
				'item_class'   => (string) $row['item_class'],
				'item_display' => (string) $row['item_display'],
				'sales'        => (int) $row['sales'],
				'avg_price'    => (int) round( (float) $row['avg_price'] ),
			);
		}

		return $out;
	}

	/**
	 * Average / min / max prices per item within a period.
	 *
	 * @param string $period 24h|7d|30d|all.
	 * @param int    $limit  Row limit.
	 * @return array[]
	 */
	public function get_avg_prices( $period, $limit = 20 ) {
		global $wpdb;

		$limit = max( 1, min( 200, (int) $limit ) );
		$table = $this->table();

		$where_ts = $this->where_time_clause( $period );

		$sql = 'SELECT item_class, MAX(item_display) AS item_display,'
			. ' MIN(final_price) AS min_price, MAX(final_price) AS max_price,'
			. ' AVG(final_price) AS avg_price, COUNT(*) AS sales'
			. ' FROM ' . $table
			. ' WHERE type IN (0,1)' . $where_ts['sql']
			. ' AND item_class <> \'\''
			. ' GROUP BY item_class'
			. ' ORDER BY sales DESC, avg_price DESC'
			. ' LIMIT %d';

		$args = array_merge( $where_ts['args'], array( $limit ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		$out  = array();

		if ( ! is_array( $rows ) ) {
			return $out;
		}

		foreach ( $rows as $row ) {
			$out[] = array(
				'item_class'   => (string) $row['item_class'],
				'item_display' => (string) $row['item_display'],
				'min_price'    => (int) $row['min_price'],
				'max_price'    => (int) $row['max_price'],
				'avg_price'    => (int) round( (float) $row['avg_price'] ),
				'sales'        => (int) $row['sales'],
			);
		}

		return $out;
	}

	/**
	 * Bucketed price history for one item class.
	 *
	 * Period → bucket scheme:
	 *   24h  → 24 hourly buckets (bucket size 3600 s)
	 *   7d   → 168 hourly buckets (bucket size 3600 s)
	 *   30d  → 30 daily buckets (bucket size 86400 s)
	 *   all  → up to 52 most-recent weekly buckets (bucket size 604800 s)
	 *
	 * SQL bucket-floor: FLOOR(timestamp / {bucket_size}) * {bucket_size}.
	 * `{bucket_size}` is injected from the strict whitelist in
	 * period_bucket_size() — never from user input.
	 *
	 * Missing buckets are NOT zero-filled; the frontend renders gaps.
	 *
	 * @param string $item_class Item class (already sanitized).
	 * @param string $period     24h|7d|30d|all.
	 * @return array[] List of { bucket_ts, avg_price, min_price, max_price, sale_count }.
	 */
	public function get_price_history( $item_class, $period ) {
		global $wpdb;

		$item_class = (string) $item_class;
		if ( '' === $item_class ) {
			return array();
		}

		$bucket_size = $this->period_bucket_size( $period );
		if ( $bucket_size <= 0 ) {
			return array();
		}

		$table    = $this->table();
		$where_ts = $this->where_time_clause( $period );

		// bucket_size comes from the whitelist (period_bucket_size) — safe to inject.
		$sql = 'SELECT FLOOR(`timestamp` / ' . (int) $bucket_size . ') * ' . (int) $bucket_size . ' AS bucket_ts,'
			. ' AVG(final_price) AS avg_price,'
			. ' MIN(final_price) AS min_price,'
			. ' MAX(final_price) AS max_price,'
			. ' COUNT(*) AS sale_count'
			. ' FROM ' . $table
			. ' WHERE type IN (0,1)'
			. ' AND item_class = %s'
			. $where_ts['sql']
			. ' GROUP BY bucket_ts'
			. ' ORDER BY bucket_ts ASC';

		$args = array_merge( array( $item_class ), $where_ts['args'] );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		// For 'all' cap to 52 most recent weekly buckets.
		if ( 'all' === $period && count( $rows ) > 52 ) {
			$rows = array_slice( $rows, -52 );
		}

		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'bucket_ts'  => (int) $row['bucket_ts'],
				'avg_price'  => (int) round( (float) $row['avg_price'] ),
				'min_price'  => (int) $row['min_price'],
				'max_price'  => (int) $row['max_price'],
				'sale_count' => (int) $row['sale_count'],
			);
		}

		return $out;
	}

	/**
	 * Invalidate price-history transients for a list of item classes.
	 *
	 * Called by Psyern_AH_Transactions::add_transactions() after a successful
	 * INSERT. Iterates the explicit period whitelist to avoid LIKE-style
	 * key searches (WordPress transients don't support wildcards safely on
	 * all object caches).
	 *
	 * @param string[] $item_classes Item class names.
	 * @return void
	 */
	public function invalidate_price_history_cache( array $item_classes ) {
		if ( empty( $item_classes ) ) {
			return;
		}

		foreach ( $item_classes as $class ) {
			$key = $this->item_class_cache_key( (string) $class );
			if ( '' === $key ) {
				continue;
			}
			foreach ( self::PERIODS as $period ) {
				delete_transient( 'psyern_ah_ph_' . $key . '_' . $period );
			}
		}
	}

	/**
	 * REST: GET /public/stats.
	 *
	 * Query params:
	 *   period  (24h|7d|30d|all, default 7d)
	 *   section (all|top_sellers|popular|avg_prices, default all)
	 *
	 * Response: { top_sellers, popular_items, avg_prices, period, generated_at }.
	 * Sections not requested are returned as empty arrays.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_get_stats( WP_REST_Request $request ) {
		$period_in = (string) $request->get_param( 'period' );
		if ( '' === $period_in ) {
			$period_in = '7d';
		}

		$period = $this->validate_period( $period_in );
		if ( is_wp_error( $period ) ) {
			return $this->wp_error_response( $period );
		}

		$section = (string) $request->get_param( 'section' );
		if ( '' === $section ) {
			$section = 'all';
		}
		if ( ! in_array( $section, self::SECTIONS, true ) ) {
			return $this->wp_error_response(
				new WP_Error(
					'invalid_section',
					__( 'Invalid section parameter.', 'psyerns-auctionhouse' ),
					array( 'status' => 400 )
				)
			);
		}

		$cache_key = 'psyern_ah_stats_' . $section . '_' . $period;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return new WP_REST_Response( $cached, 200 );
		}

		$payload = array(
			'top_sellers'   => array(),
			'popular_items' => array(),
			'avg_prices'    => array(),
			'period'        => $period,
			'generated_at'  => gmdate( 'c' ),
		);

		if ( 'all' === $section || 'top_sellers' === $section ) {
			$payload['top_sellers'] = $this->get_top_sellers( $period );
		}
		if ( 'all' === $section || 'popular' === $section ) {
			$payload['popular_items'] = $this->get_popular_items( $period );
		}
		if ( 'all' === $section || 'avg_prices' === $section ) {
			$payload['avg_prices'] = $this->get_avg_prices( $period );
		}

		set_transient( $cache_key, $payload, self::CACHE_TTL );

		return new WP_REST_Response( $payload, 200 );
	}

	/**
	 * REST: GET /public/price-history.
	 *
	 * Query params: item_class (required), period (24h|7d|30d|all, default 30d).
	 * Response: { item_class, period, buckets, generated_at }.
	 *
	 * Cache key sanitization: item_class is passed through sanitize_key() with
	 * dots replaced by underscores (DayZ classnames contain no dots in practice
	 * but we defend against unusual admin-supplied aliases). The sanitized key
	 * is used ONLY for the cache entry — the raw class is what hits SQL, via
	 * $wpdb->prepare() with %s.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_get_price_history( WP_REST_Request $request ) {
		$item_class_raw = sanitize_text_field( (string) $request->get_param( 'item_class' ) );
		if ( '' === $item_class_raw ) {
			return $this->wp_error_response(
				new WP_Error(
					'missing_item_class',
					__( 'item_class parameter is required.', 'psyerns-auctionhouse' ),
					array( 'status' => 400 )
				)
			);
		}

		$period_in = (string) $request->get_param( 'period' );
		if ( '' === $period_in ) {
			$period_in = '30d';
		}
		$period = $this->validate_period( $period_in );
		if ( is_wp_error( $period ) ) {
			return $this->wp_error_response( $period );
		}

		$cache_key_class = $this->item_class_cache_key( $item_class_raw );
		$cache_key       = 'psyern_ah_ph_' . $cache_key_class . '_' . $period;

		$cached = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return new WP_REST_Response( $cached, 200 );
		}

		$buckets = $this->get_price_history( $item_class_raw, $period );

		$payload = array(
			'item_class'   => $item_class_raw,
			'period'       => $period,
			'buckets'      => $buckets,
			'generated_at' => gmdate( 'c' ),
		);

		set_transient( $cache_key, $payload, self::CACHE_TTL );

		return new WP_REST_Response( $payload, 200 );
	}

	/**
	 * Period → seconds (whitelist). "all" returns 0 (no filter).
	 *
	 * @param string $period Period.
	 * @return int Seconds.
	 */
	private function period_to_seconds( $period ) {
		switch ( (string) $period ) {
			case '24h':
				return 86400;
			case '7d':
				return 604800;
			case '30d':
				return 2592000;
			case 'all':
				return 0;
		}
		return 0;
	}

	/**
	 * Period → bucket size in seconds (whitelist).
	 *
	 * @param string $period Period.
	 * @return int Bucket size in seconds (0 on bad input).
	 */
	private function period_bucket_size( $period ) {
		switch ( (string) $period ) {
			case '24h':
				return 3600;
			case '7d':
				return 3600;
			case '30d':
				return 86400;
			case 'all':
				return 604800;
		}
		return 0;
	}

	/**
	 * Validate a period string against the whitelist.
	 *
	 * @param string $period Period.
	 * @return string|WP_Error Period on success, WP_Error(400) on invalid.
	 */
	private function validate_period( $period ) {
		if ( in_array( (string) $period, self::PERIODS, true ) ) {
			return (string) $period;
		}
		return new WP_Error(
			'invalid_period',
			__( 'Invalid period. Allowed: 24h, 7d, 30d, all.', 'psyerns-auctionhouse' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Build the optional " AND timestamp >= ..." SQL fragment + args for a period.
	 *
	 * Returns an array { sql: string, args: int[] } — sql prefixed with a space
	 * so it can be concatenated directly after a WHERE clause. args must be
	 * merged into the $wpdb->prepare() argument list in order.
	 *
	 * @param string $period Period.
	 * @return array{sql:string,args:array}
	 */
	private function where_time_clause( $period ) {
		$seconds = $this->period_to_seconds( $period );
		if ( $seconds <= 0 ) {
			return array(
				'sql'  => '',
				'args' => array(),
			);
		}
		return array(
			'sql'  => ' AND `timestamp` >= %d',
			'args' => array( time() - $seconds ),
		);
	}

	/**
	 * Build a cache-safe suffix for an item_class.
	 *
	 * @param string $item_class Item class.
	 * @return string Sanitized key (possibly empty).
	 */
	private function item_class_cache_key( $item_class ) {
		$s = str_replace( '.', '_', (string) $item_class );
		return sanitize_key( $s );
	}

	/**
	 * Wrap a WP_Error as a REST response at its declared status.
	 *
	 * @param WP_Error $err Error.
	 * @return WP_REST_Response
	 */
	private function wp_error_response( WP_Error $err ) {
		$data   = $err->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;

		return new WP_REST_Response(
			array(
				'code'    => $err->get_error_code(),
				'message' => $err->get_error_message(),
			),
			$status
		);
	}
}
