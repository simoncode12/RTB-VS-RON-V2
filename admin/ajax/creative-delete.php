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

if ($_SERVER['REQUEST_METHOD'] === 'DELETE' || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_method']) && $_POST['_method'] === 'DELETE')) {
    $input = json_decode(file_get_contents('php://input'), true);
    $creative_id = $input['id'] ?? $_POST['id'] ?? $_GET['id'] ?? null;
    
    if (!$creative_id) {
        echo json_encode(['success' => false, 'message' => 'Creative ID is required']);
        exit;
    }
    
    try {
        // First check if creative exists and get campaign info
        $stmt = $pdo->prepare("SELECT c.*, ca.name as campaign_name FROM creatives c LEFT JOIN campaigns ca ON c.campaign_id = ca.id WHERE c.id = ?");
        $stmt->execute([$creative_id]);
        $creative = $stmt->fetch();
        
        if (!$creative) {
            echo json_encode(['success' => false, 'message' => 'Creative not found']);
            exit;
        }
        
        // Delete the creative
        $stmt = $pdo->prepare("DELETE FROM creatives WHERE id = ?");
        $stmt->execute([$creative_id]);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Creative "' . $creative['name'] . '" deleted successfully'
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error deleting creative: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>