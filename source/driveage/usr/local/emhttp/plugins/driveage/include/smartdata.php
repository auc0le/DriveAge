<?php
/**
 * DriveAge Plugin - Drive Enumeration & Data Assembly
 *
 * Sources, in order of authority:
 *  1. /var/local/emhttp/disks.ini  - assigned drives: names, ids, sizes,
 *     temperatures, spin state (Unraid's own live state)
 *  2. /var/local/emhttp/devs.ini   - unassigned devices
 *  3. /var/local/emhttp/smart/<name>     - cached smartctl attribute output,
 *     refreshed by Unraid on the Disk Settings "poll_attributes" interval
 *     (default 1800s = 30 minutes) - NOT every 30 seconds. <name> is the
 *     Unraid disk name (parity, disk1, cache, dev1...), NOT the kernel
 *     device; the device name is only tried as a fallback
 *  4. /var/local/emhttp/smart/<name>.ssa - cached overall SMART health
 *  5. Direct `smartctl -a -j` - LAST resort, only when no cache file exists
 *     and the drive is not spun down
 *
 * Enumerating from Unraid's ini files (not /dev globs) supports >26 SATA
 * devices (sdaa+), multi-digit NVMe names, and keeps temperatures / spin
 * state consistent with the Main page.
 */

require_once __DIR__ . '/formatting.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/risk.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/smartparse.php';
require_once __DIR__ . '/predictions.php';

// Unraid state directory (guarded define so tests can point at fixtures)
if (!defined('DRIVEAGE_STATE_DIR')) {
    define('DRIVEAGE_STATE_DIR', '/var/local/emhttp');
}

// RAM-backed scratch space for direct smartctl results (/tmp is tmpfs on
// Unraid - never the flash device)
if (!defined('DRIVEAGE_TMP_DIR')) {
    define('DRIVEAGE_TMP_DIR', '/tmp/driveage');
}

/**
 * Validate a device NAME (not path) against the expected patterns.
 * Supports sda..sdzzz and multi-digit NVMe controllers/namespaces.
 */
function isValidDeviceName($name) {
    return is_string($name) && preg_match('/^(sd[a-z]{1,3}|nvme\d+n\d+)$/', $name) === 1;
}

/**
 * Get Unraid's SMART attribute poll interval (seconds).
 * This is how often the smart/ cache files are refreshed at most.
 */
function getPollInterval() {
    static $interval = null; // constant for the request; read var.ini once
    if ($interval !== null) {
        return $interval;
    }
    $interval = 1800; // Unraid default: 30 minutes
    $varFile = DRIVEAGE_STATE_DIR . '/var.ini';
    if (file_exists($varFile)) {
        $vars = @parse_ini_file($varFile) ?: [];
        if (isset($vars['poll_attributes']) && is_numeric($vars['poll_attributes']) && intval($vars['poll_attributes']) > 0) {
            $interval = intval($vars['poll_attributes']);
        }
    }
    return $interval;
}

/**
 * smartctl target for a device, honouring Unraid's per-disk SMART settings
 * (Disk Settings -> "SMART controller type"): smType holds a "-d <type>"
 * option, smPort1..4 + smGlue the controller ports (megaraid,N / areca,N/E),
 * smDevice an alternate device node. Mirrors dynamix's get_ctlr_options().
 * Every piece is whitelisted and shell-escaped.
 *
 * @param string $deviceName Validated device name
 * @param array $ini Ini entry for this device
 * @return array [string $typeArgs (leading space or ''), string $devicePath]
 */
function getSmartctlTarget($deviceName, $ini) {
    $typeArgs = '';
    $smType = trim((string)($ini['smType'] ?? ''));
    if ($smType !== '' && preg_match('/^(?:-d\s+)?([A-Za-z0-9_+\-]+)$/', $smType, $m)) {
        $type = $m[1];
        $ports = [];
        foreach (['smPort1', 'smPort2', 'smPort3', 'smPort4'] as $key) {
            $port = trim((string)($ini[$key] ?? ''));
            if ($port !== '' && preg_match('/^[A-Za-z0-9]+$/', $port)) {
                $ports[] = $port;
            }
        }
        $glue = in_array($ini['smGlue'] ?? ',', [',', '/'], true) ? ($ini['smGlue'] ?? ',') : ',';
        if ($ports) {
            $type .= ',' . implode($glue, $ports);
        }
        $typeArgs = ' -d ' . escapeshellarg($type);
    }

    $device = $deviceName;
    $smDevice = trim((string)($ini['smDevice'] ?? ''));
    if ($smDevice !== '' && preg_match('/^(sd[a-z]{1,3}|sg\d+|nvme\d+(n\d+)?)$/', $smDevice)) {
        $device = $smDevice;
    }
    return [$typeArgs, '/dev/' . $device];
}

/**
 * Enumerate drives from Unraid's state files.
 *
 * @return array Map of deviceName => ['ini' => array, 'source' => 'disks'|'devs']
 */
function enumerateDrives() {
    $found = [];

    // Assigned drives (array, pools, flash)
    $disksIni = DRIVEAGE_STATE_DIR . '/disks.ini';
    if (file_exists($disksIni)) {
        $disks = @parse_ini_file($disksIni, true) ?: [];
        foreach ($disks as $slotName => $info) {
            $dev = $info['device'] ?? '';
            if ($dev === '' || !isValidDeviceName($dev)) {
                continue; // empty slot or unexpected name
            }
            $info['_slot'] = $slotName;
            $found[$dev] = ['ini' => $info, 'source' => 'disks'];
        }
    }

    // Unassigned devices
    $devsIni = DRIVEAGE_STATE_DIR . '/devs.ini';
    if (file_exists($devsIni)) {
        $devs = @parse_ini_file($devsIni, true) ?: [];
        foreach ($devs as $slotName => $info) {
            $dev = $info['device'] ?? '';
            if ($dev === '' || !isValidDeviceName($dev) || isset($found[$dev])) {
                continue;
            }
            $info['_slot'] = $slotName;
            $found[$dev] = ['ini' => $info, 'source' => 'devs'];
        }
    }

    // Safety net: if Unraid state is unavailable (should not happen on a
    // running system), fall back to /dev discovery incl. sdaa+ and nvme10+
    if (empty($found)) {
        foreach (array_merge(glob('/dev/sd?') ?: [], glob('/dev/sd??') ?: [], glob('/dev/nvme*n*') ?: []) as $path) {
            $dev = basename($path);
            if (isValidDeviceName($dev) && @filetype($path) === 'block') {
                $found[$dev] = ['ini' => ['device' => $dev, '_slot' => $dev], 'source' => 'glob'];
            }
        }
    }

    return $found;
}

/**
 * Get all drives with their SMART data.
 *
 * @param array $config Plugin configuration
 * @return array Array of drive information
 */
function getAllDrives($config) {
    $drives = [];

    $tempUnit = getTemperatureUnit();
    $pollInterval = getPollInterval();
    $pohCache = loadPohCache();
    $pohUpdates = [];

    foreach (enumerateDrives() as $deviceName => $entry) {
        $driveInfo = getDriveInfo($deviceName, $entry, $config, $tempUnit, $pohCache);
        if ($driveInfo) {
            // Collect fresh POH readings for the write-on-change cache
            if (!$driveInfo['is_standby'] && $driveInfo['power_on_hours'] !== null && $driveInfo['power_on_hours'] > 0) {
                $pohUpdates[getDriveCacheKey($deviceName, $entry['ini'])] = [
                    'power_on_hours' => $driveInfo['power_on_hours'],
                    'model' => $driveInfo['model'],
                    'serial' => $driveInfo['serial']
                ];
            }
            $driveInfo['poll_interval'] = $pollInterval;
            $drives[] = $driveInfo;
        }
    }

    // Single write, and only if a value actually changed
    updatePohCache($pohCache, $pohUpdates);

    // Sort by power-on hours (descending - oldest first); nulls sink to end
    usort($drives, function($a, $b) {
        return intval($b['power_on_hours'] ?? -1) <=> intval($a['power_on_hours'] ?? -1);
    });

    return $drives;
}

/**
 * Candidate basenames for a device's files in the smart/ cache directory,
 * most authoritative first.
 *
 * emhttpd names the cache after the Unraid disk NAME (parity, disk1, cache,
 * cache2, dev1...) - dynamix's own monitor script reads smart/$disk['name'].
 * The kernel device name (sdb, nvme0n1) is kept as a last fallback.
 *
 * @param string $deviceName Device name (e.g. sdb)
 * @param array $ini Ini entry for this device
 * @return array Unique, path-safe basenames
 */
function getSmartCacheNames($deviceName, $ini) {
    $names = [];
    foreach ([$ini['name'] ?? null, $ini['_slot'] ?? null, $deviceName] as $candidate) {
        if (!is_string($candidate)) {
            continue;
        }
        $candidate = basename($candidate);
        if ($candidate === '' || $candidate === '.' || $candidate === '..' || in_array($candidate, $names, true)) {
            continue;
        }
        $names[] = $candidate;
    }
    return $names;
}

/**
 * Read normalized SMART data for a device.
 *
 * @param string $deviceName Device name (e.g. sda, nvme0n1)
 * @param bool $iniStandby True if Unraid reports the drive spun down
 * @param array|null $cacheNames Cache basenames to try, in order (see
 *                               getSmartCacheNames()); defaults to the device name
 * @param array $ini Ini entry (SMART controller settings for direct queries)
 * @return array ['data' => array|null, 'age' => int|null] data + data age (s)
 */
function readSmartData($deviceName, $iniStandby, $cacheNames = null, $ini = []) {
    // Primary: Unraid's cached smartctl output
    foreach ($cacheNames ?? [$deviceName] as $cacheName) {
        $smartFile = DRIVEAGE_STATE_DIR . '/smart/' . $cacheName;
        if (!file_exists($smartFile)) {
            continue;
        }
        $parsed = driveage_parse_smart_text(@file_get_contents($smartFile));
        if ($parsed !== null) {
            $mtime = @filemtime($smartFile);
            return ['data' => $parsed, 'age' => $mtime ? max(0, time() - $mtime) : null];
        }
    }

    // No cache file. Never touch a spun-down drive.
    if ($iniStandby) {
        return ['data' => driveage_standby_smart_data(), 'age' => null];
    }

    // Last resort: query the drive directly (e.g. first run before Unraid's
    // cache exists, or a device Unraid does not poll)
    if (!isValidDeviceName($deviceName) || !file_exists('/dev/' . $deviceName)) {
        return ['data' => null, 'age' => null];
    }

    // A recent direct result (or failure - e.g. a USB stick smartctl cannot
    // read) is reused for one poll interval, so the dashboard never queries
    // a drive more often than Unraid itself does
    $memoFile = DRIVEAGE_TMP_DIR . '/smart-' . $deviceName . '.json';
    $driveId = (string)($ini['id'] ?? '');
    $memo = @json_decode((string)@file_get_contents($memoFile), true);
    if (is_array($memo) && ($memo['id'] ?? null) === $driveId && array_key_exists('data', $memo)
        && time() - intval($memo['time'] ?? 0) < getPollInterval()) {
        $data = is_array($memo['data']) ? $memo['data'] : null;
        return ['data' => $data, 'age' => $data === null ? null : max(0, time() - intval($memo['time']))];
    }

    $result = queryDriveDirectly($deviceName, $ini);
    // Standby is not remembered: data should appear as soon as the drive wakes
    if (($result === null || !$result['is_standby'])
        && (is_dir(DRIVEAGE_TMP_DIR) || @mkdir(DRIVEAGE_TMP_DIR, 0700, true))) {
        @file_put_contents($memoFile, json_encode(['id' => $driveId, 'time' => time(), 'data' => $result]), LOCK_EX);
    }
    return ['data' => $result, 'age' => $result === null ? null : 0];
}

/**
 * Normalized data for a drive known to be spun down.
 */
function driveage_standby_smart_data() {
    $standby = driveage_empty_smart_data();
    $standby['is_standby'] = true;
    $standby['spin_status'] = 'standby';
    return $standby;
}

/**
 * Query a drive with smartctl. ONE call: "-n standby" makes smartctl exit
 * (status bit 1) instead of waking a sleeping drive, with no gap between a
 * separate standby check and the query.
 *
 * @param string $deviceName Validated device name
 * @param array $ini Ini entry (SMART controller type / device overrides)
 * @return array|null Normalized data (standby stub if asleep), null on failure
 */
function queryDriveDirectly($deviceName, $ini) {
    [$typeArgs, $devicePath] = getSmartctlTarget($deviceName, $ini);
    $target = $typeArgs . ' ' . escapeshellarg($devicePath);

    $lines = [];
    exec('smartctl -n standby -a -j' . $target . ' 2>/dev/null', $lines, $exitCode);
    $output = implode("\n", $lines);

    // Exit status bit 1: device open failed OR in a low-power mode
    if ($exitCode & 2) {
        return preg_match('/STANDBY|SLEEP/i', $output) ? driveage_standby_smart_data() : null;
    }

    $decoded = json_decode($output, true);
    if (is_array($decoded)) {
        return driveage_parse_smart_json($decoded);
    }

    // smartctl too old for JSON output (-j arrived in 7.0): plain text
    $text = shell_exec('smartctl -n standby -a' . $target . ' 2>/dev/null');
    return $text ? driveage_parse_smart_text($text) : null;
}

/**
 * Read Unraid's cached overall SMART health for a device (.ssa file).
 *
 * @param array $cacheNames Cache basenames to try, in order (see getSmartCacheNames())
 * @return string|null 'PASSED', 'FAILED' or null if unavailable
 */
function readSsaHealth($cacheNames) {
    foreach ($cacheNames as $base) {
        $file = DRIVEAGE_STATE_DIR . '/smart/' . $base . '.ssa';
        if (!file_exists($file)) {
            continue;
        }
        $content = strtoupper(trim(@file_get_contents($file) ?: ''));
        if ($content === '') {
            continue;
        }
        // Content may be a full word (PASSED/FAILED/OK) or a single letter
        if (strpos($content, 'PASS') !== false || $content === 'OK' || $content === 'P') {
            return 'PASSED';
        }
        if (strpos($content, 'FAIL') !== false || $content === 'F') {
            return 'FAILED';
        }
    }
    return null;
}

/**
 * Build complete information for one drive.
 *
 * @param string $deviceName Device name (e.g. sda)
 * @param array $entry Enumeration entry ['ini' => .., 'source' => ..]
 * @param array $config Plugin configuration
 * @param string $tempUnit Temperature unit ('C' or 'F')
 * @param array $pohCache Persistent POH cache map
 * @return array|null Drive information array or null if unusable
 */
function getDriveInfo($deviceName, $entry, $config, $tempUnit, $pohCache) {
    $ini = $entry['ini'];
    $slotName = $ini['_slot'] ?? $deviceName;

    // Assignment info straight from the ini entry
    $assignment = getUnraidAssignment($slotName, $ini, $entry['source']);

    // Unraid's live state: spin + temperature (consistent with the Main page)
    $iniStandby = (($ini['spundown'] ?? '0') === '1');
    $iniTemp = (isset($ini['temp']) && is_numeric($ini['temp'])) ? intval($ini['temp']) : null;

    // SMART data (cache file -> standby stub -> direct query)
    $cacheNames = getSmartCacheNames($deviceName, $ini);
    $read = readSmartData($deviceName, $iniStandby, $cacheNames, $ini);
    $smartData = $read['data'];
    $dataAge = $read['age'];

    // Size: Unraid's sectors x sector_size when available, blockdev last resort
    $size = getDriveSize($deviceName, $ini);

    if ($smartData === null && $size === 0) {
        return null; // nothing knowable about this device
    }

    $isStandby = $iniStandby || (($smartData['is_standby'] ?? false) === true);

    // Physical type. SATA SSDs: Unraid's rotational flag (what dynamix uses
    // for its SSD temperature limits), else smartctl's rotation rate / an
    // SSD wear attribute
    if ($assignment['drive_type'] === 'flash') {
        $physicalType = 'usb';
    } elseif (strpos($deviceName, 'nvme') === 0) {
        $physicalType = 'nvme';
    } elseif (($ini['rotational'] ?? null) === '0' || !empty($smartData['is_ssd'])) {
        $physicalType = 'ssd';
    } else {
        $physicalType = 'hdd';
    }

    $model  = $smartData['model'] ?? null;
    $serial = $smartData['serial'] ?? null;
    $powerOnHours = $smartData['power_on_hours'] ?? null;
    $temperature = $iniTemp ?? ($smartData['temperature'] ?? null);

    // Overall SMART health: .ssa cache first, then parsed output
    $smartStatus = readSsaHealth($cacheNames)
        ?? (($smartData['smart_status'] ?? 'UNKNOWN') !== 'UNKNOWN' ? $smartData['smart_status'] : 'UNKNOWN');

    // Spin status: Unraid state wins; NVMe never spins
    if ($physicalType === 'nvme') {
        $spinStatus = 'active';
    } elseif ($isStandby) {
        $spinStatus = 'standby';
    } else {
        $spinStatus = $smartData['spin_status'] ?? 'unknown';
        if ($spinStatus === 'unknown' && isset($ini['spundown'])) {
            $spinStatus = 'active';
        }
    }

    // Standby drives: fill POH/model/serial from the persistent cache
    $cacheTimestamp = null;
    if ($isStandby && $powerOnHours === null) {
        $cached = getPohCacheEntry($pohCache, getDriveCacheKey($deviceName, $ini));
        if ($cached !== null) {
            $powerOnHours = intval($cached['power_on_hours']);
            $model = $model ?? ($cached['model'] ?? null);
            $serial = $serial ?? ($cached['serial'] ?? null);
            $cacheTimestamp = intval($cached['timestamp']);
        }
    }
    $isStale = $isStandby && $cacheTimestamp !== null && isCacheStale($cacheTimestamp);

    // USB flash drives without SMART: everything is N/A
    if ($physicalType === 'usb' && $powerOnHours === null) {
        $smartStatus = 'N/A';
        $spinStatus = 'n/a';
    }

    // Merge for risk/health evaluation
    $evalInfo = array_merge($smartData ?? [], [
        'physical_type' => $physicalType,
        'smart_status' => $smartStatus,
        'power_on_hours' => $powerOnHours
    ]);

    // Lifecycle category: wear-based for flash, age-based otherwise. A SATA
    // SSD that reports no wear attribute falls back to its age.
    $wearUsed = null;
    $ageBased = true;
    if ($physicalType === 'nvme') {
        $ageCategory = getNvmeRiskCategory($evalInfo) ?? getAgeCategory($powerOnHours, $config);
        $wearUsed = $smartData['nvme_percentage_used'] ?? null;
        $ageBased = false;
    } elseif ($physicalType === 'ssd' && ($smartData['ssd_percentage_used'] ?? null) !== null) {
        $wearUsed = $smartData['ssd_percentage_used'];
        $ageCategory = getWearRiskCategory($wearUsed, null, null);
        $ageBased = false;
    } else {
        $ageCategory = getAgeCategory($powerOnHours, $config);
    }
    $ageLabel = getLifecycleLabel($ageCategory, $physicalType, $wearUsed, $config, $ageBased);

    // Health warnings
    $healthWarnings = [];
    if (($physicalType === 'hdd' || $physicalType === 'ssd') && $smartData) {
        $healthWarnings = getHddHealthWarnings($evalInfo);
    }
    if ($physicalType === 'nvme' && $smartData) {
        $healthWarnings = array_merge($healthWarnings, getNvmeHealthWarnings($evalInfo));
    }

    // Drive-reported overall FAILURE outranks everything - surface it first
    if (strtoupper($smartStatus) === 'FAILED') {
        array_unshift($healthWarnings, [
            'level' => 'critical',
            'attribute' => 'smart_overall_failed',
            'value' => 1,
            'message' => 'SMART overall-health FAILED',
            'action' => 'Replace immediately',
            'tooltip' => 'The drive reports its own SMART overall-health self-assessment as FAILED. Failure may be imminent.'
        ]);
    }

    // Health (this drive's SMART evidence) and the overall risk shown in the
    // UI. Overall category, class and label are derived together here and
    // nowhere else, so they cannot desync.
    $hasSmartData = !empty($smartData['has_ata_attrs']) || !empty($smartData['has_nvme_log'])
        || ($smartData['power_on_hours'] ?? null) !== null
        || in_array(strtoupper($smartStatus), ['PASSED', 'FAILED'], true);
    $healthStatus = getHealthStatus($smartStatus, $healthWarnings, $hasSmartData);
    $riskCategory = getOverallRiskCategory($ageCategory, $healthStatus);

    // Temperature limits: the same ones Unraid's Main page and notifications use
    [$tempWarning, $tempCritical] = getTemperatureLimits($ini, $physicalType, getDisplaySettings());

    // Identification
    $formattedDeviceName = formatDeviceName($assignment['display_name']);
    if (!empty($assignment['disk_id'])) {
        $identification = $assignment['disk_id'];
    } elseif ($model !== null) {
        $identification = processDeviceId($model) . ' (' . $deviceName . ')';
    } else {
        $identification = $deviceName;
    }

    $driveInfo = [
        'device_name' => $formattedDeviceName,
        'device_path' => '/dev/' . $deviceName,
        'device_id' => $deviceName,
        'identification' => $identification,
        'model' => $model ?? 'Unknown',
        'serial' => $serial ?? 'Unknown',
        'size_bytes' => $size,
        'size_human' => formatBytes($size),
        'array_name' => $assignment['array_name'],
        'drive_type' => $assignment['drive_type'],
        'physical_type' => $physicalType,
        'power_on_hours' => $powerOnHours,
        'power_on_human' => formatPowerOnHours($powerOnHours),
        'temperature' => $temperature,
        'temperature_formatted' => formatTemperature($temperature, $tempUnit),
        'temperature_class' => getTemperatureClass($temperature, $tempWarning, $tempCritical),
        'temperature_limits' => sprintf('Warning above %s, critical above %s (Unraid disk settings)',
            formatTemperature($tempWarning, $tempUnit), formatTemperature($tempCritical, $tempUnit)),
        'smart_status' => $smartStatus,
        'smart_status_class' => getSmartStatusClass($smartStatus),
        'spin_status' => $spinStatus,
        'spin_status_class' => getSpinStatusClass($spinStatus),
        // Lifecycle (age/wear band - never escalated by SMART)
        'age_category' => $ageCategory,
        'age_label' => $ageLabel,
        // Health (SMART evidence)
        'health_status' => $healthStatus,
        'health_class' => 'health-' . $healthStatus,
        'health_label' => getHealthLabel($healthStatus),
        // Overall (worse of the two) - row color, chart, sorting
        'risk_category' => $riskCategory,
        'risk_class' => getAgeColorClass($riskCategory),
        'risk_label' => getRiskLabel($riskCategory, $ageCategory, $ageLabel, $healthStatus),
        'is_standby' => $isStandby,
        'data_age_seconds' => $dataAge,
        'is_stale' => $isStale,
        'poh_cached_at' => $cacheTimestamp, // set when POH came from drivecache (drive spun down)
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
        'hdd_command_timeout' => $smartData['hdd_command_timeout'] ?? null,
        // SATA SSD wear (null unless the drive reports a known wear attribute)
        'ssd_percentage_used' => $smartData['ssd_percentage_used'] ?? null,
        'ssd_wear_attribute' => $smartData['ssd_wear_attribute'] ?? null,
        'ssd_tbw_calculated' => $smartData['ssd_tbw_calculated'] ?? null,
        // SCSI/SAS error indicators
        'scsi_grown_defects' => $smartData['scsi_grown_defects'] ?? null,
        'scsi_uncorrected_errors' => $smartData['scsi_uncorrected_errors'] ?? null
    ];

    // Predictive replacement estimate. It does NOT feed back into the risk
    // category: a replacement countdown is a planning target, and escalating
    // on it turned healthy drives "High Risk (AFR ~4%+)" at ~7.5 years.
    $driveInfo['replacement_prediction'] = getPredictiveReplacement($driveInfo, $config);

    return $driveInfo;
}

/**
 * Severity for an error count that can grow over a drive's life.
 *
 * With drivecache history: rising within the last 30 days -> critical;
 * unchanged for 30+ days -> warning (unless the count is enormous). Without
 * history yet, plain count thresholds apply.
 *
 * @param int $count Current count (> 0)
 * @param int|null $criticalAbove Count above which a drive with no history
 *                                is critical (null: never by count alone)
 * @param string|null $trend 'growing', 'stable', 'new' or null
 * @return string 'critical' or 'warning'
 */
function driveage_count_level($count, $criticalAbove, $trend) {
    if ($trend === 'growing') {
        return 'critical';
    }
    if ($criticalAbove === null) {
        return 'warning';
    }
    if ($trend === 'stable') {
        return $count > $criticalAbove * 10 ? 'critical' : 'warning';
    }
    return $count > $criticalAbove ? 'critical' : 'warning';
}

/**
 * Detect pre-failure conditions from SMART attributes (HDD, SATA SSD, SAS).
 *
 * Google's disk study and Backblaze's SMART analyses identify attributes
 * 5, 187, 188, 197 and 198 as the strongest failure predictors - all five
 * are evaluated for hard drives. SAS drives report the equivalent grown
 * defect list and uncorrected error counters. SATA SSDs are checked for 5
 * and 187 only: 188/197/198 carry vendor-specific (sometimes buggy)
 * meanings on SSDs - Crucial's 197, for one, flips between 0 and 1.
 *
 * @param array $driveInfo Drive information with SMART attributes
 * @param array $trends Field => ['trend' => 'growing'|'stable'|'new',
 *                      'since' => int|null, 'previous' => int|null]
 *                      from the drivecache history (see getAttributeTrend())
 * @return array Health warnings
 */
function getHddHealthWarnings($driveInfo, $trends = []) {
    $type = $driveInfo['physical_type'] ?? '';
    if ($type !== 'hdd' && $type !== 'ssd') {
        return [];
    }
    $isSsd = ($type === 'ssd');
    $warnings = [];

    $add = function ($field, $attribute, $value, $level, $noun, $action, $tooltip) use (&$warnings, $trends) {
        $trend = $trends[$field] ?? [];
        $message = $value . ' ' . $noun . ($value > 1 ? 's' : '');
        if (($trend['trend'] ?? null) === 'growing') {
            $message .= (isset($trend['previous'], $trend['since']) && $value > $trend['previous'])
                ? sprintf(' (+%d since %s)', $value - $trend['previous'], date('M j', $trend['since']))
                : ' (increasing)';
        } elseif (($trend['trend'] ?? null) === 'stable') {
            $message .= ' (stable)';
        }
        $warnings[] = [
            'level' => $level,
            'attribute' => $attribute,
            'value' => $value,
            'message' => $message,
            'action' => $action,
            'tooltip' => $tooltip,
            'trend' => $trend['trend'] ?? null
        ];
    };
    $trendOf = fn($field) => $trends[$field]['trend'] ?? null;

    $reallocated   = intval($driveInfo['hdd_reallocated_sectors'] ?? 0);
    $pending       = intval($driveInfo['hdd_pending_sectors'] ?? 0);
    $uncorrectable = intval($driveInfo['hdd_uncorrectable_sectors'] ?? 0);
    $reported      = intval($driveInfo['hdd_reported_uncorrectable'] ?? 0);
    // SMART 188 composite raw values (already masked by the parser; values
    // supplied from elsewhere get the same treatment)
    $timeouts      = driveage_mask_command_timeout(intval($driveInfo['hdd_command_timeout'] ?? 0));
    $grownDefects  = intval($driveInfo['scsi_grown_defects'] ?? 0);
    $uncorrected   = intval($driveInfo['scsi_uncorrected_errors'] ?? 0);

    // CRITICAL: pending sectors = unreadable right now (SMART 197)
    if (!$isSsd && $pending > 0) {
        $add('hdd_pending_sectors', 'pending_sectors', $pending, 'critical', 'pending sector', 'Replace ASAP',
            'Pending sectors could not be read and are waiting to be reallocated. The drive is actively failing.');
    }

    // CRITICAL: offline uncorrectable sectors = data loss (SMART 198)
    if (!$isSsd && $uncorrectable > 0) {
        $add('hdd_uncorrectable_sectors', 'uncorrectable_sectors', $uncorrectable, 'critical', 'uncorrectable error', 'Replace immediately',
            'Uncorrectable errors indicate permanent data loss. Replace drive now.');
    }

    // Reallocated sectors / retired flash blocks (SMART 5)
    if ($reallocated > 0) {
        $level = driveage_count_level($reallocated, 10, $trendOf('hdd_reallocated_sectors'));
        $action = ($level === 'critical')
            ? ($trendOf('hdd_reallocated_sectors') === 'growing' ? 'Replace ASAP - count is rising' : 'Replace immediately')
            : 'Back up data, monitor closely';
        $add('hdd_reallocated_sectors', 'reallocated_sectors', $reallocated, $level, 'reallocated sector', $action, $isSsd
            ? 'The SSD has retired flash blocks. A stable count is common; a rising count means the flash is failing.'
            : 'Reallocated sectors indicate physical damage. A stable count can last years; a rising count means the drive is deteriorating.');
    }

    // Reported uncorrectable errors (SMART 187)
    if ($reported > 0) {
        $level = driveage_count_level($reported, 10, $trendOf('hdd_reported_uncorrectable'));
        $add('hdd_reported_uncorrectable', 'reported_uncorrectable', $reported, $level, 'reported uncorrectable error',
            $level === 'critical' ? 'Replace ASAP' : 'Monitor closely, plan replacement',
            'Read errors during normal operation. Strong failure predictor (Google study).');
    }

    // Command timeouts (SMART 188) - often cabling/controller related
    if (!$isSsd && $timeouts > 0) {
        $level = $timeouts > 100 ? 'critical' : 'warning';
        $add('hdd_command_timeout', 'command_timeout', $timeouts, $level, 'command timeout',
            $level === 'critical' ? 'Replace ASAP' : 'Monitor closely',
            'Commands timed out - the drive is becoming unresponsive or has cabling/controller issues.');
    }

    // SAS: grown defect list (the SCSI counterpart of reallocations). Many
    // healthy SAS drives carry a few entries - only growth is critical.
    if ($grownDefects > 0) {
        $level = driveage_count_level($grownDefects, null, $trendOf('scsi_grown_defects'));
        $add('scsi_grown_defects', 'grown_defects', $grownDefects, $level, 'grown defect',
            $level === 'critical' ? 'Replace ASAP - defects are increasing' : 'Monitor for growth',
            'Blocks the drive has remapped since leaving the factory. A stable list is common on SAS drives; a growing one means the media is deteriorating.');
    }

    // SAS: total uncorrected read/write/verify errors
    if ($uncorrected > 0) {
        $level = driveage_count_level($uncorrected, 10, $trendOf('scsi_uncorrected_errors'));
        $add('scsi_uncorrected_errors', 'scsi_uncorrected', $uncorrected, $level, 'uncorrected error',
            $level === 'critical' ? 'Replace ASAP' : 'Monitor closely, plan replacement',
            'Read/write/verify errors the drive could not correct (SCSI error counter log).');
    }

    return $warnings;
}

/**
 * Detect NVMe critical conditions.
 *
 * @param array $driveInfo Drive information with NVMe SMART attributes
 * @return array Health warnings
 */
function getNvmeHealthWarnings($driveInfo) {
    $warnings = [];

    if (($driveInfo['nvme_media_errors'] ?? 0) > 0) {
        $warnings[] = [
            'level' => 'critical',
            'attribute' => 'media_errors',
            'value' => $driveInfo['nvme_media_errors'],
            'message' => 'Media errors detected',
            'action' => 'Replace ASAP',
            'tooltip' => 'Uncorrectable data errors. Drive reliability compromised.'
        ];
    }

    // Each Critical Warning bit means something different: a hot drive
    // (temperature bit) needs cooling, a read-only drive needs replacing
    foreach (driveage_decode_nvme_critical_warning($driveInfo['nvme_critical_warning'] ?? 0) as $condition) {
        $warnings[] = [
            'level' => $condition['level'],
            'attribute' => $condition['attribute'],
            'value' => $condition['bit'],
            'message' => $condition['message'],
            'action' => $condition['action'],
            'tooltip' => $condition['tooltip']
        ];
    }

    return $warnings;
}

/**
 * Get drive size in bytes.
 *
 * @param string $deviceName Device name
 * @param array $ini Ini entry for this device
 * @return int Size in bytes
 */
function getDriveSize($deviceName, $ini) {
    // Unraid's own numbers: sectors x sector_size
    $sectors = intval($ini['sectors'] ?? 0);
    $sectorSize = intval($ini['sector_size'] ?? 0);
    if ($sectors > 0 && $sectorSize > 0) {
        return $sectors * $sectorSize;
    }

    // Some ini entries carry size in KiB
    if (isset($ini['size']) && is_numeric($ini['size']) && intval($ini['size']) > 0) {
        return intval($ini['size']) * 1024;
    }

    // Last resort: ask the block layer
    if (isValidDeviceName($deviceName)) {
        $output = shell_exec('blockdev --getsize64 ' . escapeshellarg('/dev/' . $deviceName) . ' 2>/dev/null');
        return $output ? intval(trim($output)) : 0;
    }

    return 0;
}

/**
 * Pool name for a pool member's slot name (Unraid's prefix()):
 * "cache2" -> "cache", "media_pool3" -> "media_pool".
 */
function getPoolName($slotName) {
    $pool = preg_replace('/\d+$/', '', (string)$slotName);
    return $pool !== '' ? $pool : (string)$slotName;
}

/**
 * Apply the Settings page drive-type filters.
 *
 * @param array $drives Drives from getAllDrives()
 * @param array $config Validated configuration
 * @return array Drives to display (re-indexed)
 */
function filterDrivesByType($drives, $config) {
    $filterMap = [
        'parity'     => 'SHOW_PARITY',
        'array'      => 'SHOW_ARRAY',
        'cache'      => 'SHOW_CACHE',
        'pool'       => 'SHOW_POOL',
        'unassigned' => 'SHOW_UNASSIGNED'
        // 'flash' (USB boot) is always shown
    ];
    return array_values(array_filter($drives, function($drive) use ($config, $filterMap) {
        $key = $filterMap[$drive['drive_type']] ?? null;
        return $key === null || ($config[$key] ?? 'true') === 'true';
    }));
}

/**
 * Group drives for display, in the order Unraid's Main page uses: the
 * array, then pools (cache first, others alphabetically), unassigned
 * devices, and the boot flash last.
 *
 * @param array $drives Drives to display
 * @return array List of ['name' => group name, 'type' => drive_type, 'drives' => [...]]
 */
function groupDrives($drives) {
    $typeOrder = ['parity' => 1, 'array' => 2, 'cache' => 3, 'pool' => 4, 'unassigned' => 5, 'flash' => 6];

    $groups = [];
    foreach ($drives as $drive) {
        $key = $drive['array_name'] . "\0" . $drive['drive_type'];
        if (!isset($groups[$key])) {
            $groups[$key] = ['name' => $drive['array_name'], 'type' => $drive['drive_type'], 'drives' => []];
        }
        $groups[$key]['drives'][] = $drive;
    }

    $groups = array_values($groups);
    usort($groups, function($a, $b) use ($typeOrder) {
        return [$typeOrder[$a['type']] ?? 99, strtolower($a['name'])]
           <=> [$typeOrder[$b['type']] ?? 99, strtolower($b['name'])];
    });
    return $groups;
}

/**
 * Map an ini entry to DriveAge assignment info.
 *
 * @param string $slotName Section name from the ini (disk1, parity, cache...)
 * @param array $ini Ini entry
 * @param string $source 'disks', 'devs' or 'glob'
 * @return array Assignment info
 */
function getUnraidAssignment($slotName, $ini, $source) {
    $assignment = [
        // Unraid's own name ("dev1" -> "Dev 1" for unassigned devices)
        'display_name' => $ini['name'] ?? $ini['device'] ?? $slotName,
        'array_name' => 'Unassigned',
        'drive_type' => 'unassigned',
        'disk_id' => $ini['id'] ?? null
    ];

    if ($source !== 'disks') {
        return $assignment;
    }

    $type = $ini['type'] ?? 'Unknown';

    switch ($type) {
        case 'Parity':
            $assignment['display_name'] = ucfirst($slotName);
            $assignment['array_name'] = 'Main Array';
            $assignment['drive_type'] = 'parity';
            break;

        case 'Data':
            $assignment['display_name'] = ucfirst($slotName);
            $assignment['array_name'] = 'Main Array';
            $assignment['drive_type'] = 'array';
            break;

        case 'Cache':
            // Pool member. Unraid names members <pool>, <pool>2, <pool>3...
            // and derives the pool name by stripping trailing digits (its
            // prefix() helper) - so cache + cache2 are ONE pool, "Cache".
            // The pool literally named "cache" is the cache pool; every
            // other pool is drive_type 'pool' (SHOW_POOL).
            $poolName = getPoolName($slotName);
            $assignment['display_name'] = str_replace('_', ' ', $slotName);
            $assignment['array_name'] = ucfirst(str_replace('_', ' ', $poolName));
            $assignment['drive_type'] = ($poolName === 'cache') ? 'cache' : 'pool';
            break;

        case 'Flash':
            $assignment['display_name'] = 'Flash (USB Boot)';
            $assignment['array_name'] = 'USB Boot';
            $assignment['drive_type'] = 'flash';
            break;

        default:
            $assignment['display_name'] = ucfirst($slotName);
            $assignment['array_name'] = 'Unknown';
            $assignment['drive_type'] = 'unassigned';
            break;
    }

    return $assignment;
}
