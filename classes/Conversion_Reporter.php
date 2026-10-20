<?php
/**
 * Google Ads conversion reporter.
 *
 * Registers enqueue and process callbacks on the core plugin's
 * `kntnt_ad_attr_conversion_reporters` filter, enabling asynchronous
 * conversion uploads to Google Ads via cron.
 *
 * Always registers regardless of credential status so conversions are
 * queued even during credential outages. Missing credentials are filled
 * in from current settings at processing time.
 *
 * @package Kntnt\Ad_Attribution_Gads
 * @since   0.3.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution_Gads;

use DateTimeImmutable;
use Kntnt\Ad_Attribution\Logger;

/**
 * Hooks into the core plugin's conversion reporting system.
 *
 * @since 0.3.0
 */
final class Conversion_Reporter {

	/**
	 * Seconds to delay upload after the attributed click (6 hours).
	 *
	 * Google Ads needs time to register the click before accepting
	 * offline conversions. Using click time (not conversion time)
	 * minimizes unnecessary wait for old clicks already past the window.
	 *
	 * @var int
	 * @since 1.8.0
	 */
	private const UPLOAD_DELAY_SECONDS = 21600;

	/**
	 * Transient key for flagging credential errors in admin.
	 *
	 * @var string
	 * @since 0.5.0
	 */
	public const CREDENTIAL_ERROR_TRANSIENT = 'kntnt_ad_attr_gads_credential_error';

	/**
	 * Creates the reporter with Settings and Logger dependencies.
	 *
	 * @param Settings $settings Plugin settings instance.
	 * @param Logger   $logger   Core diagnostic logger instance.
	 *
	 * @since 0.3.0
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly Logger $logger,
	) {}

	/**
	 * Registers the Google Ads reporter unconditionally.
	 *
	 * Conversions are always queued so nothing is lost during credential
	 * outages. The process() callback fills in missing credentials from
	 * current settings and retries if still incomplete.
	 *
	 * @param array<string,array{enqueue: callable, process: callable}> $reporters Existing reporters.
	 *
	 * @return array<string,array{enqueue: callable, process: callable}> Reporters with Google Ads added.
	 * @since 0.3.0
	 */
	public function register( array $reporters ): array {
		$reporters['google_ads'] = [
			'enqueue' => [ $this, 'enqueue' ],
			'process' => [ $this, 'process' ],
		];

		return $reporters;
	}

	/**
	 * Builds queue payloads for the eligible attributions of a conversion.
	 *
	 * Only eligible attributions (see is_eligible()) produce uploads. Google
	 * Ads counts every accepted click conversion as one conversion whatever
	 * its value, so the `kntnt_ad_attr_gads_multi_touch` filter decides how
	 * many uploads a multi-touch conversion produces:
	 *
	 * - `highest` (default): one upload for the eligible attribution with the
	 *   highest fraction (ties go to the latest click, then to the first in
	 *   `$attributions` order), carrying the sum of all eligible fractions,
	 *   capped at 1.0. Google Ads gets the same total value as in `split`
	 *   but counts one conversion.
	 * - `split`: one upload per eligible attribution with its own fraction.
	 *
	 * Snapshots current settings into each payload as raw values. Derived
	 * values (resource name, attributed value) are computed at process time
	 * so payloads remain useful even if credentials were empty at enqueue time.
	 *
	 * Each job is deferred by UPLOAD_DELAY_SECONDS from the time of the
	 * attributed click to give Google Ads time to register the click before
	 * accepting uploads.
	 *
	 * @param array<string,float>  $attributions Hash => attribution fraction.
	 * @param array<string,array<string,array{id: string, captured_at: int}>> $click_ids Hash => platform => click ID captured on the hash's attributed click (core >= 1.10.0), with `captured_at` the Unix time of that click.
	 * @param array<string,array<string,string>> $campaigns Hash => campaign data.
	 * @param array{timestamp: string}           $context   Conversion context.
	 *
	 * @return array<int,array<string,mixed>> Queue payloads for Google Ads.
	 * @since 0.3.0
	 */
	public function enqueue( array $attributions, array $click_ids, array $campaigns, array $context ): array {
		$settings = $this->settings->get_all();

		// Format timestamp for Google Ads (RFC 3339 with offset).
		$datetime = ( new DateTimeImmutable( $context['timestamp'] ) )->format( 'Y-m-d H:i:sP' );

		// Fractions come from the user-filterable core attribution model, so
		// cast before the strictly typed eligibility check.
		$eligible = [];
		foreach ( $attributions as $hash => $fraction ) {
			$fraction = (float) $fraction;
			$click    = $this->google_ads_click( $click_ids[ $hash ] ?? [] );
			if ( $this->is_eligible( $fraction, $click ) ) {
				$eligible[] = [
					'fraction' => $fraction,
					'click'    => $click,
				];
			}
		}

		if ( ! $eligible ) {
			return [];
		}

		/**
		 * Filters how a multi-touch conversion is uploaded to Google Ads.
		 *
		 * `highest` uploads one conversion per recorded conversion; `split`
		 * uploads one per eligible attribution, which Google Ads counts as
		 * separate conversions. Any other value is treated as `highest`.
		 *
		 * @param string $mode Upload mode. Default 'highest'.
		 *
		 * @since 1.11.0
		 */
		$mode = apply_filters( 'kntnt_ad_attr_gads_multi_touch', 'highest' );

		// Anything but an exact 'split' falls back to the default, so a typo
		// in a filter callback cannot inflate the conversion count.
		if ( $mode === 'split' ) {
			return array_map(
				fn( array $attribution ): array => $this->build_item( $attribution['click'], $attribution['fraction'], $settings, $datetime, 'mode: split' ),
				$eligible,
			);
		}

		// The summed fraction keeps the uploaded value equal to the split
		// total; the cap guards against attribution models that sum above 1.
		$chosen   = $this->select_highest( $eligible );
		$fraction = min( 1.0, array_sum( array_column( $eligible, 'fraction' ) ) );
		$note     = sprintf( 'mode: highest, merged: %d', count( $eligible ) );

		return [ $this->build_item( $chosen['click'], $fraction, $settings, $datetime, $note ) ];
	}

	/**
	 * Picks the eligible attribution that a `highest` upload is built from.
	 *
	 * The highest fraction wins. Among equal fractions the latest click wins,
	 * in line with the core's last-click default; among equal capture times
	 * the first in input order wins, so the choice is deterministic.
	 *
	 * @param non-empty-list<array{fraction: float, click: array{id: string, captured_at: int}}> $eligible Eligible attributions in input order.
	 *
	 * @return array{fraction: float, click: array{id: string, captured_at: int}} The chosen attribution.
	 * @since 1.11.0
	 */
	private function select_highest( array $eligible ): array {
		$chosen = $eligible[0];

		foreach ( $eligible as $candidate ) {
			$heavier  = $candidate['fraction'] > $chosen['fraction'];
			$wins_tie = $candidate['fraction'] === $chosen['fraction'] && $candidate['click']['captured_at'] > $chosen['click']['captured_at'];
			if ( $heavier || $wins_tie ) {
				$chosen = $candidate;
			}
		}

		return $chosen;
	}

	/**
	 * Builds one queue item for a Google Ads click and logs it.
	 *
	 * @param array{id: string, captured_at: int} $click    Structured Google Ads click data.
	 * @param float                               $fraction Attribution fraction to upload.
	 * @param array<string,string>                $settings Current plugin settings.
	 * @param string                              $datetime Conversion time in Google Ads format.
	 * @param string                              $note     Mode details for the log line.
	 *
	 * @return array<string,mixed> Queue item with payload, label and not_before.
	 * @since 1.11.0
	 */
	private function build_item( array $click, float $fraction, array $settings, string $datetime, string $note ): array {
		$gclid                = $click['id'];
		$not_before           = $click['captured_at'] + self::UPLOAD_DELAY_SECONDS;
		$conversion_action_id = $settings['conversion_action_id'];

		$this->logger->info( 'GADS', "Enqueued — {$note}, gclid: {$gclid}, datetime: {$datetime}, fraction: {$fraction}" );

		return [
			'payload'    => [
				'gclid'                => $gclid,
				'conversion_datetime'  => $datetime,
				'attribution_fraction' => $fraction,
				'customer_id'          => $settings['customer_id'],
				'conversion_action_id' => $conversion_action_id,
				'conversion_value'     => $settings['conversion_value'],
				'currency_code'        => $settings['currency_code'],
				'developer_token'      => $settings['developer_token'],
				'client_id'            => $settings['client_id'],
				'client_secret'        => $settings['client_secret'],
				'refresh_token'        => $settings['refresh_token'],
				'login_customer_id'    => $settings['login_customer_id'],
			],
			'label'      => sprintf( 'gclid %s → action %s', substr( $gclid, 0, 12 ) . '…', $conversion_action_id ),
			'not_before' => $not_before,
		];
	}

	/**
	 * Tells whether an attribution may produce a Google Ads upload.
	 *
	 * The single definition of an eligible attribution: positive weight and
	 * a Google Ads click identifier. A zero-weight upload would still count
	 * as a full conversion in Google Ads, so a missing weight disqualifies
	 * an attribution as surely as a missing click identifier.
	 *
	 * @param float                                    $fraction Attribution fraction of the hash.
	 * @param array{id: string, captured_at: int}|null $click    The hash's Google Ads click from google_ads_click().
	 *
	 * @return bool True if the attribution may be uploaded.
	 * @since 1.11.0
	 */
	private function is_eligible( float $fraction, ?array $click ): bool {
		return $fraction > 0 && $click !== null;
	}

	/**
	 * Resolves the Google Ads click identifier of a hash.
	 *
	 * The only place that decides which captured click IDs count as a Google
	 * Ads click, so supporting further identifier types means changing this
	 * method alone.
	 *
	 * @param array<string,mixed> $hash_click_ids Platform => structured click data for the hash.
	 *
	 * @return array{id: string, captured_at: int}|null The click, or null if the hash has no Google Ads click identifier.
	 * @since 1.11.0
	 */
	private function google_ads_click( array $hash_click_ids ): ?array {
		return empty( $hash_click_ids['google_ads']['id'] ) ? null : $hash_click_ids['google_ads'];
	}

	/**
	 * Processes a single queued conversion payload.
	 *
	 * Merges snapshotted credentials with current settings so payloads
	 * queued during a credential outage can be processed once credentials
	 * are restored. Computes derived values (resource name, attributed
	 * value) from the merged data.
	 *
	 * @param array<string,mixed> $payload Queue payload from enqueue().
	 *
	 * @return bool True on success, false on failure (will be retried).
	 * @since 0.3.0
	 */
	public function process( array $payload ): bool {
		$settings = $this->settings->get_all();

		// Merge: prefer payload snapshot, fall back to current settings.
		// Exception: conversion_action_id prefers current settings because
		// changing the action ID is intentional (e.g. replacing a misconfigured
		// action), and old queued jobs must follow the new configuration.
		$customer_id          = $payload['customer_id'] ?: $settings['customer_id'];
		$conversion_action_id = $settings['conversion_action_id'] ?: $payload['conversion_action_id'];
		$conversion_value     = $payload['conversion_value'] ?: $settings['conversion_value'];
		$currency_code        = $payload['currency_code'] ?: $settings['currency_code'];
		$developer_token      = $payload['developer_token'] ?: $settings['developer_token'];
		$client_id            = $payload['client_id'] ?: $settings['client_id'];
		$client_secret        = $payload['client_secret'] ?: $settings['client_secret'];
		$refresh_token        = $payload['refresh_token'] ?: $settings['refresh_token'];
		$login_customer_id    = $payload['login_customer_id'] ?: $settings['login_customer_id'];

		// Abort if required credentials are still missing after merge.
		if ( ! $customer_id || ! $conversion_action_id || ! $developer_token || ! $client_id || ! $client_secret || ! $refresh_token ) {
			set_transient( self::CREDENTIAL_ERROR_TRANSIENT, 'missing', 0 );
			$this->logger->error( 'GADS', "Aborted — gclid: {$payload['gclid']}, missing credentials" );
			error_log( "Kntnt Ad Attribution Gads: Cannot process gclid {$payload['gclid']} — required credentials still missing." );
			return false;
		}

		// Log processing start with diagnostic context.
		$this->logger->info( 'GADS', "Processing — gclid: {$payload['gclid']}, customer: {$customer_id}, action_id: {$conversion_action_id}" );

		// Compute derived values from merged raw data.
		$conversion_action = "customers/{$customer_id}/conversionActions/{$conversion_action_id}";
		$attributed_value  = (float) $conversion_value * (float) $payload['attribution_fraction'];

		// Create API client and upload conversion.
		$client = new Google_Ads_Client(
			customer_id: $customer_id,
			developer_token: $developer_token,
			client_id: $client_id,
			client_secret: $client_secret,
			refresh_token: $refresh_token,
			login_customer_id: $login_customer_id,
			logger: $this->logger,
		);

		$result = $client->upload_click_conversion(
			$payload['gclid'],
			$conversion_action,
			$payload['conversion_datetime'],
			$attributed_value,
			$currency_code,
		);

		// Log failures for debugging.
		if ( ! $result['success'] ) {

			// Flag credential problems so the admin notice can alert the user.
			if ( ! empty( $result['credential_error'] ) ) {
				set_transient( self::CREDENTIAL_ERROR_TRANSIENT, 'token_refresh_failed', 0 );
			}

			// Permanent API failures (e.g. CLICK_NOT_FOUND) will never succeed
			// on retry. Return true to stop the queue from retrying. The
			// conversion is already recorded in the core plugin's stats.
			// A permanent failure means the API was reached (credentials
			// worked), so clear any stale credential error flag.
			if ( ! empty( $result['permanent_failure'] ) ) {
				delete_transient( self::CREDENTIAL_ERROR_TRANSIENT );
				$this->logger->error( 'GADS', "Permanent failure — gclid: {$payload['gclid']}, will not retry: {$result['error']}" );
				error_log( "Kntnt Ad Attribution Gads: Permanent failure for gclid {$payload['gclid']}: {$result['error']}" );
				return true;
			}

			error_log( "Kntnt Ad Attribution Gads: Conversion upload failed for gclid {$payload['gclid']}: {$result['error']}" );
			return false;
		}

		// Clear any previous credential error flag on success.
		delete_transient( self::CREDENTIAL_ERROR_TRANSIENT );

		return true;
	}

	/**
	 * Resets all failed Google Ads queue jobs to pending.
	 *
	 * Called when settings are updated with valid credentials, giving
	 * previously failed jobs another chance with the new credentials.
	 *
	 * @return void
	 * @since 0.4.0
	 */
	public function reset_failed_jobs(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'kntnt_ad_attr_queue';

		// Reset failed jobs so they are retried with current credentials.
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET status = 'pending', attempts = 0, error_message = NULL WHERE reporter = %s AND status = 'failed'",
			'google_ads',
		) );

		// Trigger queue processing on the next available tick.
		wp_schedule_single_event( time(), 'kntnt_ad_attr_process_queue' );
	}

}
