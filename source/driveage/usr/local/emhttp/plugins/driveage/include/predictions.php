<?php
/**
 * DriveAge Plugin - Predictive Replacement Estimates
 *
 * Calculates estimated time-to-replacement for drives:
 *  - HDD/USB: SMART health overrides + linear countdown to a target age
 *             derived from Backblaze's 2025 bathtub-curve revisit
 *             (AFR roughly flat through mid-life, peaking ~4.25% at ~10.3 yr)
 *  - NVMe:    measured wear rates from the drive's own history
 *             (TB written / power-on years, wear%-per-year), with
 *             assumed-rate fallbacks when history is too short
 *
 * Every estimate carries 'method', 'confidence' and human-readable notes.
 */

require_once __DIR__ . '/config.php';

// Replacement target ages (years). Conservative replaces before the measured
// end-of-life AFR ramp; aggressive rides drives to the measured ~4.25% peak.
define('DRIVEAGE_TARGET_YEARS_CONSERVATIVE', 8.0);
define('DRIVEAGE_TARGET_YEARS_AGGRESSIVE', 10.0);

// Minimum drive history before trusting measured wear rates
define('DRIVEAGE_MIN_HISTORY_YEARS', 0.25); // ~3 months

// Safety margin applied to measured rates in conservative mode
define('DRIVEAGE_CONSERVATIVE_RATE_MARGIN', 1.25);

/**
 * Get the target replacement age in months for a prediction mode.
 */
function driveage_target_months($predictionMode) {
    $years = ($predictionMode === 'conservative')
        ? DRIVEAGE_TARGET_YEARS_CONSERVATIVE
        : DRIVEAGE_TARGET_YEARS_AGGRESSIVE;
    return intval(round($years * 12));
}

/**
 * Estimate generic TBW rating based on drive capacity.
 *
 * Consumer TLC drives are typically rated 300-600 TBW per TB of capacity
 * (QLC budget drives lower, premium TLC higher); 500 TBW/TB is used as a
 * middle-of-market estimate since SMART does not expose the vendor rating.
 *
 * @param int $sizeBytes Drive size in bytes
 * @return int Estimated TBW rating
 */
function estimateNvmeTBW($sizeBytes) {
    if ($sizeBytes <= 0) {
        return 300; // Default minimum (typical for 256GB-512GB drives)
    }

    $sizeTB = $sizeBytes / (1024 * 1024 * 1024 * 1024);

    // Mid-market estimate: 500 TBW per TB
    $estimatedTBW = round($sizeTB * 500);

    // Minimum 250 TBW for small drives
    return max($estimatedTBW, 250);
}

/**
 * Calculate estimated remaining life for an NVMe drive.
 *
 * Method order (first applicable wins):
 *  0. Critical conditions (SMART FAILED, media errors, spare below threshold)
 *  1. TBW: measured write rate from drive history, assumed rate as fallback
 *  2. Percentage Used: measured wear slope, fixed-lifespan fallback
 *  3. Available Spare depletion: measured depletion rate, fallback assumption
 *
 * @param array $driveInfo Complete drive information array
 * @param string $predictionMode 'conservative' or 'aggressive'
 * @return array Prediction data
 */
function estimateNvmeRemainingLife($driveInfo, $predictionMode = 'conservative') {
    $percentageUsed  = $driveInfo['nvme_percentage_used'] ?? null;
    $availableSpare  = $driveInfo['nvme_available_spare'] ?? null;
    $spareThreshold  = $driveInfo['nvme_available_spare_threshold'] ?? 10;
    $tbwCalculated   = $driveInfo['nvme_tbw_calculated'] ?? null;
    $mediaErrors     = $driveInfo['nvme_media_errors'] ?? 0;
    $criticalWarning = $driveInfo['nvme_critical_warning'] ?? 0;
    $sizeBytes       = $driveInfo['size_bytes'] ?? 0;
    $powerOnHours    = $driveInfo['power_on_hours'] ?? null;
    $smartStatus     = strtoupper($driveInfo['smart_status'] ?? 'UNKNOWN');

    $maxMonths = driveage_target_months($predictionMode);
    $margin = ($predictionMode === 'conservative') ? DRIVEAGE_CONSERVATIVE_RATE_MARGIN : 1.0;

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

    // CRITICAL: media errors or critical warning flag
    if ($mediaErrors > 0 || $criticalWarning > 0) {
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
    if ($availableSpare !== null && $availableSpare < $spareThreshold) {
        return [
            'months_remaining' => 1,
            'confidence' => 'high',
            'method' => 'spare_below_threshold',
            'notes' => 'Available spare below threshold. Replace within 1 month.',
            'timeline_text' => 'Replace Now',
            'timeline_class' => 'timeline-critical'
        ];
    }

    // Method 1: TBW - prefer the drive's own measured write rate
    if ($tbwCalculated !== null && $tbwCalculated > 0 && $sizeBytes > 0) {
        $estimatedMaxTBW = estimateNvmeTBW($sizeBytes);
        $remainingTBW = $estimatedMaxTBW - $tbwCalculated;

        if ($remainingTBW > 0) {
            if ($hasHistory) {
                // Measured: TB written per power-on year, with safety margin
                $measuredRate = $tbwCalculated / $ageYears;
                $writeRate = max($measuredRate * $margin, 0.01);
                $method = 'tbw_measured_rate';
                $confidence = 'medium';
                $rateNote = sprintf('measured %.1f TB/yr from drive history', $measuredRate)
                          . (($margin > 1.0) ? sprintf(' (+%d%% safety margin)', round(($margin - 1) * 100)) : '');
            } else {
                // Fallback: assumed home-NAS write rate
                $writeRate = ($predictionMode === 'conservative') ? 30 : 15;
                $method = 'tbw_assumed_rate';
                $confidence = 'low';
                $rateNote = sprintf('assumed %d TB/yr (drive history too short to measure)', $writeRate);
            }

            $monthsRemaining = intval(min(round(($remainingTBW / $writeRate) * 12), $maxMonths));
            $timeline = getReplacementTimelineText($monthsRemaining);

            return [
                'months_remaining' => $monthsRemaining,
                'confidence' => $confidence,
                'method' => $method,
                'notes' => "Based on estimated {$estimatedMaxTBW} TBW rating; {$rateNote}. Capped at the " .
                           ($predictionMode === 'conservative' ? '8' : '10') . '-year target age.',
                'estimated_tbw' => $estimatedMaxTBW,
                'written_tbw' => $tbwCalculated,
                'remaining_tbw' => round($remainingTBW, 1),
                'write_rate_tb_yr' => round($writeRate, 1),
                'timeline_text' => $timeline['text'],
                'timeline_class' => $timeline['class']
            ];
        }
    }

    // Method 2: Percentage Used - prefer the drive's own measured wear slope
    if ($percentageUsed !== null && $percentageUsed > 0 && $percentageUsed < 100) {
        if ($hasHistory) {
            // Measured: wear percent per power-on year, with safety margin
            $wearPerYear = max(($percentageUsed / $ageYears) * $margin, 0.01);
            $monthsRemaining = round(((100 - $percentageUsed) / $wearPerYear) * 12);
            $method = 'percentage_used_measured';
            $confidence = 'medium';
            $notes = sprintf('Measured wear rate: %.1f%%/yr from drive history.', $percentageUsed / $ageYears)
                   . (($margin > 1.0) ? sprintf(' Conservative +%d%% margin applied.', round(($margin - 1) * 100)) : '');
        } else {
            // Fallback: assume a 5-year rated life (20% wear per year)
            $wearMultiplier = ($predictionMode === 'conservative') ? 1.5 : 1.0;
            $monthsRemaining = round(((100 - $percentageUsed) / 20) * 12 / $wearMultiplier);
            $method = 'percentage_used_linear';
            $confidence = 'low';
            $notes = 'Assumed 5-year wear life (drive history too short to measure). Actual lifespan may vary.';
        }

        $monthsRemaining = intval(min($monthsRemaining, $maxMonths));
        $timeline = getReplacementTimelineText($monthsRemaining);

        return [
            'months_remaining' => $monthsRemaining,
            'confidence' => $confidence,
            'method' => $method,
            'notes' => $notes,
            'percentage_used' => $percentageUsed,
            'timeline_text' => $timeline['text'],
            'timeline_class' => $timeline['class']
        ];
    }

    // Method 3: Available Spare depletion
    if ($availableSpare !== null && $availableSpare < 90) {
        $spareConsumed = 100 - $availableSpare;
        $spareRemaining = $availableSpare - $spareThreshold;

        if ($spareConsumed > 0 && $spareRemaining > 0) {
            if ($hasHistory) {
                // Measured: spare consumed per power-on year, with safety margin
                $depletionPerYear = max(($spareConsumed / $ageYears) * $margin, 0.01);
                $monthsRemaining = round(($spareRemaining / $depletionPerYear) * 12);
                $notes = sprintf('Measured spare depletion: %.1f%%/yr from drive history.', $spareConsumed / $ageYears);
            } else {
                // Fallback: assume current consumption took 2 years
                $wearMultiplier = ($predictionMode === 'conservative') ? 1.5 : 1.0;
                $monthsRemaining = round(($spareRemaining / $spareConsumed) * 24 / $wearMultiplier);
                $notes = 'Assumed depletion rate (drive history too short to measure).';
            }

            $monthsRemaining = intval(min($monthsRemaining, $maxMonths));
            $timeline = getReplacementTimelineText($monthsRemaining);

            return [
                'months_remaining' => $monthsRemaining,
                'confidence' => 'low',
                'method' => 'spare_depletion',
                'notes' => $notes . ' Spare depletion is often non-linear.',
                'available_spare' => $availableSpare,
                'timeline_text' => $timeline['text'],
                'timeline_class' => $timeline['class']
            ];
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
    $monthsRemaining = intval(round($remainingYears * 12));

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
        // Past target age - graceful degradation: 6 -> 0 months
        $monthsRemaining = intval(max(0, round(6 + ($remainingYears * 12))));
        $confidence = 'low';
        $reason = sprintf('Drive is %.1f years old, exceeds the %d-year target age', $ageYears, intval($targetAge));
        $action = 'Replace soon';
    }

    $timeline = getReplacementTimelineText($monthsRemaining);

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
