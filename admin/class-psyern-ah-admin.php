<?php
/**
 * Admin panel for Psyerns AuctionHouse.
 *
 * Registers the top-level "AuctionHouse" menu, the Settings-API fields, and
 * all `admin_post_*` handlers for side-effectful actions (rotate API key,
 * admin-cancel, force-resync, clear caches, reset data).
 *
 * Dispatches a single `render_page()` method to the correct `admin/views/*.php`
 * template based on `$_GET['tab']`. Views contain no logic — they only render.
 *
 * Security model:
 *   - All admin_post handlers verify a per-action nonce and require the
 *     `manage_options` capability before touching anything.
 *   - After every POST we PRG-redirect back to the referring tab with a
 *     transient-stored admin notice.
 *   - Views escape every value on output; the admin class only prepares data.
 *
 * @package Psyerns_AuctionHouse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Psyern_AH_Admin
 */
class Psyern_AH_Admin {

	/**
	 * Top-level menu slug.
	 */
	const MENU_SLUG = 'psyern-ah';

	/**
	 * Capability required to access all admin pages/handlers.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Settings API group for the Settings tab.
	 */
	const SETTINGS_GROUP = 'psyern_ah_settings';

	/**
	 * Transient that Agent 6's upload handler populates on every /internal/upload.
	 * Mirrors Psyern_AH_Upload::META_TRANSIENT_KEY (we do not hard-depend on that
	 * class — Phase 4 may load before/without Phase 2 classes).
	 */
	const UPLOAD_META_TRANSIENT = 'psyern_ah_upload_meta';

	/**
	 * Transient key used to signal a force-resync request to the mod.
	 */
	const FORCE_RESYNC_TRANSIENT = 'psyern_ah_force_resync';

	/**
	 * Force-resync flag lifetime (seconds). README §13 #15 notes v1 is a no-op
	 * flag the mod can consult; 5 min gives the 10s poller ample time to see it.
	 */
	const FORCE_RESYNC_TTL = 300;

	/**
	 * Allowed rarity values for the item-map JSON editor (Briefing 3).
	 *
	 * @var string[]
	 */
	const ITEM_MAP_RARITIES = array( 'common', 'uncommon', 'rare', 'epic', 'legendary' );

	/**
	 * Valid values for the public-visibility toggles.
	 *
	 * @var string[]
	 */
	const VISIBILITY_KEYS = array( 'marketplace', 'history', 'stats', 'my' );

	/**
	 * Canonical status-filter vocabulary for the Pending tab.
	 *
	 * Mirrors Psyern_AH_Pending_Actions' class docstring (Agent 6's own
	 * internal statuses + Agent 11's executor result statuses). Agent 6 does
	 * NOT expose a class-level constant for this list, so we define it here
	 * and keep it in sync manually. See report.
	 *
	 * @var string[]
	 */
	const STATUS_FILTER_OPTIONS = array(
		// Internal (set by Psyern_AH_Pending_Actions).
		'queued',
		'dispatched',
		'executing',
		// Terminal — success.
		'success',
		// Terminal — failures reported by PF_AH_ActionExecutor (mod-side).
		'failed_not_enough_money',
		'failed_listing_not_found',
		'failed_listing_expired',
		'failed_bid_too_low',
		'failed_max_listings_reached',
		'failed_max_bids_reached',
		'failed_item_not_in_inventory',
		'failed_cannot_cancel_with_bids',
		'failed_own_listing',
		'failed_invalid_price',
		'failed_server_error',
		'failed_unknown_type',
		'failed_null_action',
		'failed_dme_ah_missing',
		'failed_executor_missing',
		'failed_unknown',
	);

	/**
	 * Default capacity (rows per admin table page).
	 */
	const DEFAULT_PER_PAGE = 25;

	/**
	 * Register all admin hooks. Called from the main plugin bootstrap.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );

		add_action( 'admin_post_psyern_ah_rotate_key', array( $this, 'handle_rotate_key' ) );
		add_action( 'admin_post_psyern_ah_admin_cancel', array( $this, 'handle_admin_cancel' ) );
		add_action( 'admin_post_psyern_ah_force_resync', array( $this, 'handle_force_resync' ) );
		add_action( 'admin_post_psyern_ah_clear_caches', array( $this, 'handle_clear_caches' ) );
		add_action( 'admin_post_psyern_ah_reset_data', array( $this, 'handle_reset_data' ) );
	}

	// ======================================================================
	// Menu
	// ======================================================================

	/**
	 * Register the top-level "AuctionHouse" menu page.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Psyerns AuctionHouse', 'psyerns-auctionhouse' ),
			__( 'AuctionHouse', 'psyerns-auctionhouse' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-cart',
			56
		);
	}

	// ======================================================================
	// Settings API
	// ======================================================================

	/**
	 * Register all plugin options with the Settings API.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			'psyern_ah_api_key',
			array( 'sanitize_callback' => 'sanitize_text_field' )
		);

		register_setting(
			self::SETTINGS_GROUP,
			'psyern_ah_steam_api_key',
			array( 'sanitize_callback' => 'sanitize_text_field' )
		);

		register_setting(
			self::SETTINGS_GROUP,
			'psyern_ah_currency_format',
			array(
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '{amount} €',
			)
		);

		register_setting(
			self::SETTINGS_GROUP,
			'psyern_ah_push_interval_seconds',
			array(
				'sanitize_callback' => array( $this, 'sanitize_push_interval' ),
				'default'           => 30,
			)
		);

		register_setting(
			self::SETTINGS_GROUP,
			'psyern_ah_poll_interval_seconds',
			array(
				'sanitize_callback' => array( $this, 'sanitize_poll_interval' ),
				'default'           => 10,
			)
		);

		register_setting(
			self::SETTINGS_GROUP,
			'psyern_ah_default_theme',
			array(
				'sanitize_callback' => array( $this, 'sanitize_theme_slug' ),
				'default'           => 'default',
			)
		);

		register_setting(
			self::SETTINGS_GROUP,
			'psyern_ah_listing_detail_url',
			array(
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
			)
		);

		register_setting(
			self::SETTINGS_GROUP,
			'psyern_ah_public_visibility',
			array(
				'sanitize_callback' => array( $this, 'sanitize_visibility' ),
			)
		);

		register_setting(
			self::SETTINGS_GROUP,
			'psyern_ah_item_map',
			array( 'sanitize_callback' => array( $this, 'sanitize_item_map_json' ) )
		);

		register_setting(
			self::SETTINGS_GROUP,
			'psyern_ah_categories',
			array( 'sanitize_callback' => array( $this, 'sanitize_categories_json' ) )
		);
	}

	/**
	 * Sanitize push interval (mod -> WP full upload). README §12 default 30s;
	 * clamp to [10, 3600] so the mod cannot be told to spam.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public function sanitize_push_interval( $value ) {
		$int = (int) $value;
		if ( $int < 10 ) {
			$int = 10;
		}
		if ( $int > 3600 ) {
			$int = 3600;
		}
		return $int;
	}

	/**
	 * Sanitize poll interval (mod -> WP pending-action poll). README §12 default
	 * 10s; clamp to [3, 300].
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public function sanitize_poll_interval( $value ) {
		$int = (int) $value;
		if ( $int < 3 ) {
			$int = 3;
		}
		if ( $int > 300 ) {
			$int = 300;
		}
		return $int;
	}

	/**
	 * Sanitize theme slug against the set published by Psyern_AH_Theme (when
	 * present) or the single fallback 'default'.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_theme_slug( $value ) {
		$slug    = sanitize_key( (string) $value );
		$allowed = $this->get_available_themes();
		if ( '' === $slug || ! in_array( $slug, $allowed, true ) ) {
			return 'default';
		}
		return $slug;
	}

	/**
	 * Sanitize the public-visibility checkbox array.
	 *
	 * Form field posts `psyern_ah_public_visibility[marketplace]=1`, etc.
	 * Anything not in self::VISIBILITY_KEYS is dropped.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string,int>
	 */
	public function sanitize_visibility( $value ) {
		$out = array();
		if ( ! is_array( $value ) ) {
			$value = array();
		}
		foreach ( self::VISIBILITY_KEYS as $key ) {
			$out[ $key ] = ! empty( $value[ $key ] ) ? 1 : 0;
		}
		return $out;
	}

	/**
	 * Validate the item-map JSON textarea (Briefing 3).
	 *
	 * Accepts the finalized schema:
	 *   {
	 *     "version": 1,
	 *     "default_icon_url": "https://...",
	 *     "items": {
	 *       "<item_class>": { display_name?, icon_url, rarity?, category_hint? }
	 *     }
	 *   }
	 *
	 * On failure we:
	 *   - queue a transient admin notice with the specific error,
	 *   - return the previous value (never overwrite the stored JSON with garbage).
	 *
	 * @param mixed $value Raw POSTed string.
	 * @return string JSON string to store.
	 */
	public function sanitize_item_map_json( $value ) {
		$previous = (string) get_option( 'psyern_ah_item_map', '' );
		$raw      = is_string( $value ) ? trim( wp_unslash( $value ) ) : '';

		if ( '' === $raw ) {
			return wp_json_encode(
				array(
					'version'          => 1,
					'default_icon_url' => '',
					'items'            => (object) array(),
				)
			);
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			$this->queue_notice( 'error', __( 'Item-Map: JSON is not valid (parser failed). Previous value kept.', 'psyerns-auctionhouse' ) );
			return '' !== $previous ? $previous : $raw;
		}

		if ( ! isset( $decoded['version'] ) || 1 !== (int) $decoded['version'] ) {
			$this->queue_notice( 'error', __( 'Item-Map: missing or unsupported "version" (must be 1). Previous value kept.', 'psyerns-auctionhouse' ) );
			return '' !== $previous ? $previous : $raw;
		}

		$default_icon = isset( $decoded['default_icon_url'] ) ? (string) $decoded['default_icon_url'] : '';
		if ( '' !== $default_icon ) {
			$clean = esc_url_raw( $default_icon );
			if ( '' === $clean ) {
				$this->queue_notice( 'error', __( 'Item-Map: "default_icon_url" is not a valid URL. Previous value kept.', 'psyerns-auctionhouse' ) );
				return '' !== $previous ? $previous : $raw;
			}
			$default_icon = $clean;
		}

		if ( ! isset( $decoded['items'] ) || ! is_array( $decoded['items'] ) ) {
			$this->queue_notice( 'error', __( 'Item-Map: "items" must be an object. Previous value kept.', 'psyerns-auctionhouse' ) );
			return '' !== $previous ? $previous : $raw;
		}

		$clean_items = array();
		foreach ( $decoded['items'] as $class_name => $entry ) {
			$class_name = sanitize_text_field( (string) $class_name );
			if ( '' === $class_name ) {
				continue;
			}
			if ( ! is_array( $entry ) ) {
				$this->queue_notice(
					'error',
					sprintf(
						/* translators: %s: item class name */
						__( 'Item-Map: entry "%s" is not an object. Previous value kept.', 'psyerns-auctionhouse' ),
						$class_name
					)
				);
				return '' !== $previous ? $previous : $raw;
			}

			$clean_entry = array();

			if ( isset( $entry['icon_url'] ) && '' !== $entry['icon_url'] ) {
				$clean_icon = esc_url_raw( (string) $entry['icon_url'] );
				if ( '' === $clean_icon ) {
					$this->queue_notice(
						'error',
						sprintf(
							/* translators: %s: item class name */
							__( 'Item-Map: "%s" has an invalid icon_url. Previous value kept.', 'psyerns-auctionhouse' ),
							$class_name
						)
					);
					return '' !== $previous ? $previous : $raw;
				}
				$clean_entry['icon_url'] = $clean_icon;
			}

			if ( isset( $entry['display_name'] ) ) {
				if ( ! is_string( $entry['display_name'] ) ) {
					$this->queue_notice(
						'error',
						sprintf(
							/* translators: %s: item class name */
							__( 'Item-Map: "%s" display_name must be a string. Previous value kept.', 'psyerns-auctionhouse' ),
							$class_name
						)
					);
					return '' !== $previous ? $previous : $raw;
				}
				$clean_entry['display_name'] = sanitize_text_field( $entry['display_name'] );
			}

			if ( isset( $entry['rarity'] ) ) {
				$rarity = is_string( $entry['rarity'] ) ? strtolower( $entry['rarity'] ) : '';
				if ( ! in_array( $rarity, self::ITEM_MAP_RARITIES, true ) ) {
					$this->queue_notice(
						'error',
						sprintf(
							/* translators: 1: item class name, 2: comma-separated rarity list */
							__( 'Item-Map: "%1$s" has invalid rarity. Allowed: %2$s. Previous value kept.', 'psyerns-auctionhouse' ),
							$class_name,
							implode( ', ', self::ITEM_MAP_RARITIES )
						)
					);
					return '' !== $previous ? $previous : $raw;
				}
				$clean_entry['rarity'] = $rarity;
			}

			if ( isset( $entry['category_hint'] ) ) {
				if ( ! is_string( $entry['category_hint'] ) ) {
					$this->queue_notice(
						'error',
						sprintf(
							/* translators: %s: item class name */
							__( 'Item-Map: "%s" category_hint must be a string. Previous value kept.', 'psyerns-auctionhouse' ),
							$class_name
						)
					);
					return '' !== $previous ? $previous : $raw;
				}
				$clean_entry['category_hint'] = sanitize_text_field( $entry['category_hint'] );
			}

			$clean_items[ $class_name ] = $clean_entry;
		}

		$normalized = array(
			'version'          => 1,
			'default_icon_url' => $default_icon,
			'items'            => empty( $clean_items ) ? (object) array() : $clean_items,
		);

		$encoded = wp_json_encode( $normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $encoded ) {
			$this->queue_notice( 'error', __( 'Item-Map: could not re-encode JSON. Previous value kept.', 'psyerns-auctionhouse' ) );
			return '' !== $previous ? $previous : $raw;
		}

		$this->queue_notice( 'success', __( 'Item-Map saved.', 'psyerns-auctionhouse' ) );
		return $encoded;
	}

	/**
	 * Validate the categories JSON textarea. Same failure pattern as the item
	 * map — invalid JSON → previous value preserved + error notice.
	 *
	 * @param mixed $value Raw POSTed string.
	 * @return string JSON string to store.
	 */
	public function sanitize_categories_json( $value ) {
		$previous = (string) get_option( 'psyern_ah_categories', '' );
		$raw      = is_string( $value ) ? trim( wp_unslash( $value ) ) : '';

		if ( '' === $raw ) {
			return wp_json_encode( array() );
		}

		$decoded = json_decode( $raw, true );
		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			$this->queue_notice( 'error', __( 'Categories: JSON is not valid (parser failed). Previous value kept.', 'psyerns-auctionhouse' ) );
			return '' !== $previous ? $previous : '[]';
		}

		$encoded = wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $encoded ) {
			return '' !== $previous ? $previous : '[]';
		}

		return $encoded;
	}

	// ======================================================================
	// Asset enqueue
	// ======================================================================

	/**
	 * Enqueue admin CSS + JS on our pages only.
	 *
	 * @param string $hook Current admin page hook (e.g. "toplevel_page_psyern-ah").
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, self::MENU_SLUG ) ) {
			return;
		}

		$version = defined( 'PSYERN_AH_VERSION' ) ? PSYERN_AH_VERSION : '1.0.0';
		$url     = defined( 'PSYERN_AH_PLUGIN_URL' ) ? PSYERN_AH_PLUGIN_URL : plugin_dir_url( dirname( __FILE__ ) . '/../psyerns-auctionhouse.php' );

		wp_enqueue_style(
			'psyern-ah-admin',
			$url . 'admin/css/psyern-ah-admin.css',
			array( 'dashicons' ),
			$version
		);

		wp_enqueue_script(
			'psyern-ah-admin-tabs',
			$url . 'admin/js/psyern-ah-admin-tabs.js',
			array( 'jquery' ),
			$version,
			true
		);

		wp_localize_script(
			'psyern-ah-admin-tabs',
			'PsyernAHAdmin',
			array(
				'i18n' => array(
					'confirmRotate' => __( 'Rotate the API key now? The DayZ mod config must be updated with the new key or uploads will fail.', 'psyerns-auctionhouse' ),
					'confirmCancel' => __( 'Cancel this listing? Items will be returned to the seller via pending-pickup.', 'psyerns-auctionhouse' ),
					'resetLiteral'  => 'RESET',
				),
			)
		);
	}

	// ======================================================================
	// Page dispatcher
	// ======================================================================

	/**
	 * Dispatch to the right view based on ?tab=.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'psyerns-auctionhouse' ) );
		}

		$tab       = $this->get_active_tab();
		$tabs      = $this->get_tab_definitions();
		$page_url  = $this->get_page_url();
		$view_file = PSYERN_AH_PLUGIN_DIR . 'admin/views/' . $tab . '-page.php';

		if ( ! file_exists( $view_file ) ) {
			$view_file = PSYERN_AH_PLUGIN_DIR . 'admin/views/settings-page.php';
		}

		// Data prep per tab — views render only.
		$view_data = $this->prepare_view_data( $tab );

		include $view_file;
	}

	/**
	 * Build the tabs map. Source of truth for tab slugs and labels.
	 *
	 * @return array<string,array{label:string,icon:string}>
	 */
	public function get_tab_definitions() {
		return array(
			'settings' => array(
				'label' => __( 'Settings', 'psyerns-auctionhouse' ),
				'icon'  => 'dashicons-admin-generic',
			),
			'listings' => array(
				'label' => __( 'Listings', 'psyerns-auctionhouse' ),
				'icon'  => 'dashicons-list-view',
			),
			'history'  => array(
				'label' => __( 'History', 'psyerns-auctionhouse' ),
				'icon'  => 'dashicons-backup',
			),
			'balances' => array(
				'label' => __( 'Balances', 'psyerns-auctionhouse' ),
				'icon'  => 'dashicons-money-alt',
			),
			'pending'  => array(
				'label' => __( 'Pending', 'psyerns-auctionhouse' ),
				'icon'  => 'dashicons-update',
			),
			'tools'    => array(
				'label' => __( 'Tools', 'psyerns-auctionhouse' ),
				'icon'  => 'dashicons-admin-tools',
			),
		);
	}

	/**
	 * Resolve the active tab slug from the query string.
	 *
	 * @return string
	 */
	public function get_active_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		$tabs = $this->get_tab_definitions();
		if ( ! isset( $tabs[ $raw ] ) ) {
			return 'settings';
		}
		return $raw;
	}

	/**
	 * Admin page URL (base, without tab query arg).
	 *
	 * @return string
	 */
	public function get_page_url() {
		return admin_url( 'admin.php?page=' . self::MENU_SLUG );
	}

	/**
	 * Prepare all data a view needs. Returns an array the view file can destructure.
	 *
	 * @param string $tab Active tab slug.
	 * @return array
	 */
	private function prepare_view_data( $tab ) {
		switch ( $tab ) {
			case 'listings':
				return $this->prepare_listings_data();
			case 'history':
				return $this->prepare_history_data();
			case 'balances':
				return $this->prepare_balances_data();
			case 'pending':
				return $this->prepare_pending_data();
			case 'tools':
				return $this->prepare_tools_data();
			case 'settings':
			default:
				return $this->prepare_settings_data();
		}
	}

	// ======================================================================
	// View-data preparation
	// ======================================================================

	/**
	 * Prepare data for the Settings view.
	 *
	 * @return array
	 */
	private function prepare_settings_data() {
		$meta = get_transient( self::UPLOAD_META_TRANSIENT );
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}

		$last_upload_at = isset( $meta['last_upload_at'] ) ? (int) $meta['last_upload_at'] : 0;
		$age_seconds    = $last_upload_at > 0 ? max( 0, time() - $last_upload_at ) : -1;

		if ( $age_seconds < 0 || $age_seconds > 300 ) {
			$indicator = 'red';
		} elseif ( $age_seconds > 90 ) {
			$indicator = 'yellow';
		} else {
			$indicator = 'green';
		}

		$item_map_raw = (string) get_option( 'psyern_ah_item_map', '' );
		$item_map     = json_decode( $item_map_raw, true );
		$preview_rows = array();
		if ( is_array( $item_map ) && isset( $item_map['items'] ) && is_array( $item_map['items'] ) ) {
			$count = 0;
			foreach ( $item_map['items'] as $class_name => $entry ) {
				if ( $count >= 5 ) {
					break;
				}
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$preview_rows[] = array(
					'class_name'    => (string) $class_name,
					'display_name'  => isset( $entry['display_name'] ) ? (string) $entry['display_name'] : '',
					'icon_url'      => isset( $entry['icon_url'] ) ? (string) $entry['icon_url'] : '',
					'rarity'        => isset( $entry['rarity'] ) ? (string) $entry['rarity'] : '',
					'category_hint' => isset( $entry['category_hint'] ) ? (string) $entry['category_hint'] : '',
				);
				++$count;
			}
		}

		$visibility_raw = get_option( 'psyern_ah_public_visibility', array() );
		$visibility     = array();
		foreach ( self::VISIBILITY_KEYS as $key ) {
			$visibility[ $key ] = is_array( $visibility_raw ) && ! empty( $visibility_raw[ $key ] ) ? 1 : 0;
		}

		return array(
			'api_key'          => (string) get_option( 'psyern_ah_api_key', '' ),
			'steam_api_key'    => (string) get_option( 'psyern_ah_steam_api_key', '' ),
			'currency_format'  => (string) get_option( 'psyern_ah_currency_format', '{amount} €' ),
			'push_interval'    => (int) get_option( 'psyern_ah_push_interval_seconds', 30 ),
			'poll_interval'    => (int) get_option( 'psyern_ah_poll_interval_seconds', 10 ),
			'default_theme'    => (string) get_option( 'psyern_ah_default_theme', 'default' ),
			'detail_url'       => (string) get_option( 'psyern_ah_listing_detail_url', '' ),
			'visibility'       => $visibility,
			'available_themes' => $this->get_available_themes(),
			'item_map_raw'     => $item_map_raw,
			'item_map_preview' => $preview_rows,
			'categories_raw'   => (string) get_option( 'psyern_ah_categories', '[]' ),
			'upload_meta'      => $meta,
			'upload_age'       => $age_seconds,
			'upload_indicator' => $indicator,
			'rest_base'        => esc_url_raw( rest_url( 'psyern-ah/v1' ) ),
		);
	}

	/**
	 * Prepare data for the Listings view.
	 *
	 * Returns empty items array with a zero-total if the Listings service is
	 * not loaded, so the page still renders without PHP errors.
	 *
	 * @return array
	 */
	private function prepare_listings_data() {
		$service_available = class_exists( 'Psyern_AH_Listings' );
		$status_filter     = $this->query_string_param( 'status', '' );
		$search            = $this->query_string_param( 'search', '' );
		$page              = max( 1, (int) $this->query_string_param( 'paged', 1 ) );
		$per_page          = self::DEFAULT_PER_PAGE;

		$items = array();
		$total = 0;

		if ( $service_available ) {
			$args = array(
				'page'     => $page,
				'per_page' => $per_page,
				'search'   => $search,
			);

			if ( '' !== $status_filter && 'all' !== $status_filter ) {
				$args['status'] = (int) $status_filter;
			} elseif ( 'all' === $status_filter ) {
				// Null status triggers "no status filter" if the service supports it;
				// default behaviour of get_listings() is to force status=0.
				$args['status'] = null;
			}

			$svc    = new Psyern_AH_Listings();
			$result = $svc->get_listings( $args );
			if ( is_array( $result ) ) {
				$items = isset( $result['items'] ) && is_array( $result['items'] ) ? $result['items'] : array();
				$total = isset( $result['total'] ) ? (int) $result['total'] : 0;
			}
		}

		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;
		if ( $total_pages < 1 ) {
			$total_pages = 1;
		}

		return array(
			'service_available' => $service_available,
			'items'             => $items,
			'total'             => $total,
			'page'              => $page,
			'per_page'          => $per_page,
			'total_pages'       => $total_pages,
			'status_filter'     => $status_filter,
			'search'            => $search,
			'status_map'        => array(
				'all' => __( 'All statuses', 'psyerns-auctionhouse' ),
				'0'   => __( 'Active', 'psyerns-auctionhouse' ),
				'1'   => __( 'Sold', 'psyerns-auctionhouse' ),
				'2'   => __( 'Expired', 'psyerns-auctionhouse' ),
				'3'   => __( 'Cancelled', 'psyerns-auctionhouse' ),
			),
			'currency_format'   => (string) get_option( 'psyern_ah_currency_format', '{amount} €' ),
		);
	}

	/**
	 * Prepare data for the History view.
	 *
	 * @return array
	 */
	private function prepare_history_data() {
		$service_available = class_exists( 'Psyern_AH_Transactions' );
		$page              = max( 1, (int) $this->query_string_param( 'paged', 1 ) );
		$per_page          = self::DEFAULT_PER_PAGE;
		$search            = $this->query_string_param( 'search', '' );
		$type              = $this->query_string_param( 'type', '' );
		$date_from         = $this->query_string_param( 'date_from', '' );
		$date_to           = $this->query_string_param( 'date_to', '' );

		$items = array();
		$total = 0;

		if ( $service_available ) {
			$svc      = new Psyern_AH_Transactions();
			$offset   = ( $page - 1 ) * $per_page;
			$raw      = $svc->get_recent( $per_page * 10, 0 ); // Fetch a window to filter in PHP (v1 pragmatic — count acceptable for typical volumes).
			$filtered = array();

			$from_ts = $this->parse_date_to_ts( $date_from, false );
			$to_ts   = $this->parse_date_to_ts( $date_to, true );

			foreach ( $raw as $row ) {
				if ( '' !== $search ) {
					$hay = strtolower(
						( isset( $row['buyer_name'] ) ? (string) $row['buyer_name'] : '' ) . ' ' .
						( isset( $row['seller_name'] ) ? (string) $row['seller_name'] : '' ) . ' ' .
						( isset( $row['item_display'] ) ? (string) $row['item_display'] : '' )
					);
					if ( false === strpos( $hay, strtolower( $search ) ) ) {
						continue;
					}
				}
				if ( '' !== $type && '' !== $type && (string) $row['type'] !== $type ) {
					continue;
				}
				$ts = isset( $row['timestamp'] ) ? (int) $row['timestamp'] : 0;
				if ( null !== $from_ts && $ts < $from_ts ) {
					continue;
				}
				if ( null !== $to_ts && $ts > $to_ts ) {
					continue;
				}
				$filtered[] = $row;
			}

			$total = count( $filtered );
			$items = array_slice( $filtered, $offset, $per_page );
		}

		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;
		if ( $total_pages < 1 ) {
			$total_pages = 1;
		}

		return array(
			'service_available' => $service_available,
			'items'             => $items,
			'total'             => $total,
			'page'              => $page,
			'per_page'          => $per_page,
			'total_pages'       => $total_pages,
			'search'            => $search,
			'type'              => $type,
			'date_from'         => $date_from,
			'date_to'           => $date_to,
			'type_map'          => array(
				''  => __( 'All types', 'psyerns-auctionhouse' ),
				'0' => __( 'BuyNow', 'psyerns-auctionhouse' ),
				'1' => __( 'AuctionWon', 'psyerns-auctionhouse' ),
				'2' => __( 'Expired', 'psyerns-auctionhouse' ),
				'3' => __( 'Cancelled', 'psyerns-auctionhouse' ),
			),
			'currency_format'   => (string) get_option( 'psyern_ah_currency_format', '{amount} €' ),
		);
	}

	/**
	 * Prepare data for the Balances view.
	 *
	 * @return array
	 */
	private function prepare_balances_data() {
		$service_available = class_exists( 'Psyern_AH_Balances' );
		$page              = max( 1, (int) $this->query_string_param( 'paged', 1 ) );
		$per_page          = self::DEFAULT_PER_PAGE;
		$search            = $this->query_string_param( 'search', '' );
		$source            = $this->query_string_param( 'source', '' );
		$orderby           = $this->query_string_param( 'orderby', 'updated_at' );
		$order             = $this->query_string_param( 'order', 'DESC' );

		$items = array();
		$total = 0;

		if ( $service_available ) {
			$svc    = new Psyern_AH_Balances();
			$offset = ( $page - 1 ) * $per_page;
			$result = $svc->get_all_balances(
				array(
					'source'  => $source,
					'search'  => $search,
					'limit'   => $per_page,
					'offset'  => $offset,
					'orderby' => $orderby,
					'order'   => $order,
				)
			);
			if ( is_array( $result ) ) {
				$items = isset( $result['items'] ) && is_array( $result['items'] ) ? $result['items'] : array();
				$total = isset( $result['total'] ) ? (int) $result['total'] : 0;
			}
		}

		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;
		if ( $total_pages < 1 ) {
			$total_pages = 1;
		}

		return array(
			'service_available' => $service_available,
			'items'             => $items,
			'total'             => $total,
			'page'              => $page,
			'per_page'          => $per_page,
			'total_pages'       => $total_pages,
			'search'            => $search,
			'source'            => $source,
			'orderby'           => $orderby,
			'order'             => $order,
			'source_map'        => array(
				''          => __( 'All sources', 'psyerns-auctionhouse' ),
				'Expansion' => __( 'Expansion (ATM wallet)', 'psyerns-auctionhouse' ),
				'Internal'  => __( 'Internal (PlayerData.json)', 'psyerns-auctionhouse' ),
			),
			'currency_format'   => (string) get_option( 'psyern_ah_currency_format', '{amount} €' ),
		);
	}

	/**
	 * Prepare data for the Pending view.
	 *
	 * @return array
	 */
	private function prepare_pending_data() {
		global $wpdb;

		$table_exists = class_exists( 'Psyern_AH_Database' );
		$page         = max( 1, (int) $this->query_string_param( 'paged', 1 ) );
		$per_page     = self::DEFAULT_PER_PAGE;
		$status       = $this->query_string_param( 'status', '' );
		$action_type  = $this->query_string_param( 'action_type', '' );
		$date_from    = $this->query_string_param( 'date_from', '' );
		$date_to      = $this->query_string_param( 'date_to', '' );

		$items = array();
		$total = 0;

		if ( $table_exists ) {
			$table        = Psyern_AH_Database::get_table_name( 'pending_actions' );
			$where        = array( '1=1' );
			$where_values = array();

			if ( '' !== $status && in_array( $status, self::STATUS_FILTER_OPTIONS, true ) ) {
				$where[]        = 'status = %s';
				$where_values[] = $status;
			}

			if ( '' !== $action_type ) {
				$allowed_types = array( 'purchase', 'bid', 'cancel', 'admin_cancel' );
				if ( in_array( $action_type, $allowed_types, true ) ) {
					$where[]        = 'action_type = %s';
					$where_values[] = $action_type;
				}
			}

			$from_ts = $this->parse_date_to_mysql( $date_from, false );
			$to_ts   = $this->parse_date_to_mysql( $date_to, true );

			if ( null !== $from_ts ) {
				$where[]        = 'created_at >= %s';
				$where_values[] = $from_ts;
			}
			if ( null !== $to_ts ) {
				$where[]        = 'created_at <= %s';
				$where_values[] = $to_ts;
			}

			$where_sql = implode( ' AND ', $where );

			// Count.
			$count_sql = 'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where_sql;
			if ( ! empty( $where_values ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $where_values ) );
			} else {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$total = (int) $wpdb->get_var( $count_sql );
			}

			// Rows.
			$offset      = ( $page - 1 ) * $per_page;
			$select_sql  = 'SELECT id, action_uuid, action_type, player_uid, listing_id, amount, status, result_code, result_message, created_at, dispatched_at, completed_at FROM ' . $table
				. ' WHERE ' . $where_sql
				. ' ORDER BY id DESC LIMIT %d OFFSET %d';
			$select_args = array_merge( $where_values, array( $per_page, $offset ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( $select_sql, $select_args ), ARRAY_A );
			if ( is_array( $rows ) ) {
				$items = $rows;
			}
		}

		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;
		if ( $total_pages < 1 ) {
			$total_pages = 1;
		}

		return array(
			'service_available' => $table_exists,
			'items'             => $items,
			'total'             => $total,
			'page'              => $page,
			'per_page'          => $per_page,
			'total_pages'       => $total_pages,
			'status'            => $status,
			'action_type'       => $action_type,
			'date_from'         => $date_from,
			'date_to'           => $date_to,
			'status_options'    => self::STATUS_FILTER_OPTIONS,
			'type_options'      => array( 'purchase', 'bid', 'cancel', 'admin_cancel' ),
		);
	}

	/**
	 * Prepare data for the Tools view.
	 *
	 * @return array
	 */
	private function prepare_tools_data() {
		$resync_flag = get_transient( self::FORCE_RESYNC_TRANSIENT );

		return array(
			'force_resync_active' => ! empty( $resync_flag ),
			'force_resync_ttl'    => self::FORCE_RESYNC_TTL,
		);
	}

	// ======================================================================
	// POST handlers
	// ======================================================================

	/**
	 * admin_post_psyern_ah_rotate_key handler.
	 *
	 * @return void
	 */
	public function handle_rotate_key() {
		$this->assert_cap();
		check_admin_referer( 'psyern_ah_rotate_key' );

		if ( ! class_exists( 'Psyern_AH_Auth' ) || ! method_exists( 'Psyern_AH_Auth', 'rotate_api_key' ) ) {
			$this->queue_notice( 'error', __( 'Auth service unavailable — cannot rotate API key.', 'psyerns-auctionhouse' ) );
			$this->redirect_back( 'settings' );
		}

		$new_key = Psyern_AH_Auth::rotate_api_key();

		$this->queue_notice(
			'success',
			sprintf(
				/* translators: %s: new API key */
				__( 'API key rotated. New key: %s — update the DayZ mod config immediately.', 'psyerns-auctionhouse' ),
				'<code>' . esc_html( $new_key ) . '</code>'
			)
		);

		$this->redirect_back( 'settings' );
	}

	/**
	 * admin_post_psyern_ah_admin_cancel handler.
	 *
	 * @return void
	 */
	public function handle_admin_cancel() {
		$this->assert_cap();
		check_admin_referer( 'psyern_ah_admin_cancel' );

		$listing_id = isset( $_POST['listing_id'] ) ? sanitize_text_field( wp_unslash( $_POST['listing_id'] ) ) : '';
		$seller_uid = isset( $_POST['seller_uid'] ) ? sanitize_text_field( wp_unslash( $_POST['seller_uid'] ) ) : '';

		if ( '' === $listing_id || '' === $seller_uid ) {
			$this->queue_notice( 'error', __( 'Admin-Cancel: missing listing_id or seller_uid.', 'psyerns-auctionhouse' ) );
			$this->redirect_back( 'listings' );
		}

		if ( ! class_exists( 'Psyern_AH_Pending_Actions' ) ) {
			$this->queue_notice( 'error', __( 'Pending-Actions service unavailable — cannot enqueue admin-cancel.', 'psyerns-auctionhouse' ) );
			$this->redirect_back( 'listings' );
		}

		$svc    = new Psyern_AH_Pending_Actions();
		$result = $svc->enqueue( 'admin_cancel', $seller_uid, $listing_id, 0, '' );

		if ( is_wp_error( $result ) ) {
			$this->queue_notice(
				'error',
				sprintf(
					/* translators: 1: error code, 2: error message */
					__( 'Admin-Cancel failed: %1$s — %2$s', 'psyerns-auctionhouse' ),
					esc_html( $result->get_error_code() ),
					esc_html( $result->get_error_message() )
				)
			);
		} else {
			$this->queue_notice(
				'success',
				sprintf(
					/* translators: 1: listing id, 2: action uuid */
					__( 'Admin-Cancel queued for listing %1$s (uuid: %2$s). Items will be returned to the seller.', 'psyerns-auctionhouse' ),
					'<code>' . esc_html( $listing_id ) . '</code>',
					'<code>' . esc_html( (string) $result ) . '</code>'
				)
			);
		}

		$this->redirect_back( 'listings' );
	}

	/**
	 * admin_post_psyern_ah_force_resync handler.
	 *
	 * @return void
	 */
	public function handle_force_resync() {
		$this->assert_cap();
		check_admin_referer( 'psyern_ah_force_resync' );

		set_transient( self::FORCE_RESYNC_TRANSIENT, (int) time(), self::FORCE_RESYNC_TTL );

		$this->queue_notice(
			'success',
			sprintf(
				/* translators: %d: ttl in seconds */
				__( 'Force Re-Sync flag set (TTL %d s). The mod will read the flag on its next push.', 'psyerns-auctionhouse' ),
				self::FORCE_RESYNC_TTL
			)
		);

		$this->redirect_back( 'tools' );
	}

	/**
	 * admin_post_psyern_ah_clear_caches handler.
	 *
	 * @return void
	 */
	public function handle_clear_caches() {
		$this->assert_cap();
		check_admin_referer( 'psyern_ah_clear_caches' );

		$scope   = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'all';
		$results = array();

		if ( 'price_history' === $scope || 'all' === $scope ) {
			if ( class_exists( 'Psyern_AH_Stats' ) && method_exists( 'Psyern_AH_Stats', 'invalidate_all_price_history_cache' ) ) {
				Psyern_AH_Stats::invalidate_all_price_history_cache();
				$results[] = __( 'price-history cache', 'psyerns-auctionhouse' );
			} else {
				// Pragmatic fallback: wipe our transients via the DB.
				$this->wipe_transients_like( 'psyern_ah_price_history_' );
				$results[] = __( 'price-history transients', 'psyerns-auctionhouse' );
			}
		}

		if ( 'stats' === $scope || 'all' === $scope ) {
			$this->wipe_transients_like( 'psyern_ah_stats_' );
			$results[] = __( 'stats transients', 'psyerns-auctionhouse' );
		}

		$this->queue_notice(
			'success',
			sprintf(
				/* translators: %s: comma-separated list of cleared scopes */
				__( 'Cleared: %s', 'psyerns-auctionhouse' ),
				implode( ', ', $results )
			)
		);

		$this->redirect_back( 'tools' );
	}

	/**
	 * admin_post_psyern_ah_reset_data handler.
	 *
	 * Two-step confirmation: the POST must include `confirm=RESET` (literally,
	 * case-sensitive), OR we bail out.
	 *
	 * @return void
	 */
	public function handle_reset_data() {
		$this->assert_cap();
		check_admin_referer( 'psyern_ah_reset_data' );

		$confirm = isset( $_POST['confirm'] ) ? (string) wp_unslash( $_POST['confirm'] ) : '';
		if ( 'RESET' !== $confirm ) {
			$this->queue_notice( 'error', __( 'Reset not performed: confirmation token mismatch (type RESET exactly).', 'psyerns-auctionhouse' ) );
			$this->redirect_back( 'tools' );
		}

		if ( ! class_exists( 'Psyern_AH_Database' ) ) {
			$this->queue_notice( 'error', __( 'Database helper not loaded — cannot reset.', 'psyerns-auctionhouse' ) );
			$this->redirect_back( 'tools' );
		}

		global $wpdb;
		$tables = array( 'pending_actions', 'transactions', 'balances', 'listings', 'users' );
		foreach ( $tables as $suffix ) {
			$table = Psyern_AH_Database::get_table_name( $suffix );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( 'TRUNCATE TABLE ' . $table );
		}

		$this->queue_notice(
			'success',
			__( 'All plugin data (listings, transactions, balances, pending actions, users) has been wiped. Plugin options kept.', 'psyerns-auctionhouse' )
		);

		$this->redirect_back( 'tools' );
	}

	// ======================================================================
	// Notices
	// ======================================================================

	/**
	 * Queue a transient-stored admin notice for the current user.
	 *
	 * @param string $kind "success" | "error" | "warning".
	 * @param string $message Safe HTML (pre-escaped).
	 * @return void
	 */
	private function queue_notice( $kind, $message ) {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$bucket = get_transient( 'psyern_ah_admin_notice_' . $user_id );
		if ( ! is_array( $bucket ) ) {
			$bucket = array();
		}

		$bucket[] = array(
			'kind'    => in_array( $kind, array( 'success', 'error', 'warning' ), true ) ? $kind : 'warning',
			'message' => (string) $message,
		);

		set_transient( 'psyern_ah_admin_notice_' . $user_id, $bucket, 60 );
	}

	/**
	 * admin_notices renderer — draws and clears the transient bucket.
	 *
	 * @return void
	 */
	public function render_notices() {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false === strpos( (string) $screen->id, self::MENU_SLUG ) ) {
			return;
		}

		$bucket = get_transient( 'psyern_ah_admin_notice_' . $user_id );
		if ( empty( $bucket ) || ! is_array( $bucket ) ) {
			return;
		}

		foreach ( $bucket as $notice ) {
			$kind    = isset( $notice['kind'] ) ? $notice['kind'] : 'warning';
			$message = isset( $notice['message'] ) ? (string) $notice['message'] : '';
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $kind ),
				wp_kses(
					$message,
					array(
						'code'   => array(),
						'strong' => array(),
						'em'     => array(),
						'br'     => array(),
					)
				)
			);
		}

		delete_transient( 'psyern_ah_admin_notice_' . $user_id );
	}

	// ======================================================================
	// Helpers
	// ======================================================================

	/**
	 * Bail out with 403 if the current user lacks the admin cap.
	 *
	 * @return void
	 */
	private function assert_cap() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'psyerns-auctionhouse' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * PRG-redirect back to the admin page on the given tab and exit.
	 *
	 * @param string $tab Tab slug to land on.
	 * @return void
	 */
	private function redirect_back( $tab ) {
		$url = add_query_arg( 'tab', $tab, $this->get_page_url() );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Read a sanitized query-string param with a default.
	 *
	 * @param string $name    Parameter name.
	 * @param mixed  $default Fallback value.
	 * @return string
	 */
	private function query_string_param( $name, $default = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ $name ] ) ) {
			return (string) $default;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw = wp_unslash( $_GET[ $name ] );
		if ( is_array( $raw ) ) {
			return (string) $default;
		}
		return sanitize_text_field( (string) $raw );
	}

	/**
	 * Parse a YYYY-MM-DD string to a Unix timestamp. Returns null on parse failure.
	 *
	 * @param string $str  Date string.
	 * @param bool   $is_end_of_day If true, use 23:59:59 for the day.
	 * @return int|null
	 */
	private function parse_date_to_ts( $str, $is_end_of_day ) {
		$str = trim( (string) $str );
		if ( '' === $str ) {
			return null;
		}
		$ts = strtotime( $str . ( $is_end_of_day ? ' 23:59:59' : ' 00:00:00' ) . ' UTC' );
		if ( false === $ts ) {
			return null;
		}
		return (int) $ts;
	}

	/**
	 * Parse a YYYY-MM-DD string to a MySQL datetime string. Returns null on failure.
	 *
	 * @param string $str  Date string.
	 * @param bool   $is_end_of_day If true, use 23:59:59 for the day.
	 * @return string|null
	 */
	private function parse_date_to_mysql( $str, $is_end_of_day ) {
		$ts = $this->parse_date_to_ts( $str, $is_end_of_day );
		if ( null === $ts ) {
			return null;
		}
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	/**
	 * List of available theme slugs. Falls back to single 'default' when the
	 * theme service is not loaded.
	 *
	 * @return string[]
	 */
	public function get_available_themes() {
		if ( class_exists( 'Psyern_AH_Theme' ) && method_exists( 'Psyern_AH_Theme', 'available_themes' ) ) {
			$themes = Psyern_AH_Theme::available_themes();
			if ( is_array( $themes ) && ! empty( $themes ) ) {
				return array_values( array_unique( array_map( 'sanitize_key', $themes ) ) );
			}
		}
		return array( 'default' );
	}

	/**
	 * Format an integer amount using the configured currency_format pattern.
	 *
	 * @param int    $amount Raw amount.
	 * @param string $format Pattern containing `{amount}`.
	 * @return string
	 */
	public static function format_amount( $amount, $format = '' ) {
		if ( '' === $format ) {
			$format = (string) get_option( 'psyern_ah_currency_format', '{amount} €' );
		}
		$n = number_format_i18n( (int) $amount );
		return str_replace( '{amount}', $n, $format );
	}

	/**
	 * Wipe transients whose option name starts with the given prefix. Safe
	 * no-op on object-cache-backed transient layers where transient rows do
	 * not exist in the options table (Redis/Memcached) — in that case the
	 * caller can separately nudge wp_cache_flush() from a different UI.
	 *
	 * @param string $prefix Transient name prefix without the `_transient_` part.
	 * @return int Number of options rows removed.
	 */
	private function wipe_transients_like( $prefix ) {
		global $wpdb;

		$prefix = (string) $prefix;
		if ( '' === $prefix ) {
			return 0;
		}

		$like1 = $wpdb->esc_like( '_transient_' . $prefix ) . '%';
		$like2 = $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$affected = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$like1,
				$like2
			)
		);

		return (int) $affected;
	}
}

/**
 * Bootstrap: instantiate and register hooks. Called via admin_init on
 * first admin load — the main plugin file only needs to require this file.
 */
if ( is_admin() ) {
	add_action(
		'plugins_loaded',
		function () {
			$admin = new Psyern_AH_Admin();
			$admin->init();
		},
		20
	);
}
