<?php
// File: dashboard/professional/ajax/resend_invitation.php
session_start();

require_once '../../../config/db_connect.php';
require_once '../../../includes/functions.php';

// Check if user is logged in and is a professional
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] != 'professional') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check if member_id is provided
if (!isset($_POST['member_id']) || empty($_POST['member_id'])) {
    echo json_encode(['success' => false, 'message' => 'Member ID is required']);
    exit();
}

$member_id = (int)$_POST['member_id'];
$user_id = $_SESSION['user_id'];

// Verify that this team member belongs to the current professional's company
$stmt = $conn->prepare("
    SELECT tm.*, u.email, u.email_verification_token, tm.first_name, tm.last_name, cp.company_name
    FROM team_members tm
    JOIN users u ON tm.user_id = u.id
    JOIN company_professionals cp ON tm.company_id = cp.id
    JOIN professional_entities pe ON cp.entity_id = pe.id
    WHERE tm.id = ? AND pe.user_id = ? AND u.email_verified = 0
");
$stmt->bind_param("ii", $member_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Member not found or already verified']);
    exit();
}

$member = $result->fetch_assoc();
$stmt->close();

// Generate a new token
$token = bin2hex(random_bytes(32));
$expires = date('Y-m-d H:i:s', strtotime('+48 hours'));

// Update user with new token
$stmt = $conn->prepare("UPDATE users SET email_verification_token = ?, email_verification_expires = ? WHERE id = ?");
$stmt->bind_param("ssi", $token, $expires, $member['user_id']);
$success = $stmt->execute();
$stmt->close();

if (!$success) {
    echo json_encode(['success' => false, 'message' => 'Error updating token']);
    exit();
}

// Send email with invitation link
$invitation_link = "https://neowebx.com/invite/accept.php?token=" . $token;
$subject = "Visafy Team Invitation (Reminder)";
$message = "
<html>
<head>
    <title>Team Invitation Reminder</title>
</head>
<body>
    <h2>Reminder: You've been invited to join a team on Visafy</h2>
    <p>Hello {$member['first_name']},</p>
    <p>This is a reminder that you have been invited by {$member['company_name']} to join their team on Visafy as a team member.</p>
    <p>Please click the link below to set up your account:</p>
    <p><a href='$invitation_link'>Accept Invitation</a></p>
    <p>This link will expire in 48 hours.</p>
    <p>Best regards,<br>The Visafy Team</p>
</body>
</html>
";

$headers = "MIME-Version: 1.0" . "\r\n";
$headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
$headers .= "From: Visafy <noreply@visafy.com>" . "\r\n";

// In a production environment, use a proper email sending library or service
// For this implementation, we'll use mail()
$mail_sent = mail($member['email'], $subject, $message, $headers);

// Log the invitation resend
$stmt = $conn->prepare("INSERT INTO activity_log (user_id, action_type, action_details, ip_address) 
                       VALUES (?, 'team_member_invite_resend', ?, ?)");
$log_details = json_encode([
    'member_id' => $member_id,
    'member_email' => $member['email']
]);
$ip_address = $_SERVER['REMOTE_ADDR'];
$stmt->bind_param("iss", $user_id, $log_details, $ip_address);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true, 'message' => 'Invitation resent successfully']);
exit();
?>
