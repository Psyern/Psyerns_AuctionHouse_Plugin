/**
 * Psyerns AuctionHouse — Price-History Chart (Chart.js v4).
 *
 * Exposes a single public function:
 *   window.psyernAhRenderPriceChart( container, itemClass, period )
 *
 * It also auto-initializes any DOM elements carrying
 *   .psyern-ah-price-chart[data-item-class][data-period]
 * on DOMContentLoaded, so the shortcode template just drops the container
 * in place with the right data attributes and does not need its own script.
 *
 * Datasets rendered (per README §13 #16):
 *   1. Average price — solid line  (dataset "avg")
 *   2. Min/Max price — hidden lines fill between as a band (datasets "min" + "max")
 *   3. Sale count   — bars on a secondary y-axis
 *
 * Period → time-scale unit / stepSize (Briefing 4):
 *   24h  → unit:'hour', stepSize:1
 *   7d   → unit:'hour', stepSize:6
 *   30d  → unit:'day',  stepSize:1
 *   all  → unit:'week', stepSize:1
 *
 * Chart.js v4 is expected to be loaded globally as `window.Chart`
 * (Agent 7's shortcode enqueues the vendored bundle before this file).
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

	const t = ( key, fallback ) => {
		const tr = window.psyernAh.translations || {};
		return ( typeof tr[ key ] === 'string' && tr[ key ] !== '' ) ? tr[ key ] : fallback;
	};

	const fmtPrice = ( amount ) => {
		const template = window.psyernAh.currencyFormat || '{amount}';
		const num = Number( amount || 0 );
		return template.replace( '{amount}', num.toLocaleString() );
	};

	const ALLOWED_PERIODS = [ '24h', '7d', '30d', 'all' ];

	const periodToTimeScale = ( period ) => {
		switch ( period ) {
			case '24h': return { unit: 'hour', stepSize: 1 };
			case '7d':  return { unit: 'hour', stepSize: 6 };
			case '30d': return { unit: 'day',  stepSize: 1 };
			case 'all':
			default:    return { unit: 'week', stepSize: 1 };
		}
	};

	// ---------------------------------------------------------------
	// Per-container state
	// ---------------------------------------------------------------

	const containers = new WeakMap(); // container -> { chart, canvas, overlay, period, itemClass, bound }

	const ensureStructure = ( container ) => {
		let state = containers.get( container );
		if ( state ) {
			return state;
		}

		container.classList.add( 'psyern-ah-price-chart' );

		// Period toggle buttons (if absent, we create them).
		let buttonBar = container.querySelector( '[data-role="period-bar"]' );
		if ( ! buttonBar ) {
			buttonBar = document.createElement( 'div' );
			buttonBar.className = 'psyern-ah-price-chart__periods';
			buttonBar.setAttribute( 'data-role', 'period-bar' );
			ALLOWED_PERIODS.forEach( ( p ) => {
				const btn = document.createElement( 'button' );
				btn.type = 'button';
				btn.className = 'psyern-ah-price-chart__period-btn';
				btn.setAttribute( 'data-period', p );
				btn.textContent = p;
				buttonBar.appendChild( btn );
			} );
			container.appendChild( buttonBar );
		}

		// Canvas host (fixed aspect via wrapper) — honour data-height if present.
		let canvasWrap = container.querySelector( '[data-role="canvas-wrap"]' );
		if ( ! canvasWrap ) {
			canvasWrap = document.createElement( 'div' );
			canvasWrap.className = 'psyern-ah-price-chart__canvas-wrap';
			canvasWrap.setAttribute( 'data-role', 'canvas-wrap' );
			const h = parseInt( container.getAttribute( 'data-height' ), 10 );
			if ( h && h > 0 ) {
				canvasWrap.style.height = h + 'px';
			}
			container.appendChild( canvasWrap );
		}

		let canvas = canvasWrap.querySelector( 'canvas' );
		if ( ! canvas ) {
			canvas = document.createElement( 'canvas' );
			canvasWrap.appendChild( canvas );
		}

		// Empty-state overlay.
		let overlay = container.querySelector( '[data-role="empty-overlay"]' );
		if ( ! overlay ) {
			overlay = document.createElement( 'div' );
			overlay.className = 'psyern-ah-price-chart__empty';
			overlay.setAttribute( 'data-role', 'empty-overlay' );
			overlay.hidden = true;
			overlay.textContent = t( 'no_price_data', 'Keine Daten verfügbar' );
			canvasWrap.appendChild( overlay );
		}

		state = {
			chart: null,
			canvas: canvas,
			overlay: overlay,
			period: '30d',
			itemClass: '',
			bound: false,
		};
		containers.set( container, state );
		return state;
	};

	const setActivePeriodButton = ( container, period ) => {
		const btns = container.querySelectorAll( '.psyern-ah-price-chart__period-btn' );
		btns.forEach( ( b ) => {
			if ( b.getAttribute( 'data-period' ) === period ) {
				b.classList.add( 'is-active' );
			} else {
				b.classList.remove( 'is-active' );
			}
		} );
	};

	const bindPeriodButtons = ( container, state ) => {
		if ( state.bound ) {
			return;
		}
		state.bound = true;
		const bar = container.querySelector( '[data-role="period-bar"]' );
		if ( ! bar ) {
			return;
		}
		bar.addEventListener( 'click', ( ev ) => {
			const btn = ev.target.closest( '.psyern-ah-price-chart__period-btn' );
			if ( ! btn ) {
				return;
			}
			const p = btn.getAttribute( 'data-period' );
			if ( ! p || p === state.period || ALLOWED_PERIODS.indexOf( p ) === -1 ) {
				return;
			}
			renderChart( container, state.itemClass, p );
		} );
	};

	// ---------------------------------------------------------------
	// Fetch + render
	// ---------------------------------------------------------------

	const buildDatasets = ( buckets ) => {
		const avgPoints = buckets.map( ( b ) => ( {
			x: Number( b.bucket_ts ) * 1000,
			y: b.avg_price === null || b.avg_price === undefined ? null : Number( b.avg_price ),
		} ) );
		const minPoints = buckets.map( ( b ) => ( {
			x: Number( b.bucket_ts ) * 1000,
			y: b.min_price === null || b.min_price === undefined ? null : Number( b.min_price ),
		} ) );
		const maxPoints = buckets.map( ( b ) => ( {
			x: Number( b.bucket_ts ) * 1000,
			y: b.max_price === null || b.max_price === undefined ? null : Number( b.max_price ),
		} ) );
		const barPoints = buckets.map( ( b ) => ( {
			x: Number( b.bucket_ts ) * 1000,
			y: Number( b.sale_count || 0 ),
		} ) );

		return [
			// min (baseline for the band — invisible line).
			{
				type: 'line',
				label: t( 'price_min', 'Min' ),
				data: minPoints,
				borderColor: 'rgba(0,0,0,0)',
				backgroundColor: 'rgba(0,0,0,0)',
				pointRadius: 0,
				fill: false,
				spanGaps: true,
				yAxisID: 'yPrice',
				order: 3,
				parsing: false,
			},
			// max — fills to previous dataset (min) producing a band.
			{
				type: 'line',
				label: t( 'price_max', 'Max' ),
				data: maxPoints,
				borderColor: 'rgba(0,0,0,0)',
				backgroundColor: 'rgba(99, 179, 237, 0.20)',
				pointRadius: 0,
				fill: '-1',
				spanGaps: true,
				yAxisID: 'yPrice',
				order: 2,
				parsing: false,
			},
			// avg — visible solid line with breaks.
			{
				type: 'line',
				label: t( 'price_avg', 'Durchschnitt' ),
				data: avgPoints,
				borderColor: '#3182ce',
				backgroundColor: '#3182ce',
				borderWidth: 2,
				pointRadius: 3,
				pointHoverRadius: 5,
				tension: 0.2,
				fill: false,
				spanGaps: false,
				yAxisID: 'yPrice',
				order: 1,
				parsing: false,
			},
			// Sale count bars on secondary axis.
			{
				type: 'bar',
				label: t( 'sale_count', 'Verkäufe' ),
				data: barPoints,
				backgroundColor: 'rgba(160, 174, 192, 0.55)',
				borderColor: 'rgba(113, 128, 150, 0.8)',
				borderWidth: 1,
				yAxisID: 'yCount',
				order: 4,
				parsing: false,
			},
		];
	};

	const buildChartConfig = ( datasets, period ) => {
		const ts = periodToTimeScale( period );
		return {
			type: 'bar', // mixed — default base for combined line+bar.
			data: { datasets: datasets },
			options: {
				responsive: true,
				maintainAspectRatio: false,
				interaction: { mode: 'index', intersect: false },
				plugins: {
					legend: { display: true, position: 'top' },
					tooltip: {
						callbacks: {
							label: ( ctx ) => {
								const dsLabel = ctx.dataset.label || '';
								const v = ctx.parsed.y;
								if ( ctx.dataset.yAxisID === 'yCount' ) {
									return dsLabel + ': ' + ( v === null ? '—' : v );
								}
								return dsLabel + ': ' + ( v === null ? '—' : fmtPrice( v ) );
							},
						},
					},
				},
				scales: {
					x: {
						type: 'time',
						time: {
							unit: ts.unit,
							stepSize: ts.stepSize,
							tooltipFormat: 'PP p',
						},
						ticks: {
							autoSkip: true,
							maxRotation: 0,
						},
					},
					yPrice: {
						type: 'linear',
						position: 'left',
						beginAtZero: false,
						ticks: {
							callback: ( v ) => fmtPrice( v ),
						},
					},
					yCount: {
						type: 'linear',
						position: 'right',
						beginAtZero: true,
						grid: { drawOnChartArea: false },
						ticks: {
							precision: 0,
						},
					},
				},
			},
		};
	};

	const renderChart = async ( container, itemClass, period ) => {
		if ( ! window.Chart ) {
			// Chart.js missing — show overlay and bail.
			const state0 = ensureStructure( container );
			state0.overlay.hidden = false;
			state0.overlay.textContent = 'Chart.js missing';
			return;
		}
		const state = ensureStructure( container );
		bindPeriodButtons( container, state );

		state.itemClass = itemClass || '';
		state.period = ALLOWED_PERIODS.indexOf( period ) !== -1 ? period : '30d';

		setActivePeriodButton( container, state.period );
		container.classList.add( 'is-loading' );
		state.overlay.hidden = true;

		if ( ! state.itemClass ) {
			container.classList.remove( 'is-loading' );
			state.overlay.hidden = false;
			state.overlay.textContent = t( 'no_price_data', 'Keine Daten verfügbar' );
			if ( state.chart ) {
				state.chart.destroy();
				state.chart = null;
			}
			return;
		}

		const params = new URLSearchParams();
		params.set( 'item_class', state.itemClass );
		params.set( 'period', state.period );

		const { ok, body } = await apiFetch( 'public/price-history?' + params.toString() );
		container.classList.remove( 'is-loading' );

		if ( ! ok || ! body ) {
			state.overlay.hidden = false;
			state.overlay.textContent = t( 'load_error', 'Fehler beim Laden.' );
			if ( state.chart ) {
				state.chart.destroy();
				state.chart = null;
			}
			return;
		}

		// Defensive: handle non-fatal partial errors.
		if ( Array.isArray( body.errors ) && body.errors.length > 0 ) {
			// The chart still renders if buckets exist — just log.
			try {
				window.console && window.console.warn && window.console.warn( 'psyern-ah price-chart partial errors', body.errors );
			} catch ( e ) { /* noop */ }
		}

		const buckets = Array.isArray( body.buckets ) ? body.buckets : [];
		if ( ! buckets.length ) {
			state.overlay.hidden = false;
			state.overlay.textContent = t( 'no_price_data', 'Keine Daten verfügbar' );
			if ( state.chart ) {
				state.chart.destroy();
				state.chart = null;
			}
			return;
		}

		const datasets = buildDatasets( buckets );
		const config = buildChartConfig( datasets, state.period );

		if ( state.chart ) {
			state.chart.destroy();
			state.chart = null;
		}
		state.chart = new window.Chart( state.canvas.getContext( '2d' ), config );
	};

	// ---------------------------------------------------------------
	// Public API
	// ---------------------------------------------------------------

	window.psyernAhRenderPriceChart = function ( container, itemClass, period ) {
		if ( ! container ) {
			return;
		}
		renderChart( container, itemClass || '', period || '30d' );
	};

	// ---------------------------------------------------------------
	// Auto-init
	// ---------------------------------------------------------------

	const autoInit = () => {
		const nodes = document.querySelectorAll( '.psyern-ah-price-chart[data-item-class]' );
		nodes.forEach( ( el ) => {
			const ic = el.getAttribute( 'data-item-class' ) || '';
			const p = el.getAttribute( 'data-period' ) || '30d';
			renderChart( el, ic, p );
		} );
	};

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', autoInit );
	} else {
		autoInit();
	}
})();
