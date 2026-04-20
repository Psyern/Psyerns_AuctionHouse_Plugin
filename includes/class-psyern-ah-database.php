<?php
/**
 * Database schema and table management for Psyerns AuctionHouse.
 *
 * @package Psyerns_AuctionHouse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Psyern_AH_Database
 *
 * Creates, upgrades and drops the plugin's five tables via dbDelta().
 */
class Psyern_AH_Database {

	/**
	 * Option key storing the current schema version on disk.
	 */
	const DB_VERSION_OPTION = 'psyern_ah_db_version';

	/**
	 * Build the fully prefixed table name for a given suffix.
	 *
	 * @param string $suffix Table suffix (listings|transactions|balances|pending_actions|users).
	 * @return string Full table name including wpdb prefix.
	 */
	public static function get_table_name( $suffix ) {
		global $wpdb;
		return $wpdb->prefix . 'psyern_ah_' . $suffix;
	}

	/**
	 * Create or upgrade all plugin tables via dbDelta().
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		$listings        = self::get_table_name( 'listings' );
		$transactions    = self::get_table_name( 'transactions' );
		$balances        = self::get_table_name( 'balances' );
		$pending_actions = self::get_table_name( 'pending_actions' );
		$users           = self::get_table_name( 'users' );

		$sql_listings = "CREATE TABLE {$listings} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			listing_id varchar(64) NOT NULL,
			seller_uid varchar(32) NOT NULL DEFAULT '',
			seller_name varchar(128) NOT NULL DEFAULT '',
			item_class varchar(128) NOT NULL DEFAULT '',
			item_display varchar(255) NOT NULL DEFAULT '',
			category_id smallint(6) NOT NULL DEFAULT 0,
			listing_type tinyint(4) NOT NULL DEFAULT 0,
			start_price bigint(20) NOT NULL DEFAULT 0,
			buy_now_price bigint(20) NOT NULL DEFAULT 0,
			current_bid bigint(20) NOT NULL DEFAULT 0,
			current_bidder_uid varchar(32) NOT NULL DEFAULT '',
			current_bidder_name varchar(128) NOT NULL DEFAULT '',
			bid_count int(11) NOT NULL DEFAULT 0,
			created_ts bigint(20) NOT NULL DEFAULT 0,
			expires_ts bigint(20) NOT NULL DEFAULT 0,
			status tinyint(4) NOT NULL DEFAULT 0,
			last_sync datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY listing_id (listing_id),
			KEY seller_uid (seller_uid),
			KEY item_class (item_class),
			KEY category_id (category_id),
			KEY expires_ts (expires_ts),
			KEY status (status)
		) {$charset};";

		$sql_transactions = "CREATE TABLE {$transactions} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			transaction_id varchar(64) NOT NULL,
			listing_id varchar(64) NOT NULL DEFAULT '',
			seller_uid varchar(32) NOT NULL DEFAULT '',
			seller_name varchar(128) NOT NULL DEFAULT '',
			buyer_uid varchar(32) NOT NULL DEFAULT '',
			buyer_name varchar(128) NOT NULL DEFAULT '',
			item_class varchar(128) NOT NULL DEFAULT '',
			item_display varchar(255) NOT NULL DEFAULT '',
			final_price bigint(20) NOT NULL DEFAULT 0,
			fee bigint(20) NOT NULL DEFAULT 0,
			type tinyint(4) NOT NULL DEFAULT 0,
			timestamp bigint(20) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY transaction_id (transaction_id),
			KEY listing_id (listing_id),
			KEY item_class (item_class),
			KEY timestamp (timestamp)
		) {$charset};";

		$sql_balances = "CREATE TABLE {$balances} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			player_uid varchar(32) NOT NULL DEFAULT '',
			currency_source varchar(16) NOT NULL DEFAULT '',
			balance bigint(20) NOT NULL DEFAULT 0,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY player_source (player_uid,currency_source),
			KEY player_uid (player_uid)
		) {$charset};";

		$sql_pending = "CREATE TABLE {$pending_actions} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			action_uuid varchar(36) NOT NULL,
			action_type varchar(16) NOT NULL DEFAULT '',
			player_uid varchar(32) NOT NULL DEFAULT '',
			listing_id varchar(64) NOT NULL DEFAULT '',
			amount bigint(20) NOT NULL DEFAULT 0,
			nonce varchar(64) NOT NULL DEFAULT '',
			status varchar(16) NOT NULL DEFAULT 'queued',
			result_code varchar(32) NOT NULL DEFAULT '',
			result_message text,
			created_at datetime DEFAULT NULL,
			dispatched_at datetime DEFAULT NULL,
			completed_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY action_uuid (action_uuid),
			KEY player_uid (player_uid),
			KEY listing_id (listing_id),
			KEY status (status)
		) {$charset};";

		$sql_users = "CREATE TABLE {$users} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			wp_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			steam_uid varchar(32) NOT NULL DEFAULT '',
			steam_name varchar(128) NOT NULL DEFAULT '',
			avatar_url varchar(512) NOT NULL DEFAULT '',
			linked_at datetime DEFAULT NULL,
			last_login datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY wp_user_id (wp_user_id),
			UNIQUE KEY steam_uid (steam_uid)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_listings );
		dbDelta( $sql_transactions );
		dbDelta( $sql_balances );
		dbDelta( $sql_pending );
		dbDelta( $sql_users );
	}

	/**
	 * Drop all plugin tables. Used by uninstall.php.
	 *
	 * @return void
	 */
	public static function drop_tables() {
		global $wpdb;

		$tables = array(
			self::get_table_name( 'pending_actions' ),
			self::get_table_name( 'transactions' ),
			self::get_table_name( 'balances' ),
			self::get_table_name( 'listings' ),
			self::get_table_name( 'users' ),
		);

		foreach ( $tables as $table ) {
			// Table name cannot be parameterized via prepare().
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $table );
		}
	}

	/**
	 * Get the currently installed DB schema version.
	 *
	 * @return string Version string, or '0' if never installed.
	 */
	public static function get_db_version() {
		return (string) get_option( self::DB_VERSION_OPTION, '0' );
	}

	/**
	 * Persist the installed DB schema version.
	 *
	 * @param string $version Version string (matches PSYERN_AH_DB_VERSION).
	 * @return void
	 */
	public static function update_db_version( $version ) {
		update_option( self::DB_VERSION_OPTION, (string) $version );
	}

	/**
	 * Run create_tables() only when the stored version is older than the plugin's
	 * current schema version. Safe to call on every plugin load.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( ! defined( 'PSYERN_AH_DB_VERSION' ) ) {
			return;
		}

		$installed = self::get_db_version();
		$current   = (string) PSYERN_AH_DB_VERSION;

		if ( version_compare( $installed, $current, '<' ) ) {
			self::create_tables();
			self::update_db_version( $current );
		}
	}
}
