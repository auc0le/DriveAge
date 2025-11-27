<?php
/**
 * DriveAge Plugin - Security Functions
 *
 * Handles rate limiting, logging, and other security features
 */

// Rate limit storage
define('RATE_LIMIT_DIR', '/var/lib/driveage/ratelimit');
define('SECURITY_LOG_FILE', '/var/log/driveage_security.log');

/**
 * Initialize rate limit directory
 *
 * @return bool True if successful
 */
function initRateLimitDirectory() {
    if (!is_dir(RATE_LIMIT_DIR)) {
        if (!mkdir(RATE_LIMIT_DIR, 0700, true)) {
            error_log('DriveAge: Failed to create rate limit directory');
            return false;
        }
    }

    chmod(RATE_LIMIT_DIR, 0700);
    return true;
}

/**
 * Check and enforce rate limiting
 *
 * @param array $config Plugin configuration
 * @param bool $internalRequest Whether this is an internal dashboard request
 * @return bool True if request is allowed
 */
function checkRateLimit($config, $internalRequest = false) {
    // Check if API is enabled (only for external requests)
    // Internal dashboard requests are always allowed
    if (!$internalRequest && $config['API_ENABLED'] !== 'true') {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'API Disabled',
            'message' => 'The JSON API is currently disabled. Enable it in plugin settings.'
        ]);
        logSecurityEvent('api_access_denied', ['reason' => 'API disabled']);
        exit;
    }

    $clientIp = getClientIp();
    $limit = max(10, min(1000, intval($config['API_RATE_LIMIT'])));
    $window = 60; // 1 minute window

    if (!initRateLimitDirectory()) {
        // If we can't create rate limit dir, allow but log
        error_log('DriveAge: Rate limiting unavailable');
        return true;
    }

    $rateLimitFile = RATE_LIMIT_DIR . '/' . md5($clientIp) . '.json';

    // Load existing request data
    $requests = [];
    if (file_exists($rateLimitFile)) {
        $content = @file_get_contents($rateLimitFile);
        if ($content) {
            $requests = json_decode($content, true) ?: [];
        }
    }

    // Clean old requests (outside time window)
    $now = time();
    $requests = array_filter($requests, function($timestamp) use ($now, $window) {
        return ($now - $timestamp) < $window;
    });

    // Check if limit exceeded
    if (count($requests) >= $limit) {
        $oldestRequest = min($requests);
        $retryAfter = $window - ($now - $oldestRequest);

        http_response_code(429);
        header('Content-Type: application/json');
        header('Retry-After: ' . $retryAfter);

        echo json_encode([
            'error' => 'Rate limit exceeded',
            'message' => "Maximum $limit requests per minute allowed",
            'retry_after' => $retryAfter
        ]);

        logSecurityEvent('rate_limit_exceeded', [
            'ip' => $clientIp,
            'limit' => $limit,
            'requests' => count($requests)
        ]);

        exit;
    }

    // Add current request
    $requests[] = $now;

    // Save updated request log
    file_put_contents($rateLimitFile, json_encode(array_values($requests)), LOCK_EX);

    return true;
}

/**
 * Clean up old rate limit files
 *
 * @return void
 */
function cleanupRateLimitFiles() {
    if (!is_dir(RATE_LIMIT_DIR)) {
        return;
    }

    $files = glob(RATE_LIMIT_DIR . '/*.json');
    if (!$files) {
        return;
    }

    $now = time();
    $maxAge = 300; // 5 minutes

    foreach ($files as $file) {
        if (($now - @filemtime($file)) > $maxAge) {
            @unlink($file);
        }
    }
}

/**
 * Get client IP address
 *
 * @return string Client IP address
 */
function getClientIp() {
    // Check for proxied requests
    $ipSources = [
        'HTTP_X_FORWARDED_FOR',
        'HTTP_CLIENT_IP',
        'HTTP_X_REAL_IP',
        'REMOTE_ADDR'
    ];

    foreach ($ipSources as $source) {
        if (!empty($_SERVER[$source])) {
            $ip = $_SERVER[$source];

            // Handle comma-separated IPs (from proxy chains)
            if (strpos($ip, ',') !== false) {
                $ips = explode(',', $ip);
                $ip = trim($ips[0]);
            }

            // Validate IP address
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }

    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/**
 * Log security event
 *
 * @param string $event Event type
 * @param array $details Event details
 * @return bool True if logged successfully
 */
function logSecurityEvent($event, $details = []) {
    $timestamp = date('Y-m-d H:i:s');
    $ip = getClientIp();
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $requestUri = $_SERVER['REQUEST_URI'] ?? 'unknown';

    $logEntry = [
        'timestamp' => $timestamp,
        'event' => $event,
        'ip' => $ip,
        'user_agent' => substr($userAgent, 0, 200), // Limit length
        'request_uri' => $requestUri,
        'details' => $details
    ];

    $logLine = json_encode($logEntry) . "\n";

    // Ensure log directory exists
    $logDir = dirname(SECURITY_LOG_FILE);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    // Write to log file
    $result = @file_put_contents(SECURITY_LOG_FILE, $logLine, FILE_APPEND | LOCK_EX);

    // Rotate log if too large (> 10MB)
    if (file_exists(SECURITY_LOG_FILE) && filesize(SECURITY_LOG_FILE) > 10485760) {
        @rename(SECURITY_LOG_FILE, SECURITY_LOG_FILE . '.old');
    }

    return $result !== false;
}

/**
 * Generate CSRF token
 *
 * @return string CSRF token
 */
function generateCsrfToken() {
    if (session_status() === PHP_SESSION_NONE) {
        // Check if headers already sent (common in Unraid .page files)
        if (!headers_sent()) {
            @session_start();
        } else {
            // Can't start session - headers already sent
            // Generate a temporary token (won't persist across requests)
            return bin2hex(random_bytes(32));
        }
    }

    if (!isset($_SESSION)) {
        // Session not available, return temporary token
        return bin2hex(random_bytes(32));
    }

    if (empty($_SESSION['driveage_csrf_token'])) {
        $_SESSION['driveage_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['driveage_csrf_token'];
}

/**
 * Validate CSRF token
 *
 * @param string $token Token to validate
 * @return bool True if valid
 */
function validateCsrfToken($token) {
    if (session_status() === PHP_SESSION_NONE) {
        if (!headers_sent()) {
            @session_start();
        } else {
            // Can't validate without session
            return false;
        }
    }

    if (!isset($_SESSION)) {
        // Session not available
        return false;
    }

    $sessionToken = $_SESSION['driveage_csrf_token'] ?? '';

    if (empty($sessionToken) || empty($token)) {
        return false;
    }

    return hash_equals($sessionToken, $token);
}

/**
 * Require CSRF token for POST requests
 *
 * @return void Exits if validation fails
 */
function requireCsrfToken() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';

        if (!validateCsrfToken($token)) {
            http_response_code(403);
            logSecurityEvent('csrf_validation_failed', [
                'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
            ]);
            die('CSRF token validation failed. Please refresh the page and try again.');
        }
    }
}
