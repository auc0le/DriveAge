<?php
/**
 * Debug endpoint to show Unraid vars
 */

header('Content-Type: application/json');

$varFile = '/var/local/emhttp/var.ini';

if (!file_exists($varFile)) {
    echo json_encode([
        'error' => 'var.ini not found',
        'path' => $varFile
    ], JSON_PRETTY_PRINT);
    exit;
}

$vars = parse_ini_file($varFile) ?: [];

// Show all drive-related variables
$driveVars = [];
foreach ($vars as $key => $value) {
    if (stripos($key, 'rdev') !== false ||
        stripos($key, 'disk') !== false ||
        stripos($key, 'cache') !== false ||
        stripos($key, 'parity') !== false ||
        stripos($key, 'pool') !== false) {
        $driveVars[$key] = $value;
    }
}

echo json_encode([
    'var_file' => $varFile,
    'drive_related_vars' => $driveVars,
    'all_vars_count' => count($vars)
], JSON_PRETTY_PRINT);
