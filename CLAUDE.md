# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

DriveAge is an Unraid plugin that monitors drive health and age based on SMART power-on hours. It provides a dashboard with six-tier color coding (brand new → elderly) and displays comprehensive drive information including temperature, SMART status, and spin state. The plugin is security-hardened and OWASP Top 10 compliant.

## Build and Test Commands

### Building the Plugin
```bash
./build.sh
```
This creates a Slackware `.txz` package in `archive/` directory with auto-incrementing build numbers. The script:
- Auto-increments build numbers for same-day versions
- Generates MD5 checksums
- Updates the plugin manifest (`plugins/driveage.plg`) with new version, build, and MD5

### Testing on Unraid
After building, manually:
1. Upload `.txz` package and `.md5` file to GitHub releases
2. Update `plugins/driveage.plg` if needed
3. Install on Unraid via Plugins → Install Plugin using the GitHub raw URL

### No Automated Tests
There are no unit tests or linting tools configured. All testing is done manually on Unraid systems.

## Architecture

### Core System Flow

1. **Dashboard (`DriveAge.page`)**: Main UI that loads drive data via AJAX from `get_drive_data.php`
2. **AJAX Endpoint (`scripts/get_drive_data.php`)**: Returns JSON with all drive data, enforces rate limiting and API enable/disable
3. **SMART Data Collection (`include/smartdata.php`)**: Discovers drives using `glob()` and reads SMART data from Unraid's cached files (`/var/local/emhttp/smart/`) for performance
4. **Configuration (`include/config.php`)**: Manages thresholds and settings stored in `/boot/config/plugins/driveage/driveage.cfg`
5. **Security Layer (`include/security.php`)**: CSRF tokens, rate limiting, security logging

### Key Design Decisions

**Performance Optimization**: The plugin uses Unraid's pre-cached SMART data from `/var/local/emhttp/smart/` instead of running `smartctl` directly. This reduces dashboard load time from 26-52 seconds to ~100ms. Falls back to direct `smartctl` queries if cache unavailable.

**Security-First Architecture**:
- All drive discovery uses `glob()` instead of `shell_exec()` to prevent command injection
- Multi-layer device validation (regex, symlink check, block device type verification)
- CSRF tokens for all settings forms
- IP-based rate limiting for JSON API
- All output escaped via `e()` and `esc()` helpers to prevent XSS

**Unraid Integration**: Reads from Unraid's system files:
- `/var/local/emhttp/disks.ini` - Drive assignments (parity, array, cache)
- `/var/local/emhttp/var.ini` - Unraid variables
- `/var/local/emhttp/smart/{device}` - Cached SMART data

### File Organization

```
source/driveage/usr/local/emhttp/plugins/driveage/
├── DriveAge.page              # Main dashboard (Unraid .page format)
├── DriveAgeSettings.page      # Settings interface
├── include/
│   ├── config.php             # Configuration load/save/validate
│   ├── smartdata.php          # Drive discovery and SMART parsing
│   ├── formatting.php         # Display formatting utilities
│   ├── security.php           # Rate limiting, CSRF, logging
│   └── helpers.php            # XSS prevention (e(), esc())
├── scripts/
│   └── get_drive_data.php     # AJAX endpoint for dashboard
├── styles/
│   └── driveage.css           # Six-tier color coding CSS
└── js/
    └── dashboard.js           # Frontend interactivity, XSS-safe rendering
```

### Unraid .page Format

Unraid plugins use `.page` files (not `.php`). Format:
```
Menu="Category"
Title="Page Title"
Icon="icon.png"
---
<?PHP /* PHP code here */ ?>
<html content>
```

The three lines before `---` are Unraid metadata. Everything after is standard PHP/HTML.

### SMART Data Parsing Strategy

1. **Primary**: Read from `/var/local/emhttp/smart/{device}` (Unraid's cache, updated by emhttpd)
2. **Secondary**: Run `smartctl -a -j {device}` for JSON output
3. **Fallback**: Run `smartctl -a {device}` and parse text output

Critical SMART attributes:
- Attribute ID 9: Power_On_Hours
- Attribute ID 194: Temperature_Celsius
- SMART overall-health status

### Age Categories and Thresholds

Six configurable tiers (default hours):
- **brand_new**: < 17,520h (< 2 years)
- **newish**: 17,520-26,280h (2-3 years)
- **normal**: 26,280-35,040h (3-4 years)
- **aged**: 35,040-43,800h (4-5 years)
- **old**: 43,800-52,560h (5-6 years)
- **elderly**: ≥ 52,560h (≥ 6 years)

Thresholds must be in ascending order; invalid configurations reset to defaults.

## Security Requirements

**CRITICAL**: This plugin is security-hardened. When modifying code:

### Input Validation Rules
- All enum values (view modes, sort columns, sort directions) must use strict whitelists with `in_array($value, $whitelist, true)`
- All numeric inputs must be constrained with `max()` and `min()` and cast to `intval()`
- Boolean values must be validated to literal `'true'` or `'false'` strings
- Device paths MUST match `/^\/dev\/(sd[a-z]|nvme[0-9]n[0-9])$/` regex AND pass block device validation

### Output Escaping Rules
- PHP templates: Always use `e()` or `esc()` helper for any dynamic content
- JavaScript: Always use `escapeHtml()` function when inserting user-controlled data
- Never output raw variables directly

### Command Execution Rules
- **NEVER** use `shell_exec()`, `exec()`, `system()` with user input
- Use `glob()` for file discovery instead of shell commands
- Use `escapeshellarg()` for any unavoidable shell commands (e.g., `smartctl`, `blockdev`)
- Prefer PHP built-in functions over shell commands

### File Operation Rules
- All cache/config files must be validated: not symlinks, not world-writable
- Use atomic writes (write to temp file, then `rename()`)
- Cache directory: `/var/lib/driveage/` with 0700 permissions (not `/tmp`)
- Config directory: `/boot/config/plugins/driveage/` (persists across reboots)

### Rate Limiting
- JSON API is disabled by default (`API_ENABLED='false'`)
- Rate limits: 10-1000 requests/minute (default 100)
- Internal dashboard requests bypass API enable check but still log

### CSRF Protection
When adding forms that modify settings:
```php
// Generate token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// In form
<input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']); ?>">

// Validate
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    die('CSRF validation failed');
}
```

## Common Development Workflows

### Adding a New Age Category
1. Update defaults in `config.php` `getDefaultConfig()`
2. Add threshold validation in `validateConfig()`
3. Add label in `getAgeLabel()`
4. Update `getAgeCategory()` logic
5. Add CSS class in `styles/driveage.css`
6. Update legend in `DriveAge.page`

### Adding a New Configuration Option
1. Add to defaults in `getDefaultConfig()` in `config.php`
2. Add validation logic in `validateConfig()`
3. Add to `saveConfig()` INI generation
4. Update settings form in `DriveAgeSettings.page` with CSRF protection
5. Use new config value in relevant code

### Modifying SMART Data Collection
All changes must maintain the cache-first approach:
1. Try Unraid cache (`getSmartDataFromUnraidCache()`)
2. Fallback to direct query (`getSmartData()`)
3. Update cache on successful query (`saveCache()`)

### Adding API Endpoints
1. Check API enabled: `if ($config['API_ENABLED'] !== 'true')`
2. Enforce rate limiting: `checkRateLimit($config, false)`
3. Validate all inputs
4. Escape all outputs
5. Log security events: `logSecurityEvent($event, $data)`

## Version and Release Process

Version format: `YYYY.MM.DD` (e.g., `2025.11.27`)

Build number auto-increments for same-day builds (e.g., Build 39, Build 40).

Release checklist (from `build.sh` output):
1. Run `./build.sh`
2. Commit and push to GitHub (including updated `plugins/driveage.plg`)
3. Upload package and MD5 to GitHub releases
4. Test installation on Unraid

## Configuration Files

### Plugin Manifest (`plugins/driveage.plg`)
XML format with entities for version, build, and MD5. Auto-updated by `build.sh`.

### User Configuration (`/boot/config/plugins/driveage/driveage.cfg`)
INI format, persists across reboots (Unraid's `/boot` is the USB flash drive).

### Cache Files (`/var/lib/driveage/`)
JSON cache for SMART data (TTL: 300 seconds). Lost on reboot (ramdisk), regenerated on first access.

## Important Constraints

- **No npm/node**: This is pure PHP/JavaScript, no build tools
- **No backend framework**: Vanilla PHP, no composer dependencies
- **Unraid-specific paths**: All system integration assumes Unraid 6.12+ file structure
- **Slackware packaging**: Must use `.txz` format for Unraid plugin system
- **Shell availability**: Can assume bash, standard GNU tools, smartmontools
