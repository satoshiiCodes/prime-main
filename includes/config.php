<?php
// Set timezone to Philippines
date_default_timezone_set('Asia/Manila');

// Get current date and time in 12-hour format with AM/PM
$currentDateTime = date('m/d/Y h:i A');

// Database configuration
$dbConfig = [
    'host' => 'localhost',
    'username' => 'root',
    'password' => '',
    'name' => 'db_prime_city',
    'charset' => 'utf8mb4'
];

// Helper function to escape output
function escape($data) {
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

// Function to get customer name by ID
function getCustomerName($conn, $id) {
    $sql = "SELECT CONCAT(first_name, ' ', COALESCE(middle_name, ''), ' ', last_name, 
            CASE WHEN suffix IS NOT NULL AND suffix != 'None' THEN CONCAT(' ', suffix) ELSE '' END) AS full_name 
            FROM customer_accounts WHERE id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['full_name'];
    }
    return "Unknown Customer";
}

// Function to get customer area by ID
function getCustomerArea($conn, $id) {
    $sql = "SELECT area FROM customer_accounts WHERE id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
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
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['branch'] ?? 'Tuguegarao City'; // Default branch if not set
    }
    return "Tuguegarao City"; // Default branch
}

// Function to get active loans for a customer
function getCustomerLoans($conn, $customer_id) {
    $loans = [];
    $sql = "SELECT id, daily_payment, balance FROM financing 
            WHERE customer_id = ? AND balance > 0 
            ORDER BY created_at DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $loans[] = [
            'id' => $row['id'],
            'display' => "Loan #" . $row['id'] . " - Balance: ₱" . number_format($row['balance'], 2),
            'daily_payment' => $row['daily_payment'],
            'balance' => $row['balance'],
            'auto_select' => false // Will be set to true in handleGetAccounts if only one exists
        ];
    }
    
    return $loans;
}

// Function to get customer savings accounts
function getCustomerSavings($conn, $customer_id) {
    $savings = [];
    $sql = "SELECT id, account_number, current_balance, loan_id
            FROM savings_accounts 
            WHERE customer_id = ? AND status = 'Active'
            ORDER BY created_at DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $display = "Savings";
        
        if ($row['loan_id']) {
            $display .= " #" . $row['loan_id'];
        }
        
        $display .= " - Balance: ₱" . number_format($row['current_balance'], 2);
        
        $savings[] = [
            'id' => $row['id'],
            'account_number' => $row['account_number'],
            'display' => $display,
            'current_balance' => $row['current_balance'],
            'loan_id' => $row['loan_id'],
            'auto_select' => false // Will be set to true in handleGetAccounts if only one exists
        ];
    }
    
    return $savings;
}
?>