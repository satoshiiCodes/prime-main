<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['logged_in'])) {
    header('HTTP/1.1 401 Unauthorized');
    die(json_encode(['error' => 'Unauthorized access']));
}

// Check if loan_id is provided
if (!isset($_GET['loan_id']) || !is_numeric($_GET['loan_id'])) {
    header('HTTP/1.1 400 Bad Request');
    die(json_encode(['error' => 'Invalid loan ID']));
}

$loan_id = intval($_GET['loan_id']);

// Database configuration
require '../includes/db_config.php';

try {
    // Create connection
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get penalties for this loan (include both 'added' and 'partial_waiver' types)
    $stmt = $conn->prepare("
        SELECT 
            p.id,
            p.amount,
            p.amount as original_amount,
            p.notes,
            p.type,
            DATE(p.created_at) as date,
            p.created_at
        FROM penalties p 
        WHERE p.loan_id = :loan_id 
        AND p.type IN ('added', 'partial waiver')
        AND p.amount > 0
        ORDER BY p.created_at ASC
    ");
    $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
    $stmt->execute();
    $penalties = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Return penalties data
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'penalties' => $penalties
    ]);
    
} catch(PDOException $e) {
    header('HTTP/1.1 500 Internal Server Error');
    die(json_encode(['error' => 'Database error: ' . $e->getMessage()]));
} catch(Exception $e) {
    header('HTTP/1.1 400 Bad Request');
    die(json_encode(['error' => $e->getMessage()]));
}
?>