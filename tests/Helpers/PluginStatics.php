<?php
/**
 * Helper for resetting the add-on Plugin class's static state in tests.
 *
 * The singleton and the plugin file, data and slug are static, so a test that
 * creates or sets them leaks into every later test unless it resets them.
 * Pest includes this file at boot, before Patchwork; that is fine for a plain
 * function nobody redefines, and the Plugin class itself is only loaded when
 * the function is first called.
 *
 * @package Tests\Helpers
 * @since   1.11.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution_Gads\Plugin;

/**
 * Sets static properties of the add-on's Plugin class.
 *
 * @param array<string,mixed> $values Values keyed by property name, e.g.
 *                                    `['instance' => null]`.
 *
 * @return void
 */
function set_plugin_statics(array $values): void {
    foreach ($values as $property => $value) {
        (new ReflectionProperty(Plugin::class, $property))->setValue(null, $value);
    }
}
