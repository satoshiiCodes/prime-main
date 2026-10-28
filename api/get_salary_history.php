<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require '../includes/db_config.php';

header('Content-Type: application/json');

$account_id = $_GET['account_id'] ?? 0;
$source_type = $_GET['account_type'] ?? ''; // This is the source table

if (!$account_id || !$source_type) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

if (!in_array($source_type, ['collector', 'proxy', 'accounts'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid account type']);
    exit;
}

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    
    // Check which column name exists in the table
    $check_daily_salary = $conn->query("SHOW COLUMNS FROM salary_history LIKE 'daily_salary'");
    $check_salary = $conn->query("SHOW COLUMNS FROM salary_history LIKE 'salary'");
    
    $salary_column = 'daily_salary'; // Default to new column name
    
    if ($check_daily_salary->num_rows === 0 && $check_salary->num_rows > 0) {
        $salary_column = 'salary'; // Use old column name if daily_salary doesn't exist
    }
    
    // Check if salary_history table has the new structure with source_table
    $check_source_table = $conn->query("SHOW COLUMNS FROM salary_history LIKE 'source_table'");
    
    if ($check_source_table->num_rows > 0) {
        // New structure with source_table and account_type
        $sql = "SELECT sh.*, 
                       CONCAT(a.first_name, ' ', a.last_name) as updated_by_name 
                FROM salary_history sh 
                LEFT JOIN accounts a ON sh.created_by = a.id 
                WHERE sh.account_id = ? AND sh.source_table = ? 
                ORDER BY sh.effective_date DESC, sh.created_at DESC";
    } else {
        // Old structure - use account_type as source_table
        $sql = "SELECT sh.*, 
                       CONCAT(a.first_name, ' ', a.last_name) as updated_by_name 
                FROM salary_history sh 
                LEFT JOIN accounts a ON sh.created_by = a.id 
                WHERE sh.account_id = ? AND sh.account_type = ? 
                ORDER BY sh.effective_date DESC, sh.created_at DESC";
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $account_id, $source_type);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $history = [];
    while ($row = $result->fetch_assoc()) {
        // Use the appropriate salary column
        $salary_value = isset($row['daily_salary']) ? $row['daily_salary'] : (isset($row['salary']) ? $row['salary'] : 0);
        
        $history[] = [
            'id' => $row['id'],
            'salary' => $salary_value, // Keep as 'salary' for JavaScript compatibility
            'effective_date' => date('M d, Y', strtotime($row['effective_date'])),
            'note' => $row['note'],
            'created_at' => date('M d, Y h:i A', strtotime($row['created_at'])),
            'updated_by_name' => $row['updated_by_name'] ?? 'System',
            'account_type' => $row['account_type'] ?? $source_type // Show actual account type if available
        ];
    }
    
    echo json_encode([
        'success' => true,
        'history' => $history
    ]);
    
    $stmt->close();
    $conn->close();
    
} catch (Exception $e) {
    error_log("Error in get_salary_history.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>