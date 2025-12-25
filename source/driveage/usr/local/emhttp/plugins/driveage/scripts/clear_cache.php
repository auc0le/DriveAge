<?php
/**
 * DriveAge Plugin - Clear Cache Endpoint (Legacy)
 *
 * This endpoint is now a no-op for backwards compatibility.
 * DriveAge no longer maintains its own cache - it reads exclusively from
 * Unraid's SMART cache at /var/local/emhttp/smart/ which is managed by emhttpd.
 */

// Set JSON content type
header('Content-Type: application/json');

// Always return success - no cache to clear
echo json_encode([
    'success' => true,
    'message' => 'No cache to clear - DriveAge uses Unraid system cache'
]);
