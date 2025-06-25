<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    // Get parameters
    $bid_id = $_GET['bid_id'] ?? '';
    $campaign_id = $_GET['campaign_id'] ?? '';
    $creative_id = $_GET['creative_id'] ?? '';
    $price = $_GET['price'] ?? 0;
    
    // Validate required parameters
    if (!$bid_id || !$campaign_id || !$creative_id) {
        throw new Exception('Missing required parameters');
    }
    
    // Log the win notification
    error_log("Win notification: bid_id=$bid_id, campaign_id=$campaign_id, creative_id=$creative_id, price=$price");
    
    // Find the bid log entry
    $stmt = $pdo->prepare("
        SELECT bl.*, c.advertiser_id, cr.width, cr.height 
        FROM bid_logs bl 
        LEFT JOIN campaigns c ON bl.campaign_id = c.id 
        LEFT JOIN creatives cr ON bl.creative_id = cr.id 
        WHERE bl.campaign_id = ? AND bl.creative_id = ? 
        ORDER BY bl.created_at DESC 
        LIMIT 1
    ");
    $stmt->execute([$campaign_id, $creative_id]);
    $bid_log = $stmt->fetch();
    
    if (!$bid_log) {
        // Create a new bid log entry if not found
        $stmt = $pdo->prepare("
            INSERT INTO bid_logs (
                request_id, campaign_id, creative_id, bid_amount, win_price, 
                status, created_at
            ) VALUES (?, ?, ?, ?, ?, 'win', NOW())
        ");
        $stmt->execute([$bid_id, $campaign_id, $creative_id, $price, $price]);
    } else {
        // Update existing bid log with win information
        $stmt = $pdo->prepare("
            UPDATE bid_logs 
            SET status = 'win', win_price = ? 
            WHERE id = ?
        ");
        $stmt->execute([$price, $bid_log['id']]);
    }
    
    // Get campaign and publisher information
    $stmt = $pdo->prepare("
        SELECT c.*, a.name as advertiser_name, 
               w.publisher_id, p.revenue_share 
        FROM campaigns c 
        LEFT JOIN advertisers a ON c.advertiser_id = a.id 
        LEFT JOIN zones z ON 1=1  -- We'll need to determine zone from context
        LEFT JOIN websites w ON z.website_id = w.id 
        LEFT JOIN publishers p ON w.publisher_id = p.id 
        WHERE c.id = ? 
        LIMIT 1
    ");
    $stmt->execute([$campaign_id]);
    $campaign = $stmt->fetch();
    
    if ($campaign) {
        // Calculate revenue shares
        $total_revenue = (float)$price;
        $publisher_share = $campaign['revenue_share'] ?? 50.00;
        $publisher_revenue = $total_revenue * ($publisher_share / 100);
        $platform_revenue = $total_revenue - $publisher_revenue;
        
        // Track revenue (if we have publisher info)
        if ($campaign['publisher_id']) {
            $today = date('Y-m-d');
            
            // Update or insert revenue tracking
            $stmt = $pdo->prepare("
                INSERT INTO revenue_tracking (
                    publisher_id, campaign_id, zone_id, impressions, clicks, 
                    revenue, publisher_revenue, date
                ) VALUES (?, ?, NULL, 1, 0, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    impressions = impressions + 1,
                    revenue = revenue + VALUES(revenue),
                    publisher_revenue = publisher_revenue + VALUES(publisher_revenue)
            ");
            $stmt->execute([
                $campaign['publisher_id'],
                $campaign_id,
                $total_revenue,
                $publisher_revenue,
                $today
            ]);
        }
    }
    
    // Return success response
    echo json_encode([
        'status' => 'success',
        'message' => 'Win notification processed',
        'bid_id' => $bid_id,
        'price' => $price,
        'timestamp' => date('c')
    ]);
    
    // Log successful processing
    error_log("Win notification processed successfully: bid_id=$bid_id, price=$price");
    
} catch (Exception $e) {
    error_log('Win notification error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'timestamp' => date('c')
    ]);
}
?>