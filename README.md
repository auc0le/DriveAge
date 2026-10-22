# DriveAge - Drive Age Monitor for Unraid

![Version](https://img.shields.io/badge/version-2026.07.16-blue.svg)
![Unraid](https://img.shields.io/badge/unraid-6.12+-orange.svg)
![License](https://img.shields.io/badge/license-MIT-green.svg)

DriveAge is an Unraid plugin that provides drive health monitoring with **dual risk assessment** and **predictive replacement estimates**. It uses age-based analysis for HDDs (calibrated to Backblaze's 2025 drive-stats data) and wear-based assessment for SSDs and NVMe drives (the drive's own wear indicator, projected at its measured wear rate), and keeps that separate from each drive's own SMART evidence - helping you plan replacements before failures occur.

## Features

### Core Features
- **Two Separate Questions, Both Shown**:
  - **Age / wear band**: HDDs by age, calibrated to Backblaze's 2025 bathtub-curve analysis (~317k drives); SSDs and NVMe by their own wear indicator
  - **SMART health**: Healthy / Warning / Failing from the drive's own SMART data
  - The row color is the worse of the two, and the label says which one decided it
- **Predictive Replacement Estimates**:
  - HDD: SMART evidence first; otherwise a countdown to a target age (8 years conservative / 10 years aggressive), reading "Due now" at the target
  - SSD/NVMe: the drive's wear indicator (NVMe Percentage Used, SATA SSD wear attribute) projected at its **own measured wear rate** - including the recent write rate once DriveAge has a month of history
- **Health Warning Detection**:
  - HDD: all five SMART attributes Google's disk study identified as the strongest failure predictors - reallocated sectors (5), reported uncorrectable errors (187), command timeouts (188), pending sectors (197), uncorrectable sectors (198)
  - **Stable vs growing**: DriveAge remembers each drive's error counts - a count that is still rising is critical, one that has been stable for a month is a warning
  - SAS: grown defect list and uncorrected error counters; SATA SSD: reallocated blocks and reported uncorrectable errors
  - NVMe: media errors, spare below threshold and each critical-warning flag decoded (an over-temperature flag means "improve cooling", not "replace")
  - SMART overall-health **FAILED** is surfaced as a first-class warning and forces High Risk + "Replace Now"
- **Five-Tier Risk Categories** plus an explicit **N/A** category for devices without SMART data (e.g. the USB boot drive) - no more "brand new" USB sticks
- **Confidence Levels**: All predictions include confidence ratings (High/Medium/Low/None) and the estimation method used (visible in tooltips)
- **Conservative vs Aggressive Modes**: 8-year vs 10-year HDD targets; +25% vs no safety margin on measured SSD/NVMe wear rates
- **Human-Readable Time Format**: Displays drive age as years, months, days, and hours (e.g., "2y 10m 5d 20h")
- **Multi-Array Support**: Full support for Unraid 6.12+ multi-pool configurations, unassigned devices, SAS drives behind HBAs, drives behind RAID controllers (Unraid's SMART controller type is honoured), systems with more than 26 drives (`sdaa`+), and multi-digit NVMe names
- **Honest Data Freshness**: Shows how old the underlying SMART cache is and Unraid's actual refresh interval

### Display Options
- **Summary at a glance**: drives needing attention, replacements due within 12 months, and the next replacement
- **Replacement Timeline**: drives (and capacity) due now, within a year, 1-2 years and beyond - for budgeting
- **Drive Details**: click any row for the SMART values, the age/wear band vs SMART health, and how the estimate was worked out
- **Sortable Columns** with a configurable default sort (keyboard accessible; your sort and collapsed groups are remembered)
- **Hierarchical Grouping**: Drives organized like Unraid's Main page (array, cache pool, other pools, unassigned, boot flash) with collapsible groups
- **Drive-Type Filters**: Show/hide parity, array, cache/pool, and unassigned devices
- **Auto-Refresh**: Optional, with a configurable interval
- **Row Coloring**: colored edge + risk badge (default) or the classic full-row color
- **Theme-Aware**: Follows Unraid's white/black/azure/gray themes (charts included); no third-party JavaScript

### Configuration
- **Customizable Thresholds, Labels and Colors** for every risk category
- **Validated On Load**: every configuration value is checked (ranges, ordering, color uniqueness) before use

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
2. View all drives with color-coded risk indicators, a summary and charts
3. Click column headers to sort; click group headers to collapse; click a drive for details
4. Click **Refresh** to update drive data

### Interpreting Risk Categories

Default categories are calibrated to Backblaze's 2025 bathtub-curve revisit: fleet AFR holds roughly flat (~1-1.5%) through most of drive life, then rises near end of life to a peak of **~4.25% at ~10 years 3 months**. All thresholds and labels are configurable.

#### HDD Age-Based Risk (Backblaze 2025 calibration)

| Color | Risk Category | Default Age Band | Approx. AFR | Description |
|-------|--------------|-------------|-----------|-------------|
| 🟢 Green | Minimal Risk | < 4 years | ~1% | Early life, lowest failure rates |
| 🟢 Light Green | Low Risk | 4-6 years | ~1.5% | Normal mid-life operation |
| 🟡 Amber | Moderate Risk | 6-8 years | ~2% | Later mid-life; start budgeting |
| 🟠 Orange | Elevated Risk | 8-10 years | ~2.5-4% | Approaching the end-of-life ramp |
| 🔴 Red | High Risk | ≥ 10 years | ~4%+ | At/beyond the measured AFR peak |
| ⬜ Gray | N/A | - | - | No SMART data (e.g. USB boot drive) |

#### SMART health overrides the band

A drive's row shows the worse of its band and its SMART health. A **SMART warning** raises it to at least Elevated, labelled "Warning (SMART)"; a **SMART failure** (FAILED, pending/uncorrectable sectors, a rising defect count, NVMe media errors...) raises it to High, labelled "Failing (SMART)". Click the row to see both parts.

#### SSD / NVMe Wear-Based Risk

| Color | Risk Category | Percentage Used | Available Spare |
|-------|--------------|----------------|-----------------|
| 🟢 Green | Minimal Risk | < 50% | > 80% |
| 🟢 Light Green | Low Risk | 50-79% | 51-80% |
| 🟡 Amber | Moderate Risk | 80-100% | ≤ 50% |
| 🟠 Orange | Elevated Risk | > 100% | Within 10 pts of threshold |
| 🔴 Red | High Risk | - | < vendor threshold |

SATA SSDs use the same Percentage Used bands (from their wear attribute); available spare applies to NVMe only. An SSD that reports no wear attribute uses its age band, labelled "(age)".

### Understanding Drive Information

**Device Name**: User-friendly name (e.g., "Disk 20", "Parity", "Cache")
**Identification**: Drive model and serial number (from Unraid's disk id)
**Size**: Drive capacity (Unraid's own sectors × sector size)
**Power On Hours**: Total powered-on hours (cached for spun-down drives, surviving reboots)
**Temperature**: From Unraid's live state - identical to the Main page, and colored with Unraid's own warning/critical limits (per-disk overrides, SSD limits for SSD/NVMe)
**SMART Status**: Overall health self-assessment (PASSED/FAILED)
**Spin Status**: Active or standby, from Unraid's live state
**Health**: SMART evidence (click any warning for the built-in Help page):
  - **Failing** (replace now): SMART FAILED, pending sectors, uncorrectable errors, a reallocated/defect count that rose in the last 30 days, >10 reallocated or reported uncorrectable with no history yet, >100 command timeouts, NVMe media errors or degraded/read-only flags
  - **Warning** (monitor): small or stable reallocated/defect counts, 1-100 command timeouts, NVMe over-temperature flag
  - **Healthy**: no warnings detected
**Est. Replacement**: Predicted timeline until recommended replacement - "Replace Now" for SMART evidence, "Due now" when a drive reaches its target age or rated endurance. Click the row for the method, confidence and reasoning.

### Data Freshness

DriveAge reads Unraid's own SMART cache (`/var/local/emhttp/smart/`), which Unraid refreshes on the **Disk Settings → poll_attributes interval (default: 30 minutes)**. The dashboard shows the actual age of the data. Spun-down drives are never woken; their last-known values come from a persistent cache on the flash device.

### Technical Details & Methodology

**[METHODOLOGY.md](METHODOLOGY.md)** documents the full technical detail: Backblaze 2025 calibration, NVMe measured-rate formulas, SMART attribute thresholds, conservative/aggressive math, confidence levels, and limitations.

## JSON Endpoint

Drive data is available as JSON:

```
GET /plugins/driveage/scripts/get_drive_data.php
```

The endpoint sits behind Unraid's webGUI authentication like every other webGUI page - requests must carry a valid Unraid session (or come through an authenticated reverse proxy). It is not reachable anonymously, and there is no separate enable switch: if you can see the dashboard, you can read the JSON.

### Response Format (abridged)
```json
{
  "success": true,
  "timestamp": 1784230000,
  "drive_count": 27,
  "data_age_seconds": 412,
  "poll_interval": 1800,
  "groups": [{ "name": "Main Array", "type": "array", "device_ids": ["sdc", "sdd"] }],
  "drives": [
    {
      "device_name": "Disk 20",
      "identification": "WDC_WD200EDGZ-11BLDS0_XXXXXXX",
      "size_human": "20TB",
      "power_on_hours": 24980,
      "power_on_human": "2y 10m 6d 20h",
      "temperature": 34,
      "temperature_class": "temp-normal",
      "smart_status": "PASSED",
      "smart_status_class": "smart-passed",
      "spin_status": "active",
      "age_category": "minimal_risk",
      "age_label": "Minimal Risk (AFR ~1%)",
      "health_status": "ok",
      "health_label": "Healthy",
      "risk_category": "minimal_risk",
      "risk_label": "Minimal Risk (AFR ~1%)",
      "health_warnings": [],
      "replacement_prediction": {
        "months_remaining": 61,
        "confidence": "medium",
        "method": "age_linear",
        "timeline_text": "5.1 years"
      }
    }
  ],
  "thresholds": { "minimal_risk": 35040, "low_risk": 52560, "moderate_risk": 70080, "elevated_risk": 87600 },
  "labels": { "...": "..." },
  "colors": { "...": "..." }
}
```

All fields are plain data (no HTML fragments), so the endpoint is directly consumable by monitoring tools.

## Security Posture

- **Access control**: provided by Unraid's webGUI session authentication (all `/plugins/*` requests are authenticated). Settings are saved through Unraid's own `/update.php`, which enforces Unraid's native CSRF token.
- **Validation on load**: every configuration value read from disk is validated (whitelists, ranges, threshold ordering, color format/uniqueness, label sanitization) before use.
- **Output escaping**: all dynamic content is escaped in PHP (`esc()`/`e()`) and JavaScript (`escapeHtml()`).
- **No shell exposure**: drive enumeration comes from Unraid's state files; the only shell calls are `smartctl`/`blockdev` fallbacks with `escapeshellarg()` on validated device names and whitelisted controller options.
- **Read-only by design**: the plugin never writes to your drives; its only writes are its own config, a small drive cache on the flash device (last-known power-on hours plus error-count/write history, write-on-change), and short-lived smartctl results in RAM (`/tmp`).

## Configuration File

Settings are stored in `/boot/config/plugins/driveage/driveage.cfg`:

```ini
# Display Configuration
DEFAULT_SORT="replacement_months"
DEFAULT_SORT_DIR="asc"
ROW_STYLE="accent"               # accent (colored edge + badge) or full (whole row)

# Refresh Configuration
AUTO_REFRESH="false"
REFRESH_INTERVAL="300"

# Age Thresholds (in hours) - Backblaze 2025 calibration
THRESHOLD_MINIMAL_RISK="35040"    # < 4 years
THRESHOLD_LOW_RISK="52560"        # 4-6 years
THRESHOLD_MODERATE_RISK="70080"   # 6-8 years
THRESHOLD_ELEVATED_RISK="87600"   # 8-10 years

# Prediction Mode: conservative (8yr target) or aggressive (10yr target)
PREDICTION_MODE="conservative"

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
```

Category labels and colors (`LABEL_*`, `COLOR_*`, `TEXTCOLOR_*`) are managed from the Settings page.

**Upgrading from earlier versions**: your existing thresholds and labels are preserved. To adopt the new Backblaze-2025 defaults, use **Reset to Defaults** on the Settings page and Apply.

## Requirements

- **Unraid Version**: 6.12.0 or higher
- **Architecture**: x86_64
- **Dependencies**: `smartmontools` (pre-installed on Unraid), PHP 8.x (Unraid standard)

## Troubleshooting

### No Drives Displayed
- Check that the array has been started at least once (DriveAge enumerates from `disks.ini`/`devs.ini`)
- Run the diagnostic: `php /usr/local/emhttp/plugins/driveage/scripts/diagnose.php`

### Incorrect Power-On Hours
- Some drive vendors store power-on hours in odd raw formats; DriveAge parses the leading hour count
- Compare with `smartctl -a /dev/sdX` output and report mismatches on GitHub with the drive model

### Temperature Not Showing
- Temperatures come from Unraid's live state and match the Main page; spun-down drives show N/A

### Stale Data
- The "SMART data" line in the header shows the cache age. Unraid refreshes SMART on the Disk Settings poll interval (default 30 minutes) - lower `poll_attributes` in **Settings → Disk Settings** if you need fresher attributes

## Development

### Building from Source

```bash
git clone https://github.com/auc0le/DriveAge.git
cd DriveAge
./build.sh          # builds archive/driveage-YYYY.MM.DD-x86_64-N.txz and stamps version.php
php tests/run.php   # run the test suite (no Unraid required)
```

### File Structure
```
DriveAge/
├── plugins/
│   └── driveage.plg              # Plugin manifest
├── source/driveage/
│   └── usr/local/emhttp/plugins/driveage/
│       ├── DriveAge.page          # Menu container (tabs)
│       ├── DriveAgeDashboard.page # Dashboard tab
│       ├── DriveAgeSettings.page  # Settings tab
│       ├── DriveAgeHelp.page      # Help tab (SMART warning reference)
│       ├── include/
│       │   ├── version.php       # Single version source (written by build.sh)
│       │   ├── config.php        # Config load + validation (validated on load)
│       │   ├── risk.php          # Risk policy: age/wear band, SMART health, overall
│       │   ├── smartparse.php    # smartctl parsers (ATA/NVMe/SCSI, text+JSON) - pure functions
│       │   ├── smartdata.php     # Enumeration (disks.ini/devs.ini) + assembly + health warnings
│       │   ├── predictions.php   # Replacement estimates (flash wear model, 8/10yr HDD targets)
│       │   ├── cache.php         # Persistent drive cache: POH + error/write history (flash, write-on-change)
│       │   ├── formatting.php    # Plain-text formatting + CSS class mapping
│       │   └── helpers.php       # Escaping and form helpers
│       ├── scripts/
│       │   ├── get_drive_data.php # JSON endpoint
│       │   └── diagnose.php       # CLI diagnostic
│       ├── styles/driveage.css    # Theme-aware stylesheet
│       └── js/
│           ├── dashboard.js       # Summary, table, details, sorting, auto-refresh
│           ├── charts.js          # Risk donut + replacement timeline (inline SVG/CSS)
│           └── settings.js        # Reset-to-defaults
├── tests/
│   ├── run.php                    # Test runner (php tests/run.php)
│   └── fixtures/                  # Captured smartctl outputs (ATA/SSD/NVMe/SAS/standby)
├── METHODOLOGY.md                 # Technical documentation & research sources
└── README.md
```

## Roadmap

### Future Enhancements
- Email/agent notifications when drives reach critical thresholds
- Trend graphs for drive wear over time (the history is already recorded)
- Export reports to CSV
- Multi-language support

## Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Run `php tests/run.php` and add tests for new parsing/prediction logic
4. Commit your changes and open a Pull Request

## Support

- **Issues**: [GitHub Issues](https://github.com/auc0le/DriveAge/issues)
- **Documentation**: [README.md](README.md) · [METHODOLOGY.md](METHODOLOGY.md)

## License

MIT License - See [LICENSE](LICENSE) file for details

## Credits

- **Author**: Tony Coleman
- **Research**: Backblaze Drive Stats (2025 bathtub-curve revisit), Google disk reliability study
- **Built with**: PHP, JavaScript, and lots of coffee ☕

## Disclaimer

DriveAge is a monitoring tool and does not predict drive failures. SMART data provides historical information but cannot guarantee future reliability. Always maintain proper backups and follow best practices for data protection.

---

**Made with ❤️ for the Unraid community**
