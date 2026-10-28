<?php
session_start();
header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../includes/db_config.php';

$employee_id = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
$employee_type = isset($_GET['employee_type']) ? $_GET['employee_type'] : '';

if (!$employee_id || !in_array($employee_type, ['collector', 'proxy', 'accounts'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    $stmt = $conn->prepare("SELECT id, allowance_type, amount, effective_date, end_date, notes 
                            FROM employee_allowances 
                            WHERE employee_id = ? AND employee_type = ? AND is_active = 1 
                            ORDER BY effective_date DESC");
    $stmt->bind_param("is", $employee_id, $employee_type);
    $stmt->execute();
    $result = $stmt->get_result();

    $allowances = [];
    while ($row = $result->fetch_assoc()) {
        $allowances[] = $row;
    }

    $stmt->close();
    $conn->close();

    echo json_encode(['success' => true, 'allowances' => $allowances]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>