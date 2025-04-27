<?php
require_once 'config/db_connect.php';
require_once 'includes/functions.php';
session_start();

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    // Redirect to appropriate dashboard based on user type
    if ($_SESSION['user_type'] == 'applicant') {
        header("Location: dashboard/applicant/index.php");
    } elseif ($_SESSION['user_type'] == 'professional') {
        header("Location: dashboard/professional/index.php");
    } elseif ($_SESSION['user_type'] == 'team_member') {
        header("Location: dashboard/team_member/index.php");
    }
    exit();
}

// Initialize variables
$error = '';
$success = '';
$user_type = isset($_GET['type']) ? $_GET['type'] : 'applicant';
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$google_data = isset($_SESSION['google_data']) ? $_SESSION['google_data'] : null;

// For professional registration, check for entity_type
$entity_type = isset($_GET['entity_type']) ? $_GET['entity_type'] : '';
if ($user_type == 'professional' && $step == 2 && empty($entity_type) && isset($_SESSION['registration']['entity_type'])) {
    $entity_type = $_SESSION['registration']['entity_type'];
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // For all user types - basic information (step 1)
    if (isset($_POST['register_step1'])) {
        $email = $conn->real_escape_string($_POST['email']);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        $user_type = $conn->real_escape_string($_POST['user_type']);
        
        // Validate input
        if (empty($email) || (empty($password) && !$google_data)) {
            $error = "Please fill in all required fields";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address";
        } elseif (!$google_data && $password !== $confirm_password) {
            $error = "Passwords do not match";
        } else {
            // Check if email already exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $error = "Email is already registered. Please login or use a different email.";
            } else {
                // Validate password strength if not using Google login
                if (!$google_data) {
                    $password_validation = validatePassword($password);
                    if (!$password_validation['valid']) {
                        $error = $password_validation['message'];
                    }
                }
                
                if (empty($error)) {
                    // If registering as professional, store data in session and move to next step
                    if ($user_type == 'professional') {
                        // Get entity_type selection for professional
                        $entity_type = isset($_POST['entity_type']) ? $conn->real_escape_string($_POST['entity_type']) : '';
                        
                        if (empty($entity_type) || !in_array($entity_type, ['individual', 'company'])) {
                            $error = "Please select a valid entity type (Individual or Company)";
                        } else {
                            $_SESSION['registration'] = [
                                'email' => $email,
                                'password' => $password,
                                'user_type' => $user_type,
                                'entity_type' => $entity_type,
                                'google_data' => $google_data
                            ];
                            header("Location: register.php?type=professional&step=2&entity_type=".$entity_type);
                            exit();
                        }
                    } else {
                        // For applicant, complete registration directly
                        $hashed_password = $google_data ? null : password_hash($password, PASSWORD_DEFAULT);
                        $verification_token = generateToken();
                        $token_expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
                        
                        // Start transaction
                        $conn->begin_transaction();
                        
                        try {
                            if ($google_data) {
                                // OAuth registration
                                $stmt = $conn->prepare("INSERT INTO users (email, password, role, auth_provider, google_id, profile_picture, email_verified, email_verification_token, email_verification_expires) VALUES (?, NULL, ?, 'google', ?, ?, 1, NULL, NULL)");
                                $stmt->bind_param("ssss", $email, $user_type, $google_data['google_id'], $google_data['picture']);
                            } else {
                                // Regular registration
                                $stmt = $conn->prepare("INSERT INTO users (email, password, role, email_verification_token, email_verification_expires) VALUES (?, ?, ?, ?, ?)");
                                $stmt->bind_param("sssss", $email, $hashed_password, $user_type, $verification_token, $token_expires);
                            }
                            
                            $stmt->execute();
                            $user_id = $conn->insert_id;
                            
                            $conn->commit();
                            
                            // Send verification email for regular registration
                            if (!$google_data) {
                                sendVerificationEmail($email, 'Applicant', $verification_token);
                                $success = "Registration successful! Please check your email to verify your account.";
                                
                                // Display verification link on all environments for better user experience
                                $protocol = ($_SERVER['SERVER_NAME'] == 'localhost' || strpos($_SERVER['SERVER_NAME'], '.local') !== false) ? 'http' : 'https';
                                
                                // Fix for Hostinger - detect if we're on production hostinger site
                                $is_hostinger = (strpos($_SERVER['HTTP_HOST'], 'hostingersite.com') !== false);
                                
                                if ($is_hostinger) {
                                    // On Hostinger site, use direct path without /visafy-v2 folder
                                    $verification_link = $protocol . "://" . $_SERVER['HTTP_HOST'] . "/verify_email.php?token=" . $verification_token;
                                } else {
                                    // Local development or other hosting
                                    $verification_link = $protocol . "://" . $_SERVER['HTTP_HOST'] . "/visafy-v2/verify_email.php?token=" . $verification_token;
                                }
                                
                                $success .= "<br><br><strong>Verification Link:</strong> <a href='$verification_link' target='_blank'>Click here to verify your email</a>";
                                
                                // Add note about email logs only in development
                                if ($_SERVER['SERVER_NAME'] == 'localhost' || strpos($_SERVER['SERVER_NAME'], '.local') !== false) {
                                    $success .= "<br><small>Emails are also logged in the /logs directory.</small>";
                                }
                            } else {
                                // Log in the user immediately for Google registration
                                $_SESSION['user_id'] = $user_id;
                                $_SESSION['user_email'] = $email;
                                $_SESSION['user_type'] = $user_type;
                                
                                // Clear Google data
                                unset($_SESSION['google_data']);
                                
                                // Redirect to dashboard
                                if ($user_type == 'applicant') {
                                    header("Location: dashboard/applicant/index.php");
                                }
                                exit();
                            }
                        } catch (Exception $e) {
                            $conn->rollback();
                            $error = "Registration failed: " . $e->getMessage();
                        }
                    }
                }
            }
        }
    }
    
    // For professional - step 2 (professional details)
    else if (isset($_POST['register_step2']) && isset($_SESSION['registration'])) {
        $registration = $_SESSION['registration'];
        $entity_type = $registration['entity_type'];
        
        // Common fields for both entity types
        $license_number = $conn->real_escape_string($_POST['license_number']);
        $bio = $conn->real_escape_string($_POST['bio']);
        $country_code = $conn->real_escape_string($_POST['country_code']);
        $phone_number = $conn->real_escape_string($_POST['phone']);
        $phone = $country_code . $phone_number; // Combine country code with phone number
        
        // Process website (if provided)
        $website_input = trim($conn->real_escape_string($_POST['website'] ?? ''));
        $website = '';
        if (!empty($website_input)) {
            // If no protocol is specified, add https://
            if (!preg_match('~^(?:f|ht)tps?://~i', $website_input)) {
                $website = 'https://' . $website_input;
            } else {
                $website = $website_input;
            }
        }
        
        // Entity-specific fields
        if ($entity_type == 'individual') {
            $first_name = $conn->real_escape_string($_POST['first_name']);
            $last_name = $conn->real_escape_string($_POST['last_name']);
            $years_experience = $conn->real_escape_string($_POST['years_experience']);
            
            // Validate input for individual
            if (empty($license_number) || empty($first_name) || empty($last_name) || empty($years_experience) || empty($bio) || empty($phone)) {
                $error = "Please fill in all required fields";
            } else {
                // Store in session and move to next step
                $_SESSION['registration']['professional'] = [
                    'license_number' => $license_number,
                    'bio' => $bio,
                    'phone' => $phone,
                    'website' => $website,
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'years_experience' => $years_experience
                ];
                
                header("Location: register.php?type=professional&step=3&entity_type=".$entity_type);
                exit();
            }
        } else { // company
            $company_name = $conn->real_escape_string($_POST['company_name']);
            $registration_number = $conn->real_escape_string($_POST['registration_number']);
            $founded_year = $conn->real_escape_string($_POST['founded_year']);
            $company_size = $conn->real_escape_string($_POST['company_size']);
            $headquarters_address = $conn->real_escape_string($_POST['headquarters_address']);
            
            // Validate input for company
            if (empty($license_number) || empty($company_name) || empty($registration_number) || empty($bio) || empty($phone)) {
                $error = "Please fill in all required fields";
            } else {
                // Store in session and move to next step
                $_SESSION['registration']['professional'] = [
                    'license_number' => $license_number,
                    'bio' => $bio,
                    'phone' => $phone,
                    'website' => $website,
                    'company_name' => $company_name,
                    'registration_number' => $registration_number,
                    'founded_year' => $founded_year,
                    'company_size' => $company_size,
                    'headquarters_address' => $headquarters_address
                ];
                
                header("Location: register.php?type=professional&step=3&entity_type=".$entity_type);
                exit();
            }
        }
    }
    
    // For professional - step 3 (languages)
    else if (isset($_POST['register_step3']) && isset($_SESSION['registration'])) {
        // Enable error logging for debugging
        ini_set('display_errors', 1);
        error_reporting(E_ALL);
        
        $registration = $_SESSION['registration'];
        $entity_type = $registration['entity_type'];
        
        $languages = isset($_POST['languages']) ? $_POST['languages'] : [];
        
        // Validate input
        if (empty($languages)) {
            $error = "Please select at least one language";
        } else {
            // Complete the registration
            $email = $registration['email'];
            $password = $registration['password'];
            $user_type = $registration['user_type'];
            $google_data = isset($registration['google_data']) ? $registration['google_data'] : null;
            $professional = $registration['professional'];
            
            $hashed_password = $google_data ? null : password_hash($password, PASSWORD_DEFAULT);
            $verification_token = generateToken();
            $token_expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
            
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Insert user
                if ($google_data) {
                    // OAuth registration
                    $stmt = $conn->prepare("INSERT INTO users (email, password, role, auth_provider, google_id, profile_picture, email_verified, email_verification_token, email_verification_expires) VALUES (?, NULL, ?, 'google', ?, ?, 1, NULL, NULL)");
                    $stmt->bind_param("ssss", $email, $user_type, $google_data['google_id'], $google_data['picture']);
                } else {
                    // Regular registration
                    $stmt = $conn->prepare("INSERT INTO users (email, password, role, email_verification_token, email_verification_expires) VALUES (?, ?, ?, ?, ?)");
                    $stmt->bind_param("sssss", $email, $hashed_password, $user_type, $verification_token, $token_expires);
                }
                
                $stmt->execute();
                $user_id = $conn->insert_id;
                
                // Debug log - before professional entity insertion
                $log_dir = dirname(__FILE__) . '/logs';
                if (!file_exists($log_dir)) {
                    mkdir($log_dir, 0777, true);
                }
                file_put_contents($log_dir . '/professional_debug.log', 
                    date('Y-m-d H:i:s') . " - Preparing to insert professional entity: " . 
                    "User ID: $user_id, License: {$professional['license_number']}, " . 
                    "Entity Type: $entity_type\n", 
                    FILE_APPEND
                );
                
                // Insert professional entity (common data)
                $website = $professional['website'] ?? '';
                
                $stmt = $conn->prepare("INSERT INTO professional_entities (user_id, entity_type, license_number, phone, website, bio) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("isssss", $user_id, $entity_type, $professional['license_number'], $professional['phone'], $website, $professional['bio']);
                
                if (!$stmt->execute()) {
                    throw new Exception("Failed to insert professional entity: " . $stmt->error);
                }
                
                $entity_id = $conn->insert_id;
                
                // Insert entity-specific data
                if ($entity_type == 'individual') {
                    $years_exp = (int)$professional['years_experience'];
                    
                    $stmt = $conn->prepare("INSERT INTO individual_professionals (entity_id, first_name, last_name, years_experience) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("issi", $entity_id, $professional['first_name'], $professional['last_name'], $years_exp);
                    
                    if (!$stmt->execute()) {
                        throw new Exception("Failed to insert individual professional: " . $stmt->error);
                    }
                } else { // company
                    $stmt = $conn->prepare("INSERT INTO company_professionals (entity_id, company_name, registration_number, founded_year, company_size, headquarters_address) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("isssss", $entity_id, $professional['company_name'], $professional['registration_number'], $professional['founded_year'], $professional['company_size'], $professional['headquarters_address']);
                    
                    if (!$stmt->execute()) {
                        throw new Exception("Failed to insert company professional: " . $stmt->error);
                    }
                }
                
                // Insert languages
                $lang_count = 0;
                foreach ($languages as $language_id) {
                    $lang_id = (int)$language_id;
                    $stmt = $conn->prepare("INSERT INTO professional_languages (entity_id, language_id, proficiency_level) VALUES (?, ?, 'fluent')");
                    $stmt->bind_param("ii", $entity_id, $lang_id);
                    $stmt->execute();
                    $lang_count++;
                }
                
                // Debug log - summary
                file_put_contents($log_dir . '/professional_debug.log', 
                    date('Y-m-d H:i:s') . " - Registration complete: " . 
                    "Entity ID: $entity_id, Entity Type: $entity_type, " . 
                    "Added $lang_count languages\n", 
                    FILE_APPEND
                );
                
                $conn->commit();
                
                // Send verification email for regular registration
                if (!$google_data) {
                    // For individual professionals, use their name
                    $name = ($entity_type == 'individual') ? 
                        $professional['first_name'] . ' ' . $professional['last_name'] : 
                        ($entity_type == 'company' ? $professional['company_name'] : '');
                        
                    sendVerificationEmail($email, $name, $verification_token);
                    $success = "Registration successful! Please check your email to verify your account.";
                    
                    // Display verification link on all environments for better user experience
                    $protocol = ($_SERVER['SERVER_NAME'] == 'localhost' || strpos($_SERVER['SERVER_NAME'], '.local') !== false) ? 'http' : 'https';
                    
                    // Fix for Hostinger - detect if we're on production hostinger site
                    $is_hostinger = (strpos($_SERVER['HTTP_HOST'], 'hostingersite.com') !== false);
                    
                    if ($is_hostinger) {
                        // On Hostinger site, use direct path without /visafy-v2 folder
                        $verification_link = $protocol . "://" . $_SERVER['HTTP_HOST'] . "/verify_email.php?token=" . $verification_token;
                    } else {
                        // Local development or other hosting
                        $verification_link = $protocol . "://" . $_SERVER['HTTP_HOST'] . "/visafy-v2/verify_email.php?token=" . $verification_token;
                    }
                    
                    $success .= "<br><br><strong>Verification Link:</strong> <a href='$verification_link' target='_blank'>Click here to verify your email</a>";
                    
                    // Add note about email logs only in development
                    if ($_SERVER['SERVER_NAME'] == 'localhost' || strpos($_SERVER['SERVER_NAME'], '.local') !== false) {
                        $success .= "<br><small>Emails are also logged in the /logs directory.</small>";
                    }
                } else {
                    // Log in the user immediately for Google registration
                    $_SESSION['user_id'] = $user_id;
                    $_SESSION['user_email'] = $email;
                    $_SESSION['user_type'] = $user_type;
                    
                    // Clear Google data and registration data
                    unset($_SESSION['google_data']);
                    unset($_SESSION['registration']);
                    
                    // Redirect to dashboard
                    header("Location: dashboard/professional/index.php");
                    exit();
                }
                
                // Clear registration data
                unset($_SESSION['registration']);
                
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Registration failed: " . $e->getMessage();
                
                // Log the error to a file
                $log_dir = dirname(__FILE__) . '/logs';
                if (!file_exists($log_dir)) {
                    mkdir($log_dir, 0777, true);
                }
                file_put_contents($log_dir . '/registration_errors.log', 
                    date('Y-m-d H:i:s') . " - Registration error: " . $e->getMessage() . "\n" . 
                    "User: $email, Type: Professional, Entity Type: $entity_type\n" . 
                    "Trace: " . $e->getTraceAsString() . "\n\n", 
                    FILE_APPEND
                );
            }
        }
    }
}

// Include header
$page_title = "Register - Visafy";
include 'includes/header.php';

// Fetch data for professional registration
$specializations = [];
$languages = [];

if ($user_type == 'professional' && $step == 3) {
    // Get languages
    $result = $conn->query("SELECT id, name FROM languages");
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $languages[] = $row;
        }
    }
}
?>

<div class="register-container">
    <div class="container">
        <div class="register-card">
            <div class="register-header">
                <h2>Create an Account</h2>
            </div>
            <div class="register-body">
                <?php if ($error): ?>
                    <div class="auth-error"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="auth-success">
                        <?php echo $success; ?>
                        <p class="mt-3"><a href="login.php" class="btn btn-primary">Go to Login</a></p>
                    </div>
                <?php else: ?>
                
                <!-- Type selection tabs -->
                <div class="register-tabs">
                    <a class="register-tab <?php echo $user_type == 'applicant' ? 'active' : ''; ?>" href="register.php?type=applicant">Applicant</a>
                    <a class="register-tab <?php echo $user_type == 'professional' ? 'active' : ''; ?>" href="register.php?type=professional">Professional</a>
                </div>
                
                <?php if ($user_type == 'professional'): ?>
                    <!-- Professional registration - multi-step form -->
                    <div class="register-progress">
                        <div class="register-progress-bar" style="width: <?php echo $step * 33.33; ?>%"></div>
                    </div>
                    
                    <p class="register-step-title">Step <?php echo $step; ?> of 3: 
                        <?php 
                        if ($step == 1) echo "Basic Information";
                        elseif ($step == 2) echo "Professional Details";
                        else echo "Languages";
                        ?>
                    </p>
                    
                    <?php if ($step == 1): ?>
                        <!-- Step 1: Basic Information -->
                        <form method="post" action="" class="register-form">
                            <div class="form-group">
                                <label for="email">Email Address</label>
                                <input type="email" id="email" name="email" value="<?php echo $google_data ? $google_data['email'] : ''; ?>" <?php echo $google_data ? 'readonly' : ''; ?> required>
                            </div>
                            
                            <?php if (!$google_data): ?>
                            <div class="form-group">
                                <label for="password">Password</label>
                                <input type="password" id="password" name="password" required>
                                <div class="help-text">Password must be at least 8 characters, include at least one number and one uppercase letter.</div>
                            </div>
                            <div class="form-group">
                                <label for="confirm_password">Confirm Password</label>
                                <input type="password" id="confirm_password" name="confirm_password" required>
                            </div>
                            <?php endif; ?>
                            
                            <div class="form-group">
                                <label>Entity Type</label>
                                <select id="entity_type" name="entity_type" required class="form-control">
                                    <option value="">Select entity type...</option>
                                    <option value="individual">Individual Professional</option>
                                    <option value="company">Company</option>
                                </select>
                            </div>
                            
                            <input type="hidden" name="user_type" value="professional">
                            <div class="register-nav">
                                <div></div> <!-- Empty div for spacing -->
                                <button type="submit" name="register_step1" class="btn btn-primary btn-next">Continue</button>
                            </div>
                        </form>
                    
                    <?php elseif ($step == 2): ?>
                        <!-- Step 2: Professional Details -->
                        <form method="post" action="" class="register-form">
                            <div class="form-group">
                                <label for="license_number">License Number</label>
                                <input type="text" id="license_number" name="license_number" required>
                            </div>
                            
                            <?php if ($entity_type == 'individual'): ?>
                                <!-- Individual-specific fields -->
                                <div class="form-group">
                                    <label for="first_name">First Name</label>
                                    <input type="text" id="first_name" name="first_name" required>
                                </div>
                                <div class="form-group">
                                    <label for="last_name">Last Name</label>
                                    <input type="text" id="last_name" name="last_name" required>
                                </div>
                                <div class="form-group">
                                    <label for="years_experience">Years of Experience</label>
                                    <input type="number" id="years_experience" name="years_experience" min="0" required>
                                </div>
                            <?php else: ?>
                                <!-- Company-specific fields -->
                                <div class="form-group">
                                    <label for="company_name">Company Name</label>
                                    <input type="text" id="company_name" name="company_name" required>
                                </div>
                                <div class="form-group">
                                    <label for="registration_number">Registration Number</label>
                                    <input type="text" id="registration_number" name="registration_number" required>
                                </div>
                                <div class="form-group">
                                    <label for="founded_year">Founded Year</label>
                                    <input type="number" id="founded_year" name="founded_year" min="1900" max="<?php echo date('Y'); ?>">
                                </div>
                                <div class="form-group">
                                    <label for="company_size">Company Size</label>
                                    <select id="company_size" name="company_size">
                                        <option value="">Select size...</option>
                                        <option value="1-10">1-10 employees</option>
                                        <option value="11-50">11-50 employees</option>
                                        <option value="51-200">51-200 employees</option>
                                        <option value="201-500">201-500 employees</option>
                                        <option value="500+">500+ employees</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="headquarters_address">Headquarters Address</label>
                                    <textarea id="headquarters_address" name="headquarters_address" rows="2"></textarea>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Common fields for both types -->
                            <div class="form-group">
                                <label for="bio">Professional Bio</label>
                                <textarea id="bio" name="bio" rows="3" required></textarea>
                            </div>
                            <div class="form-group">
                                <label for="phone">Phone Number</label>
                                <div class="visafy-phone-container">
                                    <select id="country_code" name="country_code" class="visafy-country-code">
                                        <option value="+1">+1</option>
                                        <option value="+44">+44</option>
                                        <option value="+91">+91</option>
                                        <option value="+61">+61</option>
                                        <option value="+86">+86</option>
                                        <option value="+49">+49</option>
                                        <option value="+33">+33</option>
                                        <option value="+81">+81</option>
                                        <option value="+7">+7</option>
                                        <option value="+971">+971</option>
                                        <option value="+966">+966</option>
                                        <option value="+65">+65</option>
                                        <option value="+55">+55</option>
                                        <option value="+27">+27</option>
                                    </select>
                                    <input type="tel" id="phone" name="phone" class="visafy-phone-input" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="website">Website (Optional)</label>
                                <input type="text" id="website" name="website" placeholder="domain.com">
                            </div>
                            
                            <div class="register-nav">
                                <a href="register.php?type=professional&step=1" class="btn btn-back">Back</a>
                                <button type="submit" name="register_step2" class="btn btn-primary btn-next">Continue</button>
                            </div>
                        </form>
                        
                    <?php elseif ($step == 3): ?>
                        <!-- Step 3: Languages -->
                        <form method="post" action="" class="register-form">
                            <div class="form-group">
                                <label>Languages</label>
                                <div class="checkbox-group">
                                    <?php foreach ($languages as $language): ?>
                                    <div class="checkbox-item">
                                        <input type="checkbox" name="languages[]" value="<?php echo $language['id']; ?>" id="lang_<?php echo $language['id']; ?>">
                                        <label for="lang_<?php echo $language['id']; ?>">
                                            <?php echo $language['name']; ?>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php if (empty($languages)): ?>
                                    <div class="alert alert-warning">
                                        No languages available. Please contact the administrator.
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="register-nav">
                                <a href="register.php?type=professional&step=2&entity_type=<?php echo $entity_type; ?>" class="btn btn-back">Back</a>
                                <button type="submit" name="register_step3" class="btn btn-primary btn-next">Complete Registration</button>
                            </div>
                        </form>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <!-- Applicant registration - single step form -->
                    <form method="post" action="" class="register-form">
                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" name="email" value="<?php echo $google_data ? $google_data['email'] : ''; ?>" <?php echo $google_data ? 'readonly' : ''; ?> required>
                        </div>
                        
                        <?php if (!$google_data): ?>
                        <div class="form-group">
                            <label for="password">Password</label>
                            <input type="password" id="password" name="password" required>
                            <div class="help-text">Password must be at least 8 characters, include at least one number and one uppercase letter.</div>
                        </div>
                        <div class="form-group">
                            <label for="confirm_password">Confirm Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" required>
                        </div>
                        <?php endif; ?>
                        
                        <input type="hidden" name="user_type" value="<?php echo $user_type; ?>">
                        <button type="submit" name="register_step1" class="btn btn-primary btn-next">Register</button>
                    </form>
                <?php endif; ?>
                
                <?php if (!$success): // Only show alternative login options if not showing success message ?>
                <div class="auth-divider">
                    <span>OR</span>
                </div>
                
                <?php
                // Generate Google OAuth URL
                require_once 'config/google_auth.php';
                require_once 'vendor/autoload.php';
                
                $client = new Google_Client();
                $client->setClientId(GOOGLE_CLIENT_ID);
                $client->setClientSecret(GOOGLE_CLIENT_SECRET);
                $client->setRedirectUri(GOOGLE_REDIRECT_URI);
                $client->addScope("email");
                $client->addScope("profile");
                
                $google_auth_url = $client->createAuthUrl();
                ?>
                
                <a href="<?php echo $google_auth_url; ?>" class="social-login-btn">
                    <i class="fab fa-google"></i> Sign up with Google
                </a>
                <?php endif; ?>
                
                <div class="auth-footer">
                    <p>Already have an account? <a href="login.php">Login here</a></p>
                </div>
                
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<style>
/* Custom styles for Visafy phone input with country code */
.visafy-phone-container {
    display: flex !important;
    align-items: center !important;
    width: 100% !important;
}

.visafy-country-code {
    width: 80px !important;
    min-width: 80px !important;
    max-width: 80px !important;
    margin-right: 10px !important;
    padding: 8px !important;
    border: 1px solid #ced4da !important;
    border-radius: 4px !important;
    flex-shrink: 0 !important;
    box-sizing: border-box !important;
}

.visafy-phone-input {
    flex: 1 !important;
    width: auto !important;
    min-width: 0 !important;
}

/* Make sure all form controls have same height */
.register-form select, 
.register-form input, 
.register-form textarea {
    padding: 8px 12px;
    border: 1px solid #ced4da;
    border-radius: 4px;
}

/* Style for the entity type dropdown */
.form-control {
    display: block;
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ced4da;
    border-radius: 4px;
}
</style>

<script>
// Ensure proper styling for phone input
document.addEventListener('DOMContentLoaded', function() {
    // Set proper width for country code dropdown
    var countryCode = document.querySelector('.visafy-country-code');
    if (countryCode) {
        countryCode.style.width = '80px';
        countryCode.style.minWidth = '80px';
        countryCode.style.maxWidth = '80px';
    }
    
    // Make phone input use remaining space
    var phoneInput = document.querySelector('.visafy-phone-input');
    if (phoneInput) {
        phoneInput.style.flex = '1';
        phoneInput.style.width = 'auto';
    }
});
</script>
