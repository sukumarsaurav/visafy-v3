<?php
// File path: dashboard/professional/ajax/get_visa_types.php

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include DB connection
require_once '../../../config/db_connect.php';

// Check if user is logged in and is a professional
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] != 'professional') {
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Validate input
if (!isset($_GET['country_id']) || !is_numeric($_GET['country_id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid country ID']);
    exit;
}

$country_id = intval($_GET['country_id']);

// Fetch visa types for the selected country
try {
    $stmt = $conn->prepare("SELECT id, name, code, processing_time, validity_period 
                           FROM visa_types 
                           WHERE country_id = ? AND is_active = 1 
                           ORDER BY name");
    $stmt->bind_param("i", $country_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $visa_types = [];
    while ($row = $result->fetch_assoc()) {
        $visa_types[] = $row;
    }
    
    echo json_encode(['success' => true, 'visa_types' => $visa_types]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>
