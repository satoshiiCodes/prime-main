<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require '../includes/db_config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Log received data for debugging
error_log("add_deduction.php received: " . print_r($_POST, true));

$employee_id = $_POST['employee_id'] ?? 0;
$employee_type = $_POST['employee_type'] ?? '';
$deduction_type = $_POST['deduction_type'] ?? '';
$amount = $_POST['amount'] ?? 0;
$frequency = $_POST['frequency'] ?? 'monthly';
$effective_date = $_POST['effective_date'] ?? null;
$start_date = $_POST['start_date'] ?? null;
$end_date = $_POST['end_date'] ?? null;
$notes = $_POST['notes'] ?? '';
$created_by = $_SESSION['user_id'] ?? null;

// Validate inputs
if (!$employee_id || !$employee_type || !$deduction_type || !$amount) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Validate employee type
if (!in_array($employee_type, ['collector', 'proxy', 'accounts'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid employee type']);
    exit;
}

// Validate deduction type - UPDATED to include sss_loan and pagibig_loan
if (!in_array($deduction_type, ['loan_payment', 'cash_advance', 'sss', 'pagibig', 'philhealth', 'sss_loan', 'pagibig_loan'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid deduction type']);
    exit;
}

if (!is_numeric($amount) || $amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Amount must be a positive number']);
    exit;
}

// Set date variables to NULL for different deduction types
$db_effective_date = null;
$db_start_date = null;
$db_end_date = null;

if (in_array($deduction_type, ['loan_payment', 'cash_advance', 'sss_loan', 'pagibig_loan'])) {
    // For loans and cash advances, require start and end dates
    if (empty($start_date) || empty($end_date)) {
        echo json_encode(['success' => false, 'message' => 'Start date and end date are required for loans and cash advances']);
        exit;
    }
    
    // Validate date formats
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
        echo json_encode(['success' => false, 'message' => 'Invalid date format. Use YYYY-MM-DD']);
        exit;
    }
    
    // Validate date order
    if (strtotime($start_date) > strtotime($end_date)) {
        echo json_encode(['success' => false, 'message' => 'Start date must be before end date']);
        exit;
    }
    
    $db_start_date = $start_date;
    $db_end_date = $end_date;
    
} else if (in_array($deduction_type, ['sss', 'pagibig', 'philhealth'])) {
    // For government contributions, require effective date
    if (empty($effective_date)) {
        echo json_encode(['success' => false, 'message' => 'Effective date is required for government contributions']);
        exit;
    }
    
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effective_date)) {
        echo json_encode(['success' => false, 'message' => 'Invalid effective date format. Use YYYY-MM-DD']);
        exit;
    }
    
    $db_effective_date = $effective_date;
}

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    
    // Check if employee_deductions table exists with correct structure
    $conn->query("
        CREATE TABLE IF NOT EXISTS employee_deductions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            employee_type ENUM('collector', 'proxy', 'accounts') NOT NULL,
            deduction_type ENUM('loan_payment', 'cash_advance', 'sss', 'pagibig', 'philhealth', 'sss_loan', 'pagibig_loan') NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            effective_date DATE NULL,
            start_date DATE NULL,
            end_date DATE NULL,
            frequency ENUM('one_time', 'monthly', 'semi_monthly') DEFAULT 'monthly',
            is_active BOOLEAN DEFAULT TRUE,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_by INT,
            INDEX idx_employee (employee_id, employee_type),
            INDEX idx_deduction_type (deduction_type),
            INDEX idx_dates (effective_date, start_date, end_date)
        )
    ");
    
    // Check for existing ACTIVE deduction with the same parameters
    $check_sql = "";
    $check_params = [];
    $check_types = "";
    
    if (in_array($deduction_type, ['loan_payment', 'cash_advance', 'sss_loan', 'pagibig_loan'])) {
        // Check by start_date and end_date for loans - only check ACTIVE ones
        $check_sql = "SELECT id FROM employee_deductions 
                      WHERE employee_id = ? AND employee_type = ? 
                      AND deduction_type = ? AND start_date = ? AND end_date = ? AND is_active = 1";
        $check_params = [$employee_id, $employee_type, $deduction_type, $db_start_date, $db_end_date];
        $check_types = "issss";
    } else {
        // Check by effective_date for government contributions - only check ACTIVE ones
        $check_sql = "SELECT id FROM employee_deductions 
                      WHERE employee_id = ? AND employee_type = ? 
                      AND deduction_type = ? AND effective_date = ? AND is_active = 1";
        $check_params = [$employee_id, $employee_type, $deduction_type, $db_effective_date];
        $check_types = "isss";
    }
    
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param($check_types, ...$check_params);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows > 0) {
        // Existing ACTIVE deduction found - don't allow duplicate
        $existing = $result->fetch_assoc();
        $existing_id = $existing['id'];
        
        // Instead of updating, return an error for duplicate
        echo json_encode([
            'success' => false,
            'message' => 'An active deduction with the same dates already exists. Please deactivate the existing one first or change the dates.',
            'existing_id' => $existing_id
        ]);
        $check_stmt->close();
        $conn->close();
        exit;
    }
    
    // No existing active deduction - insert new one
    $sql = "INSERT INTO employee_deductions 
            (employee_id, employee_type, deduction_type, amount, effective_date, start_date, end_date, frequency, notes, created_by, is_active) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";
    
    $stmt = $conn->prepare($sql);
    
    // Bind parameters - note that we use the db_* variables which are either valid dates or null
    $stmt->bind_param("issdsssssi", 
        $employee_id, 
        $employee_type, 
        $deduction_type, 
        $amount, 
        $db_effective_date, 
        $db_start_date, 
        $db_end_date, 
        $frequency, 
        $notes, 
        $created_by
    );
    
    if ($stmt->execute()) {
        $deduction_id = $conn->insert_id;
        error_log("Deduction added successfully. ID: $deduction_id");
        
        echo json_encode([
            'success' => true,
            'message' => 'Deduction added successfully',
            'deduction_id' => $deduction_id,
            'action' => 'inserted'
        ]);
    } else {
        throw new Exception("Failed to add deduction: " . $stmt->error);
    }
    $stmt->close();
    
    $check_stmt->close();
    $conn->close();
    
} catch (Exception $e) {
    error_log("Error in add_deduction.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>