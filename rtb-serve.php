<?php
/**
 * RTB Ad Serving Endpoint
 * Handles bid requests and serves winning ads
 * Version: 1.1.0 - Fixed RTB winning logic
 * Date: 2025-06-23 21:32:15
 * Author: simoncode12
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'config/database.php';

// Get request parameters
$zone_id = $_GET['zone'] ?? 0;
$container_id = $_GET['container'] ?? '';
$size = $_GET['size'] ?? '300x250';

// Log the request
error_log("AdStart Request - Zone: $zone_id, Container: $container_id, Size: $size");

// Validate zone
$stmt = $pdo->prepare("SELECT * FROM zones WHERE id = ? AND status = 'active'");
$stmt->execute([$zone_id]);
$zone = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$zone) {
    error_log("Zone not found or inactive: $zone_id");
    echo json_encode(['error' => 'Invalid zone']);
    exit;
}

// Parse size
list($width, $height) = explode('x', $size);

// Get user info
$user_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';

// Detect device type
$is_mobile = preg_match('/Mobile|Android|iPhone/i', $user_agent);
$device_type = $is_mobile ? 'mobile' : 'desktop';

// Detect browser
$browser = 'other';
if (strpos($user_agent, 'Chrome') !== false) $browser = 'chrome';
elseif (strpos($user_agent, 'Firefox') !== false) $browser = 'firefox';
elseif (strpos($user_agent, 'Safari') !== false) $browser = 'safari';
elseif (strpos($user_agent, 'Edge') !== false) $browser = 'edge';

// Collect all bids
$bids = [];

// 1. Get RTB bids from active campaigns
$rtb_query = "
    SELECT 
        c.id as campaign_id,
        c.name as campaign_name,
        c.bid_price,
        c.daily_budget,
        c.total_budget,
        cr.id as creative_id,
        cr.name as creative_name,
        cr.type as creative_type,
        cr.content,
        cr.url as creative_url,
        cr.click_url,
        'rtb' as bid_type,
        (SELECT COALESCE(SUM(win_price), 0) 
         FROM bid_logs 
         WHERE campaign_id = c.id 
         AND DATE(created_at) = CURDATE()) as daily_spent
    FROM campaigns c
    JOIN campaign_creatives cc ON c.id = cc.campaign_id
    JOIN creatives cr ON cc.creative_id = cr.id
    WHERE c.status = 'active'
    AND c.type = 'rtb'
    AND cr.status = 'active'
    AND cr.width = ?
    AND cr.height = ?
    AND (c.device_targeting = 'all' OR c.device_targeting = ?)
    AND (c.start_date IS NULL OR c.start_date <= NOW())
    AND (c.end_date IS NULL OR c.end_date >= NOW())
    HAVING daily_spent < c.daily_budget OR c.daily_budget = 0
";

$stmt = $pdo->prepare($rtb_query);
$stmt->execute([$width, $height, $device_type]);
$rtb_campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rtb_campaigns as $campaign) {
    $bids[] = [
        'campaign_id' => $campaign['campaign_id'],
        'campaign_name' => $campaign['campaign_name'],
        'creative_id' => $campaign['creative_id'],
        'bid_price' => floatval($campaign['bid_price']),
        'type' => 'rtb',
        'creative_type' => $campaign['creative_type'],
        'content' => $campaign['content'],
        'creative_url' => $campaign['creative_url'],
        'click_url' => $campaign['click_url']
    ];
}

// 2. Get RON bids (only if active)
$ron_query = "
    SELECT 
        c.id as campaign_id,
        c.name as campaign_name,
        z.floor_price as bid_price,
        cr.id as creative_id,
        cr.name as creative_name,
        cr.type as creative_type,
        cr.content,
        cr.url as creative_url,
        cr.click_url,
        'ron' as bid_type
    FROM campaigns c
    JOIN campaign_zones cz ON c.id = cz.campaign_id
    JOIN zones z ON cz.zone_id = z.id
    JOIN campaign_creatives cc ON c.id = cc.campaign_id
    JOIN creatives cr ON cc.creative_id = cr.id
    WHERE c.status = 'active'
    AND c.type = 'ron'
    AND z.id = ?
    AND cr.status = 'active'
    AND cr.width = ?
    AND cr.height = ?
    AND (c.start_date IS NULL OR c.start_date <= NOW())
    AND (c.end_date IS NULL OR c.end_date >= NOW())
";

$stmt = $pdo->prepare($ron_query);
$stmt->execute([$zone_id, $width, $height]);
$ron_campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($ron_campaigns as $campaign) {
    $bids[] = [
        'campaign_id' => $campaign['campaign_id'],
        'campaign_name' => $campaign['campaign_name'],
        'creative_id' => $campaign['creative_id'],
        'bid_price' => floatval($campaign['bid_price']),
        'type' => 'ron',
        'creative_type' => $campaign['creative_type'],
        'content' => $campaign['content'],
        'creative_url' => $campaign['creative_url'],
        'click_url' => $campaign['click_url']
    ];
}

// Log bid counts
$rtb_count = count($rtb_campaigns);
$ron_count = count($ron_campaigns);
error_log("Total bids: " . count($bids) . " (RTB: $rtb_count, RON: $ron_count)");

// Determine winning bid
$winning_bid = null;

if (!empty($bids)) {
    // Sort bids by price (highest first)
    usort($bids, function($a, $b) {
        return $b['bid_price'] <=> $a['bid_price'];
    });
    
    // Get zone floor price
    $floor_price = floatval($zone['floor_price']);
    
    // Find the highest bid that meets floor price
    foreach ($bids as $bid) {
        if ($bid['bid_price'] >= $floor_price) {
            $winning_bid = $bid;
            break;
        }
    }
    
    // If no bid meets floor price but we have RTB bids, use the highest RTB bid
    if (!$winning_bid && $rtb_count > 0) {
        foreach ($bids as $bid) {
            if ($bid['type'] == 'rtb') {
                $winning_bid = $bid;
                error_log("RTB bid won by default (no floor price met): " . $bid['campaign_name'] . " at $" . $bid['bid_price']);
                break;
            }
        }
    }
}

if ($winning_bid) {
    // Log the winning bid
    error_log($winning_bid['type'] . " bid won: " . $winning_bid['campaign_name'] . " at price: " . $winning_bid['bid_price']);
    
    // Record bid in database
    $stmt = $pdo->prepare("
        INSERT INTO bid_logs (
            zone_id, campaign_id, creative_id, bid_price, win_price, 
            status, ip_address, user_agent, referer, device_type, 
            browser, country, created_at
        ) VALUES (
            ?, ?, ?, ?, ?, 'win', ?, ?, ?, ?, ?, 'US', NOW()
        )
    ");
    
    $stmt->execute([
        $zone_id,
        $winning_bid['campaign_id'],
        $winning_bid['creative_id'],
        $winning_bid['bid_price'],
        $winning_bid['bid_price'],
        $user_ip,
        $user_agent,
        $referer,
        $device_type,
        $browser
    ]);
    
    $impression_id = $pdo->lastInsertId();
    
    // Generate click tracking URL
    $click_url = "https://{$_SERVER['HTTP_HOST']}/click.php?id=" . $impression_id;
    
    // Prepare ad response
    $response = [
        'success' => true,
        'ad' => [
            'creative_type' => $winning_bid['creative_type'],
            'width' => $width,
            'height' => $height,
            'click_url' => $click_url,
            'impression_id' => $impression_id
        ]
    ];
    
    // Add creative content based on type
    if ($winning_bid['creative_type'] == 'image') {
        $response['ad']['image_url'] = $winning_bid['creative_url'];
        $response['ad']['html'] = '<a href="' . $click_url . '" target="_blank"><img src="' . $winning_bid['creative_url'] . '" width="' . $width . '" height="' . $height . '" border="0" /></a>';
    } elseif ($winning_bid['creative_type'] == 'html5') {
        $html_content = str_replace('{CLICK_URL}', $click_url, $winning_bid['content']);
        $response['ad']['html'] = $html_content;
    }
    
    echo json_encode($response);
    
} else {
    // No winning bid
    error_log("No ad served: No matching ads found for size $size");
    
    // Return blank ad or default
    echo json_encode([
        'success' => false,
        'message' => 'No ads available',
        'ad' => [
            'html' => '<!-- No ad available -->'
        ]
    ]);
}
?>