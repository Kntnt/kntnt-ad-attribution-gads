<?php
/**
 * Plugin dependency guard.
 *
 * Emulates WordPress 6.5+ `Requires Plugins` behavior for plugins not
 * hosted in the WordPress Plugin Directory. Prevents activation when
 * required plugins are missing and protects them from accidental
 * deactivation while this add-on is active.
 *
 * @package Kntnt\Ad_Attribution_Gads
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution_Gads;

/**
 * Enforces that the core Kntnt Ad Attribution plugin is active.
 *
 * Because neither this plugin nor the core plugin is hosted on
 * wordpress.org, WordPress cannot automatically resolve the `Requires
 * Plugins` header. This class fills the gap by:
 *
 * 1. Blocking activation with wp_die() if the core plugin is not active.
 * 2. Replacing the core plugin's "Deactivate" link on the Plugins screen
 *    with a "Required by …" notice as long as this add-on is active.
 * 3. Intercepting bulk deactivation requests that include the core plugin
 *    and silently removing it from the list.
 *
 * The constructor hooks the protection immediately so the guard is in
 * place regardless of plugin load order.
 *
 * @since 0.1.0
 */
final class Dependencies {

	/**
	 * Basename of the core plugin this add-on depends on.
	 *
	 * @var string
	 * @since 0.1.0
	 */
	private const CORE_BASENAME = 'kntnt-ad-attribution/kntnt-ad-attribution.php';

	/**
	 * Full path to this add-on's main plugin file.
	 *
	 * @var string
	 * @since 0.1.0
	 */
	private readonly string $plugin_file;

	/**
	 * Initializes the dependency guard.
	 *
	 * Immediately hooks into plugin_action_links to protect the core
	 * plugin from deactivation while this add-on is active.
	 *
	 * @param string $plugin_file Full path to the add-on's main plugin file.
	 *
	 * @since 0.1.0
	 */
	public function __construct( string $plugin_file ) {
		$this->plugin_file = $plugin_file;

		// Replace the core plugin's "Deactivate" link with a notice.
		add_filter(
			'plugin_action_links_' . self::CORE_BASENAME,
			[ $this, 'protect_core_deactivate_link' ],
		);

		// Prevent bulk-deactivation of the core plugin.
		add_filter( 'pre_update_option_active_plugins', [ $this, 'prevent_core_deactivation' ], 10, 2 );
	}

	/**
	 * Blocks activation if the core plugin is not active.
	 *
	 * Called from register_activation_hook. Uses wp_die() to abort
	 * activation with a user-friendly message and a back link.
	 *
	 * @return void
	 * @since 0.1.0
	 */
	public function guard_activation(): void {

		// is_plugin_active() may not be loaded during activation.
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active( self::CORE_BASENAME ) ) {
			wp_die(
				esc_html__( 'Kntnt Ad Attribution for Google Ads requires the Kntnt Ad Attribution plugin to be active.', 'kntnt-ad-attr-gads' ),
				esc_html__( 'Plugin Activation Error', 'kntnt-ad-attr-gads' ),
				[ 'back_link' => true ],
			);
		}
	}

	/**
	 * Replaces the core plugin's "Deactivate" link with a "Required by" notice.
	 *
	 * Mimics how WordPress handles `Requires Plugins` for plugins in the
	 * official directory — the dependent plugin's Deactivate link is
	 * disabled while a plugin that requires it is active.
	 *
	 * @param string[] $links Existing action links for the core plugin.
	 *
	 * @return string[] Modified action links.
	 * @since 0.1.0
	 */
	public function protect_core_deactivate_link( array $links ): array {

		if ( ! isset( $links['deactivate'] ) ) {
			return $links;
		}

		$addon_name = $this->get_addon_name();

		$links['deactivate'] = sprintf(
			'<span class="description">%s</span>',
			esc_html(
				sprintf(
					/* translators: %s: Name of the add-on that requires this plugin. */
					__( 'Required by %s', 'kntnt-ad-attr-gads' ),
					$addon_name,
				),
			),
		);

		return $links;
	}

	/**
	 * Prevents the core plugin from being removed from the active list.
	 *
	 * Catches bulk deactivation and programmatic deactivation attempts.
	 * If the new active list no longer contains the core plugin but
	 * still contains this add-on, the core plugin is silently re-added.
	 *
	 * @param mixed $new_value The proposed new list of active plugins.
	 * @param mixed $old_value The current list of active plugins.
	 *
	 * @return mixed The (possibly corrected) list of active plugins.
	 * @since 0.1.0
	 */
	public function prevent_core_deactivation( mixed $new_value, mixed $old_value ): mixed {

		if ( ! is_array( $new_value ) || ! is_array( $old_value ) ) {
			return $new_value;
		}

		$addon_basename = plugin_basename( $this->plugin_file );

		// Only intervene when the add-on is still active but the core is being removed.
		$addon_stays_active = in_array( $addon_basename, $new_value, true );
		$core_being_removed = ! in_array( self::CORE_BASENAME, $new_value, true )
			&& in_array( self::CORE_BASENAME, $old_value, true );

		if ( $addon_stays_active && $core_being_removed ) {
			$new_value[] = self::CORE_BASENAME;
		}

		return $new_value;
	}

	/**
	 * Returns the human-readable name of this add-on plugin.
	 *
	 * @return string The plugin name, or the directory name as fallback.
	 * @since 0.1.0
	 */
	private function get_addon_name(): string {

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$data = get_plugin_data( $this->plugin_file, false, false );

		return $data['Name'] ?: basename( dirname( $this->plugin_file ) );
	}

}
