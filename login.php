<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Database configuration
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "db_prime_city";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode([
        "success" => false, 
        "message" => "Database connection failed"
    ]);
    exit();
}

// Get posted data
$data = json_decode(file_get_contents("php://input"));

if (!isset($data->username) || !isset($data->password)) {
    http_response_code(400);
    echo json_encode([
        "success" => false, 
        "message" => "Username and password are required"
    ]);
    exit();
}

$username = $conn->real_escape_string($data->username);
$password = $data->password;

// Function to search user in both tables
function findUser($conn, $username, $password) {
    // Search in customer_accounts table
    $sql_customer = "SELECT id, last_name, first_name, middle_name, suffix, gender, 
                            marital_status, date_of_birth, place_of_birth, spouse_name, 
                            spouse_occupation, address, present_address, occupation, 
                            employer_business_name, nature_of_work, contact_number, 
                            contact_number_2, username, password, account_type, branch, 
                            registration_date, profile_image, requirements, area, status, notes 
                     FROM customer_accounts 
                     WHERE username = '$username' AND status = 'Active'";
    
    $result_customer = $conn->query($sql_customer);
    
    if ($result_customer && $result_customer->num_rows > 0) {
        $user = $result_customer->fetch_assoc();
        
        // Verify password
        if (password_verify($password, $user['password'])) {
            // Remove password from response
            unset($user['password']);
            $user['user_type'] = 'customer';
            return $user;
        }
    }
    
    // Search in collector_accounts table
    $sql_collector = "SELECT id, last_name, first_name, middle_name, suffix, address, 
                             contact_number, account_type, branch, registration_date, 
                             status, gender, area, username, password 
                      FROM collector_accounts 
                      WHERE username = '$username'";
    
    $result_collector = $conn->query($sql_collector);
    
    if ($result_collector && $result_collector->num_rows > 0) {
        $user = $result_collector->fetch_assoc();
        
        // Verify password
        if (password_verify($password, $user['password'])) {
            // Remove password from response
            unset($user['password']);
            $user['user_type'] = 'collector';
            return $user;
        }
    }
    
    return null;
}

// Find user in both tables
$user = findUser($conn, $username, $password);

if ($user) {
    echo json_encode([
        "success" => true,
        "message" => "Login successful",
        "user" => $user
    ]);
} else {
    http_response_code(401);
    echo json_encode([
        "success" => false, 
        "message" => "Invalid username or password"
    ]);
}

$conn->close();
?>