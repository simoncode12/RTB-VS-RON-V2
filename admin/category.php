<?php
include 'includes/header.php';

$message = '';
$message_type = 'success';

// Handle form submission for adding new category
if ($_POST && !isset($_POST['action'])) {
    try {
        $name = $_POST['name'] ?? '';
        $description = $_POST['description'] ?? '';
        $type = $_POST['type'] ?? 'mainstream';
        
        if ($name) {
            // Check if category name already exists
            $stmt = $pdo->prepare("SELECT id FROM categories WHERE name = ?");
            $stmt->execute([$name]);
            if ($stmt->fetch()) {
                throw new Exception('Category name already exists');
            }
            
            $stmt = $pdo->prepare("INSERT INTO categories (name, description, type, status) VALUES (?, ?, ?, 'active')");
            $stmt->execute([$name, $description, $type]);
            
            $message = 'Category added successfully!';
            $message_type = 'success';
        } else {
            $message = 'Please enter category name.';
            $message_type = 'danger';
        }
    } catch (Exception $e) {
        $message = 'Error adding category: ' . $e->getMessage();
        $message_type = 'danger';
    }
}

// Handle edit category
if ($_POST && isset($_POST['action']) && $_POST['action'] == 'edit') {
    try {
        $category_id = $_POST['category_id'] ?? '';
        $name = $_POST['name'] ?? '';
        $description = $_POST['description'] ?? '';
        $type = $_POST['type'] ?? 'mainstream';
        
        if ($category_id && $name) {
            // Check if category name already exists (excluding current category)
            $stmt = $pdo->prepare("SELECT id FROM categories WHERE name = ? AND id != ?");
            $stmt->execute([$name, $category_id]);
            if ($stmt->fetch()) {
                throw new Exception('Category name already exists');
            }
            
            $stmt = $pdo->prepare("UPDATE categories SET name = ?, description = ?, type = ? WHERE id = ?");
            $stmt->execute([$name, $description, $type, $category_id]);
            
            $message = 'Category updated successfully!';
            $message_type = 'success';
        } else {
            $message = 'Please enter category name.';
            $message_type = 'danger';
        }
    } catch (Exception $e) {
        $message = 'Error updating category: ' . $e->getMessage();
        $message_type = 'danger';
    }
}

// Handle status toggle
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $id = $_GET['id'];
    
    try {
        if ($action == 'toggle_status') {
            $stmt = $pdo->prepare("UPDATE categories SET status = CASE WHEN status = 'active' THEN 'inactive' ELSE 'active' END WHERE id = ?");
            $stmt->execute([$id]);
            $message = 'Category status updated successfully!';
        } elseif ($action == 'delete') {
            // Check if category is being used
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM campaigns WHERE category_id = ?");
            $stmt->execute([$id]);
            $campaign_count = $stmt->fetchColumn();
            
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM websites WHERE category_id = ?");
            $stmt->execute([$id]);
            $website_count = $stmt->fetchColumn();
            
            if ($campaign_count > 0 || $website_count > 0) {
                throw new Exception('Cannot delete category. It is being used by ' . ($campaign_count + $website_count) . ' items.');
            }
            
            $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
            $stmt->execute([$id]);
            $message = 'Category deleted successfully!';
        }
        $message_type = 'success';
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
        $message_type = 'danger';
    }
}

// Get all categories with usage statistics
$categories = $pdo->query("
    SELECT c.*, 
           COUNT(DISTINCT ca.id) as campaign_count,
           COUNT(DISTINCT w.id) as website_count,
           COUNT(DISTINCT CASE WHEN ca.status = 'active' THEN ca.id END) as active_campaigns,
           COUNT(DISTINCT CASE WHEN w.status = 'active' THEN w.id END) as active_websites
    FROM categories c 
    LEFT JOIN campaigns ca ON c.id = ca.category_id 
    LEFT JOIN websites w ON c.id = w.category_id 
    GROUP BY c.id 
    ORDER BY c.created_at DESC
")->fetchAll();

// Get category statistics for cards
$stats = [
    'total_categories' => $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn(),
    'active_categories' => $pdo->query("SELECT COUNT(*) FROM categories WHERE status = 'active'")->fetchColumn(),
    'adult_categories' => $pdo->query("SELECT COUNT(*) FROM categories WHERE type = 'adult'")->fetchColumn(),
    'mainstream_categories' => $pdo->query("SELECT COUNT(*) FROM categories WHERE type = 'mainstream'")->fetchColumn()
];
?>

<div class="row">
    <div class="col-12">
        <h1 class="h3 mb-4">
            <i class="fas fa-tags"></i> Category Management
            <small class="text-muted">Manage content categories for campaigns and websites</small>
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
                        <h6 class="card-title">Total Categories</h6>
                        <h4 class="mb-0"><?php echo formatNumber($stats['total_categories']); ?></h4>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-tags fa-2x"></i>
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
                        <h6 class="card-title">Active Categories</h6>
                        <h4 class="mb-0"><?php echo formatNumber($stats['active_categories']); ?></h4>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-check-circle fa-2x"></i>
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
                        <h6 class="card-title">Mainstream</h6>
                        <h4 class="mb-0"><?php echo formatNumber($stats['mainstream_categories']); ?></h4>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-globe fa-2x"></i>
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
                        <h6 class="card-title">Adult Content</h6>
                        <h4 class="mb-0"><?php echo formatNumber($stats['adult_categories']); ?></h4>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-exclamation-triangle fa-2x"></i>
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

<div class="row">
    <!-- Add New Category -->
    <div class="col-lg-4 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-plus"></i> Add New Category</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label for="name" class="form-label">Category Name *</label>
                        <input type="text" class="form-control" id="name" name="name" required
                               placeholder="e.g., Technology, Entertainment">
                    </div>
                    
                    <div class="mb-3">
                        <label for="type" class="form-label">Content Type *</label>
                        <select class="form-select" id="type" name="type" required>
                            <option value="mainstream">Mainstream</option>
                            <option value="adult">Adult Content</option>
                        </select>
                        <div class="form-text">
                            <small>
                                <i class="fas fa-info-circle"></i> 
                                Adult content requires special compliance and targeting restrictions
                            </small>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"
                                  placeholder="Brief description of this category..."></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-save"></i> Add Category
                    </button>
                </form>
            </div>
        </div>
        
        <!-- Category Guidelines -->
        <div class="card mt-4">
            <div class="card-header">
                <h6 class="mb-0"><i class="fas fa-info-circle"></i> Category Guidelines</h6>
            </div>
            <div class="card-body">
                <div class="small">
                    <h6>Mainstream Categories:</h6>
                    <ul>
                        <li>Technology & Software</li>
                        <li>Finance & Business</li>
                        <li>Entertainment & Media</li>
                        <li>Health & Wellness</li>
                        <li>Education & Learning</li>
                        <li>Travel & Tourism</li>
                        <li>Sports & Recreation</li>
                        <li>Fashion & Lifestyle</li>
                    </ul>
                    
                    <h6 class="mt-3">Adult Categories:</h6>
                    <ul>
                        <li>Adult Entertainment</li>
                        <li>Dating & Relationships</li>
                        <li>Adult Products</li>
                    </ul>
                    
                    <div class="alert alert-warning mt-3">
                        <small>
                            <strong>Note:</strong> Adult categories have additional compliance requirements and may have restricted targeting options.
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Categories List -->
    <div class="col-lg-8 mb-4">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-list"></i> Categories</h5>
                <span class="badge bg-primary"><?php echo count($categories); ?> Categories</span>
            </div>
            <div class="card-body">
                <?php if (empty($categories)): ?>
                    <div class="text-center py-4">
                        <i class="fas fa-tags fa-3x text-muted mb-3"></i>
                        <p class="text-muted">No categories created yet.</p>
                        <p class="text-muted">Create your first category using the form on the left.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Category Details</th>
                                    <th>Type</th>
                                    <th>Usage</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($categories as $category): ?>
                                <tr>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($category['name']); ?></strong>
                                            <?php if ($category['description']): ?>
                                                <br>
                                                <small class="text-muted">
                                                    <?php echo htmlspecialchars(substr($category['description'], 0, 100)); ?>
                                                    <?php echo strlen($category['description']) > 100 ? '...' : ''; ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $category['type'] == 'adult' ? 'warning' : 'info'; ?>">
                                            <?php echo ucfirst($category['type']); ?>
                                        </span>
                                        <?php if ($category['type'] == 'adult'): ?>
                                            <br><small class="text-muted">18+ Content</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="small">
                                            <div>
                                                <strong><?php echo $category['campaign_count']; ?></strong> campaigns
                                                <?php if ($category['active_campaigns'] > 0): ?>
                                                    (<span class="text-success"><?php echo $category['active_campaigns']; ?> active</span>)
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <strong><?php echo $category['website_count']; ?></strong> websites
                                                <?php if ($category['active_websites'] > 0): ?>
                                                    (<span class="text-success"><?php echo $category['active_websites']; ?> active</span>)
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($category['campaign_count'] > 0 || $category['website_count'] > 0): ?>
                                                <div class="text-muted mt-1">
                                                    Total usage: <?php echo $category['campaign_count'] + $category['website_count']; ?> items
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $category['status'] == 'active' ? 'success' : 'secondary'; ?>">
                                            <?php echo ucfirst($category['status']); ?>
                                        </span>
                                        <br>
                                        <small class="text-muted">
                                            Created: <?php echo date('M j, Y', strtotime($category['created_at'])); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-outline-primary" data-bs-toggle="tooltip" title="Edit"
                                                    onclick="editCategory(<?php echo $category['id']; ?>, '<?php echo htmlspecialchars($category['name']); ?>', '<?php echo htmlspecialchars($category['description']); ?>', '<?php echo $category['type']; ?>')">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            
                                            <a href="?action=toggle_status&id=<?php echo $category['id']; ?>" 
                                               class="btn btn-outline-<?php echo $category['status'] == 'active' ? 'warning' : 'success'; ?>" 
                                               data-bs-toggle="tooltip" 
                                               title="<?php echo $category['status'] == 'active' ? 'Deactivate' : 'Activate'; ?>">
                                                <i class="fas fa-<?php echo $category['status'] == 'active' ? 'pause' : 'play'; ?>"></i>
                                            </a>
                                            
                                            <?php if ($category['campaign_count'] == 0 && $category['website_count'] == 0): ?>
                                                <a href="?action=delete&id=<?php echo $category['id']; ?>" 
                                                   class="btn btn-outline-danger btn-delete" 
                                                   data-bs-toggle="tooltip" title="Delete"
                                                   onclick="return confirm('Are you sure you want to delete this category?')">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            <?php else: ?>
                                                <button class="btn btn-outline-secondary" 
                                                        data-bs-toggle="tooltip" 
                                                        title="Cannot delete - Category is in use"
                                                        disabled>
                                                    <i class="fas fa-lock"></i>
                                                </button>
                                            <?php endif; ?>
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

<!-- Edit Category Modal -->
<div class="modal fade" id="editCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editCategoryForm">
                <input type="hidden" id="edit_category_id" name="category_id">
                <input type="hidden" name="action" value="edit">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_name" class="form-label">Category Name *</label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_type" class="form-label">Content Type *</label>
                        <select class="form-select" id="edit_type" name="type" required>
                            <option value="mainstream">Mainstream</option>
                            <option value="adult">Adult Content</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_description" class="form-label">Description</label>
                        <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Category
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Category Usage Details Modal -->
<div class="modal fade" id="categoryUsageModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-chart-bar"></i> Category Usage Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="categoryUsageContent">
                <!-- Content will be loaded dynamically -->
            </div>
        </div>
    </div>
</div>

<script>
function editCategory(id, name, description, type) {
    document.getElementById('edit_category_id').value = id;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_description').value = description;
    document.getElementById('edit_type').value = type;
    
    new bootstrap.Modal(document.getElementById('editCategoryModal')).show();
}

function showCategoryUsage(id, name) {
    document.querySelector('#categoryUsageModal .modal-title').innerHTML = 
        '<i class="fas fa-chart-bar"></i> Usage Details for: ' + name;
    
    // Load usage details via AJAX
    fetch(`category-usage.php?id=${id}`)
        .then(response => response.text())
        .then(data => {
            document.getElementById('categoryUsageContent').innerHTML = data;
            new bootstrap.Modal(document.getElementById('categoryUsageModal')).show();
        })
        .catch(error => {
            document.getElementById('categoryUsageContent').innerHTML = 
                '<div class="alert alert-danger">Error loading usage details.</div>';
            new bootstrap.Modal(document.getElementById('categoryUsageModal')).show();
        });
}

// Form validation
document.addEventListener('DOMContentLoaded', function() {
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            const nameInput = this.querySelector('input[name="name"]');
            if (nameInput && nameInput.value.trim().length < 2) {
                e.preventDefault();
                alert('Category name must be at least 2 characters long.');
                nameInput.focus();
                return false;
            }
        });
    });
    
    // Adult content warning
    const typeSelects = document.querySelectorAll('select[name="type"]');
    typeSelects.forEach(select => {
        select.addEventListener('change', function() {
            if (this.value === 'adult') {
                if (!confirm('Adult content categories have additional compliance requirements and targeting restrictions. Continue?')) {
                    this.value = 'mainstream';
                }
            }
        });
    });
});

// Auto-capitalize category names
document.getElementById('name').addEventListener('input', function() {
    this.value = this.value.replace(/\b\w/g, l => l.toUpperCase());
});

document.getElementById('edit_name').addEventListener('input', function() {
    this.value = this.value.replace(/\b\w/g, l => l.toUpperCase());
});
</script>

<?php include 'includes/footer.php'; ?>