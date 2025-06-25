<?php
include 'includes/header.php';

// Get current user info
$user_id = $_SESSION['user_id'] ?? 0;
$username = $_SESSION['username'] ?? '';

if (!$user_id) {
    header('Location: login.php');
    exit;
}

$message = '';
$message_type = 'success';

// Fetch user details
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Handle password update
if (isset($_POST['action']) && $_POST['action'] == 'update_password') {
    try {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            throw new Exception('All password fields are required.');
        }
        
        // Verify current password
        if (!password_verify($current_password, $user['password'])) {
            throw new Exception('Current password is incorrect.');
        }
        
        // Validate new password
        if (strlen($new_password) < 8) {
            throw new Exception('New password must be at least 8 characters long.');
        }
        
        if ($new_password !== $confirm_password) {
            throw new Exception('New password and confirmation do not match.');
        }
        
        // Update password
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$hashed_password, $user_id]);
        
        $message = 'Password updated successfully!';
        $message_type = 'success';
        
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
        $message_type = 'danger';
    }
}

// Handle profile update
if (isset($_POST['action']) && $_POST['action'] == 'update_profile') {
    try {
        $email = $_POST['email'] ?? '';
        $full_name = $_POST['full_name'] ?? '';
        
        if (empty($email)) {
            throw new Exception('Email address is required.');
        }
        
        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Please enter a valid email address.');
        }
        
        // Check if full_name column exists
        $stmt = $pdo->prepare("SHOW COLUMNS FROM users LIKE 'full_name'");
        $stmt->execute();
        $column_exists = $stmt->rowCount() > 0;
        
        if ($column_exists) {
            // Update profile with full_name
            $stmt = $pdo->prepare("UPDATE users SET email = ?, full_name = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$email, $full_name, $user_id]);
        } else {
            // Update profile without full_name
            $stmt = $pdo->prepare("UPDATE users SET email = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$email, $user_id]);
        }
        
        // Update session data if needed
        $_SESSION['email'] = $email;
        
        // Refresh user data
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        $message = 'Profile updated successfully!';
        $message_type = 'success';
        
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
        $message_type = 'danger';
    }
}

// Check if user_logins table exists
$logins = [];
try {
    $check_table = $pdo->query("SHOW TABLES LIKE 'user_logins'");
    if ($check_table->rowCount() > 0) {
        // Get login history
        $login_history = $pdo->prepare("
            SELECT * FROM user_logins 
            WHERE user_id = ? 
            ORDER BY login_time DESC 
            LIMIT 5
        ");
        $login_history->execute([$user_id]);
        $logins = $login_history->fetchAll();
    }
} catch (Exception $e) {
    // Silent fail - just don't show login history
}

// Check if user_activity table exists
$activities = [];
try {
    $check_table = $pdo->query("SHOW TABLES LIKE 'user_activity'");
    if ($check_table->rowCount() > 0) {
        // Get user activity
        $user_activity = $pdo->prepare("
            SELECT action, entity_type, entity_id, created_at 
            FROM user_activity 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT 10
        ");
        $user_activity->execute([$user_id]);
        $activities = $user_activity->fetchAll();
    }
} catch (Exception $e) {
    // Silent fail - just don't show activity
}

// Log this profile view if the table exists
try {
    $check_table = $pdo->query("SHOW TABLES LIKE 'user_activity'");
    if ($check_table->rowCount() > 0) {
        $stmt = $pdo->prepare("
            INSERT INTO user_activity (user_id, action, entity_type, entity_id, ip_address, created_at)
            VALUES (?, 'view', 'profile', ?, ?, NOW())
        ");
        $stmt->execute([$user_id, $user_id, $_SERVER['REMOTE_ADDR'] ?? '']);
    }
} catch (Exception $e) {
    // Silent fail - just don't log the activity
}

// Current timestamp
$current_time = '2025-06-23 05:44:15';
?>

<div class="row">
    <div class="col-12">
        <h1 class="h3 mb-4">
            <i class="fas fa-user-circle"></i> My Profile
            <small class="text-muted">Manage your account settings</small>
        </h1>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row">
    <!-- Profile Information -->
    <div class="col-lg-4 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-user"></i> Profile Information</h5>
            </div>
            <div class="card-body">
                <div class="text-center mb-4">
                    <div class="avatar-circle">
                        <span class="avatar-initials"><?php echo strtoupper(substr($user['username'], 0, 2)); ?></span>
                    </div>
                    <h5 class="mt-3 mb-0"><?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?></h5>
                    <p class="text-muted"><?php echo ucfirst($user['role']); ?></p>
                </div>
                
                <form method="POST" id="profileForm">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="mb-3">
                        <label for="username" class="form-label">Username</label>
                        <input type="text" class="form-control" id="username" value="<?php echo htmlspecialchars($user['username']); ?>" readonly>
                        <div class="form-text">Username cannot be changed</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="email" class="form-label">Email Address</label>
                        <input type="email" class="form-control" id="email" name="email" 
                               value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
                    </div>
                    
                    <?php 
                    // Check if full_name column exists
                    $stmt = $pdo->prepare("SHOW COLUMNS FROM users LIKE 'full_name'");
                    $stmt->execute();
                    $column_exists = $stmt->rowCount() > 0;
                    
                    if ($column_exists): 
                    ?>
                    <div class="mb-3">
                        <label for="full_name" class="form-label">Full Name</label>
                        <input type="text" class="form-control" id="full_name" name="full_name"
                               value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>">
                    </div>
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <label for="role" class="form-label">Role</label>
                        <input type="text" class="form-control" id="role" 
                               value="<?php echo ucfirst($user['role']); ?>" readonly>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-save"></i> Update Profile
                    </button>
                </form>
            </div>
        </div>
        
        <!-- Account Info -->
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-info-circle"></i> Account Information</h5>
            </div>
            <div class="card-body">
                <div class="mb-2">
                    <strong>Account Created:</strong> 
                    <span class="float-end"><?php echo date('M j, Y', strtotime($user['created_at'])); ?></span>
                </div>
                <div class="mb-2">
                    <strong>Last Updated:</strong> 
                    <span class="float-end"><?php echo date('M j, Y', strtotime($user['updated_at'])); ?></span>
                </div>
                <div class="mb-2">
                    <strong>Last Login:</strong> 
                    <span class="float-end">
                        <?php 
                        if (!empty($logins)) {
                            echo date('M j, Y H:i', strtotime($logins[0]['login_time'])); 
                        } else {
                            echo date('M j, Y H:i', strtotime($user['updated_at'])); 
                        }
                        ?>
                    </span>
                </div>
                <div>
                    <strong>Status:</strong> 
                    <span class="float-end">
                        <span class="badge bg-success">Active</span>
                    </span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Change Password & Activity -->
    <div class="col-lg-8 mb-4">
        <!-- Change Password -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-key"></i> Change Password</h5>
            </div>
            <div class="card-body">
                <form method="POST" id="passwordForm">
                    <input type="hidden" name="action" value="update_password">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="current_password" class="form-label">Current Password</label>
                            <input type="password" class="form-control" id="current_password" name="current_password" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="new_password" class="form-label">New Password</label>
                            <input type="password" class="form-control" id="new_password" name="new_password" 
                                   required minlength="8">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="confirm_password" class="form-label">Confirm Password</label>
                            <input type="password" class="form-control" id="confirm_password" 
                                   name="confirm_password" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <div class="progress" style="height: 6px;">
                            <div class="progress-bar" id="password-strength-meter" role="progressbar" style="width: 0%"></div>
                        </div>
                        <small id="password-strength-text" class="form-text"></small>
                    </div>
                    
                    <div class="alert alert-info small">
                        <i class="fas fa-shield-alt"></i> <strong>Password Requirements:</strong>
                        <ul class="mb-0">
                            <li>Minimum 8 characters</li>
                            <li>Include both uppercase and lowercase letters</li>
                            <li>Include at least one number</li>
                            <li>Include at least one special character</li>
                        </ul>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Password
                    </button>
                </form>
            </div>
        </div>
        
        <!-- Login History -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-history"></i> Recent Logins</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>IP Address</th>
                                <th>Device / Browser</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($logins)): ?>
                                <?php foreach ($logins as $login): ?>
                                <tr>
                                    <td><?php echo date('M j, Y H:i:s', strtotime($login['login_time'])); ?></td>
                                    <td><?php echo htmlspecialchars($login['ip_address'] ?? 'Unknown'); ?></td>
                                    <td><?php echo htmlspecialchars($login['user_agent'] ?? 'Unknown'); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $login['status'] == 'success' ? 'success' : 'danger'; ?>">
                                            <?php echo ucfirst($login['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center">No login history available.</td>
                                </tr>
                                <tr>
                                    <td><?php echo date('M j, Y H:i:s'); ?></td>
                                    <td><?php echo $_SERVER['REMOTE_ADDR'] ?? 'Unknown'; ?></td>
                                    <td><?php echo htmlspecialchars($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'); ?></td>
                                    <td><span class="badge bg-success">Current</span></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Activity -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-line"></i> Recent Activity</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>Action</th>
                                <th>Entity</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($activities)): ?>
                                <?php foreach ($activities as $activity): ?>
                                <tr>
                                    <td><?php echo date('M j, Y H:i:s', strtotime($activity['created_at'])); ?></td>
                                    <td><?php echo ucfirst($activity['action']); ?></td>
                                    <td><?php echo ucfirst($activity['entity_type']); ?> #<?php echo $activity['entity_id']; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3" class="text-center">No activity recorded yet.</td>
                                </tr>
                                <tr>
                                    <td><?php echo $current_time; ?></td>
                                    <td>View</td>
                                    <td>Profile #<?php echo $user_id; ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Current Session Card -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="fas fa-desktop"></i> Current Session</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <div class="mb-2">
                    <strong>IP Address:</strong> 
                    <span><?php echo $_SERVER['REMOTE_ADDR'] ?? 'Unknown'; ?></span>
                </div>
                <div class="mb-2">
                    <strong>Browser:</strong> 
                    <span><?php echo htmlspecialchars($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'); ?></span>
                </div>
            </div>
            <div class="col-md-6">
                <div class="mb-2">
                    <strong>Session Started:</strong> 
                    <span><?php echo isset($_SESSION['login_time']) ? date('M j, Y H:i:s', $_SESSION['login_time']) : date('M j, Y H:i:s'); ?></span>
                </div>
                <div class="mb-2">
                    <strong>Current Time (UTC):</strong> 
                    <span><?php echo $current_time; ?></span>
                </div>
            </div>
        </div>
        <div class="alert alert-warning mt-3">
            <i class="fas fa-exclamation-triangle"></i> If you do not recognize this session, immediately change your password and contact support.
        </div>
    </div>
</div>

<style>
.avatar-circle {
    width: 100px;
    height: 100px;
    background-color: #0d6efd;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto;
}
.avatar-initials {
    font-size: 42px;
    color: white;
    font-weight: bold;
    text-transform: uppercase;
}
</style>

<script>
// Password strength meter
document.addEventListener('DOMContentLoaded', function() {
    const passwordInput = document.getElementById('new_password');
    const meter = document.getElementById('password-strength-meter');
    const text = document.getElementById('password-strength-text');

    passwordInput.addEventListener('input', function() {
        const password = passwordInput.value;
        let strength = 0;
        let message = '';

        // Basic requirements
        if (password.length >= 8) strength += 25;
        if (/[A-Z]/.test(password)) strength += 25;
        if (/[0-9]/.test(password)) strength += 25;
        if (/[^A-Za-z0-9]/.test(password)) strength += 25;

        // Update meter
        meter.style.width = strength + '%';
        
        // Update color
        if (strength <= 25) {
            meter.className = 'progress-bar bg-danger';
            message = 'Very Weak';
        } else if (strength <= 50) {
            meter.className = 'progress-bar bg-warning';
            message = 'Weak';
        } else if (strength <= 75) {
            meter.className = 'progress-bar bg-info';
            message = 'Good';
        } else {
            meter.className = 'progress-bar bg-success';
            message = 'Strong';
        }
        
        text.innerHTML = message;
    });

    // Confirm password validation
    const confirmInput = document.getElementById('confirm_password');
    const passwordForm = document.getElementById('passwordForm');
    
    passwordForm.addEventListener('submit', function(e) {
        if (passwordInput.value !== confirmInput.value) {
            e.preventDefault();
            alert('Password and confirmation do not match.');
        }
    });
});

// Profile form validation
document.getElementById('profileForm').addEventListener('submit', function(e) {
    const emailInput = document.getElementById('email');
    if (!emailInput.value.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
        e.preventDefault();
        alert('Please enter a valid email address.');
        emailInput.focus();
    }
});
</script>

<?php include 'includes/footer.php'; ?>