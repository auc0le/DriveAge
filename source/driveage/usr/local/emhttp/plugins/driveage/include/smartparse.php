<?php
/**
 * DriveAge Plugin - SMART Output Parsers
 *
 * Pure functions that turn smartctl output (text or JSON) into a normalized
 * drive-data array. No filesystem or shell access here - fully unit-testable.
 *
 * Handles the three formats found on Unraid systems:
 *  - ATA/SATA attribute tables  (smartctl -A / -a)
 *  - NVMe text health log       (smartctl -A / -a on nvme devices)
 *  - SCSI/SAS text output       (smartctl -a on SAS drives behind HBAs)
 *
 * Note: Unraid's cache files at /var/local/emhttp/smart/<name> are written
 * with `smartctl -n standby -A`, which contains NO model/serial/overall-health
 * lines for ATA drives. Callers must source identity from disks.ini/devs.ini
 * and health from the .ssa files; the parser only fills what is present.
 */

// NVMe SMART/Health log "Critical Warning" bits (NVMe base spec, Log 0x02)
define('DRIVEAGE_NVME_CW_SPARE',       0x01); // available spare below threshold
define('DRIVEAGE_NVME_CW_TEMPERATURE', 0x02); // temperature outside threshold
define('DRIVEAGE_NVME_CW_RELIABILITY', 0x04); // reliability degraded (media/internal errors)
define('DRIVEAGE_NVME_CW_READ_ONLY',   0x08); // media placed in read-only mode
define('DRIVEAGE_NVME_CW_BACKUP',      0x10); // volatile memory backup device failed
define('DRIVEAGE_NVME_CW_PMR',         0x20); // persistent memory region read-only/unreliable
// Bits that mean the drive itself is degraded and should be replaced
define('DRIVEAGE_NVME_CW_REPLACE_MASK', DRIVEAGE_NVME_CW_RELIABILITY | DRIVEAGE_NVME_CW_READ_ONLY
    | DRIVEAGE_NVME_CW_BACKUP | DRIVEAGE_NVME_CW_PMR);

/**
 * Decode an NVMe Critical Warning value into individual conditions.
 *
 * @param int|null $value Critical Warning byte
 * @return array List of ['bit','attribute','level','message','action','tooltip']
 */
function driveage_decode_nvme_critical_warning($value) {
    $value = intval($value ?? 0);
    $defs = [
        DRIVEAGE_NVME_CW_SPARE => ['nvme_spare_low', 'critical', 'Spare capacity below threshold', 'Replace soon',
            'The drive has used up its reserve of spare flash blocks - it is at the end of its wear life.'],
        DRIVEAGE_NVME_CW_TEMPERATURE => ['nvme_temperature', 'warning', 'Temperature over threshold', 'Improve cooling',
            'The drive reported a temperature outside its limits. This is a cooling problem, not a sign of wear - add airflow or a heatsink.'],
        DRIVEAGE_NVME_CW_RELIABILITY => ['nvme_reliability', 'critical', 'Reliability degraded', 'Replace ASAP',
            'The drive reports its reliability is degraded by media or internal errors.'],
        DRIVEAGE_NVME_CW_READ_ONLY => ['nvme_read_only', 'critical', 'Drive is read-only', 'Replace immediately',
            'The drive has switched its media to read-only mode, usually at end of life. Copy data off now.'],
        DRIVEAGE_NVME_CW_BACKUP => ['nvme_backup_failed', 'critical', 'Power-loss backup failed', 'Replace ASAP',
            'The volatile memory backup device (power-loss protection) has failed.'],
        DRIVEAGE_NVME_CW_PMR => ['nvme_pmr_read_only', 'critical', 'Persistent memory region read-only', 'Replace ASAP',
            'The persistent memory region has become read-only or unreliable.'],
    ];

    $conditions = [];
    $known = 0;
    foreach ($defs as $bit => [$attribute, $level, $message, $action, $tooltip]) {
        $known |= $bit;
        if ($value & $bit) {
            $conditions[] = compact('bit', 'attribute', 'level', 'message', 'action', 'tooltip');
        }
    }
    if ($value & ~$known & 0xFF) {
        $conditions[] = [
            'bit' => $value & ~$known & 0xFF,
            'attribute' => 'critical_warning',
            'level' => 'warning',
            'message' => sprintf('Critical warning 0x%02X', $value),
            'action' => 'Check drive',
            'tooltip' => 'The drive set a critical-warning bit DriveAge does not recognize.'
        ];
    }
    return $conditions;
}

/**
 * Extract the leading integer from a smartctl RAW_VALUE field.
 * Handles vendor formats like "34 (Min/Max 21/49)", "33477h+12m+30.271s",
 * "0/0/0" and plain integers.
 *
 * @param mixed $raw Raw value field
 * @return int|null Leading integer, or null if none
 */
function driveage_extract_leading_int($raw) {
    if ($raw === null) {
        return null;
    }
    if (preg_match('/^(\d+)/', ltrim((string)$raw), $m)) {
        return intval($m[1]);
    }
    return null;
}

/**
 * SMART 188 (Command_Timeout) raw value is a composite on many drives: three
 * 16-bit counters packed into 48 bits. The low 16 bits are the total count.
 *
 * @param int $raw Raw value
 * @return int Timeout count
 */
function driveage_mask_command_timeout($raw) {
    return ($raw > 0xFFFF) ? ($raw & 0xFFFF) : $raw;
}

/**
 * Convert NVMe Data Units (512-byte units x 1000) to TB written (decimal).
 *
 * @param int|null $dataUnits
 * @return float|null TB written, rounded to 2 decimals
 */
function driveage_calc_tbw($dataUnits) {
    if ($dataUnits === null || $dataUnits <= 0) {
        return null;
    }
    return round(($dataUnits * 512000) / 1000000000000, 2);
}

/**
 * Return an empty normalized SMART data structure.
 */
function driveage_empty_smart_data() {
    return [
        'model'           => null,
        'serial'          => null,
        'smart_status'    => 'UNKNOWN',
        'temperature'     => null,
        'power_on_hours'  => null,
        'spin_status'     => 'unknown',
        'is_standby'      => false,
        'has_ata_attrs'   => false,
        'has_nvme_log'    => false,
        // HDD critical attributes
        'hdd_reallocated_sectors'     => null,
        'hdd_pending_sectors'         => null,
        'hdd_uncorrectable_sectors'   => null,
        'hdd_reported_uncorrectable'  => null,
        'hdd_command_timeout'         => null,
        // NVMe health attributes
        'nvme_percentage_used'          => null,
        'nvme_available_spare'          => null,
        'nvme_available_spare_threshold'=> null,
        'nvme_data_units_written'       => null,
        'nvme_data_units_read'          => null,
        'nvme_media_errors'             => null,
        'nvme_critical_warning'         => null,
        'nvme_tbw_calculated'           => null
    ];
}

/**
 * Parse smartctl TEXT output (ATA attribute table, NVMe health log, or SCSI).
 *
 * @param string $output Raw smartctl output
 * @return array|null Normalized data array, or null for empty input
 */
function driveage_parse_smart_text($output) {
    if ($output === null || trim($output) === '') {
        return null;
    }

    $data = driveage_empty_smart_data();

    // Standby detection: smartctl -n standby prints this and exits early
    if (preg_match('/Device is in (STANDBY|SLEEP) mode/i', $output)) {
        $data['is_standby'] = true;
        $data['spin_status'] = 'standby';
        return $data;
    }

    // Power mode line (present in full -a output)
    if (preg_match('/Power mode (?:is|was):\s+(\S+)/i', $output, $m)) {
        $mode = strtoupper($m[1]);
        if (strpos($mode, 'STANDBY') !== false || strpos($mode, 'SLEEP') !== false) {
            $data['spin_status'] = 'standby';
            $data['is_standby'] = true;
        } else {
            $data['spin_status'] = 'active';
        }
    }

    // Identity lines (present only in full -a / -i output)
    if (preg_match('/^(?:Device Model|Model Number|Product):\s+(.+)$/mi', $output, $m)) {
        $data['model'] = trim($m[1]);
    }
    if (preg_match('/^Serial [Nn]umber:\s+(.+)$/m', $output, $m)) {
        $data['serial'] = trim($m[1]);
    }

    // Overall health (ATA/NVMe full output)
    if (preg_match('/SMART overall-health self-assessment test result:\s+(\S+)/i', $output, $m)) {
        $data['smart_status'] = (stripos($m[1], 'PASSED') !== false) ? 'PASSED' : 'FAILED';
    }
    // Overall health (SCSI)
    if (preg_match('/SMART Health Status:\s+(.+)$/mi', $output, $m)) {
        $data['smart_status'] = (stripos(trim($m[1]), 'OK') === 0) ? 'PASSED' : 'FAILED';
    }

    // ---- ATA attribute table ----
    // Format: ID# ATTRIBUTE_NAME FLAG VALUE WORST THRESH TYPE UPDATED WHEN_FAILED RAW_VALUE
    $temp190 = null;
    foreach (explode("\n", $output) as $line) {
        if (!preg_match('/^\s*\d{1,3}\s+\S+\s+/', $line)) {
            continue;
        }
        $cols = preg_split('/\s+/', trim($line));
        if (count($cols) < 10 || !ctype_digit($cols[0])) {
            continue;
        }
        $attrId = intval($cols[0]);
        // RAW_VALUE is column 10 onward (vendor formats may contain spaces)
        $rawField = implode(' ', array_slice($cols, 9));
        $rawInt = driveage_extract_leading_int($rawField);
        if ($rawInt === null) {
            continue;
        }

        $data['has_ata_attrs'] = true;

        switch ($attrId) {
            case 5:   $data['hdd_reallocated_sectors'] = $rawInt; break;
            case 9:   $data['power_on_hours'] = $rawInt; break;
            case 187: $data['hdd_reported_uncorrectable'] = $rawInt; break;
            case 188: $data['hdd_command_timeout'] = driveage_mask_command_timeout($rawInt); break;
            case 190: $temp190 = $rawInt; break;
            case 194: $data['temperature'] = $rawInt; break;
            case 197: $data['hdd_pending_sectors'] = $rawInt; break;
            case 198: $data['hdd_uncorrectable_sectors'] = $rawInt; break;
        }
    }
    // Prefer 194 (Temperature_Celsius); fall back to 190 (Airflow_Temperature)
    if ($data['temperature'] === null && $temp190 !== null) {
        $data['temperature'] = $temp190;
    }

    // ---- NVMe text health log ----
    // "SMART/Health Information (NVMe Log 0x02)" style key/value lines
    if (preg_match('/^Percentage Used:\s+([\d,]+)\s*%/mi', $output, $m)) {
        $data['has_nvme_log'] = true;
        $data['nvme_percentage_used'] = intval(str_replace(',', '', $m[1]));
    }
    if (preg_match('/^Available Spare:\s+([\d,]+)\s*%/mi', $output, $m)) {
        $data['has_nvme_log'] = true;
        $data['nvme_available_spare'] = intval(str_replace(',', '', $m[1]));
    }
    if (preg_match('/^Available Spare Threshold:\s+([\d,]+)\s*%/mi', $output, $m)) {
        $data['nvme_available_spare_threshold'] = intval(str_replace(',', '', $m[1]));
    }
    if (preg_match('/^Data Units Written:\s+([\d,]+)/mi', $output, $m)) {
        $data['nvme_data_units_written'] = intval(str_replace(',', '', $m[1]));
    }
    if (preg_match('/^Data Units Read:\s+([\d,]+)/mi', $output, $m)) {
        $data['nvme_data_units_read'] = intval(str_replace(',', '', $m[1]));
    }
    if (preg_match('/^Media and Data Integrity Errors:\s+([\d,]+)/mi', $output, $m)) {
        $data['nvme_media_errors'] = intval(str_replace(',', '', $m[1]));
    }
    if (preg_match('/^Critical Warning:\s+(0x[0-9a-f]+|\d+)/mi', $output, $m)) {
        $data['nvme_critical_warning'] = (stripos($m[1], '0x') === 0) ? hexdec($m[1]) : intval($m[1]);
    }
    if ($data['has_nvme_log']) {
        if (preg_match('/^Power On Hours:\s+([\d,]+)/mi', $output, $m)) {
            $data['power_on_hours'] = intval(str_replace(',', '', $m[1]));
        }
        if (preg_match('/^Temperature:\s+([\d,]+)\s*Celsius/mi', $output, $m)) {
            $data['temperature'] = intval(str_replace(',', '', $m[1]));
        }
        $data['nvme_tbw_calculated'] = driveage_calc_tbw($data['nvme_data_units_written']);
        // NVMe drives do not spin
        if ($data['spin_status'] === 'unknown') {
            $data['spin_status'] = 'active';
        }
    }

    // ---- SCSI/SAS text output ----
    if ($data['power_on_hours'] === null &&
        preg_match('/number of hours powered up\s*=\s*([\d.]+)/i', $output, $m)) {
        $data['power_on_hours'] = intval(floor(floatval($m[1])));
    }
    if ($data['temperature'] === null &&
        preg_match('/Current Drive Temperature:\s+(\d+)\s*C/i', $output, $m)) {
        $data['temperature'] = intval($m[1]);
    }

    // If the attribute table parsed and we have no explicit power mode,
    // the drive answered a query - treat as active
    if ($data['spin_status'] === 'unknown' &&
        ($data['has_ata_attrs'] || $data['power_on_hours'] !== null)) {
        $data['spin_status'] = 'active';
    }

    return $data;
}

/**
 * Parse smartctl JSON output (smartctl -a -j).
 *
 * Uses smartctl's universal fields (power_on_time, temperature, smart_status)
 * so ATA, NVMe and SCSI drives are all covered, then adds type-specific data.
 *
 * @param array $json Decoded JSON from smartctl -j
 * @return array|null Normalized data array, or null for invalid input
 */
function driveage_parse_smart_json($json) {
    if (!is_array($json) || empty($json)) {
        return null;
    }

    $data = driveage_empty_smart_data();
    $data['spin_status'] = 'active'; // drive answered a full query

    $data['model']  = $json['model_name'] ?? $json['model_family'] ?? null;
    $data['serial'] = $json['serial_number'] ?? null;

    if (isset($json['smart_status']['passed'])) {
        $data['smart_status'] = $json['smart_status']['passed'] ? 'PASSED' : 'FAILED';
    }

    // Universal fields (ATA, NVMe and SCSI)
    if (isset($json['power_on_time']['hours'])) {
        $data['power_on_hours'] = intval($json['power_on_time']['hours']);
    }
    if (isset($json['temperature']['current'])) {
        $data['temperature'] = intval($json['temperature']['current']);
    }

    // ATA attribute table
    if (isset($json['ata_smart_attributes']['table']) && is_array($json['ata_smart_attributes']['table'])) {
        $data['has_ata_attrs'] = true;
        foreach ($json['ata_smart_attributes']['table'] as $attr) {
            $id = $attr['id'] ?? null;
            $rawInt = driveage_extract_leading_int($attr['raw']['string'] ?? ($attr['raw']['value'] ?? null));
            if ($id === null || $rawInt === null) {
                continue;
            }
            switch ($id) {
                case 5:   $data['hdd_reallocated_sectors'] = $rawInt; break;
                case 9:
                    if ($data['power_on_hours'] === null) {
                        $data['power_on_hours'] = $rawInt;
                    }
                    break;
                case 187: $data['hdd_reported_uncorrectable'] = $rawInt; break;
                case 188: $data['hdd_command_timeout'] = driveage_mask_command_timeout($rawInt); break;
                case 194:
                    if ($data['temperature'] === null) {
                        $data['temperature'] = $rawInt;
                    }
                    break;
                case 197: $data['hdd_pending_sectors'] = $rawInt; break;
                case 198: $data['hdd_uncorrectable_sectors'] = $rawInt; break;
            }
        }
    }

    // NVMe health log
    if (isset($json['nvme_smart_health_information_log'])) {
        $h = $json['nvme_smart_health_information_log'];
        $data['has_nvme_log'] = true;
        $data['nvme_percentage_used']           = $h['percentage_used'] ?? null;
        $data['nvme_available_spare']           = $h['available_spare'] ?? null;
        $data['nvme_available_spare_threshold'] = $h['available_spare_threshold'] ?? 10;
        $data['nvme_data_units_written']        = $h['data_units_written'] ?? null;
        $data['nvme_data_units_read']           = $h['data_units_read'] ?? null;
        $data['nvme_media_errors']              = $h['media_errors'] ?? 0;
        $data['nvme_critical_warning']          = $h['critical_warning'] ?? 0;
        $data['nvme_tbw_calculated']            = driveage_calc_tbw($data['nvme_data_units_written']);
        if ($data['power_on_hours'] === null && isset($h['power_on_hours'])) {
            $data['power_on_hours'] = intval(floor($h['power_on_hours']));
        }
        if ($data['temperature'] === null && isset($h['temperature'])) {
            $data['temperature'] = intval($h['temperature']);
        }
    }

    return $data;
}
