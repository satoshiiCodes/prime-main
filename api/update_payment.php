<?php
session_start();

// Check if user is logged in and has proper privileges
if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['account_type'], ['Admin', 'Area Manager'])) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Check if required data is provided
if (!isset($_POST['id']) || !isset($_POST['amount'])) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'Missing required fields']);
    exit();
}

$payment_id = intval($_POST['id']);
$new_amount = floatval($_POST['amount']);
$user_id = $_SESSION['user_id']; // Assuming user_id is stored in session

// Validate amount
if ($new_amount <= 0) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'Amount must be greater than 0']);
    exit();
}

// Database configuration
require '../includes/db_config.php';

try {
    // Create connection
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Begin transaction
    $conn->beginTransaction();
    
    // 1. Get current payment details and loan information
    $stmt = $conn->prepare("
        SELECT p.amount as old_amount, p.loan_id, p.customer_id, p.payment_date, p.notes,
               (l.amount_financed + l.interest_peso + l.penalty) as total_amount,
               (SELECT IFNULL(SUM(amount), 0) FROM payments WHERE loan_id = p.loan_id) as total_paid
        FROM payments p
        JOIN financing l ON p.loan_id = l.id
        WHERE p.id = :id
    ");
    $stmt->bindParam(':id', $payment_id, PDO::PARAM_INT);
    $stmt->execute();
    $payment_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$payment_info) {
        throw new Exception("Payment not found");
    }
    
    $old_amount = floatval($payment_info['old_amount']);
    $loan_id = intval($payment_info['loan_id']);
    $customer_id = intval($payment_info['customer_id']);
    $payment_date = $payment_info['payment_date'];
    $current_notes = $payment_info['notes'] ?? '';
    $total_amount = floatval($payment_info['total_amount']);
    $current_total_paid = floatval($payment_info['total_paid']);
    
    // Check if this is a "Payment from Savings"
    $is_payment_from_savings = (strpos($current_notes, 'Payment from Savings') !== false || 
                               strpos($current_notes, 'payment from savings') !== false);
    
    $savings_account_id = null;
    $savings_transaction_id = null;
    $savings_withdrawal_amount = 0;
    $new_savings_balance = 0;
    
    // 2. If this is a payment from savings, find and update the corresponding savings transaction
    if ($is_payment_from_savings) {
        // Find the savings account for this loan
        $stmt = $conn->prepare("SELECT id, current_balance FROM savings_accounts WHERE loan_id = :loan_id AND customer_id = :customer_id");
        $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
        $stmt->bindParam(':customer_id', $customer_id, PDO::PARAM_INT);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $savings_account = $stmt->fetch(PDO::FETCH_ASSOC);
            $savings_account_id = $savings_account['id'];
            $current_savings_balance = floatval($savings_account['current_balance']);
            
            // Find the savings withdrawal transaction that corresponds to this payment
            $stmt = $conn->prepare("
                SELECT id, amount 
                FROM savings_transactions 
                WHERE savings_account_id = :savings_account_id 
                AND transaction_type LIKE '%Withdrawal%'
                AND DATE(transaction_date) = DATE(:payment_date)
                AND ABS(amount - :old_amount) < 0.01
                ORDER BY id DESC 
                LIMIT 1
            ");
            $stmt->bindParam(':savings_account_id', $savings_account_id, PDO::PARAM_INT);
            $stmt->bindParam(':payment_date', $payment_date);
            $stmt->bindParam(':old_amount', $old_amount);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $savings_transaction = $stmt->fetch(PDO::FETCH_ASSOC);
                $savings_transaction_id = $savings_transaction['id'];
                $savings_withdrawal_amount = floatval($savings_transaction['amount']);
                
                // Calculate the difference for savings adjustment
                $savings_amount_difference = $new_amount - $old_amount;
                
                // Update the savings transaction amount
                $new_savings_amount = $savings_withdrawal_amount + $savings_amount_difference;
                
                if ($new_savings_amount <= 0) {
                    throw new Exception("Savings withdrawal amount cannot be zero or negative");
                }
                
                $stmt = $conn->prepare("
                    UPDATE savings_transactions 
                    SET amount = :amount,
                        notes = CONCAT('Withdrawal - EDITED - ', COALESCE(notes, '')),
                        saving_balance_after_payment = saving_balance_after_payment + :difference
                    WHERE id = :id
                ");
                $stmt->bindParam(':amount', $new_savings_amount, PDO::PARAM_STR);
                $stmt->bindParam(':difference', $savings_amount_difference, PDO::PARAM_STR);
                $stmt->bindParam(':id', $savings_transaction_id, PDO::PARAM_INT);
                $stmt->execute();
                
                // Update the savings account balance by adjusting with the difference
                $stmt = $conn->prepare("
                    UPDATE savings_accounts 
                    SET current_balance = current_balance - :difference,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :savings_account_id
                ");
                $stmt->bindParam(':difference', $savings_amount_difference, PDO::PARAM_STR);
                $stmt->bindParam(':savings_account_id', $savings_account_id, PDO::PARAM_INT);
                $stmt->execute();
                
                // Get the updated savings balance
                $stmt = $conn->prepare("SELECT current_balance FROM savings_accounts WHERE id = :savings_account_id");
                $stmt->bindParam(':savings_account_id', $savings_account_id, PDO::PARAM_INT);
                $stmt->execute();
                $updated_savings = $stmt->fetch(PDO::FETCH_ASSOC);
                $new_savings_balance = floatval($updated_savings['current_balance']);
            } else {
                // If no matching savings transaction found, log it but don't fail
                error_log("No matching savings transaction found for payment ID: $payment_id");
            }
        }
    }
    
    // 3. Calculate the difference between new and old amount for loan balance
    $amount_difference = $new_amount - $old_amount;
    
    // 4. Update the payment amount and notes
    $new_notes = "Payment - EDITED - " . $current_notes;
    // Remove "Payment - " prefix if it exists to avoid duplication
    if (strpos($current_notes, 'Payment - ') === 0) {
        $base_notes = substr($current_notes, 10); // Remove "Payment - "
        $new_notes = "Payment - EDITED - " . $base_notes;
    }
    
    $stmt = $conn->prepare("UPDATE payments SET amount = :amount, notes = :notes WHERE id = :id");
    $stmt->bindParam(':amount', $new_amount, PDO::PARAM_STR);
    $stmt->bindParam(':notes', $new_notes, PDO::PARAM_STR);
    $stmt->bindParam(':id', $payment_id, PDO::PARAM_INT);
    $stmt->execute();
    
    // 5. Calculate new balance (total_amount - (current_total_paid + amount_difference))
    $new_balance = $total_amount - ($current_total_paid + $amount_difference);
    
    // Ensure balance doesn't go negative
    if ($new_balance < 0) {
        $new_balance = 0;
    }
    
    // 6. Update the balance in the financing table
    $stmt = $conn->prepare("UPDATE financing SET balance = :balance WHERE id = :loan_id");
    $stmt->bindParam(':balance', $new_balance, PDO::PARAM_STR);
    $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
    $stmt->execute();
    
    // 7. Log the activity
    $action = "Payment Update";
    $details = "Updated payment ID: $payment_id (Loan ID: $loan_id). Amount changed from " . number_format($old_amount, 2) . " to " . number_format($new_amount, 2);
    
    // Add savings transaction info if applicable
    if ($is_payment_from_savings && $savings_transaction_id) {
        $details .= " | Updated savings transaction ID: $savings_transaction_id. New savings balance: " . number_format($new_savings_balance, 2);
    }
    
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
    
    // Prepare success response
    $response = [
        'success' => true,
        'formatted_amount' => number_format($new_amount, 2),
        'new_balance' => number_format($new_balance, 2)
    ];
    
    // Add savings info to response if applicable
    if ($is_payment_from_savings) {
        $response['is_payment_from_savings'] = true;
        if ($savings_transaction_id) {
            $response['savings_transaction_updated'] = true;
            $response['savings_account_id'] = $savings_account_id;
            $response['new_savings_balance'] = number_format($new_savings_balance, 2);
        } else {
            $response['savings_transaction_updated'] = false;
            $response['message'] = 'Payment updated (Note: Corresponding savings transaction not found)';
        }
    }
    
    echo json_encode($response);
    
} catch(PDOException $e) {
    // Rollback transaction on error
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    exit();
} catch(Exception $e) {
    // Rollback transaction on error
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => $e->getMessage()]);
    exit();
}
?>