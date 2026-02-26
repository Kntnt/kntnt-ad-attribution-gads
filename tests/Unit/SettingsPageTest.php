<?php
/**
 * Unit tests for Settings_Page.
 *
 * @package Tests\Unit
 * @since   0.2.0
 */

declare(strict_types=1);

// Stub the core plugin singleton so Patchwork can redefine its static method.
// The real class lives in the core plugin which is not loaded during unit tests.
if (!class_exists('Kntnt\Ad_Attribution\Plugin')) {
    // phpcs:ignore
    class_alias(
        get_class(new class {
            /** @var mixed */
            public mixed $logger = null;

            /** @return static */
            public static function get_instance(): static {
                return new static();
            }
        }),
        'Kntnt\Ad_Attribution\Plugin',
    );
}

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

    it('preserves disabled fields from database when conversion_action_id is set', function () {
        $this->settings->shouldReceive('get_all')->once()->andReturn([
            'conversion_action_name'     => 'Offline Lead',
            'conversion_action_category' => 'SUBMIT_LEAD_FORM',
        ]);

        // Browser doesn't submit disabled fields, so they're missing from input.
        $result = $this->page->sanitize_settings([
            'conversion_action_id' => '42',
            'customer_id'          => '1234567890',
        ]);

        expect($result['conversion_action_name'])->toBe('Offline Lead');
        expect($result['conversion_action_category'])->toBe('SUBMIT_LEAD_FORM');
    });

    it('does not preserve disabled fields when conversion_action_id is empty', function () {
        // No get_all() call expected when ID is empty.
        $result = $this->page->sanitize_settings([
            'conversion_action_id'       => '',
            'conversion_action_name'     => 'My Action',
            'conversion_action_category' => 'PURCHASE',
        ]);

        expect($result['conversion_action_name'])->toBe('My Action');
        expect($result['conversion_action_category'])->toBe('PURCHASE');
    });

});

// ─── Constructor hook registration ───

describe('Settings_Page constructor', function () {

    it('registers admin hooks and REST API init when is_admin is true', function () {
        $hooks = [];

        Functions\when('is_admin')->justReturn(true);
        Functions\when('add_action')->alias(function (string $hook) use (&$hooks): void {
            $hooks[] = $hook;
        });

        $settings = Mockery::mock(Settings::class);
        new Settings_Page($settings);

        expect($hooks)->toContain('admin_menu');
        expect($hooks)->toContain('admin_init');
        expect($hooks)->toContain('admin_notices');
        expect($hooks)->toContain('admin_enqueue_scripts');
        expect($hooks)->toContain('rest_api_init');

        // AJAX hooks should no longer be registered.
        expect($hooks)->not->toContain('wp_ajax_kntnt_ad_attr_gads_test_connection');
        expect($hooks)->not->toContain('wp_ajax_kntnt_ad_attr_gads_create_conversion_action');
    });

    it('registers only rest_api_init when is_admin is false', function () {
        $hooks = [];

        Functions\when('is_admin')->justReturn(false);
        Functions\when('add_action')->alias(function (string $hook) use (&$hooks): void {
            $hooks[] = $hook;
        });

        $settings = Mockery::mock(Settings::class);
        new Settings_Page($settings);

        // REST routes are always registered (outside is_admin check).
        expect($hooks)->toContain('rest_api_init');

        // Admin-only hooks should not be registered.
        expect($hooks)->not->toContain('admin_menu');
        expect($hooks)->not->toContain('admin_init');
    });

});

// ─── add_page() ───

describe('Settings_Page::add_page()', function () {

    it('registers the options page with manage_options capability', function () {
        Functions\when('is_admin')->justReturn(false);

        $captured = [];

        Functions\when('add_options_page')->alias(
            function () use (&$captured): string {
                $captured = func_get_args();
                return 'settings_page_kntnt-ad-attr-gads';
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

        // 3 sections: API Credentials, Conversion Action, Conversion Defaults.
        // 12 fields: 6 API creds + 4 conversion action + 2 conversion defaults.
        Functions\expect('add_settings_section')->times(3);
        Functions\expect('add_settings_field')->times(12);

        $settings = Mockery::mock(Settings::class);
        $page     = new Settings_Page($settings);
        $page->register_settings();

        expect($captured_args['sanitize_callback'])->toBe([$page, 'sanitize_settings']);
    });

});

// ─── handle_test_connection() via REST ───

describe('Settings_Page::handle_test_connection()', function () {

    it('returns error when required credentials are missing', function () {
        Functions\when('is_admin')->justReturn(false);
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_unslash')->returnArg();

        $request = Mockery::mock('WP_REST_Request');
        $request->shouldReceive('get_params')->andReturn([
            'customer_id'     => '',
            'developer_token' => 'dev_token',
            'client_id'       => 'client.apps.googleusercontent.com',
            'client_secret'   => 'secret',
            'refresh_token'   => 'refresh_abc',
        ]);
        $request->shouldReceive('get_param')->andReturn('');

        $settings = Mockery::mock(Settings::class);
        $page     = new Settings_Page($settings);

        $response = $page->handle_test_connection($request);

        expect($response)->toBeInstanceOf(\WP_REST_Response::class);
        expect($response->get_status())->toBe(400);
        expect($response->get_data()['success'])->toBeFalse();
        expect($response->get_data()['message'])->toContain('required');
    });

    it('returns success on successful token refresh and GAQL verification', function () {
        Functions\when('is_admin')->justReturn(false);
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_unslash')->returnArg();

        $request = Mockery::mock('WP_REST_Request');
        $request->shouldReceive('get_params')->andReturn([
            'customer_id'       => '1234567890',
            'developer_token'   => 'dev_token',
            'client_id'         => 'client.apps.googleusercontent.com',
            'client_secret'     => 'secret',
            'refresh_token'     => 'refresh_abc',
            'login_customer_id' => '',
        ]);
        $request->shouldReceive('get_param')
            ->with('conversion_action_id')
            ->andReturn('99');

        // Stub response helpers.
        Functions\when('wp_remote_retrieve_response_code')->alias(
            fn ($response) => $response['response']['code'] ?? 0,
        );
        Functions\when('wp_remote_retrieve_body')->alias(
            fn ($response) => $response['body'] ?? '',
        );
        Functions\when('wp_json_encode')->alias(fn ($data) => json_encode($data));

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
                            ['conversionAction' => ['id' => '99', 'name' => 'Offline Lead', 'category' => 'SUBMIT_LEAD_FORM']],
                        ],
                    ]),
                ];
            });

        Functions\expect('is_wp_error')->twice()->andReturn(false);
        Functions\expect('set_transient')->once()->andReturn(true);

        // Mock the core plugin singleton used by Settings_Page to pass a logger to Google_Ads_Client.
        \Patchwork\redefine('Kntnt\Ad_Attribution\Plugin::get_instance', function () {
            return (object) ['logger' => null];
        });

        $settings = Mockery::mock(Settings::class);
        $page     = new Settings_Page($settings);

        $response = $page->handle_test_connection($request);

        expect($response)->toBeInstanceOf(\WP_REST_Response::class);
        expect($response->get_data()['success'])->toBeTrue();
        expect($response->get_data()['message'])->toContain('All credentials verified');
        expect($response->get_data()['message'])->toContain('Offline Lead');

        \Patchwork\restoreAll();
    });

});

// ─── handle_fetch_conversion_action() via REST ───

describe('Settings_Page::handle_fetch_conversion_action()', function () {

    it('returns name and category for a valid conversion action ID', function () {
        Functions\when('is_admin')->justReturn(false);
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_unslash')->returnArg();

        $request = Mockery::mock('WP_REST_Request');
        $request->shouldReceive('get_params')->andReturn([
            'customer_id'       => '1234567890',
            'developer_token'   => 'dev_token',
            'client_id'         => 'client.apps.googleusercontent.com',
            'client_secret'     => 'secret',
            'refresh_token'     => 'refresh_abc',
            'login_customer_id' => '',
        ]);
        $request->shouldReceive('get_param')
            ->with('conversion_action_id')
            ->andReturn('42');

        // Stub response helpers.
        Functions\when('wp_remote_retrieve_response_code')->alias(
            fn ($response) => $response['response']['code'] ?? 0,
        );
        Functions\when('wp_remote_retrieve_body')->alias(
            fn ($response) => $response['body'] ?? '',
        );
        Functions\when('wp_json_encode')->alias(fn ($data) => json_encode($data));

        Functions\expect('get_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_access_token')
            ->andReturn('cached_token');

        Functions\expect('wp_remote_post')
            ->once()
            ->andReturn([
                'response' => ['code' => 200],
                'body'     => json_encode([
                    'results' => [
                        ['conversionAction' => ['id' => '42', 'name' => 'Offline Lead', 'category' => 'SUBMIT_LEAD_FORM']],
                    ],
                ]),
            ]);

        Functions\expect('is_wp_error')->once()->andReturn(false);

        // Mock the core plugin singleton used by Settings_Page to pass a logger to Google_Ads_Client.
        \Patchwork\redefine('Kntnt\Ad_Attribution\Plugin::get_instance', function () {
            return (object) ['logger' => null];
        });

        $settings = Mockery::mock(Settings::class);
        $page     = new Settings_Page($settings);

        $response = $page->handle_fetch_conversion_action($request);

        expect($response)->toBeInstanceOf(\WP_REST_Response::class);
        expect($response->get_data()['success'])->toBeTrue();
        expect($response->get_data()['conversion_action_name'])->toBe('Offline Lead');
        expect($response->get_data()['conversion_action_category'])->toBe('SUBMIT_LEAD_FORM');

        \Patchwork\restoreAll();
    });

    it('returns error when conversion_action_id is empty', function () {
        Functions\when('is_admin')->justReturn(false);
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_unslash')->returnArg();

        $request = Mockery::mock('WP_REST_Request');
        $request->shouldReceive('get_params')->andReturn([
            'customer_id'       => '1234567890',
            'developer_token'   => 'dev_token',
            'client_id'         => 'client.apps.googleusercontent.com',
            'client_secret'     => 'secret',
            'refresh_token'     => 'refresh_abc',
            'login_customer_id' => '',
        ]);
        $request->shouldReceive('get_param')
            ->with('conversion_action_id')
            ->andReturn('');

        $settings = Mockery::mock(Settings::class);
        $page     = new Settings_Page($settings);

        $response = $page->handle_fetch_conversion_action($request);

        expect($response)->toBeInstanceOf(\WP_REST_Response::class);
        expect($response->get_status())->toBe(400);
        expect($response->get_data()['success'])->toBeFalse();
    });

});

// ─── enqueue_scripts() ───

describe('Settings_Page::enqueue_scripts()', function () {

    it('enqueues script only on the settings page', function () {
        Functions\when('is_admin')->justReturn(false);

        $settings = Mockery::mock(Settings::class);
        $page     = new Settings_Page($settings);

        // Simulate add_page() having set the page hook.
        Functions\when('add_options_page')->justReturn('settings_page_kntnt-ad-attr-gads');
        $page->add_page();

        // Should not enqueue on a different page.
        $enqueued = false;
        Functions\when('wp_enqueue_script')->alias(function () use (&$enqueued) {
            $enqueued = true;
        });

        $page->enqueue_scripts('edit.php');
        expect($enqueued)->toBeFalse();

        // Should enqueue on the correct page.
        Functions\when('plugins_url')->justReturn('http://example.com/js/settings-page.js');
        Functions\when('wp_localize_script')->justReturn(true);
        Functions\when('rest_url')->justReturn('http://example.com/wp-json/kntnt-ad-attr-gads/v1');
        Functions\when('wp_create_nonce')->justReturn('test_nonce');
        \Patchwork\redefine('Kntnt\Ad_Attribution_Gads\Plugin::get_version', fn () => '1.6.0');

        $page->enqueue_scripts('settings_page_kntnt-ad-attr-gads');
        expect($enqueued)->toBeTrue();
    });

});

// ─── display_credential_notice() ───

describe('Settings_Page::display_credential_notice()', function () {

    it('displays error notice when credential error transient is set', function () {
        Functions\when('is_admin')->justReturn(false);
        Functions\expect('current_user_can')
            ->once()
            ->with('manage_options')
            ->andReturn(true);
        Functions\expect('get_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_credential_error')
            ->andReturn('missing');
        Functions\when('admin_url')->justReturn('http://example.com/wp-admin/options-general.php?page=kntnt-ad-attr-gads');
        Functions\when('esc_url')->returnArg();
        Functions\when('wp_kses')->returnArg();

        $settings = Mockery::mock(Settings::class);
        $page     = new Settings_Page($settings);

        ob_start();
        $page->display_credential_notice();
        $output = ob_get_clean();

        expect($output)->toContain('notice-error');
        expect($output)->toContain('is-dismissible');
        expect($output)->toContain('options-general.php?page=kntnt-ad-attr-gads');
    });

    it('does nothing when no credential error', function () {
        Functions\when('is_admin')->justReturn(false);
        Functions\expect('current_user_can')
            ->once()
            ->with('manage_options')
            ->andReturn(true);
        Functions\expect('get_transient')
            ->once()
            ->with('kntnt_ad_attr_gads_credential_error')
            ->andReturn(false);

        $settings = Mockery::mock(Settings::class);
        $page     = new Settings_Page($settings);

        ob_start();
        $page->display_credential_notice();
        $output = ob_get_clean();

        expect($output)->toBeEmpty();
    });

    it('does nothing for non-admin users', function () {
        Functions\when('is_admin')->justReturn(false);
        Functions\expect('current_user_can')
            ->once()
            ->with('manage_options')
            ->andReturn(false);

        $settings = Mockery::mock(Settings::class);
        $page     = new Settings_Page($settings);

        ob_start();
        $page->display_credential_notice();
        $output = ob_get_clean();

        expect($output)->toBeEmpty();
    });

});

