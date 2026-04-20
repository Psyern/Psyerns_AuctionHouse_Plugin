<?php
/**
 * REST API route registration for Psyerns AuctionHouse.
 *
 * @package Psyerns_AuctionHouse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Psyern_AH_Api
 *
 * Registers all REST routes under the psyern-ah/v1 namespace.
 * Routes fall into three groups:
 *  - /public/*   — open endpoints, no auth
 *  - /user/*     — logged-in WP users (nonce is validated inside each service method)
 *  - /internal/* — API-key protected endpoints called by the DayZ mod
 *
 * Routes wire real service callbacks when the corresponding service class exists.
 * Otherwise a 501 stub is registered so the namespace is discoverable and the
 * plugin remains activatable while parallel Phase 2 agents finish their work.
 */
class Psyern_AH_Api {

	/**
	 * REST namespace. "NAMESPACE" is a reserved keyword in PHP; use "NS".
	 *
	 * @var string
	 */
	const NS = 'psyern-ah/v1';

	/**
	 * Register every REST route for this plugin.
	 *
	 * @return void
	 */
	public function register_routes() {
		$logged_in = array( $this, 'require_logged_in' );
		$api_key   = array( 'Psyern_AH_Auth', 'validate_api_key' );

		// ---- Public routes ----------------------------------------------------.

		$this->register_route(
			'/public/listings',
			'GET',
			'Psyern_AH_Listings',
			'handle_get_public',
			'__return_true'
		);

		$this->register_route(
			'/public/listings/(?P<id>[\w-]+)',
			'GET',
			'Psyern_AH_Listings',
			'handle_get_single',
			'__return_true'
		);

		$this->register_route(
			'/public/history',
			'GET',
			'Psyern_AH_Transactions',
			'handle_get_history',
			'__return_true'
		);

		$this->register_route(
			'/public/stats',
			'GET',
			'Psyern_AH_Stats',
			'handle_get_stats',
			'__return_true'
		);

		$this->register_route(
			'/public/price-history',
			'GET',
			'Psyern_AH_Stats',
			'handle_get_price_history',
			'__return_true'
		);

		$this->register_route(
			'/public/categories',
			'GET',
			'Psyern_AH_Listings',
			'handle_get_categories',
			'__return_true'
		);

		// ---- User (session-authenticated) routes -----------------------------.

		$this->register_route(
			'/user/me',
			'GET',
			'Psyern_AH_Pending_Actions',
			'handle_user_me',
			$logged_in
		);

		$this->register_route(
			'/user/listings',
			'GET',
			'Psyern_AH_Listings',
			'handle_get_user_listings',
			$logged_in
		);

		$this->register_route(
			'/user/bids',
			'GET',
			'Psyern_AH_Listings',
			'handle_get_user_bids',
			$logged_in
		);

		$this->register_route(
			'/user/purchase',
			'POST',
			'Psyern_AH_Pending_Actions',
			'handle_enqueue_purchase',
			$logged_in
		);

		$this->register_route(
			'/user/bid',
			'POST',
			'Psyern_AH_Pending_Actions',
			'handle_enqueue_bid',
			$logged_in
		);

		$this->register_route(
			'/user/cancel',
			'POST',
			'Psyern_AH_Pending_Actions',
			'handle_enqueue_cancel',
			$logged_in
		);

		// ---- Internal (API-key) routes ---------------------------------------.

		$this->register_route(
			'/internal/upload',
			'POST',
			'Psyern_AH_Upload',
			'handle_upload',
			$api_key
		);

		$this->register_route(
			'/internal/pending',
			'GET',
			'Psyern_AH_Pending_Actions',
			'handle_dispatch',
			$api_key
		);

		// Complete-route: dual transport.
		// - POST is the primary path (DayZ Enforce-Script engine only exposes
		//   GET/POST, so the mod POSTs with a `_method:"PATCH"` marker in the
		//   JSON body).
		// - PATCH is registered in parallel for curl/admin tooling.
		// Both delegate to the same service method (handle_complete), which
		// must reject POSTs that lack the `_method=PATCH` marker with HTTP 400.
		$this->register_route(
			'/internal/pending/(?P<uuid>[a-f0-9\-]+)',
			'POST',
			'Psyern_AH_Pending_Actions',
			'handle_complete',
			$api_key
		);

		$this->register_route(
			'/internal/pending/(?P<uuid>[a-f0-9\-]+)',
			'PATCH',
			'Psyern_AH_Pending_Actions',
			'handle_complete',
			$api_key
		);

		// Ping is implemented in this class directly.
		register_rest_route(
			self::NS,
			'/internal/ping',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_ping' ),
				'permission_callback' => $api_key,
			)
		);
	}

	/**
	 * Register a single REST route, delegating to the named service class when
	 * available and falling back to the 501 stub when it is not.
	 *
	 * @param string          $path                 Route path relative to the namespace.
	 * @param string          $method               HTTP method ('GET', 'POST', 'PATCH', ...).
	 * @param string          $service_class        Expected service class name.
	 * @param string          $service_method       Expected service method name.
	 * @param callable|string $permission_callback Permission callback.
	 * @return void
	 */
	protected function register_route( $path, $method, $service_class, $service_method, $permission_callback ) {
		if ( class_exists( $service_class ) ) {
			$service = new $service_class();
			$callback = array( $service, $service_method );
		} else {
			$callback = array( $this, 'stub' );
		}

		register_rest_route(
			self::NS,
			$path,
			array(
				'methods'             => $method,
				'callback'            => $callback,
				'permission_callback' => $permission_callback,
			)
		);
	}

	/**
	 * Permission callback: require a logged-in WordPress user.
	 *
	 * Nonce verification lives inside each service method, not here.
	 *
	 * @return bool
	 */
	public function require_logged_in() {
		return is_user_logged_in();
	}

	/**
	 * Fallback callback used while the corresponding service class is still
	 * being built by a parallel Phase 2 agent.
	 *
	 * @return WP_REST_Response
	 */
	public function stub() {
		return new WP_REST_Response(
			array(
				'error' => 'not_implemented',
				'todo'  => 'service-class-pending',
			),
			501
		);
	}

	/**
	 * Internal health-check endpoint for the DayZ mod.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_ping() {
		return new WP_REST_Response(
			array(
				'pong'      => true,
				'timestamp' => time(),
			),
			200
		);
	}
}
