<?php
include 'includes/header.php';

$message = '';
$message_type = 'success';

// Get publisher filter
$publisher_id = $_GET['publisher_id'] ?? null;

// Get publishers for dropdown
$publishers = $pdo->query("SELECT id, name FROM publishers WHERE status = 'active' ORDER BY name")->fetchAll();
$categories = $pdo->query("SELECT id, name FROM categories WHERE status = 'active' ORDER BY name")->fetchAll();

// Handle form submission
if ($_POST) {
    try {
        $name = $_POST['name'] ?? '';
        $domain = $_POST['domain'] ?? '';
        $publisher_id_post = $_POST['publisher_id'] ?? '';
        $category_id = $_POST['category_id'] ?? null;
        $description = $_POST['description'] ?? '';
        
        if ($name && $domain && $publisher_id_post) {
            // Clean domain (remove protocol and trailing slash)
            $domain = preg_replace('#^https?://#', '', $domain);
            $domain = rtrim($domain, '/');
            
            // Check if domain already exists
            $stmt = $pdo->prepare("SELECT id FROM websites WHERE domain = ? AND id != ?");
            $stmt->execute([$domain, 0]);
            if ($stmt->fetch()) {
                throw new Exception('Domain already exists in the system');
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO websites (publisher_id, name, domain, category_id, description, status) 
                VALUES (?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([$publisher_id_post, $name, $domain, $category_id, $description]);
            
            $message = 'Website added successfully and is pending approval!';
            $message_type = 'success';
        } else {
            $message = 'Please fill in all required fields.';
            $message_type = 'danger';
        }
    } catch (Exception $e) {
        $message = 'Error adding website: ' . $e->getMessage();
        $message_type = 'danger';
    }
}

// Handle status updates
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $id = $_GET['id'];
    
    try {
        if ($action == 'approve') {
            $stmt = $pdo->prepare("UPDATE websites SET status = 'active' WHERE id = ?");
            $stmt->execute([$id]);
            $message = 'Website approved successfully!';
        } elseif ($action == 'reject') {
            $stmt = $pdo->prepare("UPDATE websites SET status = 'inactive' WHERE id = ?");
            $stmt->execute([$id]);
            $message = 'Website rejected!';
        } elseif ($action == 'suspend') {
            $stmt = $pdo->prepare("UPDATE websites SET status = 'inactive' WHERE id = ?");
            $stmt->execute([$id]);
            $message = 'Website suspended!';
        }
        $message_type = 'success';
    } catch (Exception $e) {
        $message = 'Error updating website: ' . $e->getMessage();
        $message_type = 'danger';
    }
}

// Build websites query with filters
$websites_query = "
    SELECT w.*, p.name as publisher_name, p.email as publisher_email,
           c.name as category_name,
           COUNT(DISTINCT z.id) as zone_count,
           COUNT(DISTINCT CASE WHEN z.status = 'active' THEN z.id END) as active_zones,
           COALESCE(SUM(rt.impressions), 0) as total_impressions,
           COALESCE(SUM(rt.clicks), 0) as total_clicks,
           COALESCE(SUM(rt.revenue), 0) as total_revenue
    FROM websites w 
    LEFT JOIN publishers p ON w.publisher_id = p.id 
    LEFT JOIN categories c ON w.category_id = c.id 
    LEFT JOIN zones z ON w.id = z.website_id 
    LEFT JOIN revenue_tracking rt ON z.id = rt.zone_id
    WHERE 1=1
";

$params = [];

if ($publisher_id) {
    $websites_query .= " AND p.id = ?";
    $params[] = $publisher_id;
}

$websites_query .= " GROUP BY w.id ORDER BY w.created_at DESC";

$stmt = $pdo->prepare($websites_query);
$stmt->execute($params);
$websites = $stmt->fetchAll();

// Get website statistics for cards
$stats = [
    'total_websites' => $pdo->query("SELECT COUNT(*) FROM websites")->fetchColumn(),
    'active_websites' => $pdo->query("SELECT COUNT(*) FROM websites WHERE status = 'active'")->fetchColumn(),
    'pending_websites' => $pdo->query("SELECT COUNT(*) FROM websites WHERE status = 'pending'")->fetchColumn(),
    'total_zones' => $pdo->query("SELECT COUNT(*) FROM zones z JOIN websites w ON z.website_id = w.id WHERE w.status = 'active'")->fetchColumn()
];
?>

<div class="row">
    <div class="col-12">
        <h1 class="h3 mb-4">
            <i class="fas fa-sitemap"></i> Website Management
            <small class="text-muted">Manage publisher websites and domains</small>
        </h1>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card bg-primary text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="card-title">Total Websites</h6>
                        <h4 class="mb-0"><?php echo formatNumber($stats['total_websites']); ?></h4>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-sitemap fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card bg-success text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="card-title">Active</h6>
                        <h4 class="mb-0"><?php echo formatNumber($stats['active_websites']); ?></h4>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-check-circle fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card bg-warning text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="card-title">Pending Approval</h6>
                        <h4 class="mb-0"><?php echo formatNumber($stats['pending_websites']); ?></h4>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-clock fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card bg-info text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="card-title">Total Zones</h6>
                        <h4 class="mb-0"><?php echo formatNumber($stats['total_zones']); ?></h4>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-map-marker-alt fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Filter and Add Section -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label for="publisher_filter" class="form-label">Filter by Publisher</label>
                        <select class="form-select" id="publisher_filter" name="publisher_id" onchange="this.form.submit()">
                            <option value="">All Publishers</option>
                            <?php foreach ($publishers as $pub): ?>
                                <option value="<?php echo $pub['id']; ?>" <?php echo $publisher_id == $pub['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($pub['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <a href="website.php" class="btn btn-outline-secondary">Clear Filter</a>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addWebsiteModal">
                            <i class="fas fa-plus"></i> Add Website
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Websites List -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-list"></i> Websites</h5>
                <span class="badge bg-primary"><?php echo count($websites); ?> Websites</span>
            </div>
            <div class="card-body">
                <?php if (empty($websites)): ?>
                    <div class="text-center py-4">
                        <i class="fas fa-sitemap fa-3x text-muted mb-3"></i>
                        <p class="text-muted">No websites found.</p>
                        <?php if ($publisher_id): ?>
                            <p class="text-muted">Try clearing the filter or add a new website.</p>
                        <?php else: ?>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addWebsiteModal">
                                <i class="fas fa-plus"></i> Add First Website
                            </button>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Website Details</th>
                                    <th>Publisher</th>
                                    <th>Category</th>
                                    <th>Zones</th>
                                    <th>Performance</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($websites as $website): ?>
                                <tr>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($website['name']); ?></strong>
                                            <br>
                                            <a href="https://<?php echo htmlspecialchars($website['domain']); ?>" 
                                               target="_blank" class="text-decoration-none">
                                                <i class="fas fa-external-link-alt"></i> <?php echo htmlspecialchars($website['domain']); ?>
                                            </a>
                                            <?php if ($website['description']): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars(substr($website['description'], 0, 100)); ?><?php echo strlen($website['description']) > 100 ? '...' : ''; ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($website['publisher_name']); ?></strong>
                                            <br>
                                            <small class="text-muted">
                                                <a href="mailto:<?php echo htmlspecialchars($website['publisher_email']); ?>">
                                                    <?php echo htmlspecialchars($website['publisher_email']); ?>
                                                </a>
                                            </small>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($website['category_name']): ?>
                                            <span class="badge bg-secondary">
                                                <?php echo htmlspecialchars($website['category_name']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">Uncategorized</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="text-center">
                                            <div>
                                                <span class="badge bg-primary"><?php echo $website['zone_count']; ?> Total</span>
                                                <?php if ($website['active_zones'] > 0): ?>
                                                    <span class="badge bg-success"><?php echo $website['active_zones']; ?> Active</span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($website['zone_count'] > 0): ?>
                                                <small>
                                                    <a href="zone.php?website_id=<?php echo $website['id']; ?>" class="text-decoration-none">
                                                        Manage Zones
                                                    </a>
                                                </small>
                                            <?php else: ?>
                                                <small>
                                                    <a href="zone.php?website_id=<?php echo $website['id']; ?>" class="text-decoration-none">
                                                        Add Zones
                                                    </a>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="small">
                                            <div><strong><?php echo formatNumber($website['total_impressions']); ?></strong> impressions</div>
                                            <div><strong><?php echo formatNumber($website['total_clicks']); ?></strong> clicks</div>
                                            <div><strong><?php echo formatCurrency($website['total_revenue']); ?></strong> revenue</div>
                                            <?php if ($website['total_impressions'] > 0): ?>
                                                <div class="text-muted">
                                                    CTR: <?php echo number_format(($website['total_clicks'] / $website['total_impressions']) * 100, 2); ?>%
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $website['status'] == 'active' ? 'success' : 
                                                ($website['status'] == 'pending' ? 'warning' : 'secondary'); 
                                        ?>">
                                            <?php echo ucfirst($website['status']); ?>
                                        </span>
                                        <br>
                                        <small class="text-muted">
                                            Added: <?php echo date('M j, Y', strtotime($website['created_at'])); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-outline-primary" data-bs-toggle="tooltip" title="Edit" 
                                                    onclick="editWebsite(<?php echo $website['id']; ?>, '<?php echo htmlspecialchars($website['name']); ?>', '<?php echo htmlspecialchars($website['domain']); ?>', <?php echo $website['publisher_id']; ?>, <?php echo $website['category_id'] ?: 'null'; ?>, '<?php echo htmlspecialchars($website['description']); ?>')">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            
                                            <?php if ($website['status'] == 'pending'): ?>
                                                <a href="?action=approve&id=<?php echo $website['id']; ?>" 
                                                   class="btn btn-outline-success" data-bs-toggle="tooltip" title="Approve">
                                                    <i class="fas fa-check"></i>
                                                </a>
                                                <a href="?action=reject&id=<?php echo $website['id']; ?>" 
                                                   class="btn btn-outline-danger" data-bs-toggle="tooltip" title="Reject">
                                                    <i class="fas fa-times"></i>
                                                </a>
                                            <?php elseif ($website['status'] == 'active'): ?>
                                                <a href="?action=suspend&id=<?php echo $website['id']; ?>" 
                                                   class="btn btn-outline-warning" data-bs-toggle="tooltip" title="Suspend">
                                                    <i class="fas fa-pause"></i>
                                                </a>
                                            <?php endif; ?>
                                            
                                            <a href="zone.php?website_id=<?php echo $website['id']; ?>" 
                                               class="btn btn-outline-info" data-bs-toggle="tooltip" title="Zones">
                                                <i class="fas fa-map-marker-alt"></i>
                                            </a>
                                            
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
    </div>
</div>

<!-- Add Website Modal -->
<div class="modal fade" id="addWebsiteModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus"></i> Add New Website</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="name" class="form-label">Website Name *</label>
                            <input type="text" class="form-control" id="name" name="name" required
                                   placeholder="e.g., My Gaming Blog">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="domain" class="form-label">Domain *</label>
                            <input type="text" class="form-control" id="domain" name="domain" required
                                   placeholder="example.com">
                            <div class="form-text">Enter domain without http:// or https://</div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="publisher_id" class="form-label">Publisher *</label>
                            <select class="form-select" id="publisher_id" name="publisher_id" required>
                                <option value="">Select Publisher</option>
                                <?php foreach ($publishers as $publisher): ?>
                                    <option value="<?php echo $publisher['id']; ?>" <?php echo $publisher_id == $publisher['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($publisher['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
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
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"
                                  placeholder="Brief description of the website content and audience..."></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <h6><i class="fas fa-info-circle"></i> Important Notes:</h6>
                        <ul class="mb-0">
                            <li>Website will be marked as "Pending" and require approval</li>
                            <li>Domain must be accessible and owned by the publisher</li>
                            <li>After approval, you can add ad zones to this website</li>
                            <li>Revenue sharing will be based on publisher settings</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Add Website
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Website Modal -->
<div class="modal fade" id="editWebsiteModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Website</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editWebsiteForm">
                <input type="hidden" id="edit_website_id" name="website_id">
                <input type="hidden" name="action" value="edit">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_name" class="form-label">Website Name *</label>
                            <input type="text" class="form-control" id="edit_name" name="name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_domain" class="form-label">Domain *</label>
                            <input type="text" class="form-control" id="edit_domain" name="domain" required>
                            <div class="form-text">Enter domain without http:// or https://</div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_publisher_id" class="form-label">Publisher *</label>
                            <select class="form-select" id="edit_publisher_id" name="publisher_id" required>
                                <option value="">Select Publisher</option>
                                <?php foreach ($publishers as $publisher): ?>
                                    <option value="<?php echo $publisher['id']; ?>">
                                        <?php echo htmlspecialchars($publisher['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_category_id" class="form-label">Category</label>
                            <select class="form-select" id="edit_category_id" name="category_id">
                                <option value="">Select Category</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>">
                                        <?php echo htmlspecialchars($category['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_description" class="form-label">Description</label>
                        <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Website
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editWebsite(id, name, domain, publisherId, categoryId, description) {
    document.getElementById('edit_website_id').value = id;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_domain').value = domain;
    document.getElementById('edit_publisher_id').value = publisherId;
    document.getElementById('edit_category_id').value = categoryId || '';
    document.getElementById('edit_description').value = description;
    
    new bootstrap.Modal(document.getElementById('editWebsiteModal')).show();
}

// Auto-format domain input
document.getElementById('domain').addEventListener('input', function() {
    this.value = this.value.toLowerCase().replace(/[^a-z0-9.-]/g, '');
});

document.getElementById('edit_domain').addEventListener('input', function() {
    this.value = this.value.toLowerCase().replace(/[^a-z0-9.-]/g, '');
});

// Handle edit form submission
document.getElementById('editWebsiteForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    fetch('website.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(data => {
        location.reload();
    })
    .catch(error => {
        alert('Error updating website: ' + error.message);
    });
});

// Domain validation
function validateDomain(domain) {
    const pattern = /^[a-zA-Z0-9][a-zA-Z0-9-]{0,61}[a-zA-Z0-9](?:\.[a-zA-Z]{2,})+$/;
    return pattern.test(domain);
}

document.addEventListener('DOMContentLoaded', function() {
    // Add domain validation to forms
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            const domainInput = this.querySelector('input[name="domain"]');
            if (domainInput && domainInput.value) {
                const domain = domainInput.value.trim();
                if (!validateDomain(domain)) {
                    e.preventDefault();
                    alert('Please enter a valid domain name (e.g., example.com)');
                    domainInput.focus();
                    return false;
                }
            }
        });
    });
});
</script>

<?php include 'includes/footer.php'; ?>