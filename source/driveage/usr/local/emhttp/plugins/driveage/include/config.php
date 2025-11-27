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
        'VIEW_MODE' => 'table',
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
        'API_RATE_LIMIT' => '100'
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
    $content .= "VIEW_MODE=\"{$config['VIEW_MODE']}\"\n";
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
    $content .= "API_RATE_LIMIT=\"{$config['API_RATE_LIMIT']}\"\n";

    return file_put_contents(DRIVEAGE_CONFIG_FILE, $content) !== false;
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

    // View mode - strict whitelist
    $validViewModes = ['table', 'card'];
    $validated['VIEW_MODE'] = in_array($config['VIEW_MODE'] ?? '', $validViewModes, true)
        ? $config['VIEW_MODE']
        : $defaults['VIEW_MODE'];

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
 */
function getAgeLabel($category) {
    $labels = [
        'brand_new' => 'Brand New',
        'newish' => 'Newish',
        'normal' => 'Normal',
        'aged' => 'Aged',
        'old' => 'Old',
        'elderly' => 'Elderly'
    ];

    return $labels[$category] ?? 'Unknown';
}
