<?php
// Set page variables
$page_title = "Profile";
$page_header = "My Profile";

// Include header (handles session and authentication)
require_once 'includes/header.php';

// Get user_id from session (already verified in header.php)
$user_id = $_SESSION['user_id'];

// Check if form is submitted
$success_message = "";
$error_message = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Form validation
    $errors = [];
    
    // Common fields for all professionals
    $phone = trim($_POST['phone'] ?? '');
    $website = trim($_POST['website'] ?? '');
    $bio = trim($_POST['bio'] ?? '');
    $license_number = trim($_POST['license_number'] ?? '');
    
    // Basic validation
    if (empty($phone)) $errors[] = "Phone number is required";
    if (empty($license_number)) $errors[] = "License number is required";
    if (empty($bio)) $errors[] = "Bio/Description is required";
    
    // Get entity type to determine which specific fields to validate
    $entity_type = $_POST['entity_type'] ?? '';
    
    // Specific fields for individual professionals
    if ($entity_type == 'individual') {
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $years_experience = intval($_POST['years_experience'] ?? 0);
        
        if (empty($first_name)) $errors[] = "First name is required";
        if (empty($last_name)) $errors[] = "Last name is required";
        if ($years_experience <= 0) $errors[] = "Years of experience must be greater than 0";
    } 
    // Specific fields for company professionals
    else if ($entity_type == 'company') {
        $company_name = trim($_POST['company_name'] ?? '');
        $registration_number = trim($_POST['registration_number'] ?? '');
        $founded_year = intval($_POST['founded_year'] ?? 0);
        $company_size = trim($_POST['company_size'] ?? '');
        $headquarters_address = trim($_POST['headquarters_address'] ?? '');
        
        if (empty($company_name)) $errors[] = "Company name is required";
        if (empty($registration_number)) $errors[] = "Registration number is required";
        if ($founded_year <= 1900 || $founded_year > date('Y')) $errors[] = "Please enter a valid founded year";
    } else {
        $errors[] = "Invalid professional type";
    }
    
    // Process profile image if uploaded
    $profile_image = null;
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['size'] > 0) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        $max_size = 5 * 1024 * 1024; // 5MB
        
        if (!in_array($_FILES['profile_image']['type'], $allowed_types)) {
            $errors[] = "Only JPG, PNG, and GIF images are allowed";
        } elseif ($_FILES['profile_image']['size'] > $max_size) {
            $errors[] = "Image size must be less than 5MB";
        } else {
            // Generate unique filename
            $file_ext = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
            $filename = 'professional_' . $user_id . '_' . time() . '.' . $file_ext;
            $upload_dir = '../../uploads/profiles/';
            
            // Create directory if it doesn't exist
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $target_file = $upload_dir . $filename;
            
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $target_file)) {
                $profile_image = $filename;
            } else {
                $errors[] = "Failed to upload image";
            }
        }
    }
    
    // If no errors, update profile
    if (empty($errors)) {
        try {
            $conn->begin_transaction();
            
            // Update users table if password change requested
            if (!empty($_POST['password']) && !empty($_POST['confirm_password'])) {
                if ($_POST['password'] === $_POST['confirm_password']) {
                    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmt->bind_param("si", $password, $user_id);
                    $stmt->execute();
                } else {
                    $errors[] = "Passwords do not match";
                }
            }
            
            // Update professional_entities table (common fields)
            $sql = "UPDATE professional_entities SET 
                    phone = ?, 
                    website = ?, 
                    bio = ?, 
                    license_number = ?,
                    profile_completed = 1";
            
            $params = [$phone, $website, $bio, $license_number];
            $types = "ssss";
            
            // Add profile_image to query if it was uploaded
            if ($profile_image) {
                $sql .= ", profile_image = ?";
                $params[] = $profile_image;
                $types .= "s";
            }
            
            $sql .= " WHERE user_id = ?";
            $params[] = $user_id;
            $types .= "i";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            
            // Get entity ID for specific professional type updates
            $stmt = $conn->prepare("SELECT id, entity_type FROM professional_entities WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $entity_result = $stmt->get_result();
            $entity = $entity_result->fetch_assoc();
            $entity_id = $entity['id'];
            
            // Update specific fields based on entity type
            if ($entity_type == 'individual') {
                $stmt = $conn->prepare("
                    INSERT INTO individual_professionals (entity_id, first_name, last_name, years_experience)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    first_name = VALUES(first_name),
                    last_name = VALUES(last_name),
                    years_experience = VALUES(years_experience)
                ");
                $stmt->bind_param("issi", $entity_id, $first_name, $last_name, $years_experience);
                $stmt->execute();
            } else if ($entity_type == 'company') {
                $stmt = $conn->prepare("
                    INSERT INTO company_professionals (entity_id, company_name, registration_number, founded_year, company_size, headquarters_address)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    company_name = VALUES(company_name),
                    registration_number = VALUES(registration_number),
                    founded_year = VALUES(founded_year),
                    company_size = VALUES(company_size),
                    headquarters_address = VALUES(headquarters_address)
                ");
                $stmt->bind_param("isssss", $entity_id, $company_name, $registration_number, $founded_year, $company_size, $headquarters_address);
                $stmt->execute();
            }
            
            // Log activity
            $action_details = json_encode([
                'action' => 'profile_update',
                'user_id' => $user_id
            ]);
            
            $stmt = $conn->prepare("INSERT INTO activity_log (user_id, action_type, action_details, ip_address) VALUES (?, 'profile_update', ?, ?)");
            $ip = $_SERVER['REMOTE_ADDR'];
            $stmt->bind_param("iss", $user_id, $action_details, $ip);
            $stmt->execute();
            
            $conn->commit();
            $success_message = "Profile updated successfully!";
            
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = "Error updating profile: " . $e->getMessage();
        }
    } else {
        $error_message = implode("<br>", $errors);
    }
}

// Get professional data from all related tables
$stmt = $conn->prepare("
    SELECT pe.*, u.email 
    FROM professional_entities pe
    JOIN users u ON pe.user_id = u.id
    WHERE pe.user_id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$professional = $stmt->get_result()->fetch_assoc();

if (!$professional) {
    echo "<div class='alert alert-danger'>Professional profile not found. Please contact support.</div>";
    require_once 'includes/footer.php';
    exit;
}

// Get specific data based on entity type
$specific_data = [];
if ($professional['entity_type'] == 'individual') {
    $stmt = $conn->prepare("SELECT * FROM individual_professionals WHERE entity_id = ?");
    $stmt->bind_param("i", $professional['id']);
    $stmt->execute();
    $specific_data = $stmt->get_result()->fetch_assoc();
} else if ($professional['entity_type'] == 'company') {
    $stmt = $conn->prepare("SELECT * FROM company_professionals WHERE entity_id = ?");
    $stmt->bind_param("i", $professional['id']);
    $stmt->execute();
    $specific_data = $stmt->get_result()->fetch_assoc();
}
?>

<div class="profile-container">
    <?php if ($success_message): ?>
        <div class="alert alert-success"><?php echo $success_message; ?></div>
    <?php endif; ?>
    
    <?php if ($error_message): ?>
        <div class="alert alert-danger"><?php echo $error_message; ?></div>
    <?php endif; ?>
    
    <div class="profile-header">
        <div class="profile-image-container">
            <?php if (!empty($professional['profile_image'])): ?>
                <img src="../../uploads/profiles/<?php echo htmlspecialchars($professional['profile_image']); ?>" alt="Profile Image" class="profile-image">
            <?php else: ?>
                <div class="profile-image-placeholder">
                    <?php if ($professional['entity_type'] == 'individual'): ?>
                        <?php echo !empty($specific_data) ? strtoupper(substr($specific_data['first_name'], 0, 1) . substr($specific_data['last_name'], 0, 1)) : 'PP'; ?>
                    <?php else: ?>
                        <?php echo !empty($specific_data) ? strtoupper(substr($specific_data['company_name'], 0, 2)) : 'CP'; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="profile-info">
            <h1 class="profile-name">
                <?php if ($professional['entity_type'] == 'individual'): ?>
                    <?php echo !empty($specific_data) ? htmlspecialchars($specific_data['first_name'] . ' ' . $specific_data['last_name']) : 'Professional Profile'; ?>
                <?php else: ?>
                    <?php echo !empty($specific_data) ? htmlspecialchars($specific_data['company_name']) : 'Company Profile'; ?>
                <?php endif; ?>
            </h1>
            <p class="profile-entity-type"><?php echo ucfirst(htmlspecialchars($professional['entity_type'])); ?> Professional</p>
            <div class="verification-status <?php echo $professional['verification_status']; ?>">
                <?php echo ucfirst(htmlspecialchars($professional['verification_status'])); ?>
            </div>
        </div>
    </div>
    
    <div class="profile-tabs">
        <button class="tab-btn active" data-tab="general">General Information</button>
        <button class="tab-btn" data-tab="professional">Professional Details</button>
        <button class="tab-btn" data-tab="security">Security</button>
    </div>
    
    <form method="POST" action="" enctype="multipart/form-data" class="profile-form">
        <input type="hidden" name="entity_type" value="<?php echo htmlspecialchars($professional['entity_type']); ?>">
        
        <div class="tab-content active" id="general-tab">
            <div class="form-group-row">
                <?php if ($professional['entity_type'] == 'individual'): ?>
                <div class="form-group">
                    <label for="first_name">First Name</label>
                    <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($specific_data['first_name'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label for="last_name">Last Name</label>
                    <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($specific_data['last_name'] ?? ''); ?>" required>
                </div>
                <?php else: ?>
                <div class="form-group">
                    <label for="company_name">Company Name</label>
                    <input type="text" id="company_name" name="company_name" value="<?php echo htmlspecialchars($specific_data['company_name'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label for="registration_number">Registration Number</label>
                    <input type="text" id="registration_number" name="registration_number" value="<?php echo htmlspecialchars($specific_data['registration_number'] ?? ''); ?>" required>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="form-group-row">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" value="<?php echo htmlspecialchars($professional['email']); ?>" disabled>
                    <small>Email cannot be changed. Contact support for assistance.</small>
                </div>
                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($professional['phone']); ?>" required>
                </div>
            </div>
            
            <div class="form-group-row">
                <div class="form-group">
                    <label for="website">Website</label>
                    <input type="url" id="website" name="website" value="<?php echo htmlspecialchars($professional['website'] ?? ''); ?>" placeholder="https://yourdomain.com">
                </div>
                <div class="form-group">
                    <label for="license_number">License Number</label>
                    <input type="text" id="license_number" name="license_number" value="<?php echo htmlspecialchars($professional['license_number']); ?>" required>
                </div>
            </div>
            
            <div class="form-group">
                <label for="bio">Bio / Description</label>
                <textarea id="bio" name="bio" rows="5" required><?php echo htmlspecialchars($professional['bio']); ?></textarea>
            </div>
            
            <div class="form-group">
                <label for="profile_image">Profile Image</label>
                <div class="file-upload-container">
                    <input type="file" id="profile_image" name="profile_image" accept="image/jpeg, image/png, image/gif">
                    <label for="profile_image" class="custom-file-upload">Choose File</label>
                    <span id="file-name-display">No file chosen</span>
                </div>
                <small>Maximum file size: 5MB. Supported formats: JPG, PNG, GIF</small>
            </div>
        </div>
        
        <div class="tab-content" id="professional-tab">
            <?php if ($professional['entity_type'] == 'individual'): ?>
            <!-- Individual Professional Specific Fields -->
            <div class="form-group">
                <label for="years_experience">Years of Experience</label>
                <input type="number" id="years_experience" name="years_experience" min="0" max="70" value="<?php echo htmlspecialchars($specific_data['years_experience'] ?? 0); ?>" required>
            </div>
            
            <?php else: ?>
            <!-- Company Professional Specific Fields -->
            <div class="form-group-row">
                <div class="form-group">
                    <label for="founded_year">Founded Year</label>
                    <input type="number" id="founded_year" name="founded_year" min="1900" max="<?php echo date('Y'); ?>" value="<?php echo htmlspecialchars($specific_data['founded_year'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="company_size">Company Size</label>
                    <select id="company_size" name="company_size">
                        <option value="" disabled>Select company size</option>
                        <option value="1-10" <?php echo ($specific_data['company_size'] ?? '') == '1-10' ? 'selected' : ''; ?>>1-10 employees</option>
                        <option value="11-50" <?php echo ($specific_data['company_size'] ?? '') == '11-50' ? 'selected' : ''; ?>>11-50 employees</option>
                        <option value="51-200" <?php echo ($specific_data['company_size'] ?? '') == '51-200' ? 'selected' : ''; ?>>51-200 employees</option>
                        <option value="201-500" <?php echo ($specific_data['company_size'] ?? '') == '201-500' ? 'selected' : ''; ?>>201-500 employees</option>
                        <option value="500+" <?php echo ($specific_data['company_size'] ?? '') == '500+' ? 'selected' : ''; ?>>500+ employees</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label for="headquarters_address">Headquarters Address</label>
                <textarea id="headquarters_address" name="headquarters_address" rows="3"><?php echo htmlspecialchars($specific_data['headquarters_address'] ?? ''); ?></textarea>
            </div>
            <?php endif; ?>
            
            <div class="verification-info">
                <div class="icon"><i class="fas fa-certificate"></i></div>
                <div class="content">
                    <h3>Verification Status: <span class="status <?php echo $professional['verification_status']; ?>"><?php echo ucfirst($professional['verification_status']); ?></span></h3>
                    <?php if ($professional['verification_status'] == 'verified'): ?>
                        <p>Your profile has been verified. Verification improves your credibility with clients.</p>
                    <?php elseif ($professional['verification_status'] == 'pending'): ?>
                        <p>Your profile verification is pending. Our team will review your information shortly.</p>
                    <?php else: ?>
                        <p>Your profile verification has been rejected. Please update your information and contact support.</p>
                    <?php endif; ?>
                    
                    <?php if (!empty($professional['verification_notes'])): ?>
                        <div class="verification-notes">
                            <strong>Verification Notes:</strong>
                            <p><?php echo htmlspecialchars($professional['verification_notes']); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="tab-content" id="security-tab">
            <div class="form-group">
                <label for="password">New Password</label>
                <input type="password" id="password" name="password" minlength="8">
                <small>Leave blank if you don't want to change your password</small>
            </div>
            
            <div class="form-group">
                <label for="confirm_password">Confirm New Password</label>
                <input type="password" id="confirm_password" name="confirm_password" minlength="8">
            </div>
            
            <div class="security-note">
                <div class="icon"><i class="fas fa-shield-alt"></i></div>
                <div class="content">
                    <h3>Password Security Tips</h3>
                    <ul>
                        <li>Use at least 8 characters, including uppercase, lowercase, numbers, and special characters</li>
                        <li>Don't reuse passwords from other websites</li>
                        <li>Update your password regularly</li>
                        <li>Never share your password with others</li>
                    </ul>
                </div>
            </div>
        </div>
        
        <div class="form-actions">
            <button type="submit" class="save-btn">Save Changes</button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Tab switching
    const tabButtons = document.querySelectorAll('.tab-btn');
    const tabContents = document.querySelectorAll('.tab-content');
    
    tabButtons.forEach(button => {
        button.addEventListener('click', function() {
            // Remove active class from all buttons and contents
            tabButtons.forEach(btn => btn.classList.remove('active'));
            tabContents.forEach(content => content.classList.remove('active'));
            
            // Add active class to current button
            this.classList.add('active');
            
            // Show corresponding content
            const tabId = this.dataset.tab + '-tab';
            document.getElementById(tabId).classList.add('active');
        });
    });
    
    // File input display
    const fileInput = document.getElementById('profile_image');
    const fileNameDisplay = document.getElementById('file-name-display');
    
    fileInput.addEventListener('change', function() {
        if (this.files.length > 0) {
            fileNameDisplay.textContent = this.files[0].name;
        } else {
            fileNameDisplay.textContent = 'No file chosen';
        }
    });
    
    // Password validation
    const passwordInput = document.getElementById('password');
    const confirmPasswordInput = document.getElementById('confirm_password');
    
    function validatePassword() {
        if (passwordInput.value !== confirmPasswordInput.value) {
            confirmPasswordInput.setCustomValidity('Passwords do not match');
        } else {
            confirmPasswordInput.setCustomValidity('');
        }
    }
    
    passwordInput.addEventListener('change', validatePassword);
    confirmPasswordInput.addEventListener('keyup', validatePassword);
});
</script>

<?php
// Include footer
require_once 'includes/footer.php';
?>
