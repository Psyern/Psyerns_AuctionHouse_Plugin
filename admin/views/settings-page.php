<?php
/**
 * Settings tab view.
 *
 * @package Psyerns_AuctionHouse
 * @var array $view_data Prepared by Psyern_AH_Admin::prepare_settings_data().
 * @var array $tabs      Tab definitions (label/icon per slug).
 * @var string $tab      Active tab slug.
 * @var string $page_url Admin base URL.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$api_key          = isset( $view_data['api_key'] ) ? (string) $view_data['api_key'] : '';
$steam_api_key    = isset( $view_data['steam_api_key'] ) ? (string) $view_data['steam_api_key'] : '';
$currency_format  = isset( $view_data['currency_format'] ) ? (string) $view_data['currency_format'] : '{amount} €';
$push_interval    = isset( $view_data['push_interval'] ) ? (int) $view_data['push_interval'] : 30;
$poll_interval    = isset( $view_data['poll_interval'] ) ? (int) $view_data['poll_interval'] : 10;
$default_theme    = isset( $view_data['default_theme'] ) ? (string) $view_data['default_theme'] : 'default';
$detail_url       = isset( $view_data['detail_url'] ) ? (string) $view_data['detail_url'] : '';
$visibility       = isset( $view_data['visibility'] ) && is_array( $view_data['visibility'] ) ? $view_data['visibility'] : array();
$available_themes = isset( $view_data['available_themes'] ) ? (array) $view_data['available_themes'] : array( 'default' );
$item_map_raw     = isset( $view_data['item_map_raw'] ) ? (string) $view_data['item_map_raw'] : '';
$item_map_preview = isset( $view_data['item_map_preview'] ) ? (array) $view_data['item_map_preview'] : array();
$categories_raw   = isset( $view_data['categories_raw'] ) ? (string) $view_data['categories_raw'] : '[]';
$upload_meta      = isset( $view_data['upload_meta'] ) && is_array( $view_data['upload_meta'] ) ? $view_data['upload_meta'] : array();
$upload_age       = isset( $view_data['upload_age'] ) ? (int) $view_data['upload_age'] : -1;
$upload_indicator = isset( $view_data['upload_indicator'] ) ? (string) $view_data['upload_indicator'] : 'red';
$rest_base        = isset( $view_data['rest_base'] ) ? (string) $view_data['rest_base'] : '';

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

		<?php /* ── Upload-meta early-warning card (Briefing 2) ─────────── */ ?>
		<div class="psyern-ah-admin__sync psyern-ah-admin__sync-indicator--<?php echo esc_attr( $upload_indicator ); ?>">
			<div class="psyern-ah-admin__sync-header">
				<span class="psyern-ah-admin__sync-dot" aria-hidden="true"></span>
				<?php if ( $upload_age < 0 ) : ?>
					<?php esc_html_e( 'Status: Kein Upload empfangen.', 'psyerns-auctionhouse' ); ?>
				<?php else : ?>
					<?php
					printf(
						/* translators: %d: seconds since last upload */
						esc_html__( 'Letzter Upload: vor %d Sekunden', 'psyerns-auctionhouse' ),
						(int) $upload_age
					);
					?>
				<?php endif; ?>
			</div>
			<?php if ( ! empty( $upload_meta ) ) : ?>
				<dl class="psyern-ah-admin__sync-detail">
					<div>
						<dt><?php esc_html_e( 'Modus', 'psyerns-auctionhouse' ); ?></dt>
						<dd><?php echo esc_html( isset( $upload_meta['currency_mode'] ) && '' !== $upload_meta['currency_mode'] ? $upload_meta['currency_mode'] : '—' ); ?></dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'Server-Time', 'psyerns-auctionhouse' ); ?></dt>
						<dd><?php echo esc_html( isset( $upload_meta['generated_at'] ) && '' !== $upload_meta['generated_at'] ? $upload_meta['generated_at'] : '—' ); ?></dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'Server-Epoch', 'psyerns-auctionhouse' ); ?></dt>
						<dd><?php echo esc_html( isset( $upload_meta['server_time_epoch'] ) ? (string) (int) $upload_meta['server_time_epoch'] : '0' ); ?></dd>
					</div>
					<?php if ( isset( $upload_meta['sizes'] ) && is_array( $upload_meta['sizes'] ) ) : ?>
						<div>
							<dt><?php esc_html_e( 'Listings (last push)', 'psyerns-auctionhouse' ); ?></dt>
							<dd><?php echo esc_html( isset( $upload_meta['sizes']['listings'] ) ? (string) (int) $upload_meta['sizes']['listings'] : '0' ); ?></dd>
						</div>
						<div>
							<dt><?php esc_html_e( 'Transactions (last push)', 'psyerns-auctionhouse' ); ?></dt>
							<dd><?php echo esc_html( isset( $upload_meta['sizes']['transactions'] ) ? (string) (int) $upload_meta['sizes']['transactions'] : '0' ); ?></dd>
						</div>
						<div>
							<dt><?php esc_html_e( 'Payload bytes', 'psyerns-auctionhouse' ); ?></dt>
							<dd><?php echo esc_html( isset( $upload_meta['sizes']['body_bytes'] ) ? (string) (int) $upload_meta['sizes']['body_bytes'] : '0' ); ?></dd>
						</div>
					<?php endif; ?>
				</dl>
			<?php endif; ?>
		</div>

		<?php /* ── API key card + rotate ────────────────────────────── */ ?>
		<div class="psyern-ah-admin__card psyern-ah-admin__card--info">
			<h2><?php esc_html_e( 'API-Key (Mod → WordPress)', 'psyerns-auctionhouse' ); ?></h2>
			<p class="description">
				<?php
				printf(
					/* translators: %s: REST base URL */
					esc_html__( 'Bearer-Key für alle /internal/* Routen. REST-Base: %s', 'psyerns-auctionhouse' ),
					'<code>' . esc_html( $rest_base ) . '</code>'
				);
				?>
			</p>
			<div class="psyern-ah-admin__key-row">
				<code><?php echo '' !== $api_key ? esc_html( $api_key ) : esc_html__( '(noch kein Key)', 'psyerns-auctionhouse' ); ?></code>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="psyern-ah-admin__rotate-form">
					<input type="hidden" name="action" value="psyern_ah_rotate_key" />
					<?php wp_nonce_field( 'psyern_ah_rotate_key' ); ?>
					<button type="submit" class="button button-secondary">
						<span class="dashicons dashicons-update"></span>
						<?php esc_html_e( 'Key rotieren', 'psyerns-auctionhouse' ); ?>
					</button>
				</form>
			</div>
		</div>

		<?php /* ── Main settings form ─────────────────────────────── */ ?>
		<form method="post" action="options.php">
			<?php settings_fields( Psyern_AH_Admin::SETTINGS_GROUP ); ?>

			<div class="psyern-ah-admin__card">
				<h2><?php esc_html_e( 'Steam-API (optional)', 'psyerns-auctionhouse' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="psyern_ah_steam_api_key"><?php esc_html_e( 'Steam API-Key', 'psyerns-auctionhouse' ); ?></label></th>
						<td>
							<input type="text" id="psyern_ah_steam_api_key" name="psyern_ah_steam_api_key"
								value="<?php echo esc_attr( $steam_api_key ); ?>" class="regular-text" />
							<p class="description">
								<?php
								echo wp_kses(
									__( 'Optional. Für Avatar-Resolution. Get at <a href="https://steamcommunity.com/dev/apikey" target="_blank" rel="noopener">steamcommunity.com/dev/apikey</a>', 'psyerns-auctionhouse' ),
									array(
										'a' => array(
											'href'   => array(),
											'target' => array(),
											'rel'    => array(),
										),
									)
								);
								?>
							</p>
						</td>
					</tr>
				</table>
			</div>

			<div class="psyern-ah-admin__card">
				<h2><?php esc_html_e( 'Formatierung & Intervalle', 'psyerns-auctionhouse' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="psyern_ah_currency_format"><?php esc_html_e( 'Währungsformat', 'psyerns-auctionhouse' ); ?></label></th>
						<td>
							<input type="text" id="psyern_ah_currency_format" name="psyern_ah_currency_format"
								value="<?php echo esc_attr( $currency_format ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( '{amount} wird durch den Betrag ersetzt. Beispiel: "{amount} ₽" oder "${amount}".', 'psyerns-auctionhouse' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="psyern_ah_push_interval_seconds"><?php esc_html_e( 'Push-Intervall (Sek)', 'psyerns-auctionhouse' ); ?></label></th>
						<td>
							<input type="number" min="10" max="3600" id="psyern_ah_push_interval_seconds"
								name="psyern_ah_push_interval_seconds"
								value="<?php echo esc_attr( (string) $push_interval ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Mod → WP Full-Upload Frequenz. Default: 30. Min 10, max 3600.', 'psyerns-auctionhouse' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="psyern_ah_poll_interval_seconds"><?php esc_html_e( 'Poll-Intervall (Sek)', 'psyerns-auctionhouse' ); ?></label></th>
						<td>
							<input type="number" min="3" max="300" id="psyern_ah_poll_interval_seconds"
								name="psyern_ah_poll_interval_seconds"
								value="<?php echo esc_attr( (string) $poll_interval ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Mod → WP Pending-Action-Poll Frequenz. Default: 10. Min 3, max 300.', 'psyerns-auctionhouse' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<div class="psyern-ah-admin__card">
				<h2><?php esc_html_e( 'Darstellung', 'psyerns-auctionhouse' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="psyern_ah_default_theme"><?php esc_html_e( 'Default-Theme', 'psyerns-auctionhouse' ); ?></label></th>
						<td>
							<select id="psyern_ah_default_theme" name="psyern_ah_default_theme">
								<?php foreach ( $available_themes as $theme_slug ) : ?>
									<option value="<?php echo esc_attr( $theme_slug ); ?>" <?php selected( $default_theme, $theme_slug ); ?>>
										<?php echo esc_html( $theme_slug ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Per-Shortcode überschreibbar via theme="…". Listet Framework-Themes + default-Fallback.', 'psyerns-auctionhouse' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="psyern_ah_listing_detail_url"><?php esc_html_e( 'Listing-Detail-URL', 'psyerns-auctionhouse' ); ?></label></th>
						<td>
							<input type="url" id="psyern_ah_listing_detail_url" name="psyern_ah_listing_detail_url"
								value="<?php echo esc_attr( $detail_url ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'URL der Seite mit [psyerns_auctionhouse_listing]. Wird für Klicks aus Marketplace-Karten verwendet.', 'psyerns-auctionhouse' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Public Visibility', 'psyerns-auctionhouse' ); ?></th>
						<td>
							<?php
							$labels = array(
								'marketplace' => __( 'Marketplace (öffentliche Auktionen)', 'psyerns-auctionhouse' ),
								'history'     => __( 'History (abgeschlossene Transaktionen)', 'psyerns-auctionhouse' ),
								'stats'       => __( 'Stats (Top-Seller / Preistrends)', 'psyerns-auctionhouse' ),
								'my'          => __( 'My (nur eingeloggt – eigene Listings/Gebote)', 'psyerns-auctionhouse' ),
							);
							foreach ( $labels as $key => $label ) :
								$checked = ! empty( $visibility[ $key ] );
								?>
								<label style="display:block;margin-bottom:6px;">
									<input type="checkbox" name="psyern_ah_public_visibility[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $checked ); ?> />
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
				</table>
			</div>

			<div class="psyern-ah-admin__card">
				<h2><?php esc_html_e( 'Item-Map (JSON)', 'psyerns-auctionhouse' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Schema v1: { version:1, default_icon_url, items: { item_class: { display_name?, icon_url, rarity?, category_hint? } } }. Erlaubte Rarities: common | uncommon | rare | epic | legendary.', 'psyerns-auctionhouse' ); ?>
				</p>
				<label for="psyern_ah_item_map" class="screen-reader-text"><?php esc_html_e( 'Item-Map JSON', 'psyerns-auctionhouse' ); ?></label>
				<textarea id="psyern_ah_item_map" name="psyern_ah_item_map" class="psyern-ah-admin__json-editor" spellcheck="false"><?php echo esc_textarea( $item_map_raw ); ?></textarea>
				<p class="psyern-ah-admin__help">
					<?php esc_html_e( 'Invalide Eingaben werden abgelehnt — der vorherige Wert bleibt erhalten.', 'psyerns-auctionhouse' ); ?>
				</p>

				<?php if ( ! empty( $item_map_preview ) ) : ?>
					<h3 style="margin-top:18px;"><?php esc_html_e( 'Vorschau (erste 5 Einträge)', 'psyerns-auctionhouse' ); ?></h3>
					<table class="psyern-ah-admin__item-preview">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Icon', 'psyerns-auctionhouse' ); ?></th>
								<th><?php esc_html_e( 'Item-Klasse', 'psyerns-auctionhouse' ); ?></th>
								<th><?php esc_html_e( 'Anzeige-Name', 'psyerns-auctionhouse' ); ?></th>
								<th><?php esc_html_e( 'Rarity', 'psyerns-auctionhouse' ); ?></th>
								<th><?php esc_html_e( 'Category Hint', 'psyerns-auctionhouse' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $item_map_preview as $row ) : ?>
								<tr>
									<td>
										<?php if ( '' !== $row['icon_url'] ) : ?>
											<img src="<?php echo esc_url( $row['icon_url'] ); ?>" alt="" />
										<?php else : ?>
											—
										<?php endif; ?>
									</td>
									<td><code><?php echo esc_html( $row['class_name'] ); ?></code></td>
									<td><?php echo esc_html( '' !== $row['display_name'] ? $row['display_name'] : '—' ); ?></td>
									<td>
										<?php if ( '' !== $row['rarity'] ) : ?>
											<span class="psyern-ah-admin__rarity psyern-ah-admin__rarity--<?php echo esc_attr( $row['rarity'] ); ?>">
												<?php echo esc_html( $row['rarity'] ); ?>
											</span>
										<?php else : ?>
											—
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( '' !== $row['category_hint'] ? $row['category_hint'] : '—' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<div class="psyern-ah-admin__card">
				<h2><?php esc_html_e( 'Categories (JSON)', 'psyerns-auctionhouse' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Kategorienliste – entweder { "1": "Weapons" } oder [ { "id":1, "label":"Weapons" } ]. Wird im Marketplace-Filter verwendet.', 'psyerns-auctionhouse' ); ?>
				</p>
				<label for="psyern_ah_categories" class="screen-reader-text"><?php esc_html_e( 'Categories JSON', 'psyerns-auctionhouse' ); ?></label>
				<textarea id="psyern_ah_categories" name="psyern_ah_categories" class="psyern-ah-admin__json-editor" spellcheck="false"><?php echo esc_textarea( $categories_raw ); ?></textarea>
			</div>

			<?php submit_button( __( 'Einstellungen speichern', 'psyerns-auctionhouse' ) ); ?>
		</form>

	</div><!-- .psyern-ah-admin__tab-content -->
</div><!-- .wrap -->
