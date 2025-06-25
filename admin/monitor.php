<?php
/**
 * Real-time Monitoring Dashboard
 * Monitor ad serving, bidding, and system performance
 * Version: 1.0.2
 * Date: 2025-06-23 21:26:33
 * Author: simoncode12
 */

include 'includes/header.php';

// Get last 100 bid logs
$recent_bids = $pdo->query("
    SELECT 
        bl.*,
        c.name as campaign_name,
        c.type as campaign_type,
        cr.name as creative_name,
        z.name as zone_name,
        w.name as website_name
    FROM bid_logs bl
    LEFT JOIN campaigns c ON bl.campaign_id = c.id
    LEFT JOIN creatives cr ON bl.creative_id = cr.id
    LEFT JOIN zones z ON bl.zone_id = z.id
    LEFT JOIN websites w ON z.website_id = w.id
    ORDER BY bl.created_at DESC
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

// Get real-time stats (last hour)
$hourly_stats = $pdo->query("
    SELECT 
        COUNT(*) as total_requests,
        SUM(CASE WHEN status = 'win' THEN 1 ELSE 0 END) as wins,
        SUM(CASE WHEN status = 'click' THEN 1 ELSE 0 END) as clicks,
        COALESCE(SUM(win_price), 0) as revenue,
        COALESCE(AVG(win_price), 0) as avg_bid,
        COALESCE(MAX(win_price), 0) as max_bid,
        COALESCE(MIN(win_price), 0) as min_bid,
        COUNT(DISTINCT zone_id) as active_zones,
        COUNT(DISTINCT campaign_id) as active_campaigns
    FROM bid_logs
    WHERE created_at >= NOW() - INTERVAL 1 HOUR
")->fetch(PDO::FETCH_ASSOC);

// Ensure all values are numeric
$hourly_stats = array_map(function($value) {
    return $value === null ? 0 : floatval($value);
}, $hourly_stats);

// Get bid distribution
$bid_distribution = $pdo->query("
    SELECT 
        c.type,
        COUNT(*) as count,
        COALESCE(SUM(bl.win_price), 0) as revenue
    FROM bid_logs bl
    JOIN campaigns c ON bl.campaign_id = c.id
    WHERE bl.created_at >= NOW() - INTERVAL 1 HOUR
    AND bl.status = 'win'
    GROUP BY c.type
")->fetchAll(PDO::FETCH_ASSOC);

// Get zone performance
$zone_performance = $pdo->query("
    SELECT 
        z.id,
        z.name,
        z.size,
        COUNT(bl.id) as requests,
        SUM(CASE WHEN bl.status = 'win' THEN 1 ELSE 0 END) as wins,
        COALESCE(SUM(bl.win_price), 0) as revenue
    FROM zones z
    LEFT JOIN bid_logs bl ON z.id = bl.zone_id 
        AND bl.created_at >= NOW() - INTERVAL 1 HOUR
    WHERE z.status = 'active'
    GROUP BY z.id
    HAVING requests > 0
    ORDER BY revenue DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="row">
    <div class="col-12">
        <h1 class="h3 mb-4">
            <i class="fas fa-tachometer-alt"></i> Real-time Monitor
            <small class="text-muted">Live system performance</small>
        </h1>
        <div class="text-muted mb-3">
            <small>
                <i class="fas fa-clock"></i> <?php echo date('Y-m-d H:i:s'); ?> UTC | 
                <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['username'] ?? 'Unknown'); ?> |
                <i class="fas fa-sync-alt"></i> Auto-refresh: <span id="refresh-counter">30</span>s
            </small>
        </div>
    </div>
</div>

<!-- Real-time Stats -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card bg-primary text-white">
            <div class="card-body">
                <h6 class="text-uppercase mb-1">Requests (1h)</h6>
                <h2 class="mb-0" id="total-requests"><?php echo number_format($hourly_stats['total_requests']); ?></h2>
                <small>Fill Rate: <?php echo $hourly_stats['total_requests'] > 0 ? number_format(($hourly_stats['wins'] / $hourly_stats['total_requests']) * 100, 1) : '0.0'; ?>%</small>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body">
                <h6 class="text-uppercase mb-1">Revenue (1h)</h6>
                <h2 class="mb-0">$<?php echo number_format($hourly_stats['revenue'], 4); ?></h2>
                <small>Avg Bid: $<?php echo number_format($hourly_stats['avg_bid'], 4); ?></small>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card bg-info text-white">
            <div class="card-body">
                <h6 class="text-uppercase mb-1">Active Zones</h6>
                <h2 class="mb-0"><?php echo number_format($hourly_stats['active_zones']); ?></h2>
                <small>Serving ads</small>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card bg-warning text-white">
            <div class="card-body">
                <h6 class="text-uppercase mb-1">Active Campaigns</h6>
                <h2 class="mb-0"><?php echo number_format($hourly_stats['active_campaigns']); ?></h2>
                <small>Bidding now</small>
            </div>
        </div>
    </div>
</div>

<!-- RTB vs RON Distribution -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-pie"></i> RTB vs RON Distribution (1h)</h5>
            </div>
            <div class="card-body">
                <?php if (!empty($bid_distribution)): ?>
                    <div style="max-height: 300px;">
                        <canvas id="bidTypeChart"></canvas>
                    </div>
                    <div class="mt-3">
                        <?php foreach ($bid_distribution as $dist): ?>
                        <div class="d-flex justify-content-between mb-2">
                            <span>
                                <span class="badge bg-<?php echo $dist['type'] == 'rtb' ? 'primary' : 'success'; ?>">
                                    <?php echo strtoupper($dist['type']); ?>
                                </span>
                                <?php echo number_format(floatval($dist['count'])); ?> wins
                            </span>
                            <strong>$<?php echo number_format(floatval($dist['revenue']), 4); ?></strong>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4">
                        <p class="text-muted">No bid data available for the last hour</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-bullseye"></i> Zone Performance (1h)</h5>
            </div>
            <div class="card-body">
                <?php if (!empty($zone_performance)): ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Zone</th>
                                    <th>Size</th>
                                    <th>Requests</th>
                                    <th>Fill Rate</th>
                                    <th>Revenue</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($zone_performance as $zone): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($zone['name']); ?></td>
                                    <td><?php echo $zone['size']; ?></td>
                                    <td><?php echo number_format(floatval($zone['requests'])); ?></td>
                                    <td>
                                        <?php 
                                        $requests = floatval($zone['requests']);
                                        $wins = floatval($zone['wins']);
                                        $fill_rate = $requests > 0 ? ($wins / $requests) * 100 : 0;
                                        ?>
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar <?php echo $fill_rate > 80 ? 'bg-success' : ($fill_rate > 50 ? 'bg-warning' : 'bg-danger'); ?>" 
                                                 style="width: <?php echo $fill_rate; ?>%">
                                                <?php echo number_format($fill_rate, 1); ?>%
                                            </div>
                                        </div>
                                    </td>
                                    <td>$<?php echo number_format(floatval($zone['revenue']), 4); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4">
                        <p class="text-muted">No zone activity in the last hour</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Live Bid Log -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-stream"></i> Live Bid Log (Last 100)</h5>
        <div>
            <span class="badge bg-success">RTB</span>
            <span class="badge bg-primary">RON</span>
            <span class="badge bg-warning">Click</span>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
            <table class="table table-sm" id="bid-log-table">
                <thead style="position: sticky; top: 0; background: white; z-index: 10;">
                    <tr>
                        <th>Time</th>
                        <th>Type</th>
                        <th>Campaign</th>
                        <th>Zone</th>
                        <th>Bid</th>
                        <th>Status</th>
                        <th>Device</th>
                        <th>Country</th>
                    </tr>
                </thead>
                <tbody id="bid-log-body">
                    <?php if (!empty($recent_bids)): ?>
                        <?php foreach ($recent_bids as $bid): ?>
                        <tr>
                            <td>
                                <small><?php echo date('H:i:s', strtotime($bid['created_at'])); ?></small>
                            </td>
                            <td>
                                <span class="badge bg-<?php echo ($bid['campaign_type'] ?? '') == 'rtb' ? 'success' : 'primary'; ?>">
                                    <?php echo strtoupper($bid['campaign_type'] ?? 'N/A'); ?>
                                </span>
                            </td>
                            <td>
                                <small><?php echo htmlspecialchars($bid['campaign_name'] ?? 'Unknown'); ?></small>
                            </td>
                            <td>
                                <small><?php echo htmlspecialchars($bid['zone_name'] ?? 'Zone ' . $bid['zone_id']); ?></small>
                            </td>
                            <td>$<?php echo number_format(floatval($bid['win_price'] ?? 0), 4); ?></td>
                            <td>
                                <?php if (($bid['status'] ?? '') == 'win'): ?>
                                    <i class="fas fa-check-circle text-success"></i>
                                <?php elseif (($bid['status'] ?? '') == 'click'): ?>
                                    <i class="fas fa-mouse-pointer text-warning"></i>
                                <?php else: ?>
                                    <i class="fas fa-times-circle text-danger"></i>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small><?php echo htmlspecialchars($bid['device_type'] ?? 'Unknown'); ?></small>
                            </td>
                            <td>
                                <small><?php echo htmlspecialchars($bid['country'] ?? 'US'); ?></small>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">
                                No bid logs available
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js"></script>
<script>
// Initialize Chart.js only if data exists
document.addEventListener('DOMContentLoaded', function() {
    <?php if (!empty($bid_distribution)): ?>
    // RTB vs RON Chart
    const ctx = document.getElementById('bidTypeChart');
    if (ctx) {
        const bidData = <?php echo json_encode($bid_distribution); ?>;
        
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: bidData.map(d => d.type.toUpperCase()),
                datasets: [{
                    data: bidData.map(d => parseFloat(d.count) || 0),
                    backgroundColor: ['#0d6efd', '#198754'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        padding: 20
                    }
                }
            }
        });
    }
    <?php endif; ?>
});

// Auto-refresh countdown
let refreshCount = 30;
const counterElement = document.getElementById('refresh-counter');

const countdownInterval = setInterval(function() {
    refreshCount--;
    if (counterElement) {
        counterElement.textContent = refreshCount;
    }
    
    if (refreshCount <= 0) {
        clearInterval(countdownInterval);
        location.reload();
    }
}, 1000);

// Prevent memory leaks - clear interval on page unload
window.addEventListener('beforeunload', function() {
    clearInterval(countdownInterval);
});

function formatNumber(num) {
    return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
}
</script>

<style>
.fade-in {
    animation: fadeIn 0.5s;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

#bid-log-table tbody tr {
    transition: background-color 0.3s;
}

#bid-log-table tbody tr:hover {
    background-color: rgba(0, 0, 0, 0.05);
}

/* Fix for sticky header */
.table-responsive {
    position: relative;
}

/* Prevent infinite scroll */
.card-body {
    max-height: 600px;
}
</style>

<?php include 'includes/footer.php'; ?>