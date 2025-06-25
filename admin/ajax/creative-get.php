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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $creative_id = $_GET['id'] ?? null;
    
    if (!$creative_id) {
        echo json_encode(['success' => false, 'message' => 'Creative ID is required']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT c.*, ca.name as campaign_name, ca.type as campaign_type, ca.endpoint_url 
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
        
        echo json_encode(['success' => true, 'creative' => $creative]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error fetching creative: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>