<?php
/**
 * Unit tests for Google_Ads_Client.
 *
 * @package Tests\Unit
 * @since   0.3.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution_Gads\Google_Ads_Client;
use Brain\Monkey\Functions;

/**
 * Creates a client instance with sensible test defaults.
 */
function make_client(string $login_customer_id = '', string $conversion_action_id = ''): Google_Ads_Client {
    return new Google_Ads_Client(
        customer_id: '1234567890',
        developer_token: 'dev_token',
        client_id: 'client.apps.googleusercontent.com',
        client_secret: 'secret',
        refresh_token: 'refresh_abc',
        login_customer_id: $login_customer_id,
        conversion_action_id: $conversion_action_id,
    );
}

/**
 * Stubs wp_json_encode to delegate to json_encode.
 */
function stub_wp_json_encode(): void {
    if (!function_exists('wp_json_encode')) {
        Functions\when('wp_json_encode')->alias(fn ($data) => json_encode($data));
    }
}

/**
 * Stubs wp_remote_retrieve_response_code and wp_remote_retrieve_body.
 */
function stub_response_helpers(): void {
    Functions\when('wp_remote_retrieve_response_code')->alias(
        fn ($response) => $response['response']['code'] ?? 0,
    );
    Functions\when('wp_remote_retrieve_body')->alias(
        fn ($response) => $response['body'] ?? '',
    );
}

// ─── test_connection() ───

describe('Google_Ads_Client::test_connection()', function () {

    it('succeeds when token refresh succeeds', function () {
        stub_response_helpers();

        // Token refresh request succeeds.
        Functions\expect('wp_remote_post')
            ->once()
            ->andReturn([
                'response' => ['code' => 200],
                'body'     => json_encode([
                    'access_token' => 'fresh_token',
                    'expires_in'   => 3600,
                ]),
            ]);

        Functions\expect('is_wp_error')->once()->andReturn(false);

        Functions\expect('set_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_access_token', 'fresh_token', 3300)
            ->andReturn(true);

        $client = make_client();
        $result = $client->test_connection();

        expect($result)->toBe(['success' => true, 'error' => '', 'credential_error' => false, 'debug' => '', 'conversion_action_name' => '']);
    });

    it('sends correct OAuth2 parameters in token refresh request', function () {
        stub_response_helpers();

        // Capture the token refresh request to verify OAuth2 parameters.
        $captured_url  = null;
        $captured_body = null;
        Functions\expect('wp_remote_post')
            ->once()
            ->withArgs(function (string $url, array $args) use (&$captured_url, &$captured_body) {
                $captured_url  = $url;
                $captured_body = $args['body'];
                return true;
            })
            ->andReturn([
                'response' => ['code' => 200],
                'body'     => json_encode([
                    'access_token' => 'fresh_token',
                    'expires_in'   => 3600,
                ]),
            ]);

        Functions\expect('is_wp_error')->once()->andReturn(false);
        Functions\when('set_transient')->justReturn(true);

        $client = make_client();
        $client->test_connection();

        // Verify the token endpoint URL.
        expect($captured_url)->toBe('https://oauth2.googleapis.com/token');

        // Verify all required OAuth2 parameters.
        expect($captured_body['grant_type'])->toBe('refresh_token');
        expect($captured_body['client_id'])->toBe('client.apps.googleusercontent.com');
        expect($captured_body['client_secret'])->toBe('secret');
        expect($captured_body['refresh_token'])->toBe('refresh_abc');
    });

    it('clamps token TTL to zero when expires_in is below safety margin', function () {
        stub_response_helpers();

        // Token response with expires_in smaller than the 300s safety margin.
        Functions\expect('wp_remote_post')
            ->once()
            ->andReturn([
                'response' => ['code' => 200],
                'body'     => json_encode([
                    'access_token' => 'short_lived_token',
                    'expires_in'   => 100,
                ]),
            ]);

        Functions\expect('is_wp_error')->once()->andReturn(false);

        // TTL should be max(0, 100 - 300) = 0, not negative.
        Functions\expect('set_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_access_token', 'short_lived_token', 0)
            ->andReturn(true);

        $client = make_client();
        $result = $client->test_connection();

        expect($result['success'])->toBeTrue();
    });

    it('fails when token refresh fails', function () {
        stub_response_helpers();

        // Token refresh returns error with description.
        Functions\expect('wp_remote_post')
            ->once()
            ->andReturn([
                'response' => ['code' => 200],
                'body'     => json_encode([
                    'error'             => 'invalid_grant',
                    'error_description' => 'Token has been expired or revoked.',
                ]),
            ]);

        Functions\expect('is_wp_error')->once()->andReturn(false);

        $client = make_client();
        $result = $client->test_connection();

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toBe('Token has been expired or revoked.');
    });

    it('falls back to error code when error_description is absent', function () {
        stub_response_helpers();

        // Token refresh returns error without description.
        Functions\expect('wp_remote_post')
            ->once()
            ->andReturn([
                'response' => ['code' => 200],
                'body'     => json_encode(['error' => 'invalid_grant']),
            ]);

        Functions\expect('is_wp_error')->once()->andReturn(false);

        $client = make_client();
        $result = $client->test_connection();

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toBe('invalid_grant');
    });

    it('surfaces WP_Error message on network failure', function () {
        // Build a mock WP_Error for the token refresh request.
        $wp_error = Mockery::mock('WP_Error');
        $wp_error->shouldReceive('get_error_message')
            ->once()
            ->andReturn('cURL error 28: Connection timed out');

        Functions\expect('wp_remote_post')
            ->once()
            ->andReturn($wp_error);

        Functions\expect('is_wp_error')->once()->andReturn(true);

        $client = make_client();
        $result = $client->test_connection();

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toBe('cURL error 28: Connection timed out');
    });

});

// ─── test_connection() — phase 2: Google Ads API verification ───

describe('Google_Ads_Client::test_connection() phase 2', function () {

    /**
     * Stubs a successful token refresh as the first wp_remote_post call.
     */
    function stub_successful_token_refresh(): void {
        Functions\expect('set_transient')
            ->once()
            ->andReturn(true);
    }

    it('succeeds when token refresh and GAQL query both succeed', function () {
        stub_response_helpers();
        stub_wp_json_encode();
        stub_successful_token_refresh();

        // First call: token refresh. Second call: GAQL search.
        Functions\expect('wp_remote_post')
            ->twice()
            ->andReturnUsing(function (string $url) {
                if (str_contains($url, 'oauth2.googleapis.com/token')) {
                    return [
                        'response' => ['code' => 200],
                        'body'     => json_encode([
                            'access_token' => 'fresh_token',
                            'expires_in'   => 3600,
                        ]),
                    ];
                }
                return [
                    'response' => ['code' => 200],
                    'body'     => json_encode([
                        'results' => [
                            ['conversionAction' => ['id' => '99', 'name' => 'Offline Lead']],
                        ],
                    ]),
                ];
            });

        Functions\expect('is_wp_error')->twice()->andReturn(false);

        $client = make_client(conversion_action_id: '99');
        $result = $client->test_connection();

        expect($result['success'])->toBeTrue();
        expect($result['conversion_action_name'])->toBe('Offline Lead');
    });

    it('sends correct GAQL request URL, headers, and body', function () {
        stub_response_helpers();
        stub_wp_json_encode();
        stub_successful_token_refresh();

        $captured_url     = null;
        $captured_headers = null;
        $captured_body    = null;

        Functions\expect('wp_remote_post')
            ->twice()
            ->andReturnUsing(function (string $url, array $args) use (&$captured_url, &$captured_headers, &$captured_body) {
                if (str_contains($url, 'oauth2.googleapis.com/token')) {
                    return [
                        'response' => ['code' => 200],
                        'body'     => json_encode([
                            'access_token' => 'fresh_token',
                            'expires_in'   => 3600,
                        ]),
                    ];
                }

                // Capture GAQL request details.
                $captured_url     = $url;
                $captured_headers = $args['headers'];
                $captured_body    = json_decode($args['body'], true);

                return [
                    'response' => ['code' => 200],
                    'body'     => json_encode([
                        'results' => [
                            ['conversionAction' => ['id' => '99', 'name' => 'Offline Lead']],
                        ],
                    ]),
                ];
            });

        Functions\expect('is_wp_error')->twice()->andReturn(false);

        $client = make_client(login_customer_id: '5555555555', conversion_action_id: '99');
        $client->test_connection();

        // Verify GAQL endpoint URL.
        expect($captured_url)->toBe('https://googleads.googleapis.com/v23/customers/1234567890/googleAds:search');

        // Verify headers.
        expect($captured_headers['Authorization'])->toBe('Bearer fresh_token');
        expect($captured_headers['developer-token'])->toBe('dev_token');
        expect($captured_headers['login-customer-id'])->toBe('5555555555');
        expect($captured_headers['Content-Type'])->toBe('application/json');

        // Verify GAQL query in body.
        expect($captured_body['query'])->toContain('conversion_action.id = 99');
    });

    it('returns credential error on HTTP 403 from Google Ads API', function () {
        stub_response_helpers();
        stub_wp_json_encode();
        stub_successful_token_refresh();

        Functions\expect('wp_remote_post')
            ->twice()
            ->andReturnUsing(function (string $url) {
                if (str_contains($url, 'oauth2.googleapis.com/token')) {
                    return [
                        'response' => ['code' => 200],
                        'body'     => json_encode([
                            'access_token' => 'fresh_token',
                            'expires_in'   => 3600,
                        ]),
                    ];
                }
                return [
                    'response' => ['code' => 403],
                    'body'     => json_encode([
                        'error' => ['message' => 'The developer token is not approved.'],
                    ]),
                ];
            });

        Functions\expect('is_wp_error')->twice()->andReturn(false);

        $client = make_client(conversion_action_id: '99');
        $result = $client->test_connection();

        expect($result['success'])->toBeFalse();
        expect($result['credential_error'])->toBeTrue();
        expect($result['error'])->toContain('developer token');
    });

    it('returns credential error when conversion action is not found', function () {
        stub_response_helpers();
        stub_wp_json_encode();
        stub_successful_token_refresh();

        Functions\expect('wp_remote_post')
            ->twice()
            ->andReturnUsing(function (string $url) {
                if (str_contains($url, 'oauth2.googleapis.com/token')) {
                    return [
                        'response' => ['code' => 200],
                        'body'     => json_encode([
                            'access_token' => 'fresh_token',
                            'expires_in'   => 3600,
                        ]),
                    ];
                }

                // HTTP 200 but empty results — conversion action not found.
                return [
                    'response' => ['code' => 200],
                    'body'     => json_encode(['results' => []]),
                ];
            });

        Functions\expect('is_wp_error')->twice()->andReturn(false);

        $client = make_client(conversion_action_id: '999999');
        $result = $client->test_connection();

        expect($result['success'])->toBeFalse();
        expect($result['credential_error'])->toBeTrue();
        expect($result['error'])->toContain('999999');
        expect($result['error'])->toContain('1234567890');
    });

    it('returns non-credential error on WP_Error during GAQL query', function () {
        stub_response_helpers();
        stub_wp_json_encode();
        stub_successful_token_refresh();

        $wp_error = Mockery::mock('WP_Error');
        $wp_error->shouldReceive('get_error_message')
            ->once()
            ->andReturn('cURL error 28: Connection timed out');

        $call_count = 0;
        Functions\expect('wp_remote_post')
            ->twice()
            ->andReturnUsing(function (string $url) use ($wp_error, &$call_count) {
                $call_count++;
                if ($call_count === 1) {
                    return [
                        'response' => ['code' => 200],
                        'body'     => json_encode([
                            'access_token' => 'fresh_token',
                            'expires_in'   => 3600,
                        ]),
                    ];
                }
                return $wp_error;
            });

        Functions\expect('is_wp_error')
            ->twice()
            ->andReturnUsing(fn ($response) => $response === $wp_error);

        $client = make_client(conversion_action_id: '99');
        $result = $client->test_connection();

        expect($result['success'])->toBeFalse();
        expect($result['credential_error'])->toBeFalse();
        expect($result['error'])->toBe('cURL error 28: Connection timed out');
    });

    it('omits login-customer-id header when not set', function () {
        stub_response_helpers();
        stub_wp_json_encode();
        stub_successful_token_refresh();

        $gaql_headers = null;
        Functions\expect('wp_remote_post')
            ->twice()
            ->andReturnUsing(function (string $url, array $args) use (&$gaql_headers) {
                if (str_contains($url, 'oauth2.googleapis.com/token')) {
                    return [
                        'response' => ['code' => 200],
                        'body'     => json_encode([
                            'access_token' => 'fresh_token',
                            'expires_in'   => 3600,
                        ]),
                    ];
                }
                $gaql_headers = $args['headers'];
                return [
                    'response' => ['code' => 200],
                    'body'     => json_encode([
                        'results' => [
                            ['conversionAction' => ['id' => '99', 'name' => 'Lead']],
                        ],
                    ]),
                ];
            });

        Functions\expect('is_wp_error')->twice()->andReturn(false);

        $client = make_client(conversion_action_id: '99');
        $client->test_connection();

        expect($gaql_headers)->not->toHaveKey('login-customer-id');
    });

    it('skips phase 2 when conversion_action_id is empty', function () {
        stub_response_helpers();

        // Only one wp_remote_post call — no GAQL request.
        Functions\expect('wp_remote_post')
            ->once()
            ->andReturn([
                'response' => ['code' => 200],
                'body'     => json_encode([
                    'access_token' => 'fresh_token',
                    'expires_in'   => 3600,
                ]),
            ]);

        Functions\expect('is_wp_error')->once()->andReturn(false);
        Functions\expect('set_transient')->once()->andReturn(true);

        $client = make_client();
        $result = $client->test_connection();

        expect($result['success'])->toBeTrue();
        expect($result['conversion_action_name'])->toBe('');
    });

});

// ─── upload_click_conversion() — success with cached token ───

describe('Google_Ads_Client::upload_click_conversion()', function () {

    it('succeeds when access token is cached', function () {

        // Cached token available — no refresh needed.
        Functions\expect('get_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_access_token')
            ->andReturn('cached_token_123');

        stub_wp_json_encode();
        stub_response_helpers();

        // Expect the upload request (only one wp_remote_post call).
        Functions\expect('wp_remote_post')
            ->once()
            ->withArgs(function (string $url, array $args) {
                return str_contains($url, 'customers/1234567890:uploadClickConversions')
                    && $args['headers']['Authorization'] === 'Bearer cached_token_123';
            })
            ->andReturn([
                'response' => ['code' => 200],
                'body'     => json_encode(['results' => [[]]]),
            ]);

        Functions\expect('is_wp_error')->once()->andReturn(false);

        $client = make_client();
        $result = $client->upload_click_conversion(
            'test_gclid',
            'customers/1234567890/conversionActions/99',
            '2026-01-15 10:30:00+01:00',
            100.0,
            'SEK',
        );

        expect($result)->toBe(['success' => true, 'error' => '', 'credential_error' => false]);
    });

    it('succeeds with token refresh when cache is empty', function () {

        // No cached token.
        Functions\expect('get_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_access_token')
            ->andReturn(false);

        stub_wp_json_encode();
        stub_response_helpers();

        // First wp_remote_post: token refresh.
        // Second wp_remote_post: conversion upload.
        Functions\expect('wp_remote_post')
            ->twice()
            ->andReturnUsing(function (string $url) {
                if (str_contains($url, 'oauth2.googleapis.com/token')) {
                    return [
                        'response' => ['code' => 200],
                        'body'     => json_encode([
                            'access_token' => 'fresh_token',
                            'expires_in'   => 3600,
                        ]),
                    ];
                }
                return [
                    'response' => ['code' => 200],
                    'body'     => json_encode(['results' => [[]]]),
                ];
            });

        Functions\expect('is_wp_error')->twice()->andReturn(false);

        // Verify token is cached with correct TTL (3600 - 300 = 3300).
        Functions\expect('set_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_access_token', 'fresh_token', 3300)
            ->andReturn(true);

        $client = make_client();
        $result = $client->upload_click_conversion(
            'test_gclid',
            'customers/1234567890/conversionActions/99',
            '2026-01-15 10:30:00+01:00',
            100.0,
            'SEK',
        );

        expect($result)->toBe(['success' => true, 'error' => '', 'credential_error' => false]);
    });

    it('sends correctly structured conversion payload in upload request', function () {

        // Cached token available — skip refresh.
        Functions\expect('get_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_access_token')
            ->andReturn('cached_token');

        stub_wp_json_encode();
        stub_response_helpers();

        // Capture the upload request body.
        $captured_body = null;
        Functions\expect('wp_remote_post')
            ->once()
            ->withArgs(function (string $url, array $args) use (&$captured_body) {
                $captured_body = json_decode($args['body'], true);
                return true;
            })
            ->andReturn([
                'response' => ['code' => 200],
                'body'     => json_encode(['results' => [[]]]),
            ]);

        Functions\expect('is_wp_error')->once()->andReturn(false);

        $client = make_client();
        $client->upload_click_conversion(
            'gclid_xyz',
            'customers/1234567890/conversionActions/99',
            '2026-03-15 14:30:00+01:00',
            250.0,
            'SEK',
        );

        // Verify the conversion payload structure matches Google Ads API spec.
        expect($captured_body['partialFailure'])->toBeTrue();
        expect($captured_body['conversions'])->toHaveCount(1);

        $conversion = $captured_body['conversions'][0];
        expect($conversion['gclid'])->toBe('gclid_xyz');
        expect($conversion['conversionAction'])->toBe('customers/1234567890/conversionActions/99');
        expect($conversion['conversionDateTime'])->toBe('2026-03-15 14:30:00+01:00');
        expect((float) $conversion['conversionValue'])->toBe(250.0);
        expect($conversion['currencyCode'])->toBe('SEK');
    });

    it('returns failure when token refresh fails', function () {

        // No cached token.
        Functions\expect('get_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_access_token')
            ->andReturn(false);

        stub_response_helpers();

        // Token refresh returns error response with description.
        Functions\expect('wp_remote_post')
            ->once()
            ->andReturn([
                'response' => ['code' => 200],
                'body'     => json_encode([
                    'error'             => 'invalid_grant',
                    'error_description' => 'Token has been expired or revoked.',
                ]),
            ]);

        Functions\expect('is_wp_error')->once()->andReturn(false);

        $client = make_client();
        $result = $client->upload_click_conversion(
            'test_gclid',
            'customers/1234567890/conversionActions/99',
            '2026-01-15 10:30:00+01:00',
            100.0,
            'SEK',
        );

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toBe('Token has been expired or revoked.');
    });

    it('returns failure on partial failure error', function () {

        // Cached token available.
        Functions\expect('get_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_access_token')
            ->andReturn('cached_token');

        stub_wp_json_encode();
        stub_response_helpers();

        Functions\expect('wp_remote_post')
            ->once()
            ->andReturn([
                'response' => ['code' => 200],
                'body'     => json_encode([
                    'partialFailureError' => [
                        'code'    => 3,
                        'message' => 'Too recent conversion action.',
                    ],
                ]),
            ]);

        Functions\expect('is_wp_error')->once()->andReturn(false);

        $client = make_client();
        $result = $client->upload_click_conversion(
            'test_gclid',
            'customers/1234567890/conversionActions/99',
            '2026-01-15 10:30:00+01:00',
            100.0,
            'SEK',
        );

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toBe('Too recent conversion action.');
    });

    it('returns failure on non-200 HTTP status', function () {

        // Cached token available.
        Functions\expect('get_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_access_token')
            ->andReturn('cached_token');

        stub_wp_json_encode();
        stub_response_helpers();

        Functions\expect('wp_remote_post')
            ->once()
            ->andReturn([
                'response' => ['code' => 403],
                'body'     => 'Forbidden',
            ]);

        Functions\expect('is_wp_error')->once()->andReturn(false);

        $client = make_client();
        $result = $client->upload_click_conversion(
            'test_gclid',
            'customers/1234567890/conversionActions/99',
            '2026-01-15 10:30:00+01:00',
            100.0,
            'SEK',
        );

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toBe('HTTP 403: Forbidden');
    });

    it('returns failure on WP_Error', function () {

        // Cached token available.
        Functions\expect('get_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_access_token')
            ->andReturn('cached_token');

        stub_wp_json_encode();

        // Build a mock WP_Error.
        $wp_error = Mockery::mock('WP_Error');
        $wp_error->shouldReceive('get_error_message')
            ->once()
            ->andReturn('Connection timed out');

        Functions\expect('wp_remote_post')
            ->once()
            ->andReturn($wp_error);

        Functions\expect('is_wp_error')->once()->andReturn(true);

        $client = make_client();
        $result = $client->upload_click_conversion(
            'test_gclid',
            'customers/1234567890/conversionActions/99',
            '2026-01-15 10:30:00+01:00',
            100.0,
            'SEK',
        );

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toBe('Connection timed out');
    });

    it('includes login-customer-id header when set', function () {

        Functions\expect('get_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_access_token')
            ->andReturn('cached_token');

        stub_wp_json_encode();
        stub_response_helpers();

        Functions\expect('wp_remote_post')
            ->once()
            ->withArgs(function (string $url, array $args) {
                return isset($args['headers']['login-customer-id'])
                    && $args['headers']['login-customer-id'] === '9876543210';
            })
            ->andReturn([
                'response' => ['code' => 200],
                'body'     => json_encode(['results' => [[]]]),
            ]);

        Functions\expect('is_wp_error')->once()->andReturn(false);

        $client = make_client(login_customer_id: '9876543210');
        $result = $client->upload_click_conversion(
            'test_gclid',
            'customers/1234567890/conversionActions/99',
            '2026-01-15 10:30:00+01:00',
            100.0,
            'SEK',
        );

        expect($result['success'])->toBeTrue();
    });

    it('returns failure when token refresh returns empty body', function () {

        // No cached token.
        Functions\expect('get_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_access_token')
            ->andReturn(false);

        stub_response_helpers();

        // Token refresh returns 200 but with an empty body.
        Functions\expect('wp_remote_post')
            ->once()
            ->andReturn([
                'response' => ['code' => 200],
                'body'     => '',
            ]);

        Functions\expect('is_wp_error')->once()->andReturn(false);

        $client = make_client();
        $result = $client->upload_click_conversion(
            'test_gclid',
            'customers/1234567890/conversionActions/99',
            '2026-01-15 10:30:00+01:00',
            100.0,
            'SEK',
        );

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toBe('Unexpected token response.');
    });

    it('returns failure when token refresh returns non-JSON body', function () {

        // No cached token.
        Functions\expect('get_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_access_token')
            ->andReturn(false);

        stub_response_helpers();

        // Token refresh returns 200 but body is not valid JSON.
        Functions\expect('wp_remote_post')
            ->once()
            ->andReturn([
                'response' => ['code' => 200],
                'body'     => 'not json',
            ]);

        Functions\expect('is_wp_error')->once()->andReturn(false);

        $client = make_client();
        $result = $client->upload_click_conversion(
            'test_gclid',
            'customers/1234567890/conversionActions/99',
            '2026-01-15 10:30:00+01:00',
            100.0,
            'SEK',
        );

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toBe('Unexpected token response.');
    });

    it('returns failure when token response lacks expires_in', function () {

        // No cached token.
        Functions\expect('get_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_access_token')
            ->andReturn(false);

        stub_response_helpers();

        // Token response has access_token but no expires_in.
        Functions\expect('wp_remote_post')
            ->once()
            ->andReturn([
                'response' => ['code' => 200],
                'body'     => json_encode(['access_token' => 'x']),
            ]);

        Functions\expect('is_wp_error')->once()->andReturn(false);

        $client = make_client();
        $result = $client->upload_click_conversion(
            'test_gclid',
            'customers/1234567890/conversionActions/99',
            '2026-01-15 10:30:00+01:00',
            100.0,
            'SEK',
        );

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toBe('Unexpected token response.');
    });

    it('returns failure when token response lacks access_token', function () {

        // No cached token.
        Functions\expect('get_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_access_token')
            ->andReturn(false);

        stub_response_helpers();

        // Token response has expires_in but no access_token.
        Functions\expect('wp_remote_post')
            ->once()
            ->andReturn([
                'response' => ['code' => 200],
                'body'     => json_encode(['expires_in' => 3600]),
            ]);

        Functions\expect('is_wp_error')->once()->andReturn(false);

        $client = make_client();
        $result = $client->upload_click_conversion(
            'test_gclid',
            'customers/1234567890/conversionActions/99',
            '2026-01-15 10:30:00+01:00',
            100.0,
            'SEK',
        );

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toBe('Unexpected token response.');
    });

    it('returns fallback message when partialFailureError lacks message key', function () {

        // Cached token available.
        Functions\expect('get_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_access_token')
            ->andReturn('cached_token');

        stub_wp_json_encode();
        stub_response_helpers();

        // Response has partialFailureError with code but no message.
        Functions\expect('wp_remote_post')
            ->once()
            ->andReturn([
                'response' => ['code' => 200],
                'body'     => json_encode([
                    'partialFailureError' => ['code' => 3],
                ]),
            ]);

        Functions\expect('is_wp_error')->once()->andReturn(false);

        $client = make_client();
        $result = $client->upload_click_conversion(
            'test_gclid',
            'customers/1234567890/conversionActions/99',
            '2026-01-15 10:30:00+01:00',
            100.0,
            'SEK',
        );

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toBe('Partial failure error');
    });

    it('returns failure when token refresh gets WP_Error', function () {

        // No cached token.
        Functions\expect('get_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_access_token')
            ->andReturn(false);

        // Build a WP_Error for the token refresh request itself.
        $wp_error = Mockery::mock('WP_Error');
        $wp_error->shouldReceive('get_error_message')
            ->once()
            ->andReturn('cURL error 6: Could not resolve host');

        Functions\expect('wp_remote_post')
            ->once()
            ->andReturn($wp_error);

        Functions\expect('is_wp_error')->once()->andReturn(true);

        $client = make_client();
        $result = $client->upload_click_conversion(
            'test_gclid',
            'customers/1234567890/conversionActions/99',
            '2026-01-15 10:30:00+01:00',
            100.0,
            'SEK',
        );

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toBe('cURL error 6: Could not resolve host');
    });

    it('omits login-customer-id header when empty', function () {

        Functions\expect('get_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_access_token')
            ->andReturn('cached_token');

        stub_wp_json_encode();
        stub_response_helpers();

        Functions\expect('wp_remote_post')
            ->once()
            ->withArgs(function (string $url, array $args) {
                return ! isset($args['headers']['login-customer-id']);
            })
            ->andReturn([
                'response' => ['code' => 200],
                'body'     => json_encode(['results' => [[]]]),
            ]);

        Functions\expect('is_wp_error')->once()->andReturn(false);

        $client = make_client();
        $result = $client->upload_click_conversion(
            'test_gclid',
            'customers/1234567890/conversionActions/99',
            '2026-01-15 10:30:00+01:00',
            100.0,
            'SEK',
        );

        expect($result['success'])->toBeTrue();
    });

});
