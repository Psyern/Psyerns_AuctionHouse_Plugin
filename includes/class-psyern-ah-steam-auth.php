<?php
/**
 * Steam OpenID 2.0 login for the Psyerns AuctionHouse plugin.
 *
 * @package Psyerns_AuctionHouse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Psyern_AH_Steam_Auth
 *
 * Implements a self-contained Steam OpenID 2.0 flow:
 *   1. GET  /auth/steam/login    -> 302 to steamcommunity.com/openid/login
 *   2. GET  /auth/steam/callback -> verifies signature, logs user in, redirects back
 *   3. POST /auth/logout         -> logs user out
 *
 * The `claimed_id` returned by Steam is always validated by a server-side
 * `check_authentication` round-trip; no data from the query string is trusted
 * before that check succeeds.
 */
class Psyern_AH_Steam_Auth {

	/**
	 * REST namespace for this plugin.
	 */
	const REST_NAMESPACE = 'psyern-ah/v1';

	/**
	 * Steam OpenID endpoint (both redirect target and verification target).
	 */
	const STEAM_OPENID_URL = 'https://steamcommunity.com/openid/login';

	/**
	 * Regex that extracts the 17-digit Steam-UID from a valid claimed_id.
	 */
	const STEAM_ID_REGEX = '#^https://steamcommunity\.com/openid/id/(7656119\d{10})$#';

	/**
	 * Transient prefix for single-use login nonces (replay protection).
	 */
	const NONCE_TRANSIENT_PREFIX = 'psyern_ah_login_nonce_';

	/**
	 * Transient prefix for cached Steam profile data.
	 */
	const PROFILE_TRANSIENT_PREFIX = 'psyern_ah_steam_profile_';

	/**
	 * Login nonce TTL in seconds.
	 */
	const NONCE_TTL = 600;

	/**
	 * Profile cache TTL in seconds (6 hours).
	 */
	const PROFILE_TTL = 21600;

	/**
	 * Hook registration. Called from the plugin bootstrap.
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes under psyern-ah/v1.
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/auth/steam/login',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_login_redirect' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/auth/steam/callback',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_callback' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/auth/logout',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_logout' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Step 1: Redirect the browser to Steam's OpenID login.
	 *
	 * A single-use nonce is generated server-side and stored as a transient
	 * keyed by itself. The same nonce is appended to `openid.return_to`, so
	 * Steam echoes it back in the callback for replay protection.
	 *
	 * Design note: we bypass the REST server's response handling and call
	 * wp_redirect() + exit. Returning a WP_REST_Response with a Location
	 * header is unreliable because wp-json responses are JSON-wrapped, which
	 * breaks the browser redirect for this pure-navigational endpoint.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return void Never returns — terminates via exit.
	 */
	public function handle_login_redirect( WP_REST_Request $request ) {
		$redirect_param = (string) $request->get_param( 'redirect' );
		$redirect       = $this->sanitize_redirect( $redirect_param );

		$nonce = wp_generate_password( 32, false, false );
		set_transient( self::NONCE_TRANSIENT_PREFIX . $nonce, $redirect, self::NONCE_TTL );

		$return_to = add_query_arg(
			array(
				'nonce'    => $nonce,
				'redirect' => rawurlencode( $redirect ),
			),
			rest_url( self::REST_NAMESPACE . '/auth/steam/callback' )
		);

		$realm = home_url( '/' );

		$steam_url = add_query_arg(
			array(
				'openid.ns'         => 'http://specs.openid.net/auth/2.0',
				'openid.mode'       => 'checkid_setup',
				'openid.return_to'  => $return_to,
				'openid.realm'      => $realm,
				'openid.identity'   => 'http://specs.openid.net/auth/2.0/identifier_select',
				'openid.claimed_id' => 'http://specs.openid.net/auth/2.0/identifier_select',
			),
			self::STEAM_OPENID_URL
		);

		wp_redirect( $steam_url );
		exit;
	}

	/**
	 * Step 2: Handle Steam's callback, verify signature, log user in.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return void Never returns — terminates via exit after wp_redirect.
	 */
	public function handle_callback( WP_REST_Request $request ) {
		$nonce = sanitize_text_field( (string) $request->get_param( 'nonce' ) );

		if ( '' === $nonce ) {
			$this->redirect_with_error( 'missing_nonce' );
		}

		$transient_key = self::NONCE_TRANSIENT_PREFIX . $nonce;
		$stored        = get_transient( $transient_key );

		if ( false === $stored ) {
			$this->redirect_with_error( 'invalid_nonce' );
		}

		// Single-use: consume immediately.
		delete_transient( $transient_key );

		$redirect = $this->sanitize_redirect( (string) $stored );

		$claimed_id = (string) $request->get_param( 'openid_claimed_id' );
		if ( '' === $claimed_id ) {
			// REST turns dots into underscores; also try raw query.
			$claimed_id = isset( $_GET['openid.claimed_id'] )
				? sanitize_text_field( wp_unslash( $_GET['openid.claimed_id'] ) )
				: '';
		}

		$matches = array();
		if ( ! preg_match( self::STEAM_ID_REGEX, $claimed_id, $matches ) ) {
			$this->redirect_with_error( 'invalid_claimed_id', $redirect );
		}
		$steam_uid = $matches[1];

		if ( ! $this->verify_openid_signature( $request ) ) {
			$this->redirect_with_error( 'openid_verification_failed', $redirect );
		}

		$user_id = $this->get_or_create_wp_user( $steam_uid );
		if ( $user_id <= 0 ) {
			$this->redirect_with_error( 'user_creation_failed', $redirect );
		}

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );

		wp_redirect( $redirect );
		exit;
	}

	/**
	 * Step 3: Log the user out.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function handle_logout( WP_REST_Request $request ) {
		wp_logout();

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Perform the `check_authentication` round-trip to Steam.
	 *
	 * Copies every openid.* parameter verbatim, flips `openid.mode` to
	 * `check_authentication`, POSTs them to Steam, and requires the
	 * literal token `is_valid:true` in the response body.
	 *
	 * @param WP_REST_Request $request The callback request.
	 * @return bool
	 */
	private function verify_openid_signature( WP_REST_Request $request ) {
		$params = array();

		// Prefer raw $_GET so we keep the canonical `openid.*` dotted keys
		// that Steam signed — REST parameter keys lose the dot.
		$source = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( empty( $source ) ) {
			$source = $request->get_query_params();
		}

		foreach ( $source as $key => $value ) {
			if ( 0 === strpos( (string) $key, 'openid.' ) || 0 === strpos( (string) $key, 'openid_' ) ) {
				$normalized            = str_replace( 'openid_', 'openid.', (string) $key );
				$params[ $normalized ] = is_array( $value ) ? '' : sanitize_text_field( wp_unslash( $value ) );
			}
		}

		if ( empty( $params['openid.mode'] ) ) {
			return false;
		}

		$params['openid.mode'] = 'check_authentication';

		$response = wp_remote_post(
			self::STEAM_OPENID_URL,
			array(
				'timeout'   => 10,
				'body'      => $params,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			return false;
		}

		$body = (string) wp_remote_retrieve_body( $response );

		// Steam returns key:value lines. Require literal "is_valid:true".
		return ( false !== strpos( $body, 'is_valid:true' ) );
	}

	/**
	 * Look up (or create) the WordPress user mapped to this Steam-UID.
	 *
	 * @param string $steam_uid 17-digit Steam-UID (already regex-validated).
	 * @return int WordPress user ID, or 0 on failure.
	 */
	private function get_or_create_wp_user( $steam_uid ) {
		global $wpdb;

		if ( ! preg_match( '/^7656119\d{10}$/', $steam_uid ) ) {
			return 0;
		}

		$table = Psyern_AH_Database::get_table_name( 'users' );
		$now   = current_time( 'mysql' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT wp_user_id FROM {$table} WHERE steam_uid = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$steam_uid
			)
		);

		if ( $row && ! empty( $row->wp_user_id ) ) {
			$wp_user_id = (int) $row->wp_user_id;

			$wpdb->update(
				$table,
				array( 'last_login' => $now ),
				array( 'wp_user_id' => $wp_user_id ),
				array( '%s' ),
				array( '%d' )
			);

			return $wp_user_id;
		}

		// Create a fresh WP user — resolve username collisions by counter.
		$base_login = 'steam_' . $steam_uid;
		$login      = $base_login;
		$suffix     = 1;
		while ( username_exists( $login ) ) {
			$login = $base_login . '_' . $suffix;
			$suffix++;
			if ( $suffix > 100 ) {
				return 0;
			}
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'display_name' => 'Steam ' . substr( $steam_uid, -6 ),
				'role'         => 'subscriber',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return 0;
		}

		$wpdb->insert(
			$table,
			array(
				'wp_user_id' => (int) $user_id,
				'steam_uid'  => $steam_uid,
				'linked_at'  => $now,
				'last_login' => $now,
			),
			array( '%d', '%s', '%s', '%s' )
		);

		return (int) $user_id;
	}

	/**
	 * Fetch the Steam profile summary (name, avatar) via the official Web API.
	 *
	 * Only runs if the admin has stored a Steam Web API key in the
	 * `psyern_ah_steam_api_key` option. Results are transient-cached per UID.
	 *
	 * @param string $steam_uid 17-digit Steam-UID.
	 * @return array{name:string,avatar:string}|null
	 */
	public function fetch_steam_profile( $steam_uid ) {
		if ( ! preg_match( '/^7656119\d{10}$/', (string) $steam_uid ) ) {
			return null;
		}

		$api_key = (string) get_option( 'psyern_ah_steam_api_key', '' );
		if ( '' === $api_key ) {
			return null;
		}

		$cache_key = self::PROFILE_TRANSIENT_PREFIX . $steam_uid;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$url = add_query_arg(
			array(
				'key'      => $api_key,
				'steamids' => $steam_uid,
			),
			'https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v2/'
		);

		$response = wp_remote_get( $url, array( 'timeout' => 5 ) );
		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			return null;
		}

		$body    = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) || empty( $decoded['response']['players'][0] ) ) {
			return null;
		}

		$player  = $decoded['response']['players'][0];
		$profile = array(
			'name'   => isset( $player['personaname'] ) ? sanitize_text_field( $player['personaname'] ) : '',
			'avatar' => isset( $player['avatarfull'] ) ? esc_url_raw( $player['avatarfull'] ) : '',
		);

		set_transient( $cache_key, $profile, self::PROFILE_TTL );

		return $profile;
	}

	/**
	 * Whitelist a redirect URL: same-host only, else fall back to home_url().
	 *
	 * @param string $url Candidate URL from query string.
	 * @return string Sanitized redirect target (guaranteed same-host).
	 */
	private function sanitize_redirect( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return home_url( '/' );
		}

		$url = esc_url_raw( wp_unslash( $url ) );

		$site_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$url_host  = wp_parse_url( $url, PHP_URL_HOST );

		// Relative URLs (no host) are safe — resolve against home_url.
		if ( null === $url_host || '' === $url_host ) {
			return home_url( $url );
		}

		if ( strcasecmp( (string) $url_host, (string) $site_host ) !== 0 ) {
			return home_url( '/' );
		}

		return $url;
	}

	/**
	 * Redirect to home with an error code query parameter and exit.
	 *
	 * @param string $code     Short error code.
	 * @param string $redirect Base URL; defaults to home_url('/').
	 * @return void Never returns.
	 */
	private function redirect_with_error( $code, $redirect = '' ) {
		if ( '' === $redirect ) {
			$redirect = home_url( '/' );
		}

		$target = add_query_arg( 'psyern_ah_auth_error', sanitize_key( $code ), $redirect );
		wp_redirect( $target );
		exit;
	}
}
