# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

DriveAge is an Unraid plugin that monitors drive health and age. It uses a **dual risk model**: age-based risk for HDDs (five tiers calibrated to Backblaze's 2025 bathtub-curve data, plus an N/A tier for devices without SMART data) and wear-based risk for NVMe drives. It produces predictive replacement estimates: HDDs count down to a target age (8 years conservative / 10 years aggressive), NVMe projections use the drive's own measured wear rate.

## Build and Test Commands

### Building the Plugin
```bash
./build.sh
```
Creates a Slackware `.txz` package in `archive/` with auto-incrementing build numbers. The script:
- Aborts if any `*.backup`/`*.bak` files exist in the source tree
- Writes `include/version.php` (the single version source used by all pages for display and cache-busting)
- Generates MD5 checksums and updates `plugins/driveage.plg` (version, build, MD5)

### Running Tests
```bash
php tests/run.php
```
Pure-PHP test suite - no Unraid or smartmontools required. Covers the smartctl parsers (against captured fixtures in `tests/fixtures/`), risk categorization, prediction math, config validation, and an end-to-end `getAllDrives()` run against a fixture Unraid state directory. **Run this after every change; add fixtures/assertions for any new parsing or prediction logic.**

### Testing on Unraid
1. Build, upload `.txz` + `.md5` to GitHub, install via the raw `.plg` URL, or copy the source tree directly to `/usr/local/emhttp/plugins/driveage/`
2. CLI diagnostic on the server: `php /usr/local/emhttp/plugins/driveage/scripts/diagnose.php`

## Architecture

### Core System Flow

1. **`DriveAge.page`**: menu container (tabs); **`DriveAgeDashboard.page`** loads data via AJAX from `get_drive_data.php`
2. **`scripts/get_drive_data.php`**: JSON endpoint - loads config, collects drives, applies drive-type filters, groups, responds. Plain data only (no HTML fragments)
3. **`include/smartdata.php`**: enumeration + assembly + health-warning policy
4. **`include/smartparse.php`**: pure parsing functions (no filesystem/shell access) - keep them pure; they are the most heavily unit-tested code
5. **`include/predictions.php`**: replacement estimates; **`include/risk.php`**: category policy; **`include/config.php`**: config load + validation

### Data Sources (order of authority)

1. `/var/local/emhttp/disks.ini` + `devs.ini` - device enumeration, ids, sizes, live temp/spin state. **Never enumerate from `/dev` globs** (breaks >26 drives); the glob path exists only as a last-resort fallback when both inis are missing.
2. `/var/local/emhttp/smart/<name>` - Unraid's cached smartctl output. **`<name>` is the Unraid disk name** (`parity`, `disk1`, `cache`, `cache2`, `dev1`...), not the kernel device - emhttpd and dynamix's monitor use `$disk['name']`. `getSmartCacheNames()` tries the ini `name`, then the section name, then the device name as a fallback. **Refreshed on the Disk Settings `poll_attributes` interval (default 1800s = 30 minutes)** - do not claim or assume faster freshness. Cache files written with `smartctl -A` have NO model/serial/health lines for ATA drives.
3. `/var/local/emhttp/smart/<name>.ssa` - cached overall-health verdict (PASSED/FAILED).
4. Direct `smartctl -a -j` - last resort only (no cache file, drive not spun down). Parse the universal `power_on_time`/`temperature`/`smart_status` JSON fields first (covers SAS), then type-specific tables.

Spun-down drives are **never queried**. Their last-known power-on hours live in `/boot/config/plugins/driveage/drivecache.json` (single JSON file on flash, rewritten only when a value changes - keep it write-on-change to protect the flash device). Entries are keyed by Unraid's disk id (`getDriveCacheKey()`), never by `sdX` - device letters change between boots.

### Key Invariants (do not regress these)

- **Category, color class and label always move together.** Any code path that escalates `age_category` must also update `color_class` and `age_label` (a desync here shipped once as a visible bug).
- **Devices without SMART data are `unknown` / "N/A"**, never Minimal Risk.
- **SMART overall-health FAILED** forces High Risk, a "Replace Now" prediction, and a first-class health warning.
- All five Google-study attributes are evaluated: 5, 187, 188, 197, 198. Attribute 188 raw values above 0xFFFF are masked to the low 16 bits (composite vendor encoding).
- Every prediction result includes `method` and `confidence`.
- Every setting shown on the Settings page must actually do something (drive-type filters, auto-refresh, default sort are all implemented in `dashboard.js`/`get_drive_data.php`).
- The JSON endpoint returns plain data; CSS classes are computed server-side (`*_class` fields), HTML is built client-side with `escapeHtml()`.

### Unraid .page Format

`.page` files have an ini-style header (`Menu=`, `Title=`), a `---` separator, then PHP/HTML. `DriveAge.page` is the tab container (`Type="xmenu"`, `Tabs="true"`); Dashboard/Settings/Help are `Menu="DriveAge:N"` tabs.

### Age Categories and Thresholds (defaults, Backblaze 2025)

Five configurable tiers + N/A (default hours):
- **minimal_risk**: < 35,040h (< 4 years, AFR ~1%)
- **low_risk**: 35,040-52,560h (4-6 years, AFR ~1.5%)
- **moderate_risk**: 52,560-70,080h (6-8 years, AFR ~2%)
- **elevated_risk**: 70,080-87,600h (8-10 years, AFR ~2.5-4%)
- **high_risk**: ≥ 87,600h (≥ 10 years, AFR ~4%+; measured fleet peak ~4.25% at ~10.3 years)
- **unknown**: no SMART data (gray, "N/A")

Thresholds must be ascending; `validateConfig()` resets to defaults otherwise.

## Security Model

- **Access control is Unraid's**: the webGUI authenticates every `/plugins/*` request. Do NOT add plugin-level auth layers, rate limiters, API-enable switches, or custom CSRF tokens - the settings form posts to Unraid's `/update.php`, which enforces Unraid's own CSRF token. A previous custom CSRF implementation conflicted with it and was removed.
- **Validation happens on load** (`loadConfig()` → `validateConfig()`), because `/update.php` writes POSTed values verbatim. Any new config key needs validation there, a default in `getDefaultConfig()`, and a form field in `DriveAgeSettings.page`.
- **Escape all output**: `esc()`/`e()` in PHP templates, `escapeHtml()` in JS.
- **Shell rules**: no shell execution except the `smartctl`/`blockdev` fallbacks, always with `escapeshellarg()` on device names that passed `isValidDeviceName()` (`sd[a-z]{1,3}` or `nvme\d+n\d+`).

## Common Development Workflows

### Adding a New Configuration Option
1. Default in `getDefaultConfig()` (config.php)
2. Validation in `validateConfig()` (config.php) - this is the enforcement point
3. Form field in `DriveAgeSettings.page` (checkbox pattern: hidden `false` input + checkbox `true` input)
4. Consume it (PHP endpoint and/or `config` section of the JSON response for JS)
5. Test in `tests/run.php`

### Modifying SMART Parsing
1. Add a captured fixture to `tests/fixtures/`
2. Extend `smartparse.php` (keep functions pure - string/array in, array out)
3. Add assertions to `tests/run.php`; run `php tests/run.php`

### Changing Risk/Prediction Logic
- Category policy lives in `risk.php`; time estimates in `predictions.php`; escalation wiring in `smartdata.php::getDriveInfo()`
- Update METHODOLOGY.md in the same commit - it documents the shipped formulas, and drift between docs and code is treated as a bug
- Add assertions to `tests/run.php`

## Version and Release Process

Version format: `YYYY.MM.DD` + build number (`version="&version;-&build;"` in the .plg - the build suffix is required or Unraid refuses same-day reinstalls).

**Single version source**: `include/version.php` (`DRIVEAGE_VERSION`), rewritten by `build.sh`. Pages use it for the footer and cache-busting query strings. Do not hardcode version strings anywhere else; update the README badge on release.

Release checklist:
1. `php tests/run.php` - must pass
2. `./build.sh`
3. Commit and push (including updated `plugins/driveage.plg`), message format "Build N: description"
4. Upload package and MD5 to GitHub releases
5. Test installation on Unraid

## Important Constraints

- **No npm/node/composer**: plain PHP 8.x + vanilla JS (Chart.js vendored in `js/vendor/`)
- **Theme-aware UI**: Unraid ships white/black/azure/gray themes. Use translucent `rgba(128,128,128,x)` surfaces and `color: inherit`; never hardcode light backgrounds with dark text. Chart.js text color is set from `getComputedStyle(document.body).color` at render time.
- **Unraid-specific paths** are guarded defines (`DRIVEAGE_STATE_DIR`, `DRIVEAGE_CONFIG_FILE`, `DRIVEAGE_POH_CACHE_FILE`) so tests can redirect them to fixtures - preserve that pattern for any new path.
- **Slackware packaging**: `.txz` via `build.sh`; `tests/` and repo docs are outside `source/` and never ship.
