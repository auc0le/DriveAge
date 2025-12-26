# DriveAge Methodology & Research Documentation

This document provides detailed technical information about the risk assessment and predictive replacement methodologies used in the DriveAge plugin.

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
9. [Confidence Levels](#confidence-levels)
10. [Limitations & Assumptions](#limitations--assumptions)

---

## Overview

DriveAge implements a **dual risk model** that uses different assessment strategies for different drive types:

- **HDDs (Hard Disk Drives)**: Age-based risk using Annual Failure Rate (AFR) curves combined with critical SMART attribute monitoring
- **NVMe SSDs**: Wear-based risk using Percentage Used and Available Spare metrics

Both models include **predictive replacement estimates** that forecast the recommended time until drive replacement based on current health status and historical data.

---

## Research Sources

### Annual Failure Rate (AFR) Data

**Primary Source: Backblaze Hard Drive Stats (2013-2024)**
- URL: https://www.backblaze.com/blog/backblaze-drive-stats/
- Data: 250,000+ drives in production environments
- Key Finding: AFR increases significantly after 4-5 years of operation
- Typical AFR progression:
  - Year 0-2: ~1.5% AFR (minimal risk)
  - Year 2-3: ~2.0% AFR (low risk)
  - Year 3-4: ~3.0% AFR (moderate risk)
  - Year 4-5: ~6.0% AFR (elevated risk)
  - Year 5-6: ~10.0% AFR (high risk)
  - Year 6+: >10% AFR, increasing (high risk)

**Secondary Source: Google Drive Reliability Study (2007)**
- URL: https://research.google/pubs/pub32774/
- Data: 100,000+ drives in Google data centers
- Key Finding: SMART attributes 5, 187, 188, 197, 198 are strong failure predictors

### NVMe Endurance & TBW Data

**Primary Source: Consumer SSD Endurance Research (2024)**
- Tom's Hardware SSD Forums & Reviews (2023-2024)
- AnandTech SSD Endurance Testing (2020-2024)
- Key Finding: Modern TLC consumer SSDs average **500 TBW per TB of capacity**

**TBW Estimates by Capacity** (Conservative estimates):
- 250 GB: ~250 TBW
- 500 GB: ~300 TBW
- 1 TB: ~500 TBW
- 2 TB: ~1,000 TBW
- 4 TB: ~2,000 TBW

**Write Rate Assumptions** (Home NAS workloads):
- Conservative estimate: **30 TB/year** (82 GB/day)
- Aggressive estimate: **15 TB/year** (41 GB/day)
- Based on typical Unraid usage patterns: media server, file storage, backups

### SMART Attribute Thresholds

**Source: smartmontools Documentation & Industry Standards**
- URL: https://www.smartmontools.org/
- SMART Attribute ID 5 (Reallocated Sectors): ANY reallocation = warning, >10 = critical
- SMART Attribute ID 197 (Current Pending Sectors): ANY pending = critical (immediate failure risk)
- SMART Attribute ID 198 (Uncorrectable Sectors): ANY uncorrectable = critical (data loss)
- SMART Attribute ID 187 (Reported Uncorrectable): Indicates read errors during normal operation
- SMART Attribute ID 188 (Command Timeout): Indicates communication failures with drive

---

## HDD Age-Based Risk Assessment

### Methodology

HDDs are assessed using **power-on hours** converted to age in years (1 year = 8,760 hours). Risk categories are assigned based on AFR curves from Backblaze data.

### Risk Categories

| Risk Category | Age Range | AFR Range | Color | Description |
|--------------|-----------|-----------|-------|-------------|
| **Minimal Risk** | < 2 years | < 1.5% | Green | Brand new drives, minimal failure risk |
| **Low Risk** | 2-3 years | 1.5-2% | Light Green | Normal operation, low failure risk |
| **Moderate Risk** | 3-4 years | 2-5% | Amber | Mid-life, moderate failure risk |
| **Elevated Risk** | 4-5 years | 5-10% | Orange | Aging drives, elevated failure risk |
| **High Risk** | 5-6 years | 10-15% | Red | Old drives, high failure risk |
| **High Risk** | 6+ years | > 15% | Dark Red | Elderly drives, very high failure risk |

### Pre-Failure Detection

HDDs with ANY of the following SMART attributes trigger immediate warnings:

- **Critical Warning** (Replace ASAP):
  - Current Pending Sectors > 0 (SMART 197)
  - Uncorrectable Sectors > 0 (SMART 198)
  - Reallocated Sectors > 10 (SMART 5)
  - Command Timeouts > 100 (SMART 188)

- **Caution Warning** (Monitor closely):
  - Reallocated Sectors 1-10 (SMART 5)
  - Reported Uncorrectable > 0 (SMART 187)
  - Command Timeouts 1-100 (SMART 188)

**Risk Override**: If an HDD has critical SMART warnings, it is automatically categorized as **High Risk** regardless of age.

---

## NVMe Wear-Based Risk Assessment

### Methodology

NVMe drives are assessed using two primary wear metrics:
1. **Percentage Used**: Vendor-specific wear indicator (0-255%, typically 0-100%)
2. **Available Spare**: Remaining spare blocks (100% = full spare, 0% = depleted)

Risk is determined by the **higher (more severe) risk** of the two metrics (conservative approach).

### Risk Categories

| Risk Category | Percentage Used | Available Spare | Description |
|--------------|-----------------|-----------------|-------------|
| **Minimal Risk** | < 50% | > 80% | Minimal wear, plenty of spare capacity |
| **Low Risk** | 50-79% | 60-80% | Low wear, good spare capacity |
| **Moderate Risk** | 80-99% | 40-60% | Moderate wear, spare capacity declining |
| **Elevated Risk** | 100-120% | 20-40% | High wear, spare capacity low |
| **High Risk** | > 120% OR Below Threshold | < 20% OR Below Threshold | Critical wear or spare depletion |

### Critical Conditions

NVMe drives are immediately categorized as **High Risk** if ANY of:
- Media Errors > 0 (indicates NAND chip failures)
- Critical Warning > 0 (vendor-specific critical status)
- Available Spare < Available Spare Threshold (typically 10%)

---

## HDD Predictive Replacement Estimation

### Estimation Algorithm

HDD lifespan estimates combine **age-based AFR curves** with **SMART health warnings**.

#### Method 1: Critical SMART Warnings
- **Trigger**: ANY critical SMART attributes detected
- **Estimate**: 0 months (Replace immediately)
- **Confidence**: High
- **Action**: Replace ASAP

#### Method 2: Non-Critical SMART Warnings
- **Trigger**: Caution-level SMART attributes detected
- **Conservative Estimate**: 3 months
- **Aggressive Estimate**: 6 months
- **Confidence**: Medium
- **Action**: Plan replacement soon

#### Method 3: Age-Based Estimation
Uses Backblaze AFR curve projections:

| Current Age | Conservative Estimate | Aggressive Estimate | Reasoning |
|-------------|----------------------|---------------------|-----------|
| < 2 years | 42 months (3.5 years) | 60 months (5 years) | Low AFR, long remaining life |
| 2-4 years | 25 months (2 years) | 36 months (3 years) | Moderate AFR, mid-life |
| 4-6 years | 13 months (1 year) | 18 months (1.5 years) | Elevated AFR, aging |
| > 6 years | 4 months | 6 months | High AFR, replace soon |

**Conservative Mode**: Reduces estimates by 30% (0.7x multiplier)
**Aggressive Mode**: Uses standard estimates (1.0x multiplier)

---

## NVMe Predictive Replacement Estimation

NVMe drives use **three estimation methods** with automatic fallback if data is unavailable.

### Method 1: TBW Calculation (Most Accurate)

**Requirements**: Data Units Written available, drive size known

**Algorithm**:
1. Calculate TBW written: `(Data Units Written × 512,000) / 1 TB`
2. Estimate maximum TBW: `Drive Size (TB) × 500 TBW/TB`
3. Calculate remaining TBW: `Max TBW - Written TBW`
4. Estimate years remaining: `Remaining TBW / Assumed Write Rate`

**Assumed Write Rates**:
- Conservative: 30 TB/year (faster wear assumption)
- Aggressive: 15 TB/year (slower wear assumption)

**Example** (1TB NVMe, 50 TBW written, Conservative mode):
- Max TBW: 500 TBW
- Remaining TBW: 450 TBW
- Years remaining: 450 / 30 = 15 years
- Months remaining: 180 months

**Confidence**: Medium (depends on write rate assumption accuracy)

### Method 2: Percentage Used Linear Estimation

**Requirements**: Percentage Used < 100%

**Algorithm**:
1. Calculate percent remaining: `100% - Percentage Used`
2. Assume 5-year expected life (1 year per 20% used)
3. Apply wear multiplier:
   - Conservative: 1.5x (faster wear going forward)
   - Aggressive: 1.0x (same wear rate)
4. Calculate months: `(Percent Remaining / 20) × 12 / Multiplier`

**Example** (60% used, Conservative mode):
- Percent remaining: 40%
- Base estimate: (40 / 20) × 12 = 24 months
- With 1.5x multiplier: 24 / 1.5 = 16 months

**Confidence**: Low (very rough linear approximation)

### Method 3: Available Spare Depletion

**Requirements**: Available Spare < 90% (some spare consumed)

**Algorithm**:
1. Calculate spare consumed: `100% - Available Spare`
2. Calculate spare remaining: `Available Spare - Spare Threshold`
3. Assume 2 years to consume current amount
4. Apply wear multiplier (Conservative: 1.5x, Aggressive: 1.0x)
5. Calculate months: `(Spare Remaining / Spare Consumed) × 24 / Multiplier`

**Example** (80% spare available, 10% threshold, Conservative mode):
- Spare consumed: 20%
- Spare remaining: 70%
- Base estimate: (70 / 20) × 24 = 84 months
- With 1.5x multiplier: 84 / 1.5 = 56 months

**Confidence**: Low (assumes steady depletion rate)

### Fallback: Insufficient Data
- **Estimate**: Unknown
- **Confidence**: None
- **Display**: "Insufficient data for lifespan estimate"

---

## Conservative vs Aggressive Modes

Users can select their preferred prediction mode in plugin settings.

### Conservative Mode (Default)
**Philosophy**: Assume faster wear and shorter lifespan to be safe

**Settings**:
- NVMe write rate: **30 TB/year** (higher workload assumption)
- NVMe wear multiplier: **1.5x** (assume accelerating wear)
- HDD time multiplier: **0.7x** (reduce time estimates by 30%)

**Use Case**: Production systems, mission-critical data, risk-averse users

### Aggressive Mode
**Philosophy**: Assume slower wear and longer lifespan based on typical usage

**Settings**:
- NVMe write rate: **15 TB/year** (lower workload assumption)
- NVMe wear multiplier: **1.0x** (assume steady wear)
- HDD time multiplier: **1.0x** (standard time estimates)

**Use Case**: Home labs, non-critical storage, budget-conscious users

---

## Critical SMART Attributes

### HDD SMART Attributes

| ID | Name | Meaning | Warning Threshold | Critical Threshold |
|----|------|---------|-------------------|-------------------|
| **5** | Reallocated Sector Count | Sectors remapped due to read errors | > 0 | > 10 |
| **197** | Current Pending Sectors | Sectors waiting to be reallocated | > 0 | > 0 |
| **198** | Uncorrectable Sector Count | Sectors that cannot be read/written | > 0 | > 0 |
| **187** | Reported Uncorrectable Errors | Read errors during normal operation | > 0 | > 10 |
| **188** | Command Timeout | Commands that timed out | > 0 | > 100 |

**Note**: Google's research found attributes 5, 187, 188, 197, 198 had the strongest correlation with imminent drive failure.

### NVMe SMART Attributes

| Attribute | Meaning | Warning Threshold | Critical Threshold |
|-----------|---------|-------------------|-------------------|
| **Percentage Used** | Vendor-specific wear indicator | > 80% | > 100% |
| **Available Spare** | Remaining spare NAND blocks | < 60% | < Spare Threshold (typically 10%) |
| **Available Spare Threshold** | Vendor-defined critical threshold | - | Spare < Threshold |
| **Media Errors** | NAND chip read/write errors | > 0 | > 0 |
| **Critical Warning** | Vendor critical status flags | > 0 | > 0 |
| **Data Units Written** | Total writes (512-byte units × 1000) | Used for TBW calculation | - |

---

## Confidence Levels

Prediction confidence indicates how reliable the estimate is based on available data and method used.

### High Confidence
- **Triggers**: Critical SMART warnings detected, media errors, spare below threshold
- **Meaning**: Strong evidence of imminent failure or critical condition
- **Action**: Replace immediately or within 1 month
- **Examples**:
  - HDD with pending sectors > 0
  - NVMe with media errors > 0
  - Available spare below threshold

### Medium Confidence
- **Triggers**: TBW calculation method, age-based estimates with SMART data
- **Meaning**: Reasonable estimate based on industry data and current metrics
- **Action**: Plan replacement within estimated timeframe
- **Examples**:
  - NVMe with TBW calculation available
  - HDD age-based AFR estimates
  - Non-critical SMART warnings

### Low Confidence
- **Triggers**: Linear wear estimates, spare depletion estimates
- **Meaning**: Rough approximation based on assumptions, actual lifespan may vary significantly
- **Action**: Monitor regularly, use estimate as general guidance
- **Examples**:
  - NVMe percentage used linear estimation
  - NVMe spare depletion estimation

### None (Unknown)
- **Triggers**: Insufficient data for estimation
- **Meaning**: Cannot make a reliable estimate with available data
- **Action**: Continue monitoring, collect more data over time
- **Examples**:
  - NVMe with no write data and 0% used
  - Drives with missing SMART attributes

---

## Limitations & Assumptions

### General Limitations

1. **Manufacturer Variability**: Different manufacturers and models have different reliability characteristics. This plugin uses industry averages.

2. **Workload Dependency**: Drive lifespan heavily depends on workload (writes per day, temperature, operating hours). Assumptions may not match all use cases.

3. **No Warranty Information**: Plugin cannot access manufacturer warranty periods or specifications.

4. **SMART Data Reliability**: Some drives report inaccurate or missing SMART data. Results depend on drive firmware quality.

5. **No Crystal Ball**: Drives can fail suddenly without warning. These estimates are **probabilistic guidance**, not guarantees.

### HDD Assumptions

- AFR curves based on Backblaze data (large-scale data center environment)
- Assumes 24/7 operation (Unraid typical usage)
- Temperature and environmental factors not accounted for in base estimates
- Brand/model differences not considered

### NVMe Assumptions

- TBW ratings based on **consumer TLC drives** (500 TBW/TB)
- Enterprise drives (MLC/SLC) may have 5-10x higher endurance
- Write amplification factor assumed ~1.0 (ideal scenario)
- Write rates (15-30 TB/year) based on typical home NAS usage
- Over-provisioning and TRIM effectiveness assumed optimal

### Prediction Assumptions

- **Conservative Mode**: Assumes higher workload and faster wear than typical
- **Aggressive Mode**: Assumes lower workload and steady wear rate
- Linear wear rate (reality: wear accelerates over time for some drives)
- No consideration of:
  - Power cycles (startup/shutdown stress)
  - Temperature extremes
  - Vibration (in multi-drive arrays)
  - Firmware bugs or defects

---

## Recommended Actions

### For Users

1. **Don't panic**: Red/high-risk drives don't always fail immediately. Use estimates as planning guidance.

2. **Monitor critical attributes**: Pay close attention to drives with SMART warnings, especially pending sectors.

3. **Have backups**: No prediction model is perfect. Always maintain backups of critical data.

4. **Plan proactively**: Use replacement estimates to budget and schedule drive replacements during maintenance windows.

5. **Use conservative mode for critical data**: If the array contains irreplaceable data, use conservative estimates for safer planning.

### For Developers

1. **Future Enhancement**: Add per-manufacturer TBW databases when/if publicly available
2. **Future Enhancement**: Track actual write rates over time for more accurate NVMe predictions
3. **Future Enhancement**: Integrate temperature history for HDD AFR adjustments
4. **Future Enhancement**: Add machine learning to refine predictions based on actual failures

---

## Version History

- **v1.0 (2025-12-26)**: Initial implementation of dual risk model and predictive replacement estimates

---

## References

1. Backblaze Blog - Hard Drive Stats (2013-2024): https://www.backblaze.com/blog/backblaze-drive-stats/
2. Google - Failure Trends in a Large Disk Drive Population (2007): https://research.google/pubs/pub32774/
3. smartmontools Documentation: https://www.smartmontools.org/
4. Tom's Hardware - SSD Endurance Testing: https://www.tomshardware.com/reviews/ssd-endurance
5. AnandTech - SSD Reliability and Endurance: https://www.anandtech.com/
6. JEDEC JESD218/JESD219 - SSD Endurance Standards
7. NVMe Specification 1.4 - SMART/Health Information

---

**Document Maintained By**: DriveAge Development Team
**Last Updated**: 2025-12-26
**Plugin Version**: 2025.12.26+
