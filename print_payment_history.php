<?php
session_start();

// Redirect to login if not authenticated
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

// Database configuration
require 'includes/db_config.php';

// Initialize user variable
$user = [];

// Get user details
try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    // Determine which table to query based on account source from session
    $account_source = $_SESSION['account_source'] ?? 'accounts';
    
    if ($account_source === 'accounts') {
        $stmt = $conn->prepare("SELECT first_name, middle_name, last_name, suffix, account_type, branch FROM accounts WHERE id = ?");
    } else {
        $stmt = $conn->prepare("SELECT first_name, middle_name, last_name, suffix, account_type, branch FROM proxy_accounts WHERE id = ?");
    }
    
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
    } else {
        throw new Exception("User not found");
    }
    
    $stmt->close();
    $conn->close();

} catch (Exception $e) {
    error_log($e->getMessage());
    // If user data can't be fetched, use session data as fallback
    $user = [
        'first_name' => $_SESSION['first_name'] ?? 'Admin',
        'middle_name' => '',
        'last_name' => '',
        'suffix' => '',
        'account_type' => $_SESSION['account_type'] ?? 'User',
        'branch' => $_SESSION['branch'] ?? ''
    ];
}

// Initialize filter variables from GET parameters
$selected_area = isset($_GET['area']) ? $_GET['area'] : '';
$selected_branch = isset($_GET['branch']) ? $_GET['branch'] : '';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('monday this week'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d', strtotime('Saturday this week'));
$due_date_filter = isset($_GET['due_date']) ? $_GET['due_date'] : '';
$custom_due_date = isset($_GET['custom_due_date']) ? $_GET['custom_due_date'] : '';
$show_completed = isset($_GET['show_completed']) ? $_GET['show_completed'] : false;
$show_missed_payments = isset($_GET['show_missed_payments']) ? $_GET['show_missed_payments'] : false;

// Validate dates
if (!empty($start_date) && !empty($end_date)) {
    if (strtotime($start_date) > strtotime($end_date)) {
        // Swap dates if start is after end
        $temp = $start_date;
        $start_date = $end_date;
        $end_date = $temp;
    }
}

// Determine available branches based on account type
$available_branches = [];
$user_branch = $user['branch'] ?? null;
$user_account_type = $user['account_type'] ?? '';

// Define restricted account types (single branch only)
$restricted_account_types = ['Branch Manager', 'Proxy Collector-Cashier', 'Proxy Collector'];

if ($user_account_type === 'Admin') {
    // Admin can see all branches
    $available_branches = ['Tuguegarao City', 'Gattaran', 'Abulug', 'Ilagan'];
    
    // If selected branch is 'all', keep it as 'all'
    if ($selected_branch === 'all') {
        // No need to change
    } elseif (empty($selected_branch)) {
        $selected_branch = 'all';
    }
} 
elseif ($user_account_type === 'Area Manager') {
    // Area Manager can have multiple branches
    if ($user_branch) {
        // Split branches by comma and trim each one
        $available_branches = array_map('trim', explode(',', $user_branch));
        
        // If selected branch is 'all' or not in available branches, set to first available branch
        if ($selected_branch === 'all' || !in_array($selected_branch, $available_branches)) {
            $selected_branch = $available_branches[0];
        }
    } else {
        // Fallback if no branch assigned
        $available_branches = ['Tuguegarao City'];
        $selected_branch = 'Tuguegarao City';
    }
}
elseif (in_array($user_account_type, $restricted_account_types)) {
    // For restricted users, only show their assigned branch
    if ($user_branch) {
        $available_branches[] = $user_branch;
        
        // If selected branch is 'all' or doesn't match user's branch, force it to user's branch
        if ($selected_branch === 'all' || $selected_branch !== $user_branch) {
            $selected_branch = $user_branch;
        }
    }
} else {
    // Default fallback for other account types
    $available_branches = ['Tuguegarao City', 'Gattaran', 'Abulug', 'Ilagan'];
    if (empty($selected_branch)) {
        $selected_branch = 'Tuguegarao City';
    }
}

// Create predefined areas 1-10
$areas = [];
for ($i = 1; $i <= 10; $i++) {
    $areas[] = "Area $i";
}

// Fetch all customers with their financing information and payments
try {
    $customer_conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $customer_conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Build the base query for customers - Get customers with active OR completed loans based on filter
    $customer_query = "
        SELECT ca.*, f.end_date, f.daily_payment, f.balance, f.status as loan_status 
        FROM customer_accounts ca
        INNER JOIN financing f ON ca.id = f.customer_id
        WHERE 1=1
    ";
    
    // Modify query based on show_completed filter
    if (!$show_completed) {
        $customer_query .= " AND f.status = 'active'";
    } else {
        $customer_query .= " AND (f.status = 'active' OR f.status = 'completed')";
    }
    
    // Add area filter if selected
    if (!empty($selected_area)) {
        $customer_query .= " AND ca.area = :area";
    }
    
    // ADDED: Add due date filter if selected
    if (!empty($due_date_filter)) {
        if ($due_date_filter === 'past_due') {
            $customer_query .= " AND f.end_date < CURDATE()";
        } elseif ($due_date_filter === 'today') {
            $customer_query .= " AND f.end_date = CURDATE()";
        } elseif ($due_date_filter === 'future') {
            $customer_query .= " AND f.end_date > CURDATE()";
        } elseif ($due_date_filter === 'this_week') {
            $customer_query .= " AND YEARWEEK(f.end_date, 1) = YEARWEEK(CURDATE(), 1)";
        } elseif ($due_date_filter === 'next_week') {
            $customer_query .= " AND YEARWEEK(f.end_date, 1) = YEARWEEK(CURDATE(), 1) + 1";
        } elseif ($due_date_filter === 'this_month') {
            $customer_query .= " AND MONTH(f.end_date) = MONTH(CURDATE()) AND YEAR(f.end_date) = YEAR(CURDATE())";
        } elseif ($due_date_filter === 'custom' && !empty($custom_due_date)) {
            $customer_query .= " AND f.end_date = :custom_due_date";
        }
    }
    
    // Add branch filter based on user type and selection
    if (!empty($selected_branch)) {
        if ($selected_branch == 'all') {
            // For Admin viewing all branches, no branch filter needed
            if ($user_account_type != 'Admin') {
                // For non-admin users, filter by available branches
                if (!empty($available_branches)) {
                    $placeholders = str_repeat('?,', count($available_branches) - 1) . '?';
                    $customer_query .= " AND ca.branch IN ($placeholders)";
                }
            }
        } else {
            // Specific branch selected
            $customer_query .= " AND ca.branch = :branch";
        }
    } else {
        // No branch selected, filter by available branches for non-admin users
        if ($user_account_type != 'Admin' && !empty($available_branches)) {
            $placeholders = str_repeat('?,', count($available_branches) - 1) . '?';
            $customer_query .= " AND ca.branch IN ($placeholders)";
        }
    }
    
    $customer_query .= " ORDER BY ca.area, ca.last_name, ca.first_name";
    
    $stmt = $customer_conn->prepare($customer_query);
    $param_index = 1;
    
    if (!empty($selected_area)) {
        $stmt->bindParam(':area', $selected_area);
    }
    
    // ADDED: Bind custom due date parameter if needed
    if ($due_date_filter === 'custom' && !empty($custom_due_date)) {
        $stmt->bindParam(':custom_due_date', $custom_due_date);
    }
    
    if (!empty($selected_branch)) {
        if ($selected_branch == 'all') {
            // For non-admin users with available branches
            if ($user_account_type != 'Admin' && !empty($available_branches)) {
                foreach ($available_branches as $branch) {
                    $stmt->bindValue($param_index++, $branch);
                }
            }
        } else {
            // Specific branch selected
            $stmt->bindParam(':branch', $selected_branch);
        }
    } else {
        // No branch selected, bind available branches for non-admin users
        if ($user_account_type != 'Admin' && !empty($available_branches)) {
            foreach ($available_branches as $branch) {
                $stmt->bindValue($param_index++, $branch);
            }
        }
    }
    
    $stmt->execute();
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all payments for these customers for the specified date range with payment method
    $payment_stmt = $customer_conn->prepare("
        SELECT p.customer_id, p.amount, p.notes, p.payment_method, DATE(p.payment_date) as payment_date
        FROM payments p
        WHERE DATE(p.payment_date) BETWEEN :start_date AND :end_date
        AND (p.notes LIKE 'Payment - %' OR p.notes LIKE 'Payment from Savings - %')
        ORDER BY p.customer_id, DATE(p.payment_date), p.id
    ");
    $payment_stmt->bindParam(':start_date', $start_date);
    $payment_stmt->bindParam(':end_date', $end_date);
    $payment_stmt->execute();
    $all_payments = $payment_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // FIXED: Get all savings deposits for these customers for the specified date range WITH PAYMENT METHOD
    $savings_stmt = $customer_conn->prepare("
        SELECT sa.customer_id, st.amount, st.transaction_type, DATE(st.transaction_date) as transaction_date,
               COALESCE(st.payment_method, 'Cash') as payment_method
        FROM savings_transactions st
        JOIN savings_accounts sa ON st.savings_account_id = sa.id
        WHERE DATE(st.transaction_date) BETWEEN :start_date AND :end_date
        AND st.transaction_type = 'Deposit'
        ORDER BY sa.customer_id, DATE(st.transaction_date), st.id
    ");
    $savings_stmt->bindParam(':start_date', $start_date);
    $savings_stmt->bindParam(':end_date', $end_date);
    $savings_stmt->execute();
    $all_savings_deposits = $savings_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get last payments for completed loans (for all time, not just date range)
    $last_payments_stmt = $customer_conn->prepare("
        SELECT p1.customer_id, p1.amount, p1.payment_date, p1.notes, p1.payment_method
        FROM payments p1
        INNER JOIN (
            SELECT customer_id, MAX(payment_date) as last_payment_date
            FROM payments 
            WHERE notes LIKE 'Payment - %' OR notes LIKE 'Payment from Savings - %'
            GROUP BY customer_id
        ) p2 ON p1.customer_id = p2.customer_id AND p1.payment_date = p2.last_payment_date
        WHERE p1.customer_id IN (
            SELECT customer_id FROM financing WHERE status = 'completed'
        )
    ");
    $last_payments_stmt->execute();
    $last_payments = $last_payments_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Organize last payments by customer
    $customer_last_payments = [];
    foreach ($last_payments as $payment) {
        $customer_last_payments[$payment['customer_id']] = [
            'amount' => $payment['amount'],
            'payment_date' => $payment['payment_date'],
            'notes' => $payment['notes'],
            'payment_method' => $payment['payment_method']
        ];
    }
    
    // Organize payments by customer, date, and payment method - MERGE SAME METHODS ON SAME DATE
    $daily_payments = [];
    $savings_payments = [];
    $savings_deposits = [];

    foreach ($all_payments as $payment) {
        $customer_id = $payment['customer_id'];
        $payment_date = $payment['payment_date'];
        $payment_method = $payment['payment_method'] ?? 'Cash';
        
        if (strpos($payment['notes'], 'Payment - ') === 0) {
            // Daily payment
            if (!isset($daily_payments[$customer_id])) {
                $daily_payments[$customer_id] = [];
            }
            
            if (!isset($daily_payments[$customer_id][$payment_date])) {
                $daily_payments[$customer_id][$payment_date] = [];
            }
            
            if (!isset($daily_payments[$customer_id][$payment_date][$payment_method])) {
                $daily_payments[$customer_id][$payment_date][$payment_method] = 0;
            }
            
            // Sum amounts for same payment method on same date
            $daily_payments[$customer_id][$payment_date][$payment_method] += $payment['amount'];
        } elseif (strpos($payment['notes'], 'Payment from Savings - ') === 0) {
            // Savings payment
            if (!isset($savings_payments[$customer_id])) {
                $savings_payments[$customer_id] = [];
            }
            
            if (!isset($savings_payments[$customer_id][$payment_date])) {
                $savings_payments[$customer_id][$payment_date] = [];
            }
            
            if (!isset($savings_payments[$customer_id][$payment_date][$payment_method])) {
                $savings_payments[$customer_id][$payment_date][$payment_method] = 0;
            }
            
            // Sum amounts for same payment method on same date
            $savings_payments[$customer_id][$payment_date][$payment_method] += $payment['amount'];
        }
    }

    // Organize savings deposits by customer, date, and payment method
    foreach ($all_savings_deposits as $deposit) {
        $customer_id = $deposit['customer_id'];
        $transaction_date = $deposit['transaction_date'];
        $payment_method = $deposit['payment_method'] ?? 'Cash';
        
        if (!isset($savings_deposits[$customer_id])) {
            $savings_deposits[$customer_id] = [];
        }
        
        if (!isset($savings_deposits[$customer_id][$transaction_date])) {
            $savings_deposits[$customer_id][$transaction_date] = [];
        }
        
        if (!isset($savings_deposits[$customer_id][$transaction_date][$payment_method])) {
            $savings_deposits[$customer_id][$transaction_date][$payment_method] = 0;
        }
        
        // Sum amounts for same payment method on same date
        $savings_deposits[$customer_id][$transaction_date][$payment_method] += $deposit['amount'];
    }
    
    // UPDATED: Filter customers with missed payments - WITH NET CALCULATION (Underpay - Advance)
    if ($show_missed_payments) {
        // Generate date range for checking missed payments - INCLUSIVE of end date
        $start_datetime = new DateTime($start_date);
        $end_datetime = new DateTime($end_date);
        
        // Calculate ALL dates in the range (including both start and end dates)
        $all_dates_in_range = [];
        $current = clone $start_datetime;
        
        while ($current <= $end_datetime) {
            $all_dates_in_range[] = $current->format('Y-m-d');
            $current->modify('+1 day');
        }
        
        // Function to filter customers with missed payments - WITH NET CALCULATION
        $filterMissedPayments = function(&$customer_array) use ($all_dates_in_range, $daily_payments, $savings_payments) {
            $filtered = [];
            
            foreach ($customer_array as $customer) {
                $customer_id = $customer['id'];
                $has_missed_payment = false;
                $missed_dates = [];
                $paid_dates = [];
                $daily_amount = $customer['daily_payment'];
                $total_net_difference = 0; // Positive = Underpay, Negative = Advance
                
                // Count expected payment days (Monday-Saturday, exclude only Sunday)
                $expected_payment_dates = [];
                foreach ($all_dates_in_range as $date) {
                    $day_of_week = date('N', strtotime($date));
                    if ($day_of_week != 7) { // Exclude only Sunday (7)
                        $expected_payment_dates[] = $date;
                    }
                }
                
                // Check each expected date
                foreach ($expected_payment_dates as $expected_date) {
                    $has_daily_payment = isset($daily_payments[$customer_id][$expected_date]);
                    $has_savings_payment = isset($savings_payments[$customer_id][$expected_date]);
                    $payment_total = 0;
                    
                    if ($has_daily_payment) {
                        foreach ($daily_payments[$customer_id][$expected_date] as $method => $amount) {
                            $payment_total += $amount;
                        }
                    }
                    if ($has_savings_payment) {
                        foreach ($savings_payments[$customer_id][$expected_date] as $method => $amount) {
                            $payment_total += $amount;
                        }
                    }
                    
                    // Calculate net difference for this day: (Daily Amount - Payment Total)
                    // Positive = Underpay (paid less), Negative = Advance (paid more)
                    $net_difference = $daily_amount - $payment_total;
                    $total_net_difference += $net_difference;
                    
                    if ($payment_total > 0) {
                        $paid_dates[] = $expected_date;
                    } else {
                        $missed_dates[] = $expected_date;
                        $has_missed_payment = true;
                    }
                }
                
                // Only include customers who have at least one missed payment
                if ($has_missed_payment) {
                    $customer['missed_payment_count'] = count($missed_dates);
                    $customer['paid_payment_count'] = count($paid_dates);
                    $customer['expected_payment_days'] = count($expected_payment_dates);
                    $customer['missed_dates'] = $missed_dates;
                    $customer['paid_dates'] = $paid_dates;
                    $customer['total_net_difference'] = $total_net_difference;
                    $filtered[] = $customer;
                }
            }
            
            return $filtered;
        };
        
        // Apply missed payments filter to each customer category
        $active_customers_filtered = $filterMissedPayments($customers);
        
        // Replace original customers array with filtered one
        $customers = $active_customers_filtered;
    }
    
    // Separate customers into Active, Past Due, and Completed based on loan status and end_date
    // FIX: Customers with 0 balance (or <= 0) are ALWAYS classified as Active,
    // even if their end_date has already passed. This prevents "0 balance"
    // customers from showing up in the Past Due section.
    $active_customers = [];
    $past_due_customers = [];
    $completed_customers = [];
    $current_date = date('Y-m-d');

    foreach ($customers as $customer) {
        if ($customer['loan_status'] == 'completed') {
            $completed_customers[] = $customer;
        } elseif (empty($customer['end_date']) || $customer['end_date'] >= $current_date) {
            // Not yet past due date -> Active
            $active_customers[] = $customer;
        } elseif (isset($customer['balance']) && $customer['balance'] <= 0) {
            // Past due date BUT zero/negative balance -> still show under Active
            $active_customers[] = $customer;
        } else {
            // Past due date AND has remaining balance -> Past Due
            $past_due_customers[] = $customer;
        }
    }
    
    // Group customers by area
    $customers_by_area = [];

    // First get all areas from the customers
    $all_areas = array_unique(array_column($customers, 'area'));
    $all_areas = array_filter($all_areas); // Remove empty values
    if (!empty($all_areas)) {
        sort($all_areas);
    }

    // If a specific area is selected, only show that area
    if (!empty($selected_area)) {
        $all_areas = [$selected_area];
    }

    // Organize active, past due, and completed customers by area
    if (!empty($all_areas)) {
        foreach ($all_areas as $area) {
            $customers_by_area[$area] = [
                'active' => [],
                'past_due' => [],
                'completed' => []
            ];
            
            foreach ($active_customers as $customer) {
                if ($customer['area'] == $area) {
                    $customers_by_area[$area]['active'][] = $customer;
                }
            }
            
            foreach ($past_due_customers as $customer) {
                if ($customer['area'] == $area) {
                    $customers_by_area[$area]['past_due'][] = $customer;
                }
            }
            
            foreach ($completed_customers as $customer) {
                if ($customer['area'] == $area) {
                    $customers_by_area[$area]['completed'][] = $customer;
                }
            }
        }
    }

    // Generate date range for headers
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    $interval = new DateInterval('P1D');
    $date_range = new DatePeriod($start, $interval, $end->modify('+1 day'));

    $date_headers = [];
    foreach ($date_range as $date) {
        $date_headers[] = $date->format('Y-m-d');
    }

} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Function to format name as "Last Name, First Name Middle Name Suffix"
function formatName($customer) {
    $lastName = htmlspecialchars($customer['last_name']);
    $firstName = htmlspecialchars($customer['first_name']);
    $middleName = !empty($customer['middle_name']) ? ' ' . htmlspecialchars($customer['middle_name']) : '';
    
    // Only include suffix if it's not empty and not "none"
    $suffix = '';
    if (!empty($customer['suffix']) && strtolower($customer['suffix']) !== 'none') {
        $suffix = ' ' . htmlspecialchars($customer['suffix']);
    }
    
    return trim("$lastName, $firstName$middleName$suffix");
}

// Function to format number without .00
function formatNumber($number) {
    if (empty($number) || $number == 0) {
        return '0';
    }
    
    // Check if the number has decimal places
    if (is_numeric($number)) {
        $number = (float)$number;
        if (floor($number) == $number) {
            // It's a whole number
            return number_format($number, 0);
        } else {
            // It has decimal places
            return number_format($number, 2);
        }
    }
    
    return $number;
}

// Function to get CSS class based on payment method for TEXT COLOR
function getPaymentMethodClass($method) {
    $method = strtolower(trim($method));
    
    switch ($method) {
        case 'cash':
            return 'text-cash';
        case 'gcash':
            return 'text-gcash';
        case 'bank transfer':
            return 'text-bank';
        case 'check':
            return 'text-check';
        default:
            return 'text-other';
    }
}

// Function to get payment display name
function getPaymentMethodDisplayName($method) {
    $method = strtolower(trim($method));
    
    switch ($method) {
        case 'cash':
            return 'Cash';
        case 'gcash':
            return 'GCash';
        case 'bank transfer':
            return 'Bank Transfer';
        case 'check':
            return 'Check';
        default:
            return ucfirst($method);
    }
}

// Function to format payment display with merged amounts by method
function formatPaymentDisplay($payments_by_method) {
    if (empty($payments_by_method)) {
        return null;
    }
    
    // Check if it's a simple numeric value (old format - should not happen now with the fix)
    if (is_numeric($payments_by_method)) {
        // For backward compatibility, use Cash color
        return [
            'html' => '<span class="text-cash">' . formatNumber($payments_by_method) . '</span>',
            'total_amount' => $payments_by_method,
            'multiple' => false
        ];
    }
    
    // If it's an array of payment methods
    if (is_array($payments_by_method)) {
        $display_parts = [];
        $total_amount = 0;
        
        foreach ($payments_by_method as $method => $amount) {
            if ($amount > 0) {
                $formatted_amount = formatNumber($amount);
                $total_amount += $amount;
                
                // Wrap amount in span with appropriate color class
                $display_parts[] = '<span class="' . getPaymentMethodClass($method) . '">' . $formatted_amount . '</span>';
            }
        }
        
        // Count how many different payment methods we have
        $method_count = count($display_parts);
        
        // If only one payment method, return just the formatted amount
        if ($method_count == 1) {
            return [
                'html' => $display_parts[0],
                'total_amount' => $total_amount,
                'multiple' => false
            ];
        } else if ($method_count > 1) {
            // Multiple payment methods - join with "+" symbol
            return [
                'html' => implode('<span style="color: #000 !important; font-weight: normal; margin: 0 2px;">+</span>', $display_parts),
                'total_amount' => $total_amount,
                'multiple' => true
            ];
        }
    }
    
    return null;
}

// Function to get payment display for a specific customer and date
function getPaymentDisplay($customer_id, $date, $payments_array) {
    if (isset($payments_array[$customer_id][$date])) {
        $payments = $payments_array[$customer_id][$date];
        return formatPaymentDisplay($payments);
    }
    
    return null;
}

// Function to get last payment info for completed loans
function getLastPaymentInfo($customer_id, $last_payments_array) {
    if (isset($last_payments_array[$customer_id])) {
        $payment = $last_payments_array[$customer_id];
        return [
            'amount' => formatNumber($payment['amount']),
            'date' => date('m/d/Y', strtotime($payment['payment_date'])),
            'method' => $payment['payment_method'] ?? 'Cash'
        ];
    }
    return null;
}

// Function to calculate total payments
function calculateTotalPayments($customers, $payments_array) {
    $total = 0;
    foreach ($customers as $customer) {
        $customer_id = $customer['id'];
        if (isset($payments_array[$customer_id])) {
            foreach ($payments_array[$customer_id] as $date_payments) {
                if (is_array($date_payments)) {
                    foreach ($date_payments as $method => $amount) {
                        if (is_numeric($amount)) {
                            $total += $amount;
                        }
                    }
                }
            }
        }
    }
    return formatNumber($total);
}

// Function to calculate total balance
function calculateTotalBalance($customers) {
    $total = 0;
    foreach ($customers as $customer) {
        if (!empty($customer['balance'])) {
            $total += $customer['balance'];
        }
    }
    return formatNumber($total);
}

// Function to calculate total daily payments
function calculateTotalDaily($customers) {
    $total = 0;
    foreach ($customers as $customer) {
        if (!empty($customer['daily_payment'])) {
            $total += $customer['daily_payment'];
        }
    }
    return formatNumber($total);
}

// Function to calculate column totals for Daily, Savings, Savings Pay for a specific customer group
function calculateColumnTotalsForGroup($customers, $date_headers, $daily_payments, $savings_deposits, $savings_payments) {
    $column_totals = [];
    
    // Initialize totals array
    foreach ($date_headers as $date) {
        $column_totals[$date] = [
            'daily' => 0,
            'savings' => 0,
            'savings_pay' => 0
        ];
    }
    
    // Calculate totals from all customers in the group
    foreach ($customers as $customer) {
        $customer_id = $customer['id'];
        
        foreach ($date_headers as $date) {
            // Daily payments total
            if (isset($daily_payments[$customer_id][$date])) {
                $daily_amount = 0;
                if (is_array($daily_payments[$customer_id][$date])) {
                    foreach ($daily_payments[$customer_id][$date] as $method => $amount) {
                        $daily_amount += $amount;
                    }
                }
                $column_totals[$date]['daily'] += $daily_amount;
            }
            
            // Savings deposits total
            if (isset($savings_deposits[$customer_id][$date])) {
                $savings_amount = 0;
                if (is_array($savings_deposits[$customer_id][$date])) {
                    foreach ($savings_deposits[$customer_id][$date] as $method => $amount) {
                        $savings_amount += $amount;
                    }
                }
                $column_totals[$date]['savings'] += $savings_amount;
            }
            
            // Savings payments total
            if (isset($savings_payments[$customer_id][$date])) {
                $savings_pay_amount = 0;
                if (is_array($savings_payments[$customer_id][$date])) {
                    foreach ($savings_payments[$customer_id][$date] as $method => $amount) {
                        $savings_pay_amount += $amount;
                    }
                }
                $column_totals[$date]['savings_pay'] += $savings_pay_amount;
            }
        }
    }
    
    return $column_totals;
}

// Function to calculate category totals for each status
function calculateCategoryTotals($customers, $date_headers, $daily_payments, $savings_deposits, $savings_payments) {
    $category_totals = [
        'daily' => 0,
        'savings' => 0,
        'savings_pay' => 0
    ];
    
    foreach ($customers as $customer) {
        $customer_id = $customer['id'];
        
        foreach ($date_headers as $date) {
            // Daily payments total
            if (isset($daily_payments[$customer_id][$date])) {
                $daily_amount = 0;
                if (is_array($daily_payments[$customer_id][$date])) {
                    foreach ($daily_payments[$customer_id][$date] as $method => $amount) {
                        $daily_amount += $amount;
                    }
                }
                $category_totals['daily'] += $daily_amount;
            }
            
            // Savings deposits total
            if (isset($savings_deposits[$customer_id][$date])) {
                $savings_amount = 0;
                if (is_array($savings_deposits[$customer_id][$date])) {
                    foreach ($savings_deposits[$customer_id][$date] as $method => $amount) {
                        $savings_amount += $amount;
                    }
                }
                $category_totals['savings'] += $savings_amount;
            }
            
            // Savings payments total
            if (isset($savings_payments[$customer_id][$date])) {
                $savings_pay_amount = 0;
                if (is_array($savings_payments[$customer_id][$date])) {
                    foreach ($savings_payments[$customer_id][$date] as $method => $amount) {
                        $savings_pay_amount += $amount;
                    }
                }
                $category_totals['savings_pay'] += $savings_pay_amount;
            }
        }
    }
    
    return $category_totals;
}

// UPDATED: Display missed payment information with NET calculation and COLOR BASED ON PERCENTAGE
function displayMissedPaymentInfo($customer) {
    if (isset($customer['missed_payment_count']) && isset($customer['expected_payment_days'])) {
        $missed = $customer['missed_payment_count'];
        $expected = $customer['expected_payment_days'];
        $paid = $customer['paid_payment_count'];
        $percentage = ($expected > 0) ? round(($missed / $expected) * 100, 1) : 0;
        
        // Color logic based on percentage
        if ($percentage >= 100) {
            $color_class = 'text-danger'; // Red for 100% missed
            $color_style = 'color: #dc3545;';
        } elseif ($percentage >= 50) {
            $color_class = 'text-warning'; // Orange/Warning for 50%+
            $color_style = 'color: #fd7e14;';
        } else {
            $color_class = 'text-info'; // Blue for less than 50%
            $color_style = 'color: #0dcaf0;';
        }
        
        $html = '<div class="missed-payment-info-pdf" style="font-size: 8px; font-style: italic; ' . $color_style . '">';
        $html .= 'Missed ' . $missed . ' of ' . $expected . ' days (' . $percentage . '%)';
        
        // Calculate net difference: Positive = Underpay, Negative = Advance
        if (isset($customer['total_net_difference'])) {
            $net_diff = $customer['total_net_difference'];
            
            if ($net_diff > 0) {
                $html .= '<br><span style="color: #dc3545;">Underpay: ' . formatNumber($net_diff) . '</span>';
            } elseif ($net_diff < 0) {
                $html .= '<br><span style="color: #28a745;">Advance: ' . formatNumber(abs($net_diff)) . '</span>';
            }
        }
        
        $html .= '</div>';
        return $html;
    }
    return '';
}

// Get filter description for due date
function getDueDateFilterDescription($filter, $custom_date = '') {
    switch ($filter) {
        case 'past_due':
            return 'Past Due Loans';
        case 'today':
            return 'Due Today';
        case 'future':
            return 'Future Due Dates';
        case 'this_week':
            return 'Due This Week';
        case 'next_week':
            return 'Due Next Week';
        case 'this_month':
            return 'Due This Month';
        case 'custom':
            return 'Due on ' . date('F j, Y', strtotime($custom_date));
        default:
            return 'All Due Dates';
    }
}

// Get filter description for missed payments
function getMissedPaymentDescription($show_missed) {
    if ($show_missed) {
        return 'With Missed Payments (Net: Daily Required - Total Paid)';
    }
    return '';
}

// Check if DOMPDF is installed
$dompdf_installed = false;
if (file_exists('vendor/autoload.php')) {
    require_once 'vendor/autoload.php';
    $dompdf_installed = true;
} elseif (file_exists('dompdf/autoload.inc.php')) {
    require_once 'dompdf/autoload.inc.php';
    $dompdf_installed = true;
} elseif (file_exists('dompdf/dompdf.php')) {
    require_once 'dompdf/dompdf.php';
    $dompdf_installed = true;
}

if (!$dompdf_installed) {
    die("DOMPDF library not found. Please install it via composer: composer require dompdf/dompdf");
}

use Dompdf\Dompdf;
use Dompdf\Options;

// Create PDF options
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'Arial');

// Initialize DOMPDF
$dompdf = new Dompdf($options);

// ============================================================
// LOGO IMPLEMENTATION (UPDATED)
// ============================================================
// Try multiple possible logo filenames/formats, with PrimeLogo.png as primary
// (matches payment_history.php). Falls back to .jpg and .jpeg for compatibility.
// ============================================================
$logo_html = '';
$logo_candidates = [
    'assets/images/prime-logo-row.png'  => 'image/png',
];

$logo_found_path = null;
$logo_mime_type = 'image/png';

foreach ($logo_candidates as $relative_path => $mime) {
    $absolute_path = __DIR__ . '/' . $relative_path;
    if (file_exists($absolute_path)) {
        $logo_found_path = $absolute_path;
        $logo_mime_type = $mime;
        break;
    }
}

if ($logo_found_path !== null) {
    // Embed logo as base64 data URI so DOMPDF doesn't need remote access
    $logo_data = base64_encode(file_get_contents($logo_found_path));
    $logo_html = '<img src="data:' . $logo_mime_type . ';base64,' . $logo_data . '" '
               . 'alt="Prime City Logo" class="logo" '
               . 'style="max-width: 250px; margin-bottom: 5px; display: block; margin-left: auto; margin-right: auto;">';
} else {
    // Log missing logo so admin can investigate (won't break the PDF)
    error_log('print_payment_history.php: PrimeLogo image not found in assets/images/ directory. '
            . 'Expected one of: ' . implode(', ', array_keys($logo_candidates)));
}
// ============================================================

// Create HTML content for PDF
$html = '
<!DOCTYPE html>
<html>
<head>
    <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
    <meta charset="UTF-8">
    <title>Payment History - All Customers</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 0.2in;
        }
        
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            font-size: 8px;
            line-height: 1.2;
        }
        
        .container {
            width: 100%;
            margin: 0;
            padding: 0;
        }
        
        .header {
            text-align: center;
            margin-bottom: 5px;
            padding: 0;
        }
        
        .header h2 {
            margin-bottom: -8px;
            font-weight: bold;
            font-size: 20px;
        }
        
        .header p {
            margin-bottom: -6px;
            font-size: 12px;
        }
        
        .filter-info {
            background-color: #f8f9fa;
            padding: 5px;
            margin: 5px 0;
            border-radius: 3px;
            font-size: 9px;
            text-align: center;
            border-left: 4px solid #007bff;
        }
        
        .payment-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 5px;
            font-size: 7px;
            table-layout: fixed;
        }
        
        .payment-table th, .payment-table td {
            border: 1px solid #000;
            padding: 2px;
            text-align: center;
            word-wrap: break-word;
            overflow: hidden;
        }
        
        .payment-table th {
            background-color: #f2f2f2;
            font-weight: bold;
            font-size: 8px;
        }
        
        .payment-table td {
            font-size: 10px;
        }
        
        /* Payment method TEXT COLOR classes */
        .text-cash {
            color: #000000 !important;
            font-weight: bold;
        }
        
        .text-gcash {
            color: #17a2b8 !important;
            font-weight: bold;
        }
        
        .text-bank {
            color: #28a745 !important;
            font-weight: bold;
        }
        
        .text-check {
            color: #8b0000 !important;
            font-weight: bold;
        }
        
        .text-other {
            color: #6c757d !important;
            font-weight: bold;
        }
        
        /* For multiple payments */
        .multiple-payment {
            font-size: 9px;
            line-height: 1.1;
        }
        
        .totals-row {
            font-weight: bold;
            margin-bottom: 10px;
            padding: 3px;
            border: 1px solid #ddd;
            background-color: #f9f9f9;
            text-align: center;
            font-size: 8px;
        }
        
        .text-left {
            text-align: left !important;
        }
        
        .text-right {
            text-align: right !important;
        }
        
        .signature-line {
            border-top: 2px solid #000;
            width: 300px;
            display: inline-block;
            margin-top: 20px;
        }
        
        .signature-container {
            margin-top: 20px;
            text-align: center;
            font-size: 12px;
        }
        
        .customer-row {
            page-break-inside: avoid;
        }
        
        .status-header {
            background-color: #e9ecef;
            padding: 5px;
            margin: 30px 0 0 0;
            border-radius: 3px;
            font-weight: bold;
            font-size: 9px;
        }
        
        .active-header {
            background-color: #d4edda;
        }
        
        .past-due-header {
            background-color: #f8d7da;
        }
        
        .completed-header {
            background-color: #cce7ff;
        }
        
        .area-section {
            margin-bottom: 20px;
        }
        
        .logo {
            max-width: 70px;
            margin-bottom: 3px;
        }
        
        /* Column width adjustments for A4 landscape */
        .col-no { width: 2%; }
        .col-name { width: 12%; }
        .col-due-date { width: 5%; }
        .col-balance { width: 5%; }
        .col-daily { width: 4%; }
        .col-date-group { width: 6%; }
        .col-payment { width: 3%; }
        .col-last-payment { width: 6%; }
        .col-total { width: 6%; }
        
        .totals-table {
            width: 100%;
            border-collapse: collapse;
            margin: 3px 0;
        }
        
        .totals-table td {
            border: 1px solid #000;
            padding: 2px;
            text-align: center;
            font-weight: bold;
            font-size: 10px;
        }
        
        .blank-rows {
            height: 20px;
        }
        
        .total-row {
            font-weight: bold;
            background-color: #f0f0f0;
        }
        
        .column-total {
            font-weight: bold;
            background-color: #e8f4f8;
        }
        
        /* Add page break before each area section except the first one */
        .area-section:not(:first-child) {
            page-break-before: always;
        }
        
        /* Payment method color boxes for legend */
        .color-box {
            display: inline-block;
            width: 10px;
            height: 10px;
            margin-right: 3px;
            vertical-align: middle;
            border: 1px solid #ccc;
        }
        
        .color-cash { background-color: #000000; }
        .color-gcash { background-color: #17a2b8; }
        .color-bank { background-color: #28a745; }
        .color-check { background-color: #8b0000; }
        .color-other { background-color: #6c757d; }
        
        /* Legend styling */
        .legend-container {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 5px;
            padding: 6px;
            background-color: #ddddddff;
            border-radius: 5px;
            font-size: 9px;
            flex-wrap: wrap;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
        }
        
        /* Missed payment info in PDF */
        .missed-payment-info-pdf {
            font-size: 7px !important;
            font-style: italic;
            margin-top: 2px;
        }
        
        /* Color classes for missed payment text */
        .text-danger {
            color: #dc3545 !important;
        }
        
        .text-warning {
            color: #fd7e14 !important;
        }
        
        .text-info {
            color: #0dcaf0 !important;
        }
        
    </style>
</head>
<body>
    <div class="container">';

// Track if this is the first area to avoid page break before it
$first_area = true;

// Get filter descriptions
$due_date_filter_desc = getDueDateFilterDescription($due_date_filter, $custom_due_date);
$missed_payment_desc = getMissedPaymentDescription($show_missed_payments);

// Check if there are customers to display
if (!empty($customers_by_area)) {
    foreach ($customers_by_area as $area => $customer_types) {
        if (!$first_area) {
            $html .= '<div class="area-section">';
        } else {
            $html .= '<div class="area-section">';
            $first_area = false;
        }
        
        // Active Customers for this area
        if (!empty($customer_types['active'])) {
            // Calculate column totals for active customers
            $active_column_totals = calculateColumnTotalsForGroup($customer_types['active'], $date_headers, $daily_payments, $savings_deposits, $savings_payments);
            $active_category_totals = calculateCategoryTotals($customer_types['active'], $date_headers, $daily_payments, $savings_deposits, $savings_payments);
            
            $html .= '<div class="customer-row">
                <div class="header">
                    ' . $logo_html . '
                    <p>' . htmlspecialchars($area) . '</p>';
                    
            if (!empty($selected_branch) && $selected_branch != 'all') {
                $html .= '<p>Branch: ' . htmlspecialchars($selected_branch) . '</p>';
            } elseif ($selected_branch == 'all') {
                $html .= '<p>Branch: All Branches</p>';
            } else {
                $html .= '<p>Branch: ' . htmlspecialchars($selected_branch) . '</p>';
            }
            
            $html .= '<p>' . date('F j, Y', strtotime($start_date)) . ' to ' . date('F j, Y', strtotime($end_date)) . '</p>';
            
            // Display filter information
            if (!empty($due_date_filter) || !empty($show_missed_payments)) {
                $html .= '<div class="filter-info">';
                $html .= '<strong>Filters Applied:</strong> ';
                $filters = [];
                if (!empty($due_date_filter)) {
                    $filters[] = $due_date_filter_desc;
                }
                if (!empty($show_missed_payments)) {
                    $filters[] = $missed_payment_desc;
                }
                $html .= implode(' | ', $filters);
                $html .= '</div>';
            }
            
            $html .= '<div class="status-header active-header">
                        ACTIVE CUSTOMERS - AREA: ' . htmlspecialchars($area) . '
                    </div>

                    <!-- Payment Method Legend -->
                    <div class="legend-container">
                        <div class="legend-item">
                            <span class="color-box color-cash"></span> Cash
                            <span class="color-box color-gcash"></span> GCash
                            <span class="color-box color-bank"></span> Bank Transfer
                            <span class="color-box color-check"></span> Check
                            <span class="color-box color-other"></span> Others
                        </div>
                    </div>

                </div>

                <table class="payment-table">
                    <thead>
                        <tr>
                            <th rowspan="2" class="col-no">No.</th>
                            <th rowspan="2" class="col-name">Names</th>
                            <th rowspan="2" class="col-due-date">Due Date</th>
                            <th rowspan="2" class="col-balance">Balance</th>
                            <th rowspan="2" class="col-daily">Daily</th>';
            
            foreach ($date_headers as $date) {
                $html .= '<th colspan="3" class="col-date-group">' . date('d', strtotime($date)) . '</th>';
            }
            
            $html .= '</tr>
                    <tr>';
            
            foreach ($date_headers as $date) {
                $html .= '<th class="col-payment">Daily</th>
                         <th class="col-payment">Savings</th>
                         <th class="col-payment">Savings Pay</th>';
            }
            
            $html .= '</tr>
                </thead>
                <tbody>';
            
            $counter = 1;
            foreach ($customer_types['active'] as $customer) {
                $balance = !empty($customer['balance']) ? $customer['balance'] : 0;
                $daily_payment = !empty($customer['daily_payment']) ? $customer['daily_payment'] : 0;
                
                $html .= '<tr>
                    <td>' . $counter++ . '</td>
                    <td class="text-left">' . formatName($customer);
                
                // Display missed payment info in PDF with NET calculation and color based on percentage
                if ($show_missed_payments && isset($customer['missed_payment_count'])) {
                    $html .= displayMissedPaymentInfo($customer);
                }
                
                $html .= '</td>
                    <td>' . (!empty($customer['end_date']) ? date('m/d/Y', strtotime($customer['end_date'])) : 'N/A') . '</td>
                    <td class="text-right">' . formatNumber($customer['balance']) . '</td>
                    <td>' . formatNumber($customer['daily_payment']) . '</td>';
                
                foreach ($date_headers as $date) {
                    $daily_payment_display = getPaymentDisplay($customer['id'], $date, $daily_payments);
                    $savings_deposit_display = getPaymentDisplay($customer['id'], $date, $savings_deposits);
                    $savings_payment_display = getPaymentDisplay($customer['id'], $date, $savings_payments);
                    
                    $html .= '<td class="text-right ' . ($daily_payment_display && $daily_payment_display['multiple'] ? 'multiple-payment' : '') . '">';
                    if ($daily_payment_display) {
                        $html .= $daily_payment_display['html'];
                    }
                    $html .= '</td>';
                    
                    $html .= '<td class="text-right ' . ($savings_deposit_display && $savings_deposit_display['multiple'] ? 'multiple-payment' : '') . '">';
                    if ($savings_deposit_display) {
                        $html .= $savings_deposit_display['html'];
                    }
                    $html .= '</td>';
                    
                    $html .= '<td class="text-right ' . ($savings_payment_display && $savings_payment_display['multiple'] ? 'multiple-payment' : '') . '">';
                    if ($savings_payment_display) {
                        $html .= $savings_payment_display['html'];
                    }
                    $html .= '</td>';
                }
                
                $html .= '</tr>';
            }
            
            // Add 5 blank rows for Active Customers
            for ($i = 1; $i <= 5; $i++) {
                $html .= '<tr class="blank-rows">
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>';
                
                foreach ($date_headers as $date) {
                    $html .= '<td>&nbsp;</td>
                             <td>&nbsp;</td>
                             <td>&nbsp;</td>';
                }
                
                $html .= '</tr>';
            }
            
            // Add TOTAL row for Active Customers with column totals
            $html .= '<tr class="total-row">
                    <td colspan="2" class="text-left">TOTAL</td>
                    <td>&nbsp;</td>
                    <td class="text-right">' . calculateTotalBalance($customer_types['active']) . '</td>
                    <td class="text-right">' . calculateTotalDaily($customer_types['active']) . '</td>';
            
            foreach ($date_headers as $date) {
                $html .= '<td></td>
                          <td></td>
                          <td></td>';
            }
            
            $html .= '</tr>';
            
            $html .= '</tbody>
                </table>

                <div class="totals-row">
                    <table class="totals-table">
                        <tr>
                            <td>Total Daily Payment: PHP ' . formatNumber($active_category_totals['daily']) . '</td>
                            <td>Total Savings Deposit: PHP ' . formatNumber($active_category_totals['savings']) . '</td>
                            <td>Total Savings Payment: PHP ' . formatNumber($active_category_totals['savings_pay']) . '</td>
                            <td>Total Customers Balance: PHP ' . calculateTotalBalance($customer_types['active']) . '</td>
                        </tr>
                    </table>
                </div>

                <div class="signature-container">
                    <div class="signature-line"></div>
                    <p>Collector\'s Name & Signature</p>
                </div>
            </div>';
        }

        // Past Due Customers for this area
        if (!empty($customer_types['past_due'])) {
            // Calculate column totals for past due customers
            $past_due_column_totals = calculateColumnTotalsForGroup($customer_types['past_due'], $date_headers, $daily_payments, $savings_deposits, $savings_payments);
            $past_due_category_totals = calculateCategoryTotals($customer_types['past_due'], $date_headers, $daily_payments, $savings_deposits, $savings_payments);
            
            $html .= '<div class="customer-row">
                <div class="header">
                    ' . $logo_html . '
                    <p>' . htmlspecialchars($area) . '</p>';
                    
            if (!empty($selected_branch) && $selected_branch != 'all') {
                $html .= '<p>Branch: ' . htmlspecialchars($selected_branch) . '</p>';
            } elseif ($selected_branch == 'all') {
                $html .= '<p>Branch: All Branches</p>';
            } else {
                $html .= '<p>Branch: ' . htmlspecialchars($selected_branch) . '</p>';
            }
            
            $html .= '<p>' . date('F j, Y', strtotime($start_date)) . ' to ' . date('F j, Y', strtotime($end_date)) . '</p>';
            
            // Display filter information
            if (!empty($due_date_filter) || !empty($show_missed_payments)) {
                $html .= '<div class="filter-info">';
                $html .= '<strong>Filters Applied:</strong> ';
                $filters = [];
                if (!empty($due_date_filter)) {
                    $filters[] = $due_date_filter_desc;
                }
                if (!empty($show_missed_payments)) {
                    $filters[] = $missed_payment_desc;
                }
                $html .= implode(' | ', $filters);
                $html .= '</div>';
            }
                    
            $html .= '<div class="status-header past-due-header">
                        PAST DUE CUSTOMERS - AREA: ' . htmlspecialchars($area) . '
                    </div>

                    <!-- Payment Method Legend -->
                    <div class="legend-container">
                        <div class="legend-item">
                            <span class="color-box color-cash"></span> Cash
                            <span class="color-box color-gcash"></span> GCash
                            <span class="color-box color-bank"></span> Bank Transfer
                            <span class="color-box color-check"></span> Check
                            <span class="color-box color-other"></span> Others
                        </div>
                    </div>

                </div>

                <table class="payment-table">
                    <thead>
                        <tr>
                            <th rowspan="2" class="col-no">No.</th>
                            <th rowspan="2" class="col-name">Names</th>
                            <th rowspan="2" class="col-due-date">Due Date</th>
                            <th rowspan="2" class="col-balance">Balance</th>
                            <th rowspan="2" class="col-daily">Daily</th>';
            
            foreach ($date_headers as $date) {
                $html .= '<th colspan="3" class="col-date-group">' . date('d', strtotime($date)) . '</th>';
            }
            
            $html .= '</tr>
                    <tr>';
            
            foreach ($date_headers as $date) {
                $html .= '<th class="col-payment">Daily</th>
                         <th class="col-payment">Savings</th>
                         <th class="col-payment">Savings Pay</th>';
            }
            
            $html .= '</tr>
                </thead>
                <tbody>';
            
            $counter = 1;
            foreach ($customer_types['past_due'] as $customer) {
                $balance = !empty($customer['balance']) ? $customer['balance'] : 0;
                $daily_payment = !empty($customer['daily_payment']) ? $customer['daily_payment'] : 0;
                
                $html .= '<tr>
                    <td>' . $counter++ . '</td>
                    <td class="text-left">' . formatName($customer);
                
                // Display missed payment info in PDF with NET calculation and color based on percentage
                if ($show_missed_payments && isset($customer['missed_payment_count'])) {
                    $html .= displayMissedPaymentInfo($customer);
                }
                
                $html .= '</td>
                    <td>' . (!empty($customer['end_date']) ? date('m/d/Y', strtotime($customer['end_date'])) : 'N/A') . '</td>
                    <td class="text-right">' . formatNumber($customer['balance']) . '</td>
                    <td>' . formatNumber($customer['daily_payment']) . '</td>';
                
                foreach ($date_headers as $date) {
                    $daily_payment_display = getPaymentDisplay($customer['id'], $date, $daily_payments);
                    $savings_deposit_display = getPaymentDisplay($customer['id'], $date, $savings_deposits);
                    $savings_payment_display = getPaymentDisplay($customer['id'], $date, $savings_payments);
                    
                    $html .= '<td class="text-right ' . ($daily_payment_display && $daily_payment_display['multiple'] ? 'multiple-payment' : '') . '">';
                    if ($daily_payment_display) {
                        $html .= $daily_payment_display['html'];
                    }
                    $html .= '</td>';
                    
                    $html .= '<td class="text-right ' . ($savings_deposit_display && $savings_deposit_display['multiple'] ? 'multiple-payment' : '') . '">';
                    if ($savings_deposit_display) {
                        $html .= $savings_deposit_display['html'];
                    }
                    $html .= '</td>';
                    
                    $html .= '<td class="text-right ' . ($savings_payment_display && $savings_payment_display['multiple'] ? 'multiple-payment' : '') . '">';
                    if ($savings_payment_display) {
                        $html .= $savings_payment_display['html'];
                    }
                    $html .= '</td>';
                }
                
                $html .= '</tr>';
            }
            
            // Add 1 blank row for Past Due Customers
            $html .= '<tr class="blank-rows">
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>';
            
            foreach ($date_headers as $date) {
                $html .= '<td>&nbsp;</td>
                         <td>&nbsp;</td>
                         <td>&nbsp;</td>';
            }
            
            $html .= '</tr>';
            
            // Add TOTAL row for Past Due Customers with column totals
            $html .= '<tr class="total-row">
                    <td colspan="2" class="text-left">TOTAL</td>
                    <td>&nbsp;</td>
                    <td class="text-right">' . calculateTotalBalance($customer_types['past_due']) . '</td>
                    <td class="text-right">' . calculateTotalDaily($customer_types['past_due']) . '</td>';
            
            foreach ($date_headers as $date) {
                $html .= '<td></td>
                          <td></td>
                          <td></td>';
            }
            
            $html .= '</tr>';
            
            $html .= '</tbody>
                </table>

                <div class="totals-row">
                    <table class="totals-table">
                        <tr>
                            <td>Total Daily Payment: PHP ' . formatNumber($past_due_category_totals['daily']) . '</td>
                            <td>Total Savings Deposit: PHP ' . formatNumber($past_due_category_totals['savings']) . '</td>
                            <td>Total Savings Payment: PHP ' . formatNumber($past_due_category_totals['savings_pay']) . '</td>
                            <td>Total Customers Balance: PHP ' . calculateTotalBalance($customer_types['past_due']) . '</td>
                        </tr>
                    </table>
                </div>

                <div class="signature-container">
                    <div class="signature-line"></div>
                    <p>Collector\'s Name & Signature</p>
                </div>
            </div>';
        }

        // Completed Customers for this area
        if ($show_completed && !empty($customer_types['completed'])) {
            // Calculate column totals for completed customers
            $completed_column_totals = calculateColumnTotalsForGroup($customer_types['completed'], $date_headers, $daily_payments, $savings_deposits, $savings_payments);
            $completed_category_totals = calculateCategoryTotals($customer_types['completed'], $date_headers, $daily_payments, $savings_deposits, $savings_payments);
            
            $html .= '<div class="customer-row">
                <div class="header">
                    ' . $logo_html . '
                    <p>' . htmlspecialchars($area) . '</p>';
                    
            if (!empty($selected_branch) && $selected_branch != 'all') {
                $html .= '<p>Branch: ' . htmlspecialchars($selected_branch) . '</p>';
            } elseif ($selected_branch == 'all') {
                $html .= '<p>Branch: All Branches</p>';
            } else {
                $html .= '<p>Branch: ' . htmlspecialchars($selected_branch) . '</p>';
            }
            
            $html .= '<p>' . date('F j, Y', strtotime($start_date)) . ' to ' . date('F j, Y', strtotime($end_date)) . '</p>';
            
            // Display filter information
            if (!empty($due_date_filter) || !empty($show_missed_payments)) {
                $html .= '<div class="filter-info">';
                $html .= '<strong>Filters Applied:</strong> ';
                $filters = [];
                if (!empty($due_date_filter)) {
                    $filters[] = $due_date_filter_desc;
                }
                if (!empty($show_missed_payments)) {
                    $filters[] = $missed_payment_desc;
                }
                $html .= implode(' | ', $filters);
                $html .= '</div>';
            }
                    
            $html .= '<div class="status-header completed-header">
                        COMPLETED CUSTOMERS - AREA: ' . htmlspecialchars($area) . '
                    </div>

                    <!-- Payment Method Legend -->
                    <div class="legend-container">
                        <div class="legend-item">
                            <span class="color-box color-cash"></span> Cash
                            <span class="color-box color-gcash"></span> GCash
                            <span class="color-box color-bank"></span> Bank Transfer
                            <span class="color-box color-check"></span> Check
                            <span class="color-box color-other"></span> Others
                        </div>
                    </div>
                    
                </div>

                <table class="payment-table">
                    <thead>
                        <tr>
                            <th rowspan="2" class="col-no">No.</th>
                            <th rowspan="2" class="col-name">Names</th>
                            <th rowspan="2" class="col-due-date">Due Date</th>
                            <th rowspan="2" class="col-balance">Balance</th>
                            <th rowspan="2" class="col-daily">Daily</th>
                            <th colspan="2" class="col-last-payment">Last Payment</th>';
            
            foreach ($date_headers as $date) {
                $html .= '<th colspan="3" class="col-date-group">' . date('d', strtotime($date)) . '</th>';
            }
            
            $html .= '</tr>
                    <tr>
                        <th class="col-payment">Amount</th>
                        <th class="col-payment">Date</th>';
            
            foreach ($date_headers as $date) {
                $html .= '<th class="col-payment">Daily</th>
                         <th class="col-payment">Savings</th>
                         <th class="col-payment">Savings Pay</th>';
            }
            
            $html .= '</tr>
                </thead>
                <tbody>';
            
            $counter = 1;
            foreach ($customer_types['completed'] as $customer) {
                $balance = !empty($customer['balance']) ? $customer['balance'] : 0;
                $daily_payment = !empty($customer['daily_payment']) ? $customer['daily_payment'] : 0;
                
                $html .= '<tr>
                    <td>' . $counter++ . '</td>
                    <td class="text-left">' . formatName($customer);
                
                // Display missed payment info in PDF with NET calculation and color based on percentage
                if ($show_missed_payments && isset($customer['missed_payment_count'])) {
                    $html .= displayMissedPaymentInfo($customer);
                }
                
                $html .= '</td>
                    <td>' . (!empty($customer['end_date']) ? date('m/d/Y', strtotime($customer['end_date'])) : 'N/A') . '</td>
                    <td class="text-right">' . formatNumber($customer['balance']) . '</td>
                    <td>' . formatNumber($customer['daily_payment']) . '</td>';
                
                $last_payment_info = getLastPaymentInfo($customer['id'], $customer_last_payments);
                if ($last_payment_info) {
                    $html .= '<td class="text-right ' . getPaymentMethodClass($last_payment_info['method']) . '">PHP ' . $last_payment_info['amount'] . '</td>
                              <td>' . $last_payment_info['date'] . '</td>';
                } else {
                    $html .= '<td colspan="2" class="text-center">No payment data</td>';
                }
                
                foreach ($date_headers as $date) {
                    $daily_payment_display = getPaymentDisplay($customer['id'], $date, $daily_payments);
                    $savings_deposit_display = getPaymentDisplay($customer['id'], $date, $savings_deposits);
                    $savings_payment_display = getPaymentDisplay($customer['id'], $date, $savings_payments);
                    
                    $html .= '<td class="text-right ' . ($daily_payment_display && $daily_payment_display['multiple'] ? 'multiple-payment' : '') . '">';
                    if ($daily_payment_display) {
                        $html .= $daily_payment_display['html'];
                    }
                    $html .= '</td>';
                    
                    $html .= '<td class="text-right ' . ($savings_deposit_display && $savings_deposit_display['multiple'] ? 'multiple-payment' : '') . '">';
                    if ($savings_deposit_display) {
                        $html .= $savings_deposit_display['html'];
                    }
                    $html .= '</td>';
                    
                    $html .= '<td class="text-right ' . ($savings_payment_display && $savings_payment_display['multiple'] ? 'multiple-payment' : '') . '">';
                    if ($savings_payment_display) {
                        $html .= $savings_payment_display['html'];
                    }
                    $html .= '</td>';
                }
                
                $html .= '</tr>';
            }
            
            // Add TOTAL row for Completed Customers with column totals
            $html .= '<tr class="total-row">
                    <td colspan="2" class="text-left">TOTAL</td>
                    <td>&nbsp;</td>
                    <td class="text-right">' . calculateTotalBalance($customer_types['completed']) . '</td>
                    <td class="text-right">' . calculateTotalDaily($customer_types['completed']) . '</td>
                    <td colspan="2">&nbsp;</td>';
            
            foreach ($date_headers as $date) {
                $html .= '<td></td>
                          <td></td>
                          <td></td>';
            }
            
            $html .= '</tr>';
            
            $html .= '</tbody>
                </table>

                <div class="totals-row">
                    <table class="totals-table">
                        <tr>
                            <td>Total Daily Payment: PHP ' . formatNumber($completed_category_totals['daily']) . '</td>
                            <td>Total Savings Deposit: PHP ' . formatNumber($completed_category_totals['savings']) . '</td>
                            <td>Total Savings Payment: PHP ' . formatNumber($completed_category_totals['savings_pay']) . '</td>
                            <td>Total Customers Balance: PHP ' . calculateTotalBalance($customer_types['completed']) . '</td>
                        </tr>
                    </table>
                </div>

                <div class="signature-container">
                    <div class="signature-line"></div>
                    <p>Collector\'s Name & Signature</p>
                </div>
            </div>';
        }
        
        $html .= '</div>'; // Close area-section
    }
} else {
    // No customers found
    $html .= '<div style="text-align: center; padding: 50px;">
                <h3>No customers found for the selected filters.</h3>
              </div>';
}

$html .= '
    </div>
</body>
</html>';

// Load HTML content
$dompdf->loadHtml($html);

// Set paper size and orientation
$dompdf->setPaper('A4', 'landscape');

// Render PDF
$dompdf->render();

// Generate filename with timestamp
$filename = 'payment_history_' . date('Y-m-d_H-i-s') . '.pdf';

// Output PDF to browser
$dompdf->stream($filename, array("Attachment" => false));
?>
