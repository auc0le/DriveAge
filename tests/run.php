<?php
/**
 * DriveAge test runner
 *
 * Usage: php tests/run.php
 * Exit code 0 = all tests passed, 1 = failures.
 *
 * Pure-PHP assertions against the parsers, risk policy, predictions and
 * config validation, plus an end-to-end getAllDrives() run against a
 * fixture Unraid state directory. No smartmontools or Unraid required.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$FIXTURES = __DIR__ . '/fixtures';
$TMP = sys_get_temp_dir() . '/driveage-test-' . getmypid();
@mkdir($TMP, 0700, true);
@mkdir("$TMP/state/smart", 0700, true);

// Redirect all filesystem constants BEFORE including plugin code
define('DRIVEAGE_STATE_DIR', "$TMP/state");
define('DRIVEAGE_CONFIG_FILE', "$TMP/driveage.cfg");
define('DRIVEAGE_POH_CACHE_FILE', "$TMP/drivecache.json");

$BASE = dirname(__DIR__) . '/source/driveage/usr/local/emhttp/plugins/driveage/include';
if (!is_dir($BASE)) {
    // Allow running from a flat dev tree (include/ next to tests/)
    $BASE = dirname(__DIR__) . '/include';
}

require_once "$BASE/smartdata.php"; // pulls in all other modules

// ---------------------------------------------------------------------------
$pass = 0;
$fail = 0;

function check($name, $condition, $detail = '') {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "  ok  $name\n";
    } else {
        $fail++;
        echo "FAIL  $name" . ($detail !== '' ? "  [$detail]" : '') . "\n";
    }
}

function checkEq($name, $expected, $actual) {
    check($name, $expected === $actual, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

// ---------------------------------------------------------------------------
echo "== smartparse: ATA cache format (-A only, tricky raw values) ==\n";
$d = driveage_parse_smart_text(file_get_contents("$FIXTURES/ata_cache.txt"));
checkEq('POH from Seagate-style raw (33477h+12m+...)', 33477, $d['power_on_hours']);
checkEq('temperature prefers 194, strips (Min/Max ...)', 36, $d['temperature']);
checkEq('reallocated', 0, $d['hdd_reallocated_sectors']);
checkEq('pending', 0, $d['hdd_pending_sectors']);
checkEq('status stays UNKNOWN without health line', 'UNKNOWN', $d['smart_status']);
checkEq('spin active (attrs answered)', 'active', $d['spin_status']);
check('has_ata_attrs', $d['has_ata_attrs'] === true);

echo "\n== smartparse: full ATA output ==\n";
$d = driveage_parse_smart_text(file_get_contents("$FIXTURES/ata_full.txt"));
checkEq('model', 'WDC WD140EDGZ-11B1PA0', $d['model']);
checkEq('serial', 'Y5KVTEST', $d['serial']);
checkEq('overall health', 'PASSED', $d['smart_status']);
checkEq('reallocated=5', 5, $d['hdd_reallocated_sectors']);
checkEq('reported uncorrectable=2', 2, $d['hdd_reported_uncorrectable']);
checkEq('command timeout raw composite parsed', 65539, $d['hdd_command_timeout']);

echo "\n== smartparse: NVMe text health log (Unraid cache format) ==\n";
$d = driveage_parse_smart_text(file_get_contents("$FIXTURES/nvme_cache.txt"));
check('nvme log detected', $d['has_nvme_log'] === true);
checkEq('POH with thousands separator', 24845, $d['power_on_hours']);
checkEq('percentage used', 3, $d['nvme_percentage_used']);
checkEq('available spare', 100, $d['nvme_available_spare']);
checkEq('spare threshold', 10, $d['nvme_available_spare_threshold']);
checkEq('media errors', 0, $d['nvme_media_errors']);
checkEq('critical warning 0x00', 0, $d['nvme_critical_warning']);
checkEq('temperature', 45, $d['temperature']);
check('TBW ~24.57', abs($d['nvme_tbw_calculated'] - 24.57) < 0.1, 'got ' . $d['nvme_tbw_calculated']);

echo "\n== smartparse: SCSI/SAS text ==\n";
$d = driveage_parse_smart_text(file_get_contents("$FIXTURES/scsi_sas.txt"));
checkEq('POH from "hours powered up"', 33822, $d['power_on_hours']);
checkEq('temperature', 34, $d['temperature']);
checkEq('health OK -> PASSED', 'PASSED', $d['smart_status']);
checkEq('model from Product line', 'HUH721212AL5200', $d['model']);

echo "\n== smartparse: standby ==\n";
$d = driveage_parse_smart_text(file_get_contents("$FIXTURES/standby.txt"));
check('standby detected', $d['is_standby'] === true);
checkEq('spin standby', 'standby', $d['spin_status']);

echo "\n== smartparse: JSON (universal power_on_time covers SAS) ==\n";
$d = driveage_parse_smart_json([
    'model_name' => 'TEST DRIVE',
    'serial_number' => 'S123',
    'smart_status' => ['passed' => true],
    'power_on_time' => ['hours' => 12345],
    'temperature' => ['current' => 41]
]);
checkEq('universal POH', 12345, $d['power_on_hours']);
checkEq('universal temp', 41, $d['temperature']);
checkEq('status', 'PASSED', $d['smart_status']);

// ---------------------------------------------------------------------------
echo "\n== risk: age categories (Backblaze 2025 defaults) ==\n";
$config = getDefaultConfig();
checkEq('null POH -> unknown', 'unknown', getAgeCategory(null, $config));
checkEq('2yr -> minimal', 'minimal_risk', getAgeCategory(17520, $config));
checkEq('5yr -> low', 'low_risk', getAgeCategory(43800, $config));
checkEq('7yr -> moderate', 'moderate_risk', getAgeCategory(61320, $config));
checkEq('9yr -> elevated', 'elevated_risk', getAgeCategory(78840, $config));
checkEq('11yr -> high', 'high_risk', getAgeCategory(96360, $config));
checkEq('unknown label', 'N/A (no SMART data)', getAgeLabel('unknown'));
checkEq('high label', 'High Risk (AFR ~4%+)', getAgeLabel('high_risk', $config));

echo "\n== risk: NVMe wear categories ==\n";
checkEq('media errors -> high', 'high_risk',
    getNvmeRiskCategory(['physical_type' => 'nvme', 'nvme_media_errors' => 1]));
checkEq('SMART FAILED -> high', 'high_risk',
    getNvmeRiskCategory(['physical_type' => 'nvme', 'smart_status' => 'FAILED']));
checkEq('85% used -> moderate', 'moderate_risk',
    getNvmeRiskCategory(['physical_type' => 'nvme', 'nvme_percentage_used' => 85, 'nvme_available_spare' => 100]));
checkEq('worse-of-two wins (spare 45 -> moderate)', 'moderate_risk',
    getNvmeRiskCategory(['physical_type' => 'nvme', 'nvme_percentage_used' => 10, 'nvme_available_spare' => 45]));
checkEq('spare below threshold -> high', 'high_risk',
    getNvmeRiskCategory(['physical_type' => 'nvme', 'nvme_available_spare' => 5, 'nvme_available_spare_threshold' => 10]));

// ---------------------------------------------------------------------------
echo "\n== predictions: HDD targets (8yr conservative / 10yr aggressive) ==\n";
$p = estimateHddRemainingLife(['power_on_hours' => 17520, 'smart_status' => 'PASSED'], 'conservative');
checkEq('2yr drive conservative -> 72 months', 72, $p['months_remaining']);
checkEq('method age_linear', 'age_linear', $p['method']);

$p = estimateHddRemainingLife(['power_on_hours' => 17520, 'smart_status' => 'PASSED'], 'aggressive');
checkEq('2yr drive aggressive -> 96 months', 96, $p['months_remaining']);

$p = estimateHddRemainingLife(['power_on_hours' => 78840, 'smart_status' => 'PASSED'], 'conservative');
checkEq('9yr conservative -> past target, graceful 0', 0, $p['months_remaining']);

$p = estimateHddRemainingLife(['power_on_hours' => 78840, 'smart_status' => 'PASSED'], 'aggressive');
checkEq('9yr aggressive -> 12 months', 12, $p['months_remaining']);

$p = estimateHddRemainingLife(['power_on_hours' => 30000, 'smart_status' => 'FAILED'], 'conservative');
checkEq('SMART FAILED -> 0 months', 0, $p['months_remaining']);
checkEq('SMART FAILED method', 'smart_failed', $p['method']);

$p = estimateHddRemainingLife(['power_on_hours' => null, 'smart_status' => 'FAILED'], 'conservative');
checkEq('FAILED without POH still Replace Now', 0, $p['months_remaining']);

$p = estimateHddRemainingLife([
    'power_on_hours' => 30000, 'smart_status' => 'PASSED',
    'health_warnings' => [['level' => 'critical']]
], 'conservative');
checkEq('critical warning -> 0 months', 0, $p['months_remaining']);

$p = estimateHddRemainingLife([
    'power_on_hours' => 30000, 'smart_status' => 'PASSED',
    'health_warnings' => [['level' => 'warning']]
], 'conservative');
checkEq('caution warning conservative -> 3 months', 3, $p['months_remaining']);

$p = estimateHddRemainingLife(['power_on_hours' => 30000, 'smart_status' => 'UNKNOWN'], 'conservative');
check('UNKNOWN status no longer blocks age estimate', $p['months_remaining'] !== null,
    'got ' . var_export($p['months_remaining'], true));

$p = estimateHddRemainingLife([
    'power_on_hours' => null, 'smart_status' => 'PASSED',
    'health_warnings' => [['level' => 'critical']]
], 'conservative');
checkEq('critical warning without POH still Replace Now', 0, $p['months_remaining']);

echo "\n== predictions: NVMe measured wear rates ==\n";
// User's real-world case: 2TB, 24.5 TBW written over ~2.84 years
$drive = [
    'physical_type' => 'nvme',
    'smart_status' => 'PASSED',
    'size_bytes' => 2000398934016,
    'power_on_hours' => 24845,
    'nvme_tbw_calculated' => 24.5,
    'nvme_percentage_used' => 3,
    'nvme_available_spare' => 100,
    'nvme_available_spare_threshold' => 10,
    'nvme_media_errors' => 0,
    'nvme_critical_warning' => 0
];
$p = estimateNvmeRemainingLife($drive, 'conservative');
checkEq('light writes -> capped at 8yr target', 96, $p['months_remaining']);
checkEq('measured method used', 'tbw_measured_rate', $p['method']);
checkEq('measured confidence medium', 'medium', $p['confidence']);

$p = estimateNvmeRemainingLife($drive, 'aggressive');
checkEq('aggressive cap 120 months', 120, $p['months_remaining']);

// Heavy writer: 800 of ~909 TBW gone in 1 year
$heavy = array_merge($drive, ['nvme_tbw_calculated' => 800.0, 'power_on_hours' => 8760]);
$p = estimateNvmeRemainingLife($heavy, 'conservative');
// remaining ~109.5 TBW / (800*1.25 TB/yr) = 0.1095 yr -> ~1 month
check('heavy writer -> ~1 month', $p['months_remaining'] >= 1 && $p['months_remaining'] <= 2,
    'got ' . $p['months_remaining']);

// Short history falls back to assumed rate
$young = array_merge($drive, ['power_on_hours' => 1000, 'nvme_tbw_calculated' => 2.0]);
$p = estimateNvmeRemainingLife($young, 'conservative');
checkEq('short history -> assumed rate method', 'tbw_assumed_rate', $p['method']);
checkEq('assumed rate confidence low', 'low', $p['confidence']);

// Percentage-used measured slope (no TBW available)
$puOnly = [
    'physical_type' => 'nvme', 'smart_status' => 'PASSED', 'size_bytes' => 0,
    'power_on_hours' => 35040, // 4 years
    'nvme_tbw_calculated' => null,
    'nvme_percentage_used' => 40,
    'nvme_available_spare' => 100,
    'nvme_available_spare_threshold' => 10,
    'nvme_media_errors' => 0, 'nvme_critical_warning' => 0
];
$p = estimateNvmeRemainingLife($puOnly, 'conservative');
// slope = 10%/yr * 1.25 = 12.5 -> (60/12.5)*12 = 57.6 -> 58
checkEq('measured wear slope -> 58 months', 58, $p['months_remaining']);
checkEq('measured slope method', 'percentage_used_measured', $p['method']);

$p = estimateNvmeRemainingLife(array_merge($drive, ['smart_status' => 'FAILED']), 'conservative');
checkEq('NVMe SMART FAILED -> 0', 0, $p['months_remaining']);

$p = estimateNvmeRemainingLife(array_merge($drive, ['nvme_media_errors' => 3]), 'conservative');
checkEq('media errors -> 0', 0, $p['months_remaining']);

// ---------------------------------------------------------------------------
echo "\n== health warnings: 5/187/188/197/198 + FAILED ==\n";
$w = getHddHealthWarnings([
    'physical_type' => 'hdd',
    'hdd_reallocated_sectors' => 5,
    'hdd_pending_sectors' => 0,
    'hdd_uncorrectable_sectors' => 0,
    'hdd_reported_uncorrectable' => 2,
    'hdd_command_timeout' => 65539 // composite: low 16 bits = 3
]);
$byAttr = [];
foreach ($w as $warning) { $byAttr[$warning['attribute']] = $warning; }
checkEq('reallocated=5 -> warning level', 'warning', $byAttr['reallocated_sectors']['level'] ?? null);
checkEq('187 evaluated (2 -> warning)', 'warning', $byAttr['reported_uncorrectable']['level'] ?? null);
checkEq('188 composite masked to 3', 3, $byAttr['command_timeout']['value'] ?? null);
checkEq('188 masked -> warning not critical', 'warning', $byAttr['command_timeout']['level'] ?? null);

$w = getHddHealthWarnings([
    'physical_type' => 'hdd',
    'hdd_reallocated_sectors' => 0, 'hdd_pending_sectors' => 1,
    'hdd_uncorrectable_sectors' => 0, 'hdd_reported_uncorrectable' => 15,
    'hdd_command_timeout' => 500
]);
$byAttr = [];
foreach ($w as $warning) { $byAttr[$warning['attribute']] = $warning; }
checkEq('pending=1 -> critical', 'critical', $byAttr['pending_sectors']['level'] ?? null);
checkEq('187>10 -> critical', 'critical', $byAttr['reported_uncorrectable']['level'] ?? null);
checkEq('188>100 -> critical', 'critical', $byAttr['command_timeout']['level'] ?? null);

// ---------------------------------------------------------------------------
echo "\n== config validation (enforced on load) ==\n";
$v = validateConfig(array_merge(getDefaultConfig(), [
    'THRESHOLD_MINIMAL_RISK' => '50000',
    'THRESHOLD_LOW_RISK' => '40000' // out of order
]));
checkEq('bad threshold order resets to defaults', '35040', $v['THRESHOLD_MINIMAL_RISK']);

$v = validateConfig(array_merge(getDefaultConfig(), [
    'COLOR_MINIMAL_RISK' => '#4CAF50',
    'COLOR_LOW_RISK' => '#4CAF50' // duplicate
]));
checkEq('duplicate colors reset', '#8BC34A', $v['COLOR_LOW_RISK']);

$v = validateConfig(array_merge(getDefaultConfig(), ['DEFAULT_SORT' => 'evil_column']));
checkEq('bad sort column -> default', 'replacement_months', $v['DEFAULT_SORT']);

$v = validateConfig(array_merge(getDefaultConfig(), ['LABEL_HIGH_RISK' => '<script>x</script>Danger']));
checkEq('label tags stripped', 'xDanger', $v['LABEL_HIGH_RISK']);

$v = validateConfig(array_merge(getDefaultConfig(), ['REFRESH_INTERVAL' => '5']));
checkEq('refresh interval clamped to 30', '30', $v['REFRESH_INTERVAL']);

// ---------------------------------------------------------------------------
echo "\n== smart cache lookup: Unraid disk name first, device name fallback ==\n";
checkEq('assigned disk: name, then device', ['disk1', 'sdc'],
    getSmartCacheNames('sdc', ['name' => 'disk1', '_slot' => 'disk1']));
checkEq('pool member: name, then device', ['cache2', 'nvme1n1'],
    getSmartCacheNames('nvme1n1', ['name' => 'cache2', '_slot' => 'cache2']));
checkEq('glob fallback entry: device only', ['sdb'],
    getSmartCacheNames('sdb', ['device' => 'sdb', '_slot' => 'sdb']));
checkEq('path components stripped from ini names', ['x', 'sdc'],
    getSmartCacheNames('sdc', ['name' => '../../x', '_slot' => '..']));

// Device-name file alone is still found (fallback)
copy("$FIXTURES/ata_cache.txt", DRIVEAGE_STATE_DIR . '/smart/sdz');
$r = readSmartData('sdz', false, getSmartCacheNames('sdz', ['name' => 'disk9']));
checkEq('device-name cache file used when no name file', 33477, $r['data']['power_on_hours'] ?? null);
// Once the disk-name file exists it wins over a stale device-name file
copy("$FIXTURES/nvme_cache.txt", DRIVEAGE_STATE_DIR . '/smart/disk9');
$r = readSmartData('sdz', false, getSmartCacheNames('sdz', ['name' => 'disk9']));
checkEq('disk-name cache file preferred', 24845, $r['data']['power_on_hours'] ?? null);
file_put_contents(DRIVEAGE_STATE_DIR . '/smart/sdz.ssa', 'FAILED');
file_put_contents(DRIVEAGE_STATE_DIR . '/smart/disk9.ssa', 'PASSED');
checkEq('disk-name .ssa preferred', 'PASSED', readSsaHealth(getSmartCacheNames('sdz', ['name' => 'disk9'])));
foreach (['sdz', 'sdz.ssa', 'disk9', 'disk9.ssa'] as $f) { @unlink(DRIVEAGE_STATE_DIR . "/smart/$f"); }

// ---------------------------------------------------------------------------
echo "\n== end-to-end: getAllDrives() against fixture Unraid state ==\n";

// disks.ini: parity (active, cache file), disk1 (spun down, no cache file),
// cache pool nvme, flash USB. Cache files are named after the Unraid disk
// name (smart/parity, smart/cache, smart/dev1), exactly as emhttpd writes them.
file_put_contents(DRIVEAGE_STATE_DIR . '/disks.ini', <<<INI
["parity"]
idx="0"
name="parity"
device="sdb"
id="WDC_WD140EDGZ-11B1PA0_Y5KVTEST"
size="13672382412"
sectors="27344764824"
sector_size="512"
temp="36"
spundown="0"
type="Parity"
["disk1"]
idx="1"
name="disk1"
device="sdc"
id="WDC_WD140EDGZ-11B1PA0_Y5KVSLPY"
size="13672382412"
sectors="27344764824"
sector_size="512"
temp="*"
spundown="1"
type="Data"
["cache"]
idx="30"
name="cache"
device="nvme0n1"
id="Sabrent_Rocket_4.0_2TB_TESTSER"
size="1953514584"
sectors="3907029168"
sector_size="512"
temp="45"
spundown="0"
type="Cache"
["flash"]
idx="32"
name="flash"
device="sda"
id="USB_Flash_TESTUSB"
size="15633408"
sectors="31266816"
sector_size="512"
temp="*"
spundown="0"
type="Flash"
INI);

// devs.ini: one unassigned SAS-ish drive with a cache file
file_put_contents(DRIVEAGE_STATE_DIR . '/devs.ini', <<<INI
["dev1"]
name="dev1"
device="sdd"
id="HGST_HUH721212AL5200_8HHTEST1"
sectors="23437770752"
sector_size="512"
temp="34"
spundown="0"
INI);

file_put_contents(DRIVEAGE_STATE_DIR . '/var.ini', "poll_attributes=\"1800\"\n");

// SMART cache files, named by Unraid disk name (not kernel device)
copy("$FIXTURES/ata_full.txt", DRIVEAGE_STATE_DIR . '/smart/parity');
touch(DRIVEAGE_STATE_DIR . '/smart/parity', time() - 600); // direct queries report age 0
file_put_contents(DRIVEAGE_STATE_DIR . '/smart/parity.ssa', 'PASSED');
copy("$FIXTURES/nvme_cache.txt", DRIVEAGE_STATE_DIR . '/smart/cache');
copy("$FIXTURES/scsi_sas.txt", DRIVEAGE_STATE_DIR . '/smart/dev1');
// sdc: spun down, no cache file -> POH must come from the persistent cache,
// keyed by Unraid disk id. A legacy device-name entry ('sdc', written by an
// older version for whatever drive had that letter then) must be ignored
// and pruned.
file_put_contents(DRIVEAGE_POH_CACHE_FILE, json_encode([
    'WDC_WD140EDGZ-11B1PA0_Y5KVSLPY' => ['power_on_hours' => 39111, 'model' => 'WDC WD140EDGZ', 'serial' => 'Y5KVSLPY', 'timestamp' => time() - 3600],
    'sdc' => ['power_on_hours' => 11111, 'model' => 'OLD', 'serial' => 'OLD', 'timestamp' => time() - 3600]
]));

$drives = getAllDrives($config);
$byDev = [];
foreach ($drives as $d) { $byDev[$d['device_id']] = $d; }

checkEq('all 5 devices enumerated', 5, count($drives));
check('parity parsed from cache', isset($byDev['sdb']));
checkEq('parity health from .ssa', 'PASSED', $byDev['sdb']['smart_status'] ?? null);
checkEq('parity temp from disks.ini', 36, $byDev['sdb']['temperature'] ?? null);
if (PHP_INT_SIZE >= 8) { // 14 TB overflows 32-bit ints (e.g. php-wasm); Unraid is 64-bit
    checkEq('parity size from sectors x sector_size', 27344764824 * 512, $byDev['sdb']['size_bytes'] ?? null);
} else {
    echo "skip  parity size from sectors x sector_size (needs 64-bit PHP)\n";
}
// sdb has 5 reallocated + 187=2 + masked 188=3 -> warnings, no criticals -> 3 months
checkEq('parity: warnings -> 3 month estimate', 3, $byDev['sdb']['replacement_prediction']['months_remaining'] ?? null);
checkEq('parity escalated to high risk (<6 months)', 'high_risk', $byDev['sdb']['age_category'] ?? null);
checkEq('parity label matches escalated category', getAgeLabel('high_risk', $config), $byDev['sdb']['age_label'] ?? null);

checkEq('standby drive POH from persistent cache', 39111, $byDev['sdc']['power_on_hours'] ?? null);
check('standby flagged', ($byDev['sdc']['is_standby'] ?? false) === true);

checkEq('NVMe POH parsed from text cache (no direct smartctl)', 24845, $byDev['nvme0n1']['power_on_hours'] ?? null);
checkEq('NVMe wear category minimal', 'minimal_risk', $byDev['nvme0n1']['age_category'] ?? null);
checkEq('NVMe measured-rate prediction', 'tbw_measured_rate', $byDev['nvme0n1']['replacement_prediction']['method'] ?? null);

checkEq('SAS unassigned POH parsed', 33822, $byDev['sdd']['device_id'] === 'sdd' ? $byDev['sdd']['power_on_hours'] : null);
checkEq('unassigned type', 'unassigned', $byDev['sdd']['drive_type'] ?? null);

checkEq('flash -> unknown category (not Minimal Risk)', 'unknown', $byDev['sda']['age_category'] ?? null);
checkEq('flash label N/A', 'N/A (no SMART data)', $byDev['sda']['age_label'] ?? null);

check('parity read from smart/parity (data age = file age)', ($byDev['sdb']['data_age_seconds'] ?? 0) >= 600,
    'got ' . var_export($byDev['sdb']['data_age_seconds'] ?? null, true));
checkEq('poll interval read from var.ini', 1800, $byDev['sdb']['poll_interval'] ?? null);

// POH cache write-on-change: fresh values should now be persisted
$cacheAfter = json_decode(file_get_contents(DRIVEAGE_POH_CACHE_FILE), true);
checkEq('POH cache updated for parity (keyed by disk id)', 33477, $cacheAfter['WDC_WD140EDGZ-11B1PA0_Y5KVTEST']['power_on_hours'] ?? null);
check('legacy device-name key pruned', !isset($cacheAfter['sdc']));
checkEq('standby entry kept under its id', 39111, $cacheAfter['WDC_WD140EDGZ-11B1PA0_Y5KVSLPY']['power_on_hours'] ?? null);
checkEq('cache key: disk id', 'WDC_X_1', getDriveCacheKey('sdb', ['id' => 'WDC_X_1']));
checkEq('cache key: no id -> dev: prefix', 'dev:sdb', getDriveCacheKey('sdb', []));
$mtimeBefore = filemtime(DRIVEAGE_POH_CACHE_FILE);
sleep(1);
clearstatcache();
getAllDrives($config); // second run, nothing changed
clearstatcache();
checkEq('no rewrite when values unchanged', $mtimeBefore, filemtime(DRIVEAGE_POH_CACHE_FILE));

// SMART FAILED end-to-end: flip the .ssa file and confirm escalation
file_put_contents(DRIVEAGE_STATE_DIR . '/smart/parity.ssa', 'FAILED');
$drives = getAllDrives($config);
$byDev = [];
foreach ($drives as $d) { $byDev[$d['device_id']] = $d; }
checkEq('FAILED .ssa -> smart_status FAILED', 'FAILED', $byDev['sdb']['smart_status'] ?? null);
checkEq('FAILED -> Replace Now', 0, $byDev['sdb']['replacement_prediction']['months_remaining'] ?? null);
checkEq('FAILED -> high risk', 'high_risk', $byDev['sdb']['age_category'] ?? null);
$failedWarning = null;
foreach (($byDev['sdb']['health_warnings'] ?? []) as $warning) {
    if ($warning['attribute'] === 'smart_overall_failed') { $failedWarning = $warning; }
}
check('FAILED surfaced as first-class health warning', $failedWarning !== null);

// ---------------------------------------------------------------------------
// Cleanup
foreach (glob("$TMP/state/smart/*") as $f) { @unlink($f); }
foreach (glob("$TMP/state/*") as $f) { if (is_file($f)) @unlink($f); }
@rmdir("$TMP/state/smart");
@rmdir("$TMP/state");
@unlink(DRIVEAGE_POH_CACHE_FILE);
@unlink(DRIVEAGE_CONFIG_FILE);
@rmdir($TMP);

echo "\n=====================================\n";
echo "Passed: $pass   Failed: $fail\n";
exit($fail > 0 ? 1 : 0);
