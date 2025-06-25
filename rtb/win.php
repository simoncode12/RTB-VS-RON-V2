<?php
/**
 * RTB Win Notification Handler
 * Current Date: 2025-06-25 08:14:48
 * Current User: simoncode12
 */

// Set transparent 1x1 pixel GIF header for tracking
header('Content-Type: image/gif');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Include database configuration
require_once dirname(__DIR__) . '/config/database.php';

// Debug mode
$DEBUG = true;
$log_prefix = "[RTB-WIN] ";

// Parameters
$bid_id = $_GET['bid_id'] ?? '';
$price = isset($_GET['price']) ? (float)$_GET['price'] : 0;
$creative_id = $_GET['creative_id'] ?? 0;
$campaign_id = $_GET['campaign_id'] ?? 0;
$website_id = $_GET['website_id'] ?? 0;
$publisher_id = $_GET['publisher_id'] ?? 0;
$zone_id = $_GET['zone_id'] ?? null;
$ext_nurl = $_GET['ext_nurl'] ?? null; // External RTB win notification URL
$revenue_share = isset($_GET['revenue_share']) ? (float)$_GET['revenue_share'] : 70; // Default to 70%

if ($DEBUG) {
    error_log($log_prefix . "Win notification: bid_id=$bid_id, price=$price, creative_id=$creative_id, campaign_id=$campaign_id, website_id=$website_id, zone_id=$zone_id");
}

// Validate parameters
if (empty($bid_id) || $price <= 0 || $creative_id <= 0 || $campaign_id <= 0) {
    error_log($log_prefix . "Invalid win parameters");
    outputPixel();
    exit;
}

try {
    // Make sure zone_id is valid or NULL
    if ($zone_id && is_numeric($zone_id)) {
        $stmt = $pdo->prepare("SELECT id FROM zones WHERE id = ?");
        $stmt->execute([$zone_id]);
        if (!$stmt->fetchColumn()) {
            if ($DEBUG) error_log($log_prefix . "Invalid zone_id: $zone_id, setting to NULL");
            $zone_id = null;
        }
    } else {
        $zone_id = null;
    }
    
    // Get campaign type to confirm if RTB or RON
    $stmt = $pdo->prepare("SELECT type, endpoint_url FROM campaigns WHERE id = ?");
    $stmt->execute([$campaign_id]);
    $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $campaign_type = $campaign ? $campaign['type'] : 'unknown';
    $has_external_endpoint = !empty($campaign['endpoint_url']);
    
    if ($DEBUG) {
        error_log($log_prefix . "Campaign type: $campaign_type" . ($has_external_endpoint ? " (with external endpoint)" : ""));
    }
    
    // 1. Update the bid log status to 'win'
    $stmt = $pdo->prepare("
        UPDATE bid_logs 
        SET status = 'win', win_price = ? 
        WHERE (request_id LIKE ? OR impression_id LIKE ?) AND (campaign_id = ?)
    ");
    $stmt->execute([$price, $bid_id . '%', $bid_id . '%', $campaign_id]);
    $updated = $stmt->rowCount();
    
    if ($updated == 0) {
        if ($DEBUG) error_log($log_prefix . "No bid log found for ID: $bid_id, creating new entry");
        
        // Create a new bid log entry if not found
        $stmt = $pdo->prepare("
            INSERT INTO bid_logs (
                request_id, campaign_id, creative_id, zone_id,
                bid_amount, win_price, impression_id,
                country, device_type, browser, os, status, created_at
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?, ?,
                'XX', 'unknown', 'unknown', 'unknown', 'win', NOW()
            )
        ");
        
        $stmt->execute([
            $bid_id,
            $campaign_id,
            $creative_id,
            $zone_id, // This can be NULL now
            $price,
            $price, 
            uniqid('imp_')
        ]);
    }
    
    // 2. Calculate revenue share
    $publisher_revenue = round($price * ($revenue_share / 100), 4);
    
    if (!$publisher_id && $website_id) {
        // Look up publisher if not provided
        $stmt = $pdo->prepare("SELECT publisher_id FROM websites WHERE id = ?");
        $stmt->execute([$website_id]);
        $website = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($website) {
            $publisher_id = $website['publisher_id'];
        }
    }
    
    // 3. Record revenue if publisher found
    if ($publisher_id) {
        $stmt = $pdo->prepare("
            INSERT INTO revenue_tracking 
            (publisher_id, campaign_id, zone_id, impressions, clicks, revenue, publisher_revenue, date)
            VALUES (?, ?, ?, 1, 0, ?, ?, CURDATE())
            ON DUPLICATE KEY UPDATE
            impressions = impressions + 1,
            revenue = revenue + ?,
            publisher_revenue = publisher_revenue + ?
        ");
        
        $stmt->execute([
            $publisher_id, 
            $campaign_id, 
            $zone_id ?: NULL, // This can be NULL now
            $price, 
            $publisher_revenue,
            $price,
            $publisher_revenue
        ]);
        
        if ($DEBUG) {
            $campaign_desc = $has_external_endpoint ? "External RTB" : $campaign_type;
            error_log($log_prefix . "Revenue recorded for $campaign_desc campaign: publisher_id=$publisher_id, campaign_id=$campaign_id, revenue=$price, publisher_share=$publisher_revenue");
        }
    } else {
        error_log($log_prefix . "No publisher found for website_id: $website_id");
    }
    
    // 4. Handle external win notification if provided (for external RTB campaigns)
    if ($ext_nurl) {
        // Replace auction price macro in the URL
        $ext_win_url = str_replace('${AUCTION_PRICE}', $price, urldecode($ext_nurl));
        
        if ($DEBUG) error_log($log_prefix . "Calling external win notification URL: " . $ext_win_url);
        
        // Make async HTTP request to external win URL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $ext_win_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 1); // 1 second timeout
        curl_setopt($ch, CURLOPT_HEADER, 0);
        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        if ($curl_error && $DEBUG) {
            error_log($log_prefix . "Error calling external win URL: " . $curl_error);
        }
    }
    
    // Output 1x1 tracking pixel
    outputPixel();
    
} catch (Exception $e) {
    error_log($log_prefix . "Error: " . $e->getMessage());
    outputPixel();
}

// Output 1x1 transparent GIF
function outputPixel() {
    // 1x1 transparent GIF
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
}
?>