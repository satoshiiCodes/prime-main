<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized access', 'success' => false]);
    exit;
}

// Check if user has permission (Admin or Area Manager)
$is_admin = isset($_SESSION['account_type']) && $_SESSION['account_type'] === 'Admin';
$is_area_manager = isset($_SESSION['account_type']) && $_SESSION['account_type'] === 'Area Manager';

if (!$is_admin && !$is_area_manager) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Insufficient permissions', 'success' => false]);
    exit;
}

// Turn off error display
error_reporting(0);

// Database configuration
require '../includes/db_config.php';

// Set JSON header
header('Content-Type: application/json');

try {
    // Get raw POST data
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid JSON input');
    }
    
    // Validate required parameters
    if (!isset($input['loan_id']) || !isset($input['new_amount'])) {
        throw new Exception('Missing required parameters');
    }
    
    $loan_id = intval($input['loan_id']);
    $new_amount = floatval($input['new_amount']);
    
    if ($loan_id <= 0 || $new_amount <= 0) {
        throw new Exception('Invalid loan ID or amount');
    }
    
    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        throw new Exception("Database connection failed");
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Get current loan details from financing table
        $stmt = $conn->prepare("
            SELECT 
                amount_financed, 
                interest, 
                interest_peso,
                daily_payment,
                loan_term,
                penalty,
                (SELECT IFNULL(SUM(amount), 0) FROM payments WHERE loan_id = ?) as total_paid
            FROM financing 
            WHERE id = ?
        ");
        
        if (!$stmt) {
            throw new Exception("Database error");
        }
        
        $stmt->bind_param("ii", $loan_id, $loan_id);
        
        if (!$stmt->execute()) {
            throw new Exception("Database error");
        }
        
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception('Loan not found');
        }
        
        $loan = $result->fetch_assoc();
        $stmt->close();
        
        $old_amount = floatval($loan['amount_financed']);
        $interest_rate = floatval($loan['interest']);
        $loan_term = intval($loan['loan_term']);
        $penalty = floatval($loan['penalty']);
        $total_paid = floatval($loan['total_paid']);
        $old_interest_peso = floatval($loan['interest_peso']);
        $old_daily_payment = floatval($loan['daily_payment']);
        
        // Calculate new interest amount (15% of new amount)
        $new_interest_peso = $new_amount * ($interest_rate / 100);
        
        // Calculate new total amount (amount_financed + interest_peso + penalty)
        $new_total_amount = $new_amount + $new_interest_peso + $penalty;
        
        // Calculate remaining balance (new total amount - total paid)
        $new_remaining_balance = max(0, $new_total_amount - $total_paid);
        
        // Calculate new daily payment (based on original loan term)
        $new_daily_payment = $new_total_amount / $loan_term;
        
        // Update the financing table directly (NO NOTES FIELD)
        $update_stmt = $conn->prepare("
            UPDATE financing 
            SET 
                amount_financed = ?,
                interest_peso = ?,
                balance = ?,
                daily_payment = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        
        if (!$update_stmt) {
            throw new Exception("Database error");
        }
        
        $update_stmt->bind_param("dddii", $new_amount, $new_interest_peso, $new_remaining_balance, $new_daily_payment, $loan_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to update financing");
        }
        $update_stmt->close();
        
        // Commit transaction
        $conn->commit();
        
        // Send success response
        echo json_encode([
            'success' => true,
            'message' => 'Amount financed updated successfully',
            'new_amount' => $new_amount,
            'new_interest' => $new_interest_peso,
            'total_balance' => $new_total_amount,
            'daily_payment' => $new_daily_payment,
            'remaining_balance' => $new_remaining_balance
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
    
    $conn->close();
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>