# DriveAge - Drive Age Monitor for Unraid

![Version](https://img.shields.io/badge/version-2025.11.26-blue.svg)
![Unraid](https://img.shields.io/badge/unraid-6.12+-orange.svg)
![License](https://img.shields.io/badge/license-MIT-green.svg)
![Security](https://img.shields.io/badge/security-OWASP%20compliant-brightgreen.svg)

DriveAge is an Unraid plugin that provides a unified dashboard to monitor drive health and age based on SMART power-on hours. It helps you identify aging drives at a glance with visual color coding, making drive replacement planning simple and proactive.

## Features

### Core Features
- **Six-Tier Color Coding System**: Drives are color-coded from "Brand New" (dark green) to "Elderly" (bright red) based on power-on hours
- **Human-Readable Time Format**: Displays drive age as years, months, days, and hours (e.g., "2y 10m 5d 20h")
- **Bold Oldest Drives**: Automatically highlights the oldest drives in bold for immediate attention
- **Multi-Array Support**: Full support for Unraid 6.12+ multi-array configurations
- **Comprehensive Drive Information**: Shows device name, model, size, temperature, SMART status, and spin status

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

### Interpreting the Colors

| Color | Category | Default Range | Description |
|-------|----------|---------------|-------------|
| 🟢 Dark Green | Brand New | < 2 years (< 17,520h) | Recently purchased drives |
| 🟢 Green | Newish | 2-3 years (17,520-26,280h) | Drives with light usage |
| 🟢 Light Green | Normal | 3-4 years (26,280-35,040h) | Drives in normal operational range |
| 🟡 Yellow | Aged | 4-5 years (35,040-43,800h) | Consider monitoring closely |
| 🔴 Dark Red | Old | 5-6 years (43,800-52,560h) | Plan for replacement |
| 🔴 Bright Red | Elderly | 6+ years (52,560+h) | Priority replacement candidate |

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
│       │   ├── config.php        # Configuration handler
│       │   ├── smartdata.php     # SMART data collection
│       │   ├── formatting.php    # Formatting utilities
│       │   ├── security.php      # Security functions (CSRF, rate limiting, logging)
│       │   └── helpers.php       # Helper functions (escaping, validation)
│       ├── scripts/
│       │   └── get_drive_data.php # AJAX endpoint with rate limiting
│       ├── styles/
│       │   └── driveage.css      # Stylesheet
│       └── js/
│           └── dashboard.js      # Dashboard interactivity with XSS protection
├── docs/
│   ├── screenshots/
│   ├── SECURITY_REVIEW.md        # Comprehensive security audit
│   └── SECURITY_FIXES_COMPLETED.md # Implementation details
└── README.md
```

## Roadmap

### Future Enhancements (Not in Current Version)
- Email notifications when drives cross age thresholds
- Historical tracking and trend graphs
- Drive replacement predictions
- Integration with drive warranty databases
- Multi-language support
- Export to PDF/CSV

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
- **Documentation**: [Requirements Document](REQUIREMENTS.md)

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
