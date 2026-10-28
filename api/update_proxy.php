<?php
session_start();
require '../includes/db_config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    
    // Get form data
    $proxy_id = $conn->real_escape_string($_POST['id']);
    $last_name = $conn->real_escape_string($_POST['last_name']);
    $first_name = $conn->real_escape_string($_POST['first_name']);
    $middle_name = isset($_POST['middle_name']) ? $conn->real_escape_string($_POST['middle_name']) : null;
    $suffix = !empty($_POST['suffix']) ? $conn->real_escape_string($_POST['suffix']) : null;
    $address = $conn->real_escape_string($_POST['address']);
    $contact_number = $conn->real_escape_string($_POST['contact_number']);
    $account_type = $conn->real_escape_string($_POST['account_type']);
    $status = $conn->real_escape_string($_POST['status']);
    $gender = $conn->real_escape_string($_POST['gender']);
    $area = $conn->real_escape_string($_POST['area']);
    $branch = $conn->real_escape_string($_POST['branch']);
    $birth_date = $conn->real_escape_string($_POST['birth_date']);
    $place_of_birth = $conn->real_escape_string($_POST['place_of_birth']);
    $spouse_name = isset($_POST['spouse_name']) ? $conn->real_escape_string($_POST['spouse_name']) : null;
    $account_status = $conn->real_escape_string($_POST['account_status']);
    $username = $conn->real_escape_string($_POST['username']);
    $employee_number = isset($_POST['employee_number']) && !empty($_POST['employee_number']) ? $conn->real_escape_string($_POST['employee_number']) : null;
    
    // Validate required fields
    if (empty($last_name) || empty($first_name) || empty($address) || empty($contact_number) || 
        empty($status) || empty($gender) || empty($branch) || empty($birth_date) || empty($place_of_birth) || 
        empty($account_status) || empty($account_type) || empty($username)) {
        throw new Exception("All required fields must be filled");
    }

    // Validate contact number format
    $contact_number = preg_replace('/[^0-9]/', '', $contact_number);
    if (strlen($contact_number) != 11 || substr($contact_number, 0, 2) != '09') {
        throw new Exception("Contact number must be 11 digits starting with '09'");
    }

    // Check if username already exists (excluding current proxy)
    $check_username_sql = "SELECT id FROM proxy_accounts WHERE username = '$username' AND id != $proxy_id";
    $result = $conn->query($check_username_sql);
    if ($result && $result->num_rows > 0) {
        throw new Exception("Username already exists. Please choose a different username.");
    }

    // Check if employee number already exists (if provided and excluding current proxy)
    if (!empty($employee_number)) {
        $check_employee_sql = "SELECT id FROM proxy_accounts WHERE employee_number = '$employee_number' AND id != $proxy_id";
        $employee_result = $conn->query($check_employee_sql);
        if ($employee_result && $employee_result->num_rows > 0) {
            throw new Exception("Employee number already exists. Please choose a different employee number.");
        }
    }

    // Handle file upload
    $profile_image = null;
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == UPLOAD_ERR_OK) {
        $target_dir = __DIR__ . "/../uploads/profile_assets/images/";
        
        if (!file_exists($target_dir)) {
            if (!mkdir($target_dir, 0755, true)) {
                throw new Exception("Failed to create upload directory");
            }
        }
        
        $file_extension = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
        $new_filename = uniqid() . '.' . $file_extension;
        $target_file = $target_dir . $new_filename;
        
        $check = getimagesize($_FILES['profile_image']['tmp_name']);
        if ($check !== false) {
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $target_file)) {
                $profile_image = "uploads/profile_assets/images/" . $new_filename;
            } else {
                throw new Exception("Sorry, there was an error uploading your file.");
            }
        } else {
            throw new Exception("File is not an image.");
        }
    }

    // Handle password update if provided
    $password_update = '';
    if (!empty($_POST['password'])) {
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        
        if ($password !== $confirm_password) {
            throw new Exception("Password and Confirm Password do not match");
        }

        if (strlen($password) < 6) {
            throw new Exception("Password must be at least 6 characters long");
        }
        
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $password_update = ", password = '$hashed_password'";
    }

    // Prepare SQL statement with username and employee_number
    if (!empty($profile_image)) {
        $sql = "UPDATE proxy_accounts SET 
            last_name = '$last_name', 
            first_name = '$first_name', 
            middle_name = " . ($middle_name ? "'$middle_name'" : "NULL") . ", 
            suffix = " . ($suffix ? "'$suffix'" : "NULL") . ", 
            address = '$address', 
            contact_number = '$contact_number', 
            account_type = '$account_type', 
            status = '$status', 
            gender = '$gender', 
            area = '$area', 
            branch = '$branch', 
            birth_date = '$birth_date', 
            place_of_birth = '$place_of_birth', 
            spouse_name = " . ($spouse_name ? "'$spouse_name'" : "NULL") . ", 
            account_status = '$account_status', 
            username = '$username', 
            employee_number = " . ($employee_number ? "'$employee_number'" : "NULL") . ", 
            profile_image = '$profile_image', 
            updated_at = NOW() 
            $password_update 
            WHERE id = $proxy_id";
    } else {
        $sql = "UPDATE proxy_accounts SET 
            last_name = '$last_name', 
            first_name = '$first_name', 
            middle_name = " . ($middle_name ? "'$middle_name'" : "NULL") . ", 
            suffix = " . ($suffix ? "'$suffix'" : "NULL") . ", 
            address = '$address', 
            contact_number = '$contact_number', 
            account_type = '$account_type', 
            status = '$status', 
            gender = '$gender', 
            area = '$area', 
            branch = '$branch', 
            birth_date = '$birth_date', 
            place_of_birth = '$place_of_birth', 
            spouse_name = " . ($spouse_name ? "'$spouse_name'" : "NULL") . ", 
            account_status = '$account_status', 
            username = '$username', 
            employee_number = " . ($employee_number ? "'$employee_number'" : "NULL") . ", 
            updated_at = NOW() 
            $password_update 
            WHERE id = $proxy_id";
    }

    if ($conn->query($sql) === TRUE) {
        echo json_encode(['success' => true, 'message' => 'Proxy account updated successfully!']);
    } else {
        throw new Exception("Error updating proxy account: " . $conn->error);
    }

    $conn->close();
    
} catch (Exception $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>