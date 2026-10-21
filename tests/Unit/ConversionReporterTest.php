<?php
/**
 * Unit tests for Conversion_Reporter.
 *
 * @package Tests\Unit
 * @since   0.3.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution_Gads\Conversion_Reporter;
use Kntnt\Ad_Attribution\Logger;
use Kntnt\Ad_Attribution_Gads\Settings;
use Brain\Monkey\Filters;
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

        $captured_at  = 1700000000;
        $attributions = ['hash_a' => 1.0];
        $click_ids    = ['hash_a' => ['google_ads' => ['id' => 'gclid_abc', 'captured_at' => $captured_at]]];
        $campaigns    = ['hash_a' => ['source' => 'google']];
        $context      = ['timestamp' => '2026-01-15 10:30:00'];

        $payloads = $reporter->enqueue($attributions, $click_ids, $campaigns, $context);

        expect($payloads)->toHaveCount(1);
        expect($payloads[0]['payload']['click_id'])->toBe('gclid_abc');
        expect($payloads[0]['payload']['attribution_fraction'])->toBe(1.0);
        expect($payloads[0]['payload']['conversion_action_id'])->toBe('99');
        expect($payloads[0]['payload']['conversion_value'])->toBe('1000');
        expect($payloads[0]['payload']['currency_code'])->toBe('SEK');
        expect($payloads[0]['payload']['customer_id'])->toBe('1234567890');
        expect($payloads[0])->toHaveKey('not_before');
        expect($payloads[0]['not_before'])->toBe($captured_at + 21600);
    });

    it('computes not_before as captured_at + 21600 (UPLOAD_DELAY_SECONDS)', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get_all')->once()->andReturn(default_settings());

        $reporter = new Conversion_Reporter($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());

        // A recent click — not_before should be in the future.
        $recent_capture = time() - 3600; // 1 hour ago
        $attributions   = ['hash_a' => 1.0];
        $click_ids      = ['hash_a' => ['google_ads' => ['id' => 'gclid_recent', 'captured_at' => $recent_capture]]];
        $context        = ['timestamp' => '2026-01-15 10:30:00'];

        $payloads = $reporter->enqueue($attributions, $click_ids, [], $context);

        expect($payloads[0]['not_before'])->toBe($recent_capture + 21600);
    });

    it('defers the upload six hours from the attributed click, not from the conversion', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get_all')->once()->andReturn(default_settings());

        $reporter = new Conversion_Reporter($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());

        // The shape core >= 1.10.0 passes: the click IDs of the click the
        // conversion is attributed to, with captured_at set to that click's
        // clicked_at (2023-11-14 22:13:20 UTC). The conversion is a day later.
        $click_ids = ['h1' => ['google_ads' => ['id' => 'G1', 'captured_at' => 1700000000]]];
        $context   = ['timestamp' => '2023-11-15T22:13:20+00:00'];

        $payloads = $reporter->enqueue(['h1' => 1.0], $click_ids, [], $context);

        expect($payloads[0]['payload']['click_id'])->toBe('G1');
        expect($payloads[0]['not_before'])->toBe(1700021600);
    });

    it('produces past not_before for old clicks (immediate processing)', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get_all')->once()->andReturn(default_settings());

        $reporter = new Conversion_Reporter($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());

        // An old click — captured_at + 21600 is in the past.
        $old_capture  = time() - 86400; // 24 hours ago
        $attributions = ['hash_a' => 1.0];
        $click_ids    = ['hash_a' => ['google_ads' => ['id' => 'gclid_old', 'captured_at' => $old_capture]]];
        $context      = ['timestamp' => '2026-01-15 10:30:00'];

        $payloads = $reporter->enqueue($attributions, $click_ids, [], $context);

        // not_before is old_capture + 21600, which is still in the past.
        expect($payloads[0]['not_before'])->toBe($old_capture + 21600);
        expect($payloads[0]['not_before'])->toBeLessThan(time());
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

    it('stores attribution_fraction as raw value', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get_all')->once()->andReturn(default_settings());

        $reporter = new Conversion_Reporter($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());

        $attributions = ['hash_a' => 0.25];
        $click_ids    = ['hash_a' => ['google_ads' => ['id' => 'gclid_abc', 'captured_at' => 1700000000]]];
        $campaigns    = [];
        $context      = ['timestamp' => '2026-01-15 10:30:00'];

        $payloads = $reporter->enqueue($attributions, $click_ids, $campaigns, $context);

        // Raw fraction stored, not pre-computed value.
        expect($payloads[0]['payload']['attribution_fraction'])->toBe(0.25);
        expect($payloads[0]['payload']['conversion_value'])->toBe('1000');
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
        $click_ids    = ['hash_a' => ['google_ads' => ['id' => 'gclid_abc', 'captured_at' => 1700000000]]];
        $campaigns    = [];
        $context      = ['timestamp' => '2026-03-15 14:30:00'];

        $payloads = $reporter->enqueue($attributions, $click_ids, $campaigns, $context);

        // DateTimeImmutable with format 'Y-m-d H:i:sP' produces offset.
        expect($payloads[0]['payload']['conversion_datetime'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');
    });

    it('snapshots credentials into payload', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get_all')->once()->andReturn(default_settings());

        $reporter = new Conversion_Reporter($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());

        $attributions = ['hash_a' => 1.0];
        $click_ids    = ['hash_a' => ['google_ads' => ['id' => 'gclid_abc', 'captured_at' => 1700000000]]];
        $campaigns    = [];
        $context      = ['timestamp' => '2026-01-15 10:30:00'];

        $payloads = $reporter->enqueue($attributions, $click_ids, $campaigns, $context);

        // All credentials should be present for self-contained async processing.
        expect($payloads[0]['payload'])->toHaveKey('customer_id');
        expect($payloads[0]['payload'])->toHaveKey('developer_token');
        expect($payloads[0]['payload'])->toHaveKey('client_id');
        expect($payloads[0]['payload'])->toHaveKey('client_secret');
        expect($payloads[0]['payload'])->toHaveKey('refresh_token');
        expect($payloads[0]['payload'])->toHaveKey('login_customer_id');
        expect($payloads[0]['payload']['developer_token'])->toBe('dev_token');
        expect($payloads[0]['payload']['client_secret'])->toBe('secret');
    });

    it('works with empty settings and snapshots empty strings', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get_all')->once()->andReturn(empty_settings());

        $reporter = new Conversion_Reporter($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());

        $attributions = ['hash_a' => 1.0];
        $click_ids    = ['hash_a' => ['google_ads' => ['id' => 'gclid_abc', 'captured_at' => 1700000000]]];
        $campaigns    = [];
        $context      = ['timestamp' => '2026-01-15 10:30:00'];

        $payloads = $reporter->enqueue($attributions, $click_ids, $campaigns, $context);

        // Payload is built even with empty credentials.
        expect($payloads)->toHaveCount(1);
        expect($payloads[0]['payload']['click_id'])->toBe('gclid_abc');
        expect($payloads[0]['payload']['customer_id'])->toBe('');
        expect($payloads[0]['payload']['developer_token'])->toBe('');
    });

});

// ─── enqueue() attribution selection ───

/**
 * Builds a reporter whose settings mock returns default_settings().
 *
 * @param Logger|null $logger Optional logger; a permissive mock is used when null.
 *
 * @return Conversion_Reporter
 */
function selection_reporter(?Logger $logger = null): Conversion_Reporter {
    $settings = Mockery::mock(Settings::class);
    $settings->shouldReceive('get_all')->andReturn(default_settings());

    return new Conversion_Reporter($settings, $logger ?? Mockery::mock(Logger::class)->shouldIgnoreMissing());
}

/**
 * Builds a hash's click-ID entries for a click that has only a gclid.
 *
 * @param string $gclid       Click identifier.
 * @param int    $captured_at Unix timestamp of the click.
 *
 * @return array{google_ads: array{id: string, captured_at: int}}
 */
function gads_click(string $gclid, int $captured_at = 1700000000): array {
    return gads_click_ids(['gclid' => $gclid], $captured_at);
}

/**
 * Builds a hash's click-ID entries for the given Google Ads identifiers.
 *
 * Uses the platform keys the core stores them under, so the entries have
 * the shape core >= 1.11.0 passes to reporters.
 *
 * @param array<string,string> $ids         Identifier type ('gclid', 'gbraid' or 'wbraid') => value.
 * @param int                  $captured_at Unix timestamp of the click.
 *
 * @return array<string,array{id: string, captured_at: int}>
 */
function gads_click_ids(array $ids, int $captured_at = 1700000000): array {
    $platforms = ['gclid' => 'google_ads', 'gbraid' => 'google_ads_gbraid', 'wbraid' => 'google_ads_wbraid'];
    $entries   = [];
    foreach ($ids as $type => $id) {
        $entries[$platforms[$type]] = ['id' => $id, 'captured_at' => $captured_at];
    }
    return $entries;
}

/**
 * Makes the multi-touch filter return the given mode.
 *
 * @param string $mode Filter return value.
 *
 * @return void
 */
function use_multi_touch_mode(string $mode): void {
    Filters\expectApplied('kntnt_ad_attr_gads_multi_touch')->andReturn($mode);
}

describe('Conversion_Reporter::enqueue() attribution selection', function () {

    $context = ['timestamp' => '2026-01-15 10:30:00'];

    it('skips zero-weight attributions', function (string $mode) use ($context) {
        use_multi_touch_mode($mode);

        $attributions = ['hash_a' => 1.0, 'hash_b' => 0.0];
        $click_ids    = [
            'hash_a' => gads_click('gclid_a'),
            'hash_b' => gads_click('gclid_b'),
        ];

        $payloads = selection_reporter()->enqueue($attributions, $click_ids, [], $context);

        expect($payloads)->toHaveCount(1);
        expect($payloads[0]['payload']['click_id'])->toBe('gclid_a');
        expect($payloads[0]['payload']['attribution_fraction'])->toBe(1.0);
    })->with(['highest', 'split']);

    it('uploads only the Google Ads share when another platform holds part of the weight', function (string $mode) use ($context) {
        use_multi_touch_mode($mode);

        $attributions = ['hash_m' => 0.6, 'hash_g' => 0.4];
        $click_ids    = [
            'hash_m' => ['meta_ads' => ['id' => 'fbclid_m', 'captured_at' => 1700000000]],
            'hash_g' => gads_click('gclid_g', 1700000500),
        ];

        $payloads = selection_reporter()->enqueue($attributions, $click_ids, [], $context);

        expect($payloads)->toHaveCount(1);
        expect($payloads[0]['payload']['click_id'])->toBe('gclid_g');
        expect($payloads[0]['payload']['attribution_fraction'])->toBe(0.4);
        expect($payloads[0]['not_before'])->toBe(1700000500 + 21600);
    })->with(['highest', 'split']);

    it('returns no items when no attribution has positive weight', function (string $mode) use ($context) {
        use_multi_touch_mode($mode);

        $attributions = ['hash_a' => 0.0, 'hash_b' => -0.2];
        $click_ids    = [
            'hash_a' => gads_click('gclid_a'),
            'hash_b' => gads_click('gclid_b'),
        ];

        expect(selection_reporter()->enqueue($attributions, $click_ids, [], $context))->toBe([]);
    })->with(['highest', 'split']);

    it('returns no items when no attribution has a Google Ads click', function (string $mode) use ($context) {
        use_multi_touch_mode($mode);

        $attributions = ['hash_a' => 0.6, 'hash_b' => 0.4];
        $click_ids    = [
            'hash_a' => ['meta_ads' => ['id' => 'fbclid_a', 'captured_at' => 1700000000]],
        ];

        expect(selection_reporter()->enqueue($attributions, $click_ids, [], $context))->toBe([]);
    })->with(['highest', 'split']);

    it('merges eligible attributions into one upload for the heaviest click in the default mode', function () use ($context) {
        $attributions = ['hash_a' => 0.7, 'hash_b' => 0.3];
        $click_ids    = [
            'hash_a' => gads_click('gclid_a', 1700000000),
            'hash_b' => gads_click('gclid_b', 1700000500),
        ];

        $payloads = selection_reporter()->enqueue($attributions, $click_ids, [], $context);

        expect($payloads)->toHaveCount(1);
        expect($payloads[0]['payload']['click_id'])->toBe('gclid_a');
        expect($payloads[0]['payload']['attribution_fraction'])->toEqualWithDelta(1.0, 1e-9);
        expect($payloads[0]['not_before'])->toBe(1700000000 + 21600);
    });

    it('breaks a weight tie in favour of the most recent click', function () use ($context) {
        $attributions = ['hash_a' => 0.5, 'hash_b' => 0.5];
        $click_ids    = [
            'hash_a' => gads_click('gclid_a', 1700000500),
            'hash_b' => gads_click('gclid_b', 1700000900),
        ];

        $payloads = selection_reporter()->enqueue($attributions, $click_ids, [], $context);

        expect($payloads)->toHaveCount(1);
        expect($payloads[0]['payload']['click_id'])->toBe('gclid_b');
        expect($payloads[0]['payload']['attribution_fraction'])->toBe(1.0);
        expect($payloads[0]['not_before'])->toBe(1700000900 + 21600);
    });

    it('breaks a full tie in favour of the first attribution', function () use ($context) {
        $attributions = ['hash_a' => 0.5, 'hash_b' => 0.5];
        $click_ids    = [
            'hash_a' => gads_click('gclid_a', 1700000500),
            'hash_b' => gads_click('gclid_b', 1700000500),
        ];

        $payloads = selection_reporter()->enqueue($attributions, $click_ids, [], $context);

        expect($payloads)->toHaveCount(1);
        expect($payloads[0]['payload']['click_id'])->toBe('gclid_a');
    });

    it('caps the merged fraction at 1.0', function () use ($context) {
        $attributions = ['hash_a' => 0.8, 'hash_b' => 0.5];
        $click_ids    = [
            'hash_a' => gads_click('gclid_a'),
            'hash_b' => gads_click('gclid_b'),
        ];

        $payloads = selection_reporter()->enqueue($attributions, $click_ids, [], $context);

        expect($payloads)->toHaveCount(1);
        expect($payloads[0]['payload']['attribution_fraction'])->toBe(1.0);
    });

    it('logs the mode and the number of merged attributions in highest mode', function () use ($context) {
        $messages = [];
        $logger   = recording_logger($messages);

        $attributions = ['hash_a' => 0.7, 'hash_b' => 0.3, 'hash_c' => 0.0];
        $click_ids    = [
            'hash_a' => gads_click('gclid_a'),
            'hash_b' => gads_click('gclid_b'),
            'hash_c' => gads_click('gclid_c'),
        ];

        selection_reporter($logger)->enqueue($attributions, $click_ids, [], $context);

        $enqueued = array_values(array_filter($messages, fn ($m) => str_starts_with($m, 'Enqueued')));
        expect($enqueued)->toHaveCount(1);
        expect($enqueued[0])->toContain('mode: highest');
        expect($enqueued[0])->toContain('merged: 2');
        expect($enqueued[0])->toContain('gclid_a');
    });

    it('logs the mode for every upload in split mode', function () use ($context) {
        use_multi_touch_mode('split');

        $messages = [];
        $logger   = recording_logger($messages);

        $attributions = ['hash_a' => 0.7, 'hash_b' => 0.3];
        $click_ids    = [
            'hash_a' => gads_click('gclid_a'),
            'hash_b' => gads_click('gclid_b'),
        ];

        selection_reporter($logger)->enqueue($attributions, $click_ids, [], $context);

        $enqueued = array_values(array_filter($messages, fn ($m) => str_starts_with($m, 'Enqueued')));
        expect($enqueued)->toHaveCount(2);
        expect($enqueued[0])->toContain('mode: split');
        expect($enqueued[0])->toContain('gclid_a');
        expect($enqueued[1])->toContain('mode: split');
        expect($enqueued[1])->toContain('gclid_b');
    });

    it('treats an unknown mode as highest', function (mixed $mode) use ($context) {
        Filters\expectApplied('kntnt_ad_attr_gads_multi_touch')->andReturn($mode);

        $attributions = ['hash_a' => 0.7, 'hash_b' => 0.3];
        $click_ids    = [
            'hash_a' => gads_click('gclid_a'),
            'hash_b' => gads_click('gclid_b'),
        ];

        $payloads = selection_reporter()->enqueue($attributions, $click_ids, [], $context);

        expect($payloads)->toHaveCount(1);
        expect($payloads[0]['payload']['click_id'])->toBe('gclid_a');
        expect($payloads[0]['payload']['attribution_fraction'])->toEqualWithDelta(1.0, 1e-9);
    })->with(['bogus', 'Split', null]);

    it('uploads each eligible attribution with its own fraction in split mode', function () use ($context) {
        use_multi_touch_mode('split');

        $attributions = ['hash_a' => 0.7, 'hash_b' => 0.3];
        $click_ids    = [
            'hash_a' => gads_click('gclid_a', 1700000000),
            'hash_b' => gads_click('gclid_b', 1700000500),
        ];

        $payloads = selection_reporter()->enqueue($attributions, $click_ids, [], $context);

        expect($payloads)->toHaveCount(2);
        expect($payloads[0]['payload']['click_id'])->toBe('gclid_a');
        expect($payloads[0]['payload']['attribution_fraction'])->toBe(0.7);
        expect($payloads[0]['not_before'])->toBe(1700000000 + 21600);
        expect($payloads[1]['payload']['click_id'])->toBe('gclid_b');
        expect($payloads[1]['payload']['attribution_fraction'])->toBe(0.3);
        expect($payloads[1]['not_before'])->toBe(1700000500 + 21600);
    });

});

// ─── enqueue() click identifier ───

describe('Conversion_Reporter::enqueue() click identifier', function () {

    $context = ['timestamp' => '2026-01-15 10:30:00'];

    it('queues the Google Ads click identifier the hash has, gclid before gbraid before wbraid', function (string $mode, array $ids, string $type, string $id) use ($context) {
        use_multi_touch_mode($mode);

        $payloads = selection_reporter()->enqueue(['hash_a' => 1.0], ['hash_a' => gads_click_ids($ids)], [], $context);

        expect($payloads)->toHaveCount(1);
        expect($payloads[0]['payload']['click_id_type'])->toBe($type);
        expect($payloads[0]['payload']['click_id'])->toBe($id);
    })->with(['highest', 'split'])->with([
        'only gclid'          => [['gclid' => 'G1'], 'gclid', 'G1'],
        'only gbraid'         => [['gbraid' => 'B1'], 'gbraid', 'B1'],
        'only wbraid'         => [['wbraid' => 'W1'], 'wbraid', 'W1'],
        'gclid and gbraid'    => [['gclid' => 'G1', 'gbraid' => 'B1'], 'gclid', 'G1'],
        'gclid and wbraid'    => [['gclid' => 'G1', 'wbraid' => 'W1'], 'gclid', 'G1'],
        'gbraid and wbraid'   => [['gbraid' => 'B1', 'wbraid' => 'W1'], 'gbraid', 'B1'],
        'all three'           => [['gclid' => 'G1', 'gbraid' => 'B1', 'wbraid' => 'W1'], 'gclid', 'G1'],
        'empty gclid, gbraid' => [['gclid' => '', 'gbraid' => 'B1'], 'gbraid', 'B1'],
    ]);

    it('queues nothing for a hash with none of the three identifiers', function (string $mode) use ($context) {
        use_multi_touch_mode($mode);

        $click_ids = ['hash_a' => ['meta_ads' => ['id' => 'fbclid_a', 'captured_at' => 1700000000]] + gads_click_ids(['gclid' => '', 'gbraid' => '', 'wbraid' => ''])];

        expect(selection_reporter()->enqueue(['hash_a' => 1.0], $click_ids, [], $context))->toBe([]);
    })->with(['highest', 'split']);

    it('defers the upload six hours from the click of the chosen identifier', function () use ($context) {
        $click_ids = ['hash_a' => gads_click_ids(['gbraid' => 'B1'], 1700000000) + gads_click_ids(['wbraid' => 'W1'], 1700000900)];

        $payloads = selection_reporter()->enqueue(['hash_a' => 1.0], $click_ids, [], $context);

        expect($payloads[0]['payload']['click_id_type'])->toBe('gbraid');
        expect($payloads[0]['not_before'])->toBe(1700021600);
    });

    it('uploads a braid-only click that has the highest fraction in highest mode', function () use ($context) {
        $attributions = ['hash_g' => 0.3, 'hash_b' => 0.7];
        $click_ids    = [
            'hash_g' => gads_click_ids(['gclid' => 'G1'], 1700000900),
            'hash_b' => gads_click_ids(['gbraid' => 'B1'], 1700000000),
        ];

        $payloads = selection_reporter()->enqueue($attributions, $click_ids, [], $context);

        expect($payloads)->toHaveCount(1);
        expect($payloads[0]['payload']['click_id_type'])->toBe('gbraid');
        expect($payloads[0]['payload']['click_id'])->toBe('B1');
        expect($payloads[0]['payload']['attribution_fraction'])->toEqualWithDelta(1.0, 1e-9);
        expect($payloads[0]['not_before'])->toBe(1700021600);
    });

    it('labels the queue item with the identifier type and the start of its value', function (array $ids, string $label) use ($context) {
        $payloads = selection_reporter()->enqueue(['hash_a' => 1.0], ['hash_a' => gads_click_ids($ids)], [], $context);

        expect($payloads[0]['label'])->toBe($label);
    })->with([
        'gclid'  => [['gclid' => 'Cj0KCQjw_gclid_value'], 'gclid Cj0KCQjw_gcl… → action 99'],
        'gbraid' => [['gbraid' => '0AAAAAD_gbraid_value'], 'gbraid 0AAAAAD_gbra… → action 99'],
        'wbraid' => [['wbraid' => 'ClQKCQjw_wbraid_value'], 'wbraid ClQKCQjw_wbr… → action 99'],
    ]);

    it('names the identifier type in the Enqueued log line', function () use ($context) {
        $messages = [];

        selection_reporter(recording_logger($messages))->enqueue(['hash_a' => 1.0], ['hash_a' => gads_click_ids(['wbraid' => 'W1'])], [], $context);

        expect($messages)->toHaveCount(1);
        expect($messages[0])->toStartWith('Enqueued — mode: highest, merged: 1, wbraid: W1,');
    });

    it('uploads a conversion from a braid-only click with that braid alone', function (string $type) use ($context) {
        $reporter = selection_reporter();

        $payloads = $reporter->enqueue(['hash_a' => 1.0], ['hash_a' => gads_click_ids([$type => 'BRAID_1'])], [], $context);
        expect($payloads)->toHaveCount(1);

        $conversion = uploaded_conversion_of($reporter, $payloads[0]['payload']);

        expect(click_identifiers($conversion))->toBe([$type => 'BRAID_1']);
    })->with(['gbraid', 'wbraid']);

});

// ─── process() ───

/**
 * Returns a complete queued payload around the given click identifier keys.
 *
 * @param array<string,string> $click Identifier keys, e.g. ['gclid' => 'G1']
 *                                    for a payload queued before 1.11.0.
 *
 * @return array<string,mixed>
 */
function queued_payload(array $click): array {
    return $click + [
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
    ];
}

/**
 * Processes a payload against an API stub that accepts every upload, and
 * returns the one conversion the upload request carried.
 *
 * @param Conversion_Reporter $reporter Reporter under test.
 * @param array<string,mixed> $payload  Queued payload.
 *
 * @return array<string,mixed> The uploaded ClickConversion.
 */
function uploaded_conversion_of(Conversion_Reporter $reporter, array $payload): array {
    Functions\when('delete_transient')->justReturn(true);

    return conversion_uploaded_by(fn () => expect($reporter->process($payload))->toBeTrue());
}

/**
 * Returns the click identifier fields of an uploaded ClickConversion.
 *
 * @param array<string,mixed> $conversion Uploaded ClickConversion.
 *
 * @return array<string,mixed> Only its gclid, gbraid and wbraid fields.
 */
function click_identifiers(array $conversion): array {
    return array_intersect_key($conversion, array_flip(['gclid', 'gbraid', 'wbraid']));
}

describe('Conversion_Reporter::process() click identifiers', function () {

    it('uploads a queued click identifier in the field of its type only', function (string $type) {
        $payload = queued_payload(['click_id_type' => $type, 'click_id' => 'ID_123']);

        $conversion = uploaded_conversion_of(selection_reporter(), $payload);

        expect(click_identifiers($conversion))->toBe([$type => 'ID_123']);
    })->with(['gclid', 'gbraid', 'wbraid']);

    it('uploads a payload queued before 1.11.0, which has only a gclid, as a gclid', function () {
        $payload = queued_payload(['gclid' => 'gclid_legacy']);

        $conversion = uploaded_conversion_of(selection_reporter(), $payload);

        expect(click_identifiers($conversion))->toBe(['gclid' => 'gclid_legacy']);
        expect($conversion['conversionAction'])->toBe('customers/1234567890/conversionActions/99');
    });

    it('names the identifier type when an upload fails', function () {
        stub_api_with_cached_token();
        Functions\when('wp_remote_post')->justReturn(['response' => ['code' => 403], 'body' => 'Forbidden']);

        $logged = [];
        \Patchwork\redefine('error_log', function (string $message) use (&$logged) {
            $logged[] = $message;
            return true;
        });

        $messages = [];
        $result   = selection_reporter(recording_logger($messages))->process(queued_payload(['click_id_type' => 'wbraid', 'click_id' => 'W_123']));

        expect($result)->toBeFalse();
        expect($logged)->toHaveCount(1);
        expect($logged[0])->toContain('wbraid W_123')->not->toContain('gclid');
        expect($messages[0])->toStartWith('Processing — wbraid: W_123,');

        \Patchwork\restoreAll();
    });

    it('ends a job whose click identifier type is unknown without uploading it', function (mixed $type) {
        stub_wp_json_encode();
        Functions\expect('wp_remote_post')->never();

        $logged = [];
        \Patchwork\redefine('error_log', function (string $message) use (&$logged) {
            $logged[] = $message;
            return true;
        });

        $errors = [];
        $result = selection_reporter(recording_logger($errors, 'error'))->process(queued_payload(['click_id_type' => $type, 'click_id' => 'D_123']));

        expect($result)->toBeTrue();
        expect($errors)->toHaveCount(1);
        expect($errors[0])->toStartWith('Permanent failure')->toContain((string) $type);
        expect($logged)->toHaveCount(1);
        expect($logged[0])->toContain('Permanent failure')->toContain((string) $type);

        \Patchwork\restoreAll();
    })->with([
        'unknown name' => ['dclid'],
        'not a string' => [7],
    ]);

    it('ends a job without a click identifier value without uploading it', function (array $click) {
        stub_wp_json_encode();
        Functions\expect('wp_remote_post')->never();

        $logged = [];
        \Patchwork\redefine('error_log', function (string $message) use (&$logged) {
            $logged[] = $message;
            return true;
        });

        $errors = [];
        $result = selection_reporter(recording_logger($errors, 'error'))->process(queued_payload($click));

        expect($result)->toBeTrue();
        expect($errors)->toHaveCount(1);
        expect($errors[0])->toStartWith('Permanent failure');
        expect($logged)->toHaveCount(1);
        expect($logged[0])->toContain('Permanent failure');

        \Patchwork\restoreAll();
    })->with([
        'no click_id'         => [['click_id_type' => 'gbraid']],
        'empty click_id'      => [['click_id_type' => 'gbraid', 'click_id' => '']],
        'legacy, no gclid'    => [[]],
        'legacy, empty gclid' => [['gclid' => '']],
    ]);

});

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
        $click_ids    = ['hash_a' => ['google_ads' => ['id' => 'gclid_end_to_end', 'captured_at' => 1700000000]]];
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
        $result = $reporter->process($payloads[0]['payload']);

        expect($result)->toBeTrue();
    });

    it('fills empty payload credentials from current settings at process time', function () {
        $empty = Mockery::mock(Settings::class);
        $empty->shouldReceive('get_all')->once()->andReturn(empty_settings());

        $reporter = new Conversion_Reporter($empty, Mockery::mock(Logger::class)->shouldIgnoreMissing());

        // Enqueue with empty credentials.
        $attributions = ['hash_a' => 0.5];
        $click_ids    = ['hash_a' => ['google_ads' => ['id' => 'gclid_fill', 'captured_at' => 1700000000]]];
        $campaigns    = [];
        $context      = ['timestamp' => '2026-01-15 10:30:00'];

        $payloads = $reporter->enqueue($attributions, $click_ids, $campaigns, $context);
        expect($payloads[0]['payload']['customer_id'])->toBe('');

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

        $result = $reporter2->process($payloads[0]['payload']);

        expect($result)->toBeTrue();
    });

    it('uses current settings conversion_action_id over payload value', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get_all')->once()->andReturn(array_merge(
            default_settings(),
            ['conversion_action_id' => '200'],
        ));

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

        // Verify the upload uses the NEW action ID from settings, not the old payload one.
        $uploaded_body = null;
        Functions\expect('wp_remote_post')
            ->once()
            ->withArgs(function (string $url, array $args) use (&$uploaded_body) {
                $uploaded_body = json_decode($args['body'], true);
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
            'gclid'                => 'gclid_old_action',
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

        // Payload had action ID 99, but settings now has 200 — settings must win.
        $action = $uploaded_body['conversions'][0]['conversionAction'];
        expect($action)->toBe('customers/1234567890/conversionActions/200');
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

    it('returns true on permanent failure to stop retries', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get_all')->once()->andReturn(empty_settings());

        // Stub HTTP functions — upload returns a permanent partial failure.
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
                'body'     => json_encode([
                    'partialFailureError' => [
                        'code'    => 3,
                        'message' => 'The imported event could not be attributed to a click. This may be because the event did not come from a Google Ads campaign., at conversions[0].gclid',
                    ],
                ]),
            ]);

        Functions\expect('is_wp_error')->once()->andReturn(false);

        // The credential error transient is cleared because the API was reached.
        Functions\expect('delete_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_credential_error');

        $logged = null;
        \Patchwork\redefine('error_log', function (string $message) use (&$logged) {
            $logged = $message;
            return true;
        });

        $reporter = new Conversion_Reporter($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());
        $result   = $reporter->process([
            'gclid'                => 'gclid_permanent',
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

        // Permanent failures return true to prevent retries.
        expect($result)->toBeTrue();
        expect($logged)->toContain('Permanent failure');
        expect($logged)->toContain('gclid_permanent');

        \Patchwork\restoreAll();
    });

    it('clears credential error transient on permanent failure', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get_all')->once()->andReturn(empty_settings());

        // Stub HTTP functions — upload returns a permanent partial failure.
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
                'body'     => json_encode([
                    'partialFailureError' => [
                        'code'    => 3,
                        'message' => 'The imported event could not be attributed to a click.',
                    ],
                ]),
            ]);

        Functions\expect('is_wp_error')->once()->andReturn(false);

        // Credential error transient should be deleted even though the
        // upload failed — a permanent failure proves the API was reached.
        Functions\expect('delete_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_credential_error');

        \Patchwork\redefine('error_log', function () {
            return true;
        });

        $reporter = new Conversion_Reporter($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());
        $result   = $reporter->process([
            'gclid'                => 'gclid_perm',
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

        \Patchwork\restoreAll();
    });

    it('returns true when Google Ads rejects the conversion with EXPIRED_EVENT', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get_all')->once()->andReturn(empty_settings());

        Functions\when('get_transient')->justReturn('cached_token');
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_json_encode')->alias(fn ($data) => json_encode($data));
        Functions\when('wp_remote_retrieve_response_code')->alias(
            fn ($response) => $response['response']['code'] ?? 0,
        );
        Functions\when('wp_remote_retrieve_body')->alias(
            fn ($response) => $response['body'] ?? '',
        );

        // Partial failure as returned by Google Ads API v23 for a click that
        // is older than the conversion action's click-through window.
        $message = "The imported event can't be recorded because its click occurred before this conversion's click-through window. Make sure you import the most recent data.";
        Functions\expect('wp_remote_post')
            ->once()
            ->andReturn([
                'response' => ['code' => 200],
                'body'     => json_encode([
                    'partialFailureError' => [
                        'code'    => 3,
                        'message' => "{$message}, at conversions[0].gclid",
                        'details' => [
                            [
                                '@type'  => 'type.googleapis.com/google.ads.googleads.v23.errors.GoogleAdsFailure',
                                'errors' => [
                                    [
                                        'errorCode' => ['conversionUploadError' => 'EXPIRED_EVENT'],
                                        'message'   => $message,
                                        'location'  => [
                                            'fieldPathElements' => [
                                                ['fieldName' => 'conversions', 'index' => 0],
                                                ['fieldName' => 'gclid'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'results' => [new stdClass()],
                ]),
            ]);

        Functions\expect('delete_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_credential_error');

        $logged = null;
        \Patchwork\redefine('error_log', function (string $log_message) use (&$logged) {
            $logged = $log_message;
            return true;
        });

        $reporter = new Conversion_Reporter($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());
        $result   = $reporter->process([
            'gclid'                => 'gclid_expired',
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
        expect($logged)->toContain('EXPIRED_EVENT');

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
