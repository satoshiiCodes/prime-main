<?php
// Set timezone to Philippines
date_default_timezone_set('Asia/Manila');

// Database configuration
$dbConfig = [
    'host' => 'localhost',
    'username' => 'root',
    'password' => '',
    'name' => 'db_prime_city',
    'charset' => 'utf8mb4'
];

// Start session and set JSON header
session_start();
header('Content-Type: application/json');

// Disable error display to prevent HTML in JSON response
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Verify authentication
if (!isset($_SESSION['logged_in'])) {
    http_response_code(401);
    die(json_encode(['status' => 'error', 'message' => 'Unauthorized']));
}

// Database connection
try {
    $conn = new mysqli($dbConfig['host'], $dbConfig['username'], $dbConfig['password'], $dbConfig['name']);
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }
    $conn->set_charset($dbConfig['charset']);
} catch (Exception $e) {
    http_response_code(500);
    die(json_encode(['status' => 'error', 'message' => 'Database connection failed']));
}

// Function to get customer name by ID
function getCustomerName($conn, $id) {
    $sql = "SELECT first_name, middle_name, last_name, suffix 
            FROM customer_accounts 
            WHERE id = ?";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return "Unknown Customer";
    }
    
    $stmt->bind_param("i", $id);
    if (!$stmt->execute()) {
        return "Unknown Customer";
    }
    
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        
        $first_name = trim($row['first_name']);
        $middle_name = trim($row['middle_name'] ?? '');
        $last_name = trim($row['last_name']);
        $suffix = trim($row['suffix'] ?? '');
        
        // Format: "last_name, first_name middle_name"
        $name = $last_name . ', ' . $first_name;
        
        // Add middle name if not empty
        if (!empty($middle_name)) {
            $name .= ' ' . $middle_name;
        }
        
        // Add suffix if not empty and not "None"
        if (!empty($suffix) && strtolower($suffix) !== 'none') {
            $name .= ' ' . $suffix;
        }
        
        return $name;
    }
    return "Unknown Customer";
}

// Function to get customer area by ID
function getCustomerArea($conn, $id) {
    $sql = "SELECT area FROM customer_accounts WHERE id = ?";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return "Unknown Area";
    }
    
    $stmt->bind_param("i", $id);
    if (!$stmt->execute()) {
        return "Unknown Area";
    }
    
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['area'];
    }
    return "Unknown Area";
}

// Function to get customer branch by ID
function getCustomerBranch($conn, $id) {
    $sql = "SELECT branch FROM customer_accounts WHERE id = ?";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return "Unknown Branch";
    }
    
    $stmt->bind_param("i", $id);
    if (!$stmt->execute()) {
        return "Unknown Branch";
    }
    
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['branch'];
    }
    return "Unknown Branch";
}

// Function to get active loans for a customer
function getCustomerLoans($conn, $customer_id) {
    $loans = [];
    
    $sql = "SELECT id, daily_payment, balance, start_date, status 
            FROM financing 
            WHERE customer_id = ? AND status = 'active'
            ORDER BY created_at DESC";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $loans;
    }
    
    $stmt->bind_param("i", $customer_id);
    if (!$stmt->execute()) {
        return $loans;
    }
    
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $loans[] = [
            'id' => $row['id'],
            'daily_payment' => $row['daily_payment'],
            'balance' => $row['balance'],
            'start_date' => $row['start_date'],
            'status' => $row['status']
        ];
    }
    
    return $loans;
}

// Function to get customer savings accounts
function getCustomerSavings($conn, $customer_id) {
    $savings = [];
    
    $sql = "SELECT id, account_number, current_balance, loan_id, status
            FROM savings_accounts 
            WHERE customer_id = ? AND status = 'Active'
            ORDER BY created_at DESC";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $savings;
    }
    
    $stmt->bind_param("i", $customer_id);
    if (!$stmt->execute()) {
        return $savings;
    }
    
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $savings[] = [
            'id' => $row['id'],
            'account_number' => $row['account_number'],
            'current_balance' => $row['current_balance'],
            'loan_id' => $row['loan_id'],
            'status' => $row['status']
        ];
    }
    
    return $savings;
}

// Function to get customer savings balance
function getCustomerSavingsBalance($conn, $customer_id) {
    $sql = "SELECT SUM(current_balance) as total_balance 
            FROM savings_accounts 
            WHERE customer_id = ? AND status = 'Active'";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0;
    }
    
    $stmt->bind_param("i", $customer_id);
    if (!$stmt->execute()) {
        return 0;
    }
    
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['total_balance'] ? (float)$row['total_balance'] : 0;
    }
    
    return 0;
}

// Function to get customer total loan balance
function getCustomerLoanBalance($conn, $customer_id) {
    $sql = "SELECT SUM(balance) as total_balance 
            FROM financing 
            WHERE customer_id = ? AND status = 'active'";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0;
    }
    
    $stmt->bind_param("i", $customer_id);
    if (!$stmt->execute()) {
        return 0;
    }
    
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['total_balance'] ? (float)$row['total_balance'] : 0;
    }
    
    return 0;
}

// Function to get collector's area and branch
function getCollectorInfo($conn, $collector_id) {
    $sql = "SELECT area, branch FROM collector_accounts WHERE id = ?";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }
    
    $stmt->bind_param("i", $collector_id);
    if (!$stmt->execute()) {
        return null;
    }
    
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return null;
}

// Handle API actions
try {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'get_accounts':
            handleGetAccounts($conn);
            break;
            
        case 'get_accounts_bulk':
            handleGetAccountsBulk($conn);
            break;
            
        case 'get_customers':
            handleGetCustomers($conn);
            break;
            
        case 'get_past_due_customers':
            handleGetPastDueCustomers($conn);
            break;
            
        case 'get_current_customers':
            handleGetCurrentCustomers($conn);
            break;
            
        case 'get_savings_only_customers':
            handleGetSavingsOnlyCustomers($conn);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error occurred: ' . $e->getMessage()]);
}

/**
 * New bulk endpoint: accepts POST JSON with customer_ids array
 */
function handleGetAccountsBulk($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $customer_ids = $input['customer_ids'] ?? [];
    
    if (!is_array($customer_ids) || empty($customer_ids)) {
        http_response_code(400);
        die(json_encode(['status' => 'error', 'message' => 'Invalid customer IDs']));
    }
    
    // Sanitize IDs
    $customer_ids = array_map('intval', $customer_ids);
    $customer_ids = array_filter($customer_ids, function($id) { return $id > 0; });
    
    $result = [];
    foreach ($customer_ids as $id) {
        $customer_name = getCustomerName($conn, $id);
        $customer_area = getCustomerArea($conn, $id);
        $customer_branch = getCustomerBranch($conn, $id);
        $loans = getCustomerLoans($conn, $id);
        $savings = getCustomerSavings($conn, $id);
        
        $result[$id] = [
            'customer' => [
                'id' => $id,
                'name' => $customer_name,
                'area' => $customer_area,
                'branch' => $customer_branch
            ],
            'accounts' => [
                'loans' => $loans,
                'savings' => $savings
            ]
        ];
    }
    
    echo json_encode(['status' => 'success', 'data' => $result]);
}

function handleGetAccounts($conn) {
    $customer_id = filter_input(INPUT_GET, 'customer_id', FILTER_VALIDATE_INT);
    if (!$customer_id || $customer_id <= 0) {
        http_response_code(400);
        die(json_encode(['status' => 'error', 'message' => 'Invalid customer ID']));
    }

    try {
        $customer_name = getCustomerName($conn, $customer_id);
        $customer_area = getCustomerArea($conn, $customer_id);
        $customer_branch = getCustomerBranch($conn, $customer_id);
        
        $loans = getCustomerLoans($conn, $customer_id);
        $savings = getCustomerSavings($conn, $customer_id);
        
        echo json_encode([
            'status' => 'success',
            'data' => [
                'customer' => [
                    'id' => $customer_id,
                    'name' => $customer_name,
                    'area' => $customer_area,
                    'branch' => $customer_branch
                ],
                'accounts' => [
                    'loans' => $loans,
                    'savings' => $savings
                ]
            ]
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to fetch accounts: ' . $e->getMessage()]);
    }
}

function handleGetCustomers($conn) {
    $collector_id = filter_input(INPUT_GET, 'collector_id', FILTER_VALIDATE_INT);
    if (!$collector_id || $collector_id <= 0) {
        http_response_code(400);
        die(json_encode(['status' => 'error', 'message' => 'Invalid collector ID']));
    }

    try {
        // First, get the collector's area and branch
        $collector_info = getCollectorInfo($conn, $collector_id);
        if (!$collector_info) {
            http_response_code(404);
            die(json_encode(['status' => 'error', 'message' => 'Collector not found']));
        }
        
        $collector_area = $collector_info['area'];
        $collector_branch = $collector_info['branch'];
        
        // Get customers in the SAME AREA and SAME BRANCH as the collector
        $customers = fetchCustomers($conn, $collector_area, $collector_branch);
        
        echo json_encode([
            'status' => 'success',
            'data' => [
                'collector_area' => $collector_area,
                'collector_branch' => $collector_branch,
                'customers' => $customers
            ]
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to fetch customers: ' . $e->getMessage()]);
    }
}

function handleGetPastDueCustomers($conn) {
    $collector_id = filter_input(INPUT_GET, 'collector_id', FILTER_VALIDATE_INT);
    if (!$collector_id || $collector_id <= 0) {
        http_response_code(400);
        die(json_encode(['status' => 'error', 'message' => 'Invalid collector ID']));
    }

    try {
        $currentDate = date('Y-m-d');
        
        // First, get the collector's area and branch
        $collector_info = getCollectorInfo($conn, $collector_id);
        if (!$collector_info) {
            http_response_code(404);
            die(json_encode(['status' => 'error', 'message' => 'Collector not found']));
        }
        
        $collector_area = $collector_info['area'];
        $collector_branch = $collector_info['branch'];
        
        // Get ONLY customers with past due loans in the SAME AREA and SAME BRANCH as collector
        // Past due = active loan with end_date < current date
        $queryPastDueLoans = "SELECT DISTINCT
                    ca.id, 
                    ca.first_name,
                    ca.middle_name, 
                    ca.last_name, 
                    ca.suffix,
                    ca.area,
                    ca.branch
                  FROM customer_accounts ca
                  JOIN financing f ON ca.id = f.customer_id
                  WHERE ca.area = ?  -- Same area as collector
                  AND ca.branch = ?  -- Same branch as collector
                  AND f.end_date < ?   -- Past due: end date is before today
                  AND f.status = 'active'
                  AND f.balance > 0     -- Still has remaining balance
                  AND ca.status = 'Active'
                  ORDER BY ca.last_name, ca.first_name";
        
        $stmtPastDue = $conn->prepare($queryPastDueLoans);
        if (!$stmtPastDue) {
            throw new Exception("Failed to prepare past due loans statement");
        }
        
        $stmtPastDue->bind_param("sss", $collector_area, $collector_branch, $currentDate);
        if (!$stmtPastDue->execute()) {
            throw new Exception("Failed to execute past due loans query");
        }
        
        $resultPastDue = $stmtPastDue->get_result();
        
        $customers = [];
        
        // Process past due loan customers only
        while ($row = $resultPastDue->fetch_assoc()) {
            $customerId = $row['id'];
            
            // Skip if customer already processed
            if (isset($customers[$customerId])) {
                continue;
            }
            
            $first_name = trim($row['first_name']);
            $middle_name = trim($row['middle_name'] ?? '');
            $last_name = trim($row['last_name']);
            $suffix = trim($row['suffix'] ?? '');
            
            // Format: "last_name, first_name middle_name"
            $full_name = $last_name . ', ' . $first_name;
            
            // Add middle name if not empty
            if (!empty($middle_name)) {
                $full_name .= ' ' . $middle_name;
            }
            
            // Add suffix if not empty and not "None"
            if (!empty($suffix) && strtolower($suffix) !== 'none') {
                $full_name .= ' ' . $suffix;
            }
            
            $loan_balance = getCustomerLoanBalance($conn, $customerId);
            $savings_balance = getCustomerSavingsBalance($conn, $customerId);
        
            $customers[$customerId] = [
                'id' => $customerId,
                'name' => $full_name,
                'area' => $row['area'],
                'branch' => $row['branch'],
                'loan_balance' => $loan_balance,
                'savings_balance' => $savings_balance
            ];
        }
        
        echo json_encode([
            'status' => 'success',
            'data' => [
                'collector_area' => $collector_area,
                'collector_branch' => $collector_branch,
                'customers' => array_values($customers)
            ]
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to fetch past due customers: ' . $e->getMessage()]);
    }
}

function handleGetCurrentCustomers($conn) {
    $collector_id = filter_input(INPUT_GET, 'collector_id', FILTER_VALIDATE_INT);
    if (!$collector_id || $collector_id <= 0) {
        http_response_code(400);
        die(json_encode(['status' => 'error', 'message' => 'Invalid collector ID']));
    }

    try {
        $currentDate = date('Y-m-d');
        
        // First, get the collector's area and branch
        $collector_info = getCollectorInfo($conn, $collector_id);
        if (!$collector_info) {
            http_response_code(404);
            die(json_encode(['status' => 'error', 'message' => 'Collector not found']));
        }
        
        $collector_area = $collector_info['area'];
        $collector_branch = $collector_info['branch'];
        
        // Get current customers (with active loans that are NOT past due) in the SAME AREA and SAME BRANCH as collector
        $query = "SELECT DISTINCT
            ca.id, 
            ca.first_name,
            ca.middle_name, 
            ca.last_name, 
            ca.suffix,
            ca.area,
            ca.branch
          FROM customer_accounts ca
          JOIN financing f ON ca.id = f.customer_id
          WHERE ca.area = ?  -- Same area as collector
          AND ca.branch = ?  -- Same branch as collector
          AND ca.status = 'Active'
          AND f.status = 'active'
          AND f.balance > 0    -- Has remaining loan balance
          AND (f.end_date >= ? OR f.end_date IS NULL)  -- Not past due
          ORDER BY ca.last_name, ca.first_name";
        
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            throw new Exception("Failed to prepare statement");
        }
        
        $stmt->bind_param("sss", $collector_area, $collector_branch, $currentDate);
        if (!$stmt->execute()) {
            throw new Exception("Failed to execute query");
        }
        
        $result = $stmt->get_result();
        
        $customers = [];
        while ($row = $result->fetch_assoc()) {
            $customerId = $row['id'];
            
            $first_name = trim($row['first_name']);
            $middle_name = trim($row['middle_name'] ?? '');
            $last_name = trim($row['last_name']);
            $suffix = trim($row['suffix'] ?? '');
            
            // Format: "last_name, first_name middle_name"
            $full_name = $last_name . ', ' . $first_name;
            
            // Add middle name if not empty
            if (!empty($middle_name)) {
                $full_name .= ' ' . $middle_name;
            }
            
            // Add suffix if not empty and not "None"
            if (!empty($suffix) && strtolower($suffix) !== 'none') {
                $full_name .= ' ' . $suffix;
            }
            
            $loan_balance = getCustomerLoanBalance($conn, $customerId);
            $savings_balance = getCustomerSavingsBalance($conn, $customerId);
        
            $customers[] = [
                'id' => $customerId,
                'name' => $full_name,
                'area' => $row['area'],
                'branch' => $row['branch'],
                'loan_balance' => $loan_balance,
                'savings_balance' => $savings_balance
            ];
        }
        
        echo json_encode([
            'status' => 'success',
            'data' => [
                'collector_area' => $collector_area,
                'collector_branch' => $collector_branch,
                'customers' => $customers
            ]
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to fetch current customers: ' . $e->getMessage()]);
    }
}

function handleGetSavingsOnlyCustomers($conn) {
    $collector_id = filter_input(INPUT_GET, 'collector_id', FILTER_VALIDATE_INT);
    if (!$collector_id || $collector_id <= 0) {
        http_response_code(400);
        die(json_encode(['status' => 'error', 'message' => 'Invalid collector ID']));
    }

    try {
        // First, get the collector's area and branch
        $collector_info = getCollectorInfo($conn, $collector_id);
        if (!$collector_info) {
            http_response_code(404);
            die(json_encode(['status' => 'error', 'message' => 'Collector not found']));
        }
        
        $collector_area = $collector_info['area'];
        $collector_branch = $collector_info['branch'];
        
        // Get customers with ONLY savings (no active loans) in the SAME AREA and SAME BRANCH as collector
        $query = "SELECT DISTINCT
                    ca.id, 
                    ca.first_name,
                    ca.middle_name, 
                    ca.last_name, 
                    ca.suffix,
                    ca.area,
                    ca.branch
                  FROM customer_accounts ca
                  JOIN savings_accounts sa ON ca.id = sa.customer_id
                  WHERE ca.area = ?  -- Same area as collector
                  AND ca.branch = ?  -- Same branch as collector
                  AND ca.status = 'Active'
                  AND sa.status = 'Active'
                  AND sa.current_balance > 0
                  AND NOT EXISTS (
                    SELECT 1 FROM financing f2 
                    WHERE f2.customer_id = ca.id 
                    AND f2.status = 'active' 
                    AND f2.balance > 0
                  )
                  ORDER BY ca.last_name, ca.first_name";
        
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            throw new Exception("Failed to prepare statement");
        }
        
        $stmt->bind_param("ss", $collector_area, $collector_branch);
        if (!$stmt->execute()) {
            throw new Exception("Failed to execute query");
        }
        
        $result = $stmt->get_result();
        
        $customers = [];
        while ($row = $result->fetch_assoc()) {
            $customerId = $row['id'];
            
            $first_name = trim($row['first_name']);
            $middle_name = trim($row['middle_name'] ?? '');
            $last_name = trim($row['last_name']);
            $suffix = trim($row['suffix'] ?? '');
            
            // Format: "last_name, first_name middle_name"
            $full_name = $last_name . ', ' . $first_name;
            
            // Add middle name if not empty
            if (!empty($middle_name)) {
                $full_name .= ' ' . $middle_name;
            }
            
            // Add suffix if not empty and not "None"
            if (!empty($suffix) && strtolower($suffix) !== 'none') {
                $full_name .= ' ' . $suffix;
            }
            
            $loan_balance = getCustomerLoanBalance($conn, $customerId);
            $savings_balance = getCustomerSavingsBalance($conn, $customerId);
        
            $customers[] = [
                'id' => $customerId,
                'name' => $full_name,
                'area' => $row['area'],
                'branch' => $row['branch'],
                'loan_balance' => $loan_balance,
                'savings_balance' => $savings_balance
            ];
        }
        
        echo json_encode([
            'status' => 'success',
            'data' => [
                'collector_area' => $collector_area,
                'collector_branch' => $collector_branch,
                'customers' => $customers
            ]
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to fetch savings only customers: ' . $e->getMessage()]);
    }
}

// Function to fetch customers by area and branch
function fetchCustomers($conn, $area = null, $branch = null) {
    $customers = [];
    
    $sql = "SELECT id, first_name, middle_name, last_name, suffix, area, branch
            FROM customer_accounts 
            WHERE status = 'Active'";
    
    // Add area filter if provided
    if ($area !== null) {
        $sql .= " AND area = ?";
    }
    
    // Add branch filter if provided
    if ($branch !== null) {
        $sql .= " AND branch = ?";
    }
    
    $sql .= " ORDER BY last_name, first_name";
    
    if ($area !== null && $branch !== null) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return $customers;
        }
        $stmt->bind_param("ss", $area, $branch);
        if (!$stmt->execute()) {
            return $customers;
        }
        $result = $stmt->get_result();
    } elseif ($area !== null) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return $customers;
        }
        $stmt->bind_param("s", $area);
        if (!$stmt->execute()) {
            return $customers;
        }
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($sql);
        if (!$result) {
            return $customers;
        }
    }
    
    while($row = $result->fetch_assoc()) {
        $first_name = trim($row['first_name']);
        $middle_name = trim($row['middle_name'] ?? '');
        $last_name = trim($row['last_name']);
        $suffix = trim($row['suffix'] ?? '');
        
        // Format: "last_name, first_name middle_name"
        $full_name = $last_name . ', ' . $first_name;
        
        // Add middle name if not empty
        if (!empty($middle_name)) {
            $full_name .= ' ' . $middle_name;
        }
        
        // Add suffix if not empty and not "None"
        if (!empty($suffix) && strtolower($suffix) !== 'none') {
            $full_name .= ' ' . $suffix;
        }
        
        $customer_id = $row['id'];
        $loan_balance = getCustomerLoanBalance($conn, $customer_id);
        $savings_balance = getCustomerSavingsBalance($conn, $customer_id);
        
        $customers[] = [
            'id' => $customer_id,
            'name' => $full_name,
            'area' => $row['area'],
            'branch' => $row['branch'],
            'loan_balance' => $loan_balance,
            'savings_balance' => $savings_balance
        ];
    }
    
    return $customers;
}

// Close database connection
$conn->close();
?>
