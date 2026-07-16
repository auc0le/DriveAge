<?php
/**
 * DriveAge Plugin - Helper Functions
 *
 * Output escaping, form helpers, and Unraid display-preference lookups.
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

/**
 * Get user's temperature unit preference from Dynamix config
 * Matches Unraid's display settings for temperature unit
 *
 * @return string 'C' for Celsius or 'F' for Fahrenheit (default: 'C')
 */
function getTemperatureUnit() {
    // Try to use Unraid's parse_plugin_cfg if available
    if (function_exists('parse_plugin_cfg')) {
        $dynamixConfig = parse_plugin_cfg('dynamix', true);
        if (isset($dynamixConfig['display']['unit'])) {
            return ($dynamixConfig['display']['unit'] === 'F') ? 'F' : 'C';
        }
    }

    // Fallback: Read dynamix.cfg directly
    $configFile = '/boot/config/plugins/dynamix/dynamix.cfg';
    if (file_exists($configFile)) {
        $config = @parse_ini_file($configFile, true) ?: [];
        if (isset($config['display']['unit'])) {
            return ($config['display']['unit'] === 'F') ? 'F' : 'C';
        }
    }

    // Default to Celsius
    return 'C';
}
