#!/usr/bin/php
<?php
/**
 * DriveAge Diagnostic Script
 * Run this on Unraid to diagnose cache issues
 */

echo "DriveAge Cache Diagnostic\n";
echo "=========================\n\n";

// Check if Unraid's SMART cache directory exists
$smartCacheDir = '/var/local/emhttp/smart';
echo "1. Checking Unraid SMART cache directory: $smartCacheDir\n";
if (is_dir($smartCacheDir)) {
    echo "   ✓ Directory exists\n";

    // List files in the cache directory
    $files = glob("$smartCacheDir/*");
    echo "   Found " . count($files) . " cache files:\n";
    foreach ($files as $file) {
        $size = filesize($file);
        echo "   - " . basename($file) . " ($size bytes)\n";
    }
} else {
    echo "   ✗ Directory does not exist!\n";
    echo "   This means emhttpd hasn't created the cache yet.\n";
}

echo "\n2. Checking for block devices:\n";
$devices = array_merge(glob('/dev/sd?') ?: [], glob('/dev/nvme?n?') ?: []);
echo "   Found " . count($devices) . " block devices:\n";
foreach ($devices as $device) {
    $deviceName = basename($device);
    echo "   - $device ($deviceName)\n";

    // Check if cache file exists for this device
    $cacheFile = "$smartCacheDir/$deviceName";
    if (file_exists($cacheFile)) {
        echo "     ✓ Cache file exists\n";

        // Try to read first few lines
        $content = file_get_contents($cacheFile);
        $lines = explode("\n", $content);
        echo "     Preview (first 5 lines):\n";
        for ($i = 0; $i < min(5, count($lines)); $i++) {
            echo "       " . substr($lines[$i], 0, 80) . "\n";
        }
    } else {
        echo "     ✗ No cache file found\n";
    }
}

echo "\n3. Checking disks.ini:\n";
$disksIni = '/var/local/emhttp/disks.ini';
if (file_exists($disksIni)) {
    echo "   ✓ disks.ini exists\n";
    $diskInfo = parse_ini_file($disksIni, true);
    echo "   Found " . count($diskInfo) . " disk entries\n";
} else {
    echo "   ✗ disks.ini does not exist\n";
}

echo "\n4. Test parsing a cache file:\n";
if (!empty($files)) {
    $testFile = $files[0];
    echo "   Testing: " . basename($testFile) . "\n";

    require_once '/usr/local/emhttp/plugins/driveage/include/smartdata.php';
    $deviceName = basename($testFile);
    $data = getSmartDataFromUnraidCache($deviceName);

    if ($data) {
        echo "   ✓ Successfully parsed!\n";
        echo "   Model: " . ($data['model'] ?? 'N/A') . "\n";
        echo "   Serial: " . ($data['serial'] ?? 'N/A') . "\n";
        echo "   Power-on hours: " . ($data['power_on_hours'] ?? 'N/A') . "\n";
        echo "   Temperature: " . ($data['temperature'] ?? 'N/A') . "\n";
        echo "   SMART status: " . ($data['smart_status'] ?? 'N/A') . "\n";
    } else {
        echo "   ✗ Failed to parse\n";
    }
} else {
    echo "   Skipped (no cache files found)\n";
}

echo "\n=========================\n";
echo "Diagnostic complete!\n";
