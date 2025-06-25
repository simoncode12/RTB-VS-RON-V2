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
        $phone = $_POST['phone'] ?? '';
        
        if ($name) {
            $stmt = $pdo->prepare("INSERT INTO advertisers (name, email, website, contact_person, phone) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name, $email, $website, $contact_person, $phone]);
            
            $message = 'Advertiser added successfully!';
            $message_type = 'success';
        } else {
            $message = 'Please enter advertiser name.';
            $message_type = 'danger';
        }
    } catch (Exception $e) {
        $message = 'Error adding advertiser: ' . $e->getMessage();
        $message_type = 'danger';
    }
}

// Get all advertisers
$advertisers = $pdo->query("
    SELECT a.*, 
           COUNT(c.id) as campaign_count,
           SUM(CASE WHEN c.status = 'active' THEN 1 ELSE 0 END) as active_campaigns
    FROM advertisers a 
    LEFT JOIN campaigns c ON a.id = c.advertiser_id 
    GROUP BY a.id 
    ORDER BY a.created_at DESC
")->fetchAll();
?>

<div class="row">
    <div class="col-12">
        <h1 class="h3 mb-4">
            <i class="fas fa-user-tie"></i> Advertiser Management
            <small class="text-muted">Manage your advertising clients</small>
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
    <!-- Add New Advertiser -->
    <div class="col-lg-4 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-plus"></i> Add New Advertiser</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label for="name" class="form-label">Company Name *</label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email">
                    </div>
                    
                    <div class="mb-3">
                        <label for="website" class="form-label">Website</label>
                        <input type="url" class="form-control" id="website" name="website" 
                               placeholder="https://example.com">
                    </div>
                    
                    <div class="mb-3">
                        <label for="contact_person" class="form-label">Contact Person</label>
                        <input type="text" class="form-control" id="contact_person" name="contact_person">
                    </div>
                    
                    <div class="mb-3">
                        <label for="phone" class="form-label">Phone</label>
                        <input type="tel" class="form-control" id="phone" name="phone">
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-save"></i> Add Advertiser
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Advertisers List -->
    <div class="col-lg-8 mb-4">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-list"></i> Advertisers</h5>
                <span class="badge bg-primary"><?php echo count($advertisers); ?> Advertisers</span>
            </div>
            <div class="card-body">
                <?php if (empty($advertisers)): ?>
                    <div class="text-center py-4">
                        <i class="fas fa-user-tie fa-3x text-muted mb-3"></i>
                        <p class="text-muted">No advertisers added yet.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Company</th>
                                    <th>Contact Info</th>
                                    <th>Campaigns</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($advertisers as $advertiser): ?>
                                <tr>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($advertiser['name']); ?></strong>
                                            <?php if ($advertiser['website']): ?>
                                                <br>
                                                <a href="<?php echo htmlspecialchars($advertiser['website']); ?>" 
                                                   target="_blank" class="text-decoration-none small">
                                                    <i class="fas fa-external-link-alt"></i> Website
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($advertiser['contact_person']): ?>
                                            <div><strong><?php echo htmlspecialchars($advertiser['contact_person']); ?></strong></div>
                                        <?php endif; ?>
                                        <?php if ($advertiser['email']): ?>
                                            <div><a href="mailto:<?php echo htmlspecialchars($advertiser['email']); ?>"><?php echo htmlspecialchars($advertiser['email']); ?></a></div>
                                        <?php endif; ?>
                                        <?php if ($advertiser['phone']): ?>
                                            <div><a href="tel:<?php echo htmlspecialchars($advertiser['phone']); ?>"><?php echo htmlspecialchars($advertiser['phone']); ?></a></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div>
                                            <span class="badge bg-primary"><?php echo $advertiser['campaign_count']; ?> Total</span>
                                            <?php if ($advertiser['active_campaigns'] > 0): ?>
                                                <span class="badge bg-success"><?php echo $advertiser['active_campaigns']; ?> Active</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($advertiser['campaign_count'] > 0): ?>
                                            <small>
                                                <a href="rtb-sell.php?advertiser_id=<?php echo $advertiser['id']; ?>" class="text-decoration-none">
                                                    View Campaigns
                                                </a>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $advertiser['status'] == 'active' ? 'success' : 'secondary'; ?>">
                                            <?php echo ucfirst($advertiser['status']); ?>
                                        </span>
                                        <br>
                                        <small class="text-muted">
                                            Added: <?php echo date('M j, Y', strtotime($advertiser['created_at'])); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-outline-primary" data-bs-toggle="tooltip" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <a href="rtb-sell.php?advertiser_id=<?php echo $advertiser['id']; ?>" 
                                               class="btn btn-outline-success" data-bs-toggle="tooltip" title="New Campaign">
                                                <i class="fas fa-plus"></i>
                                            </a>
                                            <button class="btn btn-outline-info" data-bs-toggle="tooltip" title="Stats">
                                                <i class="fas fa-chart-bar"></i>
                                            </button>
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