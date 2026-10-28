<?php
session_start();

// Redirect to login if not authenticated
if (!isset($_SESSION['logged_in'])) {
    header("Location: index.php");
    exit;
}

// Database configuration
require 'includes/db_config.php';

// Initialize user variable
$user = [];

// Get user details
try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    $stmt = $conn->prepare("SELECT first_name, middle_name, last_name, suffix, account_type FROM accounts WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception("User not found");
    }

    $user = $result->fetch_assoc();
    $stmt->close();
    $conn->close();

} catch (Exception $e) {
    error_log($e->getMessage());
    // You might want to redirect to an error page or back to login
}

// Logout function
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    setcookie('remember_me', '', time() - 3600, "/"); // Delete remember me cookie
    header("Location: index.php");
    exit;
}

// Initialize variables
$success_message = '';
$error_message = '';

// Only process the form if it's submitted and no time tampering detected
if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($time_tampering_detected)) {
    // Create connection
    try {
        $conn = new mysqli($servername, $username, $password, $dbname);
        
        // Check connection
        if ($conn->connect_error) {
            throw new Exception("Connection failed: " . $conn->connect_error);
        }

        // Get form data with proper validation
        $employee_number = isset($_POST['employee_number']) ? $conn->real_escape_string(trim($_POST['employee_number'])) : '';
        $last_name = isset($_POST['last_name']) ? $conn->real_escape_string($_POST['last_name']) : '';
        $first_name = isset($_POST['first_name']) ? $conn->real_escape_string($_POST['first_name']) : '';
        $middle_name = isset($_POST['middle_name']) ? $conn->real_escape_string($_POST['middle_name']) : null;
        $suffix = !empty($_POST['suffix']) ? $conn->real_escape_string($_POST['suffix']) : null;
        $address = isset($_POST['address']) ? $conn->real_escape_string($_POST['address']) : '';
        $contact_number = isset($_POST['contact_number']) ? $conn->real_escape_string($_POST['contact_number']) : '';
        $account_type = isset($_POST['account_type']) ? $conn->real_escape_string($_POST['account_type']) : '';
        
        // Handle branch selection - SIMPLIFIED AND FIXED LOGIC
        $branch = '';
        if ($account_type === 'Admin') {
            $branch = 'All Branches';
        } elseif ($account_type === 'Area Manager' && isset($_POST['branches']) && is_array($_POST['branches'])) {
            $branch = implode(', ', $_POST['branches']);
        } else {
            // For Branch Manager, Proxy Collector-Cashier, Proxy Collector, Collector
            $branch = isset($_POST['branch']) ? $conn->real_escape_string($_POST['branch']) : '';
        }
        
        $username = isset($_POST['username']) ? $conn->real_escape_string($_POST['username']) : '';
        $password = isset($_POST['password']) ? password_hash($_POST['password'], PASSWORD_DEFAULT) : '';
        $registration_date = date('Y-m-d H:i:s');

        // Validate required fields
        if (empty($employee_number) || empty($last_name) || empty($first_name) || empty($address) || empty($contact_number) || empty($account_type) || empty($username) || empty($_POST['password'])) {
            throw new Exception("All required fields must be filled");
        }

        // Validate employee number format (customize this based on your requirements)
        if (!preg_match('/^[A-Z0-9\-]+$/', $employee_number)) {
            throw new Exception("Employee number must contain only uppercase letters, numbers, and hyphens");
        }

        // Validate branch selection based on account type
        if ($account_type === 'Area Manager') {
            if (!isset($_POST['branches']) || empty($_POST['branches'])) {
                throw new Exception("Please select at least one branch for Area Manager");
            }
        } elseif ($account_type !== 'Admin') {
            // For Branch Manager, Proxy Collector-Cashier, Proxy Collector, Collector
            if (empty($branch)) {
                throw new Exception("Please select a branch");
            }
        }

        // Validate contact number
        if (!preg_match('/^09\d{9}$/', $contact_number)) {
            throw new Exception("Contact number must be 11 digits starting with '09'");
        }

        // Check if username already exists
        $check_username_stmt = $conn->prepare("SELECT id FROM accounts WHERE username = ?");
        $check_username_stmt->bind_param("s", $username);
        $check_username_stmt->execute();
        $check_username_stmt->store_result();
        
        if ($check_username_stmt->num_rows > 0) {
            throw new Exception("Username already exists. Please choose a different username.");
        }
        $check_username_stmt->close();

        // Check if employee number already exists
        $check_emp_stmt = $conn->prepare("SELECT id FROM accounts WHERE employee_number = ?");
        $check_emp_stmt->bind_param("s", $employee_number);
        $check_emp_stmt->execute();
        $check_emp_stmt->store_result();
        
        if ($check_emp_stmt->num_rows > 0) {
            throw new Exception("Employee number already exists. Please use a unique employee number.");
        }
        $check_emp_stmt->close();

        // Prepare SQL statement with employee_number
        $stmt = $conn->prepare("INSERT INTO accounts (employee_number, last_name, first_name, middle_name, suffix, address, contact_number, account_type, branch, username, password, registration_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }

        // Bind parameters
        $stmt->bind_param("ssssssssssss", $employee_number, $last_name, $first_name, $middle_name, $suffix, $address, $contact_number, $account_type, $branch, $username, $password, $registration_date);

        // Execute the statement
        if ($stmt->execute()) {
            $success_message = "Account registered successfully! Employee Number: " . $employee_number;
        } else {
            throw new Exception("Error: " . $stmt->error);
        }

        // Close statement
        $stmt->close();
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }

    // Close connection (if it was opened)
    if (isset($conn)) {
        $conn->close();
    }
}

// Shared layout includes (includes/topbar.php, includes/sidebar.php) read these.
$layout_active = 'accounts-registration';

// Fallback identity so the chrome always has a usable $user array.
if (!is_array($user) || $user === array()) {
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
        <title>Account Registration - Prime City Admin</title>
        <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
        <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="assets/css/dash.css" rel="stylesheet" />
        <link href="assets/css/dash-ui.css" rel="stylesheet" />
        <link href="assets/css/customer-registration-style.css" rel="stylesheet" />
        <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
        <!-- SweetAlert2 CSS -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
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
            .alert {
                margin-top: 1rem;
            }
            .password-toggle {
                cursor: pointer;
                position: absolute;
                right: 10px;
                top: 50%;
                transform: translateY(-50%);
            }
            /* Style for contact number input */
            #contact_number {
                font-family: monospace;
            }
            .time-tampering-alert {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                z-index: 9999;
                border-radius: 0;
            }
            .registration-card .alert {
                margin-top: 0;
            }
            .required-field::after {
                content: " *";
                color: red;
            }
            .form-floating {
                margin-bottom: 1rem;
            }
            .branches-container {
                display: none;
                margin-top: 1rem;
            }
            .branch-checkbox-group {
                border: 1px solid #dee2e6;
                border-radius: 0.375rem;
                padding: 1rem;
                background-color: #f8f9fa;
            }
            .branch-checkbox-item {
                margin-bottom: 0.5rem;
            }
            .branch-checkbox-item .form-check-input {
                margin-right: 0.5rem;
            }
            .admin-branch-field {
                background-color: #e9ecef;
                opacity: 1;
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
                                <h1 class="dash-title">Register Account</h1>
                                <p class="dash-sub">Personal, contact and sign-in details for a system account.</p>
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
                                    <h2 class="dash-card__title">Account Registration</h2>
                                    <p class="dash-card__hint">Fields marked with an asterisk are required</p>
                                </div>
                            </header>

                            <div class="dash-card__body">
                                    <form id="accountRegistrationForm" class="dash-form" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST">
                                        <!-- Personal Information Section -->
                                        <div class="form-section">
                                            <h5 class="section-title">Personal Information</h5>
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
                                                            <option value="">None</option>
                                                            <option value="Sr">Sr</option>
                                                            <option value="Jr">Jr</option>
                                                            <option value="III">III</option>
                                                            <option value="IV">IV</option>
                                                        </select>
                                                        <label for="suffix">Suffix</label>
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
                                                <div class="col-md-6">
                                                    <div class="form-floating">
                                                        <input type="tel" class="form-control" id="contact_number" name="contact_number" 
                                                               pattern="09\d{9}" 
                                                               maxlength="11" 
                                                               oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11); validateContactNumber(this)" 
                                                               placeholder="Contact Number" required>
                                                        <label for="contact_number" class="required-field">Contact Number</label>
                                                    </div>
                                                    <small class="text-muted">Must be 11 digits starting with "09"</small>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Account Credentials Section -->
                                        <div class="form-section">
                                            <h5 class="section-title">Account Credentials</h5>
                                            <div class="row g-3">
                                                <!-- Employee Number Field (Manual Input) -->
                                                <div class="col-md-6">
                                                    <div class="form-floating">
                                                        <input type="text" class="form-control employee-number-field" id="employee_number" name="employee_number" 
                                                               placeholder="Employee Number" 
                                                               oninput="this.value = this.value.toUpperCase()"
                                                               pattern="[A-Z0-9\-]+"
                                                               title="Uppercase letters, numbers, and hyphens only"
                                                               required>
                                                        <label for="employee_number" class="required-field">Employee Number</label>
                                                    </div>
                                                    <div class="input-hint">
                                                        <i class="fas fa-info-circle"></i> Numbers only (e.g., 00001)
                                                    </div>
                                                </div>
                                                
                                                <div class="col-md-6">
                                                    <div class="form-floating">
                                                        <input type="text" class="form-control" id="username" name="username" placeholder="Username" required>
                                                        <label for="username" class="required-field">Username</label>
                                                    </div>
                                                    <small class="text-muted">Must be unique</small>
                                                </div>
                                                
                                                <div class="col-md-6">
                                                    <div class="form-floating">
                                                        <select class="form-select" id="account_type" name="account_type" required onchange="toggleBranchSelection()">
                                                            <option value="">Select Account Type</option>
                                                            <option value="Admin">Admin</option>
                                                            <option value="Area Manager">Area Manager</option>
                                                            <option value="Branch Manager">Branch Manager</option>
                                                        </select>
                                                        <label for="account_type" class="required-field">Account Type</label>
                                                    </div>
                                                </div>
                                                
                                                <!-- Single Branch Selection (for non-Area Managers and non-Admins) -->
                                                <div class="col-md-6" id="singleBranchContainer">
                                                    <div class="form-floating">
                                                        <select class="form-select" id="branch" name="branch">
                                                            <option value="">Select Branch</option>
                                                            <option value="Tuguegarao City">Tuguegarao City</option>
                                                            <option value="Gattaran">Gattaran</option>
                                                            <option value="Abulug">Abulug</option>
                                                            <option value="Ilagan">Ilagan</option>
                                                        </select>
                                                        <label for="branch" class="required-field">Branch</label>
                                                    </div>
                                                </div>
                                                
                                                <!-- Admin Branch Field (auto-filled and disabled) -->
                                                <div class="col-md-6" id="adminBranchContainer" style="display: none;">
                                                    <div class="form-floating">
                                                        <input type="text" class="form-control admin-branch-field" id="admin_branch" name="admin_branch_display" value="All Branches" readonly disabled>
                                                        <label for="admin_branch">Branch</label>
                                                    </div>
                                                    <!-- REMOVED THE HIDDEN FIELD - This was causing the problem! -->
                                                </div>
                                                
                                                <!-- Multiple Branch Selection (for Area Manager) -->
                                                <div class="col-12" id="multipleBranchesContainer">
                                                    <div class="branches-container">
                                                        <label class="form-label required-field">Select Branches (Area Manager)</label>
                                                        <div class="branch-checkbox-group">
                                                            <div class="row">
                                                                <div class="col-md-6 branch-checkbox-item">
                                                                    <div class="form-check">
                                                                        <input class="form-check-input branch-checkbox" type="checkbox" name="branches[]" value="Tuguegarao City" id="branch_tuguegarao">
                                                                        <label class="form-check-label" for="branch_tuguegarao">
                                                                            Tuguegarao City
                                                                        </label>
                                                                    </div>
                                                                </div>
                                                                <div class="col-md-6 branch-checkbox-item">
                                                                    <div class="form-check">
                                                                        <input class="form-check-input branch-checkbox" type="checkbox" name="branches[]" value="Gattaran" id="branch_gattaran">
                                                                        <label class="form-check-label" for="branch_gattaran">
                                                                            Gattaran
                                                                        </label>
                                                                    </div>
                                                                </div>
                                                                <div class="col-md-6 branch-checkbox-item">
                                                                    <div class="form-check">
                                                                        <input class="form-check-input branch-checkbox" type="checkbox" name="branches[]" value="Abulug" id="branch_abulug">
                                                                        <label class="form-check-label" for="branch_abulug">
                                                                            Abulug
                                                                        </label>
                                                                    </div>
                                                                </div>
                                                                <div class="col-md-6 branch-checkbox-item">
                                                                    <div class="form-check">
                                                                        <input class="form-check-input branch-checkbox" type="checkbox" name="branches[]" value="Ilagan" id="branch_ilagan">
                                                                        <label class="form-check-label" for="branch_ilagan">
                                                                            Ilagan
                                                                        </label>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <small class="text-muted">Area Manager can manage multiple branches. Select all applicable branches.</small>
                                                    </div>
                                                </div>
                                                
                                                <div class="col-md-6">
                                                    <div class="form-floating position-relative">
                                                        <input type="password" class="form-control" id="password" name="password" placeholder="Password" required>
                                                        <label for="password" class="required-field">Password</label>
                                                        <i class="fas fa-eye password-toggle" onclick="togglePasswordVisibility('password')"></i>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-floating position-relative">
                                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Confirm Password" required>
                                                        <label for="confirm_password" class="required-field">Confirm Password</label>
                                                        <i class="fas fa-eye password-toggle" onclick="togglePasswordVisibility('confirm_password')"></i>
                                                    </div>
                                                </div>

                                                <div class="col-md-6" hidden>
                                                    <div class="form-floating">
                                                        <input type="datetime-local" class="form-control" id="registration_date" name="registration_date" disabled>
                                                        <label for="registration_date">Registration Date</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="dash-form__actions">
                                            <a class="btn btn-outline-secondary" href="employee_lists.php">Cancel</a>
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-check me-1" aria-hidden="true"></i>
                                                Register Account
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
            // Set current date and time as registration date
            document.addEventListener('DOMContentLoaded', function() {
                const now = new Date();
                // Format the date for datetime-local input
                const year = now.getFullYear();
                const month = String(now.getMonth() + 1).padStart(2, '0');
                const day = String(now.getDate()).padStart(2, '0');
                const hours = String(now.getHours()).padStart(2, '0');
                const minutes = String(now.getMinutes()).padStart(2, '0');
                
                const formattedDate = `${year}-${month}-${day}T${hours}:${minutes}`;
                document.getElementById('registration_date').value = formattedDate;

                // Initialize branch selection
                toggleBranchSelection();

                // Show SweetAlert notifications based on PHP messages
                <?php if (!empty($success_message)): ?>
                    window.PCToast.show('success', 'Account registered', <?php echo json_encode($success_message); ?>);
                <?php endif; ?>
                
                <?php if (!empty($error_message)): ?>
                    window.PCToast.show('error', 'Registration failed', <?php echo json_encode($error_message); ?>);
                <?php endif; ?>
            });
            
            // Toggle branch selection based on account type
            function toggleBranchSelection() {
                const accountType = document.getElementById('account_type').value;
                const singleBranchContainer = document.getElementById('singleBranchContainer');
                const adminBranchContainer = document.getElementById('adminBranchContainer');
                const multipleBranchesContainer = document.getElementById('multipleBranchesContainer');
                const branchesContainer = multipleBranchesContainer.querySelector('.branches-container');
                const branchSelect = document.getElementById('branch');
                
                if (accountType === 'Admin') {
                    // Show admin branch field, hide others
                    singleBranchContainer.style.display = 'none';
                    adminBranchContainer.style.display = 'block';
                    multipleBranchesContainer.style.display = 'none';
                    branchesContainer.style.display = 'none';
                    
                    // Clear the branch select value
                    branchSelect.value = '';
                    
                } else if (accountType === 'Area Manager') {
                    // Show multiple branch selection, hide others
                    singleBranchContainer.style.display = 'none';
                    adminBranchContainer.style.display = 'none';
                    multipleBranchesContainer.style.display = 'block';
                    branchesContainer.style.display = 'block';
                    
                    // Clear the branch select value
                    branchSelect.value = '';
                    
                } else {
                    // Show single branch selection, hide others
                    singleBranchContainer.style.display = 'block';
                    adminBranchContainer.style.display = 'none';
                    multipleBranchesContainer.style.display = 'none';
                    branchesContainer.style.display = 'none';
                    
                    // Clear multiple branches
                    const branchCheckboxes = document.querySelectorAll('.branch-checkbox');
                    branchCheckboxes.forEach(checkbox => {
                        checkbox.checked = false;
                    });
                }
            }
            
            // Toggle password visibility
            function togglePasswordVisibility(fieldId) {
                const field = document.getElementById(fieldId);
                const icon = field.parentElement.querySelector('.password-toggle');
                
                if (field.type === 'password') {
                    field.type = 'text';
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                } else {
                    field.type = 'password';
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            }
            
            // Validate contact number in real-time
            function validateContactNumber(input) {
                const value = input.value;
                
                if (value.length > 0 && !value.startsWith('09')) {
                    input.setCustomValidity("Contact number must start with '09'");
                } else if (value.length === 11 && !/^09\d{9}$/.test(value)) {
                    input.setCustomValidity("Contact number must be 11 digits starting with '09'");
                } else {
                    input.setCustomValidity("");
                }
            }

            // Form validation
            document.getElementById('accountRegistrationForm').addEventListener('submit', function(e) {
                const employeeNumber = document.getElementById('employee_number').value;
                const contactNumber = document.getElementById('contact_number').value;
                const password = document.getElementById('password').value;
                const confirmPassword = document.getElementById('confirm_password').value;
                const accountType = document.getElementById('account_type').value;

                // Validate employee number format
                if (!/^[A-Z0-9\-]+$/.test(employeeNumber)) {
                    alert('Employee number must contain only uppercase letters, numbers, and hyphens');
                    e.preventDefault();
                    return false;
                }

                // Validate contact number format
                if (!/^09\d{9}$/.test(contactNumber)) {
                    alert('Contact number must be 11 digits starting with "09"');
                    e.preventDefault();
                    return false;
                }

                // Validate branch selection based on account type
                if (accountType === 'Area Manager') {
                    const branchCheckboxes = document.querySelectorAll('input[name="branches[]"]:checked');
                    if (branchCheckboxes.length === 0) {
                        alert('Please select at least one branch for Area Manager');
                        e.preventDefault();
                        return false;
                    }
                } else if (accountType !== 'Admin') {
                    const branch = document.getElementById('branch').value;
                    if (!branch) {
                        alert('Please select a branch');
                        e.preventDefault();
                        return false;
                    }
                }

                if (password.length < 8) {
                    alert('Password must be at least 8 characters long');
                    e.preventDefault();
                    return false;
                }

                if (password !== confirmPassword) {
                    alert('Passwords do not match');
                    e.preventDefault();
                    return false;
                }

                return true;
            });
        </script>
    </body>
</html>
