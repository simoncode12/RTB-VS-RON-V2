<?php
/**
 * RTB Endpoint Management System
 * Updated: 2025-06-25 10:35:37
 * Current User: simoncode12
 * 
 * Added new features:
 * - Platform revenue share settings
 * - Ability to toggle revenue adjustment
 */

// Start output buffering to allow header redirects after content
ob_start();

include 'includes/header.php';

// Initialize variables
$message = '';
$message_type = 'success';
$edit_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
$selected_endpoint = null;

// Get publishers list
$publishers = $pdo->query("
    SELECT id, name, status
    FROM publishers
    WHERE status = 'active'
    ORDER BY name
")->fetchAll();

// Get websites list
$websites = $pdo->query("
    SELECT w.*, p.name as publisher_name,
           COUNT(z.id) as zone_count
    FROM websites w 
    LEFT JOIN publishers p ON w.publisher_id = p.id 
    LEFT JOIN zones z ON w.id = z.website_id AND z.status = 'active'
    WHERE w.status = 'active' 
    GROUP BY w.id
    ORDER BY w.name
")->fetchAll();

// Get zones list
$zones = $pdo->query("
    SELECT z.*, w.name as website_name, w.domain, p.name as publisher_name
    FROM zones z 
    LEFT JOIN websites w ON z.website_id = w.id 
    LEFT JOIN publishers p ON w.publisher_id = p.id 
    WHERE z.status = 'active' AND w.status = 'active'
    ORDER BY w.name, z.name
")->fetchAll();

// Get categories from database - using the actual categories table structure
$categories = [];
try {
    // Based on the database screenshot, using the actual table structure
    $categories_result = $pdo->query("
        SELECT id, name, description, type, status
        FROM categories
        WHERE status = 'active'
        ORDER BY id
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Format categories for the form - fixing the array offset issue
    if ($categories_result) {
        foreach ($categories_result as $cat) {
            $categories[$cat['id']] = [
                'name' => $cat['name'] ?? 'Unknown',
                'description' => $cat['description'] ?? '',
                'type' => $cat['type'] ?? 'mainstream'
            ];
        }
    }
} catch (Exception $e) {
    // If there's an error, log it
    error_log("Error fetching categories: " . $e->getMessage());
}

// Get existing RTB endpoints
$endpoints_query = "
    SELECT e.*, p.name as publisher_name, p.revenue_share as publisher_revenue_share
    FROM rtb_endpoints e
    LEFT JOIN publishers p ON e.publisher_id = p.id
    ORDER BY e.status DESC, e.created_at DESC
";

try {
    $endpoints = $pdo->query($endpoints_query)->fetchAll();
} catch (Exception $e) {
    $endpoints = [];
    error_log("Error fetching endpoints: " . $e->getMessage());
    $message = "Error loading endpoints: " . $e->getMessage();
    $message_type = "danger";
}

// If editing, get the endpoint data
if ($edit_id > 0) {
    $stmt = $pdo->prepare("
        SELECT * FROM rtb_endpoints WHERE id = ?
    ");
    $stmt->execute([$edit_id]);
    $selected_endpoint = $stmt->fetch();
    
    if ($selected_endpoint) {
        // Decode JSON fields
        $selected_endpoint['formats'] = json_decode($selected_endpoint['formats'], true) ?? [];
        $selected_endpoint['categories'] = json_decode($selected_endpoint['categories'], true) ?? [];
        $selected_endpoint['websites'] = json_decode($selected_endpoint['websites'], true) ?? [];
        $selected_endpoint['zones'] = json_decode($selected_endpoint['zones'], true) ?? [];
    }
}

// Handle DELETE action
if (isset($_GET['delete']) && !empty($_GET['delete'])) {
    $delete_id = intval($_GET['delete']);
    
    try {
        $stmt = $pdo->prepare("DELETE FROM rtb_endpoints WHERE id = ?");
        $stmt->execute([$delete_id]);
        
        $_SESSION['message'] = "Endpoint deleted successfully!";
        $_SESSION['message_type'] = "success";
        
        // Use JavaScript for redirection instead of header()
        echo '<script>window.location.href = "rtb-buy.php?msg=deleted";</script>';
        exit;
    } catch (Exception $e) {
        $message = "Error deleting endpoint: " . $e->getMessage();
        $message_type = "danger";
    }
}

// Handle PAUSE/ACTIVATE action
if (isset($_GET['toggle']) && !empty($_GET['toggle'])) {
    $toggle_id = intval($_GET['toggle']);
    
    try {
        // Get current status
        $stmt = $pdo->prepare("SELECT status FROM rtb_endpoints WHERE id = ?");
        $stmt->execute([$toggle_id]);
        $current_status = $stmt->fetchColumn();
        
        // Toggle status
        $new_status = ($current_status == 'active') ? 'paused' : 'active';
        
        $stmt = $pdo->prepare("UPDATE rtb_endpoints SET status = ? WHERE id = ?");
        $stmt->execute([$new_status, $toggle_id]);
        
        $action = ($new_status == 'active') ? 'activated' : 'paused';
        $_SESSION['message'] = "Endpoint {$action} successfully!";
        $_SESSION['message_type'] = "success";
        
        // Use JavaScript for redirection instead of header()
        echo '<script>window.location.href = "rtb-buy.php?msg=' . $action . '";</script>';
        exit;
    } catch (Exception $e) {
        $message = "Error updating endpoint status: " . $e->getMessage();
        $message_type = "danger";
    }
}

// Handle form submission for creating/updating endpoints
if (isset($_POST['submit_endpoint'])) {
    // Collect form data
    $name = $_POST['endpoint_name'] ?? '';
    $publisher_id = $_POST['publisher_id'] ?? 0;
    $formats = isset($_POST['formats']) ? $_POST['formats'] : [];
    $categories = isset($_POST['categories']) ? $_POST['categories'] : [];
    $selected_websites = isset($_POST['websites']) ? $_POST['websites'] : [];
    $selected_zones = isset($_POST['zones']) ? $_POST['zones'] : [];
    $daily_budget = isset($_POST['daily_budget']) ? floatval($_POST['daily_budget']) : 0.00;
    $allow_popunder = isset($_POST['allow_popunder']) ? 1 : 0;
    $description = $_POST['description'] ?? '';
    
    // New fields for revenue share settings
    $platform_share = isset($_POST['platform_share']) ? floatval($_POST['platform_share']) : 65.00;
    $apply_revenue_adjustment = isset($_POST['apply_revenue_adjustment']) ? 1 : 0;

    // Validate data
    $errors = [];
    
    if (empty($name)) {
        $errors[] = "Endpoint name is required";
    }
    
    if (empty($publisher_id)) {
        $errors[] = "Publisher selection is required";
    }
    
    if (empty($formats)) {
        $errors[] = "At least one ad format must be selected";
    }
    
    if ($platform_share < 0 || $platform_share > 100) {
        $errors[] = "Platform share percentage must be between 0 and 100";
    }
    
    // Process if no errors
    if (empty($errors)) {
        try {
            // Prepare data for insertion/update
            $formats_json = json_encode($formats);
            $categories_json = json_encode($categories);
            $websites_json = json_encode($selected_websites);
            $zones_json = json_encode($selected_zones);
            
            // Generate endpoint key if creating new endpoint
            $endpoint_key = $edit_id ? $selected_endpoint['endpoint_key'] : bin2hex(random_bytes(16));
            
            if ($edit_id > 0) {
                // Update existing endpoint
                $stmt = $pdo->prepare("
                    UPDATE rtb_endpoints SET
                        name = ?,
                        publisher_id = ?,
                        formats = ?,
                        categories = ?,
                        websites = ?,
                        zones = ?,
                        daily_budget = ?,
                        allow_popunder = ?,
                        description = ?,
                        platform_share = ?,
                        apply_revenue_adjustment = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                
                $stmt->execute([
                    $name,
                    $publisher_id,
                    $formats_json,
                    $categories_json,
                    $websites_json,
                    $zones_json,
                    $daily_budget,
                    $allow_popunder,
                    $description,
                    $platform_share,
                    $apply_revenue_adjustment,
                    $edit_id
                ]);
                
                $_SESSION['message'] = "Endpoint updated successfully!";
                $_SESSION['message_type'] = "success";
                
                // Use JavaScript for redirection instead of header()
                echo '<script>window.location.href = "rtb-buy.php?msg=updated";</script>';
                exit;
            } else {
                // Create new endpoint
                $stmt = $pdo->prepare("
                    INSERT INTO rtb_endpoints (
                        name, endpoint_key, publisher_id, formats, categories, 
                        websites, zones, daily_budget, allow_popunder,
                        description, platform_share, apply_revenue_adjustment, 
                        status, created_at, updated_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, 
                        ?, ?, ?, ?, 
                        ?, ?, ?,
                        'active', NOW(), NOW()
                    )
                ");
                
                $stmt->execute([
                    $name,
                    $endpoint_key,
                    $publisher_id,
                    $formats_json,
                    $categories_json,
                    $websites_json,
                    $zones_json,
                    $daily_budget,
                    $allow_popunder,
                    $description,
                    $platform_share,
                    $apply_revenue_adjustment
                ]);
                
                $message = "Endpoint created successfully!";
                $message_type = "success";
            }
            
            // Refresh endpoints list
            $endpoints = $pdo->query($endpoints_query)->fetchAll();
            
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
            $message_type = "danger";
        }
    } else {
        $message = "Please correct the following errors:<br>" . implode("<br>", $errors);
        $message_type = "danger";
    }
}

// Show message from redirect
if (isset($_GET['msg'])) {
    switch ($_GET['msg']) {
        case 'deleted':
            $message = "Endpoint deleted successfully!";
            $message_type = "success";
            break;
        case 'activated':
            $message = "Endpoint activated successfully!";
            $message_type = "success";
            break;
        case 'paused':
            $message = "Endpoint paused successfully!";
            $message_type = "success";
            break;
        case 'updated':
            $message = "Endpoint updated successfully!";
            $message_type = "success";
            break;
    }
}

// Check if we have stored messages in session
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'] ?? 'success';
    
    // Clear the message from session
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

// Add the necessary ALTER TABLE if it doesn't exist yet
try {
    // Check if columns exist
    $check_column = $pdo->query("SHOW COLUMNS FROM rtb_endpoints LIKE 'platform_share'");
    if ($check_column->rowCount() == 0) {
        // Add the columns if they don't exist
        $pdo->exec("ALTER TABLE rtb_endpoints ADD COLUMN platform_share DECIMAL(5,2) DEFAULT 65.00");
        $pdo->exec("ALTER TABLE rtb_endpoints ADD COLUMN apply_revenue_adjustment TINYINT(1) DEFAULT 1");
    }
} catch (Exception $e) {
    error_log("Error checking/adding columns: " . $e->getMessage());
}
?>

<div class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">
            <i class="fas fa-exchange-alt"></i> RTB Traffic Buying
        </h1>
        
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#endpointModal">
            <i class="fas fa-plus"></i> Create RTB Endpoint
        </button>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <div class="row">
        <!-- RTB Endpoints Table -->
        <div class="col-xl-8 col-lg-7">
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-link"></i> RTB Endpoints</h5>
                    <span class="badge bg-primary"><?php echo count($endpoints); ?> Total</span>
                </div>
                <div class="card-body">
                    <?php if (empty($endpoints)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-link fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No RTB endpoints have been created yet.</p>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#endpointModal">
                                <i class="fas fa-plus"></i> Create Your First RTB Endpoint
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover border">
                                <thead class="table-light">
                                    <tr>
                                        <th>Name</th>
                                        <th>Publisher</th>
                                        <th>Formats</th>
                                        <th>Revenue Share</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($endpoints as $endpoint): ?>
                                        <?php 
                                            $formats = json_decode($endpoint['formats'], true) ?: []; 
                                            $formats_display = [];
                                            foreach ($formats as $format) {
                                                switch ($format) {
                                                    case 'banner': $formats_display[] = '<span class="badge bg-info">Banner</span>'; break;
                                                    case 'native': $formats_display[] = '<span class="badge bg-success">Native</span>'; break;
                                                    case 'video': $formats_display[] = '<span class="badge bg-danger">Video</span>'; break;
                                                    case 'mobile': $formats_display[] = '<span class="badge bg-warning">Mobile</span>'; break;
                                                }
                                            }
                                            
                                            if ($endpoint['allow_popunder']) {
                                                $formats_display[] = '<span class="badge bg-dark">Popunder</span>';
                                            }

                                            // Platform share default
                                            $platform_share = isset($endpoint['platform_share']) ? (float)$endpoint['platform_share'] : 65.00;
                                            $publisher_share = isset($endpoint['publisher_revenue_share']) ? (float)$endpoint['publisher_revenue_share'] : 50.00;
                                            $apply_adjustment = isset($endpoint['apply_revenue_adjustment']) ? (bool)$endpoint['apply_revenue_adjustment'] : true;
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($endpoint['name']); ?></strong>
                                                <?php if (!empty($endpoint['description'])): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars(substr($endpoint['description'], 0, 50)) . (strlen($endpoint['description']) > 50 ? '...' : ''); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($endpoint['publisher_name'] ?? 'Unknown'); ?>
                                                <div class="small text-muted">
                                                    Budget: $<?php echo number_format($endpoint['daily_budget'], 2); ?>/day
                                                </div>
                                            </td>
                                            <td>
                                                <?php echo implode(' ', $formats_display); ?>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="me-2 position-relative" style="width: 40px; height: 40px;">
                                                        <div class="position-absolute top-0 start-0 w-100 h-100 rounded-circle" style="border: 3px solid #dee2e6;"></div>
                                                        <div class="position-absolute top-0 start-0 w-100 h-100 rounded-circle" 
                                                             style="border: 3px solid #0d6efd; border-right-color: transparent; transform: rotate(<?php echo $platform_share * 3.6; ?>deg);"></div>
                                                        <div class="position-absolute top-50 start-50 translate-middle small fw-bold">
                                                            <?php echo number_format($platform_share, 0); ?>%
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <div class="small fw-bold">Platform: <?php echo number_format($platform_share, 1); ?>%</div>
                                                        <div class="small text-muted">Publisher: <?php echo number_format($publisher_share, 1); ?>%</div>
                                                        <?php if (!$apply_adjustment): ?>
                                                            <span class="badge bg-warning">Adjustment Off</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($endpoint['status'] == 'active'): ?>
                                                    <span class="badge bg-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Paused</span>
                                                <?php endif; ?>
                                                <br>
                                                <small class="text-muted"><?php echo date('M j, Y', strtotime($endpoint['created_at'])); ?></small>
                                            </td>
                                            <td>
                                                <div class="btn-group">
                                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="copyEndpoint('<?php echo htmlspecialchars($endpoint['endpoint_key']); ?>')">
                                                        <i class="fas fa-copy"></i>
                                                    </button>
                                                    <a href="?edit=<?php echo $endpoint['id']; ?>" class="btn btn-sm btn-outline-secondary">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <a href="javascript:toggleEndpoint(<?php echo $endpoint['id']; ?>, '<?php echo $endpoint['status']; ?>')" class="btn btn-sm <?php echo $endpoint['status'] == 'active' ? 'btn-outline-warning' : 'btn-outline-success'; ?>">
                                                        <i class="fas <?php echo $endpoint['status'] == 'active' ? 'fa-pause' : 'fa-play'; ?>"></i>
                                                    </a>
                                                    <a href="javascript:deleteEndpoint(<?php echo $endpoint['id']; ?>, '<?php echo addslashes(htmlspecialchars($endpoint['name'])); ?>')" class="btn btn-sm btn-outline-danger">
                                                        <i class="fas fa-trash-alt"></i>
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
        </div>
        
        <!-- RTB Information Panel -->
        <div class="col-xl-4 col-lg-5">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-info-circle"></i> RTB Traffic Buying</h5>
                </div>
                <div class="card-body">
                    <div class="mb-4">
                        <h6><i class="fas fa-question-circle"></i> What is RTB?</h6>
                        <p class="small">Real-Time Bidding (RTB) allows you to buy ad traffic on a per-impression basis through real-time auctions. With our system, you can create custom RTB endpoints to connect with advertising platforms and bid on traffic automatically.</p>
                    </div>
                    
                    <div class="mb-4">
                        <h6><i class="fas fa-magic"></i> How to Use RTB Endpoints</h6>
                        <ol class="small">
                            <li>Create an endpoint with your desired settings</li>
                            <li>Copy the endpoint URL and provide it to your advertising partner</li>
                            <li>Monitor traffic and performance in your dashboard</li>
                            <li>Adjust settings as needed to optimize ROI</li>
                        </ol>
                    </div>
                    
                    <div class="mb-4">
                        <h6><i class="fas fa-percentage"></i> Revenue Share Explained</h6>
                        <p class="small">The platform share percentage determines how much of the original bid amount is used for pricing in RTB auctions:</p>
                        <ul class="small">
                            <li><strong>Platform Share 65%</strong>: If external RTB partner bids $0.01, our system will use $0.0065 as the bid price</li>
                            <li><strong>Publisher Share</strong>: Determined in publisher settings (usually 50%), applies to publisher payments</li>
                            <li><strong>Adjustment Toggle</strong>: When disabled, passes original bid price without adjustment</li>
                        </ul>
                        <div class="alert alert-info small">
                            <i class="fas fa-lightbulb"></i> <strong>Pro Tip:</strong> Set platform share to protect your margins while remaining competitive in RTB auctions.
                        </div>
                    </div>
                    
                    <div>
                        <h6><i class="fas fa-code"></i> Technical Integration</h6>
                        <p class="small">Our RTB system is compatible with OpenRTB 2.5 specification. Partners can integrate with your endpoints using standard RTB protocols for seamless bidding and ad delivery.</p>
                    </div>
                </div>
            </div>
            
            <!-- RTB Performance Summary -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-chart-bar"></i> Performance Summary</h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-6 mb-3">
                            <div class="h5 mb-0">$<?php 
                                $total_budget = 0;
                                foreach ($endpoints as $endpoint) {
                                    $total_budget += floatval($endpoint['daily_budget'] ?? 0);
                                }
                                echo number_format($total_budget, 2); 
                            ?></div>
                            <div class="small text-muted">Daily Budget</div>
                        </div>
                        <div class="col-6 mb-3">
                            <div class="h5 mb-0"><?php 
                                $active_count = 0;
                                foreach ($endpoints as $endpoint) {
                                    if ($endpoint['status'] == 'active') $active_count++;
                                }
                                echo $active_count; 
                            ?></div>
                            <div class="small text-muted">Active Endpoints</div>
                        </div>
                        <div class="col-6">
                            <div class="h5 mb-0">
                                <?php
                                    $formats_count = [];
                                    foreach ($endpoints as $endpoint) {
                                        $endpoint_formats = json_decode($endpoint['formats'], true) ?: [];
                                        foreach ($endpoint_formats as $format) {
                                            $formats_count[$format] = ($formats_count[$format] ?? 0) + 1;
                                        }
                                    }
                                    echo array_sum($formats_count);
                                ?>
                            </div>
                            <div class="small text-muted">Format Connections</div>
                        </div>
                        <div class="col-6">
                            <div class="h5 mb-0"><?php 
                                $unique_publishers = [];
                                foreach ($endpoints as $endpoint) {
                                    if (!empty($endpoint['publisher_id'])) {
                                        $unique_publishers[$endpoint['publisher_id']] = true;
                                    }
                                }
                                echo count($unique_publishers);
                            ?></div>
                            <div class="small text-muted">Publishers</div>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="text-center">
                        <a href="rtb-analytics.php" class="btn btn-sm btn-primary">
                            <i class="fas fa-chart-line"></i> View Detailed Analytics
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Endpoint Creation/Edit Modal -->
<div class="modal fade" id="endpointModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <?php echo $edit_id ? 'Edit RTB Endpoint' : 'Create New RTB Endpoint'; ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="endpointForm">
                    <ul class="nav nav-tabs mb-3" id="endpointTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="basic-tab" data-bs-toggle="tab" data-bs-target="#basic-content" type="button" role="tab" aria-controls="basic-content" aria-selected="true">Basic Settings</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="targeting-tab" data-bs-toggle="tab" data-bs-target="#targeting-content" type="button" role="tab" aria-controls="targeting-content" aria-selected="false">Targeting</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="revenue-tab" data-bs-toggle="tab" data-bs-target="#revenue-content" type="button" role="tab" aria-controls="revenue-content" aria-selected="false">Revenue Share</button>
                        </li>
                    </ul>
                    
                    <div class="tab-content" id="endpointTabsContent">
                        <!-- Basic Settings Tab -->
                        <div class="tab-pane fade show active" id="basic-content" role="tabpanel" aria-labelledby="basic-tab">
                            <!-- Endpoint Name -->
                            <div class="mb-3">
                                <label for="endpoint_name" class="form-label required">Endpoint Name</label>
                                <input type="text" class="form-control" id="endpoint_name" name="endpoint_name" required
                                       value="<?php echo $selected_endpoint ? htmlspecialchars($selected_endpoint['name']) : ''; ?>">
                                <div class="form-text">Give your endpoint a descriptive name for easy identification.</div>
                            </div>
                            
                            <!-- Publisher Selection -->
                            <div class="mb-3">
                                <label for="publisher_id" class="form-label required">Select Publisher</label>
                                <select class="form-select" id="publisher_id" name="publisher_id" required>
                                    <option value="">-- Select Publisher --</option>
                                    <?php foreach ($publishers as $publisher): ?>
                                        <option value="<?php echo $publisher['id']; ?>" <?php echo ($selected_endpoint && $selected_endpoint['publisher_id'] == $publisher['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($publisher['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Select the publisher who will receive traffic from this endpoint.</div>
                            </div>
                            
                            <!-- Ad Formats -->
                            <div class="mb-4">
                                <label class="form-label required">Ad Formats</label>
                                <div class="row">
                                    <div class="col-6 col-md-3 mb-2">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="format_banner" name="formats[]" value="banner"
                                                   <?php echo ($selected_endpoint && in_array('banner', $selected_endpoint['formats'])) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="format_banner">
                                                <i class="fas fa-image"></i> Banner
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-3 mb-2">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="format_video" name="formats[]" value="video"
                                                   <?php echo ($selected_endpoint && in_array('video', $selected_endpoint['formats'])) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="format_video">
                                                <i class="fas fa-video"></i> Video
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-3 mb-2">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="format_native" name="formats[]" value="native"
                                                   <?php echo ($selected_endpoint && in_array('native', $selected_endpoint['formats'])) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="format_native">
                                                <i class="fas fa-newspaper"></i> Native
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-3 mb-2">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="format_mobile" name="formats[]" value="mobile"
                                                   <?php echo ($selected_endpoint && in_array('mobile', $selected_endpoint['formats'])) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="format_mobile">
                                                <i class="fas fa-mobile-alt"></i> Mobile
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-12 mt-2">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="allow_popunder" name="allow_popunder" value="1"
                                                   <?php echo ($selected_endpoint && $selected_endpoint['allow_popunder']) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="allow_popunder">
                                                <i class="fas fa-external-link-alt"></i> Allow Popunder
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-text">Select which ad formats you want to support with this endpoint.</div>
                            </div>
                            
                            <!-- Daily Budget -->
                            <div class="mb-3">
                                <label for="daily_budget" class="form-label">Daily Budget ($)</label>
                                <input type="number" class="form-control" id="daily_budget" name="daily_budget" step="0.01" min="0"
                                       value="<?php echo $selected_endpoint ? htmlspecialchars($selected_endpoint['daily_budget']) : '10.00'; ?>">
                                <div class="form-text">Set the maximum daily budget for this endpoint (0 = unlimited).</div>
                            </div>
                            
                            <!-- Description -->
                            <div class="mb-3">
                                <label for="description" class="form-label">Description (Optional)</label>
                                <textarea class="form-control" id="description" name="description" rows="3"><?php echo $selected_endpoint ? htmlspecialchars($selected_endpoint['description']) : ''; ?></textarea>
                                <div class="form-text">Add notes about this endpoint for future reference.</div>
                            </div>
                        </div>
                        
                        <!-- Targeting Tab -->
                        <div class="tab-pane fade" id="targeting-content" role="tabpanel" aria-labelledby="targeting-tab">
                            <!-- Categories -->
                            <div class="mb-4">
                                <label class="form-label">Categories (Optional)</label>
                                <div style="max-height: 150px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 0.375rem; padding: 10px;">
                                    <div class="row">
                                        <?php foreach ($categories as $id => $category): ?>
                                            <div class="col-md-4 mb-1">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="cat_<?php echo $id; ?>" 
                                                           name="categories[]" value="<?php echo $id; ?>"
                                                           <?php echo ($selected_endpoint && in_array($id, $selected_endpoint['categories'] ?? [])) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label small" for="cat_<?php echo $id; ?>" 
                                                           title="<?php echo htmlspecialchars($category['description'] ?? ''); ?>">
                                                        <?php echo htmlspecialchars($category['name'] ?? "Category $id"); ?>
                                                        <?php if (isset($category['type']) && $category['type'] == 'adult'): ?>
                                                            <span class="badge bg-danger">Adult</span>
                                                        <?php endif; ?>
                                                    </label>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="form-text">Select categories to target (leave empty to target all categories).</div>
                            </div>
                            
                            <!-- Targeting Options -->
                            <div class="row">
                                <!-- Target Websites -->
                                <div class="col-md-6 mb-4">
                                    <label class="form-label">Target Websites (Optional)</label>
                                    <div style="max-height: 200px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 0.375rem; padding: 10px;">
                                        <?php foreach ($websites as $website): ?>
                                            <div class="form-check mb-1">
                                                <input class="form-check-input" type="checkbox" 
                                                       id="website_<?php echo $website['id']; ?>" 
                                                       name="websites[]" value="<?php echo $website['id']; ?>"
                                                       <?php echo ($selected_endpoint && in_array($website['id'], $selected_endpoint['websites'] ?? [])) ? 'checked' : ''; ?>>
                                                <label class="form-check-label small" for="website_<?php echo $website['id']; ?>">
                                                    <strong><?php echo htmlspecialchars($website['name']); ?></strong>
                                                    <br>
                                                    <span class="text-muted">
                                                        <?php echo htmlspecialchars($website['domain']); ?> 
                                                        (<?php echo htmlspecialchars($website['publisher_name'] ?? 'Unknown'); ?>)
                                                    </span>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="form-text">Select specific websites to target (leave empty for all).</div>
                                </div>
                                
                                <!-- Target Zones -->
                                <div class="col-md-6 mb-4">
                                    <label class="form-label">Target Zones (Optional)</label>
                                    <div style="max-height: 200px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 0.375rem; padding: 10px;">
                                        <?php foreach ($zones as $zone): ?>
                                            <div class="form-check mb-1">
                                                <input class="form-check-input" type="checkbox" 
                                                       id="zone_<?php echo $zone['id']; ?>" 
                                                       name="zones[]" value="<?php echo $zone['id']; ?>"
                                                       <?php echo ($selected_endpoint && in_array($zone['id'], $selected_endpoint['zones'] ?? [])) ? 'checked' : ''; ?>>
                                                <label class="form-check-label small" for="zone_<?php echo $zone['id']; ?>">
                                                    <strong><?php echo htmlspecialchars($zone['name']); ?></strong>
                                                    <span class="badge bg-info"><?php echo $zone['size']; ?></span>
                                                    <br>
                                                    <span class="text-muted">
                                                        <?php echo htmlspecialchars($zone['website_name'] ?? 'Unknown'); ?>
                                                    </span>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="form-text">Select specific zones to target (leave empty for all).</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Revenue Share Tab (New) -->
                        <div class="tab-pane fade" id="revenue-content" role="tabpanel" aria-labelledby="revenue-tab">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Revenue share settings determine how our platform handles bids from external RTB partners.
                            </div>
                            
                            <!-- Platform Share -->
                            <div class="mb-4">
                                <label for="platform_share" class="form-label">Platform Share (%)</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" id="platform_share" name="platform_share"
                                           value="<?php echo isset($selected_endpoint['platform_share']) ? number_format($selected_endpoint['platform_share'], 2) : '65.00'; ?>"
                                           min="0" max="100" step="0.01">
                                    <span class="input-group-text">%</span>
                                </div>
                                <div class="form-text">This percentage of the original bid will be used as the actual bid price. Default: 65%</div>
                                
                                <div class="mt-3">
                                    <div class="position-relative d-inline-block">
                                        <div class="progress" style="width: 300px; height: 24px;">
                                            <div class="progress-bar" id="platformShareBar" role="progressbar" style="width: 65%;"
                                                aria-valuemin="0" aria-valuemax="100">65%</div>
                                        </div>
                                        <div class="progress-bar bg-success" style="width: 300px; height: 8px; position: absolute; bottom: -8px;">
                                            <div class="d-flex justify-content-between position-absolute" style="width: 100%;">
                                                <small class="position-absolute" style="left: 0; bottom: -20px;">0%</small>
                                                <small class="position-absolute" style="left: 50%; bottom: -20px; transform: translateX(-50%);">50%</small>
                                                <small class="position-absolute" style="right: 0; bottom: -20px;">100%</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="apply_revenue_adjustment" name="apply_revenue_adjustment" value="1"
                                           <?php echo (!isset($selected_endpoint['apply_revenue_adjustment']) || $selected_endpoint['apply_revenue_adjustment']) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="apply_revenue_adjustment">
                                        Apply Revenue Adjustment
                                    </label>
                                </div>
                                <div class="form-text">When enabled, the platform share percentage will be applied to the original bid price. If disabled, the original bid price will be used.</div>
                            </div>
                            
                            <div class="card mt-4">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0">Bid Calculation Example</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <p class="mb-2"><strong>Original Bid from Partner:</strong> $0.01 CPM</p>
                                            <p class="mb-2"><strong>Platform Share:</strong> <span id="examplePlatformShare">65</span>%</p>
                                            <p class="mb-2"><strong>Publisher Revenue Share:</strong> 50% (set in publisher settings)</p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mb-2"><strong>Adjusted Bid:</strong> $<span id="exampleAdjustedBid">0.0065</span> CPM</p>
                                            <p class="mb-2"><strong>Platform Revenue:</strong> $<span id="examplePlatformRevenue">0.0033</span></p>
                                            <p class="mb-2"><strong>Publisher Payment:</strong> $<span id="examplePublisherRevenue">0.0033</span></p>
                                        </div>
                                    </div>
                                    <div class="mt-3 alert alert-warning small mb-0">
                                        <i class="fas fa-exclamation-triangle"></i> <strong>Note:</strong> Platform share directly affects competitiveness. Lower values may win more auctions but reduce margins.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="endpointForm" name="submit_endpoint" class="btn btn-primary">
                    <?php echo $edit_id ? 'Update Endpoint' : 'Create Endpoint'; ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Confirmation Dialogs -->
<div class="modal fade" id="confirmationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirmationTitle">Confirm Action</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="confirmationBody">
                Are you sure you want to proceed?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="#" id="confirmationAction" class="btn btn-danger">Proceed</a>
            </div>
        </div>
    </div>
</div>

<script>
// Handle automatic modal open when editing
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($edit_id > 0 && $selected_endpoint): ?>
    var endpointModal = new bootstrap.Modal(document.getElementById('endpointModal'));
    endpointModal.show();
    <?php endif; ?>
    
    // Validate form before submission
    document.getElementById('endpointForm').addEventListener('submit', function(e) {
        var formats = document.querySelectorAll('input[name="formats[]"]:checked');
        if (formats.length === 0) {
            e.preventDefault();
            alert('Please select at least one ad format.');
        }
        
        var publisher = document.getElementById('publisher_id').value;
        if (!publisher) {
            e.preventDefault();
            alert('Please select a publisher.');
        }
        
        var platformShare = parseFloat(document.getElementById('platform_share').value);
        if (isNaN(platformShare) || platformShare < 0 || platformShare > 100) {
            e.preventDefault();
            alert('Platform share must be between 0 and 100%.');
        }
    });
    
    // Select all formats by default for new endpoints
    if (!document.getElementById('endpoint_name').value) {
        document.querySelectorAll('input[name="formats[]"]').forEach(function(checkbox) {
            checkbox.checked = true;
        });
    }
    
    // Platform share visualization
    var platformShareInput = document.getElementById('platform_share');
    var platformShareBar = document.getElementById('platformShareBar');
    var examplePlatformShare = document.getElementById('examplePlatformShare');
    var exampleAdjustedBid = document.getElementById('exampleAdjustedBid');
    var examplePlatformRevenue = document.getElementById('examplePlatformRevenue');
    var examplePublisherRevenue = document.getElementById('examplePublisherRevenue');
    
    function updatePlatformShareVisualization() {
        var value = parseFloat(platformShareInput.value) || 65;
        
        // Update progress bar
        platformShareBar.style.width = value + '%';
        platformShareBar.innerText = value + '%';
        
        // Update example calculations
        examplePlatformShare.innerText = value;
        
        // Calculate adjusted bid: Original Bid * Platform Share %
        var originalBid = 0.01;
        var adjustedBid = originalBid * (value / 100);
        exampleAdjustedBid.innerText = adjustedBid.toFixed(4);
        
        // Calculate revenues
        var publisherShare = 50; // Default publisher share
        var publisherRevenue = adjustedBid * (publisherShare / 100);
        var platformRevenue = adjustedBid - publisherRevenue;
        
        examplePlatformRevenue.innerText = platformRevenue.toFixed(4);
        examplePublisherRevenue.innerText = publisherRevenue.toFixed(4);
    }
    
    platformShareInput.addEventListener('input', updatePlatformShareVisualization);
    
    // Initial update
    updatePlatformShareVisualization();
});

// Function to copy endpoint URL to clipboard
function copyEndpoint(key) {
    const endpointUrl = `https://up.adstart.click/rtb/endpoint.php?key=${key}`;
    navigator.clipboard.writeText(endpointUrl).then(function() {
        // Show toast notification
        showToast('Endpoint URL copied to clipboard!');
    });
}

// Function to delete endpoint with confirmation
function deleteEndpoint(id, name) {
    const modal = new bootstrap.Modal(document.getElementById('confirmationModal'));
    document.getElementById('confirmationTitle').innerText = 'Delete Endpoint';
    document.getElementById('confirmationBody').innerHTML = 
        `Are you sure you want to delete endpoint <strong>${name}</strong>?<br>` +
        `<span class="text-danger">This action cannot be undone.</span>`;
    document.getElementById('confirmationAction').href = `?delete=${id}`;
    document.getElementById('confirmationAction').classList.remove('btn-success');
    document.getElementById('confirmationAction').classList.add('btn-danger');
    document.getElementById('confirmationAction').innerText = 'Delete';
    modal.show();
}

// Function to toggle endpoint status with confirmation
function toggleEndpoint(id, status) {
    const modal = new bootstrap.Modal(document.getElementById('confirmationModal'));
    const newStatus = (status === 'active') ? 'pause' : 'activate';
    const actionClass = (status === 'active') ? 'btn-warning' : 'btn-success';
    const actionText = (status === 'active') ? 'Pause' : 'Activate';
    
    document.getElementById('confirmationTitle').innerText = `${actionText} Endpoint`;
    document.getElementById('confirmationBody').innerHTML = 
        `Are you sure you want to ${newStatus} this endpoint?`;
    document.getElementById('confirmationAction').href = `?toggle=${id}`;
    document.getElementById('confirmationAction').classList.remove('btn-danger', 'btn-success', 'btn-warning');
    document.getElementById('confirmationAction').classList.add(actionClass);
    document.getElementById('confirmationAction').innerText = actionText;
    modal.show();
}

// Simple toast notification
function showToast(message) {
    const toast = document.createElement('div');
    toast.className = 'position-fixed bottom-0 end-0 p-3';
    toast.style.zIndex = 11;
    
    toast.innerHTML = `
        <div class="toast show" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="toast-header">
                <strong class="me-auto">RTB System</strong>
                <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
            <div class="toast-body">
                ${message}
            </div>
        </div>
    `;
    
    document.body.appendChild(toast);
    
    // Auto-remove after 3 seconds
    setTimeout(() => {
        toast.remove();
    }, 3000);
}
</script>

<!-- Add this CSS for form styles -->
<style>
.required:after {
    content: " *";
    color: red;
}

.form-text {
    color: #6c757d;
    font-size: 0.875em;
}

.nav-tabs .nav-link {
    color: #495057;
    background-color: transparent;
    border-color: transparent;
}

.nav-tabs .nav-link.active {
    color: #0d6efd;
    background-color: transparent;
    border-color: transparent transparent #0d6efd transparent;
    border-bottom-width: 2px;
}
</style>

<?php 
include 'includes/footer.php'; 
// End output buffering and flush content
ob_end_flush();
?>