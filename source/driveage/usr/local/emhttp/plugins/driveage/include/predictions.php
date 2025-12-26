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
 * Research sources:
 * - Tom's Hardware SSD Forums (2024)
 * - Industry average for consumer TLC drives
 *
 * @param int $sizeBytes Drive size in bytes
 * @return int Estimated TBW rating
 */
function estimateNvmeTBW($sizeBytes) {
    if ($sizeBytes <= 0) {
        return 300; // Default minimum (typical for 256GB-512GB drives)
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
 * @param array $driveInfo Complete drive information array
 * @param string $predictionMode 'conservative' or 'aggressive'
 * @return array ['months_remaining' => int|null, 'confidence' => string, 'method' => string, 'notes' => string, ...]
 */
function estimateNvmeRemainingLife($driveInfo, $predictionMode = 'conservative') {
    $percentageUsed = $driveInfo['nvme_percentage_used'] ?? null;
    $availableSpare = $driveInfo['nvme_available_spare'] ?? null;
    $spareThreshold = $driveInfo['nvme_available_spare_threshold'] ?? 10;
    $tbwCalculated = $driveInfo['nvme_tbw_calculated'] ?? null;
    $mediaErrors = $driveInfo['nvme_media_errors'] ?? 0;
    $criticalWarning = $driveInfo['nvme_critical_warning'] ?? 0;
    $sizeBytes = $driveInfo['size_bytes'] ?? 0;

    // CRITICAL: Media errors or critical warning = immediate replacement
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

    // CRITICAL: Available spare below threshold
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

    // Method 1: TBW calculation (most accurate if we have write data)
    if ($tbwCalculated !== null && $tbwCalculated > 0 && $sizeBytes > 0) {
        $estimatedMaxTBW = estimateNvmeTBW($sizeBytes);
        $remainingTBW = $estimatedMaxTBW - $tbwCalculated;

        if ($remainingTBW > 0) {
            // Assume moderate write workload: 20 TB/year for home NAS
            // Conservative mode: assume 30 TB/year (faster wear)
            // Aggressive mode: assume 15 TB/year (slower wear)
            $assumedWriteRate = ($predictionMode === 'conservative') ? 30 : 15;

            $yearsRemaining = $remainingTBW / $assumedWriteRate;
            $monthsRemaining = round($yearsRemaining * 12);

            $timeline = getReplacementTimelineText($monthsRemaining);

            return [
                'months_remaining' => $monthsRemaining,
                'confidence' => 'medium',
                'method' => 'tbw_estimate',
                'notes' => "Based on estimated {$estimatedMaxTBW} TBW rating and {$assumedWriteRate} TB/year write rate.",
                'estimated_tbw' => $estimatedMaxTBW,
                'written_tbw' => $tbwCalculated,
                'remaining_tbw' => round($remainingTBW, 1),
                'assumed_write_rate' => $assumedWriteRate,
                'timeline_text' => $timeline['text'],
                'timeline_class' => $timeline['class']
            ];
        }
    }

    // Method 2: Percentage Used (if < 100%, we can estimate)
    if ($percentageUsed !== null && $percentageUsed > 0 && $percentageUsed < 100) {
        $percentRemaining = 100 - $percentageUsed;

        // Rough linear estimate (not always accurate, but better than nothing)
        // Assume drive has been running for X time to reach Y% used
        // Conservative: assume faster wear going forward (1.5x multiplier)
        // Aggressive: assume same wear rate (1x multiplier)
        $wearMultiplier = ($predictionMode === 'conservative') ? 1.5 : 1.0;

        // Very rough: assume 1 year per 20% used (5 year expected life)
        $monthsRemaining = round(($percentRemaining / 20) * 12 / $wearMultiplier);

        $timeline = getReplacementTimelineText($monthsRemaining);

        return [
            'months_remaining' => $monthsRemaining,
            'confidence' => 'low',
            'method' => 'percentage_used_linear',
            'notes' => 'Estimate based on current wear percentage. Actual lifespan may vary significantly.',
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
            // Rough estimate: how long until spare reaches threshold
            // Assume 2 years to consume what's been consumed so far
            // Conservative: assume 1.5x faster going forward
            // Aggressive: assume same rate
            $wearMultiplier = ($predictionMode === 'conservative') ? 1.5 : 1.0;

            $monthsRemaining = round(($spareRemaining / $spareConsumed) * 24 / $wearMultiplier);

            $timeline = getReplacementTimelineText($monthsRemaining);

            return [
                'months_remaining' => $monthsRemaining,
                'confidence' => 'low',
                'method' => 'spare_depletion',
                'notes' => 'Estimate based on spare capacity depletion rate.',
                'available_spare' => $availableSpare,
                'timeline_text' => $timeline['text'],
                'timeline_class' => $timeline['class']
            ];
        }
    }

    // Fallback: No reliable estimate
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
 * Calculate estimated remaining life for HDD based on age and AFR
 *
 * Uses Backblaze AFR curves: failure rate increases with age
 * Age-based estimates combined with SMART health warnings
 *
 * @param array $driveInfo Complete drive information array
 * @param string $predictionMode 'conservative' or 'aggressive'
 * @return array ['months_remaining' => int|null, 'confidence' => string, 'reason' => string, ...]
 */
function estimateHddRemainingLife($driveInfo, $predictionMode = 'conservative') {
    $powerOnHours = $driveInfo['power_on_hours'] ?? null;
    $healthWarnings = $driveInfo['health_warnings'] ?? [];
    $smartStatus = $driveInfo['smart_status'] ?? 'UNKNOWN';

    // No SMART data available - cannot make prediction
    // Check for: null power_on_hours, 0 hours, or UNKNOWN/N/A SMART status
    if ($powerOnHours === null || $powerOnHours === 0 ||
        in_array(strtoupper($smartStatus), ['UNKNOWN', 'N/A'], true)) {
        return [
            'months_remaining' => null,
            'confidence' => 'none',
            'reason' => 'No SMART data available',
            'action' => 'Cannot estimate without power-on hours',
            'timeline_text' => 'Unknown',
            'timeline_class' => 'timeline-unknown'
        ];
    }

    $ageYears = $powerOnHours / 8760;

    // CRITICAL: If drive has critical warnings, estimate very short remaining life
    if (!empty($healthWarnings)) {
        foreach ($healthWarnings as $warning) {
            if ($warning['level'] === 'critical') {
                return [
                    'months_remaining' => 0,
                    'confidence' => 'high',
                    'reason' => 'Critical SMART warnings detected',
                    'action' => 'Replace immediately',
                    'timeline_text' => 'Replace Now',
                    'timeline_class' => 'timeline-critical'
                ];
            }
        }

        // Non-critical warnings: conservative = 3 months, aggressive = 6 months
        $months = ($predictionMode === 'conservative') ? 3 : 6;
        $timeline = getReplacementTimelineText($months);

        return [
            'months_remaining' => $months,
            'confidence' => 'medium',
            'reason' => 'SMART warnings detected',
            'action' => 'Plan replacement soon',
            'timeline_text' => $timeline['text'],
            'timeline_class' => $timeline['class']
        ];
    }

    // Linear age-based estimates using Backblaze AFR curves
    // Conservative: Target replacement at 6 years (AFR ~10%, balance of safety vs longevity)
    // Aggressive: Target replacement at 8 years (higher AFR acceptable, maximize drive life)

    $targetAge = ($predictionMode === 'conservative') ? 6.0 : 8.0;

    // Calculate remaining years until target age
    $remainingYears = $targetAge - $ageYears;

    // Convert to months
    $monthsRemaining = round($remainingYears * 12);

    // Apply bounds and determine confidence
    if ($monthsRemaining > 60) {
        // Very new drive - cap at 5 years max estimate
        $monthsRemaining = 60;
        $confidence = 'medium';
        $reason = sprintf('Drive is relatively new (%.1f years old)', $ageYears);
        $action = 'No action needed';
    } elseif ($monthsRemaining > 24) {
        // Plenty of life remaining
        $confidence = 'medium';
        $reason = sprintf('Drive is %.1f years old, well within normal lifespan', $ageYears);
        $action = 'Monitor regularly';
    } elseif ($monthsRemaining > 12) {
        // Moderate time remaining
        $confidence = 'medium';
        $reason = sprintf('Drive is %.1f years old, approaching target replacement age', $ageYears);
        $action = 'Plan replacement within 1-2 years';
    } elseif ($monthsRemaining > 0) {
        // Less than 1 year remaining
        $confidence = 'low';
        $reason = sprintf('Drive is %.1f years old, near or past recommended replacement age', $ageYears);
        $action = 'Plan replacement within the year';
    } else {
        // Past target age - critical
        // Gracefully degrade: give 0-6 months based on how far past
        $monthsRemaining = max(0, round(6 + ($remainingYears * 12)));
        $confidence = 'low';
        $reason = sprintf('Drive is %.1f years old, exceeds recommended replacement age', $ageYears);
        $action = 'Replace soon';
    }

    $timeline = getReplacementTimelineText($monthsRemaining);

    return [
        'months_remaining' => $monthsRemaining,
        'confidence' => $confidence,
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
 * Get predictive replacement estimate for a drive
 *
 * Main entry point that routes to NVMe or HDD estimation
 *
 * @param array $driveInfo Complete drive information array
 * @param array $config Plugin configuration
 * @return array Prediction data with timeline, confidence, notes
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
