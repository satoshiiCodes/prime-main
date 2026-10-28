<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require '../includes/db_config.php';

header('Content-Type: application/json');

$employee_id = $_GET['employee_id'] ?? 0;
$employee_type = $_GET['employee_type'] ?? '';

if (!$employee_id || !$employee_type) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

if (!in_array($employee_type, ['collector', 'proxy', 'accounts'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid employee type']);
    exit;
}

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    
    // Get active deductions
    $sql = "SELECT * FROM employee_deductions 
            WHERE employee_id = ? AND employee_type = ? AND is_active = 1 
            ORDER BY 
                CASE deduction_type
                    WHEN 'loan_payment' THEN 1
                    WHEN 'cash_advance' THEN 2
                    WHEN 'sss_loan' THEN 3
                    WHEN 'pagibig_loan' THEN 4
                    WHEN 'sss' THEN 5
                    WHEN 'pagibig' THEN 6
                    WHEN 'philhealth' THEN 7
                END,
                created_at DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $employee_id, $employee_type);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $deductions = [];
    while ($row = $result->fetch_assoc()) {
        // For loan and cash advance, get payment summary
        if (in_array($row['deduction_type'], ['loan_payment', 'cash_advance', 'sss_loan', 'pagibig_loan'])) {
            // Get total payments made
            $payment_sql = "SELECT SUM(amount) as total_paid, COUNT(*) as payment_count 
                           FROM deduction_payments 
                           WHERE deduction_id = ?";
            $payment_stmt = $conn->prepare($payment_sql);
            $payment_stmt->bind_param("i", $row['id']);
            $payment_stmt->execute();
            $payment_result = $payment_stmt->get_result();
            $payment_data = $payment_result->fetch_assoc();
            
            $row['total_paid'] = $payment_data['total_paid'] ?? 0;
            $row['payment_count'] = $payment_data['payment_count'] ?? 0;
            $row['remaining_balance'] = $row['amount'] - ($payment_data['total_paid'] ?? 0);
            $payment_stmt->close();
        }
        
        // Format dates
        if ($row['effective_date']) {
            $row['effective_date'] = date('M d, Y', strtotime($row['effective_date']));
        }
        if ($row['start_date']) {
            $row['start_date'] = date('M d, Y', strtotime($row['start_date']));
        }
        if ($row['end_date']) {
            $row['end_date'] = date('M d, Y', strtotime($row['end_date']));
        }
        if ($row['created_at']) {
            $row['created_at'] = date('M d, Y', strtotime($row['created_at']));
        }
        
        $deductions[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'deductions' => $deductions
    ]);
    
    $stmt->close();
    $conn->close();
    
} catch (Exception $e) {
    error_log("Error in get_active_deductions.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>