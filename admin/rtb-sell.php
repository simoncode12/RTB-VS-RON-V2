<?php
// Start output buffering to prevent "headers already sent" errors
ob_start();

include 'includes/header.php';

// Current session information
$current_timestamp = '2025-06-23 06:14:17'; // Current UTC time
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
        $endpoint_url = $_POST['endpoint_url'] ?? '';
        
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
            $stmt = $pdo->prepare("
                INSERT INTO campaigns (
                    advertiser_id, name, type, category_id, bid_type, 
                    daily_budget, total_budget, start_date, end_date, status,
                    endpoint_url, target_countries, target_browsers, target_devices, 
                    target_os, banner_sizes
                ) VALUES (
                    ?, ?, 'rtb', ?, ?, 
                    ?, ?, ?, ?, 'active',
                    ?, ?, ?, ?, 
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
                $endpoint_url, 
                $target_countries, 
                $target_browsers, 
                $target_devices,
                $target_os, 
                $banner_sizes
            ]);
            
            $campaign_id = $pdo->lastInsertId();
            
            $message = 'RTB campaign created successfully!';
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
                        'Created RTB campaign: ' . $name
                    ]);
                }
            } catch (Exception $e) {
                // Silent fail - don't prevent campaign creation if activity logging fails
            }
            
            // Use an intermediate variable to store the redirect URL
            $redirect_url = "creative.php?campaign_id={$campaign_id}&new=1";
            
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

// Get active campaigns
$campaigns = $pdo->query("
    SELECT c.*, a.name as advertiser_name, cat.name as category_name,
           COUNT(cr.id) as creative_count,
           SUM(CASE WHEN cr.status = 'active' THEN 1 ELSE 0 END) as active_creatives,
           SUM(bl.impressions) as impressions,
           SUM(bl.clicks) as clicks,
           SUM(bl.revenue) as revenue
    FROM campaigns c
    LEFT JOIN advertisers a ON c.advertiser_id = a.id
    LEFT JOIN categories cat ON c.category_id = cat.id
    LEFT JOIN creatives cr ON c.id = cr.campaign_id
    LEFT JOIN (
        SELECT 
            campaign_id, 
            COUNT(DISTINCT id) as impressions,
            SUM(CASE WHEN status = 'click' THEN 1 ELSE 0 END) as clicks,
            SUM(win_price) as revenue
        FROM bid_logs
        WHERE status IN ('win', 'click')
        GROUP BY campaign_id
    ) bl ON c.id = bl.campaign_id
    WHERE c.type = 'rtb'
    GROUP BY c.id
    ORDER BY c.created_at DESC
")->fetchAll();
?>

<div class="row">
    <div class="col-12">
        <h1 class="h3 mb-4">
            <i class="fas fa-exchange-alt"></i> RTB Campaigns
            <small class="text-muted">Manage Real-Time Bidding campaigns</small>
        </h1>
        <div class="text-muted mb-3">
            <small>
                <i class="fas fa-clock"></i> Current Time (UTC): <?php echo $current_timestamp; ?> | 
                <i class="fas fa-user"></i> Logged in as: <?php echo htmlspecialchars($current_user); ?>
            </small>
        </div>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Create Campaign Form -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-plus"></i> Create RTB Campaign</h5>
        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#createCampaignForm">
            <i class="fas fa-plus"></i> New Campaign
        </button>
    </div>
    <div class="collapse" id="createCampaignForm">
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="create_campaign">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="name" class="form-label">Campaign Name *</label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="advertiser_id" class="form-label">Advertiser *</label>
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
                
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label for="category_id" class="form-label">Category</label>
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
                        <label for="bid_type" class="form-label">Bid Type</label>
                        <select class="form-select" id="bid_type" name="bid_type">
                            <option value="cpm">CPM - Cost Per Mile</option>
                            <option value="cpc">CPC - Cost Per Click</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="endpoint_url" class="form-label">RTB Endpoint URL</label>
                        <input type="url" class="form-control" id="endpoint_url" name="endpoint_url">
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label for="daily_budget" class="form-label">Daily Budget</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" class="form-control" id="daily_budget" name="daily_budget"
                                   step="0.01" min="0">
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label for="total_budget" class="form-label">Total Budget</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" class="form-control" id="total_budget" name="total_budget"
                                   step="0.01" min="0">
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label for="start_date" class="form-label">Start Date</label>
                        <input type="date" class="form-control" id="start_date" name="start_date">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label for="end_date" class="form-label">End Date</label>
                        <input type="date" class="form-control" id="end_date" name="end_date">
                    </div>
                </div>
                
                <h6 class="mt-4 mb-3">Targeting Options</h6>
                
                <!-- Countries Selection -->
                <div class="mb-4">
                    <label class="form-label">Target Countries</label>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="select_all_countries" name="select_all_countries" checked>
                        <label class="form-check-label" for="select_all_countries">
                            <strong>All Countries</strong> (no targeting restrictions)
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
                    <label class="form-label">Target Browsers</label>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="select_all_browsers" name="select_all_browsers" checked>
                        <label class="form-check-label" for="select_all_browsers">
                            <strong>All Browsers</strong> (no targeting restrictions)
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
                    <label class="form-label">Target Devices</label>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="select_all_devices" name="select_all_devices" checked>
                        <label class="form-check-label" for="select_all_devices">
                            <strong>All Devices</strong> (no targeting restrictions)
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
                    <label class="form-label">Target Operating Systems</label>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="select_all_os" name="select_all_os" checked>
                        <label class="form-check-label" for="select_all_os">
                            <strong>All Operating Systems</strong> (no targeting restrictions)
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
        <h5 class="mb-0"><i class="fas fa-list"></i> RTB Campaigns</h5>
    </div>
    <div class="card-body">
        <?php if (empty($campaigns)): ?>
            <div class="text-center py-4">
                <i class="fas fa-exchange-alt fa-3x text-muted mb-3"></i>
                <p class="text-muted">No RTB campaigns created yet.</p>
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
                                    <a href="creative.php?campaign_id=<?php echo $campaign['id']; ?>" class="btn btn-sm btn-outline-primary mt-1">
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
    
    // Handle "Select All Sizes" checkbox
    document.getElementById('select_all_sizes').addEventListener('change', function() {
        const sizeCheckboxes = document.querySelectorAll('.banner-size');
        sizeCheckboxes.forEach(cb => cb.checked = this.checked);
    });

    // Default select all banner sizes
    document.getElementById('select_all_sizes').checked = true;
    const sizeCheckboxes = document.querySelectorAll('.banner-size');
    sizeCheckboxes.forEach(cb => cb.checked = true);
});
</script>

<?php 
// End output buffering and send the output
ob_end_flush();
include 'includes/footer.php'; 
?>