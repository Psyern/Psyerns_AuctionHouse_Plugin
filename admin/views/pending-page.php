<?php
/**
 * Pending tab view.
 *
 * @package Psyerns_AuctionHouse
 * @var array  $view_data Prepared by Psyern_AH_Admin::prepare_pending_data().
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
$status_filter     = isset( $view_data['status'] ) ? (string) $view_data['status'] : '';
$action_type       = isset( $view_data['action_type'] ) ? (string) $view_data['action_type'] : '';
$date_from         = isset( $view_data['date_from'] ) ? (string) $view_data['date_from'] : '';
$date_to           = isset( $view_data['date_to'] ) ? (string) $view_data['date_to'] : '';
$status_options    = isset( $view_data['status_options'] ) ? (array) $view_data['status_options'] : array();
$type_options      = isset( $view_data['type_options'] ) ? (array) $view_data['type_options'] : array();

$base_args = array( 'page' => 'psyern-ah', 'tab' => 'pending' );

if ( ! function_exists( 'psyern_ah_admin_status_modifier' ) ) {
	/**
	 * Resolve the badge modifier class from a status string.
	 *
	 * @param string $status Status value.
	 * @return string Modifier suffix (queued|dispatched|executing|success|failed).
	 */
	function psyern_ah_admin_status_modifier( $status ) {
		$status = (string) $status;
		if ( 'queued' === $status ) {
			return 'queued';
		}
		if ( 'dispatched' === $status ) {
			return 'dispatched';
		}
		if ( 'executing' === $status ) {
			return 'executing';
		}
		if ( 'success' === $status ) {
			return 'success';
		}
		return 'failed';
	}
}
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
				<p><?php esc_html_e( 'Database-Helper noch nicht geladen (Phase 2 nicht aktiv). Ansicht leer bis Service bereit ist.', 'psyerns-auctionhouse' ); ?></p>
			</div>
		<?php endif; ?>

		<form method="get" class="psyern-ah-admin__filters" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="psyern-ah" />
			<input type="hidden" name="tab" value="pending" />

			<div class="psyern-ah-admin__filter">
				<label for="psyern-ah-pending-status"><?php esc_html_e( 'Status', 'psyerns-auctionhouse' ); ?></label>
				<select id="psyern-ah-pending-status" name="status">
					<option value=""><?php esc_html_e( 'Alle', 'psyerns-auctionhouse' ); ?></option>
					<?php foreach ( $status_options as $opt ) : ?>
						<option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $status_filter, $opt ); ?>>
							<?php echo esc_html( $opt ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="psyern-ah-admin__filter">
				<label for="psyern-ah-pending-type"><?php esc_html_e( 'Aktion', 'psyerns-auctionhouse' ); ?></label>
				<select id="psyern-ah-pending-type" name="action_type">
					<option value=""><?php esc_html_e( 'Alle', 'psyerns-auctionhouse' ); ?></option>
					<?php foreach ( $type_options as $t ) : ?>
						<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $action_type, $t ); ?>>
							<?php echo esc_html( $t ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="psyern-ah-admin__filter">
				<label for="psyern-ah-pending-date-from"><?php esc_html_e( 'Von', 'psyerns-auctionhouse' ); ?></label>
				<input type="date" id="psyern-ah-pending-date-from" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" />
			</div>
			<div class="psyern-ah-admin__filter">
				<label for="psyern-ah-pending-date-to"><?php esc_html_e( 'Bis', 'psyerns-auctionhouse' ); ?></label>
				<input type="date" id="psyern-ah-pending-date-to" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" />
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
				esc_html__( 'Seite %1$d / %2$d — %3$d Einträge', 'psyerns-auctionhouse' ),
				(int) $page,
				(int) $total_pages,
				(int) $total
			);
			?>
		</p>

		<table class="wp-list-table widefat striped psyern-ah-admin__table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'UUID', 'psyerns-auctionhouse' ); ?></th>
					<th><?php esc_html_e( 'Typ', 'psyerns-auctionhouse' ); ?></th>
					<th><?php esc_html_e( 'Spieler', 'psyerns-auctionhouse' ); ?></th>
					<th><?php esc_html_e( 'Listing', 'psyerns-auctionhouse' ); ?></th>
					<th><?php esc_html_e( 'Betrag', 'psyerns-auctionhouse' ); ?></th>
					<th><?php esc_html_e( 'Status', 'psyerns-auctionhouse' ); ?></th>
					<th><?php esc_html_e( 'Result', 'psyerns-auctionhouse' ); ?></th>
					<th><?php esc_html_e( 'Created', 'psyerns-auctionhouse' ); ?></th>
					<th><?php esc_html_e( 'Dispatched', 'psyerns-auctionhouse' ); ?></th>
					<th><?php esc_html_e( 'Completed', 'psyerns-auctionhouse' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $items ) ) : ?>
					<tr>
						<td colspan="10" class="psyern-ah-admin__table-empty"><?php esc_html_e( 'Keine Pending-Actions gefunden.', 'psyerns-auctionhouse' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $items as $row ) :
						$uuid   = isset( $row['action_uuid'] ) ? (string) $row['action_uuid'] : '';
						$rtype  = isset( $row['action_type'] ) ? (string) $row['action_type'] : '';
						$uid    = isset( $row['player_uid'] ) ? (string) $row['player_uid'] : '';
						$lid    = isset( $row['listing_id'] ) ? (string) $row['listing_id'] : '';
						$amt    = isset( $row['amount'] ) ? (int) $row['amount'] : 0;
						$status = isset( $row['status'] ) ? (string) $row['status'] : '';
						$rcode  = isset( $row['result_code'] ) ? (string) $row['result_code'] : '';
						$rmsg   = isset( $row['result_message'] ) ? (string) $row['result_message'] : '';
						$cat    = isset( $row['created_at'] ) ? (string) $row['created_at'] : '';
						$dsp    = isset( $row['dispatched_at'] ) ? (string) $row['dispatched_at'] : '';
						$cmp    = isset( $row['completed_at'] ) ? (string) $row['completed_at'] : '';
						$mod    = psyern_ah_admin_status_modifier( $status );
					?>
					<tr>
						<td><code><?php echo esc_html( $uuid ); ?></code></td>
						<td><span class="psyern-ah-admin__badge psyern-ah-admin__badge--type"><?php echo esc_html( $rtype ); ?></span></td>
						<td><code><?php echo esc_html( $uid ); ?></code></td>
						<td><code><?php echo esc_html( $lid ); ?></code></td>
						<td><?php echo esc_html( (string) $amt ); ?></td>
						<td><span class="psyern-ah-admin__badge psyern-ah-admin__badge--<?php echo esc_attr( $mod ); ?>"><?php echo esc_html( $status ); ?></span></td>
						<td>
							<?php if ( '' !== $rcode || '' !== $rmsg ) : ?>
								<strong><?php echo esc_html( $rcode ); ?></strong>
								<?php if ( '' !== $rmsg ) : ?>
									<br /><small><?php echo esc_html( $rmsg ); ?></small>
								<?php endif; ?>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( '' !== $cat ? $cat : '—' ); ?></td>
						<td><?php echo esc_html( '' !== $dsp ? $dsp : '—' ); ?></td>
						<td><?php echo esc_html( '' !== $cmp ? $cmp : '—' ); ?></td>
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
						'status'      => $status_filter,
						'action_type' => $action_type,
						'date_from'   => $date_from,
						'date_to'     => $date_to,
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
