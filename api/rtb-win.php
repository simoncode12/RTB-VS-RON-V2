<?php
/**
 * RTB Win Notification Handler - FIXED
 * Processes win notifications from external DSPs
 * Date: 2025-06-23 21:50:15
 * Author: simoncode12
 */

header('Content-Type: application/json');

$campaign_id = $_GET['campaign_id'] ?? 0;
$creative_id = $_GET['creative_id'] ?? 0;
$price = $_GET['price'] ?? 0;
$imp_id = $_GET['imp_id'] ?? '';
$request_id = $_GET['request_id'] ?? '';

require_once '../config/database.php';

try {
    // Validate parameters
    if (!$campaign_id || !$creative_id || !$price) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required parameters']);
        exit;
    }
    
    // Clean price value (remove ${AUCTION_PRICE} macro if not replaced)
    if (strpos($price, '$') !== false) {
        $price = str_replace(['$', '{AUCTION_PRICE}'], '', $price);
    }
    $price = floatval($price);
    
    // Log the win
    $stmt = $pdo->prepare("
        INSERT INTO bid_logs (
            request_id, campaign_id, creative_id, zone_id,
            bid_amount, win_price, impression_id,
            user_agent, ip_address, country, device_type,
            browser, os, status, created_at
        ) VALUES (
            ?, ?, ?, 0,
            ?, ?, ?,
            ?, ?, 'US', 'desktop',
            '', '', 'win', NOW()
        )
    ");
    
    $stmt->execute([
        $request_id,
        $campaign_id,
        $creative_id,
        $price,
        $price,
        $imp_id,
        $_SERVER['HTTP_USER_AGENT'] ?? '',
        $_SERVER['REMOTE_ADDR']
    ]);
    
    // Check if campaigns table has daily_spent and total_spent columns
    $check_columns = $pdo->query("SHOW COLUMNS FROM campaigns LIKE 'daily_spent'")->rowCount();
    
    if ($check_columns > 0) {
        // Update campaign spend
        $stmt = $pdo->prepare("
            UPDATE campaigns 
            SET daily_spent = COALESCE(daily_spent, 0) + ?,
                total_spent = COALESCE(total_spent, 0) + ?
            WHERE id = ?
        ");
        $stmt->execute([$price, $price, $campaign_id]);
    }
    
    // Update daily statistics
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("
        INSERT INTO daily_statistics (date, total_impressions, total_revenue, rtb_impressions)
        VALUES (?, 1, ?, 1)
        ON DUPLICATE KEY UPDATE
        total_impressions = total_impressions + 1,
        total_revenue = total_revenue + ?,
        rtb_impressions = rtb_impressions + 1
    ");
    $stmt->execute([$today, $price, $price]);
    
    error_log("RTB Win processed: Campaign {$campaign_id}, Price {$price}");
    
    // Return success response
    echo json_encode([
        'status' => 'success',
        'campaign_id' => $campaign_id,
        'price' => $price,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    error_log("RTB Win Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error', 'debug' => $e->getMessage()]);
}
?>