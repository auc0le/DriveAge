# DriveAge Plugin - Requirements Document
**Version:** 1.0
**Date:** 2025-11-26
**Target Platform:** Unraid 6.12+

---

## 1. Executive Summary

### 1.1 Plugin Overview
**Name:** DriveAge
**Category:** System Monitoring / Storage Management
**Purpose:** Provide a unified dashboard displaying drive age and health information based on SMART power-on hours, with visual color coding to help users identify aging drives at a glance.

### 1.2 Target Users
- Unraid server administrators monitoring drive health
- Users planning drive replacements
- Users managing multi-array configurations
- Users wanting quick visual assessment of storage infrastructure age

### 1.3 Problem Statement
Unraid users currently need to manually check SMART data for each drive to assess drive age and plan replacements. DriveAge consolidates this information into a single, color-coded dashboard with human-readable formats, making drive age assessment immediate and actionable.

---

## 2. Functional Requirements

### 2.1 Core Features

#### 2.1.1 Drive Data Display
For each drive detected by Unraid, display:
- **Device Name**: User-friendly identifier (e.g., "Disk 20", "Cache", "Parity")
- **Identification**: Technical identification (e.g., "WDC_WD200EDGZ-11BLDS0_2LGBS1XK (sdi)")
- **Size**: Drive capacity (e.g., "20TB")
- **Power On Hours (Raw)**: Raw SMART attribute value (e.g., "24980")
- **Power On Hours (Human)**: Human-readable format (e.g., "2y 10m 5d 20h")
- **Temperature**: Current drive temperature from SMART data
- **SMART Status**: Overall health status (PASSED/FAILED)
- **Spin Down Status**: Whether drive is currently spun up or down

#### 2.1.2 Drive Grouping
Drives must be organized hierarchically by:
1. **Array**: Support for multiple arrays (Unraid 6.12+ multi-array feature)
   - Array 1, Array 2, etc.
   - Unassigned devices (not in any array)

2. **Within Each Array, Group By Type**:
   - Parity Disks
   - Array Disks
   - Cache Disks
   - Pool Disks
   - Unassigned Devices

#### 2.1.3 Sorting Capabilities
- **Primary Sort**: By power-on hours (descending - oldest first)
- **User-Configurable Sorting**: Allow sorting by:
  - Device name
  - Size
  - Temperature
  - SMART status
  - Age (power-on hours)

#### 2.1.4 Color Coding System
Six-tier color coding based on power-on hours:

| Age Range | Hours Range | Color | Label | Description |
|-----------|-------------|-------|-------|-------------|
| < 2 years | 0 - 17,519 h | Dark Green | Brand New | Recently purchased drives |
| 2 - 3 years | 17,520 - 26,279 h | Green | Newish | Drives with light usage |
| 3 - 4 years | 26,280 - 35,039 h | Light Green | Normal | Drives in normal operational range |
| 4 - 5 years | 35,040 - 43,799 h | Yellow | Aged | Drives approaching replacement consideration |
| 5 - 6 years | 43,800 - 52,559 h | Dark Red | Old | Drives that should be monitored closely |
| 6+ years | 52,560+ h | Bright Red | Elderly | Drives that should be prioritized for replacement |

**Note:** All thresholds are configurable by the user.

### 2.2 Configuration Options

#### 2.2.1 Age Threshold Configuration
- Allow users to customize hour thresholds for each color tier
- Provide reset-to-defaults option
- Validate that thresholds are in ascending order

#### 2.2.2 Display Filters
- Show/hide specific drive types (parity, array, cache, pool, unassigned)
- Show/hide specific columns (temperature, SMART status, spin status)
- Show/hide specific arrays

#### 2.2.3 Refresh Interval
- **Default**: Load data only when dashboard page is accessed
- **Configurable Options**:
  - Load on page access only (default)
  - Auto-refresh at specified intervals (30s, 5m, 15m, 30m)
  - Manual refresh button always available

#### 2.2.4 Sorting Options
- Default sort column
- Default sort direction (ascending/descending)
- Preserve user's last sorting preference

### 2.3 User Interface Requirements

#### 2.3.1 Multiple View Modes
Support two display modes with user toggle:

**Table View**:
- Compact, sortable table
- All columns visible
- Row color coding based on age
- Click column headers to sort
- Sticky header on scroll

**Card View**:
- Visual card for each drive
- Large, prominent color coding
- Key information displayed prominently
- Grid layout (responsive)
- Good for visual scanning

#### 2.3.2 View Toggle
- Persistent toggle button (table/card icons)
- Remember user's last selected view
- Smooth transition between views

### 2.4 Export & Integration Features

#### 2.4.1 CSV Export
- Export current filtered/sorted view to CSV
- Include all columns
- Filename format: `driveage_export_YYYY-MM-DD_HH-MM.csv`

#### 2.4.2 Print View
- Printer-friendly layout
- Remove unnecessary UI chrome
- Optimize for paper (portrait or landscape)
- Include timestamp and threshold settings

#### 2.4.3 JSON API
- Expose drive data via HTTP endpoint
- Format: `/plugins/driveage/api/drives.json`
- Enable integration with monitoring tools
- Include metadata (thresholds, last updated timestamp)

---

## 3. Technical Requirements

### 3.1 Platform Requirements
- **Minimum Unraid Version**: 6.12.0
- **Architecture**: x86_64
- **Dependencies**:
  - `smartmontools` (typically pre-installed on Unraid)
  - PHP 7.4+ (Unraid standard)
  - Standard Unraid web server

### 3.2 Data Sources

#### 3.2.1 Drive Discovery
- Read Unraid's drive configuration from: `/var/local/emhttp/var.ini`
- Parse array/disk assignments
- Support multi-array configurations

#### 3.2.2 SMART Data Acquisition
- Use `smartctl` command-line tool
- Parse output for:
  - Power_On_Hours attribute (typically attribute ID 9)
  - Temperature (attribute ID 194)
  - Overall SMART health status
- Handle different drive types (SATA, SAS, NVMe)

#### 3.2.3 Spin Status
- Check drive spin status via Unraid API or `/var/local/emhttp/` status files

### 3.3 Performance Requirements
- Dashboard load time: < 3 seconds for 50 drives
- SMART data caching: Cache results for 1 minute to avoid hammering drives
- Minimal impact on system resources
- No continuous background processes (load on-demand only)

### 3.4 File Structure

```
/boot/config/plugins/driveage/
├── driveage.cfg                 # Configuration file
└── settings/                    # Additional settings

/usr/local/emhttp/plugins/driveage/
├── DriveAge.page               # Main dashboard page
├── DriveAgeSettings.page       # Settings page
├── include/
│   ├── config.php              # Configuration loader
│   ├── smartdata.php           # SMART data collection
│   ├── formatting.php          # Time formatting utilities
│   └── api.php                 # JSON API handler
├── scripts/
│   ├── get_drive_data.php      # AJAX endpoint for drive data
│   └── export_csv.php          # CSV export handler
├── styles/
│   ├── driveage.css            # Main stylesheet
│   └── print.css               # Print-specific styles
├── js/
│   ├── dashboard.js            # Dashboard interactivity
│   └── settings.js             # Settings page logic
└── README.md                   # Documentation
```

### 3.5 Configuration File Format

**File**: `/boot/config/plugins/driveage/driveage.cfg`

```ini
# Display Configuration
VIEW_MODE="table"                # "table" or "card"
DEFAULT_SORT="power_on_hours"    # Column to sort by
DEFAULT_SORT_DIR="desc"          # "asc" or "desc"

# Refresh Configuration
AUTO_REFRESH="false"             # "true" or "false"
REFRESH_INTERVAL="300"           # Seconds (if auto_refresh enabled)

# Age Thresholds (in hours)
THRESHOLD_BRAND_NEW="17520"      # < 2 years
THRESHOLD_NEWISH="26280"         # 2-3 years
THRESHOLD_NORMAL="35040"         # 3-4 years
THRESHOLD_AGED="43800"           # 4-5 years
THRESHOLD_OLD="52560"            # 5-6 years
# 6+ years = anything above THRESHOLD_OLD

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

---

## 4. Data Model

### 4.1 Drive Object Structure

```php
[
  'device_name' => 'Disk 20',
  'device_path' => '/dev/sdi',
  'identification' => 'WDC_WD200EDGZ-11BLDS0_2LGBS1XK',
  'size_bytes' => 20000000000000,
  'size_human' => '20TB',
  'array_name' => 'Array 1',
  'drive_type' => 'array',  // parity|array|cache|pool|unassigned
  'power_on_hours' => 24980,
  'power_on_human' => '2y 10m 5d 20h',
  'temperature' => 34,
  'smart_status' => 'PASSED',  // PASSED|FAILED|UNKNOWN
  'spin_status' => 'active',   // active|standby|unknown
  'age_category' => 'normal',  // brand_new|newish|normal|aged|old|elderly
  'color_class' => 'age-normal'
]
```

### 4.2 Human-Readable Time Format

**Format**: `Xy Ym Xd Xh`
- `y` = years (365.25 days)
- `m` = months (30.44 days average)
- `d` = days
- `h` = remaining hours

**Examples**:
- `24980h` → `2y 10m 5d 20h`
- `8760h` → `1y 0m 0d 0h`
- `2190h` → `0y 3m 0d 6h`

---

## 5. User Interface Specifications

### 5.1 Dashboard Page

**Location**: Settings → DriveAge

**Layout Components**:
1. **Header Section**
   - Plugin title: "DriveAge"
   - Last updated timestamp
   - Manual refresh button
   - View mode toggle (table/card)
   - Export buttons (CSV, Print, JSON)

2. **Legend Section**
   - Display color legend with labels
   - Show current threshold values
   - Link to settings page

3. **Drive Display Section**
   - Grouped by array, then by type
   - Collapsible groups (click to expand/collapse)
   - Drive count per group

4. **Footer Section**
   - Total drive count
   - Link to settings

### 5.2 Settings Page

**Location**: Settings → DriveAge Settings

**Sections**:

1. **Age Thresholds**
   - Input fields for each threshold (in hours)
   - Visual preview of color scheme
   - Reset to defaults button
   - Validation (must be ascending)

2. **Display Options**
   - Checkboxes for drive types to show
   - Checkboxes for columns to show
   - Default view mode (table/card)
   - Default sorting options

3. **Refresh Options**
   - Radio buttons for refresh mode
   - Interval selector (if auto-refresh enabled)

4. **Export Settings**
   - Enable/disable each export option
   - API endpoint display with copy button

5. **Actions**
   - Apply button
   - Reset all to defaults button
   - Help/documentation link

### 5.3 Color Scheme Specifications

**CSS Classes**:
```css
.age-brand-new { background-color: #006400; color: white; }  /* Dark Green */
.age-newish    { background-color: #008000; color: white; }  /* Green */
.age-normal    { background-color: #90EE90; color: black; }  /* Light Green */
.age-aged      { background-color: #FFD700; color: black; }  /* Yellow */
.age-old       { background-color: #8B0000; color: white; }  /* Dark Red */
.age-elderly   { background-color: #FF0000; color: white; }  /* Bright Red */
```

### 5.4 Responsive Design
- Support desktop resolutions (1920x1080+)
- Support tablet (768px+)
- Minimum width: 768px (warn if narrower)

---

## 6. Future Enhancements (Not in MVP)

### 6.1 Alerting & Notifications
- Email notifications when drives cross age thresholds
- Unraid notification system integration
- Configurable alert rules

### 6.2 Historical Tracking
- Track power-on hours over time
- Graph showing drive aging trends
- Predict replacement dates

### 6.3 Advanced Filtering
- Search/filter by drive model
- Filter by SMART status
- Filter by temperature range

### 6.4 Drive Recommendations
- Highlight drives for replacement priority
- Compare drive lifespans
- Integration with drive warranty databases

---

## 7. Development Phases

### 7.1 Phase 1 - MVP (Initial Release)
**Goal**: Basic functional plugin with core features

**Scope**:
- Drive data collection (device, ID, size, power-on hours)
- Basic table view
- Six-tier color coding with default thresholds
- Simple settings page for threshold configuration
- Manual refresh only

**Success Criteria**:
- Displays all drives correctly
- Color coding works accurately
- Settings persist across reboots
- Clean installation and removal

### 7.2 Phase 2 - Enhanced Display
**Goal**: Improve user experience and flexibility

**Scope**:
- Add card view with toggle
- Implement all display filters
- Add temperature, SMART status, spin status
- Improve grouping UI (collapsible sections)
- Add sorting options

**Success Criteria**:
- Both view modes work seamlessly
- Filters apply correctly
- All data fields display accurately
- Grouping is intuitive

### 7.3 Phase 3 - Export & Integration
**Goal**: Enable data export and external tool integration

**Scope**:
- CSV export
- Print view
- JSON API endpoint
- Auto-refresh option

**Success Criteria**:
- Exports contain all relevant data
- Print view is well-formatted
- JSON API is accessible and well-documented
- Auto-refresh works without performance issues

### 7.4 Phase 4 - Polish & Optimization
**Goal**: Refine and optimize for production

**Scope**:
- Performance optimization
- UI/UX refinements based on feedback
- Multi-language support preparation
- Comprehensive error handling
- Documentation

**Success Criteria**:
- Fast load times even with many drives
- No console errors
- Clean, professional appearance
- Ready for Community Applications submission

---

## 8. Testing Requirements

### 8.1 Test Scenarios

#### 8.1.1 Drive Detection
- Test with 1 drive
- Test with 20+ drives
- Test with mixed drive types (SATA, SAS, NVMe)
- Test with multi-array configurations
- Test with unassigned devices
- Test with missing/failed drives

#### 8.1.2 SMART Data Accuracy
- Verify power-on hours match `smartctl` output
- Verify temperature readings
- Verify SMART status detection
- Test with drives that don't support SMART

#### 8.1.3 Time Formatting
- Test with various hour values (0, 100, 10000, 100000)
- Verify correct year/month/day/hour calculations
- Test edge cases (exactly 1 year, etc.)

#### 8.1.4 Color Coding
- Verify correct color for each threshold range
- Test boundary conditions (hour exactly at threshold)
- Test custom threshold configurations

#### 8.1.5 Configuration Persistence
- Verify settings save correctly
- Verify settings persist after reboot
- Test reset-to-defaults functionality

#### 8.1.6 UI/UX Testing
- Test table sorting
- Test view switching
- Test filter application
- Test responsive behavior
- Test with long device names

### 8.2 Compatibility Testing
- Test on Unraid 6.12.x
- Test on Unraid 6.13.x (when available)
- Test with different themes (if applicable)
- Test in different browsers (Chrome, Firefox, Safari)

### 8.3 Performance Testing
- Load time with 50+ drives
- Memory usage during operation
- Impact on system during SMART queries
- Cache effectiveness

---

## 9. Distribution & Support

### 9.1 GitHub Repository Structure

```
driveage/
├── plugins/
│   └── driveage.plg            # Main plugin file
├── archive/
│   ├── driveage-YYYY.MM.DD-x86_64-1.txz
│   └── driveage-YYYY.MM.DD-x86_64-1.md5
├── source/
│   └── driveage/
│       └── usr/local/emhttp/plugins/driveage/
├── docs/
│   ├── README.md
│   ├── REQUIREMENTS.md         # This document
│   ├── INSTALLATION.md
│   └── screenshots/
├── tests/
│   └── test-cases.md
└── README.md
```

### 9.2 Community Applications Submission
- Create forum support thread in Plugin Support
- Submit plugin to Community Applications moderators
- Provide comprehensive documentation
- Include screenshots and feature descriptions

### 9.3 Support Plan
- Monitor forum support thread
- Respond to issues within 48 hours
- Maintain GitHub Issues for bug tracking
- Provide changelog for all updates

### 9.4 Versioning Strategy
- Use date-based versioning: `YYYY.MM.DD`
- Maintain semantic meaning: major features = year change
- Update version in all files consistently

---

## 10. Non-Functional Requirements

### 10.1 Security
- Validate all user inputs
- Sanitize configuration values
- No execution of user-provided code
- Read-only access to SMART data (no write operations)
- HTTPS required for all external resources

### 10.2 Reliability
- Graceful handling of missing drives
- Handle SMART query failures
- No crashes on malformed data
- Logging for troubleshooting

### 10.3 Maintainability
- Clear, commented code
- Modular architecture
- Separation of concerns (data, logic, presentation)
- Follow Unraid plugin conventions

### 10.4 Documentation
- Inline code comments
- README with installation instructions
- User guide with screenshots
- API documentation (for JSON endpoint)

---

## 11. Acceptance Criteria

The DriveAge plugin will be considered complete and ready for release when:

1. All Phase 1 requirements are implemented
2. Plugin installs cleanly on fresh Unraid 6.12+ system
3. Plugin uninstalls cleanly without leaving artifacts
4. All drives are detected and displayed correctly
5. Color coding accurately reflects configured thresholds
6. Settings save and persist across reboots
7. No console errors in browser
8. Basic documentation is complete
9. Forum support thread is created
10. Plugin is tested on at least 3 different Unraid systems

---

## 12. Risks & Mitigation

### 12.1 Technical Risks

**Risk**: SMART data format varies across drive manufacturers
**Mitigation**: Extensive testing with different drive brands; graceful fallbacks

**Risk**: Performance impact with many drives
**Mitigation**: Implement caching; optimize SMART queries; load data asynchronously

**Risk**: Unraid API changes in future versions
**Mitigation**: Use stable, documented interfaces; test with beta releases

### 12.2 Adoption Risks

**Risk**: Users don't understand color coding
**Mitigation**: Clear legend; tooltips; good documentation

**Risk**: Users expect features not in MVP
**Mitigation**: Clear roadmap; manage expectations in documentation

**Risk**: Conflicts with other plugins
**Mitigation**: Follow Unraid conventions; test with popular plugins

---

## Appendix A: References

- Unraid Plugin Development: https://docs.unraid.net/
- Unraid Forums - Plugin Support: https://forums.unraid.net/forum/51-plugin-support/
- Community Applications: https://forums.unraid.net/topic/38582-plug-in-community-applications/
- smartmontools Documentation: https://www.smartmontools.org/

---

## Appendix B: Glossary

- **Array**: Primary storage array in Unraid
- **Cache**: High-speed storage tier (typically SSD)
- **Parity**: Redundancy drive(s) for data protection
- **Pool**: Named group of drives (e.g., cache pool, backup pool)
- **SMART**: Self-Monitoring, Analysis, and Reporting Technology
- **Power-On Hours**: SMART attribute tracking total hours drive has been powered on
- **Spin Status**: Whether a drive is actively spinning or in standby/sleep mode

---

**Document Status**: DRAFT - Pending Review
**Next Steps**: Review requirements with stakeholders, refine as needed, begin Phase 1 development planning
