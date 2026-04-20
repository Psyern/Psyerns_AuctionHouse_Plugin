<?php
/**
 * Transactions service for Psyerns AuctionHouse.
 *
 * Owns all reads/writes against the {wp_prefix}psyern_ah_transactions table.
 * Provides the /public/history REST handler and is the entry point the
 * Upload agent calls when the DayZ mod pushes completed-listing deltas.
 *
 * @package Psyerns_AuctionHouse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Psyern_AH_Transactions
 *
 * Upload payload contract (from DME_AH_Transaction.c — PascalCase keys):
 *
 *   JSON key (mod)      DB column           Type
 *   ------------------  ------------------  --------------------------------
 *   TransactionID       transaction_id      string (UNIQUE — idempotency key)
 *   ListingID           listing_id          string
 *   SellerUID           seller_uid          string
 *   SellerName          seller_name         string
 *   BuyerUID            buyer_uid           string
 *   BuyerName           buyer_name          string
 *   ItemClassName       item_class          string
 *   ItemDisplayName     item_display        string
 *   FinalPrice          final_price         bigint
 *   Fee                 fee                 bigint
 *   Type                type                tinyint
 *                                           0=BuyNow 1=AuctionWon
 *                                           2=Expired 3=Cancelled
 *   Timestamp           timestamp           bigint (unix)
 *
 * Idempotency: transaction_id has a UNIQUE key. add_transactions() uses
 * INSERT IGNORE so a re-sent delta from the mod after a failed upload is
 * safely absorbed. Inserted vs skipped vs failed counts are returned.
 */
class Psyern_AH_Transactions {

	/**
	 * Get the transactions table name (fully prefixed).
	 *
	 * @return string
	 */
	protected function table() {
		return Psyern_AH_Database::get_table_name( 'transactions' );
	}

	/**
	 * Bulk-insert transactions pushed by the mod. Idempotent via INSERT IGNORE
	 * against the UNIQUE `transaction_id` key.
	 *
	 * Strategy:
	 *  1. Map each PascalCase payload to a snake_case row.
	 *  2. Skip rows with an empty transaction_id (required).
	 *  3. Attempt a single multi-row INSERT IGNORE built with $wpdb->prepare()
	 *     (placeholder groups repeated per row). This is far cheaper than N
	 *     round-trips for typical delta sizes (10–200 rows).
	 *  4. If anything goes wrong, fall back to per-row inserts.
	 *  5. After a successful write, invalidate the price-history cache for
	 *     any item_class that got new rows, so Agent 8's chart endpoint
	 *     sees fresh data on the next request.
	 *
	 * @param array $payloads List of PascalCase payload arrays from the mod.
	 * @return array{inserted:int,skipped:int,failed:int}
	 */
	public function add_transactions( array $payloads ) {
		global $wpdb;

		$result = array(
			'inserted' => 0,
			'skipped'  => 0,
			'failed'   => 0,
		);

		if ( empty( $payloads ) ) {
			return $result;
		}

		$rows          = array();
		$item_classes  = array();
		$table         = $this->table();

		foreach ( $payloads as $payload ) {
			if ( ! is_array( $payload ) ) {
				++$result['failed'];
				continue;
			}
			$row = $this->map_payload_to_row( $payload );
			if ( '' === $row['transaction_id'] ) {
				++$result['failed'];
				continue;
			}
			$rows[] = $row;
			if ( '' !== $row['item_class'] ) {
				$item_classes[ $row['item_class'] ] = true;
			}
		}

		if ( empty( $rows ) ) {
			return $result;
		}

		$columns = array(
			'transaction_id',
			'listing_id',
			'seller_uid',
			'seller_name',
			'buyer_uid',
			'buyer_name',
			'item_class',
			'item_display',
			'final_price',
			'fee',
			'type',
			'timestamp',
		);

		$placeholder_group = '(%s,%s,%s,%s,%s,%s,%s,%s,%d,%d,%d,%d)';
		$placeholders      = array();
		$values            = array();

		foreach ( $rows as $row ) {
			$placeholders[] = $placeholder_group;
			$values[]       = $row['transaction_id'];
			$values[]       = $row['listing_id'];
			$values[]       = $row['seller_uid'];
			$values[]       = $row['seller_name'];
			$values[]       = $row['buyer_uid'];
			$values[]       = $row['buyer_name'];
			$values[]       = $row['item_class'];
			$values[]       = $row['item_display'];
			$values[]       = $row['final_price'];
			$values[]       = $row['fee'];
			$values[]       = $row['type'];
			$values[]       = $row['timestamp'];
		}

		$column_list = '`' . implode( '`,`', $columns ) . '`';

		// Table + column names cannot be parameterized via prepare().
		$sql = 'INSERT IGNORE INTO ' . $table . ' (' . $column_list . ') VALUES ' . implode( ',', $placeholders );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared = $wpdb->prepare( $sql, $values );

		$insert_ok = false;

		if ( false !== $prepared ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$affected = $wpdb->query( $prepared );
			if ( false !== $affected ) {
				$inserted            = (int) $affected;
				$result['inserted'] += $inserted;
				$result['skipped']  += ( count( $rows ) - $inserted );
				$insert_ok           = true;
			}
		}

		if ( ! $insert_ok ) {
			// Fallback: per-row INSERT IGNORE. Slower but bullet-proof.
			$result['inserted'] = 0;
			$result['skipped']  = 0;
			foreach ( $rows as $row ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$affected = $wpdb->query(
					$wpdb->prepare(
						'INSERT IGNORE INTO ' . $table . ' (' . $column_list . ') VALUES ' . $placeholder_group,
						$row['transaction_id'],
						$row['listing_id'],
						$row['seller_uid'],
						$row['seller_name'],
						$row['buyer_uid'],
						$row['buyer_name'],
						$row['item_class'],
						$row['item_display'],
						$row['final_price'],
						$row['fee'],
						$row['type'],
						$row['timestamp']
					)
				);
				if ( false === $affected ) {
					++$result['failed'];
				} elseif ( (int) $affected > 0 ) {
					++$result['inserted'];
				} else {
					++$result['skipped'];
				}
			}
		}

		// Invalidate price-history cache for classes that received new rows.
		if ( $result['inserted'] > 0 && ! empty( $item_classes ) ) {
			if ( class_exists( 'Psyern_AH_Stats' ) && method_exists( 'Psyern_AH_Stats', 'invalidate_price_history_cache' ) ) {
				$stats = new Psyern_AH_Stats();
				$stats->invalidate_price_history_cache( array_keys( $item_classes ) );
			}
		}

		return $result;
	}

	/**
	 * Map a mod PascalCase transaction payload to a snake_case DB row.
	 *
	 * Empty / missing values default to safe zero-equivalents. Numeric columns
	 * are cast to int. Text columns are passed through unescaped — $wpdb->prepare()
	 * handles escaping downstream.
	 *
	 * @param array $p Payload.
	 * @return array Row keyed by DB column.
	 */
	private function map_payload_to_row( array $p ) {
		return array(
			'transaction_id' => isset( $p['TransactionID'] ) ? (string) $p['TransactionID'] : '',
			'listing_id'     => isset( $p['ListingID'] ) ? (string) $p['ListingID'] : '',
			'seller_uid'     => isset( $p['SellerUID'] ) ? (string) $p['SellerUID'] : '',
			'seller_name'    => isset( $p['SellerName'] ) ? (string) $p['SellerName'] : '',
			'buyer_uid'      => isset( $p['BuyerUID'] ) ? (string) $p['BuyerUID'] : '',
			'buyer_name'     => isset( $p['BuyerName'] ) ? (string) $p['BuyerName'] : '',
			'item_class'     => isset( $p['ItemClassName'] ) ? (string) $p['ItemClassName'] : '',
			'item_display'   => isset( $p['ItemDisplayName'] ) ? (string) $p['ItemDisplayName'] : '',
			'final_price'    => isset( $p['FinalPrice'] ) ? (int) $p['FinalPrice'] : 0,
			'fee'            => isset( $p['Fee'] ) ? (int) $p['Fee'] : 0,
			'type'           => isset( $p['Type'] ) ? (int) $p['Type'] : 0,
			'timestamp'      => isset( $p['Timestamp'] ) ? (int) $p['Timestamp'] : 0,
		);
	}

	/**
	 * Fetch the most recent transactions, enriched with icon_url from the
	 * admin-maintained item-map option and ISO8601 timestamp for clients.
	 *
	 * @param int $limit  Max rows. Clamped to [1, 200].
	 * @param int $offset Offset for pagination.
	 * @return array[] List of enriched row arrays.
	 */
	public function get_recent( $limit = 50, $offset = 0 ) {
		global $wpdb;

		$limit  = max( 1, min( 200, (int) $limit ) );
		$offset = max( 0, (int) $offset );
		$table  = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT transaction_id, listing_id, seller_uid, seller_name, buyer_uid, buyer_name, item_class, item_display, final_price, fee, type, timestamp FROM ' . $table . ' ORDER BY timestamp DESC, id DESC LIMIT %d OFFSET %d',
				$limit,
				$offset
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$item_map = $this->get_item_map();
		$out      = array();

		foreach ( $rows as $row ) {
			$row['final_price'] = (int) $row['final_price'];
			$row['fee']         = (int) $row['fee'];
			$row['type']        = (int) $row['type'];
			$row['timestamp']   = (int) $row['timestamp'];

			$row['icon_url']        = $this->resolve_icon_url( $row['item_class'], $item_map );
			$row['timestamp_iso']   = gmdate( 'c', $row['timestamp'] );
			$row['timestamp_unix']  = $row['timestamp'];

			$out[] = $row;
		}

		return $out;
	}

	/**
	 * Latest stored transaction timestamp. Used by the mod for delta-sync.
	 *
	 * @return int Unix seconds, 0 if empty.
	 */
	public function get_last_timestamp() {
		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var( 'SELECT MAX(timestamp) FROM ' . $table );
		return null === $value ? 0 : (int) $value;
	}

	/**
	 * Count rows, optionally filtered by "since" unix timestamp.
	 *
	 * @param int|null $since_ts Unix seconds; null = total count.
	 * @return int
	 */
	public function get_count( $since_ts = null ) {
		global $wpdb;

		$table = $this->table();

		if ( null === $since_ts ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$value = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table );
		} else {
			$value = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . $table . ' WHERE `timestamp` >= %d',
					(int) $since_ts
				)
			);
		}

		return null === $value ? 0 : (int) $value;
	}

	/**
	 * REST: GET /public/history.
	 *
	 * Query params: limit (default 50, clamped to [1,200]), offset (>=0).
	 * Response: { items, total, limit, offset }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_get_history( WP_REST_Request $request ) {
		$limit  = $request->get_param( 'limit' );
		$offset = $request->get_param( 'offset' );

		$limit  = null === $limit ? 50 : (int) $limit;
		$offset = null === $offset ? 0 : (int) $offset;

		$limit  = max( 1, min( 200, $limit ) );
		$offset = max( 0, $offset );

		$items = $this->get_recent( $limit, $offset );
		$total = $this->get_count();

		return new WP_REST_Response(
			array(
				'items'  => $items,
				'total'  => $total,
				'limit'  => $limit,
				'offset' => $offset,
			),
			200
		);
	}

	/**
	 * Load and decode the admin-managed item map option.
	 *
	 * Structure: { version, default_icon_url, items: { item_class: { icon_url, ... } } }.
	 *
	 * @return array Normalized map.
	 */
	private function get_item_map() {
		$raw = get_option( 'psyern_ah_item_map', '' );
		if ( empty( $raw ) ) {
			return array(
				'default_icon_url' => '',
				'items'            => array(),
			);
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array(
				'default_icon_url' => '',
				'items'            => array(),
			);
		}
		return array(
			'default_icon_url' => isset( $decoded['default_icon_url'] ) ? (string) $decoded['default_icon_url'] : '',
			'items'            => isset( $decoded['items'] ) && is_array( $decoded['items'] ) ? $decoded['items'] : array(),
		);
	}

	/**
	 * Resolve an icon URL for a given item_class via the item-map.
	 *
	 * @param string $item_class Class name.
	 * @param array  $item_map   Normalized map.
	 * @return string URL or '' when none available.
	 */
	private function resolve_icon_url( $item_class, array $item_map ) {
		if ( isset( $item_map['items'][ $item_class ]['icon_url'] ) ) {
			$url = (string) $item_map['items'][ $item_class ]['icon_url'];
			if ( '' !== $url ) {
				return esc_url_raw( $url );
			}
		}
		return isset( $item_map['default_icon_url'] ) ? esc_url_raw( (string) $item_map['default_icon_url'] ) : '';
	}
}
