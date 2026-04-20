<?php
/**
 * Plugin Name: Psyerns AuctionHouse
 * Plugin URI:  https://deadmansecho.com
 * Description: Bridge between DME_Auction_House DayZ mod and WordPress — marketplace, price charts, Steam login, buy/bid from the web.
 * Version:     1.0.0
 * Author:      Psyern
 * Author URI:  https://deadmansecho.com
 * License:     MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: psyerns-auctionhouse
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package Psyerns_AuctionHouse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PSYERN_AH_VERSION', '1.0.0' );
define( 'PSYERN_AH_DB_VERSION', 1 );
define( 'PSYERN_AH_PLUGIN_FILE', __FILE__ );
define( 'PSYERN_AH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PSYERN_AH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PSYERN_AH_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Conditionally require a class file only if it exists on disk.
 *
 * Phase 1 agents run in parallel; some service classes may not yet exist when
 * the plugin is first activated. Guarding each include keeps activation safe.
 *
 * @param string $relative_path Path relative to plugin root.
 * @return void
 */
function psyern_ah_require_if_exists( $relative_path ) {
	$full = PSYERN_AH_PLUGIN_DIR . $relative_path;
	if ( file_exists( $full ) ) {
		require_once $full;
	}
}

psyern_ah_require_if_exists( 'includes/class-psyern-ah-database.php' );
psyern_ah_require_if_exists( 'includes/class-psyern-ah-auth.php' );
psyern_ah_require_if_exists( 'includes/class-psyern-ah-steam-auth.php' );
psyern_ah_require_if_exists( 'includes/class-psyern-ah-api.php' );
psyern_ah_require_if_exists( 'includes/class-psyern-ah-listings.php' );
psyern_ah_require_if_exists( 'includes/class-psyern-ah-transactions.php' );
psyern_ah_require_if_exists( 'includes/class-psyern-ah-stats.php' );
psyern_ah_require_if_exists( 'includes/class-psyern-ah-balances.php' );
psyern_ah_require_if_exists( 'includes/class-psyern-ah-pending-actions.php' );
psyern_ah_require_if_exists( 'includes/class-psyern-ah-upload.php' );
psyern_ah_require_if_exists( 'includes/class-psyern-ah-theme.php' );
psyern_ah_require_if_exists( 'admin/class-psyern-ah-admin.php' );
psyern_ah_require_if_exists( 'public/class-psyern-ah-shortcodes.php' );

register_activation_hook( __FILE__, 'psyern_ah_activate' );
register_deactivation_hook( __FILE__, 'psyern_ah_deactivate' );

/**
 * Activation routine: create tables and seed default options.
 *
 * @return void
 */
function psyern_ah_activate() {
	if ( class_exists( 'Psyern_AH_Database' ) ) {
		Psyern_AH_Database::create_tables();
	}

	update_option( 'psyern_ah_db_version', PSYERN_AH_DB_VERSION );

	$existing_key = get_option( 'psyern_ah_api_key', '' );
	if ( empty( $existing_key ) && class_exists( 'Psyern_AH_Auth' ) && method_exists( 'Psyern_AH_Auth', 'generate_api_key' ) ) {
		update_option( 'psyern_ah_api_key', Psyern_AH_Auth::generate_api_key() );
	}

	if ( false === get_option( 'psyern_ah_currency_format', false ) ) {
		update_option( 'psyern_ah_currency_format', '{amount} €' );
	}

	if ( false === get_option( 'psyern_ah_push_interval_seconds', false ) ) {
		update_option( 'psyern_ah_push_interval_seconds', 30 );
	}

	if ( false === get_option( 'psyern_ah_poll_interval_seconds', false ) ) {
		update_option( 'psyern_ah_poll_interval_seconds', 10 );
	}

	if ( false === get_option( 'psyern_ah_item_map', false ) ) {
		$default_item_map = wp_json_encode(
			array(
				'version'          => 1,
				'default_icon_url' => '',
				'items'            => (object) array(),
			)
		);
		update_option( 'psyern_ah_item_map', $default_item_map );
	}

	if ( false === get_option( 'psyern_ah_public_visibility', false ) ) {
		update_option(
			'psyern_ah_public_visibility',
			array(
				'marketplace' => 1,
				'history'     => 1,
				'stats'       => 1,
			)
		);
	}

	if ( false === get_option( 'psyern_ah_categories', false ) ) {
		update_option( 'psyern_ah_categories', wp_json_encode( array() ) );
	}
}

/**
 * Deactivation routine: clear plugin transients only. Tables are preserved.
 *
 * @return void
 */
function psyern_ah_deactivate() {
	global $wpdb;

	$like_transient         = $wpdb->esc_like( '_transient_psyern_ah_' ) . '%';
	$like_transient_timeout = $wpdb->esc_like( '_transient_timeout_psyern_ah_' ) . '%';

	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$like_transient,
			$like_transient_timeout
		)
	);
}

/**
 * Maybe upgrade the database schema on plugin load.
 *
 * @return void
 */
function psyern_ah_maybe_upgrade() {
	if ( class_exists( 'Psyern_AH_Database' ) && method_exists( 'Psyern_AH_Database', 'maybe_upgrade' ) ) {
		Psyern_AH_Database::maybe_upgrade();
	}
}
add_action( 'plugins_loaded', 'psyern_ah_maybe_upgrade' );

/**
 * Load plugin textdomain for i18n.
 *
 * @return void
 */
function psyern_ah_load_textdomain() {
	load_plugin_textdomain( 'psyerns-auctionhouse', false, dirname( PSYERN_AH_PLUGIN_BASENAME ) . '/languages' );
}
add_action( 'init', 'psyern_ah_load_textdomain' );

/**
 * Register all REST routes on rest_api_init.
 *
 * @return void
 */
function psyern_ah_register_rest_routes() {
	if ( class_exists( 'Psyern_AH_Api' ) ) {
		$api = new Psyern_AH_Api();
		$api->register_routes();
	}

	if ( class_exists( 'Psyern_AH_Steam_Auth' ) ) {
		$steam_auth = new Psyern_AH_Steam_Auth();
		if ( method_exists( $steam_auth, 'register_routes' ) ) {
			$steam_auth->register_routes();
		}
	}
}
add_action( 'rest_api_init', 'psyern_ah_register_rest_routes' );
