<?php
/**
 * History tab view.
 *
 * @package Psyerns_AuctionHouse
 * @var array  $view_data Prepared by Psyern_AH_Admin::prepare_history_data().
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
$search            = isset( $view_data['search'] ) ? (string) $view_data['search'] : '';
$type              = isset( $view_data['type'] ) ? (string) $view_data['type'] : '';
$date_from         = isset( $view_data['date_from'] ) ? (string) $view_data['date_from'] : '';
$date_to           = isset( $view_data['date_to'] ) ? (string) $view_data['date_to'] : '';
$type_map          = isset( $view_data['type_map'] ) ? (array) $view_data['type_map'] : array();
$currency_format   = isset( $view_data['currency_format'] ) ? (string) $view_data['currency_format'] : '{amount} €';

$base_args = array( 'page' => 'psyern-ah', 'tab' => 'history' );
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
				<p><?php esc_html_e( 'Transactions-Service noch nicht geladen (Phase 2 nicht aktiv). Ansicht leer bis Service bereit ist.', 'psyerns-auctionhouse' ); ?></p>
			</div>
		<?php endif; ?>

		<form method="get" class="psyern-ah-admin__filters" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="psyern-ah" />
			<input type="hidden" name="tab" value="history" />

			<div class="psyern-ah-admin__filter">
				<label for="psyern-ah-history-type"><?php esc_html_e( 'Typ', 'psyerns-auctionhouse' ); ?></label>
				<select id="psyern-ah-history-type" name="type">
					<?php foreach ( $type_map as $value => $label ) : ?>
						<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( (string) $type, (string) $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="psyern-ah-admin__filter">
				<label for="psyern-ah-history-date-from"><?php esc_html_e( 'Von', 'psyerns-auctionhouse' ); ?></label>
				<input type="date" id="psyern-ah-history-date-from" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" />
			</div>
			<div class="psyern-ah-admin__filter">
				<label for="psyern-ah-history-date-to"><?php esc_html_e( 'Bis', 'psyerns-auctionhouse' ); ?></label>
				<input type="date" id="psyern-ah-history-date-to" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" />
			</div>

			<div class="psyern-ah-admin__filter" style="flex:1 1 auto;">
				<label for="psyern-ah-history-search"><?php esc_html_e( 'Suche (Spieler / Item)', 'psyerns-auctionhouse' ); ?></label>
				<input type="search" id="psyern-ah-history-search" name="search"
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
				esc_html__( 'Seite %1$d / %2$d — %3$d Transaktionen', 'psyerns-auctionhouse' ),
				(int) $page,
				(int) $total_pages,
				(int) $total
			);
			?>
		</p>

		<table class="wp-list-table widefat striped psyern-ah-admin__table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Transaction-ID', 'psyerns-auctionhouse' ); ?></th>
					<th><?php esc_html_e( 'Zeit', 'psyerns-auctionhouse' ); ?></th>
					<th><?php esc_html_e( 'Typ', 'psyerns-auctionhouse' ); ?></th>
					<th><?php esc_html_e( 'Item', 'psyerns-auctionhouse' ); ?></th>
					<th><?php esc_html_e( 'Verkäufer', 'psyerns-auctionhouse' ); ?></th>
					<th><?php esc_html_e( 'Käufer', 'psyerns-auctionhouse' ); ?></th>
					<th><?php esc_html_e( 'Preis', 'psyerns-auctionhouse' ); ?></th>
					<th><?php esc_html_e( 'Gebühr', 'psyerns-auctionhouse' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $items ) ) : ?>
					<tr>
						<td colspan="8" class="psyern-ah-admin__table-empty"><?php esc_html_e( 'Keine Transaktionen gefunden.', 'psyerns-auctionhouse' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $items as $row ) :
						$tid       = isset( $row['transaction_id'] ) ? (string) $row['transaction_id'] : '';
						$ts        = isset( $row['timestamp'] ) ? (int) $row['timestamp'] : 0;
						$iso       = isset( $row['timestamp_iso'] ) ? (string) $row['timestamp_iso'] : ( $ts > 0 ? gmdate( 'c', $ts ) : '' );
						$rtype     = isset( $row['type'] ) ? (int) $row['type'] : 0;
						$rtype_lbl = isset( $type_map[ (string) $rtype ] ) ? $type_map[ (string) $rtype ] : (string) $rtype;
						$item_lbl  = isset( $row['item_display'] ) && '' !== $row['item_display'] ? $row['item_display'] : ( isset( $row['item_class'] ) ? $row['item_class'] : '' );
						$seller    = isset( $row['seller_name'] ) ? (string) $row['seller_name'] : '';
						$buyer     = isset( $row['buyer_name'] ) ? (string) $row['buyer_name'] : '';
						$price     = isset( $row['final_price'] ) ? (int) $row['final_price'] : 0;
						$fee       = isset( $row['fee'] ) ? (int) $row['fee'] : 0;
					?>
					<tr>
						<td><code><?php echo esc_html( $tid ); ?></code></td>
						<td><?php echo esc_html( $iso ); ?></td>
						<td><span class="psyern-ah-admin__badge psyern-ah-admin__badge--type"><?php echo esc_html( $rtype_lbl ); ?></span></td>
						<td><?php echo esc_html( $item_lbl ); ?></td>
						<td><?php echo esc_html( '' !== $seller ? $seller : '—' ); ?></td>
						<td><?php echo esc_html( '' !== $buyer ? $buyer : '—' ); ?></td>
						<td><?php echo esc_html( Psyern_AH_Admin::format_amount( $price, $currency_format ) ); ?></td>
						<td><?php echo esc_html( Psyern_AH_Admin::format_amount( $fee, $currency_format ) ); ?></td>
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
						'search'    => $search,
						'type'      => $type,
						'date_from' => $date_from,
						'date_to'   => $date_to,
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
