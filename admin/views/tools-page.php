<?php
/**
 * Tools tab view.
 *
 * @package Psyerns_AuctionHouse
 * @var array  $view_data Prepared by Psyern_AH_Admin::prepare_tools_data().
 * @var array  $tabs      Tab definitions.
 * @var string $tab       Active tab slug.
 * @var string $page_url  Admin base URL.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$force_resync_active = ! empty( $view_data['force_resync_active'] );
$force_resync_ttl    = isset( $view_data['force_resync_ttl'] ) ? (int) $view_data['force_resync_ttl'] : 300;
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

		<div class="psyern-ah-admin__card psyern-ah-admin__card--info">
			<h2><?php esc_html_e( 'Force Re-Sync', 'psyerns-auctionhouse' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %d: TTL in seconds */
					esc_html__( 'Setzt ein Flag (TTL %d s), das die Mod beim nächsten Push konsumieren kann. In v1 ein No-Op — der Push ist ohnehin Full-Sync (README §13 #15).', 'psyerns-auctionhouse' ),
					(int) $force_resync_ttl
				);
				?>
			</p>
			<?php if ( $force_resync_active ) : ?>
				<p><strong><?php esc_html_e( 'Flag ist derzeit aktiv.', 'psyerns-auctionhouse' ); ?></strong></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="psyern_ah_force_resync" />
				<?php wp_nonce_field( 'psyern_ah_force_resync' ); ?>
				<button type="submit" class="button button-primary">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Force Re-Sync anfordern', 'psyerns-auctionhouse' ); ?>
				</button>
			</form>
		</div>

		<div class="psyern-ah-admin__card psyern-ah-admin__card--warn">
			<h2><?php esc_html_e( 'Caches leeren', 'psyerns-auctionhouse' ); ?></h2>
			<p>
				<?php esc_html_e( 'Preis-History Cache und Stats Cache werden regelmäßig automatisch invalidiert. Nutze die Buttons nur, wenn du bemerkst, dass Änderungen nicht im Frontend sichtbar werden.', 'psyerns-auctionhouse' ); ?>
			</p>
			<p class="description">
				<?php esc_html_e( 'Hinweis: Wird ein Object-Cache (Redis/Memcached) verwendet, können WordPress-Transients nicht per LIKE-Query gelöscht werden. In diesem Fall hilft ein globales wp_cache_flush() des Servers.', 'psyerns-auctionhouse' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px;">
				<input type="hidden" name="action" value="psyern_ah_clear_caches" />
				<input type="hidden" name="scope" value="price_history" />
				<?php wp_nonce_field( 'psyern_ah_clear_caches' ); ?>
				<button type="submit" class="button">
					<?php esc_html_e( 'Preis-History Cache', 'psyerns-auctionhouse' ); ?>
				</button>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px;">
				<input type="hidden" name="action" value="psyern_ah_clear_caches" />
				<input type="hidden" name="scope" value="stats" />
				<?php wp_nonce_field( 'psyern_ah_clear_caches' ); ?>
				<button type="submit" class="button">
					<?php esc_html_e( 'Stats Cache', 'psyerns-auctionhouse' ); ?>
				</button>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
				<input type="hidden" name="action" value="psyern_ah_clear_caches" />
				<input type="hidden" name="scope" value="all" />
				<?php wp_nonce_field( 'psyern_ah_clear_caches' ); ?>
				<button type="submit" class="button button-secondary">
					<?php esc_html_e( 'Alle AuctionHouse-Transients', 'psyerns-auctionhouse' ); ?>
				</button>
			</form>
		</div>

		<div class="psyern-ah-admin__danger">
			<h2>
				<span class="dashicons dashicons-warning"></span>
				<?php esc_html_e( 'Danger-Zone — Plugin-Daten zurücksetzen', 'psyerns-auctionhouse' ); ?>
			</h2>
			<p>
				<?php esc_html_e( 'Leert die Tabellen listings, transactions, balances, pending_actions, users. Plugin-Optionen (API-Key, Settings, Item-Map) bleiben erhalten. Die nächste Mod-Push-Anfrage stellt Listings/Balances/Transactions wieder her.', 'psyerns-auctionhouse' ); ?>
			</p>
			<p>
				<strong><?php esc_html_e( 'Zwei-Schritt-Bestätigung:', 'psyerns-auctionhouse' ); ?></strong>
				<?php esc_html_e( 'Tippe das Wort RESET (großgeschrieben, exakt) in das Feld, dann wird der Button aktiv.', 'psyerns-auctionhouse' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="psyern-ah-admin__reset-form">
				<input type="hidden" name="action" value="psyern_ah_reset_data" />
				<?php wp_nonce_field( 'psyern_ah_reset_data' ); ?>
				<p>
					<label for="psyern_ah_reset_confirm"><?php esc_html_e( 'Bestätigung:', 'psyerns-auctionhouse' ); ?></label>
					<input type="text" id="psyern_ah_reset_confirm" name="confirm" value=""
						autocomplete="off" placeholder="RESET" style="font-family:Consolas,Monaco,monospace;" />
				</p>
				<button type="submit" class="button button-link-delete" disabled>
					<?php esc_html_e( 'Alle Plugin-Daten leeren', 'psyerns-auctionhouse' ); ?>
				</button>
			</form>
		</div>

	</div><!-- .psyern-ah-admin__tab-content -->
</div><!-- .wrap -->
