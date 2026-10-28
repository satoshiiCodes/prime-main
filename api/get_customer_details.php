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

// Get customer ID from request
$customerId = $_GET['id'] ?? 0;

if (!$customerId) {
    http_response_code(400);
    echo json_encode(['error' => 'Customer ID is required']);
    exit();
}

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Fetch customer details
    $stmt = $conn->prepare("SELECT * FROM customer_accounts WHERE id = :id");
    $stmt->bindParam(':id', $customerId);
    $stmt->execute();
    
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        throw new Exception("Customer not found");
    }
    
    // Return customer data as JSON
    echo json_encode($customer);
    
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} catch(Exception $e) {
    http_response_code(404);
    echo json_encode(['error' => $e->getMessage()]);
}
?>