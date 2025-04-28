<?php
// Set page title
$page_title = "Visa Applications";

// Include header
require_once 'includes/header.php';

// Check if entity is a professional entity
$entity_id = $user['id'];

// Process form submission
$error_msg = '';
$success_msg = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'submit_application') {
    // Validate and sanitize input
    $email = filter_var(trim($_POST['email']), FILTER_VALIDATE_EMAIL);
    $first_name = isset($_POST['first_name']) ? trim($_POST['first_name']) : '';
    $last_name = isset($_POST['last_name']) ? trim($_POST['last_name']) : '';
    $country_id = (int)$_POST['country_id'];
    $visa_type_id = (int)$_POST['visa_type_id'];
    $team_member_id = !empty($_POST['team_member_id']) ? (int)$_POST['team_member_id'] : null;
    $is_existing_user = isset($_POST['is_existing_user']) ? (int)$_POST['is_existing_user'] : 0;
    $notes = trim($_POST['notes'] ?? '');
    
    // Validate required fields with specific error messages
    $errors = [];
    if (empty($email)) $errors[] = "Email address is required";
    if (empty($first_name)) $errors[] = "First name is required";
    if (empty($last_name)) $errors[] = "Last name is required";
    if (empty($country_id)) $errors[] = "Country is required";
    if (empty($visa_type_id)) $errors[] = "Visa type is required";
    
    if (!empty($errors)) {
        $error_msg = implode("<br>", $errors);
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
                $user_id = null;
                
                if ($existing_user) {
                    // Use existing user
                    $user_id = $existing_user['id'];
                    
                    // Check if user is already a client of this professional
                    $stmt = $conn->prepare("
                        SELECT id FROM professional_clients 
                        WHERE professional_entity_id = ? AND applicant_id = ? AND deleted_at IS NULL
                    ");
                    $stmt->bind_param("ii", $entity_id, $user_id);
                    $stmt->execute();
                    $client_result = $stmt->get_result();
                    
                    if ($client_result->num_rows == 0) {
                        // Add user as a client to this professional
                        $stmt = $conn->prepare("
                            INSERT INTO professional_clients (professional_entity_id, applicant_id, created_at) 
                            VALUES (?, ?, NOW())
                        ");
                        $stmt->bind_param("ii", $entity_id, $user_id);
                        $stmt->execute();
                    }
                } else {
                    // Create new user
                    $temp_password = bin2hex(random_bytes(8)); // Generate random password
                    $hashed_password = password_hash($temp_password, PASSWORD_DEFAULT);
                    $verification_token = bin2hex(random_bytes(32));
                    $verification_expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
                    
                    $stmt = $conn->prepare("
                        INSERT INTO users (email, password, role, email_verification_token, email_verification_expires, created_at) 
                        VALUES (?, ?, 'applicant', ?, ?, NOW())
                    ");
                    $stmt->bind_param("ssss", $email, $hashed_password, $verification_token, $verification_expires);
                    $stmt->execute();
                    $user_id = $conn->insert_id;
                    
                    // Create applicant profile
                    $stmt = $conn->prepare("
                        INSERT INTO applicant_profiles (user_id, first_name, last_name, created_at) 
                        VALUES (?, ?, ?, NOW())
                    ");
                    $stmt->bind_param("iss", $user_id, $first_name, $last_name);
                    $stmt->execute();
                    
                    // Add user as a client to this professional
                    $stmt = $conn->prepare("
                        INSERT INTO professional_clients (professional_entity_id, applicant_id, created_at) 
                        VALUES (?, ?, NOW())
                    ");
                    $stmt->bind_param("ii", $entity_id, $user_id);
                    $stmt->execute();
                }
                
                // Get professional service ID
                $stmt = $conn->prepare("
                    SELECT id FROM professional_visa_services 
                    WHERE entity_id = ? AND country_id = ? AND visa_type_id = ? AND is_active = 1
                ");
                $stmt->bind_param("iii", $entity_id, $country_id, $visa_type_id);
                $stmt->execute();
                $service_result = $stmt->get_result();
                
                if ($service_result->num_rows === 0) {
                    throw new Exception("No active service found for the selected country and visa type");
                }
                
                $service = $service_result->fetch_assoc();
                $professional_service_id = $service['id'];
                
                // Create visa application
                $stmt = $conn->prepare("
                    INSERT INTO visa_applications (
                        user_id, professional_service_id, status, notes, created_at
                    ) VALUES (?, ?, 'pending', ?, NOW())
                ");
                $stmt->bind_param("iis", $user_id, $professional_service_id, $notes);
                $stmt->execute();
                $application_id = $conn->insert_id;
                
                // Generate invitation link
                $invitation_token = bin2hex(random_bytes(32));
                $invitation_expires = date('Y-m-d H:i:s', strtotime('+7 days'));
                
                $stmt = $conn->prepare("
                    INSERT INTO application_invitations (
                        application_id, token, expires_at, created_at
                    ) VALUES (?, ?, ?, NOW())
                ");
                $stmt->bind_param("iss", $application_id, $invitation_token, $invitation_expires);
                $stmt->execute();
                
                // Send invitation email
                $invitation_link = "https://" . $_SERVER['HTTP_HOST'] . "/client_accept.php?token=" . $invitation_token;
                
                // For existing users, send application details email
                if ($existing_user) {
                    // Get visa type details
                    $stmt = $conn->prepare("SELECT name FROM visa_types WHERE id = ?");
                    $stmt->bind_param("i", $visa_type_id);
                    $stmt->execute();
                    $visa_result = $stmt->get_result();
                    $visa_type = $visa_result->fetch_assoc();
                    
                    // Get country details
                    $stmt = $conn->prepare("SELECT name FROM countries WHERE id = ?");
                    $stmt->bind_param("i", $country_id);
                    $stmt->execute();
                    $country_result = $stmt->get_result();
                    $country = $country_result->fetch_assoc();
                    
                    // TODO: Send email with application details and invitation link
                    // This would be implemented with your email sending system
                } else {
                    // For new users, send invitation email with password setup link
                    // TODO: Send email with invitation link
                    // This would be implemented with your email sending system
                }
                
                // After successful application creation, send email with required documents
                $stmt = $conn->prepare("
                    SELECT d.name as doc_name, d.description as doc_description, d.is_required 
                    FROM visa_type_documents vtd 
                    JOIN documents d ON vtd.document_id = d.id 
                    WHERE vtd.visa_type_id = ?
                ");
                $stmt->bind_param("i", $visa_type_id);
                $stmt->execute();
                $documents_result = $stmt->get_result();
                
                $required_documents = [];
                while ($doc = $documents_result->fetch_assoc()) {
                    $required_documents[] = $doc;
                }
                
                // Send email with application details and required documents
                $to = $email;
                $subject = "Your Visa Application Details";
                $message = "Dear $first_name $last_name,\n\n";
                $message .= "Your visa application has been submitted successfully. Here are the required documents:\n\n";
                
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
                
                mail($to, $subject, $message, $headers);
                
                $conn->commit();
                $success_msg = "Visa application has been created successfully. An email has been sent to the client with required documents.";
            }
        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = "An error occurred: " . $e->getMessage();
            error_log("Application creation error: " . $e->getMessage());
        }
    }
}

// Fetch countries for dropdown
$countries = [];
$stmt = $conn->prepare("SELECT id, name FROM countries WHERE is_active = 1 ORDER BY name");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $countries[] = $row;
}
$stmt->close();

// Fetch team members if entity is a company
$team_members = [];
if ($user['entity_type'] == 'company') {
    $stmt = $conn->prepare("SELECT id, first_name, last_name FROM team_members WHERE company_id = ? AND is_active = 1 AND deleted_at IS NULL");
    $stmt->bind_param("i", $entity_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $team_members[] = $row;
    }
    $stmt->close();
}

// Fetch existing applications
$applications = [];
$stmt = $conn->prepare("
    SELECT 
        va.id, va.status, va.created_at, 
        COALESCE(va.invitation_status, 'pending') as invitation_status,
        u.email,
        CASE 
            WHEN ap.id IS NOT NULL THEN ap.first_name
            ELSE u.email
        END as first_name,
        CASE 
            WHEN ap.id IS NOT NULL THEN ap.last_name
            ELSE ''
        END as last_name,
        c.name as country_name,
        vt.name as visa_type_name,
        tm.first_name as tm_first_name, tm.last_name as tm_last_name
    FROM visa_applications va
    JOIN users u ON va.user_id = u.id
    LEFT JOIN applicant_profiles ap ON u.id = ap.user_id
    JOIN professional_visa_services pvs ON va.professional_service_id = pvs.id
    JOIN countries c ON pvs.country_id = c.id
    JOIN visa_types vt ON pvs.visa_type_id = vt.id
    LEFT JOIN application_team_members atm ON va.id = atm.application_id
    LEFT JOIN team_members tm ON atm.team_member_id = tm.id
    WHERE pvs.entity_id = ?
    ORDER BY va.created_at DESC
");
$stmt->bind_param("i", $entity_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $applications[] = $row;
}
$stmt->close();
?>

<!-- Page Content -->
<div class="content-section">
    <div class="content-header">
        <h1 class="page-title">Visa Applications</h1>
        <link rel="stylesheet" href="css/applications.css">
    </div>
    
    <div class="row">
        <div class="col-12">
            <?php if ($error_msg): ?>
                <div class="alert alert-danger alert-dismiss">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error_msg; ?>
                    <button type="button" class="close-alert"><i class="fas fa-times"></i></button>
                </div>
            <?php endif; ?>
            
            <?php if ($success_msg): ?>
                <div class="alert alert-success alert-dismiss">
                    <i class="fas fa-check-circle"></i> <?php echo $success_msg; ?>
                    <button type="button" class="close-alert"><i class="fas fa-times"></i></button>
                </div>
            <?php endif; ?>
            
            <div class="applications-card">
                <div class="applications-card-header">
                    <i class="fas fa-file-alt"></i>
                    <h2 class="applications-card-title">New Visa Application</h2>
                    <div class="header-actions">
                        <button type="button" class="primary-btn" id="startApplicationBtn">
                            <i class="fas fa-plus"></i> Start New Application
                        </button>
                    </div>
                </div>
                
                <div class="applications-card-body">
                    <div class="applications-empty-state">
                        <i class="fas fa-file-alt"></i>
                        <h5>No Active Applications</h5>
                        <p>Start a new visa application for your client by clicking the button above.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Applications List -->
<div class="applications-list">
    <?php if (empty($applications)): ?>
        <div class="applications-empty-state">
            <i class="fas fa-file-alt"></i>
            <h5>No Active Applications</h5>
            <p>Start a new visa application for your client by clicking the button above.</p>
        </div>
    <?php else: ?>
        <?php foreach ($applications as $app): ?>
            <div class="application-card">
                <div class="application-card-header">
                    <div class="application-status <?php echo strtolower($app['status']); ?>">
                        <?php echo htmlspecialchars($app['status']); ?>
                    </div>
                    <div class="application-date">
                        <?php echo date('M d, Y', strtotime($app['created_at'])); ?>
                    </div>
                </div>
                <div class="application-card-body">
                    <h3 class="application-title">
                        <?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name']); ?>
                    </h3>
                    <div class="application-details">
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($app['email']); ?></p>
                        <p><strong>Country:</strong> <?php echo htmlspecialchars($app['country_name']); ?></p>
                        <p><strong>Visa Type:</strong> <?php echo htmlspecialchars($app['visa_type_name']); ?></p>
                        <?php if ($app['tm_first_name']): ?>
                            <p><strong>Team Member:</strong> <?php echo htmlspecialchars($app['tm_first_name'] . ' ' . $app['tm_last_name']); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="application-actions">
                        <button type="button" class="btn btn-primary resend-invitation" data-application-id="<?php echo $app['id']; ?>">
                            <i class="fas fa-paper-plane"></i> Resend Invitation
                        </button>
                        <a href="application_details.php?id=<?php echo $app['id']; ?>" class="btn btn-secondary">
                            <i class="fas fa-eye"></i> View Details
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Application Modal -->
<div class="modal" id="applicationModal">
    <div class="modal-content">
        <button type="button" class="close-modal"><i class="fas fa-times"></i></button>
        
        <form id="applicationForm" method="post" action="">
            <input type="hidden" name="action" value="submit_application">
            
            <!-- Step 1: Choose User Type -->
            <div class="modal-step" id="step1">
                <h3 class="modal-title">Step 1: Choose User Type</h3>
                <p class="modal-subtitle">Select whether the client is an existing Visafy user or a new user.</p>
                
                <div class="user-type-selection">
                    <div class="user-type-option" data-type="existing">
                        <i class="fas fa-user"></i>
                        <h3>Existing Visafy User</h3>
                        <p>Select this if the client already has a Visafy account.</p>
                    </div>
                    <div class="user-type-option" data-type="new">
                        <i class="fas fa-user-plus"></i>
                        <h3>New User</h3>
                        <p>Select this if the client doesn't have a Visafy account yet.</p>
                    </div>
                </div>
                
                <input type="hidden" name="is_existing_user" id="isExistingUser" value="0">
                
                <div class="modal-footer">
                    <button type="button" class="secondary-btn" id="cancelBtn">Cancel</button>
                    <button type="button" class="primary-btn" id="nextToStep2Btn" disabled>Next <i class="fas fa-arrow-right"></i></button>
                </div>
            </div>
            
            <!-- Step 2: Enter Email -->
            <div class="modal-step" id="step2" style="display: none;">
                <h3 class="modal-title">Step 2: Enter Client Email</h3>
                <p class="modal-subtitle">Enter the client's email address.</p>
                
                <div class="form-group">
                    <label for="email" class="form-label">Client Email</label>
                    <input type="email" id="email" name="email" class="form-input" required>
                    <div class="invalid-feedback">Please enter a valid email address.</div>
                </div>

                <div class="form-group">
                    <label for="first_name" class="form-label">First Name</label>
                    <input type="text" id="first_name" name="first_name" class="form-input" required>
                    <div class="invalid-feedback">Please enter the first name.</div>
                </div>

                <div class="form-group">
                    <label for="last_name" class="form-label">Last Name</label>
                    <input type="text" id="last_name" name="last_name" class="form-input" required>
                    <div class="invalid-feedback">Please enter the last name.</div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="secondary-btn" id="backToStep1Btn"><i class="fas fa-arrow-left"></i> Back</button>
                    <button type="button" class="primary-btn" id="nextToStep3Btn">Next <i class="fas fa-arrow-right"></i></button>
                </div>
            </div>
            
            <!-- Step 3: Choose Country -->
            <div class="modal-step" id="step3" style="display: none;">
                <h3 class="modal-title">Step 3: Choose Country</h3>
                <p class="modal-subtitle">Select the country for the visa application.</p>
                
                <div class="form-group">
                    <label for="country_id" class="form-label">Country <span class="form-required">*</span></label>
                    <select id="country_id" name="country_id" class="form-select" required>
                        <option value="">Select a country</option>
                        <?php foreach ($countries as $country): ?>
                            <option value="<?php echo $country['id']; ?>"><?php echo htmlspecialchars($country['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="invalid-feedback">Please select a country.</div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="secondary-btn" id="backToStep2Btn"><i class="fas fa-arrow-left"></i> Back</button>
                    <button type="button" class="primary-btn" id="nextToStep4Btn">Next <i class="fas fa-arrow-right"></i></button>
                </div>
            </div>
            
            <!-- Step 4: Choose Visa Type -->
            <div class="modal-step" id="step4" style="display: none;">
                <h3 class="modal-title">Step 4: Choose Visa Type</h3>
                <p class="modal-subtitle">Select the visa type for the application.</p>
                
                <div class="form-group">
                    <label for="visa_type_id" class="form-label">Visa Type <span class="form-required">*</span></label>
                    <select id="visa_type_id" name="visa_type_id" class="form-select" required>
                        <option value="">Select a visa type</option>
                    </select>
                    <div class="invalid-feedback">Please select a visa type.</div>
                    <div id="visaTypeLoading" style="display: none;">
                        <i class="fas fa-spinner fa-spin"></i> Loading visa types...
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="secondary-btn" id="backToStep3Btn"><i class="fas fa-arrow-left"></i> Back</button>
                    <button type="button" class="primary-btn" id="nextToStep5Btn">Next <i class="fas fa-arrow-right"></i></button>
                </div>
            </div>
            
            <!-- Step 5: Review Required Documents -->
            <div class="modal-step" id="step5" style="display: none;">
                <h3 class="modal-title">Step 5: Review Required Documents</h3>
                <p class="modal-subtitle">Review the documents required for this visa application.</p>
                
                <div id="documentsContainer">
                    <div class="documents-loading">
                        <i class="fas fa-spinner fa-spin"></i> Loading required documents...
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="secondary-btn" id="backToStep4Btn"><i class="fas fa-arrow-left"></i> Back</button>
                    <button type="button" class="primary-btn" id="nextToStep6Btn">Next <i class="fas fa-arrow-right"></i></button>
                </div>
            </div>
            
            <!-- Step 6: Select Team Member (for company professionals only) -->
            <div class="modal-step" id="step6" style="display: none;">
                <h3 class="modal-title">Step 6: Select Team Member</h3>
                <p class="modal-subtitle">Assign a team member to handle this application.</p>
                
                <?php if (count($team_members) > 0): ?>
                    <div class="form-group">
                        <label for="team_member_id" class="form-label">Team Member</label>
                        <select id="team_member_id" name="team_member_id" class="form-select">
                            <option value="">Select a team member (optional)</option>
                            <?php foreach ($team_members as $member): ?>
                                <option value="<?php echo $member['id']; ?>"><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php else: ?>
                    <div class="team-empty-message">
                        <i class="fas fa-users"></i>
                        <p>No team members available. You can add team members in the Members section.</p>
                    </div>
                <?php endif; ?>
                
                <div class="modal-footer">
                    <button type="button" class="secondary-btn" id="backToStep5Btn"><i class="fas fa-arrow-left"></i> Back</button>
                    <button type="button" class="primary-btn" id="nextToStep7Btn">Next <i class="fas fa-arrow-right"></i></button>
                </div>
            </div>
            
            <!-- Step 7: Review and Submit -->
            <div class="modal-step" id="step7" style="display: none;">
                <h3 class="modal-title">Step 7: Review and Submit</h3>
                <p class="modal-subtitle">Review the application details before submitting.</p>
                
                <div class="review-section">
                    <h4 class="review-heading">Application Details</h4>
                    
                    <div class="review-item">
                        <div class="review-label">Client Email:</div>
                        <div class="review-value" id="reviewEmail"></div>
                    </div>
                    
                    <div class="review-item">
                        <div class="review-label">Country:</div>
                        <div class="review-value" id="reviewCountry"></div>
                    </div>
                    
                    <div class="review-item">
                        <div class="review-label">Visa Type:</div>
                        <div class="review-value" id="reviewVisaType"></div>
                    </div>
                    
                    <div class="review-item">
                        <div class="review-label">Team Member:</div>
                        <div class="review-value" id="reviewTeamMember"></div>
                    </div>
                    
                    <div class="form-group">
                        <label for="notes" class="form-label">Additional Notes</label>
                        <textarea id="notes" name="notes" class="form-textarea" rows="3"></textarea>
                    </div>
                    
                    <div class="review-notice">
                        <i class="fas fa-info-circle"></i>
                        <p>By submitting this application, an invitation will be sent to the client's email address. The client will need to accept the invitation to proceed with the application.</p>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="secondary-btn" id="backToStep6Btn"><i class="fas fa-arrow-left"></i> Back</button>
                    <button type="submit" class="primary-btn">Submit Application <i class="fas fa-check"></i></button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Modal elements
    const modal = document.getElementById('applicationModal');
    const startApplicationBtn = document.getElementById('startApplicationBtn');
    const closeModalBtn = document.querySelector('.close-modal');
    const cancelBtn = document.getElementById('cancelBtn');
    const closeModalBtns = document.querySelectorAll('.close-modal');
    
    // Form elements
    const applicationForm = document.getElementById('applicationForm');
    const isExistingUserInput = document.getElementById('isExistingUser');
    const emailInput = document.getElementById('email');
    const countrySelect = document.getElementById('country_id');
    const visaTypeSelect = document.getElementById('visa_type_id');
    const visaTypeLoading = document.getElementById('visaTypeLoading');
    const teamMemberSelect = document.getElementById('team_member_id');
    const notesTextarea = document.getElementById('notes');
    
    // Review elements
    const reviewEmail = document.getElementById('reviewEmail');
    const reviewCountry = document.getElementById('reviewCountry');
    const reviewVisaType = document.getElementById('reviewVisaType');
    const reviewTeamMember = document.getElementById('reviewTeamMember');
    
    // User type selection
    const userTypeOptions = document.querySelectorAll('.user-type-option');
    const nextToStep2Btn = document.getElementById('nextToStep2Btn');
    
    // Step navigation buttons
    const nextToStep3Btn = document.getElementById('nextToStep3Btn');
    const nextToStep4Btn = document.getElementById('nextToStep4Btn');
    const nextToStep5Btn = document.getElementById('nextToStep5Btn');
    const nextToStep6Btn = document.getElementById('nextToStep6Btn');
    const nextToStep7Btn = document.getElementById('nextToStep7Btn');
    
    const backToStep1Btn = document.getElementById('backToStep1Btn');
    const backToStep2Btn = document.getElementById('backToStep2Btn');
    const backToStep3Btn = document.getElementById('backToStep3Btn');
    const backToStep4Btn = document.getElementById('backToStep4Btn');
    const backToStep5Btn = document.getElementById('backToStep5Btn');
    const backToStep6Btn = document.getElementById('backToStep6Btn');
    
    // Open modal
    startApplicationBtn.addEventListener('click', function() {
        modal.style.display = 'flex';
        document.body.classList.add('modal-open');
    });
    
    // Close modal
    function closeModal() {
        modal.style.display = 'none';
        document.body.classList.remove('modal-open');
        resetForm();
    }
    
    closeModalBtn.addEventListener('click', closeModal);
    cancelBtn.addEventListener('click', closeModal);
    closeModalBtns.forEach(btn => {
        btn.addEventListener('click', closeModal);
    });
    
    // Reset form
    function resetForm() {
        applicationForm.reset();
        userTypeOptions.forEach(option => option.classList.remove('selected'));
        isExistingUserInput.value = '0';
        nextToStep2Btn.disabled = true;
        
        // Hide all steps except step 1
        document.querySelectorAll('.modal-step').forEach(step => {
            step.style.display = 'none';
        });
        document.getElementById('step1').style.display = 'block';
    }
    
    // User type selection
    userTypeOptions.forEach(option => {
        option.addEventListener('click', function() {
            userTypeOptions.forEach(opt => opt.classList.remove('selected'));
            this.classList.add('selected');
            isExistingUserInput.value = this.dataset.type === 'existing' ? '1' : '0';
            nextToStep2Btn.disabled = false;
        });
    });
    
    // Step navigation
    nextToStep2Btn.addEventListener('click', function() {
        document.getElementById('step1').style.display = 'none';
        document.getElementById('step2').style.display = 'block';
    });
    
    backToStep1Btn.addEventListener('click', function() {
        document.getElementById('step2').style.display = 'none';
        document.getElementById('step1').style.display = 'block';
    });
    
    // Form validation
    function validateField(field) {
        const value = field.value.trim();
        const isValid = value !== '';
        const feedback = field.parentElement.querySelector('.invalid-feedback');
        
        if (!isValid) {
            field.classList.add('is-invalid');
            if (feedback) feedback.style.display = 'block';
        } else {
            field.classList.remove('is-invalid');
            if (feedback) feedback.style.display = 'none';
        }
        
        return isValid;
    }
    
    // Email validation
    function validateEmail(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        const isValid = emailRegex.test(email.value.trim());
        const feedback = email.parentElement.querySelector('.invalid-feedback');
        
        if (!isValid) {
            email.classList.add('is-invalid');
            if (feedback) feedback.style.display = 'block';
        } else {
            email.classList.remove('is-invalid');
            if (feedback) feedback.style.display = 'none';
        }
        
        return isValid;
    }
    
    // Update validation for next buttons
    nextToStep3Btn.addEventListener('click', function() {
        const isEmailValid = validateEmail(emailInput);
        const isFirstNameValid = validateField(document.getElementById('first_name'));
        const isLastNameValid = validateField(document.getElementById('last_name'));
        
        if (isEmailValid && isFirstNameValid && isLastNameValid) {
            document.getElementById('step2').style.display = 'none';
            document.getElementById('step3').style.display = 'block';
        }
    });
    
    nextToStep4Btn.addEventListener('click', function() {
        if (validateField(countrySelect)) {
            document.getElementById('step3').style.display = 'none';
            document.getElementById('step4').style.display = 'block';
            loadVisaTypes(countrySelect.value);
        }
    });
    
    nextToStep5Btn.addEventListener('click', function() {
        if (validateField(visaTypeSelect)) {
            document.getElementById('step4').style.display = 'none';
            document.getElementById('step5').style.display = 'block';
            loadRequiredDocuments(visaTypeSelect.value);
        }
    });
    
    // Form submission validation
    applicationForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        let isValid = true;
        
        // Validate all required fields
        if (!validateEmail(emailInput)) isValid = false;
        if (!validateField(countrySelect)) isValid = false;
        if (!validateField(visaTypeSelect)) isValid = false;
        
        if (isValid) {
            this.submit();
        }
    });
    
    // Handle resend invitation button clicks
    document.querySelectorAll('.resend-invitation').forEach(button => {
        button.addEventListener('click', function() {
            const applicationId = this.dataset.applicationId;
            if (confirm('Are you sure you want to resend the invitation?')) {
                // Send AJAX request to resend invitation
                fetch('ajax/resend_application_invitation.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'application_id=' + applicationId
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Invitation has been resent successfully.');
                    } else {
                        alert('Error resending invitation: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while resending the invitation.');
                });
            }
        });
    });
    
    // Load visa types for selected country
    function loadVisaTypes(countryId) {
        visaTypeSelect.innerHTML = '<option value="">Select a visa type</option>';
        visaTypeLoading.style.display = 'block';
        
        fetch(`ajax/get_visa_types.php?country_id=${countryId}`)
            .then(response => response.json())
            .then(data => {
                visaTypeLoading.style.display = 'none';
                
                if (data.success) {
                    data.visa_types.forEach(visaType => {
                        const option = document.createElement('option');
                        option.value = visaType.id;
                        option.textContent = visaType.name;
                        visaTypeSelect.appendChild(option);
                    });
                } else {
                    alert('Error loading visa types: ' + data.error);
                }
            })
            .catch(error => {
                visaTypeLoading.style.display = 'none';
                console.error('Error:', error);
                alert('An error occurred while loading visa types.');
            });
    }
    
    // Load required documents for selected visa type
    function loadRequiredDocuments(visaTypeId) {
        const documentsContainer = document.getElementById('documentsContainer');
        documentsContainer.innerHTML = '<div class="documents-loading"><i class="fas fa-spinner fa-spin"></i> Loading required documents...</div>';
        
        fetch(`ajax/get_documents.php?visa_type_id=${visaTypeId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    let html = '';
                    
                    if (data.categories.length === 0) {
                        html = '<div class="documents-empty">No documents required for this visa type.</div>';
                    } else {
                        data.categories.forEach(category => {
                            html += `<div class="document-category">
                                <h4 class="category-title">${category.name}</h4>
                                <ul class="document-list">`;
                            
                            category.documents.forEach(doc => {
                                html += `<li class="document-item ${doc.is_required ? 'required' : 'optional'}">
                                    <i class="fas ${doc.is_required ? 'fa-check-circle' : 'fa-circle'}"></i>
                                    <span class="document-name">${doc.name}</span>
                                    ${doc.description ? `<p class="document-description">${doc.description}</p>` : ''}
                                    ${doc.additional_requirements ? `<p class="document-additional">${doc.additional_requirements}</p>` : ''}
                                </li>`;
                            });
                            
                            html += `</ul></div>`;
                        });
                    }
                    
                    documentsContainer.innerHTML = html;
                } else {
                    documentsContainer.innerHTML = `<div class="documents-error">Error loading documents: ${data.error}</div>`;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                documentsContainer.innerHTML = '<div class="documents-error">An error occurred while loading documents.</div>';
            });
    }
    
    // Update review section
    function updateReviewSection() {
        reviewEmail.textContent = emailInput.value;
        reviewCountry.textContent = countrySelect.options[countrySelect.selectedIndex].text;
        reviewVisaType.textContent = visaTypeSelect.options[visaTypeSelect.selectedIndex].text;
        
        if (teamMemberSelect.value) {
            reviewTeamMember.textContent = teamMemberSelect.options[teamMemberSelect.selectedIndex].text;
        } else {
            reviewTeamMember.textContent = 'Not assigned';
        }
    }
    
    // Add event listeners for review section updates
    emailInput.addEventListener('input', updateReviewSection);
    countrySelect.addEventListener('change', updateReviewSection);
    visaTypeSelect.addEventListener('change', updateReviewSection);
    teamMemberSelect.addEventListener('change', updateReviewSection);
    
    // Add event listeners for step navigation
    nextToStep6Btn.addEventListener('click', function() {
        document.getElementById('step5').style.display = 'none';
        document.getElementById('step6').style.display = 'block';
    });
    
    nextToStep7Btn.addEventListener('click', function() {
        updateReviewSection();
        document.getElementById('step6').style.display = 'none';
        document.getElementById('step7').style.display = 'block';
    });
    
    backToStep2Btn.addEventListener('click', function() {
        document.getElementById('step3').style.display = 'none';
        document.getElementById('step2').style.display = 'block';
    });
    
    backToStep3Btn.addEventListener('click', function() {
        document.getElementById('step4').style.display = 'none';
        document.getElementById('step3').style.display = 'block';
    });
    
    backToStep4Btn.addEventListener('click', function() {
        document.getElementById('step5').style.display = 'none';
        document.getElementById('step4').style.display = 'block';
    });
    
    backToStep5Btn.addEventListener('click', function() {
        document.getElementById('step6').style.display = 'none';
        document.getElementById('step5').style.display = 'block';
    });
    
    backToStep6Btn.addEventListener('click', function() {
        document.getElementById('step7').style.display = 'none';
        document.getElementById('step6').style.display = 'block';
    });
});
</script>

<?php
// Include footer
require_once 'includes/footer.php';
?>
