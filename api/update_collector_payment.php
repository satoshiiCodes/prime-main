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

if (!isset($_POST['payment_id']) || !isset($_POST['loan_id']) || !isset($_POST['amount'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

try {
    $conn = getDBConnection();
    
    // Get the current payment details
    $stmt = $conn->prepare("SELECT * FROM collector_payments WHERE id = ?");
    $stmt->execute([$_POST['payment_id']]);
    $currentPayment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$currentPayment) {
        echo json_encode(['success' => false, 'message' => 'Payment not found']);
        exit;
    }
    
    // Begin transaction
    $conn->beginTransaction();
    
    // Update the payment - only the amount field
    $stmt = $conn->prepare("UPDATE collector_payments SET amount = ? WHERE id = ?");
    $stmt->execute([
        $_POST['amount'],
        $_POST['payment_id']
    ]);
    
    // Calculate the difference in payment amount
    $amountDifference = $_POST['amount'] - $currentPayment['amount'];
    
    if ($amountDifference != 0) {
        // Update the loan balance
        $stmt = $conn->prepare("UPDATE collector_loans SET balance = balance - ? WHERE id = ?");
        $stmt->execute([$amountDifference, $_POST['loan_id']]);
        
        // Check if loan is now fully paid
        $stmt = $conn->prepare("SELECT balance FROM collector_loans WHERE id = ?");
        $stmt->execute([$_POST['loan_id']]);
        $loan = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($loan['balance'] <= 0) {
            $stmt = $conn->prepare("UPDATE collector_loans SET status = 'completed' WHERE id = ?");
            $stmt->execute([$_POST['loan_id']]);
        }
    }

    // Log the activity
    $user_id = $_SESSION['user_id']; // Assuming user_id is stored in session
    $payment_id = $_POST['payment_id'];
    $loan_id = $_POST['loan_id'];
    $old_amount = $currentPayment['amount'];
    $new_amount = $_POST['amount'];
    $action = "Collector Payment Update";
    $details = "Updated payment ID: $payment_id (Loan ID: $loan_id). Amount changed from " . number_format($old_amount, 2) . " to " . number_format($new_amount, 2);
    $ip_address = $_SERVER['REMOTE_ADDR'];
    
    $stmt = $conn->prepare("
        INSERT INTO activity_logs (user_id, action, details, ip_address, created_at)
        VALUES (:user_id, :action, :details, :ip_address, NOW())
    ");
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->bindParam(':action', $action, PDO::PARAM_STR);
    $stmt->bindParam(':details', $details, PDO::PARAM_STR);
    $stmt->bindParam(':ip_address', $ip_address, PDO::PARAM_STR);
    $stmt->execute();
    
    // Commit transaction
    $conn->commit();
    
    echo json_encode(['success' => true, 'message' => 'Payment updated successfully']);
    
} catch (Exception $e) {
    $conn->rollBack();
    error_log($e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}