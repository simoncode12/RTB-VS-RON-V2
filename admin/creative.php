<?php
include 'includes/header.php';

// Current session information
$current_timestamp = '2025-06-25 20:32:12'; // Current UTC time
$current_user = 'simoncode12';              // Current logged in user

$message = '';
$message_type = 'success';
$campaign_id = $_GET['campaign_id'] ?? null;
$is_new_campaign = isset($_GET['new']);

// Handle delete action
if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['creative_id'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM creatives WHERE id = ?");
        $stmt->execute([$_POST['creative_id']]);
        $message = 'Creative deleted successfully!';
        $message_type = 'success';
    } catch (Exception $e) {
        $message = 'Error deleting creative: ' . $e->getMessage();
        $message_type = 'danger';
    }
}

// Handle edit action
if (isset($_POST['action']) && $_POST['action'] === 'edit' && isset($_POST['creative_id'])) {
    try {
        $name = $_POST['name'] ?? '';
        $bid_amount = $_POST['bid_amount'] ?? '';
        $click_url = $_POST['click_url'] ?? '';
        
        if ($name) {
            $stmt = $pdo->prepare("
                UPDATE creatives 
                SET name = ?, bid_amount = ?, click_url = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $bid_amount ?: 0, $click_url, $_POST['creative_id']]);
            $message = 'Creative updated successfully!';
            $message_type = 'success';
        } else {
            $message = 'Please fill in the required fields.';
            $message_type = 'danger';
        }
    } catch (Exception $e) {
        $message = 'Error updating creative: ' . $e->getMessage();
        $message_type = 'danger';
    }
}

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

// Handle create creative
if ($_POST && !isset($_POST['action'])) {
    try {
        $name = $_POST['name'] ?? '';
        $campaign_id_post = $_POST['campaign_id'] ?? '';
        $width = $_POST['width'] ?? '';
        $height = $_POST['height'] ?? '';
        $bid_amount = $_POST['bid_amount'] ?? '';
        $creative_type = $_POST['creative_type'] ?? 'image';
        $image_url = $_POST['image_url'] ?? '';
        $video_url = $_POST['video_url'] ?? '';
        $html_content = $_POST['html_content'] ?? '';
        $click_url = $_POST['click_url'] ?? '';
        
        // Get campaign type
        $stmt = $pdo->prepare("SELECT type, endpoint_url FROM campaigns WHERE id = ?");
        $stmt->execute([$campaign_id_post]);
        $campaign_info = $stmt->fetch();
        $is_rtb_campaign = ($campaign_info['type'] == 'rtb');
        
        // Validation based on campaign type
        if ($is_rtb_campaign) {
            // For RTB campaigns: name, campaign_id, width, height are required (bid_amount is optional)
            $required_fields_valid = ($name && $campaign_id_post && $width && $height);
        } else {
            // For RON campaigns: all fields including bid_amount are required
            $required_fields_valid = ($name && $campaign_id_post && $width && $height && $bid_amount);
        }
        
        if ($required_fields_valid) {
            // For RTB campaigns, force creative type to rtb_external
            if ($is_rtb_campaign) {
                $creative_type = 'rtb_external';
                
                // Set default bid amount if not provided for RTB campaigns
                if (empty($bid_amount)) {
                    $bid_amount = 0.0001; // Default minimal bid for RTB
                }
                
                // For RTB campaigns with endpoints, use placeholder data
                if (!empty($campaign_info['endpoint_url'])) {
                    $stmt = $pdo->prepare("
                        INSERT INTO creatives (
                            campaign_id, name, width, height, bid_amount, creative_type,
                            image_url, video_url, html_content, click_url
                        ) VALUES (?, ?, ?, ?, ?, ?, '', '', 
                        'RTB External Creative - Content will be provided by RTB endpoint',
                        ?)
                    ");
                    
                    $stmt->execute([
                        $campaign_id_post, 
                        $name, 
                        $width, 
                        $height, 
                        $bid_amount,
                        $creative_type,
                        $click_url ?: 'https://rtb.placeholder.url'
                    ]);
                    
                    $message = 'RTB external creative created successfully!';
                    $message_type = 'success';
                } else {
                    // RTB campaign without endpoint - allow manual content
                    $valid_content = false;
                    if (!empty($image_url) || !empty($video_url) || !empty($html_content)) {
                        $valid_content = true;
                    }
                    
                    if (!$valid_content && !$click_url) {
                        $message = 'Please provide content and a click URL for RTB creative.';
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
                        
                        $message = 'RTB creative created successfully!';
                        $message_type = 'success';
                    }
                }
            } else {
                // Regular RON campaign validation
                $valid_content = false;
                switch ($creative_type) {
                    case 'image':
                        $valid_content = !empty($image_url);
                        break;
                    case 'video':
                        $valid_content = !empty($video_url);
                        break;
                    case 'html5':
                    case 'third_party':
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
        } else {
            if ($is_rtb_campaign) {
                $message = 'Please fill in all required fields: Name, Campaign, Width, and Height.';
            } else {
                $message = 'Please fill in all required fields.';
            }
            $message_type = 'danger';
        }
    } catch (Exception $e) {
        $message = 'Error creating creative: ' . $e->getMessage();
        $message_type = 'danger';
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
                <form method="POST" id="creativeForm">
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
                        
                        <!-- RTB Campaign Notice -->
                        <?php if ($campaign['type'] == 'rtb'): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> 
                                <strong>RTB Campaign Detected</strong><br>
                                Creative type will be automatically set to <strong>RTB External</strong>.
                                <br><span class="badge bg-warning text-dark">Note:</span> <strong>Bid Amount is optional</strong> for RTB campaigns.
                                <?php if (!empty($campaign['endpoint_url'])): ?>
                                    <br><small>External Endpoint: <?php echo htmlspecialchars(substr($campaign['endpoint_url'], 0, 60) . (strlen($campaign['endpoint_url']) > 60 ? '...' : '')); ?></small>
                                    <hr class="my-2">
                                    <small class="text-muted">For RTB campaigns with endpoints, creative content will be dynamically provided by the RTB endpoint. You only need to configure size parameters.</small>
                                <?php else: ?>
                                    <hr class="my-2">
                                    <small class="text-muted">This RTB campaign allows manual creative content configuration.</small>
                                <?php endif; ?>
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
                            <label for="bid_amount" class="form-label">
                                Bid Amount ($) 
                                <?php if ($campaign['type'] != 'rtb'): ?>
                                    *
                                <?php else: ?>
                                    <span class="text-muted">(Optional for RTB)</span>
                                <?php endif; ?>
                            </label>
                            <input type="number" class="form-control" id="bid_amount" name="bid_amount" 
                                   step="0.0001" min="0" 
                                   <?php echo ($campaign['type'] != 'rtb') ? 'required' : ''; ?>
                                   placeholder="<?php echo ($campaign['type'] == 'rtb') ? 'Leave empty for default RTB bid' : ''; ?>">
                            <div class="form-text">
                                <?php if ($campaign['type'] == 'rtb'): ?>
                                    For RTB campaigns, bid amount is optional. If left empty, a default minimal bid will be used.
                                <?php else: ?>
                                    Amount you're willing to bid for this creative
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Creative Type Display for RTB -->
                        <?php if ($campaign['type'] == 'rtb'): ?>
                            <div class="mb-3">
                                <label class="form-label">Creative Type</label>
                                <div class="form-control-plaintext">
                                    <span class="badge bg-primary">RTB External</span>
                                    <small class="text-muted ms-2">Automatically set for RTB campaigns</small>
                                </div>
                                <input type="hidden" name="creative_type" value="rtb_external">
                            </div>
                        <?php endif; ?>
                        
                        <!-- Content fields for RTB campaigns without endpoints or RON campaigns -->
                        <?php if ($campaign['type'] != 'rtb' || empty($campaign['endpoint_url'])): ?>
                            <?php if ($campaign['type'] != 'rtb'): ?>
                                <div class="mb-3">
                                    <label for="creative_type" class="form-label">Creative Type *</label>
                                    <select class="form-select" id="creative_type" name="creative_type" required onchange="toggleCreativeFields()">
                                        <option value="image">Image Banner</option>
                                        <option value="video">Video Banner</option>
                                        <option value="html5">HTML5/Custom</option>
                                        <option value="third_party">Third Party Script</option>
                                    </select>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Image Creative Fields -->
                            <div id="image_fields" class="creative-fields" <?php echo ($campaign['type'] == 'rtb') ? '' : 'style="display: block;"'; ?>>
                                <div class="mb-3">
                                    <label for="image_url" class="form-label">Image URL <?php echo ($campaign['type'] == 'rtb' && empty($campaign['endpoint_url'])) ? '*' : ''; ?></label>
                                    <input type="url" class="form-control" id="image_url" name="image_url"
                                           placeholder="https://example.com/banner.jpg">
                                </div>
                            </div>
                            
                            <!-- Video Creative Fields -->
                            <div id="video_fields" class="creative-fields" style="display: none;">
                                <div class="mb-3">
                                    <label for="video_url" class="form-label">Video URL</label>
                                    <input type="url" class="form-control" id="video_url" name="video_url"
                                           placeholder="https://example.com/video.mp4">
                                </div>
                            </div>
                            
                            <!-- HTML5/Third Party Fields -->
                            <div id="html_fields" class="creative-fields" style="display: none;">
                                <div class="mb-3">
                                    <label for="html_content" class="form-label">HTML Content</label>
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
                            <!-- For RTB campaigns with endpoints, hidden fields -->
                            <input type="hidden" name="click_url" value="https://rtb.placeholder.url">
                            
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i> <strong>Note:</strong> 
                                For RTB campaigns with external endpoints, you only need to specify the size. Bid amount is optional.
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
                                <div class="card h-100">
                                    <div class="card-body">
                                        <h6 class="card-title"><?php echo htmlspecialchars($creative['name']); ?></h6>
                                        <div class="mb-2">
                                            <span class="badge bg-info"><?php echo $creative['width']; ?>x<?php echo $creative['height']; ?></span>
                                            <?php if ($creative['bid_amount'] > 0): ?>
                                                <span class="badge bg-success"><?php echo formatCurrency($creative['bid_amount']); ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Default Bid</span>
                                            <?php endif; ?>
                                            <span class="badge bg-secondary"><?php echo ucfirst(str_replace('_', ' ', $creative['creative_type'])); ?></span>
                                            
                                            <?php if ($creative['creative_type'] == 'rtb_external'): ?>
                                                <span class="badge bg-primary">RTB</span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <?php if ($creative['creative_type'] == 'rtb_external'): ?>
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
                                        <?php elseif (in_array($creative['creative_type'], ['html5', 'third_party']) && $creative['html_content']): ?>
                                            <div class="mb-2">
                                                <div class="text-muted small">
                                                    <strong>HTML Content Preview:</strong><br>
                                                    <code class="d-block p-2 bg-light" style="max-height: 80px; overflow: auto; font-size: 0.75rem;">
                                                        <?php echo htmlspecialchars(substr($creative['html_content'], 0, 200) . (strlen($creative['html_content']) > 200 ? '...' : '')); ?>
                                                    </code>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <!-- Show click URL for non-RTB external creatives -->
                                        <?php if ($creative['creative_type'] != 'rtb_external' || ($creative['creative_type'] == 'rtb_external' && $creative['click_url'] != 'https://rtb.placeholder.url')): ?>
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
                                            <button class="btn btn-outline-primary btn-edit" 
                                                    data-creative-id="<?php echo $creative['id']; ?>"
                                                    data-creative-name="<?php echo htmlspecialchars($creative['name']); ?>"
                                                    data-creative-bid="<?php echo $creative['bid_amount']; ?>"
                                                    data-creative-click="<?php echo htmlspecialchars($creative['click_url']); ?>"
                                                    data-bs-toggle="tooltip" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-outline-success btn-preview" 
                                                    data-creative-id="<?php echo $creative['id']; ?>"
                                                    data-bs-toggle="tooltip" title="Preview">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="btn btn-outline-info btn-stats" 
                                                    data-creative-id="<?php echo $creative['id']; ?>"
                                                    data-bs-toggle="tooltip" title="Stats">
                                                <i class="fas fa-chart-bar"></i>
                                            </button>
                                            <button class="btn btn-outline-danger btn-delete" 
                                                    data-creative-id="<?php echo $creative['id']; ?>"
                                                    data-creative-name="<?php echo htmlspecialchars($creative['name']); ?>"
                                                    data-bs-toggle="tooltip" title="Delete">
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

<!-- Edit Creative Modal -->
<div class="modal fade" id="editCreativeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Creative</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editCreativeForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="creative_id" id="edit_creative_id">
                    
                    <div class="mb-3">
                        <label for="edit_name" class="form-label">Creative Name *</label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_bid_amount" class="form-label">Bid Amount ($)</label>
                        <input type="number" class="form-control" id="edit_bid_amount" name="bid_amount" 
                               step="0.0001" min="0">
                        <div class="form-text">Leave empty for default bid (RTB campaigns)</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_click_url" class="form-label">Click URL</label>
                        <input type="url" class="form-control" id="edit_click_url" name="click_url">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteCreativeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the creative "<span id="delete_creative_name"></span>"?</p>
                <p class="text-danger"><small>This action cannot be undone.</small></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="creative_id" id="delete_creative_id">
                    <button type="submit" class="btn btn-danger">Delete</button>
                </form>
            </div>
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
            imageFields.style.display = 'block';
            break;
        case 'video':
            videoFields.style.display = 'block';
            break;
        case 'html5':
        case 'third_party':
            htmlFields.style.display = 'block';
            break;
    }
}

// Dynamic validation based on campaign type
function updateFormValidation() {
    const campaignSelect = document.getElementById('campaign_id');
    const bidAmountField = document.getElementById('bid_amount');
    
    if (campaignSelect && bidAmountField) {
        const selectedOption = campaignSelect.options[campaignSelect.selectedIndex];
        const campaignText = selectedOption.text;
        
        // Check if it's an RTB campaign
        if (campaignText.includes('[RTB]')) {
            bidAmountField.removeAttribute('required');
            bidAmountField.placeholder = 'Leave empty for default RTB bid';
        } else {
            bidAmountField.setAttribute('required', 'required');
            bidAmountField.placeholder = '';
        }
    }
}

// Prevent form submission when changing campaign
document.getElementById('campaign_id').addEventListener('change', function() {
    updateFormValidation();
    if (this.value) {
        window.location.href = 'creative.php?campaign_id=' + this.value;
    }
});

// Initialize form
document.addEventListener('DOMContentLoaded', function() {
    toggleCreativeFields();
    updateFormValidation();
    
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    });
    
    // Edit button functionality
    document.querySelectorAll('.btn-edit').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const creativeId = this.dataset.creativeId;
            const creativeName = this.dataset.creativeName;
            const creativeBid = this.dataset.creativeBid;
            const creativeClick = this.dataset.creativeClick;
            
            document.getElementById('edit_creative_id').value = creativeId;
            document.getElementById('edit_name').value = creativeName;
            document.getElementById('edit_bid_amount').value = creativeBid;
            document.getElementById('edit_click_url').value = creativeClick;
            
            new bootstrap.Modal(document.getElementById('editCreativeModal')).show();
        });
    });
    
    // Delete button functionality
    document.querySelectorAll('.btn-delete').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const creativeId = this.dataset.creativeId;
            const creativeName = this.dataset.creativeName;
            
            document.getElementById('delete_creative_id').value = creativeId;
            document.getElementById('delete_creative_name').textContent = creativeName;
            
            new bootstrap.Modal(document.getElementById('deleteCreativeModal')).show();
        });
    });
    
    // Preview button functionality
    document.querySelectorAll('.btn-preview').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const creativeId = this.dataset.creativeId;
            // Open preview in new window/tab
            window.open('preview.php?creative_id=' + creativeId, '_blank');
        });
    });
    
    // Stats button functionality
    document.querySelectorAll('.btn-stats').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const creativeId = this.dataset.creativeId;
            // Redirect to stats page
            window.location.href = 'statistics.php?creative_id=' + creativeId;
        });
    });
});
</script>

<?php include 'includes/footer.php'; ?>
