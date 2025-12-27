<?php
/**
 * DriveAge Plugin - SMART Data Collection
 *
 * Handles discovery and querying of drive SMART data
 */

require_once 'formatting.php';
require_once 'config.php';
require_once 'helpers.php';
require_once 'cache.php';
require_once 'predictions.php';

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

    // Mark high risk drives for bold formatting
    foreach ($drives as &$drive) {
        $drive['is_oldest'] = ($drive['age_category'] === 'high_risk');
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

    // Get SMART data (may be null if drive in standby or SMART unavailable)
    $smartData = getSmartData($devicePath);

    // Get Unraid assignment info
    $assignment = getUnraidAssignment($deviceName, $diskAssignments);

    // Get drive size (preferably from Unraid's disks.ini for accuracy)
    $size = getDriveSize($devicePath, $deviceName, $diskAssignments);

    // If no SMART data and no size, skip this device
    if (!$smartData && $size == 0) {
        return null;
    }

    // For USB/Flash drives without SMART data, use N/A values instead of defaults
    $isFlashDrive = ($assignment['drive_type'] === 'flash');
    $hasSmartData = ($smartData !== null && isset($smartData['power_on_hours']));

    // Use SMART data if available, otherwise use appropriate defaults
    $model = $smartData['model'] ?? 'Unknown';
    $serial = $smartData['serial'] ?? 'Unknown';

    // USB drives without SMART: use null for power_on_hours (displays as "N/A")
    // Other drives without SMART: use 0 (may have been unable to read temporarily)
    if (!$hasSmartData && $isFlashDrive) {
        $powerOnHours = null;
        $temperature = null;
        $smartStatus = 'N/A';
        $spinStatus = 'N/A';
    } else {
        $powerOnHours = $smartData['power_on_hours'] ?? 0;
        $temperature = $smartData['temperature'] ?? null;
        $smartStatus = $smartData['smart_status'] ?? 'UNKNOWN';
        $spinStatus = $smartData['spin_status'] ?? 'unknown';
    }

    // Standby state and cache metadata
    $isStandby = $smartData['is_standby'] ?? false;
    $cacheTimestamp = $smartData['cache_timestamp'] ?? null;
    $cacheAge = $cacheTimestamp ? (time() - $cacheTimestamp) : 0;
    $isStale = $isStandby && $cacheTimestamp && isCacheStale($cacheTimestamp);

    // Detect physical drive type (HDD vs NVMe vs USB) from device path and assignment
    // Check assignment first for Flash type (USB boot drive)
    if ($assignment['drive_type'] === 'flash') {
        $physicalType = 'usb';
    } elseif (strpos($devicePath, 'nvme') !== false) {
        $physicalType = 'nvme';
    } else {
        $physicalType = 'hdd';
    }

    // Build temporary array with data needed for risk assessment
    $tempDriveInfo = array_merge(
        ['physical_type' => $physicalType],
        $smartData ?? []
    );

    // Determine age category based on drive type
    // NVMe: Use wear-based risk assessment
    // HDD/USB: Use age-based risk assessment
    if ($physicalType === 'nvme') {
        $nvmeRiskCategory = getNvmeRiskCategory($tempDriveInfo);
        $ageCategory = $nvmeRiskCategory ?? getAgeCategory($powerOnHours, $config);
    } else {
        $ageCategory = getAgeCategory($powerOnHours, $config);
    }

    // Get health warnings for HDDs
    $healthWarnings = [];
    if ($physicalType === 'hdd' && $smartData) {
        $healthWarnings = getHddHealthWarnings($tempDriveInfo);
    }

    // Check for NVMe critical conditions
    if ($physicalType === 'nvme' && $smartData) {
        if (($smartData['nvme_media_errors'] ?? 0) > 0) {
            $healthWarnings[] = [
                'level' => 'critical',
                'attribute' => 'media_errors',
                'value' => $smartData['nvme_media_errors'],
                'message' => 'Media errors detected',
                'action' => 'Replace ASAP',
                'tooltip' => 'Uncorrectable data errors. Drive reliability compromised.'
            ];
        }

        if (($smartData['nvme_critical_warning'] ?? 0) > 0) {
            $healthWarnings[] = [
                'level' => 'critical',
                'attribute' => 'critical_warning',
                'value' => $smartData['nvme_critical_warning'],
                'message' => 'Critical warning active',
                'action' => 'Check drive immediately',
                'tooltip' => 'Drive has raised a critical warning flag.'
            ];
        }
    }

    // Format device name and identification using Unraid's logic
    $formattedDeviceName = formatDeviceName($assignment['display_name']);

    // Use disk ID from disks.ini if available (includes model + serial with underscores)
    // Otherwise fall back to SMART model data
    if (!empty($assignment['disk_id'])) {
        $identification = $assignment['disk_id'];
    } else {
        // Fallback: use processed SMART model with device name
        $processedModel = processDeviceId($model);
        $identification = $processedModel . ' (' . $deviceName . ')';
    }

    // Build complete drive information array
    $driveInfo = [
        'device_name' => $formattedDeviceName,
        'device_path' => $devicePath,
        'device_id' => $deviceName,
        'identification' => $identification,
        'model' => $model,
        'serial' => $serial,
        'size_bytes' => $size,
        'size_human' => formatBytes($size),
        'array_name' => $assignment['array_name'],
        'drive_type' => $assignment['drive_type'],
        'physical_type' => $physicalType,
        'power_on_hours' => $powerOnHours,
        'power_on_human' => formatPowerOnHours($powerOnHours),
        'temperature' => $temperature,
        'temperature_formatted' => formatTemperature($temperature, $tempUnit),
        'temperature_class' => getTemperatureClass($temperature, $physicalType),
        'smart_status' => $smartStatus,
        'smart_status_formatted' => formatSmartStatus($smartStatus),
        'spin_status' => $spinStatus,
        'spin_status_formatted' => formatSpinStatus($spinStatus),
        'age_category' => $ageCategory,
        'color_class' => getAgeColorClass($ageCategory),
        'age_label' => getAgeLabel($ageCategory, $config),
        'is_oldest' => false, // Will be set later
        'is_standby' => $isStandby,
        'cache_age' => $cacheAge,
        'is_stale' => $isStale,
        // Health warnings array
        'health_warnings' => $healthWarnings,
        'has_warnings' => count($healthWarnings) > 0,
        // NVMe health metrics (null for non-NVMe drives)
        'nvme_percentage_used' => $smartData['nvme_percentage_used'] ?? null,
        'nvme_available_spare' => $smartData['nvme_available_spare'] ?? null,
        'nvme_available_spare_threshold' => $smartData['nvme_available_spare_threshold'] ?? null,
        'nvme_data_units_written' => $smartData['nvme_data_units_written'] ?? null,
        'nvme_data_units_read' => $smartData['nvme_data_units_read'] ?? null,
        'nvme_media_errors' => $smartData['nvme_media_errors'] ?? null,
        'nvme_critical_warning' => $smartData['nvme_critical_warning'] ?? null,
        'nvme_tbw_calculated' => $smartData['nvme_tbw_calculated'] ?? null,
        // HDD critical SMART attributes (null for non-HDD drives)
        'hdd_reallocated_sectors' => $smartData['hdd_reallocated_sectors'] ?? null,
        'hdd_pending_sectors' => $smartData['hdd_pending_sectors'] ?? null,
        'hdd_uncorrectable_sectors' => $smartData['hdd_uncorrectable_sectors'] ?? null,
        'hdd_reported_uncorrectable' => $smartData['hdd_reported_uncorrectable'] ?? null,
        'hdd_command_timeout' => $smartData['hdd_command_timeout'] ?? null
    ];

    // Calculate predictive replacement estimate
    $prediction = getPredictiveReplacement($driveInfo, $config);

    // Add prediction data to drive info
    $driveInfo['replacement_prediction'] = $prediction;

    // CRITICAL: Override age category to high_risk if replacement estimate is < 6 months
    // This ensures drives with imminent failure warnings show as red regardless of age
    if (isset($prediction['months_remaining']) &&
        $prediction['months_remaining'] !== null &&
        $prediction['months_remaining'] < 6) {
        $driveInfo['age_category'] = 'high_risk';
        $driveInfo['color_class'] = getAgeColorClass('high_risk');
    }

    return $driveInfo;
}

/**
 * Detect HDD pre-failure conditions based on critical SMART attributes
 *
 * Analyzes reallocated sectors, pending sectors, and uncorrectable errors
 * to provide early warning of drive failure
 *
 * @param array $driveInfo Drive information array with HDD SMART attributes
 * @return array Health warnings [['level' => string, 'attribute' => string, 'value' => int, 'message' => string, 'action' => string, 'tooltip' => string]]
 */
function getHddHealthWarnings($driveInfo) {
    $warnings = [];

    // Only check if this is an HDD
    if ($driveInfo['physical_type'] !== 'hdd') {
        return $warnings;
    }

    $reallocated = $driveInfo['hdd_reallocated_sectors'] ?? 0;
    $pending = $driveInfo['hdd_pending_sectors'] ?? 0;
    $uncorrectable = $driveInfo['hdd_uncorrectable_sectors'] ?? 0;

    // CRITICAL: Pending sectors = imminent reallocation
    if ($pending > 0) {
        $warnings[] = [
            'level' => 'critical',
            'attribute' => 'pending_sectors',
            'value' => $pending,
            'message' => $pending . ' pending sector' . ($pending > 1 ? 's' : ''),
            'action' => 'Replace ASAP',
            'tooltip' => 'Pending sectors are waiting to be reallocated. Drive is actively failing.'
        ];
    }

    // CRITICAL: Uncorrectable sectors = data loss risk
    if ($uncorrectable > 0) {
        $warnings[] = [
            'level' => 'critical',
            'attribute' => 'uncorrectable_sectors',
            'value' => $uncorrectable,
            'message' => $uncorrectable . ' uncorrectable error' . ($uncorrectable > 1 ? 's' : ''),
            'action' => 'Replace immediately',
            'tooltip' => 'Uncorrectable errors indicate permanent data loss. Replace drive now.'
        ];
    }

    // WARNING/CRITICAL: Reallocated sectors = physical damage
    if ($reallocated > 0) {
        $severity = $reallocated > 10 ? 'critical' : 'warning';
        $action = $reallocated > 10 ? 'Replace immediately' : 'Backup data, monitor closely';

        $warnings[] = [
            'level' => $severity,
            'attribute' => 'reallocated_sectors',
            'value' => $reallocated,
            'message' => $reallocated . ' reallocated sector' . ($reallocated > 1 ? 's' : ''),
            'action' => $action,
            'tooltip' => 'Reallocated sectors indicate physical damage. Drive may fail soon.'
        ];
    }

    return $warnings;
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

    // Check if cache file indicates drive is in standby
    // smartctl -n standby outputs "Device is in STANDBY mode" if drive is sleeping
    // Return special array indicating standby state (will be filled with cached data)
    if (stripos($output, 'STANDBY') !== false || stripos($output, 'SLEEP') !== false) {
        return [
            'model' => 'Unknown',
            'serial' => 'Unknown',
            'smart_status' => 'UNKNOWN',
            'temperature' => null,
            'power_on_hours' => null, // Will be filled from cache
            'spin_status' => 'standby',
            'is_standby' => true
        ];
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

    // Parse HDD-specific critical SMART attributes (text mode)
    // Check if this is an HDD (has ATA attributes)
    if (stripos($output, 'ATA') !== false || stripos($output, 'SATA') !== false) {
        $hddAttrs = parseHddSmartAttributes(null, $output);
        if (!empty($hddAttrs)) {
            $smartData = array_merge($smartData, $hddAttrs);
        }
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
        // If drive is active and has valid data, save to our persistent cache
        if (isset($cachedData['power_on_hours']) &&
            $cachedData['power_on_hours'] > 0 &&
            (!isset($cachedData['is_standby']) || !$cachedData['is_standby'])) {

            $model = $cachedData['model'] ?? 'Unknown';
            $serial = $cachedData['serial'] ?? 'Unknown';
            saveDriveCache($deviceName, $cachedData['power_on_hours'], $model, $serial);
        }

        // If drive is in standby, load cached power-on hours
        if (isset($cachedData['is_standby']) && $cachedData['is_standby']) {
            $cache = loadDriveCache($deviceName);
            if ($cache) {
                $cachedData['power_on_hours'] = $cache['power_on_hours'];
                $cachedData['cache_timestamp'] = $cache['timestamp'];
                $cachedData['model'] = $cache['model'];
                $cachedData['serial'] = $cache['serial'];
            }
        }

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

    // Parse NVMe-specific health attributes
    $nvmeAttrs = parseNvmeSmartAttributes($data);
    if (!empty($nvmeAttrs)) {
        $smartData = array_merge($smartData, $nvmeAttrs);
    }

    // Parse HDD-specific critical SMART attributes
    $hddAttrs = parseHddSmartAttributes($data, null);
    if (!empty($hddAttrs)) {
        $smartData = array_merge($smartData, $hddAttrs);
    }

    return $smartData['power_on_hours'] !== null ? $smartData : null;
}

/**
 * Parse NVMe-specific SMART attributes from smartctl JSON
 *
 * Extracts health metrics for wear-based risk assessment
 *
 * @param array $data Parsed JSON data from smartctl -j
 * @return array NVMe health metrics or empty array if not NVMe
 */
function parseNvmeSmartAttributes($data) {
    $nvme = [];

    // Check if this is an NVMe drive
    if (!isset($data['nvme_smart_health_information_log'])) {
        return $nvme;
    }

    $health = $data['nvme_smart_health_information_log'];

    // Extract percentage used (0-255, can exceed 100%)
    $nvme['nvme_percentage_used'] = $health['percentage_used'] ?? null;

    // Extract available spare (0-100%)
    $nvme['nvme_available_spare'] = $health['available_spare'] ?? null;

    // Extract available spare threshold (0-100%)
    $nvme['nvme_available_spare_threshold'] = $health['available_spare_threshold'] ?? 10;

    // Extract data units written (512-byte units × 1000)
    $nvme['nvme_data_units_written'] = $health['data_units_written'] ?? null;

    // Extract data units read (512-byte units × 1000)
    $nvme['nvme_data_units_read'] = $health['data_units_read'] ?? null;

    // Extract media errors (uncorrectable errors)
    $nvme['nvme_media_errors'] = $health['media_errors'] ?? 0;

    // Extract critical warning bitmap
    $nvme['nvme_critical_warning'] = $health['critical_warning'] ?? 0;

    // Calculate actual TBW from data units written
    // Formula: data_units × 512 bytes × 1000 ÷ 1,000,000,000,000
    if ($nvme['nvme_data_units_written'] !== null && $nvme['nvme_data_units_written'] > 0) {
        $bytes = $nvme['nvme_data_units_written'] * 512000;
        $nvme['nvme_tbw_calculated'] = round($bytes / 1000000000000, 2);
    } else {
        $nvme['nvme_tbw_calculated'] = null;
    }

    return $nvme;
}

/**
 * Parse HDD-specific critical SMART attributes
 *
 * Extracts attributes that predict imminent drive failure
 *
 * @param array $data Parsed JSON data from smartctl -j (null if text mode)
 * @param string $textOutput Raw smartctl text output (null if JSON mode)
 * @return array HDD critical attributes or empty array if not HDD
 */
function parseHddSmartAttributes($data = null, $textOutput = null) {
    $hdd = [
        'hdd_reallocated_sectors' => null,
        'hdd_pending_sectors' => null,
        'hdd_uncorrectable_sectors' => null,
        'hdd_reported_uncorrectable' => null,
        'hdd_command_timeout' => null
    ];

    // Try JSON first (more reliable)
    if ($data !== null && isset($data['ata_smart_attributes']['table'])) {
        foreach ($data['ata_smart_attributes']['table'] as $attr) {
            switch ($attr['id']) {
                case 5:  // Reallocated_Sector_Ct
                    $hdd['hdd_reallocated_sectors'] = $attr['raw']['value'] ?? 0;
                    break;
                case 197: // Current_Pending_Sector
                    $hdd['hdd_pending_sectors'] = $attr['raw']['value'] ?? 0;
                    break;
                case 198: // Offline_Uncorrectable
                    $hdd['hdd_uncorrectable_sectors'] = $attr['raw']['value'] ?? 0;
                    break;
                case 187: // Reported_Uncorrect
                    $hdd['hdd_reported_uncorrectable'] = $attr['raw']['value'] ?? 0;
                    break;
                case 188: // Command_Timeout
                    $hdd['hdd_command_timeout'] = $attr['raw']['value'] ?? 0;
                    break;
            }
        }
        return $hdd;
    }

    // Fallback: parse text output
    if ($textOutput !== null) {
        // Format: ID# ATTRIBUTE_NAME FLAG VALUE WORST THRESH TYPE UPDATED WHEN_FAILED RAW_VALUE
        $lines = explode("\n", $textOutput);
        foreach ($lines as $line) {
            // Match SMART attribute lines
            if (preg_match('/^\s*(\d+)\s+\S+.*\s+(\d+)\s*$/', $line, $matches)) {
                $attrId = intval($matches[1]);
                $rawValue = intval($matches[2]);

                switch ($attrId) {
                    case 5:
                        $hdd['hdd_reallocated_sectors'] = $rawValue;
                        break;
                    case 197:
                        $hdd['hdd_pending_sectors'] = $rawValue;
                        break;
                    case 198:
                        $hdd['hdd_uncorrectable_sectors'] = $rawValue;
                        break;
                    case 187:
                        $hdd['hdd_reported_uncorrectable'] = $rawValue;
                        break;
                    case 188:
                        $hdd['hdd_command_timeout'] = $rawValue;
                        break;
                }
            }
        }
    }

    return $hdd;
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
                    // USB boot drive
                    $assignment['display_name'] = 'Flash (USB Boot)';
                    $assignment['array_name'] = 'USB Boot';
                    $assignment['drive_type'] = 'flash';
                    break;

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

