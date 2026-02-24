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
function make_client(string $login_customer_id = ''): Google_Ads_Client {
    return new Google_Ads_Client(
        customer_id: '1234567890',
        developer_token: 'dev_token',
        client_id: 'client.apps.googleusercontent.com',
        client_secret: 'secret',
        refresh_token: 'refresh_abc',
        login_customer_id: $login_customer_id,
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

        expect($result)->toBe(['success' => true, 'error' => '']);
    });

    it('fails when token refresh fails', function () {
        stub_response_helpers();

        // Token refresh returns error.
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
        expect($result['error'])->toBe('Failed to obtain access token.');
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

        expect($result)->toBe(['success' => true, 'error' => '']);
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

        expect($result)->toBe(['success' => true, 'error' => '']);
    });

    it('returns failure when token refresh fails', function () {

        // No cached token.
        Functions\expect('get_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_access_token')
            ->andReturn(false);

        stub_response_helpers();

        // Token refresh returns error response.
        Functions\expect('wp_remote_post')
            ->once()
            ->andReturn([
                'response' => ['code' => 200],
                'body'     => json_encode(['error' => 'invalid_grant']),
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
        expect($result['error'])->toBe('Failed to obtain access token.');
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
