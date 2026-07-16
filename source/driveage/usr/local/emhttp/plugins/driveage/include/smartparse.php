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
 * Note: Unraid's cache files at /var/local/emhttp/smart/<dev> are written
 * with `smartctl -n standby -A`, which contains NO model/serial/overall-health
 * lines for ATA drives. Callers must source identity from disks.ini/devs.ini
 * and health from the .ssa files; the parser only fills what is present.
 */

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
            case 188: $data['hdd_command_timeout'] = $rawInt; break;
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
                case 188: $data['hdd_command_timeout'] = $rawInt; break;
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
