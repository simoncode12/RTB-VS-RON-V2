<?php
include 'includes/header.php';

$message = '';
$message_type = 'success';

// Handle form submission
if ($_POST) {
    try {
        $name = $_POST['name'] ?? '';
        $email = $_POST['email'] ?? '';
        $website = $_POST['website'] ?? '';
        $contact_person = $_POST['contact_person'] ?? '';
        $revenue_share = $_POST['revenue_share'] ?? 50.00;
        $payment_method = $_POST['payment_method'] ?? 'paypal';
        $payment_details = $_POST['payment_details'] ?? '';
        
        if ($name && $email) {
            $stmt = $pdo->prepare("
                INSERT INTO publishers (name, email, website, contact_person, revenue_share, payment_method, payment_details) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$name, $email, $website, $contact_person, $revenue_share, $payment_method, $payment_details]);
            
            $message = 'Publisher added successfully!';
            $message_type = 'success';
        } else {
            $message = 'Please enter publisher name and email.';
            $message_type = 'danger';
        }
    } catch (Exception $e) {
        $message = 'Error adding publisher: ' . $e->getMessage();
        $message_type = 'danger';
    }
}

// Handle status updates
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $id = $_GET['id'];
    
    if ($action == 'approve') {
        $stmt = $pdo->prepare("UPDATE publishers SET status = 'active' WHERE id = ?");
        $stmt->execute([$id]);
        $message = 'Publisher approved successfully!';
    } elseif ($action == 'suspend') {
        $stmt = $pdo->prepare("UPDATE publishers SET status = 'inactive' WHERE id = ?");
        $stmt->execute([$id]);
        $message = 'Publisher suspended successfully!';
    }
}

// Get all publishers with statistics
$publishers = $pdo->query("
    SELECT p.*, 
           COUNT(DISTINCT w.id) as website_count,
           COUNT(DISTINCT z.id) as zone_count,
           COALESCE(SUM(rt.impressions), 0) as total_impressions,
           COALESCE(SUM(rt.clicks), 0) as total_clicks,
           COALESCE(SUM(rt.publisher_revenue), 0) as total_revenue
    FROM publishers p 
    LEFT JOIN websites w ON p.id = w.publisher_id AND w.status = 'active'
    LEFT JOIN zones z ON w.id = z.website_id AND z.status = 'active'
    LEFT JOIN revenue_tracking rt ON p.id = rt.publisher_id
    GROUP BY p.id 
    ORDER BY p.created_at DESC
")->fetchAll();
?>

<div class="row">
    <div class="col-12">
        <h1 class="h3 mb-4">
            <i class="fas fa-globe"></i> Publisher Management
            <small class="text-muted">Manage your publishing partners</small>
        </h1>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row">
    <!-- Add New Publisher -->
    <div class="col-lg-4 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-plus"></i> Add New Publisher</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label for="name" class="form-label">Publisher Name *</label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="email" class="form-label">Email *</label>
                        <input type="email" class="form-control" id="email" name="email" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="website" class="form-label">Main Website</label>
                        <input type="url" class="form-control" id="website" name="website" 
                               placeholder="https://example.com">
                    </div>
                    
                    <div class="mb-3">
                        <label for="contact_person" class="form-label">Contact Person</label>
                        <input type="text" class="form-control" id="contact_person" name="contact_person">
                    </div>
                    
                    <div class="mb-3">
                        <label for="revenue_share" class="form-label">Revenue Share (%)</label>
                        <input type="number" class="form-control" id="revenue_share" name="revenue_share" 
                               value="50.00" step="0.01" min="0" max="100">
                        <div class="form-text">Default: 50% for publisher, 50% for platform</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="payment_method" class="form-label">Payment Method</label>
                        <select class="form-select" id="payment_method" name="payment_method">
                            <option value="paypal">PayPal</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="wire">Wire Transfer</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="payment_details" class="form-label">Payment Details</label>
                        <textarea class="form-control" id="payment_details" name="payment_details" rows="3"
                                  placeholder="PayPal email, bank account details, etc."></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-save"></i> Add Publisher
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Publishers List -->
    <div class="col-lg-8 mb-4">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-list"></i> Publishers</h5>
                <span class="badge bg-primary"><?php echo count($publishers); ?> Publishers</span>
            </div>
            <div class="card-body">
                <?php if (empty($publishers)): ?>
                    <div class="text-center py-4">
                        <i class="fas fa-globe fa-3x text-muted mb-3"></i>
                        <p class="text-muted">No publishers added yet.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Publisher</th>
                                    <th>Contact Info</th>
                                    <th>Revenue Share</th>
                                    <th>Performance</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($publishers as $publisher): ?>
                                <tr>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($publisher['name']); ?></strong>
                                            <?php if ($publisher['website']): ?>
                                                <br>
                                                <a href="<?php echo htmlspecialchars($publisher['website']); ?>" 
                                                   target="_blank" class="text-decoration-none small">
                                                    <i class="fas fa-external-link-alt"></i> <?php echo parse_url($publisher['website'], PHP_URL_HOST); ?>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($publisher['contact_person']): ?>
                                            <div><strong><?php echo htmlspecialchars($publisher['contact_person']); ?></strong></div>
                                        <?php endif; ?>
                                        <div><a href="mailto:<?php echo htmlspecialchars($publisher['email']); ?>"><?php echo htmlspecialchars($publisher['email']); ?></a></div>
                                        <div class="small text-muted">
                                            Payment: <?php echo ucfirst(str_replace('_', ' ', $publisher['payment_method'])); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="text-center">
                                            <strong class="text-primary"><?php echo number_format($publisher['revenue_share'], 1); ?>%</strong>
                                            <br>
                                            <small class="text-muted">Publisher Share</small>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="small">
                                            <div><strong><?php echo formatNumber($publisher['total_impressions']); ?></strong> impressions</div>
                                            <div><strong><?php echo formatNumber($publisher['total_clicks']); ?></strong> clicks</div>
                                            <div><strong><?php echo formatCurrency($publisher['total_revenue']); ?></strong> earned</div>
                                            <div class="text-muted">
                                                <?php echo $publisher['website_count']; ?> websites, <?php echo $publisher['zone_count']; ?> zones
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $publisher['status'] == 'active' ? 'success' : 
                                                ($publisher['status'] == 'pending' ? 'warning' : 'secondary'); 
                                        ?>">
                                            <?php echo ucfirst($publisher['status']); ?>
                                        </span>
                                        <br>
                                        <small class="text-muted">
                                            Added: <?php echo date('M j, Y', strtotime($publisher['created_at'])); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-outline-primary" data-bs-toggle="tooltip" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            
                                            <?php if ($publisher['status'] == 'pending'): ?>
                                                <a href="?action=approve&id=<?php echo $publisher['id']; ?>" 
                                                   class="btn btn-outline-success" data-bs-toggle="tooltip" title="Approve">
                                                    <i class="fas fa-check"></i>
                                                </a>
                                            <?php elseif ($publisher['status'] == 'active'): ?>
                                                <a href="?action=suspend&id=<?php echo $publisher['id']; ?>" 
                                                   class="btn btn-outline-warning" data-bs-toggle="tooltip" title="Suspend">
                                                    <i class="fas fa-pause"></i>
                                                </a>
                                            <?php endif; ?>
                                            
                                            <a href="website.php?publisher_id=<?php echo $publisher['id']; ?>" 
                                               class="btn btn-outline-info" data-bs-toggle="tooltip" title="Websites">
                                                <i class="fas fa-sitemap"></i>
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

<?php include 'includes/footer.php'; ?>