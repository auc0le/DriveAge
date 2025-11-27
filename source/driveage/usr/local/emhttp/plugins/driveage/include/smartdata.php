<?php
/**
 * DriveAge Plugin - SMART Data Collection
 *
 * Handles discovery and querying of drive SMART data
 */

require_once 'formatting.php';
require_once 'config.php';

// Cache directory and file for SMART data (secure location)
define('SMART_CACHE_DIR', '/var/lib/driveage');
define('SMART_CACHE_FILE', SMART_CACHE_DIR . '/cache.json');
define('SMART_CACHE_TTL', 300); // Cache TTL in seconds (5 minutes)

/**
 * Get all drives with their SMART data
 *
 * @param array $config Plugin configuration
 * @param bool $useCache Whether to use cached data
 * @return array Array of drive information
 */
function getAllDrives($config, $useCache = true) {
    // Check cache
    if ($useCache && isCacheValid()) {
        $cached = loadCache();
        if ($cached !== null) {
            return $cached;
        }
    }

    $drives = [];

    // Parse disks.ini once for all drives (performance optimization)
    $disksIni = '/var/local/emhttp/disks.ini';
    $diskAssignments = [];
    if (file_exists($disksIni)) {
        $diskAssignments = parse_ini_file($disksIni, true) ?: [];
    }

    // Discover all block devices
    $devices = discoverBlockDevices();

    foreach ($devices as $devicePath) {
        $driveInfo = getDriveInfo($devicePath, $diskAssignments, $config);
        if ($driveInfo) {
            $drives[] = $driveInfo;
        }
    }

    // Sort by power-on hours (descending - oldest first)
    usort($drives, function($a, $b) {
        return $b['power_on_hours'] - $a['power_on_hours'];
    });

    // Mark elderly drives for bold formatting
    foreach ($drives as &$drive) {
        $drive['is_oldest'] = ($drive['age_category'] === 'elderly');
    }

    // Save to cache
    saveCache($drives);

    return $drives;
}

/**
 * Discover all block devices
 *
 * @return array Array of device paths
 */
function discoverBlockDevices() {
    $devices = [];

    // Use glob() instead of shell_exec for security
    $patterns = ['/dev/sd?', '/dev/nvme?n?'];

    foreach ($patterns as $pattern) {
        $found = glob($pattern);
        if ($found) {
            foreach ($found as $device) {
                // Validate device path and ensure it's a block device
                if (isValidBlockDevice($device)) {
                    $devices[] = $device;
                }
            }
        }
    }

    return $devices;
}

/**
 * Validate that a path is a legitimate block device
 *
 * @param string $path Device path
 * @return bool True if valid block device
 */
function isValidBlockDevice($path) {
    // Must match expected pattern
    if (!preg_match('/^\/dev\/(sd[a-z]|nvme[0-9]n[0-9])$/', $path)) {
        return false;
    }

    // Must exist and be readable
    if (!file_exists($path) || !is_readable($path)) {
        return false;
    }

    // Must be a block device (not regular file or symlink)
    if (is_link($path)) {
        return false;
    }

    // Check if it's actually a block device
    $fileType = @filetype($path);
    if ($fileType !== 'block') {
        return false;
    }

    return true;
}

/**
 * Get Unraid variable information
 *
 * @return array Unraid vars
 */
function getUnraidVars() {
    $varFile = '/var/local/emhttp/var.ini';

    if (!file_exists($varFile)) {
        return [];
    }

    return parse_ini_file($varFile) ?: [];
}

/**
 * Get detailed information about a specific drive
 *
 * @param string $devicePath Device path (e.g., /dev/sda)
 * @param array $diskAssignments Parsed disks.ini data
 * @param array $config Plugin configuration
 * @return array|null Drive information array or null if failed
 */
function getDriveInfo($devicePath, $diskAssignments, $config) {
    // Get device name (e.g., sda from /dev/sda)
    $deviceName = basename($devicePath);

    // Get SMART data
    $smartData = getSmartData($devicePath);
    if (!$smartData) {
        return null; // Skip drives without SMART data
    }

    // Get Unraid assignment info
    $assignment = getUnraidAssignment($deviceName, $diskAssignments);

    // Get drive size (preferably from Unraid's disks.ini for accuracy)
    $size = getDriveSize($devicePath, $deviceName, $diskAssignments);

    // Determine age category
    $ageCategory = getAgeCategory($smartData['power_on_hours'], $config);

    return [
        'device_name' => $assignment['display_name'],
        'device_path' => $devicePath,
        'device_id' => $deviceName,
        'identification' => $smartData['model'] . ' (' . $deviceName . ')',
        'model' => $smartData['model'],
        'serial' => $smartData['serial'],
        'size_bytes' => $size,
        'size_human' => formatBytes($size),
        'array_name' => $assignment['array_name'],
        'drive_type' => $assignment['drive_type'],
        'power_on_hours' => $smartData['power_on_hours'],
        'power_on_human' => formatPowerOnHours($smartData['power_on_hours']),
        'temperature' => $smartData['temperature'],
        'temperature_formatted' => formatTemperature($smartData['temperature']),
        'smart_status' => $smartData['smart_status'],
        'smart_status_formatted' => formatSmartStatus($smartData['smart_status']),
        'spin_status' => $smartData['spin_status'],
        'spin_status_formatted' => formatSpinStatus($smartData['spin_status']),
        'age_category' => $ageCategory,
        'color_class' => getAgeColorClass($ageCategory),
        'age_label' => getAgeLabel($ageCategory),
        'is_oldest' => false // Will be set later
    ];
}

/**
 * Get SMART data from Unraid's cached files (updated by emhttpd)
 *
 * @param string $deviceName Device name (e.g., sda, nvme0n1)
 * @return array|null SMART data or null if cache not available
 */
function getSmartDataFromUnraidCache($deviceName) {
    // Unraid caches SMART data in /var/local/emhttp/smart/
    $cacheFile = "/var/local/emhttp/smart/$deviceName";

    if (!file_exists($cacheFile)) {
        return null;
    }

    $output = file_get_contents($cacheFile);
    if (!$output) {
        return null;
    }

    // Parse the cached smartctl output (same format as smartctl -A)
    return parseSmartctlOutput($output);
}

/**
 * Parse smartctl text output to extract SMART attributes
 *
 * @param string $output Raw smartctl output
 * @return array|null SMART data array or null if failed
 */
function parseSmartctlOutput($output) {
    $smartData = [
        'model' => 'Unknown',
        'serial' => 'Unknown',
        'smart_status' => 'UNKNOWN',
        'temperature' => null,
        'power_on_hours' => null,
        'spin_status' => 'unknown'
    ];

    $lines = explode("\n", $output);

    foreach ($lines as $line) {
        // Model
        if (preg_match('/Device Model:\s+(.+)/', $line, $matches)) {
            $smartData['model'] = trim($matches[1]);
        } elseif (preg_match('/Model Number:\s+(.+)/', $line, $matches)) {
            $smartData['model'] = trim($matches[1]);
        }

        // Serial
        if (preg_match('/Serial Number:\s+(.+)/', $line, $matches)) {
            $smartData['serial'] = trim($matches[1]);
        }

        // SMART status
        if (preg_match('/SMART overall-health.*:\s+(.+)/', $line, $matches)) {
            $smartData['smart_status'] = (stripos($matches[1], 'PASSED') !== false) ? 'PASSED' : 'FAILED';
        }

        // Parse SMART attribute table (ID 9 = Power_On_Hours, ID 194 = Temperature)
        // Format: ID# ATTRIBUTE_NAME FLAG VALUE WORST THRESH TYPE UPDATED WHEN_FAILED RAW_VALUE
        if (preg_match('/^\s*(\d+)\s+\S+.*\s+(\d+)\s*$/', $line, $matches)) {
            $attrId = intval($matches[1]);
            $rawValue = intval($matches[2]);

            if ($attrId === 9) { // Power_On_Hours
                $smartData['power_on_hours'] = $rawValue;
            } elseif ($attrId === 194 && $smartData['temperature'] === null) { // Temperature_Celsius
                $smartData['temperature'] = $rawValue;
            }
        }
    }

    // Return null if we couldn't get critical data
    return $smartData['power_on_hours'] !== null ? $smartData : null;
}

/**
 * Query SMART data from a drive
 *
 * @param string $devicePath Device path
 * @return array|null SMART data or null if failed
 */
function getSmartData($devicePath) {
    $deviceName = basename($devicePath);

    // First, try to read from Unraid's cached SMART data for performance
    $cachedData = getSmartDataFromUnraidCache($deviceName);
    if ($cachedData !== null) {
        return $cachedData;
    }

    // Fallback: Run smartctl directly if cache not available
    $output = shell_exec("smartctl -a -j " . escapeshellarg($devicePath) . " 2>/dev/null");

    if (!$output) {
        // Try without JSON output for older smartctl versions
        return getSmartDataLegacy($devicePath);
    }

    $data = json_decode($output, true);
    if (!$data) {
        return getSmartDataLegacy($devicePath);
    }

    // Extract relevant SMART attributes
    $smartData = [
        'model' => $data['model_name'] ?? $data['model_family'] ?? 'Unknown',
        'serial' => $data['serial_number'] ?? 'Unknown',
        'smart_status' => ($data['smart_status']['passed'] ?? false) ? 'PASSED' : 'FAILED',
        'temperature' => null,
        'power_on_hours' => null,
        'spin_status' => 'unknown'
    ];

    // Get temperature
    if (isset($data['temperature']['current'])) {
        $smartData['temperature'] = $data['temperature']['current'];
    }

    // Get power-on hours and other attributes
    if (isset($data['ata_smart_attributes']['table'])) {
        foreach ($data['ata_smart_attributes']['table'] as $attr) {
            if ($attr['id'] === 9) { // Power_On_Hours
                $smartData['power_on_hours'] = $attr['raw']['value'];
            }
            if ($attr['id'] === 194 && $smartData['temperature'] === null) { // Temperature
                $smartData['temperature'] = $attr['raw']['value'];
            }
        }
    }

    // For NVMe drives
    if (isset($data['nvme_smart_health_information_log'])) {
        $nvmeData = $data['nvme_smart_health_information_log'];
        $smartData['temperature'] = $nvmeData['temperature'] ?? null;
        $smartData['power_on_hours'] = isset($nvmeData['power_on_hours'])
            ? floor($nvmeData['power_on_hours'])
            : null;
    }

    // Get spin status
    $smartData['spin_status'] = getSpinStatus($devicePath);

    return $smartData['power_on_hours'] !== null ? $smartData : null;
}

/**
 * Get SMART data using legacy text parsing (fallback)
 *
 * @param string $devicePath Device path
 * @return array|null SMART data or null if failed
 */
function getSmartDataLegacy($devicePath) {
    $output = shell_exec("smartctl -a " . escapeshellarg($devicePath) . " 2>/dev/null");

    if (!$output) {
        return null;
    }

    $smartData = [
        'model' => 'Unknown',
        'serial' => 'Unknown',
        'smart_status' => 'UNKNOWN',
        'temperature' => null,
        'power_on_hours' => null,
        'spin_status' => 'unknown'
    ];

    $lines = explode("\n", $output);

    foreach ($lines as $line) {
        // Model
        if (preg_match('/Device Model:\s+(.+)/', $line, $matches)) {
            $smartData['model'] = trim($matches[1]);
        } elseif (preg_match('/Model Number:\s+(.+)/', $line, $matches)) {
            $smartData['model'] = trim($matches[1]);
        }

        // Serial
        if (preg_match('/Serial Number:\s+(.+)/', $line, $matches)) {
            $smartData['serial'] = trim($matches[1]);
        }

        // SMART status
        if (preg_match('/SMART overall-health.*:\s+(.+)/', $line, $matches)) {
            $smartData['smart_status'] = (stripos($matches[1], 'PASSED') !== false) ? 'PASSED' : 'FAILED';
        }

        // Power-on hours (attribute 9)
        if (preg_match('/^\s*9\s+Power_On_Hours.*\s+(\d+)$/', $line, $matches)) {
            $smartData['power_on_hours'] = intval($matches[1]);
        }

        // Temperature (attribute 194)
        if (preg_match('/^\s*194\s+Temperature_Celsius.*\s+(\d+)(\s+|$)/', $line, $matches)) {
            $smartData['temperature'] = intval($matches[1]);
        }
    }

    $smartData['spin_status'] = getSpinStatus($devicePath);

    return $smartData['power_on_hours'] !== null ? $smartData : null;
}

/**
 * Get spin status of a drive
 *
 * @param string $devicePath Device path
 * @return string Spin status (active, standby, unknown)
 */
function getSpinStatus($devicePath) {
    $output = shell_exec("smartctl -n standby " . escapeshellarg($devicePath) . " 2>/dev/null");

    if (stripos($output, 'STANDBY') !== false || stripos($output, 'SLEEP') !== false) {
        return 'standby';
    } elseif (stripos($output, 'ACTIVE') !== false || stripos($output, 'IDLE') !== false) {
        return 'active';
    }

    return 'active'; // Default to active if can't determine
}

/**
 * Get drive size in bytes
 *
 * @param string $devicePath Device path
 * @param string $deviceName Device name (e.g., sda)
 * @param array $diskAssignments Parsed disks.ini data
 * @return int Size in bytes
 */
function getDriveSize($devicePath, $deviceName, $diskAssignments) {
    // First, try to get size from Unraid's disks.ini (this is the raw hardware size)
    if (!empty($diskAssignments)) {
        foreach ($diskAssignments as $diskInfo) {
            if (isset($diskInfo['device']) && $diskInfo['device'] === $deviceName) {
                if (isset($diskInfo['size'])) {
                    // Size in disks.ini is in 512-byte blocks, convert to bytes
                    return intval($diskInfo['size']) * 512;
                }
                break;
            }
        }
    }

    // Fallback to blockdev if not found in disks.ini
    $output = shell_exec("blockdev --getsize64 " . escapeshellarg($devicePath) . " 2>/dev/null");
    return $output ? intval(trim($output)) : 0;
}

/**
 * Get Unraid assignment information for a drive
 *
 * @param string $deviceName Device name (e.g., sda)
 * @param array $diskAssignments Parsed disks.ini data
 * @return array Assignment info
 */
function getUnraidAssignment($deviceName, $diskAssignments) {
    $assignment = [
        'display_name' => $deviceName,
        'array_name' => 'Unassigned',
        'drive_type' => 'unassigned'
    ];

    // Check if we have disk assignments data
    if (empty($diskAssignments)) {
        return $assignment;
    }

    // Search for matching device
    foreach ($diskAssignments as $diskName => $diskInfo) {
        if (!isset($diskInfo['device'])) {
            continue;
        }

        // Match device name (handle both full device like "nvme0n1" and base like "sda")
        if ($diskInfo['device'] === $deviceName) {
            $type = $diskInfo['type'] ?? 'Unknown';

            // Map Unraid type to our drive type
            switch ($type) {
                case 'Parity':
                    $assignment['display_name'] = ucfirst($diskName);
                    $assignment['array_name'] = 'Main Array';
                    $assignment['drive_type'] = 'parity';
                    break;

                case 'Data':
                    $assignment['display_name'] = ucfirst($diskName);
                    $assignment['array_name'] = 'Main Array';
                    $assignment['drive_type'] = 'array';
                    break;

                case 'Cache':
                    // Use the actual cache name (e.g., "cache", "media_cache")
                    $assignment['display_name'] = ucfirst(str_replace('_', ' ', $diskName));
                    $assignment['array_name'] = ucfirst(str_replace('_', ' ', $diskName));
                    $assignment['drive_type'] = 'cache';
                    break;

                case 'Flash':
                    // Skip flash drive
                    return $assignment;

                default:
                    $assignment['display_name'] = ucfirst($diskName);
                    $assignment['array_name'] = 'Unknown';
                    $assignment['drive_type'] = 'unassigned';
                    break;
            }

            return $assignment;
        }
    }

    return $assignment;
}

/**
 * Initialize cache directory with secure permissions
 *
 * @return bool True if successful
 */
function initCacheDirectory() {
    if (!is_dir(SMART_CACHE_DIR)) {
        if (!mkdir(SMART_CACHE_DIR, 0700, true)) {
            error_log('DriveAge: Failed to create cache directory');
            return false;
        }
    }

    // Ensure directory has secure permissions
    chmod(SMART_CACHE_DIR, 0700);
    return true;
}

/**
 * Check if cache is valid
 *
 * @return bool True if cache exists and is not expired
 */
function isCacheValid() {
    if (!file_exists(SMART_CACHE_FILE)) {
        return false;
    }

    // Verify it's a regular file (not a symlink)
    if (!is_file(SMART_CACHE_FILE) || is_link(SMART_CACHE_FILE)) {
        error_log('DriveAge: Cache file is not a regular file');
        return false;
    }

    $cacheTime = filemtime(SMART_CACHE_FILE);
    return (time() - $cacheTime) < SMART_CACHE_TTL;
}

/**
 * Load data from cache
 *
 * @return array|null Cached data or null if invalid
 */
function loadCache() {
    if (!initCacheDirectory()) {
        return null;
    }

    if (!file_exists(SMART_CACHE_FILE)) {
        return null;
    }

    // Verify it's a regular file (not a symlink)
    if (!is_file(SMART_CACHE_FILE) || is_link(SMART_CACHE_FILE)) {
        error_log('DriveAge: Cache file is not a regular file');
        return null;
    }

    $content = file_get_contents(SMART_CACHE_FILE);
    if (!$content) {
        return null;
    }

    $data = json_decode($content, true);
    return $data ?: null;
}

/**
 * Save data to cache using atomic write
 *
 * @param array $data Data to cache
 * @return bool True if successful
 */
function saveCache($data) {
    if (!initCacheDirectory()) {
        return false;
    }

    $tempFile = SMART_CACHE_FILE . '.tmp.' . getmypid();
    $json = json_encode($data, JSON_PRETTY_PRINT);

    // Write to temp file with exclusive lock
    if (file_put_contents($tempFile, $json, LOCK_EX) === false) {
        error_log('DriveAge: Failed to write cache temp file');
        return false;
    }

    // Set restrictive permissions
    chmod($tempFile, 0600);

    // Atomic rename
    if (!rename($tempFile, SMART_CACHE_FILE)) {
        error_log('DriveAge: Failed to rename cache file');
        @unlink($tempFile);
        return false;
    }

    return true;
}

/**
 * Clear cache
 *
 * @return bool True if successful
 */
function clearCache() {
    if (file_exists(SMART_CACHE_FILE)) {
        return unlink(SMART_CACHE_FILE);
    }
    return true;
}
