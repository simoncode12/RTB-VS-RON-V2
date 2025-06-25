<?php
/**
 * Revenue Management Page
 * Track publisher earnings and manage payouts
 * Version: 1.0.0
 * Date: 2025-06-23 20:49:24
 * Author: simoncode12
 */

include 'includes/header.php';

// Get filter parameters
$publisher_id = $_GET['publisher_id'] ?? 0;
$month = $_GET['month'] ?? date('Y-m');
$status = $_GET['status'] ?? 'all';

// Get publishers list
$publishers = $pdo->query("
    SELECT id, name, email, revenue_share, payment_method 
    FROM publishers 
    WHERE status = 'active' 
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

// Calculate date range for selected month
$start_date = $month . '-01';
$end_date = date('Y-m-t', strtotime($start_date));

// Get revenue summary
$where_clause = "";
$params = [$start_date, $end_date];

if ($publisher_id > 0) {
    $where_clause = " AND p.id = ?";
    $params[] = $publisher_id;
}

$stmt = $pdo->prepare("
    SELECT 
        p.id as publisher_id,
        p.name as publisher_name,
        p.email,
        p.revenue_share,
        p.payment_method,
        p.payment_details,
        COUNT(DISTINCT bl.id) as total_impressions,
        SUM(CASE WHEN bl.status = 'click' THEN 1 ELSE 0 END) as total_clicks,
        COALESCE(SUM(bl.win_price), 0) as gross_revenue,
        COALESCE(SUM(bl.win_price * (p.revenue_share / 100)), 0) as publisher_revenue,
        COALESCE(SUM(bl.win_price * ((100 - p.revenue_share) / 100)), 0) as platform_revenue
    FROM publishers p
    LEFT JOIN websites w ON p.id = w.publisher_id
    LEFT JOIN zones z ON w.id = z.website_id
    LEFT JOIN bid_logs bl ON z.id = bl.zone_id 
        AND bl.status IN ('win', 'click')
        AND DATE(bl.created_at) BETWEEN ? AND ?
    WHERE p.status = 'active'
    $where_clause
    GROUP BY p.id
    HAVING gross_revenue > 0
    ORDER BY publisher_revenue DESC
");

$stmt->execute($params);
$revenue_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$totals = [
    'impressions' => array_sum(array_column($revenue_data, 'total_impressions')),
    'clicks' => array_sum(array_column($revenue_data, 'total_clicks')),
    'gross_revenue' => array_sum(array_column($revenue_data, 'gross_revenue')),
    'publisher_revenue' => array_sum(array_column($revenue_data, 'publisher_revenue')),
    'platform_revenue' => array_sum(array_column($revenue_data, 'platform_revenue'))
];

// Get payment history
$payment_history = $pdo->query("
    SELECT 
        pr.*,
        p.name as publisher_name
    FROM publisher_payments pr
    JOIN publishers p ON pr.publisher_id = p.id
    ORDER BY pr.created_at DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

// Handle payment action
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'mark_paid') {
        $pub_id = $_POST['publisher_id'];
        $amount = $_POST['amount'];
        $payment_method = $_POST['payment_method'];
        $transaction_id = $_POST['transaction_id'] ?? '';
        $notes = $_POST['notes'] ?? '';
        
        try {
            // Record payment
            $stmt = $pdo->prepare("
                INSERT INTO publisher_payments (
                    publisher_id, amount, payment_method, 
                    transaction_id, notes, period_start, period_end,
                    status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'completed', NOW())
            ");
            
            $stmt->execute([
                $pub_id, $amount, $payment_method,
                $transaction_id, $notes, $start_date, $end_date
            ]);
            
            $message = 'Payment recorded successfully!';
            $message_type = 'success';
            
            // Refresh page
            header("Location: revenue.php?month=$month&message=" . urlencode($message));
            exit;
        } catch (Exception $e) {
            $message = 'Error recording payment: ' . $e->getMessage();
            $message_type = 'danger';
        }
    }
}

// Show message if passed via URL
if (isset($_GET['message'])) {
    $message = $_GET['message'];
    $message_type = 'success';
}
?>

<div class="row">
    <div class="col-12">
        <h1 class="h3 mb-4">
            <i class="fas fa-dollar-sign"></i> Revenue Management
            <small class="text-muted">Publisher earnings and payouts</small>
        </h1>
        <div class="text-muted mb-3">
            <small>
                <i class="fas fa-clock"></i> Current Time (UTC): <?php echo date('Y-m-d H:i:s'); ?> | 
                <i class="fas fa-user"></i> Logged in as: <?php echo htmlspecialchars($_SESSION['username']); ?>
            </small>
        </div>
    </div>
</div>

<?php if (isset($message)): ?>
<div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
    <?php echo htmlspecialchars($message); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <label for="publisher_id" class="form-label">Publisher</label>
                <select class="form-select" name="publisher_id" id="publisher_id">
                    <option value="0">All Publishers</option>
                    <?php foreach ($publishers as $pub): ?>
                        <option value="<?php echo $pub['id']; ?>" <?php echo $publisher_id == $pub['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($pub['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="month" class="form-label">Month</label>
                <input type="month" class="form-control" name="month" id="month" value="<?php echo $month; ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary d-block">
                    <i class="fas fa-filter"></i> Apply Filters
                </button>
            </div>
            <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <button type="button" class="btn btn-success d-block" onclick="exportRevenue()">
                    <i class="fas fa-download"></i> Export
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card bg-primary text-white">
            <div class="card-body">
                <h6 class="text-uppercase mb-1">Total Impressions</h6>
                <h3 class="mb-0"><?php echo number_format($totals['impressions']); ?></h3>
                <small>CTR: <?php echo $totals['impressions'] > 0 ? number_format(($totals['clicks'] / $totals['impressions']) * 100, 2) : '0.00'; ?>%</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body">
                <h6 class="text-uppercase mb-1">Gross Revenue</h6>
                <h3 class="mb-0">$<?php echo number_format($totals['gross_revenue'], 2); ?></h3>
                <small>eCPM: $<?php echo $totals['impressions'] > 0 ? number_format(($totals['gross_revenue'] / $totals['impressions']) * 1000, 2) : '0.00'; ?></small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-warning text-white">
            <div class="card-body">
                <h6 class="text-uppercase mb-1">Publisher Earnings</h6>
                <h3 class="mb-0">$<?php echo number_format($totals['publisher_revenue'], 2); ?></h3>
                <small><?php echo count($revenue_data); ?> Publishers</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-info text-white">
            <div class="card-body">
                <h6 class="text-uppercase mb-1">Platform Revenue</h6>
                <h3 class="mb-0">$<?php echo number_format($totals['platform_revenue'], 2); ?></h3>
                <small>Net Profit</small>
            </div>
        </div>
    </div>
</div>

<!-- Revenue Table -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0">
            <i class="fas fa-table"></i> Publisher Revenue for <?php echo date('F Y', strtotime($start_date)); ?>
        </h5>
    </div>
    <div class="card-body">
        <?php if (empty($revenue_data)): ?>
            <div class="text-center py-4">
                <i class="fas fa-chart-line fa-3x text-muted mb-3"></i>
                <p class="text-muted">No revenue data for the selected period.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Publisher</th>
                            <th>Payment Method</th>
                            <th>Impressions</th>
                            <th>Clicks</th>
                            <th>CTR</th>
                            <th>Gross Revenue</th>
                            <th>Rev Share</th>
                            <th>Publisher Earnings</th>
                            <th>Platform Revenue</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($revenue_data as $row): ?>
                        <tr>
                            <td>
                                <div>
                                    <strong><?php echo htmlspecialchars($row['publisher_name']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($row['email']); ?></small>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-secondary">
                                    <?php echo ucfirst($row['payment_method']); ?>
                                </span>
                            </td>
                            <td><?php echo number_format($row['total_impressions']); ?></td>
                            <td><?php echo number_format($row['total_clicks']); ?></td>
                            <td>
                                <?php 
                                $ctr = $row['total_impressions'] > 0 ? 
                                    ($row['total_clicks'] / $row['total_impressions']) * 100 : 0;
                                echo number_format($ctr, 2) . '%';
                                ?>
                            </td>
                            <td>$<?php echo number_format($row['gross_revenue'], 2); ?></td>
                            <td><?php echo $row['revenue_share']; ?>%</td>
                            <td class="text-success">
                                <strong>$<?php echo number_format($row['publisher_revenue'], 2); ?></strong>
                            </td>
                            <td>$<?php echo number_format($row['platform_revenue'], 2); ?></td>
                            <td>
                                <button class="btn btn-sm btn-success" 
                                        onclick="markPaid(<?php echo $row['publisher_id']; ?>, '<?php echo htmlspecialchars($row['publisher_name']); ?>', <?php echo $row['publisher_revenue']; ?>, '<?php echo $row['payment_method']; ?>')">
                                    <i class="fas fa-check"></i> Pay
                                </button>
                                <a href="reports.php?type=publisher&entity_id=<?php echo $row['publisher_id']; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" 
                                   class="btn btn-sm btn-info">
                                    <i class="fas fa-chart-bar"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="fw-bold">
                            <td>TOTAL</td>
                            <td></td>
                            <td><?php echo number_format($totals['impressions']); ?></td>
                            <td><?php echo number_format($totals['clicks']); ?></td>
                            <td>
                                <?php 
                                $total_ctr = $totals['impressions'] > 0 ? 
                                    ($totals['clicks'] / $totals['impressions']) * 100 : 0;
                                echo number_format($total_ctr, 2) . '%';
                                ?>
                            </td>
                            <td>$<?php echo number_format($totals['gross_revenue'], 2); ?></td>
                            <td></td>
                            <td class="text-success">$<?php echo number_format($totals['publisher_revenue'], 2); ?></td>
                            <td>$<?php echo number_format($totals['platform_revenue'], 2); ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Payment History -->
<div class="card">
    <div class="card-header">
        <h5 class="mb-0">
            <i class="fas fa-history"></i> Recent Payment History
        </h5>
    </div>
    <div class="card-body">
        <?php if (empty($payment_history)): ?>
            <div class="text-center py-4">
                <i class="fas fa-file-invoice-dollar fa-3x text-muted mb-3"></i>
                <p class="text-muted">No payment history yet.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Publisher</th>
                            <th>Period</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Transaction ID</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payment_history as $payment): ?>
                        <tr>
                            <td><?php echo date('Y-m-d', strtotime($payment['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($payment['publisher_name']); ?></td>
                            <td>
                                <?php echo date('M d', strtotime($payment['period_start'])); ?> - 
                                <?php echo date('M d, Y', strtotime($payment['period_end'])); ?>
                            </td>
                            <td class="text-success">
                                <strong>$<?php echo number_format($payment['amount'], 2); ?></strong>
                            </td>
                            <td><?php echo ucfirst($payment['payment_method']); ?></td>
                            <td>
                                <?php if ($payment['transaction_id']): ?>
                                    <code><?php echo htmlspecialchars($payment['transaction_id']); ?></code>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-<?php echo $payment['status'] == 'completed' ? 'success' : 'warning'; ?>">
                                    <?php echo ucfirst($payment['status']); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="mark_paid">
                <input type="hidden" name="publisher_id" id="payment_publisher_id">
                <input type="hidden" name="amount" id="payment_amount">
                <input type="hidden" name="payment_method" id="payment_method">
                
                <div class="modal-header">
                    <h5 class="modal-title">Record Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Publisher</label>
                        <input type="text" class="form-control" id="payment_publisher_name" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Amount</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="text" class="form-control" id="payment_amount_display" readonly>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Transaction ID</label>
                        <input type="text" class="form-control" name="transaction_id" 
                               placeholder="PayPal transaction ID, wire reference, etc.">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="2" 
                                  placeholder="Optional payment notes"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check"></i> Confirm Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function markPaid(publisherId, publisherName, amount, paymentMethod) {
    document.getElementById('payment_publisher_id').value = publisherId;
    document.getElementById('payment_publisher_name').value = publisherName;
    document.getElementById('payment_amount').value = amount;
    document.getElementById('payment_amount_display').value = amount.toFixed(2);
    document.getElementById('payment_method').value = paymentMethod;
    
    var modal = new bootstrap.Modal(document.getElementById('paymentModal'));
    modal.show();
}

function exportRevenue() {
    const month = document.getElementById('month').value;
    const publisherId = document.getElementById('publisher_id').value;
    
    // Create CSV content
    let csv = "Publisher,Email,Payment Method,Impressions,Clicks,CTR,Gross Revenue,Rev Share,Publisher Earnings,Platform Revenue\n";
    
    // Get table data
    const rows = document.querySelectorAll('tbody tr');
    rows.forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length > 0) {
            const publisher = cells[0].querySelector('strong').textContent;
            const email = cells[0].querySelector('small').textContent;
            const paymentMethod = cells[1].textContent.trim();
            const impressions = cells[2].textContent;
            const clicks = cells[3].textContent;
            const ctr = cells[4].textContent;
            const grossRevenue = cells[5].textContent;
            const revShare = cells[6].textContent;
            const publisherEarnings = cells[7].textContent;
            const platformRevenue = cells[8].textContent;
            
            csv += `"${publisher}","${email}","${paymentMethod}",${impressions},${clicks},${ctr},${grossRevenue},${revShare},${publisherEarnings},${platformRevenue}\n`;
        }
    });
    
    // Download CSV
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `revenue_${month}.csv`;
    a.click();
}
</script>

<?php include 'includes/footer.php'; ?>