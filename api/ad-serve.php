<?php
// Simplified Ad serving script for RTB & RON Platform
// Current Date: 2025-06-23 23:48:23
// Current User: simoncode12

// Set CORS headers
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/javascript; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Debug mode
$DEBUG = true;

// Essential parameters
$zone_id = isset($_GET['zone_id']) ? intval($_GET['zone_id']) : 0;
$container_id = isset($_GET['container']) ? $_GET['container'] : '';
$width = isset($_GET['width']) ? intval($_GET['width']) : 0;
$height = isset($_GET['height']) ? intval($_GET['height']) : 0;

// Log incoming request
error_log("AdStart Request - Zone: $zone_id, Container: $container_id, Size: {$width}x{$height}");

// Include database configuration
require_once dirname(__DIR__) . '/config/database.php';

// Generate unique IDs
$request_id = uniqid('req_');
$impression_id = uniqid('imp_');

try {
    // Check if zone exists and is active
    $stmt = $pdo->prepare("SELECT z.*, w.domain, w.publisher_id FROM zones z JOIN websites w ON z.website_id = w.id WHERE z.id = ?");
    $stmt->execute([$zone_id]);
    $zone = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$zone) {
        error_log("Zone $zone_id not found");
        outputNoAd("Zone not found", $container_id, $width, $height);
        exit;
    }
    
    if ($zone['status'] != 'active') {
        error_log("Zone $zone_id is not active: {$zone['status']}");
        outputNoAd("Zone is not active", $container_id, $width, $height);
        exit;
    }
    
    // Parse zone size
    list($zone_width, $zone_height) = explode('x', $zone['size']);
    
    // Use zone dimensions if not provided
    if ($width == 0 || $height == 0) {
        $width = $zone_width;
        $height = $zone_height;
    }
    
    if ($DEBUG) error_log("[DEBUG] Looking for ads of size: {$width}x{$height}");

    // SIMPLIFIED APPROACH: Find any active creative that matches the size
    $stmt = $pdo->prepare("
        SELECT c.id AS campaign_id, c.name AS campaign_name, c.type AS campaign_type, 
               cr.id AS creative_id, cr.name AS creative_name, cr.creative_type,
               cr.width, cr.height, cr.html_content, cr.image_url, 
               cr.click_url, cr.bid_amount, c.endpoint_url
        FROM campaigns c 
        JOIN creatives cr ON c.id = cr.campaign_id
        WHERE c.status = 'active'
        AND cr.status = 'active'
        AND cr.width = ?
        AND cr.height = ?
        ORDER BY c.type = 'rtb' DESC, cr.bid_amount DESC
        LIMIT 1
    ");
    
    $stmt->execute([$width, $height]);
    $creative = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$creative) {
        // Check what sizes are available for better diagnostics
        $size_query = $pdo->query("
            SELECT cr.width, cr.height, COUNT(*) as count
            FROM creatives cr
            JOIN campaigns c ON cr.campaign_id = c.id
            WHERE c.status = 'active' AND cr.status = 'active'
            GROUP BY cr.width, cr.height
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        $size_list = '';
        foreach ($size_query as $size) {
            $size_list .= "{$size['width']}x{$size['height']} ({$size['count']}), ";
        }
        
        error_log("No active creatives found for size {$width}x{$height}. Available sizes: " . $size_list);
        outputNoAd("No matching ads found for size {$width}x{$height}", $container_id, $width, $height);
        exit;
    }
    
    // We found a creative! Now handle based on type
    error_log("Found creative ID {$creative['creative_id']} for campaign {$creative['campaign_name']} ({$creative['campaign_type']})");
    
    $ad_html = '';
    
    // Handle RTB campaign with endpoint
    if ($creative['campaign_type'] == 'rtb' && !empty($creative['endpoint_url'])) {
        // Create simplified OpenRTB bid request
        $rtb_request = [
            'id' => $request_id,
            'imp' => [[
                'id' => $impression_id,
                'banner' => [
                    'w' => (int)$width,
                    'h' => (int)$height,
                    'format' => [['w' => (int)$width, 'h' => (int)$height]]
                ],
                'tagid' => (string)$zone_id
            ]],
            'site' => [
                'id' => (string)$zone_id,
                'domain' => $zone['domain'],
                'publisher' => ['id' => (string)$zone['publisher_id']]
            ],
            'device' => [
                'ip' => $_SERVER['REMOTE_ADDR'],
                'ua' => $_SERVER['HTTP_USER_AGENT']
            ],
            'at' => 1,
            'tmax' => 500,
            'cur' => ['USD']
        ];
        
        error_log("Calling RTB endpoint: " . $creative['endpoint_url']);
        
        // Make RTB request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $creative['endpoint_url']);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($rtb_request));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-openrtb-version: 2.5'
        ]);
        
        $rtb_response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        if ($curl_error) {
            error_log("RTB error: " . $curl_error);
            // Fall back to using the creative directly
        } elseif ($http_code == 200 && $rtb_response) {
            $rtb_data = json_decode($rtb_response, true);
            if (isset($rtb_data['seatbid'][0]['bid'][0]['adm'])) {
                $ad_html = $rtb_data['seatbid'][0]['bid'][0]['adm'];
                error_log("Using RTB response content");
            } else {
                error_log("RTB response invalid format: " . substr($rtb_response, 0, 100));
            }
        } else {
            error_log("RTB response error, code: " . $http_code);
        }
    }
    
    // If we don't have ad content yet, use the creative's content
    if (empty($ad_html)) {
        if ($creative['creative_type'] == 'html5' || !empty($creative['html_content'])) {
            $ad_html = $creative['html_content'];
        } else if (!empty($creative['image_url'])) {
            $ad_html = '<a href="' . htmlspecialchars($creative['click_url']) . '" target="_blank" rel="nofollow noopener">' .
                      '<img src="' . htmlspecialchars($creative['image_url']) . '" ' .
                      'width="' . $width . '" height="' . $height . '" ' .
                      'style="border:0; display:block;" alt="Advertisement">' .
                      '</a>';
        } else {
            $ad_html = '<div style="width:100%; height:100%; display:flex; justify-content:center; align-items:center; background:#f0f0f0; border:1px solid #ccc;">' .
                      '<span style="font-family: Arial, sans-serif; font-size: 12px;">Ad Content</span></div>';
        }
    }
    
    // Log the impression
    $stmt = $pdo->prepare("
        INSERT INTO bid_logs (
            request_id, campaign_id, creative_id, zone_id,
            bid_amount, win_price, impression_id, status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'win', NOW())
    ");
    
    $stmt->execute([
        $request_id,
        $creative['campaign_id'],
        $creative['creative_id'],
        $zone_id,
        $creative['bid_amount'],
        $creative['bid_amount'],
        $impression_id
    ]);
    
    $bid_log_id = $pdo->lastInsertId();
    
    // Generate tracking URLs
    $impression_url = "https://up.adstart.click/api/click-track.php?type=impression&bid_id={$bid_log_id}";
    $click_url = "https://up.adstart.click/api/click-track.php?type=click&bid_id={$bid_log_id}";
    
    // Prepare dimensions object
    $dimensions = json_encode(['width' => $width, 'height' => $height]);
    
    // Output the JavaScript with the ad
    echo "(function(){";
    echo "  console.log('AdStart: Serving ad for container: " . $container_id . "');";
    echo "  if(typeof window['adstart_display_" . $container_id . "'] === 'function') {";
    echo "    window['adstart_display_" . $container_id . "'](";
    echo      json_encode($ad_html) . ", ";
    echo      json_encode($impression_url) . ", ";
    echo      json_encode($click_url) . ", ";
    echo      $dimensions;
    echo "    );";
    echo "  } else {";
    echo "    console.error('AdStart: Callback function adstart_display_" . $container_id . " not found');";
    echo "  }";
    echo "})();";
    
} catch (Exception $e) {
    error_log("Ad serving error: " . $e->getMessage());
    outputNoAd("System error: " . $e->getMessage(), $container_id, $width, $height);
}

// Function to output a "no ad" response
function outputNoAd($reason, $container_id, $width = 0, $height = 0) {
    $dimensions = ($width && $height) ? ['width' => $width, 'height' => $height] : null;
    
    $no_ad_html = '<div style="width:100%; height:100%; display:flex; flex-direction:column; justify-content:center; align-items:center; text-align:center; background:#f8f9fa; border:1px solid #ddd; font-family:Arial,sans-serif;">' .
                  '<span style="font-size:12px; color:#666;">Advertise Here</span>' .
                  '<span style="font-size:10px; margin-top:5px; color:#999;">RTB & RON Platform</span>' .
                  '</div>';
    
    echo "(function(){";
    echo "  console.log('AdStart: No ad - " . addslashes($reason) . "');";
    echo "  if(typeof window['adstart_display_" . $container_id . "'] === 'function') {";
    echo "    window['adstart_display_" . $container_id . "'](" . 
         json_encode($no_ad_html) . ", null, null, " . json_encode($dimensions) . ");";
    echo "  } else {";
    echo "    console.error('AdStart: Callback function adstart_display_" . $container_id . " not found for no-ad');";
    echo "  }";
    echo "})();";
    
    error_log("No ad served: " . $reason);
}
?>