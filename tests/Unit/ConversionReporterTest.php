<?php
/**
 * Unit tests for Conversion_Reporter.
 *
 * @package Tests\Unit
 * @since   0.3.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution_Gads\Conversion_Reporter;
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

// ─── register() ───

describe('Conversion_Reporter::register()', function () {

    it('registers when settings are configured', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('is_configured')->once()->andReturn(true);

        $reporter = new Conversion_Reporter($settings);
        $result   = $reporter->register([]);

        expect($result)->toHaveKey('google_ads');
        expect($result['google_ads']['enqueue'])->toBeArray();
        expect($result['google_ads']['process'])->toBeArray();
    });

    it('does not register when settings are not configured', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('is_configured')->once()->andReturn(false);

        $reporter = new Conversion_Reporter($settings);
        $result   = $reporter->register([]);

        expect($result)->not->toHaveKey('google_ads');
    });

    it('preserves existing reporters', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('is_configured')->once()->andReturn(true);

        $existing = [
            'meta_ads' => [
                'enqueue' => fn () => [],
                'process' => fn () => true,
            ],
        ];

        $reporter = new Conversion_Reporter($settings);
        $result   = $reporter->register($existing);

        expect($result)->toHaveKey('meta_ads');
        expect($result)->toHaveKey('google_ads');
    });

});

// ─── enqueue() ───

describe('Conversion_Reporter::enqueue()', function () {

    it('builds payload for a single attribution with gclid', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get_all')->once()->andReturn(default_settings());

        $reporter = new Conversion_Reporter($settings);

        $attributions = ['hash_a' => 1.0];
        $click_ids    = ['hash_a' => ['google_ads' => 'gclid_abc']];
        $campaigns    = ['hash_a' => ['source' => 'google']];
        $context      = ['timestamp' => '2026-01-15 10:30:00'];

        $payloads = $reporter->enqueue($attributions, $click_ids, $campaigns, $context);

        expect($payloads)->toHaveCount(1);
        expect($payloads[0]['gclid'])->toBe('gclid_abc');
        expect($payloads[0]['conversion_action'])->toBe('customers/1234567890/conversionActions/99');
        expect($payloads[0]['conversion_value'])->toBe(1000.0);
        expect($payloads[0]['currency_code'])->toBe('SEK');
        expect($payloads[0]['customer_id'])->toBe('1234567890');
    });

    it('skips attributions without google_ads click ID', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get_all')->once()->andReturn(default_settings());

        $reporter = new Conversion_Reporter($settings);

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

        $reporter = new Conversion_Reporter($settings);

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
        expect($payloads[1]['gclid'])->toBe('gclid_b');
    });

    it('applies fractional attribution to conversion value', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get_all')->once()->andReturn(default_settings());

        $reporter = new Conversion_Reporter($settings);

        $attributions = ['hash_a' => 0.25];
        $click_ids    = ['hash_a' => ['google_ads' => 'gclid_abc']];
        $campaigns    = [];
        $context      = ['timestamp' => '2026-01-15 10:30:00'];

        $payloads = $reporter->enqueue($attributions, $click_ids, $campaigns, $context);

        // 1000 * 0.25 = 250.0
        expect($payloads[0]['conversion_value'])->toBe(250.0);
    });

    it('returns empty array when no gclids exist', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get_all')->once()->andReturn(default_settings());

        $reporter = new Conversion_Reporter($settings);

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

    it('formats timestamp correctly', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get_all')->once()->andReturn(default_settings());

        $reporter = new Conversion_Reporter($settings);

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

        $reporter = new Conversion_Reporter($settings);

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

});

// ─── process() ───

describe('Conversion_Reporter::process()', function () {

    it('returns true on successful upload', function () {
        $settings = Mockery::mock(Settings::class);

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
            ->andReturn([
                'response' => ['code' => 200],
                'body'     => json_encode(['results' => [[]]]),
            ]);

        Functions\expect('is_wp_error')->once()->andReturn(false);

        $reporter = new Conversion_Reporter($settings);
        $result   = $reporter->process([
            'gclid'               => 'gclid_abc',
            'conversion_action'   => 'customers/1234567890/conversionActions/99',
            'conversion_datetime' => '2026-01-15 10:30:00+01:00',
            'conversion_value'    => 1000.0,
            'currency_code'       => 'SEK',
            'customer_id'         => '1234567890',
            'developer_token'     => 'dev_token',
            'client_id'           => 'client.apps.googleusercontent.com',
            'client_secret'       => 'secret',
            'refresh_token'       => 'refresh_abc',
            'login_customer_id'   => '',
        ]);

        expect($result)->toBeTrue();
    });

    it('returns false on failure and logs error', function () {
        $settings = Mockery::mock(Settings::class);

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

        $reporter = new Conversion_Reporter($settings);
        $result   = $reporter->process([
            'gclid'               => 'gclid_abc',
            'conversion_action'   => 'customers/1234567890/conversionActions/99',
            'conversion_datetime' => '2026-01-15 10:30:00+01:00',
            'conversion_value'    => 1000.0,
            'currency_code'       => 'SEK',
            'customer_id'         => '1234567890',
            'developer_token'     => 'dev_token',
            'client_id'           => 'client.apps.googleusercontent.com',
            'client_secret'       => 'secret',
            'refresh_token'       => 'refresh_abc',
            'login_customer_id'   => '',
        ]);

        expect($result)->toBeFalse();
        expect($logged)->toContain('gclid_abc');
        expect($logged)->toContain('HTTP 403');

        \Patchwork\restoreAll();
    });

});
