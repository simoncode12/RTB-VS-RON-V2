<?php
// Start output buffering to prevent "headers already sent" errors
ob_start();

include 'includes/header.php';

// Current session information
$current_timestamp = '2025-06-26 07:27:23';
$current_user = 'simoncode12';

$message = '';
$message_type = 'success';

// Get campaign ID
$campaign_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$campaign_type = $_GET['type'] ?? 'rtb';

if (!$campaign_id) {
    // Redirect based on campaign type parameter
    if ($campaign_type === 'rtb') {
        header("Location: rtb-sell.php");
    } else {
        header("Location: ron-campaign.php");
    }
    exit;
}

// Fetch campaign data
$stmt = $pdo->prepare("
    SELECT c.*, a.name as advertiser_name 
    FROM campaigns c
    LEFT JOIN advertisers a ON c.advertiser_id = a.id
    WHERE c.id = ?
");
$stmt->execute([$campaign_id]);
$campaign = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$campaign) {
    // Redirect based on campaign type
    if ($campaign_type === 'rtb') {
        header("Location: rtb-sell.php");
    } else {
        header("Location: ron-campaign.php");
    }
    exit;
}

// Set the correct return page based on actual campaign type
$return_page = $campaign['type'] === 'rtb' ? 'rtb-sell.php' : 'ron-campaign.php';

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

// Ad formats available for campaigns
$ad_formats_list = [
    'banner' => 'Banner Display Ads',
    'video_instream' => 'Video Instream (Pre-roll)',
    'video_outstream' => 'Video Outstream',
    'native' => 'Native Ads',
    'interstitial' => 'Interstitial Ads',
    'rewarded_video' => 'Rewarded Video Ads',
    'audio' => 'Audio Ads'
];

// Decode JSON fields
$target_countries = json_decode($campaign['target_countries'] ?? '[]', true) ?: [];
$target_browsers = json_decode($campaign['target_browsers'] ?? '[]', true) ?: [];
$target_devices = json_decode($campaign['target_devices'] ?? '[]', true) ?: [];
$target_os = json_decode($campaign['target_os'] ?? '[]', true) ?: [];
$ad_formats = json_decode($campaign['ad_formats'] ?? '[]', true) ?: [];

// Handle form submission
if ($_POST && isset($_POST['action']) && $_POST['action'] == 'update_campaign') {
    try {
        $name = $_POST['name'] ?? '';
        $type = $_POST['type'] ?? $campaign['type'];
        $advertiser_id = $_POST['advertiser_id'] ?? '';
        $category_id = empty($_POST['category_id']) ? null : $_POST['category_id'];
        $bid_type = $_POST['bid_type'] ?? 'cpm';
        $daily_budget = !empty($_POST['daily_budget']) ? floatval($_POST['daily_budget']) : null;
        $total_budget = !empty($_POST['total_budget']) ? floatval($_POST['total_budget']) : null;
        $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
        $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        $status = $_POST['status'] ?? 'active';
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
        
        // Process ad formats
        $ad_formats_selected = !empty($_POST['ad_formats']) ? json_encode($_POST['ad_formats']) : json_encode(['banner']);
        
        if ($name && $advertiser_id) {
            $sql = "UPDATE campaigns SET 
                    name = ?,
                    type = ?,
                    advertiser_id = ?,
                    category_id = ?,
                    bid_type = ?,
                    daily_budget = ?,
                    total_budget = ?,
                    start_date = ?,
                    end_date = ?,
                    status = ?,
                    endpoint_url = ?,
                    target_countries = ?,
                    target_browsers = ?,
                    target_devices = ?,
                    target_os = ?,
                    ad_formats = ?,
                    updated_at = NOW()
                    WHERE id = ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $name,
                $type,
                $advertiser_id,
                $category_id,
                $bid_type,
                $daily_budget,
                $total_budget,
                $start_date,
                $end_date,
                $status,
                $type === 'rtb' ? $endpoint_url : null,
                $target_countries,
                $target_browsers,
                $target_devices,
                $target_os,
                $ad_formats_selected,
                $campaign_id
            ]);
            
            $message = 'Campaign updated successfully!';
            $message_type = 'success';
            
            // Record user activity
            try {
                $check_table = $pdo->query("SHOW TABLES LIKE 'user_activity'");
                if ($check_table->rowCount() > 0) {
                    $stmt = $pdo->prepare("
                        INSERT INTO user_activity (
                            user_id, action, entity_type, entity_id, ip_address, details, created_at
                        ) VALUES (?, 'update', 'campaign', ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $_SESSION['user_id'] ?? 0,
                        $campaign_id,
                        $_SERVER['REMOTE_ADDR'] ?? '',
                        'Updated ' . ucfirst($type) . ' campaign: ' . $name
                    ]);
                }
            } catch (Exception $e) {
                // Silent fail
            }
            
            // Redirect after successful update based on campaign type
            ob_end_clean();
            header("Location: $return_page?success=1");
            exit;
            
        } else {
            $message = 'Please fill in all required fields.';
            $message_type = 'danger';
        }
    } catch (Exception $e) {
        $message = 'Error updating campaign: ' . $e->getMessage();
        $message_type = 'danger';
    }
}
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 mb-1">
                    <i class="fas fa-edit text-primary"></i> Edit <?php echo strtoupper($campaign['type']); ?> Campaign
                </h1>
                <p class="text-muted mb-0">Modify campaign settings and targeting options</p>
            </div>
            <a href="<?php echo $return_page; ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-1"></i> Back to <?php echo $campaign['type'] === 'rtb' ? 'RTB' : 'RON'; ?> Campaigns
            </a>
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

<!-- Edit Campaign Form -->
<div class="card shadow-sm">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="fas fa-edit me-2"></i>Edit <?php echo strtoupper($campaign['type']); ?> Campaign</h5>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="update_campaign">
            <input type="hidden" name="type" value="<?php echo htmlspecialchars($campaign['type']); ?>">
            
            <!-- Basic Information -->
            <div class="row mb-4">
                <div class="col-12">
                    <h6 class="text-primary border-bottom pb-2 mb-3">
                        <i class="fas fa-info-circle me-1"></i>Basic Information
                    </h6>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="name" class="form-label fw-medium">Campaign Name *</label>
                    <input type="text" class="form-control" id="name" name="name" required 
                           value="<?php echo htmlspecialchars($campaign['name']); ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <label for="advertiser_id" class="form-label fw-medium">Advertiser *</label>
                    <select class="form-select" id="advertiser_id" name="advertiser_id" required>
                        <option value="">Select Advertiser</option>
                        <?php foreach ($advertisers as $advertiser): ?>
                            <option value="<?php echo $advertiser['id']; ?>" 
                                    <?php echo $campaign['advertiser_id'] == $advertiser['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($advertiser['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <!-- Campaign Settings -->
            <div class="row mb-4">
                <div class="col-12">
                    <h6 class="text-primary border-bottom pb-2 mb-3">
                        <i class="fas fa-cogs me-1"></i>Campaign Settings
                    </h6>
                </div>
                <div class="col-md-4 mb-3">
                    <label for="category_id" class="form-label fw-medium">Category</label>
                    <select class="form-select" id="category_id" name="category_id">
                        <option value="">Select Category</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['id']; ?>"
                                    <?php echo $campaign['category_id'] == $category['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($category['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label for="bid_type" class="form-label fw-medium">Bid Type</label>
                    <select class="form-select" id="bid_type" name="bid_type">
                        <option value="cpm" <?php echo $campaign['bid_type'] == 'cpm' ? 'selected' : ''; ?>>CPM - Cost Per Mile</option>
                        <option value="cpc" <?php echo $campaign['bid_type'] == 'cpc' ? 'selected' : ''; ?>>CPC - Cost Per Click</option>
                        <option value="cpv" <?php echo $campaign['bid_type'] == 'cpv' ? 'selected' : ''; ?>>CPV - Cost Per View (Video)</option>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label for="status" class="form-label fw-medium">Status</label>
                    <select class="form-select" id="status" name="status">
                        <option value="active" <?php echo $campaign['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="paused" <?php echo $campaign['status'] == 'paused' ? 'selected' : ''; ?>>Paused</option>
                        <option value="completed" <?php echo $campaign['status'] == 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="pending" <?php echo $campaign['status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                    </select>
                </div>
            </div>
            
            <?php if ($campaign['type'] === 'rtb'): ?>
            <div class="row mb-4">
                <div class="col-12">
                    <label for="endpoint_url" class="form-label fw-medium">RTB Endpoint URL</label>
                    <input type="url" class="form-control" id="endpoint_url" name="endpoint_url" 
                           value="<?php echo htmlspecialchars($campaign['endpoint_url'] ?? ''); ?>"
                           placeholder="https://example.com/rtb/bid">
                    <div class="form-text">External RTB endpoint for bid requests</div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Budget & Timeline -->
            <div class="row mb-4">
                <div class="col-12">
                    <h6 class="text-primary border-bottom pb-2 mb-3">
                        <i class="fas fa-dollar-sign me-1"></i>Budget & Timeline
                    </h6>
                </div>
                <div class="col-md-3 mb-3">
                    <label for="daily_budget" class="form-label fw-medium">Daily Budget</label>
                    <div class="input-group">
                        <span class="input-group-text">$</span>
                        <input type="number" class="form-control" id="daily_budget" name="daily_budget"
                               step="0.01" min="0" value="<?php echo $campaign['daily_budget']; ?>">
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <label for="total_budget" class="form-label fw-medium">Total Budget</label>
                    <div class="input-group">
                        <span class="input-group-text">$</span>
                        <input type="number" class="form-control" id="total_budget" name="total_budget"
                               step="0.01" min="0" value="<?php echo $campaign['total_budget']; ?>">
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <label for="start_date" class="form-label fw-medium">Start Date</label>
                    <input type="date" class="form-control" id="start_date" name="start_date"
                           value="<?php echo $campaign['start_date']; ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label for="end_date" class="form-label fw-medium">End Date</label>
                    <input type="date" class="form-control" id="end_date" name="end_date"
                           value="<?php echo $campaign['end_date']; ?>">
                </div>
            </div>
            
            <!-- Budget Spent Info -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="alert alert-info">
                        <h6 class="mb-2"><i class="fas fa-chart-line me-2"></i>Campaign Performance</h6>
                        <div class="row">
                            <div class="col-md-3">
                                <strong>Daily Spent:</strong> $<?php echo number_format($campaign['daily_spent'], 2); ?>
                            </div>
                            <div class="col-md-3">
                                <strong>Total Spent:</strong> $<?php echo number_format($campaign['total_spent'], 2); ?>
                            </div>
                            <div class="col-md-3">
                                <strong>Created:</strong> <?php echo date('M j, Y', strtotime($campaign['created_at'])); ?>
                            </div>
                            <div class="col-md-3">
                                <strong>Updated:</strong> <?php echo date('M j, Y H:i', strtotime($campaign['updated_at'])); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Ad Formats -->
            <div class="mb-4">
                <h6 class="text-primary border-bottom pb-2 mb-3">
                    <i class="fas fa-bullseye me-1"></i>Ad Formats
                </h6>
                <div class="row">
                    <?php foreach ($ad_formats_list as $format_key => $format_name): ?>
                    <div class="col-md-6 col-lg-4 mb-3">
                        <div class="card border-0 bg-light h-100">
                            <div class="card-body p-3">
                                <div class="form-check">
                                    <input class="form-check-input ad-format-checkbox" type="checkbox" 
                                           name="ad_formats[]" id="format_<?php echo $format_key; ?>" 
                                           value="<?php echo $format_key; ?>"
                                           <?php echo in_array($format_key, $ad_formats) ? 'checked' : ''; ?>>
                                    <label class="form-check-label d-flex align-items-start" for="format_<?php echo $format_key; ?>">
                                        <i class="fas fa-<?php 
                                            echo $format_key == 'banner' ? 'image' : 
                                                (strpos($format_key, 'video') !== false ? 'play-circle' : 
                                                ($format_key == 'native' ? 'file-alt' : 
                                                ($format_key == 'audio' ? 'volume-up' : 'expand'))); 
                                        ?> me-2 mt-1 text-primary"></i>
                                        <div>
                                            <strong><?php echo htmlspecialchars($format_name); ?></strong>
                                            <?php if ($format_key == 'video_instream'): ?>
                                                <small class="d-block text-muted">Pre-roll, mid-roll, post-roll video ads</small>
                                            <?php elseif ($format_key == 'video_outstream'): ?>
                                                <small class="d-block text-muted">Standalone video ads in content</small>
                                            <?php elseif ($format_key == 'rewarded_video'): ?>
                                                <small class="d-block text-muted">Video ads with user rewards</small>
                                            <?php elseif ($format_key == 'audio'): ?>
                                                <small class="d-block text-muted">Audio advertisements and podcasts</small>
                                            <?php elseif ($format_key == 'banner'): ?>
                                                <small class="d-block text-muted">Standard display banner ads</small>
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
                <h6 class="text-primary border-bottom pb-2 mb-3">
                    <i class="fas fa-crosshairs me-1"></i>Targeting Options
                </h6>
                
                <!-- Countries Selection -->
                <div class="mb-4">
                    <label class="form-label fw-medium">Target Countries</label>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="select_all_countries" name="select_all_countries" 
                               <?php echo empty($target_countries) ? 'checked' : ''; ?>>
                        <label class="form-check-label fw-medium" for="select_all_countries">
                            All Countries <small class="text-muted">(no targeting restrictions)</small>
                        </label>
                    </div>
                    
                    <div id="countries_container" class="row g-2 mt-2" style="<?php echo empty($target_countries) ? 'display: none;' : ''; ?>">
                        <?php foreach ($countries as $code => $name): ?>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input country-checkbox" type="checkbox" name="target_countries[]" 
                                       id="country_<?php echo $code; ?>" value="<?php echo $code; ?>"
                                       <?php echo in_array($code, $target_countries) ? 'checked' : ''; ?>>
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
                        <input class="form-check-input" type="checkbox" id="select_all_browsers" name="select_all_browsers"
                               <?php echo empty($target_browsers) ? 'checked' : ''; ?>>
                        <label class="form-check-label fw-medium" for="select_all_browsers">
                            All Browsers <small class="text-muted">(no targeting restrictions)</small>
                        </label>
                    </div>
                    
                    <div id="browsers_container" class="row g-2 mt-2" style="<?php echo empty($target_browsers) ? 'display: none;' : ''; ?>">
                        <?php foreach ($browsers as $code => $name): ?>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input browser-checkbox" type="checkbox" name="target_browsers[]" 
                                       id="browser_<?php echo $code; ?>" value="<?php echo $code; ?>"
                                       <?php echo in_array($code, $target_browsers) ? 'checked' : ''; ?>>
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
                        <input class="form-check-input" type="checkbox" id="select_all_devices" name="select_all_devices"
                               <?php echo empty($target_devices) ? 'checked' : ''; ?>>
                        <label class="form-check-label fw-medium" for="select_all_devices">
                            All Devices <small class="text-muted">(no targeting restrictions)</small>
                        </label>
                    </div>
                    
                    <div id="devices_container" class="row g-2 mt-2" style="<?php echo empty($target_devices) ? 'display: none;' : ''; ?>">
                        <?php foreach ($devices as $code => $name): ?>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input device-checkbox" type="checkbox" name="target_devices[]" 
                                       id="device_<?php echo $code; ?>" value="<?php echo $code; ?>"
                                       <?php echo in_array($code, $target_devices) ? 'checked' : ''; ?>>
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
                        <input class="form-check-input" type="checkbox" id="select_all_os" name="select_all_os"
                               <?php echo empty($target_os) ? 'checked' : ''; ?>>
                        <label class="form-check-label fw-medium" for="select_all_os">
                            All Operating Systems <small class="text-muted">(no targeting restrictions)</small>
                        </label>
                    </div>
                    
                    <div id="os_container" class="row g-2 mt-2" style="<?php echo empty($target_os) ? 'display: none;' : ''; ?>">
                        <?php foreach ($operating_systems as $code => $name): ?>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input os-checkbox" type="checkbox" name="target_os[]" 
                                       id="os_<?php echo $code; ?>" value="<?php echo $code; ?>"
                                       <?php echo in_array($code, $target_os) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="os_<?php echo $code; ?>">
                                    <?php echo htmlspecialchars($name); ?>
                                </label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                <a href="<?php echo $return_page; ?>" class="btn btn-secondary">
                    <i class="fas fa-times me-2"></i>Cancel
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-2"></i>Update Campaign
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Handle "All Countries" checkbox
    document.getElementById('select_all_countries').addEventListener('change', function() {
        const countriesContainer = document.getElementById('countries_container');
        const countryCheckboxes = document.querySelectorAll('.country-checkbox');
        
        if (this.checked) {
            countriesContainer.style.display = 'none';
            countryCheckboxes.forEach(cb => cb.checked = false);
        } else {
            countriesContainer.style.display = 'flex';
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
        }
    });
    
    // Form validation
    document.querySelector('form')?.addEventListener('submit', function(e) {
        const startDate = document.getElementById('start_date').value;
        const endDate = document.getElementById('end_date').value;
        
        if (startDate && endDate && startDate > endDate) {
            e.preventDefault();
            alert('End date must be after start date');
            return false;
        }
        
        const dailyBudget = parseFloat(document.getElementById('daily_budget').value) || 0;
        const totalBudget = parseFloat(document.getElementById('total_budget').value) || 0;
        
        if (dailyBudget > 0 && totalBudget > 0 && dailyBudget > totalBudget) {
            e.preventDefault();
            alert('Daily budget cannot exceed total budget');
            return false;
        }
    });
});
</script>

<?php 
// End output buffering and send the output
ob_end_flush();
include 'includes/footer.php'; 
?>
