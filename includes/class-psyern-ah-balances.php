<?php
/**
 * Balance-mirror service for Psyerns AuctionHouse.
 *
 * Owns all reads/writes against the {wp_prefix}psyern_ah_balances table.
 * The DayZ mod is the single source of truth for balances; the website only
 * ever mirrors values the mod pushes via POST /internal/upload.
 *
 * @package Psyerns_AuctionHouse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Psyern_AH_Balances
 *
 * Each row represents one (player_uid, currency_source) tuple. A single player
 * can have two rows — one for "Expansion" (ATM wallet) and one for "Internal"
 * (the mod's own balance stored in PlayerData.json). The "Item" currency mode
 * is intentionally NOT mirrored here since it lives inside the player's
 * inventory and cannot be spent without the live DayZ client.
 *
 * All amounts are BIGINT. No floats are ever stored or returned by this class.
 */
class Psyern_AH_Balances {

	/**
	 * Whitelist of accepted currency_source values. Anything else is rejected.
	 *
	 * @var string[]
	 */
	const ALLOWED_SOURCES = array( 'Expansion', 'Internal' );

	/**
	 * Get the balances table name (fully prefixed).
	 *
	 * @return string
	 */
	protected function table() {
		return Psyern_AH_Database::get_table_name( 'balances' );
	}

	/**
	 * Insert-or-update a single balance row for (player_uid, currency_source).
	 *
	 * Uses ON DUPLICATE KEY UPDATE against the UNIQUE KEY (player_uid,
	 * currency_source) declared in Psyern_AH_Database so two concurrent upload
	 * requests for the same player cannot race and produce duplicate rows.
	 *
	 * @param string $uid     Steam-UID of the player (1–32 chars).
	 * @param string $source  Currency source; must be one of ALLOWED_SOURCES.
	 * @param int    $balance Balance amount (BIGINT). Negative values accepted
	 *                        and stored as-is since the mod may push them.
	 * @return bool True on success, false on invalid input or DB error.
	 */
	public function upsert_balance( $uid, $source, $balance ) {
		global $wpdb;

		$uid     = is_string( $uid ) ? trim( $uid ) : '';
		$source  = is_string( $source ) ? trim( $source ) : '';
		$balance = (int) $balance;

		if ( '' === $uid ) {
			return false;
		}

		if ( ! in_array( $source, self::ALLOWED_SOURCES, true ) ) {
			return false;
		}

		$table = $this->table();
		$now   = current_time( 'mysql', 1 );

		$sql = 'INSERT INTO ' . $table . ' (player_uid, currency_source, balance, updated_at)'
			. ' VALUES (%s, %s, %d, %s)'
			. ' ON DUPLICATE KEY UPDATE balance = VALUES(balance), updated_at = VALUES(updated_at)';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare( $sql, $uid, $source, $balance, $now )
		);

		return false !== $result;
	}

	/**
	 * Look up a single balance. Returns null when no row exists (distinct from
	 * a stored balance of 0).
	 *
	 * @param string $uid    Steam-UID.
	 * @param string $source Currency source.
	 * @return int|null
	 */
	public function get_balance( $uid, $source ) {
		global $wpdb;

		$uid    = is_string( $uid ) ? trim( $uid ) : '';
		$source = is_string( $source ) ? trim( $source ) : '';

		if ( '' === $uid || ! in_array( $source, self::ALLOWED_SOURCES, true ) ) {
			return null;
		}

		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT balance FROM ' . $table . ' WHERE player_uid = %s AND currency_source = %s LIMIT 1',
				$uid,
				$source
			)
		);

		return null === $value ? null : (int) $value;
	}

	/**
	 * Return every known balance for a player as an associative array keyed by
	 * currency_source. Missing sources are omitted (NOT zero-filled) so the
	 * caller can distinguish "unknown" from "zero".
	 *
	 * @param string $uid Steam-UID.
	 * @return array<string,int> e.g. [ 'Expansion' => 12340, 'Internal' => 0 ].
	 */
	public function get_all_balances_for_uid( $uid ) {
		global $wpdb;

		$uid = is_string( $uid ) ? trim( $uid ) : '';
		if ( '' === $uid ) {
			return array();
		}

		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT currency_source, balance FROM ' . $table . ' WHERE player_uid = %s',
				$uid
			),
			ARRAY_A
		);

		$out = array();
		if ( empty( $rows ) ) {
			return $out;
		}

		foreach ( $rows as $row ) {
			$source = isset( $row['currency_source'] ) ? (string) $row['currency_source'] : '';
			if ( ! in_array( $source, self::ALLOWED_SOURCES, true ) ) {
				continue;
			}
			$out[ $source ] = isset( $row['balance'] ) ? (int) $row['balance'] : 0;
		}

		return $out;
	}

	/**
	 * Admin-facing listing for the Balances tab.
	 *
	 * Supported args:
	 *   - source  string   "Expansion" | "Internal" | "" (all)
	 *   - search  string   LIKE match on player_uid (also matches steam_name
	 *                      via JOIN on the users table so the admin can search
	 *                      by display name)
	 *   - limit   int      1..500, default 50
	 *   - offset  int      >=0, default 0
	 *   - orderby string   "updated_at" | "balance" | "player_uid"
	 *   - order   string   "ASC" | "DESC"
	 *
	 * Returns:
	 *   [
	 *     items  => [ { player_uid, currency_source, balance, updated_at, steam_name } ],
	 *     total  => int,
	 *     limit  => int,
	 *     offset => int,
	 *   ]
	 *
	 * @param array $args Filter/pagination options.
	 * @return array
	 */
	public function get_all_balances( array $args = array() ) {
		global $wpdb;

		$defaults = array(
			'source'  => '',
			'search'  => '',
			'limit'   => 50,
			'offset'  => 0,
			'orderby' => 'updated_at',
			'order'   => 'DESC',
		);
		$args = array_merge( $defaults, $args );

		$source  = is_string( $args['source'] ) ? trim( $args['source'] ) : '';
		$search  = is_string( $args['search'] ) ? trim( $args['search'] ) : '';
		$limit   = max( 1, min( 500, (int) $args['limit'] ) );
		$offset  = max( 0, (int) $args['offset'] );
		$orderby = in_array( $args['orderby'], array( 'updated_at', 'balance', 'player_uid' ), true )
			? $args['orderby']
			: 'updated_at';
		$order   = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$table       = $this->table();
		$users_table = Psyern_AH_Database::get_table_name( 'users' );

		$where        = array( '1=1' );
		$where_values = array();

		if ( '' !== $source ) {
			if ( ! in_array( $source, self::ALLOWED_SOURCES, true ) ) {
				return array(
					'items'  => array(),
					'total'  => 0,
					'limit'  => $limit,
					'offset' => $offset,
				);
			}
			$where[]        = 'b.currency_source = %s';
			$where_values[] = $source;
		}

		if ( '' !== $search ) {
			$like           = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]        = '(b.player_uid LIKE %s OR u.steam_name LIKE %s)';
			$where_values[] = $like;
			$where_values[] = $like;
		}

		$where_sql = implode( ' AND ', $where );

		$base_from = ' FROM ' . $table . ' AS b LEFT JOIN ' . $users_table . ' AS u ON u.steam_uid = b.player_uid WHERE ' . $where_sql;

		// Count.
		$count_sql = 'SELECT COUNT(*)' . $base_from;
		if ( ! empty( $where_values ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $where_values ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total = (int) $wpdb->get_var( $count_sql );
		}

		// Items. ORDER BY column is validated above, safe to inline.
		$items_sql = 'SELECT b.player_uid, b.currency_source, b.balance, b.updated_at, u.steam_name'
			. $base_from
			. ' ORDER BY b.' . $orderby . ' ' . $order
			. ', b.id ' . $order
			. ' LIMIT %d OFFSET %d';

		$values   = array_merge( $where_values, array( $limit, $offset ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( $items_sql, $values ), ARRAY_A );

		if ( empty( $rows ) ) {
			return array(
				'items'  => array(),
				'total'  => $total,
				'limit'  => $limit,
				'offset' => $offset,
			);
		}

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'player_uid'      => isset( $row['player_uid'] ) ? (string) $row['player_uid'] : '',
				'currency_source' => isset( $row['currency_source'] ) ? (string) $row['currency_source'] : '',
				'balance'         => isset( $row['balance'] ) ? (int) $row['balance'] : 0,
				'updated_at'      => isset( $row['updated_at'] ) ? (string) $row['updated_at'] : '',
				'steam_name'      => isset( $row['steam_name'] ) ? (string) $row['steam_name'] : '',
			);
		}

		return array(
			'items'  => $items,
			'total'  => $total,
			'limit'  => $limit,
			'offset' => $offset,
		);
	}
}
