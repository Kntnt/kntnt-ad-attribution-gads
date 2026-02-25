# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Plugin Does

WordPress add-on plugin for Kntnt Ad Attribution that adds Google Ads offline conversion tracking. Captures `gclid` parameters from ad clicks and reports conversions back to Google Ads via the Offline Conversion Upload API.

The plugin captures `gclid` parameters via the core plugin's click-ID system, provides a settings page (Settings > Google Ads Attribution) for API credentials, conversion defaults, and a test connection button. Conversions are always queued regardless of credential status — missing credentials are filled in from current settings at processing time. Failed queue jobs are automatically reset when credentials are updated. A persistent admin notice warns when uploads fail due to missing or invalid credentials.

## Naming Conventions

All machine-readable names use `kntnt-ad-attr-gads` (hyphens) / `kntnt_ad_attr_gads` (underscores) as prefix. The PHP namespace is `Kntnt\Ad_Attribution_Gads`. The GitHub URL (`kntnt-ad-attribution-gads`) is the only exception.

| Context | Name |
|---------|------|
| Plugin slug | `kntnt-ad-attribution-gads` |
| Text domain | `kntnt-ad-attr-gads` |
| DB version option | `kntnt_ad_attr_gads_version` |
| Settings option | `kntnt_ad_attr_gads_settings` |
| Options prefix | `kntnt_ad_attr_gads_` |
| Namespace | `Kntnt\Ad_Attribution_Gads` |
| GitHub repo | `Kntnt/kntnt-ad-attribution-gads` |

## Architecture

**Singleton bootstrap:** `kntnt-ad-attribution-gads.php` loads `autoloader.php` (PSR-4 for `Kntnt\Ad_Attribution_Gads\*` → `classes/*.php`), creates the `Dependencies` guard, then `Plugin::get_instance()` creates the singleton which instantiates all components and registers hooks. A PHP 8.3 version check and core plugin dependency check abort with admin notices if requirements are not met.

**Dependency enforcement:** The `Dependencies` class emulates WordPress 6.5+ `Requires Plugins` behavior for plugins not hosted in the WordPress Plugin Directory. Three layers of protection:

1. **Activation guard** — `guard_activation()` blocks activation with `wp_die()` if the core plugin is not active. Called via `register_activation_hook`. Loads `plugin.php` explicitly because `is_plugin_active()` may not be available during activation.
2. **Deactivate link replacement** — `protect_core_deactivate_link()` replaces the core plugin's "Deactivate" link with a "Required by …" notice. Hooked via `plugin_action_links_` in the constructor.
3. **Bulk deactivation prevention** — `prevent_core_deactivation()` hooks `pre_update_option_active_plugins` to silently re-add the core plugin if it is being removed while this add-on remains active.

The `Dependencies` constructor hooks filters immediately (before `plugins_loaded`) so the guard is in place regardless of plugin load order. The `get_addon_name()` helper reads the plugin header for the display name, falling back to the directory basename.

**Component instantiation order in `Plugin::__construct()`:**

1. `Updater` — GitHub-based update checker
2. `Migrator` — database migration runner
3. `Gclid_Capturer` — registers `gclid` parameter on the core plugin's click-ID capture filter
4. `Settings` — reads/writes `kntnt_ad_attr_gads_settings` option (API credentials + conversion defaults)
5. `Settings_Page` — WordPress Settings API page under Settings > Google Ads Attribution (registers its own `admin_menu`, `admin_init`, and `admin_notices` hooks in the constructor)
6. `Conversion_Reporter` — registers enqueue/process callbacks on the conversion reporters filter

**Lifecycle files (not autoloaded):**

- `install.php` — activation: runs Migrator
- `uninstall.php` — complete data removal. Runs outside the plugin's namespace (no autoloader available), uses raw `$wpdb`. Deletes version option, settings option, and transients.
- `Plugin::deactivate()` — clears transients. Preserves data.

**Migrator pattern:** Version-based migrations in `migrations/X.Y.Z.php`. Each file returns `function(\wpdb $wpdb): void`. Migrator compares `kntnt_ad_attr_gads_version` option with the plugin header version on `plugins_loaded` and runs pending files in order. The `migrations/` directory does not exist yet — it will be created when the first migration is needed.

**Core plugin integration:** This plugin uses two filter hooks from the core plugin:

- `kntnt_ad_attr_click_id_capturers` — registers `'google_ads' => 'gclid'` to capture click IDs
- `kntnt_ad_attr_conversion_reporters` — registers enqueue/process callbacks for Google Ads API

**Conversion reporting flow:** The `Conversion_Reporter` always registers regardless of credential status, so conversions are queued even during credential outages. `enqueue()` snapshots raw settings values (including `attribution_fraction` and `conversion_action_id`) into each payload. `process()` merges payload credentials with current settings as fallback — if the payload has empty credentials (queued during an outage), current settings fill the gaps. Derived values (resource name, attributed value) are computed at process time. If required credentials are still missing after merge, the job fails and will be retried. When settings are updated with valid credentials, `Plugin::on_settings_updated()` resets all failed Google Ads queue jobs to pending. The `Google_Ads_Client` handles OAuth2 token refresh and conversion upload via `wp_remote_post()`. Access tokens are cached in the `kntnt_ad_attr_gads_access_token` transient with a safety margin.

**Token refresh error reporting:** `Google_Ads_Client::refresh_access_token()` captures the specific error from Google's OAuth2 response (`error_description`, `error` code, or WP_Error message) in `$last_refresh_error`, and the raw HTTP response in `$last_refresh_debug`. Both `test_connection()` and `upload_click_conversion()` surface these details instead of a generic "Failed to obtain access token." message. The return arrays include a `credential_error` boolean flag to distinguish token refresh failures from other API errors.

**Test connection diagnostics:** When Test Connection fails, `Settings_Page::handle_test_connection()` appends masked credential diagnostics and Google's raw response to the error message. The `mask()` helper reveals only the last 4 characters of `client_secret` and `refresh_token`; `client_id` is shown in full (not sensitive). This helps users verify which credentials the plugin is actually using without exposing secrets.

**Credential error notification:** `Conversion_Reporter::process()` checks the `credential_error` flag in the result array from `Google_Ads_Client` to decide whether to set the `kntnt_ad_attr_gads_credential_error` transient. The transient has no expiry — it persists until explicitly cleared. On successful upload the transient is deleted. `Settings_Page` hooks `admin_notices` to display a persistent error notice (visible on all admin pages, `manage_options` capability required) with a link to the settings page. `Plugin::on_settings_updated()` also clears the transient when settings are saved, so the notice disappears immediately after re-entering credentials. Only credential failures set the flag — other API errors (HTTP 4xx, partial failures) do not.

The plugin creates no custom tables, CPTs, cron hooks, REST endpoints, or cookies. It uses the core plugin's infrastructure (Click_ID_Store, Queue, Queue_Processor).

## File Structure

```
kntnt-ad-attribution-gads/
├── kntnt-ad-attribution-gads.php      ← Main plugin file (version header, PHP check, bootstrap)
├── autoloader.php                     ← PSR-4 autoloader for Kntnt\Ad_Attribution_Gads namespace
├── install.php                        ← Activation script (runs Migrator)
├── uninstall.php                      ← Uninstall script (removes option + transients)
├── LICENSE                            ← GPL-2.0-or-later
├── README.md                          ← User and contributor documentation
├── CLAUDE.md                          ← This file
├── classes/
│   ├── Plugin.php                     ← Singleton, component wiring, hooks, path helpers
│   ├── Dependencies.php               ← Core plugin dependency enforcement
│   ├── Updater.php                    ← GitHub release update checker
│   ├── Migrator.php                   ← Database migration runner (version-based)
│   ├── Gclid_Capturer.php            ← Registers gclid on the click-ID capture filter
│   ├── Settings.php                   ← Settings read/write (kntnt_ad_attr_gads_settings option)
│   ├── Settings_Page.php             ← Admin settings page + credential error admin notice
│   ├── Conversion_Reporter.php       ← Enqueue/process callbacks + credential error transient flag
│   └── Google_Ads_Client.php         ← Standalone HTTP client for Google Ads REST API
├── js/
│   └── settings-page.js              ← Test connection button AJAX handler
├── languages/
│   └── kntnt-ad-attr-gads.pot        ← Translation template (generated by wp i18n make-pot)
├── build-release-zip.sh               ← Release zip builder (local or from git tag)
├── run-tests.sh                       ← Test runner with DDEV auto-detection
├── composer.json                      ← Dependencies (Pest, Brain Monkey, Mockery)
├── phpunit.xml                        ← PHPUnit/Pest configuration
├── patchwork.json                     ← Patchwork redefinable internals
└── tests/
    ├── bootstrap.php                  ← Patchwork init + final-stripping
    ├── Pest.php                       ← Pest configuration
    ├── Helpers/
    │   ├── WpStubs.php                ← WordPress constants and helper stubs
    │   └── TestFactory.php            ← Mock $wpdb factory
    └── Unit/
        ├── DependenciesTest.php       ← Dependency enforcement tests
        ├── MigratorTest.php           ← Migration runner tests
        ├── PluginTest.php             ← Plugin metadata and lifecycle tests
        ├── UpdaterTest.php            ← GitHub update checker tests
        ├── GclidCapturerTest.php      ← Gclid capturer registration tests
        ├── SettingsTest.php           ← Settings read/write/is_configured tests
        ├── SettingsPageTest.php       ← Settings page sanitization + credential notice tests
        ├── BootstrapSafetyTest.php     ← Try-catch safety wrapper tests
        ├── GoogleAdsClientTest.php    ← API client token/upload tests
        └── ConversionReporterTest.php ← Conversion reporter register/enqueue/process/transient tests
```

**Directories that will be created when needed:** `migrations/`

## Tests

| Level | Framework | What it covers |
|-------|-----------|----------------|
| PHP unit | Pest + Brain Monkey + Mockery + Patchwork | Individual class methods in isolation |

### Running tests

```bash
bash run-tests.sh              # All tests
bash run-tests.sh --unit-only  # Unit tests only
bash run-tests.sh --filter <pattern>  # Filter by pattern
```

### Environment detection

`run-tests.sh` resolves tool paths in three steps (highest priority first):

1. **Explicit overrides** — `PHP_BIN`, `COMPOSER_BIN` as env vars or in `.env.testing`.
2. **DDEV auto-detection** — if `.ddev/config.yaml` exists in any parent directory, uses `ddev php` and `ddev composer`.
3. **Local PATH fallback** — resolves tools from PATH if no DDEV project is found.

### Running tests individually

```bash
# PHP unit tests via DDEV
ddev here vendor/bin/pest
ddev here vendor/bin/pest --filter Dependencies

# PHP unit tests locally
./vendor/bin/pest
./vendor/bin/pest --filter Migrator
```

## Coding Standards

- **PHP 8.3 features required:** typed properties, readonly, match expressions, arrow functions, null-safe operator, named arguments, `str_contains()`/`str_starts_with()`.
- `declare(strict_types=1)` in every PHP file.
- `[]` not `array()`. Natural conditions, not Yoda. Trailing commas in multi-line arrays.
- PSR-4 autoloading: `Kntnt\Ad_Attribution_Gads\Migrator` → `classes/Migrator.php`.
- PHPDoc on every class, method, property, constant. `@since 0.1.0` for initial release.
- Inline comments explain **why**, not what. Written for senior developers.
- All identifiers and comments in **English**.
- All user-facing strings translatable via `__()` / `esc_html__()` with text domain `kntnt-ad-attr-gads`.
- All SQL via `$wpdb->prepare()`. All admin URLs via `admin_url()`. All superglobals sanitized.
- Errors are silent toward visitors, logged via `error_log()`.
- JavaScript: ES6+, IIFE with `'use strict'`, `const` default, arrow functions, `fetch` over jQuery.

## Known Gotchas

- `Plugin::get_plugin_data()` must pass `$translate = false` to WP's `get_plugin_data()` to avoid triggering `_load_textdomain_just_in_time` warnings when called before `init` (e.g. from Migrator on `plugins_loaded`).
- `uninstall.php` runs without the namespace autoloader — use fully qualified function calls and raw `$wpdb`.
- The `Dependencies` class hooks `plugin_action_links` in its constructor, before `plugins_loaded`, so the deactivation guard is active regardless of plugin load order.
- `Dependencies::guard_activation()` loads `plugin.php` explicitly because `is_plugin_active()` may not be available during activation hooks.
- `Dependencies::get_addon_name()` falls back to `basename(dirname($plugin_file))` when the plugin header's Name field is empty (e.g. if the plugin file is not yet fully configured).
- Brain Monkey stubs WordPress i18n functions (`__()`, `esc_html__()`, etc.) in the global namespace. When testing namespaced code that calls these functions, you must add explicit `Functions\when('esc_html__')->returnArg()` stubs in the test, because PHP resolves unqualified function calls to the current namespace first.
