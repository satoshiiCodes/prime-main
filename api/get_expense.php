<?php
session_start();

// Redirect to login if not authenticated
if (!isset($_SESSION['logged_in'])) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Database configuration
require '../includes/db_config.php';

// Get expense ID from request
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'Invalid ID']);
    exit;
}

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    $stmt = $conn->prepare("SELECT * FROM expenses WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $expense = $result->fetch_assoc();
        // Format the date for datetime-local input
        $expense['expense_date'] = date('Y-m-d\TH:i', strtotime($expense['expense_date']));
        header('Content-Type: application/json');
        echo json_encode($expense);
    } else {
        header('HTTP/1.1 404 Not Found');
        echo json_encode(['error' => 'Expense not found']);
    }
    
    $stmt->close();
    $conn->close();
} catch (Exception $e) {
    error_log($e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
?>