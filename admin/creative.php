<?php
include 'includes/header.php';

// Current session information
$current_timestamp = '2025-06-23 23:19:33'; // Current UTC time
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

if ($_POST) {
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
        
        if ($name && $campaign_id_post && $width && $height && $bid_amount) {
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
            $message = 'Please fill in all required fields.';
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
                        
                        <!-- Hide creative type for RTB campaigns with endpoints -->
                        <?php if ($campaign['type'] != 'rtb' || empty($campaign['endpoint_url'])): ?>
                            <div class="mb-3">
                                <label for="creative_type" class="form-label">Creative Type *</label>
                                <select class="form-select" id="creative_type" name="creative_type" required onchange="toggleCreativeFields()">
                                    <?php if ($campaign['type'] == 'rtb'): ?>
                                        <!-- RTB-specific creative types -->
                                        <option value="image">Banner/Display</option>
                                        <option value="video">Video/Pre-roll</option>
                                        <option value="html5">Rich Media/HTML5</option>
                                        <option value="third_party">Native/In-feed</option>
                                    <?php else: ?>
                                        <!-- RON campaign types -->
                                        <option value="image">Image Banner</option>
                                        <option value="video">Video Banner</option>
                                        <option value="html5">HTML5/Custom</option>
                                        <option value="third_party">Third Party Script</option>
                                    <?php endif; ?>
                                </select>
                                <?php if ($campaign['type'] == 'rtb'): ?>
                                    <div class="form-text">RTB creative types: Banner for display ads, Video for pre-roll, Rich Media for interactive ads, Native for in-feed content</div>
                                <?php endif; ?>
                            </div>
                            
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
                                <div class="card h-100">
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
                                            <button class="btn btn-outline-primary btn-edit" 
                                                    data-bs-toggle="tooltip" title="Edit Creative"
                                                    data-id="<?php echo $creative['id']; ?>">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-outline-success btn-preview" 
                                                    data-bs-toggle="modal" data-bs-target="#previewModal"
                                                    data-bs-original-title="Preview Creative"
                                                    data-type="<?php echo $creative['creative_type']; ?>"
                                                    data-content="<?php echo htmlspecialchars($creative['html_content']); ?>"
                                                    data-url="<?php echo htmlspecialchars($creative['image_url'] ?: $creative['video_url']); ?>"
                                                    data-width="<?php echo $creative['width']; ?>"
                                                    data-height="<?php echo $creative['height']; ?>"
                                                    data-click-url="<?php echo htmlspecialchars($creative['click_url']); ?>">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <a href="statistics.php?creative_id=<?php echo $creative['id']; ?>" 
                                               class="btn btn-outline-info" 
                                               data-bs-toggle="tooltip" title="View Statistics">
                                                <i class="fas fa-chart-bar"></i>
                                            </a>
                                            <button class="btn btn-outline-danger delete-item" 
                                                    data-bs-toggle="tooltip" title="Delete Creative"
                                                    data-url="ajax/creative-delete.php"
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

<!-- Edit Creative Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editModalLabel">
                    <i class="fas fa-edit"></i> Edit Creative
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editForm">
                <div class="modal-body">
                    <input type="hidden" id="edit_id" name="id">
                    
                    <div class="mb-3">
                        <label for="edit_name" class="form-label">Creative Name *</label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>
                    
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label for="edit_width" class="form-label">Width *</label>
                            <select class="form-select" id="edit_width" name="width" required onchange="updateEditHeight()">
                                <option value="">Select Size</option>
                                <?php foreach (getBannerSizes() as $size => $label): ?>
                                    <option value="<?php echo explode('x', $size)[0]; ?>" data-height="<?php echo explode('x', $size)[1]; ?>">
                                        <?php echo $size; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 mb-3">
                            <label for="edit_height" class="form-label">Height *</label>
                            <input type="number" class="form-control" id="edit_height" name="height" required readonly>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_bid_amount" class="form-label">Bid Amount ($) *</label>
                        <input type="number" class="form-control" id="edit_bid_amount" name="bid_amount" 
                               step="0.0001" min="0" required>
                    </div>
                    
                    <div class="mb-3" id="edit_creative_type_container">
                        <label for="edit_creative_type" class="form-label">Creative Type *</label>
                        <select class="form-select" id="edit_creative_type" name="creative_type" required onchange="toggleEditCreativeFields()">
                            <option value="image">Image Banner</option>
                            <option value="video">Video Banner</option>
                            <option value="html5">HTML5/Custom</option>
                            <option value="third_party">Third Party Script</option>
                        </select>
                    </div>
                    
                    <!-- Edit Image Creative Fields -->
                    <div id="edit_image_fields" class="creative-fields">
                        <div class="mb-3">
                            <label for="edit_image_url" class="form-label">Image URL *</label>
                            <input type="url" class="form-control" id="edit_image_url" name="image_url"
                                   placeholder="https://example.com/banner.jpg">
                        </div>
                    </div>
                    
                    <!-- Edit Video Creative Fields -->
                    <div id="edit_video_fields" class="creative-fields" style="display: none;">
                        <div class="mb-3">
                            <label for="edit_video_url" class="form-label">Video URL *</label>
                            <input type="url" class="form-control" id="edit_video_url" name="video_url"
                                   placeholder="https://example.com/video.mp4">
                        </div>
                    </div>
                    
                    <!-- Edit HTML5/Third Party Fields -->
                    <div id="edit_html_fields" class="creative-fields" style="display: none;">
                        <div class="mb-3">
                            <label for="edit_html_content" class="form-label">HTML Content *</label>
                            <textarea class="form-control" id="edit_html_content" name="html_content" rows="6"
                                      placeholder="<div>Your HTML/JavaScript code here</div>"></textarea>
                        </div>
                    </div>
                    
                    <div class="mb-3" id="edit_click_url_container">
                        <label for="edit_click_url" class="form-label">Click URL *</label>
                        <input type="url" class="form-control" id="edit_click_url" name="click_url" required
                               placeholder="https://example.com/landing-page">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Creative
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Preview Creative Modal -->
<div class="modal fade" id="previewModal" tabindex="-1" aria-labelledby="previewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="previewModalLabel">
                    <i class="fas fa-eye"></i> Creative Preview
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div id="previewContent">
                    <!-- Preview content will be inserted here -->
                </div>
                <div class="mt-3">
                    <small class="text-muted">Click URL: <span id="previewClickUrl"></span></small>
                </div>
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

// Prevent form submission when changing campaign
document.getElementById('campaign_id').addEventListener('change', function() {
    if (this.value) {
        window.location.href = 'creative.php?campaign_id=' + this.value;
    }
});

// Initialize form
document.addEventListener('DOMContentLoaded', function() {
    toggleCreativeFields();
    
    // Fix for tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    });
    
    // Initialize edit button handlers
    initializeEditButtons();
    
    // Initialize preview modal handlers
    initializePreviewModal();
    
    // Initialize edit form submission
    initializeEditForm();
});

// Edit button functionality
function initializeEditButtons() {
    document.querySelectorAll('.btn-edit').forEach(function(button) {
        button.addEventListener('click', function() {
            const creativeId = this.getAttribute('data-id');
            loadCreativeForEdit(creativeId);
        });
    });
}

// Load creative data for editing
function loadCreativeForEdit(creativeId) {
    // Show loading state
    const editModal = new bootstrap.Modal(document.getElementById('editModal'));
    editModal.show();
    
    // Add loading overlay to modal
    const modalBody = document.querySelector('#editModal .modal-body');
    const originalContent = modalBody.innerHTML;
    modalBody.innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x"></i><p class="mt-2">Loading creative data...</p></div>';
    
    // Fetch creative data
    fetch('ajax/creative-get.php?id=' + creativeId)
        .then(response => response.json())
        .then(data => {
            modalBody.innerHTML = originalContent;
            if (data.success) {
                populateEditForm(data.creative);
            } else {
                showNotification('Error loading creative: ' + data.message, 'error');
                editModal.hide();
            }
        })
        .catch(error => {
            modalBody.innerHTML = originalContent;
            showNotification('Network error: ' + error.message, 'error');
            editModal.hide();
        });
}
}

// Populate edit form with creative data
function populateEditForm(creative) {
    document.getElementById('edit_id').value = creative.id;
    document.getElementById('edit_name').value = creative.name;
    document.getElementById('edit_bid_amount').value = creative.bid_amount;
    document.getElementById('edit_creative_type').value = creative.creative_type;
    document.getElementById('edit_image_url').value = creative.image_url || '';
    document.getElementById('edit_video_url').value = creative.video_url || '';
    document.getElementById('edit_html_content').value = creative.html_content || '';
    document.getElementById('edit_click_url').value = creative.click_url || '';
    
    // Set size
    const widthSelect = document.getElementById('edit_width');
    widthSelect.value = creative.width;
    document.getElementById('edit_height').value = creative.height;
    
    // Hide/show fields based on campaign type
    if (creative.campaign_type === 'rtb' && creative.endpoint_url) {
        document.getElementById('edit_creative_type_container').style.display = 'none';
        document.getElementById('edit_click_url_container').style.display = 'none';
    }
    
    toggleEditCreativeFields();
}

// Toggle edit creative fields
function toggleEditCreativeFields() {
    const creativeType = document.getElementById('edit_creative_type');
    if (!creativeType) return;
    
    const imageFields = document.getElementById('edit_image_fields');
    const videoFields = document.getElementById('edit_video_fields');
    const htmlFields = document.getElementById('edit_html_fields');
    
    if (!imageFields || !videoFields || !htmlFields) return;
    
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

// Update height in edit form
function updateEditHeight() {
    const widthSelect = document.getElementById('edit_width');
    const heightInput = document.getElementById('edit_height');
    const selectedOption = widthSelect.options[widthSelect.selectedIndex];
    
    if (selectedOption && selectedOption.dataset.height) {
        heightInput.value = selectedOption.dataset.height;
    }
}

// Initialize edit form submission
function initializeEditForm() {
    document.getElementById('editForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const submitButton = this.querySelector('button[type="submit"]');
        
        // Show loading state
        submitButton.disabled = true;
        submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
        
        fetch('ajax/creative-update.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('Creative updated successfully!', 'success');
                setTimeout(() => location.reload(), 1500); // Reload after showing notification
            } else {
                showNotification('Error: ' + data.message, 'error');
            }
        })
        .catch(error => {
            showNotification('Network error: ' + error.message, 'error');
        })
        .finally(() => {
            submitButton.disabled = false;
            submitButton.innerHTML = '<i class="fas fa-save"></i> Update Creative';
        });
    });
}

// Initialize preview modal
function initializePreviewModal() {
    const previewModal = document.getElementById('previewModal');
    if (previewModal) {
        previewModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const creative = {
                type: button.getAttribute('data-type'),
                content: button.getAttribute('data-content'),
                url: button.getAttribute('data-url'),
                width: button.getAttribute('data-width'),
                height: button.getAttribute('data-height'),
                clickUrl: button.getAttribute('data-click-url')
            };
            
            updatePreviewContent(creative);
        });
    }
}

// Update preview modal content
function updatePreviewContent(creative) {
    const previewContainer = document.getElementById('previewContent');
    const clickUrlSpan = document.getElementById('previewClickUrl');
    
    if (!previewContainer) return;
    
    let content = '';
    switch(creative.type) {
        case 'image':
            if (creative.url) {
                content = `<img src="${creative.url}" class="img-fluid border" style="max-width: 100%; max-height: 400px;" alt="Creative Preview">`;
            } else {
                content = '<p class="text-muted">No image URL provided</p>';
            }
            break;
        case 'video':
            if (creative.url) {
                content = `<video width="${Math.min(creative.width, 600)}" height="${Math.min(creative.height, 400)}" controls class="border">
                          <source src="${creative.url}" type="video/mp4">
                          Your browser does not support the video tag.
                          </video>`;
            } else {
                content = '<p class="text-muted">No video URL provided</p>';
            }
            break;
        case 'html5':
        case 'third_party':
            if (creative.content && creative.content !== 'RTB External Creative - Content will be provided by RTB endpoint') {
                content = `<div class="border p-3" style="max-width: ${creative.width}px; max-height: ${creative.height}px; overflow: auto; background: #f8f9fa;">
                          <strong>HTML Content:</strong><br>
                          <code style="font-size: 0.85rem;">${creative.content.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</code>
                          </div>`;
            } else {
                content = '<p class="text-muted"><i class="fas fa-code"></i> RTB External Creative<br><small>Content will be provided dynamically by RTB endpoint</small></p>';
            }
            break;
        default:
            content = '<p class="text-muted">Preview not available for this creative type.</p>';
    }
    
    previewContainer.innerHTML = content;
    
    if (clickUrlSpan && creative.clickUrl) {
        clickUrlSpan.innerHTML = `<a href="${creative.clickUrl}" target="_blank">${creative.clickUrl}</a>`;
    }
}

// Show notifications (using the same function from admin.js)
function showNotification(message, type = 'info') {
    // Using SweetAlert2 for better notifications
    if (typeof Swal !== 'undefined') {
        const Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true,
            didOpen: (toast) => {
                toast.addEventListener('mouseenter', Swal.stopTimer);
                toast.addEventListener('mouseleave', Swal.resumeTimer);
            }
        });

        Toast.fire({
            icon: type === 'error' ? 'error' : type,
            title: message
        });
    } else {
        // Fallback to basic alert
        alert(message);
    }
}
</script>

<?php include 'includes/footer.php'; ?>