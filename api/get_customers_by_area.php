<?php
require_once '../includes/db_config1.php';

header('Content-Type: application/json');

try {
    if (!isset($_GET['area'])) {
        throw new Exception('Area parameter is required');
    }

    $area = $_GET['area'];
    $branch = isset($_GET['branch']) ? $_GET['branch'] : '';
    
    $conn = getDBConnection();
    
    // Build query to get customers from the same area AND branch
    $sql = "SELECT id, first_name, middle_name, last_name, suffix, contact_number, status, area, branch 
            FROM customer_accounts 
            WHERE area = ?";
    
    $params = [$area];
    
    // Add branch filter if provided
    if (!empty($branch)) {
        $sql .= " AND branch = ?";
        $params[] = $branch;
    }
    
    $sql .= " ORDER BY last_name, first_name";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format the names in "First Middle Last Suffix" format
    $formattedCustomers = array_map(function($customer) {
        $nameParts = [];
        $nameParts[] = $customer['first_name'];
        if (!empty($customer['middle_name'])) {
            $nameParts[] = $customer['middle_name'];
        }
        $nameParts[] = $customer['last_name'];
        if (!empty($customer['suffix']) && $customer['suffix'] !== 'None') {
            $nameParts[] = $customer['suffix'];
        }
        
        $customer['formatted_name'] = implode(' ', $nameParts);
        return $customer;
    }, $customers);
    
    echo json_encode([
        'success' => true,
        'customers' => $formattedCustomers,
        'filters' => [
            'area' => $area,
            'branch' => $branch
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}