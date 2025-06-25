<?php
include 'includes/header.php';

// Get statistics
$stats = [
    'total_campaigns' => $pdo->query("SELECT COUNT(*) FROM campaigns")->fetchColumn(),
    'active_campaigns' => $pdo->query("SELECT COUNT(*) FROM campaigns WHERE status = 'active'")->fetchColumn(),
    'total_advertisers' => $pdo->query("SELECT COUNT(*) FROM advertisers WHERE status = 'active'")->fetchColumn(),
    'total_publishers' => $pdo->query("SELECT COUNT(*) FROM publishers WHERE status = 'active'")->fetchColumn(),
    'total_websites' => $pdo->query("SELECT COUNT(*) FROM websites WHERE status = 'active'")->fetchColumn(),
    'total_zones' => $pdo->query("SELECT COUNT(*) FROM zones WHERE status = 'active'")->fetchColumn()
];

// Get recent campaigns
$recent_campaigns = $pdo->query("
    SELECT c.*, a.name as advertiser_name, cat.name as category_name 
    FROM campaigns c 
    LEFT JOIN advertisers a ON c.advertiser_id = a.id 
    LEFT JOIN categories cat ON c.category_id = cat.id 
    ORDER BY c.created_at DESC 
    LIMIT 5
")->fetchAll();

// Get revenue data for chart (last 7 days)
$revenue_data = $pdo->query("
    SELECT DATE(created_at) as date, SUM(revenue) as daily_revenue 
    FROM revenue_tracking 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) 
    GROUP BY DATE(created_at) 
    ORDER BY date
")->fetchAll();
?>

<div class="row">
    <div class="col-12">
        <h1 class="h3 mb-4">
            <i class="fas fa-chart-line"></i> Dashboard
            <small class="text-muted">Overview and Statistics</small>
        </h1>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
        <div class="card bg-primary text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="card-title">Total Campaigns</h6>
                        <h4 class="mb-0"><?php echo formatNumber($stats['total_campaigns']); ?></h4>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-bullhorn fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
        <div class="card bg-success text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="card-title">Active Campaigns</h6>
                        <h4 class="mb-0"><?php echo formatNumber($stats['active_campaigns']); ?></h4>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-play fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
        <div class="card bg-info text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="card-title">Advertisers</h6>
                        <h4 class="mb-0"><?php echo formatNumber($stats['total_advertisers']); ?></h4>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-user-tie fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
        <div class="card bg-warning text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="card-title">Publishers</h6>
                        <h4 class="mb-0"><?php echo formatNumber($stats['total_publishers']); ?></h4>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-globe fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
        <div class="card bg-secondary text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="card-title">Websites</h6>
                        <h4 class="mb-0"><?php echo formatNumber($stats['total_websites']); ?></h4>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-sitemap fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
        <div class="card bg-dark text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="card-title">Zones</h6>
                        <h4 class="mb-0"><?php echo formatNumber($stats['total_zones']); ?></h4>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-map-marker-alt fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Recent Campaigns -->
    <div class="col-lg-8 mb-4">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-clock"></i> Recent Campaigns</h5>
                <a href="rtb-sell.php" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus"></i> New Campaign
                </a>
            </div>
            <div class="card-body">
                <?php if (empty($recent_campaigns)): ?>
                    <div class="text-center py-4">
                        <i class="fas fa-bullhorn fa-3x text-muted mb-3"></i>
                        <p class="text-muted">No campaigns created yet.</p>
                        <a href="rtb-sell.php" class="btn btn-primary">Create Your First Campaign</a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Campaign</th>
                                    <th>Type</th>
                                    <th>Advertiser</th>
                                    <th>Category</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_campaigns as $campaign): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($campaign['name']); ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $campaign['type'] == 'rtb' ? 'primary' : 'success'; ?>">
                                            <?php echo strtoupper($campaign['type']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($campaign['advertiser_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($campaign['category_name'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $campaign['status'] == 'active' ? 'success' : 
                                                ($campaign['status'] == 'paused' ? 'warning' : 'secondary'); 
                                        ?>">
                                            <?php echo ucfirst($campaign['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($campaign['created_at'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Quick Actions -->
    <div class="col-lg-4 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-bolt"></i> Quick Actions</h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="rtb-sell.php" class="btn btn-primary">
                        <i class="fas fa-exchange-alt"></i> Create RTB Campaign
                    </a>
                    <a href="ron-campaign.php" class="btn btn-success">
                        <i class="fas fa-network-wired"></i> Create RON Campaign
                    </a>
                    <a href="advertiser.php" class="btn btn-info">
                        <i class="fas fa-user-tie"></i> Add Advertiser
                    </a>
                    <a href="publisher.php" class="btn btn-warning">
                        <i class="fas fa-globe"></i> Add Publisher
                    </a>
                    <a href="rtb-buy.php" class="btn btn-secondary">
                        <i class="fas fa-link"></i> Generate RTB Endpoint
                    </a>
                </div>
            </div>
        </div>
        
        <!-- System Status -->
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-server"></i> System Status</h5>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span>Database</span>
                    <span class="badge bg-success">Online</span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span>RTB Endpoint</span>
                    <span class="badge bg-success">Active</span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span>Ad Serving</span>
                    <span class="badge bg-success">Operational</span>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <span>Bidding Engine</span>
                    <span class="badge bg-success">Running</span>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>