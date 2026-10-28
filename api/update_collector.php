<?php
session_start();
require_once '../includes/db_config1.php'; // Adjust path as needed

header('Content-Type: application/json');

try {
    // Validate session
    if (!isset($_SESSION['logged_in'])) {
        throw new Exception("Unauthorized access", 401);
    }

    // Validate input
    if (!isset($_POST['id'])) {
        throw new Exception("Collector ID is required", 400);
    }

    $id = $_POST['id'];
    $conn = getDBConnection();

    // Get current data
    $stmt = $conn->prepare("SELECT * FROM collector_accounts WHERE id = ?");
    $stmt->execute([$id]);
    $currentData = $stmt->fetch();

    if (!$currentData) {
        throw new Exception("Collector not found", 404);
    }

    // Handle file upload
    $profileImage = $currentData['profile_image'] ?? null;
    
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == UPLOAD_ERR_OK) {
        $targetDir = "../uploads/profile_assets/images/";
        if (!file_exists($targetDir)) {
            mkdir($targetDir, 0777, true);
        }
        
        // Delete old image if exists
        if ($profileImage && file_exists("../" . $profileImage)) {
            unlink("../" . $profileImage);
        }
        
        $fileExt = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
        $newFilename = uniqid() . '.' . $fileExt;
        $targetFile = $targetDir . $newFilename;
        
        if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $targetFile)) {
            $profileImage = str_replace('../', '', $targetFile);
        } else {
            throw new Exception("Failed to upload profile image");
        }
    } elseif (isset($_POST['current_profile_image'])) {
        $profileImage = $_POST['current_profile_image'];
    }

    // Validate required fields
    $requiredFields = [
        'last_name', 'first_name', 'birth_date', 'place_of_birth',
        'address', 'contact_number', 'area', 'account_status',
        'status', 'gender', 'branch', 'account_type', 'username'
    ];
    
    foreach ($requiredFields as $field) {
        if (empty($_POST[$field])) {
            throw new Exception("Field $field is required", 400);
        }
    }

    // Check if username already exists (excluding current user)
    $checkUsernameStmt = $conn->prepare("SELECT id FROM collector_accounts WHERE username = ? AND id != ?");
    $checkUsernameStmt->execute([$_POST['username'], $id]);
    if ($checkUsernameStmt->fetch()) {
        throw new Exception("Username already exists. Please choose a different username.", 400);
    }

    // Check if employee number already exists (if provided and excluding current user)
    if (!empty($_POST['employee_number'])) {
        $checkEmployeeStmt = $conn->prepare("SELECT id FROM collector_accounts WHERE employee_number = ? AND id != ?");
        $checkEmployeeStmt->execute([$_POST['employee_number'], $id]);
        if ($checkEmployeeStmt->fetch()) {
            throw new Exception("Employee number already exists. Please choose a different employee number.", 400);
        }
    }

    // Handle password update if provided
    $passwordUpdate = '';
    $passwordParams = [];
    
    if (!empty($_POST['password'])) {
        $password = $_POST['password'];
        $confirmPassword = $_POST['confirm_password'];
        
        // Validate password match
        if ($password !== $confirmPassword) {
            throw new Exception("Password and Confirm Password do not match", 400);
        }
        
        // Validate password strength
        if (strlen($password) < 6) {
            throw new Exception("Password must be at least 6 characters long", 400);
        }
        
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $passwordUpdate = ", password = ?";
        $passwordParams[] = $hashedPassword;
    }

    // Prepare update statement with all fields including username and employee_number
    $sql = "UPDATE collector_accounts SET 
        last_name = ?,
        first_name = ?,
        middle_name = ?,
        suffix = ?,
        status = ?,
        gender = ?,
        birth_date = ?,
        place_of_birth = ?,
        spouse_name = ?,
        address = ?,
        contact_number = ?,
        area = ?,
        branch = ?,
        account_type = ?,
        account_status = ?,
        username = ?,
        employee_number = ?,
        profile_image = ?" . $passwordUpdate . "
        WHERE id = ?";

    $params = [
        $_POST['last_name'],
        $_POST['first_name'],
        $_POST['middle_name'] ?? null,
        $_POST['suffix'] ?? 'None',
        $_POST['status'],
        $_POST['gender'],
        $_POST['birth_date'],
        $_POST['place_of_birth'],
        $_POST['spouse_name'] ?? null,
        $_POST['address'],
        $_POST['contact_number'],
        $_POST['area'],
        $_POST['branch'],
        $_POST['account_type'],
        $_POST['account_status'],
        $_POST['username'],
        $_POST['employee_number'] ?? null,
        $profileImage
    ];

    // Add password parameters if updating password
    if (!empty($passwordUpdate)) {
        $params = array_merge($params, $passwordParams);
    }

    // Add the ID parameter
    $params[] = $id;

    $stmt = $conn->prepare($sql);
    $success = $stmt->execute($params);

    if ($success) {
        echo json_encode([
            'success' => true,
            'message' => 'Collector updated successfully'
        ]);
    } else {
        throw new Exception("Failed to update collector");
    }
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch(Exception $e) {
    http_response_code($e->getCode() ?: 400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>