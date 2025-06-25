<?php
/**
 * Live Bids API Endpoint
 * Returns recent bids for real-time monitoring
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache');

require_once '../config/database.php';

// Get last bid ID from request
$last_id = $_GET['last_id'] ?? 0;

// Get new bids
$stmt = $pdo->prepare("
    SELECT 
        bl.id,
        bl.created_at,
        bl.win_price,
        bl.status,
        bl.device_type,
        bl.country,
        c.name as campaign_name,
        c.type as campaign_type,
        z.name as zone_name
    FROM bid_logs bl
    LEFT JOIN campaigns c ON bl.campaign_id = c.id
    LEFT JOIN zones z ON bl.zone_id = z.id
    WHERE bl.id > ?
    ORDER BY bl.id DESC
    LIMIT 20
");

$stmt->execute([$last_id]);
$new_bids = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Format bids for response
$formatted_bids = [];
foreach ($new_bids as $bid) {
    $formatted_bids[] = [
        'id' => $bid['id'],
        'time' => date('H:i:s', strtotime($bid['created_at'])),
        'type' => $bid['campaign_type'] ?? 'unknown',
        'campaign' => $bid['campaign_name'] ?? 'Unknown',
        'zone' => $bid['zone_name'] ?? 'Zone ' . $bid['zone_id'],
        'price' => number_format($bid['win_price'], 4),
        'status' => $bid['status'],
        'status_icon' => $bid['status'] == 'win' ? 
            '<i class="fas fa-check-circle text-success"></i>' : 
            '<i class="fas fa-mouse-pointer text-warning"></i>',
        'device' => $bid['device_type'],
        'country' => $bid['country'] ?? 'US'
    ];
}

// Get updated stats
$stats = $pdo->query("
    SELECT 
        COUNT(*) as total_requests,
        SUM(CASE WHEN status = 'win' THEN 1 ELSE 0 END) as wins,
        SUM(win_price) as revenue
    FROM bid_logs
    WHERE created_at >= NOW() - INTERVAL 1 HOUR
")->fetch(PDO::FETCH_ASSOC);

// Return response
echo json_encode([
    'success' => true,
    'new_bids' => array_reverse($formatted_bids),
    'last_id' => !empty($new_bids) ? $new_bids[0]['id'] : $last_id,
    'stats' => $stats,
    'timestamp' => date('Y-m-d H:i:s')
]);
?>