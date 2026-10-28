<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['logged_in'])) {
    header("HTTP/1.1 403 Forbidden");
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Validate input
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("HTTP/1.1 400 Bad Request");
    echo json_encode(['error' => 'Invalid payment ID']);
    exit();
}

$payment_id = $_GET['id'];

// Database configuration
require '../includes/db_config.php';

try {
    // Create connection
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get payment details with customer, collector, and proxy info
    $stmt = $conn->prepare("
        SELECT 
            p.*,
            c.first_name AS customer_first_name,
            c.last_name AS customer_last_name,
            c.middle_name AS customer_middle_name,
            c.suffix AS customer_suffix,
            col.first_name AS collector_first_name,
            col.last_name AS collector_last_name,
            COALESCE(CONCAT(px.first_name, ' ', px.last_name), 'N/A') AS proxy_name
        FROM payments p
        LEFT JOIN customer_accounts c ON p.customer_id = c.id
        LEFT JOIN collector_accounts col ON p.collector_id = col.id
        LEFT JOIN proxy_accounts px ON p.proxy = px.id
        WHERE p.id = :id
    ");
    $stmt->bindParam(':id', $payment_id);
    $stmt->execute();
    
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$payment) {
        throw new Exception("Payment not found");
    }
    
    // Format customer name
    $payment['customer_name'] = $payment['customer_last_name'] . ', ' . $payment['customer_first_name'];
    if (!empty($payment['customer_middle_name'])) {
        $payment['customer_name'] .= ' ' . $payment['customer_middle_name'];
    }
    
    echo json_encode($payment);
} catch(PDOException $e) {
    header("HTTP/1.1 500 Internal Server Error");
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} catch(Exception $e) {
    header("HTTP/1.1 404 Not Found");
    echo json_encode(['error' => $e->getMessage()]);
}
?>