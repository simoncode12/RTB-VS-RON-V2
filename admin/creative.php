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
                                    <option value="image">Image Banner</option>
                                    <option value="video">Video Banner</option>
                                    <option value="html5">HTML5/Custom</option>
                                    <option value="third_party">Third Party Script</option>
                                </select>
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
                                            <button class="btn btn-outline-primary" data-bs-toggle="tooltip" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-outline-success" data-bs-toggle="tooltip" title="Preview">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="btn btn-outline-info" data-bs-toggle="tooltip" title="Stats">
                                                <i class="fas fa-chart-bar"></i>
                                            </button>
                                            <button class="btn btn-outline-danger btn-delete" data-bs-toggle="tooltip" title="Delete">
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
});
</script>

<?php include 'includes/footer.php'; ?>