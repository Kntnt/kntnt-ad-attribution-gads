<?php
/**
 * Main plugin class implementing singleton pattern.
 *
 * Manages plugin initialization, configuration, and provides central access
 * to plugin metadata. Coordinates between different plugin components.
 *
 * @package Kntnt\Ad_Attribution_Gads
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution_Gads;

use LogicException;

/**
 * Singleton entry point for the plugin.
 *
 * Bootstraps all components, registers WordPress hooks, and exposes
 * helper methods for plugin metadata.
 *
 * @since 0.1.0
 */
final class Plugin {

	/**
	 * Singleton instance of the plugin.
	 *
	 * @var Plugin|null
	 * @since 0.1.0
	 */
	private static ?Plugin $instance = null;

	/**
	 * Updater component instance.
	 *
	 * @var Updater
	 * @since 0.1.0
	 */
	public readonly Updater $updater;

	/**
	 * Migrator component instance.
	 *
	 * @var Migrator
	 * @since 0.1.0
	 */
	public readonly Migrator $migrator;

	/**
	 * Gclid capturer component instance.
	 *
	 * @var Gclid_Capturer
	 * @since 0.2.0
	 */
	public readonly Gclid_Capturer $gclid_capturer;

	/**
	 * Settings component instance.
	 *
	 * @var Settings
	 * @since 0.2.0
	 */
	public readonly Settings $settings;

	/**
	 * Settings page component instance.
	 *
	 * @var Settings_Page
	 * @since 0.2.0
	 */
	public readonly Settings_Page $settings_page;

	/**
	 * Conversion reporter component instance.
	 *
	 * @var Conversion_Reporter
	 * @since 0.3.0
	 */
	public readonly Conversion_Reporter $conversion_reporter;

	/**
	 * Cached plugin metadata from header.
	 *
	 * @var array|null
	 * @since 0.1.0
	 */
	private static ?array $plugin_data = null;

	/**
	 * Path to the main plugin file.
	 *
	 * @var string|null
	 * @since 0.1.0
	 */
	private static ?string $plugin_file = null;

	/**
	 * Plugin slug derived from filename.
	 *
	 * @var string|null
	 * @since 0.1.0
	 */
	private static ?string $plugin_slug = null;

	/**
	 * Private constructor for singleton pattern.
	 *
	 * Initializes plugin components and registers WordPress hooks.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {

		// Initialize plugin components.
		$this->updater              = new Updater();
		$this->migrator             = new Migrator();
		$this->gclid_capturer       = new Gclid_Capturer();
		$this->settings             = new Settings();
		$this->settings_page        = new Settings_Page( $this->settings );
		$this->conversion_reporter  = new Conversion_Reporter( $this->settings );

		// Register WordPress hooks.
		$this->register_hooks();
	}

	/**
	 * Gets the singleton instance of the plugin.
	 *
	 * Creates the instance if it doesn't exist, otherwise returns existing instance.
	 *
	 * @return Plugin The plugin instance.
	 * @since 0.1.0
	 */
	public static function get_instance(): Plugin {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Sets the plugin file path. Called from the main plugin file.
	 *
	 * Must be called before any other plugin methods that depend on file paths.
	 *
	 * @param string $file Full path to the main plugin file.
	 *
	 * @return void
	 * @since 0.1.0
	 */
	public static function set_plugin_file( string $file ): void {
		self::$plugin_file = $file;
	}

	/**
	 * Gets the plugin file path.
	 *
	 * @return string Full path to the main plugin file.
	 * @throws LogicException If plugin file hasn't been set.
	 * @since 0.1.0
	 */
	public static function get_plugin_file(): string {
		if ( self::$plugin_file === null ) {
			throw new LogicException( 'Plugin file must be set using set_plugin_file() before accessing plugin metadata.' );
		}
		return self::$plugin_file;
	}

	/**
	 * Gets the plugin data from the plugin header.
	 *
	 * Reads version information from the main plugin file header. Caches
	 * the result to avoid repeated file parsing.
	 *
	 * @return array {
	 *     Plugin data. Values will be empty if not supplied by the plugin.
	 *
	 *     @type string $Name            Name of the plugin.
	 *     @type string $PluginURI       Plugin URI.
	 *     @type string $Version         Plugin version.
	 *     @type string $Description     Plugin description.
	 *     @type string $Author          Plugin author's name.
	 *     @type string $AuthorURI       Plugin author's website address.
	 *     @type string $TextDomain      Plugin text domain.
	 *     @type string $DomainPath      Relative path to .mo files.
	 *     @type string $RequiresWP      Minimum required WordPress version.
	 *     @type string $RequiresPHP     Minimum required PHP version.
	 * }
	 * @since 0.1.0
	 */
	public static function get_plugin_data(): array {

		// Load plugin data if not already cached.
		if ( self::$plugin_data === null ) {

			// get_plugin_data() is only available in admin context by default.
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			// Disable translation to avoid triggering _load_textdomain_just_in_time
			// when called before `init` (e.g. from Migrator on `plugins_loaded`).
			self::$plugin_data = get_plugin_data( self::get_plugin_file(), true, false );
		}

		return self::$plugin_data;
	}

	/**
	 * Gets the plugin version from the plugin header.
	 *
	 * @return string Plugin version number.
	 * @since 0.1.0
	 */
	public static function get_version(): string {
		return self::get_plugin_data()['Version'] ?? '';
	}

	/**
	 * Gets the plugin slug based on filename (without .php).
	 *
	 * @return string Plugin slug.
	 * @since 0.1.0
	 */
	public static function get_slug(): string {
		if ( self::$plugin_slug === null ) {
			self::$plugin_slug = basename( self::get_plugin_file(), '.php' );
		}
		return self::$plugin_slug;
	}

	/**
	 * Gets the plugin directory path.
	 *
	 * @return string Full path to the plugin directory.
	 * @since 0.1.0
	 */
	public static function get_plugin_dir(): string {
		return plugin_dir_path( self::get_plugin_file() );
	}

	/**
	 * Registers WordPress hooks for plugin functionality.
	 *
	 * @return void
	 * @since 0.1.0
	 */
	private function register_hooks(): void {

		// Run pending database migrations before other components initialize.
		add_action( 'plugins_loaded', [ $this->migrator, 'run' ] );

		// Check for updates from GitHub.
		add_filter( 'pre_set_site_transient_update_plugins', [ $this->updater, 'check_for_updates' ] );

		// Register gclid capture for Google Ads click tracking.
		add_filter( 'kntnt_ad_attr_click_id_capturers', [ $this->gclid_capturer, 'register' ] );

		// Register conversion reporter for Google Ads offline upload.
		add_filter( 'kntnt_ad_attr_conversion_reporters', [ $this->conversion_reporter, 'register' ] );

		// Reset failed queue jobs when settings are updated with valid credentials.
		add_action( 'update_option_' . Settings::OPTION_KEY, [ $this, 'on_settings_updated' ], 10, 2 );

		// Add a "Settings" link to the plugin row on the Plugins page.
		$basename = plugin_basename( self::get_plugin_file() );
		add_filter( "plugin_action_links_{$basename}", [ $this, 'add_settings_link' ] );
	}

	/**
	 * Resets failed queue jobs when settings are saved with valid credentials.
	 *
	 * Hooked to `update_option_{option}` so that credentials restored after
	 * an outage automatically trigger reprocessing of queued conversions.
	 *
	 * @param mixed $old_value Previous option value (unused).
	 * @param mixed $new_value New option value (unused — we re-read via Settings).
	 *
	 * @return void
	 * @since 0.4.0
	 */
	public function on_settings_updated( mixed $old_value, mixed $new_value ): void {

		// Clear credential error flag so the admin notice disappears.
		delete_transient( Conversion_Reporter::CREDENTIAL_ERROR_TRANSIENT );

		if ( $this->settings->is_configured() ) {
			$this->conversion_reporter->reset_failed_jobs();
		}
	}

	/**
	 * Adds a "Settings" action link to the plugin row on the Plugins page.
	 *
	 * @param string[] $links Existing action links.
	 *
	 * @return string[] Modified action links with "Settings" prepended.
	 * @since 1.3.1
	 */
	public function add_settings_link( array $links ): array {
		$url  = admin_url( 'options-general.php?page=kntnt-ad-attr-gads' );
		$link = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Settings', 'kntnt-ad-attr-gads' ) );
		array_unshift( $links, $link );
		return $links;
	}

	/**
	 * Handles plugin deactivation cleanup.
	 *
	 * Removes transient resources while preserving persistent data
	 * (options) for potential reactivation.
	 *
	 * @return void
	 * @since 0.1.0
	 */
	public static function deactivate(): void {

		// Remove plugin transients.
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				 WHERE option_name LIKE %s
				    OR option_name LIKE %s",
				'_transient_kntnt_ad_attr_gads_%',
				'_transient_timeout_kntnt_ad_attr_gads_%',
			),
		);
	}

	/**
	 * Prevents cloning of singleton instance.
	 *
	 * @throws LogicException Always throws to prevent cloning.
	 * @since 0.1.0
	 */
	private function __clone(): void {
		throw new LogicException( 'Cannot clone a singleton.' );
	}

	/**
	 * Prevents unserialization of singleton instance.
	 *
	 * @throws LogicException Always throws to prevent unserialization.
	 * @since 0.1.0
	 */
	public function __wakeup(): void {
		throw new LogicException( 'Cannot unserialize a singleton.' );
	}

}
