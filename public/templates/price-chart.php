<?php
/**
 * [psyerns_auctionhouse_price_chart] template — also reusable as a partial.
 *
 * Expects in scope:
 *   - $atts         array   Normalized shortcode attributes.
 *   - $theme_slug   string  Resolved theme slug.
 *   - $item_class   string  Item class (may be empty = render empty state).
 *   - $period       string  24h|7d|30d|all.
 *   - $height       int     Canvas pixel height.
 *   - $container_id string  Unique DOM id for the chart container.
 *   - $canvas_id    string  Unique DOM id for the <canvas>.
 *   - $has_item     bool    Whether item_class is non-empty.
 *   - $icon_url     string  Resolved item icon URL (can be '').
 *
 * The server emits:
 *   - a shell with period buttons, a canvas, and a loading/empty state
 *   - NO buckets — JS (Agent 8: handle `psyern-ah-price-chart`) calls
 *     GET /wp-json/psyern-ah/v1/public/price-history?item_class=...&period=...
 *     and feeds the resulting data into Chart.js with spanGaps:false so
 *     empty buckets render as gaps (see orchestrator briefing §2).
 *
 * @package Psyerns_AuctionHouse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pc_item   = isset( $item_class ) ? (string) $item_class : '';
$pc_period = isset( $period ) && in_array( (string) $period, array( '24h', '7d', '30d', 'all' ), true )
	? (string) $period
	: '30d';
$pc_height = isset( $height ) ? (int) $height : 300;
if ( $pc_height < 100 ) {
	$pc_height = 300;
}
$pc_has_item = isset( $has_item ) ? (bool) $has_item : ( '' !== $pc_item );
$pc_icon_url = isset( $icon_url ) ? (string) $icon_url : '';
$pc_theme    = isset( $theme_slug ) ? (string) $theme_slug : Psyern_AH_Shortcodes::DEFAULT_THEME;

$pc_periods = array(
	'24h' => __( '24h', 'psyerns-auctionhouse' ),
	'7d'  => __( '7d', 'psyerns-auctionhouse' ),
	'30d' => __( '30d', 'psyerns-auctionhouse' ),
	'all' => __( 'Gesamt', 'psyerns-auctionhouse' ),
);
?>
<div
	id="<?php echo esc_attr( $container_id ); ?>"
	class="psyern-ah-price-chart psyern-ah-theme-<?php echo esc_attr( $pc_theme ); ?>"
	data-psyern-ah-price-chart
	data-item-class="<?php echo esc_attr( $pc_item ); ?>"
	data-period="<?php echo esc_attr( $pc_period ); ?>"
	data-canvas-id="<?php echo esc_attr( $canvas_id ); ?>"
	data-has-item="<?php echo $pc_has_item ? 'true' : 'false'; ?>"
>
	<div class="psyern-ah-price-chart__header">
		<?php if ( '' !== $pc_icon_url ) : ?>
			<img class="psyern-ah-price-chart__icon" src="<?php echo esc_url( $pc_icon_url ); ?>" alt="" loading="lazy" />
		<?php endif; ?>

		<div class="psyern-ah-price-chart__title-wrap">
			<h3 class="psyern-ah-price-chart__title">
				<?php if ( $pc_has_item ) : ?>
					<?php
					printf(
						/* translators: %s: item class */
						esc_html__( 'Preis-Historie: %s', 'psyerns-auctionhouse' ),
						esc_html( $pc_item )
					);
					?>
				<?php else : ?>
					<?php esc_html_e( 'Preis-Historie', 'psyerns-auctionhouse' ); ?>
				<?php endif; ?>
			</h3>
		</div>

		<div class="psyern-ah-price-chart__periods" role="tablist" aria-label="<?php esc_attr_e( 'Zeitraum', 'psyerns-auctionhouse' ); ?>">
			<?php foreach ( $pc_periods as $pc_p_key => $pc_p_label ) : ?>
				<button
					type="button"
					class="psyern-ah-price-chart__period-button<?php echo $pc_period === $pc_p_key ? ' psyern-ah-price-chart__period-button--active' : ''; ?>"
					data-period="<?php echo esc_attr( $pc_p_key ); ?>"
					aria-pressed="<?php echo $pc_period === $pc_p_key ? 'true' : 'false'; ?>"
				>
					<?php echo esc_html( $pc_p_label ); ?>
				</button>
			<?php endforeach; ?>
		</div>
	</div>

	<div class="psyern-ah-price-chart__canvas-wrap" style="height: <?php echo esc_attr( (string) $pc_height ); ?>px;">
		<?php if ( ! $pc_has_item ) : ?>
			<div class="psyern-ah-price-chart__empty" data-psyern-ah-price-chart-empty>
				<p><?php esc_html_e( 'Wähle ein Item, um die Preis-Historie zu sehen.', 'psyerns-auctionhouse' ); ?></p>
			</div>
		<?php else : ?>
			<div class="psyern-ah-price-chart__loading" data-psyern-ah-price-chart-loading>
				<p><?php esc_html_e( 'Lade Preis-Historie …', 'psyerns-auctionhouse' ); ?></p>
			</div>
		<?php endif; ?>
		<canvas
			id="<?php echo esc_attr( $canvas_id ); ?>"
			class="psyern-ah-price-chart__canvas"
			data-psyern-ah-price-chart-canvas
			<?php echo $pc_has_item ? '' : 'hidden'; ?>
		></canvas>
	</div>

	<div class="psyern-ah-price-chart__legend" data-psyern-ah-price-chart-legend></div>
</div>
