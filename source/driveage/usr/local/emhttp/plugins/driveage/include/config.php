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

        // Age Thresholds (in hours)
        'THRESHOLD_BRAND_NEW' => '17520',   // < 2 years
        'THRESHOLD_NEWISH' => '26280',      // 2-3 years
        'THRESHOLD_NORMAL' => '35040',      // 3-4 years
        'THRESHOLD_AGED' => '43800',        // 4-5 years
        'THRESHOLD_OLD' => '52560',         // 5-6 years

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

        // Category Colors (hex format)
        'COLOR_BRAND_NEW' => '#006400',
        'COLOR_NEWISH' => '#008000',
        'COLOR_NORMAL' => '#90EE90',
        'COLOR_AGED' => '#FFD700',
        'COLOR_OLD' => '#8B0000',
        'COLOR_ELDERLY' => '#FF0000',

        // Category Text Colors (hex format)
        'TEXTCOLOR_BRAND_NEW' => '#FFFFFF',
        'TEXTCOLOR_NEWISH' => '#FFFFFF',
        'TEXTCOLOR_NORMAL' => '#000000',
        'TEXTCOLOR_AGED' => '#000000',
        'TEXTCOLOR_OLD' => '#FFFFFF',
        'TEXTCOLOR_ELDERLY' => '#FFFFFF',

        // Category Labels
        'LABEL_BRAND_NEW' => 'Brand New',
        'LABEL_NEWISH' => 'Newish',
        'LABEL_NORMAL' => 'Mature',
        'LABEL_AGED' => 'Aged',
        'LABEL_OLD' => 'Old',
        'LABEL_ELDERLY' => 'Elderly'
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

    $content .= "# Age Thresholds (in hours)\n";
    $content .= "THRESHOLD_BRAND_NEW=\"{$config['THRESHOLD_BRAND_NEW']}\"\n";
    $content .= "THRESHOLD_NEWISH=\"{$config['THRESHOLD_NEWISH']}\"\n";
    $content .= "THRESHOLD_NORMAL=\"{$config['THRESHOLD_NORMAL']}\"\n";
    $content .= "THRESHOLD_AGED=\"{$config['THRESHOLD_AGED']}\"\n";
    $content .= "THRESHOLD_OLD=\"{$config['THRESHOLD_OLD']}\"\n\n";

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
    $content .= "COLOR_BRAND_NEW=\"{$config['COLOR_BRAND_NEW']}\"\n";
    $content .= "COLOR_NEWISH=\"{$config['COLOR_NEWISH']}\"\n";
    $content .= "COLOR_NORMAL=\"{$config['COLOR_NORMAL']}\"\n";
    $content .= "COLOR_AGED=\"{$config['COLOR_AGED']}\"\n";
    $content .= "COLOR_OLD=\"{$config['COLOR_OLD']}\"\n";
    $content .= "COLOR_ELDERLY=\"{$config['COLOR_ELDERLY']}\"\n\n";

    $content .= "# Category Text Colors\n";
    $content .= "TEXTCOLOR_BRAND_NEW=\"{$config['TEXTCOLOR_BRAND_NEW']}\"\n";
    $content .= "TEXTCOLOR_NEWISH=\"{$config['TEXTCOLOR_NEWISH']}\"\n";
    $content .= "TEXTCOLOR_NORMAL=\"{$config['TEXTCOLOR_NORMAL']}\"\n";
    $content .= "TEXTCOLOR_AGED=\"{$config['TEXTCOLOR_AGED']}\"\n";
    $content .= "TEXTCOLOR_OLD=\"{$config['TEXTCOLOR_OLD']}\"\n";
    $content .= "TEXTCOLOR_ELDERLY=\"{$config['TEXTCOLOR_ELDERLY']}\"\n\n";

    $content .= "# Category Labels\n";
    $content .= "LABEL_BRAND_NEW=\"{$config['LABEL_BRAND_NEW']}\"\n";
    $content .= "LABEL_NEWISH=\"{$config['LABEL_NEWISH']}\"\n";
    $content .= "LABEL_NORMAL=\"{$config['LABEL_NORMAL']}\"\n";
    $content .= "LABEL_AGED=\"{$config['LABEL_AGED']}\"\n";
    $content .= "LABEL_OLD=\"{$config['LABEL_OLD']}\"\n";
    $content .= "LABEL_ELDERLY=\"{$config['LABEL_ELDERLY']}\"\n";

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
        'THRESHOLD_BRAND_NEW' => max(0, min($maxThreshold, intval($config['THRESHOLD_BRAND_NEW'] ?? $defaults['THRESHOLD_BRAND_NEW']))),
        'THRESHOLD_NEWISH' => max(0, min($maxThreshold, intval($config['THRESHOLD_NEWISH'] ?? $defaults['THRESHOLD_NEWISH']))),
        'THRESHOLD_NORMAL' => max(0, min($maxThreshold, intval($config['THRESHOLD_NORMAL'] ?? $defaults['THRESHOLD_NORMAL']))),
        'THRESHOLD_AGED' => max(0, min($maxThreshold, intval($config['THRESHOLD_AGED'] ?? $defaults['THRESHOLD_AGED']))),
        'THRESHOLD_OLD' => max(0, min($maxThreshold, intval($config['THRESHOLD_OLD'] ?? $defaults['THRESHOLD_OLD'])))
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
            'THRESHOLD_BRAND_NEW' => $defaults_thresh['THRESHOLD_BRAND_NEW'],
            'THRESHOLD_NEWISH' => $defaults_thresh['THRESHOLD_NEWISH'],
            'THRESHOLD_NORMAL' => $defaults_thresh['THRESHOLD_NORMAL'],
            'THRESHOLD_AGED' => $defaults_thresh['THRESHOLD_AGED'],
            'THRESHOLD_OLD' => $defaults_thresh['THRESHOLD_OLD']
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

    // Category colors validation
    $colorFields = [
        'COLOR_BRAND_NEW',
        'COLOR_NEWISH',
        'COLOR_NORMAL',
        'COLOR_AGED',
        'COLOR_OLD',
        'COLOR_ELDERLY'
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
        'TEXTCOLOR_BRAND_NEW',
        'TEXTCOLOR_NEWISH',
        'TEXTCOLOR_NORMAL',
        'TEXTCOLOR_AGED',
        'TEXTCOLOR_OLD',
        'TEXTCOLOR_ELDERLY'
    ];

    // Validate format and normalize (no uniqueness check for text colors)
    foreach ($textColorFields as $field) {
        $color = $config[$field] ?? $defaults[$field];
        $validated[$field] = isValidHexColor($color) ? strtoupper($color) : $defaults[$field];
    }

    // Category labels validation
    $labelFields = [
        'LABEL_BRAND_NEW',
        'LABEL_NEWISH',
        'LABEL_NORMAL',
        'LABEL_AGED',
        'LABEL_OLD',
        'LABEL_ELDERLY'
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
    if ($hours < intval($config['THRESHOLD_BRAND_NEW'])) {
        return 'brand_new';
    } elseif ($hours < intval($config['THRESHOLD_NEWISH'])) {
        return 'newish';
    } elseif ($hours < intval($config['THRESHOLD_NORMAL'])) {
        return 'normal';
    } elseif ($hours < intval($config['THRESHOLD_AGED'])) {
        return 'aged';
    } elseif ($hours < intval($config['THRESHOLD_OLD'])) {
        return 'old';
    } else {
        return 'elderly';
    }
}

/**
 * Get CSS class for age category
 */
function getAgeColorClass($category) {
    return 'age-' . $category;
}

/**
 * Get human-readable label for age category
 * @param string $category The age category (brand_new, newish, etc.)
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
        'brand_new' => 'Brand New',
        'newish' => 'Newish',
        'normal' => 'Mature',
        'aged' => 'Aged',
        'old' => 'Old',
        'elderly' => 'Elderly'
    ];

    return $labels[$category] ?? 'Unknown';
}
