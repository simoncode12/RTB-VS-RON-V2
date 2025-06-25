<?php
session_start();
require_once '../config/database.php';

$error = '';
$success = '';

// Check for logout message
if (isset($_GET['logout']) && $_GET['logout'] === 'success') {
    $success = 'You have been successfully logged out.';
    $logout_time = $_GET['time'] ?? date('Y-m-d H:i:s');
}

if ($_POST) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if ($username && $password) {
        $stmt = $pdo->prepare("SELECT id, username, password, email, role FROM users WHERE username = ? AND status = 'active'");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            // Login successful
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['login_time'] = time();
            
            // Try to log this login if the tables exist
            try {
                // Check if user_logins table exists
                $check_table = $pdo->query("SHOW TABLES LIKE 'user_logins'");
                if ($check_table->rowCount() > 0) {
                    $stmt = $pdo->prepare("
                        INSERT INTO user_logins (
                            user_id, username, action, ip_address, user_agent, status, login_time
                        ) VALUES (?, ?, 'login', ?, ?, 'success', NOW())
                    ");
                    $stmt->execute([
                        $user['id'],
                        $user['username'],
                        $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
                        $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
                    ]);
                }
                
                // Check if user_activity table exists
                $check_table = $pdo->query("SHOW TABLES LIKE 'user_activity'");
                if ($check_table->rowCount() > 0) {
                    $stmt = $pdo->prepare("
                        INSERT INTO user_activity (
                            user_id, action, entity_type, entity_id, ip_address, details, created_at
                        ) VALUES (?, 'login', 'session', ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $user['id'],
                        session_id(),
                        $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
                        'User logged in successfully'
                    ]);
                }
            } catch (Exception $e) {
                // Silent fail - don't prevent login if logging fails
                error_log('Login logging error: ' . $e->getMessage());
            }
            
            header('Location: dashboard.php');
            exit;
        } else {
            $error = 'Invalid username or password';
            
            // Try to log this failed login
            try {
                // Check if user_logins table exists
                $check_table = $pdo->query("SHOW TABLES LIKE 'user_logins'");
                if ($check_table->rowCount() > 0) {
                    $stmt = $pdo->prepare("
                        INSERT INTO user_logins (
                            user_id, username, action, ip_address, user_agent, status, login_time
                        ) VALUES (?, ?, 'failed', ?, ?, 'failed', NOW())
                    ");
                    $stmt->execute([
                        0, // Unknown user
                        $username,
                        $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
                        $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
                    ]);
                }
            } catch (Exception $e) {
                // Silent fail
                error_log('Failed login logging error: ' . $e->getMessage());
            }
        }
    } else {
        $error = 'Please enter both username and password';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - RTB & RON Platform</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            max-width: 400px;
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>
<body>
    <div class="login-card p-5">
        <div class="text-center mb-4">
            <i class="fas fa-bullseye fa-3x text-primary mb-3"></i>
            <h2 class="fw-bold">RTB & RON Platform</h2>
            <p class="text-muted">Admin Panel Login</p>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="mb-3">
                <label for="username" class="form-label">Username</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-user"></i></span>
                    <input type="text" class="form-control" id="username" name="username" required 
                           value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                </div>
            </div>
            
            <div class="mb-4">
                <label for="password" class="form-label">Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                    <input type="password" class="form-control" id="password" name="password" required>
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary w-100 py-2">
                <i class="fas fa-sign-in-alt"></i> Login
            </button>
        </form>
        
        <div class="text-center mt-4">
            <small class="text-muted">
                Default login: admin / password
            </small>
        </div>
    </div>
</body>
</html>