<?php
/**
 * Unit tests for Settings_Page.
 *
 * @package Tests\Unit
 * @since   0.2.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution_Gads\Logger;
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
        $this->page     = new Settings_Page($this->settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());
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

    it('registers admin hooks and AJAX handler when is_admin is true', function () {
        $hooks = [];

        Functions\when('is_admin')->justReturn(true);
        Functions\when('add_action')->alias(function (string $hook) use (&$hooks): void {
            $hooks[] = $hook;
        });

        $settings = Mockery::mock(Settings::class);
        new Settings_Page($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());

        expect($hooks)->toContain('admin_menu');
        expect($hooks)->toContain('admin_init');
        expect($hooks)->toContain('admin_notices');
        expect($hooks)->toContain('admin_enqueue_scripts');
        expect($hooks)->toContain('wp_ajax_kntnt_ad_attr_gads_test_connection');
        expect($hooks)->toContain('admin_post_kntnt_ad_attr_gads_download_log');
        expect($hooks)->toContain('admin_post_kntnt_ad_attr_gads_clear_log');
    });

    it('does not register hooks when is_admin is false', function () {
        $hooks = [];

        Functions\when('is_admin')->justReturn(false);
        Functions\when('add_action')->alias(function (string $hook) use (&$hooks): void {
            $hooks[] = $hook;
        });

        $settings = Mockery::mock(Settings::class);
        new Settings_Page($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());

        expect($hooks)->toBeEmpty();
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
        $page     = new Settings_Page($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());
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
        // 8 credential fields + 2 conversion fields + 2 log fields = 12 total.
        Functions\expect('add_settings_section')->times(3);
        Functions\expect('add_settings_field')->times(12);

        $settings = Mockery::mock(Settings::class);
        $page     = new Settings_Page($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());
        $page->register_settings();

        expect($captured_args['sanitize_callback'])->toBe([$page, 'sanitize_settings']);
    });

});

// ─── handle_test_connection() ───

describe('Settings_Page::handle_test_connection()', function () {

    it('returns error when not configured', function () {
        Functions\when('is_admin')->justReturn(false);
        Functions\expect('check_ajax_referer')
            ->once()
            ->with('kntnt_ad_attr_gads_test_connection', 'nonce');
        Functions\expect('current_user_can')
            ->once()
            ->with('manage_options')
            ->andReturn(true);

        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('is_configured')->once()->andReturn(false);

        $error_sent = null;
        Functions\expect('wp_send_json_error')
            ->once()
            ->withArgs(function (array $data) use (&$error_sent) {
                $error_sent = $data;
                return true;
            });

        $page = new Settings_Page($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());
        $page->handle_test_connection();

        expect($error_sent['message'])->toContain('required credentials');
    });

    it('returns success on successful token refresh and GAQL verification', function () {
        Functions\when('is_admin')->justReturn(false);
        Functions\expect('check_ajax_referer')
            ->once()
            ->with('kntnt_ad_attr_gads_test_connection', 'nonce');
        Functions\expect('current_user_can')
            ->once()
            ->with('manage_options')
            ->andReturn(true);

        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('is_configured')->once()->andReturn(true);
        $settings->shouldReceive('get_all')->once()->andReturn([
            'customer_id'          => '1234567890',
            'conversion_action_id' => '99',
            'developer_token'      => 'dev_token',
            'client_id'            => 'client.apps.googleusercontent.com',
            'client_secret'        => 'secret',
            'refresh_token'        => 'refresh_abc',
            'login_customer_id'    => '',
            'conversion_value'     => '1000',
            'currency_code'        => 'SEK',
        ]);

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
                            ['conversionAction' => ['id' => '99', 'name' => 'Offline Lead']],
                        ],
                    ]),
                ];
            });

        Functions\expect('is_wp_error')->twice()->andReturn(false);
        Functions\expect('set_transient')->once()->andReturn(true);

        $success_sent = null;
        Functions\expect('wp_send_json_success')
            ->once()
            ->withArgs(function (array $data) use (&$success_sent) {
                $success_sent = $data;
                return true;
            });

        $page = new Settings_Page($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());
        $page->handle_test_connection();

        expect($success_sent['message'])->toContain('All credentials verified');
        expect($success_sent['message'])->toContain('Offline Lead');
    });

});

// ─── enqueue_scripts() ───

describe('Settings_Page::enqueue_scripts()', function () {

    it('enqueues script only on the settings page', function () {
        Functions\when('is_admin')->justReturn(false);

        $settings = Mockery::mock(Settings::class);
        $page     = new Settings_Page($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());

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
        Functions\when('admin_url')->justReturn('http://example.com/wp-admin/admin-ajax.php');
        Functions\when('wp_create_nonce')->justReturn('test_nonce');

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
        $page     = new Settings_Page($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());

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
        $page     = new Settings_Page($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());

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
        $page     = new Settings_Page($settings, Mockery::mock(Logger::class)->shouldIgnoreMissing());

        ob_start();
        $page->display_credential_notice();
        $output = ob_get_clean();

        expect($output)->toBeEmpty();
    });

});

// ─── render_log_checkbox_field() ───

describe('Settings_Page::render_log_checkbox_field()', function () {

    it('renders checked checkbox when logging is enabled', function () {
        Functions\when('is_admin')->justReturn(false);
        Functions\when('checked')->alias(fn ($value, $current, $echo) => $value == $current ? ' checked="checked"' : '');

        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get')->with('enable_logging')->once()->andReturn('1');

        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('get_relative_path')->andReturn('wp-content/uploads/kntnt-ad-attr-gads/kntnt-ad-attr-gads.log');

        $page = new Settings_Page($settings, $logger);

        ob_start();
        $page->render_log_checkbox_field();
        $output = ob_get_clean();

        expect($output)->toContain('type="checkbox"');
        expect($output)->toContain('checked="checked"');
        expect($output)->toContain('enable_logging');
        expect($output)->toContain('kntnt-ad-attr-gads.log');
    });

    it('renders unchecked checkbox when logging is disabled', function () {
        Functions\when('is_admin')->justReturn(false);
        Functions\when('checked')->alias(fn ($value, $current, $echo) => $value == $current ? ' checked="checked"' : '');

        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get')->with('enable_logging')->once()->andReturn('');

        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('get_relative_path')->andReturn('wp-content/uploads/kntnt-ad-attr-gads/kntnt-ad-attr-gads.log');

        $page = new Settings_Page($settings, $logger);

        ob_start();
        $page->render_log_checkbox_field();
        $output = ob_get_clean();

        expect($output)->toContain('type="checkbox"');
        expect($output)->not->toContain('checked="checked"');
    });

});

// ─── render_log_actions_field() ───

describe('Settings_Page::render_log_actions_field()', function () {

    it('renders enabled buttons and file size when log exists', function () {
        Functions\when('is_admin')->justReturn(false);
        Functions\when('wp_nonce_url')->returnArg();
        Functions\when('admin_url')->returnArg();
        Functions\when('size_format')->justReturn('1.2 KB');

        \Patchwork\redefine('filesize', function () {
            return 1234;
        });

        $settings = Mockery::mock(Settings::class);
        $logger   = Mockery::mock(Logger::class);
        $logger->shouldReceive('exists')->andReturn(true);
        $logger->shouldReceive('get_path')->andReturn('/tmp/kntnt-ad-attr-gads.log');

        $page = new Settings_Page($settings, $logger);

        ob_start();
        $page->render_log_actions_field();
        $output = ob_get_clean();

        expect($output)->toContain('1.2 KB');
        expect($output)->toContain('Download Log');
        expect($output)->toContain('Clear Log');
        expect($output)->not->toContain('disabled');

        \Patchwork\restoreAll();
    });

    it('renders disabled buttons when no log exists', function () {
        Functions\when('is_admin')->justReturn(false);
        Functions\when('wp_nonce_url')->returnArg();
        Functions\when('admin_url')->returnArg();

        $settings = Mockery::mock(Settings::class);
        $logger   = Mockery::mock(Logger::class);
        $logger->shouldReceive('exists')->andReturn(false);
        $logger->shouldReceive('get_path')->andReturn('/tmp/kntnt-ad-attr-gads.log');

        $page = new Settings_Page($settings, $logger);

        ob_start();
        $page->render_log_actions_field();
        $output = ob_get_clean();

        expect($output)->toContain('Download Log');
        expect($output)->toContain('Clear Log');
        expect($output)->toContain('disabled');
        expect($output)->not->toContain('Current size');
    });

});

// ─── handle_download_log() ───

describe('Settings_Page::handle_download_log()', function () {

    it('sends log file as download attachment', function () {
        Functions\when('is_admin')->justReturn(false);
        Functions\expect('check_admin_referer')->once()->with('kntnt_ad_attr_gads_download_log');
        Functions\expect('current_user_can')->once()->with('manage_options')->andReturn(true);
        Functions\when('nocache_headers')->justReturn(null);

        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('exists')->once()->andReturn(true);
        $logger->shouldReceive('get_path')->andReturn('/tmp/kntnt-ad-attr-gads.log');

        $settings = Mockery::mock(Settings::class);
        $page     = new Settings_Page($settings, $logger);

        $headers = [];
        \Patchwork\redefine('header', function (string $h) use (&$headers) {
            $headers[] = $h;
        });
        \Patchwork\redefine('filesize', fn () => 5678);

        $readfile_path = null;
        \Patchwork\redefine('readfile', function (string $path) use (&$readfile_path) {
            $readfile_path = $path;
            return 5678;
        });
        \Patchwork\redefine('exit', function () {});

        $page->handle_download_log();

        expect($headers)->toContain('Content-Type: text/plain');
        expect($headers)->toContain('Content-Disposition: attachment; filename="kntnt-ad-attr-gads.log"');
        expect($headers)->toContain('Content-Length: 5678');
        expect($readfile_path)->toBe('/tmp/kntnt-ad-attr-gads.log');

        \Patchwork\restoreAll();
    });

});

// ─── handle_clear_log() ───

describe('Settings_Page::handle_clear_log()', function () {

    it('clears log and redirects to settings page', function () {
        Functions\when('is_admin')->justReturn(false);
        Functions\expect('check_admin_referer')->once()->with('kntnt_ad_attr_gads_clear_log');
        Functions\expect('current_user_can')->once()->with('manage_options')->andReturn(true);

        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('clear')->once();

        $redirect_url = null;
        Functions\expect('wp_safe_redirect')->once()->withArgs(function (string $url) use (&$redirect_url) {
            $redirect_url = $url;
            return true;
        });
        Functions\when('admin_url')->alias(fn ($path) => 'http://example.com/wp-admin/' . $path);

        \Patchwork\redefine('exit', function () {});

        $settings = Mockery::mock(Settings::class);
        $page     = new Settings_Page($settings, $logger);
        $page->handle_clear_log();

        expect($redirect_url)->toContain('options-general.php?page=kntnt-ad-attr-gads');

        \Patchwork\restoreAll();
    });

});
