<?php
/**
 * DriveAge Plugin - Helper Functions
 *
 * Output escaping, form helpers, and Unraid display-setting lookups.
 */

/**
 * Escape string for HTML output (shorthand for htmlspecialchars)
 *
 * @param mixed $str String to escape
 * @return string Escaped string
 */
function esc($str) {
    if ($str === null || $str === false) {
        return '';
    }

    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

/**
 * Escape and echo string
 *
 * @param mixed $str String to escape and output
 * @return void
 */
function e($str) {
    echo esc($str);
}

/**
 * Check if value is checked (for checkboxes)
 *
 * @param mixed $value Value to check
 * @return string 'checked' if true, empty string otherwise
 */
function checked($value) {
    return ($value === 'true' || $value === true || $value === 1) ? 'checked' : '';
}

/**
 * Check if value is selected (for select options)
 *
 * @param mixed $value Current value
 * @param mixed $compare Comparison value
 * @return string 'selected' if match, empty string otherwise
 */
function selected($value, $compare) {
    return ($value === $compare) ? 'selected' : '';
}

if (!defined('DRIVEAGE_DYNAMIX_CONFIG_FILE')) {
    define('DRIVEAGE_DYNAMIX_CONFIG_FILE', '/boot/config/plugins/dynamix/dynamix.cfg');
}

/**
 * Unraid's display settings that DriveAge mirrors: temperature unit and the
 * disk temperature warning/critical limits (Celsius) used by the Main page
 * and notifications. Read once per request.
 *
 * @return array ['unit' => 'C'|'F', 'hot', 'max', 'hotssd', 'maxssd']
 *               (hotssd/maxssd -1 = use the HDD limits, as dynamix does)
 */
function getDisplaySettings() {
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }

    $display = [];
    if (function_exists('parse_plugin_cfg')) {
        $display = parse_plugin_cfg('dynamix', true)['display'] ?? [];
    } elseif (file_exists(DRIVEAGE_DYNAMIX_CONFIG_FILE)) {
        $display = (@parse_ini_file(DRIVEAGE_DYNAMIX_CONFIG_FILE, true) ?: [])['display'] ?? [];
    }

    // Defaults match dynamix's shipped settings
    $num = fn($key, $default) => (isset($display[$key]) && is_numeric($display[$key])) ? intval($display[$key]) : $default;
    $settings = [
        'unit'   => (($display['unit'] ?? 'C') === 'F') ? 'F' : 'C',
        'hot'    => $num('hot', 45),
        'max'    => $num('max', 55),
        'hotssd' => $num('hotssd', 60),
        'maxssd' => $num('maxssd', 70)
    ];
    return $settings;
}

/**
 * Get user's temperature unit preference (Unraid display settings).
 *
 * @return string 'C' for Celsius or 'F' for Fahrenheit (default: 'C')
 */
function getTemperatureUnit() {
    return getDisplaySettings()['unit'];
}
