<?php
session_start();

// Database configuration
require '../includes/db_config.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Create connection function
function getDBConnection() {
    global $servername, $username, $password, $dbname;
    
    try {
        $conn = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8mb4", $username, $password);
        // Set the PDO error mode to exception
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        return $conn;
    } catch(PDOException $e) {
        // Log error to file in production
        error_log("Connection failed: " . $e->getMessage());
        
        // Display user-friendly message
        die("Could not connect to the database. Please try again later.");
    }
}

// Function to close connection (though PDO doesn't need it usually)
function closeDBConnection($conn = null) {
    $conn = null;
}

header('Content-Type: application/json');

try {
    if (!isset($_SESSION['logged_in'])) {
        throw new Exception("Unauthorized access");
    }

    $id = $_GET['id'] ?? null;
    if (!$id) {
        throw new Exception("Collector ID is required");
    }

    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $conn->prepare("SELECT * FROM collector_accounts WHERE id = :id");
    $stmt->bindParam(':id', $id);
    $stmt->execute();
    
    $collector = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$collector) {
        throw new Exception("Collector not found");
    }

    echo json_encode([
        'success' => true,
        'collector' => $collector
    ]);
} catch(PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch(Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} finally {
    $conn = null;
}
?>