<?php
/**
 * Unit tests for the minimum core version gate in the main plugin file.
 *
 * Boots the real kntnt-ad-attribution-gads.php: includes it, captures the
 * `plugins_loaded` callback it registers and runs that callback with the
 * core plugin stub reporting the version under test.
 *
 * @package Tests\Unit
 * @since   1.11.0
 */

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Kntnt\Ad_Attribution\Logger;
use Kntnt\Ad_Attribution_Gads\Connection_Monitor;
use Kntnt\Ad_Attribution_Gads\Plugin;

/**
 * Includes the main plugin file and runs its `plugins_loaded` callback.
 *
 * @param string|Throwable $core_version Version the core plugin reports, or
 *                                       what its get_version() throws.
 *
 * @return list<callable> The `admin_notices` callbacks added while booting.
 */
function boot_against_core(string|Throwable $core_version): array {
    \Patchwork\redefine(
        'Kntnt\Ad_Attribution\Plugin::get_version',
        fn () => $core_version instanceof Throwable ? throw $core_version : $core_version,
    );
    \Patchwork\redefine(
        'Kntnt\Ad_Attribution\Plugin::get_instance',
        fn () => (object) ['logger' => Mockery::mock(Logger::class)],
    );

    Functions\when('register_activation_hook')->justReturn();
    Functions\when('register_deactivation_hook')->justReturn();
    Functions\when('plugin_basename')->justReturn('kntnt-ad-attribution-gads/kntnt-ad-attribution-gads.php');
    Functions\when('is_admin')->justReturn(false);

    // The bootstrap's own callback is the first one added to the hook;
    // Plugin adds a migrator callback to the same hook once initialized.
    $bootstrap = null;
    Actions\expectAdded('plugins_loaded')->whenHappen(function (callable $callback) use (&$bootstrap): void {
        $bootstrap ??= $callback;
    });

    // Capture only; whether a notice is added is for each test to assert.
    $notices = [];
    Actions\expectAdded('admin_notices')->zeroOrMoreTimes()->whenHappen(function (callable $callback) use (&$notices): void {
        $notices[] = $callback;
    });

    // Brain Monkey 2.7 tells static closures apart by trying to bind them,
    // which PHP 8.5 reports as a warning despite the `@`. The bootstrap's
    // closures are static on purpose, so swallow exactly that warning and
    // hand every other one to PHPUnit's handler.
    $previous = set_error_handler(
        function (int $errno, string $message, string $file = '', int $line = 0) use (&$previous): bool {
            if (str_contains($message, 'Cannot bind an instance to a static closure')) {
                return true;
            }
            return $previous !== null && (bool) $previous($errno, $message, $file, $line);
        },
        E_WARNING,
    );

    try {
        require dirname(__DIR__, 2) . '/kntnt-ad-attribution-gads.php';
        $bootstrap();
    } finally {
        restore_error_handler();
    }

    return $notices;
}

/**
 * Renders an `admin_notices` callback and returns its output.
 *
 * @param callable $notice An `admin_notices` callback returned by
 *                         boot_against_core().
 *
 * @return string The HTML the callback prints.
 */
function render_notice(callable $notice): string {
    ob_start();
    $notice();
    return (string) ob_get_clean();
}

describe('Core version gate', function () {

    // The bootstrap creates the singleton and sets the plugin file, so both
    // are reset around each test to keep other test files unaffected.
    beforeEach(function () {
        $this->plugin_file = (new ReflectionProperty(Plugin::class, 'plugin_file'))->getValue();
        set_plugin_statics(['instance' => null]);
    });

    afterEach(function () {
        set_plugin_statics(['instance' => null, 'plugin_file' => $this->plugin_file]);
    });

    it('leaves the add-on inert when the core is older than 1.10.0', function (string $core_version) {
        boot_against_core($core_version);

        expect(has_filter('kntnt_ad_attr_click_id_capturers'))->toBeFalse();
        expect(has_filter('kntnt_ad_attr_conversion_reporters'))->toBeFalse();
        expect(has_action(Connection_Monitor::WEEKLY_HOOK))->toBeFalse();
    })->with([
        'last release before the fix' => '1.9.1',
        'no version in the header'    => '',
    ]);

    it('tells admins the required and the installed core version', function () {
        $notices = boot_against_core('1.9.1');

        expect($notices)->toHaveCount(1);
        expect(render_notice($notices[0]))->toContain('1.10.0')->toContain('1.9.1');
    });

    it('tells admins the installed core version is unknown when its header has none', function () {
        $notices = boot_against_core('');

        expect($notices)->toHaveCount(1);
        expect(render_notice($notices[0]))
            ->toContain('1.10.0')
            ->toContain('an unknown version')
            ->not->toContain(' .');
    });

    it('catches an error from reading the core version like any other initialization error', function () {
        $logged = [];
        \Patchwork\redefine('error_log', function (string $message) use (&$logged): bool {
            $logged[] = $message;
            return true;
        });

        $notices = boot_against_core(new RuntimeException('Core header unreadable'));

        expect($logged)->toHaveCount(1);
        expect($logged[0])->toContain('Core header unreadable');
        expect($notices)->toHaveCount(1);
        expect(render_notice($notices[0]))->toContain('failed to initialize: Core header unreadable');
        expect(has_filter('kntnt_ad_attr_click_id_capturers'))->toBeFalse();
        expect(has_filter('kntnt_ad_attr_conversion_reporters'))->toBeFalse();
    });

    it('initializes as before when the core is 1.10.0 or newer', function (string $core_version) {
        $notices = boot_against_core($core_version);

        expect(has_filter('kntnt_ad_attr_click_id_capturers'))->toBeTrue();
        expect(has_filter('kntnt_ad_attr_conversion_reporters'))->toBeTrue();
        expect(has_action(Connection_Monitor::WEEKLY_HOOK))->toBeTrue();
        expect($notices)->toBe([]);
    })->with([
        'the first release with the fix' => '1.10.0',
        'a later patch release'          => '1.10.1',
        'a later major release'          => '2.0.0',
    ]);

});
