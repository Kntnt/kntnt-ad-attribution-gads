<?php
/**
 * Google Ads conversion reporter.
 *
 * Registers enqueue and process callbacks on the core plugin's
 * `kntnt_ad_attr_conversion_reporters` filter, enabling asynchronous
 * conversion uploads to Google Ads via cron.
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
 * Follows the same pattern as `Gclid_Capturer`: a `register()` method
 * that conditionally adds the reporter when the plugin is configured.
 *
 * @since 0.3.0
 */
final class Conversion_Reporter {

	/**
	 * Creates the reporter with a Settings dependency.
	 *
	 * @param Settings $settings Plugin settings instance.
	 *
	 * @since 0.3.0
	 */
	public function __construct(
		private readonly Settings $settings,
	) {}

	/**
	 * Registers the Google Ads reporter if API credentials are configured.
	 *
	 * @param array<string,array{enqueue: callable, process: callable}> $reporters Existing reporters.
	 *
	 * @return array<string,array{enqueue: callable, process: callable}> Reporters with Google Ads added.
	 * @since 0.3.0
	 */
	public function register( array $reporters ): array {

		// Only register when all required API credentials are present.
		if ( ! $this->settings->is_configured() ) {
			return $reporters;
		}

		$reporters['google_ads'] = [
			'enqueue' => [ $this, 'enqueue' ],
			'process' => [ $this, 'process' ],
		];

		return $reporters;
	}

	/**
	 * Builds queue payloads for attributions that have a Google Ads click ID.
	 *
	 * Called by the core plugin when a conversion is attributed. Each payload
	 * snapshots all credentials so processing is self-contained.
	 *
	 * @param array<string,float>  $attributions Hash → attribution fraction.
	 * @param array<string,array<string,string>> $click_ids Hash → platform → click ID.
	 * @param array<string,array<string,string>> $campaigns Hash → campaign data.
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

		// Build the conversion action resource name.
		$conversion_action = "customers/{$settings['customer_id']}/conversionActions/{$settings['conversion_action_id']}";

		foreach ( $attributions as $hash => $fraction ) {

			// Skip hashes without a Google Ads click ID.
			if ( empty( $click_ids[ $hash ]['google_ads'] ) ) {
				continue;
			}

			// Calculate attributed conversion value.
			$attributed_value = (float) $settings['conversion_value'] * $fraction;

			$payloads[] = [
				'gclid'               => $click_ids[ $hash ]['google_ads'],
				'conversion_action'   => $conversion_action,
				'conversion_datetime' => $datetime,
				'conversion_value'    => $attributed_value,
				'currency_code'       => $settings['currency_code'],
				'customer_id'         => $settings['customer_id'],
				'developer_token'     => $settings['developer_token'],
				'client_id'           => $settings['client_id'],
				'client_secret'       => $settings['client_secret'],
				'refresh_token'       => $settings['refresh_token'],
				'login_customer_id'   => $settings['login_customer_id'],
			];
		}

		return $payloads;
	}

	/**
	 * Processes a single queued conversion payload.
	 *
	 * Creates a Google_Ads_Client from the snapshotted credentials
	 * and uploads the conversion. Logs errors via error_log().
	 *
	 * @param array<string,mixed> $payload Queue payload from enqueue().
	 *
	 * @return bool True on success, false on failure.
	 * @since 0.3.0
	 */
	public function process( array $payload ): bool {

		// Create API client from snapshotted credentials.
		$client = new Google_Ads_Client(
			customer_id: $payload['customer_id'],
			developer_token: $payload['developer_token'],
			client_id: $payload['client_id'],
			client_secret: $payload['client_secret'],
			refresh_token: $payload['refresh_token'],
			login_customer_id: $payload['login_customer_id'],
		);

		$result = $client->upload_click_conversion(
			$payload['gclid'],
			$payload['conversion_action'],
			$payload['conversion_datetime'],
			$payload['conversion_value'],
			$payload['currency_code'],
		);

		// Log failures for debugging.
		if ( ! $result['success'] ) {
			error_log( "Kntnt Ad Attribution Gads: Conversion upload failed for gclid {$payload['gclid']}: {$result['error']}" );
			return false;
		}

		return true;
	}

}
