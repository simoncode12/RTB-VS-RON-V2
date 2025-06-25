<?php
// Start output buffering to prevent "headers already sent" errors
ob_start();

include 'includes/header.php';

// Current session information
$current_timestamp = '2025-06-23 18:34:34'; // Current UTC time
$current_user = 'simoncode12';              // Current logged in user

$message = '';
$message_type = 'success';

// Get websites for dropdown selection
$websites = $pdo->query("
    SELECT w.id, w.name, w.domain, p.name AS publisher_name 
    FROM websites w
    JOIN publishers p ON w.publisher_id = p.id 
    WHERE w.status = 'active'
    ORDER BY w.name ASC
")->fetchAll();

// Get available zone sizes
$sizes = [
    '300x250' => 'Medium Rectangle (300x250)',
    '728x90' => 'Leaderboard (728x90)',
    '160x600' => 'Skyscraper (160x600)',
    '300x600' => 'Half Page (300x600)',
    '320x50' => 'Mobile Banner (320x50)',
    '336x280' => 'Large Rectangle (336x280)',
    '970x90' => 'Large Leaderboard (970x90)',
    '970x250' => 'Billboard (970x250)',
    '320x100' => 'Large Mobile Banner (320x100)',
    '468x60' => 'Banner (468x60)'
];

// Handle zone creation
if ($_POST && isset($_POST['action']) && $_POST['action'] == 'create_zone') {
    try {
        // Get form data
        $website_id = $_POST['website_id'] ?? '';
        $name = $_POST['name'] ?? '';
        $size = $_POST['size'] ?? '';
        $zone_type = $_POST['zone_type'] ?? 'banner';
        $status = $_POST['status'] ?? 'active';
        
        // Validate required fields
        if (empty($website_id) || empty($name) || empty($size)) {
            throw new Exception('Website, name, and size are required fields');
        }
        
        // Insert the new zone
        $stmt = $pdo->prepare("
            INSERT INTO zones (
                website_id, name, size, zone_type, status,
                created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                NOW(), NOW()
            )
        ");
        
        $stmt->execute([
            $website_id,
            $name,
            $size,
            $zone_type,
            $status
        ]);
        
        $zone_id = $pdo->lastInsertId();
        
        // Log user activity
        try {
            $check_table = $pdo->query("SHOW TABLES LIKE 'user_activity'");
            if ($check_table->rowCount() > 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO user_activity (
                        user_id, action, entity_type, entity_id, ip_address, details, created_at
                    ) VALUES (?, 'create', 'zone', ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $_SESSION['user_id'] ?? 0,
                    $zone_id,
                    $_SERVER['REMOTE_ADDR'] ?? '',
                    "Created zone: $name for website ID: $website_id"
                ]);
            }
        } catch (Exception $e) {
            // Silent fail - don't prevent zone creation if activity logging fails
        }
        
        // Success message
        $message = "Zone \"$name\" created successfully!";
        $message_type = 'success';
        
    } catch (Exception $e) {
        // Error message
        $message = 'Error: ' . $e->getMessage();
        $message_type = 'danger';
    }
}

// Handle zone deletion
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    try {
        $zone_id = $_GET['delete'];
        
        // Get zone details before deleting
        $stmt = $pdo->prepare("SELECT name, website_id FROM zones WHERE id = ?");
        $stmt->execute([$zone_id]);
        $zone = $stmt->fetch();
        
        if ($zone) {
            // Delete the zone
            $stmt = $pdo->prepare("DELETE FROM zones WHERE id = ?");
            $stmt->execute([$zone_id]);
            
            // Log user activity
            try {
                $check_table = $pdo->query("SHOW TABLES LIKE 'user_activity'");
                if ($check_table->rowCount() > 0) {
                    $stmt = $pdo->prepare("
                        INSERT INTO user_activity (
                            user_id, action, entity_type, entity_id, ip_address, details, created_at
                        ) VALUES (?, 'delete', 'zone', ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $_SESSION['user_id'] ?? 0,
                        $zone_id,
                        $_SERVER['REMOTE_ADDR'] ?? '',
                        "Deleted zone: {$zone['name']} from website ID: {$zone['website_id']}"
                    ]);
                }
            } catch (Exception $e) {
                // Silent fail
            }
            
            $message = "Zone deleted successfully!";
            $message_type = 'success';
        } else {
            $message = "Zone not found!";
            $message_type = 'danger';
        }
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
        $message_type = 'danger';
    }
}

// Handle zone status change
if (isset($_GET['toggle_status']) && is_numeric($_GET['toggle_status'])) {
    try {
        $zone_id = $_GET['toggle_status'];
        
        // Get current status
        $stmt = $pdo->prepare("SELECT status, name FROM zones WHERE id = ?");
        $stmt->execute([$zone_id]);
        $zone = $stmt->fetch();
        
        if ($zone) {
            // Toggle status
            $new_status = ($zone['status'] == 'active') ? 'inactive' : 'active';
            
            $stmt = $pdo->prepare("UPDATE zones SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_status, $zone_id]);
            
            // Log user activity
            try {
                $check_table = $pdo->query("SHOW TABLES LIKE 'user_activity'");
                if ($check_table->rowCount() > 0) {
                    $stmt = $pdo->prepare("
                        INSERT INTO user_activity (
                            user_id, action, entity_type, entity_id, ip_address, details, created_at
                        ) VALUES (?, 'update', 'zone', ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $_SESSION['user_id'] ?? 0,
                        $zone_id,
                        $_SERVER['REMOTE_ADDR'] ?? '',
                        "Updated zone status to $new_status: {$zone['name']}"
                    ]);
                }
            } catch (Exception $e) {
                // Silent fail
            }
            
            $message = "Zone \"{$zone['name']}\" ".($new_status == 'active' ? 'activated' : 'deactivated')." successfully!";
            $message_type = 'success';
        } else {
            $message = "Zone not found!";
            $message_type = 'danger';
        }
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
        $message_type = 'danger';
    }
}

// Get zone listing with website and publisher info
$zones = $pdo->query("
    SELECT z.*, w.name AS website_name, w.domain, p.name AS publisher_name,
           SUM(rt.impressions) AS total_impressions,
           SUM(rt.clicks) AS total_clicks,
           SUM(rt.revenue) AS total_revenue,
           SUM(rt.publisher_revenue) AS total_publisher_revenue
    FROM zones z
    LEFT JOIN websites w ON z.website_id = w.id
    LEFT JOIN publishers p ON w.publisher_id = p.id
    LEFT JOIN revenue_tracking rt ON z.id = rt.zone_id
    GROUP BY z.id
    ORDER BY z.created_at DESC
")->fetchAll();

// Get zone code for modal popup
$zone_id = isset($_GET['code']) && is_numeric($_GET['code']) ? $_GET['code'] : null;
$zone_code = '';
$zone_details = null;

if ($zone_id) {
    $stmt = $pdo->prepare("
        SELECT z.*, w.domain, w.name AS website_name
        FROM zones z
        LEFT JOIN websites w ON z.website_id = w.id
        WHERE z.id = ?
    ");
    $stmt->execute([$zone_id]);
    $zone_details = $stmt->fetch();
    
    if ($zone_details) {
        // Generate the zone code
        list($width, $height) = explode('x', $zone_details['size']);
        
        $zone_code = <<<HTML
<!-- RTB & RON Ad Zone: {$zone_details['name']} ({$zone_details['size']}) -->
<script type="text/javascript">
    var ad_zone_id = "{$zone_id}";
    var ad_zone_width = "{$width}";
    var ad_zone_height = "{$height}";
    var ad_zone_type = "{$zone_details['zone_type']}";
</script>
<script src="https://up.adstart.click/serve.js" async></script>
<!-- End Ad Zone -->
HTML;
    }
}
?>

<div class="row">
    <div class="col-12">
        <h1 class="h3 mb-4">
            <i class="fas fa-th"></i> Ad Zones
            <small class="text-muted">Manage publisher ad zones</small>
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

<!-- Zone Create Form -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-plus"></i> Create New Zone</h5>
        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#createZoneForm">
            <i class="fas fa-plus"></i> New Zone
        </button>
    </div>
    <div class="collapse" id="createZoneForm">
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="create_zone">
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="website_id" class="form-label">Website *</label>
                        <select class="form-select" id="website_id" name="website_id" required>
                            <option value="">Select Website</option>
                            <?php foreach ($websites as $website): ?>
                                <option value="<?php echo $website['id']; ?>">
                                    <?php echo htmlspecialchars($website['name']); ?> 
                                    (<?php echo htmlspecialchars($website['domain']); ?>) - 
                                    Publisher: <?php echo htmlspecialchars($website['publisher_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="name" class="form-label">Zone Name *</label>
                        <input type="text" class="form-control" id="name" name="name" required>
                        <div class="form-text">A descriptive name to identify this zone (e.g., "Homepage Leaderboard")</div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label for="size" class="form-label">Size *</label>
                        <select class="form-select" id="size" name="size" required>
                            <option value="">Select Size</option>
                            <?php foreach ($sizes as $value => $label): ?>
                                <option value="<?php echo $value; ?>">
                                    <?php echo htmlspecialchars($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="zone_type" class="form-label">Zone Type</label>
                        <select class="form-select" id="zone_type" name="zone_type">
                            <option value="banner">Banner</option>
                            <option value="video">Video</option>
                            <option value="native">Native</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Create Zone
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Zones List -->
<div class="card">
    <div class="card-header">
        <h5 class="mb-0"><i class="fas fa-list"></i> Ad Zones</h5>
    </div>
    <div class="card-body">
        <?php if (empty($zones)): ?>
            <div class="text-center py-4">
                <i class="fas fa-th fa-3x text-muted mb-3"></i>
                <p class="text-muted">No zones created yet.</p>
                <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#createZoneForm">
                    <i class="fas fa-plus"></i> Create Your First Zone
                </button>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Zone</th>
                            <th>Website</th>
                            <th>Publisher</th>
                            <th>Size</th>
                            <th>Performance</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($zones as $zone): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($zone['name']); ?></strong>
                                <br>
                                <small class="text-muted">ID: <?php echo $zone['id']; ?></small>
                            </td>
                            <td>
                                <span><?php echo htmlspecialchars($zone['website_name']); ?></span>
                                <br>
                                <small class="text-muted"><?php echo htmlspecialchars($zone['domain']); ?></small>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($zone['publisher_name']); ?>
                            </td>
                            <td>
                                <span class="badge bg-secondary"><?php echo htmlspecialchars($zone['size']); ?></span>
                                <br>
                                <small class="text-muted"><?php echo ucfirst($zone['zone_type']); ?></small>
                            </td>
                            <td>
                                <div class="small">
                                    <div><strong><?php echo formatNumber($zone['total_impressions'] ?? 0); ?></strong> impressions</div>
                                    <div><strong><?php echo formatNumber($zone['total_clicks'] ?? 0); ?></strong> clicks</div>
                                    <?php if ($zone['total_impressions'] > 0): ?>
                                        <div class="text-muted">
                                            CTR: <?php echo number_format(($zone['total_clicks'] / $zone['total_impressions']) * 100, 2); ?>%
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        Revenue: <?php echo formatCurrency($zone['total_revenue'] ?? 0); ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-<?php echo $zone['status'] == 'active' ? 'success' : 'warning'; ?>">
                                    <?php echo ucfirst($zone['status']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <a href="?code=<?php echo $zone['id']; ?>" class="btn btn-outline-primary" data-bs-toggle="tooltip" title="Get Code">
                                        <i class="fas fa-code"></i>
                                    </a>
                                    <a href="?toggle_status=<?php echo $zone['id']; ?>" class="btn btn-outline-warning" data-bs-toggle="tooltip" 
                                       title="<?php echo $zone['status'] == 'active' ? 'Deactivate' : 'Activate'; ?>">
                                        <i class="fas fa-<?php echo $zone['status'] == 'active' ? 'pause' : 'play'; ?>"></i>
                                    </a>
                                    <a href="#" class="btn btn-outline-success" data-bs-toggle="tooltip" title="Stats">
                                        <i class="fas fa-chart-bar"></i>
                                    </a>
                                    <a href="#" class="btn btn-outline-info" data-bs-toggle="tooltip" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="?delete=<?php echo $zone['id']; ?>" class="btn btn-outline-danger btn-delete" data-bs-toggle="tooltip" title="Delete"
                                       onclick="return confirm('Are you sure you want to delete this zone? This cannot be undone.')">
                                        <i class="fas fa-trash"></i>
                                    </a>
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

<!-- Zone Code Modal -->
<?php if ($zone_details): ?>
<div class="modal fade" id="zoneCodeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    Ad Zone Code: <?php echo htmlspecialchars($zone_details['name']); ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <p>
                        <strong>Zone Details:</strong><br>
                        Website: <?php echo htmlspecialchars($zone_details['website_name']); ?> (<?php echo htmlspecialchars($zone_details['domain']); ?>)<br>
                        Size: <?php echo htmlspecialchars($zone_details['size']); ?><br>
                        Type: <?php echo ucfirst($zone_details['zone_type']); ?><br>
                        Status: <?php echo ucfirst($zone_details['status']); ?>
                    </p>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> Copy the code below and paste it on your website where you want this ad to appear.
                    </div>
                </div>
                <div class="form-group">
                    <label for="zoneCode">Integration Code:</label>
                    <textarea class="form-control font-monospace" id="zoneCode" rows="8" readonly><?php echo htmlspecialchars($zone_code); ?></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="copyZoneCode()">
                    <i class="fas fa-copy"></i> Copy Code
                </button>
            </div>
        </div>
    </div>
</div>
<script>
    // Automatically open the modal when the page loads
    document.addEventListener('DOMContentLoaded', function() {
        var zoneCodeModal = new bootstrap.Modal(document.getElementById('zoneCodeModal'));
        zoneCodeModal.show();
    });
    
    // Function to copy the zone code
    function copyZoneCode() {
        var codeElem = document.getElementById('zoneCode');
        codeElem.select();
        document.execCommand('copy');
        
        // Show success message
        alert('Code copied to clipboard!');
    }
</script>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    });
});
</script>

<?php 
// End output buffering and send the output
ob_end_flush();
include 'includes/footer.php'; 
?>