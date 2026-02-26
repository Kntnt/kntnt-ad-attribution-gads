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

// ─── WordPress REST API stubs ───

if (!class_exists('WP_REST_Response')) {
    /**
     * Minimal WP_REST_Response stub for unit tests.
     */
    class WP_REST_Response {
        /** @var mixed */
        protected mixed $data;
        /** @var int */
        protected int $status;

        public function __construct(mixed $data = null, int $status = 200) {
            $this->data   = $data;
            $this->status = $status;
        }

        public function get_data(): mixed {
            return $this->data;
        }

        public function get_status(): int {
            return $this->status;
        }
    }
}

if (!class_exists('WP_REST_Request')) {
    /**
     * Minimal WP_REST_Request stub for unit tests.
     */
    class WP_REST_Request {
        /** @var array<string,mixed> */
        protected array $params = [];

        public function get_params(): array {
            return $this->params;
        }

        public function get_param(string $key): mixed {
            return $this->params[$key] ?? null;
        }

        /** @param array<string,mixed> $params */
        public function set_params(array $params): void {
            $this->params = $params;
        }
    }
}

// ─── Core plugin class stubs ───
// The core plugin is not loaded during GADS unit tests. Provide minimal
// stubs so type hints and Mockery mocks resolve correctly.

if (!class_exists('Kntnt\\Ad_Attribution\\Logger')) {
    eval('namespace Kntnt\\Ad_Attribution; class Logger {
        public static function mask(string $value): string {
            if ($value === "") { return ""; }
            $len = strlen($value);
            if ($len <= 4) { return str_repeat("*", $len); }
            return str_repeat("*", $len - 4) . substr($value, -4);
        }
        public function info(string $prefix, string $message): void {}
        public function error(string $prefix, string $message): void {}
    }');
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
