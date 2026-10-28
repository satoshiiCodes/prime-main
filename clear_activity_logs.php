<?php
session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['logged_in']) || $_SESSION['account_type'] !== 'Admin') {
    header("HTTP/1.1 403 Forbidden");
    echo json_encode(['error' => 'Access denied']);
    exit();
}

// Database configuration
require 'includes/db_config.php';

try {
    // Create connection
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Clear all activity logs
    $stmt = $conn->prepare("TRUNCATE TABLE activity_logs");
    $stmt->execute();
    
    echo json_encode(['success' => true, 'message' => 'All activity logs cleared successfully']);
    
} catch(PDOException $e) {
    header("HTTP/1.1 500 Internal Server Error");
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>