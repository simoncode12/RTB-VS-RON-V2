<?php
session_start();
require_once '../config/database.php';

// Get user information before destroying session
$user_id = $_SESSION['user_id'] ?? 0;
$username = $_SESSION['username'] ?? 'simoncode12'; // Default to current user

// Current timestamp
$current_time = '2025-06-23 05:44:15';

// Log the logout event - only if the tables exist
try {
    // Check if user_logins table exists
    $check_table = $pdo->query("SHOW TABLES LIKE 'user_logins'");
    if ($check_table->rowCount() > 0) {
        // Log to user_logins table
        $stmt = $pdo->prepare("
            INSERT INTO user_logins (
                user_id, 
                username, 
                action, 
                ip_address, 
                user_agent, 
                status, 
                login_time
            ) VALUES (?, ?, 'logout', ?, ?, 'success', NOW())
        ");
        $stmt->execute([
            $user_id,
            $username,
            $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
        ]);
    }
    
    // Check if user_activity table exists
    $check_table = $pdo->query("SHOW TABLES LIKE 'user_activity'");
    if ($check_table->rowCount() > 0) {
        // Log to user_activity table
        $stmt = $pdo->prepare("
            INSERT INTO user_activity (
                user_id,
                action,
                entity_type,
                entity_id,
                ip_address,
                details,
                created_at
            ) VALUES (?, 'logout', 'session', ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $user_id,
            session_id(),
            $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
            'User logged out successfully'
        ]);
    }
    
} catch (Exception $e) {
    // Silent fail - don't prevent logout if logging fails
    error_log('Logout logging error: ' . $e->getMessage());
}

// Unset all session variables
$_SESSION = array();

// Destroy the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Redirect to login page with a logout message
header("Location: login.php?logout=success&time=" . urlencode($current_time));
exit;
?>