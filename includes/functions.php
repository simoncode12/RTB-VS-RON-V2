<?php
/**
 * Enhanced Functions for RTB vs RON Platform
 * Version: 2.0.0
 * Date: 2025-06-25
 * Author: Enhanced by AI Assistant
 */

/**
 * Enhanced error handling and logging
 */
function logError($message, $context = []) {
    $timestamp = date('Y-m-d H:i:s');
    $contextStr = empty($context) ? '' : ' | Context: ' . json_encode($context);
    error_log("[$timestamp] RTB Platform Error: $message $contextStr");
}

/**
 * Enhanced security function for input sanitization
 */
function sanitizeInput($input, $type = 'string') {
    if (is_array($input)) {
        return array_map(function($item) use ($type) {
            return sanitizeInput($item, $type);
        }, $input);
    }
    
    switch ($type) {
        case 'email':
            return filter_var(trim($input), FILTER_SANITIZE_EMAIL);
        case 'url':
            return filter_var(trim($input), FILTER_SANITIZE_URL);
        case 'int':
            return intval($input);
        case 'float':
            return floatval($input);
        case 'html':
            return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
        case 'json':
            return json_encode($input, JSON_UNESCAPED_UNICODE);
        default:
            return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Enhanced validation functions
 */
function validateCampaignData($data) {
    $errors = [];
    
    if (empty($data['name'])) {
        $errors[] = 'Campaign name is required';
    } elseif (strlen($data['name']) < 3) {
        $errors[] = 'Campaign name must be at least 3 characters';
    }
    
    if (empty($data['advertiser_id']) || !is_numeric($data['advertiser_id'])) {
        $errors[] = 'Valid advertiser is required';
    }
    
    if (!empty($data['daily_budget']) && $data['daily_budget'] <= 0) {
        $errors[] = 'Daily budget must be greater than 0';
    }
    
    if (!empty($data['total_budget']) && $data['total_budget'] <= 0) {
        $errors[] = 'Total budget must be greater than 0';
    }
    
    if (!empty($data['daily_budget']) && !empty($data['total_budget']) && 
        $data['daily_budget'] > $data['total_budget']) {
        $errors[] = 'Daily budget cannot exceed total budget';
    }
    
    if (!empty($data['start_date']) && !empty($data['end_date'])) {
        $start = new DateTime($data['start_date']);
        $end = new DateTime($data['end_date']);
        if ($end <= $start) {
            $errors[] = 'End date must be after start date';
        }
    }
    
    return $errors;
}

/**
 * Performance monitoring
 */
class PerformanceMonitor {
    private static $timers = [];
    
    public static function start($name) {
        self::$timers[$name] = microtime(true);
    }
    
    public static function end($name) {
        if (isset(self::$timers[$name])) {
            $elapsed = (microtime(true) - self::$timers[$name]) * 1000;
            error_log("Performance: $name took {$elapsed}ms");
            unset(self::$timers[$name]);
            return $elapsed;
        }
        return null;
    }
}

/**
 * Cache management
 */
class SimpleCache {
    private static $cache = [];
    private static $ttl = [];
    
    public static function set($key, $value, $ttl = 300) {
        self::$cache[$key] = $value;
        self::$ttl[$key] = time() + $ttl;
    }
    
    public static function get($key) {
        if (isset(self::$cache[$key])) {
            if (time() <= self::$ttl[$key]) {
                return self::$cache[$key];
            } else {
                unset(self::$cache[$key], self::$ttl[$key]);
            }
        }
        return null;
    }
    
    public static function clear($key = null) {
        if ($key) {
            unset(self::$cache[$key], self::$ttl[$key]);
        } else {
            self::$cache = [];
            self::$ttl = [];
        }
    }
}

/**
 * Enhanced targeting validation
 */
function validateTargeting($targeting) {
    $errors = [];
    
    if (isset($targeting['countries']) && is_string($targeting['countries'])) {
        $countries = json_decode($targeting['countries'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errors[] = 'Invalid country targeting format';
        }
    }
    
    if (isset($targeting['devices']) && is_string($targeting['devices'])) {
        $devices = json_decode($targeting['devices'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errors[] = 'Invalid device targeting format';
        }
    }
    
    return $errors;
}

/**
 * Budget calculation helpers
 */
function calculateBudgetProjection($dailyBudget, $startDate, $endDate) {
    if (!$dailyBudget || !$startDate || !$endDate) {
        return null;
    }
    
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    $days = $end->diff($start)->days + 1;
    
    return [
        'days' => $days,
        'total_budget' => $dailyBudget * $days,
        'weekly_budget' => $dailyBudget * 7,
        'monthly_budget' => $dailyBudget * 30
    ];
}

/**
 * Campaign status helper
 */
function getCampaignStatus($campaign) {
    $now = new DateTime();
    $start = new DateTime($campaign['start_date']);
    $end = $campaign['end_date'] ? new DateTime($campaign['end_date']) : null;
    
    if ($campaign['status'] !== 'active') {
        return $campaign['status'];
    }
    
    if ($now < $start) {
        return 'scheduled';
    }
    
    if ($end && $now > $end) {
        return 'completed';
    }
    
    if ($campaign['daily_spent'] >= $campaign['daily_budget']) {
        return 'budget_exceeded';
    }
    
    return 'active';
}

/**
 * Response helper for AJAX
 */
function jsonResponse($success, $data = null, $message = null) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message,
        'timestamp' => time()
    ]);
    exit;
}

/**
 * Enhanced database query with error handling
 */
function safeQuery($pdo, $sql, $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        logError("Database query failed: " . $e->getMessage(), ['sql' => $sql, 'params' => $params]);
        return false;
    }
}

/**
 * Generate secure tokens
 */
function generateSecureToken($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Rate limiting helper
 */
class RateLimiter {
    private static $requests = [];
    
    public static function checkLimit($key, $maxRequests = 100, $window = 3600) {
        $now = time();
        $windowStart = $now - $window;
        
        if (!isset(self::$requests[$key])) {
            self::$requests[$key] = [];
        }
        
        // Clean old requests
        self::$requests[$key] = array_filter(self::$requests[$key], function($timestamp) use ($windowStart) {
            return $timestamp > $windowStart;
        });
        
        if (count(self::$requests[$key]) >= $maxRequests) {
            return false;
        }
        
        self::$requests[$key][] = $now;
        return true;
    }
}

/**
 * Enhanced format functions
 */
function formatBudget($amount, $currency = 'USD') {
    if ($currency === 'USD') {
        return '$' . number_format($amount, 2);
    }
    return number_format($amount, 2) . ' ' . $currency;
}

function formatPercentage($value, $total) {
    if ($total == 0) return '0%';
    return round(($value / $total) * 100, 1) . '%';
}

function formatTimeAgo($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'just now';
    if ($time < 3600) return floor($time/60) . ' minutes ago';
    if ($time < 86400) return floor($time/3600) . ' hours ago';
    if ($time < 2592000) return floor($time/86400) . ' days ago';
    
    return date('M j, Y', strtotime($datetime));
}

/**
 * System health check
 */
function checkSystemHealth($pdo) {
    $health = [
        'database' => false,
        'memory' => false,
        'disk' => false,
        'timestamp' => time()
    ];
    
    // Database check
    try {
        $pdo->query('SELECT 1');
        $health['database'] = true;
    } catch (Exception $e) {
        logError('Database health check failed: ' . $e->getMessage());
    }
    
    // Memory check (warn if over 80%)
    $memoryUsage = memory_get_usage(true);
    $memoryLimit = ini_get('memory_limit');
    if ($memoryLimit !== '-1') {
        $memoryLimitBytes = convertToBytes($memoryLimit);
        $health['memory'] = ($memoryUsage / $memoryLimitBytes) < 0.8;
    } else {
        $health['memory'] = true;
    }
    
    // Disk space check (warn if less than 1GB free)
    $freeBytes = disk_free_space('.');
    $health['disk'] = $freeBytes > (1024 * 1024 * 1024); // 1GB
    
    return $health;
}

function convertToBytes($val) {
    $val = trim($val);
    $last = strtolower($val[strlen($val)-1]);
    $val = intval($val);
    switch($last) {
        case 'g': $val *= 1024;
        case 'm': $val *= 1024;
        case 'k': $val *= 1024;
    }
    return $val;
}
?>