<?php
/**
 * Listing-detail shortcode template.
 *
 * Expects in scope:
 *   - $atts               array  Normalized shortcode attributes.
 *   - $theme_slug         string Resolved theme slug.
 *   - $listing            array  Enriched listing row.
 *   - $item_class         string Item classname.
 *   - $icon_url           string Icon URL (resolved).
 *   - $rarity             string Rarity slug ('' when unknown).
 *   - $listing_type       int    0|1|2.
 *   - $supports_buy_now   bool
 *   - $supports_bidding   bool
 *   - $start_price        int
 *   - $current_bid        int
 *   - $buy_now_price      int
 *   - $expires_ts         int
 *   - $min_bid            int
 *   - $current_user_uid   string Steam UID of current user (empty when unlinked).
 *   - $is_owner           bool
 *   - $is_logged_in       bool
 *   - $is_linked          bool
 *   - $listing_type_label string
 *   - $status_label       string
 *
 * @package Psyerns_AuctionHouse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! isset( $listing ) || ! is_array( $listing ) ) {
	return;
}

$ld_listing_id   = isset( $listing['listing_id'] ) ? (string) $listing['listing_id'] : '';
$ld_item_display = isset( $listing['item_display'] ) ? (string) $listing['item_display'] : '';
$ld_category_lbl = isset( $listing['category_label'] ) ? (string) $listing['category_label'] : '';
$ld_seller_name  = isset( $listing['seller_name'] ) ? (string) $listing['seller_name'] : '';
$ld_seller_uid   = isset( $listing['seller_uid'] ) ? (string) $listing['seller_uid'] : '';
$ld_bid_count    = isset( $listing['bid_count'] ) ? (int) $listing['bid_count'] : 0;
$ld_bidder_name  = isset( $listing['current_bidder_name'] ) ? (string) $listing['current_bidder_name'] : '';

$ld_rarity_cls = '' !== $rarity ? 'psyern-ah-listing--rarity-' . sanitize_html_class( $rarity ) : '';

$ld_current_price = $supports_buy_now && 0 === $listing_type ? $buy_now_price : max( $current_bid, $start_price );
$ld_current_str   = Psyern_AH_Shortcodes::format_price( $ld_current_price );
$ld_buy_now_str   = Psyern_AH_Shortcodes::format_price( $buy_now_price );
$ld_min_bid_str   = Psyern_AH_Shortcodes::format_price( $min_bid );
$ld_expires_fb    = Psyern_AH_Shortcodes::format_expires( $expires_ts );

$ld_status_code = isset( $listing['status'] ) ? (int) $listing['status'] : 0;
$ld_is_active   = ( 0 === $ld_status_code );
?>
<div
	class="psyern-ah-listing psyern-ah-theme-<?php echo esc_attr( $theme_slug ); ?> <?php echo esc_attr( $ld_rarity_cls ); ?>"
	data-listing-id="<?php echo esc_attr( $ld_listing_id ); ?>"
	data-item-class="<?php echo esc_attr( $item_class ); ?>"
	data-listing-type="<?php echo esc_attr( (string) $listing_type ); ?>"
	data-expires-ts="<?php echo esc_attr( (string) $expires_ts ); ?>"
	data-rarity="<?php echo esc_attr( $rarity ); ?>"
>
	<header class="psyern-ah-listing__header">
		<div class="psyern-ah-listing__media">
			<?php if ( '' !== $icon_url ) : ?>
				<img
					class="psyern-ah-listing__icon"
					src="<?php echo esc_url( $icon_url ); ?>"
					alt="<?php echo esc_attr( $ld_item_display ); ?>"
				/>
			<?php else : ?>
				<span class="psyern-ah-listing__icon psyern-ah-listing__icon--placeholder" aria-hidden="true"></span>
			<?php endif; ?>
		</div>

		<div class="psyern-ah-listing__title-block">
			<h2 class="psyern-ah-listing__title"><?php echo esc_html( $ld_item_display ); ?></h2>

			<div class="psyern-ah-listing__badges">
				<span class="psyern-ah-listing__badge psyern-ah-listing__badge--type">
					<?php echo esc_html( $listing_type_label ); ?>
				</span>
				<?php if ( '' !== $ld_category_lbl ) : ?>
					<span class="psyern-ah-listing__badge psyern-ah-listing__badge--category">
						<?php echo esc_html( $ld_category_lbl ); ?>
					</span>
				<?php endif; ?>
				<?php if ( '' !== $rarity ) : ?>
					<span class="psyern-ah-listing__badge psyern-ah-listing__badge--rarity psyern-ah-listing__badge--rarity-<?php echo esc_attr( $rarity ); ?>">
						<?php echo esc_html( ucfirst( $rarity ) ); ?>
					</span>
				<?php endif; ?>
				<?php if ( ! $ld_is_active ) : ?>
					<span class="psyern-ah-listing__badge psyern-ah-listing__badge--status">
						<?php echo esc_html( $status_label ); ?>
					</span>
				<?php endif; ?>
			</div>

			<div class="psyern-ah-listing__meta">
				<?php if ( '' !== $ld_seller_name ) : ?>
					<span class="psyern-ah-listing__seller">
						<?php
						printf(
							/* translators: %s: seller name */
							esc_html__( 'Verkäufer: %s', 'psyerns-auctionhouse' ),
							esc_html( $ld_seller_name )
						);
						?>
					</span>
				<?php endif; ?>

				<?php if ( $expires_ts > 0 ) : ?>
					<span
						class="psyern-ah-listing__countdown"
						data-countdown
						data-expires-ts="<?php echo esc_attr( (string) $expires_ts ); ?>"
					>
						<?php echo esc_html( $ld_expires_fb ); ?>
					</span>
				<?php endif; ?>
			</div>
		</div>
	</header>

	<section class="psyern-ah-listing__price-panel">
		<div class="psyern-ah-listing__price psyern-ah-listing__price--primary">
			<span class="psyern-ah-listing__price-label">
				<?php
				if ( $supports_bidding && $current_bid > 0 ) {
					esc_html_e( 'Aktuelles Gebot', 'psyerns-auctionhouse' );
				} elseif ( $supports_bidding ) {
					esc_html_e( 'Startpreis', 'psyerns-auctionhouse' );
				} else {
					esc_html_e( 'Sofortkauf', 'psyerns-auctionhouse' );
				}
				?>
			</span>
			<span class="psyern-ah-listing__price-value"><?php echo esc_html( $ld_current_str ); ?></span>
		</div>

		<?php if ( $supports_buy_now && 2 === $listing_type ) : ?>
			<div class="psyern-ah-listing__price psyern-ah-listing__price--secondary">
				<span class="psyern-ah-listing__price-label"><?php esc_html_e( 'Sofortkauf', 'psyerns-auctionhouse' ); ?></span>
				<span class="psyern-ah-listing__price-value"><?php echo esc_html( $ld_buy_now_str ); ?></span>
			</div>
		<?php endif; ?>

		<?php if ( $supports_bidding ) : ?>
			<div class="psyern-ah-listing__price psyern-ah-listing__price--meta">
				<span class="psyern-ah-listing__price-label"><?php esc_html_e( 'Gebote', 'psyerns-auctionhouse' ); ?></span>
				<span class="psyern-ah-listing__price-value"><?php echo esc_html( (string) $ld_bid_count ); ?></span>
			</div>

			<?php if ( '' !== $ld_bidder_name ) : ?>
				<div class="psyern-ah-listing__price psyern-ah-listing__price--meta">
					<span class="psyern-ah-listing__price-label"><?php esc_html_e( 'Höchstbieter', 'psyerns-auctionhouse' ); ?></span>
					<span class="psyern-ah-listing__price-value"><?php echo esc_html( $ld_bidder_name ); ?></span>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	</section>

	<section class="psyern-ah-listing__actions" data-psyern-ah-actions>
		<?php if ( ! $ld_is_active ) : ?>
			<div class="psyern-ah-listing__action-notice">
				<?php esc_html_e( 'Dieses Listing ist nicht mehr aktiv.', 'psyerns-auctionhouse' ); ?>
			</div>
		<?php elseif ( ! $is_logged_in ) : ?>
			<div class="psyern-ah-listing__action-notice">
				<?php esc_html_e( 'Bitte einloggen (Steam), um zu kaufen oder zu bieten.', 'psyerns-auctionhouse' ); ?>
			</div>
		<?php elseif ( ! $is_linked ) : ?>
			<div class="psyern-ah-listing__action-notice">
				<?php esc_html_e( 'Bitte Steam-Konto verknüpfen, um zu kaufen oder zu bieten.', 'psyerns-auctionhouse' ); ?>
			</div>
		<?php elseif ( $is_owner ) : ?>
			<div class="psyern-ah-listing__action-notice">
				<?php esc_html_e( 'Dies ist dein Listing.', 'psyerns-auctionhouse' ); ?>
			</div>

			<button
				type="button"
				class="psyern-ah-listing__button psyern-ah-listing__button--cancel"
				data-psyern-ah-cancel
				data-listing-id="<?php echo esc_attr( $ld_listing_id ); ?>"
			>
				<?php esc_html_e( 'Listing abbrechen', 'psyerns-auctionhouse' ); ?>
			</button>
		<?php else : ?>
			<?php if ( $supports_buy_now && $buy_now_price > 0 ) : ?>
				<form
					class="psyern-ah-listing__form psyern-ah-listing__form--buy-now"
					data-psyern-ah-buy-now
					data-listing-id="<?php echo esc_attr( $ld_listing_id ); ?>"
					data-expected-price="<?php echo esc_attr( (string) $buy_now_price ); ?>"
				>
					<button type="submit" class="psyern-ah-listing__button psyern-ah-listing__button--buy">
						<?php
						printf(
							/* translators: %s: price string */
							esc_html__( 'Jetzt kaufen für %s', 'psyerns-auctionhouse' ),
							esc_html( $ld_buy_now_str )
						);
						?>
					</button>
				</form>
			<?php endif; ?>

			<?php if ( $supports_bidding ) : ?>
				<form
					class="psyern-ah-listing__form psyern-ah-listing__form--bid"
					data-psyern-ah-bid
					data-listing-id="<?php echo esc_attr( $ld_listing_id ); ?>"
					data-min-bid="<?php echo esc_attr( (string) $min_bid ); ?>"
				>
					<label class="psyern-ah-listing__label" for="psyern-ah-bid-<?php echo esc_attr( $ld_listing_id ); ?>">
						<?php
						printf(
							/* translators: %s: minimum bid string */
							esc_html__( 'Dein Gebot (min. %s)', 'psyerns-auctionhouse' ),
							esc_html( $ld_min_bid_str )
						);
						?>
					</label>
					<input
						class="psyern-ah-listing__input psyern-ah-listing__input--bid"
						type="number"
						id="psyern-ah-bid-<?php echo esc_attr( $ld_listing_id ); ?>"
						name="amount"
						min="<?php echo esc_attr( (string) $min_bid ); ?>"
						value="<?php echo esc_attr( (string) $min_bid ); ?>"
						step="1"
						inputmode="numeric"
						required
					/>
					<button type="submit" class="psyern-ah-listing__button psyern-ah-listing__button--bid">
						<?php esc_html_e( 'Gebot abgeben', 'psyerns-auctionhouse' ); ?>
					</button>
				</form>
			<?php endif; ?>
		<?php endif; ?>

		<div class="psyern-ah-listing__pending" data-psyern-ah-pending hidden>
			<h4 class="psyern-ah-listing__pending-title"><?php esc_html_e( 'Status deiner Aktion', 'psyerns-auctionhouse' ); ?></h4>
			<p class="psyern-ah-listing__pending-state" data-psyern-ah-pending-state></p>
			<p class="psyern-ah-listing__pending-message" data-psyern-ah-pending-message></p>
		</div>
	</section>

	<section class="psyern-ah-listing__chart">
		<h3 class="psyern-ah-listing__chart-title"><?php esc_html_e( 'Preis-Historie', 'psyerns-auctionhouse' ); ?></h3>
		<?php
		// Reuse the price-chart partial for the detail page.
		$atts         = array(
			'item_class' => $item_class,
			'period'     => '30d',
			'height'     => 300,
			'theme'      => $theme_slug,
		);
		$period       = '30d';
		$height       = 300;
		$container_id = 'psyern-ah-chart-embed-' . wp_rand();
		$canvas_id    = $container_id . '-canvas';
		$has_item     = ( '' !== $item_class );
		$icon_url_chart = '' !== $icon_url ? $icon_url : Psyern_AH_Shortcodes::get_icon_url( $item_class );
		$icon_url     = $icon_url_chart;
		include PSYERN_AH_PLUGIN_DIR . 'public/templates/price-chart.php';
		?>
	</section>
</div>
