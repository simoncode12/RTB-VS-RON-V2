<?php
/**
 * RTB & RON Platform - Bid Request Handler
 * Processes incoming bid requests and selects winning bid
 * Version: 2.0.0 (Enhanced with unified bidding engine)
 * Date: 2025-06-25
 * Author: simoncode12 + AI Assistant
 */

require_once '../config/database.php';
require_once '../includes/BiddingEngine.php';

// Get request data
$requestData = $_POST;
$zoneId = isset($requestData['zone_id']) ? intval($requestData['zone_id']) : 0;
$containerKey = isset($requestData['container']) ? $requestData['container'] : '';

// Check if zone exists and is active
$stmt = $pdo->prepare("SELECT * FROM zones WHERE id = ? AND status = 'active'");
$stmt->execute([$zoneId]);
$zone = $stmt->fetch();

if (!$zone) {
    // Zone not found or not active
    echo json_encode([
        'success' => false,
        'error' => 'Invalid or inactive zone'
    ]);
    exit;
}

// Extract data
$size = $zone['size'];
list($width, $height) = explode('x', $size);

// Get user data
$ip = $_SERVER['REMOTE_ADDR'];
$userAgent = $_SERVER['HTTP_USER_AGENT'];
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$deviceType = detectDevice($userAgent);
$browser = detectBrowser($userAgent);
$os = detectOS($userAgent);
$country = getCountry($ip);

// Log request
error_log("AdStart Request - Zone: $zoneId, Container: $containerKey, Size: $size");

// Initialize the enhanced bidding engine
$bidding_engine = new BiddingEngine($pdo, true); // Enable debug mode

// Prepare request data for the bidding engine
$request_data = [
    'zone_id' => $zoneId,
    'width' => intval($width),
    'height' => intval($height),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'referer' => $_SERVER['HTTP_REFERER'] ?? '',
    'container' => $containerKey
];

// Process the bid request using the new unified engine
$winning_bid = $bidding_engine->processBidRequest($request_data);

if ($winning_bid) {
    // Prepare ad content based on winning bid
    $ad_content = prepareAdContent($winning_bid);
    
    // Return successful response
    echo json_encode([
        'success' => true,
        'ad' => $ad_content,
        'win_price' => $winning_bid['winning_price'],
        'campaign_type' => $winning_bid['campaign_type'],
        'campaign_id' => $winning_bid['campaign_id']
    ]);
    
} else {
    // No valid bids found
    error_log("No valid bids found for zone $zoneId");
    
    echo json_encode([
        'success' => false,
        'error' => 'No matching ads found'
    ]);
}

/**
 * Prepare ad content from winning bid data
 */
function prepareAdContent($winning_bid) {
    $creative_data = $winning_bid['creative_data'];
    $click_url = "http://" . $_SERVER['HTTP_HOST'] . "/click.php?zone={$winning_bid['zone_id']}&creative={$winning_bid['creative_id']}&container=" . urlencode($_POST['container'] ?? '');
    
    switch ($creative_data['type']) {
        case 'image':
            return [
                'type' => 'image',
                'img_url' => $creative_data['image_url'],
                'click_url' => $click_url,
                'width' => $creative_data['width'],
                'height' => $creative_data['height'],
                'alt' => 'Advertisement'
            ];
            
        case 'video':
            return [
                'type' => 'video',
                'video_url' => $creative_data['video_url'],
                'click_url' => $click_url,
                'width' => $creative_data['width'],
                'height' => $creative_data['height']
            ];
            
        case 'html5':
        case 'third_party':
            return [
                'type' => 'html',
                'html' => $creative_data['html_content'],
                'click_url' => $click_url,
                'width' => $creative_data['width'],
                'height' => $creative_data['height']
            ];
            
        default:
            return [
                'type' => 'text',
                'text' => 'Advertisement',
                'click_url' => $click_url,
                'width' => $creative_data['width'],
                'height' => $creative_data['height']
            ];
    }
}

// Helper functions that remain from the legacy implementation

/**
 * Detect device type from user agent
 */
function detectDevice($userAgent) {
    if (strpos($userAgent, 'Mobile') !== false || strpos($userAgent, 'Android') !== false) {
        return 'mobile';
    } else if (strpos($userAgent, 'Tablet') !== false || strpos($userAgent, 'iPad') !== false) {
        return 'tablet';
    }
    return 'desktop';
}

/**
 * Detect browser from user agent
 */
function detectBrowser($userAgent) {
    if (strpos($userAgent, 'Chrome') !== false) {
        return 'chrome';
    } else if (strpos($userAgent, 'Firefox') !== false) {
        return 'firefox';
    } else if (strpos($userAgent, 'Safari') !== false) {
        return 'safari';
    } else if (strpos($userAgent, 'MSIE') !== false || strpos($userAgent, 'Trident') !== false) {
        return 'ie';
    }
    return 'other';
}

/**
 * Detect OS from user agent
 */
function detectOS($userAgent) {
    if (strpos($userAgent, 'Windows') !== false) {
        return 'windows';
    } else if (strpos($userAgent, 'Mac OS') !== false) {
        return 'mac';
    } else if (strpos($userAgent, 'Linux') !== false) {
        return 'linux';
    } else if (strpos($userAgent, 'Android') !== false) {
        return 'android';
    } else if (strpos($userAgent, 'iOS') !== false || strpos($userAgent, 'iPhone') !== false || strpos($userAgent, 'iPad') !== false) {
        return 'ios';
    }
    return 'other';
}

/**
 * Get country from IP
 */
function getCountry($ip) {
    // Simple implementation - in production, use a proper GeoIP database
    return 'US';
}
?>