<?php
// Set timezone to Philippines
date_default_timezone_set('Asia/Manila');
session_start();

// Check if user is logged in, otherwise redirect to login page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit();
}

// Database configuration
require 'includes/db_config.php';

// Initialize user variable
$user = [];

// Get user data from database
try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    // Determine which table to query based on account source from session
    $account_source = $_SESSION['account_source'] ?? 'accounts';
    
    if ($account_source === 'accounts') {
        $stmt = $conn->prepare("SELECT first_name, middle_name, last_name, suffix, account_type, branch FROM accounts WHERE id = ?");
    } else {
        $stmt = $conn->prepare("SELECT first_name, middle_name, last_name, suffix, account_type, branch FROM proxy_accounts WHERE id = ?");
    }
    
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
    } else {
        throw new Exception("User not found");
    }
    
    $stmt->close();

    // Define restricted account types
    $restricted_account_types = ['Branch Manager', 'Proxy Collector-Cashier', 'Proxy Collector'];
    
    // Initialize branch options
    $all_branches = ['Tuguegarao City', 'Gattaran', 'Abulug', 'Ilagan'];
    $user_branch = $user['branch'] ?? '';
    $user_account_type = $user['account_type'] ?? '';
    $branch_disabled = false;
    $selected_branch = '';
    $available_branches = [];

    // Determine available branches based on account type
    if ($user_account_type === 'Admin') {
        if ($user_branch === 'All Branches') {
            // Admin with "All Branches" can see all branches
            $available_branches = $all_branches;
            $branch_disabled = false;
        } else {
            // Admin with specific branch can only see that branch
            $available_branches = [$user_branch];
            $branch_disabled = true;
            $selected_branch = $user_branch;
        }
    } elseif ($user_account_type === 'Area Manager') {
        // Area Manager can have multiple branches
        if (!empty($user_branch)) {
            $user_branches = explode(', ', $user_branch);
            $available_branches = array_intersect($all_branches, $user_branches);
            $branch_disabled = false;
        } else {
            $available_branches = [];
            $branch_disabled = true;
        }
    } elseif (in_array($user_account_type, $restricted_account_types)) {
        // Other restricted account types can only see their assigned branch
        $available_branches = [$user_branch];
        $branch_disabled = true;
        $selected_branch = $user_branch;
    } else {
        // Default: show all branches (for other account types)
        $available_branches = $all_branches;
        $branch_disabled = false;
    }

    // Initialize variables
    $success_message = '';
    $error_message = '';
    $target_dir = __DIR__ . "/uploads/profile_assets/images/"; // Use absolute path

    // Create uploads directory if it doesn't exist
    if (!file_exists($target_dir)) {
        if (!mkdir($target_dir, 0755, true)) {
            die("Failed to create upload directory");
        }
    }

    // Check if directory is writable
    if (!is_writable($target_dir)) {
        die("Upload directory is not writable. Please check permissions.");
    }

    // Process form submission
    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        // Get form data with proper validation
        $employee_number = isset($_POST['employee_number']) ? $conn->real_escape_string(trim($_POST['employee_number'])) : '';
        $last_name = isset($_POST['last_name']) ? $conn->real_escape_string($_POST['last_name']) : '';
        $first_name = isset($_POST['first_name']) ? $conn->real_escape_string($_POST['first_name']) : '';
        $middle_name = isset($_POST['middle_name']) ? $conn->real_escape_string($_POST['middle_name']) : null;
        $suffix = !empty($_POST['suffix']) ? $conn->real_escape_string($_POST['suffix']) : null;
        $address = isset($_POST['address']) ? $conn->real_escape_string($_POST['address']) : '';
        $contact_number = isset($_POST['contact_number']) ? $conn->real_escape_string($_POST['contact_number']) : '';
        $account_type = isset($_POST['account_type']) ? $conn->real_escape_string($_POST['account_type']) : '';
        $status = isset($_POST['status']) ? $conn->real_escape_string($_POST['status']) : '';
        $gender = isset($_POST['gender']) ? $conn->real_escape_string($_POST['gender']) : '';
        $area = isset($_POST['area']) ? $conn->real_escape_string($_POST['area']) : '';
        $branch = isset($_POST['branch']) ? $conn->real_escape_string($_POST['branch']) : '';
        $birth_date = isset($_POST['birth_date']) ? $conn->real_escape_string($_POST['birth_date']) : '';
        $place_of_birth = isset($_POST['place_of_birth']) ? $conn->real_escape_string($_POST['place_of_birth']) : '';
        $spouse_name = isset($_POST['spouse_name']) ? $conn->real_escape_string($_POST['spouse_name']) : null;
        $account_status = isset($_POST['account_status']) ? $conn->real_escape_string($_POST['account_status']) : 'Active';
        $registration_date = date('Y-m-d'); // Only date, no time
        
        // Validate required fields including employee_number
        if (empty($employee_number) || empty($last_name) || empty($first_name) || empty($address) || empty($contact_number) || 
            empty($status) || empty($gender) || empty($branch) || empty($birth_date) || empty($place_of_birth) || 
            empty($account_status) || empty($account_type)) {
            throw new Exception("All required fields must be filled");
        }

        // Validate employee number format
        if (!preg_match('/^[A-Z0-9\-]+$/', $employee_number)) {
            throw new Exception("Employee number must contain only uppercase letters, numbers, and hyphens");
        }

        // Check account type
        $is_collector = ($account_type === 'Collector');
        $is_proxy = ($account_type === 'Proxy Collector' || $account_type === 'Proxy Collector-Cashier');
        
        // For all account types, validate login credentials
        $username = isset($_POST['username']) ? $conn->real_escape_string($_POST['username']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        $confirm_password = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';

        // Validate username and password for all account types
        if (empty($username) || empty($password) || empty($confirm_password)) {
            throw new Exception("Username and password are required for all account types");
        }

        // Validate password match
        if ($password !== $confirm_password) {
            throw new Exception("Password and Confirm Password do not match");
        }

        // Validate password strength
        if (strlen($password) < 6) {
            throw new Exception("Password must be at least 6 characters long");
        }

        // Check if username already exists in either table
        $check_username_stmt = $conn->prepare("SELECT id FROM collector_accounts WHERE username = ? 
                                              UNION 
                                              SELECT id FROM proxy_accounts WHERE username = ?");
        $check_username_stmt->bind_param("ss", $username, $username);
        $check_username_stmt->execute();
        $username_result = $check_username_stmt->get_result();
        
        if ($username_result->num_rows > 0) {
            throw new Exception("Username already exists. Please choose a different username.");
        }
        $check_username_stmt->close();

        // Check if employee number already exists in either table
        $check_emp_stmt = $conn->prepare("SELECT id FROM collector_accounts WHERE employee_number = ? 
                                         UNION 
                                         SELECT id FROM proxy_accounts WHERE employee_number = ?");
        $check_emp_stmt->bind_param("ss", $employee_number, $employee_number);
        $check_emp_stmt->execute();
        $emp_result = $check_emp_stmt->get_result();
        
        if ($emp_result->num_rows > 0) {
            throw new Exception("Employee number already exists. Please use a unique employee number.");
        }
        $check_emp_stmt->close();

        // For Collectors, validate area and check if area already exists
        if ($is_collector) {
            if (empty($area)) {
                throw new Exception("Area is required for Collector accounts");
            }
            
            // Check if area already exists in the same branch
            $check_area_stmt = $conn->prepare("SELECT id FROM collector_accounts WHERE area = ? AND branch = ? AND account_status != 'Inactive'");
            $check_area_stmt->bind_param("ss", $area, $branch);
            $check_area_stmt->execute();
            $area_result = $check_area_stmt->get_result();
            
            if ($area_result->num_rows > 0) {
                throw new Exception("A collector is already assigned to this area in the selected branch. Please choose a different area or branch.");
            }
            $check_area_stmt->close();
        } else {
            // For Proxy accounts, set area to "All Areas" if not provided
            if (empty($area)) {
                $area = "All Areas";
            }
        }

        // Validate contact number format
        $contact_number = preg_replace('/[^0-9]/', '', $contact_number); // Remove all non-numeric characters
        if (strlen($contact_number) != 11 || substr($contact_number, 0, 2) != '09') {
            throw new Exception("Contact number must be 11 digits starting with '09'");
        }

        // Handle file upload
        $profile_image = null;
        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == UPLOAD_ERR_OK) {
            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0777, true);
            }
            
            $file_extension = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
            $new_filename = uniqid() . '.' . $file_extension;
            $target_file = $target_dir . $new_filename;
            
            // Check if image file is a actual image
            $check = getimagesize($_FILES['profile_image']['tmp_name']);
            if ($check !== false) {
                if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $target_file)) {
                    $profile_image = "uploads/profile_assets/images/" . $new_filename; // Store relative path
                } else {
                    throw new Exception("Sorry, there was an error uploading your file.");
                }
            } else {
                throw new Exception("File is not an image.");
            }
        }

        // Hash the password for all account types
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // Insert into appropriate table based on account type with employee_number
        if ($is_collector) {
            // Prepare SQL statement for collector_accounts
            $stmt = $conn->prepare("INSERT INTO collector_accounts 
                (employee_number, last_name, first_name, middle_name, suffix, address, contact_number, account_type, registration_date,
                status, gender, area, branch, birth_date, place_of_birth, spouse_name, account_status, profile_image, username, password) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }

            // Bind parameters
            $stmt->bind_param("ssssssssssssssssssss", 
                $employee_number, $last_name, $first_name, $middle_name, $suffix, $address, $contact_number, $account_type, $registration_date,
                $status, $gender, $area, $branch, $birth_date, $place_of_birth, $spouse_name, $account_status, $profile_image, $username, $hashed_password);
        } else {
            // Prepare SQL statement for proxy_accounts
            $stmt = $conn->prepare("INSERT INTO proxy_accounts 
                (employee_number, last_name, first_name, middle_name, suffix, address, contact_number, account_type, registration_date,
                status, gender, area, branch, birth_date, place_of_birth, spouse_name, account_status, profile_image, username, password) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }

            // Bind parameters
            $stmt->bind_param("ssssssssssssssssssss", 
                $employee_number, $last_name, $first_name, $middle_name, $suffix, $address, $contact_number, $account_type, $registration_date,
                $status, $gender, $area, $branch, $birth_date, $place_of_birth, $spouse_name, $account_status, $profile_image, $username, $hashed_password);
        }

        // Execute the statement
        if ($stmt->execute()) {
            $stmt->close();
            $conn->close();
            
            // Set success message in session and redirect
            $account_type_name = $is_collector ? "Collector" : "Proxy Collector";
            $_SESSION['success_message'] = "$account_type_name registered successfully! Employee Number: " . $employee_number;
            header("Location: collector_registration.php");
            exit();
        } else {
            throw new Exception("Error: " . $stmt->error);
        }
    }
} catch (Exception $e) {
    $error_message = $e->getMessage();
}

// Close connection if opened
if (isset($conn)) {
    $conn->close();
}

// Handle logout
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    setcookie('remember_me', '', time() - 3600, "/");
    header("Location: index.php");
    exit;
}

// Check for success message in session
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Shared layout includes (includes/topbar.php, includes/sidebar.php) read these.
$layout_active = 'proxy-registration';

// Fallback identity so the chrome always has a usable $user array.
if (!isset($user) || !is_array($user) || $user === array()) {
    $user = array(
        'account_type' => $_SESSION['account_type'] ?? '',
        'first_name'   => $_SESSION['first_name'] ?? '',
    );
}
?>

<!DOCTYPE html>
<html lang="en">
    <head>
        <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
        <meta charset="utf-8" />
        <meta http-equiv="X-UA-Compatible" content="IE=edge" />
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
        <meta name="description" content="" />
        <meta name="author" content="" />
        <title>Collector Registration - Prime City Admin</title>
        <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
        <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="assets/css/dash.css" rel="stylesheet" />
        <link href="assets/css/dash-ui.css" rel="stylesheet" />
        <link href="assets/css/customer-registration-style.css" rel="stylesheet" />
        <!-- SweetAlert2 CSS -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
        <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
        <style>
            .registration-card {
                max-width: none;
                margin: 0;
            }
            .form-section {
                margin-bottom: 0;
                padding: 1.35rem 0 0;
                border-top: 1px solid #eef4fc;
            }
            .form-section:first-of-type {
                padding-top: 0;
                border-top: 0;
            }
            .profile-image-preview {
                width: 150px;
                height: 150px;
                object-fit: cover;
                display: block;
                margin: 0 auto;
                margin-bottom: 10px;
                margin-top: 10px;
            }
            .required-field::after {
                content: " *";
                color: red;
            }
            .form-floating {
                margin-bottom: 1rem;
            }
            .password-toggle {
                position: absolute;
                right: 10px;
                top: 50%;
                transform: translateY(-50%);
                background: none;
                border: none;
                cursor: pointer;
                z-index: 3;
            }
            .password-input-group {
                position: relative;
            }
            .branch-info {
                background-color: #e9ecef;
                padding: 10px;
                border-radius: 5px;
                margin-bottom: 15px;
                font-size: 0.9rem;
            }
            .disabled-field { 
                background-color: #e9ecef; 
                opacity: 1; 
            }
            .account-type-section {
                background: transparent;
                padding: 0;
                border-radius: 0;
                margin-bottom: 0;
            }
            .card-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .back-button {
                margin-left: 10px;
            }
            .employee-number-field {
                text-transform: uppercase;
                font-family: monospace;
            }
            .input-hint {
                font-size: 0.875rem;
                color: #6c757d;
                margin-top: 0.25rem;
            }
        </style>
    </head>
    <body class="sb-nav-fixed">
        <?php include __DIR__ . '/includes/topbar.php'; ?>

        <div id="layoutSidenav">
            <?php include __DIR__ . '/includes/sidebar.php'; ?>

            <div id="layoutSidenav_content">
                <main>
                    <div class="dash">
                        <header class="dash-head">
                            <div>
                                <p class="dash-eyebrow">
                                    <i class="fas fa-user-plus" aria-hidden="true"></i>
                                    Prime City &middot; Employees
                                </p>
                                <h1 class="dash-title">Register Proxy</h1>
                                <p class="dash-sub">Personal, contact, area and sign-in details for a new proxy account.</p>
                            </div>

                            <div class="dash-head__actions">
                                <a class="btn btn-primary" href="employee_lists.php">
                                    <i class="fas fa-arrow-left me-1" aria-hidden="true"></i>
                                    Back to employee list
                                </a>
                            </div>
                        </header>

                        <section class="dash-card">
                            <header class="dash-card__head">
                                <span class="dash-card__icon"><i class="fas fa-id-card-clip" aria-hidden="true"></i></span>
                                <div>
                                    <h2 class="dash-card__title">Proxy Registration</h2>
                                    <p class="dash-card__hint">Fields marked with an asterisk are required</p>
                                </div>
                            </header>

                            <div class="dash-card__body">
                                <form id="collectorRegistrationForm" class="dash-form" action="proxy_registration.php" method="POST" enctype="multipart/form-data">
                                    <!-- Account Type Selection -->
                                    <div class="form-section account-type-section">
                                        <h5 class="section-title">Account Type</h5>
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <div class="form-floating">
                                                    <select class="form-select" id="account_type" name="account_type" required onchange="toggleFields()">
                                                        <option value="">Select Account Type</option>
                                                        <option value="Proxy Collector">Proxy Collector</option>
                                                        <option value="Proxy Collector-Cashier">Proxy Collector-Cashier</option>
                                                    </select>
                                                    <label for="account_type" class="required-field">Account Type</label>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-floating">
                                                    <input type="date" class="form-control" id="registration_date" name="registration_date" readonly>
                                                    <label for="registration_date">Registration Date</label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Personal Information Section -->
                                    <div class="form-section">
                                        <h5 class="section-title">Personal Information</h5>

                                            <div class="profile-upload">
                                                <span class="upload__label" id="profileImageLabel">Profile Image</span>
                                                <label class="upload" for="profile_image" id="profileImageZone" aria-labelledby="profileImageLabel">
                                                    <input
                                                        type="file"
                                                        class="upload__input"
                                                        id="profile_image"
                                                        name="profile_image"
                                                        accept="image/*"
                                                    >
                                                    <img id="profile_image_preview" class="preview-image" src="#" alt="Selected profile photo">
                                                    <span class="upload__icon"><i class="fas fa-cloud-arrow-up" aria-hidden="true"></i></span>
                                                    <span class="upload__title">Drop a photo here or click to browse</span>
                                                    <span class="upload__hint">JPG, JPEG, PNG or GIF &middot; up to 5 MB</span>
                                                </label>
                                                <div class="upload__meta">
                                                    <span class="upload__name" id="profileImageName">No file selected</span>
                                                    <button type="button" class="upload__clear" id="profileImageClear" hidden>Remove</button>
                                                </div>
                                            </div>

                                        <div class="row g-3">
                                            <div class="col-md-3">
                                                <div class="form-floating">
                                                    <input type="text" class="form-control" id="last_name" name="last_name" placeholder="Last Name" required>
                                                    <label for="last_name" class="required-field">Last Name</label>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-floating">
                                                    <input type="text" class="form-control" id="first_name" name="first_name" placeholder="First Name" required>
                                                    <label for="first_name" class="required-field">First Name</label>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-floating">
                                                    <input type="text" class="form-control" id="middle_name" name="middle_name" placeholder="Middle Name">
                                                    <label for="middle_name">Middle Name</label>
                                                </div>
                                            </div>
                                            <div class="col-md-2">
                                                <div class="form-floating">
                                                    <select class="form-select" id="suffix" name="suffix">
                                                        <option value="None">None</option>
                                                        <option value="Jr">Jr</option>
                                                        <option value="Sr">Sr</option>
                                                        <option value="II">II</option>
                                                        <option value="III">III</option>
                                                        <option value="IV">IV</option>
                                                    </select>
                                                    <label for="suffix">Suffix</label>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="row g-3 mt-2">
                                            <div class="col-md-3">
                                                <div class="form-floating">
                                                    <select class="form-select" id="status" name="status" required>
                                                        <option value="">Select Status</option>
                                                        <option value="Single">Single</option>
                                                        <option value="Married">Married</option>
                                                        <option value="Widow">Widow</option>
                                                        <option value="Separated">Separated</option>
                                                    </select>
                                                    <label for="status" class="required-field">Status</label>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-floating">
                                                    <select class="form-select" id="gender" name="gender" required>
                                                        <option value="">Select Gender</option>
                                                        <option value="Male">Male</option>
                                                        <option value="Female">Female</option>
                                                    </select>
                                                    <label for="gender" class="required-field">Gender</label>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-floating">
                                                    <input type="date" class="form-control" id="birth_date" name="birth_date" required>
                                                    <label for="birth_date" class="required-field">Birth Date</label>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-floating">
                                                    <input type="text" class="form-control" id="place_of_birth" name="place_of_birth" placeholder="Place of Birth" required>
                                                    <label for="place_of_birth" class="required-field">Place of Birth</label>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="row g-3 mt-2">
                                            <div class="col-md-6">
                                                <div class="form-floating">
                                                    <input type="text" class="form-control" id="spouse_name" name="spouse_name" placeholder="Spouse Name">
                                                    <label for="spouse_name">Spouse Name (if applicable)</label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Contact Information Section -->
                                    <div class="form-section">
                                        <h5 class="section-title">Contact Information</h5>
                                        <div class="row g-3">
                                            <div class="col-12">
                                                <div class="form-floating">
                                                    <textarea class="form-control" id="address" name="address" placeholder="Address" style="height: 100px" required></textarea>
                                                    <label for="address" class="required-field">Address</label>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-floating">
                                                    <input type="tel" class="form-control" id="contact_number" name="contact_number" 
                                                        pattern="[0-9]{11}" title="Please enter exactly 11 digits starting with 09" 
                                                        maxlength="11" placeholder="Contact Number" required>
                                                    <label for="contact_number" class="required-field">Contact Number</label>
                                                    <small class="text-muted">Must be 11 digits starting with "09"</small>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-floating">
                                                    <select class="form-select" id="area" name="area">
                                                        <option value="">Select Area</option>
                                                        <option value="Area 1">Area 1</option>
                                                        <option value="Area 2">Area 2</option>
                                                        <option value="Area 3">Area 3</option>
                                                        <option value="Area 4">Area 4</option>
                                                        <option value="Area 5">Area 5</option>
                                                        <option value="Area 6">Area 6</option>
                                                        <option value="Area 7">Area 7</option>
                                                        <option value="Area 8">Area 8</option>
                                                        <option value="Area 9">Area 9</option>
                                                        <option value="Area 10">Area 10</option>
                                                    </select>
                                                    <label for="area" id="area-label">Area</label>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-floating">
                                                    <select class="form-select" id="branch" name="branch" 
                                                        <?php echo $branch_disabled ? 'disabled' : ''; ?> required>
                                                        <option value="">Select Branch</option>
                                                        <?php foreach ($available_branches as $branch): ?>
                                                            <option value="<?php echo $branch; ?>" 
                                                                <?php echo ($branch_disabled && $branch === $selected_branch) ? 'selected' : ''; ?>>
                                                                <?php echo $branch; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <?php if ($branch_disabled): ?>
                                                        <input type="hidden" name="branch" value="<?php echo $selected_branch; ?>">
                                                    <?php endif; ?>
                                                    <label for="branch" class="required-field">Branch</label>
                                                    <?php if ($branch_disabled): ?>
                                                        <small class="text-muted">Branch is automatically set to your assigned branch (<?php echo $selected_branch; ?>)</small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-floating">
                                                    <select class="form-select" id="account_status" name="account_status" required>
                                                        <option value="Active">Active</option>
                                                        <option value="Inactive">Inactive</option>
                                                        <option value="Suspended">Suspended</option>
                                                    </select>
                                                    <label for="account_status" class="required-field">Account Status</label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Employee Number Section -->
                                    <div class="form-section">
                                        <h5 class="section-title">Employee Information</h5>
                                        <div class="row g-3">
                                            <div class="col-md-12">
                                                <div class="form-floating">
                                                    <input type="text" class="form-control employee-number-field" id="employee_number" name="employee_number" 
                                                           placeholder="Employee Number" 
                                                           oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9\-]/g, '')"
                                                           pattern="[A-Z0-9\-]+"
                                                           title="Uppercase letters, numbers, and hyphens only"
                                                           required>
                                                    <label for="employee_number" class="required-field">Employee Number</label>
                                                </div>
                                                <div class="input-hint">
                                                    <i class="fas fa-info-circle"></i> Use numbers only (e.g., 00001)
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Login Credentials Section (For All Account Types) -->
                                    <div class="form-section" id="login-credentials-section">
                                        <h5 class="section-title">Login Credentials</h5>
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <div class="form-floating">
                                                    <input type="text" class="form-control" id="username" name="username" placeholder="Username" autocomplete="off" required>
                                                    <label for="username" class="required-field">Username</label>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-floating password-input-group">
                                                    <input type="password" class="form-control" id="password" name="password" placeholder="Password" autocomplete="new-password" required>
                                                    <label for="password" class="required-field">Password</label>
                                                    <button type="button" class="password-toggle" onclick="togglePassword('password')"
                                                            aria-label="Show password" aria-pressed="false" title="Show password">
                                                        <i class="fas fa-eye" aria-hidden="true"></i>
                                                    </button>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-floating password-input-group">
                                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Confirm Password" autocomplete="new-password" required>
                                                    <label for="confirm_password" class="required-field">Confirm Password</label>
                                                    <button type="button" class="password-toggle" onclick="togglePassword('confirm_password')"
                                                            aria-label="Show password confirmation" aria-pressed="false" title="Show password confirmation">
                                                        <i class="fas fa-eye" aria-hidden="true"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="dash-form__actions">
                                        <a class="btn btn-outline-secondary" href="employee_lists.php">Cancel</a>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-check me-1" aria-hidden="true"></i>
                                            Register Proxy
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </section>
                    </div>
                </main>

                <?php include __DIR__ . '/includes/footer.php'; ?>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
        <script src="js/scripts.js"></script>
        <!-- SweetAlert2 JS -->
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <script src="assets/js/pc-toast.js?v=<?php echo is_file(__DIR__ . '/assets/js/pc-toast.js') ? filemtime(__DIR__ . '/assets/js/pc-toast.js') : 1; ?>"></script>
        <script>
            // Function to toggle fields based on account type
            /* Toasts come from assets/js/pc-toast.js (PCToast), the same
               component the customer list and collector page use. */
            function toast(type, title, text) {
                if (window.PCToast) {
                    window.PCToast.show(type, title, text);
                    return;
                }

                if (window.Swal) {
                    window.Swal.fire({ icon: type === 'success' ? 'success' : 'error', title: title, text: text, toast: true, position: 'top-end', showConfirmButton: false, timer: 4000 });
                }
            }

            function toastError(title, text) {
                toast('error', title, text);
            }

            function toggleFields() {
                const accountType = document.getElementById('account_type').value;
                const areaField = document.getElementById('area');
                const areaLabel = document.getElementById('area-label');
                
                // Update area field based on account type
                if (accountType === 'Collector') {
                    // Make area required for Collectors
                    areaField.required = true;
                    areaLabel.innerHTML = 'Area <span style="color: red;">*</span>';
                } else if (accountType === 'Proxy Collector' || accountType === 'Proxy Collector-Cashier') {
                    // Make area not required for Proxy Collectors
                    areaField.required = false;
                    areaLabel.innerHTML = 'Area';
                    
                    // Set area to "All Areas" for Proxy accounts if empty
                    if (areaField.value === '') {
                        areaField.value = 'All Areas';
                    }
                } else {
                    // Reset if no account type selected
                    areaField.required = false;
                    areaLabel.innerHTML = 'Area';
                }
            }

            // Set current date as registration date in Philippine time zone
            /* Profile image drop zone: click to browse, drag to drop, file name,
               clear button and the round preview. */
            function initProfileZone() {
                var input = document.getElementById('profile_image');
                var zone = document.getElementById('profileImageZone');

                if (!input || !zone) {
                    return;
                }

                var nameLabel = document.getElementById('profileImageName');
                var clearButton = document.getElementById('profileImageClear');
                var preview = document.getElementById('profile_image_preview');
                var emptyText = nameLabel ? nameLabel.textContent : '';

                zone.classList.add('is-photo');

                function sync() {
                    var files = input.files;
                    var count = files ? files.length : 0;

                    zone.classList.toggle('has-file', count > 0);

                    if (nameLabel) {
                        if (count === 0) {
                            nameLabel.textContent = emptyText;
                            nameLabel.classList.remove('is-set');
                            nameLabel.removeAttribute('title');
                        } else {
                            nameLabel.textContent = files[0].name;
                            nameLabel.classList.add('is-set');
                            nameLabel.title = files[0].name;
                        }
                    }

                    if (clearButton) {
                        clearButton.hidden = count === 0;
                    }

                    if (!preview) {
                        return;
                    }

                    if (count === 0) {
                        preview.classList.remove('d-none');
                        preview.removeAttribute('src');
                        preview.style.display = 'none';
                        return;
                    }

                    var reader = new FileReader();

                    reader.onload = function (loadEvent) {
                        preview.classList.remove('d-none');
                        preview.src = loadEvent.target.result;
                        preview.style.display = 'block';
                    };

                    reader.readAsDataURL(files[0]);
                }

                input.addEventListener('change', sync);

                ['dragenter', 'dragover'].forEach(function (type) {
                    zone.addEventListener(type, function (event) {
                        event.preventDefault();
                        zone.classList.add('is-dragover');
                    });
                });

                ['dragleave', 'dragend'].forEach(function (type) {
                    zone.addEventListener('click', function () {
                        zone.classList.remove('is-dragover');
                    });
                });

                zone.addEventListener('drop', function (event) {
                    event.preventDefault();
                    zone.classList.remove('is-dragover');

                    var dropped = event.dataTransfer ? event.dataTransfer.files : null;

                    if (!dropped || dropped.length === 0) {
                        return;
                    }

                    var transfer = new DataTransfer();
                    transfer.items.add(dropped[0]);

                    try {
                        input.files = transfer.files;
                    } catch (error) {
                        return;
                    }

                    input.dispatchEvent(new Event('change', { bubbles: true }));
                });

                if (clearButton) {
                    clearButton.addEventListener('click', function (event) {
                        event.preventDefault();
                        input.value = '';
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                }

                sync();
            }
            document.addEventListener('DOMContentLoaded', function() {
                // Create date in Philippine time zone
                const philippinesTime = new Date().toLocaleString("en-US", {timeZone: "Asia/Manila"});
                const now = new Date(philippinesTime);
                
                // Format the date for date input (YYYY-MM-DD)
                const year = now.getFullYear();
                const month = String(now.getMonth() + 1).padStart(2, '0');
                const day = String(now.getDate()).padStart(2, '0');
                
                const formattedDate = `${year}-${month}-${day}`;
                document.getElementById('registration_date').value = formattedDate;
                
                // Profile image drop zone
                initProfileZone();
                
                // Contact number validation - only allow numbers
                document.getElementById('contact_number').addEventListener('input', function(e) {
                    this.value = this.value.replace(/[^0-9]/g, '');
                });
                
                // Password toggle function
                window.togglePassword = function(fieldId) {
                    const field = document.getElementById(fieldId);
                    const toggleButton = field.parentNode.querySelector('.password-toggle i');
                    
                    if (field.type === 'password') {
                        field.type = 'text';
                        toggleButton.classList.remove('fa-eye');
                        toggleButton.classList.add('fa-eye-slash');
                    } else {
                        field.type = 'password';
                        toggleButton.classList.remove('fa-eye-slash');
                        toggleButton.classList.add('fa-eye');
                    }
                };
                
                // Form validation
                document.getElementById('collectorRegistrationForm').addEventListener('submit', function(e) {
                    const employeeNumber = document.getElementById('employee_number').value;
                    const accountType = document.getElementById('account_type').value;
                    const contactNumber = document.getElementById('contact_number').value;
                    const password = document.getElementById('password').value;
                    const confirmPassword = document.getElementById('confirm_password').value;
                    const username = document.getElementById('username').value;
                    const area = document.getElementById('area').value;
                    
                    // Get branch value - check both select and hidden input
                    let branch;
                    const branchSelect = document.getElementById('branch');
                    const branchHidden = document.querySelector('input[name="branch"][type="hidden"]');
                    
                    if (branchHidden) {
                        branch = branchHidden.value;
                    } else {
                        branch = branchSelect.value;
                    }
                    
                    // Validate employee number format
                    if (!/^[A-Z0-9\-]+$/.test(employeeNumber)) {
                        e.preventDefault();
                        toastError('Invalid Employee Number', 'Employee number must contain only uppercase letters, numbers, and hyphens');
                        return false;
                    }
                    
                    // Validate account type selection
                    if (!accountType) {
                        e.preventDefault();
                        toastError('Account Type Required', 'Please select an account type');
                        return false;
                    }
                    
                    // Validate contact number format (11 digits starting with 09)
                    if (contactNumber.length !== 11 || !contactNumber.startsWith('09')) {
                        e.preventDefault();
                        toastError('Invalid Contact Number', 'Contact number must be exactly 11 digits starting with 09');
                        return false;
                    }
                    
                    // Validate password match for all account types
                    if (password !== confirmPassword) {
                        e.preventDefault();
                        toastError('Password Mismatch', 'Password and Confirm Password do not match');
                        return false;
                    }
                    
                    // Validate password length for all account types
                    if (password.length < 6) {
                        e.preventDefault();
                        toastError('Weak Password', 'Password must be at least 6 characters long');
                        return false;
                    }
                    
                    // Validate username for all account types
                    if (username.length < 3) {
                        e.preventDefault();
                        toastError('Invalid Username', 'Username must be at least 3 characters long');
                        return false;
                    }
                    
                    // Validate area for Collectors
                    if (accountType === 'Collector' && !area) {
                        e.preventDefault();
                        toastError('Area Required', 'Area is required for Collector accounts');
                        return false;
                    }
                    
                    // Validate branch selection
                    if (!branch) {
                        e.preventDefault();
                        toastError('Branch Required', 'Please select a branch');
                        return false;
                    }
                    
                    // Validate other required fields
                    const requiredFields = [
                        'employee_number', 'last_name', 'first_name', 'address', 'status', 'gender', 
                        'birth_date', 'place_of_birth', 'account_status'
                    ];
                    
                    for (const fieldId of requiredFields) {
                        const field = document.getElementById(fieldId);
                        if (!field.value) {
                            e.preventDefault();
                            toastError('Missing Information', `Please fill all required fields (${fieldId.replace('_', ' ')} is empty)`);
                            return false;
                        }
                    }
                    
                    return true;
                });

                // Show SweetAlert notifications based on PHP messages
                <?php if (!empty($success_message)): ?>
                    window.PCToast.show('success', 'Proxy registered', <?php echo json_encode($success_message); ?>);
                <?php endif; ?>
                
                <?php if (!empty($error_message)): ?>
                    window.PCToast.show('error', 'Registration failed', <?php echo json_encode($error_message); ?>);
                <?php endif; ?>
            });
        </script>
    </body>
</html>
