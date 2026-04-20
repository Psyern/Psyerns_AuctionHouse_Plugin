<?php
/**
 * Balances tab view.
 *
 * @package Psyerns_AuctionHouse
 * @var array  $view_data Prepared by Psyern_AH_Admin::prepare_balances_data().
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
$source            = isset( $view_data['source'] ) ? (string) $view_data['source'] : '';
$orderby           = isset( $view_data['orderby'] ) ? (string) $view_data['orderby'] : 'updated_at';
$order             = isset( $view_data['order'] ) ? (string) $view_data['order'] : 'DESC';
$source_map        = isset( $view_data['source_map'] ) ? (array) $view_data['source_map'] : array();
$currency_format   = isset( $view_data['currency_format'] ) ? (string) $view_data['currency_format'] : '{amount} €';

$base_args = array( 'page' => 'psyern-ah', 'tab' => 'balances' );
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
				<p><?php esc_html_e( 'Balances-Service noch nicht geladen (Phase 2 nicht aktiv). Ansicht leer bis Service bereit ist.', 'psyerns-auctionhouse' ); ?></p>
			</div>
		<?php endif; ?>

		<div class="psyern-ah-admin__card psyern-ah-admin__card--muted">
			<p style="margin:0;">
				<?php esc_html_e( 'Read-only Mirror der Mod-Seite. Einzige Quelle der Wahrheit ist der Server — Änderungen hier sind nicht möglich.', 'psyerns-auctionhouse' ); ?>
			</p>
		</div>

		<form method="get" class="psyern-ah-admin__filters" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="psyern-ah" />
			<input type="hidden" name="tab" value="balances" />

			<div class="psyern-ah-admin__filter">
				<label for="psyern-ah-bal-source"><?php esc_html_e( 'Quelle', 'psyerns-auctionhouse' ); ?></label>
				<select id="psyern-ah-bal-source" name="source">
					<?php foreach ( $source_map as $value => $label ) : ?>
						<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( (string) $source, (string) $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="psyern-ah-admin__filter">
				<label for="psyern-ah-bal-orderby"><?php esc_html_e( 'Sortierung', 'psyerns-auctionhouse' ); ?></label>
				<select id="psyern-ah-bal-orderby" name="orderby">
					<option value="updated_at" <?php selected( $orderby, 'updated_at' ); ?>><?php esc_html_e( 'Zuletzt aktualisiert', 'psyerns-auctionhouse' ); ?></option>
					<option value="balance" <?php selected( $orderby, 'balance' ); ?>><?php esc_html_e( 'Guthaben', 'psyerns-auctionhouse' ); ?></option>
					<option value="player_uid" <?php selected( $orderby, 'player_uid' ); ?>><?php esc_html_e( 'Spieler-UID', 'psyerns-auctionhouse' ); ?></option>
				</select>
			</div>

			<div class="psyern-ah-admin__filter">
				<label for="psyern-ah-bal-order"><?php esc_html_e( 'Richtung', 'psyerns-auctionhouse' ); ?></label>
				<select id="psyern-ah-bal-order" name="order">
					<option value="DESC" <?php selected( strtoupper( $order ), 'DESC' ); ?>><?php esc_html_e( 'Absteigend', 'psyerns-auctionhouse' ); ?></option>
					<option value="ASC" <?php selected( strtoupper( $order ), 'ASC' ); ?>><?php esc_html_e( 'Aufsteigend', 'psyerns-auctionhouse' ); ?></option>
				</select>
			</div>

			<div class="psyern-ah-admin__filter" style="flex:1 1 auto;">
				<label for="psyern-ah-bal-search"><?php esc_html_e( 'Suche (UID / Name)', 'psyerns-auctionhouse' ); ?></label>
				<input type="search" id="psyern-ah-bal-search" name="search"
					value="<?php echo esc_attr( $search ); ?>" placeholder="76561198000000000" />
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
					<th><?php esc_html_e( 'Spieler-UID', 'psyerns-auctionhouse' ); ?></th>
					<th><?php esc_html_e( 'Steam-Name', 'psyerns-auctionhouse' ); ?></th>
					<th><?php esc_html_e( 'Quelle', 'psyerns-auctionhouse' ); ?></th>
					<th><?php esc_html_e( 'Guthaben', 'psyerns-auctionhouse' ); ?></th>
					<th><?php esc_html_e( 'Zuletzt aktualisiert', 'psyerns-auctionhouse' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $items ) ) : ?>
					<tr>
						<td colspan="5" class="psyern-ah-admin__table-empty"><?php esc_html_e( 'Keine Balances gefunden.', 'psyerns-auctionhouse' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $items as $row ) :
						$uid        = isset( $row['player_uid'] ) ? (string) $row['player_uid'] : '';
						$steam_name = isset( $row['steam_name'] ) ? (string) $row['steam_name'] : '';
						$src        = isset( $row['currency_source'] ) ? (string) $row['currency_source'] : '';
						$bal        = isset( $row['balance'] ) ? (int) $row['balance'] : 0;
						$upd        = isset( $row['updated_at'] ) ? (string) $row['updated_at'] : '';
					?>
					<tr>
						<td><code><?php echo esc_html( $uid ); ?></code></td>
						<td><?php echo esc_html( '' !== $steam_name ? $steam_name : '—' ); ?></td>
						<td><span class="psyern-ah-admin__badge psyern-ah-admin__badge--type"><?php echo esc_html( $src ); ?></span></td>
						<td><?php echo esc_html( Psyern_AH_Admin::format_amount( $bal, $currency_format ) ); ?></td>
						<td><?php echo esc_html( '' !== $upd ? $upd : '—' ); ?></td>
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
						'source'  => $source,
						'orderby' => $orderby,
						'order'   => $order,
						'search'  => $search,
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
