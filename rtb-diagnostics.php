<?php
// RTB Integration Diagnostic Tool
// Current Date: 2025-06-23 23:48:23
// Current User: simoncode12

echo "<h1>RTB Integration Diagnostic Report</h1>";
echo "<p>Generated at: " . date('Y-m-d H:i:s') . "</p>";

// Include database configuration
require_once __DIR__ . '/config/database.php';

// Check zone configuration
echo "<h2>1. Zone Configuration</h2>";
try {
    $stmt = $pdo->prepare("
        SELECT z.*, w.domain, w.publisher_id 
        FROM zones z
        JOIN websites w ON z.website_id = w.id
        WHERE z.id = ? 
    ");
    $stmt->execute([1]); // Zone ID 1
    $zone = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($zone) {
        echo "<div style='color:green'>✓ Zone ID 1 found</div>";
        echo "<pre>" . print_r($zone, true) . "</pre>";
        
        if ($zone['status'] != 'active') {
            echo "<div style='color:red'>✕ Zone is not active! Current status: {$zone['status']}</div>";
            echo "<div>Solution: Update zone status to 'active' in database</div>";
        }
    } else {
        echo "<div style='color:red'>✕ Zone ID 1 not found!</div>";
    }
} catch (Exception $e) {
    echo "<div style='color:red'>Error checking zone: " . $e->getMessage() . "</div>";
}

// Check RTB campaigns
echo "<h2>2. RTB Campaign Configuration</h2>";
try {
    $rtb_campaigns = $pdo->query("
        SELECT c.id, c.name, c.status, c.endpoint_url, c.type,
               COUNT(cr.id) as creative_count
        FROM campaigns c
        LEFT JOIN creatives cr ON c.id = cr.campaign_id
        WHERE c.type = 'rtb'
        GROUP BY c.id
        ORDER BY c.status DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($rtb_campaigns)) {
        echo "<div style='color:green'>✓ " . count($rtb_campaigns) . " RTB campaigns found</div>";
        
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Name</th><th>Status</th><th>Endpoint URL</th><th>Creatives</th><th>Issues</th></tr>";
        
        foreach ($rtb_campaigns as $campaign) {
            $endpoint_status = empty($campaign['endpoint_url']) ? 
                "<span style='color:red'>Missing</span>" : 
                "<span style='color:green'>Set</span>";
                
            $status_color = $campaign['status'] == 'active' ? 'green' : 'red';
            $creative_color = $campaign['creative_count'] > 0 ? 'green' : 'red';
            
            echo "<tr>";
            echo "<td>{$campaign['id']}</td>";
            echo "<td>{$campaign['name']}</td>";
            echo "<td style='color:{$status_color}'>{$campaign['status']}</td>";
            echo "<td>{$endpoint_status}<br><small>" . substr($campaign['endpoint_url'] ?? '', 0, 50) . "...</small></td>";
            echo "<td style='color:{$creative_color}'>{$campaign['creative_count']}</td>";
            
            echo "<td>";
            if ($campaign['status'] != 'active') {
                echo "• Campaign not active<br>";
            }
            if (empty($campaign['endpoint_url']) && $campaign['type'] == 'rtb') {
                echo "• Missing endpoint URL<br>";
            }
            if ($campaign['creative_count'] == 0) {
                echo "• No creatives<br>";
            }
            echo "</td>";
            
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<div style='color:red'>✕ No RTB campaigns found!</div>";
        echo "<div>Solution: Create RTB campaigns with proper endpoint URLs</div>";
    }
} catch (Exception $e) {
    echo "<div style='color:red'>Error checking RTB campaigns: " . $e->getMessage() . "</div>";
}

// Check creatives for size 300x250
echo "<h2>3. Creatives for Size 300x250</h2>";
try {
    $size_creatives = $pdo->query("
        SELECT cr.id, cr.name, cr.campaign_id, c.name as campaign_name, c.type as campaign_type, 
               c.status as campaign_status, cr.status as creative_status, cr.creative_type,
               cr.width, cr.height, cr.bid_amount
        FROM creatives cr
        JOIN campaigns c ON cr.campaign_id = c.id
        WHERE cr.width = 300 AND cr.height = 250
        ORDER BY c.status DESC, cr.status DESC, cr.bid_amount DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($size_creatives)) {
        echo "<div style='color:green'>✓ " . count($size_creatives) . " creatives found for size 300x250</div>";
        
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Name</th><th>Campaign</th><th>Type</th><th>Status</th><th>Bid</th><th>Issues</th></tr>";
        
        $has_active_creative = false;
        foreach ($size_creatives as $creative) {
            $campaign_status_color = $creative['campaign_status'] == 'active' ? 'green' : 'red';
            $creative_status_color = $creative['creative_status'] == 'active' ? 'green' : 'red';
            
            if ($creative['campaign_status'] == 'active' && $creative['creative_status'] == 'active') {
                $has_active_creative = true;
            }
            
            echo "<tr>";
            echo "<td>{$creative['id']}</td>";
            echo "<td>{$creative['name']}</td>";
            echo "<td>{$creative['campaign_name']} (ID: {$creative['campaign_id']})<br>" .
                 "<small style='color:{$campaign_status_color}'>Campaign: {$creative['campaign_status']}</small></td>";
            echo "<td>{$creative['campaign_type']} / {$creative['creative_type']}</td>";
            echo "<td style='color:{$creative_status_color}'>{$creative['creative_status']}</td>";
            echo "<td>\${$creative['bid_amount']}</td>";
            
            echo "<td>";
            if ($creative['campaign_status'] != 'active') {
                echo "• Campaign not active<br>";
            }
            if ($creative['creative_status'] != 'active') {
                echo "• Creative not active<br>";
            }
            echo "</td>";
            
            echo "</tr>";
        }
        echo "</table>";
        
        if (!$has_active_creative) {
            echo "<div style='color:red'>✕ No ACTIVE creatives for size 300x250 in ACTIVE campaigns!</div>";
            echo "<div>Solution: Activate both the campaign and at least one 300x250 creative</div>";
        }
    } else {
        echo "<div style='color:red'>✕ No creatives found for size 300x250!</div>";
        echo "<div>Solution: Create 300x250 creatives for your campaigns</div>";
    }
} catch (Exception $e) {
    echo "<div style='color:red'>Error checking creatives: " . $e->getMessage() . "</div>";
}

// Quick fix advice
echo "<h2>4. Quick Fix Recommendations</h2>";

echo "<h3>Step 1: Ensure your RTB campaign is active</h3>";
$sql = "UPDATE campaigns SET status = 'active' WHERE type = 'rtb' AND id = ?";
echo "<pre>-- Replace ? with your RTB campaign ID\n{$sql}</pre>";

echo "<h3>Step 2: Ensure you have a 300x250 creative that's active</h3>";
$sql = "UPDATE creatives SET status = 'active' WHERE width = 300 AND height = 250 AND campaign_id = ?";
echo "<pre>-- Replace ? with your RTB campaign ID\n{$sql}</pre>";

echo "<h3>Step 3: Ensure your RTB campaign has an endpoint URL</h3>";
$sql = "UPDATE campaigns SET endpoint_url = 'http://rtb.exoclick.com/rtb.php?idzone=5128252&fid=e573a1c2a656509b0112f7213359757be76929c7' WHERE id = ?";
echo "<pre>-- Replace ? with your RTB campaign ID and the URL with your actual endpoint\n{$sql}</pre>";

// Complete SQL fix script if needed
echo "<h3>Complete Fix (if all the above issues are present)</h3>";
echo "<pre>
-- Activate RTB campaign with ID 5 (replace with your actual campaign ID)
UPDATE campaigns SET 
    status = 'active', 
    endpoint_url = 'http://rtb.exoclick.com/rtb.php?idzone=5128252&fid=e573a1c2a656509b0112f7213359757be76929c7' 
WHERE id = 5;

-- Activate all 300x250 creatives for this campaign
UPDATE creatives SET status = 'active' 
WHERE width = 300 AND height = 250 AND campaign_id = 5;

-- Activate zone if needed
UPDATE zones SET status = 'active' WHERE id = 1;
</pre>";

?>