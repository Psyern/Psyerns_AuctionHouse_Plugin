<?php
/**
 * Listing-card partial.
 *
 * Expects in scope:
 *   - $listing       array  Enriched listing row (from Psyern_AH_Listings::get_listings()).
 *
 * Rendered:
 *   - as a grid tile inside marketplace.php
 *   - as part of any other list/grid the admin might build in the future
 *
 * No PHP logic beyond pre-computed display variables from the enriched row.
 *
 * @package Psyerns_AuctionHouse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! isset( $listing ) || ! is_array( $listing ) ) {
	return;
}

$card_listing_id    = isset( $listing['listing_id'] ) ? (string) $listing['listing_id'] : '';
$card_item_class    = isset( $listing['item_class'] ) ? (string) $listing['item_class'] : '';
$card_item_display  = isset( $listing['item_display'] ) ? (string) $listing['item_display'] : '';
$card_category_lbl  = isset( $listing['category_label'] ) ? (string) $listing['category_label'] : '';
$card_seller_name   = isset( $listing['seller_name'] ) ? (string) $listing['seller_name'] : '';
$card_type          = isset( $listing['listing_type'] ) ? (int) $listing['listing_type'] : 0;
$card_buy_now       = isset( $listing['buy_now_price'] ) ? (int) $listing['buy_now_price'] : 0;
$card_current_bid   = isset( $listing['current_bid'] ) ? (int) $listing['current_bid'] : 0;
$card_start_price   = isset( $listing['start_price'] ) ? (int) $listing['start_price'] : 0;
$card_bid_count     = isset( $listing['bid_count'] ) ? (int) $listing['bid_count'] : 0;
$card_expires_ts    = isset( $listing['expires_ts'] ) ? (int) $listing['expires_ts'] : 0;

$card_icon_url  = isset( $listing['icon_url'] ) && '' !== $listing['icon_url']
	? (string) $listing['icon_url']
	: Psyern_AH_Shortcodes::get_icon_url( $card_item_class );
$card_rarity    = Psyern_AH_Shortcodes::get_rarity( $card_item_class );
$card_rarity_cls = '' !== $card_rarity ? 'psyern-ah-listing-card--rarity-' . sanitize_html_class( $card_rarity ) : '';

$card_detail_url = Psyern_AH_Shortcodes::build_listing_url( $card_listing_id );
$card_expires_fb = Psyern_AH_Shortcodes::format_expires( $card_expires_ts );

if ( 0 === $card_type ) {
	$card_primary_price       = $card_buy_now;
	$card_primary_price_label = __( 'Sofortkauf', 'psyerns-auctionhouse' );
	$card_type_modifier       = 'buy-now';
} elseif ( 1 === $card_type ) {
	$card_primary_price       = max( $card_current_bid, $card_start_price );
	$card_primary_price_label = $card_current_bid > 0
		? __( 'Aktuelles Gebot', 'psyerns-auctionhouse' )
		: __( 'Startpreis', 'psyerns-auctionhouse' );
	$card_type_modifier       = 'auction';
} else {
	$card_primary_price       = max( $card_current_bid, $card_start_price );
	$card_primary_price_label = $card_current_bid > 0
		? __( 'Aktuelles Gebot', 'psyerns-auctionhouse' )
		: __( 'Startpreis', 'psyerns-auctionhouse' );
	$card_type_modifier       = 'auction-buy-now';
}

$card_primary_price_str = Psyern_AH_Shortcodes::format_price( $card_primary_price );
$card_buy_now_str       = $card_buy_now > 0 ? Psyern_AH_Shortcodes::format_price( $card_buy_now ) : '';

$card_type_label_map = array(
	0 => __( 'Sofortkauf', 'psyerns-auctionhouse' ),
	1 => __( 'Auktion', 'psyerns-auctionhouse' ),
	2 => __( 'Auktion + Sofortkauf', 'psyerns-auctionhouse' ),
);
$card_type_label = isset( $card_type_label_map[ $card_type ] ) ? $card_type_label_map[ $card_type ] : '';
?>
<article
	class="psyern-ah-listing-card psyern-ah-listing-card--type-<?php echo esc_attr( $card_type_modifier ); ?> <?php echo esc_attr( $card_rarity_cls ); ?>"
	data-listing-id="<?php echo esc_attr( $card_listing_id ); ?>"
	data-item-class="<?php echo esc_attr( $card_item_class ); ?>"
	data-rarity="<?php echo esc_attr( $card_rarity ); ?>"
	data-listing-type="<?php echo esc_attr( (string) $card_type ); ?>"
	data-expires-ts="<?php echo esc_attr( (string) $card_expires_ts ); ?>"
>
	<a class="psyern-ah-listing-card__link" href="<?php echo esc_url( $card_detail_url ); ?>">
		<div class="psyern-ah-listing-card__media">
			<?php if ( '' !== $card_icon_url ) : ?>
				<img
					class="psyern-ah-listing-card__icon"
					src="<?php echo esc_url( $card_icon_url ); ?>"
					alt="<?php echo esc_attr( $card_item_display ); ?>"
					loading="lazy"
				/>
			<?php else : ?>
				<span class="psyern-ah-listing-card__icon psyern-ah-listing-card__icon--placeholder" aria-hidden="true"></span>
			<?php endif; ?>

			<span class="psyern-ah-listing-card__type-badge psyern-ah-listing-card__type-badge--<?php echo esc_attr( $card_type_modifier ); ?>">
				<?php echo esc_html( $card_type_label ); ?>
			</span>
		</div>

		<div class="psyern-ah-listing-card__body">
			<h3 class="psyern-ah-listing-card__title"><?php echo esc_html( $card_item_display ); ?></h3>

			<?php if ( '' !== $card_category_lbl ) : ?>
				<span class="psyern-ah-listing-card__category"><?php echo esc_html( $card_category_lbl ); ?></span>
			<?php endif; ?>

			<div class="psyern-ah-listing-card__price-row">
				<div class="psyern-ah-listing-card__price psyern-ah-listing-card__price--highlighted">
					<span class="psyern-ah-listing-card__price-label"><?php echo esc_html( $card_primary_price_label ); ?></span>
					<span class="psyern-ah-listing-card__price-value"><?php echo esc_html( $card_primary_price_str ); ?></span>
				</div>

				<?php if ( 2 === $card_type && $card_buy_now > 0 ) : ?>
					<div class="psyern-ah-listing-card__price psyern-ah-listing-card__price--secondary">
						<span class="psyern-ah-listing-card__price-label"><?php esc_html_e( 'Sofortkauf', 'psyerns-auctionhouse' ); ?></span>
						<span class="psyern-ah-listing-card__price-value"><?php echo esc_html( $card_buy_now_str ); ?></span>
					</div>
				<?php endif; ?>
			</div>

			<div class="psyern-ah-listing-card__meta">
				<?php if ( 0 !== $card_type ) : ?>
					<span class="psyern-ah-listing-card__bid-count">
						<?php
						printf(
							/* translators: %d: bid count */
							esc_html( _n( '%d Gebot', '%d Gebote', $card_bid_count, 'psyerns-auctionhouse' ) ),
							(int) $card_bid_count
						);
						?>
					</span>
				<?php endif; ?>

				<?php if ( $card_expires_ts > 0 ) : ?>
					<span
						class="psyern-ah-listing-card__countdown"
						data-countdown
						data-expires-ts="<?php echo esc_attr( (string) $card_expires_ts ); ?>"
					>
						<?php echo esc_html( $card_expires_fb ); ?>
					</span>
				<?php endif; ?>

				<?php if ( '' !== $card_seller_name ) : ?>
					<span class="psyern-ah-listing-card__seller">
						<?php
						printf(
							/* translators: %s: seller display name */
							esc_html__( 'von %s', 'psyerns-auctionhouse' ),
							esc_html( $card_seller_name )
						);
						?>
					</span>
				<?php endif; ?>
			</div>
		</div>
	</a>
</article>
