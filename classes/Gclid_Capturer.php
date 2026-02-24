<?php
/**
 * Google Click ID (gclid) capturer.
 *
 * Registers the gclid parameter with the core plugin's click-ID capture
 * system so that Google Ads click identifiers are stored automatically.
 *
 * @package Kntnt\Ad_Attribution_Gads
 * @since   0.2.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution_Gads;

/**
 * Registers the `gclid` query parameter as a click-ID capturer for Google Ads.
 *
 * The core plugin's `Click_Handler` reads registered capturers from the
 * `kntnt_ad_attr_click_id_capturers` filter. Each entry maps a platform
 * slug to the query parameter name that carries its click identifier.
 *
 * @since 0.2.0
 */
final class Gclid_Capturer {

	/**
	 * Adds the Google Ads gclid capturer to the registered capturers.
	 *
	 * @param array<string,string> $capturers Existing capturers keyed by platform slug.
	 *
	 * @return array<string,string> Capturers with `google_ads => gclid` added.
	 * @since 0.2.0
	 */
	public function register( array $capturers ): array {
		$capturers['google_ads'] = 'gclid';
		return $capturers;
	}

}
