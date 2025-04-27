<?php
// File: dashboard/team_member/includes/header.php

// Start session only if one isn't already active
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once '../../config/db_connect.php';
require_once '../../includes/functions.php';

// Check if user is logged in and is a team member
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] != 'team_member') {
    header("Location: ../../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch user data
$stmt = $conn->prepare("SELECT u.*, tm.* FROM users u 
                        LEFT JOIN team_members tm ON u.id = tm.user_id 
                        WHERE u.id = ? AND u.role = 'team_member'");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    header("Location: ../../login.php");
    exit();
}

$user = $result->fetch_assoc();
$stmt->close();

// Get company information
$stmt = $conn->prepare("
    SELECT cp.*, pe.verification_status, pe.entity_type
    FROM company_professionals cp
    JOIN professional_entities pe ON cp.entity_id = pe.id
    WHERE cp.id = ?
");
$stmt->bind_param("i", $user['company_id']);
$stmt->execute();
$company_result = $stmt->get_result();
$company = $company_result->fetch_assoc();
$stmt->close();

// Get role information
$stmt = $conn->prepare("SELECT * FROM team_roles WHERE id = ?");
$stmt->bind_param("i", $user['role_id']);
$stmt->execute();
$role_result = $stmt->get_result();
$role = $role_result->fetch_assoc();
$stmt->close();

// Check for unread notifications
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$notif_result = $stmt->get_result();
$notification_count = $notif_result->fetch_assoc()['count'];
$stmt->close();

// Get recent notifications (limit to 5)
$stmt = $conn->prepare("SELECT id, title, message, is_read, created_at FROM notifications 
                       WHERE user_id = ? AND deleted_at IS NULL 
                       ORDER BY created_at DESC LIMIT 5");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$notifications = $stmt->get_result();
$notifications_list = [];
while ($notification = $notifications->fetch_assoc()) {
    $notifications_list[] = $notification;
}
$stmt->close();

// Determine if sidebar should be collapsed based on user preference or default
$sidebar_collapsed = isset($_COOKIE['sidebar_collapsed']) && $_COOKIE['sidebar_collapsed'] === 'true';
$sidebar_class = $sidebar_collapsed ? 'collapsed' : '';
$main_content_class = $sidebar_collapsed ? 'expanded' : '';

// Prepare profile image
$profile_img = '../assets/img/default-profile.jpg';
if (!empty($user['profile_image'])) {
    // Check both possible locations
    if (file_exists('../../uploads/profiles/' . $user['profile_image'])) {
        $profile_img = '../../uploads/profiles/' . $user['profile_image'];
    } else if (file_exists('../uploads/profiles/' . $user['profile_image'])) {
        $profile_img = '../uploads/profiles/' . $user['profile_image'];
    }
}

// Display name (first and last name)
$display_name = $user['first_name'] . ' ' . $user['last_name'];

// Get the current page name
$current_page = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title : 'Team Member Dashboard'; ?> - Visafy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../dashboard/assets/css/style.css">
</head>
<body>
    <div class="dashboard-container">
        <!-- Header -->
        <header class="header">
            <div class="header-left">
                <button id="sidebar-toggle" class="sidebar-toggle">
                    <i class="fas fa-bars"></i>
                </button>
                <a href="index.php" class="header-logo">
                    <img src="/assets/images/logo-Visafy-light.png" alt="Visafy Logo" class="desktop-logo">
                </a>
            </div>
            <div class="header-right">
                <div class="notification-dropdown">
                    <div class="notification-icon" id="notification-toggle">
                        <i class="fas fa-bell"></i>
                        <?php if ($notification_count > 0): ?>
                        <span class="notification-badge"><?php echo $notification_count; ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="notification-menu" id="notification-menu">
                        <div class="notification-header">
                            <h3>Notifications</h3>
                            <a href="notifications.php" class="mark-all-read">Mark all as read</a>
                        </div>
                        <ul class="notification-list">
                            <?php if (empty($notifications_list)): ?>
                                <li class="no-notifications">No notifications to display</li>
                            <?php else: ?>
                                <?php foreach ($notifications_list as $notification): ?>
                                    <li class="notification-item <?php echo $notification['is_read'] ? '' : 'unread'; ?>" 
                                        data-id="<?php echo $notification['id']; ?>">
                                        <div class="title"><?php echo htmlspecialchars($notification['title']); ?></div>
                                        <div class="message"><?php echo htmlspecialchars($notification['message']); ?></div>
                                        <div class="time">
                                            <?php 
                                                $date = new DateTime($notification['created_at']);
                                                $now = new DateTime();
                                                $interval = $date->diff($now);
                                                
                                                if ($interval->d > 0) {
                                                    echo $interval->d . ' day' . ($interval->d > 1 ? 's' : '') . ' ago';
                                                } elseif ($interval->h > 0) {
                                                    echo $interval->h . ' hour' . ($interval->h > 1 ? 's' : '') . ' ago';
                                                } elseif ($interval->i > 0) {
                                                    echo $interval->i . ' minute' . ($interval->i > 1 ? 's' : '') . ' ago';
                                                } else {
                                                    echo 'Just now';
                                                }
                                            ?>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                        <div class="notification-footer">
                            <a href="notifications.php">View All Notifications</a>
                        </div>
                    </div>
                </div>
                <div class="user-dropdown">
                    <span class="user-name"><?php echo htmlspecialchars($display_name); ?></span>
                    <img src="<?php echo $profile_img; ?>" alt="Profile" class="profile-img-header" style="width: 32px; height: 32px;">
                    <div class="user-dropdown-menu">
                        <a href="profile.php" class="dropdown-item">
                            <i class="fas fa-user"></i> Profile
                        </a>
                        <a href="settings.php" class="dropdown-item">
                            <i class="fas fa-cog"></i> Settings
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="../logout.php" class="dropdown-item">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Sidebar -->
        <aside  class="sidebar <?php echo $sidebar_class; ?>">
            <div class="profile-section">
                <img src="<?php echo $profile_img; ?>" alt="Profile" class="profile-img">
                <div class="profile-info">
                    <h3 class="profile-name"><?php echo htmlspecialchars($display_name); ?></h3>
                    <span class="team-role"><?php echo htmlspecialchars($role['name']); ?></span>
                    <span class="company-name"><?php echo htmlspecialchars($company['company_name']); ?></span>
                </div>
            </div>
            <nav class="sidebar-nav">
                <a href="index.php" class="nav-item <?php echo $current_page == 'index' ? 'active' : ''; ?>">
                    <i class="fas fa-tachometer-alt"></i>
                    <span class="nav-item-text">Dashboard</span>
                </a>
                
                <div class="sidebar-divider"></div>
                
                <a href="profile.php" class="nav-item <?php echo $current_page == 'profile' ? 'active' : ''; ?>">
                    <i class="fas fa-user"></i>
                    <span class="nav-item-text">Profile</span>
                </a>
                
                <a href="tasks.php" class="nav-item <?php echo $current_page == 'tasks' ? 'active' : ''; ?>">
                    <i class="fas fa-tasks"></i>
                    <span class="nav-item-text">My Tasks</span>
                </a>
                
                <a href="cases.php" class="nav-item <?php echo $current_page == 'cases' ? 'active' : ''; ?>">
                    <i class="fas fa-folder-open"></i>
                    <span class="nav-item-text">Cases</span>
                </a>
                
                <a href="documents.php" class="nav-item <?php echo $current_page == 'documents' ? 'active' : ''; ?>">
                    <i class="fas fa-file-alt"></i>
                    <span class="nav-item-text">Documents</span>
                </a>
                
                <div class="sidebar-divider"></div>
                
                <a href="messages.php" class="nav-item <?php echo $current_page == 'messages' ? 'active' : ''; ?>">
                    <i class="fas fa-envelope"></i>
                    <span class="nav-item-text">Messages</span>
                </a>
                
                <div class="sidebar-divider"></div>
                
                <a href="../logout.php" class="nav-item">
                    <i class="fas fa-sign-out-alt"></i>
                    <span class="nav-item-text">Logout</span>
                </a>
            </nav>
        </aside>

        <!-- Main Content Container -->
        <main class="main-content <?php echo $main_content_class; ?>">
