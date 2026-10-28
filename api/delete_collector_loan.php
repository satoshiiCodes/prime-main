<?php
session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['logged_in'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Database configuration
require_once '../includes/db_config1.php';

try {
    $conn = getDBConnection();
    
    // Check if user is admin
    $stmt = $conn->prepare("SELECT account_type FROM accounts WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Also check collector_accounts and proxy_accounts
    $is_admin = false;
    if ($user && $user['account_type'] === 'Admin') {
        $is_admin = true;
    } else {
        // Check other account types
        $stmt = $conn->prepare("SELECT account_type FROM collector_accounts WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $collector = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $conn->prepare("SELECT account_type FROM proxy_accounts WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $proxy = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (($user && $user['account_type'] === 'Admin') || 
            ($collector && $collector['account_type'] === 'Admin') || 
            ($proxy && $proxy['account_type'] === 'Admin')) {
            $is_admin = true;
        }
    }
    
    if (!$is_admin) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
        exit;
    }
    
    // Get loan_id from POST data
    $loan_id = isset($_POST['loan_id']) ? intval($_POST['loan_id']) : 0;
    
    if ($loan_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid loan ID']);
        exit;
    }
    
    // Start transaction
    $conn->beginTransaction();
    
    try {
        // Delete associated payments first
        $stmt = $conn->prepare("DELETE FROM collector_payments WHERE loan_id = ?");
        $stmt->execute([$loan_id]);
        
        // Delete the loan
        $stmt = $conn->prepare("DELETE FROM collector_loans WHERE id = ?");
        $stmt->execute([$loan_id]);
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode(['success' => true, 'message' => 'Loan and associated payments deleted successfully']);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Error deleting collector loan: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred while deleting the loan: ' . $e->getMessage()]);
}
?>