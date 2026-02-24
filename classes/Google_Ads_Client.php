<?php
/**
 * Google Ads REST API client.
 *
 * Standalone HTTP client for the Google Ads Offline Conversion Upload API.
 * Handles OAuth2 token refresh and single click conversion uploads.
 * No dependency on Settings — receives all credentials via constructor.
 *
 * @package Kntnt\Ad_Attribution_Gads
 * @since   0.3.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution_Gads;

/**
 * HTTP client for Google Ads click conversion uploads.
 *
 * Uses `wp_remote_post()` for all HTTP communication. OAuth2 access tokens
 * are cached in a WordPress transient to avoid unnecessary refreshes.
 *
 * @since 0.3.0
 */
final class Google_Ads_Client {

	/**
	 * Google OAuth2 token endpoint.
	 *
	 * @var string
	 * @since 0.3.0
	 */
	private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

	/**
	 * Google Ads REST API base URL.
	 *
	 * @var string
	 * @since 0.3.0
	 */
	private const API_BASE_URL = 'https://googleads.googleapis.com/v19';

	/**
	 * Transient key for cached access token.
	 *
	 * @var string
	 * @since 0.3.0
	 */
	private const TOKEN_TRANSIENT = 'kntnt_ad_attr_gads_access_token';

	/**
	 * Safety margin subtracted from token TTL to avoid edge-case expiry.
	 *
	 * @var int
	 * @since 0.3.0
	 */
	private const TOKEN_TTL_MARGIN = 300;

	/**
	 * Creates a new API client with the given credentials.
	 *
	 * @param string $customer_id      Google Ads customer ID (digits only).
	 * @param string $developer_token  Google Ads API developer token.
	 * @param string $client_id        OAuth2 client ID.
	 * @param string $client_secret    OAuth2 client secret.
	 * @param string $refresh_token    OAuth2 refresh token.
	 * @param string $login_customer_id Optional MCC login customer ID.
	 *
	 * @since 0.3.0
	 */
	public function __construct(
		private readonly string $customer_id,
		private readonly string $developer_token,
		private readonly string $client_id,
		private readonly string $client_secret,
		private readonly string $refresh_token,
		private readonly string $login_customer_id = '',
	) {}

	/**
	 * Tests the connection by forcing a fresh OAuth2 token refresh.
	 *
	 * Bypasses the transient cache to verify that the current credentials
	 * can successfully obtain an access token from Google.
	 *
	 * @return array{success: bool, error: string} Result with success flag and error message.
	 * @since 0.4.0
	 */
	public function test_connection(): array {
		$token = $this->refresh_access_token();

		if ( $token === null ) {
			return [ 'success' => false, 'error' => 'Failed to obtain access token.' ];
		}

		return [ 'success' => true, 'error' => '' ];
	}

	/**
	 * Uploads a single click conversion to Google Ads.
	 *
	 * @param string $gclid               Google click identifier.
	 * @param string $conversion_action    Full resource name (customers/{id}/conversionActions/{id}).
	 * @param string $conversion_datetime  Conversion timestamp in "Y-m-d H:i:sP" format.
	 * @param float  $conversion_value     Monetary value of the conversion.
	 * @param string $currency_code        ISO 4217 currency code.
	 *
	 * @return array{success: bool, error: string} Result with success flag and error message.
	 * @since 0.3.0
	 */
	public function upload_click_conversion(
		string $gclid,
		string $conversion_action,
		string $conversion_datetime,
		float $conversion_value,
		string $currency_code,
	): array {

		// Obtain a valid access token.
		$access_token = $this->get_access_token();
		if ( $access_token === null ) {
			return [ 'success' => false, 'error' => 'Failed to obtain access token.' ];
		}

		// Build the conversion payload.
		$body = [
			'conversions' => [
				[
					'gclid'              => $gclid,
					'conversionAction'   => $conversion_action,
					'conversionDateTime' => $conversion_datetime,
					'conversionValue'    => $conversion_value,
					'currencyCode'       => $currency_code,
				],
			],
			'partialFailure' => true,
		];

		// Send the upload request.
		$url      = self::API_BASE_URL . "/customers/{$this->customer_id}:uploadClickConversions";
		$response = wp_remote_post( $url, [
			'headers' => $this->build_api_headers( $access_token ),
			'body'    => wp_json_encode( $body ),
			'timeout' => 30,
		] );

		// Handle WP_Error (network failure, timeout, etc.).
		if ( is_wp_error( $response ) ) {
			return [ 'success' => false, 'error' => $response->get_error_message() ];
		}

		// Check HTTP status.
		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code !== 200 ) {
			$response_body = wp_remote_retrieve_body( $response );
			return [ 'success' => false, 'error' => "HTTP {$status_code}: {$response_body}" ];
		}

		// Check for partial failure errors in the response body.
		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $response_body['partialFailureError'] ) ) {
			$error_message = $response_body['partialFailureError']['message'] ?? 'Partial failure error';
			return [ 'success' => false, 'error' => $error_message ];
		}

		return [ 'success' => true, 'error' => '' ];
	}

	/**
	 * Gets a valid access token, using cache when available.
	 *
	 * @return string|null Access token or null on failure.
	 * @since 0.3.0
	 */
	private function get_access_token(): ?string {

		// Return cached token if available.
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( $cached !== false ) {
			return $cached;
		}

		// Refresh and cache a new token.
		return $this->refresh_access_token();
	}

	/**
	 * Refreshes the OAuth2 access token using the refresh token.
	 *
	 * @return string|null New access token or null on failure.
	 * @since 0.3.0
	 */
	private function refresh_access_token(): ?string {

		// Request a new access token from Google.
		$response = wp_remote_post( self::TOKEN_URL, [
			'body' => [
				'grant_type'    => 'refresh_token',
				'client_id'     => $this->client_id,
				'client_secret' => $this->client_secret,
				'refresh_token' => $this->refresh_token,
			],
		] );

		// Handle WP_Error.
		if ( is_wp_error( $response ) ) {
			return null;
		}

		// Parse the token response.
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) || empty( $body['expires_in'] ) ) {
			return null;
		}

		// Cache the token with a safety margin.
		$ttl = max( 0, (int) $body['expires_in'] - self::TOKEN_TTL_MARGIN );
		set_transient( self::TOKEN_TRANSIENT, $body['access_token'], $ttl );

		return $body['access_token'];
	}

	/**
	 * Builds HTTP headers for Google Ads API requests.
	 *
	 * @param string $access_token Valid OAuth2 access token.
	 *
	 * @return array<string,string> HTTP headers.
	 * @since 0.3.0
	 */
	private function build_api_headers( string $access_token ): array {
		$headers = [
			'Authorization'  => "Bearer {$access_token}",
			'Content-Type'   => 'application/json',
			'developer-token' => $this->developer_token,
		];

		// Include login-customer-id only when set (MCC accounts).
		if ( $this->login_customer_id !== '' ) {
			$headers['login-customer-id'] = $this->login_customer_id;
		}

		return $headers;
	}

}
