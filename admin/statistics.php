<?php
/**
 * Admin Statistics Dashboard
 * Created: 2025-06-25 15:15:23
 * Author: simoncode12
 * 
 * Features:
 * - Advanced filtering by date, publisher, campaign, etc.
 * - Group by functionality (date, publisher, website, campaign, etc.)
 * - Display original and adjusted eCPM
 * - Show platform and publisher revenue shares
 */

include 'includes/header.php';

// Initialize variables
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-7 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$group_by = isset($_GET['group_by']) ? $_GET['group_by'] : 'date';
$publisher_id = isset($_GET['publisher_id']) ? intval($_GET['publisher_id']) : 0;
$website_id = isset($_GET['website_id']) ? intval($_GET['website_id']) : 0;
$campaign_id = isset($_GET['campaign_id']) ? intval($_GET['campaign_id']) : 0;
$campaign_type = isset($_GET['campaign_type']) ? $_GET['campaign_type'] : '';
$country = isset($_GET['country']) ? $_GET['country'] : '';
$format = isset($_GET['format']) ? $_GET['format'] : '';
$device_type = isset($_GET['device_type']) ? $_GET['device_type'] : '';
$data_source = isset($_GET['data_source']) ? $_GET['data_source'] : 'daily'; // 'daily' or 'detailed'

// Force detailed view if publisher_id is selected (since daily_statistics doesn't have publisher_id)
if ($publisher_id > 0 || $website_id > 0 || $campaign_id > 0 || !empty($campaign_type) || !empty($country) || !empty($device_type)) {
    $data_source = 'detailed';
}

// Force detailed view for certain group_by options that aren't in daily_statistics
if (in_array($group_by, ['publisher', 'website', 'zone', 'campaign', 'campaign_type', 'country', 'device'])) {
    $data_source = 'detailed';
}

// Get filter options from database
$publishers = $pdo->query("SELECT id, name FROM publishers WHERE status = 'active' ORDER BY name")->fetchAll();
$websites = $pdo->query("SELECT id, name, domain FROM websites WHERE status = 'active' ORDER BY name")->fetchAll();
$campaigns = $pdo->query("SELECT id, name, type FROM campaigns WHERE status = 'active' ORDER BY name")->fetchAll();

// Determine if we should use daily_statistics (aggregated) or revenue_tracking (detailed)
$useAggregatedData = ($data_source == 'daily');

// Build SQL query based on filters and group by option
$sql_select = "SELECT ";
$sql_group_by = "";
$sql_order_by = "";
$chart_x_label = "";
$chart_label = "";

if ($useAggregatedData) {
    // Using daily_statistics table
    switch ($group_by) {
        case 'date':
            $sql_select .= "ds.date as group_label, ";
            $sql_group_by = "GROUP BY ds.date ";
            $sql_order_by = "ORDER BY ds.date ASC";
            $chart_x_label = "Date";
            $chart_label = "Daily Performance";
            break;
            
        case 'week':
            $sql_select .= "CONCAT('Week ', WEEK(ds.date), ' ', YEAR(ds.date)) as group_label, WEEK(ds.date) as week_num, YEAR(ds.date) as year_num, ";
            $sql_group_by = "GROUP BY WEEK(ds.date), YEAR(ds.date) ";
            $sql_order_by = "ORDER BY YEAR(ds.date) ASC, WEEK(ds.date) ASC";
            $chart_x_label = "Week";
            $chart_label = "Weekly Performance";
            break;
            
        case 'month':
            $sql_select .= "DATE_FORMAT(ds.date, '%M %Y') as group_label, MONTH(ds.date) as month_num, YEAR(ds.date) as year_num, ";
            $sql_group_by = "GROUP BY MONTH(ds.date), YEAR(ds.date) ";
            $sql_order_by = "ORDER BY YEAR(ds.date) ASC, MONTH(ds.date) ASC";
            $chart_x_label = "Month";
            $chart_label = "Monthly Performance";
            break;

        default:
            $sql_select .= "ds.date as group_label, ";
            $sql_group_by = "GROUP BY ds.date ";
            $sql_order_by = "ORDER BY ds.date ASC";
            $chart_x_label = "Date";
            $chart_label = "Daily Performance";
            break;
    }
    
    // Add aggregates to SELECT clause for daily_statistics table
    $sql_select .= "
        SUM(ds.total_impressions) as total_impressions,
        SUM(ds.total_clicks) as total_clicks,
        SUM(ds.total_revenue) as total_revenue,
        SUM(ds.publisher_revenue) as total_publisher_revenue,
        SUM(ds.platform_revenue) as total_platform_revenue,
        CASE WHEN SUM(ds.total_impressions) > 0 THEN SUM(ds.total_clicks) / SUM(ds.total_impressions) * 100 ELSE 0 END as ctr,
        CASE WHEN SUM(ds.total_impressions) > 0 THEN SUM(ds.total_revenue) / SUM(ds.total_impressions) * 1000 ELSE 0 END as ecpm,
        CASE WHEN SUM(ds.total_impressions) > 0 THEN SUM(ds.publisher_revenue) / SUM(ds.total_impressions) * 1000 ELSE 0 END as publisher_ecpm,
        SUM(ds.rtb_impressions) as rtb_impressions, 
        SUM(ds.ron_impressions) as ron_impressions
    ";
    
    // Build the FROM clause with JOINs for daily_statistics - FIXED: removed incorrect JOIN with publishers
    $sql_from = "FROM daily_statistics ds";
    
} else {
    // Using revenue_tracking table (detailed)
    switch ($group_by) {
        case 'date':
            $sql_select .= "DATE(r.date) as group_label, ";
            $sql_group_by = "GROUP BY DATE(r.date) ";
            $sql_order_by = "ORDER BY DATE(r.date) ASC";
            $chart_x_label = "Date";
            $chart_label = "Daily Performance";
            break;
            
        case 'week':
            $sql_select .= "CONCAT('Week ', WEEK(r.date), ' ', YEAR(r.date)) as group_label, WEEK(r.date) as week_num, YEAR(r.date) as year_num, ";
            $sql_group_by = "GROUP BY WEEK(r.date), YEAR(r.date) ";
            $sql_order_by = "ORDER BY YEAR(r.date) ASC, WEEK(r.date) ASC";
            $chart_x_label = "Week";
            $chart_label = "Weekly Performance";
            break;
            
        case 'month':
            $sql_select .= "DATE_FORMAT(r.date, '%M %Y') as group_label, MONTH(r.date) as month_num, YEAR(r.date) as year_num, ";
            $sql_group_by = "GROUP BY MONTH(r.date), YEAR(r.date) ";
            $sql_order_by = "ORDER BY YEAR(r.date) ASC, MONTH(r.date) ASC";
            $chart_x_label = "Month";
            $chart_label = "Monthly Performance";
            break;
            
        case 'publisher':
            $sql_select .= "COALESCE(p.name, 'Unknown') as group_label, p.id as publisher_id, ";
            $sql_group_by = "GROUP BY p.id ";
            $sql_order_by = "ORDER BY SUM(r.revenue) DESC";
            $chart_x_label = "Publisher";
            $chart_label = "Publisher Performance";
            break;
            
        case 'website':
            $sql_select .= "COALESCE(w.name, 'Unknown') as group_label, w.id as website_id, ";
            $sql_group_by = "GROUP BY w.id ";
            $sql_order_by = "ORDER BY SUM(r.revenue) DESC";
            $chart_x_label = "Website";
            $chart_label = "Website Performance";
            break;
            
        case 'zone':
            $sql_select .= "CONCAT(COALESCE(z.name, 'Unknown'), ' (', COALESCE(z.size, 'N/A'), ')') as group_label, z.id as zone_id, ";
            $sql_group_by = "GROUP BY z.id ";
            $sql_order_by = "ORDER BY SUM(r.revenue) DESC";
            $chart_x_label = "Zone";
            $chart_label = "Zone Performance";
            break;
            
        case 'campaign':
            $sql_select .= "COALESCE(c.name, 'Unknown') as group_label, c.id as campaign_id, ";
            $sql_group_by = "GROUP BY c.id ";
            $sql_order_by = "ORDER BY SUM(r.revenue) DESC";
            $chart_x_label = "Campaign";
            $chart_label = "Campaign Performance";
            break;
            
        case 'campaign_type':
            $sql_select .= "COALESCE(c.type, 'unknown') as group_label, ";
            $sql_group_by = "GROUP BY c.type ";
            $sql_order_by = "ORDER BY SUM(r.revenue) DESC";
            $chart_x_label = "Campaign Type";
            $chart_label = "Performance by Campaign Type";
            break;
            
        case 'country':
            $sql_select .= "COALESCE(b.country, 'unknown') as group_label, ";
            $sql_group_by = "GROUP BY b.country ";
            $sql_order_by = "ORDER BY SUM(r.revenue) DESC";
            $chart_x_label = "Country";
            $chart_label = "Performance by Country";
            break;
            
        case 'device':
            $sql_select .= "COALESCE(b.device_type, 'unknown') as group_label, ";
            $sql_group_by = "GROUP BY b.device_type ";
            $sql_order_by = "ORDER BY SUM(r.revenue) DESC";
            $chart_x_label = "Device Type";
            $chart_label = "Performance by Device";
            break;
            
        default:
            $sql_select .= "DATE(r.date) as group_label, ";
            $sql_group_by = "GROUP BY DATE(r.date) ";
            $sql_order_by = "ORDER BY DATE(r.date) ASC";
            $chart_x_label = "Date";
            $chart_label = "Daily Performance";
            break;
    }
    
    // Add aggregates to SELECT clause for revenue_tracking table
    $sql_select .= "
        SUM(r.impressions) as total_impressions,
        SUM(r.clicks) as total_clicks,
        SUM(r.revenue) as total_revenue,
        SUM(r.publisher_revenue) as total_publisher_revenue,
        SUM(r.revenue - r.publisher_revenue) as total_platform_revenue,
        CASE WHEN SUM(r.impressions) > 0 THEN SUM(r.clicks) / SUM(r.impressions) * 100 ELSE 0 END as ctr,
        CASE WHEN SUM(r.impressions) > 0 THEN SUM(r.revenue) / SUM(r.impressions) * 1000 ELSE 0 END as ecpm,
        CASE WHEN SUM(r.impressions) > 0 THEN SUM(r.publisher_revenue) / SUM(r.impressions) * 1000 ELSE 0 END as publisher_ecpm,
        SUM(CASE WHEN c.type = 'rtb' THEN r.impressions ELSE 0 END) as rtb_impressions,
        SUM(CASE WHEN c.type = 'ron' THEN r.impressions ELSE 0 END) as ron_impressions
    ";
    
    // Build the FROM clause with JOINs for revenue_tracking
    $sql_from = "
        FROM revenue_tracking r
        LEFT JOIN publishers p ON r.publisher_id = p.id
        LEFT JOIN zones z ON r.zone_id = z.id
        LEFT JOIN websites w ON z.website_id = w.id
        LEFT JOIN campaigns c ON r.campaign_id = c.id
        LEFT JOIN bid_logs b ON r.campaign_id = b.campaign_id AND DATE(r.date) = DATE(b.created_at)
    ";
}

// Build the WHERE clause based on filters
if ($useAggregatedData) {
    $sql_where = "WHERE ds.date BETWEEN ? AND ? ";
    $params = [$start_date, $end_date];
    
    // Note: Other filters not available for aggregated data
    
} else {
    $sql_where = "WHERE r.date BETWEEN ? AND ? ";
    $params = [$start_date, $end_date];
    
    if ($publisher_id > 0) {
        $sql_where .= "AND r.publisher_id = ? ";
        $params[] = $publisher_id;
    }
    
    if ($website_id > 0) {
        $sql_where .= "AND z.website_id = ? ";
        $params[] = $website_id;
    }
    
    if ($campaign_id > 0) {
        $sql_where .= "AND r.campaign_id = ? ";
        $params[] = $campaign_id;
    }
    
    if (!empty($campaign_type)) {
        $sql_where .= "AND c.type = ? ";
        $params[] = $campaign_type;
    }
    
    if (!empty($country)) {
        $sql_where .= "AND b.country = ? ";
        $params[] = $country;
    }
    
    if (!empty($device_type)) {
        $sql_where .= "AND b.device_type = ? ";
        $params[] = $device_type;
    }
}

// Combine all SQL parts
$sql = $sql_select . " " . $sql_from . " " . $sql_where . " " . $sql_group_by . " " . $sql_order_by;

// Execute the query
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $query_error = false;
} catch (PDOException $e) {
    // Handle query error
    $error_message = "Database error: " . $e->getMessage();
    $results = [];
    $query_error = true;
}

// Prepare data for charts
$labels = [];
$impressions_data = [];
$clicks_data = [];
$revenue_data = [];
$publisher_revenue_data = [];
$platform_revenue_data = [];
$ecpm_data = [];
$publisher_ecpm_data = [];
$rtb_impressions_data = [];
$ron_impressions_data = [];

foreach ($results as $row) {
    $labels[] = $row['group_label'];
    $impressions_data[] = intval($row['total_impressions']);
    $clicks_data[] = intval($row['total_clicks']);
    $revenue_data[] = round(floatval($row['total_revenue']), 4);
    $publisher_revenue_data[] = round(floatval($row['total_publisher_revenue']), 4);
    $platform_revenue_data[] = round(floatval($row['total_platform_revenue']), 4);
    $ecpm_data[] = round(floatval($row['ecpm']), 2);
    $publisher_ecpm_data[] = round(floatval($row['publisher_ecpm']), 2);
    
    // Handle RTB and RON impressions if available
    $rtb_impressions_data[] = isset($row['rtb_impressions']) ? intval($row['rtb_impressions']) : 0;
    $ron_impressions_data[] = isset($row['ron_impressions']) ? intval($row['ron_impressions']) : 0;
}

// Calculate totals
$total_impressions = array_sum($impressions_data);
$total_clicks = array_sum($clicks_data);
$total_revenue = array_sum($revenue_data);
$total_publisher_revenue = array_sum($publisher_revenue_data);
$total_platform_revenue = array_sum($platform_revenue_data);
$total_ctr = ($total_impressions > 0) ? ($total_clicks / $total_impressions) * 100 : 0;
$total_ecpm = ($total_impressions > 0) ? ($total_revenue / $total_impressions) * 1000 : 0;
$total_publisher_ecpm = ($total_impressions > 0) ? ($total_publisher_revenue / $total_impressions) * 1000 : 0;
$total_rtb_impressions = array_sum($rtb_impressions_data);
$total_ron_impressions = array_sum($ron_impressions_data);

// Calculate platform share percentage
$platform_share = ($total_revenue > 0) ? ($total_platform_revenue / $total_revenue * 100) : 0;
$publisher_share = 100 - $platform_share;

// Encode chart data for JavaScript
$chart_data = json_encode([
    'labels' => $labels,
    'impressions' => $impressions_data,
    'clicks' => $clicks_data,
    'revenue' => $revenue_data,
    'publisher_revenue' => $publisher_revenue_data,
    'platform_revenue' => $platform_revenue_data,
    'ecpm' => $ecpm_data,
    'publisher_ecpm' => $publisher_ecpm_data,
    'rtb_impressions' => $rtb_impressions_data,
    'ron_impressions' => $ron_impressions_data
]);
?>

<div class="container-fluid px-4">
    <h1 class="h3 mb-2">
        <i class="fas fa-chart-line"></i> Statistics Dashboard
        <?php if ($useAggregatedData): ?>
            <span class="badge bg-primary">Aggregated Data</span>
        <?php else: ?>
            <span class="badge bg-info">Detailed Data</span>
        <?php endif; ?>
    </h1>
    
    <!-- Display error message if there was a query error -->
    <?php if ($query_error): ?>
    <div class="alert alert-danger mb-4">
        <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error_message); ?>
        <hr>
        <pre class="small"><?php echo htmlspecialchars($sql); ?></pre>
    </div>
    <?php endif; ?>
    
    <!-- Filters Form -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-filter"></i> Filters</h5>
            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse" aria-expanded="false" aria-controls="filterCollapse">
                <i class="fas fa-chevron-down"></i>
            </button>
        </div>
        <div class="collapse show" id="filterCollapse">
            <div class="card-body">
                <form id="statsFilterForm" method="GET" class="row g-3">
                    <!-- Date Range -->
                    <div class="col-md-4">
                        <label class="form-label">Date Range</label>
                        <div class="input-group">
                            <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $start_date; ?>">
                            <span class="input-group-text">to</span>
                            <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo $end_date; ?>">
                        </div>
                        <div class="mt-2">
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-outline-secondary preset-range" data-range="today">Today</button>
                                <button type="button" class="btn btn-outline-secondary preset-range" data-range="yesterday">Yesterday</button>
                                <button type="button" class="btn btn-outline-secondary preset-range" data-range="week">Last 7 days</button>
                                <button type="button" class="btn btn-outline-secondary preset-range" data-range="month">This Month</button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Group By -->
                    <div class="col-md-3">
                        <label for="group_by" class="form-label">Group By</label>
                        <select class="form-select" id="group_by" name="group_by">
                            <option value="date" <?php echo $group_by == 'date' ? 'selected' : ''; ?>>Date</option>
                            <option value="week" <?php echo $group_by == 'week' ? 'selected' : ''; ?>>Week</option>
                            <option value="month" <?php echo $group_by == 'month' ? 'selected' : ''; ?>>Month</option>
                            <?php if (!$useAggregatedData): ?>
                            <option value="publisher" <?php echo $group_by == 'publisher' ? 'selected' : ''; ?>>Publisher</option>
                            <option value="website" <?php echo $group_by == 'website' ? 'selected' : ''; ?>>Website</option>
                            <option value="zone" <?php echo $group_by == 'zone' ? 'selected' : ''; ?>>Zone</option>
                            <option value="campaign" <?php echo $group_by == 'campaign' ? 'selected' : ''; ?>>Campaign</option>
                            <option value="campaign_type" <?php echo $group_by == 'campaign_type' ? 'selected' : ''; ?>>Campaign Type</option>
                            <option value="country" <?php echo $group_by == 'country' ? 'selected' : ''; ?>>Country</option>
                            <option value="device" <?php echo $group_by == 'device' ? 'selected' : ''; ?>>Device Type</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    
                    <!-- Data Source -->
                    <div class="col-md-2">
                        <label for="data_source" class="form-label">Data Source</label>
                        <select class="form-select" id="data_source" name="data_source">
                            <option value="daily" <?php echo $data_source == 'daily' ? 'selected' : ''; ?>>Aggregated</option>
                            <option value="detailed" <?php echo $data_source == 'detailed' ? 'selected' : ''; ?>>Detailed</option>
                        </select>
                        <div class="form-text small">Aggregated = faster, Detailed = more options</div>
                    </div>
                    
                    <!-- Chart Type -->
                    <div class="col-md-3">
                        <label for="chart_type" class="form-label">Chart Type</label>
                        <select class="form-select" id="chart_type">
                            <option value="line">Line Chart</option>
                            <option value="bar">Bar Chart</option>
                            <option value="pie">Pie Chart</option>
                        </select>
                    </div>
                    
                    <!-- Filters that only work with detailed data -->
                    <div class="col-12">
                        <div class="row g-3">
                            <!-- Publisher Filter -->
                            <div class="col-md-3">
                                <label for="publisher_id" class="form-label">Publisher</label>
                                <select class="form-select <?php echo $useAggregatedData ? 'text-muted' : ''; ?>" id="publisher_id" name="publisher_id" <?php echo $useAggregatedData ? 'disabled' : ''; ?>>
                                    <option value="0">All Publishers</option>
                                    <?php foreach ($publishers as $pub): ?>
                                        <option value="<?php echo $pub['id']; ?>" <?php echo $publisher_id == $pub['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($pub['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($useAggregatedData): ?>
                                <div class="form-text small text-danger">Switch to detailed data to use this filter</div>
                                <?php endif; ?>
                            </div>

                            <!-- Website Filter -->
                            <div class="col-md-3">
                                <label for="website_id" class="form-label">Website</label>
                                <select class="form-select <?php echo $useAggregatedData ? 'text-muted' : ''; ?>" id="website_id" name="website_id" <?php echo $useAggregatedData ? 'disabled' : ''; ?>>
                                    <option value="0">All Websites</option>
                                    <?php foreach ($websites as $site): ?>
                                        <option value="<?php echo $site['id']; ?>" <?php echo $website_id == $site['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($site['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($useAggregatedData): ?>
                                <div class="form-text small text-danger">Switch to detailed data to use this filter</div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Campaign Filter -->
                            <div class="col-md-3">
                                <label for="campaign_id" class="form-label">Campaign</label>
                                <select class="form-select <?php echo $useAggregatedData ? 'text-muted' : ''; ?>" id="campaign_id" name="campaign_id" <?php echo $useAggregatedData ? 'disabled' : ''; ?>>
                                    <option value="0">All Campaigns</option>
                                    <?php foreach ($campaigns as $camp): ?>
                                        <option value="<?php echo $camp['id']; ?>" <?php echo $campaign_id == $camp['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($camp['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($useAggregatedData): ?>
                                <div class="form-text small text-danger">Switch to detailed data to use this filter</div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Campaign Type Filter -->
                            <div class="col-md-3">
                                <label for="campaign_type" class="form-label">Campaign Type</label>
                                <select class="form-select <?php echo $useAggregatedData ? 'text-muted' : ''; ?>" id="campaign_type" name="campaign_type" <?php echo $useAggregatedData ? 'disabled' : ''; ?>>
                                    <option value="">All Types</option>
                                    <option value="rtb" <?php echo $campaign_type == 'rtb' ? 'selected' : ''; ?>>RTB</option>
                                    <option value="ron" <?php echo $campaign_type == 'ron' ? 'selected' : ''; ?>>RON</option>
                                </select>
                                <?php if ($useAggregatedData): ?>
                                <div class="form-text small text-danger">Switch to detailed data to use this filter</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Button Row -->
                    <div class="col-12 text-end">
                        <button type="reset" class="btn btn-secondary">Reset</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Apply Filters
                        </button>
                        <div class="btn-group">
                            <button type="button" class="btn btn-success dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-download"></i> Export
                            </button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="#" id="exportCSV">Export to CSV</a></li>
                                <li><a class="dropdown-item" href="#" id="exportExcel">Export to Excel</a></li>
                            </ul>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Summary Cards Row 1 - Main Metrics -->
    <div class="row">
        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Impressions</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($total_impressions); ?></div>
                            <div class="small text-muted mt-1">
                                RTB: <?php echo number_format($total_rtb_impressions); ?><br>
                                RON: <?php echo number_format($total_ron_impressions); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-eye fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Clicks</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($total_clicks); ?></div>
                            <div class="small text-muted mt-1">
                                CTR: <?php echo number_format($total_ctr, 2); ?>%
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-mouse-pointer fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Revenue</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo '$' . number_format($total_revenue, 4); ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-dollar-sign fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-danger shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Publisher Revenue</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo '$' . number_format($total_publisher_revenue, 4); ?></div>
                            <div class="small text-muted mt-1">
                                <?php echo number_format($publisher_share, 1); ?>% of total
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-hand-holding-usd fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-dark shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-dark text-uppercase mb-1">Platform Revenue</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo '$' . number_format($total_platform_revenue, 4); ?></div>
                            <div class="small text-muted mt-1">
                                <?php echo number_format($platform_share, 1); ?>% of total
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-building fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">eCPM Comparison</div>
                            <div class="small font-weight-bold">Original: $<?php echo number_format($total_ecpm, 2); ?></div>
                            <div class="small font-weight-bold">Publisher: $<?php echo number_format($total_publisher_ecpm, 2); ?></div>
                            <div class="progress progress-sm mt-2">
                                <div class="progress-bar bg-info" role="progressbar" style="width: <?php echo min(100, ($total_publisher_ecpm / max(0.01, $total_ecpm)) * 100); ?>%" 
                                     aria-valuenow="<?php echo $total_publisher_ecpm; ?>" aria-valuemin="0" aria-valuemax="<?php echo $total_ecpm; ?>"></div>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-chart-line fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Charts Row -->
    <div class="row">
        <!-- Main Chart -->
        <div class="col-lg-8 mb-4">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold text-primary" id="chartTitle"><?php echo htmlspecialchars($chart_label); ?></h6>
                    <div class="dropdown no-arrow">
                        <a class="dropdown-toggle" href="#" role="button" id="dropdownMenuLink" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            <i class="fas fa-ellipsis-v fa-sm fa-fw text-gray-400"></i>
                        </a>
                        <div class="dropdown-menu dropdown-menu-right shadow animated--fade-in" aria-labelledby="dropdownMenuLink">
                            <div class="dropdown-header">Chart Options:</div>
                            <a class="dropdown-item chart-metric" href="#" data-metric="impressions">Show Impressions</a>
                            <a class="dropdown-item chart-metric" href="#" data-metric="clicks">Show Clicks</a>
                            <a class="dropdown-item chart-metric" href="#" data-metric="revenue">Show Revenue</a>
                            <a class="dropdown-item chart-metric" href="#" data-metric="publisher_revenue">Show Publisher Revenue</a>
                            <a class="dropdown-item chart-metric" href="#" data-metric="platform_revenue">Show Platform Revenue</a>
                            <a class="dropdown-item chart-metric" href="#" data-metric="ecpm">Show Original eCPM</a>
                            <a class="dropdown-item chart-metric" href="#" data-metric="publisher_ecpm">Show Publisher eCPM</a>
                            <a class="dropdown-item chart-metric" href="#" data-metric="compare_ecpm">Compare eCPMs</a>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="chart-area">
                        <canvas id="mainChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Revenue Distribution & eCPM Comparison Charts -->
        <div class="col-lg-4">
            <!-- Revenue Distribution Chart -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Revenue Distribution</h6>
                </div>
                <div class="card-body">
                    <div class="chart-pie pt-4 pb-2">
                        <canvas id="revenueDistributionChart"></canvas>
                    </div>
                    <div class="mt-4 text-center small">
                        <span class="mr-2">
                            <i class="fas fa-circle text-danger"></i> Publisher Revenue: <?php echo number_format($publisher_share, 1); ?>%
                        </span>
                        <span class="mr-2">
                            <i class="fas fa-circle text-dark"></i> Platform Revenue: <?php echo number_format($platform_share, 1); ?>%
                        </span>
                    </div>
                </div>
            </div>
            
            <!-- eCPM Comparison Chart -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">eCPM Comparison</h6>
                </div>
                <div class="card-body">
                    <div class="chart-bar">
                        <canvas id="ecpmComparisonChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Results Table -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Detailed Results</h6>
        </div>
        <div class="card-body">
            <?php if ($query_error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> An error occurred while retrieving data. Please try again or contact support.
                </div>
            <?php elseif (empty($results)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> No data found for the selected filters.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover" id="resultsTable" width="100%" cellspacing="0">
                        <thead>
                            <tr>
                                <th><?php echo ucfirst($group_by); ?></th>
                                <th>Impressions</th>
                                <th>Clicks</th>
                                <th>CTR</th>
                                <th>Total Revenue</th>
                                <th>Publisher Revenue</th>
                                <th>Platform Revenue</th>
                                <th>Original eCPM</th>
                                <th>Publisher eCPM</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['group_label']); ?></td>
                                    <td><?php echo number_format($row['total_impressions']); ?></td>
                                    <td><?php echo number_format($row['total_clicks']); ?></td>
                                    <td><?php echo number_format($row['ctr'], 2); ?>%</td>
                                    <td>$<?php echo number_format($row['total_revenue'], 4); ?></td>
                                    <td>$<?php echo number_format($row['total_publisher_revenue'], 4); ?></td>
                                    <td>$<?php echo number_format($row['total_platform_revenue'], 4); ?></td>
                                    <td>$<?php echo number_format($row['ecpm'], 2); ?></td>
                                    <td>$<?php echo number_format($row['publisher_ecpm'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th>Total</th>
                                <th><?php echo number_format($total_impressions); ?></th>
                                <th><?php echo number_format($total_clicks); ?></th>
                                <th><?php echo number_format($total_ctr, 2); ?>%</th>
                                <th>$<?php echo number_format($total_revenue, 4); ?></th>
                                <th>$<?php echo number_format($total_publisher_revenue, 4); ?></th>
                                <th>$<?php echo number_format($total_platform_revenue, 4); ?></th>
                                <th>$<?php echo number_format($total_ecpm, 2); ?></th>
                                <th>$<?php echo number_format($total_publisher_ecpm, 2); ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Include Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize the data from PHP
    const chartData = <?php echo $chart_data; ?>;
    let currentChartType = 'line';
    let currentMetric = 'impressions';
    
    // Initialize DataTables
    if (document.getElementById('resultsTable')) {
        $('#resultsTable').DataTable({
            ordering: true,
            paging: true,
            searching: true,
            responsive: true,
            lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]]
        });
    }
    
    // Function to update the main chart
    function updateChart() {
        const ctx = document.getElementById('mainChart');
        if (!ctx) return; // Exit if chart canvas doesn't exist
        
        // Destroy previous chart if it exists
        if (window.mainChart) {
            window.mainChart.destroy();
        }
        
        // Special case for eCPM comparison
        if (currentMetric === 'compare_ecpm') {
            createEcpmComparisonChart(true); // true = use the main chart
            return;
        }
        
        // Chart configuration based on the selected type
        let config = {
            type: currentChartType,
            data: {
                labels: chartData.labels || [],
                datasets: []
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                
                                if (currentMetric.includes('revenue') || currentMetric.includes('ecpm')) {
                                    label += '$' + context.parsed.y.toFixed(4);
                                } else if (currentMetric === 'ctr') {
                                    label += context.parsed.y.toFixed(2) + '%';
                                } else {
                                    label += context.parsed.y.toLocaleString();
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            drawBorder: false,
                            display: true
                        },
                        ticks: {
                            maxTicksLimit: 20
                        }
                    },
                    y: {
                        grid: {
                            drawBorder: false,
                            display: true
                        },
                        ticks: {
                            beginAtZero: true,
                            callback: function(value) {
                                if (currentMetric.includes('revenue') || currentMetric.includes('ecpm')) {
                                    return '$' + value.toFixed(2);
                                } else if (currentMetric === 'ctr') {
                                    return value + '%';
                                } else {
                                    return value.toLocaleString();
                                }
                            }
                        }
                    }
                }
            }
        };
        
        // Add specific dataset based on the current metric
        if (currentChartType === 'pie') {
            // For pie charts, we show revenue distribution
            config.data.datasets.push({
                label: 'Revenue Distribution',
                data: [
                    (chartData.publisher_revenue || []).reduce((a, b) => a + b, 0), 
                    (chartData.platform_revenue || []).reduce((a, b) => a + b, 0)
                ],
                backgroundColor: ['#e74a3b', '#212529'],
                hoverOffset: 4
            });
            
            config.options.plugins.tooltip = {
                callbacks: {
                    label: function(context) {
                        const label = context.label || '';
                        const value = context.raw || 0;
                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                        const percentage = ((value / total) * 100).toFixed(2) + '%';
                        return `${label}: $${value.toFixed(4)} (${percentage})`;
                    }
                }
            };
            
            // Update chart title
            document.getElementById('chartTitle').innerText = 'Revenue Distribution';
        } else {
            // For line/bar charts, show the selected metric
            let dataset = {
                label: 'Impressions',
                data: chartData.impressions || [],
                backgroundColor: 'rgba(78, 115, 223, 0.2)',
                borderColor: 'rgba(78, 115, 223, 1)',
                borderWidth: 1,
                pointRadius: 3,
                pointBackgroundColor: 'rgba(78, 115, 223, 1)',
                pointBorderColor: '#fff',
                pointHoverRadius: 5,
                pointHoverBackgroundColor: 'rgba(78, 115, 223, 1)',
                pointHoverBorderColor: '#fff',
                tension: 0.1
            };
            
            switch (currentMetric) {
                case 'clicks':
                    dataset.label = 'Clicks';
                    dataset.data = chartData.clicks || [];
                    dataset.backgroundColor = 'rgba(28, 200, 138, 0.2)';
                    dataset.borderColor = 'rgba(28, 200, 138, 1)';
                    dataset.pointBackgroundColor = 'rgba(28, 200, 138, 1)';
                    dataset.pointHoverBackgroundColor = 'rgba(28, 200, 138, 1)';
                    break;
                    
                case 'revenue':
                    dataset.label = 'Total Revenue';
                    dataset.data = chartData.revenue || [];
                    dataset.backgroundColor = 'rgba(246, 194, 62, 0.2)';
                    dataset.borderColor = 'rgba(246, 194, 62, 1)';
                    dataset.pointBackgroundColor = 'rgba(246, 194, 62, 1)';
                    dataset.pointHoverBackgroundColor = 'rgba(246, 194, 62, 1)';
                    break;
                    
                case 'publisher_revenue':
                    dataset.label = 'Publisher Revenue';
                    dataset.data = chartData.publisher_revenue || [];
                    dataset.backgroundColor = 'rgba(231, 74, 59, 0.2)';
                    dataset.borderColor = 'rgba(231, 74, 59, 1)';
                    dataset.pointBackgroundColor = 'rgba(231, 74, 59, 1)';
                    dataset.pointHoverBackgroundColor = 'rgba(231, 74, 59, 1)';
                    break;
                    
                case 'platform_revenue':
                    dataset.label = 'Platform Revenue';
                    dataset.data = chartData.platform_revenue || [];
                    dataset.backgroundColor = 'rgba(58, 59, 69, 0.2)';
                    dataset.borderColor = 'rgba(58, 59, 69, 1)';
                    dataset.pointBackgroundColor = 'rgba(58, 59, 69, 1)';
                    dataset.pointHoverBackgroundColor = 'rgba(58, 59, 69, 1)';
                    break;
                    
                case 'ecpm':
                    dataset.label = 'Original eCPM ($)';
                    dataset.data = chartData.ecpm || [];
                    dataset.backgroundColor = 'rgba(153, 102, 255, 0.2)';
                    dataset.borderColor = 'rgba(153, 102, 255, 1)';
                    dataset.pointBackgroundColor = 'rgba(153, 102, 255, 1)';
                    dataset.pointHoverBackgroundColor = 'rgba(153, 102, 255, 1)';
                    break;
                    
                case 'publisher_ecpm':
                    dataset.label = 'Publisher eCPM ($)';
                    dataset.data = chartData.publisher_ecpm || [];
                    dataset.backgroundColor = 'rgba(54, 185, 204, 0.2)';
                    dataset.borderColor = 'rgba(54, 185, 204, 1)';
                    dataset.pointBackgroundColor = 'rgba(54, 185, 204, 1)';
                    dataset.pointHoverBackgroundColor = 'rgba(54, 185, 204, 1)';
                    break;
            }
            
            config.data.datasets.push(dataset);
            
            // Update chart title
            const chartTitle = document.getElementById('chartTitle');
            if (chartTitle) {
                chartTitle.innerText = `${dataset.label} by <?php echo $chart_x_label; ?>`;
            }
        }
        
        // Create the chart
        window.mainChart = new Chart(ctx, config);
    }
    
    // Create revenue distribution pie chart
    function createRevenueDistributionChart() {
        const ctx = document.getElementById('revenueDistributionChart');
        if (!ctx) return; // Exit if chart canvas doesn't exist
        
        // Destroy previous chart if it exists
        if (window.revenueChart) {
            window.revenueChart.destroy();
        }
        
        // Calculate totals
        const publisherTotal = (chartData.publisher_revenue || []).reduce((a, b) => a + b, 0);
        const platformTotal = (chartData.platform_revenue || []).reduce((a, b) => a + b, 0);
        
        // Create the pie chart
        window.revenueChart = new Chart(ctx, {
            type: 'pie',
            data: {
                labels: ['Publisher Revenue', 'Platform Revenue'],
                datasets: [{
                    data: [publisherTotal, platformTotal],
                    backgroundColor: ['#e74a3b', '#212529'],
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.raw || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((value / total) * 100).toFixed(2) + '%';
                                return `${label}: $${value.toFixed(4)} (${percentage})`;
                            }
                        }
                    },
                    datalabels: {
                        formatter: (value, ctx) => {
                            const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = ((value / total) * 100).toFixed(1) + '%';
                            return percentage;
                        },
                        color: '#fff',
                        font: {
                            weight: 'bold'
                        }
                    }
                }
            }
        });
    }
    
    // Create eCPM comparison chart
    function createEcpmComparisonChart(useMainChart = false) {
        const ctx = useMainChart ? 
                  document.getElementById('mainChart') : 
                  document.getElementById('ecpmComparisonChart');
        if (!ctx) return;
        
        // Destroy previous chart if it exists
        if (useMainChart && window.mainChart) {
            window.mainChart.destroy();
        } else if (!useMainChart && window.ecpmComparisonChart) {
            window.ecpmComparisonChart.destroy();
        }
        
        const labels = chartData.labels || [];
        const originalEcpm = chartData.ecpm || [];
        const publisherEcpm = chartData.publisher_ecpm || [];
        
        // Create the comparison chart
        const chart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Original eCPM',
                        data: originalEcpm,
                        backgroundColor: 'rgba(153, 102, 255, 0.5)',
                        borderColor: 'rgb(153, 102, 255)',
                        borderWidth: 1
                    },
                    {
                        label: 'Publisher eCPM',
                        data: publisherEcpm,
                        backgroundColor: 'rgba(54, 185, 204, 0.5)',
                        borderColor: 'rgb(54, 185, 204)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            borderDash: [2, 2]
                        },
                        ticks: {
                            callback: function(value) {
                                return '$' + value.toFixed(2);
                            }
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.dataset.label || '';
                                return `${label}: $${context.parsed.y.toFixed(4)}`;
                            }
                        }
                    }
                }
            }
        });
        
        // Store reference to the chart
        if (useMainChart) {
            window.mainChart = chart;
            // Update chart title
            const chartTitle = document.getElementById('chartTitle');
            if (chartTitle) {
                chartTitle.innerText = `eCPM Comparison by <?php echo $chart_x_label; ?>`;
            }
        } else {
            window.ecpmComparisonChart = chart;
        }
    }
    
    // Initial chart creation - only if there's data
    if (chartData && chartData.labels && chartData.labels.length > 0) {
        updateChart();
        createRevenueDistributionChart();
        createEcpmComparisonChart();
    }
    
    // Event listeners for chart options
    const chartTypeSelector = document.getElementById('chart_type');
    if (chartTypeSelector) {
        chartTypeSelector.addEventListener('change', function() {
            currentChartType = this.value;
            updateChart();
        });
    }
    
    // Event listeners for chart metric options
    document.querySelectorAll('.chart-metric').forEach(item => {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            currentMetric = this.getAttribute('data-metric');
            updateChart();
        });
    });
    
    // Event listener for data source change
    document.getElementById('data_source').addEventListener('change', function() {
        // Update the form and submit
        document.getElementById('statsFilterForm').submit();
    });
    
    // Date range preset buttons
    document.querySelectorAll('.preset-range').forEach(button => {
        button.addEventListener('click', function() {
            const range = this.getAttribute('data-range');
            const startDate = document.getElementById('start_date');
            const endDate = document.getElementById('end_date');
            
            if (!startDate || !endDate) return;
            
            const today = new Date();
            let start = new Date(today);
            
            switch (range) {
                case 'today':
                    // Start and end date are both today
                    break;
                    
                case 'yesterday':
                    start.setDate(today.getDate() - 1);
                    today.setDate(today.getDate() - 1);
                    break;
                    
                case 'week':
                    start.setDate(today.getDate() - 6);
                    break;
                    
                case 'month':
                    start = new Date(today.getFullYear(), today.getMonth(), 1);
                    break;
            }
            
            startDate.value = start.toISOString().split('T')[0];
            endDate.value = today.toISOString().split('T')[0];
        });
    });
    
    // Export functionality
    const exportCSVBtn = document.getElementById('exportCSV');
    if (exportCSVBtn) {
        exportCSVBtn.addEventListener('click', function(e) {
            e.preventDefault();
            exportTableToCSV('statistics_export.csv');
        });
    }
    
    const exportExcelBtn = document.getElementById('exportExcel');
    if (exportExcelBtn) {
        exportExcelBtn.addEventListener('click', function(e) {
            e.preventDefault();
            exportTableToExcel('statistics_export.xlsx');
        });
    }
    
        // Function to export table to CSV
    function exportTableToCSV(filename) {
        const table = document.getElementById('resultsTable');
        if (!table) return;
        
        let csv = [];
        let rows = table.querySelectorAll('tr');
        
        for (let i = 0; i < rows.length; i++) {
            let row = [], cols = rows[i].querySelectorAll('td, th');
            
            for (let j = 0; j < cols.length; j++) {
                // Replace $ and commas to ensure proper CSV format
                let data = cols[j].innerText.replace(/(\$|,)/g, '');
                row.push('"' + data + '"');
            }
            
            csv.push(row.join(','));
        }
        
        // Download CSV file
        downloadCSV(csv.join('\n'), filename);
    }
    
    function downloadCSV(csv, filename) {
        let csvFile;
        let downloadLink;
        
        // CSV file
        csvFile = new Blob([csv], {type: 'text/csv'});
        
        // Download link
        downloadLink = document.createElement('a');
        
        // File name
        downloadLink.download = filename;
        
        // Create a link to the file
        downloadLink.href = window.URL.createObjectURL(csvFile);
        
        // Hide download link
        downloadLink.style.display = 'none';
        
        // Add the link to DOM
        document.body.appendChild(downloadLink);
        
        // Click download link
        downloadLink.click();
        
        // Remove link from DOM
        document.body.removeChild(downloadLink);
    }
    
    // Excel export (simplified - in real implementation would use a library like SheetJS)
    function exportTableToExcel(filename) {
        // For simplicity, this just triggers the CSV download
        // In a real implementation, you'd use SheetJS or similar library
        alert("Excel export would be implemented with a library like SheetJS. For now, CSV is downloaded instead.");
        exportTableToCSV(filename.replace('.xlsx', '.csv'));
    }
    
    // Form reset handler
    const resetBtn = document.querySelector('button[type="reset"]');
    if (resetBtn) {
        resetBtn.addEventListener('click', function() {
            // Wait for the form to reset
            setTimeout(() => {
                // Set default dates
                const startDate = document.getElementById('start_date');
                const endDate = document.getElementById('end_date');
                if (startDate) startDate.value = '<?php echo date('Y-m-d', strtotime('-7 days')); ?>';
                if (endDate) endDate.value = '<?php echo date('Y-m-d'); ?>';
                
                // Reset data source to daily
                const dataSource = document.getElementById('data_source');
                if (dataSource) dataSource.value = 'daily';
                
                // Reset group by to date
                const groupBy = document.getElementById('group_by');
                if (groupBy) groupBy.value = 'date';
            }, 10);
        });
    }
    
    // Toggle filter options based on data source
    document.getElementById('data_source').addEventListener('change', function() {
        const detailedFilters = document.querySelectorAll('.detailed-only');
        const isDetailed = this.value === 'detailed';
        
        detailedFilters.forEach(filter => {
            const select = filter.querySelector('select');
            if (select) {
                select.disabled = !isDetailed;
                if (!isDetailed) {
                    select.classList.add('text-muted');
                } else {
                    select.classList.remove('text-muted');
                }
            }
        });
    });
});
</script>

<style>
.border-left-primary {
    border-left: 0.25rem solid #4e73df !important;
}
.border-left-success {
    border-left: 0.25rem solid #1cc88a !important;
}
.border-left-info {
    border-left: 0.25rem solid #36b9cc !important;
}
.border-left-warning {
    border-left: 0.25rem solid #f6c23e !important;
}
.border-left-danger {
    border-left: 0.25rem solid #e74a3b !important;
}
.border-left-dark {
    border-left: 0.25rem solid #212529 !important;
}
.chart-area {
    position: relative;
    height: 300px;
    width: 100%;
}
.chart-pie {
    position: relative;
    height: 200px;
    width: 100%;
}
.chart-bar {
    position: relative;
    height: 200px;
    width: 100%;
}
</style>

<?php include 'includes/footer.php'; ?>