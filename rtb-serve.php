<?php
/**
 * RTB Ad Serving Endpoint - Enhanced Version
 * Handles bid requests and serves winning ads using unified bidding engine
 * Version: 2.0.0 - Enhanced with unified bidding logic
 * Date: 2025-06-25
 * Author: simoncode12 + AI Assistant
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'config/database.php';
require_once 'includes/BiddingEngine.php';
require_once 'includes/functions.php';

// Rate limiting
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
if (!RateLimiter::checkLimit($client_ip, 1000, 3600)) {
    http_response_code(429);
    echo json_encode(['error' => 'Rate limit exceeded']);
    exit;
}

// Start performance monitoring
PerformanceMonitor::start('rtb_serve');

try {
    // Get and validate request parameters
    $zone_id = intval($_GET['zone'] ?? 0);
    $container_id = sanitizeInput($_GET['container'] ?? '');
    $size = sanitizeInput($_GET['size'] ?? '300x250');
    
    if ($zone_id <= 0) {
        throw new Exception('Invalid zone ID');
    }
    
    // Parse size
    if (!preg_match('/^(\d+)x(\d+)$/', $size, $matches)) {
        throw new Exception('Invalid size format');
    }
    
    $width = intval($matches[1]);
    $height = intval($matches[2]);
    
    // Log the request
    error_log("Enhanced RTB Request - Zone: $zone_id, Container: $container_id, Size: {$width}x{$height}");
    
    // Initialize enhanced bidding engine
    $bidding_engine = new BiddingEngine($pdo, true);
    
    // Prepare request data
    $request_data = [
        'zone_id' => $zone_id,
        'width' => $width,
        'height' => $height,
        'container' => $container_id,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'referer' => $_SERVER['HTTP_REFERER'] ?? ''
    ];
    
    // Process bid request using enhanced engine
    $winning_bid = $bidding_engine->processBidRequest($request_data);
    
    if ($winning_bid) {
        // Prepare enhanced ad response
        $ad_response = [
            'success' => true,
            'ad' => prepareEnhancedAdResponse($winning_bid, $zone_id, $container_id),
            'campaign_type' => $winning_bid['campaign_type'],
            'win_price' => $winning_bid['winning_price'],
            'campaign_id' => $winning_bid['campaign_id'],
            'meta' => [
                'request_id' => uniqid('req_'),
                'timestamp' => time(),
                'processing_time_ms' => PerformanceMonitor::end('rtb_serve')
            ]
        ];
        
        // Cache successful response briefly
        SimpleCache::set("ad_response_{$zone_id}_{$width}x{$height}", $ad_response, 60);
        
        echo json_encode($ad_response);
        
    } else {
        // No winning bid found
        error_log("No valid bids found for zone $zone_id, size {$width}x{$height}");
        
        // Try cached fallback
        $fallback = SimpleCache::get("fallback_ad_{$zone_id}");
        if ($fallback) {
            echo json_encode([
                'success' => true,
                'ad' => $fallback,
                'campaign_type' => 'fallback',
                'meta' => ['source' => 'cache_fallback']
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'No ads available',
                'meta' => [
                    'request_id' => uniqid('req_'),
                    'timestamp' => time()
                ]
            ]);
        }
    }
    
} catch (Exception $e) {
    // Enhanced error handling
    logError('RTB Serve Error: ' . $e->getMessage(), [
        'zone_id' => $zone_id ?? null,
        'size' => $size ?? null,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null
    ]);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
        'meta' => [
            'request_id' => uniqid('req_'),
            'timestamp' => time()
        ]
    ]);
/**
 * Prepare enhanced ad response with tracking and optimization
 */
function prepareEnhancedAdResponse($winning_bid, $zone_id, $container_id) {
    $creative_data = $winning_bid['creative_data'];
    $campaign_id = $winning_bid['campaign_id'];
    $creative_id = $winning_bid['creative_id'];
    
    // Enhanced click tracking URL with campaign info
    $click_url = "https://" . $_SERVER['HTTP_HOST'] . "/api/click-track.php?" . http_build_query([
        'zone' => $zone_id,
        'campaign' => $campaign_id,
        'creative' => $creative_id,
        'container' => $container_id,
        'type' => $winning_bid['campaign_type'],
        'price' => $winning_bid['winning_price'],
        'ts' => time()
    ]);
    
    // Impression tracking pixel
    $impression_url = "https://" . $_SERVER['HTTP_HOST'] . "/api/impression-track.php?" . http_build_query([
        'zone' => $zone_id,
        'campaign' => $campaign_id,
        'creative' => $creative_id,
        'type' => $winning_bid['campaign_type'],
        'ts' => time()
    ]);
    
    switch ($creative_data['type']) {
        case 'image':
            return [
                'type' => 'image',
                'url' => $creative_data['image_url'],
                'click_url' => $click_url,
                'impression_url' => $impression_url,
                'width' => $creative_data['width'],
                'height' => $creative_data['height'],
                'alt' => 'Advertisement by ' . $winning_bid['advertiser_name']
            ];
            
        case 'video':
            return [
                'type' => 'video',
                'video_url' => $creative_data['video_url'],
                'click_url' => $click_url,
                'impression_url' => $impression_url,
                'width' => $creative_data['width'],
                'height' => $creative_data['height'],
                'autoplay' => false,
                'controls' => true
            ];
            
        case 'html5':
        case 'third_party':
            // Enhanced HTML with automatic tracking injection
            $html_content = $creative_data['html_content'];
            
            // Inject impression tracking
            $tracking_pixel = '<img src="' . htmlspecialchars($impression_url) . '" width="1" height="1" style="display:none;" />';
            
            // Add click tracking to links if not already present
            if (strpos($html_content, 'href=') !== false && strpos($html_content, $click_url) === false) {
                $html_content = preg_replace(
                    '/href=["\']([^"\']+)["\']/i',
                    'href="' . $click_url . '" data-original-url="$1"',
                    $html_content
                );
            }
            
            return [
                'type' => 'html',
                'html' => $html_content . $tracking_pixel,
                'click_url' => $click_url,
                'width' => $creative_data['width'],
                'height' => $creative_data['height']
            ];
            
        default:
            return [
                'type' => 'text',
                'text' => 'Advertisement',
                'click_url' => $click_url,
                'impression_url' => $impression_url,
                'width' => $creative_data['width'],
                'height' => $creative_data['height']
            ];
    }
}
?>