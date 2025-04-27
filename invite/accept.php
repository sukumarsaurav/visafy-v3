<?php
// File: invite/accept.php

// Start session
session_start();

require_once '../config/db_connect.php';
require_once '../includes/functions.php';

// Check if token is provided
if (!isset($_GET['token']) || empty($_GET['token'])) {
    $_SESSION['error_msg'] = "Invalid invitation link.";
    header("Location: ../login.php");
    exit();
}

$token = $_GET['token'];
$error_msg = '';
$success_msg = '';
$user_data = null;

// Verify token and get user data
$stmt = $conn->prepare("
    SELECT u.id, u.email, tm.first_name, tm.last_name, cp.company_name
    FROM users u
    JOIN team_members tm ON u.id = tm.user_id
    JOIN company_professionals cp ON tm.company_id = cp.id
    WHERE u.email_verification_token = ? AND u.email_verified = 0 
    AND u.email_verification_expires > NOW() AND u.deleted_at IS NULL
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
                VALUES (?, 'team_member_activation', ?, ?)
            ");
            $log_details = json_encode([
                'invitation_accepted' => true,
                'email' => $user_data['email']
            ]);
            $ip_address = $_SERVER['REMOTE_ADDR'];
            $stmt->bind_param("iss", $user_data['id'], $log_details, $ip_address);
            $stmt->execute();
            $stmt->close();
            
            // Commit transaction
            $conn->commit();
            
            $success_msg = "Your account has been successfully activated. You can now log in.";
            
            // Redirect to login page after a short delay
            header("Refresh: 3; URL=../login.php");
        } catch (Exception $e) {
            // Roll back transaction on error
            $conn->rollback();
            $error_msg = "An error occurred: " . $e->getMessage();
            error_log("Team member activation error: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accept Invitation - Visafy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        body {
            font-family: 'Nunito', sans-serif;
            background-color: #f8f9fa;
        }
        .container {
            max-width: 550px;
            margin-top: 50px;
        }
        .card {
            border-radius: 10px;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
            overflow: hidden;
        }
        .card-header {
            background-color: #3498db;
            color: white;
            text-align: center;
            padding: 1.5rem;
        }
        .logo {
            max-width: 150px;
            margin-bottom: 1rem;
        }
        .form-control {
            border-radius: 5px;
            padding: 10px 15px;
            margin-bottom: 15px;
        }
        .btn-primary {
            background-color: #3498db;
            border-color: #3498db;
            padding: 10px 20px;
            border-radius: 5px;
            font-weight: 600;
        }
        .password-feedback {
            font-size: 0.85rem;
            margin-top: 5px;
        }
        .password-requirements {
            margin-top: 10px;
            font-size: 0.85rem;
        }
        .requirement {
            display: flex;
            align-items: center;
            margin-bottom: 5px;
        }
        .check-icon {
            color: #2ecc71;
            margin-right: 5px;
        }
        .times-icon {
            color: #e74c3c;
            margin-right: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-header">
                <img src="../assets/images/logo-Visafy-light.png" alt="Visafy Logo" class="logo">
                <h3 class="mb-0">Team Invitation</h3>
            </div>
            <div class="card-body p-4">
                <?php if ($error_msg): ?>
                    <div class="alert alert-danger" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i> <?php echo htmlspecialchars($error_msg); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success_msg): ?>
                    <div class="alert alert-success" role="alert">
                        <i class="fas fa-check-circle me-2"></i> <?php echo htmlspecialchars($success_msg); ?>
                    </div>
                    <p class="text-center">Redirecting to login page...</p>
                <?php elseif ($user_data): ?>
                    <h4 class="text-center mb-4">Welcome to Visafy!</h4>
                    <p>Hello <?php echo htmlspecialchars($user_data['first_name']); ?>,</p>
                    <p>You've been invited by <strong><?php echo htmlspecialchars($user_data['company_name']); ?></strong> to join their team on Visafy. Please create a password to complete your account setup.</p>
                    
                    <form method="post" action="" id="activationForm">
                        <div class="mb-3">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="email" value="<?php echo htmlspecialchars($user_data['email']); ?>" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label for="password" class="form-label">Create Password</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                            <div class="password-requirements mt-2">
                                <p><small>Password must meet the following requirements:</small></p>
                                <div class="requirement" id="length-check">
                                    <i class="fas fa-times times-icon"></i> At least 8 characters
                                </div>
                                <div class="requirement" id="uppercase-check">
                                    <i class="fas fa-times times-icon"></i> At least one uppercase letter
                                </div>
                                <div class="requirement" id="lowercase-check">
                                    <i class="fas fa-times times-icon"></i> At least one lowercase letter
                                </div>
                                <div class="requirement" id="number-check">
                                    <i class="fas fa-times times-icon"></i> At least one number
                                </div>
                                <div class="requirement" id="special-check">
                                    <i class="fas fa-times times-icon"></i> At least one special character
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Confirm Password</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                            <div class="password-feedback" id="password-match-feedback"></div>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary" id="submitBtn">Activate Account</button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="text-center">
                        <i class="fas fa-link-slash fa-3x text-muted mb-3"></i>
                        <h5>Invalid or Expired Invitation</h5>
                        <p>The invitation link you're trying to use is invalid or has expired. Please contact your team administrator to request a new invitation.</p>
                        <a href="../login.php" class="btn btn-primary mt-3">Go to Login</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const passwordInput = document.getElementById('password');
        const confirmPasswordInput = document.getElementById('confirm_password');
        const lengthCheck = document.getElementById('length-check');
        const uppercaseCheck = document.getElementById('uppercase-check');
        const lowercaseCheck = document.getElementById('lowercase-check');
        const numberCheck = document.getElementById('number-check');
        const specialCheck = document.getElementById('special-check');
        const passwordMatchFeedback = document.getElementById('password-match-feedback');
        const submitBtn = document.getElementById('submitBtn');
        
        // Password validation functions
        function validatePassword() {
            const password = passwordInput.value;
            
            // Check length
            if(password.length >= 8) {
                lengthCheck.innerHTML = '<i class="fas fa-check check-icon"></i> At least 8 characters';
            } else {
                lengthCheck.innerHTML = '<i class="fas fa-times times-icon"></i> At least 8 characters';
            }
            
            // Check uppercase
            if(/[A-Z]/.test(password)) {
                uppercaseCheck.innerHTML = '<i class="fas fa-check check-icon"></i> At least one uppercase letter';
            } else {
                uppercaseCheck.innerHTML = '<i class="fas fa-times times-icon"></i> At least one uppercase letter';
            }
            
            // Check lowercase
            if(/[a-z]/.test(password)) {
                lowercaseCheck.innerHTML = '<i class="fas fa-check check-icon"></i> At least one lowercase letter';
            } else {
                lowercaseCheck.innerHTML = '<i class="fas fa-times times-icon"></i> At least one lowercase letter';
            }
            
            // Check number
            if(/[0-9]/.test(password)) {
                numberCheck.innerHTML = '<i class="fas fa-check check-icon"></i> At least one number';
            } else {
                numberCheck.innerHTML = '<i class="fas fa-times times-icon"></i> At least one number';
            }
            
            // Check special character
            if(/[^A-Za-z0-9]/.test(password)) {
                specialCheck.innerHTML = '<i class="fas fa-check check-icon"></i> At least one special character';
            } else {
                specialCheck.innerHTML = '<i class="fas fa-times times-icon"></i> At least one special character';
            }
            
            // Check if passwords match
            checkPasswordMatch();
        }
        
        function checkPasswordMatch() {
            const password = passwordInput.value;
            const confirmPassword = confirmPasswordInput.value;
            
            if(confirmPassword.length === 0) {
                passwordMatchFeedback.innerHTML = '';
                passwordMatchFeedback.className = 'password-feedback';
            } else if(password === confirmPassword) {
                passwordMatchFeedback.innerHTML = '<i class="fas fa-check check-icon"></i> Passwords match';
                passwordMatchFeedback.className = 'password-feedback text-success';
            } else {
                passwordMatchFeedback.innerHTML = '<i class="fas fa-times times-icon"></i> Passwords do not match';
                passwordMatchFeedback.className = 'password-feedback text-danger';
            }
        }
        
        // Add event listeners
        if(passwordInput) {
            passwordInput.addEventListener('keyup', validatePassword);
            confirmPasswordInput.addEventListener('keyup', checkPasswordMatch);
            
            // Form validation before submit
            const form = document.getElementById('activationForm');
            if(form) {
                form.addEventListener('submit', function(e) {
                    const password = passwordInput.value;
                    const confirmPassword = confirmPasswordInput.value;
                    
                    let isValid = true;
                    
                    // Validate password requirements
                    if(password.length < 8 || 
                       !(/[A-Z]/.test(password)) || 
                       !(/[a-z]/.test(password)) || 
                       !(/[0-9]/.test(password)) || 
                       !(/[^A-Za-z0-9]/.test(password))) {
                        isValid = false;
                    }
                    
                    // Check if passwords match
                    if(password !== confirmPassword) {
                        isValid = false;
                    }
                    
                    if(!isValid) {
                        e.preventDefault();
                        alert('Please ensure your password meets all requirements and both passwords match.');
                    }
                });
            }
        }
    });
    </script>
</body>
</html>
