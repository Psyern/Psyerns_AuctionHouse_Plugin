<?php
/**
 * Theme integration layer.
 *
 * Bridges the AuctionHouse UI with the Psyerns Framework plugin so that any
 * theme shipped by the Framework can be reused by the AuctionHouse views
 * (Soft-Dependency). When the Framework plugin is not active, the
 * AuctionHouse falls back to its own base stylesheet.
 *
 * Also owns the central asset-registration step so that the shortcode layer
 * (Agent 7) can enqueue handles without having to know the underlying URLs.
 *
 * @package Psyerns_AuctionHouse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Psyern_AH_Theme
 *
 * Stateless helper — exposes public static methods only.
 */
class Psyern_AH_Theme {

	/**
	 * Basename used for the Psyerns Framework plugin.
	 *
	 * @var string
	 */
	const FRAMEWORK_PLUGIN_BASENAME = 'psyerns-framework/psyerns-framework.php';

	/**
	 * Slug of the built-in fallback theme when the Framework is not active.
	 *
	 * @var string
	 */
	const FALLBACK_THEME = 'default';

	/**
	 * Whether the Psyerns Framework plugin is installed and active.
	 *
	 * Uses both an `is_plugin_active()` check (loading the WP plugin helpers
	 * on-demand because this may be called on the front end) and a defensive
	 * check for one of the Framework's well-known constants.
	 *
	 * @return bool
	 */
	public static function is_framework_active() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			$plugin_php = ABSPATH . 'wp-admin/includes/plugin.php';
			if ( file_exists( $plugin_php ) ) {
				require_once $plugin_php;
			}
		}

		$active = false;
		if ( function_exists( 'is_plugin_active' ) ) {
			$active = is_plugin_active( self::FRAMEWORK_PLUGIN_BASENAME );
		}

		if ( true === $active ) {
			return true;
		}

		if ( defined( 'PF_VERSION' ) || defined( 'PF_PLUGIN_DIR' ) || defined( 'PF_PLUGIN_URL' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Absolute filesystem path of the Framework plugin root (with trailing slash).
	 *
	 * Prefers the constant exposed by the Framework plugin; falls back to a
	 * conventional WP_PLUGIN_DIR lookup when the constant is not yet defined.
	 *
	 * @return string
	 */
	protected static function get_framework_dir() {
		if ( defined( 'PF_PLUGIN_DIR' ) ) {
			return trailingslashit( PF_PLUGIN_DIR );
		}

		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			return trailingslashit( WP_PLUGIN_DIR . '/psyerns-framework' );
		}

		return '';
	}

	/**
	 * Public URL of the Framework plugin root (with trailing slash).
	 *
	 * @return string
	 */
	protected static function get_framework_url() {
		if ( defined( 'PF_PLUGIN_URL' ) ) {
			return trailingslashit( PF_PLUGIN_URL );
		}

		if ( function_exists( 'plugins_url' ) ) {
			return trailingslashit( plugins_url( '', dirname( __FILE__ ) . '/../psyerns-framework/psyerns-framework.php' ) );
		}

		return '';
	}

	/**
	 * Discover the theme slugs shipped by the Framework plugin.
	 *
	 * Falls back to `[ self::FALLBACK_THEME ]` when the Framework is not active
	 * or the CSS directory cannot be scanned.
	 *
	 * @return array<int, string>
	 */
	public static function available_themes() {
		if ( false === self::is_framework_active() ) {
			return array( self::FALLBACK_THEME );
		}

		$dir = self::get_framework_dir() . 'public/css/';
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return array( self::FALLBACK_THEME );
		}

		$themes = array( self::FALLBACK_THEME );
		$files  = glob( $dir . 'psyern-theme-*.css' );
		if ( false === $files || ! is_array( $files ) ) {
			return $themes;
		}

		foreach ( $files as $file ) {
			$basename = basename( $file, '.css' );
			// Strip the 'psyern-theme-' prefix.
			$slug = substr( $basename, strlen( 'psyern-theme-' ) );
			$slug = sanitize_key( $slug );
			if ( '' !== $slug && ! in_array( $slug, $themes, true ) ) {
				$themes[] = $slug;
			}
		}

		return $themes;
	}

	/**
	 * Return the public URL of a Framework theme CSS file.
	 *
	 * @param string $theme_slug Theme slug (e.g. 'stalker').
	 * @return string|null Public URL, or null if the file is not available.
	 */
	public static function get_theme_css_url( $theme_slug ) {
		$theme_slug = sanitize_key( $theme_slug );
		if ( '' === $theme_slug || self::FALLBACK_THEME === $theme_slug ) {
			return null;
		}

		if ( false === self::is_framework_active() ) {
			return null;
		}

		$relative_file = 'public/css/psyern-theme-' . $theme_slug . '.css';
		$full_path     = self::get_framework_dir() . $relative_file;

		if ( '' === self::get_framework_dir() || ! file_exists( $full_path ) ) {
			return null;
		}

		$url = self::get_framework_url() . $relative_file;
		return esc_url_raw( $url );
	}

	/**
	 * Register all public-facing stylesheets and scripts.
	 *
	 * Runs on `wp_enqueue_scripts` with priority 5 so the handles are ready
	 * before the shortcode layer (priority 10+) calls `wp_enqueue_*()`.
	 *
	 * @return void
	 */
	public static function register_assets() {
		$version = defined( 'PSYERN_AH_VERSION' ) ? PSYERN_AH_VERSION : '1.0.0';
		$url     = defined( 'PSYERN_AH_PLUGIN_URL' ) ? PSYERN_AH_PLUGIN_URL : plugin_dir_url( dirname( __FILE__ ) . '/../psyerns-auctionhouse.php' );

		wp_register_style(
			'psyern-ah-public',
			$url . 'public/css/psyern-ah-public.css',
			array(),
			$version
		);

		wp_register_script(
			'psyern-ah-chart-vendor',
			$url . 'public/vendor/chart.min.js',
			array(),
			'4.4.1',
			true
		);

		wp_register_script(
			'psyern-ah-marketplace',
			$url . 'public/js/psyern-ah-marketplace.js',
			array( 'jquery' ),
			$version,
			true
		);

		wp_register_script(
			'psyern-ah-listing',
			$url . 'public/js/psyern-ah-listing.js',
			array( 'jquery', 'psyern-ah-chart-vendor' ),
			$version,
			true
		);

		wp_register_script(
			'psyern-ah-price-chart',
			$url . 'public/js/psyern-ah-price-chart.js',
			array( 'psyern-ah-chart-vendor' ),
			$version,
			true
		);

		wp_register_script(
			'psyern-ah-my',
			$url . 'public/js/psyern-ah-my.js',
			array( 'jquery' ),
			$version,
			true
		);
	}

	/**
	 * Enqueue the base stylesheet + (optional) Framework theme for a shortcode.
	 *
	 * The shortcode layer (Agent 7) is responsible for enqueueing its own JS
	 * handles. This method only handles the theme/CSS side of things plus a
	 * defensive `register_assets()` call in case priority ordering misbehaves.
	 *
	 * @param string $theme_slug     Theme slug chosen by the site owner.
	 * @param string $shortcode_name Shortcode name, reserved for future per-shortcode CSS.
	 * @return void
	 */
	public static function enqueue_for_shortcode( $theme_slug, $shortcode_name ) {
		unset( $shortcode_name );

		if ( ! wp_style_is( 'psyern-ah-public', 'registered' ) ) {
			self::register_assets();
		}

		wp_enqueue_style( 'psyern-ah-public' );

		$theme_slug = sanitize_key( $theme_slug );
		if ( '' === $theme_slug ) {
			return;
		}

		$theme_url = self::get_theme_css_url( $theme_slug );
		if ( null === $theme_url ) {
			return;
		}

		$handle = 'psyern-ah-theme-' . $theme_slug;
		if ( ! wp_style_is( $handle, 'registered' ) ) {
			$version = defined( 'PSYERN_AH_VERSION' ) ? PSYERN_AH_VERSION : '1.0.0';
			wp_register_style( $handle, $theme_url, array( 'psyern-ah-public' ), $version );
		}

		wp_enqueue_style( $handle );
	}

	/**
	 * Body/wrapper class string for AuctionHouse shortcode templates.
	 *
	 * Templates emit `<div class="<?php echo esc_attr( Psyern_AH_Theme::get_body_class( $theme ) ); ?>">`.
	 *
	 * @param string $theme_slug Chosen theme slug.
	 * @return string
	 */
	public static function get_body_class( $theme_slug ) {
		$theme_slug = sanitize_key( $theme_slug );
		if ( '' === $theme_slug ) {
			$theme_slug = self::FALLBACK_THEME;
		}

		return 'psyern-ah-ui psyern-theme-' . $theme_slug;
	}
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'wp_enqueue_scripts', array( 'Psyern_AH_Theme', 'register_assets' ), 5 );
}
