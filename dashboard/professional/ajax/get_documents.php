<?php
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
if (!isset($_GET['visa_type_id']) || !is_numeric($_GET['visa_type_id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid visa type ID']);
    exit;
}

$visa_type_id = intval($_GET['visa_type_id']);

try {
    // Get all document categories with their document types
    $query = "
        SELECT 
            c.id as category_id, 
            c.name as category_name,
            dt.id as document_id, 
            dt.name as document_name,
            dt.description as document_description,
            IFNULL(vrd.is_mandatory, 0) as is_required,
            vrd.additional_requirements,
            vrd.id as requirement_id
        FROM document_categories c
        JOIN document_types dt ON c.id = dt.category_id
        LEFT JOIN visa_required_documents vrd ON dt.id = vrd.document_type_id AND vrd.visa_type_id = ?
        WHERE dt.is_active = 1
        ORDER BY c.name, dt.name
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $visa_type_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $categories = [];
    $current_category = null;
    
    while ($row = $result->fetch_assoc()) {
        if (!isset($categories[$row['category_id']])) {
            $categories[$row['category_id']] = [
                'id' => $row['category_id'],
                'name' => $row['category_name'],
                'documents' => []
            ];
        }
        
        $categories[$row['category_id']]['documents'][] = [
            'id' => $row['document_id'],
            'name' => $row['document_name'],
            'description' => $row['document_description'],
            'is_required' => $row['is_required'] ? true : false,
            'requirement_id' => $row['requirement_id'],
            'additional_requirements' => $row['additional_requirements']
        ];
    }
    
    echo json_encode(['success' => true, 'categories' => array_values($categories)]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>
