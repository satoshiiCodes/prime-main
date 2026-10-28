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

// Validate
if (!$employee_id || !in_array($employee_type, ['collector', 'proxy', 'accounts'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    // Get all allowances (both active and inactive) for this employee
    $stmt = $conn->prepare("SELECT 
                                ea.id,
                                ea.allowance_type,
                                ea.amount,
                                ea.frequency,
                                ea.effective_date,
                                ea.end_date,
                                ea.is_active,
                                ea.notes,
                                ea.created_at,
                                a.first_name,
                                a.last_name
                            FROM employee_allowances ea
                            LEFT JOIN accounts a ON ea.created_by = a.id
                            WHERE ea.employee_id = ? AND ea.employee_type = ?
                            ORDER BY ea.effective_date DESC, ea.id DESC");
    
    $stmt->bind_param("is", $employee_id, $employee_type);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $allowances = [];
    while ($row = $result->fetch_assoc()) {
        $allowances[] = $row;
    }
    
    $stmt->close();
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'allowances' => $allowances
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>