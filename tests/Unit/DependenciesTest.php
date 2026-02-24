<?php
/**
 * Unit tests for Dependencies.
 *
 * @package Tests\Unit
 * @since   0.1.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution_Gads\Dependencies;
use Brain\Monkey\Functions;

// ─── guard_activation() ───

describe('Dependencies::guard_activation()', function () {

    it('does nothing when core plugin is active', function () {
        Functions\expect('is_plugin_active')
            ->once()
            ->with('kntnt-ad-attribution/kntnt-ad-attribution.php')
            ->andReturn(true);

        Functions\expect('wp_die')->never();

        // Dependencies constructor hooks add_filter, so stub it.
        Functions\when('add_filter')->justReturn(true);

        $deps = new Dependencies('/tmp/fake-addon.php');
        $deps->guard_activation();

        expect(true)->toBeTrue();
    });

    it('calls wp_die when core plugin is not active', function () {
        Functions\expect('is_plugin_active')
            ->once()
            ->with('kntnt-ad-attribution/kntnt-ad-attribution.php')
            ->andReturn(false);

        $called = false;
        Functions\expect('wp_die')
            ->once()
            ->andReturnUsing(function () use (&$called) {
                $called = true;
            });

        Functions\when('add_filter')->justReturn(true);

        $deps = new Dependencies('/tmp/fake-addon.php');
        $deps->guard_activation();

        expect($called)->toBeTrue();
    });

});

// ─── protect_core_deactivate_link() ───

describe('Dependencies::protect_core_deactivate_link()', function () {

    it('replaces deactivate link with "Required by" notice', function () {
        Functions\when('add_filter')->justReturn(true);

        Functions\expect('get_plugin_data')
            ->once()
            ->andReturn(['Name' => 'Kntnt Ad Attribution for Google Ads']);

        $deps = new Dependencies('/tmp/fake-addon.php');

        $links = [
            'activate' => '<a href="#">Activate</a>',
            'deactivate' => '<a href="#">Deactivate</a>',
        ];

        $result = $deps->protect_core_deactivate_link($links);

        expect($result['deactivate'])->toContain('Required by');
        expect($result['deactivate'])->toContain('Kntnt Ad Attribution for Google Ads');
        expect($result['deactivate'])->not->toContain('<a href');
    });

    it('returns links unchanged when deactivate key is absent', function () {
        Functions\when('add_filter')->justReturn(true);

        $deps = new Dependencies('/tmp/fake-addon.php');

        $links = ['activate' => '<a href="#">Activate</a>'];

        $result = $deps->protect_core_deactivate_link($links);

        expect($result)->toBe($links);
    });

    it('falls back to directory name when plugin Name is empty', function () {
        Functions\when('add_filter')->justReturn(true);

        Functions\expect('get_plugin_data')
            ->once()
            ->andReturn(['Name' => '']);

        $deps = new Dependencies('/tmp/my-addon-dir/my-addon.php');

        $links = ['deactivate' => '<a href="#">Deactivate</a>'];

        $result = $deps->protect_core_deactivate_link($links);

        expect($result['deactivate'])->toContain('my-addon-dir');
    });

});

// ─── prevent_core_deactivation() ───

describe('Dependencies::prevent_core_deactivation()', function () {

    it('re-adds core plugin when it is being removed but add-on stays active', function () {
        Functions\when('add_filter')->justReturn(true);

        Functions\expect('plugin_basename')
            ->once()
            ->andReturn('kntnt-ad-attribution-gads/kntnt-ad-attribution-gads.php');

        $deps = new Dependencies('/tmp/kntnt-ad-attribution-gads/kntnt-ad-attribution-gads.php');

        $old_value = [
            'kntnt-ad-attribution/kntnt-ad-attribution.php',
            'kntnt-ad-attribution-gads/kntnt-ad-attribution-gads.php',
        ];

        // New value has core removed but add-on still present.
        $new_value = [
            'kntnt-ad-attribution-gads/kntnt-ad-attribution-gads.php',
        ];

        $result = $deps->prevent_core_deactivation($new_value, $old_value);

        expect($result)->toContain('kntnt-ad-attribution/kntnt-ad-attribution.php');
        expect($result)->toContain('kntnt-ad-attribution-gads/kntnt-ad-attribution-gads.php');
    });

    it('allows core removal when add-on is also being removed', function () {
        Functions\when('add_filter')->justReturn(true);

        Functions\expect('plugin_basename')
            ->once()
            ->andReturn('kntnt-ad-attribution-gads/kntnt-ad-attribution-gads.php');

        $deps = new Dependencies('/tmp/kntnt-ad-attribution-gads/kntnt-ad-attribution-gads.php');

        $old_value = [
            'kntnt-ad-attribution/kntnt-ad-attribution.php',
            'kntnt-ad-attribution-gads/kntnt-ad-attribution-gads.php',
        ];

        // Both plugins being removed.
        $new_value = [];

        $result = $deps->prevent_core_deactivation($new_value, $old_value);

        expect($result)->not->toContain('kntnt-ad-attribution/kntnt-ad-attribution.php');
    });

    it('does nothing when core was never in old_value', function () {
        Functions\when('add_filter')->justReturn(true);

        Functions\expect('plugin_basename')
            ->once()
            ->andReturn('kntnt-ad-attribution-gads/kntnt-ad-attribution-gads.php');

        $deps = new Dependencies('/tmp/kntnt-ad-attribution-gads/kntnt-ad-attribution-gads.php');

        // Core was never active — only the add-on was.
        $old_value = [
            'kntnt-ad-attribution-gads/kntnt-ad-attribution-gads.php',
        ];

        $new_value = [
            'kntnt-ad-attribution-gads/kntnt-ad-attribution-gads.php',
        ];

        $result = $deps->prevent_core_deactivation($new_value, $old_value);

        expect($result)->toBe($new_value);
        expect($result)->not->toContain('kntnt-ad-attribution/kntnt-ad-attribution.php');
    });

    it('does nothing when core is already present in new_value', function () {
        Functions\when('add_filter')->justReturn(true);

        Functions\expect('plugin_basename')
            ->once()
            ->andReturn('kntnt-ad-attribution-gads/kntnt-ad-attribution-gads.php');

        $deps = new Dependencies('/tmp/kntnt-ad-attribution-gads/kntnt-ad-attribution-gads.php');

        $old_value = [
            'kntnt-ad-attribution/kntnt-ad-attribution.php',
            'kntnt-ad-attribution-gads/kntnt-ad-attribution-gads.php',
        ];

        // Both still present — no change needed.
        $new_value = [
            'kntnt-ad-attribution/kntnt-ad-attribution.php',
            'kntnt-ad-attribution-gads/kntnt-ad-attribution-gads.php',
        ];

        $result = $deps->prevent_core_deactivation($new_value, $old_value);

        expect($result)->toBe($new_value);
    });

    it('returns non-array values unchanged', function () {
        Functions\when('add_filter')->justReturn(true);

        $deps = new Dependencies('/tmp/fake-addon.php');

        expect($deps->prevent_core_deactivation(false, []))->toBeFalse();
        expect($deps->prevent_core_deactivation([], 'not-array'))->toBe([]);
    });

});
