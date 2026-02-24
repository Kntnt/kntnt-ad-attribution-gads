<?php
/**
 * Unit tests for Migrator.
 *
 * @package Tests\Unit
 * @since   0.1.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution_Gads\Migrator;
use Kntnt\Ad_Attribution_Gads\Plugin;
use Brain\Monkey\Functions;
use Tests\Helpers\TestFactory;

/**
 * Resets Plugin static state so each test starts clean.
 */
function resetPluginStatics(): void {
    $ref = new ReflectionClass(Plugin::class);

    $pluginData = $ref->getProperty('plugin_data');
    $pluginData->setValue(null, null);

    $pluginFile = $ref->getProperty('plugin_file');
    $pluginFile->setValue(null, null);

    $pluginSlug = $ref->getProperty('plugin_slug');
    $pluginSlug->setValue(null, null);

    // Also reset the singleton instance to prevent cross-test leakage.
    $instance = $ref->getProperty('instance');
    $instance->setValue(null, null);
}

describe('Migrator::run()', function () {

    beforeEach(function () {
        resetPluginStatics();
    });

    afterEach(function () {
        resetPluginStatics();
    });

    it('does nothing when stored version >= current', function () {
        Plugin::set_plugin_file('/tmp/fake-plugin.php');

        Functions\expect('get_plugin_data')
            ->once()
            ->andReturn(['Version' => '0.1.0']);

        Functions\expect('get_option')
            ->once()
            ->with('kntnt_ad_attr_gads_version', '0.0.0')
            ->andReturn('0.1.0');

        // update_option should NOT be called.
        Functions\expect('update_option')->never();

        (new Migrator())->run();

        // Explicit assertion so Pest does not flag this test as risky.
        expect(true)->toBeTrue();
    });

    it('updates version option after successful run', function () {
        Plugin::set_plugin_file('/tmp/fake-plugin.php');

        Functions\expect('get_plugin_data')
            ->once()
            ->andReturn(['Version' => '0.2.0']);

        Functions\expect('get_option')
            ->once()
            ->with('kntnt_ad_attr_gads_version', '0.0.0')
            ->andReturn('0.1.0');

        // Plugin::get_plugin_dir() calls plugin_dir_path().
        Functions\expect('plugin_dir_path')
            ->once()
            ->andReturn('/tmp/nonexistent-dir/'); // no migrations dir = graceful skip

        Functions\expect('update_option')
            ->once()
            ->with('kntnt_ad_attr_gads_version', '0.2.0');

        $GLOBALS['wpdb'] = TestFactory::wpdb();

        (new Migrator())->run();

        // Explicit assertion so Pest does not flag this test as risky.
        // Mockery verifies update_option was called with the correct version.
        expect(true)->toBeTrue();
    });

    it('handles missing migrations directory gracefully', function () {
        Plugin::set_plugin_file('/tmp/fake-plugin.php');

        Functions\expect('get_plugin_data')
            ->once()
            ->andReturn(['Version' => '1.0.0']);

        Functions\expect('get_option')
            ->once()
            ->with('kntnt_ad_attr_gads_version', '0.0.0')
            ->andReturn('0.1.0');

        Functions\expect('plugin_dir_path')
            ->once()
            ->andReturn('/tmp/dir-that-does-not-exist/');

        Functions\expect('update_option')->once();

        $GLOBALS['wpdb'] = TestFactory::wpdb();

        // Should not throw.
        (new Migrator())->run();

        // Explicit assertion so Pest does not flag this test as risky.
        expect(true)->toBeTrue();
    });

    it('executes pending migrations in version order', function () {
        // Create a temporary migrations directory with versioned files.
        $tmp_dir = sys_get_temp_dir() . '/kntnt-gads-test-migrations-' . uniqid();
        $migrations_dir = $tmp_dir . '/migrations';
        mkdir($migrations_dir, 0777, true);

        // Create migration files that record their execution order.
        file_put_contents($migrations_dir . '/0.2.0.php', '<?php return function($wpdb) { global $kntnt_gads_test_order; $kntnt_gads_test_order[] = "0.2.0"; };');
        file_put_contents($migrations_dir . '/0.3.0.php', '<?php return function($wpdb) { global $kntnt_gads_test_order; $kntnt_gads_test_order[] = "0.3.0"; };');
        file_put_contents($migrations_dir . '/0.1.0.php', '<?php return function($wpdb) { global $kntnt_gads_test_order; $kntnt_gads_test_order[] = "0.1.0"; };');

        Plugin::set_plugin_file('/tmp/fake-plugin.php');

        Functions\expect('get_plugin_data')
            ->once()
            ->andReturn(['Version' => '0.3.0']);

        Functions\expect('get_option')
            ->once()
            ->with('kntnt_ad_attr_gads_version', '0.0.0')
            ->andReturn('0.1.0');

        Functions\expect('plugin_dir_path')
            ->once()
            ->andReturn($tmp_dir . '/');

        Functions\expect('update_option')->once();

        $GLOBALS['wpdb'] = TestFactory::wpdb();
        $GLOBALS['kntnt_gads_test_order'] = [];

        (new Migrator())->run();

        // 0.1.0 should be skipped (not > stored 0.1.0), 0.2.0 runs before 0.3.0.
        expect($GLOBALS['kntnt_gads_test_order'])->toBe(['0.2.0', '0.3.0']);

        // Cleanup.
        unlink($migrations_dir . '/0.1.0.php');
        unlink($migrations_dir . '/0.2.0.php');
        unlink($migrations_dir . '/0.3.0.php');
        rmdir($migrations_dir);
        rmdir($tmp_dir);
        unset($GLOBALS['kntnt_gads_test_order']);
    });

    it('skips migrations at or before stored version', function () {
        $tmp_dir = sys_get_temp_dir() . '/kntnt-gads-test-migrations-' . uniqid();
        $migrations_dir = $tmp_dir . '/migrations';
        mkdir($migrations_dir, 0777, true);

        file_put_contents($migrations_dir . '/0.1.0.php', '<?php return function($wpdb) { global $kntnt_gads_test_order; $kntnt_gads_test_order[] = "0.1.0"; };');
        file_put_contents($migrations_dir . '/0.2.0.php', '<?php return function($wpdb) { global $kntnt_gads_test_order; $kntnt_gads_test_order[] = "0.2.0"; };');
        file_put_contents($migrations_dir . '/0.3.0.php', '<?php return function($wpdb) { global $kntnt_gads_test_order; $kntnt_gads_test_order[] = "0.3.0"; };');

        Plugin::set_plugin_file('/tmp/fake-plugin.php');

        Functions\expect('get_plugin_data')
            ->once()
            ->andReturn(['Version' => '0.3.0']);

        Functions\expect('get_option')
            ->once()
            ->with('kntnt_ad_attr_gads_version', '0.0.0')
            ->andReturn('0.2.0');

        Functions\expect('plugin_dir_path')
            ->once()
            ->andReturn($tmp_dir . '/');

        Functions\expect('update_option')->once();

        $GLOBALS['wpdb'] = TestFactory::wpdb();
        $GLOBALS['kntnt_gads_test_order'] = [];

        (new Migrator())->run();

        // Only 0.3.0 should execute (0.1.0 and 0.2.0 are <= stored version).
        expect($GLOBALS['kntnt_gads_test_order'])->toBe(['0.3.0']);

        // Cleanup.
        unlink($migrations_dir . '/0.1.0.php');
        unlink($migrations_dir . '/0.2.0.php');
        unlink($migrations_dir . '/0.3.0.php');
        rmdir($migrations_dir);
        rmdir($tmp_dir);
        unset($GLOBALS['kntnt_gads_test_order']);
    });

});
