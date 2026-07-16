<?php
/**
 * DriveAge Plugin - Configuration Handler
 *
 * Loads and validates plugin configuration.
 *
 * NOTE: Settings are written by Unraid's own /update.php (the settings form
 * posts to it), so validation is enforced HERE, on load. Every value read
 * from disk passes through validateConfig() before use.
 */

// Configuration file path (guarded defines so tests can redirect them)
if (!defined('DRIVEAGE_CONFIG_FILE')) {
    define('DRIVEAGE_CONFIG_FILE', '/boot/config/plugins/driveage/driveage.cfg');
}
if (!defined('DRIVEAGE_CONFIG_DIR')) {
    define('DRIVEAGE_CONFIG_DIR', dirname(DRIVEAGE_CONFIG_FILE));
}

/**
 * Load configuration from file.
 * Returns a fully validated associative array of configuration values.
 */
function loadConfig() {
    $defaults = getDefaultConfig();

    // Clear PHP's stat cache to ensure we read the latest file
    clearstatcache();

    if (!file_exists(DRIVEAGE_CONFIG_FILE)) {
        return $defaults;
    }

    // Validate it's a regular file, not a symlink
    if (!is_file(DRIVEAGE_CONFIG_FILE) || is_link(DRIVEAGE_CONFIG_FILE)) {
        error_log('DriveAge: Config file is not a regular file');
        return $defaults;
    }

    $config = @parse_ini_file(DRIVEAGE_CONFIG_FILE);

    if ($config === false) {
        error_log('DriveAge: Failed to parse config file');
        return $defaults;
    }

    // Merge with defaults, then validate everything before use
    return validateConfig(array_merge($defaults, $config));
}

/**
 * Get default configuration values.
 *
 * Age thresholds and AFR labels are calibrated to Backblaze's 2025
 * bathtub-curve revisit: AFR ~1.3% in year one, roughly flat through
 * mid-life, rising toward a peak of ~4.25% at ~10.3 years.
 */
function getDefaultConfig() {
    return [
        // Display Configuration
        'DEFAULT_SORT' => 'replacement_months',
        'DEFAULT_SORT_DIR' => 'asc',

        // Refresh Configuration
        'AUTO_REFRESH' => 'false',
        'REFRESH_INTERVAL' => '300',

        // Age Thresholds (in hours) - Backblaze 2025 calibration
        'THRESHOLD_MINIMAL_RISK' => '35040',    // < 4 years
        'THRESHOLD_LOW_RISK' => '52560',        // 4-6 years
        'THRESHOLD_MODERATE_RISK' => '70080',   // 6-8 years
        'THRESHOLD_ELEVATED_RISK' => '87600',   // 8-10 years
        // High Risk: >= 10 years (measured AFR peak: ~4.25% at ~10.3 years)

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

        // Prediction Configuration
        'PREDICTION_MODE' => 'conservative',  // 'conservative' (8yr target) or 'aggressive' (10yr target)

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

        // Category Labels (Backblaze 2025 calibration)
        'LABEL_MINIMAL_RISK' => 'Minimal Risk (AFR ~1%)',
        'LABEL_LOW_RISK' => 'Low Risk (AFR ~1.5%)',
        'LABEL_MODERATE_RISK' => 'Moderate Risk (AFR ~2%)',
        'LABEL_ELEVATED_RISK' => 'Elevated Risk (AFR ~2.5-4%)',
        'LABEL_HIGH_RISK' => 'High Risk (AFR ~4%+)'
    ];
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
 * Validate and sanitize configuration values
 *
 * @param array $config Configuration to validate
 * @return array Validated configuration
 */
function validateConfig($config) {
    $defaults = getDefaultConfig();
    $validated = [];

    // Sorting - strict whitelist (matches sortable dashboard columns)
    $validSortColumns = [
        'device_name', 'identification', 'size_bytes', 'power_on_hours',
        'temperature', 'smart_status', 'replacement_months'
    ];
    $validated['DEFAULT_SORT'] = in_array($config['DEFAULT_SORT'] ?? '', $validSortColumns, true)
        ? $config['DEFAULT_SORT']
        : $defaults['DEFAULT_SORT'];

    $validSortDirs = ['asc', 'desc'];
    $validated['DEFAULT_SORT_DIR'] = in_array($config['DEFAULT_SORT_DIR'] ?? '', $validSortDirs, true)
        ? $config['DEFAULT_SORT_DIR']
        : $defaults['DEFAULT_SORT_DIR'];

    // Refresh settings
    $validated['AUTO_REFRESH'] = ($config['AUTO_REFRESH'] ?? 'false') === 'true' ? 'true' : 'false';
    $validated['REFRESH_INTERVAL'] = (string)max(30, min(3600, intval($config['REFRESH_INTERVAL'] ?? 300)));

    // Age thresholds with reasonable limits (max 100 years)
    $maxThreshold = 876600; // 100 years in hours

    $thresholds = [
        'THRESHOLD_MINIMAL_RISK' => max(0, min($maxThreshold, intval($config['THRESHOLD_MINIMAL_RISK'] ?? $defaults['THRESHOLD_MINIMAL_RISK']))),
        'THRESHOLD_LOW_RISK' => max(0, min($maxThreshold, intval($config['THRESHOLD_LOW_RISK'] ?? $defaults['THRESHOLD_LOW_RISK']))),
        'THRESHOLD_MODERATE_RISK' => max(0, min($maxThreshold, intval($config['THRESHOLD_MODERATE_RISK'] ?? $defaults['THRESHOLD_MODERATE_RISK']))),
        'THRESHOLD_ELEVATED_RISK' => max(0, min($maxThreshold, intval($config['THRESHOLD_ELEVATED_RISK'] ?? $defaults['THRESHOLD_ELEVATED_RISK'])))
    ];

    // Validate ascending order; reset all to defaults if violated
    $prev = 0;
    $validOrder = true;
    foreach ($thresholds as $value) {
        if ($value <= $prev) {
            $validOrder = false;
            error_log('DriveAge: Invalid threshold order, resetting to defaults');
            break;
        }
        $prev = $value;
    }

    if (!$validOrder) {
        $thresholds = [
            'THRESHOLD_MINIMAL_RISK' => intval($defaults['THRESHOLD_MINIMAL_RISK']),
            'THRESHOLD_LOW_RISK' => intval($defaults['THRESHOLD_LOW_RISK']),
            'THRESHOLD_MODERATE_RISK' => intval($defaults['THRESHOLD_MODERATE_RISK']),
            'THRESHOLD_ELEVATED_RISK' => intval($defaults['THRESHOLD_ELEVATED_RISK'])
        ];
    }

    $validated = array_merge($validated, array_map('strval', $thresholds));

    // Boolean filters - strict validation
    $booleanFields = [
        'SHOW_PARITY', 'SHOW_ARRAY', 'SHOW_CACHE', 'SHOW_POOL', 'SHOW_UNASSIGNED',
        'SHOW_TEMPERATURE', 'SHOW_SMART_STATUS', 'SHOW_SPIN_STATUS'
    ];

    foreach ($booleanFields as $field) {
        $value = $config[$field] ?? 'true';
        $validated[$field] = ($value === 'true' || $value === true || $value === '1') ? 'true' : 'false';
    }

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

    // Category text colors validation (no uniqueness requirement)
    $textColorFields = [
        'TEXTCOLOR_MINIMAL_RISK',
        'TEXTCOLOR_LOW_RISK',
        'TEXTCOLOR_MODERATE_RISK',
        'TEXTCOLOR_ELEVATED_RISK',
        'TEXTCOLOR_HIGH_RISK'
    ];

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
        $label = trim(strip_tags((string)$label));

        // Validate: non-empty and reasonable length (1-50 characters)
        if (empty($label) || strlen($label) > 50) {
            $validated[$field] = $defaults[$field];
        } else {
            $validated[$field] = $label;
        }
    }

    return $validated;
}
