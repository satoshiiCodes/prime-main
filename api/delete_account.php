<?php
session_start();

if (!isset($_SESSION['logged_in'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please login first.']);
    exit;
}

require '../includes/db_config.php';

header('Content-Type: application/json');

if (isset($_GET['id'])) {
    $account_id = intval($_GET['id']);
    
    try {
        $conn = new mysqli($servername, $username, $password, $dbname);
        
        if ($conn->connect_error) {
            throw new Exception("Connection failed: " . $conn->connect_error);
        }

        // Prevent deleting your own account
        if ($account_id == $_SESSION['user_id']) {
            echo json_encode(['success' => false, 'message' => 'You cannot delete your own account.']);
            exit;
        }

        // Check if account exists before deleting
        $check_stmt = $conn->prepare("SELECT first_name, last_name FROM accounts WHERE id = ?");
        $check_stmt->bind_param("i", $account_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'Account not found.']);
            $check_stmt->close();
            $conn->close();
            exit;
        }
        
        $account_data = $check_result->fetch_assoc();
        $account_name = $account_data['first_name'] . ' ' . $account_data['last_name'];
        $check_stmt->close();

        // Prepare and execute delete statement
        $stmt = $conn->prepare("DELETE FROM accounts WHERE id = ?");
        $stmt->bind_param("i", $account_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => "Account for {$account_name} has been deleted successfully!"]);
        } else {
            throw new Exception("Error deleting account: " . $stmt->error);
        }
        
        $stmt->close();
        $conn->close();
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'No account ID specified for deletion.']);
}
?>