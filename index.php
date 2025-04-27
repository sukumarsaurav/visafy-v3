<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    // Redirect to appropriate dashboard based on user type
    if ($_SESSION['user_type'] == 'applicant') {
        header("Location: dashboard/applicant/index.php");
        exit();
    } elseif ($_SESSION['user_type'] == 'employer') {
        header("Location: dashboard/employer/index.php");
        exit();
    } elseif ($_SESSION['user_type'] == 'professional') {
        header("Location: dashboard/professional/index.php");
        exit();
    }
} else {
    // If not logged in, redirect to login page
    header("Location: login.php");
    exit();
}
?>