<?php
// Start output buffering to prevent "headers already sent" errors
ob_start();

include 'includes/header.php';

// Current session information
$current_timestamp = '2025-06-23 06:20:51'; // Current UTC time
$current_user = 'simoncode12';              // Current logged in user

$message = '';
$message_type = 'success';

// Get advertisers for dropdown
$advertisers = $pdo->query("SELECT id, name FROM advertisers WHERE status = 'active' ORDER BY name")->fetchAll();
$categories = $pdo->query("SELECT id, name FROM categories WHERE status = 'active' ORDER BY name")->fetchAll();

// Predefined options for targeting selectors
$countries = [
    'US' => 'United States',
    'CA' => 'Canada',
    'UK' => 'United Kingdom',
    'AU' => 'Australia',
    'DE' => 'Germany',
    'FR' => 'France',
    'JP' => 'Japan',
    'IN' => 'India',
    'BR' => 'Brazil',
    'MX' => 'Mexico',
    'ES' => 'Spain',
    'IT' => 'Italy',
    'NL' => 'Netherlands',
    'RU' => 'Russia',
    'CN' => 'China'
];

$browsers = [
    'Chrome' => 'Google Chrome',
    'Firefox' => 'Mozilla Firefox',
    'Safari' => 'Apple Safari',
    'Edge' => 'Microsoft Edge',
    'Opera' => 'Opera',
    'IE' => 'Internet Explorer',
    'Samsung' => 'Samsung Internet',
    'UC' => 'UC Browser'
];

$devices = [
    'desktop' => 'Desktop',
    'mobile' => 'Mobile',
    'tablet' => 'Tablet',
    'smart_tv' => 'Smart TV',
    'console' => 'Gaming Console'
];

$operating_systems = [
    'Windows' => 'Windows',
    'MacOS' => 'MacOS',
    'iOS' => 'iOS',
    'Android' => 'Android',
    'Linux' => 'Linux',
    'ChromeOS' => 'Chrome OS'
];

// Handle campaign creation
if ($_POST && isset($_POST['action']) && $_POST['action'] == 'create_campaign') {
    try {
        $name = $_POST['name'] ?? '';
        $advertiser_id = $_POST['advertiser_id'] ?? '';
        $category_id = empty($_POST['category_id']) ? null : $_POST['category_id'];
        $bid_type = $_POST['bid_type'] ?? 'cpm';
        $daily_budget = !empty($_POST['daily_budget']) ? floatval($_POST['daily_budget']) : null;
        $total_budget = !empty($_POST['total_budget']) ? floatval($_POST['total_budget']) : null;
        $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
        $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        
        // Process targeting options
        $target_countries = isset($_POST['select_all_countries']) ? null : 
            (!empty($_POST['target_countries']) ? json_encode($_POST['target_countries']) : null);
            
        $target_browsers = isset($_POST['select_all_browsers']) ? null : 
            (!empty($_POST['target_browsers']) ? json_encode($_POST['target_browsers']) : null);
            
        $target_devices = isset($_POST['select_all_devices']) ? null : 
            (!empty($_POST['target_devices']) ? json_encode($_POST['target_devices']) : null);
            
        $target_os = isset($_POST['select_all_os']) ? null : 
            (!empty($_POST['target_os']) ? json_encode($_POST['target_os']) : null);
        
        // Process banner sizes
        $banner_sizes = !empty($_POST['banner_sizes']) ? json_encode($_POST['banner_sizes']) : null;
        
        if ($name && $advertiser_id) {
            // For RON campaigns, we don't need endpoint_url as it's RON specific
            $stmt = $pdo->prepare("
                INSERT INTO campaigns (
                    advertiser_id, name, type, category_id, bid_type, 
                    daily_budget, total_budget, start_date, end_date, status,
                    target_countries, target_browsers, target_devices, 
                    target_os, banner_sizes
                ) VALUES (
                    ?, ?, 'ron', ?, ?, 
                    ?, ?, ?, ?, 'active',
                    ?, ?, ?, 
                    ?, ?
                )
            ");
            
            $stmt->execute([
                $advertiser_id, 
                $name, 
                $category_id, 
                $bid_type,
                $daily_budget, 
                $total_budget, 
                $start_date, 
                $end_date,
                $target_countries, 
                $target_browsers, 
                $target_devices,
                $target_os, 
                $banner_sizes
            ]);
            
            $campaign_id = $pdo->lastInsertId();
            
            $message = 'RON campaign created successfully!';
            $message_type = 'success';
            
            // Record user activity
            try {
                $check_table = $pdo->query("SHOW TABLES LIKE 'user_activity'");
                if ($check_table->rowCount() > 0) {
                    $stmt = $pdo->prepare("
                        INSERT INTO user_activity (
                            user_id, action, entity_type, entity_id, ip_address, details, created_at
                        ) VALUES (?, 'create', 'campaign', ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $_SESSION['user_id'] ?? 0,
                        $campaign_id,
                        $_SERVER['REMOTE_ADDR'] ?? '',
                        'Created RON campaign: ' . $name
                    ]);
                }
            } catch (Exception $e) {
                // Silent fail - don't prevent campaign creation if activity logging fails
            }
            
            // Use an intermediate variable to store the redirect URL
            $redirect_url = "creative.php?campaign_id={$campaign_id}&type=ron&new=1";
            
            // Complete and clear the output buffer
            ob_end_clean(); // Discard any buffered output
            
            // Now redirect - headers can be sent
            header("Location: $redirect_url");
            exit;
            
        } else {
            $message = 'Please fill in all required fields.';
            $message_type = 'danger';
        }
    } catch (Exception $e) {
        $message = 'Error creating campaign: ' . $e->getMessage();
        $message_type = 'danger';
    }
}

// Get active RON campaigns
$campaigns = $pdo->query("
    SELECT c.*, a.name as advertiser_name, cat.name as category_name,
           COUNT(cr.id) as creative_count,
           SUM(CASE WHEN cr.status = 'active' THEN 1 ELSE 0 END) as active_creatives,
           SUM(rt.impressions) as impressions,
           SUM(rt.clicks) as clicks,
           SUM(rt.revenue) as revenue
    FROM campaigns c
    LEFT JOIN advertisers a ON c.advertiser_id = a.id
    LEFT JOIN categories cat ON c.category_id = cat.id
    LEFT JOIN creatives cr ON c.id = cr.campaign_id
    LEFT JOIN (
        SELECT 
            campaign_id, 
            SUM(impressions) as impressions,
            SUM(clicks) as clicks,
            SUM(revenue) as revenue
        FROM revenue_tracking
        GROUP BY campaign_id
    ) rt ON c.id = rt.campaign_id
    WHERE c.type = 'ron'
    GROUP BY c.id
    ORDER BY c.created_at DESC
")->fetchAll();
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h2 mb-2 text-gradient">
                    <i class="fas fa-network-wired me-2"></i>RON Campaign Manager
                </h1>
                <p class="text-muted mb-0">Create and manage Run-of-Network advertising campaigns</p>
            </div>
            <div class="d-flex align-items-center gap-3">
                <div class="text-muted small">
                    <i class="fas fa-clock me-1"></i><?php echo $current_timestamp; ?>
                </div>
                <div class="text-muted small">
                    <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($current_user); ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show d-flex align-items-center">
        <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'danger' ? 'exclamation-triangle' : 'info-circle'); ?> me-3 fs-4"></i>
        <div class="flex-grow-1">
            <?php echo htmlspecialchars($message); ?>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Create Campaign Form -->
<div class="card creative-card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center">
            <div class="me-3">
                <div class="bg-primary rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                    <i class="fas fa-plus text-white"></i>
                </div>
            </div>
            <div>
                <h5 class="mb-0">Create RON Campaign</h5>
                <small class="text-muted">Set up a new Run-of-Network advertising campaign</small>
            </div>
        </div>
        <button class="btn btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#createCampaignForm">
            <i class="fas fa-plus me-2"></i>New Campaign
        </button>
    </div>
    <div class="collapse" id="createCampaignForm">
        <div class="card-body p-4">
            <form method="POST" id="campaignForm">
                <input type="hidden" name="action" value="create_campaign">
                <!-- Campaign Basic Information -->
                <div class="border-start border-4 border-primary p-3 mb-4 bg-light bg-opacity-50 rounded">
                    <h6 class="mb-3 text-primary">
                        <i class="fas fa-info-circle me-2"></i>Campaign Information
                    </h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="name" class="form-label">
                                <i class="fas fa-tag me-2"></i>Campaign Name *
                            </label>
                            <input type="text" class="form-control" id="name" name="name" required 
                                   placeholder="e.g., Summer Sale RON Campaign">
                        </div>
                        <div class="col-md-6">
                            <label for="advertiser_id" class="form-label">
                                <i class="fas fa-building me-2"></i>Advertiser *
                            </label>
                            <select class="form-select" id="advertiser_id" name="advertiser_id" required>
                                <option value="">Choose advertiser...</option>
                                <?php foreach ($advertisers as $advertiser): ?>
                                    <option value="<?php echo $advertiser['id']; ?>">
                                        <?php echo htmlspecialchars($advertiser['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                
                <!-- Campaign Settings -->
                <div class="border-start border-4 border-info p-3 mb-4 bg-light bg-opacity-50 rounded">
                    <h6 class="mb-3 text-info">
                        <i class="fas fa-cogs me-2"></i>Campaign Settings
                    </h6>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="category_id" class="form-label">
                                <i class="fas fa-folder me-2"></i>Category
                            </label>
                            <select class="form-select" id="category_id" name="category_id">
                                <option value="">Choose category...</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>">
                                        <?php echo htmlspecialchars($category['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="bid_type" class="form-label">
                                <i class="fas fa-dollar-sign me-2"></i>Pricing Model
                            </label>
                            <select class="form-select" id="bid_type" name="bid_type">
                                <option value="cpm">CPM - Cost Per 1,000 Impressions</option>
                                <option value="cpc">CPC - Cost Per Click</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">
                                <i class="fas fa-rocket me-2"></i>Campaign Options
                            </label>
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" id="priority_delivery" name="priority_delivery">
                                <label class="form-check-label" for="priority_delivery">
                                    <strong>Priority Delivery</strong>
                                </label>
                                <div class="form-text">
                                    <small>Higher priority in ad serving queue</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Budget & Schedule -->
                <div class="border-start border-4 border-success p-3 mb-4 bg-light bg-opacity-50 rounded">
                    <h6 class="mb-3 text-success">
                        <i class="fas fa-wallet me-2"></i>Budget & Schedule
                    </h6>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label for="daily_budget" class="form-label">
                                <i class="fas fa-calendar-day me-2"></i>Daily Budget
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" class="form-control" id="daily_budget" name="daily_budget"
                                       step="0.01" min="0" placeholder="100.00">
                            </div>
                            <div class="form-text">
                                <small>Maximum spend per day</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label for="total_budget" class="form-label">
                                <i class="fas fa-piggy-bank me-2"></i>Total Budget
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" class="form-control" id="total_budget" name="total_budget"
                                       step="0.01" min="0" placeholder="1000.00">
                            </div>
                            <div class="form-text">
                                <small>Campaign lifetime budget</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label for="start_date" class="form-label">
                                <i class="fas fa-play me-2"></i>Start Date
                            </label>
                            <input type="date" class="form-control" id="start_date" name="start_date">
                            <div class="form-text">
                                <small>Campaign start date</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label for="end_date" class="form-label">
                                <i class="fas fa-stop me-2"></i>End Date
                            </label>
                            <input type="date" class="form-control" id="end_date" name="end_date">
                            <div class="form-text">
                                <small>Campaign end date</small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Advanced Targeting -->
                <div class="border-start border-4 border-warning p-3 mb-4 bg-light bg-opacity-50 rounded">
                    <h6 class="mb-3 text-warning">
                        <i class="fas fa-crosshairs me-2"></i>Audience Targeting
                    </h6>
                    
                    <!-- Countries Targeting -->
                    <div class="mb-4">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <label class="form-label mb-0">
                                <i class="fas fa-globe-americas me-2"></i><strong>Geographic Targeting</strong>
                            </label>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="select_all_countries" name="select_all_countries" checked>
                                <label class="form-check-label" for="select_all_countries">
                                    <span class="badge bg-success">All Countries</span>
                                </label>
                            </div>
                        </div>
                        
                        <div id="countries_container" class="targeting-grid" style="display: none;">
                            <?php foreach ($countries as $code => $name): ?>
                            <div class="targeting-item">
                                <div class="form-check">
                                    <input class="form-check-input country-checkbox" type="checkbox" name="target_countries[]" 
                                           id="country_<?php echo $code; ?>" value="<?php echo $code; ?>">
                                    <label class="form-check-label" for="country_<?php echo $code; ?>">
                                        <span class="flag-icon flag-icon-<?php echo strtolower($code); ?> me-2"></span>
                                        <?php echo htmlspecialchars($name); ?>
                                        <small class="text-muted">(<?php echo $code; ?>)</small>
                                    </label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Browser Targeting -->
                    <div class="mb-4">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <label class="form-label mb-0">
                                <i class="fas fa-browser me-2"></i><strong>Browser Targeting</strong>
                            </label>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="select_all_browsers" name="select_all_browsers" checked>
                                <label class="form-check-label" for="select_all_browsers">
                                    <span class="badge bg-success">All Browsers</span>
                                </label>
                            </div>
                        </div>
                        
                        <div id="browsers_container" class="targeting-grid" style="display: none;">
                            <?php foreach ($browsers as $code => $name): ?>
                            <div class="targeting-item">
                                <div class="form-check">
                                    <input class="form-check-input browser-checkbox" type="checkbox" name="target_browsers[]" 
                                           id="browser_<?php echo $code; ?>" value="<?php echo $code; ?>">
                                    <label class="form-check-label" for="browser_<?php echo $code; ?>">
                                        <i class="fab fa-<?php echo strtolower($code) === 'chrome' ? 'chrome' : (strtolower($code) === 'firefox' ? 'firefox-browser' : (strtolower($code) === 'safari' ? 'safari' : (strtolower($code) === 'edge' ? 'edge' : 'internet-explorer'))); ?> me-2"></i>
                                        <?php echo htmlspecialchars($name); ?>
                                    </label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Device Targeting -->
                    <div class="mb-4">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <label class="form-label mb-0">
                                <i class="fas fa-devices me-2"></i><strong>Device Targeting</strong>
                            </label>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="select_all_devices" name="select_all_devices" checked>
                                <label class="form-check-label" for="select_all_devices">
                                    <span class="badge bg-success">All Devices</span>
                                </label>
                            </div>
                        </div>
                        
                        <div id="devices_container" class="targeting-grid" style="display: none;">
                            <?php foreach ($devices as $code => $name): ?>
                            <div class="targeting-item">
                                <div class="form-check">
                                    <input class="form-check-input device-checkbox" type="checkbox" name="target_devices[]" 
                                           id="device_<?php echo $code; ?>" value="<?php echo $code; ?>">
                                    <label class="form-check-label" for="device_<?php echo $code; ?>">
                                        <i class="fas fa-<?php echo $code === 'desktop' ? 'desktop' : ($code === 'mobile' ? 'mobile-alt' : ($code === 'tablet' ? 'tablet-alt' : 'tv')); ?> me-2"></i>
                                        <?php echo htmlspecialchars($name); ?>
                                    </label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- OS Targeting -->
                    <div class="mb-4">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <label class="form-label mb-0">
                                <i class="fas fa-code me-2"></i><strong>Operating System Targeting</strong>
                            </label>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="select_all_os" name="select_all_os" checked>
                                <label class="form-check-label" for="select_all_os">
                                    <span class="badge bg-success">All Operating Systems</span>
                                </label>
                            </div>
                        </div>
                        
                        <div id="os_container" class="targeting-grid" style="display: none;">
                            <?php foreach ($operating_systems as $code => $name): ?>
                            <div class="targeting-item">
                                <div class="form-check">
                                    <input class="form-check-input os-checkbox" type="checkbox" name="target_os[]" 
                                           id="os_<?php echo $code; ?>" value="<?php echo $code; ?>">
                                    <label class="form-check-label" for="os_<?php echo $code; ?>">
                                        <i class="fab fa-<?php echo strtolower($code) === 'windows' ? 'windows' : (strtolower($code) === 'macos' ? 'apple' : (strtolower($code) === 'android' ? 'android' : (strtolower($code) === 'ios' ? 'apple' : 'linux'))); ?> me-2"></i>
                                        <?php echo htmlspecialchars($name); ?>
                                    </label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <h6 class="mt-4 mb-3">Banner Sizes</h6>
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" id="select_all_sizes">
                            <label class="form-check-label" for="select_all_sizes">
                                <strong>Select All Sizes</strong>
                            </label>
                        </div>
                    </div>
                    <div class="col-12 mt-2">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input banner-size" type="checkbox" name="banner_sizes[]" id="size_300x250" value="300x250">
                            <label class="form-check-label" for="size_300x250">300x250 (Medium Rectangle)</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input banner-size" type="checkbox" name="banner_sizes[]" id="size_728x90" value="728x90">
                            <label class="form-check-label" for="size_728x90">728x90 (Leaderboard)</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input banner-size" type="checkbox" name="banner_sizes[]" id="size_160x600" value="160x600">
                            <label class="form-check-label" for="size_160x600">160x600 (Skyscraper)</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input banner-size" type="checkbox" name="banner_sizes[]" id="size_320x50" value="320x50">
                            <label class="form-check-label" for="size_320x50">320x50 (Mobile Banner)</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input banner-size" type="checkbox" name="banner_sizes[]" id="size_300x600" value="300x600">
                            <label class="form-check-label" for="size_300x600">300x600 (Half Page)</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input banner-size" type="checkbox" name="banner_sizes[]" id="size_336x280" value="336x280">
                            <label class="form-check-label" for="size_336x280">336x280 (Large Rectangle)</label>
                        </div>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Create Campaign
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Campaigns List -->
<div class="card">
    <div class="card-header">
        <h5 class="mb-0"><i class="fas fa-list"></i> RON Campaigns</h5>
    </div>
    <div class="card-body">
        <?php if (empty($campaigns)): ?>
            <div class="text-center py-4">
                <i class="fas fa-network-wired fa-3x text-muted mb-3"></i>
                <p class="text-muted">No RON campaigns created yet.</p>
                <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#createCampaignForm">
                    <i class="fas fa-plus"></i> Create Your First Campaign
                </button>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Campaign Details</th>
                            <th>Budget</th>
                            <th>Timeline</th>
                            <th>Creatives</th>
                            <th>Performance</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($campaigns as $campaign): ?>
                        <tr>
                            <td>
                                <div>
                                    <strong><?php echo htmlspecialchars($campaign['name']); ?></strong>
                                    <br>
                                    <small class="text-muted">
                                        Advertiser: <?php echo htmlspecialchars($campaign['advertiser_name']); ?>
                                    </small>
                                    <?php if ($campaign['category_name']): ?>
                                        <br>
                                        <small class="text-muted">
                                            Category: <?php echo htmlspecialchars($campaign['category_name']); ?>
                                        </small>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="small">
                                    <?php if ($campaign['daily_budget']): ?>
                                        <div>Daily: <?php echo formatCurrency($campaign['daily_budget']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($campaign['total_budget']): ?>
                                        <div>Total: <?php echo formatCurrency($campaign['total_budget']); ?></div>
                                    <?php endif; ?>
                                    <div class="text-muted"><?php echo ucfirst($campaign['bid_type']); ?> bidding</div>
                                </div>
                            </td>
                            <td>
                                <div class="small">
                                    <?php if ($campaign['start_date']): ?>
                                        <div>Start: <?php echo date('M j, Y', strtotime($campaign['start_date'])); ?></div>
                                    <?php endif; ?>
                                    <?php if ($campaign['end_date']): ?>
                                        <div>End: <?php echo date('M j, Y', strtotime($campaign['end_date'])); ?></div>
                                    <?php endif; ?>
                                    <div class="text-muted">Created: <?php echo date('M j, Y', strtotime($campaign['created_at'])); ?></div>
                                </div>
                            </td>
                            <td>
                                <div class="small text-center">
                                    <strong><?php echo $campaign['creative_count']; ?></strong> total
                                    <br>
                                    <span class="text-success"><?php echo $campaign['active_creatives']; ?></span> active
                                    <br>
                                    <a href="creative.php?campaign_id=<?php echo $campaign['id']; ?>&type=ron" class="btn btn-sm btn-outline-primary mt-1">
                                        <i class="fas fa-images"></i> Manage
                                    </a>
                                </div>
                            </td>
                            <td>
                                <div class="small">
                                    <div><strong><?php echo formatNumber($campaign['impressions'] ?? 0); ?></strong> impressions</div>
                                    <div><strong><?php echo formatNumber($campaign['clicks'] ?? 0); ?></strong> clicks</div>
                                    <div><strong><?php echo formatCurrency($campaign['revenue'] ?? 0); ?></strong> spent</div>
                                    <?php if ($campaign['impressions'] > 0): ?>
                                        <div class="text-muted">
                                            CTR: <?php echo number_format(($campaign['clicks'] / $campaign['impressions']) * 100, 2); ?>%
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-<?php 
                                    echo $campaign['status'] == 'active' ? 'success' : 
                                        ($campaign['status'] == 'paused' ? 'warning' : 
                                        ($campaign['status'] == 'completed' ? 'secondary' : 'info')); 
                                ?>">
                                    <?php echo ucfirst($campaign['status']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-primary" data-bs-toggle="tooltip" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-outline-success" data-bs-toggle="tooltip" title="Stats">
                                        <i class="fas fa-chart-bar"></i>
                                    </button>
                                    <button class="btn btn-outline-warning" data-bs-toggle="tooltip" title="Pause/Resume">
                                        <i class="fas fa-<?php echo $campaign['status'] == 'active' ? 'pause' : 'play'; ?>"></i>
                                    </button>
                                    <button class="btn btn-outline-danger btn-delete" data-bs-toggle="tooltip" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Enhanced Campaign Management JavaScript
document.addEventListener('DOMContentLoaded', function() {
    initializeCampaignManagement();
});

function initializeCampaignManagement() {
    setupFormDefaults();
    setupTargetingToggles();
    setupFormValidation();
    setupTooltips();
    setupBudgetCalculator();
    setupAnimations();
}

function setupFormDefaults() {
    // Set default start date (today)
    const today = new Date();
    const startDateInput = document.getElementById('start_date');
    if (startDateInput) {
        startDateInput.valueAsDate = today;
    }
    
    // Set default end date (30 days from now)
    const thirtyDaysLater = new Date();
    thirtyDaysLater.setDate(today.getDate() + 30);
    const endDateInput = document.getElementById('end_date');
    if (endDateInput) {
        endDateInput.valueAsDate = thirtyDaysLater;
    }
    
    // Default select all banner sizes if checkbox exists
    const selectAllSizes = document.getElementById('select_all_sizes');
    if (selectAllSizes) {
        selectAllSizes.checked = true;
        const sizeCheckboxes = document.querySelectorAll('.banner-size');
        sizeCheckboxes.forEach(cb => cb.checked = true);
    }
}

function setupTargetingToggles() {
    // Enhanced targeting toggles with smooth animations
    setupTargetingToggle('countries', ['US', 'UK', 'CA', 'AU'], 'All Countries');
    setupTargetingToggle('browsers', ['Chrome', 'Firefox', 'Safari', 'Edge'], 'All Browsers');
    setupTargetingToggle('devices', ['desktop', 'mobile', 'tablet'], 'All Devices');
    setupTargetingToggle('os', ['Windows', 'MacOS', 'iOS', 'Android'], 'All Operating Systems');
    
    // Banner sizes toggle if it exists
    const selectAllSizes = document.getElementById('select_all_sizes');
    if (selectAllSizes) {
        selectAllSizes.addEventListener('change', function() {
            const sizeCheckboxes = document.querySelectorAll('.banner-size');
            sizeCheckboxes.forEach(cb => cb.checked = this.checked);
            
            // Visual feedback
            showNotification(
                this.checked ? 'All banner sizes selected' : 'Banner size selection cleared',
                'info'
            );
        });
    }
}

function setupTargetingToggle(type, defaultOptions, label) {
    const toggleElement = document.getElementById(`select_all_${type}`);
    const containerElement = document.getElementById(`${type}_container`);
    const checkboxes = document.querySelectorAll(`.${type.slice(0, -1)}-checkbox`);
    
    if (!toggleElement || !containerElement) return;
    
    // Update badge text based on state
    const updateBadge = (isAllSelected) => {
        const badgeLabel = toggleElement.nextElementSibling.querySelector('.badge');
        if (badgeLabel) {
            badgeLabel.textContent = isAllSelected ? label : `Custom ${label}`;
            badgeLabel.className = `badge bg-${isAllSelected ? 'success' : 'warning'}`;
        }
    };
    
    toggleElement.addEventListener('change', function() {
        if (this.checked) {
            // Hide container with smooth animation
            containerElement.style.opacity = '0';
            setTimeout(() => {
                containerElement.style.display = 'none';
                checkboxes.forEach(cb => cb.checked = false);
            }, 200);
        } else {
            // Show container with smooth animation
            containerElement.style.display = 'grid';
            containerElement.classList.add('slide-in');
            setTimeout(() => {
                containerElement.style.opacity = '1';
                // Select default options
                defaultOptions.forEach(code => {
                    const checkbox = document.getElementById(`${type.slice(0, -1)}_${code}`);
                    if (checkbox) checkbox.checked = true;
                });
            }, 50);
        }
        
        updateBadge(this.checked);
        
        // Show notification
        showNotification(
            this.checked ? `Targeting all ${type}` : `Custom ${type} targeting enabled`,
            'info'
        );
    });
    
    // Initialize badge
    updateBadge(toggleElement.checked);
}

function setupFormValidation() {
    const form = document.getElementById('campaignForm');
    if (!form) return;
    
    // Real-time validation for required fields
    const requiredInputs = form.querySelectorAll('input[required], select[required]');
    requiredInputs.forEach(input => {
        input.addEventListener('blur', validateField);
        input.addEventListener('input', clearFieldError);
    });
    
    // Budget validation
    const dailyBudget = document.getElementById('daily_budget');
    const totalBudget = document.getElementById('total_budget');
    
    if (dailyBudget && totalBudget) {
        dailyBudget.addEventListener('input', validateBudgets);
        totalBudget.addEventListener('input', validateBudgets);
    }
    
    // Date validation
    const startDate = document.getElementById('start_date');
    const endDate = document.getElementById('end_date');
    
    if (startDate && endDate) {
        startDate.addEventListener('change', validateDates);
        endDate.addEventListener('change', validateDates);
    }
}

function validateField(event) {
    const field = event.target;
    if (!field.value.trim() && field.hasAttribute('required')) {
        showFieldError(field, 'This field is required');
        return false;
    } else {
        clearFieldError(field);
        return true;
    }
}

function validateBudgets() {
    const dailyBudget = parseFloat(document.getElementById('daily_budget').value) || 0;
    const totalBudget = parseFloat(document.getElementById('total_budget').value) || 0;
    
    if (dailyBudget > 0 && totalBudget > 0 && dailyBudget > totalBudget) {
        showFieldError(document.getElementById('daily_budget'), 'Daily budget cannot exceed total budget');
        return false;
    }
    
    if (dailyBudget > 1000) {
        showFieldWarning(document.getElementById('daily_budget'), 'High daily budget detected');
    }
    
    return true;
}

function validateDates() {
    const startDate = new Date(document.getElementById('start_date').value);
    const endDate = new Date(document.getElementById('end_date').value);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    
    if (startDate < today) {
        showFieldWarning(document.getElementById('start_date'), 'Start date is in the past');
    }
    
    if (endDate <= startDate) {
        showFieldError(document.getElementById('end_date'), 'End date must be after start date');
        return false;
    }
    
    return true;
}

function showFieldError(field, message) {
    clearFieldError(field);
    field.classList.add('is-invalid');
    
    const errorDiv = document.createElement('div');
    errorDiv.className = 'invalid-feedback';
    errorDiv.textContent = message;
    field.parentNode.appendChild(errorDiv);
}

function showFieldWarning(field, message) {
    clearFieldError(field);
    field.classList.add('border-warning');
    
    const warningDiv = document.createElement('div');
    warningDiv.className = 'text-warning small mt-1';
    warningDiv.innerHTML = `<i class="fas fa-exclamation-triangle me-1"></i>${message}`;
    field.parentNode.appendChild(warningDiv);
}

function clearFieldError(field) {
    if (typeof field === 'object') {
        field = field.target || field;
    }
    
    field.classList.remove('is-invalid', 'border-warning');
    const feedback = field.parentNode.querySelector('.invalid-feedback, .text-warning');
    if (feedback) {
        feedback.remove();
    }
}

function setupBudgetCalculator() {
    const dailyBudget = document.getElementById('daily_budget');
    const totalBudget = document.getElementById('total_budget');
    const startDate = document.getElementById('start_date');
    const endDate = document.getElementById('end_date');
    
    if (!dailyBudget || !totalBudget || !startDate || !endDate) return;
    
    // Auto-calculate total budget based on daily budget and date range
    [dailyBudget, startDate, endDate].forEach(element => {
        element.addEventListener('change', function() {
            calculateBudgetSuggestion();
        });
    });
}

function calculateBudgetSuggestion() {
    const dailyBudget = parseFloat(document.getElementById('daily_budget').value) || 0;
    const startDate = new Date(document.getElementById('start_date').value);
    const endDate = new Date(document.getElementById('end_date').value);
    
    if (dailyBudget > 0 && startDate && endDate && endDate > startDate) {
        const daysDiff = Math.ceil((endDate - startDate) / (1000 * 60 * 60 * 24));
        const suggestedTotal = dailyBudget * daysDiff;
        
        const totalBudgetField = document.getElementById('total_budget');
        if (!totalBudgetField.value || parseFloat(totalBudgetField.value) === 0) {
            totalBudgetField.value = suggestedTotal.toFixed(2);
            
            // Visual feedback
            totalBudgetField.style.backgroundColor = '#e3f2fd';
            setTimeout(() => {
                totalBudgetField.style.backgroundColor = '';
            }, 1000);
            
            showNotification(`Suggested total budget: $${suggestedTotal.toFixed(2)} for ${daysDiff} days`, 'info');
        }
    }
}

function setupTooltips() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl, {
            delay: { show: 500, hide: 100 }
        });
    });
}

function setupAnimations() {
    // Add smooth animations to form sections
    const formSections = document.querySelectorAll('.border-start');
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                entry.target.classList.add('fade-in');
            }
        });
    });
    
    formSections.forEach((section) => {
        observer.observe(section);
    });
}

function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
    notification.style.top = '20px';
    notification.style.right = '20px';
    notification.style.zIndex = '9999';
    notification.style.minWidth = '300px';
    notification.style.maxWidth = '400px';
    
    const icon = type === 'success' ? 'check-circle' : 
                type === 'warning' ? 'exclamation-triangle' : 
                type === 'danger' ? 'exclamation-circle' : 'info-circle';
    
    notification.innerHTML = `
        <i class="fas fa-${icon} me-2"></i>
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    document.body.appendChild(notification);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        if (notification.parentNode) {
            notification.remove();
        }
    }, 5000);
}

// Global function for external use
window.campaignManager = {
    showNotification,
    validateField,
    clearFieldError
};
</script>

<?php 
// End output buffering and send the output
ob_end_flush();
include 'includes/footer.php'; 
?>