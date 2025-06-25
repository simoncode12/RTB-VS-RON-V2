<?php
/**
 * RTB Campaign Debug Tool
 * Debug why RTB campaigns are not found
 * Date: 2025-06-23 21:58:15
 * Author: simoncode12
 */

include 'includes/header.php';

// Debug RTB campaigns
$debug_results = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['debug_campaigns'])) {
    $width = intval($_POST['width'] ?? 300);
    $height = intval($_POST['height'] ?? 250);
    
    // Step 1: Check all campaigns
    $stmt = $pdo->query("
        SELECT c.*, a.name as advertiser_name, a.status as advertiser_status
        FROM campaigns c
        JOIN advertisers a ON c.advertiser_id = a.id
        ORDER BY c.id DESC
    ");
    $all_campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Step 2: Check all creatives
    $stmt = $pdo->query("
        SELECT cr.*, c.name as campaign_name, c.type as campaign_type, c.status as campaign_status
        FROM creatives cr
        JOIN campaigns c ON cr.campaign_id = c.id
        ORDER BY cr.id DESC
    ");
    $all_creatives = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Step 3: Check RTB campaigns specifically
    $stmt = $pdo->prepare("
        SELECT 
            c.id,
            c.name,
            c.type,
            c.status as campaign_status,
            c.start_date,
            c.end_date,
            a.name as advertiser_name,
            a.status as advertiser_status,
            COUNT(cr.id) as creative_count,
            GROUP_CONCAT(CONCAT(cr.width, 'x', cr.height, ' (', cr.status, ')')) as creative_sizes
        FROM campaigns c
        JOIN advertisers a ON c.advertiser_id = a.id
        LEFT JOIN creatives cr ON c.id = cr.campaign_id
        WHERE c.type = 'rtb'
        GROUP BY c.id
        ORDER BY c.id DESC
    ");
    $stmt->execute();
    $rtb_campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Step 4: Check for specific size
    $stmt = $pdo->prepare("
        SELECT 
            c.*,
            cr.id as creative_id,
            cr.name as creative_name,
            cr.width,
            cr.height,
            cr.bid_amount,
            cr.status as creative_status,
            a.name as advertiser_name,
            a.status as advertiser_status
        FROM campaigns c
        JOIN creatives cr ON c.id = cr.campaign_id
        JOIN advertisers a ON c.advertiser_id = a.id
        WHERE c.status = 'active'
        AND c.type = 'rtb'
        AND a.status = 'active'
        AND cr.status = 'active'
        AND cr.width = ?
        AND cr.height = ?
        AND (c.start_date IS NULL OR c.start_date <= CURDATE())
        AND (c.end_date IS NULL OR c.end_date >= CURDATE())
        ORDER BY cr.bid_amount DESC
    ");
    
    $stmt->execute([$width, $height]);
    $matching_campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $debug_results = [
        'all_campaigns' => $all_campaigns,
        'all_creatives' => $all_creatives,
        'rtb_campaigns' => $rtb_campaigns,
        'matching_campaigns' => $matching_campaigns,
        'search_size' => "{$width}x{$height}"
    ];
}
?>

<div class="row">
    <div class="col-12">
        <h1 class="h3 mb-4">
            <i class="fas fa-bug"></i> RTB Campaign Debug
            <small class="text-muted">Debug why RTB campaigns are not found</small>
        </h1>
    </div>
</div>

<!-- Debug Form -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="fas fa-search"></i> Debug RTB Campaigns</h5>
    </div>
    <div class="card-body">
        <form method="POST">
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Banner Size to Search</label>
                        <div class="row">
                            <div class="col-6">
                                <input type="number" class="form-control" name="width" placeholder="Width" value="300" required>
                            </div>
                            <div class="col-6">
                                <input type="number" class="form-control" name="height" placeholder="Height" value="250" required>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 d-flex align-items-end">
                    <button type="submit" name="debug_campaigns" class="btn btn-primary">
                        <i class="fas fa-search"></i> Debug Campaigns
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if (!empty($debug_results)): ?>
<!-- Debug Results -->
<div class="row">
    <!-- All Campaigns -->
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-bullhorn"></i> All Campaigns 
                    <span class="badge bg-secondary"><?php echo count($debug_results['all_campaigns']); ?></span>
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Advertiser</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($debug_results['all_campaigns'] as $campaign): ?>
                            <tr>
                                <td><?php echo $campaign['id']; ?></td>
                                <td><?php echo htmlspecialchars($campaign['name']); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $campaign['type'] == 'rtb' ? 'success' : 'primary'; ?>">
                                        <?php echo strtoupper($campaign['type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $campaign['status'] == 'active' ? 'success' : 'danger'; ?>">
                                        <?php echo $campaign['status']; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($campaign['advertiser_name']); ?>
                                    <small class="text-<?php echo $campaign['advertiser_status'] == 'active' ? 'success' : 'danger'; ?>">
                                        (<?php echo $campaign['advertiser_status']; ?>)
                                    </small>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- All Creatives -->
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-image"></i> All Creatives 
                    <span class="badge bg-secondary"><?php echo count($debug_results['all_creatives']); ?></span>
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Size</th>
                                <th>Bid</th>
                                <th>Campaign</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($debug_results['all_creatives'] as $creative): ?>
                            <tr>
                                <td><?php echo $creative['id']; ?></td>
                                <td><?php echo htmlspecialchars($creative['name']); ?></td>
                                <td>
                                    <code><?php echo $creative['width']; ?>x<?php echo $creative['height']; ?></code>
                                </td>
                                <td>$<?php echo number_format($creative['bid_amount'], 4); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($creative['campaign_name']); ?>
                                    <br>
                                    <span class="badge bg-<?php echo $creative['campaign_type'] == 'rtb' ? 'success' : 'primary'; ?>">
                                        <?php echo strtoupper($creative['campaign_type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $creative['status'] == 'active' ? 'success' : 'danger'; ?>">
                                        <?php echo $creative['status']; ?>
                                    </span>
                                    /
                                    <span class="badge bg-<?php echo $creative['campaign_status'] == 'active' ? 'success' : 'danger'; ?>">
                                        <?php echo $creative['campaign_status']; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- RTB Campaign Summary -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0">
            <i class="fas fa-chart-bar"></i> RTB Campaigns Summary
        </h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Campaign ID</th>
                        <th>Campaign Name</th>
                        <th>Status</th>
                        <th>Advertiser</th>
                        <th>Date Range</th>
                        <th>Creative Count</th>
                        <th>Creative Sizes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($debug_results['rtb_campaigns'])): ?>
                    <tr>
                        <td colspan="7" class="text-center text-danger">
                            <i class="fas fa-exclamation-triangle"></i> No RTB campaigns found!
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($debug_results['rtb_campaigns'] as $campaign): ?>
                        <tr>
                            <td><?php echo $campaign['id']; ?></td>
                            <td><?php echo htmlspecialchars($campaign['name']); ?></td>
                            <td>
                                <span class="badge bg-<?php echo $campaign['campaign_status'] == 'active' ? 'success' : 'danger'; ?>">
                                    <?php echo $campaign['campaign_status']; ?>
                                </span>
                                /
                                <span class="badge bg-<?php echo $campaign['advertiser_status'] == 'active' ? 'success' : 'danger'; ?>">
                                    Adv: <?php echo $campaign['advertiser_status']; ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($campaign['advertiser_name']); ?></td>
                            <td>
                                <small>
                                    <?php echo $campaign['start_date'] ?? 'No start'; ?> - 
                                    <?php echo $campaign['end_date'] ?? 'No end'; ?>
                                </small>
                            </td>
                            <td>
                                <span class="badge bg-<?php echo $campaign['creative_count'] > 0 ? 'success' : 'danger'; ?>">
                                    <?php echo $campaign['creative_count']; ?> creatives
                                </span>
                            </td>
                            <td>
                                <small><?php echo $campaign['creative_sizes'] ?? 'No creatives'; ?></small>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Matching Campaigns for Size -->
<div class="card">
    <div class="card-header">
        <h5 class="mb-0">
            <i class="fas fa-target"></i> Matching RTB Campaigns for <?php echo $debug_results['search_size']; ?>
            <span class="badge bg-<?php echo count($debug_results['matching_campaigns']) > 0 ? 'success' : 'danger'; ?>">
                <?php echo count($debug_results['matching_campaigns']); ?> found
            </span>
        </h5>
    </div>
    <div class="card-body">
        <?php if (empty($debug_results['matching_campaigns'])): ?>
            <div class="alert alert-danger">
                <h6><i class="fas fa-exclamation-triangle"></i> No RTB campaigns found for <?php echo $debug_results['search_size']; ?>!</h6>
                <p class="mb-0">This is why RTB endpoint returns HTTP 204 (No Bid).</p>
                <hr>
                <p class="mb-0"><strong>Solutions:</strong></p>
                <ul class="mb-0">
                    <li>Create a <?php echo $debug_results['search_size']; ?> creative for an RTB campaign</li>
                    <li>Check that both campaign and advertiser are active</li>
                    <li>Verify date ranges are valid</li>
                </ul>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Campaign</th>
                            <th>Creative</th>
                            <th>Bid Amount</th>
                            <th>Advertiser</th>
                            <th>Status Check</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($debug_results['matching_campaigns'] as $campaign): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($campaign['name']); ?></strong>
                                <br>
                                <small>ID: <?php echo $campaign['id']; ?></small>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($campaign['creative_name']); ?>
                                <br>
                                <code><?php echo $campaign['width']; ?>x<?php echo $campaign['height']; ?></code>
                            </td>
                            <td>
                                <strong>$<?php echo number_format($campaign['bid_amount'], 4); ?></strong>
                            </td>
                            <td><?php echo htmlspecialchars($campaign['advertiser_name']); ?></td>
                            <td>
                                <div class="d-flex flex-column">
                                    <span class="badge bg-success mb-1">Campaign: <?php echo $campaign['status']; ?></span>
                                    <span class="badge bg-success mb-1">Advertiser: <?php echo $campaign['advertiser_status']; ?></span>
                                    <span class="badge bg-success">Creative: <?php echo $campaign['creative_status']; ?></span>
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

<?php endif; ?>

<?php include 'includes/footer.php'; ?>