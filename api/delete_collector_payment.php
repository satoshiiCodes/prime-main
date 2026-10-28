<?php
session_start();
require_once '../includes/db_config1.php';

if (!isset($_SESSION['logged_in'])) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['payment_id']) || !isset($data['loan_id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

try {
    $conn = getDBConnection();
    
    // Get the payment details
    $stmt = $conn->prepare("SELECT * FROM collector_payments WHERE id = ?");
    $stmt->execute([$data['payment_id']]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$payment) {
        echo json_encode(['success' => false, 'message' => 'Payment not found']);
        exit;
    }
    
    // Begin transaction
    $conn->beginTransaction();
    
    // Delete the payment
    $stmt = $conn->prepare("DELETE FROM collector_payments WHERE id = ?");
    $stmt->execute([$data['payment_id']]);
    
    // Update the loan balance by adding back the payment amount
    $stmt = $conn->prepare("UPDATE collector_loans SET balance = balance + ? WHERE id = ?");
    $stmt->execute([$payment['amount'], $data['loan_id']]);
    
    // Check if loan status needs to be updated back to active
    $stmt = $conn->prepare("SELECT balance, status FROM collector_loans WHERE id = ?");
    $stmt->execute([$data['loan_id']]);
    $loan = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($loan['balance'] > 0 && $loan['status'] === 'completed') {
        $stmt = $conn->prepare("UPDATE collector_loans SET status = 'active' WHERE id = ?");
        $stmt->execute([$data['loan_id']]);
    }

    // Activity logging for payment deletion
    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
        $ip_address = $_SERVER['REMOTE_ADDR'];
        $action = "Collector Deleted payment record";
        
        // Extract payment details for logging
        $payment_id = $data['payment_id'];
        $loan_id = $data['loan_id'];
        $customer_id = $payment['customer_id'] ?? 'N/A';
        $collector_id = $payment['collector_id'] ?? 'N/A';
        $payment_amount = $payment['amount'] ?? 'N/A';
        $payment_date = $payment['payment_date'] ?? 'N/A';
        $payment_method = $payment['payment_method'] ?? 'N/A';
        $notes = $payment['notes'] ?? '';
        
        $details = "Deleted payment ID: {$payment_id} for Loan ID: {$loan_id} | " .
                   "Customer ID: {$customer_id} | " .
                   "Collector ID: {$collector_id} | " .
                   "Amount: {$payment_amount} | " .
                   "Date: {$payment_date} | " .
                   "Method: {$payment_method} | " .
                   "Notes: " . substr($notes, 0, 100); // Limit notes to 100 chars

        $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (:user_id, :action, :details, :ip_address)");
        $log_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $log_stmt->bindParam(':action', $action, PDO::PARAM_STR);
        $log_stmt->bindParam(':details', $details, PDO::PARAM_STR);
        $log_stmt->bindParam(':ip_address', $ip_address, PDO::PARAM_STR);
        $log_stmt->execute();
    }
    
    // Commit transaction
    $conn->commit();
    
    echo json_encode(['success' => true, 'message' => 'Payment deleted successfully']);
    
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log($e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>