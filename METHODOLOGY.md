# DriveAge Methodology & Research Documentation

This document describes the risk assessment and predictive replacement methodologies implemented in the DriveAge plugin. It is kept in sync with the shipped code - the formulas below are the formulas that run (see `tests/run.php` for executable examples of each).

---

## Table of Contents

1. [Overview](#overview)
2. [Research Sources](#research-sources)
3. [HDD Age-Based Risk Assessment](#hdd-age-based-risk-assessment)
4. [NVMe Wear-Based Risk Assessment](#nvme-wear-based-risk-assessment)
5. [HDD Predictive Replacement Estimation](#hdd-predictive-replacement-estimation)
6. [NVMe Predictive Replacement Estimation](#nvme-predictive-replacement-estimation)
7. [Conservative vs Aggressive Modes](#conservative-vs-aggressive-modes)
8. [Critical SMART Attributes](#critical-smart-attributes)
9. [Data Sources & Freshness](#data-sources--freshness)
10. [Confidence Levels](#confidence-levels)
11. [Limitations & Assumptions](#limitations--assumptions)

---

## Overview

DriveAge implements a **dual risk model**:

- **HDDs (and USB devices)**: Age-based risk calibrated to Backblaze's 2025 fleet data, combined with critical SMART attribute monitoring that overrides age when pre-failure indicators appear
- **NVMe SSDs**: Wear-based risk using Percentage Used and Available Spare, with replacement projections computed from the drive's **own measured write history**

Both models produce **predictive replacement estimates** carrying an explicit method and confidence level.

---

## Research Sources

### Annual Failure Rate (AFR) Data

**Primary Source: Backblaze "Let's Revisit the Bathtub Curve" (2025)**
- URL: https://www.backblaze.com/blog/are-hard-drives-getting-better-lets-revisit-the-bathtub-curve/
- Data: ~317,230 drives in production
- Key findings the defaults are calibrated to:
  - Drives aged 0-1 years: **~1.3% AFR**
  - Failure rate stays **roughly flat through the majority of drive life**
  - A steep ramp appears near end of life, peaking at **~4.25% AFR at ~10 years 3 months**
  - The 2025 peak is roughly **one third** of the peaks measured in the 2013 (13.73%) and 2021 (14.24%) analyses - drives are lasting significantly longer than older studies suggested
- Supporting: Backblaze quarterly Drive Stats reports (https://www.backblaze.com/blog/backblaze-drive-stats-for-2024/)

**Implication**: earlier DriveAge versions (and most folk wisdom) assumed AFR explodes after year 4-5. The current data does not support that; the default bands and replacement targets were recalibrated accordingly in v2026.07.16.

**Secondary Source: Google Drive Reliability Study (2007)**
- URL: https://research.google/pubs/pub32774/
- Data: 100,000+ drives in Google data centers
- Key Finding: SMART attributes 5, 187, 188, 197, 198 are strong failure predictors. DriveAge evaluates **all five**.

### NVMe Endurance & TBW Data

Vendor endurance ratings for consumer NVMe drives typically run **~220 TBW/TB (QLC budget) to ~600-750 TBW/TB (premium TLC)**. Since SMART does not expose the vendor rating, DriveAge uses **500 TBW per TB of capacity** as a mid-market estimate when projecting endurance.

Write rates are **measured per drive** from SMART data (see below); fixed write-rate assumptions (30/15 TB/year) are used only as a fallback for drives with less than ~3 months of history.

### SMART Attribute Thresholds

**Source: smartmontools Documentation & Industry Standards**
- URL: https://www.smartmontools.org/
- See [Critical SMART Attributes](#critical-smart-attributes) for the exact thresholds.

---

## HDD Age-Based Risk Assessment

### Methodology

HDDs are assessed using **power-on hours** converted to age (1 year = 8,760 hours). Default risk categories follow the shape of the 2025 Backblaze curve: flat-ish AFR through mid-life, ramping toward the ~10.3-year peak.

### Default Risk Categories (all configurable)

| Risk Category | Age Range | Approx. AFR | Color |
|--------------|-----------|-----------|-------|
| **Minimal Risk** | < 4 years | ~1% | Green |
| **Low Risk** | 4-6 years | ~1.5% | Light Green |
| **Moderate Risk** | 6-8 years | ~2% | Amber |
| **Elevated Risk** | 8-10 years | ~2.5-4% | Orange |
| **High Risk** | ≥ 10 years | ~4%+ (measured peak: 4.25% @ 10.3y) | Red |
| **N/A** | no SMART data | - | Gray |

The AFR figures are approximate interpolations of the 2025 fleet curve (Backblaze publishes year-1 and peak values precisely; mid-life values are read from the published curve). They describe fleet-average behavior, not any individual drive.

### Risk Overrides

A drive is escalated to **High Risk regardless of age** when any of the following hold - and the category, row color, and label are updated together:

- SMART overall-health self-assessment = **FAILED**
- Any critical SMART attribute warning (see below)
- Replacement estimate under 6 months

Devices with no SMART data at all (typically the USB boot drive) are categorized **N/A**, not Minimal Risk.

---

## NVMe Wear-Based Risk Assessment

### Methodology

NVMe drives are assessed on two wear metrics; the **more severe of the two wins**:
1. **Percentage Used** (0-255%, vendor wear indicator)
2. **Available Spare** (100% = full spare)

### Risk Categories

| Risk Category | Percentage Used | Available Spare |
|--------------|-----------------|-----------------|
| **Minimal Risk** | < 50% | > 80% |
| **Low Risk** | 50-79% | 60-80% |
| **Moderate Risk** | 80-99% | 40-60% |
| **Elevated Risk** | > 100% | within 10 pts of threshold |
| **High Risk** | critical conditions below | < vendor threshold |

### Critical Conditions (immediate High Risk)

- SMART overall-health FAILED
- Media Errors > 0
- Critical Warning flag > 0
- Available Spare < Available Spare Threshold (typically 10%)

---

## HDD Predictive Replacement Estimation

Evaluated in order; the first match wins. Every result carries `method` and `confidence`.

#### Method 0: SMART FAILED (`smart_failed`)
- **Trigger**: overall-health self-assessment FAILED
- **Estimate**: 0 months (Replace Now) · **Confidence**: High

#### Method 1: Critical SMART Warnings (`critical_smart`)
- **Trigger**: any critical attribute warning (197>0, 198>0, 5>10, 187>10, 188>100)
- **Estimate**: 0 months (Replace Now) · **Confidence**: High

#### Method 2: Non-Critical SMART Warnings (`smart_warnings`)
- **Trigger**: caution-level warnings (5: 1-10, 187: 1-10, 188: 1-100)
- **Estimate**: 3 months (conservative) / 6 months (aggressive) · **Confidence**: Medium

#### Method 3: Age Countdown (`age_linear`)
A linear countdown to the target replacement age:

```
months_remaining = (target_age_years − POH/8760) × 12
```

| Mode | Target age | Rationale (Backblaze 2025) |
|------|-----------|----------------------------|
| Conservative | **8 years** | Replace before the end-of-life AFR ramp begins in earnest |
| Aggressive | **10 years** | Ride drives to the measured fleet AFR peak (~4.25% @ 10.3y) |

Drives past the target age degrade gracefully from 6 months down to "Replace Now". This is deliberately a **replacement-planning countdown**, not a survival model - the AFR data informs *where the targets sit*, and the SMART overrides catch individual drives that deviate from fleet behavior.

#### No data (`no_data`)
Drives without power-on hours (and not FAILED) return Unknown / Confidence: None.

---

## NVMe Predictive Replacement Estimation

All three methods prefer the drive's **own measured history**. A measured rate is used when the drive has at least **~3 months (0.25 years) of power-on time**; otherwise the legacy assumptions apply as a fallback. In conservative mode, measured rates get a **+25% safety margin**. All results are capped at the mode's target age (96/120 months).

### Method 1: TBW Projection (`tbw_measured_rate` / `tbw_assumed_rate`)

```
tbw_written        = data_units_written × 512,000 bytes
estimated_max_tbw  = capacity_TB × 500          (mid-market TLC estimate)
measured_rate      = tbw_written / (POH / 8760)              [TB per year]
write_rate         = measured_rate × 1.25 (conservative) or × 1.0 (aggressive)
months_remaining   = (estimated_max_tbw − tbw_written) / write_rate × 12
```

Fallback (short history): assumed 30 TB/yr (conservative) or 15 TB/yr (aggressive).
**Confidence**: Medium (measured) / Low (assumed).

**Example** (2 TB drive, 24.5 TB written over 24,845 hours ≈ 2.84 years):
- measured rate = 24.5 / 2.84 ≈ 8.6 TB/yr → conservative rate ≈ 10.8 TB/yr
- remaining ≈ 909 − 24.5 ≈ 884 TBW → ≈ 82 years of endurance
- capped at the 8-year conservative target → "8 years"

### Method 2: Percentage Used Slope (`percentage_used_measured` / `percentage_used_linear`)

```
wear_per_year    = percentage_used / (POH / 8760)  [× 1.25 in conservative mode]
months_remaining = (100 − percentage_used) / wear_per_year × 12
```

Fallback (short history): assume a 5-year wear life (20%/year), reduced ×1.5 in conservative mode.
**Confidence**: Medium (measured) / Low (assumed).

### Method 3: Available Spare Depletion (`spare_depletion`)

```
depletion_per_year = (100 − available_spare) / (POH / 8760)  [× 1.25 conservative]
months_remaining   = (available_spare − spare_threshold) / depletion_per_year × 12
```

Fallback (short history): assume current consumption took 2 years.
**Confidence**: Low (spare depletion is often non-linear).

### Critical overrides (before all methods)
SMART FAILED, media errors, or an active critical warning → 0 months; spare below threshold → 1 month. All High confidence.

### Fallback: Insufficient Data (`insufficient_data`)
New drives with 0% wear, no write data and full spare show "Unknown" until history accumulates.

---

## Conservative vs Aggressive Modes

| | Conservative (default) | Aggressive |
|---|---|---|
| HDD target age | **8 years** | **10 years** |
| HDD caution-warning estimate | 3 months | 6 months |
| NVMe measured-rate margin | **+25%** | none |
| NVMe fallback write rate | 30 TB/yr | 15 TB/yr |
| NVMe estimate cap | 96 months | 120 months |
| Best for | production / critical data | home labs / maximize drive life |

---

## Critical SMART Attributes

### HDD SMART Attributes (all five Google-study predictors evaluated)

| ID | Name | Caution | Critical |
|----|------|-------------------|-------------------|
| **5** | Reallocated Sector Count | 1-10 | > 10 |
| **187** | Reported Uncorrectable Errors | 1-10 | > 10 |
| **188** | Command Timeout | 1-100 | > 100 |
| **197** | Current Pending Sectors | - | > 0 |
| **198** | Uncorrectable Sector Count | - | > 0 |
| - | SMART overall-health | - | FAILED |

**Attribute 188 note**: many drives pack three 16-bit counters into the 48-bit raw value. DriveAge masks to the low 16 bits when the raw value exceeds 65,535, so composite encodings don't produce false criticals.

**Raw-value parsing note**: vendor raw formats such as `34 (Min/Max 21/49)` and `33477h+12m+30.271s` are handled by extracting the leading integer (matching smartmontools' normalized display).

### NVMe SMART Attributes

| Attribute | Caution | Critical |
|-----------|-------------------|-------------------|
| **Percentage Used** | ≥ 80% | > 100% |
| **Available Spare** | ≤ 60% | < Spare Threshold |
| **Media Errors** | - | > 0 |
| **Critical Warning** | - | > 0 |
| **SMART overall-health** | - | FAILED |

---

## Data Sources & Freshness

DriveAge does not poll drives itself. Sources, in order of authority:

1. **`/var/local/emhttp/disks.ini` / `devs.ini`** - device enumeration, ids, sizes (sectors × sector size), live temperatures and spin state. Enumerating from Unraid's state (not `/dev` globs) supports >26 SATA devices, multi-digit NVMe names and keeps values identical to the Main page.
2. **`/var/local/emhttp/smart/<dev>`** - Unraid's cached smartctl output (ATA attribute tables, NVMe health logs, SCSI/SAS text are all parsed). Unraid refreshes these on the **Disk Settings `poll_attributes` interval - default 1800 s (30 minutes)**. The dashboard displays the actual data age.
3. **`/var/local/emhttp/smart/<dev>.ssa`** - Unraid's cached overall-health verdict.
4. **Direct `smartctl -a -j`** - last resort only, when no cache file exists and the drive is not spun down (e.g. first run). The JSON parse uses smartctl's universal `power_on_time`/`temperature`/`smart_status` fields, so SAS drives work too.

Spun-down drives are **never woken**. Their last-known power-on hours persist in `/boot/config/plugins/driveage/drivecache.json` (single file, rewritten only when a value changes), so they survive reboots.

---

## Confidence Levels

| Level | Meaning | Examples |
|---|---|---|
| **High** | Strong evidence of critical condition | SMART FAILED, pending sectors, media errors, spare below threshold |
| **Medium** | Estimate from measured data or fleet statistics | age countdown, measured NVMe write rate, measured wear slope, caution warnings |
| **Low** | Assumption-based fallback | assumed write rates (short history), spare depletion |
| **None** | Cannot estimate | no power-on hours, brand-new NVMe with zero wear |

---

## Limitations & Assumptions

1. **Fleet vs individual**: Backblaze AFR describes data-center fleet averages of specific models. Your drives, workload and environment differ; individual drives fail without warning at any age.
2. **AFR labels are approximations**: year-1 (~1.3%) and peak (~4.25% @ ~10.3y) are published precisely; the mid-life band labels are interpolations of the published curve.
3. **NVMe endurance rating is estimated** (500 TBW/TB): actual vendor ratings range ~220-750 TBW/TB. Enterprise drives can be 5-10× higher. Write amplification is assumed ≈1.
4. **Measured rates assume history predicts the future**: a workload change (e.g. new backup target) invalidates the projection until history catches up. Conservative mode's +25% margin partially hedges this.
5. **SATA SSDs** are currently assessed with the age-based model (they expose wear attributes inconsistently across vendors).
6. **SMART reliability varies** by vendor/firmware; ~half of failed drives in Google's study showed no SMART warnings first. **These estimates are probabilistic planning guidance, not guarantees. Keep backups.**

---

## Recommended Actions

1. **Don't panic on red**: High Risk means elevated probability and planning priority, not imminent death - except when driven by critical SMART warnings, which deserve immediate action.
2. **Watch pending sectors**: any non-zero 197/198 is the strongest "act now" signal.
3. **Have backups**: no prediction model substitutes for them.
4. **Use conservative mode for critical data**; aggressive mode to maximize service life on replaceable data.

---

## Version History

- **v2026.07.16**: Recalibrated to Backblaze 2025 bathtub-curve revisit (4/6/8/10-year bands; 8yr/10yr targets). NVMe projections now use measured per-drive wear rates. Added 187/188 evaluation, SMART-FAILED surfacing/forcing, N/A category, data-freshness reporting, and a unit test suite covering every formula in this document.
- **v1.0 (2025-12-26)**: Initial dual risk model and predictive replacement estimates.

---

## References

1. Backblaze - Are Hard Drives Getting Better? Let's Revisit the Bathtub Curve (2025): https://www.backblaze.com/blog/are-hard-drives-getting-better-lets-revisit-the-bathtub-curve/
2. Backblaze Drive Stats for 2024: https://www.backblaze.com/blog/backblaze-drive-stats-for-2024/
3. Google - Failure Trends in a Large Disk Drive Population (2007): https://research.google/pubs/pub32774/
4. smartmontools Documentation: https://www.smartmontools.org/
5. NVMe Specification - SMART/Health Information Log
6. JEDEC JESD218/JESD219 - SSD Endurance Standards

---

**Document Maintained By**: DriveAge Development Team
**Last Updated**: 2026-07-16
**Plugin Version**: 2026.07.16+
