<?php
/**
 * DriveAge Plugin - Formatting Utilities
 *
 * Handles conversion of raw values to human-readable formats
 */

/**
 * Convert power-on hours to human-readable format
 *
 * Format: Xy Ym Xd Xh
 * - y = years (365.25 days)
 * - m = months (30.44 days average)
 * - d = days
 * - h = remaining hours
 *
 * @param int $hours Power-on hours
 * @return string Human-readable format (e.g., "2y 10m 5d 20h")
 */
function formatPowerOnHours($hours) {
    if ($hours === null || $hours < 0) {
        return 'N/A';
    }

    $years = 0;
    $months = 0;
    $days = 0;
    $remainingHours = $hours;

    // Calculate years (365.25 days = 8766 hours)
    $hoursPerYear = 8766;
    if ($remainingHours >= $hoursPerYear) {
        $years = floor($remainingHours / $hoursPerYear);
        $remainingHours = $remainingHours % $hoursPerYear;
    }

    // Calculate months (30.44 days = 730.5 hours average)
    $hoursPerMonth = 730.5;
    if ($remainingHours >= $hoursPerMonth) {
        $months = floor($remainingHours / $hoursPerMonth);
        $remainingHours = $remainingHours % $hoursPerMonth;
    }

    // Calculate days
    $hoursPerDay = 24;
    if ($remainingHours >= $hoursPerDay) {
        $days = floor($remainingHours / $hoursPerDay);
        $remainingHours = $remainingHours % $hoursPerDay;
    }

    // Round remaining hours
    $remainingHours = round($remainingHours);

    // Build formatted string
    $parts = [];

    if ($years > 0 || $months > 0 || $days > 0 || $remainingHours > 0) {
        $parts[] = $years . 'y';
        $parts[] = $months . 'm';
        $parts[] = $days . 'd';
        $parts[] = $remainingHours . 'h';
    } else {
        return '0h';
    }

    return implode(' ', $parts);
}

/**
 * Convert bytes to human-readable size
 *
 * @param int $bytes Size in bytes
 * @param int $precision Decimal precision
 * @return string Human-readable size (e.g., "20TB", "500GB")
 */
function formatBytes($bytes, $precision = 0) {
    if ($bytes === null || $bytes <= 0) {
        return 'N/A';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);

    $bytes /= pow(1024, $pow);

    return round($bytes, $precision) . $units[$pow];
}

/**
 * Format temperature for display
 *
 * @param mixed $temp Temperature value (can be int or null)
 * @param string $unit Temperature unit ('C' or 'F')
 * @return string Formatted temperature (e.g., "34°C")
 */
function formatTemperature($temp, $unit = 'C') {
    if ($temp === null || $temp === '' || $temp === false) {
        return 'N/A';
    }

    $temp = intval($temp);

    if ($unit === 'F') {
        $temp = ($temp * 9/5) + 32;
    }

    return $temp . '°' . $unit;
}

/**
 * Format SMART status for display
 *
 * @param string $status SMART status string
 * @return string HTML formatted status with color coding
 */
function formatSmartStatus($status) {
    $status = strtoupper(trim($status));

    switch ($status) {
        case 'PASSED':
        case 'OK':
            return '<span class="smart-passed">PASSED</span>';

        case 'FAILED':
        case 'FAILING':
            return '<span class="smart-failed">FAILED</span>';

        default:
            return '<span class="smart-unknown">UNKNOWN</span>';
    }
}

/**
 * Format spin status for display
 *
 * @param string $status Spin status
 * @return string HTML formatted spin status
 */
function formatSpinStatus($status) {
    $status = strtolower(trim($status));

    switch ($status) {
        case 'active':
        case 'spun_up':
        case 'active/idle':
            return '<span class="spin-active">Active</span>';

        case 'standby':
        case 'spun_down':
            return '<span class="spin-standby">Standby</span>';

        default:
            return '<span class="spin-unknown">Unknown</span>';
    }
}

/**
 * Sanitize string for HTML output
 *
 * @param string $str String to sanitize
 * @return string Sanitized string
 */
function sanitizeHtml($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

/**
 * Format device identification for display
 * Truncates long identifications with ellipsis
 *
 * @param string $id Device identification
 * @param int $maxLength Maximum length before truncation
 * @return string Formatted identification
 */
function formatDeviceId($id, $maxLength = 60) {
    $id = sanitizeHtml($id);

    if (strlen($id) > $maxLength) {
        return substr($id, 0, $maxLength - 3) . '...';
    }

    return $id;
}

/**
 * Format timestamp for display
 *
 * @param int $timestamp Unix timestamp
 * @param string $format Date format string
 * @return string Formatted date/time
 */
function formatTimestamp($timestamp = null, $format = 'Y-m-d H:i:s') {
    if ($timestamp === null) {
        $timestamp = time();
    }

    return date($format, $timestamp);
}

/**
 * Get color-coded temperature class
 *
 * @param int $temp Temperature in Celsius
 * @return string CSS class name
 */
function getTemperatureClass($temp) {
    if ($temp === null || $temp === '') {
        return 'temp-unknown';
    }

    $temp = intval($temp);

    if ($temp >= 60) {
        return 'temp-critical';  // Red - Critical
    } elseif ($temp >= 50) {
        return 'temp-high';      // Orange - High
    } elseif ($temp >= 40) {
        return 'temp-elevated';  // Yellow - Elevated
    } else {
        return 'temp-normal';    // Green - Normal
    }
}

/**
 * Format number with thousands separator
 *
 * @param mixed $number Number to format
 * @param int $decimals Number of decimal places
 * @return string Formatted number
 */
function formatNumber($number, $decimals = 0) {
    if ($number === null || $number === '') {
        return 'N/A';
    }

    return number_format($number, $decimals, '.', ',');
}
