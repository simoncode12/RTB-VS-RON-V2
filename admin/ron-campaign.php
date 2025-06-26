<?php
// Start output buffering to prevent "headers already sent" errors
ob_start();

include 'includes/header.php';

// Current session information
$current_timestamp = '2025-06-26 06:46:47'; // Current UTC time
$current_user = 'simoncode12';              // Current logged in user

$message = '';
$message_type = 'success';

// Handle campaign actions (pause/resume, delete)
if ($_POST && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'toggle_status' && isset($_POST['campaign_id'])) {
            $campaign_id = $_POST['campaign_id'];
            $new_status = $_POST['new_status'];
            
            $stmt = $pdo->prepare("UPDATE campaigns SET status = ? WHERE id = ? AND type = 'ron'");
            $stmt->execute([$new_status, $campaign_id]);
            
            $message = 'Campaign status updated successfully!';
            $message_type = 'success';
        } elseif ($_POST['action'] === 'delete_campaign' && isset($_POST['campaign_id'])) {
            $campaign_id = $_POST['campaign_id'];
            
            // Delete campaign and related data
            $stmt = $pdo->prepare("DELETE FROM campaigns WHERE id = ? AND type = 'ron'");
            $stmt->execute([$campaign_id]);
            
            $message = 'Campaign deleted successfully!';
            $message_type = 'success';
        }
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
        $message_type = 'danger';
    }
}

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

// Ad formats available for RON campaigns
$ad_formats = [
    'banner' => 'Banner Display Ads',
    'video_instream' => 'Video Instream (Pre-roll)',
    'video_outstream' => 'Video Outstream',
    'native' => 'Native Ads',
    'interstitial' => 'Interstitial Ads',
    'popup' => 'Pop-up/Pop-under',
    'text_ads' => 'Text Ads'
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
        
        // Process ad formats
        $ad_formats_selected = !empty($_POST['ad_formats']) ? json_encode($_POST['ad_formats']) : json_encode(['banner']);
        
        if ($name && $advertiser_id) {
            // For RON campaigns, we don't need endpoint_url as it's RON specific
            $stmt = $pdo->prepare("
                INSERT INTO campaigns (
                    advertiser_id, name, type, category_id, bid_type, 
                    daily_budget, total_budget, start_date, end_date, status,
                    target_countries, target_browsers, target_devices, 
                    target_os, ad_formats
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
                $ad_formats_selected
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

// Get active RON campaigns - using ad_formats if available, fallback to banner_sizes for compatibility
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
                <h1 class="h3 mb-1">
                    <i class="fas fa-network-wired text-success"></i> RON Campaigns
                </h1>
                <p class="text-muted mb-0">Manage Run-of-Network campaigns</p>
            </div>
            <button class="btn btn-success" type="button" data-bs-toggle="collapse" data-bs-target="#createCampaignForm">
                <i class="fas fa-plus me-1"></i> New Campaign
            </button>
        </div>
        
        <div class="d-flex justify-content-between align-items-center mb-4 p-3 bg-light rounded">
            <div class="d-flex align-items-center">
                <i class="fas fa-clock text-primary me-2"></i>
                <span class="fw-medium">Current Time (UTC):</span>
                <span class="ms-2 font-monospace"><?php echo $current_timestamp; ?></span>
            </div>
            <div class="d-flex align-items-center">
                <i class="fas fa-user text-success me-2"></i>
                <span class="fw-medium">Logged in as:</span>
                <span class="ms-2 badge bg-success"><?php echo htmlspecialchars($current_user); ?></span>
            </div>
        </div>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show shadow-sm" role="alert">
        <i class="fas fa-<?php echo $message_type == 'success' ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Create Campaign Form -->
<div class="card mb-4 shadow-sm">
    <div class="card-header bg-success text-white">
        <h5 class="mb-0"><i class="fas fa-plus me-2"></i>Create RON Campaign</h5>
    </div>
    <div class="collapse" id="createCampaignForm">
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="create_campaign">
                
                <!-- Basic Information -->
                <div class="row mb-4">
                    <div class="col-12">
                        <h6 class="text-success border-bottom pb-2 mb-3">
                            <i class="fas fa-info-circle me-1"></i>Basic Information
                        </h6>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="name" class="form-label fw-medium">Campaign Name *</label>
                        <input type="text" class="form-control" id="name" name="name" required 
                               placeholder="Enter campaign name">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="advertiser_id" class="form-label fw-medium">Advertiser *</label>
                        <select class="form-select" id="advertiser_id" name="advertiser_id" required>
                            <option value="">Select Advertiser</option>
                            <?php foreach ($advertisers as $advertiser): ?>
                                <option value="<?php echo $advertiser['id']; ?>">
                                    <?php echo htmlspecialchars($advertiser['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <!-- Campaign Settings -->
                <div class="row mb-4">
                    <div class="col-12">
                        <h6 class="text-success border-bottom pb-2 mb-3">
                            <i class="fas fa-cogs me-1"></i>Campaign Settings
                        </h6>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="category_id" class="form-label fw-medium">Category</label>
                        <select class="form-select" id="category_id" name="category_id">
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>">
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="bid_type" class="form-label fw-medium">Bid Type</label>
                        <select class="form-select" id="bid_type" name="bid_type">
                            <option value="cpm">CPM - Cost Per Mile</option>
                            <option value="cpc">CPC - Cost Per Click</option>
                            <option value="cpv">CPV - Cost Per View (Video)</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="mt-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="priority_delivery" name="priority_delivery">
                                <label class="form-check-label fw-medium" for="priority_delivery">Priority Delivery</label>
                            </div>
                            <small class="form-text text-muted">Enable for preferred delivery across the network</small>
                        </div>
                    </div>
                </div>
                
                <!-- Budget & Timeline -->
                <div class="row mb-4">
                    <div class="col-12">
                        <h6 class="text-success border-bottom pb-2 mb-3">
                            <i class="fas fa-dollar-sign me-1"></i>Budget & Timeline
                        </h6>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label for="daily_budget" class="form-label fw-medium">Daily Budget</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" class="form-control" id="daily_budget" name="daily_budget"
                                   step="0.01" min="0" placeholder="0.00">
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label for="total_budget" class="form-label fw-medium">Total Budget</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" class="form-control" id="total_budget" name="total_budget"
                                   step="0.01" min="0" placeholder="0.00">
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label for="start_date" class="form-label fw-medium">Start Date</label>
                        <input type="date" class="form-control" id="start_date" name="start_date">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label for="end_date" class="form-label fw-medium">End Date</label>
                        <input type="date" class="form-control" id="end_date" name="end_date">
                    </div>
                </div>
                
                <!-- Ad Formats -->
                <div class="mb-4">
                    <h6 class="text-success border-bottom pb-2 mb-3">
                        <i class="fas fa-bullseye me-1"></i>Ad Formats
                    </h6>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>Select the ad formats you want to support in this RON campaign. 
                        <strong>Note:</strong> Specific sizes for banner ads will be configured when creating creatives.
                    </div>
                    <div class="row">
                        <div class="col-12 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="select_all_formats">
                                <label class="form-check-label fw-bold" for="select_all_formats">
                                    Select All Formats
                                </label>
                            </div>
                        </div>
                        <?php foreach ($ad_formats as $format_key => $format_name): ?>
                        <div class="col-md-6 col-lg-4 mb-3">
                            <div class="card border-0 bg-light h-100">
                                <div class="card-body p-3">
                                    <div class="form-check">
                                        <input class="form-check-input ad-format-checkbox" type="checkbox" 
                                               name="ad_formats[]" id="format_<?php echo $format_key; ?>" 
                                               value="<?php echo $format_key; ?>">
                                        <label class="form-check-label d-flex align-items-start" for="format_<?php echo $format_key; ?>">
                                            <i class="fas fa-<?php 
                                                echo $format_key == 'banner' ? 'image' : 
                                                    (strpos($format_key, 'video') !== false ? 'play-circle' : 
                                                    ($format_key == 'native' ? 'file-alt' : 
                                                    ($format_key == 'popup' ? 'external-link-alt' : 
                                                    ($format_key == 'text_ads' ? 'font' : 'expand')))); 
                                            ?> me-2 mt-1 text-success"></i>
                                            <div>
                                                <strong><?php echo htmlspecialchars($format_name); ?></strong>
                                                <?php if ($format_key == 'video_instream'): ?>
                                                    <small class="d-block text-muted">Pre-roll, mid-roll, post-roll video ads</small>
                                                <?php elseif ($format_key == 'video_outstream'): ?>
                                                    <small class="d-block text-muted">Standalone video ads in content</small>
                                                <?php elseif ($format_key == 'popup'): ?>
                                                    <small class="d-block text-muted">Pop-up and pop-under ads</small>
                                                <?php elseif ($format_key == 'text_ads'): ?>
                                                    <small class="d-block text-muted">Text-based contextual ads</small>
                                                <?php endif; ?>
                                            </div>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Targeting Options -->
                <div class="mb-4">
                    <h6 class="text-success border-bottom pb-2 mb-3">
                        <i class="fas fa-crosshairs me-1"></i>Targeting Options
                    </h6>
                    
                    <!-- Countries Selection -->
                    <div class="mb-4">
                        <label class="form-label fw-medium">Target Countries</label>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="select_all_countries" name="select_all_countries" checked>
                            <label class="form-check-label fw-medium" for="select_all_countries">
                                All Countries <small class="text-muted">(no targeting restrictions)</small>
                            </label>
                        </div>
                        
                        <div id="countries_container" class="row g-2 mt-2" style="display: none;">
                            <?php foreach ($countries as $code => $name): ?>
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input class="form-check-input country-checkbox" type="checkbox" name="target_countries[]" 
                                           id="country_<?php echo $code; ?>" value="<?php echo $code; ?>">
                                    <label class="form-check-label" for="country_<?php echo $code; ?>">
                                        <?php echo htmlspecialchars($name); ?> (<?php echo $code; ?>)
                                    </label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Browsers Selection -->
                    <div class="mb-4">
                        <label class="form-label fw-medium">Target Browsers</label>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="select_all_browsers" name="select_all_browsers" checked>
                            <label class="form-check-label fw-medium" for="select_all_browsers">
                                All Browsers <small class="text-muted">(no targeting restrictions)</small>
                            </label>
                        </div>
                        
                        <div id="browsers_container" class="row g-2 mt-2" style="display: none;">
                            <?php foreach ($browsers as $code => $name): ?>
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input class="form-check-input browser-checkbox" type="checkbox" name="target_browsers[]" 
                                           id="browser_<?php echo $code; ?>" value="<?php echo $code; ?>">
                                    <label class="form-check-label" for="browser_<?php echo $code; ?>">
                                        <?php echo htmlspecialchars($name); ?>
                                    </label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Devices Selection -->
                    <div class="mb-4">
                        <label class="form-label fw-medium">Target Devices</label>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="select_all_devices" name="select_all_devices" checked>
                            <label class="form-check-label fw-medium" for="select_all_devices">
                                All Devices <small class="text-muted">(no targeting restrictions)</small>
                            </label>
                        </div>
                        
                        <div id="devices_container" class="row g-2 mt-2" style="display: none;">
                            <?php foreach ($devices as $code => $name): ?>
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input class="form-check-input device-checkbox" type="checkbox" name="target_devices[]" 
                                           id="device_<?php echo $code; ?>" value="<?php echo $code; ?>">
                                    <label class="form-check-label" for="device_<?php echo $code; ?>">
                                        <?php echo htmlspecialchars($name); ?>
                                    </label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- OS Selection -->
                    <div class="mb-4">
                        <label class="form-label fw-medium">Target Operating Systems</label>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="select_all_os" name="select_all_os" checked>
                            <label class="form-check-label fw-medium" for="select_all_os">
                                All Operating Systems <small class="text-muted">(no targeting restrictions)</small>
                            </label>
                        </div>
                        
                        <div id="os_container" class="row g-2 mt-2" style="display: none;">
                            <?php foreach ($operating_systems as $code => $name): ?>
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input class="form-check-input os-checkbox" type="checkbox" name="target_os[]" 
                                           id="os_<?php echo $code; ?>" value="<?php echo $code; ?>">
                                    <label class="form-check-label" for="os_<?php echo $code; ?>">
                                        <?php echo htmlspecialchars($name); ?>
                                    </label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <div class="d-grid">
                    <button type="submit" class="btn btn-success btn-lg">
                        <i class="fas fa-save me-2"></i>Create RON Campaign
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Campaigns List -->
<div class="card shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0 text-success">
            <i class="fas fa-list me-2"></i>RON Campaigns
        </h5>
        <span class="badge bg-success fs-6"><?php echo count($campaigns); ?> Campaigns</span>
    </div>
    <div class="card-body">
        <?php if (empty($campaigns)): ?>
            <div class="text-center py-5">
                <i class="fas fa-network-wired fa-4x text-muted mb-4"></i>
                <h4 class="text-muted">No RON campaigns created yet</h4>
                <p class="text-muted mb-4">Create your first RON campaign to start managing run-of-network ads.</p>
                <button class="btn btn-success btn-lg" type="button" data-bs-toggle="collapse" data-bs-target="#createCampaignForm">
                    <i class="fas fa-plus me-2"></i>Create Your First Campaign
                </button>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th class="fw-medium">Campaign Details</th>
                            <th class="fw-medium">Budget</th>
                            <th class="fw-medium">Timeline</th>
                            <th class="fw-medium text-center">Creatives</th>
                            <th class="fw-medium">Performance</th>
                            <th class="fw-medium text-center">Status</th>
                            <th class="fw-medium text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($campaigns as $campaign): ?>
                        <tr>
                            <td>
                                <div>
                                    <strong class="text-dark"><?php echo htmlspecialchars($campaign['name']); ?></strong>
                                    <br>
                                    <small class="text-muted">
                                        <i class="fas fa-user me-1"></i>Advertiser: <?php echo htmlspecialchars($campaign['advertiser_name']); ?>
                                    </small>
                                    <?php if ($campaign['category_name']): ?>
                                        <br>
                                        <small class="text-muted">
                                            <i class="fas fa-tag me-1"></i>Category: <?php echo htmlspecialchars($campaign['category_name']); ?>
                                        </small>
                                    <?php endif; ?>
                                    <?php 
                                    // Check for ad_formats first, fallback to banner_sizes for backward compatibility
                                    $formats_data = $campaign['ad_formats'] ?? $campaign['banner_sizes'] ?? null;
                                    if ($formats_data): 
                                    ?>
                                        <br>
                                        <small class="text-muted">
                                            <i class="fas fa-bullseye me-1"></i>Formats: 
                                            <?php 
                                            $formats = json_decode($formats_data, true);
                                            if ($formats) {
                                                $format_labels = [];
                                                foreach ($formats as $format) {
                                                    if (isset($ad_formats[$format])) {
                                                        $format_labels[] = $ad_formats[$format];
                                                    } else {
                                                        // Handle banner sizes for backward compatibility
                                                        $format_labels[] = ($format == 'banner' || strpos($format, 'x') !== false) ? 'Banner' : ucfirst($format);
                                                    }
                                                }
                                                echo htmlspecialchars(implode(', ', array_slice($format_labels, 0, 2)));
                                                if (count($format_labels) > 2) {
                                                    echo ' <span class="badge bg-secondary">+' . (count($format_labels) - 2) . '</span>';
                                                }
                                            }
                                            ?>
                                        </small>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="small">
                                    <?php if ($campaign['daily_budget']): ?>
                                        <div><i class="fas fa-calendar-day me-1 text-success"></i><?php echo formatCurrency($campaign['daily_budget']); ?>/day</div>
                                    <?php endif; ?>
                                    <?php if ($campaign['total_budget']): ?>
                                        <div><i class="fas fa-wallet me-1 text-primary"></i><?php echo formatCurrency($campaign['total_budget']); ?> total</div>
                                    <?php endif; ?>
                                    <div class="text-muted">
                                        <i class="fas fa-mouse-pointer me-1"></i><?php echo strtoupper($campaign['bid_type']); ?> bidding
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="small">
                                    <?php if ($campaign['start_date']): ?>
                                        <div><i class="fas fa-play me-1 text-success"></i><?php echo date('M j, Y', strtotime($campaign['start_date'])); ?></div>
                                    <?php endif; ?>
                                    <?php if ($campaign['end_date']): ?>
                                        <div><i class="fas fa-stop me-1 text-danger"></i><?php echo date('M j, Y', strtotime($campaign['end_date'])); ?></div>
                                    <?php endif; ?>
                                    <div class="text-muted">
                                        <i class="fas fa-plus me-1"></i>Created: <?php echo date('M j, Y', strtotime($campaign['created_at'])); ?>
                                    </div>
                                </div>
                            </td>
                            <td class="text-center">
                                <div class="small">
                                    <div class="fw-bold text-success"><?php echo $campaign['creative_count']; ?></div>
                                    <div class="text-muted">total</div>
                                    <div class="text-primary fw-medium"><?php echo $campaign['active_creatives']; ?> active</div>
                                    <a href="creative.php?campaign_id=<?php echo $campaign['id']; ?>&type=ron" 
                                       class="btn btn-sm btn-outline-success mt-1">
                                        <i class="fas fa-images me-1"></i>Manage
                                    </a>
                                </div>
                            </td>
                            <td>
                                <div class="small">
                                    <div class="d-flex justify-content-between">
                                        <span>Impressions:</span>
                                        <strong class="text-success"><?php echo formatNumber($campaign['impressions'] ?? 0); ?></strong>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>Clicks:</span>
                                        <strong class="text-info"><?php echo formatNumber($campaign['clicks'] ?? 0); ?></strong>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>Spent:</span>
                                        <strong class="text-primary"><?php echo formatCurrency($campaign['revenue'] ?? 0); ?></strong>
                                    </div>
                                    <?php if ($campaign['impressions'] > 0): ?>
                                        <div class="d-flex justify-content-between border-top pt-1 mt-1">
                                            <span>CTR:</span>
                                            <strong class="text-warning"><?php echo number_format(($campaign['clicks'] / $campaign['impressions']) * 100, 2); ?>%</strong>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-<?php 
                                    echo $campaign['status'] == 'active' ? 'success' : 
                                        ($campaign['status'] == 'paused' ? 'warning' : 
                                        ($campaign['status'] == 'completed' ? 'secondary' : 'info')); 
                                ?> px-3 py-2">
                                    <i class="fas fa-<?php 
                                        echo $campaign['status'] == 'active' ? 'play' : 
                                            ($campaign['status'] == 'paused' ? 'pause' : 
                                            ($campaign['status'] == 'completed' ? 'check' : 'clock')); 
                                    ?> me-1"></i>
                                    <?php echo ucfirst($campaign['status']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm d-flex" role="group">
                                    <button class="btn btn-outline-success btn-edit" 
                                            data-campaign-id="<?php echo $campaign['id']; ?>"
                                            data-bs-toggle="tooltip" title="Edit Campaign">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <a href="statistics.php?campaign_id=<?php echo $campaign['id']; ?>" 
                                       class="btn btn-outline-primary" data-bs-toggle="tooltip" title="View Statistics">
                                        <i class="fas fa-chart-bar"></i>
                                    </a>
                                    <button class="btn btn-outline-<?php echo $campaign['status'] == 'active' ? 'warning' : 'success'; ?> btn-toggle-status" 
                                            data-campaign-id="<?php echo $campaign['id']; ?>"
                                            data-current-status="<?php echo $campaign['status']; ?>"
                                            data-bs-toggle="tooltip" 
                                            title="<?php echo $campaign['status'] == 'active' ? 'Pause Campaign' : 'Resume Campaign'; ?>">
                                        <i class="fas fa-<?php echo $campaign['status'] == 'active' ? 'pause' : 'play'; ?>"></i>
                                    </button>
                                    <button class="btn btn-outline-danger btn-delete" 
                                            data-campaign-id="<?php echo $campaign['id']; ?>"
                                            data-campaign-name="<?php echo htmlspecialchars($campaign['name']); ?>"
                                            data-bs-toggle="tooltip" title="Delete Campaign">
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

<!-- Toggle Status Modal -->
<div class="modal fade" id="toggleStatusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Status Change</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to <span id="status_action"></span> this campaign?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="toggle_status">
                    <input type="hidden" name="campaign_id" id="toggle_campaign_id">
                    <input type="hidden" name="new_status" id="toggle_new_status">
                    <button type="submit" class="btn btn-primary" id="confirm_toggle_btn">Confirm</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the campaign "<span id="delete_campaign_name"></span>"?</p>
                <p class="text-danger"><small><i class="fas fa-exclamation-triangle me-1"></i>This action cannot be undone and will also delete all related creatives.</small></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="delete_campaign">
                    <input type="hidden" name="campaign_id" id="delete_campaign_id">
                    <button type="submit" class="btn btn-danger">Delete Campaign</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Set default start date (today)
    const today = new Date();
    document.getElementById('start_date').valueAsDate = today;
    
    // Set default end date (30 days from now)
    const thirtyDaysLater = new Date();
    thirtyDaysLater.setDate(today.getDate() + 30);
    document.getElementById('end_date').valueAsDate = thirtyDaysLater;
    
    // Handle "All Countries" checkbox
    document.getElementById('select_all_countries').addEventListener('change', function() {
        const countriesContainer = document.getElementById('countries_container');
        const countryCheckboxes = document.querySelectorAll('.country-checkbox');
        
        if (this.checked) {
            countriesContainer.style.display = 'none';
            countryCheckboxes.forEach(cb => cb.checked = false);
        } else {
            countriesContainer.style.display = 'flex';
            // Select at least the first few important countries
            ['US', 'UK', 'CA', 'AU'].forEach(code => {
                const cb = document.getElementById('country_' + code);
                if (cb) cb.checked = true;
            });
        }
    });
    
    // Handle "All Browsers" checkbox
    document.getElementById('select_all_browsers').addEventListener('change', function() {
        const browsersContainer = document.getElementById('browsers_container');
        const browserCheckboxes = document.querySelectorAll('.browser-checkbox');
        
        if (this.checked) {
            browsersContainer.style.display = 'none';
            browserCheckboxes.forEach(cb => cb.checked = false);
        } else {
            browsersContainer.style.display = 'flex';
            // Select major browsers by default
            ['Chrome', 'Firefox', 'Safari', 'Edge'].forEach(code => {
                const cb = document.getElementById('browser_' + code);
                if (cb) cb.checked = true;
            });
        }
    });
    
    // Handle "All Devices" checkbox
    document.getElementById('select_all_devices').addEventListener('change', function() {
        const devicesContainer = document.getElementById('devices_container');
        const deviceCheckboxes = document.querySelectorAll('.device-checkbox');
        
        if (this.checked) {
            devicesContainer.style.display = 'none';
            deviceCheckboxes.forEach(cb => cb.checked = false);
        } else {
            devicesContainer.style.display = 'flex';
            // Select desktop and mobile by default
            ['desktop', 'mobile', 'tablet'].forEach(code => {
                const cb = document.getElementById('device_' + code);
                if (cb) cb.checked = true;
            });
        }
    });
    
    // Handle "All OS" checkbox
    document.getElementById('select_all_os').addEventListener('change', function() {
        const osContainer = document.getElementById('os_container');
        const osCheckboxes = document.querySelectorAll('.os-checkbox');
        
        if (this.checked) {
            osContainer.style.display = 'none';
            osCheckboxes.forEach(cb => cb.checked = false);
        } else {
            osContainer.style.display = 'flex';
            // Select major OS by default
            ['Windows', 'MacOS', 'iOS', 'Android'].forEach(code => {
                const cb = document.getElementById('os_' + code);
                if (cb) cb.checked = true;
            });
        }
    });
    
    // Handle "Select All Ad Formats" checkbox
    document.getElementById('select_all_formats').addEventListener('change', function() {
        const formatCheckboxes = document.querySelectorAll('.ad-format-checkbox');
        formatCheckboxes.forEach(cb => cb.checked = this.checked);
    });

    // Default select banner and video_instream formats
    document.getElementById('format_banner').checked = true;
    document.getElementById('format_video_instream').checked = true;
    
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    });
    
    // Handle edit button
    document.querySelectorAll('.btn-edit').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const campaignId = this.dataset.campaignId;
            // Redirect to edit page
            window.location.href = 'edit-campaign.php?id=' + campaignId + '&type=ron';
        });
    });
    
    // Handle toggle status button
    document.querySelectorAll('.btn-toggle-status').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const campaignId = this.dataset.campaignId;
            const currentStatus = this.dataset.currentStatus;
            const newStatus = currentStatus === 'active' ? 'paused' : 'active';
            const action = currentStatus === 'active' ? 'pause' : 'resume';
            
            document.getElementById('toggle_campaign_id').value = campaignId;
            document.getElementById('toggle_new_status').value = newStatus;
            document.getElementById('status_action').textContent = action;
            document.getElementById('confirm_toggle_btn').textContent = action.charAt(0).toUpperCase() + action.slice(1);
            document.getElementById('confirm_toggle_btn').className = 'btn ' + (newStatus === 'paused' ? 'btn-warning' : 'btn-success');
            
            new bootstrap.Modal(document.getElementById('toggleStatusModal')).show();
        });
    });
    
    // Handle delete button
    document.querySelectorAll('.btn-delete').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const campaignId = this.dataset.campaignId;
            const campaignName = this.dataset.campaignName;
            
            document.getElementById('delete_campaign_id').value = campaignId;
            document.getElementById('delete_campaign_name').textContent = campaignName;
            
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        });
    });
});
</script>

<?php 
// End output buffering and send the output
ob_end_flush();
include 'includes/footer.php'; 
?>
