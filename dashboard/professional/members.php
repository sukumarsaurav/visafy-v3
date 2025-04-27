<?php
// Set page title
$page_title = "Team Members";

// Include header
require_once 'includes/header.php';

// Check if entity is a company professional
$is_company = ($user['entity_type'] == 'company');

// If entity is a company, get the company professional ID from the company_professionals table
$company_id = null;
if ($is_company) {
    $stmt = $conn->prepare("SELECT id FROM company_professionals WHERE entity_id = ?");
    $stmt->bind_param("i", $user['id']);
    $stmt->execute();
    $company_result = $stmt->get_result();
    if ($company_result->num_rows > 0) {
        $company_data = $company_result->fetch_assoc();
        $company_id = $company_data['id'];
    }
    $stmt->close();
}

// Process form submission
$error_msg = '';
$success_msg = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && $is_company && $company_id) {
    // Validate and sanitize input
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = filter_var(trim($_POST['email']), FILTER_VALIDATE_EMAIL);
    $position = trim($_POST['position']);
    $role_id = (int)$_POST['role_id'];
    $access_level = $_POST['access_level'];
    $phone = trim($_POST['phone']);
    $years_experience = isset($_POST['years_experience']) ? (int)$_POST['years_experience'] : null;
    $bio = trim($_POST['bio']);
    $is_primary_contact = isset($_POST['is_primary_contact']) ? 1 : 0;

    // Validate required fields
    if (empty($first_name) || empty($last_name) || empty($email) || empty($position) || empty($role_id) || empty($access_level)) {
        $error_msg = "Please fill in all required fields.";
    } elseif (!$email) {
        $error_msg = "Please enter a valid email address.";
    } else {
        // Check if email already exists in the users table
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND deleted_at IS NULL");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $email_check = $stmt->get_result();
        
        if ($email_check->num_rows > 0) {
            $error_msg = "This email address is already registered in the system.";
        } else {
            // Begin transaction
            $conn->begin_transaction();
            try {
                // Generate a unique token for the invitation link
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+48 hours'));
                
                // Insert into users table with temporary data
                $stmt = $conn->prepare("INSERT INTO users (email, password, role, email_verification_token, email_verification_expires) VALUES (?, '', 'team_member', ?, ?)");
                $stmt->bind_param("sss", $email, $token, $expires);
                $stmt->execute();
                $user_id = $conn->insert_id;
                
                // Handle primary contact (Only one can be primary)
                if ($is_primary_contact) {
                    // Reset all other primary contacts
                    $stmt = $conn->prepare("UPDATE team_members SET is_primary_contact = 0 WHERE company_id = ?");
                    $stmt->bind_param("i", $company_id);
                    $stmt->execute();
                }
                
                // Insert into team_members table
                $stmt = $conn->prepare("INSERT INTO team_members (user_id, company_id, role_id, first_name, last_name, position, access_level, phone, bio, years_experience, is_primary_contact) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("iiissssssis", $user_id, $company_id, $role_id, $first_name, $last_name, $position, $access_level, $phone, $bio, $years_experience, $is_primary_contact);
                $stmt->execute();
                
                // Send email with invitation link
                $invitation_link = "https://visafy.com/invite/accept.php?token=" . $token;
                $subject = "Visafy Team Invitation";
                $message = "
                <html>
                <head>
                    <title>Team Invitation</title>
                </head>
                <body>
                    <h2>Welcome to the team!</h2>
                    <p>Hello $first_name,</p>
                    <p>You have been invited by {$user['name']} to join their team on Visafy as a team member.</p>
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
                mail($email, $subject, $message, $headers);
                
                // Log the invitation
                $stmt = $conn->prepare("INSERT INTO activity_log (user_id, action_type, action_details, ip_address) 
                                       VALUES (?, 'team_member_invite', ?, ?)");
                $log_details = json_encode([
                    'invited_user_id' => $user_id,
                    'invited_email' => $email,
                    'company_id' => $company_id
                ]);
                $ip_address = $_SERVER['REMOTE_ADDR'];
                $stmt->bind_param("iss", $user_id, $log_details, $ip_address);
                $stmt->execute();
                
                // Commit transaction
                $conn->commit();
                $success_msg = "Team member invitation sent successfully to $email.";
                
            } catch (Exception $e) {
                // Roll back transaction on error
                $conn->rollback();
                $error_msg = "An error occurred: " . $e->getMessage();
                error_log("Team member invitation error: " . $e->getMessage());
            }
        }
    }
}

// Fetch team roles
$roles = [];
$stmt = $conn->prepare("SELECT * FROM team_roles WHERE (is_system = 1 OR company_id = ?) AND is_active = 1 ORDER BY name");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$roles_result = $stmt->get_result();
while ($role = $roles_result->fetch_assoc()) {
    $roles[] = $role;
}
$stmt->close();

// Fetch existing team members grouped by role
$members_by_role = [];
$total_members = 0;

if ($is_company && $company_id) {
    $stmt = $conn->prepare("
        SELECT tm.*, tr.name as role_name, u.email, u.email_verified
        FROM team_members tm
        JOIN team_roles tr ON tm.role_id = tr.id
        JOIN users u ON tm.user_id = u.id
        WHERE tm.company_id = ? AND tm.deleted_at IS NULL
        ORDER BY tr.name, tm.first_name, tm.last_name
    ");
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $members_result = $stmt->get_result();
    
    while ($member = $members_result->fetch_assoc()) {
        $role_name = $member['role_name'];
        if (!isset($members_by_role[$role_name])) {
            $members_by_role[$role_name] = [];
        }
        $members_by_role[$role_name][] = $member;
        $total_members++;
    }
    $stmt->close();
}
?>

<!-- Add custom CSS -->
<link rel="stylesheet" href="assets/css/member.css">

<div class="content-wrapper">
    <div class="content-header">
        <div class="row">
            <div class="col-12">
                <h1 class="page-title">Team Members</h1>
            </div>
        </div>
    </div>

    <div class="content-section">
        <?php if ($error_msg): ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($error_msg); ?>
                <button type="button" class="alert-dismiss">&times;</button>
            </div>
        <?php endif; ?>
        
        <?php if ($success_msg): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success_msg); ?>
                <button type="button" class="alert-dismiss">&times;</button>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-lg-8">
                <!-- Team Members List -->
                <div class="team-card">
                    <div class="team-card-header">
                        <i class="fas fa-users"></i>
                        <h3 class="team-card-title">
                            Your Team (<?php echo $total_members; ?> members)
                        </h3>
                    </div>
                    <div class="team-card-body">
                        <?php if ($is_company && count($members_by_role) > 0): ?>
                            <!-- Team members list grouped by role -->
                            <?php foreach ($members_by_role as $role_name => $members): ?>
                                <div class="role-section">
                                    <h4 class="role-title"><?php echo htmlspecialchars($role_name); ?> (<?php echo count($members); ?>)</h4>
                                    <div class="members-grid">
                                        <?php foreach ($members as $member): ?>
                                            <div class="member-card">
                                                <div class="member-header">
                                                    <div class="member-avatar">
                                                        <img src="<?php echo !empty($member['profile_image']) ? '../../uploads/profiles/' . $member['profile_image'] : '../assets/img/default-profile.jpg'; ?>" alt="Profile">
                                                    </div>
                                                    <div class="member-info">
                                                        <h5 class="member-name"><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></h5>
                                                        <p class="member-position"><?php echo htmlspecialchars($member['position']); ?></p>
                                                        <span class="member-badge <?php echo $member['is_active'] ? 'badge-active' : 'badge-inactive'; ?>">
                                                            <?php echo $member['is_active'] ? 'Active' : 'Inactive'; ?>
                                                        </span>
                                                        <?php if ($member['is_primary_contact']): ?>
                                                            <span class="member-badge badge-primary">Primary Contact</span>
                                                        <?php endif; ?>
                                                        <?php if (!$member['email_verified']): ?>
                                                            <span class="member-badge badge-warning">Invitation Pending</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="member-details">
                                                    <p><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($member['email']); ?></p>
                                                    <?php if (!empty($member['phone'])): ?>
                                                        <p><i class="fas fa-phone"></i> <?php echo htmlspecialchars($member['phone']); ?></p>
                                                    <?php endif; ?>
                                                    <p><i class="fas fa-shield-alt"></i> Access: <?php echo ucfirst(htmlspecialchars($member['access_level'])); ?></p>
                                                </div>
                                                <div class="member-actions">
                                                    <a href="member-detail.php?id=<?php echo $member['id']; ?>" class="action-btn btn-view">
                                                        <i class="fas fa-eye"></i> View
                                                    </a>
                                                    <a href="member-edit.php?id=<?php echo $member['id']; ?>" class="action-btn btn-edit">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </a>
                                                    <?php if (!$member['email_verified']): ?>
                                                        <button class="action-btn btn-info resend-invitation" data-id="<?php echo $member['id']; ?>" data-email="<?php echo htmlspecialchars($member['email']); ?>">
                                                            <i class="fas fa-paper-plane"></i> Resend
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php elseif ($is_company): ?>
                            <div class="team-empty-state">
                                <i class="fas fa-user-friends"></i>
                                <h5>No team members yet</h5>
                                <p>Add team members to collaborate on cases and tasks.</p>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Team members are only available for company professional accounts.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <!-- Add Team Member Form -->
                <?php if ($is_company): ?>
                    <div class="form-card">
                        <div class="form-card-header">
                            <i class="fas fa-user-plus"></i>
                            <h3 class="form-card-title">
                                Invite Team Member
                            </h3>
                        </div>
                        <div class="form-card-body">
                            <form method="post" action="" id="addTeamMemberForm">
                                <div class="form-group">
                                    <label for="first_name" class="form-label">First Name <span class="form-required">*</span></label>
                                    <input type="text" class="form-input" id="first_name" name="first_name" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="last_name" class="form-label">Last Name <span class="form-required">*</span></label>
                                    <input type="text" class="form-input" id="last_name" name="last_name" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="email" class="form-label">Email Address <span class="form-required">*</span></label>
                                    <input type="email" class="form-input" id="email" name="email" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="position" class="form-label">Position <span class="form-required">*</span></label>
                                    <input type="text" class="form-input" id="position" name="position" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="role_id" class="form-label">Role <span class="form-required">*</span></label>
                                    <select class="form-select" id="role_id" name="role_id" required>
                                        <option value="">Select a role</option>
                                        <?php foreach ($roles as $role): ?>
                                            <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="access_level" class="form-label">Access Level <span class="form-required">*</span></label>
                                    <select class="form-select" id="access_level" name="access_level" required>
                                        <option value="limited">Limited - Basic access to assigned tasks</option>
                                        <option value="standard" selected>Standard - Regular team member access</option>
                                        <option value="advanced">Advanced - Enhanced permissions</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="phone" class="form-label">Phone Number</label>
                                    <input type="tel" class="form-input" id="phone" name="phone">
                                </div>
                                
                                <div class="form-group">
                                    <label for="years_experience" class="form-label">Years of Experience</label>
                                    <input type="number" class="form-input" id="years_experience" name="years_experience" min="0">
                                </div>
                                
                                <div class="form-group">
                                    <label for="bio" class="form-label">Bio/Description</label>
                                    <textarea class="form-textarea" id="bio" name="bio" rows="3"></textarea>
                                </div>
                                
                                <div class="form-group">
                                    <div class="custom-control custom-checkbox">
                                        <input type="checkbox" class="custom-control-input" id="is_primary_contact" name="is_primary_contact">
                                        <label class="custom-control-label" for="is_primary_contact">Set as primary contact</label>
                                    </div>
                                </div>
                                
                                <button type="submit" class="form-button">
                                    <i class="fas fa-paper-plane"></i> Send Invitation
                                </button>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-info-circle mr-1"></i>
                                Information
                            </h3>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-warning">
                                <h5><i class="fas fa-exclamation-triangle"></i> Team members feature is unavailable</h5>
                                <p>Team members can only be added by company professional accounts. Individual professional accounts don't have access to this feature.</p>
                            </div>
                            <p>If you need to upgrade to a company account, please contact support.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript for form validation and invitation resending -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Form validation
    const form = document.getElementById('addTeamMemberForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            let isValid = true;
            const requiredFields = form.querySelectorAll('[required]');
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    isValid = false;
                    field.classList.add('is-invalid');
                } else {
                    field.classList.remove('is-invalid');
                }
            });
            
            const emailField = form.querySelector('#email');
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (emailField && !emailRegex.test(emailField.value.trim())) {
                isValid = false;
                emailField.classList.add('is-invalid');
            }
            
            if (!isValid) {
                e.preventDefault();
                alert('Please fill in all required fields correctly.');
            }
        });
    }
    
    // Resend invitation functionality
    const resendButtons = document.querySelectorAll('.resend-invitation');
    resendButtons.forEach(button => {
        button.addEventListener('click', function() {
            const memberId = this.getAttribute('data-id');
            const email = this.getAttribute('data-email');
            
            if (confirm(`Resend invitation to ${email}?`)) {
                // AJAX request to resend invitation
                fetch('ajax/resend_invitation.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'member_id=' + memberId
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Invitation resent successfully.');
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while resending the invitation.');
                });
            }
        });
    });
});
</script>

<?php
// Include footer
require_once 'includes/footer.php';
?>
