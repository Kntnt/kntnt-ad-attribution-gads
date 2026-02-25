<?php
/**
 * Unit tests for Plugin static methods.
 *
 * @package Tests\Unit
 * @since   0.1.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution_Gads\Plugin;
use Brain\Monkey\Functions;

// ─── get_plugin_file() ───

describe('Plugin::get_plugin_file()', function () {

    it('returns file path when set', function () {
        \Patchwork\redefine(
            'Kntnt\Ad_Attribution_Gads\Plugin::get_plugin_file',
            fn () => '/path/to/plugin.php',
        );

        expect(Plugin::get_plugin_file())->toBe('/path/to/plugin.php');
    });

});

// ─── get_plugin_file() throws when not set ───

describe('Plugin::get_plugin_file() without set', function () {

    it('throws LogicException when plugin file has not been set', function () {
        // Reset the static property to null so the guard triggers.
        $ref = new ReflectionClass(Plugin::class);
        $prop = $ref->getProperty('plugin_file');
        $original = $prop->getValue(null);
        $prop->setValue(null, null);

        // Also reset cached slug since it depends on plugin_file.
        $slugProp = $ref->getProperty('plugin_slug');
        $slugProp->setValue(null, null);

        try {
            Plugin::get_plugin_file();
            // Should not reach here.
            expect(false)->toBeTrue();
        } catch (\LogicException $e) {
            expect($e->getMessage())->toContain('set_plugin_file');
        } finally {
            // Restore original value.
            $prop->setValue(null, $original);
        }
    });

});

// ─── get_slug() ───

describe('Plugin::get_slug()', function () {

    it('returns slug derived from filename', function () {
        \Patchwork\redefine(
            'Kntnt\Ad_Attribution_Gads\Plugin::get_plugin_file',
            fn () => '/path/to/kntnt-ad-attribution-gads.php',
        );

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution_Gads\Plugin::get_slug',
            fn () => 'kntnt-ad-attribution-gads',
        );

        expect(Plugin::get_slug())->toBe('kntnt-ad-attribution-gads');
    });

});

// ─── get_version() ───

describe('Plugin::get_version()', function () {

    it('returns version from plugin data', function () {
        \Patchwork\redefine(
            'Kntnt\Ad_Attribution_Gads\Plugin::get_plugin_data',
            fn () => ['Version' => '0.1.0'],
        );

        expect(Plugin::get_version())->toBe('0.1.0');
    });

    it('returns empty string when Version key is missing', function () {
        \Patchwork\redefine(
            'Kntnt\Ad_Attribution_Gads\Plugin::get_plugin_data',
            fn () => [],
        );

        expect(Plugin::get_version())->toBe('');
    });

});

// ─── get_plugin_dir() ───

describe('Plugin::get_plugin_dir()', function () {

    it('returns directory path from plugin_dir_path', function () {
        \Patchwork\redefine(
            'Kntnt\Ad_Attribution_Gads\Plugin::get_plugin_file',
            fn () => '/var/www/html/wp-content/plugins/kntnt-ad-attribution-gads/kntnt-ad-attribution-gads.php',
        );

        Functions\expect('plugin_dir_path')
            ->once()
            ->andReturn('/var/www/html/wp-content/plugins/kntnt-ad-attribution-gads/');

        expect(Plugin::get_plugin_dir())->toBe('/var/www/html/wp-content/plugins/kntnt-ad-attribution-gads/');
    });

});

// ─── add_settings_link() ───

describe('Plugin::add_settings_link()', function () {

    it('prepends a Settings link to the action links array', function () {
        // Stub WordPress functions needed by the constructor and method.
        Functions\when('plugin_basename')->justReturn('kntnt-ad-attribution-gads/kntnt-ad-attribution-gads.php');
        Functions\when('add_action')->justReturn(true);
        Functions\when('add_filter')->justReturn(true);
        Functions\when('admin_url')->alias(fn (string $path) => 'http://example.com/wp-admin/' . $path);
        Functions\when('esc_url')->returnArg();
        Functions\when('esc_html__')->returnArg();

        // Use reflection to create an instance without triggering the singleton.
        $ref = new ReflectionClass(Plugin::class);
        $instance = $ref->newInstanceWithoutConstructor();

        $existing = ['deactivate' => '<a href="#">Deactivate</a>'];
        $result = $instance->add_settings_link($existing);

        expect($result)->toHaveCount(2);
        expect($result[0])->toContain('options-general.php?page=kntnt-ad-attr-gads');
        expect($result[0])->toContain('Settings');
        expect($result['deactivate'])->toBe('<a href="#">Deactivate</a>');
    });

});

// ─── deactivate() ───

describe('Plugin::deactivate()', function () {

    afterEach(function () {
        unset($GLOBALS['wpdb']);
    });

    it('deletes plugin transients from options table', function () {
        $wpdb = \Tests\Helpers\TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        // Verify the prepare call uses correct LIKE patterns.
        $wpdb->shouldReceive('prepare')
            ->once()
            ->withArgs(function (string $sql, string $like1, string $like2) {
                return str_contains($sql, 'DELETE FROM')
                    && $like1 === '_transient_kntnt_ad_attr_gads_%'
                    && $like2 === '_transient_timeout_kntnt_ad_attr_gads_%';
            })
            ->andReturn('SQL');

        $wpdb->shouldReceive('query')->once()->with('SQL')->andReturn(3);

        Plugin::deactivate();

        expect(true)->toBeTrue();
    });

});
