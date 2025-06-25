<?php
/**
 * Cron job to update daily statistics
 * Run this every hour
 * Current Date: 2025-06-23 20:35:14
 * Current User: simoncode12
 */

// Set timezone
date_default_timezone_set('UTC');

// Define base path
define('BASE_PATH', '/home/user/web/up.adstart.click/public_html');

// Include database configuration
require_once BASE_PATH . '/config/database.php';

// Log start time
echo "[" . date('Y-m-d H:i:s') . "] Starting statistics update...\n";

// Get current date
$current_date = date('Y-m-d');
$current_hour = date('H');

try {
    // Calculate statistics for today
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT CASE WHEN status IN ('win', 'click') THEN id END) as total_impressions,
            COUNT(DISTINCT CASE WHEN status = 'click' THEN id END) as total_clicks,
            COALESCE(SUM(CASE WHEN status IN ('win', 'click') THEN win_price ELSE 0 END), 0) as total_revenue,
            COUNT(DISTINCT CASE WHEN status IN ('win', 'click') AND campaign_id IN (SELECT id FROM campaigns WHERE type = 'rtb') THEN id END) as rtb_impressions,
            COUNT(DISTINCT CASE WHEN status IN ('win', 'click') AND campaign_id IN (SELECT id FROM campaigns WHERE type = 'ron') THEN id END) as ron_impressions
        FROM bid_logs
        WHERE DATE(created_at) = ?
    ");
    
    $stmt->execute([$current_date]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Calculate publisher revenue (50% default or based on actual revenue share)
    $stmt_pub = $pdo->prepare("
        SELECT 
            SUM(rt.publisher_revenue) as publisher_revenue,
            SUM(rt.revenue) - SUM(rt.publisher_revenue) as platform_revenue
        FROM revenue_tracking rt
        WHERE rt.date = ?
    ");
    $stmt_pub->execute([$current_date]);
    $revenue_split = $stmt_pub->fetch(PDO::FETCH_ASSOC);
    
    $publisher_revenue = $revenue_split['publisher_revenue'] ?? ($stats['total_revenue'] * 0.5);
    $platform_revenue = $revenue_split['platform_revenue'] ?? ($stats['total_revenue'] * 0.5);
    
    // Update or insert daily statistics
    $stmt = $pdo->prepare("
        INSERT INTO daily_statistics (
            date, total_impressions, total_clicks, total_revenue,
            publisher_revenue, platform_revenue, rtb_impressions, ron_impressions,
            created_at, updated_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
        )
        ON DUPLICATE KEY UPDATE
            total_impressions = VALUES(total_impressions),
            total_clicks = VALUES(total_clicks),
            total_revenue = VALUES(total_revenue),
            publisher_revenue = VALUES(publisher_revenue),
            platform_revenue = VALUES(platform_revenue),
            rtb_impressions = VALUES(rtb_impressions),
            ron_impressions = VALUES(ron_impressions),
            updated_at = NOW()
    ");
    
    $stmt->execute([
        $current_date,
        $stats['total_impressions'],
        $stats['total_clicks'],
        $stats['total_revenue'],
        $publisher_revenue,
        $platform_revenue,
        $stats['rtb_impressions'],
        $stats['ron_impressions']
    ]);
    
    echo "[" . date('Y-m-d H:i:s') . "] Statistics updated successfully\n";
    echo "- Date: $current_date\n";
    echo "- Total Impressions: " . number_format($stats['total_impressions']) . "\n";
    echo "- RTB Impressions: " . number_format($stats['rtb_impressions']) . "\n";
    echo "- RON Impressions: " . number_format($stats['ron_impressions']) . "\n";
    echo "- Total Clicks: " . number_format($stats['total_clicks']) . "\n";
    echo "- Total Revenue: $" . number_format($stats['total_revenue'], 4) . "\n";
    echo "- Publisher Revenue: $" . number_format($publisher_revenue, 4) . "\n";
    echo "- Platform Revenue: $" . number_format($platform_revenue, 4) . "\n";
    
    // Update campaign budgets spent
    $stmt = $pdo->prepare("
        UPDATE campaigns c
        SET c.updated_at = NOW()
        WHERE c.id IN (
            SELECT DISTINCT campaign_id 
            FROM bid_logs 
            WHERE DATE(created_at) = ?
        )
    ");
    $stmt->execute([$current_date]);
    
    // Clean up old bid logs (keep last 90 days)
    $cleanup_date = date('Y-m-d', strtotime('-90 days'));
    $stmt = $pdo->prepare("DELETE FROM bid_logs WHERE created_at < ? LIMIT 10000");
    $stmt->execute([$cleanup_date . ' 00:00:00']);
    $deleted = $stmt->rowCount();
    
    if ($deleted > 0) {
        echo "[" . date('Y-m-d H:i:s') . "] Cleaned up $deleted old bid logs\n";
    }
    
    // Log success
    echo "[" . date('Y-m-d H:i:s') . "] Cron job completed successfully\n";
    echo "========================================\n\n";
    
} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    echo "========================================\n\n";
    
    // Send email notification for errors (optional)
    // mail('admin@adstart.click', 'Statistics Update Error', $e->getMessage());
}
?>