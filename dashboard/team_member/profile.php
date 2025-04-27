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
    
    // Get form data
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $bio = trim($_POST['bio'] ?? '');
    $years_experience = intval($_POST['years_experience'] ?? 0);
    
    // Basic validation
    if (empty($first_name)) $errors[] = "First name is required";
    if (empty($last_name)) $errors[] = "Last name is required";
    if (empty($phone)) $errors[] = "Phone number is required";
    
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
            $filename = 'team_member_' . $user_id . '_' . time() . '.' . $file_ext;
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
            
            // Update team_members table
            $sql = "UPDATE team_members SET 
                    first_name = ?, 
                    last_name = ?, 
                    phone = ?, 
                    bio = ?, 
                    years_experience = ?";
            
            $params = [$first_name, $last_name, $phone, $bio, $years_experience];
            $types = "ssssi";
            
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

// Get team member data
$stmt = $conn->prepare("
    SELECT tm.*, u.email, r.name as role_name, c.company_name 
    FROM team_members tm
    JOIN users u ON tm.user_id = u.id
    JOIN team_roles r ON tm.role_id = r.id
    JOIN company_professionals c ON tm.company_id = c.entity_id
    WHERE tm.user_id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();

if (!$member) {
    echo "<div class='alert alert-danger'>Team member profile not found. Please contact your administrator.</div>";
    require_once 'includes/footer.php';
    exit;
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
            <?php if (!empty($member['profile_image'])): ?>
                <img src="../uploads/profile_images/<?php echo htmlspecialchars($member['profile_image']); ?>" alt="Profile Image" class="profile-image">
            <?php else: ?>
                <div class="profile-image-placeholder">
                    <?php echo strtoupper(substr($member['first_name'], 0, 1) . substr($member['last_name'], 0, 1)); ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="profile-info">
            <h1 class="profile-name"><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></h1>
            <p class="profile-role"><?php echo htmlspecialchars($member['role_name']); ?></p>
            <p class="profile-company"><?php echo htmlspecialchars($member['company_name']); ?></p>
        </div>
    </div>
    
    <div class="profile-tabs">
        <button class="tab-btn active" data-tab="general">General Information</button>
        <button class="tab-btn" data-tab="security">Security</button>
    </div>
    
    <form method="POST" action="" enctype="multipart/form-data" class="profile-form">
        <div class="tab-content active" id="general-tab">
            <div class="form-group-row">
                <div class="form-group">
                    <label for="first_name">First Name</label>
                    <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($member['first_name']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="last_name">Last Name</label>
                    <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($member['last_name']); ?>" required>
                </div>
            </div>
            
            <div class="form-group-row">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" value="<?php echo htmlspecialchars($member['email']); ?>" disabled>
                    <small>Email cannot be changed. Contact your company administrator.</small>
                </div>
                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($member['phone'] ?? ''); ?>">
                </div>
            </div>
            
            <div class="form-group-row">
                <div class="form-group">
                    <label for="position">Position</label>
                    <input type="text" id="position" value="<?php echo htmlspecialchars($member['position']); ?>" disabled>
                    <small>Position is assigned by your company administrator.</small>
                </div>
                <div class="form-group">
                    <label for="years_experience">Years of Experience</label>
                    <input type="number" id="years_experience" name="years_experience" min="0" max="50" value="<?php echo htmlspecialchars($member['years_experience'] ?? 0); ?>">
                </div>
            </div>
            
            <div class="form-group">
                <label for="bio">Bio / Professional Summary</label>
                <textarea id="bio" name="bio" rows="5"><?php echo htmlspecialchars($member['bio'] ?? ''); ?></textarea>
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
