<?php
/**
 * DriveAge Plugin - AJAX Endpoint for Drive Data
 *
 * Returns JSON formatted drive data for dashboard display
 */

// Set JSON content type
header('Content-Type: application/json');

// Include required files
require_once '/usr/local/emhttp/plugins/driveage/include/config.php';
require_once '/usr/local/emhttp/plugins/driveage/include/smartdata.php';
require_once '/usr/local/emhttp/plugins/driveage/include/formatting.php';
require_once '/usr/local/emhttp/plugins/driveage/include/security.php';

try {
    // Load configuration
    $config = loadConfig();

    // Detect if this is an internal dashboard request vs external API access
    // Internal requests come from the Unraid web interface (have Referer header to our own pages)
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $isInternalRequest = !empty($referer) && strpos($referer, $host) !== false;

    // Check rate limit and API access
    checkRateLimit($config, $isInternalRequest);

    // Clean up old rate limit files (1% chance)
    if (rand(1, 100) === 1) {
        cleanupRateLimitFiles();
    }

    // Log API access
    logSecurityEvent('api_access', ['endpoint' => 'get_drive_data.php']);

    // Validate refresh parameter
    $forceRefresh = false;
    if (isset($_GET['refresh'])) {
        if ($_GET['refresh'] === 'true') {
            $forceRefresh = true;
        } elseif ($_GET['refresh'] !== 'false') {
            // Log suspicious input
            logSecurityEvent('invalid_parameter', [
                'parameter' => 'refresh',
                'value' => substr($_GET['refresh'], 0, 50)
            ]);
        }
    }

    // Get all drives
    $drives = getAllDrives($config, !$forceRefresh);

    // Group drives by array and type
    $grouped = groupDrives($drives);

    // Build response
    $response = [
        'success' => true,
        'timestamp' => time(),
        'timestamp_formatted' => formatTimestamp(),
        'drive_count' => count($drives),
        'drives' => $drives,
        'grouped' => $grouped,
        'thresholds' => [
            'brand_new' => intval($config['THRESHOLD_BRAND_NEW']),
            'newish' => intval($config['THRESHOLD_NEWISH']),
            'normal' => intval($config['THRESHOLD_NORMAL']),
            'aged' => intval($config['THRESHOLD_AGED']),
            'old' => intval($config['THRESHOLD_OLD'])
        ],
        'colors' => [
            'brand_new' => $config['COLOR_BRAND_NEW'],
            'newish' => $config['COLOR_NEWISH'],
            'normal' => $config['COLOR_NORMAL'],
            'aged' => $config['COLOR_AGED'],
            'old' => $config['COLOR_OLD'],
            'elderly' => $config['COLOR_ELDERLY']
        ],
        'config' => [
            'show_temperature' => $config['SHOW_TEMPERATURE'] === 'true',
            'show_smart_status' => $config['SHOW_SMART_STATUS'] === 'true',
            'show_spin_status' => $config['SHOW_SPIN_STATUS'] === 'true'
        ]
    ];

    echo json_encode($response, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    // Log full error for debugging
    error_log('DriveAge API Error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());

    logSecurityEvent('api_error', [
        'error' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ]);

    // Generic error response (don't expose internal details)
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to retrieve drive data',
        'message' => 'An internal error occurred. Please check system logs or contact support.'
    ], JSON_PRETTY_PRINT);
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

    // Sort arrays and types
    ksort($grouped);

    foreach ($grouped as $arrayName => &$types) {
        // Define sort order for drive types
        $typeOrder = ['parity' => 1, 'array' => 2, 'cache' => 3, 'pool' => 4, 'unassigned' => 5];

        uksort($types, function($a, $b) use ($typeOrder) {
            $orderA = $typeOrder[$a] ?? 99;
            $orderB = $typeOrder[$b] ?? 99;
            return $orderA - $orderB;
        });
    }

    return $grouped;
}
