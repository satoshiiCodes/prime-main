<?php
session_start();
require '../includes/db_config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in'])) {
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

$penalty_id = $_GET['id'] ?? '';

if (empty($penalty_id)) {
    echo json_encode(['error' => 'Penalty ID is required']);
    exit();
}

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get penalty details with related information
    $stmt = $conn->prepare("
        SELECT p.*, 
               c.first_name AS collector_first_name, 
               c.last_name AS collector_last_name,
               cust.first_name AS customer_first_name,
               cust.middle_name AS customer_middle_name,
               cust.last_name AS customer_last_name,
               cust.suffix AS customer_suffix
        FROM penalties p
        LEFT JOIN collector_accounts c ON p.collector_id = c.id
        LEFT JOIN financing f ON p.loan_id = f.id
        LEFT JOIN customer_accounts cust ON f.customer_id = cust.id
        WHERE p.id = :id
    ");
    $stmt->bindParam(':id', $penalty_id);
    $stmt->execute();
    $penalty = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$penalty) {
        throw new Exception("Penalty not found");
    }
    
    echo json_encode($penalty);
    
} catch (Exception $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>