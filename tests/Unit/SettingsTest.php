<?php
/**
 * Unit tests for Settings.
 *
 * @package Tests\Unit
 * @since   0.2.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution_Gads\Settings;
use Brain\Monkey\Functions;

// ─── get_all() ───

describe('Settings::get_all()', function () {

    it('returns defaults when option does not exist', function () {
        Functions\expect('get_option')
            ->once()
            ->with(Settings::OPTION_KEY, [])
            ->andReturn([]);

        $settings = new Settings();
        $all      = $settings->get_all();

        expect($all)->toBeArray();
        expect($all['customer_id'])->toBe('');
        expect($all['conversion_value'])->toBe('0');
        expect($all['currency_code'])->toBe('SEK');
    });

    it('merges stored values with defaults', function () {
        Functions\expect('get_option')
            ->once()
            ->with(Settings::OPTION_KEY, [])
            ->andReturn(['customer_id' => '1234567890']);

        $settings = new Settings();
        $all      = $settings->get_all();

        expect($all['customer_id'])->toBe('1234567890');
        expect($all['currency_code'])->toBe('SEK');
    });

    it('falls back to defaults when stored option is not an array', function () {
        Functions\expect('get_option')
            ->once()
            ->with(Settings::OPTION_KEY, [])
            ->andReturn(false);

        $settings = new Settings();
        $all      = $settings->get_all();

        expect($all)->toBeArray();
        expect($all['customer_id'])->toBe('');
        expect($all['currency_code'])->toBe('SEK');
    });

});

// ─── get() ───

describe('Settings::get()', function () {

    it('returns a specific stored value', function () {
        Functions\expect('get_option')
            ->once()
            ->with(Settings::OPTION_KEY, [])
            ->andReturn(['developer_token' => 'abc123']);

        $settings = new Settings();

        expect($settings->get('developer_token'))->toBe('abc123');
    });

    it('returns default for an unknown key', function () {
        Functions\expect('get_option')
            ->once()
            ->with(Settings::OPTION_KEY, [])
            ->andReturn([]);

        $settings = new Settings();

        expect($settings->get('nonexistent_key', 'fallback'))->toBe('fallback');
    });

    it('returns DEFAULTS value for a known key, not the method default', function () {
        Functions\expect('get_option')
            ->once()
            ->with(Settings::OPTION_KEY, [])
            ->andReturn([]);

        $settings = new Settings();

        // 'customer_id' has DEFAULTS value '' which is not null, so the
        // $default parameter ('my_fallback') must NOT be used.
        expect($settings->get('customer_id', 'my_fallback'))->toBe('');
    });

});

// ─── update() ───

describe('Settings::update()', function () {

    it('merges new values with existing settings', function () {
        $saved = null;

        Functions\expect('get_option')
            ->once()
            ->with(Settings::OPTION_KEY, [])
            ->andReturn(['customer_id' => '1234567890']);

        Functions\expect('update_option')
            ->once()
            ->withArgs(function (string $key, array $value) use (&$saved): bool {
                $saved = $value;
                return $key === Settings::OPTION_KEY;
            })
            ->andReturn(true);

        $settings = new Settings();
        $settings->update(['developer_token' => 'new_token']);

        expect($saved['customer_id'])->toBe('1234567890');
        expect($saved['developer_token'])->toBe('new_token');
    });

    it('overwrites an existing value', function () {
        $saved = null;

        Functions\expect('get_option')
            ->once()
            ->with(Settings::OPTION_KEY, [])
            ->andReturn(['customer_id' => 'old_value']);

        Functions\expect('update_option')
            ->once()
            ->withArgs(function (string $key, array $value) use (&$saved): bool {
                $saved = $value;
                return $key === Settings::OPTION_KEY;
            })
            ->andReturn(true);

        $settings = new Settings();
        $settings->update(['customer_id' => 'new_value']);

        expect($saved['customer_id'])->toBe('new_value');
    });

    it('discards unknown keys', function () {
        $saved = null;

        Functions\expect('get_option')
            ->once()
            ->with(Settings::OPTION_KEY, [])
            ->andReturn([]);

        Functions\expect('update_option')
            ->once()
            ->withArgs(function (string $key, array $value) use (&$saved): bool {
                $saved = $value;
                return $key === Settings::OPTION_KEY;
            })
            ->andReturn(true);

        $settings = new Settings();
        $settings->update(['bogus_key' => 'should_be_dropped']);

        expect($saved)->not->toHaveKey('bogus_key');
    });

    it('preserves all default keys in the saved array', function () {
        $saved = null;

        Functions\expect('get_option')
            ->once()
            ->with(Settings::OPTION_KEY, [])
            ->andReturn([]);

        Functions\expect('update_option')
            ->once()
            ->withArgs(function (string $key, array $value) use (&$saved): bool {
                $saved = $value;
                return $key === Settings::OPTION_KEY;
            })
            ->andReturn(true);

        $settings = new Settings();
        $settings->update(['customer_id' => '1234567890']);

        // All 12 DEFAULTS keys must be present in the persisted array.
        $expected_keys = [
            'customer_id', 'conversion_action_id',
            'conversion_action_name', 'conversion_action_category',
            'developer_token', 'client_id', 'client_secret', 'refresh_token',
            'login_customer_id', 'conversion_value', 'currency_code',
            'enable_logging',
        ];
        foreach ($expected_keys as $key) {
            expect($saved)->toHaveKey($key);
        }
        expect($saved)->toHaveCount(12);
    });

});

// ─── is_configured() ───

describe('Settings::is_configured()', function () {

    it('returns false when credentials are empty', function () {
        Functions\expect('get_option')
            ->once()
            ->with(Settings::OPTION_KEY, [])
            ->andReturn([]);

        $settings = new Settings();

        expect($settings->is_configured())->toBeFalse();
    });

    it('returns true when all required fields are filled', function () {
        Functions\expect('get_option')
            ->once()
            ->with(Settings::OPTION_KEY, [])
            ->andReturn([
                'customer_id'          => '1234567890',
                'conversion_action_id' => '987654321',
                'developer_token'      => 'dev_token_abc',
                'client_id'            => 'client.apps.googleusercontent.com',
                'client_secret'        => 'secret123',
                'refresh_token'        => 'refresh_abc',
            ]);

        $settings = new Settings();

        expect($settings->is_configured())->toBeTrue();
    });

    it('returns false when one required field is missing', function () {
        Functions\expect('get_option')
            ->once()
            ->with(Settings::OPTION_KEY, [])
            ->andReturn([
                'customer_id'          => '1234567890',
                'conversion_action_id' => '987654321',
                'developer_token'      => 'dev_token_abc',
                'client_id'            => 'client.apps.googleusercontent.com',
                'client_secret'        => '',  // Empty — not configured.
                'refresh_token'        => 'refresh_abc',
            ]);

        $settings = new Settings();

        expect($settings->is_configured())->toBeFalse();
    });

    it('returns true when optional fields are empty but required fields are filled', function () {
        Functions\expect('get_option')
            ->once()
            ->with(Settings::OPTION_KEY, [])
            ->andReturn([
                'customer_id'          => '1234567890',
                'conversion_action_id' => '987654321',
                'developer_token'      => 'dev_token_abc',
                'client_id'            => 'client.apps.googleusercontent.com',
                'client_secret'        => 'secret123',
                'refresh_token'        => 'refresh_abc',
                'login_customer_id'    => '',  // Optional — empty is fine.
                'conversion_value'     => '0', // Optional — default is fine.
                'currency_code'        => '',  // Optional — empty is fine.
            ]);

        $settings = new Settings();

        expect($settings->is_configured())->toBeTrue();
    });

});
