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
	private const API_BASE_URL = 'https://googleads.googleapis.com/v23';

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
	 * @param string $customer_id        Google Ads customer ID (digits only).
	 * @param string $developer_token    Google Ads API developer token.
	 * @param string $client_id          OAuth2 client ID.
	 * @param string $client_secret      OAuth2 client secret.
	 * @param string $refresh_token      OAuth2 refresh token.
	 * @param string $login_customer_id  Optional MCC login customer ID.
	 * @param string $conversion_action_id Optional conversion action ID for test_connection() phase 2.
	 *
	 * @since 0.3.0
	 */
	/**
	 * Last error message from a failed token refresh.
	 *
	 * Populated by `refresh_access_token()` so callers can surface
	 * the specific reason for failure instead of a generic message.
	 *
	 * @var string
	 * @since 1.1.2
	 */
	private string $last_refresh_error = '';

	/**
	 * Raw Google response from the last failed token refresh.
	 *
	 * Includes HTTP status code and response body for diagnostics.
	 *
	 * @var string
	 * @since 1.2.0
	 */
	private string $last_refresh_debug = '';

	public function __construct(
		private readonly string $customer_id,
		private readonly string $developer_token,
		private readonly string $client_id,
		private readonly string $client_secret,
		private readonly string $refresh_token,
		private readonly string $login_customer_id = '',
		private readonly string $conversion_action_id = '',
		private readonly ?Logger $logger = null,
	) {}

	/**
	 * Tests the connection by verifying credentials in two phases.
	 *
	 * Phase 1: Forces a fresh OAuth2 token refresh to verify client_id,
	 * client_secret, and refresh_token.
	 *
	 * Phase 2 (if conversion_action_id is set): Queries the Google Ads API
	 * to verify customer_id, developer_token, login_customer_id, and
	 * conversion_action_id in a single GAQL request.
	 *
	 * @return array{success: bool, error: string, credential_error: bool, debug: string, conversion_action_name: string} Result with success flag, error message, credential error flag, debug info, and conversion action name.
	 * @since 0.4.0
	 */
	public function test_connection(): array {

		// Phase 1: Verify OAuth2 credentials via token refresh.
		$this->logger?->info( 'Test connection — phase 1: verifying OAuth2 credentials' );
		$token = $this->refresh_access_token();

		if ( $token === null ) {
			$this->logger?->error( "Test connection — phase 1 failed: {$this->last_refresh_error}" );
			return [ 'success' => false, 'error' => $this->last_refresh_error ?: 'Failed to obtain access token.', 'credential_error' => true, 'debug' => $this->last_refresh_debug, 'conversion_action_name' => '' ];
		}

		$this->logger?->info( 'Test connection — phase 1 passed: token refresh successful' );

		// Phase 2: Verify Google Ads API credentials if conversion_action_id is set.
		if ( $this->conversion_action_id !== '' ) {
			$this->logger?->info( "Test connection — phase 2: verifying Google Ads API access for conversion action {$this->conversion_action_id}" );
			$result = $this->verify_google_ads_access( $token );
			if ( $result['success'] ) {
				$this->logger?->info( "Test connection — phase 2 passed: conversion action '{$result['conversion_action_name']}'" );
			} else {
				$this->logger?->error( "Test connection — phase 2 failed: {$result['error']}" );
			}
			return $result;
		}

		return [ 'success' => true, 'error' => '', 'credential_error' => false, 'debug' => '', 'conversion_action_name' => '' ];
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
	 * @return array{success: bool, error: string, credential_error: bool} Result with success flag, error message, and credential error flag.
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
		$this->logger?->info( "Uploading conversion — gclid: {$gclid}, action: {$conversion_action}, value: {$conversion_value} {$currency_code}" );
		$access_token = $this->get_access_token();
		if ( $access_token === null ) {
			$this->logger?->error( "Conversion upload failed — gclid: {$gclid}, error: " . ( $this->last_refresh_error ?: 'Failed to obtain access token.' ) );
			return [ 'success' => false, 'error' => $this->last_refresh_error ?: 'Failed to obtain access token.', 'credential_error' => true ];
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
			$this->logger?->error( "Conversion upload failed — gclid: {$gclid}, error: {$response->get_error_message()}" );
			return [ 'success' => false, 'error' => $response->get_error_message(), 'credential_error' => false ];
		}

		// Check HTTP status.
		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code !== 200 ) {
			$response_body = wp_remote_retrieve_body( $response );
			$this->logger?->error( "Conversion upload failed — gclid: {$gclid}, error: HTTP {$status_code}: {$response_body}" );
			return [ 'success' => false, 'error' => "HTTP {$status_code}: {$response_body}", 'credential_error' => false ];
		}

		// Check for partial failure errors in the response body.
		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $response_body['partialFailureError'] ) ) {
			$error_message = $response_body['partialFailureError']['message'] ?? 'Partial failure error';
			$this->logger?->error( "Conversion partial failure — gclid: {$gclid}, error: {$error_message}" );
			return [ 'success' => false, 'error' => $error_message, 'credential_error' => false ];
		}

		$this->logger?->info( "Conversion uploaded — gclid: {$gclid}" );

		return [ 'success' => true, 'error' => '', 'credential_error' => false ];
	}

	/**
	 * Verifies Google Ads API access by querying for the conversion action.
	 *
	 * Sends a GAQL query to validate customer_id, developer_token,
	 * login_customer_id, and conversion_action_id in a single request.
	 *
	 * @param string $access_token Valid OAuth2 access token.
	 *
	 * @return array{success: bool, error: string, credential_error: bool, debug: string, conversion_action_name: string} Result with success flag, error message, credential error flag, debug info, and conversion action name.
	 * @since 1.3.0
	 */
	private function verify_google_ads_access( string $access_token ): array {

		// Query the conversion action by ID to validate all remaining credentials.
		$query = "SELECT conversion_action.id, conversion_action.name FROM conversion_action WHERE conversion_action.id = {$this->conversion_action_id}";
		$url   = self::API_BASE_URL . "/customers/{$this->customer_id}/googleAds:search";

		$response = wp_remote_post( $url, [
			'headers' => $this->build_api_headers( $access_token ),
			'body'    => wp_json_encode( [ 'query' => $query ] ),
			'timeout' => 30,
		] );

		// Handle WP_Error (network failure, timeout, etc.).
		if ( is_wp_error( $response ) ) {
			return [ 'success' => false, 'error' => $response->get_error_message(), 'credential_error' => false, 'debug' => '', 'conversion_action_name' => '' ];
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$body        = json_decode( $raw_body, true );

		// Non-200 means invalid developer_token, customer_id, or login_customer_id.
		if ( $status_code !== 200 ) {
			$error = $body['error']['message'] ?? "HTTP {$status_code}: {$raw_body}";
			return [ 'success' => false, 'error' => $error, 'credential_error' => true, 'debug' => "HTTP {$status_code}: {$raw_body}", 'conversion_action_name' => '' ];
		}

		// HTTP 200 but no results means the conversion action ID doesn't exist.
		if ( empty( $body['results'] ) ) {
			return [
				'success'                => false,
				'error'                  => sprintf( 'Conversion action %s not found in Google Ads account %s.', $this->conversion_action_id, $this->customer_id ),
				'credential_error'       => true,
				'debug'                  => "HTTP 200: {$raw_body}",
				'conversion_action_name' => '',
			];
		}

		// All credentials verified — extract the conversion action name.
		$name = $body['results'][0]['conversionAction']['name'] ?? '';

		return [ 'success' => true, 'error' => '', 'credential_error' => false, 'debug' => '', 'conversion_action_name' => $name ];
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

		$this->last_refresh_error = '';
		$this->last_refresh_debug = '';

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
			$this->last_refresh_error = $response->get_error_message();
			$this->last_refresh_debug = 'WP_Error: ' . $this->last_refresh_error;
			$this->logger?->error( "Token refresh failed — {$this->last_refresh_error}" );
			return null;
		}

		// Parse the token response.
		$raw_body = wp_remote_retrieve_body( $response );
		$http_code = wp_remote_retrieve_response_code( $response );
		$this->last_refresh_debug = "HTTP {$http_code}: {$raw_body}";

		$body = json_decode( $raw_body, true );
		if ( empty( $body['access_token'] ) || empty( $body['expires_in'] ) ) {
			$this->last_refresh_error = $body['error_description'] ?? $body['error'] ?? 'Unexpected token response.';
			$this->logger?->error( "Token refresh failed — {$this->last_refresh_error} (client_id: {$this->client_id}, client_secret: " . Logger::mask( $this->client_secret ) . ', refresh_token: ' . Logger::mask( $this->refresh_token ) . ')' );
			return null;
		}

		// Cache the token with a safety margin.
		$ttl = max( 0, (int) $body['expires_in'] - self::TOKEN_TTL_MARGIN );
		set_transient( self::TOKEN_TRANSIENT, $body['access_token'], $ttl );

		$this->logger?->info( "Token refresh successful — expires_in: {$body['expires_in']}s" );

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
