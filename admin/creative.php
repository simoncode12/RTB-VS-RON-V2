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
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h2 mb-2 text-gradient">
                    <i class="fas fa-images me-2"></i> Creative Management
                </h1>
                <p class="text-muted mb-0">Create and manage stunning advertising creatives</p>
            </div>
            <div class="d-flex align-items-center gap-3">
                <div class="text-muted small">
                    <i class="fas fa-clock me-1"></i> <?php echo $current_timestamp; ?>
                </div>
                <div class="text-muted small">
                    <i class="fas fa-user me-1"></i> <?php echo htmlspecialchars($current_user); ?>
                </div>
            </div>
        </div>
        
        <?php if ($campaign && $is_new_campaign): ?>
            <div class="alert alert-success d-flex align-items-center">
                <i class="fas fa-check-circle me-3 fs-4"></i>
                <div>
                    <strong>Campaign Created Successfully!</strong><br>
                    <small>Campaign "<strong><?php echo htmlspecialchars($campaign['name']); ?></strong>" is ready. Now add some creative content to start your advertising campaign.</small>
                </div>
            </div>
        <?php endif; ?>
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

<div class="row">
    <!-- Create New Creative -->
    <div class="col-lg-5 mb-4">
        <div class="card creative-card">
            <div class="card-header d-flex align-items-center">
                <div class="me-3">
                    <div class="bg-primary rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                        <i class="fas fa-plus text-white"></i>
                    </div>
                </div>
                <div>
                    <h5 class="mb-0">Create New Creative</h5>
                    <small class="text-muted">Design your advertising content</small>
                </div>
            </div>
            <div class="card-body">
                <form method="POST" id="creativeForm">
                    <div class="mb-4">
                        <label for="campaign_id" class="form-label">
                            <i class="fas fa-bullhorn me-2"></i>Select Campaign *
                        </label>
                        <select class="form-select" id="campaign_id" name="campaign_id" required>
                            <option value="">Choose a campaign...</option>
                            <?php foreach ($campaigns as $camp): ?>
                                <option value="<?php echo $camp['id']; ?>" <?php echo ($campaign_id == $camp['id']) ? 'selected' : ''; ?>>
                                    [<?php echo strtoupper($camp['type']); ?>] <?php echo htmlspecialchars($camp['name']); ?> - <?php echo htmlspecialchars($camp['advertiser_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <?php if ($campaign): ?>
                        <div class="alert alert-light border-start border-4 border-primary">
                            <div class="d-flex align-items-center mb-2">
                                <i class="fas fa-bullhorn text-primary me-2"></i>
                                <strong>Selected Campaign</strong>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <small class="text-muted d-block">Campaign Name</small>
                                    <strong><?php echo htmlspecialchars($campaign['name']); ?></strong>
                                </div>
                                <div class="col-md-6">
                                    <small class="text-muted d-block">Type</small>
                                    <span class="badge bg-<?php echo $campaign['type'] == 'rtb' ? 'primary' : 'success'; ?>">
                                        <?php echo strtoupper($campaign['type']); ?>
                                    </span>
                                </div>
                                <div class="col-12">
                                    <small class="text-muted d-block">Advertiser</small>
                                    <strong><?php echo htmlspecialchars($campaign['advertiser_name']); ?></strong>
                                </div>
                            </div>
                        </div>
                        
                        <!-- RTB Campaign with endpoint -->
                        <?php if ($campaign['type'] == 'rtb' && !empty($campaign['endpoint_url'])): ?>
                            <div class="alert alert-info border-start border-4 border-info">
                                <div class="d-flex align-items-start">
                                    <i class="fas fa-exchange-alt text-info me-3 mt-1"></i>
                                    <div class="flex-grow-1">
                                        <strong>RTB External Endpoint Campaign</strong>
                                        <p class="mb-2 mt-1">This campaign uses an external RTB endpoint for dynamic content delivery.</p>
                                        <div class="bg-light p-2 rounded mt-2">
                                            <small class="font-monospace text-break">
                                                <?php echo htmlspecialchars(substr($campaign['endpoint_url'], 0, 60) . (strlen($campaign['endpoint_url']) > 60 ? '...' : '')); ?>
                                            </small>
                                        </div>
                                        <hr class="my-2">
                                        <small class="text-muted">
                                            <i class="fas fa-info-circle me-1"></i>
                                            You only need to configure size and bid parameters. Creative content will be provided dynamically.
                                        </small>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <label for="name" class="form-label">
                                <i class="fas fa-tag me-2"></i>Creative Name *
                            </label>
                            <input type="text" class="form-control" id="name" name="name" required 
                                   placeholder="e.g., Summer Sale Banner 300x250">
                        </div>
                        
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="width" class="form-label">
                                    <i class="fas fa-expand-arrows-alt me-2"></i>Ad Size *
                                </label>
                                <select class="form-select" id="width" name="width" required onchange="updateHeight()">
                                    <option value="">Choose size...</option>
                                    <?php foreach (getBannerSizes() as $size => $label): ?>
                                        <option value="<?php echo explode('x', $size)[0]; ?>" data-height="<?php echo explode('x', $size)[1]; ?>">
                                            <?php echo $label; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="height" class="form-label">Height (auto)</label>
                                <input type="number" class="form-control" id="height" name="height" required readonly 
                                       style="background-color: #f8f9fa;">
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label for="bid_amount" class="form-label">
                                <i class="fas fa-dollar-sign me-2"></i>Bid Amount (USD) *
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" class="form-control" id="bid_amount" name="bid_amount" 
                                       step="0.0001" min="0.0001" required placeholder="0.0050">
                            </div>
                            <div class="form-text">
                                <i class="fas fa-info-circle me-1"></i>
                                Set your maximum bid per impression. Higher bids increase win probability.
                            </div>
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
        <div class="card creative-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <div class="bg-success rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                            <i class="fas fa-list text-white"></i>
                        </div>
                    </div>
                    <div>
                        <h5 class="mb-0">Creative Library</h5>
                        <small class="text-muted">Manage your advertising creatives</small>
                    </div>
                </div>
                <?php if ($campaign): ?>
                    <div class="d-flex align-items-center gap-3">
                        <span class="badge bg-primary fs-6"><?php echo count($creatives); ?> Creatives</span>
                        <?php if (!empty($creatives)): ?>
                            <button class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-download me-1"></i>Export
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!$campaign): ?>
                    <div class="text-center py-5">
                        <div class="mb-4">
                            <i class="fas fa-images text-muted" style="font-size: 4rem; opacity: 0.3;"></i>
                        </div>
                        <h5 class="text-muted mb-2">No Campaign Selected</h5>
                        <p class="text-muted mb-4">Select a campaign from the dropdown above to view and manage its creatives.</p>
                        <div class="d-flex justify-content-center">
                            <button class="btn btn-outline-primary" onclick="document.getElementById('campaign_id').focus()">
                                <i class="fas fa-search me-2"></i>Choose Campaign
                            </button>
                        </div>
                    </div>
                <?php elseif (empty($creatives)): ?>
                    <div class="text-center py-5">
                        <div class="mb-4">
                            <i class="fas fa-plus-circle text-muted" style="font-size: 4rem; opacity: 0.3;"></i>
                        </div>
                        <h5 class="text-muted mb-2">No Creatives Yet</h5>
                        <p class="text-muted mb-4">This campaign doesn't have any creatives. Create your first one to start advertising!</p>
                        <div class="d-flex justify-content-center">
                            <button class="btn btn-primary" onclick="document.getElementById('name').focus()">
                                <i class="fas fa-plus me-2"></i>Create First Creative
                            </button>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="creative-grid">
                        <?php foreach ($creatives as $creative): ?>
                            <div class="creative-card">
                                <div class="card-body p-3">
                                    <div class="creative-preview-container">
                                        <?php if ($campaign['type'] == 'rtb' && !empty($campaign['endpoint_url']) && $creative['creative_type'] == 'third_party'): ?>
                                            <div class="text-center">
                                                <i class="fas fa-exchange-alt text-primary fs-2 mb-2"></i>
                                                <div class="small text-muted">RTB External Content</div>
                                            </div>
                                            <div class="creative-type-badge">RTB</div>
                                        <?php elseif ($creative['creative_type'] == 'image' && $creative['image_url']): ?>
                                            <img src="<?php echo htmlspecialchars($creative['image_url']); ?>" 
                                                 class="img-fluid rounded" 
                                                 style="max-height: 100px; max-width: 100%; object-fit: contain;"
                                                 alt="Creative Preview">
                                            <div class="creative-type-badge">IMAGE</div>
                                        <?php elseif ($creative['creative_type'] == 'video' && $creative['video_url']): ?>
                                            <div class="text-center">
                                                <i class="fas fa-play-circle text-primary fs-2 mb-2"></i>
                                                <div class="small text-muted">Video Content</div>
                                            </div>
                                            <div class="creative-type-badge">VIDEO</div>
                                        <?php else: ?>
                                            <div class="text-center">
                                                <i class="fas fa-code text-info fs-2 mb-2"></i>
                                                <div class="small text-muted">HTML Content</div>
                                            </div>
                                            <div class="creative-type-badge">HTML</div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <h6 class="mb-2 fw-bold"><?php echo htmlspecialchars($creative['name']); ?></h6>
                                        <div class="d-flex flex-wrap gap-1 mb-2">
                                            <span class="badge bg-light text-dark"><?php echo $creative['width']; ?>×<?php echo $creative['height']; ?></span>
                                            <span class="badge bg-success"><?php echo formatCurrency($creative['bid_amount']); ?></span>
                                            <span class="badge bg-secondary"><?php echo ucfirst($creative['creative_type']); ?></span>
                                        </div>
                                        
                                        <?php if ($campaign['type'] == 'rtb' && !empty($campaign['endpoint_url']) && $creative['creative_type'] == 'third_party'): ?>
                                            <div class="alert alert-info p-2 mb-2">
                                                <small>
                                                    <i class="fas fa-info-circle me-1"></i>
                                                    Content provided by RTB endpoint
                                                </small>
                                            </div>
                                        <?php elseif (!($campaign['type'] == 'rtb' && !empty($campaign['endpoint_url']) && $creative['creative_type'] == 'third_party')): ?>
                                            <div class="mb-2">
                                                <small class="text-muted d-block mb-1">
                                                    <i class="fas fa-link me-1"></i><strong>Click URL:</strong>
                                                </small>
                                                <small class="text-break">
                                                    <a href="<?php echo htmlspecialchars($creative['click_url']); ?>" target="_blank" 
                                                       class="text-decoration-none text-primary">
                                                        <?php echo htmlspecialchars(substr($creative['click_url'], 0, 35) . (strlen($creative['click_url']) > 35 ? '...' : '')); ?>
                                                    </a>
                                                </small>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="text-muted">
                                            <small>
                                                <i class="fas fa-calendar me-1"></i>
                                                Created <?php echo date('M j, Y', strtotime($creative['created_at'])); ?>
                                            </small>
                                        </div>
                                    </div>
                                    
                                    <div class="creative-actions">
                                        <button class="btn btn-outline-primary btn-sm" data-bs-toggle="tooltip" title="Edit Creative">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-outline-success btn-sm" data-bs-toggle="tooltip" title="Preview">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-outline-info btn-sm" data-bs-toggle="tooltip" title="Statistics">
                                            <i class="fas fa-chart-bar"></i>
                                        </button>
                                        <button class="btn btn-outline-danger btn-sm btn-delete" data-bs-toggle="tooltip" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
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
// Enhanced Creative Management JavaScript
document.addEventListener('DOMContentLoaded', function() {
    initializeCreativeManagement();
});

function initializeCreativeManagement() {
    // Initialize all components
    setupFormValidation();
    setupTooltips();
    setupDropdownHandlers();
    setupPreviewFeatures();
    
    // Call existing functions
    toggleCreativeFields();
}

function setupFormValidation() {
    const form = document.getElementById('creativeForm');
    if (!form) return;
    
    // Real-time validation
    const inputs = form.querySelectorAll('input[required], select[required]');
    inputs.forEach(input => {
        input.addEventListener('blur', validateField);
        input.addEventListener('input', clearFieldError);
    });
    
    // Bid amount validation
    const bidInput = document.getElementById('bid_amount');
    if (bidInput) {
        bidInput.addEventListener('input', function() {
            const value = parseFloat(this.value);
            if (value < 0.0001) {
                showFieldError(this, 'Minimum bid amount is $0.0001');
            } else if (value > 100) {
                showFieldWarning(this, 'High bid amount detected');
            } else {
                clearFieldError(this);
            }
        });
    }
}

function validateField(event) {
    const field = event.target;
    if (!field.value.trim() && field.hasAttribute('required')) {
        showFieldError(field, 'This field is required');
    } else {
        clearFieldError(field);
    }
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

function setupTooltips() {
    // Initialize Bootstrap tooltips with custom options
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl, {
            delay: { show: 500, hide: 100 },
            placement: 'top'
        });
    });
}

function setupDropdownHandlers() {
    const campaignSelect = document.getElementById('campaign_id');
    if (campaignSelect) {
        campaignSelect.addEventListener('change', function() {
            if (this.value) {
                // Show loading state
                showLoadingState();
                
                // Redirect to load campaign data
                setTimeout(() => {
                    window.location.href = 'creative.php?campaign_id=' + this.value;
                }, 300);
            }
        });
    }
}

function setupPreviewFeatures() {
    // Add preview functionality to creative cards
    const previewButtons = document.querySelectorAll('.btn-outline-success');
    previewButtons.forEach(button => {
        button.addEventListener('click', function() {
            // In a real implementation, this would show a modal with creative preview
            showNotification('Preview feature coming soon!', 'info');
        });
    });
}

function showLoadingState() {
    const body = document.body;
    const loader = document.createElement('div');
    loader.className = 'position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center';
    loader.style.backgroundColor = 'rgba(255, 255, 255, 0.8)';
    loader.style.zIndex = '9999';
    loader.innerHTML = `
        <div class="text-center">
            <div class="spinner-border text-primary mb-3" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <div class="text-muted">Loading campaign data...</div>
        </div>
    `;
    body.appendChild(loader);
}

function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
    notification.style.top = '20px';
    notification.style.right = '20px';
    notification.style.zIndex = '9999';
    notification.style.minWidth = '300px';
    
    notification.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : 'info-circle'} me-2"></i>
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

function updateHeight() {
    const widthSelect = document.getElementById('width');
    const heightInput = document.getElementById('height');
    const selectedOption = widthSelect.options[widthSelect.selectedIndex];
    
    if (selectedOption && selectedOption.dataset.height) {
        heightInput.value = selectedOption.dataset.height;
        
        // Animate the update
        heightInput.style.backgroundColor = '#e3f2fd';
        setTimeout(() => {
            heightInput.style.backgroundColor = '#f8f9fa';
        }, 500);
        
        // Show size preview
        showSizePreview(widthSelect.value, selectedOption.dataset.height);
    }
}

function showSizePreview(width, height) {
    // Remove existing preview
    const existingPreview = document.querySelector('.size-preview');
    if (existingPreview) {
        existingPreview.remove();
    }
    
    // Create new preview
    const preview = document.createElement('div');
    preview.className = 'size-preview mt-2 p-2 border rounded bg-light';
    preview.innerHTML = `
        <small class="text-muted">
            <i class="fas fa-eye me-1"></i>
            Preview size: <strong>${width}×${height}px</strong>
        </small>
        <div class="mt-1" style="width: ${Math.min(width/2, 100)}px; height: ${Math.min(height/2, 50)}px; background: linear-gradient(45deg, #007bff, #17a2b8); border-radius: 2px;"></div>
    `;
    
    const heightInput = document.getElementById('height');
    heightInput.parentNode.appendChild(preview);
}

function toggleCreativeFields() {
    const creativeType = document.getElementById('creative_type');
    if (!creativeType) return; // Exit if not found (like in RTB campaigns)
    
    const imageFields = document.getElementById('image_fields');
    const videoFields = document.getElementById('video_fields');
    const htmlFields = document.getElementById('html_fields');
    
    if (!imageFields || !videoFields || !htmlFields) return; // Safety check
    
    // Hide all fields first with smooth transition
    [imageFields, videoFields, htmlFields].forEach(field => {
        field.style.opacity = '0';
        field.style.display = 'none';
    });
    
    // Show relevant fields with animation
    let targetField;
    switch (creativeType.value) {
        case 'image':
            targetField = imageFields;
            break;
        case 'video':
            targetField = videoFields;
            break;
        case 'html5':
        case 'third_party':
            targetField = htmlFields;
            break;
    }
    
    if (targetField) {
        targetField.style.display = 'block';
        setTimeout(() => {
            targetField.style.opacity = '1';
        }, 50);
    }
}

// Enhanced creative type field toggling with validation
if (document.getElementById('creative_type')) {
    document.getElementById('creative_type').addEventListener('change', toggleCreativeFields);
}
</script>

<?php include 'includes/footer.php'; ?>