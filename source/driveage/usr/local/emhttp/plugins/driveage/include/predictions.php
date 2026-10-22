<?php
/**
 * DriveAge Plugin - Predictive Replacement Estimates
 *
 * Calculates estimated time-to-replacement for drives:
 *  - HDD/USB: SMART health overrides + linear countdown to a target age
 *             derived from Backblaze's 2025 bathtub-curve revisit
 *             (AFR roughly flat through mid-life, peaking ~4.25% at ~10.3 yr)
 *  - NVMe:    the vendor wear indicator (Percentage Used) projected at the
 *             drive's own measured wear rate; a capacity-based TBW guess
 *             only when no wear indicator exists
 *
 * Every estimate carries 'method', 'confidence' and human-readable notes.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/smartparse.php'; // NVMe critical-warning bit constants

// Replacement target ages (years). Conservative replaces before the measured
// end-of-life AFR ramp; aggressive rides drives to the measured ~4.25% peak.
define('DRIVEAGE_TARGET_YEARS_CONSERVATIVE', 8.0);
define('DRIVEAGE_TARGET_YEARS_AGGRESSIVE', 10.0);

// Minimum drive history before trusting measured wear rates
define('DRIVEAGE_MIN_HISTORY_YEARS', 0.25); // ~3 months

// Safety margin applied to measured rates in conservative mode
define('DRIVEAGE_CONSERVATIVE_RATE_MARGIN', 1.25);

// Flash wear projections beyond this are displayed as "10+ years". This is a
// display horizon, not a lifespan: Backblaze's age curve is HDD data and
// says nothing about calendar limits for SSDs.
define('DRIVEAGE_FLASH_HORIZON_MONTHS', 120);

/**
 * Estimate a generic TBW rating from capacity.
 *
 * Only used when a drive exposes no wear indicator of its own (Percentage
 * Used). Vendors rate endurance per DECIMAL terabyte; consumer TLC drives
 * typically run 300-600 TBW/TB (QLC lower, premium TLC higher), so 500
 * TBW/TB is a middle-of-market guess.
 *
 * @param int $sizeBytes Drive size in bytes
 * @return int Estimated TBW rating
 */
function estimateNvmeTBW($sizeBytes) {
    if ($sizeBytes <= 0) {
        return 300; // Default minimum (typical for 256GB-512GB drives)
    }

    $sizeTB = $sizeBytes / 1e12;

    // Mid-market estimate: 500 TBW per TB, minimum 250 TBW for small drives
    return max(intval(round($sizeTB * 500)), 250);
}

/**
 * Build a flash-drive prediction result, applying the display horizon.
 * Beyond DRIVEAGE_FLASH_HORIZON_MONTHS the projection is shown as "10+ years":
 * a wear projection that far out says "not a concern", not a date.
 */
function driveage_flash_result($months, $confidence, $method, $notes, $extra = []) {
    $months = max(0, intval(round($months)));
    $capped = $months > DRIVEAGE_FLASH_HORIZON_MONTHS;
    if ($capped) {
        $months = DRIVEAGE_FLASH_HORIZON_MONTHS;
        $timeline = ['text' => '10+ years', 'class' => 'timeline-healthy'];
    } else {
        $timeline = getReplacementTimelineText($months);
    }
    return array_merge([
        'months_remaining' => $months,
        'confidence' => $confidence,
        'method' => $method,
        'notes' => $notes,
        'capped' => $capped,
        'timeline_text' => $timeline['text'],
        'timeline_class' => $timeline['class']
    ], $extra);
}

/**
 * Calculate estimated remaining life for an NVMe drive.
 *
 * @param array $driveInfo Complete drive information array (nvme_* fields)
 * @param string $predictionMode 'conservative' or 'aggressive'
 * @return array Prediction data
 */
function estimateNvmeRemainingLife($driveInfo, $predictionMode = 'conservative') {
    return estimateFlashRemainingLife([
        'percentage_used'   => $driveInfo['nvme_percentage_used'] ?? null,
        'available_spare'   => $driveInfo['nvme_available_spare'] ?? null,
        'spare_threshold'   => $driveInfo['nvme_available_spare_threshold'] ?? null,
        'tbw_written'       => $driveInfo['nvme_tbw_calculated'] ?? null,
        'media_errors'      => $driveInfo['nvme_media_errors'] ?? 0,
        'critical_warning'  => $driveInfo['nvme_critical_warning'] ?? 0,
        'size_bytes'        => $driveInfo['size_bytes'] ?? 0,
        'power_on_hours'    => $driveInfo['power_on_hours'] ?? null,
        'smart_status'      => $driveInfo['smart_status'] ?? 'UNKNOWN',
        'recent_write_rate' => $driveInfo['recent_write_rate_tb_yr'] ?? null
    ], $predictionMode);
}

/**
 * Calculate estimated remaining life for a flash (NVMe or SATA SSD) drive.
 *
 * Method order (first applicable wins):
 *  0. Critical conditions (SMART FAILED, media errors, replace-class
 *     critical-warning bits, spare below threshold)
 *  1. Vendor wear indicator (Percentage Used): the drive's own estimate of
 *     rated endurance consumed - it already knows its real rating and write
 *     amplification, so it outranks any capacity-based guess
 *  2. TBW against an ESTIMATED rating - only when no wear indicator exists
 *  3. Available Spare depletion
 *
 * @param array $w Normalized wear data: percentage_used, available_spare,
 *                 spare_threshold, tbw_written, media_errors, critical_warning,
 *                 size_bytes, power_on_hours, smart_status, recent_write_rate
 * @param string $predictionMode 'conservative' or 'aggressive'
 * @return array Prediction data
 */
function estimateFlashRemainingLife($w, $predictionMode = 'conservative') {
    $percentageUsed  = $w['percentage_used'] ?? null;
    $availableSpare  = $w['available_spare'] ?? null;
    $spareThreshold  = $w['spare_threshold'] ?? 10;
    $tbwWritten      = $w['tbw_written'] ?? null;
    $mediaErrors     = $w['media_errors'] ?? 0;
    $criticalWarning = intval($w['critical_warning'] ?? 0);
    $sizeBytes       = $w['size_bytes'] ?? 0;
    $powerOnHours    = $w['power_on_hours'] ?? null;
    $smartStatus     = strtoupper($w['smart_status'] ?? 'UNKNOWN');
    $recentRate      = $w['recent_write_rate'] ?? null; // TB/yr from drivecache history

    $margin = ($predictionMode === 'conservative') ? DRIVEAGE_CONSERVATIVE_RATE_MARGIN : 1.0;
    $marginNote = ($margin > 1.0) ? sprintf(' Conservative +%d%% margin applied.', round(($margin - 1) * 100)) : '';

    // Drive history in years (for measured wear rates)
    $ageYears = ($powerOnHours !== null && $powerOnHours > 0) ? $powerOnHours / 8760 : null;
    $hasHistory = ($ageYears !== null && $ageYears >= DRIVEAGE_MIN_HISTORY_YEARS);

    // CRITICAL: drive reports its own SMART health as FAILED
    if ($smartStatus === 'FAILED') {
        return [
            'months_remaining' => 0,
            'confidence' => 'high',
            'method' => 'smart_failed',
            'notes' => 'Drive reports SMART overall-health FAILED. Replace immediately.',
            'timeline_text' => 'Replace Now',
            'timeline_class' => 'timeline-critical'
        ];
    }

    // CRITICAL: media errors, or a critical-warning bit that means the drive
    // is degraded (reliability, read-only, backup failed, PMR). The
    // temperature bit is a cooling problem, not a replacement signal.
    if ($mediaErrors > 0 || ($criticalWarning & DRIVEAGE_NVME_CW_REPLACE_MASK)) {
        return [
            'months_remaining' => 0,
            'confidence' => 'high',
            'method' => 'critical_error',
            'notes' => 'Critical errors detected. Replace immediately.',
            'timeline_text' => 'Replace Now',
            'timeline_class' => 'timeline-critical'
        ];
    }

    // CRITICAL: available spare below vendor threshold
    if (($availableSpare !== null && $availableSpare < $spareThreshold) || ($criticalWarning & DRIVEAGE_NVME_CW_SPARE)) {
        return [
            'months_remaining' => 1,
            'confidence' => 'high',
            'method' => 'spare_below_threshold',
            'notes' => 'Available spare below threshold. Replace within 1 month.',
            'timeline_text' => 'Replace Now',
            'timeline_class' => 'timeline-critical'
        ];
    }

    // Method 1: vendor wear indicator
    if ($percentageUsed !== null && $percentageUsed >= 100) {
        return [
            'months_remaining' => 0,
            'confidence' => 'medium',
            'method' => 'endurance_exceeded',
            'notes' => sprintf('Vendor wear indicator at %d%%: rated endurance consumed. Many drives run well past 100%%; plan replacement and watch spare and media errors.', $percentageUsed),
            'percentage_used' => $percentageUsed,
            'timeline_text' => 'Due now',
            'timeline_class' => 'timeline-warning'
        ];
    }

    if ($percentageUsed !== null && $percentageUsed >= 1) {
        if ($hasHistory) {
            $measured = $percentageUsed / $ageYears;
            $wearPerYear = max($measured * $margin, 0.01);
            $method = 'percentage_used_measured';
            // The indicator moves in whole percent: below 5% it is coarse
            $confidence = ($percentageUsed >= 5) ? 'medium' : 'low';
            $notes = sprintf('Vendor wear indicator %d%% after %.1f power-on years (%.1f%%/yr).', $percentageUsed, $ageYears, $measured)
                   . $marginNote
                   . (($percentageUsed < 5) ? ' Low resolution: the indicator moves in whole percent.' : '');

            // A recent write rate from drivecache history reflects workload
            // changes the lifetime average hides: project the remaining wear
            // at that rate, using this drive's own wear-per-TB calibration.
            if ($recentRate !== null && $recentRate > 0 && $tbwWritten !== null && $tbwWritten > 0) {
                $lifetimeRate = $tbwWritten / $ageYears;
                $rate = ($predictionMode === 'conservative') ? max($recentRate, $lifetimeRate) : $recentRate;
                $wearPerYear = max(($percentageUsed / $tbwWritten) * $rate * $margin, 0.01);
                $notes .= sprintf(' Projected at %.1f TB/yr (recent writes; lifetime average %.1f TB/yr).', $rate, $lifetimeRate);
            }

            $months = ((100 - $percentageUsed) / $wearPerYear) * 12;
        } else {
            // Short history: assume a 5-year rated life (20% wear per year)
            $wearMultiplier = ($predictionMode === 'conservative') ? 1.5 : 1.0;
            $months = ((100 - $percentageUsed) / 20) * 12 / $wearMultiplier;
            $method = 'percentage_used_linear';
            $confidence = 'low';
            $notes = 'Assumed 5-year wear life (drive history too short to measure). Actual lifespan may vary.';
        }

        return driveage_flash_result($months, $confidence, $method, $notes, ['percentage_used' => $percentageUsed]);
    }

    // Wear indicator still reads 0%: under 1% used in the drive's whole
    // history, so at most ~1%/yr - a lower bound, far beyond the horizon
    if ($percentageUsed === 0 && $hasHistory) {
        $wearPerYear = (1 / $ageYears) * $margin;
        return driveage_flash_result((99 / $wearPerYear) * 12, 'low', 'wear_below_resolution',
            sprintf('Vendor wear indicator still 0%% after %.1f power-on years (under 1%% used); estimate is a lower bound.', $ageYears),
            ['percentage_used' => 0]);
    }

    // Method 2: TBW against an estimated rating (no wear indicator)
    if ($tbwWritten !== null && $tbwWritten > 0 && $sizeBytes > 0) {
        $estimatedMaxTBW = estimateNvmeTBW($sizeBytes);
        $remainingTBW = $estimatedMaxTBW - $tbwWritten;

        if ($remainingTBW > 0) {
            if ($hasHistory) {
                // Measured: TB written per power-on year, with safety margin
                $measuredRate = $recentRate ?? ($tbwWritten / $ageYears);
                $writeRate = max($measuredRate * $margin, 0.01);
                $method = 'tbw_measured_rate';
                $rateNote = sprintf('measured %.1f TB/yr from drive history', $measuredRate)
                          . (($margin > 1.0) ? sprintf(' (+%d%% safety margin)', round(($margin - 1) * 100)) : '');
            } else {
                // Fallback: assumed home-NAS write rate
                $writeRate = ($predictionMode === 'conservative') ? 30 : 15;
                $method = 'tbw_assumed_rate';
                $rateNote = sprintf('assumed %d TB/yr (drive history too short to measure)', $writeRate);
            }

            // Low confidence either way: the endurance rating itself is a guess
            return driveage_flash_result(($remainingTBW / $writeRate) * 12, 'low', $method,
                "No wear indicator reported. Based on an ESTIMATED {$estimatedMaxTBW} TBW rating; {$rateNote}.", [
                'estimated_tbw' => $estimatedMaxTBW,
                'written_tbw' => $tbwWritten,
                'remaining_tbw' => round($remainingTBW, 1),
                'write_rate_tb_yr' => round($writeRate, 1)
            ]);
        }
    }

    // Method 3: Available Spare depletion
    if ($availableSpare !== null && $availableSpare < 90) {
        $spareConsumed = 100 - $availableSpare;
        $spareRemaining = $availableSpare - $spareThreshold;

        if ($spareConsumed > 0 && $spareRemaining > 0) {
            if ($hasHistory) {
                // Measured: spare consumed per power-on year, with safety margin
                $depletionPerYear = max(($spareConsumed / $ageYears) * $margin, 0.01);
                $months = ($spareRemaining / $depletionPerYear) * 12;
                $notes = sprintf('Measured spare depletion: %.1f%%/yr from drive history.', $spareConsumed / $ageYears);
            } else {
                // Fallback: assume current consumption took 2 years
                $wearMultiplier = ($predictionMode === 'conservative') ? 1.5 : 1.0;
                $months = ($spareRemaining / $spareConsumed) * 24 / $wearMultiplier;
                $notes = 'Assumed depletion rate (drive history too short to measure).';
            }

            return driveage_flash_result($months, 'low', 'spare_depletion',
                $notes . ' Spare depletion is often non-linear.', ['available_spare' => $availableSpare]);
        }
    }

    // Fallback: no reliable estimate
    return [
        'months_remaining' => null,
        'confidence' => 'none',
        'method' => 'insufficient_data',
        'notes' => 'Insufficient data for lifespan estimate.',
        'timeline_text' => 'Unknown',
        'timeline_class' => 'timeline-unknown'
    ];
}

/**
 * Calculate estimated remaining life for HDD (and USB) drives.
 *
 * SMART health overrides first, then a linear countdown to the target
 * replacement age (conservative: 8 years, aggressive: 10 years), derived
 * from Backblaze's 2025 finding that fleet AFR stays roughly flat through
 * mid-life and peaks (~4.25%) around 10.3 years.
 *
 * @param array $driveInfo Complete drive information array
 * @param string $predictionMode 'conservative' or 'aggressive'
 * @return array Prediction data
 */
function estimateHddRemainingLife($driveInfo, $predictionMode = 'conservative') {
    $powerOnHours = $driveInfo['power_on_hours'] ?? null;
    $healthWarnings = $driveInfo['health_warnings'] ?? [];
    $smartStatus = strtoupper($driveInfo['smart_status'] ?? 'UNKNOWN');

    // CRITICAL: drive reports its own SMART health as FAILED
    // (checked before the no-data bail so a FAILED drive always says so)
    if ($smartStatus === 'FAILED') {
        return [
            'months_remaining' => 0,
            'confidence' => 'high',
            'method' => 'smart_failed',
            'reason' => 'Drive reports SMART overall-health FAILED',
            'action' => 'Replace immediately',
            'timeline_text' => 'Replace Now',
            'timeline_class' => 'timeline-critical'
        ];
    }

    // CRITICAL: any critical SMART attribute warnings
    // (checked before the no-data bail - a drive with pending sectors is
    // "Replace Now" even if its power-on hours could not be parsed)
    foreach ($healthWarnings as $warning) {
        if (($warning['level'] ?? '') === 'critical') {
            return [
                'months_remaining' => 0,
                'confidence' => 'high',
                'method' => 'critical_smart',
                'reason' => 'Critical SMART warnings detected',
                'action' => 'Replace immediately',
                'timeline_text' => 'Replace Now',
                'timeline_class' => 'timeline-critical'
            ];
        }
    }

    // Cannot estimate without power-on hours
    if ($powerOnHours === null || $powerOnHours === 0) {
        return [
            'months_remaining' => null,
            'confidence' => 'none',
            'method' => 'no_data',
            'reason' => 'No SMART data available',
            'action' => 'Cannot estimate without power-on hours',
            'timeline_text' => 'Unknown',
            'timeline_class' => 'timeline-unknown'
        ];
    }

    $ageYears = $powerOnHours / 8760;

    if (!empty($healthWarnings)) {
        // Non-critical warnings: conservative = 3 months, aggressive = 6 months
        $months = ($predictionMode === 'conservative') ? 3 : 6;
        $timeline = getReplacementTimelineText($months);

        return [
            'months_remaining' => $months,
            'confidence' => 'medium',
            'method' => 'smart_warnings',
            'reason' => 'SMART warnings detected',
            'action' => 'Plan replacement soon',
            'timeline_text' => $timeline['text'],
            'timeline_class' => $timeline['class']
        ];
    }

    // Linear countdown to target replacement age (Backblaze 2025 calibration)
    // Conservative: 8 years (replace before the end-of-life AFR ramp)
    // Aggressive: 10 years (ride drives to the measured ~4.25% AFR peak)
    $targetAge = ($predictionMode === 'conservative')
        ? DRIVEAGE_TARGET_YEARS_CONSERVATIVE
        : DRIVEAGE_TARGET_YEARS_AGGRESSIVE;

    $remainingYears = $targetAge - $ageYears;
    $monthsRemaining = max(0, intval(round($remainingYears * 12)));

    if ($monthsRemaining > 60) {
        $confidence = 'medium';
        $reason = sprintf('Drive is %.1f years old, well within normal lifespan', $ageYears);
        $action = 'No action needed';
    } elseif ($monthsRemaining > 24) {
        $confidence = 'medium';
        $reason = sprintf('Drive is %.1f years old, well within normal lifespan', $ageYears);
        $action = 'Monitor regularly';
    } elseif ($monthsRemaining > 12) {
        $confidence = 'medium';
        $reason = sprintf('Drive is %.1f years old, approaching target replacement age', $ageYears);
        $action = 'Plan replacement within 1-2 years';
    } elseif ($monthsRemaining > 0) {
        $confidence = 'low';
        $reason = sprintf('Drive is %.1f years old, near recommended replacement age', $ageYears);
        $action = 'Plan replacement within the year';
    } else {
        // At or past the target age: due now. (An earlier 6-month grace
        // period here made the countdown jump from 1 month back up to 6.)
        $confidence = 'low';
        $reason = sprintf('Drive is %.1f years old, at or past the %d-year target age', $ageYears, intval($targetAge));
        $action = 'Plan replacement now';
    }

    // Reaching the target age is a planning milestone, not a failure signal:
    // "Due now" in warning style; "Replace Now" stays reserved for SMART evidence
    $timeline = ($monthsRemaining === 0)
        ? ['text' => 'Due now', 'class' => 'timeline-warning']
        : getReplacementTimelineText($monthsRemaining);

    return [
        'months_remaining' => $monthsRemaining,
        'confidence' => $confidence,
        'method' => 'age_linear',
        'reason' => $reason,
        'action' => $action,
        'timeline_text' => $timeline['text'],
        'timeline_class' => $timeline['class']
    ];
}

/**
 * Get user-friendly replacement timeline text and CSS class
 *
 * @param int|null $monthsRemaining Months until recommended replacement
 * @return array ['text' => string, 'class' => string]
 */
function getReplacementTimelineText($monthsRemaining) {
    if ($monthsRemaining === null) {
        return [
            'text' => 'Unknown',
            'class' => 'timeline-unknown'
        ];
    }

    if ($monthsRemaining <= 0) {
        return [
            'text' => 'Replace Now',
            'class' => 'timeline-critical'
        ];
    } elseif ($monthsRemaining <= 6) {
        return [
            'text' => $monthsRemaining . ' month' . ($monthsRemaining > 1 ? 's' : ''),
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
            'text' => round($monthsRemaining / 12, 1) . ' years',
            'class' => 'timeline-healthy'
        ];
    }
}

/**
 * Get predictive replacement estimate for a drive.
 *
 * Main entry point that routes to NVMe or HDD estimation.
 *
 * @param array $driveInfo Complete drive information array
 * @param array $config Plugin configuration
 * @return array Prediction data with timeline, confidence, method, notes
 */
function getPredictiveReplacement($driveInfo, $config) {
    $predictionMode = $config['PREDICTION_MODE'] ?? 'conservative';
    $physicalType = $driveInfo['physical_type'] ?? 'hdd';

    if ($physicalType === 'nvme') {
        return estimateNvmeRemainingLife($driveInfo, $predictionMode);
    } else {
        // HDD and USB both use age-based estimates
        return estimateHddRemainingLife($driveInfo, $predictionMode);
    }
}
