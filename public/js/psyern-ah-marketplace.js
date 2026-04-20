/**
 * Psyerns AuctionHouse — Marketplace JS
 *
 * Handles filter UI, sort, pagination and card-grid rendering for the
 * [psyerns_auctionhouse_marketplace] shortcode. No jQuery. Vanilla ES6+.
 *
 * Expected DOM (rendered server-side by Agent 7's shortcode template):
 *   <div class="psyern-ah-marketplace" data-per-page="20">
 *     <form class="psyern-ah-marketplace__filters"> ... </form>
 *     <div class="psyern-ah-marketplace__grid"> ... </div>
 *     <div class="psyern-ah-marketplace__pagination"></div>
 *   </div>
 *
 * Filter controls (by name attribute inside the filters form):
 *   - category        <select>
 *   - listing_type    <input type=radio>   values: "" | "0" | "1"
 *   - price_min       <input type=number>
 *   - price_max       <input type=number>
 *   - search          <input type=text>
 *   - orderby         <select>
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

	const debounce = ( fn, ms ) => {
		let timer = null;
		return function ( ...args ) {
			if ( timer ) {
				clearTimeout( timer );
			}
			timer = setTimeout( () => fn.apply( this, args ), ms );
		};
	};

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

	const rarityClass = ( rarity ) => {
		const allowed = [ 'common', 'uncommon', 'rare', 'epic', 'legendary' ];
		const r = String( rarity || '' ).toLowerCase();
		return allowed.indexOf( r ) !== -1 ? 'psyern-ah-card--rarity-' + r : '';
	};

	// ---------------------------------------------------------------
	// State + URL sync
	// ---------------------------------------------------------------

	const readInitialState = ( container ) => {
		const params = new URLSearchParams( window.location.search );
		const perPage = parseInt( container.getAttribute( 'data-per-page' ), 10 ) || 20;
		return {
			category    : params.get( 'category' ) || '',
			listingType : params.get( 'type' ) || '',
			priceMin    : params.get( 'min' ) || '',
			priceMax    : params.get( 'max' ) || '',
			search      : params.get( 'q' ) || '',
			orderby     : params.get( 'sort' ) || 'newest',
			page        : Math.max( 1, parseInt( params.get( 'page' ), 10 ) || 1 ),
			perPage     : perPage,
		};
	};

	const stateToUrlParams = ( state ) => {
		const p = new URLSearchParams();
		if ( state.category )    { p.set( 'category', state.category ); }
		if ( state.listingType ) { p.set( 'type', state.listingType ); }
		if ( state.priceMin )    { p.set( 'min', state.priceMin ); }
		if ( state.priceMax )    { p.set( 'max', state.priceMax ); }
		if ( state.search )      { p.set( 'q', state.search ); }
		if ( state.orderby && state.orderby !== 'newest' ) { p.set( 'sort', state.orderby ); }
		if ( state.page && state.page !== 1 ) { p.set( 'page', String( state.page ) ); }
		return p;
	};

	const stateToApiParams = ( state ) => {
		const p = new URLSearchParams();
		if ( state.category !== '' )    { p.set( 'category_id', state.category ); }
		if ( state.listingType !== '' ) { p.set( 'listing_type', state.listingType ); }
		if ( state.priceMin !== '' )    { p.set( 'price_min', state.priceMin ); }
		if ( state.priceMax !== '' )    { p.set( 'price_max', state.priceMax ); }
		if ( state.search !== '' )      { p.set( 'search', state.search ); }
		if ( state.orderby )            { p.set( 'orderby', state.orderby ); }
		p.set( 'page', String( state.page || 1 ) );
		p.set( 'per_page', String( state.perPage || 20 ) );
		return p;
	};

	const pushHistory = ( state ) => {
		const p = stateToUrlParams( state );
		const qs = p.toString();
		const url = window.location.pathname + ( qs ? '?' + qs : '' ) + window.location.hash;
		window.history.pushState( { psyernAhState: state }, '', url );
	};

	// ---------------------------------------------------------------
	// Rendering
	// ---------------------------------------------------------------

	const listingTypeLabel = ( type ) => {
		switch ( parseInt( type, 10 ) ) {
			case 0: return t( 'type_buy_now', 'Sofortkauf' );
			case 1: return t( 'type_auction', 'Auktion' );
			case 2: return t( 'type_both', 'Auktion + Sofortkauf' );
			default: return '';
		}
	};

	const effectivePriceFor = ( item ) => {
		const type = parseInt( item.listing_type, 10 );
		if ( type === 0 ) {
			return Number( item.buy_now_price || 0 );
		}
		const curr = Number( item.current_bid || 0 );
		const start = Number( item.start_price || 0 );
		return Math.max( curr, start );
	};

	const renderCard = ( item ) => {
		const el = document.createElement( 'a' );
		el.className = 'psyern-ah-card ' + rarityClass( item.rarity );
		const listingId = encodeURIComponent( item.listing_id || '' );
		// Build href against the same page pattern agent 7's card links use.
		// Container can override via data-listing-url-template = "?listing={id}" etc.
		// Fallback: append ?listing=<id> to current page.
		const base = window.psyernAh.listingPermalinkBase || ( window.location.pathname + '?listing=' );
		el.href = base + listingId;
		el.setAttribute( 'data-listing-id', item.listing_id || '' );
		el.setAttribute( 'data-expires-ts', String( item.expires_ts || 0 ) );

		const icon = item.icon_url
			? '<img class="psyern-ah-card__icon" src="' + escapeHtml( item.icon_url ) + '" alt="" loading="lazy"/>'
			: '<div class="psyern-ah-card__icon psyern-ah-card__icon--placeholder"></div>';

		const price = effectivePriceFor( item );
		const priceHtml = fmtPrice( price );

		el.innerHTML = ''
			+ icon
			+ '<div class="psyern-ah-card__body">'
			+   '<div class="psyern-ah-card__title">' + escapeHtml( item.item_display || item.item_class || '' ) + '</div>'
			+   '<div class="psyern-ah-card__meta">'
			+     '<span class="psyern-ah-card__type">' + escapeHtml( listingTypeLabel( item.listing_type ) ) + '</span>'
			+     ( item.category_label ? '<span class="psyern-ah-card__cat">' + escapeHtml( item.category_label ) + '</span>' : '' )
			+   '</div>'
			+   '<div class="psyern-ah-card__price">' + escapeHtml( priceHtml ) + '</div>'
			+   '<div class="psyern-ah-card__countdown" data-countdown-target></div>'
			+ '</div>';

		return el;
	};

	const renderGrid = ( container, items ) => {
		const grid = container.querySelector( '.psyern-ah-marketplace__grid' );
		if ( ! grid ) {
			return;
		}
		grid.innerHTML = '';
		if ( ! items || ! items.length ) {
			const empty = document.createElement( 'div' );
			empty.className = 'psyern-ah-marketplace__empty';
			empty.textContent = t( 'no_results', 'Keine Auktionen gefunden.' );
			grid.appendChild( empty );
			return;
		}
		const frag = document.createDocumentFragment();
		items.forEach( ( item ) => frag.appendChild( renderCard( item ) ) );
		grid.appendChild( frag );
		updateCountdowns( grid );
	};

	const renderPagination = ( container, state, result ) => {
		const host = container.querySelector( '.psyern-ah-marketplace__pagination' );
		if ( ! host ) {
			return;
		}
		host.innerHTML = '';
		const totalPages = Math.max( 1, parseInt( result.total_pages, 10 ) || 1 );
		const current = Math.max( 1, Math.min( totalPages, parseInt( state.page, 10 ) || 1 ) );
		if ( totalPages <= 1 ) {
			return;
		}

		const mkBtn = ( label, targetPage, disabled, active ) => {
			const btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'psyern-ah-marketplace__page';
			if ( active ) {
				btn.classList.add( 'is-active' );
			}
			btn.textContent = label;
			btn.disabled = !! disabled;
			if ( ! disabled ) {
				btn.addEventListener( 'click', () => {
					state.page = targetPage;
					applyFilters( container, state, true );
				} );
			}
			return btn;
		};

		host.appendChild( mkBtn( t( 'prev', 'Zurück' ), current - 1, current <= 1, false ) );

		// Windowed page numbers (max 7 visible).
		const windowSize = 5;
		let start = Math.max( 1, current - Math.floor( windowSize / 2 ) );
		let end = Math.min( totalPages, start + windowSize - 1 );
		start = Math.max( 1, end - windowSize + 1 );

		if ( start > 1 ) {
			host.appendChild( mkBtn( '1', 1, false, false ) );
			if ( start > 2 ) {
				const dots = document.createElement( 'span' );
				dots.className = 'psyern-ah-marketplace__dots';
				dots.textContent = '…';
				host.appendChild( dots );
			}
		}
		for ( let p = start; p <= end; p++ ) {
			host.appendChild( mkBtn( String( p ), p, false, p === current ) );
		}
		if ( end < totalPages ) {
			if ( end < totalPages - 1 ) {
				const dots = document.createElement( 'span' );
				dots.className = 'psyern-ah-marketplace__dots';
				dots.textContent = '…';
				host.appendChild( dots );
			}
			host.appendChild( mkBtn( String( totalPages ), totalPages, false, false ) );
		}

		host.appendChild( mkBtn( t( 'next', 'Weiter' ), current + 1, current >= totalPages, false ) );
	};

	// Live countdowns on cards — optional polish.
	const updateCountdowns = ( root ) => {
		const nodes = root.querySelectorAll( '[data-countdown-target]' );
		if ( ! nodes.length ) {
			return;
		}
		const now = Math.floor( Date.now() / 1000 );
		nodes.forEach( ( el ) => {
			const card = el.closest( '[data-expires-ts]' );
			if ( ! card ) {
				el.textContent = '';
				return;
			}
			const expires = parseInt( card.getAttribute( 'data-expires-ts' ), 10 ) || 0;
			const remaining = expires - now;
			if ( remaining <= 0 ) {
				el.textContent = t( 'expired', 'Abgelaufen' );
				return;
			}
			const h = Math.floor( remaining / 3600 );
			const m = Math.floor( ( remaining % 3600 ) / 60 );
			const s = remaining % 60;
			if ( h > 0 ) {
				el.textContent = h + 'h ' + m + 'm';
			} else if ( m > 0 ) {
				el.textContent = m + 'm ' + s + 's';
			} else {
				el.textContent = s + 's';
			}
		} );
	};

	// ---------------------------------------------------------------
	// Fetch + apply
	// ---------------------------------------------------------------

	let inFlight = 0;

	const setBusy = ( container, busy ) => {
		if ( busy ) {
			container.classList.add( 'is-loading' );
		} else {
			container.classList.remove( 'is-loading' );
		}
	};

	const applyFilters = async ( container, state, updateHistory ) => {
		setBusy( container, true );
		const myReq = ++inFlight;
		const params = stateToApiParams( state );

		const { ok, body } = await apiFetch( 'public/listings?' + params.toString() );

		// Ignore stale responses when the user has fired another filter change.
		if ( myReq !== inFlight ) {
			return;
		}

		setBusy( container, false );

		if ( ! ok || ! body ) {
			const grid = container.querySelector( '.psyern-ah-marketplace__grid' );
			if ( grid ) {
				grid.innerHTML = '';
				const err = document.createElement( 'div' );
				err.className = 'psyern-ah-marketplace__error';
				err.textContent = t( 'load_error', 'Fehler beim Laden der Auktionen.' );
				grid.appendChild( err );
			}
			return;
		}

		renderGrid( container, body.items || [] );
		renderPagination( container, state, body );

		if ( updateHistory ) {
			pushHistory( state );
		}
	};

	// ---------------------------------------------------------------
	// Filter wiring
	// ---------------------------------------------------------------

	const syncInputsToState = ( form, state ) => {
		if ( ! form ) {
			return;
		}
		const catSel = form.querySelector( '[name="category"]' );
		if ( catSel ) { catSel.value = state.category; }

		const typeRadios = form.querySelectorAll( '[name="listing_type"]' );
		typeRadios.forEach( ( r ) => { r.checked = ( r.value === state.listingType ); } );

		const pmin = form.querySelector( '[name="price_min"]' );
		if ( pmin ) { pmin.value = state.priceMin; }
		const pmax = form.querySelector( '[name="price_max"]' );
		if ( pmax ) { pmax.value = state.priceMax; }

		const search = form.querySelector( '[name="search"]' );
		if ( search ) { search.value = state.search; }

		const sort = form.querySelector( '[name="orderby"]' );
		if ( sort ) { sort.value = state.orderby; }
	};

	const wireForm = ( container, state ) => {
		const form = container.querySelector( '.psyern-ah-marketplace__filters' );
		if ( ! form ) {
			return;
		}
		form.addEventListener( 'submit', ( ev ) => ev.preventDefault() );

		syncInputsToState( form, state );

		const onChange = () => {
			const catSel = form.querySelector( '[name="category"]' );
			state.category = catSel ? catSel.value : '';

			const typeEl = form.querySelector( '[name="listing_type"]:checked' );
			state.listingType = typeEl ? typeEl.value : '';

			const pmin = form.querySelector( '[name="price_min"]' );
			state.priceMin = pmin ? pmin.value : '';
			const pmax = form.querySelector( '[name="price_max"]' );
			state.priceMax = pmax ? pmax.value : '';

			const sort = form.querySelector( '[name="orderby"]' );
			state.orderby = sort ? sort.value : 'newest';

			state.page = 1;
			applyFilters( container, state, true );
		};

		const debouncedChange = debounce( onChange, 300 );

		form.addEventListener( 'change', ( ev ) => {
			if ( ev.target && ev.target.name === 'search' ) {
				return;
			}
			onChange();
		} );

		const search = form.querySelector( '[name="search"]' );
		if ( search ) {
			search.addEventListener( 'input', () => {
				state.search = search.value;
				state.page = 1;
				debouncedChange();
			} );
		}

		// Allow explicit reset button if present.
		const reset = form.querySelector( '[data-psyern-ah-reset]' );
		if ( reset ) {
			reset.addEventListener( 'click', ( ev ) => {
				ev.preventDefault();
				state.category = '';
				state.listingType = '';
				state.priceMin = '';
				state.priceMax = '';
				state.search = '';
				state.orderby = 'newest';
				state.page = 1;
				syncInputsToState( form, state );
				applyFilters( container, state, true );
			} );
		}
	};

	// ---------------------------------------------------------------
	// Init
	// ---------------------------------------------------------------

	const initContainer = ( container ) => {
		const state = readInitialState( container );

		wireForm( container, state );
		applyFilters( container, state, false );

		// Countdown tick — 1 Hz.
		setInterval( () => updateCountdowns( container ), 1000 );

		// Back/forward navigation.
		window.addEventListener( 'popstate', ( ev ) => {
			const next = ( ev.state && ev.state.psyernAhState ) || readInitialState( container );
			Object.assign( state, next );
			const form = container.querySelector( '.psyern-ah-marketplace__filters' );
			syncInputsToState( form, state );
			applyFilters( container, state, false );
		} );
	};

	const init = () => {
		const containers = document.querySelectorAll( '.psyern-ah-marketplace' );
		containers.forEach( initContainer );
	};

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
})();
