<?php
// Include database configuration
require_once('db_config.php');

// Initialize response array
$response = array(
    'status' => 'error',
    'message' => 'An error occurred while processing your request.'
);

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data and sanitize inputs
    $name = isset($_POST['name']) ? mysqli_real_escape_string($conn, $_POST['name']) : '';
    $email = isset($_POST['email']) ? mysqli_real_escape_string($conn, $_POST['email']) : '';
    $phone = isset($_POST['phone']) ? mysqli_real_escape_string($conn, $_POST['phone']) : '';
    $service = isset($_POST['service']) ? mysqli_real_escape_string($conn, $_POST['service']) : '';
    $message = isset($_POST['message']) ? mysqli_real_escape_string($conn, $_POST['message']) : '';
    
    // Validate inputs
    if (empty($name) || empty($email) || empty($message)) {
        $response['message'] = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $response['message'] = 'Please enter a valid email address.';
    } else {
        // All validations passed, insert into database
        $sql = "INSERT INTO contact_messages (name, email, phone, message, submission_date, status) 
                VALUES ('$name', '$email', '$phone', '$message', NOW(), 'new')";
        
        if ($conn->query($sql) === TRUE) {
            $response['status'] = 'success';
            $response['message'] = 'Thank you for your message! We will get back to you soon.';
            
            // Send email notification to admin (optional)
            $to = "info@easyborders.com";
            $subject = "New Contact Form Submission";
            $email_message = "Name: $name\n";
            $email_message .= "Email: $email\n";
            $email_message .= "Phone: $phone\n";
            $email_message .= "Service: $service\n\n";
            $email_message .= "Message:\n$message\n";
            
            $headers = "From: website@easyborders.com";
            
            // Uncomment to enable email sending - make sure mail server is configured
            // mail($to, $subject, $email_message, $headers);
            
        } else {
            $response['message'] = 'Database error: ' . $conn->error;
        }
    }
}

// Return JSON response if AJAX request
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Otherwise, redirect back to contact page with status
if ($response['status'] === 'success') {
    header('Location: ../contact.php?status=success&message=' . urlencode($response['message']));
} else {
    header('Location: ../contact.php?status=error&message=' . urlencode($response['message']));
}
exit;
?> 