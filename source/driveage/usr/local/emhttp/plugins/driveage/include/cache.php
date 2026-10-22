<?php
/**
 * DriveAge Plugin - Persistent Power-On-Hours Cache
 *
 * Remembers last-known power-on hours (plus model/serial) for drives that
 * are in standby, so spun-down drives still display meaningful data.
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
 * actually changed (write-on-change keeps flash writes near zero).
 *
 * Entries left by older versions under device-name keys are dropped on the
 * first write (they cannot be trusted to belong to the same drive).
 *
 * @param array $cache Existing cache map (from loadPohCache())
 * @param array $updates Map of cache key => ['power_on_hours','model','serial']
 * @return array Updated cache map
 */
function updatePohCache($cache, $updates) {
    $changed = false;

    foreach ($updates as $key => $u) {
        if (!is_numeric($u['power_on_hours'] ?? null) || $u['power_on_hours'] < 0) {
            continue;
        }
        $hours = intval($u['power_on_hours']);
        $existing = $cache[$key] ?? null;

        if ($existing === null || intval($existing['power_on_hours'] ?? -1) !== $hours) {
            $cache[$key] = [
                'power_on_hours' => $hours,
                'model'  => $u['model'] ?? ($existing['model'] ?? null),
                'serial' => $u['serial'] ?? ($existing['serial'] ?? null),
                'timestamp' => time()
            ];
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
