# DriveAge Enhancement Plan: Dual Risk Model + Predictive Replacement

**Version:** 2025.12.26
**Status:** Planning Phase
**Goal:** Implement wear-based risk assessment for NVMe drives and predictive replacement planning for all drives

---

## Executive Summary

This plan combines:
1. **Dual Risk Model** - Age-based for HDDs, wear-based for NVMe
2. **Predictive Replacement** - Estimated time-to-replacement for both drive types
3. **Pre-Failure Detection** - Early warning system using critical SMART attributes
4. **Simple UI** - Clean display with tooltips/help for technical details

---

## Research Findings & Assumptions

### NVMe TBW Ratings (Generic Estimates)

Based on 2024/2025 consumer drive market research:

| Capacity | Typical TBW (TLC) | Conservative Estimate |
|----------|-------------------|----------------------|
| 256GB    | 300 TBW          | 250 TBW             |
| 512GB    | 320 TBW          | 300 TBW             |
| 1TB      | 600 TBW          | 500 TBW             |
| 2TB      | 1200 TBW         | 1000 TBW            |
| 4TB      | 2400 TBW         | 2000 TBW            |

**Formula:** `Conservative TBW = (capacity_TB * 500)` for TLC drives

**Sources:**
- [Tom's Hardware SSD Forums](https://forums.tomshardware.com/threads/choosing-an-nvme-ssd-higher-tbw-rating-or-longer-warranty.3755858/)
- [How To Estimate SSD Lifespan](https://larryjordan.com/articles/how-to-measure-the-longevity-of-an-ssd-drive/)

### NVMe Write Workloads (Home NAS)

**Typical Usage:**
- Light use (media server): 10-15 TB/year
- Moderate use (backup + media): 20-30 TB/year
- Heavy use (VMs, containers): 40-60 TB/year

**Write Amplification:** 1.5-2x in NAS caching scenarios

**Sources:**
- [Understanding SSD Endurance](https://blogs.technet.microsoft.com/filecab/2017/08/11/understanding-dwpd-tbw/)
- [SSD Lifespan in NAS](https://nas-uk.ugreen.com/blogs/knowledge/ssd-lifespan-in-nas-guide)

### HDD Failure Predictors

**Most Critical SMART Attributes:**
- **SMART 5:** Reallocated Sectors Count - ANY value >0 is a warning
- **SMART 197:** Current Pending Sector Count - Sectors waiting to be reallocated
- **SMART 198:** Uncorrectable Sector Count - Unrecoverable errors

**Replacement Guidance:**
- Any reallocated sectors: Monitor closely, backup immediately
- Increasing trend (even slow): Replace within 6 months
- Rapid increase: Replace ASAP (< 1 month)

**Sources:**
- [SMART Attributes for HDD Failure Prediction](https://horizontechnology.com/news/smart-attributes-for-predicting-hdd-failure/)
- [Reallocated Sector Count Guidance](https://hdsentinel.com/blog/reallocated-sector-count)
- [When to Replace Drive](https://www.partitionwizard.com/disk-recovery/reallocated-sector-count.html)

### NVMe Health Metrics

**Primary Indicators:**
- **Percentage Used:** Vendor-specific wear estimate (can exceed 100%)
- **Available Spare:** Remaining spare NAND cells (critical when < threshold)
- **Media Errors:** Uncorrectable errors (red flag if > 0)

**Critical Finding:** [Percentage Used > 100% alone is NOT a failure predictor](https://forum.proxmox.com/threads/nvme-disk-wearout.67831/). Available Spare is more critical.

**Sources:**
- [NVMe Percentage Used Interpretation](https://www.percona.com/blog/using-nvme-command-line-tools-to-check-nvme-flash-health/)
- [NVMe Health Monitoring](https://nvmexpress.org/resource/features-for-error-reporting-smart-log-pages-failures-and-management-capabilities-in-nvme-architectures/)

---

## Implementation Components

### Component 1: Enhanced SMART Data Collection

**File:** `include/smartdata.php`

#### New SMART Attributes to Extract

**For NVMe (from smartctl JSON):**
```php
'nvme_percentage_used' => int (0-255, can exceed 100)
'nvme_available_spare' => int (0-100 percent)
'nvme_available_spare_threshold' => int (0-100 percent)
'nvme_data_units_written' => int (512-byte units × 1000)
'nvme_data_units_read' => int (512-byte units × 1000)
'nvme_media_errors' => int (count of uncorrectable errors)
'nvme_critical_warning' => int (bitmap of critical conditions)
```

**For HDD (from smartctl text/JSON):**
```php
'hdd_reallocated_sectors' => int (SMART 5 raw value)
'hdd_pending_sectors' => int (SMART 197 raw value)
'hdd_uncorrectable_sectors' => int (SMART 198 raw value)
'hdd_reported_uncorrectable' => int (SMART 187 raw value)
'hdd_command_timeout' => int (SMART 188 raw value)
```

#### New Functions

**`parseNvmeSmartAttributes($jsonData)`**
```php
/**
 * Parse NVMe-specific SMART attributes from smartctl JSON
 *
 * @param array $data Parsed smartctl -j output
 * @return array NVMe health metrics
 */
function parseNvmeSmartAttributes($data) {
    $nvme = [
        'percentage_used' => null,
        'available_spare' => null,
        'available_spare_threshold' => null,
        'data_units_written' => null,
        'data_units_read' => null,
        'media_errors' => null,
        'critical_warning' => null,
        'tbw_calculated' => null
    ];

    // Extract from nvme_smart_health_information_log
    if (isset($data['nvme_smart_health_information_log'])) {
        $health = $data['nvme_smart_health_information_log'];

        $nvme['percentage_used'] = $health['percentage_used'] ?? null;
        $nvme['available_spare'] = $health['available_spare'] ?? null;
        $nvme['available_spare_threshold'] = $health['available_spare_threshold'] ?? null;
        $nvme['data_units_written'] = $health['data_units_written'] ?? null;
        $nvme['data_units_read'] = $health['data_units_read'] ?? null;
        $nvme['media_errors'] = $health['media_errors'] ?? 0;
        $nvme['critical_warning'] = $health['critical_warning'] ?? 0;

        // Calculate actual TBW: data_units × 512 bytes × 1000 ÷ 1,000,000,000,000
        if ($nvme['data_units_written'] !== null) {
            $nvme['tbw_calculated'] = round(($nvme['data_units_written'] * 512000) / 1000000000000, 2);
        }
    }

    return $nvme;
}
```

**`parseHddSmartAttributes($output, $jsonData)`**
```php
/**
 * Parse HDD-specific critical SMART attributes
 *
 * @param string $output Raw smartctl text output
 * @param array $jsonData Parsed smartctl -j output (if available)
 * @return array HDD critical attributes
 */
function parseHddSmartAttributes($output, $jsonData = null) {
    $hdd = [
        'reallocated_sectors' => null,
        'pending_sectors' => null,
        'uncorrectable_sectors' => null,
        'reported_uncorrectable' => null,
        'command_timeout' => null
    ];

    // Try JSON first (more reliable)
    if ($jsonData && isset($jsonData['ata_smart_attributes']['table'])) {
        foreach ($jsonData['ata_smart_attributes']['table'] as $attr) {
            switch ($attr['id']) {
                case 5:
                    $hdd['reallocated_sectors'] = $attr['raw']['value'];
                    break;
                case 197:
                    $hdd['pending_sectors'] = $attr['raw']['value'];
                    break;
                case 198:
                    $hdd['uncorrectable_sectors'] = $attr['raw']['value'];
                    break;
                case 187:
                    $hdd['reported_uncorrectable'] = $attr['raw']['value'];
                    break;
                case 188:
                    $hdd['command_timeout'] = $attr['raw']['value'];
                    break;
            }
        }
    } else {
        // Fallback: parse text output
        // Format: ID# ATTRIBUTE_NAME FLAG VALUE WORST THRESH TYPE UPDATED WHEN_FAILED RAW_VALUE
        $lines = explode("\n", $output);
        foreach ($lines as $line) {
            if (preg_match('/^\s*(\d+)\s+\S+.*\s+(\d+)\s*$/', $line, $matches)) {
                $attrId = intval($matches[1]);
                $rawValue = intval($matches[2]);

                switch ($attrId) {
                    case 5:
                        $hdd['reallocated_sectors'] = $rawValue;
                        break;
                    case 197:
                        $hdd['pending_sectors'] = $rawValue;
                        break;
                    case 198:
                        $hdd['uncorrectable_sectors'] = $rawValue;
                        break;
                    case 187:
                        $hdd['reported_uncorrectable'] = $rawValue;
                        break;
                    case 188:
                        $hdd['command_timeout'] = $rawValue;
                        break;
                }
            }
        }
    }

    return $hdd;
}
```

#### Integration Points

**Modify `parseSmartctlJsonOutput()`:**
```php
function parseSmartctlJsonOutput($data) {
    $smartData = [
        // ... existing fields ...
    ];

    // Detect drive type
    $isNvme = isset($data['nvme_smart_health_information_log']);
    $isHdd = isset($data['ata_smart_attributes']);

    if ($isNvme) {
        $nvmeAttrs = parseNvmeSmartAttributes($data);
        $smartData = array_merge($smartData, $nvmeAttrs);
    }

    if ($isHdd) {
        $hddAttrs = parseHddSmartAttributes(null, $data);
        $smartData = array_merge($smartData, $hddAttrs);
    }

    return $smartData;
}
```

**Modify `parseSmartctlOutput()` for text fallback:**
```php
function parseSmartctlOutput($output) {
    // ... existing parsing ...

    // Parse HDD-specific attributes if this is an HDD
    if (stripos($output, 'ATA') !== false || stripos($output, 'SATA') !== false) {
        $hddAttrs = parseHddSmartAttributes($output, null);
        $smartData = array_merge($smartData, $hddAttrs);
    }

    return $smartData;
}
```

---

### Component 2: NVMe Risk Categories

**File:** `include/config.php`

#### New Function: `getNvmeRiskCategory()`

```php
/**
 * Determine NVMe risk category based on wear metrics
 *
 * Uses Percentage Used and Available Spare to assess failure risk
 *
 * @param array $nvmeData NVMe SMART attributes
 * @return string Risk category: minimal_risk, low_risk, moderate_risk, elevated_risk, high_risk
 */
function getNvmeRiskCategory($nvmeData) {
    $percentageUsed = $nvmeData['percentage_used'] ?? null;
    $availableSpare = $nvmeData['available_spare'] ?? null;
    $spareThreshold = $nvmeData['available_spare_threshold'] ?? 10;
    $mediaErrors = $nvmeData['media_errors'] ?? 0;
    $criticalWarning = $nvmeData['critical_warning'] ?? 0;

    // CRITICAL: Media errors or critical warning = high risk
    if ($mediaErrors > 0 || $criticalWarning > 0) {
        return 'high_risk';
    }

    // CRITICAL: Available spare below threshold = high risk
    if ($availableSpare !== null && $availableSpare < $spareThreshold) {
        return 'high_risk';
    }

    // ELEVATED: Available spare close to threshold (within 10%) OR percentage used >100%
    if (($availableSpare !== null && $availableSpare < ($spareThreshold + 10)) ||
        ($percentageUsed !== null && $percentageUsed > 100)) {
        return 'elevated_risk';
    }

    // Risk assessment based on Percentage Used and Available Spare
    // Both metrics must agree on risk level (use more conservative)

    $riskByPercentage = 'minimal_risk';
    if ($percentageUsed !== null) {
        if ($percentageUsed >= 80) {
            $riskByPercentage = 'moderate_risk';
        } elseif ($percentageUsed >= 50) {
            $riskByPercentage = 'low_risk';
        }
    }

    $riskBySpare = 'minimal_risk';
    if ($availableSpare !== null) {
        if ($availableSpare <= 50) {
            $riskBySpare = 'moderate_risk';
        } elseif ($availableSpare <= 80) {
            $riskBySpare = 'low_risk';
        }
    }

    // Return more conservative (higher risk)
    $riskLevels = ['minimal_risk' => 0, 'low_risk' => 1, 'moderate_risk' => 2, 'elevated_risk' => 3, 'high_risk' => 4];
    $maxRisk = max($riskLevels[$riskByPercentage], $riskLevels[$riskBySpare]);

    return array_search($maxRisk, $riskLevels);
}
```

#### Risk Category Mapping

```
NVMe Risk Categories (Wear-Based):

┌─────────────────┬──────────────┬────────────────┬──────────────────────┐
│ Category        │ % Used       │ Avail. Spare   │ Estimated Time       │
├─────────────────┼──────────────┼────────────────┼──────────────────────┤
│ Minimal Risk    │ 0-49%        │ >80%           │ >5 years             │
│ Low Risk        │ 50-79%       │ 50-80%         │ 2-5 years            │
│ Moderate Risk   │ 80-99%       │ 20-50%         │ 6-24 months          │
│ Elevated Risk   │ 100-110%     │ 10-20%         │ <6 months            │
│ High Risk       │ >110% OR     │ <10% OR        │ Replace ASAP         │
│                 │ Media Errors │ Critical Warn  │                      │
└─────────────────┴──────────────┴────────────────┴──────────────────────┘
```

---

### Component 3: HDD Pre-Failure Detection

**File:** `include/smartdata.php`

#### New Function: `getHddHealthWarnings()`

```php
/**
 * Detect HDD pre-failure conditions based on critical SMART attributes
 *
 * @param array $hddData HDD SMART attributes
 * @return array Health warnings ['level' => string, 'message' => string, 'action' => string]
 */
function getHddHealthWarnings($hddData) {
    $warnings = [];

    $reallocated = $hddData['reallocated_sectors'] ?? 0;
    $pending = $hddData['pending_sectors'] ?? 0;
    $uncorrectable = $hddData['uncorrectable_sectors'] ?? 0;

    // CRITICAL: Any reallocated sectors = warning
    if ($reallocated > 0) {
        $severity = 'warning';
        if ($reallocated > 10) {
            $severity = 'critical';
        }

        $warnings[] = [
            'level' => $severity,
            'attribute' => 'reallocated_sectors',
            'value' => $reallocated,
            'message' => $reallocated . ' sector' . ($reallocated > 1 ? 's' : '') . ' reallocated',
            'action' => $reallocated > 10 ? 'Replace immediately' : 'Backup data, monitor closely',
            'tooltip' => 'Reallocated sectors indicate physical damage. Drive may fail soon.'
        ];
    }

    // CRITICAL: Pending sectors = imminent reallocation
    if ($pending > 0) {
        $warnings[] = [
            'level' => 'critical',
            'attribute' => 'pending_sectors',
            'value' => $pending,
            'message' => $pending . ' sector' . ($pending > 1 ? 's' : '') . ' pending',
            'action' => 'Replace ASAP',
            'tooltip' => 'Pending sectors are waiting to be reallocated. Drive is failing.'
        ];
    }

    // CRITICAL: Uncorrectable sectors = data loss risk
    if ($uncorrectable > 0) {
        $warnings[] = [
            'level' => 'critical',
            'attribute' => 'uncorrectable_sectors',
            'value' => $uncorrectable,
            'message' => $uncorrectable . ' uncorrectable error' . ($uncorrectable > 1 ? 's' : ''),
            'action' => 'Replace immediately',
            'tooltip' => 'Uncorrectable errors indicate data loss. Replace drive now.'
        ];
    }

    return $warnings;
}
```

#### Integration

**Modify `getDriveInfo()` to include health warnings:**
```php
function getDriveInfo($devicePath, $diskAssignments, $config, $tempUnit = 'C') {
    // ... existing code ...

    $healthWarnings = [];

    // For HDDs: check pre-failure indicators
    if ($physicalType === 'hdd' && $smartData) {
        $healthWarnings = getHddHealthWarnings($smartData);
    }

    // For NVMe: check critical conditions
    if ($physicalType === 'nvme' && $smartData) {
        if (($smartData['media_errors'] ?? 0) > 0) {
            $healthWarnings[] = [
                'level' => 'critical',
                'attribute' => 'media_errors',
                'value' => $smartData['media_errors'],
                'message' => 'Media errors detected',
                'action' => 'Replace ASAP',
                'tooltip' => 'Uncorrectable data errors. Drive reliability compromised.'
            ];
        }

        if (($smartData['critical_warning'] ?? 0) > 0) {
            $healthWarnings[] = [
                'level' => 'critical',
                'attribute' => 'critical_warning',
                'value' => $smartData['critical_warning'],
                'message' => 'Critical warning active',
                'action' => 'Check drive immediately',
                'tooltip' => 'Drive has raised a critical warning flag.'
            ];
        }
    }

    return [
        // ... existing fields ...
        'health_warnings' => $healthWarnings,
        'has_warnings' => count($healthWarnings) > 0
    ];
}
```

---

### Component 4: Predictive Replacement Estimates

**File:** `include/predictions.php` (NEW FILE)

```php
<?php
/**
 * DriveAge Plugin - Predictive Replacement Estimates
 *
 * Calculates estimated time-to-replacement for drives based on:
 * - HDD: Age + AFR curves + SMART health
 * - NVMe: Wear rate + TBW estimates
 */

require_once 'config.php';

/**
 * Estimate generic TBW rating based on drive capacity
 *
 * Uses conservative estimates from 2024 consumer drive market
 * Formula: 500 TBW per TB of capacity (TLC drives)
 *
 * @param int $sizeBytes Drive size in bytes
 * @return int Estimated TBW rating
 */
function estimateNvmeTBW($sizeBytes) {
    if ($sizeBytes <= 0) {
        return 300; // Default minimum
    }

    $sizeGB = $sizeBytes / (1024 * 1024 * 1024);
    $sizeTB = $sizeGB / 1024;

    // Conservative estimate: 500 TBW per TB
    $estimatedTBW = round($sizeTB * 500);

    // Minimum 250 TBW for small drives
    return max($estimatedTBW, 250);
}

/**
 * Calculate estimated remaining life for NVMe drive
 *
 * @param array $nvmeData NVMe SMART attributes
 * @param int $sizeBytes Drive capacity in bytes
 * @return array ['months_remaining' => int, 'confidence' => string, 'method' => string]
 */
function estimateNvmeRemainingLife($nvmeData, $sizeBytes) {
    $percentageUsed = $nvmeData['percentage_used'] ?? null;
    $availableSpare = $nvmeData['available_spare'] ?? null;
    $spareThreshold = $nvmeData['available_spare_threshold'] ?? 10;
    $tbwCalculated = $nvmeData['tbw_calculated'] ?? null;

    // Method 1: Percentage Used (if < 100%, we can estimate)
    if ($percentageUsed !== null && $percentageUsed > 0 && $percentageUsed < 100) {
        // Estimate total life based on percentage used
        // If we're at X% used, we have (100-X)% remaining
        $percentRemaining = 100 - $percentageUsed;
        $percentConsumed = $percentageUsed;

        // This is very rough: assume linear wear (not always accurate)
        // Conservative: assume faster wear rate going forward
        $monthsRemaining = ($percentRemaining / $percentConsumed) * 12; // Assume 1 year elapsed per % used

        return [
            'months_remaining' => round($monthsRemaining),
            'confidence' => 'low',
            'method' => 'percentage_used_linear',
            'notes' => 'Estimate based on current wear percentage. Actual lifespan may vary.'
        ];
    }

    // Method 2: Available Spare depletion
    if ($availableSpare !== null && $availableSpare < 90) {
        $spareConsumed = 100 - $availableSpare;
        $spareRemaining = $availableSpare - $spareThreshold;

        if ($spareConsumed > 0 && $spareRemaining > 0) {
            // Rough estimate: how long until spare reaches threshold
            $monthsRemaining = ($spareRemaining / $spareConsumed) * 24; // Assume 2 years elapsed

            return [
                'months_remaining' => round($monthsRemaining),
                'confidence' => 'low',
                'method' => 'spare_depletion',
                'notes' => 'Estimate based on spare capacity depletion rate.'
            ];
        }
    }

    // Method 3: TBW calculation (if we have write data)
    if ($tbwCalculated !== null && $tbwCalculated > 0) {
        $estimatedMaxTBW = estimateNvmeTBW($sizeBytes);
        $remainingTBW = $estimatedMaxTBW - $tbwCalculated;

        if ($remainingTBW > 0) {
            // Assume moderate write workload: 20 TB/year for home NAS
            $assumedWriteRate = 20; // TB/year
            $yearsRemaining = $remainingTBW / $assumedWriteRate;

            return [
                'months_remaining' => round($yearsRemaining * 12),
                'confidence' => 'medium',
                'method' => 'tbw_estimate',
                'notes' => "Based on estimated " . $estimatedMaxTBW . " TBW rating and 20 TB/year write rate.",
                'estimated_tbw' => $estimatedMaxTBW,
                'written_tbw' => $tbwCalculated,
                'remaining_tbw' => round($remainingTBW, 1)
            ];
        }
    }

    // Fallback: No reliable estimate
    return [
        'months_remaining' => null,
        'confidence' => 'none',
        'method' => 'insufficient_data',
        'notes' => 'Insufficient data for lifespan estimate.'
    ];
}

/**
 * Calculate estimated remaining life for HDD based on age and AFR
 *
 * Uses Backblaze AFR curves: failure rate increases with age
 *
 * @param int $powerOnHours Current power-on hours
 * @param array $hddWarnings Health warnings from SMART
 * @return array ['months_remaining' => int, 'confidence' => string, 'reason' => string]
 */
function estimateHddRemainingLife($powerOnHours, $hddWarnings) {
    $ageYears = $powerOnHours / 8760;

    // CRITICAL: If drive has warnings, estimate very short remaining life
    if (!empty($hddWarnings)) {
        foreach ($hddWarnings as $warning) {
            if ($warning['level'] === 'critical') {
                return [
                    'months_remaining' => 1,
                    'confidence' => 'high',
                    'reason' => 'Critical SMART warnings detected',
                    'action' => 'Replace immediately'
                ];
            }
        }

        // Non-critical warnings: 6-12 months
        return [
            'months_remaining' => 6,
            'confidence' => 'medium',
            'reason' => 'SMART warnings detected',
            'action' => 'Plan replacement within 6 months'
        ];
    }

    // Age-based estimates using Backblaze AFR curves
    // AFR increases significantly after 4-5 years

    if ($ageYears < 2) {
        // Young drive: 5+ years remaining
        return [
            'months_remaining' => 60,
            'confidence' => 'medium',
            'reason' => 'Drive is relatively new',
            'action' => 'No action needed'
        ];
    } elseif ($ageYears < 4) {
        // Mid-life: 2-4 years remaining
        return [
            'months_remaining' => 36,
            'confidence' => 'medium',
            'reason' => 'Drive is in normal operating range',
            'action' => 'Monitor regularly'
        ];
    } elseif ($ageYears < 6) {
        // Aging: 1-2 years remaining
        return [
            'months_remaining' => 18,
            'confidence' => 'low',
            'reason' => 'Drive is aging, AFR increasing',
            'action' => 'Plan replacement within 1-2 years'
        ];
    } else {
        // Old: <1 year remaining
        return [
            'months_remaining' => 6,
            'confidence' => 'low',
            'reason' => 'Drive exceeds typical lifespan',
            'action' => 'Replace within 6-12 months'
        ];
    }
}

/**
 * Get user-friendly replacement timeline text
 *
 * @param int $monthsRemaining Months until recommended replacement
 * @return array ['text' => string, 'class' => string]
 */
function getReplacementTimelineText($monthsRemaining) {
    if ($monthsRemaining === null) {
        return [
            'text' => 'Unknown',
            'class' => 'timeline-unknown'
        ];
    }

    if ($monthsRemaining <= 1) {
        return [
            'text' => 'Replace Now',
            'class' => 'timeline-critical'
        ];
    } elseif ($monthsRemaining <= 6) {
        return [
            'text' => $monthsRemaining . ' months',
            'class' => 'timeline-warning'
        ];
    } elseif ($monthsRemaining <= 12) {
        return [
            'text' => 'Within 1 year',
            'class' => 'timeline-caution'
        ];
    } elseif ($monthsRemaining <= 36) {
        return [
            'text' => round($monthsRemaining / 12, 1) . ' years',
            'class' => 'timeline-normal'
        ];
    } else {
        return [
            'text' => '5+ years',
            'class' => 'timeline-healthy'
        ];
    }
}
```

---

### Component 5: UI Enhancements

#### New Dashboard Columns

**Modify `DriveAgeDashboard.page` table headers:**

```html
<th class="sortable" data-column="device_name">Device</th>
<th class="sortable" data-column="identification">Identification</th>
<th class="sortable" data-column="size_bytes">Size</th>
<th class="sortable" data-column="power_on_hours">Power On Hours</th>
<th>Human Format</th>
<th>Health Status</th> <!-- NEW COLUMN -->
<th>Est. Replacement</th> <!-- NEW COLUMN -->
```

#### Health Status Column Display

**In `dashboard.js` `renderTableView()`:**

```javascript
// Health Status column
html += '<td class="health-status-cell">';

if (drive.has_warnings) {
    // Show warning icons with tooltips
    drive.health_warnings.forEach(warning => {
        const iconClass = warning.level === 'critical' ? 'warning-critical' : 'warning-caution';
        const icon = warning.level === 'critical' ? '⚠️' : '⚡';

        html += `<span class="${iconClass}" title="${escapeHtml(warning.tooltip)}">`;
        html += `${icon} ${escapeHtml(warning.message)}`;
        html += '</span> ';
    });
} else {
    html += '<span class="health-ok" title="No warnings detected">✓ Healthy</span>';
}

html += '</td>';

// Estimated Replacement column
const timeline = drive.replacement_timeline || {};
html += `<td class="${timeline.class || ''}" title="${escapeHtml(timeline.tooltip || '')}">`;
html += escapeHtml(timeline.text || 'Unknown');
html += '</td>';
```

#### Tooltips for Technical Details

**Add help icons with detailed information:**

```html
<!-- In legend section -->
<div class="help-section">
    <button class="help-icon" onclick="toggleHelpModal('nvme-metrics')">
        ❓ NVMe Health Metrics
    </button>
    <button class="help-icon" onclick="toggleHelpModal('hdd-smart')">
        ❓ HDD SMART Attributes
    </button>
    <button class="help-icon" onclick="toggleHelpModal('predictions')">
        ❓ How Predictions Work
    </button>
</div>

<!-- Modal dialogs with detailed explanations -->
<div id="help-modal-nvme-metrics" class="help-modal" style="display:none;">
    <div class="modal-content">
        <span class="close" onclick="toggleHelpModal('nvme-metrics')">&times;</span>
        <h3>NVMe Health Metrics</h3>
        <ul>
            <li><strong>Percentage Used:</strong> Vendor estimate of wear (0-100%+)</li>
            <li><strong>Available Spare:</strong> Remaining spare NAND cells</li>
            <li><strong>Media Errors:</strong> Uncorrectable data errors</li>
        </ul>
        <p>Learn more: <a href="https://nvmexpress.org" target="_blank">NVM Express</a></p>
    </div>
</div>
```

---

### Component 6: Enhanced Charts

**Modify `charts.js` to add health distribution chart:**

```javascript
/**
 * Render drive health warnings distribution (pie chart)
 * Shows how many drives have warnings vs healthy
 */
function renderHealthChart(driveData) {
    const ctx = document.getElementById('health-chart');
    if (!ctx) return;

    let healthy = 0;
    let warnings = 0;
    let critical = 0;

    driveData.drives.forEach(drive => {
        if (!drive.has_warnings) {
            healthy++;
        } else {
            const hasCritical = drive.health_warnings.some(w => w.level === 'critical');
            if (hasCritical) {
                critical++;
            } else {
                warnings++;
            }
        }
    });

    healthChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Healthy', 'Warnings', 'Critical'],
            datasets: [{
                data: [healthy, warnings, critical],
                backgroundColor: ['#4CAF50', '#FFC107', '#F44336']
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'bottom' },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.label + ': ' + context.parsed + ' drives';
                        }
                    }
                }
            }
        }
    });
}
```

---

### Component 7: Documentation

**Create `docs/METHODOLOGY.md`:**

```markdown
# DriveAge Methodology & Assumptions

## Risk Assessment Methods

### HDD Risk Categories (Age-Based)

Based on Backblaze Hard Drive Stats 2024:
- AFR increases with age, especially after 4-5 years
- Categories map to AFR thresholds from Backblaze data

**Data Source:** [Backblaze 2024 Drive Stats](https://www.backblaze.com/blog/backblaze-drive-stats-for-2024/)

### NVMe Risk Categories (Wear-Based)

Based on NVMe SMART health metrics:
- **Percentage Used:** Vendor-specific wear estimate
- **Available Spare:** Critical metric for actual remaining life
- **Media Errors:** Direct indicator of drive failure

**Key Research Finding:** Percentage Used > 100% alone is NOT a failure predictor.
Available Spare below threshold is the critical metric.

**Sources:**
- [NVMe Health Monitoring](https://nvmexpress.org/resource/features-for-error-reporting-smart-log-pages-failures-and-management-capabilities-in-nvme-architectures/)
- [Proxmox NVMe Discussion](https://forum.proxmox.com/threads/nvme-disk-wearout.67831/)

## Predictive Replacement Estimates

### NVMe Lifespan Estimation

**TBW Ratings (Generic Estimates):**
```
256GB:  250 TBW (conservative)
512GB:  300 TBW
1TB:    500 TBW
2TB:    1000 TBW
4TB:    2000 TBW
```

Formula: `Conservative TBW = capacity_TB × 500`

**Assumed Write Workload:** 20 TB/year (moderate home NAS usage)
- Light: 10-15 TB/year (media server only)
- Moderate: 20-30 TB/year (backup + media)
- Heavy: 40-60 TB/year (VMs, containers)

**Confidence Levels:**
- **High:** Drive has critical warnings (immediate replacement)
- **Medium:** Using TBW calculation with known write rate
- **Low:** Using percentage used or spare depletion (linear extrapolation)
- **None:** Insufficient data

**Sources:**
- [SSD TBW Ratings 2024](https://forums.tomshardware.com/threads/choosing-an-nvme-ssd-higher-tbw-rating-or-longer-warranty.3755858/)
- [NAS Write Workloads](https://nas-uk.ugreen.com/blogs/knowledge/ssd-lifespan-in-nas-guide)

### HDD Lifespan Estimation

**Age-Based Estimates:**
- < 2 years: 5+ years remaining
- 2-4 years: 2-4 years remaining
- 4-6 years: 1-2 years remaining
- > 6 years: < 1 year remaining

**SMART Override:** Any critical SMART attributes (reallocated sectors, pending sectors, uncorrectable errors) reduce estimate to immediate replacement.

**Sources:**
- [HDD SMART Failure Predictors](https://horizontechnology.com/news/smart-attributes-for-predicting-hdd-failure/)
- [Reallocated Sector Guidance](https://hdsentinel.com/blog/reallocated-sector-count)

## Limitations & Caveats

1. **Generic TBW Estimates:** Actual TBW ratings vary by manufacturer and model
2. **Write Workload Assumptions:** Actual usage patterns vary significantly
3. **Vendor-Specific Metrics:** Percentage Used calculation varies by manufacturer
4. **No Historical Data:** First-run predictions are less accurate (improves over time)
5. **Linear Wear Assumptions:** Actual wear may not be linear

## Improving Accuracy

The plugin will improve accuracy over time by:
1. Tracking historical Percentage Used values (wear rate)
2. Calculating actual write velocity from Data Units Written deltas
3. Detecting trends in SMART attribute changes

## References

[Full bibliography of research sources...]
```

---

## Implementation Sequence

### Phase 1: Enhanced SMART Collection (Week 1)
1. Add `parseNvmeSmartAttributes()` function
2. Add `parseHddSmartAttributes()` function
3. Integrate into existing parsers
4. Update `getDriveInfo()` to include new attributes
5. Test SMART data extraction on both drive types

### Phase 2: Risk Assessment (Week 2)
1. Create `predictions.php` file
2. Implement `getNvmeRiskCategory()`
3. Implement `getHddHealthWarnings()`
4. Integrate into `getDriveInfo()`
5. Test risk categorization

### Phase 3: Predictive Estimates (Week 3)
1. Implement TBW estimation functions
2. Implement lifespan calculation functions
3. Add to JSON API response
4. Test predictions with various drive states

### Phase 4: UI Enhancements (Week 4)
1. Add Health Status column
2. Add Estimated Replacement column
3. Implement tooltips and help modals
4. Add health distribution chart
5. Update CSS styling

### Phase 5: Documentation (Week 5)
1. Create METHODOLOGY.md
2. Update README.md
3. Add inline code documentation
4. Create user guide section

### Phase 6: Testing & Refinement (Week 6)
1. Test with various HDD models and ages
2. Test with various NVMe drives
3. Verify predictions accuracy
4. User feedback iteration
5. Performance testing

---

## Testing Strategy

### Test Cases

**NVMe Drives:**
1. New drive (0% used, 100% spare) → Minimal Risk, 5+ years
2. Mid-life (50% used, 80% spare) → Low Risk, 2-3 years
3. High wear (90% used, 30% spare) → Moderate Risk, 6-18 months
4. Near end (105% used, 15% spare) → Elevated Risk, < 6 months
5. Critical (Media errors > 0) → High Risk, Replace Now

**HDD Drives:**
1. New (< 2 years, no SMART warnings) → Minimal Risk, 5+ years
2. Mid-life (3 years, no warnings) → Normal, 2-3 years
3. Aging (5 years, no warnings) → Aged, 1-2 years
4. Reallocated sectors (any age) → Warning, 6 months
5. Pending sectors (any age) → Critical, Replace Now

---

## Migration Path

### Backwards Compatibility

- Existing age-based categories remain for HDDs
- NVMe drives switch to wear-based categories
- No changes to config file format
- Graceful degradation if SMART data unavailable

### User Communication

- Changelog explaining new features
- Help modals for new metrics
- FAQ section in documentation
- Clear tooltips explaining predictions

---

## Performance Considerations

- SMART parsing adds ~10-20ms per drive
- Prediction calculations add ~5ms per drive
- Total impact: ~25ms per drive (50 drives = 1.25s)
- Acceptable for dashboard refresh (target < 2s)

---

## Future Enhancements

1. **Historical Tracking:** Track wear metrics over time for trend analysis
2. **Write Velocity Calculation:** Measure actual write rates from Data Units Written
3. **Email Alerts:** Notify admins when drives reach critical thresholds
4. **Manufacturer Database:** Lookup actual TBW ratings from drive model database
5. **ML-Based Predictions:** Use machine learning for more accurate failure prediction

---

## Questions for Review

1. Are the NVMe risk category thresholds appropriate?
2. Is the HDD SMART warning logic too aggressive or conservative?
3. Should we display TBW estimates even if generic?
4. What additional tooltips/help would be useful?
5. Should we add a "Replace Soon" list widget to the dashboard?

---

**Document Version:** 1.0
**Last Updated:** 2025-12-26
**Authors:** Claude Code + User Collaboration
