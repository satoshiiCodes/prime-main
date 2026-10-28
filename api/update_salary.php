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
error_log("update_salary.php received: " . print_r($_POST, true));

$account_id = $_POST['account_id'] ?? 0;
$source_type = $_POST['account_type'] ?? ''; // This is the source table (collector, proxy, accounts)
$daily_salary = $_POST['salary'] ?? 0; // Changed variable name to daily_salary
$effective_date = $_POST['effective_date'] ?? '';
$note = $_POST['note'] ?? '';

// Validate inputs
if (!$account_id || !$source_type || !$daily_salary || !$effective_date) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Validate source type
if (!in_array($source_type, ['collector', 'proxy', 'accounts'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid source type']);
    exit;
}

if (!is_numeric($daily_salary) || $daily_salary <= 0) {
    echo json_encode(['success' => false, 'message' => 'Daily salary must be a positive number']);
    exit;
}

// FIXED: Strict validation for YYYY-MM-DD format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effective_date)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date format. Please use YYYY-MM-DD']);
    exit;
}

// Check if date is valid
$date_parts = explode('-', $effective_date);
if (count($date_parts) !== 3) {
    echo json_encode(['success' => false, 'message' => 'Invalid date format']);
    exit;
}

$year = (int)$date_parts[0];
$month = (int)$date_parts[1];
$day = (int)$date_parts[2];

// Validate year range
if ($year < 1900 || $year > 2100) {
    echo json_encode(['success' => false, 'message' => 'Year must be between 1900 and 2100']);
    exit;
}

// Validate month range
if ($month < 1 || $month > 12) {
    echo json_encode(['success' => false, 'message' => 'Month must be between 1 and 12']);
    exit;
}

// Validate day range
if ($day < 1 || $day > 31) {
    echo json_encode(['success' => false, 'message' => 'Day must be between 1 and 31']);
    exit;
}

// Check if the date actually exists (e.g., not February 30)
if (!checkdate($month, $day, $year)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date: ' . $effective_date . ' does not exist']);
    exit;
}

// Optional: Validate that date is not too far in the future
$today = new DateTime();
$selected_date = new DateTime($effective_date);
$max_date = new DateTime('+10 years'); // Allow dates up to 10 years in the future

if ($selected_date > $max_date) {
    echo json_encode(['success' => false, 'message' => 'Effective date cannot be more than 10 years in the future']);
    exit;
}

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    
    // FIXED: Get the actual account_type from the appropriate table
    $actual_account_type = '';
    
    if ($source_type === 'collector') {
        // Get account_type from collector_accounts table
        $stmt = $conn->prepare("SELECT account_type FROM collector_accounts WHERE id = ?");
        $stmt->bind_param("i", $account_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $actual_account_type = $row['account_type'];
        } else {
            throw new Exception("Collector not found with ID: " . $account_id);
        }
        $stmt->close();
        
    } elseif ($source_type === 'proxy') {
        // Get account_type from proxy_accounts table
        $stmt = $conn->prepare("SELECT account_type FROM proxy_accounts WHERE id = ?");
        $stmt->bind_param("i", $account_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $actual_account_type = $row['account_type'];
        } else {
            throw new Exception("Proxy account not found with ID: " . $account_id);
        }
        $stmt->close();
        
    } elseif ($source_type === 'accounts') {
        // Get account_type from accounts table
        $stmt = $conn->prepare("SELECT account_type FROM accounts WHERE id = ?");
        $stmt->bind_param("i", $account_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $actual_account_type = $row['account_type'];
        } else {
            throw new Exception("Account not found with ID: " . $account_id);
        }
        $stmt->close();
    }
    
    if (empty($actual_account_type)) {
        throw new Exception("Could not determine account type");
    }
    
    error_log("Actual account_type retrieved: " . $actual_account_type . " for source: " . $source_type . ", ID: " . $account_id);
    
    // Check if salary_history table exists, if not create it with the new column name
    $create_table_sql = "
        CREATE TABLE IF NOT EXISTS salary_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_id INT NOT NULL,
            source_table ENUM('collector', 'proxy', 'accounts') NOT NULL,
            account_type VARCHAR(50) NOT NULL,
            daily_salary DECIMAL(10,2) NOT NULL,
            effective_date DATE NOT NULL,
            note TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            created_by INT,
            INDEX idx_account (account_id, source_table),
            UNIQUE KEY unique_account_date (account_id, source_table, effective_date)
        )
    ";
    
    if (!$conn->query($create_table_sql)) {
        throw new Exception("Failed to create table: " . $conn->error);
    }
    
    // Check if we need to alter existing table (if it has old structure with 'salary' column)
    $check_column_sql = "SHOW COLUMNS FROM salary_history LIKE 'daily_salary'";
    $column_check = $conn->query($check_column_sql);
    
    if ($column_check->num_rows === 0) {
        // Table has old structure with 'salary' column, need to rename it
        $check_old_column = $conn->query("SHOW COLUMNS FROM salary_history LIKE 'salary'");
        
        if ($check_old_column->num_rows > 0) {
            // Rename salary column to daily_salary
            $alter_sql = "ALTER TABLE salary_history CHANGE COLUMN salary daily_salary DECIMAL(10,2) NOT NULL";
            
            if (!$conn->query($alter_sql)) {
                error_log("Warning: Could not rename column: " . $conn->error);
                // Continue anyway
            } else {
                error_log("Successfully renamed salary column to daily_salary");
            }
        }
    }
    
    $created_by = $_SESSION['user_id'] ?? null;
    
    // Check if there's already a salary entry for this account on the same date
    $check_stmt = $conn->prepare("SELECT id, daily_salary FROM salary_history WHERE account_id = ? AND source_table = ? AND effective_date = ?");
    $check_stmt->bind_param("iss", $account_id, $source_type, $effective_date);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        // Entry exists for this date - update it
        $existing = $check_result->fetch_assoc();
        
        // Update the existing record with new daily_salary and account_type
        $update_stmt = $conn->prepare("UPDATE salary_history SET daily_salary = ?, account_type = ?, note = CONCAT(IFNULL(note, ''), ?), created_by = ? WHERE id = ?");
        $update_note = "Updated from " . number_format($existing['daily_salary'], 2) . " to " . number_format($daily_salary, 2) . ". " . $note;
        $update_stmt->bind_param("dssii", $daily_salary, $actual_account_type, $update_note, $created_by, $existing['id']);
        
        if ($update_stmt->execute()) {
            error_log("Daily salary updated for account_id: $account_id, source: $source_type, account_type: $actual_account_type, effective_date: $effective_date");
            echo json_encode([
                'success' => true, 
                'message' => 'Daily salary updated successfully (overwritten previous entry for this date)',
                'action' => 'updated',
                'account_type' => $actual_account_type
            ]);
        } else {
            throw new Exception("Failed to update daily salary: " . $update_stmt->error);
        }
        $update_stmt->close();
        
    } else {
        // No entry exists for this date - insert new record
        $insert_stmt = $conn->prepare("INSERT INTO salary_history (account_id, source_table, account_type, daily_salary, effective_date, note, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $insert_stmt->bind_param("issdssi", $account_id, $source_type, $actual_account_type, $daily_salary, $effective_date, $note, $created_by);
        
        if ($insert_stmt->execute()) {
            error_log("New daily salary entry created for account_id: $account_id, source: $source_type, account_type: $actual_account_type, effective_date: $effective_date");
            echo json_encode([
                'success' => true, 
                'message' => 'New daily salary record created successfully',
                'action' => 'inserted',
                'account_type' => $actual_account_type
            ]);
        } else {
            throw new Exception("Failed to insert daily salary: " . $insert_stmt->error);
        }
        $insert_stmt->close();
    }
    
    $check_stmt->close();
    $conn->close();
    
} catch (Exception $e) {
    error_log("Error in update_salary.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>