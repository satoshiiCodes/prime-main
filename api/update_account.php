<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

require '../includes/db_config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['id'])) {
    $account_id = intval($_POST['id']);
    
    try {
        $conn = new mysqli($servername, $username, $password, $dbname);
        
        if ($conn->connect_error) {
            throw new Exception("Connection failed: " . $conn->connect_error);
        }

        // Get form data with proper validation
        $last_name = isset($_POST['last_name']) ? $conn->real_escape_string($_POST['last_name']) : '';
        $first_name = isset($_POST['first_name']) ? $conn->real_escape_string($_POST['first_name']) : '';
        $middle_name = isset($_POST['middle_name']) ? $conn->real_escape_string($_POST['middle_name']) : null;
        $suffix = !empty($_POST['suffix']) ? $conn->real_escape_string($_POST['suffix']) : null;
        $address = isset($_POST['address']) ? $conn->real_escape_string($_POST['address']) : '';
        $contact_number = isset($_POST['contact_number']) ? $conn->real_escape_string($_POST['contact_number']) : '';
        $account_type = isset($_POST['account_type']) ? $conn->real_escape_string($_POST['account_type']) : '';
        $username = isset($_POST['username']) ? $conn->real_escape_string($_POST['username']) : '';
        $employee_number = isset($_POST['employee_number']) && !empty($_POST['employee_number']) ? $conn->real_escape_string($_POST['employee_number']) : null;
        
        // Handle branch selection
        $branch = '';
        if ($account_type === 'Area Manager') {
            // Handle multiple branches for Area Manager
            if (isset($_POST['branches']) && is_array($_POST['branches']) && !empty($_POST['branches'])) {
                $branch = implode(', ', $_POST['branches']);
            } else {
                throw new Exception("Please select at least one branch for Area Manager");
            }
        } else if ($account_type === 'Admin') {
            // For Admin - always set to "All Branches"
            $branch = 'All Branches';
        } else {
            // For Branch Manager, Proxy Collector-Cashier, Proxy Collector - get from single branch select
            $branch = isset($_POST['branch']) ? $conn->real_escape_string($_POST['branch']) : '';
            
            // Validate that a branch is selected for these account types
            if (empty($branch)) {
                throw new Exception("Please select a branch");
            }
        }

        // Validate required fields
        if (empty($last_name) || empty($first_name) || empty($address) || empty($contact_number) || empty($account_type) || empty($username)) {
            throw new Exception("All required fields must be filled");
        }

        // Validate contact number
        if (!preg_match('/^09\d{9}$/', $contact_number)) {
            throw new Exception("Contact number must be 11 digits starting with '09'");
        }

        // Check if username already exists (excluding current account)
        $check_stmt = $conn->prepare("SELECT id FROM accounts WHERE username = ? AND id != ?");
        $check_stmt->bind_param("si", $username, $account_id);
        $check_stmt->execute();
        $check_stmt->store_result();
        
        if ($check_stmt->num_rows > 0) {
            throw new Exception("Username already exists. Please choose a different username.");
        }
        $check_stmt->close();

        // Check if employee number already exists (if provided and excluding current account)
        if (!empty($employee_number)) {
            $check_employee_stmt = $conn->prepare("SELECT id FROM accounts WHERE employee_number = ? AND id != ?");
            $check_employee_stmt->bind_param("si", $employee_number, $account_id);
            $check_employee_stmt->execute();
            $check_employee_stmt->store_result();
            
            if ($check_employee_stmt->num_rows > 0) {
                throw new Exception("Employee number already exists. Please choose a different employee number.");
            }
            $check_employee_stmt->close();
        }

        // Build update query with employee_number field
        $sql = "UPDATE accounts SET 
                last_name = ?, 
                first_name = ?, 
                middle_name = ?, 
                suffix = ?, 
                address = ?, 
                contact_number = ?, 
                account_type = ?, 
                branch = ?, 
                username = ?,
                employee_number = ?,
                updated_at = CURRENT_TIMESTAMP";
        
        $params = [$last_name, $first_name, $middle_name, $suffix, $address, $contact_number, $account_type, $branch, $username, $employee_number];
        $types = "ssssssssss";

        // Add password update if provided
        if (!empty($_POST['password'])) {
            if ($_POST['password'] !== $_POST['confirm_password']) {
                throw new Exception("Passwords do not match");
            }
            if (strlen($_POST['password']) < 8) {
                throw new Exception("Password must be at least 8 characters long");
            }
            
            $password_hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $sql .= ", password = ?";
            $params[] = $password_hash;
            $types .= "s";
        }

        $sql .= " WHERE id = ?";
        $params[] = $account_id;
        $types .= "i";

        // Prepare and execute
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }

        $stmt->bind_param($types, ...$params);

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Account updated successfully!']);
        } else {
            throw new Exception("Error updating account: " . $stmt->error);
        }

        $stmt->close();
        $conn->close();

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}
?>