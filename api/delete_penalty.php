<?php
// Turn off error reporting to prevent HTML output
error_reporting(0);
ini_set('display_errors', 0);

session_start();
require '../includes/db_config.php';

// Set JSON header first
header('Content-Type: application/json');

// Check if user is logged in and is admin
if (!isset($_SESSION['logged_in']) || $_SESSION['account_type'] !== 'Admin') {
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $penalty_id = $_POST['id'] ?? '';
    
    if (empty($penalty_id)) {
        echo json_encode(['error' => 'Penalty ID is required']);
        exit();
    }
    
    $conn = null;
    try {
        $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Start transaction
        $conn->beginTransaction();
        
        // First get the penalty details including loan_id
        $stmt = $conn->prepare("SELECT amount, loan_id FROM penalties WHERE id = :id");
        $stmt->bindParam(':id', $penalty_id);
        $stmt->execute();
        $penalty = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$penalty) {
            throw new Exception("Penalty not found");
        }
        
        $penalty_amount = floatval($penalty['amount']);
        $loan_id = $penalty['loan_id'];
        
        // Delete the penalty
        $stmt = $conn->prepare("DELETE FROM penalties WHERE id = :id");
        $stmt->bindParam(':id', $penalty_id);
        $stmt->execute();
        
        // Update loan balance - subtract the penalty amount from the balance
        // Since we're removing a penalty, we reduce the loan balance
        $stmt = $conn->prepare("UPDATE financing SET balance = balance - :amount WHERE id = :loan_id");
        $stmt->bindParam(':amount', $penalty_amount);
        $stmt->bindParam(':loan_id', $loan_id);
        $stmt->execute();
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode(['success' => true, 'message' => 'Penalty deleted successfully']);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        if ($conn && $conn->inTransaction()) {
            $conn->rollBack();
        }
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['error' => 'Invalid request method']);
}
?>