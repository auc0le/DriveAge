<?php
/**
 * DriveAge Plugin - Configuration Handler
 *
 * Handles loading, saving, and validating plugin configuration
 */

// Configuration file path
define('DRIVEAGE_CONFIG_FILE', '/boot/config/plugins/driveage/driveage.cfg');
define('DRIVEAGE_CONFIG_DIR', '/boot/config/plugins/driveage');

/**
 * Load configuration from file
 * Returns associative array of configuration values
 */
function loadConfig() {
    $defaults = getDefaultConfig();

    // Clear PHP's stat cache to ensure we read the latest file
    // This prevents delays when config changes (e.g., color updates)
    clearstatcache();

    if (!file_exists(DRIVEAGE_CONFIG_FILE)) {
        return $defaults;
    }

    // Validate it's a regular file, not a symlink
    if (!is_file(DRIVEAGE_CONFIG_FILE) || is_link(DRIVEAGE_CONFIG_FILE)) {
        error_log('DriveAge: Config file is not a regular file');
        return $defaults;
    }

    // Check permissions - file should not be world-writable
    $perms = fileperms(DRIVEAGE_CONFIG_FILE);
    if ($perms & 0002) {
        error_log('DriveAge: Config file is world-writable');
        return $defaults;
    }

    $config = @parse_ini_file(DRIVEAGE_CONFIG_FILE);

    if ($config === false) {
        error_log('DriveAge: Failed to parse config file');
        return $defaults;
    }

    // Merge with defaults to ensure all keys exist
    return array_merge($defaults, $config);
}

/**
 * Get default configuration values
 */
function getDefaultConfig() {
    return [
        // Display Configuration
        'DEFAULT_SORT' => 'power_on_hours',
        'DEFAULT_SORT_DIR' => 'desc',

        // Refresh Configuration
        'AUTO_REFRESH' => 'false',
        'REFRESH_INTERVAL' => '300',

        // Age Thresholds (in hours) - Risk-Based Model
        'THRESHOLD_MINIMAL_RISK' => '26280',    // < 3 years (AFR <1%)
        'THRESHOLD_LOW_RISK' => '43800',        // 3-5 years (AFR 1-2%)
        'THRESHOLD_MODERATE_RISK' => '61320',   // 5-7 years (AFR 2-5%)
        'THRESHOLD_ELEVATED_RISK' => '87600',   // 7-10 years (AFR 5-10%)
        // High Risk: >= 10 years (AFR >10%)

        // Display Filters
        'SHOW_PARITY' => 'true',
        'SHOW_ARRAY' => 'true',
        'SHOW_CACHE' => 'true',
        'SHOW_POOL' => 'true',
        'SHOW_UNASSIGNED' => 'true',

        // Column Visibility
        'SHOW_TEMPERATURE' => 'true',
        'SHOW_SMART_STATUS' => 'true',
        'SHOW_SPIN_STATUS' => 'true',

        // JSON API Configuration
        'API_ENABLED' => 'false',
        'API_RATE_LIMIT' => '100',

        // Prediction Configuration
        'PREDICTION_MODE' => 'conservative',  // 'conservative' or 'aggressive'

        // Category Colors (hex format)
        'COLOR_MINIMAL_RISK' => '#4CAF50',
        'COLOR_LOW_RISK' => '#8BC34A',
        'COLOR_MODERATE_RISK' => '#FFC107',
        'COLOR_ELEVATED_RISK' => '#FF9800',
        'COLOR_HIGH_RISK' => '#F44336',

        // Category Text Colors (hex format)
        'TEXTCOLOR_MINIMAL_RISK' => '#000000',
        'TEXTCOLOR_LOW_RISK' => '#000000',
        'TEXTCOLOR_MODERATE_RISK' => '#000000',
        'TEXTCOLOR_ELEVATED_RISK' => '#000000',
        'TEXTCOLOR_HIGH_RISK' => '#FFFFFF',

        // Category Labels
        'LABEL_MINIMAL_RISK' => 'Minimal Risk (AFR <1%)',
        'LABEL_LOW_RISK' => 'Low Risk (AFR 1-2%)',
        'LABEL_MODERATE_RISK' => 'Moderate Risk (AFR 2-5%)',
        'LABEL_ELEVATED_RISK' => 'Elevated Risk (AFR 5-10%)',
        'LABEL_HIGH_RISK' => 'High Risk (AFR >10%)'
    ];
}

/**
 * Save configuration to file
 */
function saveConfig($config) {
    // Ensure directory exists
    if (!is_dir(DRIVEAGE_CONFIG_DIR)) {
        mkdir(DRIVEAGE_CONFIG_DIR, 0755, true);
    }

    // Validate configuration
    $config = validateConfig($config);

    // Build INI content
    $content = "# DriveAge Plugin Configuration\n";
    $content .= "# Generated: " . date('Y-m-d H:i:s') . "\n\n";

    $content .= "# Display Configuration\n";
    $content .= "DEFAULT_SORT=\"{$config['DEFAULT_SORT']}\"\n";
    $content .= "DEFAULT_SORT_DIR=\"{$config['DEFAULT_SORT_DIR']}\"\n\n";

    $content .= "# Refresh Configuration\n";
    $content .= "AUTO_REFRESH=\"{$config['AUTO_REFRESH']}\"\n";
    $content .= "REFRESH_INTERVAL=\"{$config['REFRESH_INTERVAL']}\"\n\n";

    $content .= "# Age Thresholds (in hours) - Risk-Based Model\n";
    $content .= "THRESHOLD_MINIMAL_RISK=\"{$config['THRESHOLD_MINIMAL_RISK']}\"\n";
    $content .= "THRESHOLD_LOW_RISK=\"{$config['THRESHOLD_LOW_RISK']}\"\n";
    $content .= "THRESHOLD_MODERATE_RISK=\"{$config['THRESHOLD_MODERATE_RISK']}\"\n";
    $content .= "THRESHOLD_ELEVATED_RISK=\"{$config['THRESHOLD_ELEVATED_RISK']}\"\n\n";

    $content .= "# Display Filters\n";
    $content .= "SHOW_PARITY=\"{$config['SHOW_PARITY']}\"\n";
    $content .= "SHOW_ARRAY=\"{$config['SHOW_ARRAY']}\"\n";
    $content .= "SHOW_CACHE=\"{$config['SHOW_CACHE']}\"\n";
    $content .= "SHOW_POOL=\"{$config['SHOW_POOL']}\"\n";
    $content .= "SHOW_UNASSIGNED=\"{$config['SHOW_UNASSIGNED']}\"\n\n";

    $content .= "# Column Visibility\n";
    $content .= "SHOW_TEMPERATURE=\"{$config['SHOW_TEMPERATURE']}\"\n";
    $content .= "SHOW_SMART_STATUS=\"{$config['SHOW_SMART_STATUS']}\"\n";
    $content .= "SHOW_SPIN_STATUS=\"{$config['SHOW_SPIN_STATUS']}\"\n\n";

    $content .= "# JSON API Configuration\n";
    $content .= "API_ENABLED=\"{$config['API_ENABLED']}\"\n";
    $content .= "API_RATE_LIMIT=\"{$config['API_RATE_LIMIT']}\"\n\n";

    $content .= "# Category Colors\n";
    $content .= "COLOR_MINIMAL_RISK=\"{$config['COLOR_MINIMAL_RISK']}\"\n";
    $content .= "COLOR_LOW_RISK=\"{$config['COLOR_LOW_RISK']}\"\n";
    $content .= "COLOR_MODERATE_RISK=\"{$config['COLOR_MODERATE_RISK']}\"\n";
    $content .= "COLOR_ELEVATED_RISK=\"{$config['COLOR_ELEVATED_RISK']}\"\n";
    $content .= "COLOR_HIGH_RISK=\"{$config['COLOR_HIGH_RISK']}\"\n\n";

    $content .= "# Category Text Colors\n";
    $content .= "TEXTCOLOR_MINIMAL_RISK=\"{$config['TEXTCOLOR_MINIMAL_RISK']}\"\n";
    $content .= "TEXTCOLOR_LOW_RISK=\"{$config['TEXTCOLOR_LOW_RISK']}\"\n";
    $content .= "TEXTCOLOR_MODERATE_RISK=\"{$config['TEXTCOLOR_MODERATE_RISK']}\"\n";
    $content .= "TEXTCOLOR_ELEVATED_RISK=\"{$config['TEXTCOLOR_ELEVATED_RISK']}\"\n";
    $content .= "TEXTCOLOR_HIGH_RISK=\"{$config['TEXTCOLOR_HIGH_RISK']}\"\n\n";

    $content .= "# Category Labels\n";
    $content .= "LABEL_MINIMAL_RISK=\"{$config['LABEL_MINIMAL_RISK']}\"\n";
    $content .= "LABEL_LOW_RISK=\"{$config['LABEL_LOW_RISK']}\"\n";
    $content .= "LABEL_MODERATE_RISK=\"{$config['LABEL_MODERATE_RISK']}\"\n";
    $content .= "LABEL_ELEVATED_RISK=\"{$config['LABEL_ELEVATED_RISK']}\"\n";
    $content .= "LABEL_HIGH_RISK=\"{$config['LABEL_HIGH_RISK']}\"\n";

    return file_put_contents(DRIVEAGE_CONFIG_FILE, $content) !== false;
}

/**
 * Validates hex color format (#RRGGBB)
 * @param string $color Color to validate
 * @return bool True if valid hex color
 */
function isValidHexColor($color) {
    return is_string($color) && preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1;
}

/**
 * Validates that all category colors are unique
 * @param array $colors Array of color values
 * @return bool True if all colors are unique
 */
function hasUniqueColors($colors) {
    $normalized = array_map('strtoupper', $colors);
    return count($normalized) === count(array_unique($normalized));
}

/**
 * Determines if white or black text should be used on a background color
 * Uses relative luminance formula for WCAG compliance
 * @param string $hexColor Background color in #RRGGBB format
 * @return string 'white' or 'black'
 */
function getContrastTextColor($hexColor) {
    // Remove # and convert to RGB
    $hex = ltrim($hexColor, '#');
    $r = hexdec(substr($hex, 0, 2)) / 255;
    $g = hexdec(substr($hex, 2, 2)) / 255;
    $b = hexdec(substr($hex, 4, 2)) / 255;

    // Calculate relative luminance
    $r = $r <= 0.03928 ? $r / 12.92 : pow(($r + 0.055) / 1.055, 2.4);
    $g = $g <= 0.03928 ? $g / 12.92 : pow(($g + 0.055) / 1.055, 2.4);
    $b = $b <= 0.03928 ? $b / 12.92 : pow(($b + 0.055) / 1.055, 2.4);

    $luminance = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;

    // WCAG recommends white text on dark backgrounds (luminance < 0.5)
    return $luminance < 0.5 ? 'white' : 'black';
}

/**
 * Validate and sanitize configuration values
 *
 * @param array $config Configuration to validate
 * @return array Validated configuration
 */
function validateConfig($config) {
    $defaults = getDefaultConfig();
    $validated = [];

    // Sorting - strict whitelist
    $validSortColumns = ['device_name', 'size_bytes', 'power_on_hours', 'temperature', 'smart_status'];
    $validated['DEFAULT_SORT'] = in_array($config['DEFAULT_SORT'] ?? '', $validSortColumns, true)
        ? $config['DEFAULT_SORT']
        : $defaults['DEFAULT_SORT'];

    $validSortDirs = ['asc', 'desc'];
    $validated['DEFAULT_SORT_DIR'] = in_array($config['DEFAULT_SORT_DIR'] ?? '', $validSortDirs, true)
        ? $config['DEFAULT_SORT_DIR']
        : $defaults['DEFAULT_SORT_DIR'];

    // Refresh settings
    $validated['AUTO_REFRESH'] = ($config['AUTO_REFRESH'] ?? 'false') === 'true' ? 'true' : 'false';
    $validated['REFRESH_INTERVAL'] = max(30, min(3600, intval($config['REFRESH_INTERVAL'] ?? 300)));

    // Age thresholds with reasonable limits (max 100 years)
    $maxThreshold = 876600; // 100 years in hours

    $thresholds = [
        'THRESHOLD_MINIMAL_RISK' => max(0, min($maxThreshold, intval($config['THRESHOLD_MINIMAL_RISK'] ?? $defaults['THRESHOLD_MINIMAL_RISK']))),
        'THRESHOLD_LOW_RISK' => max(0, min($maxThreshold, intval($config['THRESHOLD_LOW_RISK'] ?? $defaults['THRESHOLD_LOW_RISK']))),
        'THRESHOLD_MODERATE_RISK' => max(0, min($maxThreshold, intval($config['THRESHOLD_MODERATE_RISK'] ?? $defaults['THRESHOLD_MODERATE_RISK']))),
        'THRESHOLD_ELEVATED_RISK' => max(0, min($maxThreshold, intval($config['THRESHOLD_ELEVATED_RISK'] ?? $defaults['THRESHOLD_ELEVATED_RISK'])))
    ];

    // Validate ascending order
    $prev = 0;
    $validOrder = true;
    foreach ($thresholds as $key => $value) {
        if ($value <= $prev) {
            $validOrder = false;
            error_log('DriveAge: Invalid threshold order, resetting to defaults');
            break;
        }
        $prev = $value;
    }

    if (!$validOrder) {
        // Reset to defaults
        $defaults_thresh = getDefaultConfig();
        $thresholds = [
            'THRESHOLD_MINIMAL_RISK' => $defaults_thresh['THRESHOLD_MINIMAL_RISK'],
            'THRESHOLD_LOW_RISK' => $defaults_thresh['THRESHOLD_LOW_RISK'],
            'THRESHOLD_MODERATE_RISK' => $defaults_thresh['THRESHOLD_MODERATE_RISK'],
            'THRESHOLD_ELEVATED_RISK' => $defaults_thresh['THRESHOLD_ELEVATED_RISK']
        ];
    }

    $validated = array_merge($validated, $thresholds);

    // Boolean filters - strict validation
    $booleanFields = [
        'SHOW_PARITY', 'SHOW_ARRAY', 'SHOW_CACHE', 'SHOW_POOL', 'SHOW_UNASSIGNED',
        'SHOW_TEMPERATURE', 'SHOW_SMART_STATUS', 'SHOW_SPIN_STATUS'
    ];

    foreach ($booleanFields as $field) {
        $value = $config[$field] ?? 'true';
        $validated[$field] = ($value === 'true' || $value === true || $value === '1') ? 'true' : 'false';
    }

    // API settings
    $validated['API_ENABLED'] = ($config['API_ENABLED'] ?? 'false') === 'true' ? 'true' : 'false';
    $validated['API_RATE_LIMIT'] = max(10, min(1000, intval($config['API_RATE_LIMIT'] ?? 100)));

    // Prediction mode - strict whitelist
    $validPredictionModes = ['conservative', 'aggressive'];
    $validated['PREDICTION_MODE'] = in_array($config['PREDICTION_MODE'] ?? '', $validPredictionModes, true)
        ? $config['PREDICTION_MODE']
        : $defaults['PREDICTION_MODE'];

    // Category colors validation
    $colorFields = [
        'COLOR_MINIMAL_RISK',
        'COLOR_LOW_RISK',
        'COLOR_MODERATE_RISK',
        'COLOR_ELEVATED_RISK',
        'COLOR_HIGH_RISK'
    ];

    // Validate format and normalize
    foreach ($colorFields as $field) {
        $color = $config[$field] ?? $defaults[$field];
        $validated[$field] = isValidHexColor($color) ? strtoupper($color) : $defaults[$field];
    }

    // Validate uniqueness - if duplicates found, reset all to defaults
    $colorValues = array_map(fn($field) => $validated[$field], $colorFields);
    if (!hasUniqueColors($colorValues)) {
        foreach ($colorFields as $field) {
            $validated[$field] = $defaults[$field];
        }
        error_log('DriveAge: Duplicate colors detected, reset to defaults');
    }

    // Category text colors validation
    $textColorFields = [
        'TEXTCOLOR_MINIMAL_RISK',
        'TEXTCOLOR_LOW_RISK',
        'TEXTCOLOR_MODERATE_RISK',
        'TEXTCOLOR_ELEVATED_RISK',
        'TEXTCOLOR_HIGH_RISK'
    ];

    // Validate format and normalize (no uniqueness check for text colors)
    foreach ($textColorFields as $field) {
        $color = $config[$field] ?? $defaults[$field];
        $validated[$field] = isValidHexColor($color) ? strtoupper($color) : $defaults[$field];
    }

    // Category labels validation
    $labelFields = [
        'LABEL_MINIMAL_RISK',
        'LABEL_LOW_RISK',
        'LABEL_MODERATE_RISK',
        'LABEL_ELEVATED_RISK',
        'LABEL_HIGH_RISK'
    ];

    foreach ($labelFields as $field) {
        $label = $config[$field] ?? $defaults[$field];

        // Strip tags and trim whitespace
        $label = trim(strip_tags($label));

        // Validate: non-empty and reasonable length (1-50 characters)
        if (empty($label) || strlen($label) > 50) {
            $validated[$field] = $defaults[$field];
        } else {
            $validated[$field] = $label;
        }
    }

    return $validated;
}

/**
 * Get age category based on power-on hours and configured thresholds
 */
function getAgeCategory($hours, $config) {
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
 * Uses Percentage Used and Available Spare to assess failure risk
 * This is different from HDD age-based assessment
 *
 * @param array $driveInfo Drive information array with NVMe SMART attributes
 * @return string Risk category: minimal_risk, low_risk, moderate_risk, elevated_risk, high_risk
 */
function getNvmeRiskCategory($driveInfo) {
    // Only process if this is an NVMe drive
    if ($driveInfo['physical_type'] !== 'nvme') {
        // Return null to indicate this function doesn't apply
        return null;
    }

    $percentageUsed = $driveInfo['nvme_percentage_used'] ?? null;
    $availableSpare = $driveInfo['nvme_available_spare'] ?? null;
    $spareThreshold = $driveInfo['nvme_available_spare_threshold'] ?? 10;
    $mediaErrors = $driveInfo['nvme_media_errors'] ?? 0;
    $criticalWarning = $driveInfo['nvme_critical_warning'] ?? 0;

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
    $riskLevels = [
        'minimal_risk' => 0,
        'low_risk' => 1,
        'moderate_risk' => 2,
        'elevated_risk' => 3,
        'high_risk' => 4
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
 * @param string $category The age category (minimal_risk, low_risk, etc.)
 * @param array|null $config Optional configuration array with custom labels
 * @return string The label for the category
 */
function getAgeLabel($category, $config = null) {
    // If config provided, use configured labels
    if ($config !== null) {
        $labelKey = 'LABEL_' . strtoupper($category);
        if (isset($config[$labelKey])) {
            return $config[$labelKey];
        }
    }

    // Fallback to default labels
    $labels = [
        'minimal_risk' => 'Minimal Risk (AFR <1%)',
        'low_risk' => 'Low Risk (AFR 1-2%)',
        'moderate_risk' => 'Moderate Risk (AFR 2-5%)',
        'elevated_risk' => 'Elevated Risk (AFR 5-10%)',
        'high_risk' => 'High Risk (AFR >10%)'
    ];

    return $labels[$category] ?? 'Unknown';
}
