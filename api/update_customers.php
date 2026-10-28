<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['logged_in'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Database configuration
require '../includes/db_config.php';

// Get POST data
$customer_id = $_POST['id'] ?? 0;

if (!$customer_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Customer ID is required']);
    exit();
}

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Handle profile image upload/removal
    $profile_image = $_POST['current_profile_image'] ?? '';
    $remove_profile_image = $_POST['remove_profile_image'] ?? '0';
    
    if ($remove_profile_image === '1') {
        // Remove profile image
        if (!empty($profile_image) && file_exists('../' . $profile_image)) {
            unlink('../' . $profile_image);
        }
        $profile_image = '';
    } elseif (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        // Upload new profile image
        $upload_dir = '../uploads/profile_assets/images/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_extension = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
        $file_name = 'customer_' . $customer_id . '_' . time() . '.' . $file_extension;
        $file_path = $upload_dir . $file_name;
        
        if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $file_path)) {
            // Delete old profile image if exists
            if (!empty($profile_image) && file_exists('../' . $profile_image)) {
                unlink('../' . $profile_image);
            }
            // Remove "../" prefix for database storage
            $profile_image = str_replace('../', '', $file_path);
        }
    }
    
    // Handle password update
    $password_update = '';
    $hashed_password = '';
    
    $password = $_POST['password'] ?? '';
    if (!empty($password)) {
        // Validate password length
        if (strlen($password) < 6) {
            throw new Exception("Password must be at least 6 characters long");
        }
        
        // Hash the password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $password_update = ", password = :password";
    }
    
    // Handle new requirements upload
    $new_requirements = [];
    if (isset($_FILES['new_requirements']) && !empty($_FILES['new_requirements']['name'][0])) {
        $upload_dir = '../uploads/requirements/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        foreach ($_FILES['new_requirements']['tmp_name'] as $index => $tmp_name) {
            if ($_FILES['new_requirements']['error'][$index] === UPLOAD_ERR_OK) {
                $file_extension = pathinfo($_FILES['new_requirements']['name'][$index], PATHINFO_EXTENSION);
                $file_name =  uniqid() . '.' . $file_extension;
                $file_path = $upload_dir . $file_name;
                
                if (move_uploaded_file($tmp_name, $file_path)) {
                    // Remove "../" prefix for database storage
                    $db_file_path = str_replace('../', '', $file_path);
                    $new_requirements[] = $db_file_path;
                }
            }
        }
    }
    
    // Get current requirements
    $stmt = $conn->prepare("SELECT requirements FROM customer_accounts WHERE id = :id");
    $stmt->bindParam(':id', $customer_id, PDO::PARAM_INT);
    $stmt->execute();
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $current_requirements = [];
    if ($customer && !empty($customer['requirements'])) {
        $current_requirements = explode(',', $customer['requirements']);
    }
    
    // Merge current and new requirements
    $all_requirements = array_merge($current_requirements, $new_requirements);
    $requirements_str = implode(',', $all_requirements);
    
    // Get branch value from POST
    $branch = $_POST['branch'] ?? '';

    // Addresses arrive as the same six boxes the registration form uses;
    // compose them back into the single address column.
    $addressParts = array_filter(array(
        trim((string) ($_POST['house_number'] ?? '')),
        trim((string) ($_POST['street'] ?? '')),
        trim((string) ($_POST['building'] ?? '')),
        trim((string) ($_POST['barangay'] ?? '')),
        trim((string) ($_POST['municipality'] ?? '')),
        trim((string) ($_POST['province'] ?? '')),
    ), 'strlen');

    if ($addressParts) {
        $_POST['address'] = implode(', ', $addressParts);
    }

    $presentParts = array_filter(array(
        trim((string) ($_POST['present_house_number'] ?? '')),
        trim((string) ($_POST['present_street'] ?? '')),
        trim((string) ($_POST['present_building'] ?? '')),
        trim((string) ($_POST['present_barangay'] ?? '')),
        trim((string) ($_POST['present_municipality'] ?? '')),
        trim((string) ($_POST['present_province'] ?? '')),
    ), 'strlen');

    if (!empty($_POST['sameAsPermanent'])) {
        $_POST['present_address'] = $_POST['address'] ?? '';
    } elseif ($presentParts) {
        $_POST['present_address'] = implode(', ', $presentParts);
    }

    // Optional username change - must stay unique.
    $username = trim((string) ($_POST['username'] ?? ''));
    $username_update = '';

    if ($username !== '') {
        $check = $conn->prepare("SELECT COUNT(*) FROM customer_accounts WHERE username = :u AND id <> :id");
        $check->execute(array(':u' => $username, ':id' => $customer_id));

        if ((int) $check->fetchColumn() > 0) {
            http_response_code(409);
            echo json_encode(array('success' => false, 'message' => 'That username is already taken.'));
            exit;
        }

        $username_update = ", username = :username";
    }
    
    // Update customer in database - NOW INCLUDING branch
    $sql = "UPDATE customer_accounts SET 
            last_name = :last_name,
            first_name = :first_name,
            middle_name = :middle_name,
            suffix = :suffix,
            gender = :gender,
            marital_status = :marital_status,
            date_of_birth = :date_of_birth,
            place_of_birth = :place_of_birth,
            spouse_name = :spouse_name,
            spouse_occupation = :spouse_occupation,
            address = :address,
            present_address = :present_address,
            occupation = :occupation,
            employer_business_name = :employer_business_name,
            nature_of_work = :nature_of_work,
            contact_number = :contact_number,
            contact_number_2 = :contact_number_2,
            branch = :branch,
            area = :area,
            status = :status,
            notes = :notes,
            profile_image = :profile_image,
            requirements = :requirements
            {$username_update}
            {$password_update}
            WHERE id = :id";
    
    $stmt = $conn->prepare($sql);
    
    // Bind all parameters
    $stmt->bindParam(':last_name', $_POST['last_name']);
    $stmt->bindParam(':first_name', $_POST['first_name']);
    $stmt->bindParam(':middle_name', $_POST['middle_name']);
    $stmt->bindParam(':suffix', $_POST['suffix']);
    $stmt->bindParam(':gender', $_POST['gender']);
    $stmt->bindParam(':marital_status', $_POST['marital_status']);
    $stmt->bindParam(':date_of_birth', $_POST['date_of_birth']);
    $stmt->bindParam(':place_of_birth', $_POST['place_of_birth']);
    $stmt->bindParam(':spouse_name', $_POST['spouse_name']);
    $stmt->bindParam(':spouse_occupation', $_POST['spouse_occupation']);
    $stmt->bindParam(':address', $_POST['address']);
    $stmt->bindParam(':present_address', $_POST['present_address']);
    $stmt->bindParam(':occupation', $_POST['occupation']);
    $stmt->bindParam(':employer_business_name', $_POST['employer_business_name']);
    $stmt->bindParam(':nature_of_work', $_POST['nature_of_work']);
    $stmt->bindParam(':contact_number', $_POST['contact_number']);
    $stmt->bindParam(':contact_number_2', $_POST['contact_number_2']);
    $stmt->bindParam(':branch', $branch);  // NOW INCLUDED
    $stmt->bindParam(':area', $_POST['area']);
    $stmt->bindParam(':status', $_POST['status']);
    $stmt->bindParam(':notes', $_POST['notes']);
    $stmt->bindParam(':profile_image', $profile_image);
    $stmt->bindParam(':requirements', $requirements_str);
    
    if ($username !== '') {
        $stmt->bindParam(':username', $username);
    }

    // Bind password if provided
    if (!empty($password)) {
        $stmt->bindParam(':password', $hashed_password);
    }
    
    $stmt->bindParam(':id', $customer_id, PDO::PARAM_INT);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Customer updated successfully']);
    } else {
        $errorInfo = $stmt->errorInfo();
        throw new Exception("Failed to update customer: " . ($errorInfo[2] ?? 'Unknown database error'));
    }
    
} catch(PDOException $e) {
    error_log("Database error in update_customers.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} catch(Exception $e) {
    error_log("Error in update_customers.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
?>