<?php
/**
 * Plugin Name:       Kntnt Ad Attribution for Google Ads
 * Plugin URI:        https://github.com/Kntnt/kntnt-ad-attribution-gads
 * Description:       Extends Kntnt Ad Attribution with Google Ads offline conversion tracking.
 * Version:           1.10.0
 * Author:            Kntnt Sweden AB
 * Author URI:        https://www.kntnt.com/
 * License:           GPL-2.0-or-later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires PHP:      8.3
 * Requires at least: 6.9
 * Requires Plugins:  kntnt-ad-attribution
 * Text Domain:       kntnt-ad-attr-gads
 * Domain Path:       /languages
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

// Abort with an admin notice if the PHP version is too old.
if ( version_compare( PHP_VERSION, '8.3', '<' ) ) {
	add_action( 'admin_notices', function (): void {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: Required PHP version, 2: Current PHP version. */
					__( 'Kntnt Ad Attribution for Google Ads requires PHP %1$s or later. You are running PHP %2$s. The plugin has been deactivated.', 'kntnt-ad-attr-gads' ),
					'8.3',
					PHP_VERSION,
				),
			),
		);
	} );
	return;
}

// Register the autoloader for the plugin's classes.
require_once __DIR__ . '/autoloader.php';

// Enforce dependency on the core plugin: block activation if missing,
// protect it from deactivation while this add-on is active.
$dependencies = new Dependencies( __FILE__ );
register_activation_hook( __FILE__, fn() => $dependencies->guard_activation() );

// Run install script on plugin activation.
register_activation_hook( __FILE__, function (): void {
	require_once __DIR__ . '/install.php';
} );

// Run cleanup on plugin deactivation.
register_deactivation_hook( __FILE__, [ Plugin::class, 'deactivate' ] );

// Set the plugin file path for the Plugin class to use.
Plugin::set_plugin_file( __FILE__ );

// Defer initialization until all plugins are loaded, ensuring the core
// plugin's classes are available regardless of file-system loading order.
add_action( 'plugins_loaded', static function (): void {

	// Abort with an admin notice if the core plugin is not loaded.
	if ( ! class_exists( \Kntnt\Ad_Attribution\Plugin::class ) ) {
		add_action( 'admin_notices', static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Kntnt Ad Attribution for Google Ads requires Kntnt Ad Attribution to be installed and activated.', 'kntnt-ad-attr-gads' ),
			);
		} );
		return;
	}

	// Initialize the plugin inside a safety net. A fatal error here would
	// take down the entire site, and without server access the only fix is
	// a long chain of phone calls. The try-catch lets the rest of the site
	// keep running while surfacing the problem in the admin dashboard. It
	// also covers reading the core version, which runs core code.
	try {

		// Abort with an admin notice if the core plugin is too old. Registering
		// nothing at all, as if the core were missing, guarantees that no
		// conversion is queued through the old per-URL click-ID lookup.
		$core_version = \Kntnt\Ad_Attribution\Plugin::get_version();
		if ( version_compare( $core_version, Dependencies::MIN_CORE_VERSION, '<' ) ) {
			add_action( 'admin_notices', static function () use ( $core_version ): void {

				// A core whose header has no version is blocked too, so the
				// notice needs words for a version it cannot name.
				$installed_version = $core_version;
				if ( $installed_version === '' ) {
					/* translators: Stands in for the installed Kntnt Ad Attribution version in "…, but %2$s is installed." when the plugin does not report one. */
					$installed_version = __( 'an unknown version', 'kntnt-ad-attr-gads' );
				}

				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html(
						sprintf(
							/* translators: 1: Required Kntnt Ad Attribution version, e.g. "1.10.0". 2: Installed Kntnt Ad Attribution version, e.g. "1.9.1", or "an unknown version" when the plugin does not report one. */
							__( 'Kntnt Ad Attribution for Google Ads requires Kntnt Ad Attribution %1$s or later, but %2$s is installed. Until you update it, Google Ads clicks are not captured and conversions are not reported to Google Ads.', 'kntnt-ad-attr-gads' ),
							Dependencies::MIN_CORE_VERSION,
							$installed_version,
						),
					),
				);
			} );
			return;
		}

		Plugin::get_instance();

	} catch ( \Throwable $e ) {
		error_log( sprintf(
			'Kntnt Ad Attribution Gads: Fatal error during initialization — %s in %s on line %d',
			$e->getMessage(),
			$e->getFile(),
			$e->getLine(),
		) );
		add_action( 'admin_notices', static function () use ( $e ): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( sprintf(
					/* translators: %s: Error message from the caught exception. */
					__( 'Kntnt Ad Attribution for Google Ads failed to initialize: %s', 'kntnt-ad-attr-gads' ),
					$e->getMessage(),
				) ),
			);
		} );
	}

}, 0 );
