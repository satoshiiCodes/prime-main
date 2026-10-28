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
    
    // Get current date for calculating active deductions
    $current_date = date('Y-m-d');
    
    // Get all active deductions with their payment totals
    $sql = "SELECT d.*, 
                   COALESCE(SUM(dp.amount), 0) as total_paid
            FROM employee_deductions d
            LEFT JOIN deduction_payments dp ON d.id = dp.deduction_id
            WHERE d.employee_id = ? AND d.employee_type = ? AND d.is_active = 1
            GROUP BY d.id";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $employee_id, $employee_type);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $summary = [
        'loan_payment' => 0,
        'cash_advance' => 0,
        'sss' => 0,
        'pagibig' => 0,
        'philhealth' => 0,
        'sss_loan' => 0,
        'pagibig_loan' => 0,
        'total' => 0
    ];
    
    while ($row = $result->fetch_assoc()) {
        $deduction_type = $row['deduction_type'];
        $amount = floatval($row['amount']);
        $total_paid = floatval($row['total_paid']);
        
        // For loans and cash advances, calculate remaining balance
        if (in_array($deduction_type, ['loan_payment', 'cash_advance', 'sss_loan', 'pagibig_loan'])) {
            $remaining = $amount - $total_paid;
            if ($remaining > 0) {
                $summary[$deduction_type] += $remaining;
            }
        } else {
            // For government contributions, they are active if within effective date
            // For simplicity, we'll add the full amount as current deduction
            $summary[$deduction_type] += $amount;
        }
    }
    
    // Calculate total
    $summary['total'] = array_sum($summary);
    
    echo json_encode([
        'success' => true,
        'summary' => $summary
    ]);
    
    $stmt->close();
    $conn->close();
    
} catch (Exception $e) {
    error_log("Error in get_deduction_summary.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>