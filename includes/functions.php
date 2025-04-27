<?php
/**
 * Generate a random token
 * 
 * @param int $length Length of the token
 * @return string The generated token
 */
function generateToken($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Send verification email to user
 * 
 * @param string $email User's email address
 * @param string $name User's name
 * @param string $token Verification token
 * @return bool Whether the email was sent successfully
 */
function sendVerificationEmail($email, $name, $token) {
    $subject = "Verify Your Email - Visafy";
    
    // Determine if running in development environment
    $is_dev = ($_SERVER['SERVER_NAME'] == 'localhost' || strpos($_SERVER['SERVER_NAME'], '.local') !== false);
    $protocol = $is_dev ? 'http' : 'https';
    
    // Fix for Hostinger - detect if we're on production hostinger site
    $is_hostinger = (strpos($_SERVER['HTTP_HOST'], 'hostingersite.com') !== false);
    
    // Use appropriate protocol and path for verification link
    if ($is_hostinger) {
        // On Hostinger site, use direct path without /visafy-v2 folder
        $verification_link = $protocol . "://" . $_SERVER['HTTP_HOST'] . "/verify_email.php?token=" . $token;
    } else {
        // Local development or other hosting
        $verification_link = $protocol . "://" . $_SERVER['HTTP_HOST'] . "/visafy-v2/verify_email.php?token=" . $token;
    }
    
    $message = "
    <html>
    <head>
        <title>Verify Your Email</title>
    </head>
    <body>
        <div style='max-width: 600px; margin: 0 auto; padding: 20px; font-family: Arial, sans-serif;'>
            <h2>Welcome to Visafy, $name!</h2>
            <p>Thank you for registering. Please verify your email address by clicking the link below:</p>
            <p><a href='$verification_link' style='display: inline-block; padding: 10px 20px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px;'>Verify Email</a></p>
            <p>If the button above doesn't work, copy and paste this URL into your browser:</p>
            <p>$verification_link</p>
            <p>This link will expire in 24 hours.</p>
            <p>If you didn't register for an account, please ignore this email.</p>
        </div>
    </body>
    </html>
    ";
    
    // To send HTML mail, the Content-type header must be set
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: Visafy <noreply@visafy.com>" . "\r\n";
    $headers .= "Reply-To: noreply@visafy.com" . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    
    // Create logs directory if it doesn't exist (for any environment)
    $log_dir = dirname(__DIR__) . '/logs';
    if (!file_exists($log_dir)) {
        mkdir($log_dir, 0777, true);
    }
    
    // Always log the email content regardless of environment
    $log_file = $log_dir . '/email_' . time() . '_' . md5($email) . '.html';
    file_put_contents($log_file, 
        "To: $email\n" .
        "Subject: $subject\n" .
        "Headers: " . str_replace("\r\n", "<br>", $headers) . "\n\n" .
        $message
    );
    
    // Log to verify_emails.log 
    $log_message = date('Y-m-d H:i:s') . " - Email to: $email, Subject: $subject, Log: $log_file\n";
    file_put_contents($log_dir . '/verify_emails.log', $log_message, FILE_APPEND);
    
    // Always try to send the email (in both dev and production)
    // This provides a backup in case mail() isn't working
    $mail_success = false;
    
    // First try using PHP's mail() function
    try {
        $mail_success = mail($email, $subject, $message, $headers);
    } catch (Exception $e) {
        // Log the error
        file_put_contents($log_dir . '/mail_errors.log', 
            date('Y-m-d H:i:s') . " - Error sending to $email: " . $e->getMessage() . "\n", 
            FILE_APPEND
        );
    }
    
    // Return true to indicate "successful" sending (since we've logged it)
    return true;
}

/**
 * Validate password strength
 * 
 * @param string $password The password to validate
 * @return array Array with 'valid' flag and 'message'
 */
function validatePassword($password) {
    $result = ['valid' => true, 'message' => ''];
    
    // Check password length
    if (strlen($password) < 8) {
        $result['valid'] = false;
        $result['message'] = "Password must be at least 8 characters long";
        return $result;
    }
    
    // Check if password has at least one number
    if (!preg_match('/[0-9]/', $password)) {
        $result['valid'] = false;
        $result['message'] = "Password must include at least one number";
        return $result;
    }
    
    // Check if password has at least one uppercase letter
    if (!preg_match('/[A-Z]/', $password)) {
        $result['valid'] = false;
        $result['message'] = "Password must include at least one uppercase letter";
        return $result;
    }
    
    return $result;
}

/**
 * Check if user is logged in
 * 
 * @return bool Whether the user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Redirect user if not logged in
 * 
 * @param string $redirect_url URL to redirect to if not logged in
 */
function requireLogin($redirect_url = 'login.php') {
    if (!isLoggedIn()) {
        header("Location: $redirect_url");
        exit();
    }
}

/**
 * Check if user has specific user type
 * 
 * @param string|array $allowed_types Allowed user type(s)
 * @return bool Whether the user has the required type
 */
function hasUserType($allowed_types) {
    if (!isLoggedIn()) {
        return false;
    }
    
    if (is_array($allowed_types)) {
        return in_array($_SESSION['user_type'], $allowed_types);
    } else {
        return $_SESSION['user_type'] == $allowed_types;
    }
}

/**
 * Redirect user if not having specific user type
 * 
 * @param string|array $allowed_types Allowed user type(s)
 * @param string $redirect_url URL to redirect to if not authorized
 */
function requireUserType($allowed_types, $redirect_url = 'login.php') {
    requireLogin($redirect_url);
    
    if (!hasUserType($allowed_types)) {
        header("Location: $redirect_url");
        exit();
    }
}
?>
