<?php
// File: dashboard/team_member/index.php

// Set page title
$page_title = "Dashboard";

// Include header
require_once 'includes/header.php';

// Fetch task statistics
$pending_tasks_count = 0;
$completed_tasks_count = 0;
$assigned_cases_count = 0;
$upcoming_deadlines_count = 0;

// Count pending tasks
$stmt = $conn->prepare("
    SELECT COUNT(*) as count 
    FROM tasks 
    WHERE assigned_to = ? AND status = 'pending' AND deleted_at IS NULL
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $pending_tasks_count = $result->fetch_assoc()['count'];
}
$stmt->close();

// Count completed tasks (last 30 days)
$stmt = $conn->prepare("
    SELECT COUNT(*) as count 
    FROM tasks 
    WHERE assigned_to = ? AND status = 'completed' AND completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND deleted_at IS NULL
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $completed_tasks_count = $result->fetch_assoc()['count'];
}
$stmt->close();

// Count assigned cases
$stmt = $conn->prepare("
    SELECT COUNT(DISTINCT case_id) as count 
    FROM case_team_members 
    WHERE team_member_id = ? AND deleted_at IS NULL
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $assigned_cases_count = $result->fetch_assoc()['count'];
}
$stmt->close();

// Count upcoming deadlines (next 7 days)
$stmt = $conn->prepare("
    SELECT COUNT(*) as count 
    FROM tasks 
    WHERE assigned_to = ? AND status = 'pending' AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND deleted_at IS NULL
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $upcoming_deadlines_count = $result->fetch_assoc()['count'];
}
$stmt->close();

// Get recent tasks (limit to 5)
$recent_tasks = [];
$stmt = $conn->prepare("
    SELECT t.*, c.title as case_title
    FROM tasks t
    LEFT JOIN cases c ON t.case_id = c.id
    WHERE t.assigned_to = ? AND t.deleted_at IS NULL
    ORDER BY t.created_at DESC
    LIMIT 5
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($task = $result->fetch_assoc()) {
    $recent_tasks[] = $task;
}
$stmt->close();

// Get active cases (limit to 5)
$active_cases = [];
$stmt = $conn->prepare("
    SELECT c.*, u.email as client_email, 
           CONCAT(a.first_name, ' ', a.last_name) as client_name
    FROM cases c
    JOIN case_team_members ctm ON c.id = ctm.case_id
    JOIN users u ON c.client_id = u.id
    LEFT JOIN applicants a ON u.id = a.user_id
    WHERE ctm.team_member_id = ? AND c.status = 'active' AND c.deleted_at IS NULL
    ORDER BY c.updated_at DESC
    LIMIT 5
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($case = $result->fetch_assoc()) {
    $active_cases[] = $case;
}
$stmt->close();

// Format date function
function formatDate($date) {
    return date('M j, Y', strtotime($date));
}
?>

<div class="content-wrapper">
    <!-- Welcome Banner -->
    <div class="content-header">
        <h1>Welcome, <?php echo htmlspecialchars($user['first_name']); ?>!</h1>
        <p>Here's an overview of your tasks and activities.</p>
    </div>

    <!-- Dashboard Stats -->
    <div class="dashboard-stats">
        <div class="stats-card">
            <div class="stats-icon blue">
                <i class="fas fa-tasks"></i>
            </div>
            <div class="stats-info">
                <div class="stats-title">Pending Tasks</div>
                <div class="stats-value"><?php echo $pending_tasks_count; ?></div>
            </div>
        </div>
        
        <div class="stats-card">
            <div class="stats-icon green">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stats-info">
                <div class="stats-title">Completed (30 days)</div>
                <div class="stats-value"><?php echo $completed_tasks_count; ?></div>
            </div>
        </div>
        
        <div class="stats-card">
            <div class="stats-icon orange">
                <i class="fas fa-folder-open"></i>
            </div>
            <div class="stats-info">
                <div class="stats-title">Assigned Cases</div>
                <div class="stats-value"><?php echo $assigned_cases_count; ?></div>
            </div>
        </div>
        
        <div class="stats-card">
            <div class="stats-icon red">
                <i class="fas fa-clock"></i>
            </div>
            <div class="stats-info">
                <div class="stats-title">Upcoming Deadlines</div>
                <div class="stats-value"><?php echo $upcoming_deadlines_count; ?></div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Recent Tasks -->
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-tasks card-icon"></i> Recent Tasks</h3>
                    <a href="tasks.php" class="btn btn-sm btn-primary">View All</a>
                </div>
                <div class="card-body">
                    <?php if (count($recent_tasks) > 0): ?>
                        <ul class="task-list">
                            <?php foreach ($recent_tasks as $task): ?>
                                <li class="task-item">
                                    <div class="task-checkbox">
                                        <input type="checkbox" <?php echo $task['status'] === 'completed' ? 'checked' : ''; ?> disabled>
                                    </div>
                                    <div class="task-content">
                                        <div class="task-title"><?php echo htmlspecialchars($task['title']); ?></div>
                                        <?php if (!empty($task['description'])): ?>
                                            <div class="task-description"><?php echo htmlspecialchars(substr($task['description'], 0, 100)); echo strlen($task['description']) > 100 ? '...' : ''; ?></div>
                                        <?php endif; ?>
                                        <div class="task-meta">
                                            <?php if (!empty($task['case_title'])): ?>
                                                <span><i class="fas fa-folder"></i> <?php echo htmlspecialchars($task['case_title']); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($task['due_date'])): ?>
                                                <span><i class="fas fa-calendar"></i> Due: <?php echo formatDate($task['due_date']); ?></span>
                                            <?php endif; ?>
                                            <span class="task-priority priority-<?php echo strtolower($task['priority']); ?>"><?php echo ucfirst(strtolower($task['priority'])); ?></span>
                                        </div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-tasks"></i>
                            <h5>No tasks yet</h5>
                            <p>You don't have any tasks assigned to you yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Active Cases -->
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-folder-open card-icon"></i> Active Cases</h3>
                    <a href="cases.php" class="btn btn-sm btn-primary">View All</a>
                </div>
                <div class="card-body">
                    <?php if (count($active_cases) > 0): ?>
                        <ul class="case-list">
                            <?php foreach ($active_cases as $case): ?>
                                <li class="case-item">
                                    <div class="case-icon">
                                        <i class="fas fa-folder"></i>
                                    </div>
                                    <div class="case-info">
                                        <div class="case-title"><?php echo htmlspecialchars($case['title']); ?></div>
                                        <div class="case-meta">
                                            <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($case['client_name']); ?></span>
                                            <span><i class="fas fa-calendar"></i> Created: <?php echo formatDate($case['created_at']); ?></span>
                                            <span class="case-status status-<?php echo strtolower($case['status']); ?>"><?php echo ucfirst($case['status']); ?></span>
                                        </div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-folder-open"></i>
                            <h5>No active cases</h5>
                            <p>You don't have any active cases assigned to you.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Access -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-link card-icon"></i> Quick Access</h3>
        </div>
        <div class="card-body">
            <div class="quick-access-grid">
                <a href="tasks.php" class="quick-access-item">
                    <i class="fas fa-tasks"></i>
                    <span>My Tasks</span>
                </a>
                <a href="cases.php" class="quick-access-item">
                    <i class="fas fa-folder-open"></i>
                    <span>Cases</span>
                </a>
                <a href="documents.php" class="quick-access-item">
                    <i class="fas fa-file-alt"></i>
                    <span>Documents</span>
                </a>
                <a href="messages.php" class="quick-access-item">
                    <i class="fas fa-envelope"></i>
                    <span>Messages</span>
                </a>
                <a href="profile.php" class="quick-access-item">
                    <i class="fas fa-user"></i>
                    <span>Profile</span>
                </a>
            </div>
        </div>
    </div>
</div>

<style>
.quick-access-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    gap: 15px;
}

.quick-access-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 20px;
    background-color: #f8f9fc;
    border-radius: 8px;
    transition: all 0.2s;
    text-decoration: none;
    color: var(--dark-color);
}

.quick-access-item:hover {
    background-color: var(--primary-color);
    color: white;
    transform: translateY(-5px);
}

.quick-access-item i {
    font-size: 24px;
    margin-bottom: 10px;
}

.quick-access-item span {
    font-weight: 600;
}

.col-lg-6 {
    width: calc(50% - 15px);
    float: left;
}

.col-lg-6:first-child {
    margin-right: 15px;
}

.col-lg-6:last-child {
    margin-left: 15px;
}

.row {
    margin: 0 -15px;
    overflow: hidden;
    margin-bottom: 30px;
}

.btn {
    display: inline-block;
    font-weight: 600;
    text-align: center;
    white-space: nowrap;
    vertical-align: middle;
    user-select: none;
    border: 1px solid transparent;
    padding: 0.375rem 0.75rem;
    font-size: 0.875rem;
    line-height: 1.5;
    border-radius: 0.25rem;
    transition: color 0.15s ease-in-out, background-color 0.15s ease-in-out, border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
    text-decoration: none;
}

.btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.765625rem;
    line-height: 1.5;
    border-radius: 0.2rem;
}

.btn-primary {
    color: #fff;
    background-color: var(--primary-color);
    border-color: var(--primary-color);
}

@media (max-width: 992px) {
    .col-lg-6 {
        width: 100%;
        float: none;
        margin-right: 0;
        margin-left: 0;
    }
}
</style>

<?php
// Include footer
require_once 'includes/footer.php';
?>
