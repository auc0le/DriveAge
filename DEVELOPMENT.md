# DriveAge Development Guide

## Quick Start

### Building the Plugin

1. **Clone the repository**:
   ```bash
   git clone https://github.com/auc0le/DriveAge.git
   cd DriveAge
   ```

2. **Run the tests** (no Unraid required):
   ```bash
   php tests/run.php
   ```

3. **Build the package**:
   ```bash
   ./build.sh
   ```
   The package lands in `archive/`; `build.sh` also stamps `include/version.php` and updates the `.plg` manifest.

### Testing on Unraid

**Option 1: Local Installation (Recommended for Development)**
1. Copy `source/driveage/usr/local/emhttp/plugins/driveage/` to your Unraid server
2. Access at: Settings → DriveAge
3. CLI diagnostic: `php /usr/local/emhttp/plugins/driveage/scripts/diagnose.php`

**Option 2: Package Installation**
1. Build with `./build.sh`
2. Upload `.txz` and `.md5` to GitHub
3. Install via the plugin manifest URL

## Project Structure

```
DriveAge/
├── plugins/
│   └── driveage.plg              # Plugin manifest (XML)
│
├── source/driveage/              # Files that ship in the package
│   └── usr/local/emhttp/plugins/driveage/
│       ├── DriveAge.page          # Menu container (tabs)
│       ├── DriveAgeDashboard.page # Dashboard tab
│       ├── DriveAgeSettings.page  # Settings tab
│       ├── DriveAgeHelp.page      # Help tab
│       │
│       ├── include/               # PHP modules
│       │   ├── version.php        # Version constant (written by build.sh)
│       │   ├── config.php         # Config load + validation (validate-on-load)
│       │   ├── risk.php           # Risk category policy
│       │   ├── smartparse.php     # smartctl parsers - PURE functions, unit-tested
│       │   ├── smartdata.php      # Enumeration (disks.ini/devs.ini) + assembly
│       │   ├── predictions.php    # Replacement estimates
│       │   ├── cache.php          # Persistent POH cache (flash, write-on-change)
│       │   ├── formatting.php     # Plain-text formatting + CSS class mapping
│       │   └── helpers.php        # Escaping / form helpers
│       │
│       ├── scripts/
│       │   ├── get_drive_data.php # JSON endpoint for the dashboard
│       │   └── diagnose.php       # CLI diagnostic
│       │
│       ├── styles/driveage.css    # Theme-aware stylesheet
│       └── js/
│           ├── dashboard.js       # Summary, table, details, sorting, auto-refresh
│           ├── charts.js          # Risk donut + replacement timeline (inline SVG/CSS)
│           └── settings.js        # Reset-to-defaults
│
├── tests/                        # NOT packaged
│   ├── run.php                   # php tests/run.php
│   └── fixtures/                 # Captured smartctl outputs
│
├── archive/                      # Built packages
├── docs/                         # Screenshots, icon
├── build.sh
├── README.md
├── METHODOLOGY.md                # The shipped formulas - keep in sync with code
├── CLAUDE.md                     # AI-assistant guidance - keep in sync with code
└── DEVELOPMENT.md                # This file
```

## Data Flow

```
User opens dashboard
    ↓
DriveAgeDashboard.page renders shell (category colors as CSS variables) and
starts the get_drive_data.php request inline, before the page finishes parsing
    ↓
dashboard.js (deferred) picks up that request
    ↓
get_drive_data.php: loadConfig() [validated] → getAllDrives() → filters → groups
    ↓
getAllDrives(): enumerate from disks.ini/devs.ini
    → parse /var/local/emhttp/smart/<name> (ATA/NVMe/SCSI text; <name> = Unraid disk name, device name as fallback)
    → health from <name>.ssa
    → temp/spin from disks.ini
    → standby POH from /boot/config/plugins/driveage/drivecache.json
    → direct smartctl ONLY if no cache file and not spun down
    ↓
risk.php categorizes → predictions.php estimates → JSON (plain data + CSS classes)
    ↓
dashboard.js renders summary + table (+ detail panels on demand); charts.js renders charts
```

## Testing

### Unit / integration tests (run these every change)

```bash
php tests/run.php
```

Coverage: smartctl text parsing (ATA `-A` cache format with vendor raw values, full `-a`, NVMe health log, SATA SSD wear/writes by attribute name, SCSI/SAS incl. defects and error counters, standby), JSON parsing (universal fields, SSD, SAS), cache-file naming, risk categories (lifecycle, health, overall, labels), HDD/SSD/NVMe prediction math (targets, wear indicator, measured and recent rates, horizon, overrides, critical-warning bits), health warnings with trends (5/187/188/197/198, SAS, SSD rules, composite-188 masking, FAILED), drive history (trends, write samples, write-on-change), pools/filters/grouping, Unraid temperature limits, smartctl controller options, config validation, and an end-to-end `getAllDrives()` run against a fixture state directory.

Adding a test: drop a captured smartctl output into `tests/fixtures/`, assert in `tests/run.php` with `check()`/`checkEq()`.

### Manual checklist before release

- [ ] `php tests/run.php` passes
- [ ] Clean install on Unraid; drives detected (incl. NVMe, unassigned, spun-down)
- [ ] Verify against `smartctl -a` for one HDD and one NVMe
- [ ] Dark theme (black) and white theme both readable
- [ ] Settings: save, reload, reset-to-defaults; filters/auto-refresh/default-sort take effect
- [ ] Drive with SMART warning shows warning + escalated row color + matching label
- [ ] Browser console free of errors
- [ ] Uninstall cleanup

## Debugging

### Check SMART Data Manually
```bash
smartctl -a /dev/sdX
smartctl -a -j /dev/sdX          # JSON output
cat /var/local/emhttp/smart/disk1      # what Unraid cached (what DriveAge reads) - named by disk name: parity, disk1, cache, dev1...
cat /var/local/emhttp/smart/disk1.ssa
```

### Diagnostics
```bash
php /usr/local/emhttp/plugins/driveage/scripts/diagnose.php
tail -f /var/log/syslog | grep -i driveage
```

### Cache
Persistent POH cache: `/boot/config/plugins/driveage/drivecache.json` (safe to delete; regenerates as drives are seen awake).

## Design Rules

1. **Enumerate from Unraid's state files**, never `/dev` globs (breaks at 26 drives).
2. **Never wake a spun-down drive.**
3. **Parsers stay pure** (`smartparse.php`): no filesystem/shell access, so they stay testable.
4. **Validate on load**: `/update.php` writes settings verbatim, so `loadConfig()` is the enforcement point.
5. **No plugin-level auth/CSRF/rate limiting**: Unraid's webGUI provides authentication and CSRF for `/update.php`.
6. **Category, color and label change together** - never desync them.
7. **Docs are part of the change**: prediction/risk changes update METHODOLOGY.md in the same commit.
8. **Theme-neutral styling**: translucent surfaces + `color: inherit`; no hardcoded light backgrounds.

## Future Development Ideas

- Email/agent notifications at category transitions or new SMART warnings
- Historical tracking and wear-trend graphs
- CSV export
- Community Applications template/submission
- Multi-language support

## Contributing Guidelines

1. Fork, branch (`feature/...`)
2. Make changes following the design rules above
3. `php tests/run.php` (add tests for new logic)
4. Update README/METHODOLOGY/CLAUDE docs if behavior changed
5. PR with a clear description

## Resources

- [Unraid Plugin Development](https://docs.unraid.net/)
- [smartmontools Documentation](https://www.smartmontools.org/)
- [Backblaze Drive Stats](https://www.backblaze.com/blog/backblaze-drive-stats-for-2024/)
- [Unraid Forums - Plugin Support](https://forums.unraid.net/forum/51-plugin-support/)

## Support

- GitHub Issues: https://github.com/auc0le/DriveAge/issues

---

Happy coding! 🚀
