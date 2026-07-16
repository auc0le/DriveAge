<?php
/**
 * DriveAge Plugin - JSON Endpoint for Drive Data
 *
 * Returns drive data for the dashboard (and any same-origin integration).
 *
 * Access control: Unraid's webGUI serves /plugins/* behind its own session
 * authentication - every request here is already an authenticated webGUI
 * user. No additional gating is applied (an earlier plugin-level "API
 * enable" switch and rate limiter were removed: they gated nothing real
 * and were bypassable via a spoofed Referer header).
 */

header('Content-Type: application/json');

require_once '/usr/local/emhttp/plugins/driveage/include/config.php';
require_once '/usr/local/emhttp/plugins/driveage/include/smartdata.php';

try {
    // Load configuration (validated on load)
    $config = loadConfig();

    // Collect all drives from Unraid's state + SMART cache
    $drives = getAllDrives($config);

    // Apply drive-type display filters
    $filterMap = [
        'parity'     => 'SHOW_PARITY',
        'array'      => 'SHOW_ARRAY',
        'cache'      => 'SHOW_CACHE',
        'pool'       => 'SHOW_POOL',
        'unassigned' => 'SHOW_UNASSIGNED'
        // 'flash' (USB boot) is always shown
    ];
    $drives = array_values(array_filter($drives, function($drive) use ($config, $filterMap) {
        $key = $filterMap[$drive['drive_type']] ?? null;
        return $key === null || ($config[$key] ?? 'true') === 'true';
    }));

    // Group drives by array and type
    $grouped = groupDrives($drives);

    // Oldest SMART cache age across drives (for the data-age indicator)
    $dataAges = array_filter(array_map(fn($d) => $d['data_age_seconds'], $drives), 'is_numeric');
    $maxDataAge = empty($dataAges) ? null : max($dataAges);

    $response = [
        'success' => true,
        'timestamp' => time(),
        'timestamp_formatted' => formatTimestamp(),
        'drive_count' => count($drives),
        'data_age_seconds' => $maxDataAge,
        'poll_interval' => getPollInterval(),
        'drives' => $drives,
        'grouped' => $grouped,
        'thresholds' => [
            'minimal_risk' => intval($config['THRESHOLD_MINIMAL_RISK']),
            'low_risk' => intval($config['THRESHOLD_LOW_RISK']),
            'moderate_risk' => intval($config['THRESHOLD_MODERATE_RISK']),
            'elevated_risk' => intval($config['THRESHOLD_ELEVATED_RISK'])
        ],
        'labels' => [
            'minimal_risk' => getAgeLabel('minimal_risk', $config),
            'low_risk' => getAgeLabel('low_risk', $config),
            'moderate_risk' => getAgeLabel('moderate_risk', $config),
            'elevated_risk' => getAgeLabel('elevated_risk', $config),
            'high_risk' => getAgeLabel('high_risk', $config),
            'unknown' => getAgeLabel('unknown', $config)
        ],
        'colors' => [
            'minimal_risk' => $config['COLOR_MINIMAL_RISK'],
            'low_risk' => $config['COLOR_LOW_RISK'],
            'moderate_risk' => $config['COLOR_MODERATE_RISK'],
            'elevated_risk' => $config['COLOR_ELEVATED_RISK'],
            'high_risk' => $config['COLOR_HIGH_RISK']
        ],
        'text_colors' => [
            'minimal_risk' => $config['TEXTCOLOR_MINIMAL_RISK'],
            'low_risk' => $config['TEXTCOLOR_LOW_RISK'],
            'moderate_risk' => $config['TEXTCOLOR_MODERATE_RISK'],
            'elevated_risk' => $config['TEXTCOLOR_ELEVATED_RISK'],
            'high_risk' => $config['TEXTCOLOR_HIGH_RISK']
        ],
        'config' => [
            'show_temperature' => $config['SHOW_TEMPERATURE'] === 'true',
            'show_smart_status' => $config['SHOW_SMART_STATUS'] === 'true',
            'show_spin_status' => $config['SHOW_SPIN_STATUS'] === 'true',
            'auto_refresh' => $config['AUTO_REFRESH'] === 'true',
            'refresh_interval' => intval($config['REFRESH_INTERVAL']),
            'default_sort' => $config['DEFAULT_SORT'],
            'default_sort_dir' => $config['DEFAULT_SORT_DIR'],
            'temperature_unit' => getTemperatureUnit()
        ]
    ];

    echo json_encode($response);

} catch (\Throwable $e) {
    // Log full error for debugging
    error_log('DriveAge API Error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());

    // Generic error response (don't expose internal details)
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to retrieve drive data',
        'message' => 'An internal error occurred. Check the system log for details.'
    ]);
}

/**
 * Group drives by array and drive type
 *
 * @param array $drives Array of drive data
 * @return array Grouped drives
 */
function groupDrives($drives) {
    $grouped = [];

    foreach ($drives as $drive) {
        $arrayName = $drive['array_name'];
        $driveType = $drive['drive_type'];

        if (!isset($grouped[$arrayName])) {
            $grouped[$arrayName] = [];
        }

        if (!isset($grouped[$arrayName][$driveType])) {
            $grouped[$arrayName][$driveType] = [];
        }

        $grouped[$arrayName][$driveType][] = $drive;
    }

    ksort($grouped);

    foreach ($grouped as $arrayName => &$types) {
        $typeOrder = ['parity' => 1, 'array' => 2, 'cache' => 3, 'pool' => 4, 'flash' => 5, 'unassigned' => 6];

        uksort($types, function($a, $b) use ($typeOrder) {
            $orderA = $typeOrder[$a] ?? 99;
            $orderB = $typeOrder[$b] ?? 99;
            return $orderA - $orderB;
        });
    }
    unset($types);

    return $grouped;
}
