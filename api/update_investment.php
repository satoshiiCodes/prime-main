<?php
session_start();

// Redirect to login if not authenticated
if (!isset($_SESSION['logged_in'])) {
    header("Location: ../index.php");
    exit;
}

// Database configuration
require '../includes/db_config.php';

// Initialize variables
$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate and sanitize input
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $date = isset($_POST['date']) ? trim($_POST['date']) : '';
    $amount = isset($_POST['amount']) ? str_replace(',', '', trim($_POST['amount'])) : '';
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
    $type = isset($_POST['type']) ? trim($_POST['type']) : '';
    $branch = isset($_POST['branch']) ? trim($_POST['branch']) : '';
    
    // Basic validation
    if (empty($date) || empty($amount) || empty($type) || empty($branch)) {
        $_SESSION['error'] = "Date, Amount, Type, and Branch are required fields.";
    } elseif (!is_numeric($amount) || $amount <= 0) {
        $_SESSION['error'] = "Amount must be a positive number.";
    } elseif (!in_array($type, ['Beginning Balance', 'Deposited', 'Withdraw', 'Other Income'])) {
        $_SESSION['error'] = "Invalid investment type selected.";
    } elseif (!in_array($branch, ['Tuguegarao City', 'Gattaran', 'Abulug'])) {
        $_SESSION['error'] = "Invalid branch selected.";
    } else {
        try {
            $conn = new mysqli($servername, $username, $password, $dbname);
            
            if ($conn->connect_error) {
                throw new Exception("Connection failed: " . $conn->connect_error);
            }
            
            // Format notes based on investment type
            $formatted_notes = '';
            if ($type === 'Beginning Balance') {
                $formatted_notes = 'Beginning Balance - ' . $notes;
            } elseif ($type === 'Deposited') {
                $formatted_notes = 'Deposited - ' . $notes;
            } elseif ($type === 'Withdraw') {
                $formatted_notes = 'Withdraw - ' . $notes;
            } elseif ($type === 'Other Income') {
                $formatted_notes = 'Other Income - ' . $notes;
            }
            
            // Convert date to MySQL format
            $date_obj = DateTime::createFromFormat('m-d-Y h:i:s A', $date);
            if (!$date_obj) {
                throw new Exception("Invalid date format");
            }
            $mysql_date = $date_obj->format('Y-m-d H:i:s');
            
            // Update investment in database
            $stmt = $conn->prepare("UPDATE investments SET branch = ?, date = ?, amount = ?, notes = ?, type = ? WHERE id = ? AND account_id = ?");
            $stmt->bind_param("ssdssii", $branch, $mysql_date, $amount, $formatted_notes, $type, $id, $_SESSION['user_id']);

            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    $_SESSION['success'] = "Investment updated successfully!";
                } else {
                    $_SESSION['error'] = "No changes were made or investment not found.";
                }
            } else {
                throw new Exception("Error updating investment: " . $stmt->error);
            }
            
            $stmt->close();
            $conn->close();
            
        } catch (Exception $e) {
            error_log($e->getMessage());
            $_SESSION['error'] = "Failed to update investment. Please try again.";
        }
    }
} else {
    $_SESSION['error'] = "Invalid request method.";
}

// Redirect back to the investment page
header("Location: ../add_investment.php");
exit;
?>