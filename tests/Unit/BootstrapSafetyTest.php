<?php
/**
 * Unit tests for the try-catch safety wrapper in the main plugin file.
 *
 * Simulates the bootstrap pattern from kntnt-ad-attribution-gads.php to
 * verify that fatal errors during Plugin::get_instance() are caught and
 * handled gracefully instead of taking down the entire site.
 *
 * @package Tests\Unit
 * @since   1.0.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution_Gads\Plugin;

/**
 * Resets the Plugin singleton so each test starts with a clean slate.
 */
function reset_plugin_singleton(): void {
    $ref  = new ReflectionClass(Plugin::class);
    $prop = $ref->getProperty('instance');
    $prop->setValue(null, null);
}

/**
 * Simulates the safe initialization pattern from the main plugin file.
 *
 * Wraps Plugin::get_instance() in try-catch exactly as the bootstrap does,
 * returning the caught Throwable or null on success.
 */
function simulate_safe_init(): ?\Throwable {
    try {
        Plugin::get_instance();
    } catch (\Throwable $e) {
        return $e;
    }
    return null;
}

// ─── Bootstrap safety wrapper ───

describe('Bootstrap safety wrapper', function () {

    beforeEach(function () {
        reset_plugin_singleton();
    });

    afterEach(function () {
        reset_plugin_singleton();
        \Patchwork\restoreAll();
    });

    it('catches RuntimeException during initialization', function () {

        // Make get_instance() throw a RuntimeException.
        \Patchwork\redefine(
            'Kntnt\Ad_Attribution_Gads\Plugin::get_instance',
            function () {
                throw new \RuntimeException('GitHub API unavailable');
            },
        );

        $caught = simulate_safe_init();

        expect($caught)->toBeInstanceOf(\RuntimeException::class);
        expect($caught->getMessage())->toBe('GitHub API unavailable');
    });

    it('catches TypeError during initialization', function () {

        // Make get_instance() throw a TypeError.
        \Patchwork\redefine(
            'Kntnt\Ad_Attribution_Gads\Plugin::get_instance',
            function () {
                throw new \TypeError('Expected string, got null');
            },
        );

        $caught = simulate_safe_init();

        expect($caught)->toBeInstanceOf(\TypeError::class);
        expect($caught->getMessage())->toBe('Expected string, got null');
    });

    it('catches Error during initialization', function () {

        // Simulate a fatal Error (e.g. missing class dependency).
        \Patchwork\redefine(
            'Kntnt\Ad_Attribution_Gads\Plugin::get_instance',
            function () {
                throw new \Error('Class "SomeDependency" not found');
            },
        );

        $caught = simulate_safe_init();

        expect($caught)->toBeInstanceOf(\Error::class);
        expect($caught->getMessage())->toBe('Class "SomeDependency" not found');
    });

    it('returns null when initialization succeeds', function () {

        // Make get_instance() return normally without throwing.
        \Patchwork\redefine(
            'Kntnt\Ad_Attribution_Gads\Plugin::get_instance',
            function (): Plugin {
                return Mockery::mock(Plugin::class);
            },
        );

        $caught = simulate_safe_init();

        expect($caught)->toBeNull();
    });

});
