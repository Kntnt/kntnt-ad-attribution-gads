<?php
/**
 * Plugin uninstall script.
 *
 * Removes all plugin data when the plugin is deleted through WordPress admin:
 * version option and transients.
 *
 * @package Kntnt\Ad_Attribution_Gads
 * @since   0.1.0
 */

declare( strict_types = 1 );

// WordPress calls this file directly — the namespace autoloader is not
// loaded, so we use fully qualified WordPress functions throughout.

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die;
}

global $wpdb;

// Delete the stored schema version.
delete_option( 'kntnt_ad_attr_gads_version' );

// Delete plugin settings.
delete_option( 'kntnt_ad_attr_gads_settings' );

// Remove plugin transients.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE %s
		    OR option_name LIKE %s",
		'_transient_kntnt_ad_attr_gads_%',
		'_transient_timeout_kntnt_ad_attr_gads_%',
	),
);

// Remove the diagnostic log directory and its contents.
$log_dir = wp_upload_dir()['basedir'] . '/kntnt-ad-attr-gads';
if ( is_dir( $log_dir ) ) {
	array_map( 'wp_delete_file', glob( $log_dir . '/*' ) );
	rmdir( $log_dir );
}
