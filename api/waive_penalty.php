<?php
session_start();

// Check if user is logged in and has permission
if (!isset($_SESSION['logged_in']) || ($_SESSION['account_type'] !== 'Admin' && $_SESSION['account_type'] !== 'Area Manager')) {
    header('HTTP/1.1 401 Unauthorized');
    die(json_encode(['error' => 'Unauthorized access']));
}

// Check if the request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    die(json_encode(['error' => 'Invalid request method']));
}

// Get the input data
$input = json_decode(file_get_contents('php://input'), true);
$loan_id = isset($input['loan_id']) ? intval($input['loan_id']) : null;
$penalties = isset($input['penalties']) ? $input['penalties'] : null;
$reason = isset($input['reason']) ? trim($input['reason']) : '';
$additional_notes = isset($input['additional_notes']) ? trim($input['additional_notes']) : '';
$created_by = isset($input['created_by']) ? intval($input['created_by']) : ($_SESSION['user_id'] ?? 1);

// Validate input
if (!$loan_id || !$penalties || !is_array($penalties) || empty($penalties) || empty($reason)) {
    header('HTTP/1.1 400 Bad Request');
    die(json_encode(['error' => 'Invalid input data']));
}

// Database configuration
require '../includes/db_config.php';

try {
    // Create connection
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Begin transaction
    $conn->beginTransaction();
    
    // 1. Get the current loan details with FOR UPDATE lock
    $stmt = $conn->prepare("
        SELECT 
            l.*,
            c.area as customer_area
        FROM financing l 
        LEFT JOIN customer_accounts c ON l.customer_id = c.id 
        WHERE l.id = :loan_id 
        FOR UPDATE
    ");
    $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
    $stmt->execute();
    $loan = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$loan) {
        throw new Exception("Loan not found");
    }
    
    // Get customer_id for savings_accounts check
    $customer_id = $loan['customer_id'] ?? null;
    
    // Get current values from financing table
    $current_penalty = floatval($loan['penalty'] ?? 0);
    $current_balance = floatval($loan['balance'] ?? 0);
    
    $total_waived = 0;
    $processed_count = 0;
    $penalty_details = [];
    
    // 2. Process each penalty
    foreach ($penalties as $penalty_data) {
        $penalty_id = intval($penalty_data['penalty_id']);
        $waive_amount = floatval($penalty_data['amount']);
        $current_amount = floatval($penalty_data['current_amount']);
        
        if ($waive_amount <= 0 || $waive_amount > $current_amount) {
            throw new Exception("Invalid waive amount for penalty ID: $penalty_id");
        }
        
        // Get the penalty details
        $stmt = $conn->prepare("SELECT * FROM penalties WHERE id = :penalty_id AND loan_id = :loan_id");
        $stmt->bindParam(':penalty_id', $penalty_id, PDO::PARAM_INT);
        $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
        $stmt->execute();
        $penalty = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$penalty) {
            throw new Exception("Penalty not found: ID $penalty_id");
        }
        
        $penalty_amount = floatval($penalty['amount']);
        
        if ($waive_amount > $penalty_amount) {
            throw new Exception("Waive amount exceeds penalty amount for penalty ID: $penalty_id");
        }
        
        // Calculate new penalty amount after waiver
        $new_penalty_amount = $penalty_amount - $waive_amount;
        
        // Determine penalty type based on waiver amount
        if ($new_penalty_amount == 0) {
            $penalty_type = 'waived';
        } elseif ($waive_amount > 0 && $new_penalty_amount > 0) {
            $penalty_type = 'partial waiver';
        } else {
            $penalty_type = 'added';
        }
        
        // Update the penalty amount and type
        $new_notes = $penalty['notes'] ? $penalty['notes'] . " | Waived: ₱" . number_format($waive_amount, 2) . " - " . $reason : "Waived: ₱" . number_format($waive_amount, 2) . " - " . $reason;
        
        $stmt = $conn->prepare("UPDATE penalties SET amount = :new_amount, type = :penalty_type, notes = :notes, updated_at = NOW() WHERE id = :penalty_id");
        $stmt->bindParam(':new_amount', $new_penalty_amount);
        $stmt->bindParam(':penalty_type', $penalty_type);
        $stmt->bindParam(':notes', $new_notes);
        $stmt->bindParam(':penalty_id', $penalty_id, PDO::PARAM_INT);
        $stmt->execute();
        
        // 3. Create penalty waiver log
        $stmt = $conn->prepare("
            INSERT INTO penalty_waiver_logs 
            (loan_id, penalty_id, original_amount, waived_amount, remaining_amount, reason, additional_notes, created_by, created_at) 
            VALUES (:loan_id, :penalty_id, :original_amount, :waived_amount, :remaining_amount, :reason, :additional_notes, :created_by, NOW())
        ");
        $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
        $stmt->bindParam(':penalty_id', $penalty_id, PDO::PARAM_INT);
        $stmt->bindParam(':original_amount', $penalty_amount);
        $stmt->bindParam(':waived_amount', $waive_amount);
        $stmt->bindParam(':remaining_amount', $new_penalty_amount);
        $stmt->bindParam(':reason', $reason);
        $stmt->bindParam(':additional_notes', $additional_notes);
        $stmt->bindParam(':created_by', $created_by, PDO::PARAM_INT);
        $stmt->execute();
        
        $waiver_log_id = $conn->lastInsertId();
        
        // Store penalty details for summary
        $penalty_details[] = [
            'penalty_id' => $penalty_id,
            'original_amount' => $penalty_amount,
            'waived_amount' => $waive_amount,
            'remaining_amount' => $new_penalty_amount,
            'penalty_type' => $penalty_type,
            'waiver_log_id' => $waiver_log_id
        ];
        
        $total_waived += $waive_amount;
        $processed_count++;
    }
    
    // 4. Update financing table - reduce penalty and balance
    $new_penalty = $current_penalty - $total_waived;
    $new_balance = $current_balance - $total_waived;
    
    // Ensure we don't go negative
    if ($new_penalty < 0) $new_penalty = 0;
    if ($new_balance < 0) $new_balance = 0;
    
    // Update both penalty and balance fields in financing table
    $stmt = $conn->prepare("UPDATE financing SET penalty = :new_penalty, balance = :new_balance, updated_at = NOW() WHERE id = :loan_id");
    $stmt->bindParam(':new_penalty', $new_penalty);
    $stmt->bindParam(':new_balance', $new_balance);
    $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
    $stmt->execute();
    
    // 5. Check if loan should be marked as completed
    $status_updated = false;
    
    // Check if balance in financing table is 0
    if ($new_balance == 0) {
        // Get current_balance from savings_accounts table for this customer
        $stmt = $conn->prepare("SELECT current_balance FROM savings_accounts WHERE customer_id = :customer_id");
        $stmt->bindParam(':customer_id', $customer_id, PDO::PARAM_INT);
        $stmt->execute();
        $savings_account = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // If savings account exists and current_balance is 0, update status to 'completed'
        if ($savings_account && floatval($savings_account['current_balance']) == 0) {
            $stmt = $conn->prepare("UPDATE financing SET status = 'completed', updated_at = NOW() WHERE id = :loan_id");
            $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
            $stmt->execute();
            $status_updated = true;
        }
    }
    
    // Commit transaction
    $conn->commit();
    
    // Return success response
    $response = [
        'success' => true,
        'message' => 'Penalties waived successfully',
        'total_waived' => number_format($total_waived, 2),
        'processed_count' => $processed_count,
        'old_penalty' => number_format($current_penalty, 2),
        'new_penalty' => number_format($new_penalty, 2),
        'old_balance' => number_format($current_balance, 2),
        'new_balance' => number_format($new_balance, 2),
        'balance_reduction' => number_format($total_waived, 2),
        'penalty_details' => $penalty_details,
        'reason' => $reason,
        'status_updated' => $status_updated,
        'new_status' => $status_updated ? 'completed' : $loan['status']
    ];
    
    header('Content-Type: application/json');
    echo json_encode($response);
    
} catch(PDOException $e) {
    // Rollback transaction on error
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    header('HTTP/1.1 500 Internal Server Error');
    die(json_encode(['error' => 'Database error: ' . $e->getMessage()]));
} catch(Exception $e) {
    // Rollback transaction on error
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    header('HTTP/1.1 400 Bad Request');
    die(json_encode(['error' => $e->getMessage()]));
}
?>