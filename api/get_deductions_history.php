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
    
    // Get all deductions with payment information
    $sql = "SELECT d.*, 
                   GROUP_CONCAT(
                       CONCAT(dp.payment_date, ':', dp.amount, ':', dp.payment_period)
                       SEPARATOR '|'
                   ) as payments
            FROM employee_deductions d
            LEFT JOIN deduction_payments dp ON d.id = dp.deduction_id
            WHERE d.employee_id = ? AND d.employee_type = ?
            GROUP BY d.id
            ORDER BY d.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $employee_id, $employee_type);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $deductions = [];
    while ($row = $result->fetch_assoc()) {
        // Parse payments
        $payments = [];
        if (!empty($row['payments'])) {
            $payment_parts = explode('|', $row['payments']);
            foreach ($payment_parts as $part) {
                list($payment_date, $amount, $period) = explode(':', $part);
                $payments[] = [
                    'payment_date' => date('M d, Y', strtotime($payment_date)),
                    'amount' => floatval($amount),
                    'period' => $period
                ];
            }
        }
        
        // Calculate total paid
        $total_paid = array_sum(array_column($payments, 'amount'));
        
        // Format dates
        $deduction = [
            'id' => $row['id'],
            'deduction_type' => $row['deduction_type'],
            'amount' => floatval($row['amount']),
            'frequency' => $row['frequency'],
            'effective_date' => $row['effective_date'] ? date('M d, Y', strtotime($row['effective_date'])) : null,
            'start_date' => $row['start_date'] ? date('M d, Y', strtotime($row['start_date'])) : null,
            'end_date' => $row['end_date'] ? date('M d, Y', strtotime($row['end_date'])) : null,
            'is_active' => (bool)$row['is_active'],
            'notes' => $row['notes'],
            'created_at' => date('M d, Y h:i A', strtotime($row['created_at'])),
            'total_paid' => $total_paid,
            'remaining_balance' => $row['amount'] - $total_paid,
            'payments' => $payments
        ];
        
        $deductions[] = $deduction;
    }
    
    echo json_encode([
        'success' => true,
        'deductions' => $deductions
    ]);
    
    $stmt->close();
    $conn->close();
    
} catch (Exception $e) {
    error_log("Error in get_deductions_history.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>