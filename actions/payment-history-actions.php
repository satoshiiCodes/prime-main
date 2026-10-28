<?php
/**
 * Prime City - Payment history actions.
 *
 * Server-side logic for payment_history.php: the signed-in user, the branch/
 * area/due-date filters, every customer query, the missed-payment pass and the
 * view helpers the report template calls.
 *
 * Produces no markup - the page includes it and renders.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Start the session once.
 *
 * @return void
 */
function ph_start_session()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * Redirect, falling back to JS when headers are already sent.
 *
 * @param  string $location Target path.
 * @return void
 */
function ph_redirect($location)
{
    if (!headers_sent()) {
        header('Location: ' . $location);
        return;
    }

    echo '<script>window.location.href = ' . json_encode($location) . ';</script>';
}

/**
 * Destroy the session and the remember-me cookie.
 *
 * @return void
 */
function ph_sign_out()
{
    ph_start_session();

    $_SESSION = array();

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_unset();
    session_destroy();
    setcookie('remember_me', '', time() - 3600, '/');
}

/**
 * Honour `?logout=true`.
 *
 * @return void
 */
function ph_handle_logout()
{
    if (!isset($_GET['logout'])) {
        return;
    }

    ph_sign_out();
    ph_redirect('index.php');
    exit;
}

// Function to format name as "Last Name, First Name Middle Name Suffix"
function formatName($customer) {
    $lastName = htmlspecialchars($customer['last_name']);
    $firstName = htmlspecialchars($customer['first_name']);
    $middleName = !empty($customer['middle_name']) ? ' ' . htmlspecialchars($customer['middle_name']) : '';
    
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
    
    if (is_numeric($number)) {
        $number = (float)$number;
        if (floor($number) == $number) {
            return number_format($number, 0);
        } else {
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

// Format payment display with merged amounts by method
function formatPaymentDisplay($payments_by_method) {
    if (empty($payments_by_method)) {
        return null;
    }
    
    if (is_numeric($payments_by_method)) {
        return [
            'html' => '<span class="text-cash">' . formatNumber($payments_by_method) . '</span>',
            'total_amount' => $payments_by_method,
            'multiple' => false
        ];
    }
    
    if (is_array($payments_by_method)) {
        $display_parts = [];
        $total_amount = 0;
        
        foreach ($payments_by_method as $method => $amount) {
            if ($amount > 0) {
                $formatted_amount = formatNumber($amount);
                $total_amount += $amount;
                
                $display_parts[] = '<span class="' . getPaymentMethodClass($method) . '">' . $formatted_amount . '</span>';
            }
        }
        
        $method_count = count($display_parts);
        
        if ($method_count == 1) {
            return [
                'html' => $display_parts[0],
                'total_amount' => $total_amount,
                'multiple' => false
            ];
        } else if ($method_count > 1) {
            return [
                'html' => implode('<span class="plus-sign">+</span>', $display_parts),
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

// Calculate column totals for Daily, Savings, Savings Pay
function calculateColumnTotals($customers, $date_headers, $daily_payments, $savings_deposits, $savings_payments) {
    $column_totals = [];
    
    foreach ($date_headers as $date) {
        $column_totals[$date] = [
            'daily' => 0,
            'savings' => 0,
            'savings_pay' => 0
        ];
    }
    
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

// Calculate category totals for each status
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

// Display missed payment information with NET calculation (Underpay - Advance = Net Difference)
function displayMissedPaymentInfo($customer) {
    if (isset($customer['missed_payment_count']) && isset($customer['expected_payment_days'])) {
        $missed = $customer['missed_payment_count'];
        $expected = $customer['expected_payment_days'];
        $paid = $customer['paid_payment_count'];
        $percentage = ($expected > 0) ? round(($missed / $expected) * 100, 1) : 0;
        
        $color_class = '';
        if ($percentage >= 50) {
            $color_class = 'text-danger';
        } elseif ($percentage >= 25) {
            $color_class = 'text-warning';
        } else {
            $color_class = 'text-info';
        }
        
        $html = '<div class="missed-payment-info ' . $color_class . '" style="font-size: 9px; font-style: italic;">';
        $html .= 'Missed ' . $missed . ' of ' . $expected . ' days (' . $percentage . '%)';
        
        // Calculate net difference: Positive = Underpay, Negative = Advance
        if (isset($customer['total_net_difference'])) {
            $net_diff = $customer['total_net_difference'];
            
            if ($net_diff > 0) {
                $html .= '<br><span class="text-danger">Underpayment: ₱' . formatNumber($net_diff) . '</span>';
            } elseif ($net_diff < 0) {
                $html .= '<br><span class="text-success">Advance Payment: ₱' . formatNumber(abs($net_diff)) . '</span>';
            }
        }
        
        $html .= '</div>';
        return $html;
    }
    return '';
}

// Format total amount display
function formatTotalDisplay($amount) {
    return '<span class="text-right">' . formatNumber($amount) . '</span>';
}

/**
 * Everything the report view needs, in one call.
 *
 * @return array<string, mixed>
 *
 * @throws RuntimeException When the data queries fail.
 */
function ph_context()
{
    ph_start_session();
    ph_handle_logout();

    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        ph_redirect('index.php');
        exit;
    }

// Initialize user variable
$user = [];

// Get user details
try {
    $conn = db();
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    // Determine which table to query based on account source from session
    $account_source = $_SESSION['account_source'] ?? 'accounts';
    
    if ($account_source === 'accounts') {
        $stmt = $conn->prepare("SELECT first_name, middle_name, last_name, suffix, account_type, branch FROM accounts WHERE id = ?");
    } elseif ($account_source === 'collector_accounts') {
        // FOR COLLECTOR ACCOUNTS: Get additional area information
        $stmt = $conn->prepare("SELECT first_name, middle_name, last_name, suffix, account_type, branch, area FROM collector_accounts WHERE id = ?");
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
    // shared connection left open

} catch (Exception $e) {
    error_log($e->getMessage());
    // If user data can't be fetched, use session data as fallback
    $user = [
        'first_name' => $_SESSION['first_name'] ?? 'Admin',
        'middle_name' => '',
        'last_name' => '',
        'suffix' => '',
        'account_type' => $_SESSION['account_type'] ?? 'User',
        'branch' => $_SESSION['branch'] ?? '',
        'area' => $_SESSION['area'] ?? ''
    ];
}

// Initialize filter variables
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
$user_area = $user['area'] ?? ''; // For collector accounts

// Define restricted account types (single branch only)
$restricted_account_types = ['Branch Manager', 'Proxy Collector-Cashier', 'Proxy Collector', 'Collector'];

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
elseif ($user_account_type === 'Collector') {
    // For Collector accounts, only show their assigned branch and area
    if ($user_branch) {
        $available_branches[] = $user_branch;
        
        // Force selected branch to user's branch
        $selected_branch = $user_branch;
        
        // Also force selected area to user's area if available
        if ($user_area && empty($selected_area)) {
            $selected_area = $user_area;
        }
    } else {
        // Fallback if no branch assigned
        $available_branches = ['Tuguegarao City'];
        $selected_branch = 'Tuguegarao City';
    }
}
elseif (in_array($user_account_type, $restricted_account_types)) {
    // For other restricted users, only show their assigned branch
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

// Create predefined branches
$branches = [
    'Tuguegarao City',
    'Gattaran', 
    'Abulug',
    'Ilagan'
];

// Fetch all customers with their financing information and payments
try {
    $customer_conn = db_pdo();
    $customer_conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Build the base query for customers - Get customers with active OR completed loans based on filter
    // We will sort in PHP to avoid MySQL collation issues
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
    } elseif ($user_account_type === 'Collector' && !empty($user_area)) {
        // FOR COLLECTOR: If no area selected in filter but user has area, use user's area
        $customer_query .= " AND ca.area = :area";
        $selected_area = $user_area;
    }
    
    // Add due date filter if selected
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
    
    // FOR COLLECTOR: Always filter by user's area if set
    if ($user_account_type === 'Collector' && !empty($user_area) && empty($selected_area)) {
        $customer_query .= " AND ca.area = :collector_area";
    }
    
    // NOTE: We deliberately do NOT add ORDER BY here. We will sort in PHP.
    // This avoids MySQL collation and JOIN ordering issues.
    
    $stmt = $customer_conn->prepare($customer_query);
    $param_index = 1;
    
    if (!empty($selected_area)) {
        $stmt->bindParam(':area', $selected_area);
    } elseif ($user_account_type === 'Collector' && !empty($user_area)) {
        // Bind collector's area if not already bound
        $stmt->bindParam(':area', $user_area);
    }
    
    // Bind custom due date parameter if needed
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
    
    // FOR COLLECTOR: Bind collector's area if needed
    if ($user_account_type === 'Collector' && !empty($user_area) && empty($selected_area)) {
        $stmt->bindParam(':collector_area', $user_area);
    }
    
    $stmt->execute();
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ============================================================
    // INITIAL SORT: Sort all customers alphabetically by last_name, then first_name
    // This is the PRIMARY fix for the "Nera" appearing first issue.
    // We sort here BEFORE any grouping/filtering so the order is preserved.
    // ============================================================
    usort($customers, function($a, $b) {
        // Normalize names: trim whitespace, convert to lowercase for comparison
        $lastA = strtolower(trim($a['last_name'] ?? ''));
        $lastB = strtolower(trim($b['last_name'] ?? ''));
        $firstA = strtolower(trim($a['first_name'] ?? ''));
        $firstB = strtolower(trim($b['first_name'] ?? ''));
        
        // Primary sort: last name
        $cmp = strcmp($lastA, $lastB);
        if ($cmp !== 0) {
            return $cmp;
        }
        // Secondary sort: first name
        return strcmp($firstA, $firstB);
    });
    
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
    
    // Get all savings deposits for these customers for the specified date range WITH PAYMENT METHOD
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
    
    // Filter customers with missed payments
    if ($show_missed_payments) {
        // Generate date range for checking missed payments
        $start_datetime = new DateTime($start_date);
        $end_datetime = new DateTime($end_date);
        
        // Calculate ALL dates in the range
        $all_dates_in_range = [];
        $current = clone $start_datetime;
        
        while ($current <= $end_datetime) {
            $all_dates_in_range[] = $current->format('Y-m-d');
            $current->modify('+1 day');
        }
        
        // Function to filter customers with missed payments and calculate net underpay/advance
        $filterMissedPayments = function(&$customer_array) use ($all_dates_in_range, $daily_payments, $savings_payments) {
            $filtered = [];
            
            foreach ($customer_array as $customer) {
                $customer_id = $customer['id'];
                $has_missed_payment = false;
                $missed_dates = [];
                $paid_dates = [];
                $daily_amount = $customer['daily_payment'];
                $total_net_difference = 0; // Positive = underpay, Negative = advance
                
                // Count expected payment days (Monday-Saturday, exclude only Sunday)
                $expected_payment_dates = [];
                foreach ($all_dates_in_range as $date) {
                    $day_of_week = date('N', strtotime($date));
                    if ($day_of_week != 7) {
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
        if ($show_missed_payments) {
            $active_customers = $filterMissedPayments($active_customers);
            $past_due_customers = $filterMissedPayments($past_due_customers);
            
            // For completed customers, we might want to show those who had missed payments before completion
            if ($show_completed) {
                $completed_customers = $filterMissedPayments($completed_customers);
            }
        }
    }
    
    // Group customers by area
    $customers_by_area = [];

    // First get all areas from the customers
    $all_areas = array_unique(array_column($customers, 'area'));
    $all_areas = array_filter($all_areas);
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

    // ============================================================
    // FINAL SORT: Re-sort each category within each area to guarantee
    // alphabetical order is preserved even after array_filter/usort operations.
    // This is the definitive fix.
    // ============================================================
    foreach ($customers_by_area as $area => &$customer_types) {
        $sortByName = function($a, $b) {
            $lastA = strtolower(trim($a['last_name'] ?? ''));
            $lastB = strtolower(trim($b['last_name'] ?? ''));
            $firstA = strtolower(trim($a['first_name'] ?? ''));
            $firstB = strtolower(trim($b['first_name'] ?? ''));
            
            $cmp = strcmp($lastA, $lastB);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp($firstA, $firstB);
        };
        
        usort($customer_types['active'], $sortByName);
        usort($customer_types['past_due'], $sortByName);
        usort($customer_types['completed'], $sortByName);
    }
    unset($customer_types); // Break the reference

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
    throw new RuntimeException("Database error: " . $e->getMessage());
}


    return array(
        'user'                  => $user,
        'user_account_type'     => $user_account_type,
        'user_branch'           => $user_branch,
        'user_area'             => $user_area,
        'available_branches'    => $available_branches,
        'selected_branch'       => $selected_branch,
        'selected_area'         => $selected_area,
        'areas'                 => $areas,
        'branches'              => $branches,
        'start_date'            => $start_date,
        'end_date'              => $end_date,
        'due_date_filter'       => $due_date_filter,
        'custom_due_date'       => $custom_due_date,
        'show_completed'        => $show_completed,
        'show_missed_payments'  => $show_missed_payments,
        'all_dates_in_range'    => $all_dates_in_range ?? array(),
        'date_headers'          => $date_headers,
        'customers_by_area'     => $customers_by_area,
        'daily_payments'        => $daily_payments,
        'savings_deposits'      => $savings_deposits,
        'savings_payments'      => $savings_payments,
        'customer_last_payments' => $customer_last_payments,
        'layout_menu'           => in_array($user_account_type, array('Collector', 'Proxy Collector'), true) ? 'collector' : 'admin',
    );
}
