<?php
/**
 * [psyerns_auctionhouse_stats] template.
 *
 * Expects in scope:
 *   - $atts           array  Normalized shortcode attributes.
 *   - $theme_slug     string Resolved theme slug.
 *   - $period_default string Currently selected period (24h|7d|30d|all).
 *   - $stats          array  { top_sellers, popular_items, avg_prices } from Psyern_AH_Stats.
 *   - $container_id   string Unique DOM id prefix.
 *
 * Tabs use the pure-CSS radio-input pattern so the page works without JS.
 * Agent 8 may progressively enhance with animated transitions.
 *
 * @package Psyerns_AuctionHouse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$st_top     = isset( $stats['top_sellers'] ) && is_array( $stats['top_sellers'] ) ? $stats['top_sellers'] : array();
$st_popular = isset( $stats['popular_items'] ) && is_array( $stats['popular_items'] ) ? $stats['popular_items'] : array();
$st_prices  = isset( $stats['avg_prices'] ) && is_array( $stats['avg_prices'] ) ? $stats['avg_prices'] : array();

$st_periods = array(
	'24h' => __( '24 Stunden', 'psyerns-auctionhouse' ),
	'7d'  => __( '7 Tage', 'psyerns-auctionhouse' ),
	'30d' => __( '30 Tage', 'psyerns-auctionhouse' ),
	'all' => __( 'Gesamt', 'psyerns-auctionhouse' ),
);

$st_tab_name = 'psyern-ah-stats-tab-' . wp_rand();
?>
<div
	id="<?php echo esc_attr( $container_id ); ?>"
	class="psyern-ah-stats psyern-ah-theme-<?php echo esc_attr( $theme_slug ); ?>"
	data-psyern-ah-stats
	data-period="<?php echo esc_attr( $period_default ); ?>"
>
	<header class="psyern-ah-stats__header">
		<h2 class="psyern-ah-stats__title"><?php esc_html_e( 'Statistiken', 'psyerns-auctionhouse' ); ?></h2>
		<div class="psyern-ah-stats__period" data-psyern-ah-period role="tablist" aria-label="<?php esc_attr_e( 'Zeitraum', 'psyerns-auctionhouse' ); ?>">
			<?php foreach ( $st_periods as $st_p_key => $st_p_label ) : ?>
				<button
					type="button"
					class="psyern-ah-stats__period-button<?php echo $period_default === $st_p_key ? ' psyern-ah-stats__period-button--active' : ''; ?>"
					data-period="<?php echo esc_attr( $st_p_key ); ?>"
					role="tab"
					aria-selected="<?php echo $period_default === $st_p_key ? 'true' : 'false'; ?>"
				>
					<?php echo esc_html( $st_p_label ); ?>
				</button>
			<?php endforeach; ?>
		</div>
	</header>

	<div class="psyern-ah-stats__tabs">
		<input type="radio" name="<?php echo esc_attr( $st_tab_name ); ?>" id="<?php echo esc_attr( $container_id ); ?>-tab-top" class="psyern-ah-stats__tab-input" checked />
		<input type="radio" name="<?php echo esc_attr( $st_tab_name ); ?>" id="<?php echo esc_attr( $container_id ); ?>-tab-popular" class="psyern-ah-stats__tab-input" />
		<input type="radio" name="<?php echo esc_attr( $st_tab_name ); ?>" id="<?php echo esc_attr( $container_id ); ?>-tab-prices" class="psyern-ah-stats__tab-input" />
		<input type="radio" name="<?php echo esc_attr( $st_tab_name ); ?>" id="<?php echo esc_attr( $container_id ); ?>-tab-trends" class="psyern-ah-stats__tab-input" />

		<div class="psyern-ah-stats__tab-bar" role="tablist">
			<label class="psyern-ah-stats__tab-label psyern-ah-stats__tab-label--top" for="<?php echo esc_attr( $container_id ); ?>-tab-top">
				<?php esc_html_e( 'Top-Seller', 'psyerns-auctionhouse' ); ?>
			</label>
			<label class="psyern-ah-stats__tab-label psyern-ah-stats__tab-label--popular" for="<?php echo esc_attr( $container_id ); ?>-tab-popular">
				<?php esc_html_e( 'Beliebteste Items', 'psyerns-auctionhouse' ); ?>
			</label>
			<label class="psyern-ah-stats__tab-label psyern-ah-stats__tab-label--prices" for="<?php echo esc_attr( $container_id ); ?>-tab-prices">
				<?php esc_html_e( 'Ø-Preise', 'psyerns-auctionhouse' ); ?>
			</label>
			<label class="psyern-ah-stats__tab-label psyern-ah-stats__tab-label--trends" for="<?php echo esc_attr( $container_id ); ?>-tab-trends">
				<?php esc_html_e( 'Preis-Trends', 'psyerns-auctionhouse' ); ?>
			</label>
		</div>

		<!-- Top-Seller Tab -->
		<section class="psyern-ah-stats__panel psyern-ah-stats__panel--top" role="tabpanel" data-tab="top_sellers">
			<?php if ( empty( $st_top ) ) : ?>
				<p class="psyern-ah-stats__empty"><?php esc_html_e( 'Keine Daten für diesen Zeitraum.', 'psyerns-auctionhouse' ); ?></p>
			<?php else : ?>
				<table class="psyern-ah-stats__table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Rang', 'psyerns-auctionhouse' ); ?></th>
							<th><?php esc_html_e( 'Verkäufer', 'psyerns-auctionhouse' ); ?></th>
							<th><?php esc_html_e( 'Verkäufe', 'psyerns-auctionhouse' ); ?></th>
							<th><?php esc_html_e( 'Gesamt-Umsatz', 'psyerns-auctionhouse' ); ?></th>
						</tr>
					</thead>
					<tbody data-psyern-ah-stats-top>
						<?php foreach ( $st_top as $st_rank => $st_row ) : ?>
							<tr>
								<td><?php echo esc_html( (string) ( $st_rank + 1 ) ); ?></td>
								<td><?php echo esc_html( isset( $st_row['seller_name'] ) ? (string) $st_row['seller_name'] : '' ); ?></td>
								<td><?php echo esc_html( isset( $st_row['sales'] ) ? (string) (int) $st_row['sales'] : '0' ); ?></td>
								<td><?php echo esc_html( Psyern_AH_Shortcodes::format_price( isset( $st_row['total'] ) ? (int) $st_row['total'] : 0 ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>

		<!-- Popular Tab -->
		<section class="psyern-ah-stats__panel psyern-ah-stats__panel--popular" role="tabpanel" data-tab="popular_items">
			<?php if ( empty( $st_popular ) ) : ?>
				<p class="psyern-ah-stats__empty"><?php esc_html_e( 'Keine Daten für diesen Zeitraum.', 'psyerns-auctionhouse' ); ?></p>
			<?php else : ?>
				<ul class="psyern-ah-stats__list" data-psyern-ah-stats-popular>
					<?php foreach ( $st_popular as $st_item ) : ?>
						<?php
						$st_cls       = isset( $st_item['item_class'] ) ? (string) $st_item['item_class'] : '';
						$st_display   = isset( $st_item['item_display'] ) ? (string) $st_item['item_display'] : $st_cls;
						$st_sales     = isset( $st_item['sales'] ) ? (int) $st_item['sales'] : 0;
						$st_avg       = isset( $st_item['avg_price'] ) ? (int) $st_item['avg_price'] : 0;
						$st_icon      = Psyern_AH_Shortcodes::get_icon_url( $st_cls );
						$st_rarity    = Psyern_AH_Shortcodes::get_rarity( $st_cls );
						$st_rar_cls   = '' !== $st_rarity ? 'psyern-ah-stats__list-item--rarity-' . sanitize_html_class( $st_rarity ) : '';
						?>
						<li class="psyern-ah-stats__list-item <?php echo esc_attr( $st_rar_cls ); ?>">
							<?php if ( '' !== $st_icon ) : ?>
								<img class="psyern-ah-stats__list-icon" src="<?php echo esc_url( $st_icon ); ?>" alt="<?php echo esc_attr( $st_display ); ?>" loading="lazy" />
							<?php endif; ?>
							<span class="psyern-ah-stats__list-name"><?php echo esc_html( $st_display ); ?></span>
							<span class="psyern-ah-stats__list-meta">
								<?php
								printf(
									/* translators: 1: sales count, 2: avg price */
									esc_html__( '%1$d Verkäufe · Ø %2$s', 'psyerns-auctionhouse' ),
									(int) $st_sales,
									esc_html( Psyern_AH_Shortcodes::format_price( $st_avg ) )
								);
								?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>

		<!-- Avg Prices Tab -->
		<section class="psyern-ah-stats__panel psyern-ah-stats__panel--prices" role="tabpanel" data-tab="avg_prices">
			<?php if ( empty( $st_prices ) ) : ?>
				<p class="psyern-ah-stats__empty"><?php esc_html_e( 'Keine Daten für diesen Zeitraum.', 'psyerns-auctionhouse' ); ?></p>
			<?php else : ?>
				<table class="psyern-ah-stats__table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Item', 'psyerns-auctionhouse' ); ?></th>
							<th><?php esc_html_e( 'Min.', 'psyerns-auctionhouse' ); ?></th>
							<th><?php esc_html_e( 'Ø', 'psyerns-auctionhouse' ); ?></th>
							<th><?php esc_html_e( 'Max.', 'psyerns-auctionhouse' ); ?></th>
							<th><?php esc_html_e( 'Verkäufe', 'psyerns-auctionhouse' ); ?></th>
						</tr>
					</thead>
					<tbody data-psyern-ah-stats-prices>
						<?php foreach ( $st_prices as $st_row ) : ?>
							<?php
							$st_cls     = isset( $st_row['item_class'] ) ? (string) $st_row['item_class'] : '';
							$st_display = isset( $st_row['item_display'] ) ? (string) $st_row['item_display'] : $st_cls;
							$st_icon    = Psyern_AH_Shortcodes::get_icon_url( $st_cls );
							?>
							<tr>
								<td>
									<div class="psyern-ah-stats__item-cell">
										<?php if ( '' !== $st_icon ) : ?>
											<img class="psyern-ah-stats__item-icon" src="<?php echo esc_url( $st_icon ); ?>" alt="" loading="lazy" />
										<?php endif; ?>
										<span><?php echo esc_html( $st_display ); ?></span>
									</div>
								</td>
								<td><?php echo esc_html( Psyern_AH_Shortcodes::format_price( isset( $st_row['min_price'] ) ? (int) $st_row['min_price'] : 0 ) ); ?></td>
								<td><?php echo esc_html( Psyern_AH_Shortcodes::format_price( isset( $st_row['avg_price'] ) ? (int) $st_row['avg_price'] : 0 ) ); ?></td>
								<td><?php echo esc_html( Psyern_AH_Shortcodes::format_price( isset( $st_row['max_price'] ) ? (int) $st_row['max_price'] : 0 ) ); ?></td>
								<td><?php echo esc_html( (string) ( isset( $st_row['sales'] ) ? (int) $st_row['sales'] : 0 ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>

		<!-- Trends Tab -->
		<section class="psyern-ah-stats__panel psyern-ah-stats__panel--trends" role="tabpanel" data-tab="trends">
			<div class="psyern-ah-stats__trends-controls">
				<label class="psyern-ah-stats__trends-label" for="<?php echo esc_attr( $container_id ); ?>-trends-item">
					<?php esc_html_e( 'Item auswählen', 'psyerns-auctionhouse' ); ?>
				</label>
				<select
					id="<?php echo esc_attr( $container_id ); ?>-trends-item"
					class="psyern-ah-stats__trends-select"
					data-psyern-ah-trends-select
				>
					<option value=""><?php esc_html_e( '— Item wählen —', 'psyerns-auctionhouse' ); ?></option>
					<?php foreach ( $st_popular as $st_item ) : ?>
						<?php
						$st_cls     = isset( $st_item['item_class'] ) ? (string) $st_item['item_class'] : '';
						$st_display = isset( $st_item['item_display'] ) ? (string) $st_item['item_display'] : $st_cls;
						if ( '' === $st_cls ) {
							continue;
						}
						?>
						<option value="<?php echo esc_attr( $st_cls ); ?>">
							<?php echo esc_html( $st_display ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="psyern-ah-stats__trends-chart" data-psyern-ah-trends-chart>
				<?php
				// Embed a price-chart partial with empty item_class; JS swaps it.
				$atts         = array(
					'item_class' => '',
					'period'     => $period_default,
					'height'     => 320,
					'theme'      => $theme_slug,
				);
				$period       = $period_default;
				$height       = 320;
				$item_class   = '';
				$container_id_chart = 'psyern-ah-chart-stats-' . wp_rand();
				$canvas_id    = $container_id_chart . '-canvas';
				$has_item     = false;
				$icon_url     = '';

				// Alias so the chart partial's "container_id" doesn't clobber the outer one.
				$outer_container_id = $container_id;
				$container_id       = $container_id_chart;

				include PSYERN_AH_PLUGIN_DIR . 'public/templates/price-chart.php';

				$container_id = $outer_container_id;
				?>
			</div>
		</section>
	</div>
</div>
