<?php
/**
 * Shortcode registration + output for Psyerns AuctionHouse.
 *
 * Registers six shortcodes for the public-facing marketplace UI:
 *   [psyerns_auctionhouse_marketplace]
 *   [psyerns_auctionhouse_listing id="..."]
 *   [psyerns_auctionhouse_my]
 *   [psyerns_auctionhouse_history]
 *   [psyerns_auctionhouse_stats]
 *   [psyerns_auctionhouse_price_chart item_class="..."]
 *
 * Each shortcode:
 *   1. Normalizes its attributes via shortcode_atts() + per-field sanitizers.
 *   2. Resolves the active theme slug (attr > option > fallback).
 *   3. Enqueues the shortcode-specific CSS via Psyern_AH_Theme (Agent 9)
 *      when available and the matching JS handle (Agent 8) when registered.
 *   4. Localizes a shared psyernAh data object (apiBase, nonces, currentUser,
 *      translations, currencyFormat) onto the relevant script handle.
 *   5. Fetches any initial server-side data via the Phase 2 service classes.
 *   6. Buffers and returns a template partial — templates contain no logic.
 *
 * @package Psyerns_AuctionHouse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Psyern_AH_Shortcodes
 *
 * One instance per page-load. Self-registers on `init` via the bootstrap at
 * the bottom of this file so the main plugin file doesn't need modification.
 */
class Psyern_AH_Shortcodes {

	/**
	 * Fallback theme slug used when no option is set and no attr is provided.
	 */
	const DEFAULT_THEME = 'stalker';

	/**
	 * Option key holding the admin-maintained item → icon/metadata map.
	 *
	 * Kept in sync with Psyern_AH_Listings::ITEM_MAP_OPTION. The shortcode
	 * class duplicates the constant (rather than pulling it cross-class)
	 * because Phase 2 agents ship in parallel and a missing class must not
	 * break the shortcode.
	 */
	const ITEM_MAP_OPTION = 'psyern_ah_item_map';

	/**
	 * Register all shortcodes with WordPress.
	 *
	 * @return void
	 */
	public function register() {
		add_shortcode( 'psyerns_auctionhouse_marketplace', array( $this, 'shortcode_marketplace' ) );
		add_shortcode( 'psyerns_auctionhouse_listing', array( $this, 'shortcode_listing' ) );
		add_shortcode( 'psyerns_auctionhouse_my', array( $this, 'shortcode_my' ) );
		add_shortcode( 'psyerns_auctionhouse_history', array( $this, 'shortcode_history' ) );
		add_shortcode( 'psyerns_auctionhouse_stats', array( $this, 'shortcode_stats' ) );
		add_shortcode( 'psyerns_auctionhouse_price_chart', array( $this, 'shortcode_price_chart' ) );
	}

	/* =====================================================================
	 * Public static helpers (used by templates + cross-agent)
	 * ===================================================================== */

	/**
	 * Format a currency amount using the admin-configured template.
	 *
	 * Template placeholder: `{amount}` → number_format_i18n( $amount ).
	 *
	 * @param int $amount Integer amount (no decimal sub-units in DME_AH).
	 * @return string Escaped display string.
	 */
	public static function format_price( $amount ) {
		$amount = (int) $amount;
		$format = (string) get_option( 'psyern_ah_currency_format', '{amount} €' );
		if ( '' === $format ) {
			$format = '{amount} €';
		}
		return str_replace( '{amount}', number_format_i18n( $amount ), $format );
	}

	/**
	 * Resolve the icon URL for an item class by reading the item-map option.
	 *
	 * Tolerates all three storage shapes the admin UI emits (see
	 * psyern_ah_item_map briefings in the orchestrator prompt):
	 *   A) JSON-encoded string { version, default_icon_url, items: { cls: {icon_url,...} } }
	 *   B) PHP array with same shape
	 *   C) Flat map: [ classname => url ]
	 *
	 * @param string $item_class Item class name (DayZ classname).
	 * @return string URL (or '' when not resolvable).
	 */
	public static function get_icon_url( $item_class ) {
		$item_class = (string) $item_class;
		if ( '' === $item_class ) {
			return '';
		}

		$map = get_option( self::ITEM_MAP_OPTION, array() );

		// JSON-string shape (admin UI stores this way).
		if ( is_string( $map ) ) {
			$decoded = json_decode( $map, true );
			if ( is_array( $decoded ) ) {
				$map = $decoded;
			} else {
				$map = array();
			}
		}
		if ( ! is_array( $map ) ) {
			return '';
		}

		// Nested under 'items'.
		if ( isset( $map['items'] ) && is_array( $map['items'] ) && isset( $map['items'][ $item_class ] ) ) {
			$entry = $map['items'][ $item_class ];
			if ( is_array( $entry ) && isset( $entry['icon_url'] ) && '' !== $entry['icon_url'] ) {
				return esc_url_raw( (string) $entry['icon_url'] );
			}
			if ( is_string( $entry ) && '' !== $entry ) {
				return esc_url_raw( $entry );
			}
		}

		// Flat top-level.
		if ( isset( $map[ $item_class ] ) ) {
			$entry = $map[ $item_class ];
			if ( is_array( $entry ) && isset( $entry['icon_url'] ) && '' !== $entry['icon_url'] ) {
				return esc_url_raw( (string) $entry['icon_url'] );
			}
			if ( is_string( $entry ) && '' !== $entry ) {
				return esc_url_raw( $entry );
			}
		}

		// Default fallback.
		if ( isset( $map['default_icon_url'] ) && '' !== $map['default_icon_url'] ) {
			return esc_url_raw( (string) $map['default_icon_url'] );
		}

		return '';
	}

	/**
	 * Resolve the rarity tag for an item class (empty string when unknown).
	 *
	 * Same tolerant shape-handling as get_icon_url(). Valid rarity values per
	 * README §14: common | uncommon | rare | epic | legendary.
	 *
	 * @param string $item_class Item class name.
	 * @return string Rarity slug (lower-case) or '' when not set.
	 */
	public static function get_rarity( $item_class ) {
		$item_class = (string) $item_class;
		if ( '' === $item_class ) {
			return '';
		}

		$map = get_option( self::ITEM_MAP_OPTION, array() );
		if ( is_string( $map ) ) {
			$decoded = json_decode( $map, true );
			$map     = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $map ) ) {
			return '';
		}

		$entry = null;
		if ( isset( $map['items'] ) && is_array( $map['items'] ) && isset( $map['items'][ $item_class ] ) ) {
			$entry = $map['items'][ $item_class ];
		} elseif ( isset( $map[ $item_class ] ) ) {
			$entry = $map[ $item_class ];
		}

		if ( is_array( $entry ) && isset( $entry['rarity'] ) && is_string( $entry['rarity'] ) ) {
			$rarity = strtolower( sanitize_key( $entry['rarity'] ) );
			$valid  = array( 'common', 'uncommon', 'rare', 'epic', 'legendary' );
			if ( in_array( $rarity, $valid, true ) ) {
				return $rarity;
			}
		}
		return '';
	}

	/**
	 * Resolve the active theme slug for a given shortcode call.
	 *
	 * Precedence: attr > option `psyern_ah_default_theme` > self::DEFAULT_THEME.
	 *
	 * @param array $atts Normalized shortcode attributes.
	 * @return string Theme slug (sanitized lower-case).
	 */
	public static function resolve_theme( array $atts ) {
		if ( ! empty( $atts['theme'] ) ) {
			return sanitize_key( (string) $atts['theme'] );
		}
		$option = (string) get_option( 'psyern_ah_default_theme', '' );
		if ( '' !== $option ) {
			return sanitize_key( $option );
		}
		return self::DEFAULT_THEME;
	}

	/* =====================================================================
	 * Shortcodes
	 * ===================================================================== */

	/**
	 * [psyerns_auctionhouse_marketplace theme="..." per_page="20"].
	 *
	 * @param array $atts Raw attributes.
	 * @return string HTML.
	 */
	public function shortcode_marketplace( $atts ) {
		$atts = shortcode_atts(
			array(
				'theme'    => '',
				'per_page' => 20,
			),
			$atts,
			'psyerns_auctionhouse_marketplace'
		);

		$per_page = absint( $atts['per_page'] );
		if ( $per_page < 1 ) {
			$per_page = 20;
		}
		if ( $per_page > 100 ) {
			$per_page = 100;
		}

		$theme_slug = self::resolve_theme( $atts );

		$this->enqueue_for_shortcode( $theme_slug, 'marketplace' );
		$this->maybe_enqueue_script( 'psyern-ah-marketplace' );
		$this->localize_common( 'psyern-ah-marketplace' );

		// Fetch initial page server-side for SEO / no-JS fallback.
		$initial = array(
			'items'       => array(),
			'total'       => 0,
			'page'        => 1,
			'per_page'    => $per_page,
			'total_pages' => 0,
		);
		if ( class_exists( 'Psyern_AH_Listings' ) ) {
			$listings = new Psyern_AH_Listings();
			$initial  = $listings->get_listings(
				array(
					'status'   => 0,
					'orderby'  => 'newest',
					'page'     => 1,
					'per_page' => $per_page,
				)
			);
		}

		$categories = $this->get_category_list();

		$container_id = 'psyern-ah-mp-' . wp_rand();

		ob_start();
		include PSYERN_AH_PLUGIN_DIR . 'public/templates/marketplace.php';
		return ob_get_clean();
	}

	/**
	 * [psyerns_auctionhouse_listing id="..."].
	 *
	 * @param array $atts Raw attributes.
	 * @return string HTML.
	 */
	public function shortcode_listing( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'    => '',
				'theme' => '',
			),
			$atts,
			'psyerns_auctionhouse_listing'
		);

		$listing_id = sanitize_text_field( (string) $atts['id'] );

		// Also accept ?listing_id=... query var so a detail page can be reused
		// for any listing without regenerating the shortcode.
		if ( '' === $listing_id && isset( $_GET['listing_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$listing_id = sanitize_text_field( wp_unslash( (string) $_GET['listing_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$theme_slug = self::resolve_theme( $atts );

		$this->enqueue_for_shortcode( $theme_slug, 'listing' );
		$this->maybe_enqueue_script( 'psyern-ah-listing' );
		$this->maybe_enqueue_script( 'psyern-ah-price-chart' );
		$this->localize_common( 'psyern-ah-listing' );

		if ( '' === $listing_id ) {
			ob_start();
			?>
			<div class="psyern-ah-listing psyern-ah-listing--error psyern-ah-theme-<?php echo esc_attr( $theme_slug ); ?>">
				<p><?php esc_html_e( 'Kein Listing ausgewählt. Bitte id="..." angeben oder die Detailseite über einen Listing-Link aufrufen.', 'psyerns-auctionhouse' ); ?></p>
			</div>
			<?php
			return ob_get_clean();
		}

		$listing = null;
		if ( class_exists( 'Psyern_AH_Listings' ) ) {
			$svc     = new Psyern_AH_Listings();
			$listing = $svc->get_listing_by_id( $listing_id );
		}

		if ( null === $listing ) {
			ob_start();
			?>
			<div class="psyern-ah-listing psyern-ah-listing--not-found psyern-ah-theme-<?php echo esc_attr( $theme_slug ); ?>">
				<p>
					<?php
					printf(
						/* translators: %s: listing id */
						esc_html__( 'Listing „%s" nicht gefunden oder nicht mehr aktiv.', 'psyerns-auctionhouse' ),
						esc_html( $listing_id )
					);
					?>
				</p>
			</div>
			<?php
			return ob_get_clean();
		}

		// Pre-compute display strings so the template stays logic-free.
		$item_class        = isset( $listing['item_class'] ) ? (string) $listing['item_class'] : '';
		$icon_url          = isset( $listing['icon_url'] ) && '' !== $listing['icon_url']
			? $listing['icon_url']
			: self::get_icon_url( $item_class );
		$rarity            = self::get_rarity( $item_class );
		$listing_type      = isset( $listing['listing_type'] ) ? (int) $listing['listing_type'] : 0;
		$supports_buy_now  = ( 0 === $listing_type || 2 === $listing_type );
		$supports_bidding  = ( 1 === $listing_type || 2 === $listing_type );
		$start_price       = isset( $listing['start_price'] ) ? (int) $listing['start_price'] : 0;
		$current_bid       = isset( $listing['current_bid'] ) ? (int) $listing['current_bid'] : 0;
		$buy_now_price     = isset( $listing['buy_now_price'] ) ? (int) $listing['buy_now_price'] : 0;
		$expires_ts        = isset( $listing['expires_ts'] ) ? (int) $listing['expires_ts'] : 0;
		$min_bid           = max( $current_bid + 1, $start_price );
		$current_user_uid  = $this->get_current_steam_uid();
		$is_owner          = ( '' !== $current_user_uid && isset( $listing['seller_uid'] ) && $current_user_uid === (string) $listing['seller_uid'] );
		$is_logged_in      = is_user_logged_in();
		$is_linked         = ( '' !== $current_user_uid );
		$listing_type_label = $this->listing_type_label( $listing_type );
		$status_label       = $this->listing_status_label( isset( $listing['status'] ) ? (int) $listing['status'] : 0 );

		ob_start();
		include PSYERN_AH_PLUGIN_DIR . 'public/templates/listing-detail.php';
		return ob_get_clean();
	}

	/**
	 * [psyerns_auctionhouse_my].
	 *
	 * @param array $atts Raw attributes.
	 * @return string HTML.
	 */
	public function shortcode_my( $atts ) {
		$atts = shortcode_atts(
			array(
				'theme' => '',
			),
			$atts,
			'psyerns_auctionhouse_my'
		);

		$theme_slug = self::resolve_theme( $atts );

		$this->enqueue_for_shortcode( $theme_slug, 'my' );
		$this->maybe_enqueue_script( 'psyern-ah-my' );
		$this->localize_common( 'psyern-ah-my' );

		$is_logged_in   = is_user_logged_in();
		$steam_uid      = $this->get_current_steam_uid();
		$is_linked      = ( '' !== $steam_uid );
		$steam_login_url = esc_url(
			rest_url( 'psyern-ah/v1/auth/steam/login' )
			. '?return_to=' . rawurlencode( $this->current_url() )
		);

		ob_start();
		include PSYERN_AH_PLUGIN_DIR . 'public/templates/my.php';
		return ob_get_clean();
	}

	/**
	 * [psyerns_auctionhouse_history limit="50"].
	 *
	 * @param array $atts Raw attributes.
	 * @return string HTML.
	 */
	public function shortcode_history( $atts ) {
		$atts = shortcode_atts(
			array(
				'theme' => '',
				'limit' => 50,
			),
			$atts,
			'psyerns_auctionhouse_history'
		);

		$limit = absint( $atts['limit'] );
		if ( $limit < 1 ) {
			$limit = 50;
		}
		if ( $limit > 200 ) {
			$limit = 200;
		}

		$theme_slug = self::resolve_theme( $atts );

		$this->enqueue_for_shortcode( $theme_slug, 'history' );
		$this->maybe_enqueue_script( 'psyern-ah-history' );
		$this->localize_common( 'psyern-ah-history' );

		$rows = array();
		if ( class_exists( 'Psyern_AH_Transactions' ) ) {
			$svc  = new Psyern_AH_Transactions();
			$rows = $svc->get_recent( $limit, 0 );
		}

		ob_start();
		include PSYERN_AH_PLUGIN_DIR . 'public/templates/history.php';
		return ob_get_clean();
	}

	/**
	 * [psyerns_auctionhouse_stats].
	 *
	 * @param array $atts Raw attributes.
	 * @return string HTML.
	 */
	public function shortcode_stats( $atts ) {
		$atts = shortcode_atts(
			array(
				'theme'  => '',
				'period' => '7d',
			),
			$atts,
			'psyerns_auctionhouse_stats'
		);

		$period_allowed = array( '24h', '7d', '30d', 'all' );
		$period_default = in_array( (string) $atts['period'], $period_allowed, true ) ? (string) $atts['period'] : '7d';

		$theme_slug = self::resolve_theme( $atts );

		$this->enqueue_for_shortcode( $theme_slug, 'stats' );
		$this->maybe_enqueue_script( 'psyern-ah-stats' );
		$this->maybe_enqueue_script( 'psyern-ah-price-chart' );
		$this->localize_common( 'psyern-ah-stats' );

		$stats = array(
			'top_sellers'   => array(),
			'popular_items' => array(),
			'avg_prices'    => array(),
		);
		if ( class_exists( 'Psyern_AH_Stats' ) ) {
			$svc                      = new Psyern_AH_Stats();
			$stats['top_sellers']     = $svc->get_top_sellers( $period_default, 10 );
			$stats['popular_items']   = $svc->get_popular_items( $period_default, 10 );
			$stats['avg_prices']      = $svc->get_avg_prices( $period_default, 20 );
		}

		$container_id = 'psyern-ah-stats-' . wp_rand();

		ob_start();
		include PSYERN_AH_PLUGIN_DIR . 'public/templates/stats.php';
		return ob_get_clean();
	}

	/**
	 * [psyerns_auctionhouse_price_chart item_class="..." period="30d" height="300"].
	 *
	 * @param array $atts Raw attributes.
	 * @return string HTML.
	 */
	public function shortcode_price_chart( $atts ) {
		$atts = shortcode_atts(
			array(
				'item_class' => '',
				'period'     => '30d',
				'height'     => 300,
				'theme'      => '',
			),
			$atts,
			'psyerns_auctionhouse_price_chart'
		);

		$item_class = sanitize_text_field( (string) $atts['item_class'] );
		$period     = (string) $atts['period'];
		if ( ! in_array( $period, array( '24h', '7d', '30d', 'all' ), true ) ) {
			$period = '30d';
		}
		$height = absint( $atts['height'] );
		if ( $height < 100 ) {
			$height = 300;
		}
		if ( $height > 900 ) {
			$height = 900;
		}

		$theme_slug = self::resolve_theme( $atts );

		$this->enqueue_for_shortcode( $theme_slug, 'price-chart' );
		$this->maybe_enqueue_script( 'psyern-ah-price-chart' );
		$this->localize_common( 'psyern-ah-price-chart' );

		$container_id = 'psyern-ah-chart-' . wp_rand();
		$canvas_id    = $container_id . '-canvas';
		$has_item     = ( '' !== $item_class );
		$icon_url     = $has_item ? self::get_icon_url( $item_class ) : '';

		ob_start();
		include PSYERN_AH_PLUGIN_DIR . 'public/templates/price-chart.php';
		return ob_get_clean();
	}

	/* =====================================================================
	 * Internal helpers
	 * ===================================================================== */

	/**
	 * Delegate theme-CSS enqueuing to Psyern_AH_Theme if Agent 9 has shipped.
	 *
	 * Guarded via class_exists so the shortcodes still render (with whatever
	 * CSS the active WordPress theme provides) if Agent 9 is not yet loaded.
	 *
	 * @param string $theme_slug     Resolved theme slug.
	 * @param string $shortcode_name Short shortcode name (e.g. 'marketplace').
	 * @return void
	 */
	private function enqueue_for_shortcode( $theme_slug, $shortcode_name ) {
		if ( class_exists( 'Psyern_AH_Theme' ) && method_exists( 'Psyern_AH_Theme', 'enqueue_for_shortcode' ) ) {
			Psyern_AH_Theme::enqueue_for_shortcode( $theme_slug, $shortcode_name );
		}
	}

	/**
	 * wp_enqueue_script() only when the handle has been registered by Agent 8.
	 *
	 * @param string $handle Script handle.
	 * @return void
	 */
	private function maybe_enqueue_script( $handle ) {
		if ( wp_script_is( $handle, 'registered' ) ) {
			wp_enqueue_script( $handle );
		}
	}

	/**
	 * Localize the shared `psyernAh` config object onto the given script
	 * handle. No-op when the handle is not enqueued.
	 *
	 * @param string $handle Script handle to attach to.
	 * @return void
	 */
	private function localize_common( $handle ) {
		if ( ! wp_script_is( $handle, 'enqueued' ) && ! wp_script_is( $handle, 'registered' ) ) {
			return;
		}

		$current_user_data = $this->current_user_payload();

		$detail_url = (string) get_option( 'psyern_ah_listing_detail_url', '' );

		$data = array(
			'apiBase'        => esc_url_raw( rest_url( 'psyern-ah/v1' ) ),
			'restNonce'      => wp_create_nonce( 'wp_rest' ),
			'nonces'         => array(
				'purchase' => wp_create_nonce( 'psyern-ah-purchase' ),
				'bid'      => wp_create_nonce( 'psyern-ah-bid' ),
				'cancel'   => wp_create_nonce( 'psyern-ah-cancel' ),
			),
			'currentUser'    => $current_user_data,
			'currencyFormat' => (string) get_option( 'psyern_ah_currency_format', '{amount} €' ),
			'listingDetailUrl' => $detail_url,
			'translations'   => array(
				'loading'          => __( 'Lade …', 'psyerns-auctionhouse' ),
				'error'            => __( 'Fehler beim Laden.', 'psyerns-auctionhouse' ),
				'no_results'       => __( 'Keine Ergebnisse.', 'psyerns-auctionhouse' ),
				'buy_now'          => __( 'Sofort kaufen', 'psyerns-auctionhouse' ),
				'place_bid'        => __( 'Gebot abgeben', 'psyerns-auctionhouse' ),
				'cancel'           => __( 'Abbrechen', 'psyerns-auctionhouse' ),
				'confirm_buy'      => __( 'Kauf bestätigen?', 'psyerns-auctionhouse' ),
				'confirm_cancel'   => __( 'Listing wirklich abbrechen?', 'psyerns-auctionhouse' ),
				'login_required'   => __( 'Bitte einloggen, um fortzufahren.', 'psyerns-auctionhouse' ),
				'link_required'    => __( 'Bitte Steam-Konto verknüpfen.', 'psyerns-auctionhouse' ),
				'status_queued'    => __( 'In Warteschlange', 'psyerns-auctionhouse' ),
				'status_dispatched'=> __( 'Zugestellt', 'psyerns-auctionhouse' ),
				'status_executing' => __( 'Wird ausgeführt', 'psyerns-auctionhouse' ),
				'status_success'   => __( 'Erfolgreich', 'psyerns-auctionhouse' ),
				'status_failed'    => __( 'Fehlgeschlagen', 'psyerns-auctionhouse' ),
				'time_expired'     => __( 'abgelaufen', 'psyerns-auctionhouse' ),
				'time_days'        => __( 'T', 'psyerns-auctionhouse' ),
				'time_hours'       => __( 'h', 'psyerns-auctionhouse' ),
				'time_minutes'     => __( 'm', 'psyerns-auctionhouse' ),
				'time_seconds'     => __( 's', 'psyerns-auctionhouse' ),
				'leading'          => __( 'Führend', 'psyerns-auctionhouse' ),
				'outbid'           => __( 'Überboten', 'psyerns-auctionhouse' ),
				'won'              => __( 'Gewonnen', 'psyerns-auctionhouse' ),
				'lost'             => __( 'Verloren', 'psyerns-auctionhouse' ),
			),
		);

		wp_localize_script( $handle, 'psyernAh', $data );
	}

	/**
	 * Small current-user payload for JS (never leaks email or anything PII beyond
	 * the publicly-posted Steam profile).
	 *
	 * @return array{
	 *   is_logged_in: bool,
	 *   is_linked: bool,
	 *   wp_user_id: int,
	 *   steam_uid: string,
	 *   display_name: string,
	 *   avatar_url: string
	 * }
	 */
	private function current_user_payload() {
		$out = array(
			'is_logged_in' => false,
			'is_linked'    => false,
			'wp_user_id'   => 0,
			'steam_uid'    => '',
			'display_name' => '',
			'avatar_url'   => '',
		);

		$wp_user_id = (int) get_current_user_id();
		if ( $wp_user_id <= 0 ) {
			return $out;
		}

		$out['is_logged_in'] = true;
		$out['wp_user_id']   = $wp_user_id;

		$user = get_userdata( $wp_user_id );
		if ( $user ) {
			$out['display_name'] = (string) $user->display_name;
		}

		$steam = $this->fetch_current_steam_row( $wp_user_id );
		if ( ! empty( $steam ) && isset( $steam['steam_uid'] ) && '' !== $steam['steam_uid'] ) {
			$out['is_linked']    = true;
			$out['steam_uid']    = (string) $steam['steam_uid'];
			if ( ! empty( $steam['steam_name'] ) ) {
				$out['display_name'] = (string) $steam['steam_name'];
			}
			if ( ! empty( $steam['avatar_url'] ) ) {
				$out['avatar_url'] = esc_url_raw( (string) $steam['avatar_url'] );
			}
		}

		return $out;
	}

	/**
	 * Return the Steam UID for the current WP user, or '' when not linked.
	 *
	 * @return string
	 */
	private function get_current_steam_uid() {
		$row = $this->fetch_current_steam_row( (int) get_current_user_id() );
		if ( ! empty( $row ) && isset( $row['steam_uid'] ) ) {
			return (string) $row['steam_uid'];
		}
		return '';
	}

	/**
	 * Fetch steam_uid / steam_name / avatar_url for a given WP user.
	 *
	 * @param int $wp_user_id WP user id.
	 * @return array
	 */
	private function fetch_current_steam_row( $wp_user_id ) {
		if ( $wp_user_id <= 0 ) {
			return array();
		}
		if ( ! class_exists( 'Psyern_AH_Database' ) ) {
			return array();
		}

		global $wpdb;
		$table = Psyern_AH_Database::get_table_name( 'users' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT steam_uid, steam_name, avatar_url FROM `' . $table . '` WHERE wp_user_id = %d LIMIT 1',
				$wp_user_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : array();
	}

	/**
	 * Build the normalized category list for marketplace filter UI.
	 *
	 * Mirrors Psyern_AH_Listings::get_categories_list() — duplicated here to
	 * avoid ever having templates touch the raw option.
	 *
	 * @return array[] List of { id: int, label: string }.
	 */
	private function get_category_list() {
		$raw = get_option( 'psyern_ah_categories', array() );
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
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
			}
		}
		return $out;
	}

	/**
	 * Human label for a listing_type enum value.
	 *
	 * @param int $t Listing type (0/1/2).
	 * @return string
	 */
	private function listing_type_label( $t ) {
		switch ( (int) $t ) {
			case 0:
				return __( 'Sofortkauf', 'psyerns-auctionhouse' );
			case 1:
				return __( 'Auktion', 'psyerns-auctionhouse' );
			case 2:
				return __( 'Auktion + Sofortkauf', 'psyerns-auctionhouse' );
		}
		return __( 'Unbekannt', 'psyerns-auctionhouse' );
	}

	/**
	 * Human label for a listing status enum value.
	 *
	 * @param int $s Status (0/1/2/3).
	 * @return string
	 */
	private function listing_status_label( $s ) {
		switch ( (int) $s ) {
			case 0:
				return __( 'Aktiv', 'psyerns-auctionhouse' );
			case 1:
				return __( 'Verkauft', 'psyerns-auctionhouse' );
			case 2:
				return __( 'Abgelaufen', 'psyerns-auctionhouse' );
			case 3:
				return __( 'Abgebrochen', 'psyerns-auctionhouse' );
		}
		return '';
	}

	/**
	 * Current request URL (used as return_to for Steam login).
	 *
	 * @return string
	 */
	private function current_url() {
		$scheme = is_ssl() ? 'https' : 'http';
		$host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_HOST'] ) ) : '';
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '/';
		if ( '' === $host ) {
			return home_url( '/' );
		}
		return $scheme . '://' . $host . $uri;
	}

	/**
	 * Resolve the admin-configured listing-detail page URL for a listing id.
	 *
	 * When `psyern_ah_listing_detail_url` is set, the listing_id is appended as
	 * a query var. When it is empty, we re-use the current page URL with the
	 * same query var so a single page with both shortcodes on it (marketplace
	 * + listing) still works end-to-end.
	 *
	 * @param string $listing_id Listing id.
	 * @return string URL.
	 */
	public static function build_listing_url( $listing_id ) {
		$listing_id = (string) $listing_id;
		$base       = (string) get_option( 'psyern_ah_listing_detail_url', '' );
		if ( '' === $base ) {
			$base = home_url( add_query_arg( array(), (string) ( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/' ) ) );
		}
		return add_query_arg( array( 'listing_id' => rawurlencode( $listing_id ) ), $base );
	}

	/**
	 * Format a unix timestamp as a localized, human-readable "expires in" string.
	 *
	 * @param int $ts Unix seconds.
	 * @return string
	 */
	public static function format_expires( $ts ) {
		$ts   = (int) $ts;
		$now  = time();
		if ( $ts <= 0 ) {
			return '';
		}
		if ( $ts <= $now ) {
			return __( 'abgelaufen', 'psyerns-auctionhouse' );
		}
		$diff = $ts - $now;
		return sprintf(
			/* translators: %s: human-readable time diff, e.g. "2 Stunden" */
			__( 'noch %s', 'psyerns-auctionhouse' ),
			human_time_diff( $now, $ts )
		);
	}
}

/**
 * Bootstrap — self-register on `init` when the class file is loaded.
 * Guarded so multiple requires don't double-bind the hook.
 */
if ( ! function_exists( 'psyern_ah_shortcodes_bootstrap' ) ) {
	/**
	 * Instantiate and register the shortcode class.
	 *
	 * @return void
	 */
	function psyern_ah_shortcodes_bootstrap() {
		static $instance = null;
		if ( null !== $instance ) {
			return;
		}
		$instance = new Psyern_AH_Shortcodes();
		$instance->register();
	}
	add_action( 'init', 'psyern_ah_shortcodes_bootstrap' );
}
