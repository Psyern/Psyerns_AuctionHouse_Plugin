<?php
/**
 * Upload handler for Psyerns AuctionHouse.
 *
 * Implements POST /internal/upload — the single entry point through which the
 * DayZ mod (PF_AH_Sync) pushes listings, recent transactions and player
 * balances to WordPress. Runs behind the API-key permission callback registered
 * in Psyern_AH_Api.
 *
 * @package Psyerns_AuctionHouse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Psyern_AH_Upload
 *
 * Expected payload shape (Briefing 1, Mod -> WP):
 *
 *   {
 *     "generatedAt": "2026-04-20T14:00:00Z",
 *     "serverTimeEpoch": 1713621600,
 *     "currencyMode": "Expansion" | "Internal",
 *     "listings":            [ { ...PascalCase DME_AH_Listing... } ],
 *     "recentTransactions":  [ { ...PascalCase DME_AH_Transaction... } ],
 *     "balances":            [ { "uid":"...", "source":"Expansion"|"Internal",
 *                                "balance":12340 } ]
 *   }
 *
 * Section resilience: each of the three sub-payloads (listings / transactions /
 * balances) is processed inside its own try-catch so that a failure in one
 * section does not kill the others. The response carries an `errors` array
 * summarising any per-section failures, and returns 207 Multi-Status if any
 * section failed but at least one other succeeded.
 */
class Psyern_AH_Upload {

	/**
	 * Meta transient key — last upload metadata (generated_at, server_time_epoch,
	 * currency_mode, last_upload_at).
	 */
	const META_TRANSIENT_KEY = 'psyern_ah_upload_meta';

	/**
	 * Meta transient TTL in seconds.
	 */
	const META_TTL = 600;

	/**
	 * Soft-warn threshold for upload body size (1 MB).
	 */
	const SOFT_WARN_BYTES = 1048576;

	/**
	 * Handle the REST call.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function handle_upload( WP_REST_Request $request ) {
		// Raw-body size check (soft warn only).
		$raw = (string) $request->get_body();
		$len = strlen( $raw );
		if ( $len > self::SOFT_WARN_BYTES ) {
			error_log( '[Psyern AH] Upload payload >1MB: ' . $len . ' bytes' );
		}

		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response(
				array(
					'error'   => 'invalid_payload',
					'message' => 'JSON body required.',
				),
				400
			);
		}

		$listings     = isset( $payload['listings'] ) && is_array( $payload['listings'] )
			? $payload['listings']
			: array();
		$transactions = isset( $payload['recentTransactions'] ) && is_array( $payload['recentTransactions'] )
			? $payload['recentTransactions']
			: array();
		$balances     = isset( $payload['balances'] ) && is_array( $payload['balances'] )
			? $payload['balances']
			: array();

		$listings_stats     = array(
			'upserted' => 0,
			'removed'  => 0,
		);
		$transactions_stats = array(
			'inserted' => 0,
			'skipped'  => 0,
			'failed'   => 0,
		);
		$balances_stats     = array(
			'upserted' => 0,
			'failed'   => 0,
		);

		$errors = array();

		// ---- Listings (full-sync) -------------------------------------------.
		try {
			if ( class_exists( 'Psyern_AH_Listings' ) ) {
				$svc = new Psyern_AH_Listings();
				if ( method_exists( $svc, 'full_sync' ) ) {
					$result = $svc->full_sync( $listings );
					if ( is_array( $result ) ) {
						$listings_stats['upserted'] = isset( $result['upserted'] ) ? (int) $result['upserted'] : 0;
						$listings_stats['removed']  = isset( $result['removed'] ) ? (int) $result['removed'] : 0;
					}
				} else {
					$errors[] = array(
						'section' => 'listings',
						'error'   => 'method_missing',
						'hint'    => 'Psyern_AH_Listings::full_sync not found',
					);
				}
			} else {
				$errors[] = array(
					'section' => 'listings',
					'error'   => 'service_missing',
					'hint'    => 'Psyern_AH_Listings class not loaded',
				);
			}
		} catch ( Exception $e ) {
			$errors[] = array(
				'section' => 'listings',
				'error'   => 'exception',
				'message' => $e->getMessage(),
			);
		}

		// ---- Transactions (delta, idempotent) -------------------------------.
		try {
			if ( class_exists( 'Psyern_AH_Transactions' ) ) {
				$svc = new Psyern_AH_Transactions();
				if ( method_exists( $svc, 'add_transactions' ) ) {
					$result = $svc->add_transactions( $transactions );
					if ( is_array( $result ) ) {
						$transactions_stats['inserted'] = isset( $result['inserted'] ) ? (int) $result['inserted'] : 0;
						$transactions_stats['skipped']  = isset( $result['skipped'] ) ? (int) $result['skipped'] : 0;
						$transactions_stats['failed']   = isset( $result['failed'] ) ? (int) $result['failed'] : 0;
					}
				} else {
					$errors[] = array(
						'section' => 'transactions',
						'error'   => 'method_missing',
						'hint'    => 'Psyern_AH_Transactions::add_transactions not found',
					);
				}
			} else {
				$errors[] = array(
					'section' => 'transactions',
					'error'   => 'service_missing',
					'hint'    => 'Psyern_AH_Transactions class not loaded',
				);
			}
		} catch ( Exception $e ) {
			$errors[] = array(
				'section' => 'transactions',
				'error'   => 'exception',
				'message' => $e->getMessage(),
			);
		}

		// ---- Balances (per-entry upsert) ------------------------------------.
		try {
			if ( class_exists( 'Psyern_AH_Balances' ) ) {
				$svc = new Psyern_AH_Balances();
				foreach ( $balances as $entry ) {
					if ( ! is_array( $entry ) ) {
						++$balances_stats['failed'];
						continue;
					}
					$uid     = isset( $entry['uid'] ) ? (string) $entry['uid'] : '';
					$source  = isset( $entry['source'] ) ? (string) $entry['source'] : '';
					$balance = isset( $entry['balance'] ) ? (int) $entry['balance'] : 0;

					if ( $svc->upsert_balance( $uid, $source, $balance ) ) {
						++$balances_stats['upserted'];
					} else {
						++$balances_stats['failed'];
					}
				}
			} else {
				$errors[] = array(
					'section' => 'balances',
					'error'   => 'service_missing',
					'hint'    => 'Psyern_AH_Balances class not loaded',
				);
			}
		} catch ( Exception $e ) {
			$errors[] = array(
				'section' => 'balances',
				'error'   => 'exception',
				'message' => $e->getMessage(),
			);
		}

		// ---- Store last-upload meta transient -------------------------------.
		$meta = array(
			'generated_at'      => isset( $payload['generatedAt'] ) ? (string) $payload['generatedAt'] : '',
			'server_time_epoch' => isset( $payload['serverTimeEpoch'] ) ? (int) $payload['serverTimeEpoch'] : 0,
			'currency_mode'     => isset( $payload['currencyMode'] ) ? (string) $payload['currencyMode'] : '',
			'last_upload_at'    => time(),
			'sizes'             => array(
				'listings'     => count( $listings ),
				'transactions' => count( $transactions ),
				'balances'     => count( $balances ),
				'body_bytes'   => $len,
			),
		);
		set_transient( self::META_TRANSIENT_KEY, $meta, self::META_TTL );

		$body = array(
			'ok'           => empty( $errors ),
			'listings'     => $listings_stats,
			'transactions' => $transactions_stats,
			'balances'     => $balances_stats,
			'errors'       => $errors,
		);

		$status = empty( $errors ) ? 200 : 207;

		return new WP_REST_Response( $body, $status );
	}
}
