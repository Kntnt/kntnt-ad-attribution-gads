<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;

pest()->beforeEach(function () {
    Monkey\setUp();

    // Stub common WordPress i18n and escape functions.
    Functions\stubTranslationFunctions();  // __, _e, _n, esc_html__, etc.
    Functions\stubEscapeFunctions();       // esc_html, esc_attr, esc_url, etc.
})->afterEach(function () {
    Monkey\tearDown();
});
