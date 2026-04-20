/**
 * Psyerns AuctionHouse — "My" page JS.
 *
 * Drives the [psyerns_auctionhouse_my] shortcode: renders own balances,
 * own listings, own bids, and pending actions. Polls /user/me every 5 s
 * while the tab is visible.
 *
 * Expected DOM (rendered server-side by Agent 7):
 *   <div class="psyern-ah-my">
 *     <div data-role="balances"></div>
 *     <div data-role="listings"></div>
 *     <div data-role="bids"></div>
 *     <div data-role="pending"></div>
 *   </div>
 *
 * The container will call out to /user/listings, /user/bids, and /user/me
 * and re-render each section.
 *
 * @package Psyerns_AuctionHouse
 */

(function () {
	'use strict';

	if ( ! window.psyernAh || ! window.psyernAh.restUrl ) {
		return;
	}

	// ---------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------

	const fmtPrice = ( amount ) => {
		const template = window.psyernAh.currencyFormat || '{amount}';
		const num = Number( amount || 0 );
		return template.replace( '{amount}', num.toLocaleString() );
	};

	const t = ( key, fallback ) => {
		const tr = window.psyernAh.translations || {};
		return ( typeof tr[ key ] === 'string' && tr[ key ] !== '' ) ? tr[ key ] : fallback;
	};

	const apiFetch = async ( path, init = {} ) => {
		const url = window.psyernAh.restUrl + String( path ).replace( /^\//, '' );
		const merged = Object.assign( { credentials: 'same-origin' }, init );
		const res = await fetch( url, merged );
		let body = null;
		try {
			body = await res.json();
		} catch ( e ) {
			body = null;
		}
		return { status: res.status, ok: res.ok, body: body };
	};

	const postJson = ( path, body ) => apiFetch( path, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify( body || {} ),
	} );

	const escapeHtml = ( s ) => {
		if ( s === null || s === undefined ) {
			return '';
		}
		return String( s )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	};

	// ---------------------------------------------------------------
	// Toast (lightweight)
	// ---------------------------------------------------------------

	let toastHost = null;
	const toast = ( msg, type ) => {
		if ( ! toastHost ) {
			toastHost = document.createElement( 'div' );
			toastHost.className = 'psyern-ah-toast-host';
			document.body.appendChild( toastHost );
		}
		const el = document.createElement( 'div' );
		el.className = 'psyern-ah-toast psyern-ah-toast--' + ( type || 'info' );
		el.textContent = String( msg || '' );
		toastHost.appendChild( el );
		requestAnimationFrame( () => el.classList.add( 'is-shown' ) );
		setTimeout( () => {
			el.classList.remove( 'is-shown' );
			setTimeout( () => {
				if ( el.parentNode ) {
					el.parentNode.removeChild( el );
				}
			}, 300 );
		}, 5000 );
	};

	// ---------------------------------------------------------------
	// Simple inline confirm (no modal overlay)
	// ---------------------------------------------------------------

	const inlineConfirm = ( button, confirmText, commitFn ) => {
		// Replace the button's text temporarily; second click commits.
		if ( button.getAttribute( 'data-confirming' ) === '1' ) {
			button.removeAttribute( 'data-confirming' );
			button.textContent = button.getAttribute( 'data-original-text' ) || button.textContent;
			commitFn();
			return;
		}
		button.setAttribute( 'data-confirming', '1' );
		if ( ! button.getAttribute( 'data-original-text' ) ) {
			button.setAttribute( 'data-original-text', button.textContent );
		}
		button.textContent = confirmText;
		setTimeout( () => {
			if ( button.getAttribute( 'data-confirming' ) === '1' ) {
				button.removeAttribute( 'data-confirming' );
				button.textContent = button.getAttribute( 'data-original-text' ) || '';
			}
		}, 4000 );
	};

	// ---------------------------------------------------------------
	// Steam login URL
	// ---------------------------------------------------------------

	const loginUrl = () => window.psyernAh.restUrl + 'auth/steam/login?return_to=' + encodeURIComponent( window.location.href );

	// ---------------------------------------------------------------
	// Status labels
	// ---------------------------------------------------------------

	const statusBadge = ( status ) => {
		const s = String( status || '' );
		if ( s === 'queued' )       { return { cls: 'is-queued',   text: t( 'status_queued', 'In Warteschlange' ) }; }
		if ( s === 'dispatched' )   { return { cls: 'is-dispatched', text: t( 'status_dispatched', 'Zugestellt' ) }; }
		if ( s === 'executing' )    { return { cls: 'is-executing', text: t( 'status_executing', 'Wird ausgeführt' ) }; }
		if ( s === 'success' )      { return { cls: 'is-success',   text: t( 'status_success', 'Erfolgreich' ) }; }
		if ( s.indexOf( 'failed' ) === 0 ) {
			return { cls: 'is-failed', text: s.replace( /^failed_?/, '' ) || t( 'status_failed', 'Fehlgeschlagen' ) };
		}
		return { cls: 'is-unknown', text: s };
	};

	// ---------------------------------------------------------------
	// Renderers
	// ---------------------------------------------------------------

	const renderBalances = ( container, meBody ) => {
		const host = container.querySelector( '[data-role="balances"]' );
		if ( ! host ) {
			return;
		}
		host.innerHTML = '';
		const balances = meBody && meBody.balances ? meBody.balances : {};
		const entries = Object.keys( balances || {} );
		if ( ! entries.length ) {
			const empty = document.createElement( 'div' );
			empty.className = 'psyern-ah-my__empty';
			empty.textContent = t( 'no_balance', 'Kein Guthaben hinterlegt.' );
			host.appendChild( empty );
			return;
		}
		const list = document.createElement( 'ul' );
		list.className = 'psyern-ah-my__balances';
		entries.forEach( ( src ) => {
			const val = balances[ src ];
			const amount = ( val && typeof val === 'object' && 'balance' in val ) ? Number( val.balance || 0 ) : Number( val || 0 );
			const li = document.createElement( 'li' );
			li.className = 'psyern-ah-my__balance';
			const labelEl = document.createElement( 'span' );
			labelEl.className = 'psyern-ah-my__balance-source';
			labelEl.textContent = src;
			const valEl = document.createElement( 'span' );
			valEl.className = 'psyern-ah-my__balance-value';
			valEl.textContent = fmtPrice( amount );
			li.appendChild( labelEl );
			li.appendChild( valEl );
			list.appendChild( li );
		} );
		host.appendChild( list );
	};

	const renderListings = ( container, items ) => {
		const host = container.querySelector( '[data-role="listings"]' );
		if ( ! host ) {
			return;
		}
		host.innerHTML = '';
		if ( ! items || ! items.length ) {
			const empty = document.createElement( 'div' );
			empty.className = 'psyern-ah-my__empty';
			empty.textContent = t( 'no_listings', 'Keine aktiven Listings.' );
			host.appendChild( empty );
			return;
		}
		const list = document.createElement( 'ul' );
		list.className = 'psyern-ah-my__listings';
		items.forEach( ( it ) => {
			const li = document.createElement( 'li' );
			li.className = 'psyern-ah-my__listing';
			li.setAttribute( 'data-listing-id', it.listing_id || '' );
			li.innerHTML = ''
				+ ( it.icon_url ? '<img class="psyern-ah-my__icon" src="' + escapeHtml( it.icon_url ) + '" alt="" loading="lazy"/>' : '<div class="psyern-ah-my__icon psyern-ah-my__icon--placeholder"></div>' )
				+ '<div class="psyern-ah-my__col">'
				+   '<div class="psyern-ah-my__name">' + escapeHtml( it.item_display || it.item_class || '' ) + '</div>'
				+   '<div class="psyern-ah-my__meta">'
				+     ( it.category_label ? escapeHtml( it.category_label ) + ' · ' : '' )
				+     escapeHtml( fmtPrice( it.buy_now_price || it.current_bid || it.start_price || 0 ) )
				+   '</div>'
				+ '</div>'
				+ '<button type="button" class="psyern-ah-my__cancel" data-role="cancel-listing">' + escapeHtml( t( 'cancel_listing', 'Zurückziehen' ) ) + '</button>';
			list.appendChild( li );
		} );
		host.appendChild( list );

		list.addEventListener( 'click', ( ev ) => {
			const btn = ev.target.closest( '[data-role="cancel-listing"]' );
			if ( ! btn ) {
				return;
			}
			const li = btn.closest( '[data-listing-id]' );
			if ( ! li ) {
				return;
			}
			const listingId = li.getAttribute( 'data-listing-id' );
			inlineConfirm( btn, t( 'confirm_cancel_inline', 'Nochmal klicken zum Bestätigen' ), () => doCancelListing( container, listingId ) );
		} );
	};

	const renderBids = ( container, items ) => {
		const host = container.querySelector( '[data-role="bids"]' );
		if ( ! host ) {
			return;
		}
		host.innerHTML = '';
		if ( ! items || ! items.length ) {
			const empty = document.createElement( 'div' );
			empty.className = 'psyern-ah-my__empty';
			empty.textContent = t( 'no_bids', 'Keine Gebote.' );
			host.appendChild( empty );
			return;
		}
		const list = document.createElement( 'ul' );
		list.className = 'psyern-ah-my__bids';
		items.forEach( ( it ) => {
			const li = document.createElement( 'li' );
			li.className = 'psyern-ah-my__bid';
			const label = it.bid_status_label || '';
			const labelCls = ( () => {
				if ( label === 'Führend' )   { return 'is-leading'; }
				if ( label === 'Überboten' ) { return 'is-outbid'; }
				if ( label === 'Gewonnen' )  { return 'is-won'; }
				if ( label === 'Verloren' )  { return 'is-lost'; }
				return '';
			} )();
			li.innerHTML = ''
				+ ( it.icon_url ? '<img class="psyern-ah-my__icon" src="' + escapeHtml( it.icon_url ) + '" alt="" loading="lazy"/>' : '<div class="psyern-ah-my__icon psyern-ah-my__icon--placeholder"></div>' )
				+ '<div class="psyern-ah-my__col">'
				+   '<div class="psyern-ah-my__name">' + escapeHtml( it.item_display || it.item_class || '' ) + '</div>'
				+   '<div class="psyern-ah-my__meta">'
				+     escapeHtml( fmtPrice( it.current_bid || 0 ) )
				+   '</div>'
				+ '</div>'
				+ '<span class="psyern-ah-my__bid-status ' + labelCls + '">' + escapeHtml( label ) + '</span>';
			list.appendChild( li );
		} );
		host.appendChild( list );
	};

	const renderPending = ( container, items ) => {
		const host = container.querySelector( '[data-role="pending"]' );
		if ( ! host ) {
			return;
		}
		host.innerHTML = '';
		if ( ! items || ! items.length ) {
			const empty = document.createElement( 'div' );
			empty.className = 'psyern-ah-my__empty';
			empty.textContent = t( 'no_pending', 'Keine offenen Aufträge.' );
			host.appendChild( empty );
			return;
		}
		const list = document.createElement( 'ul' );
		list.className = 'psyern-ah-my__pending-list';
		items.forEach( ( it ) => {
			const st = statusBadge( it.status );
			const li = document.createElement( 'li' );
			li.className = 'psyern-ah-my__pending';
			li.innerHTML = ''
				+ '<span class="psyern-ah-my__pending-type">' + escapeHtml( it.type || '' ) + '</span>'
				+ '<span class="psyern-ah-my__pending-listing">' + escapeHtml( it.listing_id || '' ) + '</span>'
				+ '<span class="psyern-ah-my__pending-amount">' + escapeHtml( fmtPrice( it.amount || 0 ) ) + '</span>'
				+ '<span class="psyern-ah-my__pending-status psyern-ah-my__pending-status--' + st.cls + '">' + escapeHtml( st.text ) + '</span>'
				+ ( it.result_message ? '<span class="psyern-ah-my__pending-msg">' + escapeHtml( it.result_message ) + '</span>' : '' );
			list.appendChild( li );
		} );
		host.appendChild( list );
	};

	// ---------------------------------------------------------------
	// Cancel action
	// ---------------------------------------------------------------

	const refreshUserState = async () => {
		const { ok, body } = await apiFetch( 'user/me' );
		if ( ok && body && body.nonces ) {
			window.psyernAh.nonces = body.nonces;
		}
		return { ok, body };
	};

	const doCancelListing = async ( container, listingId ) => {
		if ( ! listingId ) {
			return;
		}
		const { status, body, ok: httpOk } = await postJson( 'user/cancel', {
			nonce      : window.psyernAh.nonces && window.psyernAh.nonces.cancel,
			listing_id : listingId,
		} );

		if ( status < 500 ) {
			await refreshUserState();
		}

		if ( httpOk && body && body.action_uuid ) {
			toast( t( 'queued', 'Auftrag angenommen…' ), 'info' );
			// Refresh the full page content — pending will show the new row.
			await refreshAll( container );
			return;
		}
		const code = body && ( body.error || body.code ) || '';
		if ( status === 403 && ( code === 'not_linked' || code === 'not_logged_in' ) ) {
			window.location.href = loginUrl();
			return;
		}
		if ( status === 403 && code === 'invalid_nonce' ) {
			toast( t( 'nonce_refreshed', 'Token abgelaufen — bitte erneut versuchen.' ), 'error' );
			return;
		}
		if ( status === 429 || code === 'rate_limited' ) {
			toast( t( 'rate_limited', 'Zu viele Anfragen, bitte kurz warten.' ), 'error' );
			return;
		}
		toast( ( body && body.message ) || code || t( 'load_error', 'Fehler.' ), 'error' );
	};

	// ---------------------------------------------------------------
	// Refresh loop
	// ---------------------------------------------------------------

	const refreshAll = async ( container ) => {
		const [ meRes, lRes, bRes ] = await Promise.all( [
			apiFetch( 'user/me' ),
			apiFetch( 'user/listings' ),
			apiFetch( 'user/bids' ),
		] );

		if ( meRes.status === 401 ) {
			// Render "please log in" hint.
			const pending = container.querySelector( '[data-role="pending"]' );
			if ( pending ) {
				pending.innerHTML = '';
				const a = document.createElement( 'a' );
				a.href = loginUrl();
				a.className = 'psyern-ah-my__login';
				a.textContent = t( 'login_required', 'Mit Steam einloggen' );
				pending.appendChild( a );
			}
			return;
		}

		if ( meRes.ok && meRes.body ) {
			if ( meRes.body.nonces ) {
				window.psyernAh.nonces = meRes.body.nonces;
			}
			renderBalances( container, meRes.body );
			renderPending( container, meRes.body.pending_actions || [] );
		}

		if ( lRes.ok && lRes.body ) {
			renderListings( container, lRes.body.items || [] );
		}
		if ( bRes.ok && bRes.body ) {
			renderBids( container, bRes.body.items || [] );
		}
	};

	// ---------------------------------------------------------------
	// Visibility-aware polling
	// ---------------------------------------------------------------

	const POLL_MS = 5000;
	const timers = new WeakMap();

	const startPolling = ( container ) => {
		stopPolling( container );
		const handle = setInterval( () => {
			if ( document.visibilityState === 'visible' ) {
				refreshAll( container );
			}
		}, POLL_MS );
		timers.set( container, handle );
	};

	const stopPolling = ( container ) => {
		const h = timers.get( container );
		if ( h ) {
			clearInterval( h );
			timers.delete( container );
		}
	};

	// ---------------------------------------------------------------
	// Init
	// ---------------------------------------------------------------

	const initContainer = ( container ) => {
		refreshAll( container );
		startPolling( container );

		document.addEventListener( 'visibilitychange', () => {
			if ( document.visibilityState === 'visible' ) {
				refreshAll( container );
				startPolling( container );
			} else {
				stopPolling( container );
			}
		} );
	};

	const init = () => {
		const containers = document.querySelectorAll( '.psyern-ah-my' );
		containers.forEach( initContainer );
	};

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
})();
