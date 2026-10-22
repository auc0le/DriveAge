#!/usr/bin/php
<?php
/**
 * DriveAge Diagnostic Script
 * Run on Unraid to inspect what DriveAge sees: enumeration, SMART cache,
 * parse results and poll interval.
 */

require_once '/usr/local/emhttp/plugins/driveage/include/config.php';
require_once '/usr/local/emhttp/plugins/driveage/include/smartdata.php';

echo "DriveAge Diagnostic\n";
echo "===================\n\n";

echo "1. Unraid state files:\n";
foreach (['disks.ini', 'devs.ini', 'var.ini'] as $f) {
    $path = DRIVEAGE_STATE_DIR . '/' . $f;
    echo '   ' . (file_exists($path) ? "\u{2713}" : "\u{2717}") . " $path\n";
}
echo '   SMART poll interval (poll_attributes): ' . getPollInterval() . " seconds\n";

echo "\n2. Enumerated drives:\n";
$devices = enumerateDrives();
echo '   Found ' . count($devices) . " devices\n";
foreach ($devices as $dev => $entry) {
    $slot = $entry['ini']['_slot'] ?? '?';
    echo "   - $dev (slot: $slot, source: {$entry['source']})\n";
}

echo "\n3. SMART cache files (" . DRIVEAGE_STATE_DIR . "/smart/):\n";
$smartDir = DRIVEAGE_STATE_DIR . '/smart';
if (is_dir($smartDir)) {
    $files = glob("$smartDir/*") ?: [];
    echo '   Found ' . count($files) . " files\n";
    foreach ($devices as $dev => $entry) {
        $names = getSmartCacheNames($dev, $entry['ini']);
        $found = null;
        foreach ($names as $name) {
            if (file_exists("$smartDir/$name")) {
                $found = $name;
                break;
            }
        }
        if ($found !== null) {
            $cacheFile = "$smartDir/$found";
            $age = time() - filemtime($cacheFile);
            echo "   \u{2713} $dev -> smart/$found (age: {$age}s, " . filesize($cacheFile) . " bytes)";
            echo file_exists("$cacheFile.ssa") ? " + .ssa health\n" : "\n";
        } else {
            echo "   \u{2717} $dev - no cache file (tried: " . implode(', ', $names) . "; will query directly unless spun down)\n";
        }
    }
} else {
    echo "   \u{2717} Directory does not exist - emhttpd has not populated the cache yet\n";
}

echo "\n4. Parse results:\n";
foreach ($devices as $dev => $entry) {
    $iniStandby = (($entry['ini']['spundown'] ?? '0') === '1');
    $read = readSmartData($dev, $iniStandby, getSmartCacheNames($dev, $entry['ini']), $entry['ini']);
    $d = $read['data'];
    if ($d === null) {
        echo "   \u{2717} $dev: no data\n";
        continue;
    }
    if ($d['is_standby']) {
        echo "   \u{25CB} $dev: standby (cached values will be used)\n";
        continue;
    }
    printf("   \u{2713} %s: POH=%s temp=%s status=%s%s%s%s%s\n",
        $dev,
        $d['power_on_hours'] ?? 'n/a',
        $d['temperature'] ?? 'n/a',
        $d['smart_status'],
        $d['has_nvme_log'] ? ' [nvme-log]' : '',
        $d['has_ata_attrs'] ? ' [ata-attrs]' : '',
        ($d['ssd_percentage_used'] ?? null) !== null ? " [ssd-wear {$d['ssd_percentage_used']}% via {$d['ssd_wear_attribute']}]" : (!empty($d['is_ssd']) ? ' [ssd]' : ''),
        ($d['scsi_grown_defects'] ?? null) !== null ? " [sas grown-defects={$d['scsi_grown_defects']}]" : ''
    );
}

echo "\n5. Full pipeline (getAllDrives):\n";
$config = loadConfig();
$drives = getAllDrives($config);
echo '   Assembled ' . count($drives) . " drives\n";
foreach ($drives as $d) {
    printf("   - %-12s %-10s %-14s POH=%-7s %s/%s -> %s\n",
        $d['device_id'],
        $d['physical_type'],
        $d['drive_type'],
        $d['power_on_hours'] ?? 'n/a',
        $d['age_category'],
        $d['health_status'],
        $d['replacement_prediction']['timeline_text'] ?? '?'
    );
}

echo "\nDiagnostic complete.\n";
