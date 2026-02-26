<?php
/**
 * Unit tests for Conversion_Reporter.
 *
 * @package Tests\Unit
 * @since   0.3.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution_Gads\Conversion_Reporter;
use Kntnt\Ad_Attribution_Gads\Logger;
use Kntnt\Ad_Attribution_Gads\Settings;
use Brain\Monkey\Functions;

/**
 * Returns a complete settings array for testing.
 *
 * @return array<string,string>
 */
function default_settings(): array {
    return [
        'customer_id'          => '1234567890',
        'conversion_action_id' => '99',
        'developer_token'      => 'dev_token',
        'client_id'            => 'client.apps.googleusercontent.com',
        'client_secret'        => 'secret',
        'refresh_token'        => 'refresh_abc',
        'login_customer_id'    => '',
        'conversion_value'     => '1000',
        'currency_code'        => 'SEK',
    ];
}

/**
 * Returns a settings array with all credential fields empty.
 *
 * @return array<string,string>
 */
function empty_settings(): array {
    return [
        'customer_id'          => '',
        'conversion_action_id' => '',
        'developer_token'      => '',
        'client_id'            => '',
        'client_secret'        => '',
        'refresh_token'        => '',
        'login_customer_id'    => '',
        'conversion_value'     => '0',
        'currency_code'        => 'SEK',
    ];
}

// ─── register() ───

describe('Conversion_Reporter::register()', function () {

    it('always registers regardless of configuration state', function () {
        $settings = Mockery::mock(Settings::class);

        $reporter = new Conversion_Reporter($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());
        $result   = $reporter->register([]);

        expect($result)->toHaveKey('google_ads');
        expect($result['google_ads']['enqueue'])->toBeArray();
        expect($result['google_ads']['process'])->toBeArray();
    });

    it('preserves existing reporters', function () {
        $settings = Mockery::mock(Settings::class);

        $existing = [
            'meta_ads' => [
                'enqueue' => fn () => [],
                'process' => fn () => true,
            ],
        ];

        $reporter = new Conversion_Reporter($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());
        $result   = $reporter->register($existing);

        expect($result)->toHaveKey('meta_ads');
        expect($result)->toHaveKey('google_ads');
    });

});

// ─── enqueue() ───

describe('Conversion_Reporter::enqueue()', function () {

    it('builds payload with raw values for a single attribution', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get_all')->once()->andReturn(default_settings());

        $reporter = new Conversion_Reporter($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());

        $attributions = ['hash_a' => 1.0];
        $click_ids    = ['hash_a' => ['google_ads' => 'gclid_abc']];
        $campaigns    = ['hash_a' => ['source' => 'google']];
        $context      = ['timestamp' => '2026-01-15 10:30:00'];

        $payloads = $reporter->enqueue($attributions, $click_ids, $campaigns, $context);

        expect($payloads)->toHaveCount(1);
        expect($payloads[0]['gclid'])->toBe('gclid_abc');
        expect($payloads[0]['attribution_fraction'])->toBe(1.0);
        expect($payloads[0]['conversion_action_id'])->toBe('99');
        expect($payloads[0]['conversion_value'])->toBe('1000');
        expect($payloads[0]['currency_code'])->toBe('SEK');
        expect($payloads[0]['customer_id'])->toBe('1234567890');
    });

    it('skips attributions without google_ads click ID', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get_all')->once()->andReturn(default_settings());

        $reporter = new Conversion_Reporter($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());

        $attributions = ['hash_a' => 1.0];
        $click_ids    = ['hash_a' => ['meta_ads' => 'fbclid_123']];
        $campaigns    = ['hash_a' => ['source' => 'facebook']];
        $context      = ['timestamp' => '2026-01-15 10:30:00'];

        $payloads = $reporter->enqueue($attributions, $click_ids, $campaigns, $context);

        expect($payloads)->toBeEmpty();
    });

    it('builds correct payloads for multiple attributions', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get_all')->once()->andReturn(default_settings());

        $reporter = new Conversion_Reporter($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());

        $attributions = [
            'hash_a' => 0.5,
            'hash_b' => 0.3,
            'hash_c' => 0.2,
        ];
        $click_ids = [
            'hash_a' => ['google_ads' => 'gclid_a'],
            'hash_b' => ['google_ads' => 'gclid_b'],
            'hash_c' => ['meta_ads' => 'fbclid_c'],  // No gclid.
        ];
        $campaigns = [];
        $context   = ['timestamp' => '2026-01-15 10:30:00'];

        $payloads = $reporter->enqueue($attributions, $click_ids, $campaigns, $context);

        // Only hash_a and hash_b have gclids.
        expect($payloads)->toHaveCount(2);
        expect($payloads[0]['gclid'])->toBe('gclid_a');
        expect($payloads[0]['attribution_fraction'])->toBe(0.5);
        expect($payloads[1]['gclid'])->toBe('gclid_b');
        expect($payloads[1]['attribution_fraction'])->toBe(0.3);
    });

    it('stores attribution_fraction as raw value', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get_all')->once()->andReturn(default_settings());

        $reporter = new Conversion_Reporter($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());

        $attributions = ['hash_a' => 0.25];
        $click_ids    = ['hash_a' => ['google_ads' => 'gclid_abc']];
        $campaigns    = [];
        $context      = ['timestamp' => '2026-01-15 10:30:00'];

        $payloads = $reporter->enqueue($attributions, $click_ids, $campaigns, $context);

        // Raw fraction stored, not pre-computed value.
        expect($payloads[0]['attribution_fraction'])->toBe(0.25);
        expect($payloads[0]['conversion_value'])->toBe('1000');
    });

    it('returns empty array when no gclids exist', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get_all')->once()->andReturn(default_settings());

        $reporter = new Conversion_Reporter($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());

        $attributions = ['hash_a' => 1.0, 'hash_b' => 1.0];
        $click_ids    = [
            'hash_a' => ['meta_ads' => 'fbclid_1'],
            'hash_b' => [],
        ];
        $campaigns = [];
        $context   = ['timestamp' => '2026-01-15 10:30:00'];

        $payloads = $reporter->enqueue($attributions, $click_ids, $campaigns, $context);

        expect($payloads)->toBeEmpty();
    });

    it('returns empty array when attributions array is empty', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get_all')->once()->andReturn(default_settings());

        $reporter = new Conversion_Reporter($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());

        $attributions = [];
        $click_ids    = ['hash_a' => ['google_ads' => 'gclid_abc']];
        $campaigns    = [];
        $context      = ['timestamp' => '2026-01-15 10:30:00'];

        $payloads = $reporter->enqueue($attributions, $click_ids, $campaigns, $context);

        expect($payloads)->toBeEmpty();
    });

    it('skips attribution when hash is missing entirely from click_ids', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get_all')->once()->andReturn(default_settings());

        $reporter = new Conversion_Reporter($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());

        // Attribution for hash_x exists but click_ids has no entry for it at all.
        $attributions = ['hash_x' => 0.75];
        $click_ids    = ['hash_other' => ['google_ads' => 'gclid_other']];
        $campaigns    = [];
        $context      = ['timestamp' => '2026-01-15 10:30:00'];

        $payloads = $reporter->enqueue($attributions, $click_ids, $campaigns, $context);

        expect($payloads)->toBeEmpty();
    });

    it('formats timestamp correctly', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get_all')->once()->andReturn(default_settings());

        $reporter = new Conversion_Reporter($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());

        $attributions = ['hash_a' => 1.0];
        $click_ids    = ['hash_a' => ['google_ads' => 'gclid_abc']];
        $campaigns    = [];
        $context      = ['timestamp' => '2026-03-15 14:30:00'];

        $payloads = $reporter->enqueue($attributions, $click_ids, $campaigns, $context);

        // DateTimeImmutable with format 'Y-m-d H:i:sP' produces offset.
        expect($payloads[0]['conversion_datetime'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');
    });

    it('snapshots credentials into payload', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get_all')->once()->andReturn(default_settings());

        $reporter = new Conversion_Reporter($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());

        $attributions = ['hash_a' => 1.0];
        $click_ids    = ['hash_a' => ['google_ads' => 'gclid_abc']];
        $campaigns    = [];
        $context      = ['timestamp' => '2026-01-15 10:30:00'];

        $payloads = $reporter->enqueue($attributions, $click_ids, $campaigns, $context);

        // All credentials should be present for self-contained async processing.
        expect($payloads[0])->toHaveKey('customer_id');
        expect($payloads[0])->toHaveKey('developer_token');
        expect($payloads[0])->toHaveKey('client_id');
        expect($payloads[0])->toHaveKey('client_secret');
        expect($payloads[0])->toHaveKey('refresh_token');
        expect($payloads[0])->toHaveKey('login_customer_id');
        expect($payloads[0]['developer_token'])->toBe('dev_token');
        expect($payloads[0]['client_secret'])->toBe('secret');
    });

    it('works with empty settings and snapshots empty strings', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get_all')->once()->andReturn(empty_settings());

        $reporter = new Conversion_Reporter($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());

        $attributions = ['hash_a' => 1.0];
        $click_ids    = ['hash_a' => ['google_ads' => 'gclid_abc']];
        $campaigns    = [];
        $context      = ['timestamp' => '2026-01-15 10:30:00'];

        $payloads = $reporter->enqueue($attributions, $click_ids, $campaigns, $context);

        // Payload is built even with empty credentials.
        expect($payloads)->toHaveCount(1);
        expect($payloads[0]['gclid'])->toBe('gclid_abc');
        expect($payloads[0]['customer_id'])->toBe('');
        expect($payloads[0]['developer_token'])->toBe('');
    });

});

// ─── process() ───

describe('Conversion_Reporter::process()', function () {

    it('uses payload credentials when present', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get_all')->once()->andReturn(empty_settings());

        // Stub the HTTP functions used by Google_Ads_Client.
        Functions\expect('get_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_access_token')
            ->andReturn('cached_token');

        Functions\when('wp_json_encode')->alias(fn ($data) => json_encode($data));
        Functions\when('wp_remote_retrieve_response_code')->alias(
            fn ($response) => $response['response']['code'] ?? 0,
        );
        Functions\when('wp_remote_retrieve_body')->alias(
            fn ($response) => $response['body'] ?? '',
        );

        Functions\expect('wp_remote_post')
            ->once()
            ->withArgs(function (string $url, array $args) {
                // Verify the URL uses the payload's customer_id.
                return str_contains($url, 'customers/1234567890:uploadClickConversions');
            })
            ->andReturn([
                'response' => ['code' => 200],
                'body'     => json_encode(['results' => [[]]]),
            ]);

        Functions\expect('is_wp_error')->once()->andReturn(false);
        Functions\when('delete_transient')->justReturn(true);

        $reporter = new Conversion_Reporter($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());
        $result   = $reporter->process([
            'gclid'                => 'gclid_abc',
            'conversion_datetime'  => '2026-01-15 10:30:00+01:00',
            'attribution_fraction' => 1.0,
            'customer_id'          => '1234567890',
            'conversion_action_id' => '99',
            'conversion_value'     => '1000',
            'currency_code'        => 'SEK',
            'developer_token'      => 'dev_token',
            'client_id'            => 'client.apps.googleusercontent.com',
            'client_secret'        => 'secret',
            'refresh_token'        => 'refresh_abc',
            'login_customer_id'    => '',
        ]);

        expect($result)->toBeTrue();
    });

    it('falls back to current settings when payload credentials are empty', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get_all')->once()->andReturn(default_settings());

        // Stub the HTTP functions used by Google_Ads_Client.
        Functions\expect('get_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_access_token')
            ->andReturn('cached_token');

        Functions\when('wp_json_encode')->alias(fn ($data) => json_encode($data));
        Functions\when('wp_remote_retrieve_response_code')->alias(
            fn ($response) => $response['response']['code'] ?? 0,
        );
        Functions\when('wp_remote_retrieve_body')->alias(
            fn ($response) => $response['body'] ?? '',
        );

        Functions\expect('wp_remote_post')
            ->once()
            ->withArgs(function (string $url, array $args) {
                // Settings fallback provides customer_id 1234567890.
                return str_contains($url, 'customers/1234567890:uploadClickConversions');
            })
            ->andReturn([
                'response' => ['code' => 200],
                'body'     => json_encode(['results' => [[]]]),
            ]);

        Functions\expect('is_wp_error')->once()->andReturn(false);
        Functions\when('delete_transient')->justReturn(true);

        $reporter = new Conversion_Reporter($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());
        $result   = $reporter->process([
            'gclid'                => 'gclid_abc',
            'conversion_datetime'  => '2026-01-15 10:30:00+01:00',
            'attribution_fraction' => 0.5,
            'customer_id'          => '',
            'conversion_action_id' => '',
            'conversion_value'     => '',
            'currency_code'        => '',
            'developer_token'      => '',
            'client_id'            => '',
            'client_secret'        => '',
            'refresh_token'        => '',
            'login_customer_id'    => '',
        ]);

        expect($result)->toBeTrue();
    });

    it('returns false when no credentials available anywhere', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get_all')->once()->andReturn(empty_settings());

        Functions\when('set_transient')->justReturn(true);

        // error_log is a redefinable internal in patchwork.json.
        $logged = null;
        \Patchwork\redefine('error_log', function (string $message) use (&$logged) {
            $logged = $message;
            return true;
        });

        $reporter = new Conversion_Reporter($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());
        $result   = $reporter->process([
            'gclid'                => 'gclid_abc',
            'conversion_datetime'  => '2026-01-15 10:30:00+01:00',
            'attribution_fraction' => 1.0,
            'customer_id'          => '',
            'conversion_action_id' => '',
            'conversion_value'     => '',
            'currency_code'        => '',
            'developer_token'      => '',
            'client_id'            => '',
            'client_secret'        => '',
            'refresh_token'        => '',
            'login_customer_id'    => '',
        ]);

        expect($result)->toBeFalse();
        expect($logged)->toContain('required credentials still missing');

        \Patchwork\restoreAll();
    });

    it('processes enqueue output end-to-end', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get_all')->twice()->andReturn(default_settings());

        $reporter = new Conversion_Reporter($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());

        // Enqueue a conversion.
        $attributions = ['hash_a' => 1.0];
        $click_ids    = ['hash_a' => ['google_ads' => 'gclid_end_to_end']];
        $campaigns    = [];
        $context      = ['timestamp' => '2026-01-15 10:30:00'];

        $payloads = $reporter->enqueue($attributions, $click_ids, $campaigns, $context);
        expect($payloads)->toHaveCount(1);

        // Stub HTTP functions for process().
        Functions\expect('get_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_access_token')
            ->andReturn('cached_token');

        Functions\when('wp_json_encode')->alias(fn ($data) => json_encode($data));
        Functions\when('wp_remote_retrieve_response_code')->alias(
            fn ($response) => $response['response']['code'] ?? 0,
        );
        Functions\when('wp_remote_retrieve_body')->alias(
            fn ($response) => $response['body'] ?? '',
        );

        Functions\expect('wp_remote_post')
            ->once()
            ->withArgs(function (string $url, array $args) {
                return str_contains($url, 'customers/1234567890:uploadClickConversions');
            })
            ->andReturn([
                'response' => ['code' => 200],
                'body'     => json_encode(['results' => [[]]]),
            ]);

        Functions\expect('is_wp_error')->once()->andReturn(false);
        Functions\when('delete_transient')->justReturn(true);

        // Feed enqueue output directly into process.
        $result = $reporter->process($payloads[0]);

        expect($result)->toBeTrue();
    });

    it('fills empty payload credentials from current settings at process time', function () {
        $empty = Mockery::mock(Settings::class);
        $empty->shouldReceive('get_all')->once()->andReturn(empty_settings());

        $reporter = new Conversion_Reporter($empty, Mockery::mock(Logger::class)->shouldIgnoreMissing());

        // Enqueue with empty credentials.
        $attributions = ['hash_a' => 0.5];
        $click_ids    = ['hash_a' => ['google_ads' => 'gclid_fill']];
        $campaigns    = [];
        $context      = ['timestamp' => '2026-01-15 10:30:00'];

        $payloads = $reporter->enqueue($attributions, $click_ids, $campaigns, $context);
        expect($payloads[0]['customer_id'])->toBe('');

        // Create a new reporter with filled settings for process().
        $filled = Mockery::mock(Settings::class);
        $filled->shouldReceive('get_all')->once()->andReturn(default_settings());

        $reporter2 = new Conversion_Reporter($filled, Mockery::mock(Logger::class)->shouldIgnoreMissing());

        // Stub HTTP functions for process().
        Functions\expect('get_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_access_token')
            ->andReturn('cached_token');

        Functions\when('wp_json_encode')->alias(fn ($data) => json_encode($data));
        Functions\when('wp_remote_retrieve_response_code')->alias(
            fn ($response) => $response['response']['code'] ?? 0,
        );
        Functions\when('wp_remote_retrieve_body')->alias(
            fn ($response) => $response['body'] ?? '',
        );

        Functions\expect('wp_remote_post')
            ->once()
            ->withArgs(function (string $url) {
                // Should use the fallback customer_id from current settings.
                return str_contains($url, 'customers/1234567890:uploadClickConversions');
            })
            ->andReturn([
                'response' => ['code' => 200],
                'body'     => json_encode(['results' => [[]]]),
            ]);

        Functions\expect('is_wp_error')->once()->andReturn(false);
        Functions\when('delete_transient')->justReturn(true);

        $result = $reporter2->process($payloads[0]);

        expect($result)->toBeTrue();
    });

    it('uploads zero value when attribution fraction is zero', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get_all')->once()->andReturn(default_settings());

        // Stub HTTP functions.
        Functions\expect('get_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_access_token')
            ->andReturn('cached_token');

        Functions\when('wp_json_encode')->alias(fn ($data) => json_encode($data));
        Functions\when('wp_remote_retrieve_response_code')->alias(
            fn ($response) => $response['response']['code'] ?? 0,
        );
        Functions\when('wp_remote_retrieve_body')->alias(
            fn ($response) => $response['body'] ?? '',
        );

        // Capture the uploaded body to verify zero value.
        $uploaded_body = null;
        Functions\expect('wp_remote_post')
            ->once()
            ->withArgs(function (string $url, array $args) use (&$uploaded_body) {
                $uploaded_body = json_decode($args['body'], true);
                return true;
            })
            ->andReturn([
                'response' => ['code' => 200],
                'body'     => json_encode(['results' => [[]]]),
            ]);

        Functions\expect('is_wp_error')->once()->andReturn(false);
        Functions\when('delete_transient')->justReturn(true);

        $reporter = new Conversion_Reporter($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());
        $reporter->process([
            'gclid'                => 'gclid_zero_frac',
            'conversion_datetime'  => '2026-01-15 10:30:00+01:00',
            'attribution_fraction' => 0.0,
            'customer_id'          => '1234567890',
            'conversion_action_id' => '99',
            'conversion_value'     => '1000',
            'currency_code'        => 'SEK',
            'developer_token'      => 'dev_token',
            'client_id'            => 'client.apps.googleusercontent.com',
            'client_secret'        => 'secret',
            'refresh_token'        => 'refresh_abc',
            'login_customer_id'    => '',
        ]);

        // 1000 * 0.0 = 0.0
        expect((float) $uploaded_body['conversions'][0]['conversionValue'])->toBe(0.0);
    });

    it('uploads zero value when conversion value is zero', function () {
        // Use empty_settings() so the ?: fallback also resolves to '0'
        // (PHP treats '0' as falsy, triggering fallback to settings).
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get_all')->once()->andReturn(empty_settings());

        // Stub HTTP functions.
        Functions\expect('get_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_access_token')
            ->andReturn('cached_token');

        Functions\when('wp_json_encode')->alias(fn ($data) => json_encode($data));
        Functions\when('wp_remote_retrieve_response_code')->alias(
            fn ($response) => $response['response']['code'] ?? 0,
        );
        Functions\when('wp_remote_retrieve_body')->alias(
            fn ($response) => $response['body'] ?? '',
        );

        // Capture the uploaded body to verify zero value.
        $uploaded_body = null;
        Functions\expect('wp_remote_post')
            ->once()
            ->withArgs(function (string $url, array $args) use (&$uploaded_body) {
                $uploaded_body = json_decode($args['body'], true);
                return true;
            })
            ->andReturn([
                'response' => ['code' => 200],
                'body'     => json_encode(['results' => [[]]]),
            ]);

        Functions\expect('is_wp_error')->once()->andReturn(false);
        Functions\when('delete_transient')->justReturn(true);

        $reporter = new Conversion_Reporter($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());
        $reporter->process([
            'gclid'                => 'gclid_zero_val',
            'conversion_datetime'  => '2026-01-15 10:30:00+01:00',
            'attribution_fraction' => 0.5,
            'customer_id'          => '1234567890',
            'conversion_action_id' => '99',
            'conversion_value'     => '0',
            'currency_code'        => 'SEK',
            'developer_token'      => 'dev_token',
            'client_id'            => 'client.apps.googleusercontent.com',
            'client_secret'        => 'secret',
            'refresh_token'        => 'refresh_abc',
            'login_customer_id'    => '',
        ]);

        // 0 * 0.5 = 0.0
        expect((float) $uploaded_body['conversions'][0]['conversionValue'])->toBe(0.0);
    });

    it('computes attributed value from fraction and merged conversion value', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get_all')->once()->andReturn(default_settings());

        // Stub HTTP functions.
        Functions\expect('get_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_access_token')
            ->andReturn('cached_token');

        Functions\when('wp_json_encode')->alias(fn ($data) => json_encode($data));
        Functions\when('wp_remote_retrieve_response_code')->alias(
            fn ($response) => $response['response']['code'] ?? 0,
        );
        Functions\when('wp_remote_retrieve_body')->alias(
            fn ($response) => $response['body'] ?? '',
        );

        // Capture the uploaded body to verify computed value.
        $uploaded_body = null;
        Functions\expect('wp_remote_post')
            ->once()
            ->withArgs(function (string $url, array $args) use (&$uploaded_body) {
                $uploaded_body = json_decode($args['body'], true);
                return true;
            })
            ->andReturn([
                'response' => ['code' => 200],
                'body'     => json_encode(['results' => [[]]]),
            ]);

        Functions\expect('is_wp_error')->once()->andReturn(false);
        Functions\when('delete_transient')->justReturn(true);

        $reporter = new Conversion_Reporter($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());
        $reporter->process([
            'gclid'                => 'gclid_abc',
            'conversion_datetime'  => '2026-01-15 10:30:00+01:00',
            'attribution_fraction' => 0.25,
            'customer_id'          => '1234567890',
            'conversion_action_id' => '99',
            'conversion_value'     => '1000',
            'currency_code'        => 'SEK',
            'developer_token'      => 'dev_token',
            'client_id'            => 'client.apps.googleusercontent.com',
            'client_secret'        => 'secret',
            'refresh_token'        => 'refresh_abc',
            'login_customer_id'    => '',
        ]);

        // 1000 * 0.25 = 250.0 (json_encode may produce integer 250).
        expect((float) $uploaded_body['conversions'][0]['conversionValue'])->toBe(250.0);
    });

    it('returns false on failure and logs error', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get_all')->once()->andReturn(empty_settings());

        // Stub HTTP functions for a failure scenario.
        Functions\expect('get_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_access_token')
            ->andReturn('cached_token');

        Functions\when('wp_json_encode')->alias(fn ($data) => json_encode($data));
        Functions\when('wp_remote_retrieve_response_code')->alias(
            fn ($response) => $response['response']['code'] ?? 0,
        );
        Functions\when('wp_remote_retrieve_body')->alias(
            fn ($response) => $response['body'] ?? '',
        );

        Functions\expect('wp_remote_post')
            ->once()
            ->andReturn([
                'response' => ['code' => 403],
                'body'     => 'Forbidden',
            ]);

        Functions\expect('is_wp_error')->once()->andReturn(false);

        // error_log is a redefinable internal in patchwork.json.
        $logged = null;
        \Patchwork\redefine('error_log', function (string $message) use (&$logged) {
            $logged = $message;
            return true;
        });

        $reporter = new Conversion_Reporter($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());
        $result   = $reporter->process([
            'gclid'                => 'gclid_abc',
            'conversion_datetime'  => '2026-01-15 10:30:00+01:00',
            'attribution_fraction' => 1.0,
            'customer_id'          => '1234567890',
            'conversion_action_id' => '99',
            'conversion_value'     => '1000',
            'currency_code'        => 'SEK',
            'developer_token'      => 'dev_token',
            'client_id'            => 'client.apps.googleusercontent.com',
            'client_secret'        => 'secret',
            'refresh_token'        => 'refresh_abc',
            'login_customer_id'    => '',
        ]);

        expect($result)->toBeFalse();
        expect($logged)->toContain('gclid_abc');
        expect($logged)->toContain('HTTP 403');

        \Patchwork\restoreAll();
    });

    it('sets credential error transient when credentials are missing', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get_all')->once()->andReturn(empty_settings());

        Functions\expect('set_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_credential_error', 'missing', 0)
            ->andReturn(true);

        \Patchwork\redefine('error_log', function () {
            return true;
        });

        $reporter = new Conversion_Reporter($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());
        $result   = $reporter->process([
            'gclid'                => 'gclid_abc',
            'conversion_datetime'  => '2026-01-15 10:30:00+01:00',
            'attribution_fraction' => 1.0,
            'customer_id'          => '',
            'conversion_action_id' => '',
            'conversion_value'     => '',
            'currency_code'        => '',
            'developer_token'      => '',
            'client_id'            => '',
            'client_secret'        => '',
            'refresh_token'        => '',
            'login_customer_id'    => '',
        ]);

        expect($result)->toBeFalse();

        \Patchwork\restoreAll();
    });

    it('sets credential error transient on token refresh failure', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get_all')->once()->andReturn(empty_settings());

        // Stub HTTP functions — token refresh fails (no cached token).
        Functions\expect('get_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_access_token')
            ->andReturn(false);

        Functions\when('wp_remote_retrieve_response_code')->alias(
            fn ($response) => $response['response']['code'] ?? 0,
        );
        Functions\when('wp_remote_retrieve_body')->alias(
            fn ($response) => $response['body'] ?? '',
        );

        // Token refresh HTTP call fails.
        Functions\expect('wp_remote_post')
            ->once()
            ->andReturn([
                'response' => ['code' => 401],
                'body'     => json_encode(['error' => 'invalid_grant']),
            ]);

        Functions\expect('is_wp_error')->once()->andReturn(false);

        Functions\expect('set_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_credential_error', 'token_refresh_failed', 0)
            ->andReturn(true);

        \Patchwork\redefine('error_log', function () {
            return true;
        });

        $reporter = new Conversion_Reporter($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());
        $result   = $reporter->process([
            'gclid'                => 'gclid_abc',
            'conversion_datetime'  => '2026-01-15 10:30:00+01:00',
            'attribution_fraction' => 1.0,
            'customer_id'          => '1234567890',
            'conversion_action_id' => '99',
            'conversion_value'     => '1000',
            'currency_code'        => 'SEK',
            'developer_token'      => 'dev_token',
            'client_id'            => 'client.apps.googleusercontent.com',
            'client_secret'        => 'secret',
            'refresh_token'        => 'refresh_abc',
            'login_customer_id'    => '',
        ]);

        expect($result)->toBeFalse();

        \Patchwork\restoreAll();
    });

    it('clears credential error transient on success', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get_all')->once()->andReturn(empty_settings());

        Functions\expect('get_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_access_token')
            ->andReturn('cached_token');

        Functions\when('wp_json_encode')->alias(fn ($data) => json_encode($data));
        Functions\when('wp_remote_retrieve_response_code')->alias(
            fn ($response) => $response['response']['code'] ?? 0,
        );
        Functions\when('wp_remote_retrieve_body')->alias(
            fn ($response) => $response['body'] ?? '',
        );

        Functions\expect('wp_remote_post')
            ->once()
            ->andReturn([
                'response' => ['code' => 200],
                'body'     => json_encode(['results' => [[]]]),
            ]);

        Functions\expect('is_wp_error')->once()->andReturn(false);

        Functions\expect('delete_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_credential_error')
            ->andReturn(true);

        $reporter = new Conversion_Reporter($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());
        $result   = $reporter->process([
            'gclid'                => 'gclid_abc',
            'conversion_datetime'  => '2026-01-15 10:30:00+01:00',
            'attribution_fraction' => 1.0,
            'customer_id'          => '1234567890',
            'conversion_action_id' => '99',
            'conversion_value'     => '1000',
            'currency_code'        => 'SEK',
            'developer_token'      => 'dev_token',
            'client_id'            => 'client.apps.googleusercontent.com',
            'client_secret'        => 'secret',
            'refresh_token'        => 'refresh_abc',
            'login_customer_id'    => '',
        ]);

        expect($result)->toBeTrue();
    });

});

// ─── reset_failed_jobs() ───

describe('Conversion_Reporter::reset_failed_jobs()', function () {

    afterEach(function () {
        unset($GLOBALS['wpdb']);
    });

    it('executes correct SQL and schedules queue processing', function () {
        $settings = Mockery::mock(Settings::class);

        $wpdb = \Tests\Helpers\TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        // Verify the prepare call uses correct parameters.
        $wpdb->shouldReceive('prepare')
            ->once()
            ->withArgs(function (string $sql, string $reporter) {
                return str_contains($sql, "SET status = 'pending'")
                    && str_contains($sql, "attempts = 0")
                    && str_contains($sql, "status = 'failed'")
                    && $reporter === 'google_ads';
            })
            ->andReturn('PREPARED_SQL');

        $wpdb->shouldReceive('query')
            ->once()
            ->with('PREPARED_SQL')
            ->andReturn(2);

        Functions\expect('wp_schedule_single_event')
            ->once()
            ->withArgs(function (int $time, string $hook) {
                return $hook === 'kntnt_ad_attr_process_queue';
            });

        $reporter = new Conversion_Reporter($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());
        $reporter->reset_failed_jobs();

        // Mockery verifies prepare/query/schedule expectations on teardown.
        expect(true)->toBeTrue();
    });

});
