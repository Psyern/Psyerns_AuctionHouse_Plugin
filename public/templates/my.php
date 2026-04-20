<?php
/**
 * [psyerns_auctionhouse_my] template.
 *
 * Expects in scope:
 *   - $atts             array   Normalized shortcode attributes.
 *   - $theme_slug       string  Resolved theme slug.
 *   - $is_logged_in     bool    WordPress login status.
 *   - $steam_uid        string  Linked Steam UID (empty when unlinked).
 *   - $is_linked        bool
 *   - $steam_login_url  string  Full URL (already esc_url'd) for Steam OpenID login.
 *
 * All live data (balance values, listings, bids, pending actions) is populated
 * by Agent 8's JS via GET /user/me + /user/listings + /user/bids, so the
 * server-side template emits empty skeletons with data-attributes only.
 *
 * @package Psyerns_AuctionHouse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="psyern-ah-my psyern-ah-theme-<?php echo esc_attr( $theme_slug ); ?>" data-psyern-ah-my>
	<?php if ( ! $is_logged_in ) : ?>
		<div class="psyern-ah-my__cta">
			<h2 class="psyern-ah-my__cta-title"><?php esc_html_e( 'Login erforderlich', 'psyerns-auctionhouse' ); ?></h2>
			<p class="psyern-ah-my__cta-text">
				<?php esc_html_e( 'Um dein AuctionHouse-Profil, deine Listings und offene Gebote zu sehen, melde dich bitte über Steam an.', 'psyerns-auctionhouse' ); ?>
			</p>
			<a class="psyern-ah-my__cta-button psyern-ah-my__cta-button--steam" href="<?php echo esc_url( $steam_login_url ); ?>">
				<?php esc_html_e( 'Mit Steam einloggen', 'psyerns-auctionhouse' ); ?>
			</a>
		</div>
	<?php elseif ( ! $is_linked ) : ?>
		<div class="psyern-ah-my__cta">
			<h2 class="psyern-ah-my__cta-title"><?php esc_html_e( 'Steam-Konto verknüpfen', 'psyerns-auctionhouse' ); ?></h2>
			<p class="psyern-ah-my__cta-text">
				<?php esc_html_e( 'Dein WordPress-Konto ist noch nicht mit einem Steam-Konto verknüpft. Verknüpfe dein Konto, um Listings, Gebote und Balances zu sehen.', 'psyerns-auctionhouse' ); ?>
			</p>
			<a class="psyern-ah-my__cta-button psyern-ah-my__cta-button--steam" href="<?php echo esc_url( $steam_login_url ); ?>">
				<?php esc_html_e( 'Jetzt Steam verknüpfen', 'psyerns-auctionhouse' ); ?>
			</a>
		</div>
	<?php else : ?>
		<header class="psyern-ah-my__header" data-psyern-ah-profile>
			<div class="psyern-ah-my__avatar-wrap">
				<img class="psyern-ah-my__avatar" src="" alt="" data-psyern-ah-avatar hidden />
				<span class="psyern-ah-my__avatar-placeholder" aria-hidden="true" data-psyern-ah-avatar-placeholder></span>
			</div>
			<div class="psyern-ah-my__profile">
				<h2 class="psyern-ah-my__name" data-psyern-ah-display-name><?php echo esc_html( wp_get_current_user()->display_name ); ?></h2>
				<p class="psyern-ah-my__uid">
					<?php esc_html_e( 'Steam-UID:', 'psyerns-auctionhouse' ); ?>
					<code data-psyern-ah-steam-uid><?php echo esc_html( $steam_uid ); ?></code>
				</p>
			</div>
		</header>

		<section class="psyern-ah-my__section psyern-ah-my__section--balances">
			<h3 class="psyern-ah-my__section-title"><?php esc_html_e( 'Balances', 'psyerns-auctionhouse' ); ?></h3>
			<div class="psyern-ah-my__balances" data-psyern-ah-balances>
				<div class="psyern-ah-my__balance psyern-ah-my__balance--expansion">
					<span class="psyern-ah-my__balance-label"><?php esc_html_e( 'Expansion', 'psyerns-auctionhouse' ); ?></span>
					<span class="psyern-ah-my__balance-value" data-psyern-ah-balance-expansion>
						<?php esc_html_e( 'Lade …', 'psyerns-auctionhouse' ); ?>
					</span>
				</div>
				<div class="psyern-ah-my__balance psyern-ah-my__balance--internal">
					<span class="psyern-ah-my__balance-label"><?php esc_html_e( 'Internal', 'psyerns-auctionhouse' ); ?></span>
					<span class="psyern-ah-my__balance-value" data-psyern-ah-balance-internal>
						<?php esc_html_e( 'Lade …', 'psyerns-auctionhouse' ); ?>
					</span>
				</div>
			</div>
		</section>

		<section class="psyern-ah-my__section psyern-ah-my__section--listings">
			<h3 class="psyern-ah-my__section-title"><?php esc_html_e( 'Meine Listings', 'psyerns-auctionhouse' ); ?></h3>
			<div class="psyern-ah-my__list" data-psyern-ah-my-listings>
				<p class="psyern-ah-my__loading"><?php esc_html_e( 'Lade …', 'psyerns-auctionhouse' ); ?></p>
			</div>
			<div class="psyern-ah-my__empty" data-psyern-ah-my-listings-empty hidden>
				<?php esc_html_e( 'Du hast aktuell keine aktiven Listings.', 'psyerns-auctionhouse' ); ?>
			</div>
		</section>

		<section class="psyern-ah-my__section psyern-ah-my__section--bids">
			<h3 class="psyern-ah-my__section-title"><?php esc_html_e( 'Meine Gebote', 'psyerns-auctionhouse' ); ?></h3>
			<div class="psyern-ah-my__list" data-psyern-ah-my-bids>
				<p class="psyern-ah-my__loading"><?php esc_html_e( 'Lade …', 'psyerns-auctionhouse' ); ?></p>
			</div>
			<div class="psyern-ah-my__empty" data-psyern-ah-my-bids-empty hidden>
				<?php esc_html_e( 'Du hast aktuell keine aktiven Gebote.', 'psyerns-auctionhouse' ); ?>
			</div>
		</section>

		<section class="psyern-ah-my__section psyern-ah-my__section--pending">
			<h3 class="psyern-ah-my__section-title"><?php esc_html_e( 'Offene Aufträge', 'psyerns-auctionhouse' ); ?></h3>
			<div class="psyern-ah-my__list" data-psyern-ah-my-pending>
				<p class="psyern-ah-my__loading"><?php esc_html_e( 'Lade …', 'psyerns-auctionhouse' ); ?></p>
			</div>
			<div class="psyern-ah-my__empty" data-psyern-ah-my-pending-empty hidden>
				<?php esc_html_e( 'Keine offenen Aufträge.', 'psyerns-auctionhouse' ); ?>
			</div>
		</section>
	<?php endif; ?>
</div>
