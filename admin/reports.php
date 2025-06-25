<?php
/**
 * Detailed Reports Page
 * Generate custom reports for advertisers and publishers
 */

include 'includes/header.php';

// Report parameters
$report_type = $_GET['type'] ?? 'advertiser';
$entity_id = $_GET['entity_id'] ?? 0;
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Get list of advertisers and publishers for dropdown
$advertisers = $pdo->query("SELECT id, name FROM advertisers WHERE status = 'active' ORDER BY name")->fetchAll();
$publishers = $pdo->query("SELECT id, name FROM publishers WHERE status = 'active' ORDER BY name")->fetchAll();
?>

<div class="row">
    <div class="col-12">
        <h1 class="h3 mb-4">
            <i class="fas fa-file-alt"></i> Reports
            <small class="text-muted">Generate detailed performance reports</small>
        </h1>
    </div>
</div>

<!-- Report Selector -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label for="type" class="form-label">Report Type</label>
                <select class="form-select" name="type" id="type" onchange="toggleEntitySelector()">
                    <option value="advertiser" <?php echo $report_type == 'advertiser' ? 'selected' : ''; ?>>
                        Advertiser Report
                    </option>
                    <option value="publisher" <?php echo $report_type == 'publisher' ? 'selected' : ''; ?>>
                        Publisher Report
                    </option>
                    <option value="campaign" <?php echo $report_type == 'campaign' ? 'selected' : ''; ?>>
                        Campaign Report
                    </option>
                    <option value="zone" <?php echo $report_type == 'zone' ? 'selected' : ''; ?>>
                        Zone Report
                    </option>
                </select>
            </div>
            
            <div class="col-md-3" id="entity-selector">
                <label for="entity_id" class="form-label">Select <?php echo ucfirst($report_type); ?></label>
                <select class="form-select" name="entity_id" id="entity_id">
                    <option value="0">All <?php echo ucfirst($report_type); ?>s</option>
                    <?php if ($report_type == 'advertiser'): ?>
                        <?php foreach ($advertisers as $adv): ?>
                            <option value="<?php echo $adv['id']; ?>" <?php echo $entity_id == $adv['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($adv['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php elseif ($report_type == 'publisher'): ?>
                        <?php foreach ($publishers as $pub): ?>
                            <option value="<?php echo $pub['id']; ?>" <?php echo $entity_id == $pub['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($pub['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label for="start_date" class="form-label">Start Date</label>
                <input type="date" class="form-control" name="start_date" value="<?php echo $start_date; ?>">
            </div>
            
            <div class="col-md-2">
                <label for="end_date" class="form-label">End Date</label>
                <input type="date" class="form-control" name="end_date" value="<?php echo $end_date; ?>">
            </div>
            
            <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary d-block w-100">
                    <i class="fas fa-search"></i> Generate Report
                </button>
            </div>
        </form>
    </div>
</div>

<?php
// Generate report based on type
if ($report_type == 'advertiser') {
    // Advertiser Report
    $where_clause = $entity_id > 0 ? "AND a.id = $entity_id" : "";
    
    $report_data = $pdo->query("
        SELECT 
            DATE(bl.created_at) as date,
            a.name as advertiser_name,
            c.name as campaign_name,
            c.type as campaign_type,
            COUNT(DISTINCT bl.id) as impressions,
            SUM(CASE WHEN bl.status = 'click' THEN 1 ELSE 0 END) as clicks,
            COALESCE(SUM(bl.win_price), 0) as cost,
            cr.width,
            cr.height
        FROM bid_logs bl
        JOIN campaigns c ON bl.campaign_id = c.id
        JOIN advertisers a ON c.advertiser_id = a.id
        LEFT JOIN creatives cr ON bl.creative_id = cr.id
        WHERE bl.created_at BETWEEN '$start_date 00:00:00' AND '$end_date 23:59:59'
        $where_clause
        GROUP BY DATE(bl.created_at), a.id, c.id
        ORDER BY date DESC, cost DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
} elseif ($report_type == 'publisher') {
    // Publisher Report
    $where_clause = $entity_id > 0 ? "AND p.id = $entity_id" : "";
    
    $report_data = $pdo->query("
        SELECT 
            DATE(bl.created_at) as date,
            p.name as publisher_name,
            w.name as website_name,
            z.name as zone_name,
            z.size,
            COUNT(DISTINCT bl.id) as impressions,
            SUM(CASE WHEN bl.status = 'click' THEN 1 ELSE 0 END) as clicks,
            COALESCE(SUM(bl.win_price), 0) as revenue,
            COALESCE(SUM(bl.win_price), 0) * (p.revenue_share / 100) as publisher_revenue
        FROM bid_logs bl
        JOIN zones z ON bl.zone_id = z.id
        JOIN websites w ON z.website_id = w.id
        JOIN publishers p ON w.publisher_id = p.id
        WHERE bl.created_at BETWEEN '$start_date 00:00:00' AND '$end_date 23:59:59'
        $where_clause
        GROUP BY DATE(bl.created_at), p.id, w.id, z.id
        ORDER BY date DESC, revenue DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!-- Report Results -->
<?php if (!empty($report_data)): ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">
            <i class="fas fa-table"></i> 
            <?php echo ucfirst($report_type); ?> Report Results
        </h5>
        <div>
            <button class="btn btn-sm btn-success" onclick="exportToCSV()">
                <i class="fas fa-file-csv"></i> Export CSV
            </button>
            <button class="btn btn-sm btn-danger" onclick="exportToPDF()">
                <i class="fas fa-file-pdf"></i> Export PDF
            </button>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-hover" id="report-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <?php if ($report_type == 'advertiser'): ?>
                            <th>Advertiser</th>
                            <th>Campaign</th>
                            <th>Type</th>
                            <th>Size</th>
                            <th>Impressions</th>
                            <th>Clicks</th>
                            <th>CTR</th>
                            <th>Cost</th>
                            <th>eCPM</th>
                        <?php elseif ($report_type == 'publisher'): ?>
                            <th>Publisher</th>
                            <th>Website</th>
                            <th>Zone</th>
                            <th>Size</th>
                            <th>Impressions</th>
                            <th>Clicks</th>
                            <th>CTR</th>
                            <th>Revenue</th>
                            <th>Your Earnings</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $total_impressions = 0;
                    $total_clicks = 0;
                    $total_revenue = 0;
                    $total_publisher_revenue = 0;
                    
                    foreach ($report_data as $row): 
                        $ctr = $row['impressions'] > 0 ? ($row['clicks'] / $row['impressions']) * 100 : 0;
                        $ecpm = $row['impressions'] > 0 ? (($row['cost'] ?? $row['revenue']) / $row['impressions']) * 1000 : 0;
                        
                        $total_impressions += $row['impressions'];
                        $total_clicks += $row['clicks'];
                        $total_revenue += ($row['cost'] ?? $row['revenue']);
                        $total_publisher_revenue += ($row['publisher_revenue'] ?? 0);
                    ?>
                    <tr>
                        <td><?php echo $row['date']; ?></td>
                        <?php if ($report_type == 'advertiser'): ?>
                            <td><?php echo htmlspecialchars($row['advertiser_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['campaign_name']); ?></td>
                            <td>
                                <span class="badge bg-<?php echo $row['campaign_type'] == 'rtb' ? 'primary' : 'success'; ?>">
                                    <?php echo strtoupper($row['campaign_type']); ?>
                                </span>
                            </td>
                            <td><?php echo $row['width'] . 'x' . $row['height']; ?></td>
                            <td><?php echo number_format($row['impressions']); ?></td>
                            <td><?php echo number_format($row['clicks']); ?></td>
                            <td><?php echo number_format($ctr, 2); ?>%</td>
                            <td>$<?php echo number_format($row['cost'], 2); ?></td>
                            <td>$<?php echo number_format($ecpm, 2); ?></td>
                        <?php elseif ($report_type == 'publisher'): ?>
                            <td><?php echo htmlspecialchars($row['publisher_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['website_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['zone_name']); ?></td>
                            <td><?php echo $row['size']; ?></td>
                            <td><?php echo number_format($row['impressions']); ?></td>
                            <td><?php echo number_format($row['clicks']); ?></td>
                            <td><?php echo number_format($ctr, 2); ?>%</td>
                            <td>$<?php echo number_format($row['revenue'], 2); ?></td>
                            <td>$<?php echo number_format($row['publisher_revenue'], 2); ?></td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="fw-bold">
                        <td>Total</td>
                        <?php if ($report_type == 'advertiser'): ?>
                            <td colspan="4"></td>
                            <td><?php echo number_format($total_impressions); ?></td>
                            <td><?php echo number_format($total_clicks); ?></td>
                            <td><?php echo $total_impressions > 0 ? number_format(($total_clicks / $total_impressions) * 100, 2) : '0.00'; ?>%</td>
                            <td>$<?php echo number_format($total_revenue, 2); ?></td>
                            <td>$<?php echo $total_impressions > 0 ? number_format(($total_revenue / $total_impressions) * 1000, 2) : '0.00'; ?></td>
                        <?php elseif ($report_type == 'publisher'): ?>
                            <td colspan="4"></td>
                            <td><?php echo number_format($total_impressions); ?></td>
                            <td><?php echo number_format($total_clicks); ?></td>
                            <td><?php echo $total_impressions > 0 ? number_format(($total_clicks / $total_impressions) * 100, 2) : '0.00'; ?>%</td>
                            <td>$<?php echo number_format($total_revenue, 2); ?></td>
                            <td>$<?php echo number_format($total_publisher_revenue, 2); ?></td>
                        <?php endif; ?>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
<?php else: ?>
<div class="alert alert-info">
    <i class="fas fa-info-circle"></i> No data found for the selected criteria.
</div>
<?php endif; ?>

<script>
function toggleEntitySelector() {
    // This would dynamically update the entity selector based on report type
    // For now, just reload the page
    document.forms[0].submit();
}

function exportToCSV() {
    // Simple CSV export
    let csv = [];
    let rows = document.querySelectorAll("#report-table tr");
    
    for (let i = 0; i < rows.length; i++) {
        let row = [], cols = rows[i].querySelectorAll("td, th");
        for (let j = 0; j < cols.length; j++) {
            row.push('"' + cols[j].innerText + '"');
        }
        csv.push(row.join(","));
    }
    
    let csvContent = csv.join("\n");
    let blob = new Blob([csvContent], { type: 'text/csv' });
    let link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.download = "report_" + new Date().toISOString().slice(0,10) + ".csv";
    link.click();
}
</script>

<?php include 'includes/footer.php'; ?>