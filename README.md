# DriveAge - Drive Age Monitor for Unraid

![Version](https://img.shields.io/badge/version-2025.12.26-blue.svg)
![Unraid](https://img.shields.io/badge/unraid-6.12+-orange.svg)
![License](https://img.shields.io/badge/license-MIT-green.svg)
![Security](https://img.shields.io/badge/security-OWASP%20compliant-brightgreen.svg)

DriveAge is an Unraid plugin that provides intelligent drive health monitoring with **dual risk assessment** and **predictive replacement estimates**. It uses age-based analysis for HDDs and wear-based assessment for NVMe drives, helping you proactively plan drive replacements before failures occur.

## Features

### Core Features
- **Dual Risk Assessment Model**:
  - **HDD**: Age-based risk using Annual Failure Rate (AFR) curves from Backblaze data
  - **NVMe**: Wear-based risk using Percentage Used and Available Spare metrics
- **Predictive Replacement Estimates**: Calculates estimated time until recommended replacement based on:
  - HDD: Age + AFR curves + critical SMART attributes
  - NVMe: TBW calculations + wear rate projections
- **Health Warning Detection**: Monitors critical SMART attributes for pre-failure indicators:
  - Pending sectors, reallocated sectors, uncorrectable errors (HDD)
  - Media errors, available spare depletion, critical warnings (NVMe)
- **Five-Tier Risk Categories**: Drives color-coded by risk level (Minimal → Low → Moderate → Elevated → High)
- **Confidence Levels**: All predictions include confidence ratings (High/Medium/Low/None) based on data quality
- **Conservative vs Aggressive Modes**: User-selectable prediction modes (default: conservative for safer estimates)
- **Human-Readable Time Format**: Displays drive age as years, months, days, and hours (e.g., "2y 10m 5d 20h")
- **Bold Oldest Drives**: Automatically highlights the oldest drives in bold for immediate attention
- **Multi-Array Support**: Full support for Unraid 6.12+ multi-array configurations
- **Comprehensive Drive Information**: Shows device name, model, size, temperature, SMART status, spin status, health warnings, and replacement timeline

### Display Options
- **Sortable Columns**: Click any column header to sort (device, size, age, temperature, SMART status)
- **Hierarchical Grouping**: Drives organized by array and type (Parity, Array, Cache, Pool, Unassigned)
- **Collapsible Groups**: Expand/collapse drive groups for easier navigation

### Configuration
- **Customizable Age Thresholds**: Define your own hour ranges for each color tier
- **Display Filters**: Show/hide specific drive types and columns
- **Refresh Options**: Manual or auto-refresh with configurable intervals
- **JSON API**: Optional REST API for integration with monitoring tools (disabled by default)

### Security
- **OWASP Top 10 Compliant**: Comprehensive protection against common web vulnerabilities
- **XSS Prevention**: All output properly escaped in PHP and JavaScript
- **CSRF Protection**: Session-based tokens protect against cross-site request forgery
- **Command Injection Prevention**: Strict validation and safe device discovery methods
- **Rate Limiting**: Configurable IP-based rate limiting prevents API abuse
- **Security Logging**: All security events logged for audit and monitoring

## Installation

### Via Community Applications (Not yet available)
1. Open Unraid WebGUI
2. Navigate to **Apps** tab
3. Search for "DriveAge"
4. Click **Install**

### Manual Installation
1. Navigate to **Plugins** → **Install Plugin**
2. Paste this URL:
   ```
   https://raw.githubusercontent.com/auc0le/DriveAge/main/plugins/driveage.plg
   ```
3. Click **Install**

## Usage

### Accessing the Dashboard
1. Navigate to **Settings** → **User Utilities** → **DriveAge**
2. View all drives with color-coded age indicators
3. Click column headers to sort
4. Toggle between table and card views
5. Click **Refresh** to update drive data

### Interpreting Risk Categories

DriveAge uses different risk assessment methods for HDDs and NVMe drives:

#### HDD Age-Based Risk (Based on Backblaze AFR Data)

| Color | Risk Category | Typical Age | AFR Range | Description |
|-------|--------------|-------------|-----------|-------------|
| 🟢 Green | Minimal Risk | < 2 years | < 1.5% | Brand new drives, minimal failure risk |
| 🟢 Light Green | Low Risk | 2-3 years | 1.5-2% | Normal operation, low failure risk |
| 🟡 Amber | Moderate Risk | 3-4 years | 2-5% | Mid-life drives, moderate failure risk |
| 🟠 Orange | Elevated Risk | 4-5 years | 5-10% | Aging drives, elevated failure risk |
| 🔴 Red | High Risk | 5-6 years | 10-15% | Old drives, plan replacement |
| 🔴 Dark Red | High Risk | 6+ years | > 15% | Elderly drives, priority replacement |

**Note**: HDDs with critical SMART warnings (pending sectors, uncorrectable errors) are automatically categorized as High Risk regardless of age.

#### NVMe Wear-Based Risk

| Color | Risk Category | Percentage Used | Available Spare | Description |
|-------|--------------|----------------|-----------------|-------------|
| 🟢 Green | Minimal Risk | < 50% | > 80% | Minimal wear, plenty of spare capacity |
| 🟢 Light Green | Low Risk | 50-79% | 60-80% | Low wear, good spare capacity |
| 🟡 Amber | Moderate Risk | 80-99% | 40-60% | Moderate wear, spare declining |
| 🟠 Orange | Elevated Risk | 100-120% | 20-40% | High wear, spare capacity low |
| 🔴 Red | High Risk | > 120% | < 20% | Critical wear or spare depletion |

**Note**: NVMe drives with media errors or critical warnings are automatically categorized as High Risk.

### Configuring Thresholds
1. Navigate to **Settings** → **User Utilities** → **DriveAge Settings**
2. Adjust the hour thresholds for each age category
3. See real-time preview of color scheme
4. Click **Apply Settings** to save

### Understanding Drive Information

**Device Name**: User-friendly name (e.g., "Disk 20", "Parity", "Cache")
**Identification**: Drive model and serial number
**Size**: Drive capacity
**Power On Hours**: Total hours the drive has been powered on
**Age**: Human-readable format (years, months, days, hours)
**Temperature**: Current drive temperature from SMART
**SMART Status**: Overall health assessment (PASSED/FAILED)
**Spin Status**: Whether drive is active or in standby
**Health Status**: Critical SMART attribute warnings:
  - ⚠️ **Critical Warning** (red): Immediate attention required (pending sectors, media errors)
  - ⚡ **Caution** (orange): Monitor closely (reallocated sectors, reported errors)
  - ✓ **Healthy** (green): No warnings detected
**Est. Replacement**: Predicted timeline until recommended replacement:
  - **Replace Now** (red): Critical condition, replace immediately
  - **N months** (orange): Replace within timeframe
  - **Within 1 year** (yellow): Plan replacement this year
  - **N years** (green): Normal lifespan remaining
  - **5+ years** (bright green): Plenty of life remaining
  - Hover over values to see confidence level, estimation method, and detailed notes

### Technical Details & Methodology

For in-depth technical information about risk assessment algorithms, prediction methods, research sources, and assumptions, see:

**[METHODOLOGY.md](METHODOLOGY.md)** - Comprehensive technical documentation including:
- Backblaze AFR curve research and data sources
- NVMe TBW estimation formulas and write rate assumptions
- Critical SMART attribute thresholds and failure indicators
- Conservative vs Aggressive mode calculation details
- Confidence level determination logic
- Limitations and assumptions for all prediction methods

## JSON API (Optional)

The JSON API allows external monitoring tools to access drive age data.

**Security Note**: The API is **disabled by default**. Enable it only if needed for integrations.

### Enabling the API
1. Navigate to **Settings** → **DriveAge Settings**
2. Check **Enable JSON API**
3. Configure rate limit (default: 100 requests/minute)
4. Click **Apply Settings**

### API Endpoint
```
GET http://[unraid-ip]/plugins/driveage/scripts/get_drive_data.php
```

### Response Format
```json
{
  "success": true,
  "timestamp": 1732629840,
  "drive_count": 24,
  "drives": [
    {
      "device_name": "Disk 20",
      "model": "WDC WD200EDGZ-11BLDS0",
      "size_human": "20TB",
      "power_on_hours": 24980,
      "power_on_human": "2y 10m 5d 20h",
      "temperature": 34,
      "smart_status": "PASSED",
      "age_category": "normal",
      "age_label": "Normal"
    }
  ],
  "thresholds": {
    "brand_new": 17520,
    "newish": 26280,
    "normal": 35040,
    "aged": 43800,
    "old": 52560
  }
}
```

### Rate Limiting
- Default: 100 requests per minute
- Configurable: 10-1000 requests/minute
- Exceeded limit returns HTTP 429 with retry-after header
- 1-second cache prevents excessive SMART queries

## Security Features

DriveAge implements comprehensive security measures to protect your Unraid system:

### Cross-Site Scripting (XSS) Prevention
- **Output Encoding**: All user-controlled data is properly escaped using `htmlspecialchars()` with `ENT_QUOTES` and UTF-8 encoding
- **PHP Helper Functions**: `esc()` and `e()` functions ensure consistent escaping throughout templates
- **JavaScript Sanitization**: `escapeHtml()` function prevents XSS in dynamic content rendering
- **Defense-in-Depth**: Multiple layers of validation and encoding protect against injection attacks

### Cross-Site Request Forgery (CSRF) Protection
- **Session-Based Tokens**: Cryptographically secure CSRF tokens generated for all forms
- **Token Validation**: Settings updates require valid CSRF token verification
- **Timing-Safe Comparison**: Uses `hash_equals()` to prevent timing attacks
- **Token Regeneration**: New tokens generated per session for enhanced security

### Command Injection Prevention
- **No Shell Execution**: Replaced all `shell_exec()` calls with safer PHP built-in functions
- **Glob-Based Discovery**: Uses `glob()` with strict path validation for drive discovery
- **Device Validation**: Multi-layer validation ensures only valid block devices are accessed:
  - Regex pattern matching (`/^\/dev\/(sd[a-z]|nvme[0-9]n[0-9])$/`)
  - Symlink protection (rejects symbolic links)
  - File type verification (ensures block device type)
  - Path traversal prevention

### Input Validation & Sanitization
- **Strict Whitelists**: All enum values validated against predefined whitelists
  - View modes: `table`, `card`
  - Sort columns: `power_on_hours`, `device_name`, `size_bytes`, `temperature`
  - Sort directions: `asc`, `desc`
- **Range Limits**: Numeric inputs constrained to safe ranges
  - Thresholds: 0-876,600 hours (max 100 years)
  - Refresh interval: 30-3,600 seconds
  - Rate limit: 10-1,000 requests/minute
- **Boolean Validation**: Checkboxes validated to literal `true`/`false` strings
- **Type Coercion**: All numeric inputs cast to integers to prevent type juggling attacks

### Rate Limiting & Abuse Prevention
- **IP-Based Tracking**: Monitors API requests per IP address
- **Configurable Thresholds**: Adjustable rate limits (10-1,000 req/min)
- **Automatic Cleanup**: Expired rate limit entries automatically purged
- **HTTP 429 Responses**: Proper "Too Many Requests" status with `Retry-After` header
- **Cache Protection**: 60-second cache TTL prevents SMART command flooding

### Security Event Logging
- **Comprehensive Logging**: Security-relevant events logged to `/var/log/driveage_security.log`
- **Logged Events**:
  - Rate limit violations
  - Invalid parameter attempts
  - API access (when enabled)
  - Configuration changes
  - Error conditions
- **JSON Format**: Structured logging with timestamps and context
- **Automatic Rotation**: Log file management to prevent disk space exhaustion

### File System Security
- **Secure Cache Location**: Cache files stored in `/var/lib/driveage/` (not world-readable `/tmp`)
- **Atomic Writes**: Configuration and cache updates use atomic operations with temp files
- **Symlink Protection**: Rejects symbolic links in configuration and cache paths
- **Permission Checks**: Validates file permissions (rejects world-writable files)
- **Directory Permissions**: Cache directory created with 0700 (owner-only access)

### Information Disclosure Prevention
- **Generic Error Messages**: User-facing errors don't reveal system internals
- **Detailed Logging**: Full error details logged server-side for debugging
- **API Error Handling**: Try-catch blocks prevent stack traces from leaking
- **Path Sanitization**: File paths never exposed in error messages

### Configuration Security
- **Protected Storage**: Configuration stored in `/boot/config/plugins/driveage/`
- **Validation on Load**: All configuration values validated and sanitized before use
- **Default Fallbacks**: Invalid configuration falls back to secure defaults
- **No Arbitrary Code**: Configuration is data-only (no code execution)

### Security Best Practices Implemented
- ✅ OWASP Top 10 compliance
- ✅ Defense-in-depth architecture
- ✅ Principle of least privilege
- ✅ Secure by default configuration
- ✅ Input validation at all boundaries
- ✅ Output encoding for all contexts
- ✅ Fail securely on errors
- ✅ Security logging and monitoring

### Security Audit History
- **2025.11.26**: Comprehensive security review completed
  - 7 critical vulnerabilities fixed
  - 6 high-severity issues resolved
  - Full OWASP Top 10 compliance achieved
  - See [SECURITY_REVIEW.md](SECURITY_REVIEW.md) for details

### Reporting Security Issues
If you discover a security vulnerability, please email security@[domain] or open a private security advisory on GitHub. Do not open public issues for security vulnerabilities.

## Configuration File

Settings are stored in `/boot/config/plugins/driveage/driveage.cfg`

```ini
# Display Configuration
VIEW_MODE="table"
DEFAULT_SORT="power_on_hours"
DEFAULT_SORT_DIR="desc"

# Age Thresholds (in hours)
THRESHOLD_BRAND_NEW="17520"
THRESHOLD_NEWISH="26280"
THRESHOLD_NORMAL="35040"
THRESHOLD_AGED="43800"
THRESHOLD_OLD="52560"

# Prediction Mode
PREDICTION_MODE="conservative"  # Options: "conservative" (default), "aggressive"
                                # Conservative: Assumes faster wear, shorter lifespan (safer estimates)
                                # Aggressive: Assumes slower wear, longer lifespan (optimistic estimates)

# Display Filters
SHOW_PARITY="true"
SHOW_ARRAY="true"
SHOW_CACHE="true"
SHOW_POOL="true"
SHOW_UNASSIGNED="true"

# Column Visibility
SHOW_TEMPERATURE="true"
SHOW_SMART_STATUS="true"
SHOW_SPIN_STATUS="true"

# JSON API Configuration
API_ENABLED="false"
API_RATE_LIMIT="100"
```

## Requirements

- **Unraid Version**: 6.12.0 or higher
- **Architecture**: x86_64
- **Dependencies**:
  - `smartmontools` (pre-installed on Unraid)
  - PHP 7.4+ (Unraid standard)

## Troubleshooting

### No Drives Displayed
- Ensure drives support SMART (run `smartctl -a /dev/sdX`)
- Check that smartmontools is installed
- Verify drives are assigned in Unraid

### Incorrect Power-On Hours
- Some drive manufacturers store power-on hours differently
- Compare with `smartctl -a /dev/sdX` output
- Report issues on GitHub with drive model

### Temperature Not Showing
- Not all drives report temperature via SMART
- NVMe drives may use different attribute IDs
- Check SMART output with `smartctl -a /dev/sdX`

### Performance Issues
- Increase cache TTL in code (default: 60 seconds)
- Disable auto-refresh
- Reduce number of monitored drives with filters

## Development

### Building from Source

1. Clone the repository:
   ```bash
   git clone https://github.com/auc0le/DriveAge.git
   cd DriveAge
   ```

2. Build the package:
   ```bash
   ./build.sh
   ```

3. Package will be created in `archive/` directory

### File Structure
```
DriveAge/
├── plugins/
│   └── driveage.plg              # Plugin manifest
├── source/driveage/
│   └── usr/local/emhttp/plugins/driveage/
│       ├── DriveAge.page         # Main dashboard
│       ├── DriveAgeSettings.page # Settings page
│       ├── include/
│       │   ├── config.php        # Configuration handler & risk assessment
│       │   ├── smartdata.php     # SMART data collection & health warnings
│       │   ├── predictions.php   # Predictive replacement estimates (NEW)
│       │   ├── formatting.php    # Formatting utilities
│       │   ├── security.php      # Security functions (CSRF, rate limiting, logging)
│       │   └── helpers.php       # Helper functions (escaping, validation)
│       ├── scripts/
│       │   └── get_drive_data.php # AJAX endpoint with rate limiting
│       ├── styles/
│       │   └── driveage.css      # Stylesheet with risk category colors
│       └── js/
│           └── dashboard.js      # Dashboard interactivity with XSS protection
├── docs/
│   ├── screenshots/
│   ├── SECURITY_REVIEW.md        # Comprehensive security audit
│   └── SECURITY_FIXES_COMPLETED.md # Implementation details
├── METHODOLOGY.md                # Technical documentation & research sources (NEW)
└── README.md
```

## Roadmap

### Completed Features (v2025.12.26)
- ✅ Dual risk assessment model (age-based for HDD, wear-based for NVMe)
- ✅ Predictive replacement estimates with confidence levels
- ✅ Health warning detection for critical SMART attributes
- ✅ Conservative vs aggressive prediction modes

### Future Enhancements
- Email notifications when drives reach critical thresholds or high risk
- Historical tracking and trend graphs for drive wear over time
- Integration with manufacturer warranty databases
- Multi-language support
- Export reports to PDF/CSV
- Machine learning-based failure prediction refinement

## Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## Support

- **Forum**: [Unraid Community Forums](https://forums.unraid.net/topic/xxxxx-driveage/)
- **Issues**: [GitHub Issues](https://github.com/auc0le/DriveAge/issues)
- **Documentation**:
  - [README.md](README.md) - User guide and feature overview
  - [METHODOLOGY.md](METHODOLOGY.md) - Technical details and research sources
  - [REQUIREMENTS.md](REQUIREMENTS.md) - Original requirements document

#### Security Enhancements
- Comprehensive XSS prevention (output encoding in PHP and JavaScript)
- CSRF protection with session-based tokens
- Command injection prevention (glob-based discovery, device validation)
- Strict input validation with whitelists and range limits
- IP-based rate limiting with configurable thresholds
- Security event logging to `/var/log/driveage_security.log`
- Atomic file operations with symlink protection
- Information disclosure prevention with generic error messages
- Full OWASP Top 10 compliance

## License

MIT License - See [LICENSE](LICENSE) file for details

## Credits

- **Author**: Tony Coleman
- **Inspired by**: Unraid community feedback and drive monitoring needs
- **Built with**: PHP, JavaScript, and lots of coffee ☕

## Disclaimer

DriveAge is a monitoring tool and does not predict drive failures. SMART data provides historical information but cannot guarantee future reliability. Always maintain proper backups and follow best practices for data protection.

---

**Made with ❤️ for the Unraid community**
