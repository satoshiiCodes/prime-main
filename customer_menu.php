<?php
// Handle logout FIRST, before any database operations
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    setcookie('remember_me', '', time() - 3600, "/");
    header("Location: login_form.php");
    exit();
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in, otherwise redirect to login page
if (!isset($_SESSION['logged_in']) || !isset($_SESSION['user_id'])) {
    header("Location: login_form.php");
    exit();
}

// Database configuration
require 'includes/db_config.php';

// Initialize user variable
$user = [];

// Get user details from ANY user table (admin, collector, proxy, or customer)
try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    // First try to get user from accounts table (admin/area manager)
    $stmt = $conn->prepare("SELECT first_name, middle_name, last_name, suffix, account_type FROM accounts WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
    } else {
        // If not found in accounts, try proxy_accounts
        $stmt = $conn->prepare("SELECT first_name, middle_name, last_name, suffix, 'Proxy Collector' as account_type FROM proxy_accounts WHERE id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
        } else {
            // If not found in proxy_accounts, try collector_accounts
            $stmt = $conn->prepare("SELECT first_name, middle_name, last_name, suffix, 'Collector' as account_type FROM collector_accounts WHERE id = ?");
            $stmt->bind_param("i", $_SESSION['user_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $user = $result->fetch_assoc();
            } else {
                // If not found in collector_accounts, try customer_accounts
                $stmt = $conn->prepare("SELECT first_name, middle_name, last_name, suffix, 'Customer' as account_type FROM customer_accounts WHERE id = ?");
                $stmt->bind_param("i", $_SESSION['user_id']);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $user = $result->fetch_assoc();
                } else {
                    // User not found in any table - log them out
                    session_unset();
                    session_destroy();
                    header("Location: login.php?error=user_not_found");
                    exit();
                }
            }
        }
    }
    
    $stmt->close();
    $conn->close();

} catch (Exception $e) {
    error_log($e->getMessage());
    // Don't die here, just log the error
}
?>

<nav class="navbar navbar-expand-lg navbar-light bg-white fixed-top shadow-sm" style="z-index: 1030;">
    <div class="container-fluid px-3 px-md-4">
        <!-- Logo/Brand -->
        <a class="navbar-brand fw-bold text-primary d-flex align-items-center me-3" href="customer_dashboard.php">
            <div class="bg-primary rounded-circle p-2 me-2 d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                <i class="fas fa-city text-white"></i>
            </div>
            Prime<span class="text-gradient">City</span>
        </a>

        <!-- Mobile Toggle Buttons -->
        <div class="d-flex d-lg-none align-items-center ms-auto">
            <!-- Mobile User Toggle Button -->
            <button class="btn btn-link text-decoration-none p-0 me-2" type="button" data-bs-toggle="collapse" data-bs-target="#userMenuMobile" aria-expanded="false" aria-controls="userMenuMobile">
                <div class="bg-gradient-primary rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                    <i class="fas fa-user text-white"></i>
                </div>
            </button>
            
            <!-- Main Navigation Toggle Button -->
            <button class="navbar-toggler border-0 p-1" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
        </div>

        <!-- Main Navigation (Desktop & Mobile) -->
        <div class="collapse navbar-collapse order-lg-1" id="navbarNav">
            <ul class="navbar-nav me-auto ms-lg-4">
                <li class="nav-item me-3">
                    <a class="nav-link active fw-semibold" href="customer_dashboard.php">
                        <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                    </a>
                </li>
                <li class="nav-item me-3">
                    <a class="nav-link fw-semibold" href="customer_view_payment.php?id=<?php echo $_SESSION['user_id']; ?>">
                        <i class="fas fa-file-invoice-dollar me-2"></i>Loan Details
                    </a>
                </li>
            </ul>
        </div>

        <!-- Desktop User Profile -->
        <div class="d-none d-lg-flex align-items-center ms-lg-auto order-lg-2">
            <div class="dropdown">
                <a href="#" class="d-flex align-items-center text-decoration-none dropdown-toggle" id="userDropdownDesktop" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="bg-gradient-primary rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 40px; height: 40px;">
                        <i class="fas fa-user text-white"></i>
                    </div>
                    <div class="text-start d-none d-xl-block">
                        <div class="fw-semibold">
                            <?php 
                            $displayName = htmlspecialchars($user['first_name'] ?? '');
                            if (!empty($user['middle_name'])) {
                                $displayName .= ' ' . htmlspecialchars(substr($user['middle_name'], 0, 1)) . '.';
                            }
                            $displayName .= ' ' . htmlspecialchars($user['last_name'] ?? '');
                            if (!empty($user['suffix']) && $user['suffix'] !== 'None') {
                                $displayName .= ' ' . htmlspecialchars($user['suffix']);
                            }
                            echo $displayName;
                            ?>
                        </div>
                        <small class="text-muted"><?php echo htmlspecialchars($user['account_type'] ?? ''); ?> Account</small>
                    </div>
                </a>
                <ul class="dropdown-menu dropdown-menu-end shadow mt-2" aria-labelledby="userDropdownDesktop" style="min-width: 250px;">
                    <li>
                        <div class="px-4 py-3">
                            <div class="fw-bold">
                                <?php 
                                $shortName = htmlspecialchars($user['first_name'] ?? '') . ' ' . htmlspecialchars($user['last_name'] ?? '');
                                echo $shortName;
                                ?>
                            </div>
                            <div class="small text-muted"><?php echo htmlspecialchars($user['account_type'] ?? ''); ?> Account</div>
                        </div>
                    </li>
                    <li><hr class="dropdown-divider m-0"></li>
                    <li>
                        <a class="dropdown-item py-2" href="change_password.php">
                            <i class="fas fa-key me-2"></i>Change Password
                        </a>
                    </li>
                    <li><hr class="dropdown-divider m-0"></li>
                    <li>
                        <a class="dropdown-item py-2 text-danger" href="#" id="logoutLinkDesktop">
                            <i class="fas fa-sign-out-alt me-2"></i>Logout
                        </a>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Mobile User Menu (Separate collapse, positioned absolutely) -->
        <div class="collapse collapse-horizontal position-absolute top-100 end-0 mt-2" id="userMenuMobile" style="width: 300px; max-width: calc(100vw - 2rem); z-index: 1050;">
            <div class="card shadow-lg border-0 rounded-3">
                <div class="card-body p-4">
                    <!-- User Info -->
                    <div class="d-flex align-items-center mb-4">
                        <div class="bg-gradient-primary rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 48px; height: 48px;">
                            <i class="fas fa-user text-white fs-5"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="fw-bold text-truncate">
                                <?php 
                                $mobileDisplayName = htmlspecialchars($user['first_name'] ?? '');
                                if (!empty($user['middle_name'])) {
                                    $mobileDisplayName .= ' ' . htmlspecialchars(substr($user['middle_name'], 0, 1)) . '.';
                                }
                                $mobileDisplayName .= ' ' . htmlspecialchars($user['last_name'] ?? '');
                                if (!empty($user['suffix']) && $user['suffix'] !== 'None') {
                                    $mobileDisplayName .= ' ' . htmlspecialchars($user['suffix']);
                                }
                                echo $mobileDisplayName;
                                ?>
                            </div>
                            <small class="text-muted"><?php echo htmlspecialchars($user['account_type'] ?? ''); ?> Account</small>
                        </div>
                    </div>
                    
                    <!-- Mobile User Menu -->
                    <div class="mb-4">
                        <a href="change_password.php" class="btn btn-outline-primary w-100 d-flex align-items-center justify-content-center mb-2">
                            <i class="fas fa-key me-2"></i>Change Password
                        </a>
                    </div>
                    
                    <!-- Mobile Logout -->
                    <div class="d-grid">
                        <a href="#" class="btn btn-danger d-flex align-items-center justify-content-center" id="logoutLinkMobile">
                            <i class="fas fa-sign-out-alt me-2"></i>Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</nav>

<!-- Add this script section for SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize Bootstrap collapse for mobile menus
    const navbarCollapse = document.getElementById('navbarNav');
    const userMenuCollapse = document.getElementById('userMenuMobile');
    
    let bsNavbarCollapse = null;
    let bsUserCollapse = null;
    
    if (navbarCollapse) {
        bsNavbarCollapse = new bootstrap.Collapse(navbarCollapse, {
            toggle: false
        });
    }
    
    if (userMenuCollapse) {
        bsUserCollapse = new bootstrap.Collapse(userMenuCollapse, {
            toggle: false
        });
    }

    // Function to handle logout
    function handleLogout(e) {
        e.preventDefault();
        
        Swal.fire({
            title: 'Logout Confirmation',
            text: "Are you sure you want to log out?",
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#667eea',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, logout',
            cancelButtonText: 'Cancel',
            reverseButtons: true
        }).then((result) => {
            if (result.isConfirmed) {
                // Add the logout parameter to the URL
                const currentUrl = window.location.href.split('?')[0];
                window.location.href = currentUrl + "?logout=true";
            }
        });
    }

    // Attach logout handlers to both desktop and mobile links
    const logoutDesktop = document.getElementById('logoutLinkDesktop');
    const logoutMobile = document.getElementById('logoutLinkMobile');
    
    if (logoutDesktop) logoutDesktop.addEventListener('click', handleLogout);
    if (logoutMobile) logoutMobile.addEventListener('click', handleLogout);

    // Add active class to current page nav item
    const currentPage = window.location.pathname.split('/').pop();
    const navLinks = document.querySelectorAll('.navbar-nav .nav-link');
    
    navLinks.forEach(link => {
        const linkHref = link.getAttribute('href');
        if (linkHref && (linkHref === currentPage || linkHref.includes(currentPage))) {
            link.classList.add('active');
        } else {
            link.classList.remove('active');
        }
    });

    // Close mobile menus when clicking on a link
    const mobileNavLinks = document.querySelectorAll('#navbarNav .nav-link, #userMenuMobile a');
    
    mobileNavLinks.forEach(link => {
        link.addEventListener('click', function() {
            // Close main navbar if open on mobile
            if (window.innerWidth < 992) {
                if (navbarCollapse && navbarCollapse.classList.contains('show')) {
                    bsNavbarCollapse.hide();
                }
                
                // Close user menu if open
                if (userMenuCollapse && userMenuCollapse.classList.contains('show')) {
                    bsUserCollapse.hide();
                }
            }
        });
    });

    // Ensure user menu doesn't open when main nav is toggled and vice versa
    const mainNavToggle = document.querySelector('[data-bs-target="#navbarNav"]');
    const userMenuToggle = document.querySelector('[data-bs-target="#userMenuMobile"]');
    
    if (mainNavToggle && userMenuToggle && bsNavbarCollapse && bsUserCollapse) {
        mainNavToggle.addEventListener('click', function() {
            if (userMenuCollapse.classList.contains('show')) {
                bsUserCollapse.hide();
            }
        });
        
        userMenuToggle.addEventListener('click', function() {
            if (navbarCollapse.classList.contains('show')) {
                bsNavbarCollapse.hide();
            }
        });
    }

    // Close dropdowns when clicking outside
    document.addEventListener('click', function(e) {
        // Close desktop dropdown
        if (!e.target.closest('#userDropdownDesktop') && !e.target.matches('#userDropdownDesktop')) {
            const dropdown = document.querySelector('#userDropdownDesktop');
            if (dropdown && dropdown.classList.contains('show')) {
                const bsDropdown = bootstrap.Dropdown.getInstance(dropdown);
                if (bsDropdown) {
                    bsDropdown.hide();
                }
            }
        }
        
        // Close mobile user menu when clicking outside
        if (window.innerWidth < 992) {
            const userMenu = document.getElementById('userMenuMobile');
            const userToggle = document.querySelector('[data-bs-target="#userMenuMobile"]');
            
            if (userMenu && userMenu.classList.contains('show') && 
                !e.target.closest('#userMenuMobile') && 
                !e.target.closest('[data-bs-target="#userMenuMobile"]')) {
                bsUserCollapse.hide();
            }
        }
    });

    // Close mobile menus on window resize (when switching to desktop)
    window.addEventListener('resize', function() {
        if (window.innerWidth >= 992) {
            if (navbarCollapse && navbarCollapse.classList.contains('show')) {
                bsNavbarCollapse.hide();
            }
            if (userMenuCollapse && userMenuCollapse.classList.contains('show')) {
                bsUserCollapse.hide();
            }
        }
    });
});
</script>

<style>
    /* Add these styles to your existing CSS in customer_dashboard.php */
    
    /* Gradient text for brand */
    .text-gradient {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }
    
    /* Modern navbar styles */
    .navbar {
        padding-top: 0.75rem;
        padding-bottom: 0.75rem;
        backdrop-filter: blur(10px);
        background: rgba(255, 255, 255, 0.98) !important;
        border-bottom: 1px solid rgba(0,0,0,0.05);
    }
    
    .nav-link {
        color: #4b5563 !important;
        transition: all 0.3s ease;
        padding: 0.5rem 1rem !important;
        border-radius: 8px;
        margin: 0 0.25rem;
    }
    
    .nav-link:hover {
        color: #667eea !important;
        background: rgba(102, 126, 234, 0.1);
        transform: translateY(-1px);
    }
    
    .nav-link.active {
        color: #667eea !important;
        background: rgba(102, 126, 234, 0.1);
        font-weight: 600;
    }
    
    /* Style the dropdown toggle to look clickable */
    .dropdown-toggle {
        cursor: pointer;
        transition: all 0.3s ease;
        color: #4b5563 !important;
    }
    
    .dropdown-toggle:hover {
        color: #667eea !important;
    }
    
    .dropdown-menu {
        border: none;
        border-radius: 12px;
        margin-top: 0.75rem;
        animation: slideDown 0.2s ease-out;
        box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        border: 1px solid rgba(0,0,0,0.05);
    }
    
    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .dropdown-item {
        padding: 0.75rem 1rem;
        border-radius: 8px;
        margin: 0.125rem 0.5rem;
        width: calc(100% - 1rem);
        transition: all 0.2s ease;
    }
    
    .dropdown-item:hover {
        background: rgba(102, 126, 234, 0.1);
        color: #667eea;
        transform: translateX(5px);
    }
    
    .bg-gradient-primary {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    }
    
    /* Mobile menu specific styles */
    #navbarNav {
        max-height: calc(100vh - 80px);
        overflow-y: auto;
    }
    
    /* Mobile User Menu Card */
    #userMenuMobile .card {
        animation: slideInRight 0.3s ease-out;
    }
    
    @keyframes slideInRight {
        from {
            opacity: 0;
            transform: translateX(10px);
        }
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }
    
    /* Separate mobile toggle buttons */
    .d-lg-none .navbar-toggler {
        margin: 0;
        padding: 8px;
    }
    
    .d-lg-none .btn-link[data-bs-target="#userMenuMobile"] {
        padding: 4px;
        margin-right: 8px;
    }
    
    /* Mobile responsive adjustments */
    @media (max-width: 991.98px) {
        .navbar-collapse#navbarNav {
            position: absolute;
            top: 100%;
            left: 1rem;
            right: 1rem;
            z-index: 1040;
            background: white;
            border-radius: 12px;
            margin-top: 0.5rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            border: 1px solid #e5e7eb;
        }
        
        #userMenuMobile {
            position: absolute;
            top: 100%;
            right: 1rem;
            margin-top: 0.5rem;
        }
        
        .nav-item {
            margin: 0.25rem 0;
        }
        
        .nav-link {
            padding: 0.75rem 1rem !important;
            margin: 0.125rem 0;
        }
        
        /* Hide desktop user profile on mobile */
        .d-none.d-lg-flex {
            display: none !important;
        }
        
        .container-fluid {
            padding-left: 1rem;
            padding-right: 1rem;
        }
    }
    
    @media (min-width: 992px) {
        /* Hide mobile user menu on desktop */
        #userMenuMobile {
            display: none !important;
        }
        
        /* Ensure desktop profile shows */
        .d-none.d-lg-flex {
            display: flex !important;
        }
        
        /* Hide mobile toggle buttons on desktop */
        .d-lg-none {
            display: none !important;
        }
        
        /* Remove background, border, and shadow from desktop navbar collapse */
        .navbar-collapse#navbarNav {
            background: transparent !important;
            box-shadow: none !important;
            border: none !important;
            position: static !important;
            margin-top: 0 !important;
            max-height: none !important;
            overflow-y: visible !important;
        }
        
        /* Desktop navigation links should appear normal */
        .navbar-nav .nav-link {
            background: transparent !important;
            color: #4b5563 !important;
        }
        
        .navbar-nav .nav-link:hover,
        .navbar-nav .nav-link.active {
            background: rgba(102, 126, 234, 0.1) !important;
            color: #667eea !important;
        }
    }
    
    @media (max-width: 576px) {
        .navbar-brand {
            font-size: 1.1rem;
        }
        
        .container-fluid {
            padding-left: 0.75rem;
            padding-right: 0.75rem;
        }
        
        .navbar-collapse#navbarNav,
        #userMenuMobile {
            left: 0.5rem;
            right: 0.5rem;
        }
        
        #userMenuMobile {
            width: calc(100vw - 1rem) !important;
            max-width: none !important;
        }
        
        .d-lg-none .btn-link[data-bs-target="#userMenuMobile"] {
            margin-right: 6px;
        }
    }
    
    @media (max-width: 360px) {
        .navbar-brand span:not(.text-gradient) {
            display: none;
        }
        
        .container-fluid {
            padding-left: 0.5rem;
            padding-right: 0.5rem;
        }
    }
</style>