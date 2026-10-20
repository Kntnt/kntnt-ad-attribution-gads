<?php
/**
 * Stub of the core plugin's singleton for unit tests.
 *
 * The real class lives in the core plugin, which is not loaded during unit
 * tests. The autoloader in tests/Helpers/WpStubs.php loads this file on first
 * use, during a test, so Patchwork preprocesses it and tests can redefine its
 * static methods with \Patchwork\redefine(). It lives outside tests/Helpers
 * because Pest includes every file there at boot, before Patchwork. Keeping
 * the only definition here means no test depends on file load order.
 *
 * @package Tests\Stubs
 * @since   1.11.0
 */

declare(strict_types=1);

namespace Kntnt\Ad_Attribution;

/**
 * Minimal stand-in for \Kntnt\Ad_Attribution\Plugin.
 *
 * @since 1.11.0
 */
class Plugin {

    /**
     * Core logger; tests that need one redefine get_instance().
     *
     * @var mixed
     */
    public mixed $logger = null;

    /**
     * Returns a fresh instance; tests redefine it to inject a logger.
     *
     * Typed `object` rather than `static` so a redefinition may return any
     * object exposing `logger`.
     *
     * @return object
     */
    public static function get_instance(): object {
        return new static();
    }

    /**
     * Returns the core version; tests redefine it to the version under test.
     *
     * Empty like a core whose header has no version, so a test that forgets
     * to set one sees the add-on refuse to initialize.
     *
     * @return string
     */
    public static function get_version(): string {
        return '';
    }

}
