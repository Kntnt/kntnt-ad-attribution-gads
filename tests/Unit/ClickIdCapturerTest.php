<?php
/**
 * Unit tests for Click_Id_Capturer.
 *
 * @package Tests\Unit
 * @since   0.2.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution_Gads\Click_Id_Capturer;

// ─── register() ───

describe('Click_Id_Capturer::register()', function () {

    it('adds google_ads => gclid to an empty array', function () {
        $capturer = new Click_Id_Capturer();

        $result = $capturer->register([]);

        expect($result)->toBe(['google_ads' => 'gclid']);
    });

    it('preserves existing capturers', function () {
        $capturer = new Click_Id_Capturer();

        $result = $capturer->register(['facebook' => 'fbclid']);

        expect($result)->toBe([
            'facebook'   => 'fbclid',
            'google_ads' => 'gclid',
        ]);
    });

    it('overwrites a pre-existing google_ads entry', function () {
        $capturer = new Click_Id_Capturer();

        $result = $capturer->register(['google_ads' => 'custom_param']);

        // The array assignment overwrites the key. When the filter runs in
        // priority order, later registrations win. This test documents that.
        expect($result['google_ads'])->toBe('gclid');
    });

});
