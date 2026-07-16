<?php
/**
 * DriveAge Plugin - Formatting Utilities
 *
 * Converts raw values to human-readable formats. Returns PLAIN TEXT and
 * CSS class names only - no HTML - so the JSON API stays presentation-free.
 */

/**
 * Convert power-on hours to human-readable format
 *
 * Format: Xy Ym Xd Xh
 *
 * @param int|null $hours Power-on hours
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

    // 8760 hours/year - consistent with the prediction and threshold math
    $hoursPerYear = 8760;
    if ($remainingHours >= $hoursPerYear) {
        $years = floor($remainingHours / $hoursPerYear);
        $remainingHours = $remainingHours % $hoursPerYear;
    }

    // 30.44 days = 730.5 hours average per month
    $hoursPerMonth = 730.5;
    if ($remainingHours >= $hoursPerMonth) {
        $months = floor($remainingHours / $hoursPerMonth);
        $remainingHours = fmod($remainingHours, $hoursPerMonth);
    }

    $hoursPerDay = 24;
    if ($remainingHours >= $hoursPerDay) {
        $days = floor($remainingHours / $hoursPerDay);
        $remainingHours = fmod($remainingHours, $hoursPerDay);
    }

    $remainingHours = round($remainingHours);

    if ($years > 0 || $months > 0 || $days > 0 || $remainingHours > 0) {
        return $years . 'y ' . $months . 'm ' . $days . 'd ' . $remainingHours . 'h';
    }

    return '0h';
}

/**
 * Convert bytes to human-readable size
 * Uses Unraid's adaptive decimal precision logic for consistency with Main page
 *
 * @param int $bytes Size in bytes
 * @param int|null $precision Decimal precision (null for adaptive)
 * @param int $kilo Base unit (1000 for SI/decimal, 1024 for binary) - default 1000 to match Unraid
 * @return string Human-readable size (e.g., "20TB", "500GB")
 */
function formatBytes($bytes, $precision = null, $kilo = 1000) {
    if ($bytes === null || $bytes <= 0) {
        return 'N/A';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $bytes = max($bytes, 0);
    $base = $bytes ? intval(floor(log($bytes) / log($kilo))) : 0;
    $base = min($base, count($units) - 1);

    $value = $bytes / pow($kilo, $base);

    // Adaptive decimal precision (matches Unraid's my_scale logic)
    if ($precision === null) {
        if ($value >= 100) {
            $decimals = 0;
        } elseif ($value >= 10) {
            $decimals = 1;
        } else {
            $decimals = (round($value * 100) % 100 === 0) ? 0 : 2;
        }
    } else {
        $decimals = $precision;
    }

    // Smart rounding: if value rounds to 1000, bump to next unit
    if (round($value, 0) == 1000 && $base < count($units) - 1) {
        $value = 1;
        $base++;
        $decimals = 0;
    }

    $formattedValue = number_format($value, $decimals, '.', $value > 9999 ? ',' : '');

    return $formattedValue . $units[$base];
}

/**
 * Process disk identification string (WWN processing)
 * Matches Unraid's my_id() function logic
 *
 * @param string $id Disk identification string
 * @return string Processed identification
 */
function processDeviceId($id) {
    if (empty($id)) {
        return '';
    }

    $len = strlen($id);
    if ($len <= 18) {
        return $id;
    }

    $wwn = substr($id, -18);

    if (substr($wwn, 0, 2) === '_3' && !preg_match('/.[_-]/', $wwn)) {
        return substr($id, 0, $len - 18);
    }

    return $id;
}

/**
 * Format device/disk name for display
 * Matches Unraid's my_disk() function logic
 *
 * @param string $name Device name
 * @param bool $raw Return raw name without formatting
 * @return string Formatted device name
 */
function formatDeviceName($name, $raw = false) {
    if (empty($name) || $raw) {
        return $name;
    }

    // "disk1" -> "Disk 1", "parity2" -> "Parity 2", "cache" -> "Cache"
    return ucfirst(preg_replace('/(\d+)$/', ' $1', $name));
}

/**
 * Format temperature for display
 *
 * @param mixed $temp Temperature value in Celsius (int or null)
 * @param string $unit Temperature unit ('C' or 'F')
 * @return string Formatted temperature (e.g., "34 °C" or "93 °F")
 */
function formatTemperature($temp, $unit = 'C') {
    if ($temp === null || $temp === '' || $temp === false) {
        return 'N/A';
    }

    $temp = intval($temp);

    if ($unit === 'F') {
        $temp = round(9/5 * $temp) + 32;
    }

    return $temp . ' °' . $unit;
}

/**
 * CSS class for a SMART overall-health status.
 *
 * @param string $status SMART status string
 * @return string CSS class name
 */
function getSmartStatusClass($status) {
    switch (strtoupper(trim((string)$status))) {
        case 'PASSED':
        case 'OK':
            return 'smart-passed';
        case 'FAILED':
        case 'FAILING':
            return 'smart-failed';
        case 'N/A':
            return 'smart-na';
        default:
            return 'smart-unknown';
    }
}

/**
 * CSS class for a spin status.
 *
 * @param string $status Spin status
 * @return string CSS class name
 */
function getSpinStatusClass($status) {
    switch (strtolower(trim((string)$status))) {
        case 'active':
        case 'spun_up':
        case 'active/idle':
            return 'spin-active';
        case 'standby':
        case 'spun_down':
            return 'spin-standby';
        case 'n/a':
            return 'spin-na';
        default:
            return 'spin-unknown';
    }
}

/**
 * Format timestamp for display
 *
 * @param int|null $timestamp Unix timestamp
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
 * Get color-coded temperature class.
 * Single source of truth - the JSON API ships this class to the frontend.
 *
 * @param int|null $temp Temperature in Celsius
 * @param string $physicalType Physical drive type ('hdd', 'nvme' or 'usb')
 * @return string CSS class name
 */
function getTemperatureClass($temp, $physicalType = 'hdd') {
    if ($temp === null || $temp === '') {
        return 'temp-unknown';
    }

    $temp = intval($temp);

    if ($physicalType === 'nvme') {
        // NVMe thresholds: < 50°C, 50-64°C, 65-74°C, >= 75°C
        if ($temp >= 75) {
            return 'temp-critical';
        } elseif ($temp >= 65) {
            return 'temp-high';
        } elseif ($temp >= 50) {
            return 'temp-elevated';
        }
        return 'temp-normal';
    }

    // HDD thresholds: < 40°C, 40-49°C, 50-59°C, >= 60°C
    if ($temp >= 60) {
        return 'temp-critical';
    } elseif ($temp >= 50) {
        return 'temp-high';
    } elseif ($temp >= 40) {
        return 'temp-elevated';
    }
    return 'temp-normal';
}
