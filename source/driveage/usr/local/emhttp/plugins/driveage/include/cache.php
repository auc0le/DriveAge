<?php
/**
 * DriveAge Plugin - Drive Data Caching
 *
 * Caches power-on hours for drives in standby mode
 * Prevents showing 0 hours when drives are spun down
 */

// Cache directory (ramdisk, lost on reboot)
define('DRIVEAGE_CACHE_DIR', '/var/lib/driveage');

// Cache validity period (24 hours)
define('DRIVEAGE_CACHE_STALE_SECONDS', 86400);

/**
 * Initialize cache directory
 * Creates directory with proper permissions if it doesn't exist
 *
 * @return bool True if directory exists/created, false on failure
 */
function initCacheDirectory() {
    if (!file_exists(DRIVEAGE_CACHE_DIR)) {
        if (!@mkdir(DRIVEAGE_CACHE_DIR, 0700, true)) {
            error_log('DriveAge: Failed to create cache directory: ' . DRIVEAGE_CACHE_DIR);
            return false;
        }
    }

    // Verify directory is not a symlink (security check)
    if (is_link(DRIVEAGE_CACHE_DIR)) {
        error_log('DriveAge: Cache directory is a symlink (security violation): ' . DRIVEAGE_CACHE_DIR);
        return false;
    }

    // Verify permissions
    $perms = fileperms(DRIVEAGE_CACHE_DIR);
    if (($perms & 0777) > 0700) {
        error_log('DriveAge: Cache directory has insecure permissions: ' . DRIVEAGE_CACHE_DIR);
        @chmod(DRIVEAGE_CACHE_DIR, 0700);
    }

    return true;
}

/**
 * Get cache file path for a device
 *
 * @param string $deviceName Device name (e.g., "sda")
 * @return string Cache file path
 */
function getCacheFilePath($deviceName) {
    // Sanitize device name to prevent directory traversal
    $safeDeviceName = preg_replace('/[^a-z0-9_-]/i', '', $deviceName);
    return DRIVEAGE_CACHE_DIR . '/drive_cache_' . $safeDeviceName . '.json';
}

/**
 * Save drive metrics to persistent cache
 *
 * @param string $deviceName Device name (e.g., "sda")
 * @param int $powerOnHours Power-on hours value
 * @param string $model Drive model
 * @param string $serial Drive serial number
 * @return bool True on success, false on failure
 */
function saveDriveCache($deviceName, $powerOnHours, $model, $serial) {
    // Validate inputs
    if (empty($deviceName) || !is_numeric($powerOnHours) || $powerOnHours < 0) {
        return false;
    }

    // Initialize cache directory
    if (!initCacheDirectory()) {
        return false;
    }

    $cacheFile = getCacheFilePath($deviceName);

    $cacheData = [
        'device' => $deviceName,
        'power_on_hours' => intval($powerOnHours),
        'model' => $model,
        'serial' => $serial,
        'timestamp' => time(),
        'last_updated' => date('Y-m-d H:i:s')
    ];

    $json = json_encode($cacheData, JSON_PRETTY_PRINT);
    if ($json === false) {
        error_log('DriveAge: Failed to encode cache data for ' . $deviceName);
        return false;
    }

    // Atomic write: write to temp file, then rename
    $tempFile = $cacheFile . '.tmp';
    if (@file_put_contents($tempFile, $json, LOCK_EX) === false) {
        error_log('DriveAge: Failed to write cache file for ' . $deviceName);
        return false;
    }

    if (!@rename($tempFile, $cacheFile)) {
        @unlink($tempFile);
        error_log('DriveAge: Failed to rename cache file for ' . $deviceName);
        return false;
    }

    // Set restrictive permissions
    @chmod($cacheFile, 0600);

    return true;
}

/**
 * Load cached drive metrics
 *
 * @param string $deviceName Device name (e.g., "sda")
 * @return array|null Cache data array or null if not found/invalid
 */
function loadDriveCache($deviceName) {
    $cacheFile = getCacheFilePath($deviceName);

    // Check if file exists
    if (!file_exists($cacheFile)) {
        return null;
    }

    // Security check: verify not a symlink
    if (is_link($cacheFile)) {
        error_log('DriveAge: Cache file is a symlink (security violation): ' . $cacheFile);
        @unlink($cacheFile);
        return null;
    }

    // Read cache file
    $json = @file_get_contents($cacheFile);
    if ($json === false) {
        return null;
    }

    // Parse JSON
    $cacheData = json_decode($json, true);
    if (!is_array($cacheData)) {
        error_log('DriveAge: Invalid cache data for ' . $deviceName);
        @unlink($cacheFile);
        return null;
    }

    // Validate required fields
    if (!isset($cacheData['power_on_hours']) || !isset($cacheData['timestamp'])) {
        error_log('DriveAge: Incomplete cache data for ' . $deviceName);
        @unlink($cacheFile);
        return null;
    }

    // Validate data types
    if (!is_numeric($cacheData['power_on_hours']) || !is_numeric($cacheData['timestamp'])) {
        error_log('DriveAge: Invalid cache data types for ' . $deviceName);
        @unlink($cacheFile);
        return null;
    }

    return $cacheData;
}

/**
 * Check if cache is stale (> 24 hours old)
 *
 * @param int $cacheTimestamp Unix timestamp from cache
 * @return bool True if stale, false otherwise
 */
function isCacheStale($cacheTimestamp) {
    if (!is_numeric($cacheTimestamp)) {
        return true;
    }

    $age = time() - intval($cacheTimestamp);
    return $age > DRIVEAGE_CACHE_STALE_SECONDS;
}

/**
 * Get cache age in human-readable format
 *
 * @param int $cacheTimestamp Unix timestamp from cache
 * @return string Formatted age (e.g., "2h ago", "3d ago")
 */
function getCacheAge($cacheTimestamp) {
    if (!is_numeric($cacheTimestamp)) {
        return 'unknown';
    }

    $age = time() - intval($cacheTimestamp);

    if ($age < 60) {
        return 'just now';
    } elseif ($age < 3600) {
        $minutes = floor($age / 60);
        return $minutes . 'm ago';
    } elseif ($age < 86400) {
        $hours = floor($age / 3600);
        return $hours . 'h ago';
    } else {
        $days = floor($age / 86400);
        return $days . 'd ago';
    }
}

/**
 * Clean up old cache files (maintenance function)
 * Removes cache files older than 30 days
 *
 * @return int Number of files deleted
 */
function cleanupOldCacheFiles() {
    if (!is_dir(DRIVEAGE_CACHE_DIR)) {
        return 0;
    }

    $deleted = 0;
    $maxAge = 30 * 86400; // 30 days
    $files = glob(DRIVEAGE_CACHE_DIR . '/drive_cache_*.json');

    foreach ($files as $file) {
        if (!is_file($file) || is_link($file)) {
            continue;
        }

        $mtime = @filemtime($file);
        if ($mtime === false) {
            continue;
        }

        if ((time() - $mtime) > $maxAge) {
            if (@unlink($file)) {
                $deleted++;
            }
        }
    }

    return $deleted;
}
