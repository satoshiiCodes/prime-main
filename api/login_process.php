<?php
session_start();

// Database configuration
require '../includes/db_config.php';

// Initialize variables
$error = '';

// Only process if form was submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        // Create connection
        $conn = new mysqli($servername, $username, $password, $dbname);
        
        // Check connection
        if ($conn->connect_error) {
            throw new Exception("Connection failed: " . $conn->connect_error);
        }

        // Get and sanitize input
        $username = $conn->real_escape_string($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember_me = isset($_POST['rememberMe']) ? true : false;

        // Validate input
        if (empty($username)) {
            throw new Exception("Username is required");
        }
        if (empty($password)) {
            throw new Exception("Password is required");
        }

        $user = null;
        $account_source = '';
        $user_branch = '';

        // First: Check in accounts table (Admin accounts)
        $stmt = $conn->prepare("SELECT id, username, password, account_type, first_name, branch, account_status FROM accounts WHERE username = ?");
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }

        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            $account_source = 'accounts';
            $user_branch = $user['branch'] ?? '';
            
            // Verify password for accounts table
            if (!password_verify($password, $user['password'])) {
                $user = null; // Password doesn't match, continue to other tables check
                $user_branch = '';
            } else {
                // Check account status for admin accounts
                if ($user['account_status'] !== 'Active') {
                    throw new Exception("Your account is not active. Please contact administrator.");
                }
            }
        }
        $stmt->close();

        // Second: If not found in accounts or password didn't match, check collector_accounts table
        if (!$user) {
            $stmt = $conn->prepare("SELECT id, username, password, account_type, first_name, branch, account_status FROM collector_accounts WHERE username = ?");
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }

            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                $account_source = 'collector_accounts';
                $user_branch = $user['branch'] ?? '';
                
                // Verify password for collector_accounts table
                if (!password_verify($password, $user['password'])) {
                    $user = null; // Password doesn't match, continue to proxy_accounts check
                    $user_branch = '';
                } else {
                    // Check if account type is allowed for collector_accounts
                    $allowed_collector_types = ['Collector', 'Cashier', 'Collector-Cashier'];
                    if (!in_array($user['account_type'], $allowed_collector_types)) {
                        throw new Exception("Access denied. Invalid account type for collector login.");
                    }
                    
                    // Check account status for collector accounts
                    if ($user['account_status'] !== 'Active') {
                        throw new Exception("Your account is not active. Please contact administrator.");
                    }
                }
            }
            $stmt->close();
        }

        // Third: If not found in collector_accounts or password didn't match, check customer_accounts table
        if (!$user) {
            $stmt = $conn->prepare("SELECT id, username, password, account_type, first_name, branch, status FROM customer_accounts WHERE username = ?");
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }

            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                $account_source = 'customer_accounts';
                $user_branch = $user['branch'] ?? '';
                
                // Verify password for customer_accounts table
                if (!password_verify($password, $user['password'])) {
                    $user = null; // Password doesn't match, continue to proxy_accounts check
                    $user_branch = '';
                } else {
                    // Check if account type is allowed for customer_accounts (should only be 'Customer')
                    if ($user['account_type'] !== 'Customer') {
                        throw new Exception("Access denied. Invalid account type for customer login.");
                    }
                    
                    // Check status for customer accounts (note: column name is 'status' for customers, not 'account_status')
                    if ($user['status'] !== 'Active') {
                        throw new Exception("Your account is not active. Please contact administrator.");
                    }
                }
            }
            $stmt->close();
        }

        // Fourth: If not found in customer_accounts or password didn't match, check proxy_accounts table
        if (!$user) {
            $stmt = $conn->prepare("SELECT id, username, password, account_type, first_name, branch, account_status FROM proxy_accounts WHERE username = ?");
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }

            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                $account_source = 'proxy_accounts';
                $user_branch = $user['branch'] ?? '';
                
                // Verify password for proxy_accounts table
                if (!password_verify($password, $user['password'])) {
                    throw new Exception("Invalid username or password");
                }
                
                // Allow both "Proxy Collector" and "Proxy Collector-Cashier" from proxy_accounts table
                $allowed_proxy_types = ['Proxy Collector', 'Proxy Collector-Cashier'];
                if (!in_array($user['account_type'], $allowed_proxy_types)) {
                    throw new Exception("Access denied. Only Proxy Collector or Proxy Collector-Cashier accounts can login from this system.");
                }
                
                // Check account status for proxy accounts
                if ($user['account_status'] !== 'Active') {
                    throw new Exception("Your account is not active. Please contact administrator.");
                }
            } else {
                throw new Exception("Invalid username or password");
            }
            $stmt->close();
        }

        // If we reach here, user is authenticated and active
        /**
         * Set session variables
         */
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['account_type'] = $user['account_type'];
        $_SESSION['first_name'] = $user['first_name'];
        $_SESSION['logged_in'] = true;
        $_SESSION['account_source'] = $account_source; // Store which table the account came from
        $_SESSION['branch'] = $user_branch; // Store user's branch

        // Remember Me cookie (optional: include branch in cookie)
        if ($remember_me) {
            $cookie_data = [
                'user_id' => $user['id'],
                'branch' => $user_branch,
                'hash' => hash('sha256', $user['password'] . $user_branch . $account_source) // Include branch and source in hash
            ];
            $cookie_value = base64_encode(json_encode($cookie_data));
            setcookie('remember_me', $cookie_value, time() + (86400 * 30), "/"); // 30 days
        }

        // Log login activity (including branch and account source)
        $log_stmt = $conn->prepare("INSERT INTO login_logs (account_id, login_time, ip_address, account_source, branch) VALUES (?, NOW(), ?, ?, ?)");
        $ip_address = $_SERVER['REMOTE_ADDR'];
        $log_stmt->bind_param("isss", $user['id'], $ip_address, $account_source, $user_branch);
        $log_stmt->execute();
        $log_stmt->close();

        // Close DB connection
        $conn->close();

        // Redirect based on account type
        if ($user['account_type'] === 'Customer') {
            header("Location: ../customer_dashboard.php");
        } elseif ($user['account_type'] === 'Proxy Collector' || $user['account_type'] === 'Proxy Collector-Cashier') {
            header("Location: ../collector_dashboard.php");
        } else {
            header("Location: ../admin.php");
        }
        exit;

    } catch (Exception $e) {
        $error = urlencode($e->getMessage());
        header("Location: ../index.php?error=" . $error);
        exit;
    }
} else {
    // Not a POST request, redirect to login
    header("Location: ../index.php");
    exit;
}
?>