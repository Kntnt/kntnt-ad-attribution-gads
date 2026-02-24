<?php
/**
 * WordPress stubs for unit testing.
 *
 * Defines constants and globals that plugin code references at include time,
 * allowing autoloaded classes to be parsed without a running WordPress
 * installation.
 *
 * This file is loaded via Composer autoload-dev "files" so it runs before
 * any test or plugin code.
 *
 * @package Tests
 * @since   0.1.0
 */

declare(strict_types=1);

// ─── WordPress constants ───

if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

if (!defined('WPINC')) {
    define('WPINC', 'wp-includes');
}

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

// ─── WordPress helper function stubs ───

if (!function_exists('wp_parse_args')) {
    /**
     * @param array<string,mixed>|string $args     Arguments.
     * @param array<string,mixed>        $defaults Defaults.
     *
     * @return array<string,mixed>
     */
    function wp_parse_args(array|string $args, array $defaults = []): array {
        if (is_string($args)) {
            parse_str($args, $args);
        }
        return array_merge($defaults, $args);
    }
}
