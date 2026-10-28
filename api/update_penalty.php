<?php
// Turn off error reporting to prevent HTML output
error_reporting(0);
ini_set('display_errors', 0);

session_start();
require '../includes/db_config.php';

// Set JSON header first
header('Content-Type: application/json');

// Check if user is logged in and has permission
if (!isset($_SESSION['logged_in']) || ($_SESSION['account_type'] !== 'Admin')) {
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $penalty_id = $_POST['id'] ?? '';
    $new_amount = $_POST['amount'] ?? '';
    
    if (empty($penalty_id) || empty($new_amount)) {
        echo json_encode(['error' => 'Penalty ID and amount are required']);
        exit();
    }
    
    $new_amount = floatval($new_amount);
    
    if ($new_amount <= 0) {
        echo json_encode(['error' => 'Amount must be greater than 0']);
        exit();
    }
    
    $conn = null;
    try {
        $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Start transaction
        $conn->beginTransaction();
        
        // First get the current penalty details
        $stmt = $conn->prepare("SELECT amount, loan_id FROM penalties WHERE id = :id");
        $stmt->bindParam(':id', $penalty_id);
        $stmt->execute();
        $penalty = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$penalty) {
            throw new Exception("Penalty not found");
        }
        
        $old_amount = floatval($penalty['amount']);
        $loan_id = $penalty['loan_id'];
        $amount_difference = $new_amount - $old_amount;
        
        // Update the penalty amount
        $stmt = $conn->prepare("UPDATE penalties SET amount = :amount WHERE id = :id");
        $stmt->bindParam(':amount', $new_amount);
        $stmt->bindParam(':id', $penalty_id);
        $stmt->execute();
        
        // Update loan balance by the amount difference
        $stmt = $conn->prepare("UPDATE financing SET balance = balance + :difference WHERE id = :loan_id");
        $stmt->bindParam(':difference', $amount_difference);
        $stmt->bindParam(':loan_id', $loan_id);
        $stmt->execute();
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode(['success' => true, 'message' => 'Penalty amount updated successfully']);
        
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