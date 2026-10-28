<?php
session_start();
require_once '../includes/db_config.php';

// Check if user is logged in and has admin privileges
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Check if user is admin
if ($_SESSION['account_type'] !== 'Admin' && $_SESSION['account_type'] !== 'Area Manager') {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['error' => 'Admin privileges required']);
    exit();
}

// Check if required parameters are provided
if (!isset($_POST['id']) || !isset($_POST['loan_id'])) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'Missing required parameters']);
    exit();
}

$transaction_id = intval($_POST['id']);
$loan_id = intval($_POST['loan_id']);
$user_id = $_SESSION['user_id']; // Get the user ID from session

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Begin transaction
    $conn->beginTransaction();
    
    // First, get the transaction details including type and additional info for logging
    $stmt = $conn->prepare("
        SELECT st.amount, st.savings_account_id, st.transaction_type, st.notes,
               sa.customer_id,
               c.first_name as customer_first_name, c.last_name as customer_last_name,
               sa.current_balance as account_balance
        FROM savings_transactions st
        JOIN savings_accounts sa ON st.savings_account_id = sa.id
        JOIN customer_accounts c ON sa.customer_id = c.id
        WHERE st.id = :id
    ");
    $stmt->bindParam(':id', $transaction_id, PDO::PARAM_INT);
    $stmt->execute();
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$transaction) {
        throw new Exception("Savings transaction not found");
    }
    
    $amount = floatval($transaction['amount']);
    $savings_account_id = intval($transaction['savings_account_id']);
    $transaction_type = $transaction['transaction_type'];
    $customer_id = intval($transaction['customer_id']);
    $customer_name = $transaction['customer_first_name'] . ' ' . $transaction['customer_last_name'];
    $account_balance_before = floatval($transaction['account_balance']);
    $transaction_notes = $transaction['notes'] ?? '';
    
    // Check if transaction type is valid for deletion (Deposit or Withdrawal Cash)
    if (!in_array($transaction_type, ['Deposit', 'Withdrawal Cash'])) {
        throw new Exception("Only Deposit and Withdrawal Cash transactions can be deleted using this method");
    }
    
    // Delete the savings transaction
    $stmt = $conn->prepare("DELETE FROM savings_transactions WHERE id = :id");
    $stmt->bindParam(':id', $transaction_id, PDO::PARAM_INT);
    $stmt->execute();
    
    // Update the savings account balance based on transaction type
    if ($transaction_type === 'Deposit') {
        // For Deposit: subtract amount from balance (undoing the deposit)
        $stmt = $conn->prepare("UPDATE savings_accounts SET current_balance = current_balance - :amount WHERE id = :id");
    } else if ($transaction_type === 'Withdrawal Cash') {
        // For Withdrawal Cash: add amount back to balance (undoing the withdrawal)
        $stmt = $conn->prepare("UPDATE savings_accounts SET current_balance = current_balance + :amount WHERE id = :id");
    }
    
    $stmt->bindParam(':amount', $amount, PDO::PARAM_STR);
    $stmt->bindParam(':id', $savings_account_id, PDO::PARAM_INT);
    $stmt->execute();
    
    // Get the updated account balance
    $stmt = $conn->prepare("SELECT current_balance FROM savings_accounts WHERE id = :id");
    $stmt->bindParam(':id', $savings_account_id, PDO::PARAM_INT);
    $stmt->execute();
    $updated = $stmt->fetch(PDO::FETCH_ASSOC);
    $account_balance_after = floatval($updated['current_balance']);
    
    // Update financing table status to 'Active' using the provided loan_id
    $stmt = $conn->prepare("UPDATE financing SET status = 'Active' WHERE id = :loan_id");
    $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
    $stmt->execute();
    
    // Check if any rows were affected by the financing update
    $financing_updated = $stmt->rowCount();
    
    // ============================================
    // LOG THE ACTIVITY - ADDED HERE
    // ============================================
    $action = "Savings " . ($transaction_type === 'Withdrawal Cash' ? 'Withdrawal' : 'Deposit') . " Deletion";
    
    // Calculate balance change description
    $balance_change = $account_balance_after - $account_balance_before;
    $balance_change_text = "";
    if ($transaction_type === 'Deposit') {
        $balance_change_text = "Balance decreased by ₱" . number_format($amount, 2);
    } else {
        $balance_change_text = "Balance increased by ₱" . number_format($amount, 2);
    }
    
    $details = "Deleted savings transaction ID: $transaction_id" . PHP_EOL .
               "Customer: $customer_name (ID: $customer_id)" . PHP_EOL .
               "Loan ID: $loan_id" . PHP_EOL .
               "Savings Account ID: $savings_account_id" . PHP_EOL .
               "Transaction Type: $transaction_type" . PHP_EOL .
               "Amount: ₱" . number_format($amount, 2) . PHP_EOL .
               "Notes: $transaction_notes" . PHP_EOL .
               "Balance Before: ₱" . number_format($account_balance_before, 2) . PHP_EOL .
               "Balance After: ₱" . number_format($account_balance_after, 2) . PHP_EOL .
               $balance_change_text . PHP_EOL .
               "Loan Status Reset to: Active" . ($financing_updated ? " (Updated)" : " (No Change)");
    
    $ip_address = $_SERVER['REMOTE_ADDR'];
    
    $log_stmt = $conn->prepare("
        INSERT INTO activity_logs (user_id, action, details, ip_address, created_at)
        VALUES (:user_id, :action, :details, :ip_address, NOW())
    ");
    $log_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $log_stmt->bindParam(':action', $action, PDO::PARAM_STR);
    $log_stmt->bindParam(':details', $details, PDO::PARAM_STR);
    $log_stmt->bindParam(':ip_address', $ip_address, PDO::PARAM_STR);
    $log_stmt->execute();
    
    // Commit transaction
    $conn->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Savings transaction deleted successfully',
        'transaction_type' => $transaction_type,
        'amount_adjusted' => $amount,
        'financing_updated' => $financing_updated > 0,
        'loan_id' => $loan_id,
        'new_balance' => $account_balance_after
    ]);
    
} catch (PDOException $e) {
    // Rollback transaction on error
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    // Rollback transaction on error
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => $e->getMessage()]);
}
?>