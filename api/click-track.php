<?php
// Click tracking script for RTB & RON Platform
// Current Date: 2025-06-23 19:17:36
// Current User: simoncode12

// Send 1x1 transparent GIF to close the connection early
header('Content-Type: image/gif');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
flush();

// Now continue processing without keeping the user waiting

// Get tracking parameters
$type = isset($_GET['type']) ? $_GET['type'] : '';
$bid_id = isset($_GET['bid_id']) ? intval($_GET['bid_id']) : 0;
$zone_id = isset($_GET['zone_id']) ? intval($_GET['zone_id']) : 0;
$request_id = isset($_GET['request_id']) ? $_GET['request_id'] : '';

// Skip if no bid ID
if ($bid_id <= 0) {
    exit;
}

// Include database configuration
require_once '../config/database.php';

try {
    // Get bid information
    $stmt = $pdo->prepare("
        SELECT bl.*, z.website_id, w.publisher_id, c.bid_type
        FROM bid_logs bl
        JOIN zones z ON bl.zone_id = z.id
        JOIN websites w ON z.website_id = w.id
        JOIN campaigns c ON bl.campaign_id = c.id
        WHERE bl.id = ?
    ");
    $stmt->execute([$bid_id]);
    $bid = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$bid) {
        exit;
    }
    
    $publisher_id = $bid['publisher_id'];
    $campaign_id = $bid['campaign_id'];
    $creative_id = $bid['creative_id'];
    $win_price = $bid['win_price'];
    $bid_type = $bid['bid_type'];
    
    if ($type === 'impression') {
        // Record impression in revenue_tracking
        $stmt = $pdo->prepare("
            INSERT INTO revenue_tracking (
                publisher_id, campaign_id, zone_id, 
                impressions, clicks, revenue, publisher_revenue, 
                date, created_at, updated_at
            ) VALUES (
                ?, ?, ?, 
                1, 0, ?, ?, 
                CURDATE(), NOW(), NOW()
            )
            ON DUPLICATE KEY UPDATE
                impressions = impressions + 1,
                revenue = CASE WHEN ? = 'cpm' THEN revenue + ? ELSE revenue END,
                publisher_revenue = CASE WHEN ? = 'cpm' THEN publisher_revenue + ? ELSE publisher_revenue END,
                updated_at = NOW()
        ");
        
        // Get publisher revenue share
        $stmt2 = $pdo->prepare("SELECT revenue_share FROM publishers WHERE id = ?");
        $stmt2->execute([$publisher_id]);
        $publisher = $stmt2->fetch(PDO::FETCH_ASSOC);
        $revenue_share = $publisher ? ($publisher['revenue_share'] / 100) : 0.7; // Default 70%
        
        // For CPM, we count revenue on impression
        $revenue = ($bid_type == 'cpm') ? $win_price : 0;
        $publisher_revenue = $revenue * $revenue_share;
        
        $stmt->execute([
            $publisher_id,
            $campaign_id,
            $zone_id,
            $revenue,
            $publisher_revenue,
            $bid_type,
            $revenue,
            $bid_type,
            $publisher_revenue
        ]);
        
        // Update daily statistics
        $stmt = $pdo->prepare("
            INSERT INTO daily_statistics (
                date, total_impressions, total_clicks, 
                total_revenue, publisher_revenue, platform_revenue,
                rtb_impressions, ron_impressions
            ) VALUES (
                CURDATE(), 1, 0, 
                ?, ?, ?,
                CASE WHEN (SELECT type FROM campaigns WHERE id = ?) = 'rtb' THEN 1 ELSE 0 END,
                CASE WHEN (SELECT type FROM campaigns WHERE id = ?) = 'ron' THEN 1 ELSE 0 END
            )
            ON DUPLICATE KEY UPDATE
                total_impressions = total_impressions + 1,
                total_revenue = total_revenue + ?,
                publisher_revenue = publisher_revenue + ?,
                platform_revenue = platform_revenue + ?,
                rtb_impressions = rtb_impressions + CASE WHEN (SELECT type FROM campaigns WHERE id = ?) = 'rtb' THEN 1 ELSE 0 END,
                ron_impressions = ron_impressions + CASE WHEN (SELECT type FROM campaigns WHERE id = ?) = 'ron' THEN 1 ELSE 0 END
        ");
        
        $platform_revenue = $revenue - $publisher_revenue;
        
        $stmt->execute([
            $revenue,
            $publisher_revenue,
            $platform_revenue,
            $campaign_id,
            $campaign_id,
            $revenue,
            $publisher_revenue,
            $platform_revenue,
            $campaign_id,
            $campaign_id
        ]);
        
    } elseif ($type === 'click') {
        // Update bid log status
        $stmt = $pdo->prepare("UPDATE bid_logs SET status = 'click' WHERE id = ?");
        $stmt->execute([$bid_id]);
        
        // Update revenue_tracking
        $stmt = $pdo->prepare("
            UPDATE revenue_tracking
            SET clicks = clicks + 1,
                revenue = CASE WHEN ? = 'cpc' THEN revenue + ? ELSE revenue END,
                publisher_revenue = CASE WHEN ? = 'cpc' THEN publisher_revenue + ? ELSE publisher_revenue END,
                updated_at = NOW()
            WHERE publisher_id = ?
            AND campaign_id = ?
            AND zone_id = ?
            AND date = CURDATE()
        ");
        
        // Get publisher revenue share
        $stmt2 = $pdo->prepare("SELECT revenue_share FROM publishers WHERE id = ?");
        $stmt2->execute([$publisher_id]);
        $publisher = $stmt2->fetch(PDO::FETCH_ASSOC);
        $revenue_share = $publisher ? ($publisher['revenue_share'] / 100) : 0.7; // Default 70%
        
        // For CPC, we count revenue on click
        $revenue = ($bid_type == 'cpc') ? $win_price : 0;
        $publisher_revenue = $revenue * $revenue_share;
        
        $stmt->execute([
            $bid_type,
            $revenue,
            $bid_type,
            $publisher_revenue,
            $publisher_id,
            $campaign_id,
            $zone_id
        ]);
        
        // Update daily statistics clicks
        $stmt = $pdo->prepare("
            UPDATE daily_statistics 
            SET total_clicks = total_clicks + 1,
                total_revenue = total_revenue + ?,
                publisher_revenue = publisher_revenue + ?,
                platform_revenue = platform_revenue + ?,
                updated_at = NOW()
            WHERE date = CURDATE()
        ");
        
        $platform_revenue = $revenue - $publisher_revenue;
        $stmt->execute([$revenue, $publisher_revenue, $platform_revenue]);
    }
    
} catch (Exception $e) {
    // Silent error logging
    error_log('Click tracking error: ' . $e->getMessage());
}
?>