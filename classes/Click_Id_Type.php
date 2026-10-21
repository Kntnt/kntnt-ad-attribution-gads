<?php
/**
 * Google Ads click identifier types.
 *
 * @package Kntnt\Ad_Attribution_Gads
 * @since   1.11.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution_Gads;

/**
 * The kinds of click identifier Google Ads appends to a landing page URL.
 *
 * Each backing value is both the URL query parameter that carries the
 * identifier and the `ClickConversion` field it is uploaded in. Cases are
 * declared in order of precedence: when one click carries several, the
 * first in `cases()` order is the one uploaded.
 *
 * @since 1.11.0
 */
enum Click_Id_Type: string {

	/**
	 * Google click identifier, unique per click.
	 *
	 * @since 1.11.0
	 */
	case Gclid = 'gclid';

	/**
	 * Identifier for clicks associated with app conversions (iOS).
	 *
	 * @since 1.11.0
	 */
	case Gbraid = 'gbraid';

	/**
	 * Identifier for clicks associated with web conversions (iOS).
	 *
	 * @since 1.11.0
	 */
	case Wbraid = 'wbraid';

	/**
	 * Returns the core plugin's platform key the identifier is stored under.
	 *
	 * `google_ads` predates the braids and is kept for gclid so that click
	 * IDs and queued jobs from earlier versions stay valid.
	 *
	 * @return string Platform key in the core's click-ID store.
	 * @since 1.11.0
	 */
	public function platform(): string {
		return match ( $this ) {
			self::Gclid  => 'google_ads',
			self::Gbraid => 'google_ads_gbraid',
			self::Wbraid => 'google_ads_wbraid',
		};
	}

	/**
	 * Tells whether a value of this type identifies a single click.
	 *
	 * Google describes gbraid as measuring "in a non-unique fashion (like
	 * Campaign ID)", so different visitors may arrive with the same braid
	 * value. The core must not treat a shared braid as a repeat click.
	 *
	 * @return bool True if each value belongs to one click only.
	 * @since 1.11.0
	 */
	public function is_unique(): bool {
		return $this === self::Gclid;
	}

}
