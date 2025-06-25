<?php
/**
 * ExoClick RTB Handler
 * Version: 1.0.3
 * Date: 2025-06-23 22:45:10
 * Author: simoncode12
 */

header('Content-Type: application/json');

// Get raw request
$input = file_get_contents('php://input');
$request = json_decode($input, true);

// Debug logging
error_log("[RTB] Handling request: " . json_encode(['id' => $request['id'] ?? 'unknown']));

// Validate request
if (!$request || !isset($request['imp'][0]['banner']['w']) || !isset($request['imp'][0]['banner']['h'])) {
    http_response_code(400);
    error_log("[RTB] Invalid request format");
    echo json_encode(['error' => 'Invalid request format']);
    exit;
}

// Extract size
$width = $request['imp'][0]['banner']['w'];
$height = $request['imp'][0]['banner']['h'];
$size = $width . 'x' . $height;

// Get RTB campaign from database to find endpoint
require_once '../config/database.php';

try {
    // Get active RTB campaigns with ExoClick endpoints
    $stmt = $pdo->prepare("
        SELECT c.id, c.name, c.endpoint_url, cr.id as creative_id, cr.bid_amount
        FROM campaigns c
        JOIN creatives cr ON c.id = cr.campaign_id
        WHERE c.status = 'active'
        AND c.type = 'rtb'
        AND c.endpoint_url IS NOT NULL
        AND c.endpoint_url != ''
        AND cr.width = ?
        AND cr.height = ?
        ORDER BY cr.bid_amount DESC
        LIMIT 5
    ");
    
    $stmt->execute([$width, $height]);
    $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("[RTB] Found " . count($campaigns) . " RTB campaigns matching size $size");
    
    // No matching campaigns
    if (empty($campaigns)) {
        error_log("[RTB] No RTB campaigns found for size $size");
        echo json_encode(['id' => $request['id'], 'seatbid' => []]);
        exit;
    }
    
    $all_bids = [];
    $request_id = $request['id'] ?? uniqid('req_');
    
    // Try each RTB endpoint
    foreach ($campaigns as $campaign) {
        $endpoint_url = $campaign['endpoint_url'];
        
        // Skip if no valid URL
        if (empty($endpoint_url) || !filter_var($endpoint_url, FILTER_VALIDATE_URL)) {
            error_log("[RTB] Invalid endpoint URL for campaign {$campaign['id']}");
            continue;
        }
        
        error_log("[RTB] Calling endpoint for campaign {$campaign['id']}: $endpoint_url");
        
        // Call RTB endpoint
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint_url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $input);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 0.5); // 500ms timeout
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0.2); // 200ms connect
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-openrtb-version: 2.5'
        ]);
        
        $start_time = microtime(true);
        $response = curl_exec($ch);
        $exec_time = (microtime(true) - $start_time) * 1000; // ms
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        error_log("[RTB] Campaign {$campaign['id']} response in {$exec_time}ms with code {$http_code}");
        
        // Process response
        if ($http_code == 200 && !empty($response)) {
            $rtb_data = json_decode($response, true);
            
            // Validate response format
            if (isset($rtb_data['seatbid']) && !empty($rtb_data['seatbid'][0]['bid'])) {
                error_log("[RTB] Valid bid received from campaign {$campaign['id']}");
                
                // Add our campaign info to each bid
                foreach ($rtb_data['seatbid'] as &$seatbid) {
                    foreach ($seatbid['bid'] as &$bid) {
                        // Add campaign_id to ext
                        if (!isset($bid['ext'])) {
                            $bid['ext'] = [];
                        }
                        
                        $bid['ext']['campaign_id'] = $campaign['id'];
                        $bid['ext']['internal_creative_id'] = $campaign['creative_id'];
                        
                        // Store bid for combined response
                        $all_bids[] = [
                            'seat_index' => count($all_bids),
                            'bid' => $bid
                        ];
                    }
                }
            } else {
                error_log("[RTB] Invalid bid structure from campaign {$campaign['id']}: " . substr($response, 0, 100));
            }
        } else if ($curl_error) {
            error_log("[RTB] CURL error for campaign {$campaign['id']}: $curl_error");
        } else {
            error_log("[RTB] Error response from campaign {$campaign['id']}: HTTP $http_code");
        }
    }
    
    // Build combined response
    $combined_response = [
        'id' => $request_id,
        'seatbid' => [],
        'bidid' => $request_id,
        'cur' => 'USD'
    ];
    
    // Add all bids to response
    foreach ($all_bids as $bid_data) {
        $seat_index = $bid_data['seat_index'];
        
        if (!isset($combined_response['seatbid'][$seat_index])) {
            $combined_response['seatbid'][$seat_index] = [
                'bid' => []
            ];
        }
        
        $combined_response['seatbid'][$seat_index]['bid'][] = $bid_data['bid'];
    }
    
    // Reindex seatbid array
    $combined_response['seatbid'] = array_values($combined_response['seatbid']);
    
    // Log final response
    error_log("[RTB] Combined response has " . count($all_bids) . " bids from " . 
              count($combined_response['seatbid']) . " seats");
    
    // Return combined response
    echo json_encode($combined_response);
    
} catch (Exception $e) {
    error_log("[RTB] Exception: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
?>