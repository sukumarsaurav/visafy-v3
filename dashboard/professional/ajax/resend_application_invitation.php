<?php
// File: dashboard/professional/ajax/resend_application_invitation.php
session_start();

require_once '../../config/db_connect.php';
require_once '../includes/functions.php';

// Check if user is logged in and is a professional
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'professional') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Check if application_id is provided
if (!isset($_POST['application_id'])) {
    echo json_encode(['success' => false, 'message' => 'Application ID is required']);
    exit;
}

$application_id = (int)$_POST['application_id'];
$professional_id = $_SESSION['user_id'];

// Verify that the application belongs to this professional
$stmt = $conn->prepare("
    SELECT 
        va.id, va.client_id, va.country_id, va.visa_type_id,
        u.email, u.first_name, u.last_name
    FROM visa_applications va
    JOIN users u ON va.client_id = u.id
    WHERE va.id = ? AND va.professional_id = ?
");
$stmt->bind_param("ii", $application_id, $professional_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Application not found or unauthorized']);
    exit;
}

$application = $result->fetch_assoc();

// Get required documents for the visa type
$stmt = $conn->prepare("
    SELECT d.name as doc_name, d.description as doc_description, d.is_required 
    FROM visa_type_documents vtd 
    JOIN documents d ON vtd.document_id = d.id 
    WHERE vtd.visa_type_id = ?
");
$stmt->bind_param("i", $application['visa_type_id']);
$stmt->execute();
$documents_result = $stmt->get_result();

$required_documents = [];
while ($doc = $documents_result->fetch_assoc()) {
    $required_documents[] = $doc;
}

// Send email with application details and required documents
$to = $application['email'];
$subject = "Your Visa Application Details - Reminder";
$message = "Dear {$application['first_name']} {$application['last_name']},\n\n";
$message .= "This is a reminder about your visa application. Here are the required documents:\n\n";

foreach ($required_documents as $doc) {
    $message .= ($doc['is_required'] ? "* " : "- ") . $doc['doc_name'];
    if (!empty($doc['doc_description'])) {
        $message .= " - " . $doc['doc_description'];
    }
    $message .= "\n";
}

$message .= "\nPlease log in to your account to upload these documents.\n";
$headers = "From: " . $config['site_name'] . " <noreply@" . $_SERVER['HTTP_HOST'] . ">\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

if (mail($to, $subject, $message, $headers)) {
    // Update the invitation status in the database
    $stmt = $conn->prepare("
        UPDATE visa_applications 
        SET invitation_sent_at = NOW(),
            invitation_status = 'sent'
        WHERE id = ?
    ");
    $stmt->bind_param("i", $application_id);
    $stmt->execute();
    
    echo json_encode(['success' => true, 'message' => 'Invitation has been resent successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to send invitation email']);
} 