<?php
include 'includes/header.php';

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Current session information
$current_timestamp = '2025-06-25 20:18:03'; // Current UTC time
$current_user = 'simoncode12';              // Current logged in user

$message = '';
$message_type = 'success';
$campaign_id = $_GET['campaign_id'] ?? null;
$is_new_campaign = isset($_GET['new']);

// Get campaign info if campaign_id is provided
$campaign = null;
if ($campaign_id) {
    $stmt = $pdo->prepare("SELECT c.*, a.name as advertiser_name FROM campaigns c LEFT JOIN advertisers a ON c.advertiser_id = a.id WHERE c.id = ?");
    $stmt->execute([$campaign_id]);
    $campaign = $stmt->fetch();
}

// Get all campaigns for dropdown
$campaigns = $pdo->query("
    SELECT c.id, c.name, c.type, a.name as advertiser_name 
    FROM campaigns c 
    LEFT JOIN advertisers a ON c.advertiser_id = a.id 
    WHERE c.status = 'active' 
    ORDER BY c.created_at DESC
")->fetchAll();

// Handle AJAX DELETE requests
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    header('Content-Type: application/json');
    
    $input = json_decode(file_get_contents('php://input'), true);
    $creative_id = $input['id'] ?? null;
    
    if (!$creative_id) {
        echo json_encode(['success' => false, 'message' => 'No creative ID provided']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("DELETE FROM creatives WHERE id = ?");
        $stmt->execute([$creative_id]);
        
        echo json_encode(['success' => true, 'message' => 'Creative deleted successfully']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error deleting creative: ' . $e->getMessage()]);
    }
    exit;
}

// Handle AJAX edit requests
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'edit') {
    // CSRF Protection
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token']);
        exit;
    }
    
    try {
        $creative_id = $_POST['creative_id'] ?? '';
        $name = $_POST['name'] ?? '';
        $width = $_POST['width'] ?? '';
        $height = $_POST['height'] ?? '';
        $bid_amount = $_POST['bid_amount'] ?? '';
        $image_url = $_POST['image_url'] ?? '';
        $video_url = $_POST['video_url'] ?? '';
        $html_content = $_POST['html_content'] ?? '';
        $click_url = $_POST['click_url'] ?? '';
        
        if ($creative_id && $name && $width && $height && $bid_amount && $click_url) {
            $stmt = $pdo->prepare("
                UPDATE creatives SET 
                    name = ?, width = ?, height = ?, bid_amount = ?,
                    image_url = ?, video_url = ?, html_content = ?, click_url = ?
                WHERE id = ?
            ");
            
            $stmt->execute([
                $name, $width, $height, $bid_amount,
                $image_url, $video_url, $html_content, $click_url,
                $creative_id
            ]);
            
            echo "Creative updated successfully!";
        } else {
            echo "Please fill in all required fields.";
        }
    } catch (Exception $e) {
        echo "Error updating creative: " . $e->getMessage();
    }
    exit;
}

if ($_POST) {
    // CSRF Protection
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $message = 'Invalid security token. Please try again.';
        $message_type = 'danger';
    } else {
        try {
        // Sanitize and validate input
        $name = trim($_POST['name'] ?? '');
        $campaign_id_post = intval($_POST['campaign_id'] ?? 0);
        $width = intval($_POST['width'] ?? 0);
        $height = intval($_POST['height'] ?? 0);
        $bid_amount = floatval($_POST['bid_amount'] ?? 0);
        $creative_type = $_POST['creative_type'] ?? 'image';
        $image_url = trim($_POST['image_url'] ?? '');
        $video_url = trim($_POST['video_url'] ?? '');
        $html_content = trim($_POST['html_content'] ?? '');
        $click_url = trim($_POST['click_url'] ?? '');
        
        // Validate required fields
        if (empty($name) || $campaign_id_post <= 0 || $width <= 0 || $height <= 0 || $bid_amount <= 0) {
            $message = 'Please fill in all required fields with valid values.';
            $message_type = 'danger';
        } else {
            // Validate creative type
            $valid_types = ['image', 'video', 'html5', 'third_party', 'banner_rtb', 'video_rtb', 'native_rtb', 'display_rtb'];
            if (!in_array($creative_type, $valid_types)) {
                $message = 'Invalid creative type selected.';
                $message_type = 'danger';
            } else {
                // Validate URLs if provided
                $url_fields = ['image_url' => $image_url, 'video_url' => $video_url, 'click_url' => $click_url];
                foreach ($url_fields as $field => $url) {
                    if (!empty($url) && !filter_var($url, FILTER_VALIDATE_URL)) {
                        $message = 'Please provide a valid URL for ' . str_replace('_', ' ', $field) . '.';
                        $message_type = 'danger';
                        break;
                    }
                }
                
                if (!isset($message)) {
            // Get campaign type
            $stmt = $pdo->prepare("SELECT type, endpoint_url FROM campaigns WHERE id = ?");
            $stmt->execute([$campaign_id_post]);
            $campaign_info = $stmt->fetch();
            $is_rtb_campaign = ($campaign_info['type'] == 'rtb');
            
            // For RTB campaigns with endpoints, we just need placeholder data
            // The actual creative will come from the RTB endpoint response
            if ($is_rtb_campaign && !empty($campaign_info['endpoint_url'])) {
                // Special handling for RTB creatives - using 'third_party' which is a valid enum value
                $stmt = $pdo->prepare("
                    INSERT INTO creatives (
                        campaign_id, name, width, height, bid_amount, creative_type,
                        image_url, video_url, html_content, click_url
                    ) VALUES (?, ?, ?, ?, ?, 'third_party', '', '', 
                    'RTB External Creative - Content will be provided by RTB endpoint',
                    ?)
                ");
                
                $stmt->execute([
                    $campaign_id_post, 
                    $name, 
                    $width, 
                    $height, 
                    $bid_amount,
                    $click_url ?: 'https://rtb.placeholder.url'
                ]);
                
                $message = 'RTB external creative created successfully!';
                $message_type = 'success';
            } else {
                // Regular creative validation for RON or non-endpoint RTB
                $valid_content = false;
                switch ($creative_type) {
                    case 'image':
                    case 'banner_rtb':
                    case 'display_rtb':
                        $valid_content = !empty($image_url);
                        break;
                    case 'video':
                    case 'video_rtb':
                        $valid_content = !empty($video_url);
                        break;
                    case 'html5':
                    case 'third_party':
                    case 'native_rtb':
                        $valid_content = !empty($html_content);
                        break;
                }
                
                if (!$valid_content && !$click_url) {
                    $message = 'Please provide content for the selected creative type and a click URL.';
                    $message_type = 'danger';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO creatives (
                            campaign_id, name, width, height, bid_amount, creative_type,
                            image_url, video_url, html_content, click_url
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                        $campaign_id_post, $name, $width, $height, $bid_amount, $creative_type,
                        $image_url, $video_url, $html_content, $click_url
                    ]);
                    
                    $message = 'Creative created successfully!';
                    $message_type = 'success';
                }
            }
            
            // Update campaign_id for display
            $campaign_id = $campaign_id_post;
            
            // Refresh campaign info
            $stmt = $pdo->prepare("SELECT c.*, a.name as advertiser_name FROM campaigns c LEFT JOIN advertisers a ON c.advertiser_id = a.id WHERE c.id = ?");
            $stmt->execute([$campaign_id]);
            $campaign = $stmt->fetch();
                }
            }
        }
        } catch (Exception $e) {
            $message = 'Error creating creative: ' . $e->getMessage();
            $message_type = 'danger';
        }
    }
}

// Get creatives for the selected campaign
$creatives = [];
if ($campaign_id) {
    $stmt = $pdo->prepare("SELECT * FROM creatives WHERE campaign_id = ? ORDER BY created_at DESC");
    $stmt->execute([$campaign_id]);
    $creatives = $stmt->fetchAll();
}
?>

<div class="row">
    <div class="col-12">
        <h1 class="h3 mb-4">
            <i class="fas fa-images"></i> Creative Management
            <small class="text-muted">Manage campaign creatives and ads</small>
        </h1>
        
        <div class="text-muted mb-3">
            <small>
                <i class="fas fa-clock"></i> Current Time (UTC): <?php echo $current_timestamp; ?> | 
                <i class="fas fa-user"></i> Logged in as: <?php echo htmlspecialchars($current_user); ?>
            </small>
        </div>
        
        <?php if ($campaign && $is_new_campaign): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> Campaign "<strong><?php echo htmlspecialchars($campaign['name']); ?></strong>" was created successfully. Now add creatives for this campaign.
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row">
    <!-- Create New Creative -->
    <div class="col-lg-5 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-plus"></i> Create Creative</h5>
            </div>
            <div class="card-body">
                <form method="POST" id="creativeForm" class="needs-validation" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <div class="mb-3">
                        <label for="campaign_id" class="form-label">Campaign *</label>
                        <select class="form-select" id="campaign_id" name="campaign_id" required>
                            <option value="">Select Campaign</option>
                            <?php foreach ($campaigns as $camp): ?>
                                <option value="<?php echo $camp['id']; ?>" <?php echo ($campaign_id == $camp['id']) ? 'selected' : ''; ?>>
                                    [<?php echo strtoupper($camp['type']); ?>] <?php echo htmlspecialchars($camp['name']); ?> - <?php echo htmlspecialchars($camp['advertiser_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <?php if ($campaign): ?>
                        <div class="alert alert-light">
                            <strong>Selected Campaign:</strong> <?php echo htmlspecialchars($campaign['name']); ?><br>
                            <strong>Type:</strong> <span class="badge bg-<?php echo $campaign['type'] == 'rtb' ? 'primary' : 'success'; ?>"><?php echo strtoupper($campaign['type']); ?></span>
                            <strong>Advertiser:</strong> <?php echo htmlspecialchars($campaign['advertiser_name']); ?>
                        </div>
                        
                        <!-- RTB Campaign with endpoint -->
                        <?php if ($campaign['type'] == 'rtb' && !empty($campaign['endpoint_url'])): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> 
                                <strong>RTB Campaign with External Endpoint</strong><br>
                                This campaign is configured to use an external RTB endpoint:<br>
                                <small><?php echo htmlspecialchars(substr($campaign['endpoint_url'], 0, 60) . (strlen($campaign['endpoint_url']) > 60 ? '...' : '')); ?></small>
                                <hr>
                                <div class="text-muted">
                                    <small>For RTB campaigns, creative content will be provided by the RTB endpoint response.
                                    You only need to configure size and bid parameters.</small>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <label for="name" class="form-label">Creative Name *</label>
                            <input type="text" class="form-control" id="name" name="name" required>
                        </div>
                        
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label for="width" class="form-label">Width *</label>
                                <select class="form-select" id="width" name="width" required onchange="updateHeight()">
                                    <option value="">Select Size</option>
                                    <?php foreach (getBannerSizes() as $size => $label): ?>
                                        <option value="<?php echo explode('x', $size)[0]; ?>" data-height="<?php echo explode('x', $size)[1]; ?>">
                                            <?php echo $size; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 mb-3">
                                <label for="height" class="form-label">Height *</label>
                                <input type="number" class="form-control" id="height" name="height" required readonly>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="bid_amount" class="form-label">Bid Amount ($) *</label>
                            <input type="number" class="form-control" id="bid_amount" name="bid_amount" 
                                   step="0.0001" min="0" required>
                            <div class="form-text">Amount you're willing to bid for this creative</div>
                        </div>
                        
                        <!-- Creative type section - show different options for RTB vs RON campaigns -->
                        <div class="mb-3">
                            <label for="creative_type" class="form-label">Creative Type *</label>
                            <select class="form-select" id="creative_type" name="creative_type" required onchange="toggleCreativeFields()">
                                <?php if ($campaign['type'] == 'rtb'): ?>
                                    <!-- RTB Campaign Creative Types -->
                                    <option value="banner_rtb">Banner RTB</option>
                                    <option value="video_rtb">Video RTB</option>
                                    <option value="native_rtb">Native RTB</option>
                                    <option value="display_rtb">Display RTB</option>
                                    <option value="third_party">Third Party Script</option>
                                <?php else: ?>
                                    <!-- RON Campaign Creative Types -->
                                    <option value="image">Image Banner</option>
                                    <option value="video">Video Banner</option>
                                    <option value="html5">HTML5/Custom</option>
                                    <option value="third_party">Third Party Script</option>
                                <?php endif; ?>
                            </select>
                            <?php if ($campaign['type'] == 'rtb'): ?>
                                <div class="form-text">
                                    <i class="fas fa-info-circle"></i> RTB creative types are optimized for real-time bidding campaigns
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($campaign['type'] != 'rtb' || empty($campaign['endpoint_url'])): ?>
                            
                            <!-- Image Creative Fields -->
                            <div id="image_fields" class="creative-fields">
                                <div class="mb-3">
                                    <label for="image_url" class="form-label">Image URL *</label>
                                    <input type="url" class="form-control" id="image_url" name="image_url"
                                           placeholder="https://example.com/banner.jpg">
                                </div>
                            </div>
                            
                            <!-- Video Creative Fields -->
                            <div id="video_fields" class="creative-fields" style="display: none;">
                                <div class="mb-3">
                                    <label for="video_url" class="form-label">Video URL *</label>
                                    <input type="url" class="form-control" id="video_url" name="video_url"
                                           placeholder="https://example.com/video.mp4">
                                </div>
                            </div>
                            
                            <!-- HTML5/Third Party Fields -->
                            <div id="html_fields" class="creative-fields" style="display: none;">
                                <div class="mb-3">
                                    <label for="html_content" class="form-label">HTML Content *</label>
                                    <textarea class="form-control" id="html_content" name="html_content" rows="6"
                                              placeholder="<div>Your HTML/JavaScript code here</div>"></textarea>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="click_url" class="form-label">Click URL *</label>
                                <input type="url" class="form-control" id="click_url" name="click_url" required
                                       placeholder="https://example.com/landing-page">
                                <div class="form-text">Where users will be redirected when they click the ad</div>
                            </div>
                        <?php else: ?>
                            <!-- For RTB campaigns with endpoints, this is a hidden field -->
                            <input type="hidden" name="creative_type" value="third_party">
                            <input type="hidden" name="click_url" value="https://rtb.placeholder.url">
                            
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i> <strong>Note:</strong> 
                                For RTB campaigns with external endpoints, you only need to specify the size and bid amount.
                                The creative content will be dynamically provided by the RTB endpoint.
                            </div>
                        <?php endif; ?>
                        
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-save"></i> Create Creative
                        </button>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Existing Creatives -->
    <div class="col-lg-7 mb-4">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-list"></i> Creatives</h5>
                <?php if ($campaign): ?>
                    <span class="badge bg-primary"><?php echo count($creatives); ?> Creatives</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!$campaign): ?>
                    <div class="text-center py-4">
                        <i class="fas fa-images fa-3x text-muted mb-3"></i>
                        <p class="text-muted">Select a campaign to view and manage creatives.</p>
                    </div>
                <?php elseif (empty($creatives)): ?>
                    <div class="text-center py-4">
                        <i class="fas fa-images fa-3x text-muted mb-3"></i>
                        <p class="text-muted">No creatives found for this campaign.</p>
                        <p class="text-muted">Create your first creative using the form on the left.</p>
                    </div>
                <?php else: ?>
                    <div class="row">
                        <?php foreach ($creatives as $creative): ?>
                            <div class="col-md-6 mb-3">
                                <div class="card h-100 creative-card">
                                    <div class="card-body">
                                        <h6 class="card-title"><?php echo htmlspecialchars($creative['name']); ?></h6>
                                        <div class="mb-2">
                                            <span class="badge bg-info"><?php echo $creative['width']; ?>x<?php echo $creative['height']; ?></span>
                                            <span class="badge bg-success"><?php echo formatCurrency($creative['bid_amount']); ?></span>
                                            <span class="badge bg-secondary"><?php echo ucfirst($creative['creative_type']); ?></span>
                                            
                                            <?php if ($campaign['type'] == 'rtb' && !empty($campaign['endpoint_url']) && $creative['creative_type'] == 'third_party'): ?>
                                                <span class="badge bg-primary">RTB External</span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <?php if ($campaign['type'] == 'rtb' && !empty($campaign['endpoint_url']) && $creative['creative_type'] == 'third_party'): ?>
                                            <div class="mb-2 alert alert-secondary">
                                                <i class="fas fa-exchange-alt"></i> RTB External Content<br>
                                                <small class="text-muted">Creative content will be provided dynamically by the RTB endpoint</small>
                                            </div>
                                        <?php elseif ($creative['creative_type'] == 'image' && $creative['image_url']): ?>
                                            <div class="mb-2">
                                                <img src="<?php echo htmlspecialchars($creative['image_url']); ?>" 
                                                     class="img-fluid border rounded" 
                                                     style="max-height: 100px; max-width: 100%;"
                                                     alt="Creative Preview">
                                            </div>
                                        <?php elseif ($creative['creative_type'] == 'video' && $creative['video_url']): ?>
                                            <div class="mb-2">
                                                <video width="100%" height="60" style="max-width: 150px;">
                                                    <source src="<?php echo htmlspecialchars($creative['video_url']); ?>" type="video/mp4">
                                                </video>
                                            </div>
                                        <?php elseif ($creative['creative_type'] == 'html5' || $creative['creative_type'] == 'third_party'): ?>
                                            <div class="mb-2">
                                                <div class="text-muted small">
                                                    <strong>HTML Content Preview:</strong><br>
                                                    <code class="d-block p-2 bg-light" style="max-height: 80px; overflow: auto; font-size: 0.75rem;">
                                                        <?php echo htmlspecialchars(substr($creative['html_content'], 0, 200) . (strlen($creative['html_content']) > 200 ? '...' : '')); ?>
                                                    </code>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <!-- Only show click URL for non-RTB external creatives -->
                                        <?php if (!($campaign['type'] == 'rtb' && !empty($campaign['endpoint_url']) && $creative['creative_type'] == 'third_party')): ?>
                                            <div class="text-muted small mb-2">
                                                <strong>Click URL:</strong><br>
                                                <a href="<?php echo htmlspecialchars($creative['click_url']); ?>" target="_blank" class="text-decoration-none">
                                                    <?php echo htmlspecialchars(substr($creative['click_url'], 0, 40) . (strlen($creative['click_url']) > 40 ? '...' : '')); ?>
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="text-muted small">
                                            Created: <?php echo date('M j, Y', strtotime($creative['created_at'])); ?>
                                        </div>
                                    </div>
                                    <div class="card-footer">
                                        <div class="btn-group btn-group-sm w-100">
                                            <button class="btn btn-outline-primary edit-creative" 
                                                    data-bs-toggle="tooltip" title="Edit"
                                                    data-creative-id="<?php echo $creative['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($creative['name']); ?>"
                                                    data-type="<?php echo $creative['creative_type']; ?>"
                                                    data-width="<?php echo $creative['width']; ?>"
                                                    data-height="<?php echo $creative['height']; ?>"
                                                    data-bid="<?php echo $creative['bid_amount']; ?>"
                                                    data-image-url="<?php echo htmlspecialchars($creative['image_url']); ?>"
                                                    data-video-url="<?php echo htmlspecialchars($creative['video_url']); ?>"
                                                    data-html-content="<?php echo htmlspecialchars($creative['html_content']); ?>"
                                                    data-click-url="<?php echo htmlspecialchars($creative['click_url']); ?>">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-outline-success preview-creative" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#creativePreviewModal"
                                                    data-bs-toggle2="tooltip" title="Preview"
                                                    data-type="<?php echo $creative['creative_type']; ?>"
                                                    data-content="<?php echo htmlspecialchars($creative['html_content']); ?>"
                                                    data-url="<?php echo htmlspecialchars($creative['image_url'] ?: $creative['video_url']); ?>"
                                                    data-width="<?php echo $creative['width']; ?>"
                                                    data-height="<?php echo $creative['height']; ?>">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="btn btn-outline-info stats-creative" 
                                                    data-bs-toggle="tooltip" title="Stats"
                                                    onclick="window.location.href='statistics.php?creative_id=<?php echo $creative['id']; ?>'">
                                                <i class="fas fa-chart-bar"></i>
                                            </button>
                                            <button class="btn btn-outline-danger delete-item" 
                                                    data-bs-toggle="tooltip" title="Delete"
                                                    data-url="creative.php"
                                                    data-id="<?php echo $creative['id']; ?>"
                                                    data-type="creative">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Creative Preview Modal -->
<div class="modal fade" id="creativePreviewModal" tabindex="-1" aria-labelledby="creativePreviewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="creativePreviewModalLabel">
                    <i class="fas fa-eye"></i> Creative Preview
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="creativePreviewContent" class="text-center">
                    <!-- Preview content will be loaded here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Creative Edit Modal -->
<div class="modal fade" id="creativeEditModal" tabindex="-1" aria-labelledby="creativeEditModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="creativeEditModalLabel">
                    <i class="fas fa-edit"></i> Edit Creative
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editCreativeForm">
                <div class="modal-body">
                    <input type="hidden" id="edit_creative_id" name="creative_id">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <div class="mb-3">
                        <label for="edit_name" class="form-label">Creative Name *</label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>
                    
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label for="edit_width" class="form-label">Width *</label>
                            <input type="number" class="form-control" id="edit_width" name="width" required readonly>
                        </div>
                        <div class="col-6 mb-3">
                            <label for="edit_height" class="form-label">Height *</label>
                            <input type="number" class="form-control" id="edit_height" name="height" required readonly>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_bid_amount" class="form-label">Bid Amount ($) *</label>
                        <input type="number" class="form-control" id="edit_bid_amount" name="bid_amount" step="0.0001" min="0" required>
                    </div>
                    
                    <div class="mb-3" id="edit_image_url_group" style="display: none;">
                        <label for="edit_image_url" class="form-label">Image URL</label>
                        <input type="url" class="form-control" id="edit_image_url" name="image_url">
                    </div>
                    
                    <div class="mb-3" id="edit_video_url_group" style="display: none;">
                        <label for="edit_video_url" class="form-label">Video URL</label>
                        <input type="url" class="form-control" id="edit_video_url" name="video_url">
                    </div>
                    
                    <div class="mb-3" id="edit_html_content_group" style="display: none;">
                        <label for="edit_html_content" class="form-label">HTML Content</label>
                        <textarea class="form-control" id="edit_html_content" name="html_content" rows="4"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_click_url" class="form-label">Click URL *</label>
                        <input type="url" class="form-control" id="edit_click_url" name="click_url" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function updateHeight() {
    const widthSelect = document.getElementById('width');
    const heightInput = document.getElementById('height');
    const selectedOption = widthSelect.options[widthSelect.selectedIndex];
    
    if (selectedOption && selectedOption.dataset.height) {
        heightInput.value = selectedOption.dataset.height;
    }
}

function toggleCreativeFields() {
    const creativeType = document.getElementById('creative_type');
    if (!creativeType) return; // Exit if not found (like in RTB campaigns)
    
    const imageFields = document.getElementById('image_fields');
    const videoFields = document.getElementById('video_fields');
    const htmlFields = document.getElementById('html_fields');
    
    if (!imageFields || !videoFields || !htmlFields) return; // Safety check
    
    // Hide all fields first
    imageFields.style.display = 'none';
    videoFields.style.display = 'none';
    htmlFields.style.display = 'none';
    
    // Show relevant fields
    switch (creativeType.value) {
        case 'image':
        case 'banner_rtb':
        case 'display_rtb':
            imageFields.style.display = 'block';
            break;
        case 'video':
        case 'video_rtb':
            videoFields.style.display = 'block';
            break;
        case 'html5':
        case 'third_party':
        case 'native_rtb':
            htmlFields.style.display = 'block';
            break;
    }
}

// Handle edit creative functionality
function initEditCreative() {
    document.querySelectorAll('.edit-creative').forEach(button => {
        button.addEventListener('click', function() {
            const creativeData = {
                id: this.dataset.creativeId,
                name: this.dataset.name,
                type: this.dataset.type,
                width: this.dataset.width,
                height: this.dataset.height,
                bid: this.dataset.bid,
                imageUrl: this.dataset.imageUrl,
                videoUrl: this.dataset.videoUrl,
                htmlContent: this.dataset.htmlContent,
                clickUrl: this.dataset.clickUrl
            };
            
            // Populate edit form
            document.getElementById('edit_creative_id').value = creativeData.id;
            document.getElementById('edit_name').value = creativeData.name;
            document.getElementById('edit_width').value = creativeData.width;
            document.getElementById('edit_height').value = creativeData.height;
            document.getElementById('edit_bid_amount').value = creativeData.bid;
            document.getElementById('edit_image_url').value = creativeData.imageUrl;
            document.getElementById('edit_video_url').value = creativeData.videoUrl;
            document.getElementById('edit_html_content').value = creativeData.htmlContent;
            document.getElementById('edit_click_url').value = creativeData.clickUrl;
            
            // Show/hide relevant fields based on creative type
            const isImage = creativeData.type === 'image' || creativeData.type === 'banner_rtb' || creativeData.type === 'display_rtb';
            const isVideo = creativeData.type === 'video' || creativeData.type === 'video_rtb';
            const isHtml = creativeData.type === 'html5' || creativeData.type === 'third_party' || creativeData.type === 'native_rtb';
            
            document.getElementById('edit_image_url_group').style.display = isImage ? 'block' : 'none';
            document.getElementById('edit_video_url_group').style.display = isVideo ? 'block' : 'none';
            document.getElementById('edit_html_content_group').style.display = isHtml ? 'block' : 'none';
            
            // Show modal
            new bootstrap.Modal(document.getElementById('creativeEditModal')).show();
        });
    });
}

// Handle edit form submission
function initEditFormSubmission() {
    document.getElementById('editCreativeForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        formData.append('action', 'edit');
        
        // Show loading state
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        submitBtn.disabled = true;
        
        fetch('creative.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(data => {
            // Parse response to check for success
            if (data.includes('updated successfully') || data.includes('success')) {
                // Show success message and reload page
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: 'Creative updated successfully!',
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        window.location.reload();
                    });
                } else {
                    alert('Creative updated successfully!');
                    window.location.reload();
                }
            } else {
                throw new Error('Update failed');
            }
        })
        .catch(error => {
            // Show error message
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Failed to update creative. Please try again.'
                });
            } else {
                alert('Failed to update creative. Please try again.');
            }
        })
        .finally(() => {
            // Restore button state
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        });
    });
}

// Add loading states to form submission
function addLoadingStates() {
    const createForm = document.getElementById('creativeForm');
    if (createForm) {
        createForm.addEventListener('submit', function() {
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
                submitBtn.disabled = true;
            }
        });
    }
}

// Prevent form submission when changing campaign
document.getElementById('campaign_id').addEventListener('change', function() {
    if (this.value) {
        window.location.href = 'creative.php?campaign_id=' + this.value;
    }
});

// Initialize form
document.addEventListener('DOMContentLoaded', function() {
    toggleCreativeFields();
    initEditCreative();
    initEditFormSubmission();
    addLoadingStates();
    
    // Fix for tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    });
    
    // Fix for dual tooltip attributes on preview buttons
    document.querySelectorAll('[data-bs-toggle2="tooltip"]').forEach(function(element) {
        new bootstrap.Tooltip(element, {
            title: element.getAttribute('title')
        });
    });
});
</script>

<!-- Include SweetAlert2 for better notifications -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<!-- Custom styles for creative management -->
<style>
    .creative-card {
        transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
    }
    
    .creative-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    }
    
    .btn-group .btn {
        transition: all 0.2s ease-in-out;
    }
    
    .btn-group .btn:hover {
        transform: scale(1.05);
    }
    
    .form-control:focus {
        border-color: #0d6efd;
        box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
    }
    
    .creative-type-info {
        font-size: 0.85rem;
        color: #6c757d;
    }
    
    .loading-overlay {
        position: relative;
    }
    
    .loading-overlay::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(255, 255, 255, 0.8);
        display: none;
    }
    
    .loading-overlay.loading::after {
        display: block;
    }
    
    .preview-container {
        min-height: 200px;
        border: 2px dashed #dee2e6;
        border-radius: 0.375rem;
        display: flex;
        align-items: center;
        justify-content: center;
        background-color: #f8f9fa;
    }
    
    @media (max-width: 768px) {
        .btn-group {
            width: 100%;
            display: flex;
        }
        
        .btn-group .btn {
            font-size: 0.8rem;
            padding: 0.375rem 0.5rem;
            flex: 1;
        }
        
        .creative-card .card-body {
            padding: 1rem 0.75rem;
        }
        
        .col-lg-5, .col-lg-7 {
            margin-bottom: 1rem;
        }
        
        .btn-group .btn i {
            font-size: 0.9rem;
        }
    }
    
    /* Add some animation to the create button */
    .btn-primary {
        transition: all 0.3s ease-in-out;
    }
    
    .btn-primary:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(13, 110, 253, 0.3);
    }
    
    /* Style for form validation feedback */
    .was-validated .form-control:valid {
        border-color: #198754;
    }
    
    .was-validated .form-control:invalid {
        border-color: #dc3545;
    }
</style>

<?php include 'includes/footer.php'; ?>