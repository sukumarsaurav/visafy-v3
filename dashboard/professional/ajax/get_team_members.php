<?php
require_once '../../config/db_connect.php';
session_start();
$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT id FROM company_professionals WHERE entity_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $company_id = $row['id'];
    $members = $conn->query("SELECT id, first_name, last_name FROM team_members WHERE company_id = $company_id AND is_active = 1 AND deleted_at IS NULL")->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['success' => true, 'members' => $members]);
} else {
    echo json_encode(['success' => false, 'members' => []]);
}
