<?php
/**
 * Debug endpoint to show Unraid disk mappings
 */

header('Content-Type: application/json');

$disksDir = '/var/local/emhttp/disks';
$diskMappings = [];

if (is_dir($disksDir)) {
    $diskNames = scandir($disksDir);

    foreach ($diskNames as $diskName) {
        if ($diskName === '.' || $diskName === '..') {
            continue;
        }

        $diskPath = $disksDir . '/' . $diskName;

        if (is_dir($diskPath)) {
            $info = [];

            // Read common files
            $files = ['device', 'name', 'status', 'color', 'size', 'id', 'type'];
            foreach ($files as $file) {
                $filePath = $diskPath . '/' . $file;
                if (file_exists($filePath)) {
                    $info[$file] = trim(file_get_contents($filePath));
                }
            }

            $diskMappings[$diskName] = $info;
        }
    }
}

// Also check if there's a disks.ini
$disksIni = '/var/local/emhttp/disks.ini';
$disksIniData = null;
if (file_exists($disksIni)) {
    $disksIniData = parse_ini_file($disksIni, true) ?: [];
}

echo json_encode([
    'disks_dir' => $disksDir,
    'disk_mappings' => $diskMappings,
    'disks_ini_exists' => file_exists($disksIni),
    'disks_ini' => $disksIniData
], JSON_PRETTY_PRINT);
