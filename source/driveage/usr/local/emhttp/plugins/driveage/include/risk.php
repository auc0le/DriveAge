<?php
/**
 * DriveAge Plugin - Risk Category Policy
 *
 * Two independent dimensions, combined for display:
 *  - Lifecycle (age_category): drive age (HDD) or wear (NVMe) mapped to the
 *    five risk categories. Fleet statistics - never escalated by SMART.
 *  - Health (health_status): this drive's own SMART evidence
 *    (ok / watch / failing / unknown).
 *  - Overall (risk_category): the worse of the two; drives row color, the
 *    distribution chart and sorting. Failing health forces high_risk.
 *
 * HDD AFR references are calibrated to Backblaze's 2025 bathtub-curve revisit
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
 * Determine NVMe lifecycle (wear) category
 *
 * Uses Percentage Used and Available Spare; the more severe of the two wins.
 * Error conditions (media errors, critical warning, SMART FAILED) are HEALTH
 * evidence and handled by getHealthStatus(), not here.
 *
 * @param array $driveInfo Drive information array with NVMe SMART attributes
 * @return string|null Risk category, or null if not applicable (not NVMe,
 *                     or no wear metrics at all - never pretend "Minimal")
 */
function getNvmeRiskCategory($driveInfo) {
    if (($driveInfo['physical_type'] ?? '') !== 'nvme') {
        return null;
    }
    if (($driveInfo['nvme_percentage_used'] ?? null) === null && ($driveInfo['nvme_available_spare'] ?? null) === null) {
        return null;
    }
    return getWearRiskCategory(
        $driveInfo['nvme_percentage_used'] ?? null,
        $driveInfo['nvme_available_spare'] ?? null,
        $driveInfo['nvme_available_spare_threshold'] ?? null
    );
}

/**
 * Flash (NVMe / SATA SSD) lifecycle category from wear metrics.
 *
 * @param int|null $percentageUsed Rated endurance used (%), if known
 * @param int|null $availableSpare Available spare (%), if known (NVMe)
 * @param int|null $spareThreshold Vendor spare threshold (%), default 10
 * @return string Risk category (the more severe of the two metrics)
 */
function getWearRiskCategory($percentageUsed, $availableSpare, $spareThreshold) {
    $spareThreshold = $spareThreshold ?? 10;

    // Spare exhausted below the vendor threshold: end of wear life
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
    return getRiskRank($riskByPercentage) >= getRiskRank($riskBySpare) ? $riskByPercentage : $riskBySpare;
}

/**
 * Severity rank of a risk category (unknown sorts below minimal).
 */
function getRiskRank($category) {
    $ranks = [
        'unknown'       => -1,
        'minimal_risk'  => 0,
        'low_risk'      => 1,
        'moderate_risk' => 2,
        'elevated_risk' => 3,
        'high_risk'     => 4
    ];
    return $ranks[$category] ?? -1;
}

/**
 * Health status from this drive's own SMART evidence.
 *
 * @param string $smartStatus Overall SMART status (PASSED/FAILED/UNKNOWN/N/A)
 * @param array $warnings Health warnings (each with 'level')
 * @param bool $hasSmartData True if any SMART data was read for the drive
 * @return string 'failing', 'watch', 'ok' or 'unknown'
 */
function getHealthStatus($smartStatus, $warnings, $hasSmartData) {
    if (strtoupper((string)$smartStatus) === 'FAILED') {
        return 'failing';
    }
    $levels = array_map(fn($w) => $w['level'] ?? '', $warnings);
    if (in_array('critical', $levels, true)) {
        return 'failing';
    }
    if (in_array('warning', $levels, true)) {
        return 'watch';
    }
    return $hasSmartData ? 'ok' : 'unknown';
}

/**
 * Human-readable health label.
 */
function getHealthLabel($healthStatus) {
    $labels = [
        'ok'      => 'Healthy',
        'watch'   => 'Warning',
        'failing' => 'Failing',
        'unknown' => 'N/A'
    ];
    return $labels[$healthStatus] ?? 'N/A';
}

/**
 * Overall risk: the worse of lifecycle and health.
 * Failing health forces high_risk; a warning raises the drive to at least
 * elevated_risk.
 *
 * @param string $lifecycle Lifecycle category (age/wear band)
 * @param string $healthStatus Health status from getHealthStatus()
 * @return string Risk category
 */
function getOverallRiskCategory($lifecycle, $healthStatus) {
    if ($healthStatus === 'failing') {
        return 'high_risk';
    }
    if ($healthStatus === 'watch' && getRiskRank($lifecycle) < getRiskRank('elevated_risk')) {
        return 'elevated_risk';
    }
    return $lifecycle;
}

/**
 * Label for the overall risk. When SMART evidence decided the category the
 * label says so - an age band's AFR figure would misdescribe it.
 *
 * @param string $overall Overall risk category
 * @param string $lifecycle Lifecycle category
 * @param string $lifecycleLabel Label of the lifecycle category
 * @param string $healthStatus Health status
 * @return string Label
 */
function getRiskLabel($overall, $lifecycle, $lifecycleLabel, $healthStatus) {
    if ($healthStatus === 'failing') {
        return 'Failing (SMART)';
    }
    if ($overall !== $lifecycle) {
        return 'Warning (SMART)';
    }
    return $lifecycleLabel;
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

/**
 * Label for a drive's lifecycle category.
 *
 * HDD bands carry Backblaze AFR figures; those describe spinning drives
 * only, so wear-rated flash drives get the category name plus their wear.
 *
 * @param string $category Lifecycle category
 * @param string $physicalType 'hdd', 'ssd', 'nvme' or 'usb'
 * @param int|null $percentageUsed Flash wear (Percentage Used), if known
 * @param array|null $config Configuration (custom labels)
 * @param bool $ageBased True when a flash drive's band came from its age
 *                       (SATA SSD without a wear attribute)
 * @return string Label
 */
function getLifecycleLabel($category, $physicalType, $percentageUsed, $config = null, $ageBased = false) {
    $label = getAgeLabel($category, $config);
    if ($category === 'unknown' || !in_array($physicalType, ['nvme', 'ssd'], true)) {
        return $label;
    }
    // "Minimal Risk (AFR ~1%)" -> "Minimal Risk"
    $name = trim(preg_replace('/\s*\([^)]*\)\s*$/', '', $label));
    if ($name === '') {
        $name = $label;
    }
    if ($ageBased) {
        return $name . ' (age)';
    }
    return $percentageUsed !== null ? sprintf('%s (wear %d%%)', $name, $percentageUsed) : $name . ' (wear)';
}
