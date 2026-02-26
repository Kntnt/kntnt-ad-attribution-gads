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

/**
 * Hooks into the core plugin's conversion reporting system.
 *
 * @since 0.3.0
 */
final class Conversion_Reporter {

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
	 * @param Logger   $logger   Diagnostic logger instance.
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
	 * Builds queue payloads for attributions that have a Google Ads click ID.
	 *
	 * Snapshots current settings into each payload as raw values. Derived
	 * values (resource name, attributed value) are computed at process time
	 * so payloads remain useful even if credentials were empty at enqueue time.
	 *
	 * @param array<string,float>  $attributions Hash => attribution fraction.
	 * @param array<string,array<string,string>> $click_ids Hash => platform => click ID.
	 * @param array<string,array<string,string>> $campaigns Hash => campaign data.
	 * @param array{timestamp: string}           $context   Conversion context.
	 *
	 * @return array<int,array<string,mixed>> Queue payloads for Google Ads.
	 * @since 0.3.0
	 */
	public function enqueue( array $attributions, array $click_ids, array $campaigns, array $context ): array {
		$settings = $this->settings->get_all();
		$payloads = [];

		// Format timestamp for Google Ads (RFC 3339 with offset).
		$datetime = ( new DateTimeImmutable( $context['timestamp'] ) )->format( 'Y-m-d H:i:sP' );

		foreach ( $attributions as $hash => $fraction ) {

			// Skip hashes without a Google Ads click ID.
			if ( empty( $click_ids[ $hash ]['google_ads'] ) ) {
				continue;
			}

			// Snapshot raw values; derived values are computed in process().
			$this->logger->info( "Enqueued — gclid: {$click_ids[ $hash ]['google_ads']}, datetime: {$datetime}, fraction: {$fraction}" );
			$payloads[] = [
				'gclid'                => $click_ids[ $hash ]['google_ads'],
				'conversion_datetime'  => $datetime,
				'attribution_fraction' => $fraction,
				'customer_id'          => $settings['customer_id'],
				'conversion_action_id' => $settings['conversion_action_id'],
				'conversion_value'     => $settings['conversion_value'],
				'currency_code'        => $settings['currency_code'],
				'developer_token'      => $settings['developer_token'],
				'client_id'            => $settings['client_id'],
				'client_secret'        => $settings['client_secret'],
				'refresh_token'        => $settings['refresh_token'],
				'login_customer_id'    => $settings['login_customer_id'],
			];
		}

		return $payloads;
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
			$this->logger->error( "Aborted — gclid: {$payload['gclid']}, missing credentials" );
			error_log( "Kntnt Ad Attribution Gads: Cannot process gclid {$payload['gclid']} — required credentials still missing." );
			return false;
		}

		// Log processing start with diagnostic context.
		$this->logger->info( "Processing — gclid: {$payload['gclid']}, customer: {$customer_id}, action_id: {$conversion_action_id}" );

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
