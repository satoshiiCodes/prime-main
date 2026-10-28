<?php
session_start();
header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../includes/db_config.php';

$employee_id = isset($_POST['employee_id']) ? (int)$_POST['employee_id'] : 0;
$employee_type = isset($_POST['employee_type']) ? $_POST['employee_type'] : '';
$allowance_type = isset($_POST['allowance_type']) ? trim($_POST['allowance_type']) : '';
$amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
$effective_date = isset($_POST['effective_date']) ? $_POST['effective_date'] : '';
$end_date = isset($_POST['end_date']) && !empty($_POST['end_date']) ? $_POST['end_date'] : null;
$notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
$created_by = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

// Validate
if (!$employee_id || !in_array($employee_type, ['collector', 'proxy', 'accounts']) || !$allowance_type || $amount <= 0 || !$effective_date) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

$valid_types = ['Rice Allowance', 'COLA', 'Motor Rental', 'Incentive'];
if (!in_array($allowance_type, $valid_types)) {
    echo json_encode(['success' => false, 'message' => 'Invalid allowance type']);
    exit;
}

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    // Insert new allowance (frequency is always 'monthly')
    $stmt = $conn->prepare("INSERT INTO employee_allowances 
                            (employee_id, employee_type, allowance_type, amount, frequency, effective_date, end_date, is_active, notes, created_by) 
                            VALUES (?, ?, ?, ?, 'monthly', ?, ?, 1, ?, ?)");
    $stmt->bind_param("issdsssi", $employee_id, $employee_type, $allowance_type, $amount, $effective_date, $end_date, $notes, $created_by);
    
    if ($stmt->execute()) {
        $new_id = $stmt->insert_id;
        $stmt->close();
        $conn->close();
        echo json_encode(['success' => true, 'message' => 'Allowance added successfully', 'id' => $new_id]);
    } else {
        throw new Exception($stmt->error);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>