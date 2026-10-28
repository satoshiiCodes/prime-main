<?php
session_start();

// Check if user is logged in and has appropriate permissions
if (!isset($_SESSION['logged_in']) || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Check if user is admin or area manager
require '../includes/db_config.php';

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $conn->prepare("SELECT account_type FROM accounts WHERE id = :id");
    $stmt->bindParam(':id', $_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user || !in_array($user['account_type'], ['Admin', 'Area Manager'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Insufficient permissions']);
        exit();
    }
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    exit();
}

// Get the JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['loan_id']) || !isset($input['enable'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required parameters']);
    exit();
}

$loan_id = intval($input['loan_id']);
$enable = boolval($input['enable']);

try {
    // Get customer branch for logging
    $stmt = $conn->prepare("
        SELECT c.branch 
        FROM financing f 
        JOIN customer_accounts c ON f.customer_id = c.id 
        WHERE f.id = :loan_id
    ");
    $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
    $stmt->execute();
    $loan_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $customer_branch = $loan_data['branch'] ?? null;
    
    // Update the automatic penalty setting
    $stmt = $conn->prepare("UPDATE financing SET automatic_penalty_enabled = :enabled WHERE id = :loan_id");
    $stmt->bindParam(':enabled', $enable, PDO::PARAM_BOOL);
    $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
    $stmt->execute();
    
    // Log the action
    $action = $enable ? 'enabled' : 'disabled';
    $notes = "Automatic penalty calculation {$action} by user ID: {$_SESSION['user_id']}";
    
    $stmt = $conn->prepare("INSERT INTO penalty_logs (loan_id, branch, action, notes, created_by) VALUES (:loan_id, :branch, :action, :notes, :created_by)");
    $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
    $stmt->bindParam(':branch', $customer_branch);
    $stmt->bindParam(':action', $action);
    $stmt->bindParam(':notes', $notes);
    $stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->execute();
    
    echo json_encode([
        'success' => true,
        'message' => "Automatic penalty calculation has been {$action} successfully!"
    ]);
    
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>