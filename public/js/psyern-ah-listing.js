/**
 * Psyerns AuctionHouse — Single Listing JS
 *
 * Runs on the [psyerns_auctionhouse_listing] page. Handles:
 *   - Live countdown (1 Hz)
 *   - Buy-Now button (POST /user/purchase, then poll /user/me)
 *   - Bid form (POST /user/bid)
 *   - Seller cancel button (POST /user/cancel)
 *   - Self-built confirm modal + toast (no external libs)
 *   - Nonce rotation after every successful POST
 *
 * Expected DOM:
 *   <div class="psyern-ah-listing-detail"
 *        data-listing-id="..."
 *        data-expires-ts="..."
 *        data-seller-uid="..."
 *        data-listing-type="0|1|2"
 *        data-buy-now-price="..."
 *        data-start-price="..."
 *        data-current-bid="..."
 *        data-min-bid="...">
 *     <span data-role="countdown"></span>
 *     <button data-role="buy-now">Sofortkauf</button>
 *     <form data-role="bid-form">
 *       <input name="amount" type="number">
 *       <button type="submit"></button>
 *     </form>
 *     <button data-role="cancel">Zurückziehen</button>
 *     <div data-role="pending-status"></div>
 *   </div>
 *
 * @package Psyerns_AuctionHouse
 */

(function () {
	'use strict';

	if ( ! window.psyernAh || ! window.psyernAh.restUrl ) {
		return;
	}

	// ---------------------------------------------------------------
	// Shared helpers
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

	// ---------------------------------------------------------------
	// Toast (self-built)
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
		// Trigger CSS transition.
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
	// Confirm modal (self-built)
	// ---------------------------------------------------------------

	const confirmModal = ( title, body ) => {
		return new Promise( ( resolve ) => {
			const backdrop = document.createElement( 'div' );
			backdrop.className = 'psyern-ah-modal-backdrop';
			const modal = document.createElement( 'div' );
			modal.className = 'psyern-ah-modal';
			modal.setAttribute( 'role', 'dialog' );
			modal.setAttribute( 'aria-modal', 'true' );

			const h = document.createElement( 'div' );
			h.className = 'psyern-ah-modal__title';
			h.textContent = title || '';

			const b = document.createElement( 'div' );
			b.className = 'psyern-ah-modal__body';
			b.textContent = body || '';

			const foot = document.createElement( 'div' );
			foot.className = 'psyern-ah-modal__footer';

			const cancelBtn = document.createElement( 'button' );
			cancelBtn.type = 'button';
			cancelBtn.className = 'psyern-ah-modal__btn psyern-ah-modal__btn--cancel';
			cancelBtn.textContent = t( 'cancel', 'Abbrechen' );

			const okBtn = document.createElement( 'button' );
			okBtn.type = 'button';
			okBtn.className = 'psyern-ah-modal__btn psyern-ah-modal__btn--ok';
			okBtn.textContent = t( 'confirm', 'Bestätigen' );

			foot.appendChild( cancelBtn );
			foot.appendChild( okBtn );
			modal.appendChild( h );
			modal.appendChild( b );
			modal.appendChild( foot );
			backdrop.appendChild( modal );
			document.body.appendChild( backdrop );

			const close = ( answer ) => {
				if ( backdrop.parentNode ) {
					backdrop.parentNode.removeChild( backdrop );
				}
				resolve( !! answer );
			};
			cancelBtn.addEventListener( 'click', () => close( false ) );
			okBtn.addEventListener( 'click', () => close( true ) );
			backdrop.addEventListener( 'click', ( ev ) => {
				if ( ev.target === backdrop ) {
					close( false );
				}
			} );
			okBtn.focus();
		} );
	};

	// ---------------------------------------------------------------
	// Nonce rotation
	// ---------------------------------------------------------------

	const refreshUserState = async () => {
		const { ok, body } = await apiFetch( 'user/me' );
		if ( ok && body && body.nonces ) {
			window.psyernAh.nonces = body.nonces;
		}
		return { ok, body };
	};

	// ---------------------------------------------------------------
	// Steam login redirect
	// ---------------------------------------------------------------

	const loginUrl = () => window.psyernAh.restUrl + 'auth/steam/login?return_to=' + encodeURIComponent( window.location.href );

	// ---------------------------------------------------------------
	// Error handling (shared across buy / bid / cancel)
	// ---------------------------------------------------------------

	const handlePostError = async ( container, status, body ) => {
		if ( ! body ) {
			toast( t( 'load_error', 'Serverfehler.' ), 'error' );
			return;
		}
		const code = body.error || body.code || '';

		if ( status === 403 && code === 'not_linked' ) {
			toast( t( 'not_linked', 'Bitte zuerst mit Steam einloggen.' ), 'error' );
			window.location.href = loginUrl();
			return;
		}
		if ( status === 403 && code === 'not_logged_in' ) {
			toast( t( 'not_logged_in', 'Bitte einloggen.' ), 'error' );
			window.location.href = loginUrl();
			return;
		}
		if ( status === 403 && code === 'invalid_nonce' ) {
			await refreshUserState();
			toast( t( 'nonce_refreshed', 'Token abgelaufen — bitte erneut versuchen.' ), 'error' );
			return;
		}
		if ( status === 429 || code === 'rate_limited' ) {
			toast( t( 'rate_limited', 'Zu viele Anfragen, bitte kurz warten.' ), 'error' );
			return;
		}
		if ( status === 409 && code === 'price_mismatch' ) {
			toast( t( 'price_mismatch', 'Preis hat sich geändert: ' ) + fmtPrice( body.current ), 'error' );
			await reloadListing( container );
			return;
		}
		if ( status === 400 && code === 'bid_too_low' ) {
			const form = container.querySelector( '[data-role="bid-form"]' );
			if ( form ) {
				const input = form.querySelector( '[name="amount"]' );
				if ( input ) {
					input.classList.add( 'is-error' );
					input.setAttribute( 'min', String( body.min || 0 ) );
					input.setAttribute( 'title', t( 'bid_min_hint', 'Mindestgebot: ' ) + fmtPrice( body.min ) );
				}
			}
			toast( t( 'bid_too_low', 'Gebot zu niedrig. Mindestens: ' ) + fmtPrice( body.min ), 'error' );
			return;
		}
		if ( status === 409 && code === 'listing_expired' ) {
			toast( t( 'listing_expired', 'Auktion abgelaufen.' ), 'error' );
			await reloadListing( container );
			return;
		}
		if ( status === 409 && code === 'not_owner' ) {
			toast( t( 'not_owner', 'Nicht dein Listing.' ), 'error' );
			return;
		}
		if ( code === 'own_listing' ) {
			toast( t( 'own_listing', 'Eigenes Listing — nicht möglich.' ), 'error' );
			return;
		}
		toast( body.message || code || t( 'load_error', 'Fehler.' ), 'error' );
	};

	// ---------------------------------------------------------------
	// Listing reload (on price_mismatch etc.)
	// ---------------------------------------------------------------

	const reloadListing = async ( container ) => {
		const id = container.getAttribute( 'data-listing-id' );
		if ( ! id ) {
			return;
		}
		const { ok, body } = await apiFetch( 'public/listings/' + encodeURIComponent( id ) );
		if ( ! ok || ! body ) {
			return;
		}
		// Update data-* attributes and visible prices.
		container.setAttribute( 'data-buy-now-price', String( body.buy_now_price || 0 ) );
		container.setAttribute( 'data-start-price', String( body.start_price || 0 ) );
		container.setAttribute( 'data-current-bid', String( body.current_bid || 0 ) );
		container.setAttribute( 'data-expires-ts', String( body.expires_ts || 0 ) );

		const minBid = Math.max( Number( body.current_bid || 0 ) + 1, Number( body.start_price || 0 ) );
		container.setAttribute( 'data-min-bid', String( minBid ) );

		const priceNodes = container.querySelectorAll( '[data-role="buy-now-price"]' );
		priceNodes.forEach( ( el ) => { el.textContent = fmtPrice( body.buy_now_price ); } );

		const curBidNodes = container.querySelectorAll( '[data-role="current-bid"]' );
		curBidNodes.forEach( ( el ) => { el.textContent = fmtPrice( body.current_bid ); } );

		const bidInput = container.querySelector( '[data-role="bid-form"] [name="amount"]' );
		if ( bidInput ) {
			bidInput.setAttribute( 'min', String( minBid ) );
			bidInput.placeholder = fmtPrice( minBid );
		}
	};

	// ---------------------------------------------------------------
	// Pending polling (after successful enqueue)
	// ---------------------------------------------------------------

	const pollPending = ( container, actionUuid, onDone ) => {
		const statusEl = container.querySelector( '[data-role="pending-status"]' );
		if ( statusEl ) {
			statusEl.textContent = t( 'pending', 'Wird verarbeitet…' );
			statusEl.className = 'psyern-ah-pending-status is-pending';
		}

		let attempts = 0;
		const maxAttempts = 40; // ~2 minutes at 3s.

		const tick = async () => {
			attempts++;
			const { ok, body } = await apiFetch( 'user/me' );
			if ( ! ok || ! body ) {
				if ( attempts < maxAttempts ) {
					setTimeout( tick, 3000 );
				} else if ( statusEl ) {
					statusEl.textContent = t( 'pending_timeout', 'Zeitüberschreitung — bitte später prüfen.' );
					statusEl.className = 'psyern-ah-pending-status is-error';
				}
				return;
			}
			if ( body.nonces ) {
				window.psyernAh.nonces = body.nonces;
			}
			const list = body.pending_actions || [];
			const row = list.find( ( r ) => r.action_uuid === actionUuid );
			if ( ! row ) {
				if ( attempts < maxAttempts ) {
					setTimeout( tick, 3000 );
				}
				return;
			}
			const st = row.status || '';
			if ( st === 'success' ) {
				if ( statusEl ) {
					statusEl.textContent = t( 'success', 'Erfolgreich!' );
					statusEl.className = 'psyern-ah-pending-status is-success';
				}
				toast( t( 'success', 'Erfolgreich!' ), 'success' );
				if ( typeof onDone === 'function' ) {
					onDone( true, row );
				}
				return;
			}
			if ( st.indexOf( 'failed' ) === 0 ) {
				const msg = row.result_message || st;
				if ( statusEl ) {
					statusEl.textContent = msg;
					statusEl.className = 'psyern-ah-pending-status is-error';
				}
				toast( msg, 'error' );
				if ( typeof onDone === 'function' ) {
					onDone( false, row );
				}
				return;
			}
			if ( attempts < maxAttempts ) {
				setTimeout( tick, 3000 );
			} else if ( statusEl ) {
				statusEl.textContent = t( 'pending_timeout', 'Zeitüberschreitung — bitte später prüfen.' );
				statusEl.className = 'psyern-ah-pending-status is-error';
			}
		};

		setTimeout( tick, 3000 );
	};

	// ---------------------------------------------------------------
	// Actions
	// ---------------------------------------------------------------

	const doBuyNow = async ( container ) => {
		if ( ! window.psyernAh.currentUser ) {
			toast( t( 'login_required', 'Bitte einloggen, um zu kaufen.' ), 'error' );
			window.location.href = loginUrl();
			return;
		}
		const listingId = container.getAttribute( 'data-listing-id' );
		const price = parseInt( container.getAttribute( 'data-buy-now-price' ), 10 ) || 0;

		const ok = await confirmModal(
			t( 'confirm_purchase_title', 'Kauf bestätigen' ),
			( t( 'confirm_purchase', 'Wirklich kaufen für {price}?' ) ).replace( '{price}', fmtPrice( price ) )
		);
		if ( ! ok ) {
			return;
		}

		const nonce = window.psyernAh.nonces && window.psyernAh.nonces.purchase;
		if ( ! nonce ) {
			await refreshUserState();
		}

		const { status, body, ok: httpOk } = await postJson( 'user/purchase', {
			nonce          : window.psyernAh.nonces && window.psyernAh.nonces.purchase,
			listing_id     : listingId,
			expected_price : price,
		} );

		// Always refresh nonces after a POST that hit the server successfully.
		if ( status < 500 ) {
			await refreshUserState();
		}

		if ( httpOk && body && body.action_uuid ) {
			toast( t( 'queued', 'Auftrag angenommen…' ), 'info' );
			pollPending( container, body.action_uuid );
			return;
		}
		await handlePostError( container, status, body );
	};

	const doBid = async ( container, amount ) => {
		if ( ! window.psyernAh.currentUser ) {
			toast( t( 'login_required', 'Bitte einloggen, um zu bieten.' ), 'error' );
			window.location.href = loginUrl();
			return;
		}
		const listingId = container.getAttribute( 'data-listing-id' );
		const min = parseInt( container.getAttribute( 'data-min-bid' ), 10 ) || 0;

		if ( ! Number.isFinite( amount ) || amount < min ) {
			toast( t( 'bid_too_low', 'Gebot zu niedrig. Mindestens: ' ) + fmtPrice( min ), 'error' );
			return;
		}

		const ok = await confirmModal(
			t( 'confirm_bid_title', 'Gebot bestätigen' ),
			( t( 'confirm_bid', 'Gebot {price} platzieren?' ) ).replace( '{price}', fmtPrice( amount ) )
		);
		if ( ! ok ) {
			return;
		}

		const { status, body, ok: httpOk } = await postJson( 'user/bid', {
			nonce      : window.psyernAh.nonces && window.psyernAh.nonces.bid,
			listing_id : listingId,
			amount     : amount,
		} );

		if ( status < 500 ) {
			await refreshUserState();
		}

		if ( httpOk && body && body.action_uuid ) {
			toast( t( 'queued', 'Auftrag angenommen…' ), 'info' );
			pollPending( container, body.action_uuid );
			return;
		}
		await handlePostError( container, status, body );
	};

	const doCancel = async ( container ) => {
		if ( ! window.psyernAh.currentUser ) {
			return;
		}
		const listingId = container.getAttribute( 'data-listing-id' );
		const ok = await confirmModal(
			t( 'confirm_cancel_title', 'Listing zurückziehen' ),
			t( 'confirm_cancel', 'Listing wirklich zurückziehen?' )
		);
		if ( ! ok ) {
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
			pollPending( container, body.action_uuid );
			return;
		}
		await handlePostError( container, status, body );
	};

	// ---------------------------------------------------------------
	// Countdown
	// ---------------------------------------------------------------

	const updateCountdown = ( container ) => {
		const target = container.querySelector( '[data-role="countdown"]' );
		if ( ! target ) {
			return;
		}
		const expires = parseInt( container.getAttribute( 'data-expires-ts' ), 10 ) || 0;
		if ( expires <= 0 ) {
			target.textContent = '';
			return;
		}
		const now = Math.floor( Date.now() / 1000 );
		const rem = expires - now;
		if ( rem <= 0 ) {
			target.textContent = t( 'expired', 'Abgelaufen' );
			target.classList.add( 'is-expired' );
			return;
		}
		const h = Math.floor( rem / 3600 );
		const m = Math.floor( ( rem % 3600 ) / 60 );
		const s = rem % 60;
		if ( h > 0 ) {
			target.textContent = h + 'h ' + m + 'm ' + s + 's';
		} else if ( m > 0 ) {
			target.textContent = m + 'm ' + s + 's';
		} else {
			target.textContent = s + 's';
		}
	};

	// ---------------------------------------------------------------
	// Init per container
	// ---------------------------------------------------------------

	const initContainer = ( container ) => {
		updateCountdown( container );
		setInterval( () => updateCountdown( container ), 1000 );

		const buyBtn = container.querySelector( '[data-role="buy-now"]' );
		if ( buyBtn ) {
			buyBtn.addEventListener( 'click', ( ev ) => {
				ev.preventDefault();
				doBuyNow( container );
			} );
		}

		const bidForm = container.querySelector( '[data-role="bid-form"]' );
		if ( bidForm ) {
			const input = bidForm.querySelector( '[name="amount"]' );
			if ( input ) {
				const minBid = parseInt( container.getAttribute( 'data-min-bid' ), 10 ) || 0;
				if ( minBid > 0 ) {
					input.setAttribute( 'min', String( minBid ) );
					if ( ! input.value ) {
						input.placeholder = fmtPrice( minBid );
					}
				}
				input.addEventListener( 'input', () => input.classList.remove( 'is-error' ) );
			}
			bidForm.addEventListener( 'submit', ( ev ) => {
				ev.preventDefault();
				const amount = input ? parseInt( input.value, 10 ) : NaN;
				doBid( container, amount );
			} );
		}

		const cancelBtn = container.querySelector( '[data-role="cancel"]' );
		if ( cancelBtn ) {
			cancelBtn.addEventListener( 'click', ( ev ) => {
				ev.preventDefault();
				doCancel( container );
			} );
		}
	};

	const init = () => {
		const containers = document.querySelectorAll( '.psyern-ah-listing-detail[data-listing-id]' );
		containers.forEach( initContainer );
	};

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
})();
