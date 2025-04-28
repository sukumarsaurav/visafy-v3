<?php
// Set page title
$page_title = "Clients";

// Include header
require_once 'includes/header.php';

// Check if entity is a professional entity
$entity_id = $user['id'];

// Process form submission for adding new client
$error_msg = '';
$success_msg = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_client') {
    // Validate and sanitize input
    $email = filter_var(trim($_POST['email']), FILTER_VALIDATE_EMAIL);
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $source = $_POST['source'];
    $source_details = trim($_POST['source_details'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $team_member_id = !empty($_POST['team_member_id']) ? (int)$_POST['team_member_id'] : null;
    $is_existing_user = isset($_POST['is_existing_user']) ? (int)$_POST['is_existing_user'] : 0;
    
    // Validate required fields
    if (empty($email) || empty($first_name) || empty($last_name) || empty($source)) {
        $error_msg = "Please fill in all required fields.";
    } elseif (!$email) {
        $error_msg = "Please enter a valid email address.";
    } else {
        // Begin transaction
        $conn->begin_transaction();
        try {
            // Check if user with this email already exists
            $stmt = $conn->prepare("SELECT id, role FROM users WHERE email = ? AND deleted_at IS NULL");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $user_result = $stmt->get_result();
            $existing_user = $user_result->num_rows > 0 ? $user_result->fetch_assoc() : null;
            
            // If user said it's an existing user but no user found, or if user said it's not but user exists
            if (($is_existing_user && !$existing_user) || (!$is_existing_user && $existing_user)) {
                $error_msg = $is_existing_user 
                    ? "No user with this email address was found in our system."
                    : "A user with this email already exists. Please select 'Existing Visafy User'.";
                $conn->rollback();
            } else {
                if ($existing_user) {
                    // Check if user is already a client of this professional
                    $stmt = $conn->prepare("SELECT id FROM professional_clients 
                                            WHERE professional_entity_id = ? AND applicant_id = ? AND deleted_at IS NULL");
                    $stmt->bind_param("ii", $entity_id, $existing_user['id']);
                    $stmt->execute();
                    $client_check = $stmt->get_result();
                    
                    if ($client_check->num_rows > 0) {
                        $error_msg = "This client is already associated with your account.";
                        $conn->rollback();
                    } else {
                        // Add existing user as a client
                        $applicant_id = $existing_user['id'];
                        
                        // If user is not an applicant, show error
                        if ($existing_user['role'] != 'applicant') {
                            $error_msg = "This email belongs to a non-applicant user in the system.";
                            $conn->rollback();
                        } else {
                            // Add as client
                            $stmt = $conn->prepare("INSERT INTO professional_clients 
                                                  (professional_entity_id, applicant_id, status, assigned_team_member_id, source, source_details, notes) 
                                                  VALUES (?, ?, 'pending', ?, ?, ?, ?)");
                            $stmt->bind_param("iiisss", $entity_id, $applicant_id, $team_member_id, $source, $source_details, $notes);
                            $stmt->execute();
                            
                            // Send invitation email
                            $subject = "Visafy Professional Invitation";
                            $message = "
                            <html>
                            <head>
                                <title>Professional Invitation</title>
                            </head>
                            <body>
                                <h2>You've Been Invited!</h2>
                                <p>Hello $first_name,</p>
                                <p>You have been invited by {$user['name']} to connect as a client on Visafy.</p>
                                <p>Please log in to your Visafy account and check your notifications to accept this invitation.</p>
                                <p>Best regards,<br>The Visafy Team</p>
                            </body>
                            </html>
                            ";
                            
                            $headers = "MIME-Version: 1.0" . "\r\n";
                            $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
                            $headers .= "From: Visafy <noreply@visafy.io>" . "\r\n";
                            
                            mail($email, $subject, $message, $headers);
                            
                            // Add notification for the user
                            $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, link) 
                                                  VALUES (?, 'New Professional Connection', ?, '/applicant/connections.php')");
                            $notification_msg = "You have been invited by {$user['name']} to connect as a client.";
                            $stmt->bind_param("is", $applicant_id, $notification_msg);
                            $stmt->execute();
                            
                            $success_msg = "Invitation sent to existing user $email successfully!";
                            $conn->commit();
                        }
                    }
                } else {
                    // Create new user with applicant role
                    $password = bin2hex(random_bytes(8)); // Generate a random password
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $role = 'applicant';
                    $status = 'active';
                    $email_verified = 0;
                    $token = bin2hex(random_bytes(32));
                    $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
                    
                    $stmt = $conn->prepare("INSERT INTO users (email, password, role, status, email_verified, email_verification_token, email_verification_expires) 
                                          VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("sssssss", $email, $hashed_password, $role, $status, $email_verified, $token, $expires);
                    $stmt->execute();
                    $applicant_id = $conn->insert_id;
                    
                    // Add as client
                    $stmt = $conn->prepare("INSERT INTO professional_clients 
                                          (professional_entity_id, applicant_id, status, assigned_team_member_id, source, source_details, notes) 
                                          VALUES (?, ?, 'pending', ?, ?, ?, ?)");
                    $stmt->bind_param("iiisss", $entity_id, $applicant_id, $team_member_id, $source, $source_details, $notes);
                    $stmt->execute();
                    
                    // Send invitation email with verification link
                    $invitation_link = "https://neowebx.store/verify_email.php?token=" . $token;
                    $subject = "Visafy Client Invitation";
                    $message = "
                    <html>
                    <head>
                        <title>Client Invitation</title>
                    </head>
                    <body>
                        <h2>Welcome to Visafy!</h2>
                        <p>Hello $first_name,</p>
                        <p>You have been invited by {$user['name']} to join Visafy as a client.</p>
                        <p>Please click the link below to verify your email and set up your account:</p>
                        <p><a href='$invitation_link'>Verify Email and Set Up Account</a></p>
                        <p>This link will expire in 24 hours.</p>
                        <p>Best regards,<br>The Visafy Team</p>
                    </body>
                    </html>
                    ";
                    
                    $headers = "MIME-Version: 1.0" . "\r\n";
                    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
                    $headers .= "From: Visafy <noreply@visafy.com>" . "\r\n";
                    
                    mail($email, $subject, $message, $headers);
                    
                    $success_msg = "New client created and invitation sent to $email successfully!";
                    $conn->commit();
                }
            }
        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = "An error occurred: " . $e->getMessage();
            error_log("Client invitation error: " . $e->getMessage());
        }
    }
}

// Fetch team members for dropdown
$team_members = [];
if ($user['entity_type'] == 'company') {
    // First, get the company professional ID from the company_professionals table
    $stmt = $conn->prepare("SELECT id FROM company_professionals WHERE entity_id = ?");
    $stmt->bind_param("i", $user['id']);
    $stmt->execute();
    $company_result = $stmt->get_result();
    
    if ($company_result->num_rows > 0) {
        $company_data = $company_result->fetch_assoc();
        $company_id = $company_data['id'];
        
        // Now fetch team members using the correct company_id
        $stmt = $conn->prepare("
            SELECT tm.id, tm.first_name, tm.last_name, tr.name as role_name 
            FROM team_members tm
            JOIN team_roles tr ON tm.role_id = tr.id
            WHERE tm.company_id = ? AND tm.is_active = 1 AND tm.deleted_at IS NULL
            ORDER BY tm.first_name, tm.last_name
        ");
        
        $stmt->bind_param("i", $company_id);
        $stmt->execute();
        $team_members_result = $stmt->get_result();
        
        while ($member = $team_members_result->fetch_assoc()) {
            $team_members[] = $member;
        }
    }
}

// Fetch clients
$clients = [];
$stmt = $conn->prepare("
    SELECT pc.*, u.email, u.email_verified, tm.first_name as team_first_name, tm.last_name as team_last_name
    FROM professional_clients pc
    JOIN users u ON pc.applicant_id = u.id
    LEFT JOIN team_members tm ON pc.assigned_team_member_id = tm.id
    WHERE pc.professional_entity_id = ? AND pc.deleted_at IS NULL
    ORDER BY pc.created_at DESC
");
$stmt->bind_param("i", $entity_id);
$stmt->execute();
$clients_result = $stmt->get_result();

while ($client = $clients_result->fetch_assoc()) {
    // Get applicant details
    $stmt2 = $conn->prepare("
        SELECT profile_picture 
        FROM users 
        WHERE id = ?
    ");
    $stmt2->bind_param("i", $client['applicant_id']);
    $stmt2->execute();
    $user_result = $stmt2->get_result();
    $user_data = $user_result->fetch_assoc();
    
    $client['profile_picture'] = $user_data['profile_picture'] ?? null;
    $clients[] = $client;
}
?>

<!-- Add custom CSS -->
<link rel="stylesheet" href="assets/css/clients.css">

<div class="content-wrapper">
    <div class="content-header">
        <div class="row">
            <div class="col-12">
                <h1 class="page-title">Clients</h1>
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
            <div class="col-lg-12">
                <!-- Clients List -->
                <div class="clients-card">
                    <div class="clients-card-header">
                        <i class="fas fa-users"></i>
                        <h3 class="clients-card-title">
                            Your Clients (<?php echo count($clients); ?>)
                        </h3>
                        <div class="header-actions">
                            <input type="text" id="clientSearch" class="search-input" placeholder="Search clients...">
                            <div class="filter-dropdown">
                                <button class="filter-btn">
                                    <i class="fas fa-filter"></i> Filter
                                </button>
                                <div class="filter-menu">
                                    <div class="filter-item">
                                        <input type="checkbox" id="active" checked>
                                        <label for="active">Active</label>
                                    </div>
                                    <div class="filter-item">
                                        <input type="checkbox" id="pending" checked>
                                        <label for="pending">Pending</label>
                                    </div>
                                    <div class="filter-item">
                                        <input type="checkbox" id="inactive">
                                        <label for="inactive">Inactive</label>
                                    </div>
                                    <div class="filter-item">
                                        <input type="checkbox" id="archived">
                                        <label for="archived">Archived</label>
                                    </div>
                                </div>
                            </div>
                            <button id="addClientBtn" class="primary-btn">
                                <i class="fas fa-plus"></i> Add Client
                            </button>
                        </div>
                    </div>
                    <div class="clients-card-body">
                        <?php if (count($clients) > 0): ?>
                            <div class="clients-grid">
                                <?php foreach ($clients as $client): ?>
                                    <div class="client-card" data-status="<?php echo htmlspecialchars($client['status']); ?>">
                                        <div class="client-header">
                                            <div class="client-avatar">
                                                <img src="<?php echo !empty($client['profile_picture']) ? '../../uploads/profiles/' . $client['profile_picture'] : '../assets/img/default-profile.jpg'; ?>" alt="Profile">
                                            </div>
                                            <div class="client-info">
                                                <h5 class="client-email"><?php echo htmlspecialchars($client['email']); ?></h5>
                                                <span class="client-badge <?php echo 'badge-' . $client['status']; ?>">
                                                    <?php echo ucfirst($client['status']); ?>
                                                </span>
                                                <?php if (!$client['email_verified']): ?>
                                                    <span class="client-badge badge-warning">Email Not Verified</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="client-details">
                                            <?php if($client['assigned_team_member_id']): ?>
                                                <p><i class="fas fa-user-check"></i> Assigned to: <?php echo htmlspecialchars($client['team_first_name'] . ' ' . $client['team_last_name']); ?></p>
                                            <?php else: ?>
                                                <p><i class="fas fa-user-times"></i> No team member assigned</p>
                                            <?php endif; ?>
                                            
                                            <p><i class="fas fa-calendar-alt"></i> Added: <?php echo date('M d, Y', strtotime($client['created_at'])); ?></p>
                                            <p><i class="fas fa-tag"></i> Source: <?php echo ucfirst(htmlspecialchars($client['source'])); ?></p>
                                        </div>
                                        <div class="client-actions">
                                            <a href="client-detail.php?id=<?php echo $client['id']; ?>" class="action-btn btn-view">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            <a href="client-edit.php?id=<?php echo $client['id']; ?>" class="action-btn btn-edit">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            <?php if ($client['status'] !== 'archived'): ?>
                                                <button class="action-btn btn-archive archive-client" data-id="<?php echo $client['id']; ?>">
                                                    <i class="fas fa-archive"></i> Archive
                                                </button>
                                            <?php else: ?>
                                                <button class="action-btn btn-restore restore-client" data-id="<?php echo $client['id']; ?>">
                                                    <i class="fas fa-trash-restore"></i> Restore
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="clients-empty-state">
                                <i class="fas fa-user-friends"></i>
                                <h5>No clients yet</h5>
                                <p>Add clients to start managing your visa cases.</p>
                                <button id="emptyAddClientBtn" class="primary-btn mt-3">
                                    <i class="fas fa-plus"></i> Add Your First Client
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Client Modal -->
<div id="addClientModal" class="modal">
    <div class="modal-content">
        <span class="close-modal">&times;</span>
        
        <!-- Step 1: User Type Selection -->
        <div class="modal-step" id="step1">
            <h2 class="modal-title">Add New Client</h2>
            <p class="modal-subtitle">Step 1: Select User Type</p>
            
            <div class="user-type-selection">
                <div class="user-type-option" data-type="existing">
                    <i class="fas fa-user-check"></i>
                    <h3>Existing Visafy User</h3>
                    <p>The user already has an account on Visafy</p>
                </div>
                <div class="user-type-option" data-type="new">
                    <i class="fas fa-user-plus"></i>
                    <h3>New User</h3>
                    <p>Invite someone who doesn't have a Visafy account yet</p>
                </div>
            </div>
            
            <div class="modal-footer">
                <button class="secondary-btn modal-cancel">Cancel</button>
                <button class="primary-btn" id="step1Next" disabled>Next</button>
            </div>
        </div>
        
        <!-- Step 2: Email & Basic Info -->
        <div class="modal-step" id="step2" style="display: none;">
            <h2 class="modal-title">Add New Client</h2>
            <p class="modal-subtitle">Step 2: Basic Information</p>
            
            <form id="clientInfoForm">
                <input type="hidden" id="is_existing_user" name="is_existing_user" value="0">
                
                <div class="form-group">
                    <label for="email" class="form-label">Email Address <span class="form-required">*</span></label>
                    <input type="email" class="form-input" id="email" name="email" required>
                    <small class="form-text" id="emailHelp"></small>
                </div>
                
                <div class="form-group">
                    <label for="first_name" class="form-label">First Name <span class="form-required">*</span></label>
                    <input type="text" class="form-input" id="first_name" name="first_name" required>
                </div>
                
                <div class="form-group">
                    <label for="last_name" class="form-label">Last Name <span class="form-required">*</span></label>
                    <input type="text" class="form-input" id="last_name" name="last_name" required>
                </div>
                
                <div class="form-group">
                    <label for="source" class="form-label">Source <span class="form-required">*</span></label>
                    <select class="form-select" id="source" name="source" required>
                        <option value="">Select source</option>
                        <option value="booking">Booking</option>
                        <option value="invitation">Invitation</option>
                        <option value="referral">Referral</option>
                        <option value="direct">Direct</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="source_details" class="form-label">Source Details</label>
                    <input type="text" class="form-input" id="source_details" name="source_details">
                    <small class="form-text">Additional details about how the client found you.</small>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="secondary-btn" id="step2Back">Back</button>
                    <button type="button" class="primary-btn" id="step2Next">Next</button>
                </div>
            </form>
        </div>
        
        <!-- Step 3: Team Member Assignment -->
        <?php if ($user['entity_type'] == 'company'): ?>
        <div class="modal-step" id="step3" style="display: none;">
            <h2 class="modal-title">Add New Client</h2>
            <p class="modal-subtitle">Step 3: Team Member Assignment</p>
            
            <?php if (count($team_members) > 0): ?>
                <div class="form-group">
                    <label for="team_member_id" class="form-label">Assign Team Member</label>
                    <select class="form-select" id="team_member_id" name="team_member_id">
                        <option value="">Select team member</option>
                        <?php foreach ($team_members as $member): ?>
                        <option value="<?php echo $member['id']; ?>"><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name'] . ' (' . $member['role_name'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-text">Assigning a team member grants them access to this client's information.</small>
                </div>
            <?php else: ?>
                <div class="team-empty-message">
                    <i class="fas fa-info-circle"></i>
                    <p>You don't have any team members yet. The client will be assigned to you by default.</p>
                </div>
            <?php endif; ?>
            
            <div class="form-group">
                <label for="notes" class="form-label">Notes</label>
                <textarea class="form-textarea" id="notes" name="notes" rows="3"></textarea>
                <small class="form-text">Internal notes about this client. The client will not see these notes.</small>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="secondary-btn" id="step3Back">Back</button>
                <button type="button" class="primary-btn" id="step3Next">Next</button>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Step 4: Review & Submit -->
        <div class="modal-step" id="step4" style="display: none;">
            <h2 class="modal-title">Add New Client</h2>
            <p class="modal-subtitle">Step 4: Review & Submit</p>
            
            <div class="review-section">
                <h3 class="review-heading">Client Information</h3>
                
                <div class="review-item">
                    <div class="review-label">User Type:</div>
                    <div class="review-value" id="review-user-type"></div>
                </div>
                
                <div class="review-item">
                    <div class="review-label">Email:</div>
                    <div class="review-value" id="review-email"></div>
                </div>
                
                <div class="review-item">
                    <div class="review-label">Name:</div>
                    <div class="review-value" id="review-name"></div>
                </div>
                
                <div class="review-item">
                    <div class="review-label">Source:</div>
                    <div class="review-value" id="review-source"></div>
                </div>
                
                <div class="review-item" id="review-source-details-container">
                    <div class="review-label">Source Details:</div>
                    <div class="review-value" id="review-source-details"></div>
                </div>
                
                <div class="review-item" id="review-team-member-container">
                    <div class="review-label">Assigned To:</div>
                    <div class="review-value" id="review-team-member"></div>
                </div>
                
                <div class="review-item" id="review-notes-container">
                    <div class="review-label">Notes:</div>
                    <div class="review-value" id="review-notes"></div>
                </div>
                
                <div class="review-notice">
                    <i class="fas fa-info-circle"></i>
                    <p id="review-process-message"></p>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="secondary-btn" id="step4Back">Back</button>
                <button type="button" class="primary-btn" id="submitClient">Submit</button>
            </div>
        </div>
        
        <!-- Success Confirmation -->
        <div class="modal-step" id="stepSuccess" style="display: none;">
            <div class="success-message">
                <i class="fas fa-check-circle"></i>
                <h2>Client Added Successfully!</h2>
                <p id="success-details"></p>
                <button class="primary-btn" id="closeSuccessBtn">Done</button>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript for client interactions -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Get the professional entity type for conditional logic
    const isCompanyProfessional = <?php echo json_encode($user['entity_type'] === 'company'); ?>;
    
    // Modal functionality
    const modal = document.getElementById('addClientModal');
    const addClientBtn = document.getElementById('addClientBtn');
    const emptyAddClientBtn = document.getElementById('emptyAddClientBtn');
    const closeModalBtn = modal.querySelector('.close-modal');
    const cancelBtns = modal.querySelectorAll('.modal-cancel');
    
    // Reference all steps
    const step1 = document.getElementById('step1');
    const step2 = document.getElementById('step2');
    const step3 = document.getElementById('step3');
    const step4 = document.getElementById('step4');
    
    // Buttons for navigation
    const step1Next = document.getElementById('step1Next');
    const step2Back = document.getElementById('step2Back');
    const step2Next = document.getElementById('step2Next');
    const step3Back = document.getElementById('step3Back');
    const step3Next = document.getElementById('step3Next');
    const step4Back = document.getElementById('step4Back');
    
    // Function to open modal
    function openModal() {
        modal.style.display = 'block';
        document.body.classList.add('modal-open');
    }
    
    // Function to close modal
    function closeModal() {
        modal.style.display = 'none';
        document.body.classList.remove('modal-open');
        resetModal();
    }
    
    function resetModal() {
        // Reset all steps to initial state
        document.querySelectorAll('.modal-step').forEach(step => {
            step.style.display = 'none';
        });
        step1.style.display = 'block';
        
        // Clear form values
        document.getElementById('clientInfoForm').reset();
        if (document.getElementById('notes')) {
            document.getElementById('notes').value = '';
        }
        if (document.getElementById('team_member_id')) {
            document.getElementById('team_member_id').value = '';
        }
        
        // Clear selections
        document.querySelectorAll('.user-type-option').forEach(option => {
            option.classList.remove('selected');
        });
        
        step1Next.disabled = true;
    }
    
    // Event listeners for opening/closing the modal
    if (addClientBtn) addClientBtn.addEventListener('click', openModal);
    if (emptyAddClientBtn) emptyAddClientBtn.addEventListener('click', openModal);
    closeModalBtn.addEventListener('click', closeModal);
    cancelBtns.forEach(btn => {
        btn.addEventListener('click', closeModal);
    });
    
    // Step 1: User Type Selection
    const userTypeOptions = document.querySelectorAll('.user-type-option');
    userTypeOptions.forEach(option => {
        option.addEventListener('click', function() {
            userTypeOptions.forEach(opt => opt.classList.remove('selected'));
            this.classList.add('selected');
            step1Next.disabled = false;
            
            // Set hidden input value based on selection
            document.getElementById('is_existing_user').value = this.dataset.type === 'existing' ? '1' : '0';
            
            // Update email help text based on selection
            const emailHelp = document.getElementById('emailHelp');
            if (this.dataset.type === 'existing') {
                emailHelp.textContent = 'Enter the email of an existing Visafy user.';
            } else {
                emailHelp.textContent = 'An invitation will be sent to this email to create an account.';
            }
        });
    });
    
    // Navigation between steps with conditional logic for company vs individual
    step1Next.addEventListener('click', function() {
        step1.style.display = 'none';
        step2.style.display = 'block';
    });
    
    step2Back.addEventListener('click', function() {
        step2.style.display = 'none';
        step1.style.display = 'block';
    });
    
    step2Next.addEventListener('click', function() {
        // Validate form fields
        const form = document.getElementById('clientInfoForm');
        const requiredFields = form.querySelectorAll('[required]');
        let isValid = true;
        
        requiredFields.forEach(field => {
            if (!field.value.trim()) {
                isValid = false;
                field.classList.add('is-invalid');
            } else {
                field.classList.remove('is-invalid');
            }
        });
        
        const emailField = document.getElementById('email');
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(emailField.value.trim())) {
            isValid = false;
            emailField.classList.add('is-invalid');
        }
        
        if (!isValid) {
            alert('Please fill in all required fields correctly.');
            return;
        }
        
        // Skip team member assignment step for individual professionals
        if (isCompanyProfessional) {
            // For company professionals, go to team member assignment
            step2.style.display = 'none';
            step3.style.display = 'block';
        } else {
            // For individual professionals, skip to review
            step2.style.display = 'none';
            step4.style.display = 'block';
            prepareReviewInfo();
        }
    });
    
    // Only set up these event listeners if the elements exist (for company professionals)
    if (step3Back) {
        step3Back.addEventListener('click', function() {
            step3.style.display = 'none';
            step2.style.display = 'block';
        });
    }
    
    if (step3Next) {
        step3Next.addEventListener('click', function() {
            step3.style.display = 'none';
            step4.style.display = 'block';
            prepareReviewInfo();
        });
    }
    
    step4Back.addEventListener('click', function() {
        step4.style.display = 'none';
        // Go back to the appropriate step based on professional type
        if (isCompanyProfessional) {
            step3.style.display = 'block';
        } else {
            step2.style.display = 'block';
        }
    });
    
    // Function to prepare review info
    function prepareReviewInfo() {
        const isExisting = document.getElementById('is_existing_user').value === '1';
        const email = document.getElementById('email').value;
        const firstName = document.getElementById('first_name').value;
        const lastName = document.getElementById('last_name').value;
        const source = document.getElementById('source');
        const sourceText = source.options[source.selectedIndex].text;
        const sourceDetails = document.getElementById('source_details').value;
        
        // Set review values
        document.getElementById('review-user-type').textContent = isExisting ? 'Existing User' : 'New User';
        document.getElementById('review-email').textContent = email;
        document.getElementById('review-name').textContent = firstName + ' ' + lastName;
        document.getElementById('review-source').textContent = sourceText;
        
        if (sourceDetails.trim()) {
            document.getElementById('review-source-details').textContent = sourceDetails;
            document.getElementById('review-source-details-container').style.display = 'flex';
        } else {
            document.getElementById('review-source-details-container').style.display = 'none';
        }
        
        // Only show team member info for company professionals
        if (isCompanyProfessional) {
            const notes = document.getElementById('notes').value;
            const teamMemberSelect = document.getElementById('team_member_id');
            
            if (teamMemberSelect && teamMemberSelect.selectedIndex > 0) {
                const selectedTeamMember = teamMemberSelect.options[teamMemberSelect.selectedIndex].text;
                document.getElementById('review-team-member').textContent = selectedTeamMember;
                document.getElementById('review-team-member-container').style.display = 'flex';
            } else {
                document.getElementById('review-team-member').textContent = 'Not assigned';
                document.getElementById('review-team-member-container').style.display = 'flex';
            }
            
            if (notes && notes.trim()) {
                document.getElementById('review-notes').textContent = notes;
                document.getElementById('review-notes-container').style.display = 'flex';
            } else {
                document.getElementById('review-notes-container').style.display = 'none';
            }
        } else {
            // Hide team member fields for individual professionals
            document.getElementById('review-team-member-container').style.display = 'none';
            document.getElementById('review-notes-container').style.display = 'none';
        }
        
        // Set process message based on user type
        if (isExisting) {
            document.getElementById('review-process-message').textContent = 
                'An invitation will be sent to the existing user. They will need to accept your invitation to become your client.';
        } else {
            document.getElementById('review-process-message').textContent = 
                'An email will be sent to this address with a link to create a new account. Once they verify their email and set up their account, they will be connected as your client.';
        }
    }
    
    // Form submission handling
    const submitClientBtn = document.getElementById('submitClient');
    submitClientBtn.addEventListener('click', function() {
        // Create form data from collected information
        const formData = new FormData();
        formData.append('action', 'add_client');
        formData.append('email', document.getElementById('email').value);
        formData.append('first_name', document.getElementById('first_name').value);
        formData.append('last_name', document.getElementById('last_name').value);
        formData.append('source', document.getElementById('source').value);
        formData.append('source_details', document.getElementById('source_details').value);
        formData.append('is_existing_user', document.getElementById('is_existing_user').value);
        
        // Only add team member and notes for company professionals
        if (isCompanyProfessional) {
            formData.append('notes', document.getElementById('notes').value);
            
            const teamMemberId = document.getElementById('team_member_id');
            if (teamMemberId && teamMemberId.selectedIndex > 0) {
                formData.append('team_member_id', teamMemberId.value);
            }
        }
        
        // Disable submit button to prevent double submission
        submitClientBtn.disabled = true;
        submitClientBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        
        // Submit form via AJAX
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(html => {
            // Handle response (show success or redirect)
            window.location.reload();
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while adding the client. Please try again.');
            submitClientBtn.disabled = false;
            submitClientBtn.innerHTML = 'Submit';
        });
    });
    
    // Client search functionality
    const searchInput = document.getElementById('clientSearch');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const clientCards = document.querySelectorAll('.client-card');
            
            clientCards.forEach(card => {
                const clientEmail = card.querySelector('.client-email').textContent.toLowerCase();
                const isVisible = clientEmail.includes(searchTerm);
                card.style.display = isVisible ? 'block' : 'none';
            });
        });
    }
    
    // Filter functionality
    const filterCheckboxes = document.querySelectorAll('.filter-item input[type="checkbox"]');
    filterCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateFilters);
    });
    
    function updateFilters() {
        const activeStatuses = Array.from(filterCheckboxes)
            .filter(checkbox => checkbox.checked)
            .map(checkbox => checkbox.id);
        
        const clientCards = document.querySelectorAll('.client-card');
        
        clientCards.forEach(card => {
            const status = card.getAttribute('data-status');
            const isVisible = activeStatuses.includes(status);
            card.style.display = isVisible ? 'block' : 'none';
        });
    }
    
    // Archive client functionality
    const archiveButtons = document.querySelectorAll('.archive-client');
    archiveButtons.forEach(button => {
        button.addEventListener('click', function() {
            const clientId = this.getAttribute('data-id');
            
            if (confirm('Are you sure you want to archive this client?')) {
                // AJAX request to archive client
                fetch('ajax/update_client_status.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'client_id=' + clientId + '&status=archived'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while archiving the client.');
                });
            }
        });
    });
    
    // Restore client functionality
    const restoreButtons = document.querySelectorAll('.restore-client');
    restoreButtons.forEach(button => {
        button.addEventListener('click', function() {
            const clientId = this.getAttribute('data-id');
            
            if (confirm('Are you sure you want to restore this client?')) {
                // AJAX request to restore client
                fetch('ajax/update_client_status.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'client_id=' + clientId + '&status=active'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while restoring the client.');
                });
            }
        });
    });
    
    // Close alert messages when clicking the dismiss button
    const alertDismissButtons = document.querySelectorAll('.alert-dismiss');
    alertDismissButtons.forEach(button => {
        button.addEventListener('click', function() {
            this.parentElement.style.display = 'none';
        });
    });
});
</script>

<?php
// Include footer
require_once 'includes/footer.php';
?>
