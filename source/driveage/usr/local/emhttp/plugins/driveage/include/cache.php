<?php
/**
 * DriveAge Plugin - Persistent Drive Cache (power-on hours + history)
 *
 * Remembers last-known power-on hours (plus model/serial) for drives that
 * are in standby, so spun-down drives still display meaningful data, and a
 * small history per drive:
 *  - error counts (5/187/197/198, SAS defects/errors): current value, when
 *    it was set, and the value before the last increase - so a stable
 *    defect can be told apart from a growing one
 *  - weekly host-write samples for SSDs/NVMe - so projections can use the
 *    recent write rate, not only the lifetime average
 *
 * Stored as ONE JSON file on the flash device (/boot) so it survives
 * reboots - a drive that boots spun-down keeps its last-known values.
 * Entries are keyed by Unraid's disk id (model_serial), NOT the kernel
 * device name: sdX letters can change between boots, and a device-keyed
 * entry would then hand one drive's hours to another.
 * Flash wear is a non-issue: the file is rewritten only when a value
 * actually changes (POH advances ~1/hour per drive, and only while the
 * dashboard is being used).
 */

if (!defined('DRIVEAGE_POH_CACHE_FILE')) {
    define('DRIVEAGE_POH_CACHE_FILE', '/boot/config/plugins/driveage/drivecache.json');
}

// Cache validity period for the "stale" indicator (24 hours)
define('DRIVEAGE_CACHE_STALE_SECONDS', 86400);

// A count that rose within this window is "growing"; unchanged this long, "stable"
define('DRIVEAGE_TREND_WINDOW_SECONDS', 30 * 86400);

// Host-write samples: at most one per week, ~6 months kept
define('DRIVEAGE_WRITE_SAMPLE_SECONDS', 7 * 86400);
define('DRIVEAGE_WRITE_SAMPLES_MAX', 26);

// Recent write rate: measured over (up to) this window, needs >= 30 days
define('DRIVEAGE_RECENT_RATE_WINDOW_SECONDS', 120 * 86400);

// Error counts tracked for trends
const DRIVEAGE_TREND_FIELDS = [
    'hdd_reallocated_sectors', 'hdd_reported_uncorrectable', 'hdd_pending_sectors',
    'hdd_uncorrectable_sectors', 'scsi_grown_defects', 'scsi_uncorrected_errors'
];

/**
 * Stable cache key for a drive.
 *
 * @param string $deviceName Device name (e.g. sdb)
 * @param array $ini Ini entry for this device
 * @return string Unraid disk id (model_serial), or "dev:<device>" when the
 *                ini carries no id (last-resort /dev glob enumeration)
 */
function getDriveCacheKey($deviceName, $ini) {
    $id = trim((string)($ini['id'] ?? ''));
    return $id !== '' ? $id : 'dev:' . $deviceName;
}

/**
 * True for keys written by older versions, which keyed entries by the
 * (unstable) kernel device name.
 */
function isLegacyCacheKey($key) {
    return preg_match('/^(sd[a-z]{1,3}|nvme\d+n\d+)$/', (string)$key) === 1;
}

/**
 * Load the whole POH cache.
 *
 * @return array Map of cache key => ['power_on_hours','model','serial','timestamp']
 */
function loadPohCache() {
    $file = DRIVEAGE_POH_CACHE_FILE;

    if (!file_exists($file) || is_link($file)) {
        return [];
    }

    $json = @file_get_contents($file);
    if ($json === false || $json === '') {
        return [];
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        error_log('DriveAge: Invalid POH cache file, ignoring');
        return [];
    }

    return $data;
}

/**
 * Get the cached entry for one drive.
 *
 * @param array $cache Cache map from loadPohCache()
 * @param string $key Cache key from getDriveCacheKey()
 * @return array|null Entry or null
 */
function getPohCacheEntry($cache, $key) {
    $entry = $cache[$key] ?? null;
    if ($entry === null) {
        return null;
    }
    if (!isset($entry['power_on_hours']) || !is_numeric($entry['power_on_hours']) ||
        !isset($entry['timestamp']) || !is_numeric($entry['timestamp'])) {
        return null;
    }
    return $entry;
}

/**
 * Merge fresh readings into the cache and persist - but ONLY if something
 * actually changed (write-on-change keeps flash writes near zero: POH moves
 * ~hourly, error counts rarely, write samples at most weekly).
 *
 * Entries left by older versions under device-name keys are dropped on the
 * first write (they cannot be trusted to belong to the same drive).
 *
 * @param array $cache Existing cache map (from loadPohCache())
 * @param array $updates Map of cache key => ['power_on_hours', 'model',
 *                       'serial', 'attrs' => [field => int], 'tbw' => float]
 *                       (power_on_hours null = keep the cached value)
 * @param int|null $now Current time (tests)
 * @return array Updated cache map
 */
function updatePohCache($cache, $updates, $now = null) {
    $now = $now ?? time();
    $changed = false;

    foreach ($updates as $key => $u) {
        $before = $cache[$key] ?? [];
        $entry = $before;

        // Power-on hours (+ identity), timestamped when the value changes
        if (is_numeric($u['power_on_hours'] ?? null) && $u['power_on_hours'] >= 0) {
            $hours = intval($u['power_on_hours']);
            if (intval($entry['power_on_hours'] ?? -1) !== $hours) {
                $entry['power_on_hours'] = $hours;
                $entry['model'] = $u['model'] ?? ($entry['model'] ?? null);
                $entry['serial'] = $u['serial'] ?? ($entry['serial'] ?? null);
                $entry['timestamp'] = $now;
            }
        }

        foreach ($u['attrs'] ?? [] as $field => $value) {
            if ($value !== null && is_numeric($value)) {
                $entry['attrs'][$field] = recordAttributeValue($entry['attrs'][$field] ?? null, intval($value), $now);
            }
        }

        if (isset($u['tbw']) && is_numeric($u['tbw'])) {
            $entry['writes'] = recordWriteSample($entry['writes'] ?? [], floatval($u['tbw']), $now);
        }

        if ($entry !== $before) {
            $cache[$key] = $entry;
            $changed = true;
        }
    }

    if ($changed) {
        foreach (array_keys($cache) as $key) {
            if (isLegacyCacheKey($key)) {
                unset($cache[$key]);
            }
        }
        savePohCache($cache);
    }

    return $cache;
}

/**
 * Update one error-count record with a fresh reading.
 *
 * Record: value, since (it has held this value since), prev / prev_since
 * (the value before the last increase, and since when that held).
 *
 * @param array|null $record Stored record
 * @param int $value Current reading
 * @param int $now Current time
 * @return array Record (identical array when nothing changed)
 */
function recordAttributeValue($record, $value, $now) {
    if (!is_array($record) || !isset($record['value'])) {
        return ['value' => $value, 'since' => $now, 'prev' => null, 'prev_since' => null];
    }
    $stored = intval($record['value']);
    if ($value === $stored) {
        return $record;
    }
    if ($value > $stored) {
        return ['value' => $value, 'since' => $now, 'prev' => $stored, 'prev_since' => $record['since'] ?? $now];
    }
    // Count went down (pending sectors resolved, counter reset): start over
    return ['value' => $value, 'since' => $now, 'prev' => null, 'prev_since' => null];
}

/**
 * Trend of an error count, from its stored record and the current reading.
 *
 * - growing: higher than stored now, or rose within the trend window
 * - stable:  unchanged for at least the trend window
 * - new:     no record yet, too little history, or just decreased
 *
 * @param array|null $record Stored record (recordAttributeValue())
 * @param int|null $current Current reading
 * @param int|null $now Current time
 * @return array ['trend' => string|null, 'previous' => int|null, 'since' => int|null]
 *               previous/since describe the value before the increase
 */
function getAttributeTrend($record, $current, $now = null) {
    $now = $now ?? time();
    if ($current === null) {
        return ['trend' => null, 'previous' => null, 'since' => null];
    }
    if (!is_array($record) || !isset($record['value'])) {
        return ['trend' => 'new', 'previous' => null, 'since' => null];
    }
    $stored = intval($record['value']);
    $since = intval($record['since'] ?? $now);

    if ($current > $stored) {
        return ['trend' => 'growing', 'previous' => $stored, 'since' => $since];
    }
    if ($current < $stored) {
        return ['trend' => 'new', 'previous' => null, 'since' => null];
    }
    if (($record['prev'] ?? null) !== null && $now - $since <= DRIVEAGE_TREND_WINDOW_SECONDS) {
        return ['trend' => 'growing', 'previous' => intval($record['prev']), 'since' => intval($record['prev_since'] ?? $since)];
    }
    if ($now - $since >= DRIVEAGE_TREND_WINDOW_SECONDS) {
        return ['trend' => 'stable', 'previous' => null, 'since' => $since];
    }
    return ['trend' => 'new', 'previous' => null, 'since' => null];
}

/**
 * Append a host-write sample (TB) if the last one is a week old or more.
 *
 * @param array $samples List of [timestamp, tb], oldest first
 * @param float $tbw Current total TB written
 * @param int $now Current time
 * @return array Samples (identical array when nothing was added)
 */
function recordWriteSample($samples, $tbw, $now) {
    $last = $samples ? $samples[count($samples) - 1] : null;
    if ($last !== null && $tbw < floatval($last[1])) {
        $samples = []; // counter went backwards (drive reset/replaced): start over
    } elseif ($last !== null && $now - intval($last[0]) < DRIVEAGE_WRITE_SAMPLE_SECONDS) {
        return $samples;
    }
    $samples[] = [$now, round($tbw, 2)];
    return array_slice($samples, -DRIVEAGE_WRITE_SAMPLES_MAX);
}

/**
 * Recent host-write rate (TB per calendar year) from the weekly samples:
 * measured from the oldest sample inside the window, needing >= 30 days.
 *
 * @param array $samples List of [timestamp, tb], oldest first
 * @param float|null $tbwNow Current total TB written
 * @param int|null $now Current time
 * @return float|null TB/year, or null without enough history
 */
function getRecentWriteRate($samples, $tbwNow, $now = null) {
    $now = $now ?? time();
    if ($tbwNow === null || empty($samples)) {
        return null;
    }
    foreach ($samples as $sample) {
        $age = $now - intval($sample[0]);
        if ($age <= DRIVEAGE_RECENT_RATE_WINDOW_SECONDS) {
            if ($age < DRIVEAGE_TREND_WINDOW_SECONDS) {
                return null; // oldest usable sample is too recent
            }
            $rate = ($tbwNow - floatval($sample[1])) / ($age / 31557600);
            return $rate >= 0 ? round($rate, 2) : null;
        }
    }
    return null;
}

/**
 * Persist the cache map atomically.
 *
 * @param array $cache Cache map
 * @return bool True on success
 */
function savePohCache($cache) {
    $file = DRIVEAGE_POH_CACHE_FILE;
    $dir = dirname($file);

    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        error_log('DriveAge: Failed to create cache directory: ' . $dir);
        return false;
    }

    $json = json_encode($cache);
    if ($json === false) {
        error_log('DriveAge: Failed to encode POH cache');
        return false;
    }

    // Atomic-ish write: temp file then rename
    $tmp = $file . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        error_log('DriveAge: Failed to write POH cache');
        return false;
    }
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        error_log('DriveAge: Failed to replace POH cache file');
        return false;
    }

    return true;
}

/**
 * Check if a cache timestamp is stale (> 24 hours old)
 *
 * @param int $cacheTimestamp Unix timestamp from cache
 * @return bool True if stale
 */
function isCacheStale($cacheTimestamp) {
    if (!is_numeric($cacheTimestamp)) {
        return true;
    }
    return (time() - intval($cacheTimestamp)) > DRIVEAGE_CACHE_STALE_SECONDS;
}
