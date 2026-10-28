<?php
session_start();

// Redirect to login if not authenticated
if (!isset($_SESSION['logged_in'])) {
    header("Location: login_form.php");
    exit;
}

// Database configuration
require 'includes/db_config.php';

// Initialize variables
$error = "";
$success = "";
$user = [];
$account_type = 'user'; // Default account type
$is_collector = false;
$is_proxy_collector = false;
$is_customer = false;

// Debug: Check session variables
error_log("DEBUG: Session user_id = " . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'NOT SET'));
error_log("DEBUG: Session account_type = " . (isset($_SESSION['account_type']) ? $_SESSION['account_type'] : 'NOT SET'));

// Get user details from appropriate table
try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }

    $user_id = $_SESSION['user_id'];
    
    // Determine which table to check based on session account_type
    if (isset($_SESSION['account_type'])) {
        $account_type = $_SESSION['account_type'];
        
        switch ($account_type) {
            case 'collector':
                $is_collector = true;
                $table_name = 'collector_accounts';
                $id_field = 'id';
                $sql = "SELECT first_name, middle_name, last_name, suffix, account_type FROM collector_accounts WHERE id = ?";
                break;
                
            case 'proxy_collector':
                $is_proxy_collector = true;
                $table_name = 'proxy_accounts';
                $id_field = 'id';
                $sql = "SELECT first_name, middle_name, last_name, suffix, account_type FROM proxy_accounts WHERE id = ?";
                break;
                
            case 'customer':
                $is_customer = true;
                $table_name = 'customer_accounts';
                $id_field = 'id';
                $sql = "SELECT first_name, middle_name, last_name, suffix, account_type FROM customer_accounts WHERE id = ?";
                break;
                
            case 'user':
            default:
                $table_name = 'accounts';
                $id_field = 'id';
                $sql = "SELECT first_name, middle_name, last_name, suffix, account_type FROM accounts WHERE id = ?";
                break;
        }
        
        error_log("DEBUG: Checking $table_name table for user_id: $user_id, account_type: $account_type");
    } else {
        // If account_type is not set, try to find user in any table
        $table_name = 'accounts';
        $id_field = 'id';
        $sql = "SELECT first_name, middle_name, last_name, suffix, account_type FROM accounts WHERE id = ?";
        error_log("DEBUG: Account type not in session, trying accounts table for user_id: $user_id");
    }
    
    // Prepare and execute first query
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        // User found in primary table
        $user = $result->fetch_assoc();
        error_log("DEBUG: User found in $table_name table: " . print_r($user, true));
    } else {
        // User not found in primary table, try other tables
        $stmt->close();
        
        // Try all possible tables including customer_accounts
        $tables_to_try = [
            ['table' => 'collector_accounts', 'type' => 'collector', 'id_field' => 'id'],
            ['table' => 'proxy_accounts', 'type' => 'proxy_collector', 'id_field' => 'id'],
            ['table' => 'customer_accounts', 'type' => 'customer', 'id_field' => 'id'],
            ['table' => 'accounts', 'type' => 'user', 'id_field' => 'id']
        ];
        
        $user_found = false;
        
        foreach ($tables_to_try as $table_info) {
            if ($table_info['table'] === $table_name) {
                continue; // Skip the table we already tried
            }
            
            $sql = "SELECT first_name, middle_name, last_name, suffix, account_type FROM {$table_info['table']} WHERE id = ?";
            error_log("DEBUG: Trying {$table_info['table']} table for user_id: $user_id");
            
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                error_log("WARNING: Prepare failed for {$table_info['table']}: " . $conn->error);
                continue;
            }
            
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 1) {
                // User found in this table
                $user = $result->fetch_assoc();
                $table_name = $table_info['table'];
                $account_type = $table_info['type'];
                
                // Update flags based on account type
                $is_collector = false;
                $is_proxy_collector = false;
                $is_customer = false;
                
                if ($account_type === 'collector') {
                    $is_collector = true;
                } elseif ($account_type === 'proxy_collector') {
                    $is_proxy_collector = true;
                } elseif ($account_type === 'customer') {
                    $is_customer = true;
                }
                
                // Update session to reflect correct account type
                $_SESSION['account_type'] = $account_type;
                
                error_log("DEBUG: User found in $table_name table. Updated session account_type to: $account_type");
                error_log("DEBUG: User details: " . print_r($user, true));
                
                $user_found = true;
                $stmt->close();
                break;
            }
            
            $stmt->close();
        }
        
        if (!$user_found) {
            // User not found in any table
            throw new Exception("User not found in any table with ID: $user_id");
        }
    }
    
    // Process password change
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Validate inputs
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error = "All fields are required.";
        } elseif ($new_password !== $confirm_password) {
            $error = "New passwords do not match.";
        } elseif (strlen($new_password) < 8) {
            $error = "New password must be at least 8 characters long.";
        } else {
            // Verify current password from the correct table
            $password_sql = "";
            
            if ($is_collector) {
                $password_sql = "SELECT password FROM collector_accounts WHERE id = ?";
            } elseif ($is_proxy_collector) {
                $password_sql = "SELECT password FROM proxy_accounts WHERE id = ?";
            } elseif ($is_customer) {
                $password_sql = "SELECT password FROM customer_accounts WHERE id = ?";
            } else {
                $password_sql = "SELECT password FROM accounts WHERE id = ?";
            }
            
            $stmt = $conn->prepare($password_sql);
            if (!$stmt) {
                throw new Exception("Prepare failed for password verification: " . $conn->error);
            }
            
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 1) {
                $user_data = $result->fetch_assoc();
                
                if (password_verify($current_password, $user_data['password'])) {
                    // Update password in appropriate table
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    
                    if ($is_collector) {
                        $update_sql = "UPDATE collector_accounts SET password = ? WHERE id = ?";
                    } elseif ($is_proxy_collector) {
                        $update_sql = "UPDATE proxy_accounts SET password = ? WHERE id = ?";
                    } elseif ($is_customer) {
                        $update_sql = "UPDATE customer_accounts SET password = ? WHERE id = ?";
                    } else {
                        $update_sql = "UPDATE accounts SET password = ? WHERE id = ?";
                    }
                    
                    $update_stmt = $conn->prepare($update_sql);
                    if (!$update_stmt) {
                        throw new Exception("Prepare failed for password update: " . $conn->error);
                    }
                    
                    $update_stmt->bind_param("si", $hashed_password, $user_id);
                    
                    if ($update_stmt->execute()) {
                        $success = "Password changed successfully.";
                        error_log("INFO: Password changed for user_id: $user_id, table: $table_name");
                    } else {
                        $error = "Error updating password: " . $conn->error;
                        error_log("ERROR: Password update failed: " . $conn->error);
                    }
                    
                    $update_stmt->close();
                } else {
                    $error = "Current password is incorrect.";
                    error_log("WARNING: Incorrect current password for user_id: $user_id, table: $table_name");
                }
            } else {
                $error = "User not found.";
                error_log("ERROR: User not found for password verification, user_id: $user_id, table: $table_name");
            }
            
            $stmt->close();
        }
    }

    $conn->close();

} catch (Exception $e) {
    error_log("EXCEPTION in change_password.php: " . $e->getMessage());
    $error = "An error occurred. Please try again later. Error: " . $e->getMessage();
    // Uncomment for debugging during development:
    // $error = "An error occurred: " . $e->getMessage();
}

// Shared layout chrome (includes/topbar.php + includes/sidebar.php) reads these.
if (!isset($user) || !is_array($user) || $user === array()) {
    $user = array(
        'account_type' => $_SESSION['account_type'] ?? '',
        'first_name'   => $_SESSION['first_name'] ?? '',
        'middle_name'  => $_SESSION['middle_name'] ?? '',
        'last_name'    => $_SESSION['last_name'] ?? '',
        'suffix'       => $_SESSION['suffix'] ?? '',
    );
}
$layout_active = 'change-password';
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
        <meta charset="utf-8" />
        <meta http-equiv="X-UA-Compatible" content="IE=edge" />
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=noon" />
        <meta name="description" content="" />
        <meta name="author" content="" />
        <title>Change Password - Prime City Admin</title>
        <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
        <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        <link href="assets/css/dash.css" rel="stylesheet" />
        <link href="assets/css/dash-ui.css" rel="stylesheet" />
        <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
        <!-- SweetAlert2 CSS -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
        <style>
            <?php if ($is_customer): ?>
            body {
                background: linear-gradient(135deg, #f5f7fa 0%, #e4e8f0 100%);
                min-height: 100vh;
                padding-top: 110px !important;
            }
            <?php endif; ?>
            .user-name {
                color: white;
            }
            .user-account-type {
                color: limegreen;
            }
            .password-container {
                max-width: 500px;
                margin: 0 auto;
                padding: 20px;
            }
            .center-form {
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 60vh;
                flex-direction: column;
            }
            .form-card {
                width: 100%;
                max-width: 500px;
                box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
                border-radius: 0.5rem;
            }
            .form-title {
                text-align: center;
                margin-bottom: 1.5rem;
                font-weight: 600;
            }
            .password-input-group {
                position: relative;
            }
            .password-toggle {
                position: absolute;
                right: 12px;
                top: 38px;
                cursor: pointer;
                color: #6c757d;
                z-index: 5;
            }
            .password-criteria {
                font-size: 0.85rem;
                margin-top: 5px;
            }
            .criteria-met {
                color: #28a745;
            }
            .criteria-not-met {
                color: #6c757d;
            }
            .progress {
                height: 5px;
                margin-bottom: 10px;
                margin-top: 5px;
            }
            .password-field {
                position: relative;
            }
            .toggle-icon {
                position: absolute;
                right: 15px;
                top: 38px;
                cursor: pointer;
                color: #6c757d;
                z-index: 10;
            }
            .debug-info {
                display: none; /* Set to 'block' for debugging */
                background-color: #f8f9fa;
                border: 1px solid #dee2e6;
                padding: 10px;
                margin: 10px 0;
                border-radius: 5px;
                font-family: monospace;
                font-size: 12px;
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
                                    <i class="fas fa-key" aria-hidden="true"></i>
                                    Prime City &middot; Account
                                </p>
                                <h1 class="dash-title">Change Password</h1>
                                <p class="dash-sub">Update the password for the account you are signed in with.</p>
                            </div>

                            <div class="dash-head__actions">
                                <a class="btn btn-primary" href="<?php echo $is_customer ? 'customer_dashboard.php' : (($is_collector || $is_proxy_collector) ? 'collector_dashboard.php' : 'admin.php'); ?>">
                                    <i class="fas fa-arrow-left me-1" aria-hidden="true"></i>
                                    Back to dashboard
                                </a>
                            </div>
                        </header>

                        <!-- Debug information (visible during development) -->
                        <div class="debug-info">
                            <strong>Debug Info:</strong><br>
                            User ID: <?php echo isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'Not set'; ?><br>
                            Account Type: <?php echo isset($_SESSION['account_type']) ? $_SESSION['account_type'] : 'Not set'; ?><br>
                            Is Collector: <?php echo $is_collector ? 'Yes' : 'No'; ?><br>
                            Is Proxy Collector: <?php echo $is_proxy_collector ? 'Yes' : 'No'; ?><br>
                            Is Customer: <?php echo $is_customer ? 'Yes' : 'No'; ?><br>
                            Table: <?php echo isset($table_name) ? $table_name : 'Not determined'; ?>
                        </div>
                        
                        <div class="center-form">
                            <div class="form-card card dash-card">
                                <header class="dash-card__head">
                                    <span class="dash-card__icon"><i class="fas fa-key" aria-hidden="true"></i></span>
                                    <div>
                                        <h2 class="dash-card__title">Change Your Password</h2>
                                        <p class="dash-card__hint">Enter your current password, then choose a strong new one.</p>
                                    </div>
                                </header>
                                <div class="card-body p-4">
                                    <form method="POST" action="change_password.php" id="passwordForm">
                                        <div class="mb-3 password-field">
                                            <label for="current_password" class="form-label">Current Password</label>
                                            <input type="password" class="form-control" id="current_password" name="current_password" required>
                                            <span class="toggle-icon" onclick="togglePassword('current_password')">
                                                <i class="fas fa-eye"></i>
                                            </span>
                                        </div>
                                        <div class="mb-3 password-field">
                                            <label for="new_password" class="form-label">New Password</label>
                                            <input type="password" class="form-control" id="new_password" name="new_password" required onkeyup="checkPasswordStrength()">
                                            <span class="toggle-icon" onclick="togglePassword('new_password')">
                                                <i class="fas fa-eye"></i>
                                            </span>
                                            <div class="progress">
                                                <div id="password-strength-bar" class="progress-bar" role="progressbar" style="width: 0%"></div>
                                            </div>
                                            <div class="mb-4 password-field">
                                                <label for="confirm_password" class="form-label">Confirm New Password</label>
                                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required onkeyup="checkPasswordMatch()">
                                                <span class="toggle-icon" onclick="togglePassword('confirm_password')">
                                                    <i class="fas fa-eye"></i>
                                                </span>
                                                <div id="password-match" class="password-criteria"></div>
                                            </div>
                                            <div class="password-criteria">
                                                <div id="length" class="criteria-not-met"><i class="fas fa-circle"></i> At least 8 characters</div>
                                                <div id="uppercase" class="criteria-not-met"><i class="fas fa-circle"></i> One uppercase letter</div>
                                                <div id="lowercase" class="criteria-not-met"><i class="fas fa-circle"></i> One lowercase letter</div>
                                                <div id="number" class="criteria-not-met"><i class="fas fa-circle"></i> One number</div>
                                                <div id="special" class="criteria-not-met"><i class="fas fa-circle"></i> One special character</div>
                                            </div>
                                        </div>
                                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                            <?php if ($is_collector): ?>
                                                <a href="collector_dashboard.php" class="btn btn-secondary me-md-2">Cancel</a>
                                            <?php elseif ($is_proxy_collector): ?>
                                                <a href="collector_dashboard.php" class="btn btn-secondary me-md-2">Cancel</a>
                                            <?php elseif ($is_customer): ?>
                                                <a href="customer_dashboard.php" class="btn btn-secondary me-md-2">Cancel</a>
                                            <?php else: ?>
                                                <a href="admin.php" class="btn btn-secondary me-md-2">Cancel</a>
                                            <?php endif; ?>
                                            <button type="submit" name="change_password" class="btn btn-primary" id="submit-btn">Change Password</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </main>
                <?php if (!$is_customer): ?>
                    <?php include __DIR__ . '/includes/footer.php'; ?>
                <?php endif; ?>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
        <script src="js/scripts.js"></script>
        <!-- SweetAlert2 JS -->
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
        <script>
            // Toggle password visibility
            function togglePassword(inputId) {
                const input = document.getElementById(inputId);
                const icon = document.querySelector(`[onclick="togglePassword('${inputId}')"] i`);
                
                if (input.type === 'password') {
                    input.type = 'text';
                    icon.classList.replace('fa-eye', 'fa-eye-slash');
                } else {
                    input.type = 'password';
                    icon.classList.replace('fa-eye-slash', 'fa-eye');
                }
            }
            
            // Check password strength
            function checkPasswordStrength() {
                const password = document.getElementById('new_password').value;
                const strengthBar = document.getElementById('password-strength-bar');
                const criteria = {
                    length: document.getElementById('length'),
                    uppercase: document.getElementById('uppercase'),
                    lowercase: document.getElementById('lowercase'),
                    number: document.getElementById('number'),
                    special: document.getElementById('special')
                };
                
                let strength = 0;
                let totalCriteria = 0;
                let metCriteria = 0;
                
                // Check length
                if (password.length >= 8) {
                    criteria.length.classList.remove('criteria-not-met');
                    criteria.length.classList.add('criteria-met');
                    strength += 20;
                    metCriteria++;
                } else {
                    criteria.length.classList.remove('criteria-met');
                    criteria.length.classList.add('criteria-not-met');
                }
                totalCriteria++;
                
                // Check uppercase
                if (/[A-Z]/.test(password)) {
                    criteria.uppercase.classList.remove('criteria-not-met');
                    criteria.uppercase.classList.add('criteria-met');
                    strength += 20;
                    metCriteria++;
                } else {
                    criteria.uppercase.classList.remove('criteria-met');
                    criteria.uppercase.classList.add('criteria-not-met');
                }
                totalCriteria++;
                
                // Check lowercase
                if (/[a-z]/.test(password)) {
                    criteria.lowercase.classList.remove('criteria-not-met');
                    criteria.lowercase.classList.add('criteria-met');
                    strength += 20;
                    metCriteria++;
                } else {
                    criteria.lowercase.classList.remove('criteria-met');
                    criteria.lowercase.classList.add('criteria-not-met');
                }
                totalCriteria++;
                
                // Check number
                if (/[0-9]/.test(password)) {
                    criteria.number.classList.remove('criteria-not-met');
                    criteria.number.classList.add('criteria-met');
                    strength += 20;
                    metCriteria++;
                } else {
                    criteria.number.classList.remove('criteria-met');
                    criteria.number.classList.add('criteria-not-met');
                }
                totalCriteria++;
                
                // Check special character
                if (/[^A-Za-z0-9]/.test(password)) {
                    criteria.special.classList.remove('criteria-not-met');
                    criteria.special.classList.add('criteria-met');
                    strength += 20;
                    metCriteria++;
                } else {
                    criteria.special.classList.remove('criteria-met');
                    criteria.special.classList.add('criteria-not-met');
                }
                totalCriteria++;
                
                // Update strength bar
                strengthBar.style.width = strength + '%';
                
                if (strength < 40) {
                    strengthBar.className = 'progress-bar bg-danger';
                } else if (strength < 80) {
                    strengthBar.className = 'progress-bar bg-warning';
                } else {
                    strengthBar.className = 'progress-bar bg-success';
                }
                
                // Enable/disable submit button based on criteria
                const submitBtn = document.getElementById('submit-btn');
                if (metCriteria >= 4) { // At least 4 out of 5 criteria met
                    submitBtn.disabled = false;
                } else {
                    submitBtn.disabled = true;
                }
                
                // Also check password match
                checkPasswordMatch();
            }
            
            // Check if passwords match
            function checkPasswordMatch() {
                const password = document.getElementById('new_password').value;
                const confirmPassword = document.getElementById('confirm_password').value;
                const matchElement = document.getElementById('password-match');
                
                if (confirmPassword.length === 0) {
                    matchElement.innerHTML = '';
                    return;
                }
                
                if (password === confirmPassword) {
                    matchElement.innerHTML = '<span class="criteria-met"><i class="fas fa-check-circle"></i> Passwords match</span>';
                } else {
                    matchElement.innerHTML = '<span class="text-danger"><i class="fas fa-times-circle"></i> Passwords do not match</span>';
                }
            }
            
            // Initialize on page load
            document.addEventListener('DOMContentLoaded', function() {
                checkPasswordStrength();
                checkPasswordMatch();
                
                // Show SweetAlert2 notifications based on PHP variables
                <?php if (!empty($error)): ?>
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: '<?php echo addslashes($error); ?>',
                        confirmButtonColor: '#d33'
                    });
                <?php endif; ?>
                
                <?php if (!empty($success)): ?>
                    Swal.fire({
                        icon: 'success',
                        title: 'Success',
                        text: '<?php echo addslashes($success); ?>',
                        confirmButtonColor: '#3085d6'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            <?php if ($is_collector): ?>
                                window.location.href = 'collector_dashboard.php';
                            <?php elseif ($is_proxy_collector): ?>
                                window.location.href = 'collector_dashboard.php';
                            <?php elseif ($is_customer): ?>
                                window.location.href = 'customer_dashboard.php';
                            <?php else: ?>
                                window.location.href = 'admin.php';
                            <?php endif; ?>
                        }
                    });
                <?php endif; ?>
            });
        </script>
    </body>
</html>
