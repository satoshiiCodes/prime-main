<?php
session_start();

header('Content-Type: application/json');

// Check if user is logged in and has permission
if (!isset($_SESSION['logged_in'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized - Please login first']);
    exit();
}

// Check if required parameters are provided
if (!isset($_POST['id']) || !isset($_POST['loan_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Bad Request - Missing parameters']);
    exit();
}

$payment_id = intval($_POST['id']);
$loan_id = intval($_POST['loan_id']);

// Validate IDs
if ($payment_id <= 0 || $loan_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payment or loan ID']);
    exit();
}

// Database configuration
require '../includes/db_config.php';

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Begin transaction
    $conn->beginTransaction();
    
    // 1. Get payment details and verify it exists
    $stmt = $conn->prepare("SELECT amount, customer_id, collector_id, payment_date, payment_method, notes FROM payments WHERE id = :id");
    $stmt->bindParam(':id', $payment_id, PDO::PARAM_INT);
    $stmt->execute();
    
    if ($stmt->rowCount() === 0) {
        throw new Exception("Payment not found");
    }
    
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    $payment_amount = floatval($payment['amount']);
    $customer_id = $payment['customer_id'];
    $collector_id = $payment['collector_id'];
    $payment_date = $payment['payment_date'];
    $payment_method = $payment['payment_method'];
    $notes = $payment['notes'];
    
    // Check if this is a "Payment from Savings"
    $is_payment_from_savings = (strpos($notes, 'Payment from Savings') !== false || 
                               strpos($notes, 'payment from savings') !== false);
    
    $savings_account_id = null;
    $savings_transaction_id = null;
    
    // 2. If this is a payment from savings, find and delete the corresponding savings transaction
    if ($is_payment_from_savings) {
        // Find the savings account for this loan
        $stmt = $conn->prepare("SELECT id FROM savings_accounts WHERE loan_id = :loan_id AND customer_id = :customer_id");
        $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
        $stmt->bindParam(':customer_id', $customer_id, PDO::PARAM_INT);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $savings_account = $stmt->fetch(PDO::FETCH_ASSOC);
            $savings_account_id = $savings_account['id'];
            
            // Find the savings withdrawal transaction that corresponds to this payment
            // Look for withdrawals on the same date with similar amount
            $stmt = $conn->prepare("
                SELECT id, amount 
                FROM savings_transactions 
                WHERE savings_account_id = :savings_account_id 
                AND transaction_type LIKE '%Withdrawal%'
                AND DATE(transaction_date) = DATE(:payment_date)
                AND ABS(amount - :payment_amount) < 0.01
                ORDER BY id DESC 
                LIMIT 1
            ");
            $stmt->bindParam(':savings_account_id', $savings_account_id, PDO::PARAM_INT);
            $stmt->bindParam(':payment_date', $payment_date);
            $stmt->bindParam(':payment_amount', $payment_amount);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $savings_transaction = $stmt->fetch(PDO::FETCH_ASSOC);
                $savings_transaction_id = $savings_transaction['id'];
                $savings_withdrawal_amount = floatval($savings_transaction['amount']);
                
                // Delete the savings transaction
                $stmt = $conn->prepare("DELETE FROM savings_transactions WHERE id = :id");
                $stmt->bindParam(':id', $savings_transaction_id, PDO::PARAM_INT);
                $stmt->execute();
                
                // Update the savings account balance by adding back the withdrawn amount
                $stmt = $conn->prepare("
                    UPDATE savings_accounts 
                    SET current_balance = current_balance + :amount,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :savings_account_id
                ");
                $stmt->bindParam(':amount', $savings_withdrawal_amount);
                $stmt->bindParam(':savings_account_id', $savings_account_id, PDO::PARAM_INT);
                $stmt->execute();
                
                // Get the updated savings balance
                $stmt = $conn->prepare("SELECT current_balance FROM savings_accounts WHERE id = :savings_account_id");
                $stmt->bindParam(':savings_account_id', $savings_account_id, PDO::PARAM_INT);
                $stmt->execute();
                $updated_savings = $stmt->fetch(PDO::FETCH_ASSOC);
                $new_savings_balance = floatval($updated_savings['current_balance']);
            }
        }
    }
    
    // 3. Delete the payment
    $stmt = $conn->prepare("DELETE FROM payments WHERE id = :id");
    $stmt->bindParam(':id', $payment_id, PDO::PARAM_INT);
    $stmt->execute();

    // Activity logging for payment deletion
    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
        $ip_address = $_SERVER['REMOTE_ADDR'];
        $action = "Deleted payment record";
        $details = "Deleted payment ID: {$payment_id} for Loan ID: {$loan_id} | " .
                   "Customer ID: {$customer_id} | " .
                   "Collector ID: {$collector_id} | " .
                   "Amount: {$payment_amount} | " .
                   "Date: {$payment_date} | " .
                   "Method: {$payment_method} | " .
                   "Notes: " . substr($notes, 0, 100); // Limit notes to 100 chars

        // Add savings transaction info if applicable
        if ($is_payment_from_savings && $savings_transaction_id) {
            $details .= " | Also deleted savings transaction ID: {$savings_transaction_id}";
        }

        $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (:user_id, :action, :details, :ip_address)");
        $log_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $log_stmt->bindParam(':action', $action, PDO::PARAM_STR);
        $log_stmt->bindParam(':details', $details, PDO::PARAM_STR);
        $log_stmt->bindParam(':ip_address', $ip_address, PDO::PARAM_STR);
        $log_stmt->execute();
    }

    // 4. Update the loan status to 'active'
    $stmt = $conn->prepare("UPDATE financing SET status = 'active' WHERE id = :loan_id");
    $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
    $stmt->execute();
    
    // 5. Get current loan balance
    $stmt = $conn->prepare("SELECT balance, status FROM financing WHERE id = :loan_id");
    $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
    $stmt->execute();
    
    if ($stmt->rowCount() === 0) {
        throw new Exception("Loan not found");
    }
    
    $loan = $stmt->fetch(PDO::FETCH_ASSOC);
    $current_balance = floatval($loan['balance']);
    $current_status = $loan['status'];
    
    // 6. Update loan balance by adding the deleted payment amount back
    $new_balance = $current_balance + $payment_amount;
    
    // Determine new status - only change to 'active' if it was 'paid'
    $new_status = ($current_status === 'paid') ? 'active' : $current_status;
    
    $stmt = $conn->prepare("
        UPDATE financing 
        SET status = :new_status,
            balance = :new_balance
        WHERE id = :loan_id
    ");
    $stmt->bindParam(':new_balance', $new_balance, PDO::PARAM_STR);
    $stmt->bindParam(':new_status', $new_status, PDO::PARAM_STR);
    $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
    $stmt->execute();
    
    // Commit transaction
    $conn->commit();
    
    $response = [
        'success' => true, 
        'message' => 'Payment deleted successfully',
        'new_balance' => $new_balance,
        'new_status' => $new_status
    ];
    
    // Add savings info to response if applicable
    if ($is_payment_from_savings) {
        $response['is_payment_from_savings'] = true;
        if ($savings_transaction_id) {
            $response['savings_transaction_deleted'] = true;
            $response['savings_account_id'] = $savings_account_id;
            $response['new_savings_balance'] = $new_savings_balance;
        } else {
            $response['savings_transaction_deleted'] = false;
            $response['message'] .= ' (Note: Corresponding savings transaction not found)';
        }
    }
    
    echo json_encode($response);
    
} catch(PDOException $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    exit();
} catch(Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit();
}
?>