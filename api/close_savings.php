<?php
session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['logged_in'])) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['error' => 'Not authorized']);
    exit();
}

// Check if loan_id is provided
if (!isset($_POST['loan_id']) || !is_numeric($_POST['loan_id'])) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'Invalid loan ID']);
    exit();
}

$loan_id = intval($_POST['loan_id']);

// Database configuration
require '../includes/db_config.php';

try {
    // Create connection
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check if savings account exists for this loan
    $stmt = $conn->prepare("SELECT id, status FROM savings_accounts WHERE loan_id = :loan_id");
    $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
    $stmt->execute();
    $savings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$savings) {
        throw new Exception("No savings account found for this loan");
    }
    
    if ($savings['status'] === 'Closed') {
        throw new Exception("Savings account is already closed");
    }
    
    $stmt = $conn->prepare("UPDATE savings_accounts SET status = 'Closed' WHERE loan_id = :loan_id");
    $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
    $stmt->execute();
    
    // Return success response
    echo json_encode(['success' => true]);
    
} catch(PDOException $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} catch(Exception $e) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => $e->getMessage()]);
}
?>