<?php
session_start();
require_once '../includes/db_config.php';

// Check if user is logged in and has appropriate privileges
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Check if user is admin or area manager
if ($_SESSION['account_type'] !== 'Admin' && $_SESSION['account_type'] !== 'Area Manager') {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['error' => 'Insufficient privileges']);
    exit();
}

// Check if required parameters are provided
if (!isset($_POST['id']) || !isset($_POST['amount'])) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'Missing required parameters']);
    exit();
}

$deposit_id = intval($_POST['id']);
$new_amount = floatval($_POST['amount']);
$user_id = $_SESSION['user_id'];

if ($new_amount <= 0) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'Amount must be greater than 0']);
    exit();
}

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Begin transaction
    $conn->beginTransaction();
    
    // First, get the current deposit details including transaction type
    $stmt = $conn->prepare("
        SELECT st.amount, st.savings_account_id, st.transaction_type, st.notes,
               sa.current_balance as account_balance,
               c.first_name as customer_first_name, c.last_name as customer_last_name,
               l.id as loan_id
        FROM savings_transactions st
        JOIN savings_accounts sa ON st.savings_account_id = sa.id
        JOIN customer_accounts c ON sa.customer_id = c.id
        JOIN financing l ON sa.loan_id = l.id
        WHERE st.id = :id
    ");
    $stmt->bindParam(':id', $deposit_id, PDO::PARAM_INT);
    $stmt->execute();
    $current_deposit = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$current_deposit) {
        throw new Exception("Savings transaction not found");
    }
    
    $old_amount = floatval($current_deposit['amount']);
    $savings_account_id = intval($current_deposit['savings_account_id']);
    $transaction_type = $current_deposit['transaction_type'];
    $account_balance = floatval($current_deposit['account_balance']);
    $loan_id = intval($current_deposit['loan_id']);
    $customer_name = $current_deposit['customer_first_name'] . ' ' . $current_deposit['customer_last_name'];
    $current_notes = $current_deposit['notes'] ?? '';
    
    $amount_difference = $new_amount - $old_amount;
    
    // Validate that the new amount doesn't make account negative for withdrawals
    if ($transaction_type === 'Withdrawal Cash') {
        // For withdrawals, we need to ensure the account has enough balance
        // The effective withdrawal after change would be: new_amount
        // Account balance after change would be: account_balance - (new_amount - old_amount)
        $new_balance_after_withdrawal = $account_balance - $amount_difference;
        if ($new_balance_after_withdrawal < 0) {
            throw new Exception("Cannot increase withdrawal amount. Insufficient savings balance. Available balance: ₱" . number_format($account_balance, 2));
        }
    }
    
    // Update the savings transaction amount with edit notes
    $new_notes = "EDITED - " . $current_notes;
    if (strpos($current_notes, 'EDITED - ') === 0) {
        $new_notes = $current_notes; // Keep existing edit notes
    }
    
    $stmt = $conn->prepare("
        UPDATE savings_transactions 
        SET amount = :amount,
            notes = :notes
        WHERE id = :id
    ");
    $stmt->bindParam(':amount', $new_amount, PDO::PARAM_STR);
    $stmt->bindParam(':notes', $new_notes, PDO::PARAM_STR);
    $stmt->bindParam(':id', $deposit_id, PDO::PARAM_INT);
    $stmt->execute();
    
    // Calculate the balance adjustment based on transaction type
    $balance_adjustment = $amount_difference;
    
    // If it's a withdrawal, we need to reverse the logic
    if ($transaction_type === 'Withdrawal Cash') {
        // For withdrawals, increasing the amount means MORE money is withdrawn (so subtract more from balance)
        // Decreasing the amount means LESS money is withdrawn (so add back to balance)
        $balance_adjustment = -$amount_difference;
    }
    
    // Update the savings account balance with the calculated adjustment
    $stmt = $conn->prepare("
        UPDATE savings_accounts 
        SET current_balance = current_balance + :adjustment,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ");
    $stmt->bindParam(':adjustment', $balance_adjustment, PDO::PARAM_STR);
    $stmt->bindParam(':id', $savings_account_id, PDO::PARAM_INT);
    $stmt->execute();
    
    // Get the updated balance
    $stmt = $conn->prepare("SELECT current_balance FROM savings_accounts WHERE id = :id");
    $stmt->bindParam(':id', $savings_account_id, PDO::PARAM_INT);
    $stmt->execute();
    $updated = $stmt->fetch(PDO::FETCH_ASSOC);
    $new_savings_balance = floatval($updated['current_balance']);
    
    // ============================================
    // LOG THE ACTIVITY - ADDED HERE
    // ============================================
    $action = "Savings " . ($transaction_type === 'Withdrawal Cash' ? 'Withdrawal' : 'Deposit') . " Update";
    $details = "Updated savings transaction ID: $deposit_id (Account ID: $savings_account_id, Loan ID: $loan_id). " .
               "Customer: $customer_name. " .
               "Transaction type: $transaction_type. " .
               "Amount changed from ₱" . number_format($old_amount, 2) . " to ₱" . number_format($new_amount, 2) . ". " .
               "Difference: ₱" . number_format(abs($amount_difference), 2) . " (" . ($amount_difference > 0 ? 'Increase' : 'Decrease') . "). " .
               "New savings balance: ₱" . number_format($new_savings_balance, 2);
    
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
        'message' => 'Savings transaction updated successfully',
        'new_amount' => $new_amount,
        'transaction_type' => $transaction_type,
        'new_balance' => $new_savings_balance
    ]);
    
} catch (PDOException $e) {
    // Rollback transaction on error
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    // Rollback transaction on error
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => $e->getMessage()]);
}
?>