<?php
/**
 * DriveAge Plugin - Clear Cache Endpoint
 *
 * Clears the drive data cache to force fresh data on next load
 */

// Include required files
require_once '/usr/local/emhttp/plugins/driveage/include/smartdata.php';
require_once '/usr/local/emhttp/plugins/driveage/include/security.php';

// Set JSON content type
header('Content-Type: application/json');

try {
    // Clear the cache
    $success = clearCache();

    // Log the cache clear event
    logSecurityEvent('cache_cleared', ['success' => $success]);

    // Return success response
    echo json_encode([
        'success' => $success,
        'message' => $success ? 'Cache cleared successfully' : 'Failed to clear cache'
    ]);

} catch (Exception $e) {
    error_log('DriveAge Cache Clear Error: ' . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to clear cache',
        'message' => $e->getMessage()
    ]);
}
