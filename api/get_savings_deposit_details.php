<?php
session_start();
require_once '../includes/db_config.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Check if deposit ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'Invalid deposit ID']);
    exit();
}

$deposit_id = intval($_GET['id']);

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get savings deposit details with customer and collector information
    $stmt = $conn->prepare("
        SELECT 
            st.*,
            ca.first_name AS customer_first_name,
            ca.middle_name AS customer_middle_name,
            ca.last_name AS customer_last_name,
            ca.suffix AS customer_suffix,
            col.first_name AS collector_first_name,
            col.last_name AS collector_last_name,
            sa.loan_id
        FROM savings_transactions st
        INNER JOIN savings_accounts sa ON st.savings_account_id = sa.id
        INNER JOIN customer_accounts ca ON sa.customer_id = ca.id
        LEFT JOIN collector_accounts col ON st.collector_id = col.id
        WHERE st.id = :id
    ");
    $stmt->bindParam(':id', $deposit_id, PDO::PARAM_INT);
    $stmt->execute();
    
    $deposit = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$deposit) {
        header('HTTP/1.1 404 Not Found');
        echo json_encode(['error' => 'Savings deposit not found']);
        exit();
    }
    
    echo json_encode($deposit);
    
} catch (PDOException $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>