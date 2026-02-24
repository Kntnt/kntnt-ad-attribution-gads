<?php
/**
 * Unit tests for Settings_Page.
 *
 * @package Tests\Unit
 * @since   0.2.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution_Gads\Settings;
use Kntnt\Ad_Attribution_Gads\Settings_Page;
use Brain\Monkey\Functions;

// ─── sanitize_settings() ───

describe('Settings_Page::sanitize_settings()', function () {

    beforeEach(function () {
        // Settings_Page constructor calls is_admin() and add_action().
        // Stub them so the constructor doesn't fail.
        Functions\when('is_admin')->justReturn(false);

        $this->settings = Mockery::mock(Settings::class);
        $this->page     = new Settings_Page($this->settings);
    });

    it('strips dashes from customer_id', function () {
        $result = $this->page->sanitize_settings([
            'customer_id' => '123-456-7890',
        ]);

        expect($result['customer_id'])->toBe('1234567890');
    });

    it('strips dashes from login_customer_id', function () {
        $result = $this->page->sanitize_settings([
            'login_customer_id' => '987-654-3210',
        ]);

        expect($result['login_customer_id'])->toBe('9876543210');
    });

    it('trims whitespace from all values', function () {
        $result = $this->page->sanitize_settings([
            'developer_token' => '  abc123  ',
            'client_id'       => '  some.client.id  ',
        ]);

        expect($result['developer_token'])->toBe('abc123');
        expect($result['client_id'])->toBe('some.client.id');
    });

    it('keeps valid conversion_value', function () {
        $result = $this->page->sanitize_settings([
            'conversion_value' => '49.99',
        ]);

        expect($result['conversion_value'])->toBe('49.99');
    });

    it('sets invalid conversion_value to zero', function () {
        $result = $this->page->sanitize_settings([
            'conversion_value' => 'not_a_number',
        ]);

        expect($result['conversion_value'])->toBe('0');
    });

    it('sets negative conversion_value to zero', function () {
        $result = $this->page->sanitize_settings([
            'conversion_value' => '-5.00',
        ]);

        expect($result['conversion_value'])->toBe('0');
    });

    it('casts non-string values to string', function () {
        $result = $this->page->sanitize_settings([
            'conversion_value' => 50,
        ]);

        expect($result['conversion_value'])->toBe('50');
    });

    it('accepts zero as a valid conversion_value', function () {
        $result = $this->page->sanitize_settings([
            'conversion_value' => '0',
        ]);

        expect($result['conversion_value'])->toBe('0');
    });

    it('passes customer_id without dashes through unchanged', function () {
        $result = $this->page->sanitize_settings([
            'customer_id' => '1234567890',
        ]);

        expect($result['customer_id'])->toBe('1234567890');
    });

    it('handles non-array input gracefully', function () {
        $result = $this->page->sanitize_settings(null);

        expect($result)->toBeArray();
        expect($result)->toBeEmpty();
    });

});

// ─── Constructor hook registration ───

describe('Settings_Page constructor', function () {

    it('registers admin_menu and admin_init hooks when is_admin is true', function () {
        $hooks = [];

        Functions\when('is_admin')->justReturn(true);
        Functions\when('add_action')->alias(function (string $hook) use (&$hooks): void {
            $hooks[] = $hook;
        });

        $settings = Mockery::mock(Settings::class);
        new Settings_Page($settings);

        expect($hooks)->toContain('admin_menu');
        expect($hooks)->toContain('admin_init');
    });

    it('does not register hooks when is_admin is false', function () {
        $hooks = [];

        Functions\when('is_admin')->justReturn(false);
        Functions\when('add_action')->alias(function (string $hook) use (&$hooks): void {
            $hooks[] = $hook;
        });

        $settings = Mockery::mock(Settings::class);
        new Settings_Page($settings);

        expect($hooks)->toBeEmpty();
    });

});

// ─── add_page() ───

describe('Settings_Page::add_page()', function () {

    it('registers the options page with manage_options capability', function () {
        Functions\when('is_admin')->justReturn(false);

        $captured = [];

        Functions\when('add_options_page')->alias(
            function () use (&$captured): void {
                $captured = func_get_args();
            },
        );

        $settings = Mockery::mock(Settings::class);
        $page     = new Settings_Page($settings);
        $page->add_page();

        // args: page_title, menu_title, capability, menu_slug, callback
        expect($captured[2])->toBe('manage_options');
        expect($captured[3])->toBe('kntnt-ad-attr-gads');
    });

});

// ─── register_settings() ───

describe('Settings_Page::register_settings()', function () {

    it('registers the setting with the correct option key and sanitize callback', function () {
        Functions\when('is_admin')->justReturn(false);

        $captured_args = null;

        Functions\expect('register_setting')
            ->once()
            ->withArgs(function (string $group, string $option, array $args) use (&$captured_args): bool {
                $captured_args = $args;
                return $option === 'kntnt_ad_attr_gads_settings';
            });

        // Stub the remaining Settings API calls made by register_settings().
        Functions\expect('add_settings_section')->twice();
        Functions\expect('add_settings_field')->times(9);

        $settings = Mockery::mock(Settings::class);
        $page     = new Settings_Page($settings);
        $page->register_settings();

        expect($captured_args['sanitize_callback'])->toBe([$page, 'sanitize_settings']);
    });

});
