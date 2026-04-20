<?php
/**
 * Marketplace shortcode template.
 *
 * Expects in scope:
 *   - $atts          array  Normalized shortcode attributes.
 *   - $theme_slug    string Resolved theme slug.
 *   - $per_page      int    Page size.
 *   - $initial       array  Result of Psyern_AH_Listings::get_listings() page 1.
 *   - $categories    array  Normalized list of { id, label }.
 *   - $container_id  string Unique DOM id suffix for this instance.
 *
 * Rendering strategy:
 *   - First page is server-rendered (SEO / no-JS fallback).
 *   - Filter bar emits stable data-attributes; JS hijacks submit.
 *   - Pagination is rendered as real links (add_query_arg), JS turns them
 *     into fetch() calls.
 *
 * @package Psyerns_AuctionHouse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mp_items       = isset( $initial['items'] ) && is_array( $initial['items'] ) ? $initial['items'] : array();
$mp_total       = isset( $initial['total'] ) ? (int) $initial['total'] : 0;
$mp_total_pages = isset( $initial['total_pages'] ) ? (int) $initial['total_pages'] : 0;
$mp_page        = isset( $initial['page'] ) ? (int) $initial['page'] : 1;

$mp_categories = isset( $categories ) && is_array( $categories ) ? $categories : array();

$mp_sort_options = array(
	'newest'     => __( 'Neueste', 'psyerns-auctionhouse' ),
	'price_asc'  => __( 'Preis aufsteigend', 'psyerns-auctionhouse' ),
	'price_desc' => __( 'Preis absteigend', 'psyerns-auctionhouse' ),
	'time_asc'   => __( 'Restzeit aufsteigend', 'psyerns-auctionhouse' ),
	'time_desc'  => __( 'Restzeit absteigend', 'psyerns-auctionhouse' ),
	'bid_count'  => __( 'Meiste Gebote', 'psyerns-auctionhouse' ),
);

$mp_current_url = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
?>
<div
	id="<?php echo esc_attr( $container_id ); ?>"
	class="psyern-ah-marketplace psyern-ah-theme-<?php echo esc_attr( $theme_slug ); ?>"
	data-per-page="<?php echo esc_attr( (string) $per_page ); ?>"
	data-theme="<?php echo esc_attr( $theme_slug ); ?>"
>
	<form class="psyern-ah-marketplace__filter" data-psyern-ah-filter>
		<div class="psyern-ah-marketplace__filter-group">
			<label class="psyern-ah-marketplace__filter-label" for="<?php echo esc_attr( $container_id ); ?>-search">
				<?php esc_html_e( 'Suche', 'psyerns-auctionhouse' ); ?>
			</label>
			<input
				class="psyern-ah-marketplace__filter-input psyern-ah-marketplace__filter-input--search"
				type="search"
				id="<?php echo esc_attr( $container_id ); ?>-search"
				name="search"
				placeholder="<?php esc_attr_e( 'Item-Name …', 'psyerns-auctionhouse' ); ?>"
				autocomplete="off"
			/>
		</div>

		<div class="psyern-ah-marketplace__filter-group">
			<label class="psyern-ah-marketplace__filter-label" for="<?php echo esc_attr( $container_id ); ?>-category">
				<?php esc_html_e( 'Kategorie', 'psyerns-auctionhouse' ); ?>
			</label>
			<select
				class="psyern-ah-marketplace__filter-input psyern-ah-marketplace__filter-input--select"
				id="<?php echo esc_attr( $container_id ); ?>-category"
				name="category_id"
			>
				<option value=""><?php esc_html_e( 'Alle Kategorien', 'psyerns-auctionhouse' ); ?></option>
				<?php foreach ( $mp_categories as $mp_cat ) : ?>
					<option value="<?php echo esc_attr( (string) $mp_cat['id'] ); ?>">
						<?php echo esc_html( $mp_cat['label'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="psyern-ah-marketplace__filter-group">
			<span class="psyern-ah-marketplace__filter-label"><?php esc_html_e( 'Typ', 'psyerns-auctionhouse' ); ?></span>
			<div class="psyern-ah-marketplace__filter-radios" role="radiogroup">
				<label class="psyern-ah-marketplace__filter-radio">
					<input type="radio" name="listing_type" value="all" checked />
					<span><?php esc_html_e( 'Alle', 'psyerns-auctionhouse' ); ?></span>
				</label>
				<label class="psyern-ah-marketplace__filter-radio">
					<input type="radio" name="listing_type" value="0" />
					<span><?php esc_html_e( 'Sofortkauf', 'psyerns-auctionhouse' ); ?></span>
				</label>
				<label class="psyern-ah-marketplace__filter-radio">
					<input type="radio" name="listing_type" value="1" />
					<span><?php esc_html_e( 'Auktion', 'psyerns-auctionhouse' ); ?></span>
				</label>
			</div>
		</div>

		<div class="psyern-ah-marketplace__filter-group psyern-ah-marketplace__filter-group--price-range">
			<label class="psyern-ah-marketplace__filter-label" for="<?php echo esc_attr( $container_id ); ?>-price-min">
				<?php esc_html_e( 'Preis von', 'psyerns-auctionhouse' ); ?>
			</label>
			<input
				class="psyern-ah-marketplace__filter-input psyern-ah-marketplace__filter-input--price"
				type="number"
				id="<?php echo esc_attr( $container_id ); ?>-price-min"
				name="price_min"
				min="0"
				step="1"
				inputmode="numeric"
			/>
			<label class="psyern-ah-marketplace__filter-label" for="<?php echo esc_attr( $container_id ); ?>-price-max">
				<?php esc_html_e( 'bis', 'psyerns-auctionhouse' ); ?>
			</label>
			<input
				class="psyern-ah-marketplace__filter-input psyern-ah-marketplace__filter-input--price"
				type="number"
				id="<?php echo esc_attr( $container_id ); ?>-price-max"
				name="price_max"
				min="0"
				step="1"
				inputmode="numeric"
			/>
		</div>

		<div class="psyern-ah-marketplace__filter-group">
			<label class="psyern-ah-marketplace__filter-label" for="<?php echo esc_attr( $container_id ); ?>-sort">
				<?php esc_html_e( 'Sortierung', 'psyerns-auctionhouse' ); ?>
			</label>
			<select
				class="psyern-ah-marketplace__filter-input psyern-ah-marketplace__filter-input--select"
				id="<?php echo esc_attr( $container_id ); ?>-sort"
				name="orderby"
			>
				<?php foreach ( $mp_sort_options as $mp_key => $mp_label ) : ?>
					<option value="<?php echo esc_attr( $mp_key ); ?>">
						<?php echo esc_html( $mp_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="psyern-ah-marketplace__filter-actions">
			<button type="submit" class="psyern-ah-marketplace__filter-submit">
				<?php esc_html_e( 'Anwenden', 'psyerns-auctionhouse' ); ?>
			</button>
			<button type="reset" class="psyern-ah-marketplace__filter-reset">
				<?php esc_html_e( 'Zurücksetzen', 'psyerns-auctionhouse' ); ?>
			</button>
		</div>
	</form>

	<div class="psyern-ah-marketplace__status" data-psyern-ah-status>
		<span class="psyern-ah-marketplace__status-count">
			<?php
			printf(
				/* translators: %d: total listing count */
				esc_html( _n( '%d aktives Listing', '%d aktive Listings', $mp_total, 'psyerns-auctionhouse' ) ),
				(int) $mp_total
			);
			?>
		</span>
	</div>

	<div class="psyern-ah-marketplace__grid" data-psyern-ah-grid>
		<?php if ( empty( $mp_items ) ) : ?>
			<div class="psyern-ah-marketplace__empty">
				<p><?php esc_html_e( 'Keine aktiven Auktionen.', 'psyerns-auctionhouse' ); ?></p>
			</div>
		<?php else : ?>
			<?php foreach ( $mp_items as $listing ) : ?>
				<?php include PSYERN_AH_PLUGIN_DIR . 'public/templates/listing-card.php'; ?>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>

	<?php if ( $mp_total_pages > 1 ) : ?>
		<nav class="psyern-ah-marketplace__pagination" data-psyern-ah-pagination aria-label="<?php esc_attr_e( 'Seiten', 'psyerns-auctionhouse' ); ?>">
			<?php for ( $mp_i = 1; $mp_i <= $mp_total_pages; $mp_i++ ) : ?>
				<?php
				$mp_page_url = esc_url( add_query_arg( array( 'ah_page' => $mp_i ), $mp_current_url ) );
				$mp_is_curr  = ( $mp_i === $mp_page );
				$mp_cls      = 'psyern-ah-marketplace__page-link';
				if ( $mp_is_curr ) {
					$mp_cls .= ' psyern-ah-marketplace__page-link--current';
				}
				?>
				<a
					class="<?php echo esc_attr( $mp_cls ); ?>"
					href="<?php echo esc_url( $mp_page_url ); ?>"
					data-page="<?php echo esc_attr( (string) $mp_i ); ?>"
					<?php echo $mp_is_curr ? ' aria-current="page"' : ''; ?>
				>
					<?php echo esc_html( (string) $mp_i ); ?>
				</a>
			<?php endfor; ?>
		</nav>
	<?php endif; ?>
</div>
