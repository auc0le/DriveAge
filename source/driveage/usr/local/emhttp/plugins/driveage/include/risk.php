<?php
/**
 * DriveAge Plugin - Risk Category Policy
 *
 * Maps drive age (HDD) or wear metrics (NVMe) to the five risk categories.
 * AFR references are calibrated to Backblaze's 2025 bathtub-curve revisit
 * (~317k drives): AFR holds roughly flat (~1-1.5%) through most of drive
 * life, rises near end of life, and peaks at ~4.25% around 10 years 3 months.
 * https://www.backblaze.com/blog/are-hard-drives-getting-better-lets-revisit-the-bathtub-curve/
 */

/**
 * Get age category based on power-on hours and configured thresholds
 *
 * @param int|null $hours Power-on hours (null = no SMART data available)
 * @param array $config Plugin configuration
 * @return string Category key (minimal_risk .. high_risk, or 'unknown')
 */
function getAgeCategory($hours, $config) {
    // No SMART data (USB flash, drive never seen awake) - do not pretend it's new
    if ($hours === null) {
        return 'unknown';
    }

    if ($hours < intval($config['THRESHOLD_MINIMAL_RISK'])) {
        return 'minimal_risk';
    } elseif ($hours < intval($config['THRESHOLD_LOW_RISK'])) {
        return 'low_risk';
    } elseif ($hours < intval($config['THRESHOLD_MODERATE_RISK'])) {
        return 'moderate_risk';
    } elseif ($hours < intval($config['THRESHOLD_ELEVATED_RISK'])) {
        return 'elevated_risk';
    } else {
        return 'high_risk';
    }
}

/**
 * Determine NVMe risk category based on wear metrics
 *
 * Uses Percentage Used and Available Spare; the more severe of the two wins.
 *
 * @param array $driveInfo Drive information array with NVMe SMART attributes
 * @return string|null Risk category, or null if not applicable (not NVMe)
 */
function getNvmeRiskCategory($driveInfo) {
    if (($driveInfo['physical_type'] ?? '') !== 'nvme') {
        return null;
    }

    $percentageUsed = $driveInfo['nvme_percentage_used'] ?? null;
    $availableSpare = $driveInfo['nvme_available_spare'] ?? null;
    $spareThreshold = $driveInfo['nvme_available_spare_threshold'] ?? 10;
    $mediaErrors    = $driveInfo['nvme_media_errors'] ?? 0;
    $criticalWarning = $driveInfo['nvme_critical_warning'] ?? 0;

    // CRITICAL: media errors, critical warning flag, or SMART FAILED
    if ($mediaErrors > 0 || $criticalWarning > 0) {
        return 'high_risk';
    }
    if (strtoupper($driveInfo['smart_status'] ?? '') === 'FAILED') {
        return 'high_risk';
    }

    // CRITICAL: available spare below vendor threshold
    if ($availableSpare !== null && $availableSpare < $spareThreshold) {
        return 'high_risk';
    }

    // ELEVATED: spare close to threshold (within 10 pts) OR endurance exceeded
    if (($availableSpare !== null && $availableSpare < ($spareThreshold + 10)) ||
        ($percentageUsed !== null && $percentageUsed > 100)) {
        return 'elevated_risk';
    }

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

    // Return the more severe of the two
    $riskLevels = [
        'minimal_risk'  => 0,
        'low_risk'      => 1,
        'moderate_risk' => 2,
        'elevated_risk' => 3,
        'high_risk'     => 4
    ];

    $maxRisk = max($riskLevels[$riskByPercentage], $riskLevels[$riskBySpare]);

    return array_search($maxRisk, $riskLevels);
}

/**
 * Get CSS class for age category
 */
function getAgeColorClass($category) {
    return 'age-' . $category;
}

/**
 * Get human-readable label for age category
 *
 * @param string $category The category key
 * @param array|null $config Optional configuration array with custom labels
 * @return string The label for the category
 */
function getAgeLabel($category, $config = null) {
    if ($category === 'unknown') {
        return 'N/A (no SMART data)';
    }

    if ($config !== null) {
        $labelKey = 'LABEL_' . strtoupper($category);
        if (isset($config[$labelKey])) {
            return $config[$labelKey];
        }
    }

    // Fallback labels (Backblaze 2025 calibration)
    $labels = [
        'minimal_risk'  => 'Minimal Risk (AFR ~1%)',
        'low_risk'      => 'Low Risk (AFR ~1.5%)',
        'moderate_risk' => 'Moderate Risk (AFR ~2%)',
        'elevated_risk' => 'Elevated Risk (AFR ~2.5-4%)',
        'high_risk'     => 'High Risk (AFR ~4%+)'
    ];

    return $labels[$category] ?? 'Unknown';
}
