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

// Sort for easier reading
ksort($vars);

echo json_encode([
    'var_file' => $varFile,
    'all_vars' => $vars,
    'count' => count($vars)
], JSON_PRETTY_PRINT);
