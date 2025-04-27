<?php
require_once 'config/db_connect.php';
session_start();

// Log activity if user is logged in
if (isset($_SESSION['user_id'])) {
    $sql = "INSERT INTO activity_logs (user_id, action, entity_type, ip_address, user_agent) VALUES (?, 'logout', 'users', ?, ?)";
    $stmt = $conn->prepare($sql);
    $ip = $_SERVER['REMOTE_ADDR'];
    $userAgent = $_SERVER['HTTP_USER_AGENT'];
    $stmt->bind_param("iss", $_SESSION['user_id'], $ip, $userAgent);
    $stmt->execute();
}

// Destroy session
session_unset();
session_destroy();

// Redirect to login page
header("Location: login.php");
exit();
?>
