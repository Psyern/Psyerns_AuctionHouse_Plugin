<?php
/**
 * [psyerns_auctionhouse_history] template.
 *
 * Expects in scope:
 *   - $atts       array  Normalized shortcode attributes.
 *   - $theme_slug string Resolved theme slug.
 *   - $limit      int    Max rows.
 *   - $rows       array  List of enriched transaction rows from
 *                        Psyern_AH_Transactions::get_recent().
 *
 * Server-rendered table; JS (if present) may auto-refresh the tbody.
 *
 * @package Psyerns_AuctionHouse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$hist_rows = isset( $rows ) && is_array( $rows ) ? $rows : array();

$hist_type_labels = array(
	0 => __( 'Sofortkauf', 'psyerns-auctionhouse' ),
	1 => __( 'Auktion', 'psyerns-auctionhouse' ),
	2 => __( 'Abgelaufen', 'psyerns-auctionhouse' ),
	3 => __( 'Abgebrochen', 'psyerns-auctionhouse' ),
);
?>
<div
	class="psyern-ah-history psyern-ah-theme-<?php echo esc_attr( $theme_slug ); ?>"
	data-psyern-ah-history
	data-limit="<?php echo esc_attr( (string) $limit ); ?>"
>
	<header class="psyern-ah-history__header">
		<h2 class="psyern-ah-history__title"><?php esc_html_e( 'Verkaufs-Historie', 'psyerns-auctionhouse' ); ?></h2>
		<span class="psyern-ah-history__count">
			<?php
			printf(
				/* translators: %d: count */
				esc_html( _n( '%d Eintrag', '%d Einträge', count( $hist_rows ), 'psyerns-auctionhouse' ) ),
				(int) count( $hist_rows )
			);
			?>
		</span>
	</header>

	<?php if ( empty( $hist_rows ) ) : ?>
		<div class="psyern-ah-history__empty">
			<p><?php esc_html_e( 'Bisher keine abgeschlossenen Transaktionen.', 'psyerns-auctionhouse' ); ?></p>
		</div>
	<?php else : ?>
		<div class="psyern-ah-history__table-wrap">
			<table class="psyern-ah-history__table">
				<thead>
					<tr>
						<th class="psyern-ah-history__th psyern-ah-history__th--item"><?php esc_html_e( 'Item', 'psyerns-auctionhouse' ); ?></th>
						<th class="psyern-ah-history__th psyern-ah-history__th--seller"><?php esc_html_e( 'Verkäufer', 'psyerns-auctionhouse' ); ?></th>
						<th class="psyern-ah-history__th psyern-ah-history__th--buyer"><?php esc_html_e( 'Käufer', 'psyerns-auctionhouse' ); ?></th>
						<th class="psyern-ah-history__th psyern-ah-history__th--price"><?php esc_html_e( 'Preis', 'psyerns-auctionhouse' ); ?></th>
						<th class="psyern-ah-history__th psyern-ah-history__th--type"><?php esc_html_e( 'Typ', 'psyerns-auctionhouse' ); ?></th>
						<th class="psyern-ah-history__th psyern-ah-history__th--when"><?php esc_html_e( 'Zeitpunkt', 'psyerns-auctionhouse' ); ?></th>
					</tr>
				</thead>
				<tbody data-psyern-ah-history-body>
					<?php foreach ( $hist_rows as $hist_row ) : ?>
						<?php
						$hist_item_class   = isset( $hist_row['item_class'] ) ? (string) $hist_row['item_class'] : '';
						$hist_item_display = isset( $hist_row['item_display'] ) ? (string) $hist_row['item_display'] : '';
						$hist_icon_url     = isset( $hist_row['icon_url'] ) && '' !== $hist_row['icon_url']
							? (string) $hist_row['icon_url']
							: Psyern_AH_Shortcodes::get_icon_url( $hist_item_class );
						$hist_rarity       = Psyern_AH_Shortcodes::get_rarity( $hist_item_class );
						$hist_seller       = isset( $hist_row['seller_name'] ) ? (string) $hist_row['seller_name'] : '';
						$hist_buyer        = isset( $hist_row['buyer_name'] ) ? (string) $hist_row['buyer_name'] : '';
						$hist_final_price  = isset( $hist_row['final_price'] ) ? (int) $hist_row['final_price'] : 0;
						$hist_fee          = isset( $hist_row['fee'] ) ? (int) $hist_row['fee'] : 0;
						$hist_type         = isset( $hist_row['type'] ) ? (int) $hist_row['type'] : 0;
						$hist_ts_unix      = isset( $hist_row['timestamp_unix'] ) ? (int) $hist_row['timestamp_unix'] : ( isset( $hist_row['timestamp'] ) ? (int) $hist_row['timestamp'] : 0 );
						$hist_ts_iso       = isset( $hist_row['timestamp_iso'] ) ? (string) $hist_row['timestamp_iso'] : '';

						$hist_price_str = Psyern_AH_Shortcodes::format_price( $hist_final_price );
						$hist_type_lbl  = isset( $hist_type_labels[ $hist_type ] ) ? $hist_type_labels[ $hist_type ] : '';
						$hist_rarity_cls = '' !== $hist_rarity ? 'psyern-ah-history__row--rarity-' . sanitize_html_class( $hist_rarity ) : '';

						$hist_date_fmt = $hist_ts_unix > 0
							? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $hist_ts_unix )
							: '';
						?>
						<tr class="psyern-ah-history__row <?php echo esc_attr( $hist_rarity_cls ); ?>">
							<td class="psyern-ah-history__cell psyern-ah-history__cell--item">
								<div class="psyern-ah-history__item">
									<?php if ( '' !== $hist_icon_url ) : ?>
										<img class="psyern-ah-history__item-icon" src="<?php echo esc_url( $hist_icon_url ); ?>" alt="<?php echo esc_attr( $hist_item_display ); ?>" loading="lazy" />
									<?php endif; ?>
									<span class="psyern-ah-history__item-name"><?php echo esc_html( $hist_item_display ); ?></span>
								</div>
							</td>
							<td class="psyern-ah-history__cell psyern-ah-history__cell--seller"><?php echo esc_html( $hist_seller ); ?></td>
							<td class="psyern-ah-history__cell psyern-ah-history__cell--buyer"><?php echo esc_html( $hist_buyer ); ?></td>
							<td class="psyern-ah-history__cell psyern-ah-history__cell--price">
								<span class="psyern-ah-history__price"><?php echo esc_html( $hist_price_str ); ?></span>
								<?php if ( $hist_fee > 0 ) : ?>
									<span class="psyern-ah-history__fee">
										<?php
										printf(
											/* translators: %s: fee string */
											esc_html__( '(Gebühr %s)', 'psyerns-auctionhouse' ),
											esc_html( Psyern_AH_Shortcodes::format_price( $hist_fee ) )
										);
										?>
									</span>
								<?php endif; ?>
							</td>
							<td class="psyern-ah-history__cell psyern-ah-history__cell--type">
								<span class="psyern-ah-history__type psyern-ah-history__type--<?php echo esc_attr( (string) $hist_type ); ?>">
									<?php echo esc_html( $hist_type_lbl ); ?>
								</span>
							</td>
							<td class="psyern-ah-history__cell psyern-ah-history__cell--when">
								<time datetime="<?php echo esc_attr( $hist_ts_iso ); ?>">
									<?php echo esc_html( $hist_date_fmt ); ?>
								</time>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</div>
