<?php
/**
 * OpenRTB Compatible Endpoint
 * Current Date: 2025-06-25 10:35:28
 * Current User: simoncode12
 * 
 * Updated: Added platform revenue share adjustment
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, x-openrtb-version');

require_once '../config/database.php';

// Enable debugging
$DEBUG = true;

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Log prefix
$log_prefix = '[RTB-ENDPOINT] ';

// Default no-bid response
$no_bid_response = ['id' => $_REQUEST['id'] ?? '', 'nbr' => 2]; // nbr=2 means "blocked creative"

try {
    // Get request data (handle both POST and GET)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Get the raw POST data - standard OpenRTB
        $input = file_get_contents('php://input');
        $request = json_decode($input, true);
        
        if (!$request) {
            error_log($log_prefix . "Invalid JSON request: " . substr($input, 0, 200));
            http_response_code(400);
            echo json_encode($no_bid_response);
            exit;
        }
        
        error_log($log_prefix . "OpenRTB request: " . substr($input, 0, 200));
        
        // Extract key data from OpenRTB format
        $request_id = $request['id'] ?? uniqid();
        $impressions = $request['imp'] ?? [];
        $site = $request['site'] ?? [];
        $device = $request['device'] ?? [];
        $user = $request['user'] ?? [];
        
        if (empty($impressions)) {
            error_log($log_prefix . "No impressions in request");
            echo json_encode($no_bid_response);
            exit;
        }
        
        // Get the first impression
        $impression = $impressions[0];
        $imp_id = $impression['id'];
        $tag_id = $impression['tagid'] ?? null; // This might contain zone_id
        
        // Get banner dimensions
        $banner = $impression['banner'] ?? null;
        if ($banner) {
            $width = $banner['w'] ?? 0;
            $height = $banner['h'] ?? 0;
        } else {
            error_log($log_prefix . "No banner object found");
            echo json_encode($no_bid_response);
            exit;
        }
        
        // Website info
        $website_id = $site['id'] ?? 0;
        $domain = $site['domain'] ?? '';
        
    } else {
        // Handle GET requests (simpler format for testing)
        $request_id = $_GET['id'] ?? uniqid();
        $imp_id = $_GET['imp_id'] ?? '1';
        $width = intval($_GET['width'] ?? 0);
        $height = intval($_GET['height'] ?? 0);
        $website_id = $_GET['website_id'] ?? 0;
        $domain = $_GET['domain'] ?? '';
        $tag_id = $_GET['tag_id'] ?? $_GET['zone_id'] ?? null;
        
        // Construct a standardized OpenRTB request from GET params
        $request = [
            'id' => $request_id,
            'at' => 1, // First-price auction
            'imp' => [
                [
                    'id' => $imp_id,
                    'banner' => [
                        'w' => $width,
                        'h' => $height,
                        'mimes' => ['image/jpg', 'image/png', 'video/mp4'],
                        'ext' => [
                            'image_output' => 'xml',
                            'video_output' => 'xml'
                        ]
                    ],
                    'tagid' => $tag_id
                ]
            ],
            'site' => [
                'id' => $website_id,
                'domain' => $domain,
                'page' => 'https://' . $domain . '/'
            ],
            'device' => [
                'ua' => $_SERVER['HTTP_USER_AGENT'],
                'ip' => $_SERVER['REMOTE_ADDR']
            ],
            'user' => [
                'id' => md5($_SERVER['REMOTE_ADDR'] . $_SERVER['HTTP_USER_AGENT'])
            ]
        ];
        
        error_log($log_prefix . "GET request: website_id=$website_id, domain=$domain, size={$width}x{$height}");
    }
    
    // Validate dimensions
    if ($width <= 0 || $height <= 0) {
        error_log($log_prefix . "Invalid dimensions: {$width}x{$height}");
        echo json_encode($no_bid_response);
        exit;
    }
    
    // Get endpoint key if provided
    $endpoint_key = $_GET['key'] ?? '';
    
    // Identify website/publisher
    $website = null;
    $publisher_id = 0;
    $revenue_share = 70; // Default publisher share
    $platform_share = 65; // Default platform share
    $apply_revenue_adjustment = true; // Default to apply adjustment

    // If endpoint key provided, get config from rtb_endpoints table
    if (!empty($endpoint_key)) {
        $stmt = $pdo->prepare("
            SELECT e.*, p.revenue_share as publisher_revenue_share 
            FROM rtb_endpoints e 
            LEFT JOIN publishers p ON e.publisher_id = p.id
            WHERE e.endpoint_key = ? AND e.status = 'active'
        ");
        $stmt->execute([$endpoint_key]);
        $endpoint = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($endpoint) {
            $publisher_id = $endpoint['publisher_id'];
            $revenue_share = isset($endpoint['publisher_revenue_share']) ? (float)$endpoint['publisher_revenue_share'] : 50.00;
            
            // Get platform_share from endpoint (if available)
            $platform_share = isset($endpoint['platform_share']) ? (float)$endpoint['platform_share'] : 65.00;
            $apply_revenue_adjustment = isset($endpoint['apply_revenue_adjustment']) ? (bool)$endpoint['apply_revenue_adjustment'] : true;
            
            if ($DEBUG) error_log($log_prefix . "Using endpoint settings: platform_share=$platform_share%, apply_adjustment=" . ($apply_revenue_adjustment ? 'yes' : 'no'));
        }
    }
    
    // Get website by ID or domain
    if ($website_id > 0) {
        $stmt = $pdo->prepare("
            SELECT w.id, w.domain, w.publisher_id, p.revenue_share
            FROM websites w
            JOIN publishers p ON w.publisher_id = p.id
            WHERE w.id = ? AND w.status = 'active'
        ");
        $stmt->execute([$website_id]);
        $website = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif (!empty($domain)) {
        $stmt = $pdo->prepare("
            SELECT w.id, w.domain, w.publisher_id, p.revenue_share
            FROM websites w
            JOIN publishers p ON w.publisher_id = p.id
            WHERE w.domain = ? AND w.status = 'active'
        ");
        $stmt->execute([$domain]);
        $website = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // If website not found, try getting first active website
    if (!$website) {
        if ($DEBUG) error_log($log_prefix . "Website not found, using first active site");
        $stmt = $pdo->query("
            SELECT w.id, w.domain, w.publisher_id, p.revenue_share
            FROM websites w
            JOIN publishers p ON w.publisher_id = p.id
            WHERE w.status = 'active'
            LIMIT 1
        ");
        $website = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if ($website) {
        $website_id = $website['id'];
        // Only set publisher_id and revenue_share if we don't already have them from the endpoint
        if ($publisher_id == 0) {
            $publisher_id = $website['publisher_id'];
            $revenue_share = $website['revenue_share'];
        }
        
        if ($DEBUG) error_log($log_prefix . "Using website ID: $website_id, publisher: $publisher_id, rev share: $revenue_share%");
    } else {
        error_log($log_prefix . "No active websites found");
        echo json_encode($no_bid_response);
        exit;
    }
    
    // Find a valid zone_id
    $zone_id = null;
    
    // Try to use tag_id as zone_id if provided
    if ($tag_id && is_numeric($tag_id)) {
        // Verify it exists
        $stmt = $pdo->prepare("SELECT id FROM zones WHERE id = ? AND status = 'active'");
        $stmt->execute([$tag_id]);
        if ($stmt->fetchColumn()) {
            $zone_id = $tag_id;
            if ($DEBUG) error_log($log_prefix . "Using tagid as zone_id: $zone_id");
        }
    }
    
    // If no valid zone_id yet, find one that matches website and dimensions
    if (!$zone_id) {
        $stmt = $pdo->prepare("
            SELECT id FROM zones 
            WHERE website_id = ? 
            AND size = ? 
            AND status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$website_id, $width . 'x' . $height]);
        $zone_id = $stmt->fetchColumn();
        
        if ($zone_id && $DEBUG) {
            error_log($log_prefix . "Found matching zone by size: $zone_id");
        }
    }
    
    // If still no zone_id, get any active zone as fallback
    if (!$zone_id) {
        $stmt = $pdo->query("SELECT id FROM zones WHERE status = 'active' LIMIT 1");
        $zone_id = $stmt->fetchColumn();
        
        if ($zone_id && $DEBUG) {
            error_log($log_prefix . "Using fallback zone: $zone_id");
        } else {
            if ($DEBUG) error_log($log_prefix . "WARNING: No active zones found in database!");
        }
    }
    
    // Initialize all bids array
    $all_bids = [];
    
    // STEP 1: Find RTB campaigns with external endpoints
    $stmt = $pdo->prepare("
        SELECT 
            c.id AS campaign_id, 
            c.name AS campaign_name,
            c.endpoint_url,
            cr.id AS creative_id, 
            cr.bid_amount,
            cr.width,
            cr.height
        FROM campaigns c
        JOIN creatives cr ON c.id = cr.campaign_id
        WHERE c.status = 'active' 
        AND c.type = 'rtb'
        AND c.endpoint_url IS NOT NULL
        AND c.endpoint_url != ''
        AND cr.status = 'active'
        AND cr.width = ? 
        AND cr.height = ?
        AND (c.start_date IS NULL OR c.start_date <= CURDATE())
        AND (c.end_date IS NULL OR c.end_date >= CURDATE())
    ");
    
    $stmt->execute([$width, $height]);
    $rtb_external_campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($DEBUG) {
        error_log($log_prefix . "Found " . count($rtb_external_campaigns) . " RTB campaigns with external endpoints");
        foreach ($rtb_external_campaigns as $campaign) {
            error_log($log_prefix . "RTB External: Campaign ID {$campaign['campaign_id']}, endpoint: " . substr($campaign['endpoint_url'], 0, 50));
        }
    }
    
    // STEP 2: Call external RTB endpoints (like ExoClick)
    $external_rtb_bids = [];
    
    foreach ($rtb_external_campaigns as $rtb_campaign) {
        // Prepare a copy of the OpenRTB request for this endpoint
        $endpoint_url = $rtb_campaign['endpoint_url'];
        $rtb_request = $request;
        
        if ($DEBUG) error_log($log_prefix . "Calling RTB endpoint for campaign {$rtb_campaign['campaign_id']}: " . $endpoint_url);
        
        // Make OpenRTB request to external endpoint
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint_url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($rtb_request));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 1); // 1 second timeout
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-openrtb-version: 2.5'
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        if ($curl_error) {
            error_log($log_prefix . "Error calling RTB endpoint: " . $curl_error);
            continue;
        }
        
        if ($http_code != 200 || empty($response)) {
            error_log($log_prefix . "RTB endpoint returned non-200 status: " . $http_code);
            continue;
        }
        
        // Parse response
        $rtb_response = json_decode($response, true);
        
        if (!$rtb_response || empty($rtb_response['seatbid']) || empty($rtb_response['seatbid'][0]['bid'])) {
            error_log($log_prefix . "Invalid RTB response or no bids: " . substr($response, 0, 200));
            continue;
        }
        
        // Process bids from this response
        foreach ($rtb_response['seatbid'] as $seatbid) {
            foreach ($seatbid['bid'] as $bid) {
                $bid_price = $bid['price'] ?? 0;
                $bid_adm = $bid['adm'] ?? '';
                $bid_nurl = $bid['nurl'] ?? '';
                $bid_id = $bid['id'] ?? uniqid();
                $bid_impid = $bid['impid'] ?? $imp_id;
                
                if ($bid_price > 0 && !empty($bid_adm)) {
                    $external_rtb_bids[] = [
                        'campaign_id' => $rtb_campaign['campaign_id'],
                        'creative_id' => $rtb_campaign['creative_id'],
                        'price' => $bid_price,
                        'adm' => $bid_adm,
                        'nurl' => $bid_nurl,
                        'bid_id' => $bid_id,
                        'imp_id' => $bid_impid,
                        'type' => 'external_rtb',
                        'width' => $width,
                        'height' => $height,
                        'source_endpoint' => $endpoint_url
                    ];
                    
                    if ($DEBUG) error_log($log_prefix . "Got external RTB bid: campaign_id={$rtb_campaign['campaign_id']}, price={$bid_price}");
                }
            }
        }
    }
    
    if ($DEBUG) error_log($log_prefix . "Total external RTB bids: " . count($external_rtb_bids));
    
    // STEP 3: Find internal RTB and RON campaigns
    $stmt = $pdo->prepare("
        SELECT 
            c.id AS campaign_id, 
            c.name AS campaign_name, 
            c.type AS campaign_type, 
            c.advertiser_id,
            cr.id AS creative_id, 
            cr.bid_amount, 
            cr.creative_type, 
            cr.width, 
            cr.height, 
            cr.image_url, 
            cr.video_url, 
            cr.html_content, 
            cr.click_url
        FROM campaigns c
        JOIN creatives cr ON c.id = cr.campaign_id
        WHERE c.status = 'active' 
        AND cr.status = 'active'
        AND cr.width = ? 
        AND cr.height = ?
        AND (c.start_date IS NULL OR c.start_date <= CURDATE())
        AND (c.end_date IS NULL OR c.end_date >= CURDATE())
        AND (c.endpoint_url IS NULL OR c.endpoint_url = '') -- Exclude campaigns with external endpoints
        ORDER BY cr.bid_amount DESC
        LIMIT 10
    ");
    
    $stmt->execute([$width, $height]);
    $internal_campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($DEBUG) {
        error_log($log_prefix . "Found " . count($internal_campaigns) . " internal campaigns matching size {$width}x{$height}");
        
        // Count by campaign type
        $rtb_count = 0;
        $ron_count = 0;
        
        foreach ($internal_campaigns as $c) {
            if ($c['campaign_type'] == 'rtb') $rtb_count++;
            if ($c['campaign_type'] == 'ron') $ron_count++;
        }
        
        error_log($log_prefix . "Internal campaign breakdown: RTB=$rtb_count, RON=$ron_count");
    }
    
    // STEP 4: Create bids for internal campaigns
    $internal_bids = [];
    
    foreach ($internal_campaigns as $campaign) {
        $internal_bids[] = [
            'campaign_id' => $campaign['campaign_id'],
            'creative_id' => $campaign['creative_id'],
            'price' => $campaign['bid_amount'],
            'campaign_type' => $campaign['campaign_type'],
            'creative_type' => $campaign['creative_type'],
            'image_url' => $campaign['image_url'],
            'video_url' => $campaign['video_url'],
            'html_content' => $campaign['html_content'],
            'click_url' => $campaign['click_url'],
            'width' => $campaign['width'],
            'height' => $campaign['height'],
            'type' => 'internal'
        ];
    }
    
    // STEP 5: Combine all bids and select the winner
    $all_bids = array_merge($external_rtb_bids, $internal_bids);
    
    if (empty($all_bids)) {
        error_log($log_prefix . "No matching bids found");
        echo json_encode($no_bid_response);
        exit;
    }
    
    // Sort by price (highest first)
    usort($all_bids, function($a, $b) {
        return $b['price'] <=> $a['price'];
    });
    
    // Take the highest bidder
    $winner = $all_bids[0];
    $bid_id = uniqid('bid_');
    $impression_id = uniqid('imp_');
    
    if ($DEBUG) {
        error_log($log_prefix . "Winner type: " . ($winner['type'] == 'external_rtb' ? 'External RTB' : 'Internal ' . $winner['campaign_type']));
        error_log($log_prefix . "Winner price: $" . $winner['price']);
    }
    
    // Apply revenue adjustment if enabled
    $original_price = (float)$winner['price'];
    $adjusted_price = $original_price;
    
    if ($apply_revenue_adjustment && $platform_share > 0) {
        // Adjust the bid price based on platform share
        $adjusted_price = round($original_price * ($platform_share / 100), 6);
        
        if ($DEBUG) {
            error_log($log_prefix . "Applying revenue adjustment: original=$original_price, adjusted=$adjusted_price (platform share={$platform_share}%)");
        }
    }
    
    // Create ad markup based on winner type
    $ad_html = '';
    
    if ($winner['type'] == 'external_rtb') {
        // Use the AdM from external RTB response
        $ad_html = $winner['adm'];
        
        // Log the win in our system
        try {
            $stmt = $pdo->prepare("
                INSERT INTO bid_logs (
                    request_id, campaign_id, creative_id, zone_id,
                    bid_amount, win_price, impression_id,
                    status, created_at
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    'win', NOW()
                )
            ");
            
            $stmt->execute([
                $request_id,
                $winner['campaign_id'],
                $winner['creative_id'],
                $zone_id ?: NULL,
                $original_price, // Log original price for analytics
                $adjusted_price, // Log adjusted price as win price
                $impression_id
            ]);
            
            if ($DEBUG) error_log($log_prefix . "Logged external RTB win");
            
        } catch (Exception $e) {
            error_log($log_prefix . "Error logging external RTB win: " . $e->getMessage());
        }
        
        // Create win notification URL for our system
        $win_url = "https://up.adstart.click/rtb/win.php?bid_id={$bid_id}&price={$adjusted_price}&creative_id={$winner['creative_id']}&campaign_id={$winner['campaign_id']}&website_id={$website_id}&publisher_id={$publisher_id}&revenue_share={$revenue_share}";
        if ($zone_id) {
            $win_url .= "&zone_id={$zone_id}";
        }
        
        // Also include the external win notification URL if provided
        if (!empty($winner['nurl'])) {
            $win_url .= "&ext_nurl=" . urlencode($winner['nurl']);
        }
        
    } else {
        // Internal creative
        if ($winner['creative_type'] == 'html5' || !empty($winner['html_content'])) {
            $ad_html = $winner['html_content'];
        } else if (!empty($winner['image_url'])) {
            $ad_html = '<a href="' . htmlspecialchars($winner['click_url']) . '" target="_blank" rel="nofollow noopener">' .
                      '<img src="' . htmlspecialchars($winner['image_url']) . '" ' .
                      'width="' . $winner['width'] . '" height="' . $winner['height'] . '" ' .
                      'style="border:0; display:block;" alt="Advertisement">' .
                      '</a>';
        } else if (!empty($winner['video_url'])) {
            // Video creative
            $ad_html = '<video width="' . $winner['width'] . '" height="' . $winner['height'] . '" controls autoplay>' .
                      '<source src="' . htmlspecialchars($winner['video_url']) . '" type="video/mp4">' .
                      'Your browser does not support the video tag.' .
                      '</video>';
        }
        
        // Create win notification URL for internal campaigns
        $win_url = "https://up.adstart.click/rtb/win.php?bid_id={$bid_id}&price={$adjusted_price}&creative_id={$winner['creative_id']}&campaign_id={$winner['campaign_id']}&website_id={$website_id}&publisher_id={$publisher_id}&revenue_share={$revenue_share}";
        if ($zone_id) {
            $win_url .= "&zone_id={$zone_id}";
        }
        
        // Log the internal bid in the database
        try {
            $stmt = $pdo->prepare("
                INSERT INTO bid_logs (
                    request_id, campaign_id, creative_id, zone_id,
                    bid_amount, win_price, impression_id,
                    status, created_at
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    'bid', NOW()
                )
            ");
            
            $stmt->execute([
                $request_id,
                $winner['campaign_id'],
                $winner['creative_id'],
                $zone_id ?: NULL,
                $original_price, // Log original price for analytics
                $adjusted_price, // Log adjusted price as win_price
                $impression_id
            ]);
        } catch (Exception $e) {
            error_log($log_prefix . "Error logging bid: " . $e->getMessage());
        }
    }
    
    // Add platform_share to win URL for reporting
    $win_url .= "&platform_share={$platform_share}";
    
    // Prepare OpenRTB response
    $response = [
        'id' => $request_id,
        'bidid' => $bid_id,
        'seatbid' => [
            [
                'bid' => [
                    [
                        'id' => $bid_id,
                        'impid' => $imp_id,
                        'price' => (float)$adjusted_price, // Use the adjusted price in the bid response
                        'adid' => (string)$winner['creative_id'],
                        'cid' => (string)$winner['campaign_id'],
                        'crid' => (string)$winner['creative_id'],
                        'adm' => $ad_html,
                        'nurl' => $win_url,
                        'adomain' => ['adstart.click'],
                        'iurl' => $winner['image_url'] ?? null,
                        'w' => (int)$winner['width'],
                        'h' => (int)$winner['height'],
                        'ext' => [
                            'bid_type' => $winner['type'],
                            'campaign_type' => $winner['campaign_type'] ?? ($winner['type'] == 'external_rtb' ? 'rtb' : 'unknown'),
                            'original_price' => (float)$original_price, // Add original price for transparency
                            'platform_share' => (float)$platform_share // Add platform share for transparency
                        ]
                    ]
                ]
            ]
        ],
        'cur' => 'USD'
    ];
    
    if ($DEBUG) {
        $winner_type = $winner['type'] == 'external_rtb' ? 'External RTB' : 'Internal ' . $winner['campaign_type'];
        error_log($log_prefix . "Bid response created for campaign ID: {$winner['campaign_id']}, type: {$winner_type}");
        error_log($log_prefix . "Original bid: \${$original_price}, Adjusted bid: \${$adjusted_price}, Platform share: {$platform_share}%");
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log($log_prefix . "Error: " . $e->getMessage());
    echo json_encode($no_bid_response);
}
?>