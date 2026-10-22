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
- **NVMe and SATA SSDs**: Wear-based risk using the drive's own wear indicator (NVMe Percentage Used / Available Spare, or the SATA SSD's wear attribute), with replacement projections computed from the drive's **own measured wear rate**

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

Vendor endurance ratings for consumer NVMe drives typically run **~220 TBW/TB (QLC budget) to ~600-750 TBW/TB (premium TLC)**. SMART does not expose the vendor rating, but NVMe drives report **Percentage Used** - the drive's own endurance estimate - and DriveAge uses that first. Only drives without a wear indicator fall back to **500 TBW per (decimal) TB of capacity** as a mid-market guess.

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

### Lifecycle, Health and Overall Risk

DriveAge keeps two independent dimensions and combines them only for display:

| Dimension | Field | Source | Meaning |
|---|---|---|---|
| **Lifecycle** | `age_category` | HDD age bands above; NVMe wear bands below | Fleet statistics for a drive of this age/wear. **Never escalated** by SMART or by the replacement estimate. |
| **Health** | `health_status` | This drive's SMART evidence | `failing` (SMART FAILED or any critical attribute), `watch` (warning-level attributes), `ok`, `unknown` (no SMART data) |
| **Overall** | `risk_category` | Worse of the two | Row color, distribution chart, sorting |

Combination rules:

- Health **failing** forces overall **High Risk**, labelled "Failing (SMART)".
- Health **watch** raises the overall category to at least **Elevated Risk**, labelled "Warning (SMART)" when it was the deciding factor.
- Otherwise the overall category is the lifecycle category with its own label (AFR figure for HDDs, wear percentage for NVMe).

The replacement estimate does **not** feed back into the category. (Earlier versions escalated any drive with < 6 months remaining to High Risk, which turned every healthy HDD red - labelled "AFR ~4%+" - at ~7.5 years in conservative mode.)

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
| **Low Risk** | 50-79% | 51-80% |
| **Moderate Risk** | 80-100% | ≤ 50% |
| **Elevated Risk** | > 100% | within 10 pts of the threshold |
| **High Risk** | - | < vendor threshold |

A drive reporting neither metric falls back to its age band (N/A without power-on hours) - never Minimal Risk by default.

### Health Conditions (health `failing` → overall High Risk)

- SMART overall-health FAILED
- Media Errors > 0
- Critical Warning bits 0x01 (spare low), 0x04 (reliability degraded), 0x08 (read-only), 0x10 (backup failed), 0x20 (PMR read-only)

Bit 0x02 (temperature) and unrecognized bits raise health `watch` only.

### End of Wear Life (lifecycle High Risk)

- Available Spare < Available Spare Threshold (typically 10%)

---

## SATA SSD Wear Assessment

### Detection

A drive is treated as a SATA SSD when Unraid marks it non-rotational (`rotational="0"` in `disks.ini` - the flag dynamix uses for its SSD temperature limits), when smartctl reports `Rotation Rate: Solid State Device` (JSON: `rotation_rate: 0`), or when it reports a known SSD wear attribute.

### Wear indicator (by smartctl attribute name - IDs are vendor-specific)

| Attribute name | Typical ID / vendor | Reading |
|---|---|---|
| `Percent_Lifetime_Remain` | 202 Crucial/Micron | used = 100 − normalized VALUE |
| `Percent_Lifetime_Used`, `Perc_Rated_Life_Used` | SanDisk/WD | used = RAW |
| `SSD_Life_Left` | 231 Kingston, others | used = 100 − VALUE |
| `Remaining_Lifetime_Perc` | 169 WD/SanDisk | used = 100 − VALUE |
| `Media_Wearout_Indicator` | 233 Intel, WD | used = 100 − VALUE |
| `Wear_Leveling_Count` | 177 Samsung | used = 100 − VALUE |

The first attribute in this order that the drive reports is used. Host writes come from `Total_LBAs_Written` (× 512 bytes), `Host_Writes_32MiB`, `Host_Writes_GiB`/`Lifetime_Writes_GiB`/`Total_Writes_GiB` or `Host_Writes_MiB`.

### Lifecycle band and estimate

The wear indicator maps to the same bands as NVMe Percentage Used (< 50% minimal, 50-79% low, 80-100% moderate, > 100% elevated) and feeds the same flash estimator (see [NVMe Predictive Replacement Estimation](#nvme-predictive-replacement-estimation)) after the SMART overrides. An SSD reporting no wear attribute uses its age band, labelled "(age)"; its estimate falls back to host writes against an estimated rating (Method 2) when it reports a host-writes attribute, otherwise "Unknown".

---

## HDD Predictive Replacement Estimation

Evaluated in order; the first match wins. Every result carries `method` and `confidence`.

#### Method 0: SMART FAILED (`smart_failed`)
- **Trigger**: overall-health self-assessment FAILED
- **Estimate**: 0 months (Replace Now) · **Confidence**: High

#### Method 1: Critical SMART Warnings (`critical_smart`)
- **Trigger**: any critical attribute warning - 197 > 0, 198 > 0, 188 > 100, or 5/187/SAS counters per the trend rule (rising within 30 days; > 10 with no history; > 100 when stable)
- **Estimate**: 0 months (Replace Now) · **Confidence**: High

#### Method 2: Non-Critical SMART Warnings (`smart_warnings` / `smart_warnings_stable`)
- **Trigger**: warning-level findings (see [Critical SMART Attributes](#critical-smart-attributes))
- **Estimate**: 3 months (conservative) / 6 months (aggressive) · **Confidence**: Medium
- **Stable** (`smart_warnings_stable`): when drivecache history shows *every* warning unchanged for 30+ days, the drive is stable with a known defect - estimate 12 months (conservative) / 24 months (aggressive), Low confidence
- Never longer than the drive's own age countdown; a drive already past its target age stays "Due now"

#### Method 3: Age Countdown (`age_linear`)
A linear countdown to the target replacement age:

```
months_remaining = (target_age_years − POH/8760) × 12
```

| Mode | Target age | Rationale (Backblaze 2025) |
|------|-----------|----------------------------|
| Conservative | **8 years** | Replace before the end-of-life AFR ramp begins in earnest |
| Aggressive | **10 years** | Ride drives to the measured fleet AFR peak (~4.25% @ 10.3y) |

At or past the target age the estimate is 0 months, shown as **"Due now"** in warning style - "Replace Now" is reserved for SMART evidence. The countdown is monotonic; an earlier 6-month grace period made it jump from 1 month back up to 6 at the target age. This is deliberately a **replacement-planning countdown**, not a survival model - the AFR data informs *where the targets sit*, and the SMART overrides catch individual drives that deviate from fleet behavior.

#### No data (`no_data`)
Drives without power-on hours (and not FAILED) return Unknown / Confidence: None.

---

## NVMe Predictive Replacement Estimation

Evaluated in order; the first match wins. A measured rate is used when the drive has at least **~3 months (0.25 years) of power-on time**; otherwise fallback assumptions apply. In conservative mode, measured rates get a **+25% safety margin**.

**Display horizon**: projections beyond **120 months are shown as "10+ years"** (`capped: true`) in both modes. This is a display horizon, not a lifespan - Backblaze's age curve is HDD data and implies no calendar limit for SSDs. (Earlier versions capped the *remaining* months at the HDD target age, which made a 9-year-old NVMe show "8 years left".)

### Critical overrides (before all methods)

| Condition | Estimate | Method |
|---|---|---|
| SMART overall-health FAILED | 0 months, "Replace Now" | `smart_failed` |
| Media Errors > 0, or Critical Warning bit 0x04 / 0x08 / 0x10 / 0x20 | 0 months, "Replace Now" | `critical_error` |
| Available Spare < threshold, or Critical Warning bit 0x01 | 0 months, "Replace Now" (within a month) | `spare_below_threshold` |

Critical Warning bit **0x02 (temperature)** does *not* trigger replacement - it raises a "watch" health warning advising better cooling. All overrides are High confidence.

### Method 1: Vendor wear indicator (`percentage_used_measured` / `percentage_used_linear`)

Percentage Used is the drive's own estimate of rated endurance consumed. It already reflects the vendor's real endurance rating and the drive's write amplification, so it **outranks any capacity-based guess**.

```
wear_per_year    = percentage_used / (POH / 8760)  [× 1.25 in conservative mode]
months_remaining = (100 − percentage_used) / wear_per_year × 12
```

- **≥ 100% used** (`endurance_exceeded`): 0 months, shown as "Due now" (warning style). Many drives run well past 100%.
- **Recent write rate** (when drivecache history has one): the remaining wear is re-projected at the recent rate using the drive's own wear-per-TB calibration - `wear_per_year = (percentage_used / tbw_written) × rate × margin`, where `rate = max(recent, lifetime)` in conservative mode and `recent` in aggressive mode.
- **Confidence**: Medium at ≥ 5% used; Low below 5% (the indicator moves in whole percent).
- **Fallback** (short history): assume a 5-year wear life (20%/year), reduced ×1.5 in conservative mode. Low confidence.
- **0% after ≥ 3 months** (`wear_below_resolution`): under 1% used so far, so at most ~1%/yr - a lower bound, far beyond the horizon. Low confidence.

**Example** (2 TB drive, 3% used after 24,845 hours ≈ 2.84 years): 1.06%/yr × 1.25 = 1.32%/yr → 97 / 1.32 ≈ 73 years → **"10+ years"**.

**Example** (1 TB QLC drive, 90% used after 3 years, 200 TB written): 30%/yr × 1.25 = 37.5%/yr → 10 / 37.5 × 12 ≈ **3 months**. (The previous TBW-first order estimated ~3 years from a guessed 500 TBW/TB rating.)

### Method 2: TBW against an estimated rating (`tbw_measured_rate` / `tbw_assumed_rate`)

Only when the drive reports **no** wear indicator.

```
tbw_written        = data_units_written × 512,000 bytes
estimated_max_tbw  = capacity_TB (decimal) × 500          (mid-market TLC guess, min 250)
measured_rate      = recent rate, else tbw_written / (POH / 8760)   [TB per year]
write_rate         = measured_rate × 1.25 (conservative) or × 1.0 (aggressive)
months_remaining   = (estimated_max_tbw − tbw_written) / write_rate × 12
```

Fallback (short history): assumed 30 TB/yr (conservative) or 15 TB/yr (aggressive).
**Confidence**: always Low - the endurance rating itself is a guess.

### Method 3: Available Spare Depletion (`spare_depletion`)

```
depletion_per_year = (100 − available_spare) / (POH / 8760)  [× 1.25 conservative]
months_remaining   = (available_spare − spare_threshold) / depletion_per_year × 12
```

Fallback (short history): assume current consumption took 2 years.
**Confidence**: Low (spare depletion is often non-linear).

### Fallback: Insufficient Data (`insufficient_data`)
New drives with no wear indicator, no write data and full spare show "Unknown" until history accumulates.

---

## Conservative vs Aggressive Modes

| | Conservative (default) | Aggressive |
|---|---|---|
| HDD target age | **8 years** | **10 years** |
| HDD caution-warning estimate | 3 months | 6 months |
| NVMe measured-rate margin | **+25%** | none |
| NVMe fallback write rate | 30 TB/yr | 15 TB/yr |
| NVMe display horizon | 120 months ("10+ years") | 120 months ("10+ years") |
| Best for | production / critical data | home labs / maximize drive life |

---

## Critical SMART Attributes

### HDD SMART Attributes (all five Google-study predictors evaluated)

| ID | Name | Caution | Critical |
|----|------|-------------------|-------------------|
| **5** | Reallocated Sector Count | 1-10, or stable for 30+ days | > 10 with no history, **any increase within 30 days**, or > 100 |
| **187** | Reported Uncorrectable Errors | 1-10, or stable for 30+ days | > 10 with no history, **any increase within 30 days**, or > 100 |
| **188** | Command Timeout | 1-100 | > 100 |
| **197** | Current Pending Sectors | - | > 0 |
| **198** | Uncorrectable Sector Count | - | > 0 |
| - | SMART overall-health | - | FAILED |

**Trend rule** (5, 187 and the SAS counters below): the count is compared with the drive's history in `drivecache.json`. A count that rose within the last 30 days is critical whatever its size - a *growing* count is the strongest signal (Google found drives ~14× more likely to fail within 60 days of their first reallocation). A count unchanged for 30+ days is a warning unless it exceeds 10× the threshold. With under 30 days of history the plain count thresholds apply.

### SAS (SCSI) Drives

| Indicator | Source | Caution | Critical |
|---|---|---|---|
| **Grown defect list** | "Elements in grown defect list" | > 0 | any increase within 30 days |
| **Uncorrected errors** | Error counter log, total uncorrected (read + write + verify) | 1-10, or stable | > 10 with no history, or any increase within 30 days |
| SMART health status | "SMART Health Status" | - | not OK (→ FAILED) |

Many healthy SAS drives carry a few grown-defect entries, so the list is never critical by size alone - only by growth.

### SATA SSDs

Only **5** (retired blocks) and **187** are evaluated, with the same rules as for HDDs. Attributes 188/197/198 carry vendor-specific - sometimes buggy - meanings on SSDs (Crucial's 197, for example, flips between 0 and 1), so they are not used.

**Attribute 188 note**: many drives pack three 16-bit counters into the 48-bit raw value. DriveAge masks to the low 16 bits when the raw value exceeds 65,535, so composite encodings don't produce false criticals.

**Raw-value parsing note**: vendor raw formats such as `34 (Min/Max 21/49)` and `33477h+12m+30.271s` are handled by extracting the leading integer (matching smartmontools' normalized display).

### NVMe SMART Attributes

| Attribute | Caution | Critical |
|-----------|-------------------|-------------------|
| **Percentage Used** | lifecycle band only (Moderate at 80%, Elevated above 100%) | - |
| **Available Spare** | lifecycle band only (Low ≤ 80%, Moderate ≤ 50%) | < Spare Threshold |
| **Media Errors** | - | > 0 |
| **Critical Warning** | bit 0x02 (temperature): watch | bits 0x01, 0x04, 0x08, 0x10, 0x20 |
| **SMART overall-health** | - | FAILED |

---

## Data Sources & Freshness

DriveAge does not poll drives itself. Sources, in order of authority:

1. **`/var/local/emhttp/disks.ini` / `devs.ini`** - device enumeration, ids, sizes (sectors × sector size), live temperatures and spin state. Enumerating from Unraid's state (not `/dev` globs) supports >26 SATA devices, multi-digit NVMe names and keeps values identical to the Main page.
2. **`/var/local/emhttp/smart/<name>`** - Unraid's cached smartctl output (ATA attribute tables, NVMe health logs, SCSI/SAS text are all parsed). Files are named after the Unraid disk name (`parity`, `disk1`, `cache`, `dev1`...), with the kernel device name tried as a fallback. Unraid refreshes these on the **Disk Settings `poll_attributes` interval - default 1800 s (30 minutes)**. The dashboard displays the actual data age.
3. **`/var/local/emhttp/smart/<name>.ssa`** - Unraid's cached overall-health verdict.
4. **Direct `smartctl -n standby -a -j`** - last resort only, when no cache file exists and the drive is not spun down (e.g. first run). A single call (a sleeping drive makes smartctl exit instead of waking), using Unraid's per-disk SMART controller type when one is set. The result is kept in RAM (`/tmp`) for one poll interval, so DriveAge never queries a drive more often than Unraid does. The JSON parse uses smartctl's universal `power_on_time`/`temperature`/`smart_status` fields, so SAS drives work too.

Spun-down drives are **never woken**. Their last-known power-on hours persist in `/boot/config/plugins/driveage/drivecache.json` (single file, rewritten only when a value changes, keyed by Unraid's disk id so a drive keeps its own values even if its `sdX` letter changes), so they survive reboots.

### Drive history (same file)

| What | Stored | Used for |
|---|---|---|
| Error counts: 5, 187, 197, 198, SAS grown defects, SAS uncorrected errors | current value, when it was set, the value before the last increase and since when | **growing** (rose within 30 days) vs **stable** (unchanged 30+ days) - see [Critical SMART Attributes](#critical-smart-attributes) |
| SSD/NVMe host writes | one sample per week, ~6 months kept | **recent write rate**: TB per calendar year from the oldest sample in the last 120 days (needs ≥ 30 days) |

Only real SMART readings are recorded - values filled in from the cache for a spun-down drive are never written back. Writes stay rare: error counts change seldom and write samples are weekly; power-on hours (hourly) remain the most frequent change, as before.

---

## Confidence Levels

| Level | Meaning | Examples |
|---|---|---|
| **High** | Strong evidence of critical condition | SMART FAILED, pending sectors, a growing defect count, media errors, spare below threshold |
| **Medium** | Estimate from measured data or fleet statistics | age countdown (> 12 months out), wear indicator ≥ 5% at measured rate, new warning-level findings, endurance consumed |
| **Low** | Coarse or assumption-based | wear indicator < 5%, TBW against an estimated rating, assumed rates (short history), spare depletion, stable warnings, countdown within 12 months |
| **None** | Cannot estimate | no power-on hours, flash drive with no wear data yet |

---

## Limitations & Assumptions

1. **Fleet vs individual**: Backblaze AFR describes data-center fleet averages of specific models. Your drives, workload and environment differ; individual drives fail without warning at any age.
2. **AFR labels are approximations**: year-1 (~1.3%) and peak (~4.25% @ ~10.3y) are published precisely; the mid-life band labels are interpolations of the published curve.
3. **NVMe endurance rating is estimated** (500 TBW/TB): actual vendor ratings range ~220-750 TBW/TB. Enterprise drives can be 5-10× higher. Write amplification is assumed ≈1.
4. **Measured rates assume history predicts the future**: a workload change (e.g. new backup target) invalidates the projection until history catches up. Conservative mode's +25% margin partially hedges this.
5. **SATA SSD wear attributes vary by vendor**: DriveAge recognizes them by smartctl's attribute name (see [SATA SSD Wear Assessment](#sata-ssd-wear-assessment)); a drive reporting none falls back to its age band, labelled "(age)". Host-write unit conversions are vendor conventions and can be wrong for unusual firmware.
6. **SMART reliability varies** by vendor/firmware; ~half of failed drives in Google's study showed no SMART warnings first. **These estimates are probabilistic planning guidance, not guarantees. Keep backups.**

---

## Recommended Actions

1. **Read the label on red**: "High Risk (AFR ~4%+)" is an age band - a planning priority, not imminent death. "Failing (SMART)" is evidence from the drive itself and deserves immediate action.
2. **Watch pending sectors and growth**: any non-zero 197/198, or a reallocated/defect count that keeps rising, is the strongest "act now" signal.
3. **Have backups**: no prediction model substitutes for them.
4. **Use conservative mode for critical data**; aggressive mode to maximize service life on replaceable data.

---

## Version History

- **Unreleased (2026-10)**: Lifecycle (age/wear band) separated from SMART health; the replacement estimate no longer escalates the category (healthy HDDs turned red at ~7.5 years). HDD countdown made monotonic ("Due now" at the target). NVMe wear indicator ranked ahead of the capacity-based TBW guess (decimal TB); critical-warning bits decoded individually; 10-year display horizon for flash. SATA SSDs assessed by wear; SAS grown defects / uncorrected errors evaluated. Error-count trends (stable vs growing) and recent write rates from per-drive history. Temperatures use Unraid's own limits.
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
