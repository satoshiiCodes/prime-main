<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require '../includes/db_config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$deduction_id = $_POST['deduction_id'] ?? 0;

if (!$deduction_id) {
    echo json_encode(['success' => false, 'message' => 'Missing deduction ID']);
    exit;
}

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    
    // Check if the deduction exists
    $check_sql = "SELECT * FROM employee_deductions WHERE id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $deduction_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows === 0) {
        throw new Exception("Deduction not found");
    }
    
    $deduction = $check_result->fetch_assoc();
    
    // Check if already inactive
    if ($deduction['is_active'] == 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Deduction is already inactive'
        ]);
        $check_stmt->close();
        $conn->close();
        exit;
    }
    
    // If it's a loan or cash advance, check if it's fully paid
    if (in_array($deduction['deduction_type'], ['loan_payment', 'cash_advance', 'sss_loan', 'pagibig_loan'])) {
        $payment_sql = "SELECT SUM(amount) as total_paid FROM deduction_payments WHERE deduction_id = ?";
        $payment_stmt = $conn->prepare($payment_sql);
        $payment_stmt->bind_param("i", $deduction_id);
        $payment_stmt->execute();
        $payment_result = $payment_stmt->get_result();
        $payment_data = $payment_result->fetch_assoc();
        $total_paid = $payment_data['total_paid'] ?? 0;
        
        if ($total_paid < $deduction['amount']) {
            $remaining = $deduction['amount'] - $total_paid;
            error_log("Warning: Deactivating loan/cash advance with remaining balance. ID: $deduction_id, Remaining: " . $remaining);
            
            // You can choose to allow deactivation with a warning, or prevent it
            // For now, we'll allow it but log the warning
        }
        $payment_stmt->close();
    }
    
    // Deactivate the deduction
    $sql = "UPDATE employee_deductions SET is_active = 0 WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $deduction_id);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Deduction deactivated successfully'
        ]);
    } else {
        throw new Exception("Failed to deactivate deduction: " . $stmt->error);
    }
    
    $stmt->close();
    $check_stmt->close();
    $conn->close();
    
} catch (Exception $e) {
    error_log("Error in deactivate_deduction.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>