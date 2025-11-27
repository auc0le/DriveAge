# DriveAge Development Guide

## Quick Start

### Building the Plugin

1. **Clone the repository**:
   ```bash
   git clone https://github.com/auc0le/DriveAge.git
   cd DriveAge
   ```

2. **Build the package**:
   ```bash
   ./build.sh
   ```

3. **Package will be created in** `archive/` directory

### Testing on Unraid

**Option 1: Local Installation (Recommended for Development)**
1. Copy the entire `source/driveage/usr/local/emhttp/plugins/driveage/` directory to your Unraid server
2. Create config directory: `mkdir -p /boot/config/plugins/driveage`
3. Access at: Settings → DriveAge

**Option 2: Package Installation**
1. Build the package using `./build.sh`
2. Upload `.txz` and `.md5` files to GitHub
3. Install via plugin manifest URL

## Project Structure

```
DriveAge/
├── plugins/
│   └── driveage.plg              # Plugin manifest (XML)
│
├── source/driveage/              # Source files to be packaged
│   └── usr/local/emhttp/plugins/driveage/
│       ├── DriveAge.page         # Main dashboard (Menu: Utilities)
│       ├── DriveAgeSettings.page # Settings page (Menu: Utilities:2)
│       │
│       ├── include/              # PHP modules
│       │   ├── config.php        # Configuration handling
│       │   ├── smartdata.php     # SMART data collection
│       │   └── formatting.php    # Data formatting utilities
│       │
│       ├── scripts/              # Executable scripts
│       │   └── get_drive_data.php # AJAX endpoint for dashboard
│       │
│       ├── styles/               # CSS stylesheets
│       │   └── driveage.css      # Main stylesheet
│       │
│       └── js/                   # JavaScript files
│           └── dashboard.js      # Dashboard interactivity
│
├── archive/                      # Built packages (git ignored)
│   ├── driveage-*.txz           # Slackware package
│   └── driveage-*.md5           # MD5 checksum
│
├── docs/                         # Documentation
│   ├── screenshots/             # Screenshots for README
│   └── icon.png                 # Plugin icon
│
├── build.sh                      # Build script
├── README.md                     # User documentation
├── REQUIREMENTS.md               # Requirements specification
└── DEVELOPMENT.md                # This file
```

## Development Workflow

### Making Changes

1. **Modify source files** in `source/driveage/usr/local/emhttp/plugins/driveage/`
2. **Test changes** by copying to Unraid server
3. **Update version** in `plugins/driveage.plg` if releasing
4. **Build package** using `./build.sh`
5. **Commit and push** to GitHub

### Version Numbering

- Format: `YYYY.MM.DD`
- Example: `2025.11.26`
- Update in `plugins/driveage.plg`:
  ```xml
  <!ENTITY version   "2025.11.26">
  ```

### Adding New Features

1. **Update REQUIREMENTS.md** with new feature specifications
2. **Implement in appropriate module**:
   - UI changes → `.page` files
   - Data processing → `include/*.php`
   - Styling → `styles/driveage.css`
   - Interactivity → `js/dashboard.js`
3. **Update configuration** if needed in `include/config.php`
4. **Test thoroughly** on Unraid
5. **Document** in README.md and changelog

## Key Components

### 1. Plugin Manifest (`driveage.plg`)

XML file that defines:
- Plugin metadata (name, version, author)
- Download URLs
- Installation scripts
- Removal scripts
- Changelog

### 2. Configuration System

**File**: `/boot/config/plugins/driveage/driveage.cfg`

Handled by `include/config.php`:
- `loadConfig()` - Load configuration
- `saveConfig()` - Save configuration
- `validateConfig()` - Validate values
- `getAgeCategory()` - Determine age category

### 3. SMART Data Collection

**Module**: `include/smartdata.php`

Functions:
- `getAllDrives()` - Get all drives with SMART data
- `getSmartData()` - Query SMART data from drive
- `getUnraidAssignment()` - Get drive assignment info
- Caching system with 60-second TTL

### 4. Data Formatting

**Module**: `include/formatting.php`

Functions:
- `formatPowerOnHours()` - Convert hours to human format
- `formatBytes()` - Format drive size
- `formatTemperature()` - Format temperature
- `formatSmartStatus()` - Format SMART status
- `formatSpinStatus()` - Format spin status

### 5. Dashboard Display

**File**: `DriveAge.page`

- PHP-based Unraid page
- Loads CSS and JavaScript
- Renders initial HTML structure
- JavaScript handles dynamic loading

**JavaScript**: `js/dashboard.js`

- Fetches drive data via AJAX
- Renders table or card view
- Handles sorting and filtering
- Updates UI dynamically

### 6. Settings Page

**File**: `DriveAgeSettings.page`

- HTML form for configuration
- Posts to Unraid's `/update.php`
- Validates input with JavaScript
- Shows color preview

## Data Flow

```
User loads dashboard
    ↓
DriveAge.page renders HTML
    ↓
dashboard.js loads
    ↓
AJAX request to get_drive_data.php
    ↓
get_drive_data.php calls getAllDrives()
    ↓
smartdata.php queries SMART data
    ↓
formatting.php formats data
    ↓
JSON returned to dashboard.js
    ↓
dashboard.js renders table/card view
    ↓
User sees drive data
```

## Testing Checklist

### Before Release

- [ ] Test installation on clean Unraid system
- [ ] Verify all drives detected
- [ ] Test SMART data accuracy
- [ ] Verify color coding
- [ ] Test sorting functionality
- [ ] Test view switching (table/card)
- [ ] Test settings save/load
- [ ] Test threshold configuration
- [ ] Verify oldest drive bold formatting
- [ ] Test with 1 drive
- [ ] Test with 20+ drives
- [ ] Test multi-array support
- [ ] Test JSON API (if enabled)
- [ ] Test rate limiting
- [ ] Verify uninstall cleanup
- [ ] Check browser console for errors
- [ ] Test on different browsers

### Performance Testing

- [ ] Load time with 50+ drives
- [ ] SMART query performance
- [ ] Cache effectiveness
- [ ] Memory usage
- [ ] CPU usage during refresh

## Common Development Tasks

### Update Age Thresholds

1. Edit `include/config.php` - `getDefaultConfig()`
2. Update `DriveAgeSettings.page` - default values
3. Update README.md - threshold table

### Add New Data Field

1. Add to `smartdata.php` - `getDriveInfo()`
2. Update AJAX response in `get_drive_data.php`
3. Add column to table in `dashboard.js` - `renderTableView()`
4. Add to card in `dashboard.js` - `renderCardView()`
5. Update CSS in `driveage.css` if needed

### Modify Color Scheme

1. Update `driveage.css` - age color classes
2. Update `DriveAgeSettings.page` - threshold items
3. Update `DriveAge.page` - legend section
4. Update README.md - color table

## Debugging

### Enable PHP Error Logging

Add to PHP files:
```php
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

### Check SMART Data Manually

```bash
smartctl -a /dev/sdX
smartctl -a -j /dev/sdX  # JSON output
```

### View JavaScript Console

Open browser DevTools (F12) → Console tab

### Check Unraid Logs

```bash
tail -f /var/log/syslog
```

### Cache Issues

Clear cache:
```bash
rm /tmp/driveage_cache.json
```

## Performance Optimization

### SMART Query Optimization

- Cache results for 60 seconds (configurable)
- Query drives in parallel (future enhancement)
- Skip spun-down drives option (future enhancement)

### UI Optimization

- Lazy loading for large drive lists (future enhancement)
- Virtual scrolling for 100+ drives (future enhancement)
- Debounce sort/filter operations

## Security Considerations

### Input Validation

All user inputs must be validated:
- `validateConfig()` in config.php
- JavaScript validation in settings page
- Sanitization in formatting.php

### API Security

- Disabled by default
- Rate limiting enforced
- IP-based tracking
- No authentication (consider for future)

### File Permissions

- Config files: 644
- Plugin directory: 755
- No world-writable files

## Future Development Ideas

### Phase 2 Features
- Card view enhancements
- Advanced filtering
- Export to CSV
- Print view

### Phase 3 Features
- Email notifications
- Historical tracking
- Trend graphs
- Warranty database integration

### Phase 4 Features
- Multi-language support
- Custom alert rules
- Drive health predictions
- Integration with other monitoring tools

## Contributing Guidelines

1. **Fork the repository**
2. **Create a feature branch**:
   ```bash
   git checkout -b feature/amazing-feature
   ```
3. **Make changes** following code style
4. **Test thoroughly** on Unraid
5. **Update documentation** as needed
6. **Commit with clear messages**:
   ```bash
   git commit -m "Add amazing feature"
   ```
7. **Push to your fork**:
   ```bash
   git push origin feature/amazing-feature
   ```
8. **Create Pull Request** on GitHub

## Resources

- [Unraid Plugin Development](https://docs.unraid.net/)
- [smartmontools Documentation](https://www.smartmontools.org/)
- [Slackware Package Format](http://www.slackware.com/config/packages.php)
- [Unraid Forums - Plugin Support](https://forums.unraid.net/forum/51-plugin-support/)

## Support

- GitHub Issues: https://github.com/auc0le/DriveAge/issues
- Unraid Forums: https://forums.unraid.net/

---

Happy coding! 🚀
