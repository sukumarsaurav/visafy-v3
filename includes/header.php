<?php
// Set default page title if not set
$page_title = isset($page_title) ? $page_title : "Visayfy | Canadian Immigration Consultancy";

// Check if base_url is already set from the including file
if (!isset($base_url)) {
    // Determine base URL dynamically based on the current script's location
    $current_dir = dirname($_SERVER['PHP_SELF']);
    $base_url = '';

    // If we're in a subdirectory
    if (strpos($current_dir, '/visa-types') !== false || 
        strpos($current_dir, '/blog') !== false || 
        strpos($current_dir, '/resources') !== false ||
        strpos($current_dir, '/assessment-calculator') !== false) {
        $base_url = '..';
    } else if (strpos($current_dir, '/immigration-news') !== false) {
        $base_url = ''; // Root-relative for virtual directory
    } else {
        $base_url = '.';
    }
}

// Define base path - default to use base_url if not explicitly set
$base = isset($base_path) ? $base_path : $base_url;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title : 'Visafy Immigration Consultancy'; ?></title>
    <meta name="description" content="Expert Canadian immigration consultancy services for study permits, work permits, express entry, and more.">
    
    <!-- Favicon -->
    <link rel="icon" href="<?php echo $base; ?>/favicon.ico" type="image/x-icon">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&family=Lora:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css">
    
    <!-- Swiper CSS for Sliders -->
    <link rel="stylesheet" href="https://unpkg.com/swiper@8/swiper-bundle.min.css">
    
    <!-- AOS Animation CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.css">
    <!-- Move JS libraries to the end of head to ensure they load before other scripts -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.js"></script>
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?php echo $base; ?>/assets/css/styles.css">
    <link rel="stylesheet" href="<?php echo $base; ?>/assets/css/animations.css">
    <link rel="stylesheet" href="<?php echo $base; ?>/assets/css/header.css">
    <link rel="stylesheet" href="<?php echo $base; ?>/assets/css/resources.css">
    <link rel="stylesheet" href="<?php echo $base; ?>/assets/css/assessment-drawer.css">
    <link rel="stylesheet" href="<?php echo $base; ?>/assets/css/news.css">
    <link rel="stylesheet" href="<?php echo $base; ?>/assets/css/faq.css">
    <link rel="stylesheet" href="<?php echo $base; ?>/assets/css/consultant.css">
        
    <!-- Libraries -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.js"></script>

    <!-- Load utils.js before other scripts -->
    <script src="<?php echo $base; ?>/assets/js/utils.js"></script>

    <!-- Your custom scripts should come after utils.js -->
    <script src="<?php echo $base; ?>/assets/js/main.js" defer></script>
    <script src="<?php echo $base; ?>/assets/js/resources.js" defer></script>
</head>
<body>
    <!-- Removed top navbar as requested -->

    <!-- Drawer Overlay -->
    <div class="drawer-overlay"></div>
    
    <!-- Side Drawer -->
    <div class="side-drawer">
        <div class="drawer-header">
            <a href="<?php echo $base; ?>/index.php" class="drawer-logo">
                <img src="<?php echo $base; ?>/assets/images/logo-Visafy-light.png" alt="Visafy Logo" class="mobile-logo">
            </a>
            <button class="drawer-close"><i class="fas fa-times"></i></button>
        </div>
        <nav class="drawer-nav">
           
            
            <a href="<?php echo $base; ?>/about-us.php" class="drawer-item">About Us</a>
            <a href="<?php echo $base; ?>/services.php" class="drawer-item">Services</a>
            <a href="<?php echo $base; ?>/become-member.php" class="drawer-item">Become Partner</a>
            <a href="<?php echo $base; ?>/eligibility-test.php" class="drawer-item">Eligibility Check</a>
            
          
            
            <a href="<?php echo $base; ?>/contact.php" class="drawer-item">Contact</a>
            
            <div class="drawer-cta">
                <a href="<?php echo $base; ?>/book-service.php" class="btn btn-primary">Book Service </a>
                <?php if(isset($_SESSION['user_id'])): ?>
                <div class="drawer-profile">
                    <a href="<?php echo $base; ?>/dashboard.php" class="drawer-profile-link">Dashboard</a>
                    <a href="<?php echo $base; ?>/logout.php" class="drawer-profile-link">Logout</a>
                </div>
                <?php else: ?>
                <div class="drawer-auth">
                    <a href="<?php echo $base; ?>/login.php" class="drawer-auth-link">Login</a>
                    <a href="<?php echo $base; ?>/register.php" class="drawer-auth-link">Register</a>
                </div>
                <?php endif; ?>
            </div>
        </nav>
    </div>

    <!-- Header Section -->
    <header class="header">
        <div class="container header-container">
            <!-- Logo -->
            <div class="logo">
                <a href="<?php echo $base; ?>/index.php">
                    <img src="<?php echo $base; ?>/assets/images/logo-Visafy-light.png" alt="Visafy Logo" class="desktop-logo">
                </a>
            </div>
            
            <!-- Right Side Navigation and Button -->
            <div class="header-right">
                <nav class="main-nav">
                    <ul class="nav-menu">
                        <li class="nav-item"><a href="<?php echo $base; ?>/about-us.php">About Us</a></li>
                        <li class="nav-item"><a href="<?php echo $base; ?>/services.php">Services</a></li>
                        <li class="nav-item"><a href="<?php echo $base; ?>/become-member.php">Become Partner</a></li> 
                        <li class="nav-item"><a href="<?php echo $base; ?>/eligibility-test.php">Eligibility Check</a></li> 
                    </ul>
                </nav>
                
                <!-- Inside the header-actions div -->
                <div class="header-actions">
                    <?php if(isset($_SESSION['user_id'])): ?>
                    <!-- User is logged in - show profile dropdown -->
                    <div class="action-buttons">
                        <div class="consultation-btn">
                            <a href="<?php echo $base; ?>/book-service.php" class="btn btn-primary">Book Service</a>
                        </div>
                        <div class="user-profile-dropdown">
                            <button class="profile-toggle">
                                <?php if(isset($_SESSION['user_profile_image']) && !empty($_SESSION['user_profile_image'])): ?>
                                    <img src="<?php echo $base; ?>/assets/images/profiles/<?php echo $_SESSION['user_profile_image']; ?>" alt="Profile" class="profile-image">
                                <?php else: ?>
                                    <i class="fas fa-user-circle profile-placeholder"></i>
                                <?php endif; ?>
                            </button>
                            <div class="profile-dropdown-menu">
                                <a href="<?php echo $base; ?>/dashboard.php">Dashboard</a>
                                <a href="<?php echo $base; ?>/profile.php">My Profile</a>
                                <a href="<?php echo $base; ?>/logout.php">Logout</a>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <!-- User is not logged in - show login/register button -->
                    <div class="action-buttons">
                        <div class="consultation-btn">
                            <a href="<?php echo $base; ?>/book-service.php" class="btn btn-primary">Book Service</a>
                        </div>
                        <div class="auth-button">
                            <a href="<?php echo $base; ?>/login.php" class="btn btn-secondary">Login/Register</a>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <button class="mobile-menu-toggle">
                        <i class="fas fa-bars"></i>
                    </button>
                </div>
            </div>
        </div>
    </header>
</body>
</html>