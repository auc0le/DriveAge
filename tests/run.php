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
checkEq('command timeout composite masked to low 16 bits', 3, $d['hdd_command_timeout']);

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
checkEq('media errors are health, not wear', 'minimal_risk',
    getNvmeRiskCategory(['physical_type' => 'nvme', 'nvme_media_errors' => 1]));
checkEq('SMART FAILED is health, not wear', 'minimal_risk',
    getNvmeRiskCategory(['physical_type' => 'nvme', 'smart_status' => 'FAILED']));
checkEq('85% used -> moderate', 'moderate_risk',
    getNvmeRiskCategory(['physical_type' => 'nvme', 'nvme_percentage_used' => 85, 'nvme_available_spare' => 100]));
checkEq('worse-of-two wins (spare 45 -> moderate)', 'moderate_risk',
    getNvmeRiskCategory(['physical_type' => 'nvme', 'nvme_percentage_used' => 10, 'nvme_available_spare' => 45]));
checkEq('spare below threshold -> high', 'high_risk',
    getNvmeRiskCategory(['physical_type' => 'nvme', 'nvme_available_spare' => 5, 'nvme_available_spare_threshold' => 10]));

echo "\n== risk: health + lifecycle -> overall ==\n";
checkEq('FAILED status -> failing', 'failing', getHealthStatus('FAILED', [], true));
checkEq('critical warning -> failing', 'failing', getHealthStatus('PASSED', [['level' => 'critical']], true));
checkEq('warning -> watch', 'watch', getHealthStatus('PASSED', [['level' => 'warning']], true));
checkEq('no warnings -> ok', 'ok', getHealthStatus('PASSED', [], true));
checkEq('no SMART data -> unknown', 'unknown', getHealthStatus('UNKNOWN', [], false));
checkEq('failing forces high', 'high_risk', getOverallRiskCategory('minimal_risk', 'failing'));
checkEq('watch raises to elevated', 'elevated_risk', getOverallRiskCategory('low_risk', 'watch'));
checkEq('watch never lowers', 'high_risk', getOverallRiskCategory('high_risk', 'watch'));
checkEq('ok keeps lifecycle', 'moderate_risk', getOverallRiskCategory('moderate_risk', 'ok'));
checkEq('failing label says SMART', 'Failing (SMART)', getRiskLabel('high_risk', 'minimal_risk', 'Minimal Risk (AFR ~1%)', 'failing'));
checkEq('raised-by-warning label', 'Warning (SMART)', getRiskLabel('elevated_risk', 'low_risk', 'Low Risk (AFR ~1.5%)', 'watch'));
checkEq('lifecycle label otherwise', 'Moderate Risk (AFR ~2%)', getRiskLabel('moderate_risk', 'moderate_risk', 'Moderate Risk (AFR ~2%)', 'ok'));
checkEq('NVMe lifecycle label shows wear, not HDD AFR', 'Minimal Risk (wear 3%)', getLifecycleLabel('minimal_risk', 'nvme', 3, $config));
checkEq('HDD lifecycle label keeps AFR', 'Minimal Risk (AFR ~1%)', getLifecycleLabel('minimal_risk', 'hdd', null, $config));

// ---------------------------------------------------------------------------
echo "\n== predictions: HDD targets (8yr conservative / 10yr aggressive) ==\n";
$p = estimateHddRemainingLife(['power_on_hours' => 17520, 'smart_status' => 'PASSED'], 'conservative');
checkEq('2yr drive conservative -> 72 months', 72, $p['months_remaining']);
checkEq('method age_linear', 'age_linear', $p['method']);

$p = estimateHddRemainingLife(['power_on_hours' => 17520, 'smart_status' => 'PASSED'], 'aggressive');
checkEq('2yr drive aggressive -> 96 months', 96, $p['months_remaining']);

$p = estimateHddRemainingLife(['power_on_hours' => 78840, 'smart_status' => 'PASSED'], 'conservative');
checkEq('9yr conservative -> past target, 0', 0, $p['months_remaining']);
checkEq('past target -> "Due now" (not Replace Now)', 'Due now', $p['timeline_text']);
checkEq('past target -> warning style, not critical', 'timeline-warning', $p['timeline_class']);

$p = estimateHddRemainingLife(['power_on_hours' => 78840, 'smart_status' => 'PASSED'], 'aggressive');
checkEq('9yr aggressive -> 12 months', 12, $p['months_remaining']);

// Countdown must never increase as the drive ages (an old 6-month grace
// period made it jump from 1 month back to 6 at the target age)
foreach (['conservative' => 70080, 'aggressive' => 87600] as $mode => $targetHours) {
    $prev = PHP_INT_MAX;
    $monotonic = true;
    for ($h = $targetHours - 2000; $h <= $targetHours + 6000; $h += 25) {
        $m = estimateHddRemainingLife(['power_on_hours' => $h, 'smart_status' => 'PASSED'], $mode)['months_remaining'];
        if ($m > $prev) { $monotonic = false; break; }
        $prev = $m;
    }
    check("$mode countdown is monotonic through the target age", $monotonic, "rose at POH $h");
}
$p = estimateHddRemainingLife(['power_on_hours' => 69780, 'smart_status' => 'PASSED'], 'conservative');
checkEq('7.97yr conservative -> 0 (was 6)', 0, $p['months_remaining']);

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

echo "\n== predictions: NVMe (vendor wear indicator first) ==\n";
// User's real-world case: 2TB, 24.5 TBW written over ~2.84 years, 3% used
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
checkEq('wear indicator used when reported', 'percentage_used_measured', $p['method']);
checkEq('light wear -> 10-year display horizon', 120, $p['months_remaining']);
check('horizon flagged as capped', ($p['capped'] ?? false) === true);
checkEq('capped shows 10+ years', '10+ years', $p['timeline_text']);
checkEq('3% is coarse -> low confidence', 'low', $p['confidence']);

$p = estimateNvmeRemainingLife($drive, 'aggressive');
checkEq('aggressive also capped at horizon', 120, $p['months_remaining']);

// QLC drive: ~200 TBW rated, 200 TB written in 3 years, 90% used. The old
// capacity-based guess (500 TBW/TB) said ~3 years left; the drive says 10%.
$qlc = array_merge($drive, [
    'size_bytes' => 1000204886016, 'power_on_hours' => 26280,
    'nvme_tbw_calculated' => 200.0, 'nvme_percentage_used' => 90
]);
$p = estimateNvmeRemainingLife($qlc, 'conservative');
// 30%/yr * 1.25 = 37.5%/yr -> 10 / 37.5 * 12 = 3.2 -> 3 months
checkEq('QLC: wear indicator wins over TBW guess -> 3 months', 3, $p['months_remaining']);
checkEq('QLC: medium confidence at >=5%', 'medium', $p['confidence']);

$p = estimateNvmeRemainingLife(array_merge($drive, ['nvme_percentage_used' => 104]), 'conservative');
checkEq('>=100% used -> due now', 0, $p['months_remaining']);
checkEq('>=100% used -> endurance_exceeded', 'endurance_exceeded', $p['method']);
checkEq('>=100% used -> Due now (warning, not critical)', 'Due now', $p['timeline_text']);

$p = estimateNvmeRemainingLife(array_merge($drive, ['nvme_percentage_used' => 0]), 'conservative');
checkEq('0% after years -> lower bound method', 'wear_below_resolution', $p['method']);
checkEq('0% after years -> horizon', 120, $p['months_remaining']);

// No wear indicator at all: fall back to TBW vs an ESTIMATED rating
$heavy = array_merge($drive, ['nvme_percentage_used' => null, 'nvme_tbw_calculated' => 800.0, 'power_on_hours' => 8760]);
$p = estimateNvmeRemainingLife($heavy, 'conservative');
// 2.0004 TB (decimal) * 500 = 1000 TBW; 200 left / (800*1.25 TB/yr) = 0.2 yr -> 2 months
checkEq('rating estimate uses decimal TB', 1000, $p['estimated_tbw'] ?? null);
checkEq('heavy writer, no indicator -> 2 months', 2, $p['months_remaining']);
checkEq('TBW guess -> measured method', 'tbw_measured_rate', $p['method']);
checkEq('TBW guess is always low confidence', 'low', $p['confidence']);

// Short history with a wear indicator: 5-year assumption
$young = array_merge($drive, ['power_on_hours' => 1000, 'nvme_tbw_calculated' => 2.0]);
$p = estimateNvmeRemainingLife($young, 'conservative');
checkEq('short history -> linear wear assumption', 'percentage_used_linear', $p['method']);
checkEq('short history -> low confidence', 'low', $p['confidence']);

// Short history, no indicator: assumed write rate
$p = estimateNvmeRemainingLife(array_merge($young, ['nvme_percentage_used' => null]), 'conservative');
checkEq('short history, no indicator -> assumed rate', 'tbw_assumed_rate', $p['method']);

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

// A recent write rate (from drivecache history) re-projects the remaining
// wear: 40% over 160 TB = 0.25%/TB; 80 TB/yr recent -> 20%/yr * 1.25 = 25%/yr
$p = estimateNvmeRemainingLife(array_merge($puOnly, ['nvme_tbw_calculated' => 160.0, 'recent_write_rate_tb_yr' => 80.0]), 'conservative');
checkEq('recent write rate shortens the projection', 29, $p['months_remaining']);

$p = estimateNvmeRemainingLife(array_merge($drive, ['smart_status' => 'FAILED']), 'conservative');
checkEq('NVMe SMART FAILED -> 0', 0, $p['months_remaining']);

$p = estimateNvmeRemainingLife(array_merge($drive, ['nvme_media_errors' => 3]), 'conservative');
checkEq('media errors -> 0', 0, $p['months_remaining']);

echo "\n== NVMe critical warning bits ==\n";
$p = estimateNvmeRemainingLife(array_merge($drive, ['nvme_critical_warning' => 0x02]), 'conservative');
checkEq('temperature bit alone does not force replacement', 'percentage_used_measured', $p['method']);
$p = estimateNvmeRemainingLife(array_merge($drive, ['nvme_critical_warning' => 0x08]), 'conservative');
checkEq('read-only bit -> Replace Now', 'critical_error', $p['method']);
$p = estimateNvmeRemainingLife(array_merge($drive, ['nvme_critical_warning' => 0x01]), 'conservative');
checkEq('spare bit -> spare_below_threshold', 'spare_below_threshold', $p['method']);
$w = getNvmeHealthWarnings(['nvme_critical_warning' => 0x02, 'nvme_media_errors' => 0]);
checkEq('temperature bit -> one warning', 1, count($w));
checkEq('temperature bit -> warning level', 'warning', $w[0]['level'] ?? null);
checkEq('temperature bit -> cooling advice', 'Improve cooling', $w[0]['action'] ?? null);
checkEq('temperature bit -> health watch, not failing', 'watch', getHealthStatus('PASSED', $w, true));
$w = getNvmeHealthWarnings(['nvme_critical_warning' => 0x0C, 'nvme_media_errors' => 0]);
checkEq('0x0C -> two conditions', ['nvme_reliability', 'nvme_read_only'], array_column($w, 'attribute'));
checkEq('reliability bit critical', 'critical', $w[0]['level'] ?? null);
$w = getNvmeHealthWarnings(['nvme_critical_warning' => 0x80, 'nvme_media_errors' => 0]);
checkEq('unknown bit -> generic warning', 'critical_warning', $w[0]['attribute'] ?? null);

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

$v = validateConfig(array_merge(getDefaultConfig(), ['ROW_STYLE' => 'full']));
checkEq('row style full accepted', 'full', $v['ROW_STYLE']);
$v = validateConfig(array_merge(getDefaultConfig(), ['ROW_STYLE' => '<b>']));
checkEq('bad row style -> accent', 'accent', $v['ROW_STYLE']);
$v = validateConfig(array_merge(getDefaultConfig(), ['DEFAULT_SORT' => 'risk_category']));
checkEq('risk is a valid default sort', 'risk_category', $v['DEFAULT_SORT']);

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
echo "\n== assignment: pools, filters, grouping ==\n";
$a = getUnraidAssignment('cache2', ['type' => 'Cache', 'device' => 'nvme1n1'], 'disks');
checkEq('cache2 belongs to pool "Cache"', 'Cache', $a['array_name']);
checkEq('cache pool members are type cache', 'cache', $a['drive_type']);
checkEq('member keeps its own slot name', 'cache2', $a['display_name']);
$a = getUnraidAssignment('media_pool3', ['type' => 'Cache'], 'disks');
checkEq('media_pool3 belongs to pool "Media pool"', 'Media pool', $a['array_name']);
checkEq('non-cache pools are type pool', 'pool', $a['drive_type']);
checkEq('pool name strips trailing digits', 'media_pool', getPoolName('media_pool3'));

$cfgNoPool = array_merge(getDefaultConfig(), ['SHOW_POOL' => 'false']);
$fakeDrives = [
    ['device_id' => 'sdb', 'array_name' => 'Main Array', 'drive_type' => 'parity'],
    ['device_id' => 'sdc', 'array_name' => 'Main Array', 'drive_type' => 'array'],
    ['device_id' => 'nvme0n1', 'array_name' => 'Cache', 'drive_type' => 'cache'],
    ['device_id' => 'nvme1n1', 'array_name' => 'Cache', 'drive_type' => 'cache'],
    ['device_id' => 'sde', 'array_name' => 'Media pool', 'drive_type' => 'pool'],
    ['device_id' => 'sda', 'array_name' => 'USB Boot', 'drive_type' => 'flash'],
    ['device_id' => 'sdf', 'array_name' => 'Unassigned', 'drive_type' => 'unassigned'],
];
checkEq('SHOW_POOL=false hides other pools only', ['sdb', 'sdc', 'nvme0n1', 'nvme1n1', 'sda', 'sdf'],
    array_column(filterDrivesByType($fakeDrives, $cfgNoPool), 'device_id'));
checkEq('SHOW_CACHE=false hides the cache pool only', ['sdb', 'sdc', 'sde', 'sda', 'sdf'],
    array_column(filterDrivesByType($fakeDrives, array_merge(getDefaultConfig(), ['SHOW_CACHE' => 'false'])), 'device_id'));
$groups = groupDrives($fakeDrives);
checkEq('group order: array, pools, unassigned, flash',
    ['Main Array/parity', 'Main Array/array', 'Cache/cache', 'Media pool/pool', 'Unassigned/unassigned', 'USB Boot/flash'],
    array_map(fn($g) => $g['name'] . '/' . $g['type'], $groups));
checkEq('multi-device pool is one group', 2, count($groups[2]['drives']));

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
// 3.8 years -> lifecycle stays Minimal; the warnings make health "watch",
// which raises the overall risk to Elevated (not High - nothing critical,
// and a short replacement estimate no longer escalates the category)
checkEq('parity lifecycle not escalated', 'minimal_risk', $byDev['sdb']['age_category'] ?? null);
checkEq('parity health watch', 'watch', $byDev['sdb']['health_status'] ?? null);
checkEq('parity overall risk elevated', 'elevated_risk', $byDev['sdb']['risk_category'] ?? null);
checkEq('parity risk class matches category', 'age-elevated_risk', $byDev['sdb']['risk_class'] ?? null);
checkEq('parity risk label names SMART', 'Warning (SMART)', $byDev['sdb']['risk_label'] ?? null);

checkEq('standby drive POH from persistent cache', 39111, $byDev['sdc']['power_on_hours'] ?? null);
check('standby flagged', ($byDev['sdc']['is_standby'] ?? false) === true);

checkEq('NVMe POH parsed from text cache (no direct smartctl)', 24845, $byDev['nvme0n1']['power_on_hours'] ?? null);
checkEq('NVMe wear category minimal', 'minimal_risk', $byDev['nvme0n1']['age_category'] ?? null);
checkEq('NVMe prediction from wear indicator', 'percentage_used_measured', $byDev['nvme0n1']['replacement_prediction']['method'] ?? null);
checkEq('NVMe lifecycle label shows wear', 'Minimal Risk (wear 3%)', $byDev['nvme0n1']['age_label'] ?? null);

checkEq('SAS unassigned POH parsed', 33822, $byDev['sdd']['device_id'] === 'sdd' ? $byDev['sdd']['power_on_hours'] : null);
checkEq('unassigned type', 'unassigned', $byDev['sdd']['drive_type'] ?? null);
checkEq('unassigned device uses Unraid name', 'Dev 1', $byDev['sdd']['device_name'] ?? null);

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
checkEq('FAILED -> high risk', 'high_risk', $byDev['sdb']['risk_category'] ?? null);
checkEq('FAILED -> health failing', 'failing', $byDev['sdb']['health_status'] ?? null);
checkEq('FAILED -> label Failing (SMART)', 'Failing (SMART)', $byDev['sdb']['risk_label'] ?? null);
checkEq('FAILED leaves lifecycle untouched', 'minimal_risk', $byDev['sdb']['age_category'] ?? null);
$failedWarning = null;
foreach (($byDev['sdb']['health_warnings'] ?? []) as $warning) {
    if ($warning['attribute'] === 'smart_overall_failed') { $failedWarning = $warning; }
}
check('FAILED surfaced as first-class health warning', $failedWarning !== null);

// Healthy 7.6-year-old HDD, conservative mode: ~5 months to the 8-year
// target, but it must stay Moderate (6-8 yr band) - not High Risk "AFR ~4%+"
$ata = "ID# ATTRIBUTE_NAME          FLAG     VALUE WORST THRESH TYPE      UPDATED  WHEN_FAILED RAW_VALUE\n"
     . "  5 Reallocated_Sector_Ct   0x0033   100   100   005    Pre-fail  Always       -       0\n"
     . "  9 Power_On_Hours          0x0012   090   090   000    Old_age   Always       -       66600\n"
     . "197 Current_Pending_Sector  0x0022   100   100   000    Old_age   Always       -       0\n";
file_put_contents(DRIVEAGE_STATE_DIR . '/smart/disk7', $ata);
$old = getDriveInfo('sdx', ['ini' => [
    'name' => 'disk7', '_slot' => 'disk7', 'device' => 'sdx', 'id' => 'TEST_OLD_1', 'type' => 'Data',
    'sectors' => '1000', 'sector_size' => '512', 'temp' => '30', 'spundown' => '0'
], 'source' => 'disks'], getDefaultConfig(), 'C', []);
checkEq('7.6yr healthy: ~5 months to target', 5, $old['replacement_prediction']['months_remaining']);
checkEq('7.6yr healthy: stays moderate (no escalation)', 'moderate_risk', $old['risk_category']);
checkEq('7.6yr healthy: health ok', 'ok', $old['health_status']);
checkEq('7.6yr healthy: AFR label of its real band', 'Moderate Risk (AFR ~2%)', $old['risk_label']);

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
