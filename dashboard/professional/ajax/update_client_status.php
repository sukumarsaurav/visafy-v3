<?php
// File: dashboard/professional/ajax/update_client_status.php

// Start session only if one isn't already active
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once '../../../config/db_connect.php';
require_once '../../../includes/functions.php';

// Check if user is logged in and is a professional
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] != 'professional') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check if the required parameters are set
if (!isset($_POST['client_id']) || !isset($_POST['status'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit();
}

$client_id = (int)$_POST['client_id'];
$status = $_POST['status'];
$valid_statuses = ['active', 'pending', 'inactive', 'archived'];

if (!in_array($status, $valid_statuses)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status value']);
    exit();
}

$user_id = $_SESSION['user_id'];

// Verify that the client belongs to this professional
$stmt = $conn->prepare("
    SELECT pc.id FROM professional_clients pc
    JOIN professional_entities pe ON pc.professional_entity_id = pe.id
    WHERE pc.id = ? AND pe.user_id = ? AND pc.deleted_at IS NULL
");
$stmt->bind_param("ii", $client_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Client not found or access denied']);
    exit();
}

// Update client status
$stmt = $conn->prepare("UPDATE professional_clients SET status = ?, updated_at = NOW() WHERE id = ?");
$stmt->bind_param("si", $status, $client_id);

if ($stmt->execute()) {
    // Log the action
    $log_details = json_encode([
        'client_id' => $client_id,
        'previous_status' => $_POST['previous_status'] ?? 'unknown',
        'new_status' => $status
    ]);
    
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $action_type = 'client_status_update';
    
    $stmt = $conn->prepare("INSERT INTO activity_log (user_id, action_type, action_details, ip_address) 
                          VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isss", $user_id, $action_type, $log_details, $ip_address);
    $stmt->execute();
    
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $stmt->error]);
}
?>
