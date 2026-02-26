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

// Create the diagnostic log directory with direct-access protection.
$log_dir = wp_upload_dir()['basedir'] . '/' . Logger::DIR_NAME;
wp_mkdir_p( $log_dir );
file_put_contents( $log_dir . '/.htaccess', "Deny from all\n" );
