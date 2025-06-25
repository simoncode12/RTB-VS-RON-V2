<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) && basename($_SERVER['PHP_SELF']) !== 'login.php') {
    header('Location: login.php');
    exit;
}

$current_page = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RTB & RON Platform - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../asset/css/style.css" rel="stylesheet">
</head>
<body>
    <?php if (isset($_SESSION['user_id'])): ?>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-bullseye"></i> RTB & RON Platform
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page == 'dashboard' ? 'active' : ''; ?>" href="dashboard.php">
                            <i class="fas fa-chart-line"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page == 'monitor' ? 'active' : ''; ?>" href="monitor.php">
                            <i class="fas fa-tachometer-alt"></i> Monitor
                            <span class="badge bg-danger ms-1">LIVE</span>
                        </a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="campaignsDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-bullhorn"></i> Campaigns
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="rtb-sell.php"><i class="fas fa-exchange-alt"></i> RTB Campaigns</a></li>
                            <li><a class="dropdown-item" href="ron-campaign.php"><i class="fas fa-network-wired"></i> RON Campaigns</a></li>
                            <li><a class="dropdown-item" href="creative.php"><i class="fas fa-images"></i> Creatives</a></li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="managementDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-cogs"></i> Management
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="advertiser.php"><i class="fas fa-user-tie"></i> Advertisers</a></li>
                            <li><a class="dropdown-item" href="publisher.php"><i class="fas fa-globe"></i> Publishers</a></li>
                            <li><a class="dropdown-item" href="website.php"><i class="fas fa-sitemap"></i> Websites</a></li>
                            <li><a class="dropdown-item" href="zone.php"><i class="fas fa-map-marker-alt"></i> Zones</a></li>
                            <li><a class="dropdown-item" href="category.php"><i class="fas fa-tags"></i> Categories</a></li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?php echo in_array($current_page, ['statistics', 'reports', 'revenue']) ? 'active' : ''; ?>" href="#" id="analyticsDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-chart-bar"></i> Analytics
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="statistics.php"><i class="fas fa-chart-line"></i> Statistics</a></li>
                            <li><a class="dropdown-item" href="reports.php"><i class="fas fa-file-alt"></i> Reports</a></li>
                            <li><a class="dropdown-item" href="revenue.php"><i class="fas fa-dollar-sign"></i> Revenue</a></li>
                        </ul>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page == 'rtb-buy' ? 'active' : ''; ?>" href="rtb-buy.php">
                            <i class="fas fa-link"></i> RTB Endpoints
                        </a>
                    </li>
                </ul>
                <ul class="navbar-nav">
                    <?php
                    // Get quick stats for navbar
                    try {
                        $today_stats = $pdo->query("
                            SELECT 
                                COALESCE(SUM(total_impressions), 0) as impressions,
                                COALESCE(SUM(total_revenue), 0) as revenue
                            FROM daily_statistics 
                            WHERE date = CURDATE()
                        ")->fetch(PDO::FETCH_ASSOC);
                        
                        // Get live requests count (last 5 minutes)
                        $live_requests = $pdo->query("
                            SELECT COUNT(*) as count
                            FROM bid_logs
                            WHERE created_at >= NOW() - INTERVAL 5 MINUTE
                        ")->fetchColumn();
                    } catch (Exception $e) {
                        $today_stats = ['impressions' => 0, 'revenue' => 0];
                        $live_requests = 0;
                    }
                    ?>
                    <li class="nav-item">
                        <span class="navbar-text me-3">
                            <small>
                                <?php if ($live_requests > 0): ?>
                                    <span class="badge bg-success rounded-pill">
                                        <i class="fas fa-circle fa-xs"></i> <?php echo number_format($live_requests); ?> req/5m
                                    </span>
                                <?php endif; ?>
                                <i class="fas fa-eye"></i> <?php echo number_format($today_stats['impressions']); ?> |
                                <i class="fas fa-dollar-sign"></i> <?php echo number_format($today_stats['revenue'], 2); ?>
                            </small>
                        </span>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['username']); ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user-edit"></i> Profile</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    <?php endif; ?>

    <div class="container-fluid mt-4">