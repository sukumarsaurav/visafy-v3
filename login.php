<?php
require_once 'config/db_connect.php';
require_once 'includes/functions.php';
session_start();

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    // Redirect to appropriate dashboard based on user type
    if ($_SESSION['user_type'] == 'applicant') {
        header("Location: dashboard/applicant/index.php");
    } elseif ($_SESSION['user_type'] == 'professional') {
        header("Location: dashboard/professional/index.php");
    } elseif ($_SESSION['user_type'] == 'team_member') {
        header("Location: dashboard/team_member/index.php");
    }
    exit();
}

$error = '';

// Process login form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $conn->real_escape_string($_POST['email']);
    $password = $_POST['password'];
    
    // Validate input
    if (empty($email) || empty($password)) {
        $error = "Please enter both email and password";
    } else {
        // Check user credentials
        $sql = "SELECT id, email, password, role, status, email_verified FROM users WHERE email = ? AND auth_provider = 'local'";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
            
            // Verify password
            if (password_verify($password, $user['password'])) {
                // Check if user is active
                if ($user['status'] != 'active') {
                    $error = "Your account is suspended. Please contact support.";
                } 
                // Check if email is verified
                elseif ($user['email_verified'] != 1) {
                    $error = "Please verify your email first. Check your inbox for verification link.";
                } 
                else {
                    // Set session variables
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_type'] = $user['role'];
                    
                    // Log activity
                    $sql = "INSERT INTO activity_logs (user_id, action, entity_type, ip_address, user_agent) VALUES (?, 'login', 'users', ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $ip = $_SERVER['REMOTE_ADDR'];
                    $userAgent = $_SERVER['HTTP_USER_AGENT'];
                    $stmt->bind_param("iss", $user['id'], $ip, $userAgent);
                    $stmt->execute();
                    
                    // Redirect to appropriate dashboard
                    if ($user['role'] == 'applicant') {
                        header("Location: dashboard/applicant/index.php");
                    } elseif ($user['role'] == 'professional') {
                        header("Location: dashboard/professional/index.php");
                    } elseif ($user['role'] == 'team_member') {
                        header("Location: dashboard/team_member/index.php");
                    }
                    exit();
                }
            } else {
                $error = "Invalid email or password";
            }
        } else {
            $error = "Invalid email or password";
        }
    }
}

// Generate Google OAuth URL
require_once 'config/google_auth.php';
require_once 'vendor/autoload.php';

$client = new Google_Client();
$client->setClientId(GOOGLE_CLIENT_ID);
$client->setClientSecret(GOOGLE_CLIENT_SECRET);
$client->setRedirectUri(GOOGLE_REDIRECT_URI);
$client->addScope("email");
$client->addScope("profile");

$google_auth_url = $client->createAuthUrl();

// Include header
$page_title = "Login - Visafy";
include 'includes/header.php';
?>

<div class="auth-container">
    <div class="auth-card">
        <div class="auth-header">
            <h2>Login to Visafy</h2>
        </div>
        <div class="auth-body">
            <?php if ($error): ?>
                <div class="auth-error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <form method="post" action="" class="auth-form">
                <div class="form-group">
                    <label for="email">Email address</label>
                    <input type="email" id="email" name="email" required>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <div class="remember-me">
                    <input type="checkbox" id="remember" name="remember">
                    <label for="remember">Remember me</label>
                </div>
                <a href="forgot_password.php" class="forgot-password">Forgot Password?</a>
                <button type="submit" class="btn btn-primary w-100">Login</button>
            </form>
            
            <div class="auth-divider">
                <span>OR</span>
            </div>
            
            <a href="<?php echo $google_auth_url; ?>" class="social-login-btn">
                <i class="fab fa-google"></i> Sign in with Google
            </a>
            
            <div class="auth-footer">
                <p>Don't have an account? <a href="register.php">Register here</a></p>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
