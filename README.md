# Kntnt Ad Attribution for Google Ads

[![Requires WordPress: 6.9+](https://img.shields.io/badge/WordPress-6.9+-blue.svg)](https://wordpress.org)
[![Requires PHP: 8.3+](https://img.shields.io/badge/PHP-8.3+-blue.svg)](https://php.net)
[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2+-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)

Add-on plugin for [Kntnt Ad Attribution](https://github.com/Kntnt/kntnt-ad-attribution) that enables Google Ads offline conversion tracking.

## Description

Kntnt Ad Attribution gives you internal, privacy-friendly lead attribution. This add-on extends it with Google Ads integration — capturing the `gclid` (Google Click Identifier) at ad click time and reporting conversions back to Google Ads via the Offline Conversion Upload API.

The result: you keep the core plugin's internal attribution data **and** get conversion data in Google Ads, allowing Google's bidding algorithms to optimize for actual leads rather than just clicks.

### How It Works

The plugin uses two adapter hooks provided by the core plugin:

1. **Click-ID capture** (`kntnt_ad_attr_click_id_capturers`): Registers `'google_ads' => 'gclid'`. When a visitor clicks a tracking URL that has a `gclid` query parameter, the core plugin's `Click_Handler` captures and stores the value in the `kntnt_ad_attr_click_ids` table. No custom tables or storage are needed — the core handles everything.

2. **Conversion reporting** (`kntnt_ad_attr_conversion_reporters`): Registers `enqueue` and `process` callbacks. When a conversion is attributed to a hash that has a stored `gclid`, a payload is built and queued for asynchronous processing. The queue processor then sends the conversion to the Google Ads Offline Conversion Upload API.

The plugin creates no custom tables, CPTs, cron hooks, REST endpoints, or cookies. It relies entirely on the core plugin's infrastructure.

### Current Status

Version 0.2.0 adds gclid capture and an admin settings page. Click-ID capture is fully functional: when a visitor arrives via a Google Ads tracking URL with a `gclid` parameter, the value is stored automatically by the core plugin. The settings page (Settings > Google Ads Attribution) provides fields for all required Google Ads API credentials and conversion defaults. Conversion reporting via the Google Ads API is planned for v0.3.0.

### Settings Page

Navigate to **Settings > Google Ads Attribution** to configure the plugin. The page has two sections:

**API Credentials** — required for conversion reporting (planned for v0.3.0):
- Customer ID (10 digits, dashes are stripped automatically)
- Conversion Action ID
- Developer Token
- OAuth2 Client ID, Client Secret, and Refresh Token
- Login Customer ID (MCC) — optional, only needed for manager accounts

**Conversion Defaults:**
- Default Conversion Value (numeric, >= 0)
- Currency Code (ISO 4217 select dropdown, default: SEK)

### Limitations

- **Requires the core plugin.** This is an add-on — [Kntnt Ad Attribution](https://github.com/Kntnt/kntnt-ad-attribution) must be installed and active.
- **Google Ads API credentials required.** Conversion reporting requires a Google Ads Customer ID, Conversion Action ID, OAuth2 credentials, and a Developer Token. Configure these under Settings > Google Ads Attribution.
- **Attribution window limited by cookie lifetime.** The core plugin's cookie lifetime (default: 90 days) determines the maximum attribution window, even though Google Ads allows up to 90 days for offline conversion uploads.

---

## For Users

### Installation

1. Install and activate [Kntnt Ad Attribution](https://github.com/Kntnt/kntnt-ad-attribution) first.
2. [Download the latest release ZIP file](https://github.com/Kntnt/kntnt-ad-attribution-gads/releases/latest/download/kntnt-ad-attribution-gads.zip).
3. In your WordPress admin panel, go to **Plugins → Add New**.
4. Click **Upload Plugin** and select the downloaded ZIP file.
5. Activate the plugin.

The plugin is distributed via GitHub Releases and updated through the standard WordPress plugin update UI. When a new version is available, you will see it on the WordPress Updates page.

If the core plugin is not active when you try to activate this add-on, activation is blocked with a clear error message. While this add-on is active, the core plugin's "Deactivate" link on the Plugins screen is replaced with a "Required by" notice, and bulk deactivation of the core is silently prevented.

#### System Requirements

| Requirement | Minimum |
|-------------|---------|
| PHP | 8.3 |
| WordPress | 6.9 |
| Kntnt Ad Attribution | Latest |

The plugin checks the PHP version on activation and aborts with a clear error message if the requirement is not met.

### User FAQ

**What does this plugin do?**

It extends [Kntnt Ad Attribution](https://github.com/Kntnt/kntnt-ad-attribution) with Google Ads offline conversion tracking. When a visitor clicks a Google ad and later becomes a lead, the conversion is reported back to Google Ads so its bidding algorithms can optimize for actual leads.

**Do I need this plugin if I'm not using Google Ads?**

No. This add-on is specifically for Google Ads. The core plugin works with all ad platforms for internal attribution. Separate add-ons can be created for other platforms (Meta Ads, LinkedIn Ads, etc.) using the same adapter system.

**Does this plugin send personal data to Google?**

The plugin sends the `gclid` (a click identifier generated by Google) and conversion metadata (timestamp, conversion action) to Google Ads. No email addresses, phone numbers, names, or other directly identifying information is sent. The `gclid` is a pseudonymous identifier that Google can link to a user within its own systems. Consult your legal advisor regarding GDPR implications of this data transfer.

**How can I get help or report a bug?**

Please visit the plugin's [issue tracker on GitHub](https://github.com/Kntnt/kntnt-ad-attribution-gads/issues) to ask questions, report bugs, or view existing discussions.

---

## For Contributors

This section is for developers who want to contribute code to the plugin — cloning the repository, understanding the architecture, running tests, building releases, and submitting pull requests.

### Building from Source

```bash
git clone https://github.com/Kntnt/kntnt-ad-attribution-gads.git
cd kntnt-ad-attribution-gads
```

> [!TIP]
> The repository includes `CLAUDE.md` with detailed technical documentation. This file is primarily written for [Claude Code](https://docs.anthropic.com/en/docs/claude-code) (an AI coding assistant), giving it the context it needs to work effectively with this codebase. However, it is equally useful for human developers — covering architecture, naming conventions, coding standards, and known gotchas.

### Building a Release ZIP

The `build-release-zip.sh` script creates a clean distribution ZIP by removing development files (CLAUDE.md, tests, etc.) and packaging the result. It can build from local files (default) or from a specific git tag. Exactly one destination (`--output`, `--update`, or `--create`) is required.

```bash
# Build from local files → zip in current directory
./build-release-zip.sh --output .

# Build from local files → zip with custom filename
./build-release-zip.sh --output ~/Desktop/custom-name.zip

# Build from a git tag → zip in /tmp
./build-release-zip.sh --tag 0.1.0 --output /tmp

# Build from a git tag, create a new GitHub release, and upload
./build-release-zip.sh --tag 0.1.0 --create

# Build from a git tag and upload to an existing GitHub release
./build-release-zip.sh --tag 0.1.0 --update

# Show full usage
./build-release-zip.sh --help
```

Requires `zip`. With `--tag`: `git`. With `--update` or `--create`: `gh` ([GitHub CLI](https://cli.github.com/)).

### Architecture Overview

**Singleton bootstrap:** `kntnt-ad-attribution-gads.php` loads `autoloader.php` (PSR-4 for `Kntnt\Ad_Attribution_Gads\*` → `classes/*.php`), creates the `Dependencies` guard, then `Plugin::get_instance()` creates the singleton which instantiates all components and registers hooks. A PHP 8.3 version check and core plugin dependency check abort with admin notices if requirements are not met.

**Dependency enforcement:** The `Dependencies` class emulates WordPress 6.5+ `Requires Plugins` behavior for plugins not hosted in the WordPress Plugin Directory. It provides three layers of protection:

1. **Activation guard** — blocks activation with `wp_die()` if the core plugin is not active.
2. **Deactivate link replacement** — replaces the core plugin's "Deactivate" link on the Plugins screen with a "Required by …" notice.
3. **Bulk deactivation prevention** — hooks `pre_update_option_active_plugins` to silently re-add the core plugin if it is being removed while this add-on remains active.

**Component instantiation order in `Plugin::__construct()`:**

1. `Updater` — GitHub-based update checker
2. `Migrator` — database migration runner
3. `Gclid_Capturer` — registers `gclid` parameter on the click-ID capture filter
4. `Settings` — reads/writes plugin settings option
5. `Settings_Page` — admin settings page under Settings > Google Ads Attribution

**Lifecycle:**

| Event | What happens |
|-------|-------------|
| Activation | Runs `Migrator` (no migrations yet in v0.2.0) |
| Deactivation | Clears transients with prefix `kntnt_ad_attr_gads_`. Preserves options. |
| Uninstallation | Deletes `kntnt_ad_attr_gads_version` option, `kntnt_ad_attr_gads_settings` option, and all transients. |

**Migrator pattern:** Version-based migrations in `migrations/X.Y.Z.php`. Each file returns `function(\wpdb $wpdb): void`. Migrator compares `kntnt_ad_attr_gads_version` option with the plugin header version on `plugins_loaded` and runs pending files in order. The `migrations/` directory does not exist yet — it will be created when the first migration is needed.

### File Structure

```
kntnt-ad-attribution-gads/
├── kntnt-ad-attribution-gads.php      ← Main plugin file (version header, PHP check, bootstrap)
├── autoloader.php                     ← PSR-4 autoloader for Kntnt\Ad_Attribution_Gads namespace
├── install.php                        ← Activation script (runs Migrator)
├── uninstall.php                      ← Uninstall script (removes option + transients)
├── README.md                          ← This file
├── CLAUDE.md                          ← AI-focused codebase guidance
├── classes/
│   ├── Plugin.php                     ← Singleton, component wiring, hooks, path helpers
│   ├── Dependencies.php               ← Core plugin dependency enforcement
│   ├── Updater.php                    ← GitHub release update checker
│   ├── Migrator.php                   ← Database migration runner (version-based)
│   ├── Gclid_Capturer.php            ← Registers gclid on the click-ID capture filter
│   ├── Settings.php                   ← Settings read/write (kntnt_ad_attr_gads_settings option)
│   └── Settings_Page.php             ← Admin settings page (Settings > Google Ads Attribution)
└── tests/
    ├── bootstrap.php                  ← Patchwork init + final-stripping
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
        └── SettingsPageTest.php       ← Settings page sanitization tests
```

### Naming Conventions

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

### Coding Standards

The plugin follows WordPress coding standards as a baseline, with several intentional deviations to leverage modern PHP and improve readability. Key points:

**PHP 8.3 features required:** typed properties, readonly, match expressions, arrow functions, null-safe operator, named arguments, `str_contains()`/`str_starts_with()`.

**Syntax and style:** `declare(strict_types=1)` in every PHP file. `[]` not `array()`. Natural conditions, not Yoda. Trailing commas in multi-line arrays. Early returns, small functions, descriptive names. PSR-4 autoloading: `Kntnt\Ad_Attribution_Gads\Migrator` → `classes/Migrator.php`.

**WordPress deviations:** No Yoda conditions (strict types eliminate the risk). No `array()` syntax. Namespaces instead of function prefixes. PSR-4 file naming instead of `class-*.php`.

**Documentation:** PHPDoc on every class, method, property, constant. `@since 0.1.0` for initial release. Inline comments explain **why**, not what. Written for senior developers. All identifiers and comments in English.

**General:** All user-facing strings translatable via `__()` / `esc_html__()` with text domain `kntnt-ad-attr-gads`. All SQL via `$wpdb->prepare()`. All admin URLs via `admin_url()`. All superglobals sanitized. Errors are silent toward visitors, logged via `error_log()`.

### Running Tests

The test suite uses Pest with Brain Monkey, Mockery, and Patchwork. The `run-tests.sh` script is the single entry point for running tests. It detects the environment, installs dependencies (Composer), runs the tests, and prints a summary.

```bash
# Run all tests
bash run-tests.sh

# Run only unit tests
bash run-tests.sh --unit-only

# Filter tests by name pattern
bash run-tests.sh --filter "Dependencies"

# Show full test output
bash run-tests.sh --verbose
```

#### Environment detection

The script resolves tool paths automatically in three steps (highest priority first):

1. **Explicit overrides** — set `PHP_BIN` or `COMPOSER_BIN` as environment variables, or define them in `.env.testing`. Environment variables take precedence over the file.

2. **DDEV auto-detection** — if `.ddev/config.yaml` exists in any parent directory, the script uses `ddev php` and `ddev composer`. DDEV services are started automatically if needed.

3. **Local PATH fallback** — if no DDEV project is found, tools are resolved from PATH.

```bash
# Override a single tool
PHP_BIN=/opt/php83/bin/php bash run-tests.sh
```

#### Requirements

PHP 8.3+ and Composer. With DDEV, only DDEV itself needs to be installed locally — PHP and Composer run inside the container.

### Known Gotchas

- `Plugin::get_plugin_data()` must pass `$translate = false` to WP's `get_plugin_data()` to avoid triggering `_load_textdomain_just_in_time` warnings when called before `init` (e.g. from Migrator on `plugins_loaded`).
- `uninstall.php` runs without the namespace autoloader — use fully qualified function calls and raw `$wpdb`.
- The `Dependencies` class hooks `plugin_action_links` in its constructor, before `plugins_loaded`, so the deactivation guard is active regardless of plugin load order.
- `Dependencies::guard_activation()` loads `plugin.php` explicitly because `is_plugin_active()` may not be available during activation hooks.

### Contributor FAQ

**How can I contribute?**

Contributions are welcome! Fork the repository, make your changes, and submit a pull request on GitHub. Please follow the coding standards described above and the existing patterns in the codebase.

**How does the migration system work?**

Version-based migration files live in `migrations/` and are named after the version they migrate to (e.g. `0.2.0.php`). Each file returns a callable that receives `$wpdb`. The Migrator runs pending files in order when the stored version in `wp_options` differs from the plugin header version. The directory is created when the first migration is needed.

**What is `CLAUDE.md`?**

It is a context file for [Claude Code](https://docs.anthropic.com/en/docs/claude-code), an AI coding assistant. It provides the AI with architecture, naming conventions, hook references, and coding standards so it can work effectively with the codebase. The information is equally useful for human developers.

**How are releases distributed?**

The plugin is distributed via GitHub Releases, not wordpress.org. The `Updater` class hooks into the WordPress plugin update system and checks for new GitHub releases automatically. Use `build-release-zip.sh` to create a distribution ZIP — see [Building a Release ZIP](#building-a-release-zip).
