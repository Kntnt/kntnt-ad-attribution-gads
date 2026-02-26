<?php
/**
 * Plugin settings manager.
 *
 * Provides read/write access to the plugin's Google Ads API credentials
 * and conversion defaults, stored as a single serialized WordPress option.
 *
 * @package Kntnt\Ad_Attribution_Gads
 * @since   0.2.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution_Gads;

/**
 * Manages plugin settings stored in `kntnt_ad_attr_gads_settings`.
 *
 * All settings are stored as a single associative array in one option row.
 * Default values are merged on read so missing keys always resolve.
 *
 * @since 0.2.0
 */
final class Settings {

	/**
	 * WordPress option key for plugin settings.
	 *
	 * @var string
	 * @since 0.2.0
	 */
	public const OPTION_KEY = 'kntnt_ad_attr_gads_settings';

	/**
	 * Default values for all settings.
	 *
	 * @var array<string,string>
	 * @since 0.2.0
	 */
	private const DEFAULTS = [
		'customer_id'                 => '',
		'conversion_action_id'        => '',
		'conversion_action_name'      => '',
		'conversion_action_category'  => 'SUBMIT_LEAD_FORM',
		'developer_token'             => '',
		'client_id'                   => '',
		'client_secret'               => '',
		'refresh_token'               => '',
		'login_customer_id'           => '',
		'conversion_value'            => '0',
		'currency_code'               => 'SEK',
		'enable_logging'              => '',
	];

	/**
	 * Required fields for the plugin to be considered configured.
	 *
	 * @var string[]
	 * @since 0.2.0
	 */
	private const REQUIRED_FIELDS = [
		'customer_id',
		'conversion_action_id',
		'developer_token',
		'client_id',
		'client_secret',
		'refresh_token',
	];

	/**
	 * Gets a single setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback if the key is not in DEFAULTS.
	 *
	 * @return mixed Setting value, the DEFAULTS value, or the provided default.
	 * @since 0.2.0
	 */
	public function get( string $key, mixed $default = '' ): mixed {
		$all = $this->get_all();
		return $all[ $key ] ?? $default;
	}

	/**
	 * Gets all settings merged with defaults.
	 *
	 * @return array<string,string> All settings with defaults applied.
	 * @since 0.2.0
	 */
	public function get_all(): array {
		$stored = get_option( self::OPTION_KEY, [] );
		return wp_parse_args( is_array( $stored ) ? $stored : [], self::DEFAULTS );
	}

	/**
	 * Updates settings by merging new values into existing ones.
	 *
	 * Only keys present in DEFAULTS are persisted; unknown keys are discarded.
	 *
	 * @param array<string,mixed> $values New setting values to merge.
	 *
	 * @return void
	 * @since 0.2.0
	 */
	public function update( array $values ): void {
		$current = $this->get_all();
		$merged  = array_intersect_key( array_merge( $current, $values ), self::DEFAULTS );
		update_option( self::OPTION_KEY, $merged );
	}

	/**
	 * Checks whether all required API credentials are configured.
	 *
	 * @return bool True if all required fields have non-empty values.
	 * @since 0.2.0
	 */
	public function is_configured(): bool {
		$all = $this->get_all();
		foreach ( self::REQUIRED_FIELDS as $field ) {
			if ( empty( $all[ $field ] ) ) {
				return false;
			}
		}
		return true;
	}

}
