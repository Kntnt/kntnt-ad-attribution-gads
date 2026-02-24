<?php
/**
 * Plugin activation script.
 *
 * Runs database migrations on activation.
 *
 * @package Kntnt\Ad_Attribution_Gads
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution_Gads;

// Prevent direct file access.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Run pending database migrations.
( new Migrator() )->run();
