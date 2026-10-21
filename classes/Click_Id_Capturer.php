<?php
/**
 * Google Ads click-ID capturer.
 *
 * Registers the gclid, gbraid and wbraid parameters with the core plugin's
 * click-ID capture system so that Google Ads click identifiers are stored
 * automatically.
 *
 * @package Kntnt\Ad_Attribution_Gads
 * @since   0.2.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution_Gads;

/**
 * Registers the Google Ads click identifiers as click-ID capturers.
 *
 * The core plugin's `Click_Handler` reads registered capturers from the
 * `kntnt_ad_attr_click_id_capturers` filter. Each entry maps a platform
 * slug to the query parameter that carries its click identifier, either as
 * the parameter name or, since core 1.11.0, as `[ 'parameter' => …,
 * 'unique' => bool ]`.
 *
 * @since 0.2.0
 * @since 1.11.0 Renamed from Gclid_Capturer; also registers gbraid and wbraid.
 */
final class Click_Id_Capturer {

	/**
	 * Adds a capturer for each Google Ads click identifier type.
	 *
	 * gclid keeps the plain string form it has always had. The braids use
	 * the array form, because the core counts a plain parameter name as
	 * unique and would then treat a new visitor who shares an older click's
	 * braid value as a repeat of that click.
	 *
	 * @param array<string,string|array{parameter: string, unique?: bool}> $capturers Existing capturers keyed by platform slug.
	 *
	 * @return array<string,string|array{parameter: string, unique?: bool}> Capturers with the Google Ads identifiers added.
	 * @since 0.2.0
	 * @since 1.11.0 Registers gbraid and wbraid as non-unique.
	 */
	public function register( array $capturers ): array {
		foreach ( Click_Id_Type::cases() as $type ) {
			$capturers[ $type->platform() ] = $type->is_unique()
				? $type->value
				: [ 'parameter' => $type->value, 'unique' => false ];
		}
		return $capturers;
	}

}
