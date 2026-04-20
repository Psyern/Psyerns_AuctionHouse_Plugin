<?php
/**
 * API-Key authentication for internal REST endpoints (Mod -> WP).
 *
 * @package Psyerns_AuctionHouse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Psyern_AH_Auth
 *
 * Validates Bearer API keys for /internal/* REST routes. The key is stored in
 * the `psyern_ah_api_key` WordPress option and compared via hash_equals() to
 * prevent timing attacks.
 */
class Psyern_AH_Auth {

	/**
	 * WordPress option key that stores the active API key.
	 */
	const OPTION_NAME = 'psyern_ah_api_key';

	/**
	 * Length of generated API keys (alphanumeric chars).
	 */
	const KEY_LENGTH = 48;

	/**
	 * Permission callback: validates the API key via EITHER the standard
	 * `Authorization: Bearer <key>` header OR a `?api_key=<key>` query param.
	 *
	 * Both paths are checked and compared via hash_equals(); the method does
	 * NOT short-circuit between them — both comparisons always run against the
	 * same stored key so timing does not depend on which transport was used.
	 *
	 * The query-param fallback exists because the DayZ Enforce-Script engine's
	 * RestContext can only set Content-Type, not arbitrary headers, so the
	 * PF_AH_Sync mod module sends `?api_key=...`. curl and admin tooling still
	 * use the Bearer header (recommended).
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 * @return bool True when either presented key matches the stored key.
	 */
	public static function validate_api_key( WP_REST_Request $request ) {
		$stored = self::get_api_key();

		if ( '' === $stored ) {
			return false;
		}

		$header_key = self::extract_bearer_key( $request );
		$query_key  = self::extract_query_key( $request );

		$header_ok = ( '' !== $header_key ) && hash_equals( $stored, $header_key );
		$query_ok  = ( '' !== $query_key ) && hash_equals( $stored, $query_key );

		return $header_ok || $query_ok;
	}

	/**
	 * Extract the Bearer key from the Authorization header, or '' when absent.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return string
	 */
	protected static function extract_bearer_key( WP_REST_Request $request ) {
		$header = $request->get_header( 'authorization' );

		if ( empty( $header ) ) {
			return '';
		}

		$header = trim( $header );

		if ( 0 !== stripos( $header, 'Bearer ' ) ) {
			return '';
		}

		return sanitize_text_field( substr( $header, 7 ) );
	}

	/**
	 * Extract the `api_key` query parameter, or '' when absent.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return string
	 */
	protected static function extract_query_key( WP_REST_Request $request ) {
		$value = $request->get_param( 'api_key' );

		if ( null === $value || '' === $value ) {
			return '';
		}

		return sanitize_text_field( (string) $value );
	}

	/**
	 * Generate a fresh cryptographically-random 48-char alphanumeric key.
	 *
	 * Prefers `random_bytes()` (hex-encoded, 48 chars) when available, falls
	 * back to `wp_generate_password()` without special characters.
	 *
	 * @return string
	 */
	public static function generate_api_key() {
		if ( function_exists( 'random_bytes' ) ) {
			try {
				return bin2hex( random_bytes( 24 ) );
			} catch ( Exception $e ) {
				// Fall through to wp_generate_password().
			}
		}

		return wp_generate_password( self::KEY_LENGTH, false, false );
	}

	/**
	 * Generate a new API key, persist it, and return it.
	 *
	 * @return string The newly-stored key.
	 */
	public static function rotate_api_key() {
		$key = self::generate_api_key();
		update_option( self::OPTION_NAME, $key, false );

		return $key;
	}

	/**
	 * Return the currently stored API key (empty string if never generated).
	 *
	 * @return string
	 */
	public static function get_api_key() {
		return (string) get_option( self::OPTION_NAME, '' );
	}

	/**
	 * Resolve the current WP user's linked Steam UID via the users mapping
	 * table. Single source of truth for UID lookups across service classes.
	 *
	 * @return string Steam UID, or '' when no user is logged in, the user has
	 *                no Steam link, or the mapping table is unavailable.
	 */
	public static function get_current_steam_uid() {
		$wp_user_id = (int) get_current_user_id();
		if ( $wp_user_id <= 0 ) {
			return '';
		}

		if ( ! class_exists( 'Psyern_AH_Database' ) ) {
			return '';
		}

		global $wpdb;

		$table = Psyern_AH_Database::get_table_name( 'users' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$uid = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT steam_uid FROM `' . $table . '` WHERE wp_user_id = %d LIMIT 1',
				$wp_user_id
			)
		);

		return $uid ? (string) $uid : '';
	}
}
