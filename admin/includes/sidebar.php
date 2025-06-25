<?php
$current_page = basename($_SERVER['PHP_SELF'], '.php');

// Get statistics for sidebar badges
$stats = [];
try {
    $stats['active_campaigns'] = $pdo->query("SELECT COUNT(*) FROM campaigns WHERE status = 'active'")->fetchColumn();
    $stats['pending_publishers'] = $pdo->query("SELECT COUNT(*) FROM publishers WHERE status = 'pending'")->fetchColumn();
    $stats['active_zones'] = $pdo->query("SELECT COUNT(*) FROM zones WHERE status = 'active'")->fetchColumn();
    $stats['total_creatives'] = $pdo->query("SELECT COUNT(*) FROM creatives WHERE status = 'active'")->fetchColumn();
    
    // Get today's stats for sidebar
    $today_stats = $pdo->query("
        SELECT 
            COALESCE(total_impressions, 0) as impressions,
            COALESCE(total_revenue, 0) as revenue
        FROM daily_statistics 
        WHERE date = CURDATE()
    ")->fetch(PDO::FETCH_ASSOC);
    
    $stats['today_impressions'] = $today_stats['impressions'] ?? 0;
    $stats['today_revenue'] = $today_stats['revenue'] ?? 0;
} catch (Exception $e) {
    // Handle error silently
    $stats = array_fill_keys(['active_campaigns', 'pending_publishers', 'active_zones', 'total_creatives', 'today_impressions', 'today_revenue'], 0);
}
?>

<div class="sidebar bg-light border-end" id="sidebar" style="min-height: calc(100vh - 56px); width: 280px; position: fixed; top: 56px; left: 0; z-index: 1000; transform: translateX(-100%); transition: transform 0.3s ease; overflow-y: auto;">
    <div class="p-3">
        <h6 class="text-muted mb-3">
            <i class="fas fa-tachometer-alt"></i> Navigation
        </h6>
        
        <!-- Dashboard -->
        <div class="mb-2">
            <a href="dashboard.php" class="btn btn-<?php echo $current_page == 'dashboard' ? 'primary' : 'outline-primary'; ?> w-100 text-start">
                <i class="fas fa-chart-line me-2"></i> Dashboard
                <?php if ($stats['active_campaigns'] > 0): ?>
                    <span class="badge bg-success float-end"><?php echo $stats['active_campaigns']; ?></span>
                <?php endif; ?>
            </a>
        </div>
        
        <!-- Campaigns Section -->
        <div class="mb-3">
            <h6 class="text-muted mb-2 mt-3">
                <i class="fas fa-bullhorn"></i> Campaigns
            </h6>
            
            <div class="mb-1">
                <a href="rtb-sell.php" class="btn btn-<?php echo $current_page == 'rtb-sell' ? 'primary' : 'outline-secondary'; ?> w-100 text-start btn-sm">
                    <i class="fas fa-exchange-alt me-2"></i> RTB Campaigns
                </a>
            </div>
            
            <div class="mb-1">
                <a href="ron-campaign.php" class="btn btn-<?php echo $current_page == 'ron-campaign' ? 'primary' : 'outline-secondary'; ?> w-100 text-start btn-sm">
                    <i class="fas fa-network-wired me-2"></i> RON Campaigns
                </a>
            </div>
            
            <div class="mb-1">
                <a href="creative.php" class="btn btn-<?php echo $current_page == 'creative' ? 'primary' : 'outline-secondary'; ?> w-100 text-start btn-sm">
                    <i class="fas fa-images me-2"></i> Creatives
                    <?php if ($stats['total_creatives'] > 0): ?>
                        <span class="badge bg-info float-end"><?php echo $stats['total_creatives']; ?></span>
                    <?php endif; ?>
                </a>
            </div>
        </div>
        
        <!-- Management Section -->
        <div class="mb-3">
            <h6 class="text-muted mb-2">
                <i class="fas fa-cogs"></i> Management
            </h6>
            
            <div class="mb-1">
                <a href="advertiser.php" class="btn btn-<?php echo $current_page == 'advertiser' ? 'primary' : 'outline-secondary'; ?> w-100 text-start btn-sm">
                    <i class="fas fa-user-tie me-2"></i> Advertisers
                </a>
            </div>
            
            <div class="mb-1">
                <a href="publisher.php" class="btn btn-<?php echo $current_page == 'publisher' ? 'primary' : 'outline-secondary'; ?> w-100 text-start btn-sm">
                    <i class="fas fa-globe me-2"></i> Publishers
                    <?php if ($stats['pending_publishers'] > 0): ?>
                        <span class="badge bg-warning float-end"><?php echo $stats['pending_publishers']; ?></span>
                    <?php endif; ?>
                </a>
            </div>
            
            <div class="mb-1">
                <a href="website.php" class="btn btn-<?php echo $current_page == 'website' ? 'primary' : 'outline-secondary'; ?> w-100 text-start btn-sm">
                    <i class="fas fa-sitemap me-2"></i> Websites
                </a>
            </div>
            
            <div class="mb-1">
                <a href="zone.php" class="btn btn-<?php echo $current_page == 'zone' ? 'primary' : 'outline-secondary'; ?> w-100 text-start btn-sm">
                    <i class="fas fa-map-marker-alt me-2"></i> Zones
                    <?php if ($stats['active_zones'] > 0): ?>
                        <span class="badge bg-secondary float-end"><?php echo $stats['active_zones']; ?></span>
                    <?php endif; ?>
                </a>
            </div>
            
            <div class="mb-1">
                <a href="category.php" class="btn btn-<?php echo $current_page == 'category' ? 'primary' : 'outline-secondary'; ?> w-100 text-start btn-sm">
                    <i class="fas fa-tags me-2"></i> Categories
                </a>
            </div>
        </div>
        
        <!-- RTB Tools -->
        <div class="mb-3">
            <h6 class="text-muted mb-2">
                <i class="fas fa-tools"></i> RTB Tools
            </h6>
            
            <div class="mb-1">
                <a href="rtb-buy.php" class="btn btn-<?php echo $current_page == 'rtb-buy' ? 'primary' : 'outline-secondary'; ?> w-100 text-start btn-sm">
                    <i class="fas fa-link me-2"></i> RTB Endpoints
                </a>
            </div>
        </div>
        
        <!-- Analytics Section -->
        <div class="mb-3">
            <h6 class="text-muted mb-2">
                <i class="fas fa-chart-bar"></i> Analytics
            </h6>
            
            <div class="mb-1">
                <a href="statistics.php" class="btn btn-<?php echo $current_page == 'statistics' ? 'primary' : 'outline-secondary'; ?> w-100 text-start btn-sm">
                    <i class="fas fa-chart-line me-2"></i> Statistics
                    <?php if ($stats['today_impressions'] > 0): ?>
                        <span class="badge bg-primary float-end" title="Today's Impressions"><?php echo formatNumber($stats['today_impressions']); ?></span>
                    <?php endif; ?>
                </a>
            </div>
            
            <div class="mb-1">
                <a href="reports.php" class="btn btn-<?php echo $current_page == 'reports' ? 'primary' : 'outline-secondary'; ?> w-100 text-start btn-sm">
                    <i class="fas fa-file-alt me-2"></i> Reports
                </a>
            </div>
            
            <div class="mb-1">
                <a href="revenue.php" class="btn btn-<?php echo $current_page == 'revenue' ? 'primary' : 'outline-secondary'; ?> w-100 text-start btn-sm">
                    <i class="fas fa-dollar-sign me-2"></i> Revenue
                    <?php if ($stats['today_revenue'] > 0): ?>
                        <span class="badge bg-success float-end" title="Today's Revenue">$<?php echo number_format($stats['today_revenue'], 2); ?></span>
                    <?php endif; ?>
                </a>
            </div>
        </div>
        
        <!-- Quick Stats -->
        <div class="card mt-4">
            <div class="card-header">
                <h6 class="mb-0"><i class="fas fa-info-circle"></i> Quick Stats</h6>
            </div>
            <div class="card-body p-2">
                <div class="row text-center">
                    <div class="col-6 mb-2">
                        <div class="text-primary">
                            <strong><?php echo formatNumber($stats['active_campaigns']); ?></strong>
                        </div>
                        <small class="text-muted">Active Campaigns</small>
                    </div>
                    <div class="col-6 mb-2">
                        <div class="text-success">
                            <strong><?php echo formatNumber($stats['total_creatives']); ?></strong>
                        </div>
                        <small class="text-muted">Creatives</small>
                    </div>
                    <div class="col-6">
                        <div class="text-info">
                            <strong><?php echo formatNumber($stats['active_zones']); ?></strong>
                        </div>
                        <small class="text-muted">Active Zones</small>
                    </div>
                    <div class="col-6">
                        <div class="text-warning">
                            <strong><?php echo formatNumber($stats['pending_publishers']); ?></strong>
                        </div>
                        <small class="text-muted">Pending</small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- System Status -->
        <div class="card mt-3">
            <div class="card-header">
                <h6 class="mb-0"><i class="fas fa-server"></i> System Status</h6>
            </div>
            <div class="card-body p-2">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <small>RTB Endpoint</small>
                    <span class="badge bg-success">Online</span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <small>Bidding Engine</small>
                    <span class="badge bg-success">Running</span>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <small>Ad Serving</small>
                    <span class="badge bg-success">Active</span>
                </div>
            </div>
        </div>
        
        <!-- Today's Performance -->
        <div class="card mt-3 mb-3">
            <div class="card-header">
                <h6 class="mb-0"><i class="fas fa-calendar-day"></i> Today's Performance</h6>
            </div>
            <div class="card-body p-2">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <small>Impressions</small>
                    <strong class="text-primary"><?php echo formatNumber($stats['today_impressions']); ?></strong>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <small>Revenue</small>
                    <strong class="text-success">$<?php echo number_format($stats['today_revenue'], 2); ?></strong>
                </div>
                <hr class="my-2">
                <div class="text-center">
                    <a href="statistics.php" class="btn btn-sm btn-outline-primary w-100">
                        <i class="fas fa-chart-line"></i> View Full Stats
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Sidebar Toggle Button -->
<button class="btn btn-primary position-fixed" id="sidebarToggle" 
        style="top: 70px; left: 10px; z-index: 1001; border-radius: 50%; width: 40px; height: 40px;">
    <i class="fas fa-bars"></i>
</button>

<!-- Sidebar Overlay -->
<div class="sidebar-overlay position-fixed w-100 h-100" id="sidebarOverlay" 
     style="top: 56px; left: 0; background: rgba(0,0,0,0.5); z-index: 999; display: none;"
     onclick="toggleSidebar()"></div>

<script>
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const toggleBtn = document.getElementById('sidebarToggle');
    
    if (sidebar.style.transform === 'translateX(0px)') {
        sidebar.style.transform = 'translateX(-100%)';
        overlay.style.display = 'none';
        toggleBtn.innerHTML = '<i class="fas fa-bars"></i>';
    } else {
        sidebar.style.transform = 'translateX(0px)';
        overlay.style.display = 'block';
        toggleBtn.innerHTML = '<i class="fas fa-times"></i>';
    }
}

document.getElementById('sidebarToggle').addEventListener('click', toggleSidebar);

// Auto-hide sidebar on mobile when clicking a link
document.querySelectorAll('#sidebar a').forEach(link => {
    link.addEventListener('click', function() {
        if (window.innerWidth < 768) {
            setTimeout(toggleSidebar, 100);
        }
    });
});

// Handle resize
window.addEventListener('resize', function() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    
    if (window.innerWidth >= 768) {
        sidebar.style.transform = 'translateX(0px)';
        overlay.style.display = 'none';
    } else {
        sidebar.style.transform = 'translateX(-100%)';
        overlay.style.display = 'none';
    }
});

// Initialize sidebar state
if (window.innerWidth >= 768) {
    document.getElementById('sidebar').style.transform = 'translateX(0px)';
}
</script>

<style>
@media (min-width: 768px) {
    body {
        padding-left: 280px;
    }
    #sidebarToggle {
        display: none;
    }
}

@media (max-width: 767px) {
    body {
        padding-left: 0;
    }
    #sidebar {
        transform: translateX(-100%) !important;
    }
}

/* Custom scrollbar for sidebar */
#sidebar::-webkit-scrollbar {
    width: 6px;
}

#sidebar::-webkit-scrollbar-track {
    background: #f1f1f1;
}

#sidebar::-webkit-scrollbar-thumb {
    background: #888;
    border-radius: 3px;
}

#sidebar::-webkit-scrollbar-thumb:hover {
    background: #555;
}
</style>