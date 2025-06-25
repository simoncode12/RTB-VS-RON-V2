<?php
/**
 * OpenRTB 2.5 Compliant Endpoint - FINAL FIX
 * Real-Time Bidding endpoint for external DSPs
 * Date: 2025-06-23 21:50:15
 * Author: simoncode12
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get raw POST data
$raw_input = file_get_contents('php://input');
$bid_request = json_decode($raw_input, true);

if (!$bid_request) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

require_once '../config/database.php';

// Log the incoming request
error_log("RTB Request received: " . json_encode($bid_request));

try {
    // Validate required fields
    if (!isset($bid_request['id']) || !isset($bid_request['imp'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields']);
        exit;
    }

    $request_id = $bid_request['id'];
    $impressions = $bid_request['imp'];
    
    // Extract device and geo information
    $device = $bid_request['device'] ?? [];
    $site = $bid_request['site'] ?? [];
    $user = $bid_request['user'] ?? [];
    
    $user_agent = $device['ua'] ?? '';
    $ip_address = $device['ip'] ?? $_SERVER['REMOTE_ADDR'];
    $country = $device['geo']['country'] ?? 'US';
    $language = $device['language'] ?? 'en';
    $os = $device['os'] ?? '';
    
    $domain = $site['domain'] ?? '';
    $page_url = $site['page'] ?? '';
    $site_categories = $site['cat'] ?? [];
    
    // Response structure
    $bid_response = [
        'id' => $request_id,
        'seatbid' => []
    ];
    
    $has_bids = false;
    
    // Process each impression
    foreach ($impressions as $imp) {
        $imp_id = $imp['id'];
        $banner = $imp['banner'] ?? null;
        
        if (!$banner) {
            continue; // Skip non-banner impressions for now
        }
        
        $width = $banner['w'] ?? 0;
        $height = $banner['h'] ?? 0;
        $mimes = $banner['mimes'] ?? ['image/jpeg', 'image/png', 'image/gif'];
        
        // Find matching RTB campaigns - SIMPLIFIED QUERY
        $stmt = $pdo->prepare("
            SELECT 
                c.id,
                c.name,
                c.type,
                c.advertiser_id,
                c.daily_budget,
                c.total_budget,
                c.target_countries,
                c.target_browsers,
                c.target_devices,
                c.target_os,
                cr.id as creative_id,
                cr.name as creative_name,
                cr.width,
                cr.height,
                cr.image_url,
                cr.video_url,
                cr.html_content,
                cr.click_url,
                cr.bid_amount,
                a.name as advertiser_name,
                a.website as advertiser_website
            FROM campaigns c
            JOIN creatives cr ON c.id = cr.campaign_id
            JOIN advertisers a ON c.advertiser_id = a.id
            WHERE c.status = 'active'
            AND c.type = 'rtb'
            AND a.status = 'active'
            AND cr.status = 'active'
            AND cr.width = ?
            AND cr.height = ?
            AND (c.start_date IS NULL OR c.start_date <= CURDATE())
            AND (c.end_date IS NULL OR c.end_date >= CURDATE())
            ORDER BY cr.bid_amount DESC
            LIMIT 5
        ");
        
        $stmt->execute([$width, $height]);
        $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("RTB: Found " . count($campaigns) . " campaigns for {$width}x{$height}");
        
        if (empty($campaigns)) {
            continue; // No campaigns for this impression
        }
        
        // Create bids for this impression
        $bids = [];
        
        foreach ($campaigns as $campaign) {
            // Apply bid adjustment based on targeting
            $bid_price = floatval($campaign['bid_amount'] ?? 0);
            
            // Minimum bid protection
            if ($bid_price <= 0) {
                $bid_price = 0.01;
            }
            
            // Country targeting adjustment
            if (!empty($campaign['target_countries'])) {
                try {
                    $target_countries = json_decode($campaign['target_countries'], true);
                    if (is_array($target_countries) && in_array($country, $target_countries)) {
                        $bid_price *= 1.1; // 10% boost for targeted countries
                    }
                } catch (Exception $e) {
                    // Skip JSON decode error
                }
            }
            
            // Generate creative markup based on type
            $adm = generateCreativeMarkup($campaign, $width, $height);
            
            // Win notification URL
            $nurl = "https://up.adstart.click/api/rtb-win.php?" . http_build_query([
                'campaign_id' => $campaign['id'],
                'creative_id' => $campaign['creative_id'],
                'price' => '${AUCTION_PRICE}',
                'imp_id' => $imp_id,
                'request_id' => $request_id
            ]);
            
            $bid = [
                'id' => uniqid('bid_'),
                'impid' => $imp_id,
                'price' => round($bid_price, 4),
                'adm' => $adm,
                'nurl' => $nurl,
                'cid' => (string)$campaign['id'],
                'crid' => (string)$campaign['creative_id'],
                'w' => (int)$width,
                'h' => (int)$height,
                'ext' => [
                    'btype' => determineCreativeType($campaign),
                    'campaign_name' => $campaign['name'],
                    'advertiser' => $campaign['advertiser_name']
                ]
            ];
            
            // Add image URL if available
            if (!empty($campaign['image_url'])) {
                $bid['iurl'] = $campaign['image_url'];
            }
            
            $bids[] = $bid;
            $has_bids = true;
            
            error_log("RTB: Created bid for campaign {$campaign['id']} at price {$bid_price}");
        }
        
        // Add bids to seatbid if any
        if (!empty($bids)) {
            $bid_response['seatbid'][] = [
                'bid' => $bids,
                'seat' => 'rtb-platform-1'
            ];
        }
    }
    
    // Return response
    if ($has_bids) {
        // Log successful bid response
        error_log("RTB Response sent with " . count($bid_response['seatbid']) . " seatbid(s)");
        echo json_encode($bid_response);
    } else {
        // No bid response (HTTP 204)
        http_response_code(204);
        error_log("RTB No Bid - No matching campaigns");
    }
    
} catch (Exception $e) {
    error_log("RTB Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error', 'debug' => $e->getMessage()]);
}

/**
 * Generate creative markup based on campaign type
 */
function generateCreativeMarkup($campaign, $width, $height) {
    $click_url = $campaign['click_url'] ?? '#';
    $creative_name = htmlspecialchars($campaign['creative_name'] ?? 'Ad');
    
    // HTML Creative
    if (!empty($campaign['html_content'])) {
        return $campaign['html_content'];
    }
    
    // Image Creative - XML Format
    if (!empty($campaign['image_url'])) {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
               '<ad>' . "\n" .
               '    <imageAd>' . "\n" .
               '        <clickUrl><![CDATA[' . $click_url . ']]></clickUrl>' . "\n" .
               '        <imgUrl><![CDATA[' . $campaign['image_url'] . ']]></imgUrl>' . "\n" .
               '    </imageAd>' . "\n" .
               '</ad>';
    }
    
    // Video Creative - XML Format
    if (!empty($campaign['video_url'])) {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
               '<ad>' . "\n" .
               '    <videoAd>' . "\n" .
               '        <clickUrl><![CDATA[' . $click_url . ']]></clickUrl>' . "\n" .
               '        <videoUrl><![CDATA[' . $campaign['video_url'] . ']]></videoUrl>' . "\n" .
               '    </videoAd>' . "\n" .
               '</ad>';
    }
    
    // Fallback HTML - Enhanced design
    return '<div style="width:' . $width . 'px; height:' . $height . 'px; background:linear-gradient(135deg, #667eea 0%, #764ba2 100%); position:relative; border-radius:8px; overflow:hidden; box-shadow:0 4px 15px rgba(0,0,0,0.2); font-family:Arial,sans-serif;">' .
           '<a href="' . htmlspecialchars($click_url) . '" target="_blank" rel="nofollow noopener" style="display:flex; flex-direction:column; justify-content:center; align-items:center; height:100%; color:white; text-decoration:none; padding:20px; box-sizing:border-box; text-align:center;">' .
           '<div style="font-size:' . min(18, $width/15) . 'px; font-weight:bold; margin-bottom:8px; text-shadow:1px 1px 2px rgba(0,0,0,0.3);">' . $creative_name . '</div>' .
           '<div style="font-size:' . min(14, $width/20) . 'px; opacity:0.9;">Click to Learn More</div>' .
           '<div style="position:absolute; bottom:5px; right:8px; font-size:10px; opacity:0.7; background:rgba(0,0,0,0.3); padding:2px 5px; border-radius:3px;">Ad</div>' .
           '</a>' .
           '</div>';
}

/**
 * Determine creative type for response
 */
function determineCreativeType($campaign) {
    if (!empty($campaign['video_url'])) return 3; // Video
    if (!empty($campaign['html_content'])) return 2; // Rich Media
    return 1; // Banner/Image
}
?>