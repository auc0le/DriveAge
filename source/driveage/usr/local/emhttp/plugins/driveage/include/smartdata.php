<?php
/**
 * DriveAge Plugin - SMART Data Collection
 *
 * Handles discovery and querying of drive SMART data
 */

require_once 'formatting.php';
require_once 'config.php';
require_once 'helpers.php';

// DriveAge now relies entirely on Unraid's SMART cache at /var/local/emhttp/smart/
// This cache is updated by emhttpd every 30 seconds (configurable via poll_attributes)
// No plugin-level caching is used to avoid spinning up drives

/**
 * Get all drives with their SMART data
 *
 * Reads exclusively from Unraid's SMART cache at /var/local/emhttp/smart/
 * This cache is maintained by emhttpd and updated every 30 seconds
 *
 * @param array $config Plugin configuration
 * @return array Array of drive information
 */
function getAllDrives($config) {
    $drives = [];

    // Parse disks.ini once for all drives (performance optimization)
    $disksIni = '/var/local/emhttp/disks.ini';
    $diskAssignments = [];
    if (file_exists($disksIni)) {
        $diskAssignments = parse_ini_file($disksIni, true) ?: [];
    }

    // Get user's temperature unit preference from Dynamix config
    // This matches Unraid's Main page display settings (Settings -> Display Settings)
    $tempUnit = getTemperatureUnit();

    // Discover all block devices
    $devices = discoverBlockDevices();

    foreach ($devices as $devicePath) {
        $driveInfo = getDriveInfo($devicePath, $diskAssignments, $config, $tempUnit);
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
 * @param string $tempUnit Temperature unit ('C' or 'F')
 * @return array|null Drive information array or null if failed
 */
function getDriveInfo($devicePath, $diskAssignments, $config, $tempUnit = 'C') {
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

    // Format device name and identification using Unraid's logic
    $formattedDeviceName = formatDeviceName($assignment['display_name']);

    // Use disk ID from disks.ini if available (includes model + serial with underscores)
    // Otherwise fall back to SMART model data
    if (!empty($assignment['disk_id'])) {
        $identification = $assignment['disk_id'];
    } else {
        // Fallback: use processed SMART model with device name
        $processedModel = processDeviceId($smartData['model']);
        $identification = $processedModel . ' (' . $deviceName . ')';
    }

    return [
        'device_name' => $formattedDeviceName,
        'device_path' => $devicePath,
        'device_id' => $deviceName,
        'identification' => $identification,
        'model' => $smartData['model'],
        'serial' => $smartData['serial'],
        'size_bytes' => $size,
        'size_human' => formatBytes($size),
        'array_name' => $assignment['array_name'],
        'drive_type' => $assignment['drive_type'],
        'power_on_hours' => $smartData['power_on_hours'],
        'power_on_human' => formatPowerOnHours($smartData['power_on_hours']),
        'temperature' => $smartData['temperature'],
        'temperature_formatted' => formatTemperature($smartData['temperature'], $tempUnit),
        'smart_status' => $smartData['smart_status'],
        'smart_status_formatted' => formatSmartStatus($smartData['smart_status']),
        'spin_status' => $smartData['spin_status'],
        'spin_status_formatted' => formatSpinStatus($smartData['spin_status']),
        'age_category' => $ageCategory,
        'color_class' => getAgeColorClass($ageCategory),
        'age_label' => getAgeLabel($ageCategory, $config),
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
 * Used to parse Unraid's cached SMART data from /var/local/emhttp/smart/
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

    // Determine spin status from power state line
    // Example: "Power mode is:    STANDBY" or "Power mode is:    ACTIVE"
    $fullOutput = implode("\n", $lines);
    if (stripos($fullOutput, 'STANDBY') !== false || stripos($fullOutput, 'SLEEP') !== false) {
        $smartData['spin_status'] = 'standby';
    } elseif (stripos($fullOutput, 'ACTIVE') !== false || stripos($fullOutput, 'IDLE') !== false) {
        $smartData['spin_status'] = 'active';
    }

    // Return null if we couldn't get critical data
    return $smartData['power_on_hours'] !== null ? $smartData : null;
}

/**
 * Get SMART data for a drive
 *
 * Primary: Reads from Unraid's SMART cache (fast, no spinup risk)
 * Fallback: Queries drive directly if cache unavailable (first run, cache not ready)
 *
 * @param string $devicePath Device path (e.g., /dev/sda)
 * @return array|null SMART data or null if failed
 */
function getSmartData($devicePath) {
    $deviceName = basename($devicePath);

    // Primary: Try to read from Unraid's cached SMART data
    // This cache is updated by emhttpd every 30 seconds
    $cachedData = getSmartDataFromUnraidCache($deviceName);

    if ($cachedData !== null) {
        return $cachedData;
    }

    // Fallback: If Unraid's cache doesn't exist, query drive directly
    // This can happen on first load before emhttpd has populated cache
    // Use smartctl with -n standby to avoid spinning up sleeping drives
    error_log("DriveAge: No SMART cache found for $deviceName, querying drive directly");

    // Check if drive is in standby first
    exec("smartctl -n standby " . escapeshellarg($devicePath) . " 2>&1", $output, $exitCode);
    if ($exitCode === 2) {
        // Drive is in STANDBY/SLEEP - don't spin it up
        error_log("DriveAge: Drive $deviceName is in standby, skipping");
        return null;
    }

    // Drive is active or cache check failed - safe to query
    // Try JSON output first (faster, more reliable)
    $jsonOutput = shell_exec("smartctl -a -j " . escapeshellarg($devicePath) . " 2>/dev/null");

    if ($jsonOutput) {
        $data = json_decode($jsonOutput, true);
        if ($data) {
            return parseSmartctlJsonOutput($data);
        }
    }

    // Fallback to text parsing
    $textOutput = shell_exec("smartctl -a " . escapeshellarg($devicePath) . " 2>/dev/null");
    if ($textOutput) {
        return parseSmartctlOutput($textOutput);
    }

    return null;
}

/**
 * Parse smartctl JSON output
 *
 * @param array $data Parsed JSON data from smartctl -j
 * @return array|null SMART data or null if failed
 */
function parseSmartctlJsonOutput($data) {
    $smartData = [
        'model' => $data['model_name'] ?? $data['model_family'] ?? 'Unknown',
        'serial' => $data['serial_number'] ?? 'Unknown',
        'smart_status' => ($data['smart_status']['passed'] ?? false) ? 'PASSED' : 'FAILED',
        'temperature' => null,
        'power_on_hours' => null,
        'spin_status' => 'active' // If we got here, drive is active
    ];

    // Get temperature
    if (isset($data['temperature']['current'])) {
        $smartData['temperature'] = $data['temperature']['current'];
    }

    // Get power-on hours (ATA drives)
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

    // Get power-on hours (NVMe drives)
    if (isset($data['nvme_smart_health_information_log'])) {
        $nvmeData = $data['nvme_smart_health_information_log'];
        $smartData['temperature'] = $nvmeData['temperature'] ?? $smartData['temperature'];
        $smartData['power_on_hours'] = isset($nvmeData['power_on_hours'])
            ? floor($nvmeData['power_on_hours'])
            : $smartData['power_on_hours'];
    }

    return $smartData['power_on_hours'] !== null ? $smartData : null;
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
    // First try to get size from disks.ini sectors × sector_size
    if (!empty($diskAssignments)) {
        foreach ($diskAssignments as $diskName => $diskInfo) {
            if (isset($diskInfo['device']) && $diskInfo['device'] === $deviceName) {
                // Calculate actual drive size: sectors × sector_size
                // This matches how Unraid calculates drive capacity
                if (isset($diskInfo['sectors']) && isset($diskInfo['sector_size'])) {
                    $sectors = intval($diskInfo['sectors']);
                    $sectorSize = intval($diskInfo['sector_size']);
                    if ($sectors > 0 && $sectorSize > 0) {
                        return $sectors * $sectorSize;
                    }
                }
                break;
            }
        }
    }

    // Fallback: Use blockdev to get the raw device size
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
        'drive_type' => 'unassigned',
        'disk_id' => null  // Unraid's disk identifier (model_serial)
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

            // Get disk ID if available (e.g., "WDC_WD140EDGZ-11B1PA0_Y5KVGN8C")
            if (isset($diskInfo['id'])) {
                $assignment['disk_id'] = $diskInfo['id'];
            }

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

