#!/usr/bin/env bash
# Single entry point for the Kntnt Ad Attribution for Google Ads test suite.
#
# Runs PHP unit tests.
#
# Usage:
#   bash run-tests.sh              # Run all tests
#   bash run-tests.sh --unit-only  # Unit tests only
#   bash run-tests.sh --filter <pattern>  # Filter tests by pattern
#   bash run-tests.sh --verbose    # Show full test output
#
# Environment detection (in priority order):
#   1. Explicit env vars or .env.testing overrides (PHP_BIN, COMPOSER_BIN)
#   2. DDEV auto-detection (if .ddev/config.yaml found in parent dirs)
#   3. Local PATH fallback

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
UNIT_PHP_EXIT=0
VERBOSE=false
FILTER=""
MODE="all"

# Resolved tool paths (set by load_overrides / detect_ddev / resolve_local)
PHP_BIN="${PHP_BIN:-}"
COMPOSER_BIN="${COMPOSER_BIN:-}"
ENV_SOURCE=""
DDEV_PLUGIN_DIR=""

# ─── Parse arguments ───

while [[ $# -gt 0 ]]; do
    case "$1" in
        --unit-only)
            MODE="unit"
            shift
            ;;
        --verbose)
            VERBOSE=true
            shift
            ;;
        --filter)
            FILTER="$2"
            shift 2
            ;;
        *)
            echo "Unknown option: $1" >&2
            echo "Usage: bash run-tests.sh [--unit-only] [--verbose] [--filter <pattern>]" >&2
            exit 1
            ;;
    esac
done

# ─── Load overrides from env vars and .env.testing ───

load_overrides() {

    # Track which variables were explicitly set via environment.
    declare -gA EXPLICIT_VARS=()
    for var in PHP_BIN COMPOSER_BIN; do
        if [[ -n "${!var}" ]]; then
            EXPLICIT_VARS[$var]=1
        fi
    done

    # Read .env.testing if it exists (env vars take precedence).
    local env_file="$SCRIPT_DIR/.env.testing"
    if [[ -f "$env_file" ]]; then
        while IFS= read -r line; do

            # Skip blank lines and comments.
            [[ -z "$line" || "$line" =~ ^[[:space:]]*# ]] && continue

            # Match KEY=VALUE for known variables.
            if [[ "$line" =~ ^[[:space:]]*(PHP_BIN|COMPOSER_BIN)[[:space:]]*=[[:space:]]*(.*) ]]; then
                local key="${BASH_REMATCH[1]}"
                local val="${BASH_REMATCH[2]}"

                # Trim surrounding quotes.
                val="${val#\"}" ; val="${val%\"}"
                val="${val#\'}" ; val="${val%\'}"

                # Only set if not already explicitly provided.
                if [[ -z "${EXPLICIT_VARS[$key]+x}" ]]; then
                    declare -g "$key=$val"
                    EXPLICIT_VARS[$key]=1
                fi
            fi
        done < "$env_file"
    fi
}

# ─── DDEV auto-detection ───

detect_ddev() {

    # Walk upward from SCRIPT_DIR looking for .ddev/config.yaml.
    local dir="$SCRIPT_DIR"
    local ddev_root=""
    while [[ "$dir" != "/" ]]; do
        if [[ -f "$dir/.ddev/config.yaml" ]]; then
            ddev_root="$dir"
            break
        fi
        dir="$(dirname "$dir")"
    done

    if [[ -z "$ddev_root" ]]; then
        return 1
    fi

    # Verify ddev command is available.
    if ! command -v ddev >/dev/null 2>&1; then
        echo "WARNING: DDEV project found at $ddev_root but 'ddev' command not in PATH." >&2
        echo "         Falling back to local tools." >&2
        return 1
    fi

    # Get DDEV status.
    local ddev_json
    ddev_json=$(cd "$ddev_root" && ddev describe -j 2>/dev/null) || {
        echo "WARNING: 'ddev describe' failed. Falling back to local tools." >&2
        return 1
    }

    # Check service statuses.
    local web_status db_status
    web_status=$(echo "$ddev_json" | jq -r '.raw.services.web.status // "unknown"')
    db_status=$(echo "$ddev_json" | jq -r '.raw.services.db.status // "unknown"')

    if [[ "$web_status" != "running" || "$db_status" != "running" ]]; then
        echo "DDEV project found but not running. Starting DDEV..."
        (cd "$ddev_root" && ddev start) || {
            echo "ERROR: 'ddev start' failed." >&2
            exit 1
        }

        # Re-read status after start.
        ddev_json=$(cd "$ddev_root" && ddev describe -j 2>/dev/null) || {
            echo "ERROR: 'ddev describe' failed after start." >&2
            exit 1
        }
    fi

    # Compute the container path for the plugin directory.
    local rel_path="${SCRIPT_DIR#"$ddev_root"/}"
    DDEV_PLUGIN_DIR="/var/www/html/$rel_path"

    # Assign DDEV commands for PHP/Composer.
    [[ -z "${EXPLICIT_VARS[PHP_BIN]+x}" ]]      && PHP_BIN="ddev php"
    [[ -z "${EXPLICIT_VARS[COMPOSER_BIN]+x}" ]]  && COMPOSER_BIN="ddev composer"

    # Extract version info for summary.
    DETECTED_PHP_VERSION=$(echo "$ddev_json" | jq -r '.raw.php_version // "unknown"')

    ENV_SOURCE="ddev"
    return 0
}

# ─── Local PATH fallback ───

resolve_local() {

    # Resolve each tool from PATH if not explicitly set.
    [[ -z "$PHP_BIN" ]]      && PHP_BIN=$(command -v php 2>/dev/null || true)
    [[ -z "$COMPOSER_BIN" ]] && COMPOSER_BIN=$(command -v composer 2>/dev/null || true)

    # Verify PHP version >= 8.3.
    if [[ -n "$PHP_BIN" ]]; then
        local php_version
        php_version=$($PHP_BIN -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null) || php_version="0.0"
        if [[ "$(printf '%s\n' "8.3" "$php_version" | sort -V | head -1)" != "8.3" ]]; then
            echo "ERROR: PHP 8.3+ required (found $php_version)." >&2
            echo "" >&2
            echo "Options:" >&2
            echo "  - Set up DDEV for the project (zero-configuration)" >&2
            echo "  - Set PHP_BIN to a PHP 8.3+ binary in .env.testing" >&2
            exit 1
        fi
    fi

    ENV_SOURCE="local"
}

# ─── Verify that all required tools are available ───

verify_environment() {
    local missing=()

    # Check required tools.
    [[ -z "$PHP_BIN" ]]      && missing+=("PHP (set PHP_BIN)")
    [[ -z "$COMPOSER_BIN" ]] && missing+=("Composer (set COMPOSER_BIN)")

    if [[ ${#missing[@]} -gt 0 ]]; then
        echo "ERROR: Missing required tool(s):" >&2
        for tool in "${missing[@]}"; do
            echo "  - $tool" >&2
        done
        echo "" >&2
        echo "Set the corresponding *_BIN variable via environment or .env.testing," >&2
        echo "or install the tool in PATH. See .env.testing.example for details." >&2
        echo "" >&2
        echo "Tip: DDEV provides all tools with zero configuration." >&2
        exit 1
    fi

    # Collect version info for the summary.
    local php_version
    if [[ "$ENV_SOURCE" == "ddev" ]]; then
        php_version="${DETECTED_PHP_VERSION}"
    else
        php_version=$($PHP_BIN -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null || echo "?")
    fi

    # Print environment summary.
    echo ""
    echo "═══ Test Environment ═══"
    if [[ "$ENV_SOURCE" == "ddev" ]]; then
        echo "  Source:      DDEV"
    else
        echo "  Source:      Local"
    fi
    echo "  PHP:         $PHP_BIN ($php_version)"
    echo "  Composer:    $COMPOSER_BIN"
    echo "═════════════════════════"
    echo ""
}

# ─── Install dependencies ───

install_deps() {
    if [[ ! -d "$SCRIPT_DIR/vendor" ]]; then
        echo "Installing PHP dependencies..."
        if [[ -n "$DDEV_PLUGIN_DIR" ]]; then
            $COMPOSER_BIN install --dev --quiet --working-dir="$DDEV_PLUGIN_DIR"
        else
            $COMPOSER_BIN install --dev --quiet
        fi
    fi
}

# ─── Level 1: PHP Unit Tests ───

run_unit_php() {
    echo ""
    echo "═══ Level 1: PHP Unit Tests (Pest) ═══"
    echo ""

    local pest_args=(--colors=always)

    if [[ -n "$FILTER" ]]; then
        pest_args+=(--filter "$FILTER")
    fi

    # In DDEV mode, ddev php runs from /var/www/html (the DDEV project root),
    # not the plugin dir. Use absolute paths for the Pest binary and config.
    local pest_path="vendor/bin/pest"
    if [[ -n "$DDEV_PLUGIN_DIR" ]]; then
        pest_path="$DDEV_PLUGIN_DIR/vendor/bin/pest"
        pest_args+=(--configuration "$DDEV_PLUGIN_DIR/phpunit.xml")
    fi

    if $PHP_BIN "$pest_path" "${pest_args[@]}"; then
        UNIT_PHP_EXIT=0
    else
        UNIT_PHP_EXIT=$?
    fi
}

# ─── Summary ───

print_summary() {
    echo ""
    echo "═══════════════════════════"
    echo "       Test Summary"
    echo "═══════════════════════════"

    local total_failures=0

    if [[ $UNIT_PHP_EXIT -eq 0 ]]; then
        echo "  PHP unit tests:    PASSED"
    else
        echo "  PHP unit tests:    FAILED"
        total_failures=$((total_failures + 1))
    fi

    echo "═══════════════════════════"

    if [[ $total_failures -eq 0 ]]; then
        echo "  ALL TESTS PASSED"
    else
        echo "  SOME TESTS FAILED"
    fi

    echo "═══════════════════════════"

    return $total_failures
}

# ─── Main ───

main() {
    cd "$SCRIPT_DIR"

    load_overrides
    detect_ddev || resolve_local
    verify_environment
    install_deps

    run_unit_php

    print_summary
    exit $?
}

main
