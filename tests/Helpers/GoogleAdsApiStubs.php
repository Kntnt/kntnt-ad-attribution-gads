<?php
/**
 * Stubs for the WordPress HTTP functions a Google Ads API call goes through.
 *
 * Shared by the client tests and the reporter tests, which upload through
 * the real client. Pest includes this file at boot, before Patchwork; that
 * is fine for plain functions nobody redefines.
 *
 * @package Tests\Helpers
 * @since   1.11.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;

/**
 * Stubs wp_json_encode to delegate to json_encode.
 *
 * @return void
 */
function stub_wp_json_encode(): void {
    if (!function_exists('wp_json_encode')) {
        Functions\when('wp_json_encode')->alias(fn ($data) => json_encode($data));
    }
}

/**
 * Stubs wp_remote_retrieve_response_code and wp_remote_retrieve_body.
 *
 * @return void
 */
function stub_response_helpers(): void {
    Functions\when('wp_remote_retrieve_response_code')->alias(
        fn ($response) => $response['response']['code'] ?? 0,
    );
    Functions\when('wp_remote_retrieve_body')->alias(
        fn ($response) => $response['body'] ?? '',
    );
}

/**
 * Stubs everything an API call needs except wp_remote_post, with a cached
 * access token so that no token refresh is requested.
 *
 * @return void
 */
function stub_api_with_cached_token(): void {
    Functions\when('get_transient')->justReturn('cached_token');
    Functions\when('is_wp_error')->justReturn(false);
    stub_wp_json_encode();
    stub_response_helpers();
}

/**
 * Runs an upload against an API stub that accepts it, and returns the one
 * conversion the upload request carried.
 *
 * @param callable(): mixed $upload Makes exactly one upload request.
 *
 * @return array<string,mixed> The uploaded ClickConversion.
 */
function conversion_uploaded_by(callable $upload): array {
    stub_api_with_cached_token();

    $body = null;
    Functions\expect('wp_remote_post')
        ->once()
        ->andReturnUsing(function (string $url, array $args) use (&$body) {
            $body = json_decode($args['body'], true);
            return [
                'response' => ['code' => 200],
                'body'     => json_encode(['results' => [[]]]),
            ];
        });

    $upload();

    expect($body['conversions'])->toHaveCount(1);

    return $body['conversions'][0];
}
