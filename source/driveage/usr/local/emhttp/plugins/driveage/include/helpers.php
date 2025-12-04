<?php
/**
 * DriveAge Plugin - Helper Functions
 *
 * Common utility functions used throughout the plugin
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
 * Generate a secure random token
 *
 * @param int $length Token length in bytes
 * @return string Hexadecimal token
 */
function generateToken($length = 32) {
    return bin2hex(random_bytes($length));
}

/**
 * Sanitize filename to prevent directory traversal
 *
 * @param string $filename Filename to sanitize
 * @return string Sanitized filename
 */
function sanitizeFilename($filename) {
    // Remove directory separators and null bytes
    $filename = str_replace(['/', '\\', "\0"], '', $filename);

    // Remove hidden file indicators
    $filename = ltrim($filename, '.');

    return $filename;
}

/**
 * Validate email address
 *
 * @param string $email Email to validate
 * @return bool True if valid
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate IP address
 *
 * @param string $ip IP to validate
 * @param bool $allowPrivate Allow private IP ranges
 * @return bool True if valid
 */
function isValidIp($ip, $allowPrivate = true) {
    $flags = FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6;

    if (!$allowPrivate) {
        $flags |= FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    }

    return filter_var($ip, FILTER_VALIDATE_IP, $flags) !== false;
}

/**
 * Safe redirect function
 *
 * @param string $url URL to redirect to
 * @param int $statusCode HTTP status code
 * @return void
 */
function safeRedirect($url, $statusCode = 302) {
    // Only allow relative URLs or same-origin URLs
    if (strpos($url, '//') === false) {
        header('Location: ' . $url, true, $statusCode);
        exit;
    }

    // Parse URL and check if same origin
    $parsed = parse_url($url);
    $currentHost = $_SERVER['HTTP_HOST'] ?? '';

    if (isset($parsed['host']) && $parsed['host'] === $currentHost) {
        header('Location: ' . $url, true, $statusCode);
        exit;
    }

    // Invalid redirect attempt - log and redirect to safe location
    error_log('DriveAge: Invalid redirect attempt to ' . $url);
    header('Location: /Settings/DriveAge', true, 302);
    exit;
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
        $config = parse_ini_file($configFile, true);
        if (isset($config['display']['unit'])) {
            return ($config['display']['unit'] === 'F') ? 'F' : 'C';
        }
    }

    // Default to Celsius
    return 'C';
}
