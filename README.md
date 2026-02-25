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

### Features

- Automatically captures `gclid` parameters when visitors arrive via Google Ads tracking URLs.
- Queues and uploads conversions to Google Ads via the Offline Conversion Upload API.
- Resilient queuing — conversions are always queued regardless of credential status. Missing credentials are filled in from current settings when the job is processed.
- Failed jobs are automatically reset when settings are updated with valid credentials.
- **Test Connection** button verifies OAuth2 credentials by performing a live token refresh.
- Persistent admin notice warns when uploads fail due to missing or invalid credentials.

### Settings Page

Navigate to **Settings > Google Ads Attribution** to configure the plugin. The page has two sections:

**API Credentials** — required for conversion reporting:
- Customer ID (10 digits, dashes are stripped automatically)
- Conversion Action ID
- Developer Token
- OAuth2 Client ID, Client Secret, and Refresh Token
- Login Customer ID (MCC)
- **Test Connection** button — verifies that your OAuth2 credentials can obtain an access token from Google

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




### Configuration Guide

After activation, go to **Settings > Google Ads Attribution** to enter your credentials. The following sections walk you through every step, including creating any accounts you may not already have.

#### Prerequisites

Using the Google Ads API requires a **Google Ads Manager Account (MCC)**. This is a special account type primarily used by agencies and consultants to manage multiple Google Ads accounts, but it is also required for anyone who needs API access — even if you only manage a single account.

If you don't already have one, you will create it in the steps below. It is free and does not affect your existing Google Ads account.

#### Step 1: Create a Google Ads Manager Account (MCC)

> Skip this step if you already have a manager account.

1. Go to [ads.google.com/home/tools/manager-accounts](https://ads.google.com/home/tools/manager-accounts).
2. Sign in with a Google account. **Note:** If your email is already associated with a regular Google Ads account, you may need to use a different email address.
3. Follow the prompts to create the manager account. Give it a descriptive name (e.g. "Your Company — MCC").
4. Once created, link your existing Google Ads account: go to **Accounts** in the manager account and add your Google Ads account as a managed account.

Note the 10-digit **Manager Account ID** shown in the top-right corner (formatted as `XXX-XXX-XXXX`). You will need it later.

#### Step 2: Get a Developer Token

1. Sign in to your **Manager Account** (not your regular Google Ads account).
2. Go to [ads.google.com/aw/apicenter](https://ads.google.com/aw/apicenter). If you see the message *"The API Center is only available to manager accounts"*, you are signed in to the wrong account — switch to the manager account.
3. Fill in the form:
   - **API contact email:** Your email address.
   - **Company name:** Your company name.
   - **Company website:** Your website URL (must be live and reachable).
   - **Company type:** Choose the option that best describes you (e.g. "Agency/SEM" if you manage ads for clients, or "Advertiser" if you manage your own).
   - **Purpose:** Describe your intended use. For example: *"Offline conversion tracking. We upload lead conversions from our websites to Google Ads via the Offline Conversion Upload API, so that Google's bidding algorithms can optimize for actual leads rather than just clicks."*
4. Accept the Terms and Conditions and submit.
5. You will typically receive **Explorer Access** immediately, which allows up to 2,880 API operations per day — more than sufficient for conversion uploads. You can apply for Basic Access later if needed.
6. Copy the **Developer Token** (a 22-character alphanumeric string) shown on the API Center page.

#### Step 3: Get the Conversion Action ID

1. In Google Ads, go to [Goals > Conversions](https://ads.google.com/aw/conversions).
2. If you already have a conversion action of type **Import > from clicks** (e.g. one used for offline conversion imports from Matomo or another source), you can reuse it. Otherwise, create a new one:
   - Click **+ New conversion action**.
   - Choose **Import > Other data sources or CRMs > Track conversions from clicks**.
   - Name the action (e.g. "Offline Lead") and save.
3. Open the conversion action. Copy the numeric **Conversion Action ID** — you can find it in the URL parameter `ctId` (e.g. `ctId=7171477836` → the ID is `7171477836`), or in the action details.

> **If you were previously importing conversions from another system** (e.g. Matomo's Conversion Export), you should disable that import to avoid double-counting. The plugin replaces the need for external conversion imports.

#### Step 4: Set up a Google Cloud Project

A Google Cloud project is needed to create the OAuth2 credentials that authorize the plugin to communicate with the Google Ads API.

1. Go to [console.cloud.google.com](https://console.cloud.google.com).
2. In the project selector at the top of the page, create a new project (e.g. "Google Ads Integration") or select an existing one.
3. Go to [APIs & Services > Library](https://console.cloud.google.com/apis/library).
4. Search for **Google Ads API**, click on it, and click **Enable**.

#### Step 5: Configure the OAuth Consent Screen

Before you can create OAuth credentials, Google requires a consent screen configuration.

1. Go to [Google Auth Platform > Overview](https://console.cloud.google.com/auth/overview) (or navigate via the sidebar: **APIs & Services > OAuth consent screen**).
2. Click **Configure** (or **Get started** if this is a new project).
3. Fill in the required fields:
   - **App name:** A descriptive name (e.g. "Kntnt Ad Attribution").
   - **User support email:** Your email address.
   - **Audience / User type:** Choose **External**.
   - **Developer contact email:** Your email address.
4. You can skip all optional fields (logo, homepage, privacy policy, etc.).
5. You can also skip the **Scopes** step if prompted — scopes are not needed here.
6. Save and continue through all steps.

#### Step 6: Create OAuth2 Client ID and Client Secret

1. Go to [Google Auth Platform > Clients](https://console.cloud.google.com/auth/clients) (or navigate via **APIs & Services > Credentials**).
2. Click **+ Create OAuth client** (or **+ Create Credentials > OAuth client ID**).
3. Fill in the form:
   - **Application type:** Web application.
   - **Name:** A descriptive name (e.g. "Kntnt Ad Attribution").
   - **Authorized redirect URIs:** Click **+ Add URI** and enter exactly: `https://developers.google.com/oauthplayground`
4. Click **Create**.
5. A dialog will show your **Client ID** and **Client Secret**. **Copy both immediately** — the Client Secret cannot be viewed again after you close this dialog.

> **What is the redirect URI?** It is a security mechanism. When you authorize an app with Google, Google sends the authorization code *only* to pre-approved addresses. We use Google's OAuth Playground (a tool for generating tokens) as the redirect target, which is why the redirect URI points there. This is a one-time setup — the plugin itself does not use the redirect URI at runtime.

#### Step 7: Generate a Refresh Token

The Refresh Token allows the plugin to authenticate with Google on an ongoing basis without requiring you to log in each time.

1. Go to [developers.google.com/oauthplayground](https://developers.google.com/oauthplayground).
2. Click the **gear icon** (⚙) in the top-right corner.
3. Check **"Use your own OAuth credentials"**.
4. Enter your **Client ID** and **Client Secret** from the previous step.
5. Close the settings panel.
6. In the left panel (Step 1 — "Select & authorize APIs"):
   - Scroll down and find **Google Ads API** in the list.
   - Expand it and check the scope `https://www.googleapis.com/auth/adwords`.
   - Click **Authorize APIs**.
7. Sign in with the Google account that has access to your Google Ads account.
8. You will likely see a warning that the app is not verified. This is expected — click **Advanced** and then **Go to [your app name] (unsafe)**. This is safe; the "unverified" warning appears because your OAuth app is not publicly published, which is fine for private use.
9. Grant the requested permissions.
10. You are redirected back to the Playground. In Step 2 ("Exchange authorization code for tokens"), click **Exchange authorization code for tokens**.
11. Copy the **Refresh token** shown in the left panel (a long string starting with `1//`).

#### Step 8: Enter Credentials in WordPress

Go to **Settings > Google Ads Attribution** and fill in:

| Field | Value | Where to find it |
| --- | --- | --- |
| Customer ID | Your Google Ads account ID (10 digits) | Top-right corner of the [Google Ads dashboard](https://ads.google.com) |
| Conversion Action ID | Numeric ID of the conversion action | Step 3 above |
| Developer Token | 22-character alphanumeric string | Step 2 above (API Center in your manager account) |
| OAuth2 Client ID | Long string ending in `.apps.googleusercontent.com` | Step 6 above |
| OAuth2 Client Secret | String starting with `GOCSPX-` | Step 6 above |
| OAuth2 Refresh Token | Long string starting with `1//` | Step 7 above |
| Login Customer ID (MCC) | Your Manager Account ID (10 digits) | Top-right corner of your [manager account](https://ads.google.com) |

> **Note:** A Manager Account (MCC) is required to obtain a Developer Token. When authenticating through an MCC, the Login Customer ID must be provided. Therefore all fields above are required.

#### Conversion Defaults

At the bottom of the settings page:

* **Default Conversion Value** — the monetary value assigned to each conversion (e.g. `100`). Set to `0` if you don't track conversion values.
* **Currency Code** — the ISO 4217 currency code (e.g. `SEK`, `USD`, `EUR`).

#### Test Connection

Click the **Test Connection** button on the settings page. It verifies your OAuth2 credentials by performing a live token refresh against Google. A success message confirms that your Client ID, Client Secret, and Refresh Token are correct. An error message indicates which credential is invalid.

> **Note:** The Test Connection button only verifies that the OAuth2 credentials can obtain an access token. It does not verify the Customer ID, Conversion Action ID, or Developer Token. Those are validated when the first conversion is actually uploaded.

#### Login Customer ID (MCC)

Enter the 10-digit Customer ID of your **Manager Account (MCC)**. You can find it in the top-right corner of the [Google Ads Manager Account](https://ads.google.com) interface. A Manager Account is required because the Developer Token is only available through the API Center in a Manager Account.

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

**Singleton bootstrap:** `kntnt-ad-attribution-gads.php` loads `autoloader.php` (PSR-4 for `Kntnt\Ad_Attribution_Gads\*` → `classes/*.php`), creates the `Dependencies` guard, then `Plugin::get_instance()` creates the singleton which instantiates all components and registers hooks. A PHP 8.3 version check and core plugin dependency check abort with admin notices if requirements are not met. The `get_instance()` call is wrapped in `try { … } catch (\Throwable)` so that an unexpected error during initialization is logged and shown as an admin notice instead of taking down the entire site.

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
6. `Conversion_Reporter` — registers enqueue/process callbacks on the conversion reporters filter

**Lifecycle:**

| Event | What happens |
|-------|-------------|
| Activation | Runs `Migrator` (no migrations yet) |
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
├── LICENSE                            ← GPL-2.0-or-later
├── README.md                          ← This file
├── CLAUDE.md                          ← AI-focused codebase guidance
├── classes/
│   ├── Plugin.php                     ← Singleton, component wiring, hooks, path helpers
│   ├── Dependencies.php               ← Core plugin dependency enforcement
│   ├── Updater.php                    ← GitHub release update checker
│   ├── Migrator.php                   ← Database migration runner (version-based)
│   ├── Gclid_Capturer.php            ← Registers gclid on the click-ID capture filter
│   ├── Settings.php                   ← Settings read/write (kntnt_ad_attr_gads_settings option)
│   ├── Settings_Page.php             ← Admin settings page (Settings > Google Ads Attribution)
│   ├── Conversion_Reporter.php       ← Registers enqueue/process callbacks for conversion reporting
│   └── Google_Ads_Client.php         ← Standalone HTTP client for Google Ads REST API
├── js/
│   └── settings-page.js              ← Test connection button AJAX handler
├── languages/
│   └── kntnt-ad-attr-gads.pot        ← Translation template
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
        ├── SettingsPageTest.php       ← Settings page sanitization tests
        ├── BootstrapSafetyTest.php    ← Try-catch safety wrapper tests
        ├── GoogleAdsClientTest.php    ← API client token/upload/OAuth2 tests
        └── ConversionReporterTest.php ← Conversion reporter register/enqueue/process/transient tests
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
