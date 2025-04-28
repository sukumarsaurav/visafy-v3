<?php
// File: client_accept.php

session_start();
require_once 'config/db_connect.php';
require_once 'includes/functions.php';

if (!isset($_GET['token']) || empty($_GET['token'])) {
    $_SESSION['error_msg'] = "Invalid invitation link.";
    header("Location: login.php");
    exit();
}

$token = $_GET['token'];
$error_msg = '';
$success_msg = '';
$user_data = null;

// Verify token and get user data
$stmt = $conn->prepare("
    SELECT id, email, first_name, last_name
    FROM users
    WHERE email_verification_token = ? AND email_verified = 0 
    AND email_verification_expires > NOW() AND deleted_at IS NULL AND role = 'applicant'
");
$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $error_msg = "The invitation link is invalid or has expired.";
} else {
    $user_data = $result->fetch_assoc();
}
$stmt->close();

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $user_data) {
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Password validation
    if (empty($password)) {
        $error_msg = "Password is required.";
    } elseif (strlen($password) < 8) {
        $error_msg = "Password must be at least 8 characters long.";
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $error_msg = "Password must include at least one uppercase letter.";
    } elseif (!preg_match('/[a-z]/', $password)) {
        $error_msg = "Password must include at least one lowercase letter.";
    } elseif (!preg_match('/[0-9]/', $password)) {
        $error_msg = "Password must include at least one number.";
    } elseif (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $error_msg = "Password must include at least one special character.";
    } elseif ($password !== $confirm_password) {
        $error_msg = "Passwords do not match.";
    } else {
        // Begin transaction
        $conn->begin_transaction();
        try {
            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Update user account
            $stmt = $conn->prepare("
                UPDATE users 
                SET password = ?, email_verified = 1, 
                    email_verification_token = NULL, email_verification_expires = NULL, 
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param("si", $hashed_password, $user_data['id']);
            $stmt->execute();
            $stmt->close();
            
            // Log the account activation
            $stmt = $conn->prepare("
                INSERT INTO activity_log (user_id, action_type, action_details, ip_address) 
                VALUES (?, 'client_activation', ?, ?)
            ");
            $log_details = json_encode([
                'invitation_accepted' => true,
                'email' => $user_data['email']
            ]);
            $ip_address = $_SERVER['REMOTE_ADDR'];
            $stmt->bind_param("iss", $user_data['id'], $log_details, $ip_address);
            $stmt->execute();
            $stmt->close();
            
            $conn->commit();
            
            $success_msg = "Your account has been successfully activated. You can now log in.";
            header("Refresh: 3; URL=login.php");
        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = "An error occurred: " . $e->getMessage();
            error_log("Client activation error: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Accept Client Invitation - Visafy</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        body { background: #f8f9fa; font-family: 'Nunito', sans-serif; }
        .container { max-width: 500px; margin-top: 50px; }
        .card { border-radius: 10px; }
        .card-header { background: #3498db; color: #fff; }
    </style>
</head>
<body>
<div class="container">
    <div class="card shadow">
        <div class="card-header text-center">
            <h3>Client Invitation</h3>
        </div>
        <div class="card-body p-4">
            <?php if ($error_msg): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error_msg); ?></div>
            <?php endif; ?>
            <?php if ($success_msg): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
                <p class="text-center">Redirecting to login page...</p>
            <?php elseif ($user_data): ?>
                <h4 class="text-center mb-4">Welcome to Visafy!</h4>
                <p>Hello <?php echo htmlspecialchars($user_data['first_name']); ?>,</p>
                <p>Please create a password to complete your account setup.</p>
                <form method="post" action="">
                    <div class="mb-3">
                        <label for="email" class="form-label">Email Address</label>
                        <input type="email" class="form-control" id="email" value="<?php echo htmlspecialchars($user_data['email']); ?>" readonly>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Create Password</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                        <div class="form-text">At least 8 characters, 1 uppercase, 1 lowercase, 1 number, 1 special character.</div>
                    </div>
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">Confirm Password</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">Activate Account</button>
                    </div>
                </form>
            <?php else: ?>
                <div class="text-center">
                    <h5>Invalid or Expired Invitation</h5>
                    <p>The invitation link is invalid or has expired. Please contact your professional for a new invitation.</p>
                    <a href="login.php" class="btn btn-primary mt-3">Go to Login</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
