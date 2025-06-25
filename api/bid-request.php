<?php
/**
 * RTB & RON Platform - Bid Request Handler
 * Processes incoming bid requests and selects winning bid
 * Version: 1.0.3 (Fixed RTB fallback logic)
 * Date: 2025-06-23 22:20:12
 * Author: simoncode12
 */

require_once '../config/database.php';
require_once '../includes/functions.php';

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

// Get RTB campaigns
$rtbCampaigns = $pdo->prepare("
    SELECT c.*, a.id as advertiser_id, a.name as advertiser_name
    FROM campaigns c
    JOIN advertisers a ON c.advertiser_id = a.id
    WHERE c.type = 'rtb' 
    AND c.status = 'active'
    AND c.targeting_size LIKE ?
    AND c.daily_budget > c.daily_spent
    AND c.start_date <= CURDATE()
    AND (c.end_date IS NULL OR c.end_date >= CURDATE())
");

$rtbCampaigns->execute(["%$size%"]);
$rtbCampaigns = $rtbCampaigns->fetchAll();

// Get RON campaigns
$ronCampaigns = $pdo->prepare("
    SELECT c.*, a.id as advertiser_id, a.name as advertiser_name
    FROM campaigns c
    JOIN advertisers a ON c.advertiser_id = a.id
    WHERE c.type = 'ron' 
    AND c.status = 'active'
    AND c.targeting_size LIKE ?
    AND c.daily_budget > c.daily_spent
    AND c.start_date <= CURDATE()
    AND (c.end_date IS NULL OR c.end_date >= CURDATE())
");

$ronCampaigns->execute(["%$size%"]);
$ronCampaigns = $ronCampaigns->fetchAll();

// Process bids
$bids = [];
$rtbBids = 0;
$ronBids = 0;

// Process RTB bids
foreach ($rtbCampaigns as $campaign) {
    // Get matching creative
    $creativeStmt = $pdo->prepare("
        SELECT * FROM creatives 
        WHERE campaign_id = ? 
        AND width = ? 
        AND height = ? 
        AND status = 'active'
    ");
    $creativeStmt->execute([$campaign['id'], $width, $height]);
    $creative = $creativeStmt->fetch();
    
    if (!$creative) continue;
    
    // Calculate RTB bid based on campaign settings
    $bidPrice = min($campaign['max_bid'], $campaign['daily_budget'] - $campaign['daily_spent']);
    
    // Apply frequency capping if needed
    if ($campaign['frequency_cap'] > 0) {
        // Check if frequency cap reached
        // Implementation can be added here
    }
    
    // Add to bids array
    if ($bidPrice > 0) {
        $bids[] = [
            'campaign_id' => $campaign['id'],
            'creative_id' => $creative['id'],
            'advertiser_id' => $campaign['advertiser_id'],
            'type' => 'rtb',
            'bid' => $bidPrice,
            'creative' => $creative
        ];
        $rtbBids++;
    }
}

// Process RON bids
foreach ($ronCampaigns as $campaign) {
    // Get matching creative
    $creativeStmt = $pdo->prepare("
        SELECT * FROM creatives 
        WHERE campaign_id = ? 
        AND width = ? 
        AND height = ? 
        AND status = 'active'
    ");
    $creativeStmt->execute([$campaign['id'], $width, $height]);
    $creative = $creativeStmt->fetch();
    
    if (!$creative) continue;
    
    // Calculate RON bid 
    $bidPrice = min($campaign['max_bid'], $campaign['daily_budget'] - $campaign['daily_spent']);
    
    // Apply frequency capping if needed
    if ($campaign['frequency_cap'] > 0) {
        // Check if frequency cap reached
        // Implementation can be added here
    }
    
    // Add to bids array
    if ($bidPrice > 0) {
        $bids[] = [
            'campaign_id' => $campaign['id'],
            'creative_id' => $creative['id'],
            'advertiser_id' => $campaign['advertiser_id'],
            'type' => 'ron',
            'bid' => $bidPrice,
            'creative' => $creative
        ];
        $ronBids++;
    }
}

// Log bid counts
error_log("Total bids: " . count($bids) . " (RTB: $rtbBids, RON: $ronBids)");

// Choose winner
$winner = null;
$secondBid = 0;

// ===== FIXED BIDDING LOGIC =====
// 1. First check if we have any bids at all
if (count($bids) > 0) {
    // Sort bids by price (highest first)
    usort($bids, function($a, $b) {
        return $b['bid'] - $a['bid'];
    });
    
    // Select winner (highest bid)
    $winner = $bids[0];
    
    // Get second price (if available)
    if (count($bids) > 1) {
        $secondBid = $bids[1]['bid'];
    } else {
        // Only one bidder - use floor price or minimum discount
        $minBidPrice = isset($zone['floor_price']) && $zone['floor_price'] > 0 
            ? $zone['floor_price'] 
            : $winner['bid'] * 0.9; // 10% discount if no floor price
        
        $secondBid = max($minBidPrice, $winner['bid'] * 0.9); // Ensure second bid is at least 90% of winning bid
    }
    
    // Adjust winning bid (second-price auction with floor)
    $winPrice = max($secondBid, isset($zone['floor_price']) ? $zone['floor_price'] : 0);
    
    // Log winning bid
    error_log(($winner['type'] == 'rtb' ? "RTB" : "RON") . " bid won at price: " . number_format($winPrice, 4));
    
    // Update campaign spent
    $spentStmt = $pdo->prepare("
        UPDATE campaigns 
        SET daily_spent = daily_spent + ?, 
            impressions = impressions + 1,
            updated_at = NOW()
        WHERE id = ?
    ");
    $spentStmt->execute([$winPrice, $winner['campaign_id']]);
    
    // Log bid in database
    $logStmt = $pdo->prepare("
        INSERT INTO bid_logs 
        (zone_id, campaign_id, creative_id, advertiser_id, bid_price, win_price, 
         status, container_key, ip, user_agent, referer, device_type, browser, os, country, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 'win', ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $logStmt->execute([
        $zoneId, 
        $winner['campaign_id'], 
        $winner['creative_id'], 
        $winner['advertiser_id'],
        $winner['bid'], 
        $winPrice,
        $containerKey, 
        $ip, 
        $userAgent, 
        $referer, 
        $deviceType, 
        $browser, 
        $os, 
        $country
    ]);
    
    // Get the ad content
    $ad = prepareAd($winner['creative'], $zoneId, $containerKey);
    
    // Return ad
    echo json_encode([
        'success' => true,
        'ad' => $ad,
        'win_price' => $winPrice
    ]);
} else {
    // No valid bids
    error_log("No valid bids for zone $zoneId");
    
    // Return no ad
    echo json_encode([
        'success' => false,
        'error' => 'No matching ads found'
    ]);
}

/**
 * Prepare ad for delivery
 */
function prepareAd($creative, $zoneId, $containerKey) {
    global $pdo;
    
    $clickUrl = "http://" . $_SERVER['HTTP_HOST'] . "/click.php?zone={$zoneId}&creative={$creative['id']}&container={$containerKey}";
    
    if ($creative['type'] == 'image') {
        return [
            'type' => 'image',
            'img_url' => $creative['content'],
            'click_url' => $clickUrl,
            'width' => $creative['width'],
            'height' => $creative['height']
        ];
    } else if ($creative['type'] == 'html') {
        return [
            'type' => 'html',
            'html' => $creative['content'],
            'click_url' => $clickUrl,
            'width' => $creative['width'],
            'height' => $creative['height']
        ];
    }
    
    return null;
}

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