<?php
require_once 'config/db_connect.php';
session_start();

$error = '';
$success = '';
$debug = '';

// Enable debugging for this file
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Debug information
$debug .= "Server Host: " . $_SERVER['HTTP_HOST'] . "<br>";
$debug .= "Request URI: " . $_SERVER['REQUEST_URI'] . "<br>";
$debug .= "Script Path: " . __FILE__ . "<br>";

// Check if token is provided
if (isset($_GET['token'])) {
    $token = $conn->real_escape_string($_GET['token']);
    $debug .= "Token received: " . $token . "<br>";
    
    // Verify token
    $stmt = $conn->prepare("SELECT id, email, email_verification_expires FROM users WHERE email_verification_token = ? AND email_verified = 0");
    
    if (!$stmt) {
        $debug .= "Prepare Error: " . $conn->error . "<br>";
    } else {
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();
        $debug .= "Query executed. Rows found: " . $result->num_rows . "<br>";
    
        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
            $debug .= "User found: " . $user['email'] . "<br>";
            
            // Check if token has expired
            if (strtotime($user['email_verification_expires']) < time()) {
                $error = "Verification link has expired. Please request a new one.";
                $debug .= "Token expired: " . $user['email_verification_expires'] . "<br>";
            } else {
                // Update user to verified
                $stmt = $conn->prepare("UPDATE users SET email_verified = 1, email_verification_token = NULL, email_verification_expires = NULL WHERE id = ?");
                
                if (!$stmt) {
                    $debug .= "Update Prepare Error: " . $conn->error . "<br>";
                } else {
                    $stmt->bind_param("i", $user['id']);
                    
                    if ($stmt->execute()) {
                        $success = "Your email has been verified successfully! You can now login to your account.";
                        $debug .= "User verified successfully<br>";
                        
                        // Activity logging - commented out as it may not exist in new schema
                        /* 
                        $sql = "INSERT INTO activity_logs (user_id, action, entity_type, ip_address, user_agent) VALUES (?, 'email_verified', 'users', ?, ?)";
                        $stmt = $conn->prepare($sql);
                        $ip = $_SERVER['REMOTE_ADDR'];
                        $userAgent = $_SERVER['HTTP_USER_AGENT'];
                        $stmt->bind_param("iss", $user['id'], $ip, $userAgent);
                        $stmt->execute();
                        */
                    } else {
                        $error = "An error occurred. Please try again later.";
                        $debug .= "Execute Error: " . $stmt->error . "<br>";
                    }
                }
            }
        } else {
            $error = "Invalid verification link or account already verified.";
            $debug .= "User not found with this token or already verified<br>";
        }
    }
} else {
    $error = "No verification token provided.";
    $debug .= "No token in GET parameters<br>";
}

// Include header
$page_title = "Verify Email - Visafy";
include 'includes/header.php';
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card shadow">
                <div class="card-body p-5 text-center">
                    <h2 class="mb-4">Email Verification</h2>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                        <p class="mt-4">
                            <a href="login.php" class="btn btn-primary">Go to Login</a>
                        </p>
                    <?php endif; ?>
                    
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?php echo $success; ?></div>
                        <p class="mt-4">
                            <a href="login.php" class="btn btn-primary">Login Now</a>
                        </p>
                    <?php endif; ?>
                    
                    <?php 
                    // Display debug information only if in development or with debug parameter
                    if (($_SERVER['SERVER_NAME'] == 'localhost' || strpos($_SERVER['SERVER_NAME'], '.local') !== false) 
                        || isset($_GET['debug'])): 
                    ?>
                        <div class="mt-5 p-3 text-start" style="background-color: #f8f9fa; border-radius: 5px;">
                            <h5>Debug Information</h5>
                            <pre style="white-space: pre-wrap;"><?php echo $debug; ?></pre>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?> 