<?php
/**
 * Uninstall routine for Psyerns AuctionHouse.
 *
 * Fires only when the user deletes the plugin via the WordPress admin.
 * Loaded in isolation by WordPress — does not have access to plugin runtime
 * constants, so the DB class is required explicitly below.
 *
 * @package Psyerns_AuctionHouse
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-psyern-ah-database.php';

Psyern_AH_Database::drop_tables();

$psyern_ah_options = array(
	'psyern_ah_db_version',
	'psyern_ah_api_key',
	'psyern_ah_steam_api_key',
	'psyern_ah_currency_format',
	'psyern_ah_item_map',
	'psyern_ah_push_interval_seconds',
	'psyern_ah_poll_interval_seconds',
	'psyern_ah_public_visibility',
	'psyern_ah_categories',
	'psyern_ah_default_theme',
	'psyern_ah_listing_detail_url',
);

foreach ( $psyern_ah_options as $psyern_ah_option ) {
	delete_option( $psyern_ah_option );
}

global $wpdb;

// Remove any plugin-owned transients (and their timeout twins) in one sweep.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_psyern_ah_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_psyern_ah_' ) . '%'
	)
);
