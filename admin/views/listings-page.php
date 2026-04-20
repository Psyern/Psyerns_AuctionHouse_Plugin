<?php
/**
 * Listings tab view.
 *
 * @package Psyerns_AuctionHouse
 * @var array  $view_data Prepared by Psyern_AH_Admin::prepare_listings_data().
 * @var array  $tabs      Tab definitions.
 * @var string $tab       Active tab slug.
 * @var string $page_url  Admin base URL.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$service_available = ! empty( $view_data['service_available'] );
$items             = isset( $view_data['items'] ) ? (array) $view_data['items'] : array();
$total             = isset( $view_data['total'] ) ? (int) $view_data['total'] : 0;
$page              = isset( $view_data['page'] ) ? (int) $view_data['page'] : 1;
$total_pages       = isset( $view_data['total_pages'] ) ? (int) $view_data['total_pages'] : 1;
$status_filter     = isset( $view_data['status_filter'] ) ? (string) $view_data['status_filter'] : '';
$search            = isset( $view_data['search'] ) ? (string) $view_data['search'] : '';
$status_map        = isset( $view_data['status_map'] ) ? (array) $view_data['status_map'] : array();
$currency_format   = isset( $view_data['currency_format'] ) ? (string) $view_data['currency_format'] : '{amount} €';

$base_args = array( 'page' => 'psyern-ah', 'tab' => 'listings' );
?>
<div class="wrap psyern-ah-admin">
	<h1 class="psyern-ah-admin__title">
		<span class="dashicons dashicons-cart"></span>
		<?php esc_html_e( 'Psyerns AuctionHouse', 'psyerns-auctionhouse' ); ?>
	</h1>

	<nav class="nav-tab-wrapper psyern-ah-admin__tabs" role="tablist">
		<?php foreach ( $tabs as $slug => $info ) : ?>
			<a href="<?php echo esc_url( add_query_arg( 'tab', $slug, $page_url ) ); ?>"
				class="nav-tab<?php echo $tab === $slug ? ' nav-tab-active' : ''; ?>"
				role="tab">
				<span class="dashicons <?php echo esc_attr( $info['icon'] ); ?>"></span>
				<?php echo esc_html( $info['label'] ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<div class="psyern-ah-admin__tab-content">

		<?php if ( ! $service_available ) : ?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'Listings-Service noch nicht geladen (Phase 2 nicht aktiv). Ansicht leer bis Service bereit ist.', 'psyerns-auctionhouse' ); ?></p>
			</div>
		<?php endif; ?>

		<form method="get" class="psyern-ah-admin__filters" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="psyern-ah" />
			<input type="hidden" name="tab" value="listings" />

			<div class="psyern-ah-admin__filter">
				<label for="psyern-ah-filter-status"><?php esc_html_e( 'Status', 'psyerns-auctionhouse' ); ?></label>
				<select id="psyern-ah-filter-status" name="status">
					<?php foreach ( $status_map as $value => $label ) : ?>
						<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( (string) $status_filter, (string) $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="psyern-ah-admin__filter" style="flex:1 1 auto;">
				<label for="psyern-ah-filter-search"><?php esc_html_e( 'Suche (Item oder Verkäufer)', 'psyerns-auctionhouse' ); ?></label>
				<input type="search" id="psyern-ah-filter-search" name="search"
					value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'M4A1, Gh0stWalker, ...', 'psyerns-auctionhouse' ); ?>" />
			</div>

			<div class="psyern-ah-admin__filter">
				<label>&nbsp;</label>
				<button type="submit" class="button"><?php esc_html_e( 'Filtern', 'psyerns-auctionhouse' ); ?></button>
			</div>
		</form>

		<p class="description">
			<?php
			printf(
				/* translators: 1: current page, 2: total pages, 3: total rows */
				esc_html__( 'Seite %1$d / %2$d — %3$d Treffer gesamt', 'psyerns-auctionhouse' ),
				(int) $page,
				(int) $total_pages,
				(int) $total
			);
			?>
		</p>

		<table class="wp-list-table widefat striped psyern-ah-admin__table">
			<thead>
				<tr>
					<th><?php esc_html_e( '#', 'psyerns-auctionhouse' ); ?></th>
					<th><?php esc_html_e( 'Listing-ID', 'psyerns-auctionhouse' ); ?></th>
					<th><?php esc_html_e( 'Verkäufer', 'psyerns-auctionhouse' ); ?></th>
					<th><?php esc_html_e( 'Item', 'psyerns-auctionhouse' ); ?></th>
					<th><?php esc_html_e( 'Kategorie', 'psyerns-auctionhouse' ); ?></th>
					<th><?php esc_html_e( 'Preis', 'psyerns-auctionhouse' ); ?></th>
					<th><?php esc_html_e( 'Typ', 'psyerns-auctionhouse' ); ?></th>
					<th><?php esc_html_e( 'Status', 'psyerns-auctionhouse' ); ?></th>
					<th><?php esc_html_e( 'Ablauf', 'psyerns-auctionhouse' ); ?></th>
					<th><?php esc_html_e( 'Aktion', 'psyerns-auctionhouse' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $items ) ) : ?>
					<tr>
						<td colspan="10" class="psyern-ah-admin__table-empty"><?php esc_html_e( 'Keine Listings gefunden.', 'psyerns-auctionhouse' ); ?></td>
					</tr>
				<?php else : ?>
					<?php
					$type_labels = array(
						0 => __( 'BuyNow', 'psyerns-auctionhouse' ),
						1 => __( 'Auction', 'psyerns-auctionhouse' ),
						2 => __( 'BuyNow+Auction', 'psyerns-auctionhouse' ),
					);
					$status_labels = array(
						0 => __( 'Active', 'psyerns-auctionhouse' ),
						1 => __( 'Sold', 'psyerns-auctionhouse' ),
						2 => __( 'Expired', 'psyerns-auctionhouse' ),
						3 => __( 'Cancelled', 'psyerns-auctionhouse' ),
					);
					foreach ( $items as $row ) :
						$id          = isset( $row['id'] ) ? (int) $row['id'] : 0;
						$listing_id  = isset( $row['listing_id'] ) ? (string) $row['listing_id'] : '';
						$seller_name = isset( $row['seller_name'] ) ? (string) $row['seller_name'] : '';
						$seller_uid  = isset( $row['seller_uid'] ) ? (string) $row['seller_uid'] : '';
						$item_disp   = isset( $row['item_display'] ) ? (string) $row['item_display'] : '';
						$item_class  = isset( $row['item_class'] ) ? (string) $row['item_class'] : '';
						$cat_label   = isset( $row['category_label'] ) ? (string) $row['category_label'] : '';
						$type        = isset( $row['listing_type'] ) ? (int) $row['listing_type'] : 0;
						$status      = isset( $row['status'] ) ? (int) $row['status'] : 0;
						$expires     = isset( $row['expires_ts'] ) ? (int) $row['expires_ts'] : 0;
						$now_ts      = time();
						$remaining   = $expires > 0 ? max( 0, $expires - $now_ts ) : 0;

						if ( 0 === $type ) {
							$price = isset( $row['buy_now_price'] ) ? (int) $row['buy_now_price'] : 0;
						} else {
							$bid    = isset( $row['current_bid'] ) ? (int) $row['current_bid'] : 0;
							$start  = isset( $row['start_price'] ) ? (int) $row['start_price'] : 0;
							$price  = max( $bid, $start );
						}
					?>
					<tr>
						<td><?php echo esc_html( (string) $id ); ?></td>
						<td><code><?php echo esc_html( $listing_id ); ?></code></td>
						<td>
							<?php echo esc_html( '' !== $seller_name ? $seller_name : '—' ); ?>
							<br /><small><code><?php echo esc_html( $seller_uid ); ?></code></small>
						</td>
						<td>
							<?php echo esc_html( '' !== $item_disp ? $item_disp : $item_class ); ?>
							<br /><small><code><?php echo esc_html( $item_class ); ?></code></small>
						</td>
						<td><?php echo esc_html( '' !== $cat_label ? $cat_label : '—' ); ?></td>
						<td><?php echo esc_html( Psyern_AH_Admin::format_amount( $price, $currency_format ) ); ?></td>
						<td><span class="psyern-ah-admin__badge psyern-ah-admin__badge--type"><?php echo esc_html( isset( $type_labels[ $type ] ) ? $type_labels[ $type ] : (string) $type ); ?></span></td>
						<td><?php echo esc_html( isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : (string) $status ); ?></td>
						<td>
							<?php if ( $expires <= 0 ) : ?>
								—
							<?php elseif ( $remaining <= 0 ) : ?>
								<span style="color:#a00;"><?php esc_html_e( 'abgelaufen', 'psyerns-auctionhouse' ); ?></span>
							<?php else : ?>
								<?php echo esc_html( human_time_diff( $now_ts, $expires ) ); ?>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( 0 === $status && '' !== $listing_id && '' !== $seller_uid ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="psyern-ah-admin__cancel-form" style="margin:0;">
									<input type="hidden" name="action" value="psyern_ah_admin_cancel" />
									<input type="hidden" name="listing_id" value="<?php echo esc_attr( $listing_id ); ?>" />
									<input type="hidden" name="seller_uid" value="<?php echo esc_attr( $seller_uid ); ?>" />
									<?php wp_nonce_field( 'psyern_ah_admin_cancel' ); ?>
									<button type="submit" class="button button-small button-link-delete">
										<?php esc_html_e( 'Admin-Cancel', 'psyerns-auctionhouse' ); ?>
									</button>
								</form>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
					</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="psyern-ah-admin__pagination">
				<?php
				$query_args = array_merge(
					$base_args,
					array(
						'status' => $status_filter,
						'search' => $search,
					)
				);
				for ( $i = 1; $i <= $total_pages; $i++ ) :
					$url = add_query_arg( array_merge( $query_args, array( 'paged' => $i ) ), admin_url( 'admin.php' ) );
					if ( $i === $page ) :
						?>
						<span class="is-current"><?php echo esc_html( (string) $i ); ?></span>
						<?php
					else :
						?>
						<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( (string) $i ); ?></a>
						<?php
					endif;
				endfor;
				?>
			</div>
		<?php endif; ?>

	</div><!-- .psyern-ah-admin__tab-content -->
</div><!-- .wrap -->
