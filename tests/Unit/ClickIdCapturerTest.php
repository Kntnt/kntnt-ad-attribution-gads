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

    it('registers gclid as unique and gbraid and wbraid as non-unique', function () {
        $capturer = new Click_Id_Capturer();

        $result = $capturer->register([]);

        // The format core >= 1.11.0 reads: a plain parameter name is unique,
        // and a braid must be declared non-unique so a shared value never
        // makes a new visitor's click a repeat of an older one.
        expect($result)->toBe([
            'google_ads'        => 'gclid',
            'google_ads_gbraid' => ['parameter' => 'gbraid', 'unique' => false],
            'google_ads_wbraid' => ['parameter' => 'wbraid', 'unique' => false],
        ]);
    });

    it('preserves existing capturers', function () {
        $capturer = new Click_Id_Capturer();

        $result = $capturer->register(['facebook' => 'fbclid']);

        expect($result)->toBe([
            'facebook'          => 'fbclid',
            'google_ads'        => 'gclid',
            'google_ads_gbraid' => ['parameter' => 'gbraid', 'unique' => false],
            'google_ads_wbraid' => ['parameter' => 'wbraid', 'unique' => false],
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
