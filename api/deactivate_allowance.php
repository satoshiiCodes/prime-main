<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../includes/db_config.php';

$allowance_id = isset($_POST['allowance_id']) ? (int)$_POST['allowance_id'] : 0;

if (!$allowance_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid allowance ID']);
    exit;
}

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    $stmt = $conn->prepare("UPDATE employee_allowances SET is_active = 0 WHERE id = ?");
    $stmt->bind_param("i", $allowance_id);
    
    if ($stmt->execute()) {
        $affected = $stmt->affected_rows;
        $stmt->close();
        $conn->close();
        if ($affected > 0) {
            echo json_encode(['success' => true, 'message' => 'Allowance deactivated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Allowance not found or already inactive']);
        }
    } else {
        throw new Exception($stmt->error);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>