<?php
session_start();
header('Content-Type: application/json');

// 1. Authentication check
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized - Please login first']);
    exit();
}

// 2. Input validation - get data from POST instead of GET
$input = json_decode(file_get_contents('php://input'), true);
if (!isset($input['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Bad Request - Missing loan ID']);
    exit();
}

$loan_id = filter_var($input['id'], FILTER_VALIDATE_INT);
if ($loan_id === false || $loan_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid loan ID']);
    exit();
}

// 3. Database configuration - use the same variable names as your main file
require '../includes/db_config.php'; // Make sure this path is correct

try {
    // Use the same connection variables as your main file
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->beginTransaction();

    // 4. Check if loan exists and get loan details for logging
    $stmt = $conn->prepare("SELECT 
        id, customer_id, collector_id, amount_financed, interest_peso,
        loan_term, start_date, end_date, status 
        FROM financing WHERE id = :loan_id");
    $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Loan not found']);
        exit();
    }

    $loan_details = $stmt->fetch(PDO::FETCH_ASSOC);

    // 5. Delete from savings_accounts first (to handle FK constraint)
    $stmt = $conn->prepare("DELETE FROM savings_accounts WHERE loan_id = :loan_id");
    $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
    $stmt->execute();

    // 6. Delete associated payments
    $stmt = $conn->prepare("DELETE FROM payments WHERE loan_id = :loan_id");
    $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
    $stmt->execute();

    // 7. Delete any penalties
    $stmt = $conn->prepare("DELETE FROM penalties WHERE loan_id = :loan_id");
    $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
    $stmt->execute();

    // 8. Finally delete the loan
    $stmt = $conn->prepare("DELETE FROM financing WHERE id = :loan_id");
    $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
    $stmt->execute();

    // Activity logging for loan deletion with full details
    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
        $ip_address = $_SERVER['REMOTE_ADDR'];
        $action = "Deleted loan record";
        
        $details = sprintf(
            "Deleted loan ID: %d | Customer ID: %d | Collector ID: %d | ".
            "Amount Financed: %.2f | Interest: %.2f | Term: %d days | ".
            "Dates: %s to %s | Status: %s",
            $loan_id,
            $loan_details['customer_id'],
            $loan_details['collector_id'],
            $loan_details['amount_financed'],
            $loan_details['interest_peso'],
            $loan_details['loan_term'],
            $loan_details['start_date'],
            $loan_details['end_date'],
            $loan_details['status']
        );

        $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (:user_id, :action, :details, :ip_address)");
        $log_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $log_stmt->bindParam(':action', $action, PDO::PARAM_STR);
        $log_stmt->bindParam(':details', $details, PDO::PARAM_STR);
        $log_stmt->bindParam(':ip_address', $ip_address, PDO::PARAM_STR);
        $log_stmt->execute();
    }

    $conn->commit();

    // 9. Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Loan and all associated records deleted successfully'
    ]);

} catch (PDOException $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    error_log("Delete Loan Error: " . $e->getMessage());
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    error_log("Delete Loan Error: " . $e->getMessage());
}