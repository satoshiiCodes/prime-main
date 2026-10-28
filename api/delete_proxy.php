<?php
session_start();
require '../includes/db_config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!isset($_POST['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID parameter is required']);
    exit;
}

$proxy_id = intval($_POST['id']);

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    // Check if proxy exists
    $check_stmt = $conn->prepare("SELECT id, first_name, last_name FROM proxy_accounts WHERE id = ?");
    $check_stmt->bind_param("i", $proxy_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Proxy account not found']);
        exit;
    }

    $proxy = $result->fetch_assoc();
    $check_stmt->close();

    // Delete the proxy account
    $delete_stmt = $conn->prepare("DELETE FROM proxy_accounts WHERE id = ?");
    $delete_stmt->bind_param("i", $proxy_id);
    
    if ($delete_stmt->execute()) {
        echo json_encode([
            'success' => true, 
            'message' => 'Proxy account for ' . $proxy['first_name'] . ' ' . $proxy['last_name'] . ' has been deleted successfully'
        ]);
    } else {
        throw new Exception("Error deleting proxy account: " . $delete_stmt->error);
    }

    $delete_stmt->close();
    $conn->close();

} catch (Exception $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error deleting proxy account: ' . $e->getMessage()]);
}
?>