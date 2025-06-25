<?php
session_start();
require_once '../../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $creative_id = $_POST['id'] ?? null;
    $name = $_POST['name'] ?? '';
    $width = $_POST['width'] ?? '';
    $height = $_POST['height'] ?? '';
    $bid_amount = $_POST['bid_amount'] ?? '';
    $creative_type = $_POST['creative_type'] ?? '';
    $image_url = $_POST['image_url'] ?? '';
    $video_url = $_POST['video_url'] ?? '';
    $html_content = $_POST['html_content'] ?? '';
    $click_url = $_POST['click_url'] ?? '';
    
    if (!$creative_id || !$name || !$width || !$height || !$bid_amount) {
        echo json_encode(['success' => false, 'message' => 'All required fields must be filled']);
        exit;
    }
    
    try {
        // Get creative and campaign info first
        $stmt = $pdo->prepare("
            SELECT c.*, ca.type as campaign_type, ca.endpoint_url 
            FROM creatives c 
            LEFT JOIN campaigns ca ON c.campaign_id = ca.id 
            WHERE c.id = ?
        ");
        $stmt->execute([$creative_id]);
        $creative = $stmt->fetch();
        
        if (!$creative) {
            echo json_encode(['success' => false, 'message' => 'Creative not found']);
            exit;
        }
        
        $is_rtb_campaign = ($creative['campaign_type'] == 'rtb');
        
        // Validate content based on creative type for non-RTB external campaigns
        if (!($is_rtb_campaign && !empty($creative['endpoint_url']) && $creative_type == 'third_party')) {
            $valid_content = false;
            switch ($creative_type) {
                case 'image':
                    $valid_content = !empty($image_url);
                    break;
                case 'video':
                    $valid_content = !empty($video_url);
                    break;
                case 'html5':
                case 'third_party':
                    $valid_content = !empty($html_content);
                    break;
            }
            
            if (!$valid_content) {
                echo json_encode(['success' => false, 'message' => 'Please provide content for the selected creative type']);
                exit;
            }
        }
        
        // Update the creative
        $stmt = $pdo->prepare("
            UPDATE creatives SET 
                name = ?, width = ?, height = ?, bid_amount = ?, creative_type = ?,
                image_url = ?, video_url = ?, html_content = ?, click_url = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        
        $stmt->execute([
            $name, $width, $height, $bid_amount, $creative_type,
            $image_url, $video_url, $html_content, $click_url, $creative_id
        ]);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Creative "' . $name . '" updated successfully'
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error updating creative: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>