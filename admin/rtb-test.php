<?php
/**
 * RTB Endpoint Testing Tool
 * Test OpenRTB requests and responses
 * Date: 2025-06-23 21:40:30
 * Author: simoncode12
 */

include 'includes/header.php';

// Handle test request
$test_result = null;
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['test_rtb'])) {
    $test_request = [
        'id' => 'test-' . uniqid(),
        'at' => 1,
        'imp' => [
            [
                'id' => 'imp-' . uniqid(),
                'banner' => [
                    'w' => intval($_POST['width']),
                    'h' => intval($_POST['height']),
                    'mimes' => ['image/jpeg', 'image/png', 'video/mp4']
                ]
            ]
        ],
        'site' => [
            'id' => '12345',
            'domain' => $_POST['domain'] ?? 'example.com',
            'name' => $_POST['site_name'] ?? 'Test Site',
            'page' => $_POST['page_url'] ?? 'https://example.com/page'
        ],
        'device' => [
            'ua' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'ip' => $_POST['ip'] ?? '127.0.0.1',
            'geo' => [
                'country' => $_POST['country'] ?? 'US'
            ],
            'language' => 'en',
            'os' => $_POST['os'] ?? 'Windows'
        ],
        'user' => [
            'id' => 'user-' . uniqid()
        ]
    ];
    
    // Send request to our RTB endpoint
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://up.adstart.click/api/rtb-endpoint.php');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($test_request));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'User-Agent: RTB-Test-Tool/1.0'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $test_result = [
        'request' => $test_request,
        'response' => $response,
        'http_code' => $http_code,
        'response_parsed' => json_decode($response, true)
    ];
}
?>

<div class="row">
    <div class="col-12">
        <h1 class="h3 mb-4">
            <i class="fas fa-flask"></i> RTB Endpoint Testing
            <small class="text-muted">Test OpenRTB 2.5 compliance</small>
        </h1>
    </div>
</div>

<!-- Test Form -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="fas fa-play"></i> Send Test Bid Request</h5>
    </div>
    <div class="card-body">
        <form method="POST">
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Banner Size</label>
                        <div class="row">
                            <div class="col-6">
                                <input type="number" class="form-control" name="width" placeholder="Width" value="300" required>
                            </div>
                            <div class="col-6">
                                <input type="number" class="form-control" name="height" placeholder="Height" value="250" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Site Domain</label>
                        <input type="text" class="form-control" name="domain" value="example.com">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Site Name</label>
                        <input type="text" class="form-control" name="site_name" value="Test Website">
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Page URL</label>
                        <input type="url" class="form-control" name="page_url" value="https://example.com/test-page">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Country</label>
                        <select class="form-select" name="country">
                            <option value="US">United States</option>
                            <option value="UK">United Kingdom</option>
                            <option value="DE">Germany</option>
                            <option value="FR">France</option>
                            <option value="JP">Japan</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">IP Address</label>
                        <input type="text" class="form-control" name="ip" value="127.0.0.1">
                    </div>
                </div>
            </div>
            
            <button type="submit" name="test_rtb" class="btn btn-primary">
                <i class="fas fa-paper-plane"></i> Send Test Request
            </button>
        </form>
    </div>
</div>

<?php if ($test_result): ?>
<!-- Test Results -->
<div class="row">
    <!-- Request -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-arrow-right"></i> Bid Request</h5>
            </div>
            <div class="card-body">
                <pre class="bg-light p-3 rounded" style="max-height: 400px; overflow-y: auto;"><code><?php echo json_encode($test_result['request'], JSON_PRETTY_PRINT); ?></code></pre>
            </div>
        </div>
    </div>
    
    <!-- Response -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-arrow-left"></i> Bid Response 
                    <span class="badge bg-<?php echo $test_result['http_code'] == 200 ? 'success' : ($test_result['http_code'] == 204 ? 'warning' : 'danger'); ?>">
                        HTTP <?php echo $test_result['http_code']; ?>
                    </span>
                </h5>
            </div>
            <div class="card-body">
                <?php if ($test_result['http_code'] == 204): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-info-circle"></i> No Bid (HTTP 204) - No matching campaigns found
                    </div>
                <?php elseif ($test_result['http_code'] == 200): ?>
                    <pre class="bg-light p-3 rounded" style="max-height: 400px; overflow-y: auto;"><code><?php echo json_encode($test_result['response_parsed'], JSON_PRETTY_PRINT); ?></code></pre>
                    
                    <?php if (isset($test_result['response_parsed']['seatbid'])): ?>
                    <div class="mt-3">
                        <h6>Bid Analysis:</h6>
                        <?php 
                        $total_bids = 0;
                        foreach ($test_result['response_parsed']['seatbid'] as $seatbid) {
                            $total_bids += count($seatbid['bid']);
                        }
                        ?>
                        <ul class="list-unstyled">
                            <li><strong>Total Bids:</strong> <?php echo $total_bids; ?></li>
                            <li><strong>Seatbids:</strong> <?php echo count($test_result['response_parsed']['seatbid']); ?></li>
                            <?php if ($total_bids > 0): ?>
                                <li><strong>Highest Bid:</strong> $<?php 
                                    $highest = 0;
                                    foreach ($test_result['response_parsed']['seatbid'] as $seatbid) {
                                        foreach ($seatbid['bid'] as $bid) {
                                            $highest = max($highest, $bid['price']);
                                        }
                                    }
                                    echo number_format($highest, 4);
                                ?></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i> Error Response
                        <pre class="mt-2 mb-0"><?php echo htmlspecialchars($test_result['response']); ?></pre>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- RTB Endpoint Information -->
<div class="card mt-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="fas fa-info-circle"></i> RTB Endpoint Information</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <h6>Endpoint Details</h6>
                <ul class="list-unstyled">
                    <li><strong>Bid Request URL:</strong> <code>https://up.adstart.click/api/rtb-endpoint.php</code></li>
                    <li><strong>Method:</strong> POST</li>
                    <li><strong>Content-Type:</strong> application/json</li>
                    <li><strong>OpenRTB Version:</strong> 2.5</li>
                    <li><strong>Response Timeout:</strong> 100ms</li>
                </ul>
            </div>
            <div class="col-md-6">
                <h6>Supported Features</h6>
                <ul class="list-unstyled">
                    <li><i class="fas fa-check text-success"></i> Banner Ads</li>
                    <li><i class="fas fa-check text-success"></i> Image Creatives</li>
                    <li><i class="fas fa-check text-success"></i> Video Creatives</li>
                    <li><i class="fas fa-check text-success"></i> HTML Creatives</li>
                    <li><i class="fas fa-check text-success"></i> Geo Targeting</li>
                    <li><i class="fas fa-check text-success"></i> Win Notifications</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>