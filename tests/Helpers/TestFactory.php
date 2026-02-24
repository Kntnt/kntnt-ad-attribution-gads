<?php
/**
 * Factory methods for creating test doubles and fixture data.
 *
 * Provides convenient helper methods for building mock objects used
 * across multiple test files.
 *
 * @package Tests\Helpers
 * @since   0.1.0
 */

declare(strict_types=1);

namespace Tests\Helpers;

use Mockery;

/**
 * Test double factory for common mock objects.
 *
 * @since 0.1.0
 */
final class TestFactory {

    /**
     * Creates a mock $wpdb instance with common properties pre-set.
     *
     * @param array<string,string> $table_overrides Override specific table name properties.
     *
     * @return \Mockery\MockInterface&\stdClass Mock wpdb object.
     */
    public static function wpdb(array $table_overrides = []): Mockery\MockInterface {
        $wpdb = Mockery::mock('wpdb');

        // Default table properties.
        $defaults = [
            'prefix'  => 'wp_',
            'posts'   => 'wp_posts',
            'options' => 'wp_options',
        ];

        foreach (array_merge($defaults, $table_overrides) as $prop => $value) {
            $wpdb->{$prop} = $value;
        }

        return $wpdb;
    }

}
