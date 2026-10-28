<?php
/**
 * Prime City - Cashbook actions.
 *
 * Server-side logic for cashbook.php: the signed-in account, branch scoping, the
 * 11-table ledger union, the running balance, totals and pagination.
 *
 * Read-only page.
 *
 * Produces no markup - the page includes it and renders.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Start the session once.
 *
 * @return void
 */
function cb_start_session()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * Is a user signed in?
 *
 * @return bool
 */
function cb_is_authenticated()
{
    return isset($_SESSION['logged_in']);
}

/**
 * Redirect, falling back to JS when headers are already sent.
 *
 * @param  string $location Target path.
 * @return void
 */
function cb_redirect($location)
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
function cb_sign_out()
{
    cb_start_session();

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
function cb_handle_logout()
{
    if (!isset($_GET['logout'])) {
        return;
    }

    cb_sign_out();
    cb_redirect('index.php');
    exit;
}

/**
 * The ledger sources offered in the type filter.
 *
 * @return array<string, string>
 */
function cb_transaction_types()
{
    return array(
        'Customer Payment' => 'Customer Payment',
        'Customer Loan(Cash Released)' => 'Customer Loan (Cash Released)',
        'Deposited' => 'Investment Deposit',
        'Withdraw' => 'Investment Withdrawal',
        'Beginning Balance' => 'Investment Beginning Balance',
        'Other Income' => 'Investment Other Income',
        'Gcash Fee' => 'GCash Fee',
        'Gcash Fund To Cashbook' => 'GCash Fund to Cashbook',
        'Gcash Cash In' => 'GCash Cash In',
        'Gcash Cash Out' => 'GCash Cash Out',
        'Expenses' => 'Expenses',
        'Employee Loan' => 'Employee Loan',
        'Collector Payment' => 'Employee Loan Payment',
        'Customer Deposit' => 'Savings Deposit',
        'Customer Savings Withdrawal' => 'Savings Withdrawal',
        'Customer Savings Withdrawal (Cash)' => 'Savings Withdrawal (Cash)',
        'Cash Advance' => 'Cash Advance',
        'Employee Allowances' => 'Employee Allowances',
    );
}

/**
 * Load the signed-in account.
 *
 * @param  mysqli $conn Open connection.
 * @return array<string, mixed>
 *
 * @throws RuntimeException When the account cannot be found.
 */
function cb_fetch_user(mysqli $conn)
{
    $source = $_SESSION['account_source'] ?? 'accounts';
    $table = $source === 'accounts' ? 'accounts' : 'proxy_accounts';
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    $stmt = $conn->prepare("SELECT first_name, middle_name, last_name, suffix, account_type, branch FROM {$table} WHERE id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $result = $stmt->get_result();

    if (!$result || $result->num_rows === 0) {
        $stmt->close();
        throw new RuntimeException('User not found');
    }

    $user = $result->fetch_assoc();
    $stmt->close();

    return $user;
}

/**
 * Branches this account may filter by, plus the effective selection.
 *
 * @param  array<string, mixed> $user           Signed-in account.
 * @param  string               $selectedBranch Requested branch.
 * @return array<string, mixed>
 */
function cb_branch_context(array $user, $selectedBranch)
{
    $restrictedTypes = array('Branch Manager', 'Proxy Collector-Cashier', 'Proxy Collector');

    $userBranch = $user['branch'] ?? '';
    $accountType = $user['account_type'] ?? '';
    $availableBranches = array();

    if ($accountType === 'Admin') {
        $availableBranches = array('Tuguegarao City', 'Gattaran', 'Abulug', 'Ilagan');

        if (empty($selectedBranch)) {
            $selectedBranch = '';
        }
    } elseif ($accountType === 'Area Manager') {
        if ($userBranch) {
            $availableBranches = array_map('trim', explode(',', $userBranch));

            if (empty($selectedBranch) || !in_array($selectedBranch, $availableBranches)) {
                $selectedBranch = !empty($availableBranches) ? $availableBranches[0] : '';
            }
        } else {
            $availableBranches = array('Tuguegarao City');
            $selectedBranch = 'Tuguegarao City';
        }
    } elseif (in_array($accountType, $restrictedTypes, true)) {
        if ($userBranch) {
            $availableBranches[] = $userBranch;
            $selectedBranch = $userBranch;
        } else {
            $availableBranches = array('Tuguegarao City');
            $selectedBranch = 'Tuguegarao City';
        }
    } else {
        $availableBranches = array('Tuguegarao City', 'Gattaran', 'Abulug', 'Ilagan');

        if (empty($selectedBranch)) {
            $selectedBranch = '';
        }
    }

    return array(
        'user_branch'         => $userBranch,
        'user_account_type'   => $accountType,
        'available_branches'  => $availableBranches,
        'selected_branch'     => $selectedBranch,
    );
}

/**
 * The union of every cash-moving table, newest-agnostic (ordered by callers).
 *
 * COLLATE clauses are load-bearing: the tables mix collations.
 *
 * @return string
 */
function cb_union_query()
{
    $union_query = "
        SELECT 
            'Customer Payment' as type,
            p.payment_date as created_at,
            p.customer_id,
            CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.middle_name, ''), ' ', COALESCE(c.last_name, ''), 
                CASE WHEN c.suffix IS NOT NULL AND c.suffix != '' AND c.suffix != 'None' THEN CONCAT(' ', c.suffix) ELSE '' END) COLLATE utf8mb4_general_ci as customer_name,
            p.notes COLLATE utf8mb4_general_ci as notes,
            NULL as debit,
            p.amount as credit,
            p.branch COLLATE utf8mb4_general_ci as branch,
            DATE(p.payment_date) as sort_date,
            p.payment_date as full_datetime
        FROM payments p
        LEFT JOIN customer_accounts c ON p.customer_id = c.id
        WHERE p.payment_method != 'Gcash' AND p.payment_date IS NOT NULL
        
        UNION ALL
        
        SELECT 
            'Customer Loan(Cash Released)' as type,
            f.start_date as created_at,
            f.customer_id,
            CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.middle_name, ''), ' ', COALESCE(c.last_name, ''), 
                CASE WHEN c.suffix IS NOT NULL AND c.suffix != '' AND c.suffix != 'None' THEN CONCAT(' ', c.suffix) ELSE '' END) COLLATE utf8mb4_general_ci as customer_name,
            f.notes COLLATE utf8mb4_general_ci as notes,
            f.customer_net as debit,
            NULL as credit,
            f.branch COLLATE utf8mb4_general_ci as branch,
            DATE(f.start_date) as sort_date,
            f.start_date as full_datetime
        FROM financing f
        LEFT JOIN customer_accounts c ON f.customer_id = c.id
        WHERE f.loan_released_type = 'Cash' AND f.start_date IS NOT NULL
        
        UNION ALL
        
        SELECT 
            i.type COLLATE utf8mb4_general_ci as type,
            i.date as created_at,
            i.account_id as customer_id,
            CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.middle_name, ''), ' ', COALESCE(c.last_name, ''), 
                CASE WHEN c.suffix IS NOT NULL AND c.suffix != '' AND c.suffix != 'None' THEN CONCAT(' ', c.suffix) ELSE '' END) COLLATE utf8mb4_general_ci as customer_name,
            i.notes COLLATE utf8mb4_general_ci as notes,
            CASE WHEN i.type = 'Withdraw' THEN i.amount ELSE NULL END as debit,
            CASE WHEN i.type IN ('Deposited', 'Beginning Balance', 'Other Income') THEN i.amount ELSE NULL END as credit,
            i.branch COLLATE utf8mb4_general_ci as branch,
            DATE(i.date) as sort_date,
            i.date as full_datetime
        FROM investments i
        LEFT JOIN accounts c ON i.account_id = c.id
        WHERE i.date IS NOT NULL
        
        UNION ALL
        
        SELECT 
            'Gcash Fee' as type,
            g.transaction_date as created_at,
            g.account_id as customer_id,
            CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.middle_name, ''), ' ', COALESCE(c.last_name, ''), 
                CASE WHEN c.suffix IS NOT NULL AND c.suffix != '' AND c.suffix != 'None' THEN CONCAT(' ', c.suffix) ELSE '' END) COLLATE utf8mb4_general_ci as customer_name,
            g.notes COLLATE utf8mb4_general_ci as notes,
            NULL as debit,
            g.fee as credit,
            g.branch COLLATE utf8mb4_general_ci as branch,
            DATE(g.transaction_date) as sort_date,
            g.transaction_date as full_datetime
        FROM gcash_transactions g
        LEFT JOIN accounts c ON g.account_id = c.id
        WHERE g.fee > 0 AND g.transaction_date IS NOT NULL

        UNION ALL

        SELECT 
            'Gcash Fund To Cashbook' as type,
            g.transaction_date as created_at,
            g.account_id as customer_id,
            CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.middle_name, ''), ' ', COALESCE(c.last_name, ''), 
                CASE WHEN c.suffix IS NOT NULL AND c.suffix != '' AND c.suffix != 'None' THEN CONCAT(' ', c.suffix) ELSE '' END) COLLATE utf8mb4_general_ci as customer_name,
            g.notes COLLATE utf8mb4_general_ci as notes,
            NULL as debit,
            CASE WHEN g.bank = 'Cashbook' THEN g.amount ELSE NULL END as credit,
            g.branch COLLATE utf8mb4_general_ci as branch,
            DATE(g.transaction_date) as sort_date,
            g.transaction_date as full_datetime
        FROM gcash_transactions g
        LEFT JOIN accounts c ON g.account_id = c.id
        WHERE g.amount > 0 AND g.bank = 'Cashbook' AND g.transaction_date IS NOT NULL

        UNION ALL
        
        SELECT 
            'Gcash Cash In' as type,
            g.transaction_date as created_at,
            g.account_id as customer_id,
            CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.middle_name, ''), ' ', COALESCE(c.last_name, ''), 
                CASE WHEN c.suffix IS NOT NULL AND c.suffix != '' AND c.suffix != 'None' THEN CONCAT(' ', c.suffix) ELSE '' END) COLLATE utf8mb4_general_ci as customer_name,
            g.notes COLLATE utf8mb4_general_ci as notes,
            g.amount as debit,
            NULL as credit,
            g.branch COLLATE utf8mb4_general_ci as branch,
            DATE(g.transaction_date) as sort_date,
            g.transaction_date as full_datetime
        FROM gcash_transactions g
        LEFT JOIN accounts c ON g.account_id = c.id
        WHERE g.type = 'Cash In' AND g.amount > 0 AND g.transaction_date IS NOT NULL

        UNION ALL
        
        SELECT 
            'Gcash Cash Out' as type,
            g.transaction_date as created_at,
            g.account_id as customer_id,
            CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.middle_name, ''), ' ', COALESCE(c.last_name, ''), 
                CASE WHEN c.suffix IS NOT NULL AND c.suffix != '' AND c.suffix != 'None' THEN CONCAT(' ', c.suffix) ELSE '' END) COLLATE utf8mb4_general_ci as customer_name,
            g.notes COLLATE utf8mb4_general_ci as notes,
            NULL as debit,
            g.amount as credit,
            g.branch COLLATE utf8mb4_general_ci as branch,
            DATE(g.transaction_date) as sort_date,
            g.transaction_date as full_datetime
        FROM gcash_transactions g
        LEFT JOIN accounts c ON g.account_id = c.id
        WHERE g.type = 'Cash Out' AND g.amount > 0 AND g.transaction_date IS NOT NULL

        UNION ALL
        
        SELECT 
            'Expenses' as type,
            e.expense_date as created_at,
            e.recorded_by as customer_id,
            CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.middle_name, ''), ' ', COALESCE(c.last_name, ''), 
                CASE WHEN c.suffix IS NOT NULL AND c.suffix != '' AND c.suffix != 'None' THEN CONCAT(' ', c.suffix) ELSE '' END) COLLATE utf8mb4_general_ci as customer_name,
            e.remarks COLLATE utf8mb4_general_ci as notes,
            e.total_expenses as debit,
            NULL as credit,
            e.branch COLLATE utf8mb4_general_ci as branch,
            DATE(e.expense_date) as sort_date,
            e.expense_date as full_datetime
        FROM expenses e
        LEFT JOIN accounts c ON e.recorded_by = c.id
        WHERE e.expense_date IS NOT NULL

        UNION ALL
        
        SELECT 
            'Employee Loan' as type,
            cl.created_at,
            cl.collector_id as customer_id,
            COALESCE(
                CONCAT(COALESCE(a.first_name, ''), ' ', COALESCE(a.middle_name, ''), ' ', COALESCE(a.last_name, ''), 
                    CASE WHEN a.suffix IS NOT NULL AND a.suffix != '' AND a.suffix != 'None' THEN CONCAT(' ', a.suffix) ELSE '' END),
                CONCAT(COALESCE(pa.first_name, ''), ' ', COALESCE(pa.middle_name, ''), ' ', COALESCE(pa.last_name, ''), 
                    CASE WHEN pa.suffix IS NOT NULL AND pa.suffix != '' AND pa.suffix != 'None' THEN CONCAT(' ', pa.suffix) ELSE '' END),
                cl.collector_name
            ) COLLATE utf8mb4_general_ci as customer_name,
            'Employee Loan' as notes,
            cl.amount_loaned as debit,
            NULL as credit,
            cl.branch COLLATE utf8mb4_general_ci as branch,
            DATE(cl.created_at) as sort_date,
            cl.created_at as full_datetime
        FROM collector_loans cl
        LEFT JOIN accounts a ON cl.collector_id = a.id
        LEFT JOIN proxy_accounts pa ON cl.collector_id = pa.id
        WHERE cl.created_at IS NOT NULL

        UNION ALL
        
        SELECT 
            'Collector Payment' as type,
            cp.payment_date as created_at,
            cp.collector_id as customer_id,
            cp.collector_name COLLATE utf8mb4_general_ci as customer_name,
            cp.notes COLLATE utf8mb4_general_ci as notes,
            NULL as debit,
            cp.amount as credit,
            cp.branch COLLATE utf8mb4_general_ci as branch,
            DATE(cp.payment_date) as sort_date,
            cp.payment_date as full_datetime
        FROM collector_payments cp
        WHERE cp.payment_date IS NOT NULL

        UNION ALL
        
        SELECT 
            CASE 
                WHEN st.transaction_type = 'Deposit' THEN 'Customer Deposit' 
                WHEN st.transaction_type = 'Withdrawal' THEN 'Customer Savings Withdrawal'
                WHEN st.transaction_type = 'Withdrawal Cash' THEN 'Customer Savings Withdrawal (Cash)'
            END as type,
            st.transaction_date as created_at,
            sa.customer_id,
            CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.middle_name, ''), ' ', COALESCE(c.last_name, ''), 
                CASE WHEN c.suffix IS NOT NULL AND c.suffix != '' AND c.suffix != 'None' THEN CONCAT(' ', c.suffix) ELSE '' END) COLLATE utf8mb4_general_ci as customer_name,
            st.notes COLLATE utf8mb4_general_ci as notes,
            CASE WHEN st.transaction_type IN ('Withdrawal', 'Withdrawal Cash') THEN st.amount ELSE NULL END as debit,
            CASE WHEN st.transaction_type = 'Deposit' THEN st.amount ELSE NULL END as credit,
            st.branch COLLATE utf8mb4_general_ci as branch,
            DATE(st.transaction_date) as sort_date,
            st.transaction_date as full_datetime
        FROM savings_transactions st
        LEFT JOIN savings_accounts sa ON st.savings_account_id = sa.id
        LEFT JOIN customer_accounts c ON sa.customer_id = c.id
        WHERE st.transaction_type IN ('Deposit', 'Withdrawal', 'Withdrawal Cash') 
            AND st.payment_method != 'Gcash'
            AND st.transaction_date IS NOT NULL

        UNION ALL
        
        SELECT 
            'Cash Advance' as type,
            ed.end_date as created_at,
            ed.employee_id as customer_id,
            CASE 
                WHEN ed.employee_type = 'accounts' THEN 
                    CONCAT(COALESCE(a.first_name, ''), ' ', COALESCE(a.middle_name, ''), ' ', COALESCE(a.last_name, ''), 
                        CASE WHEN a.suffix IS NOT NULL AND a.suffix != '' AND a.suffix != 'None' THEN CONCAT(' ', a.suffix) ELSE '' END)
                WHEN ed.employee_type = 'collector' THEN 
                    CONCAT(COALESCE(ca.first_name, ''), ' ', COALESCE(ca.middle_name, ''), ' ', COALESCE(ca.last_name, ''), 
                        CASE WHEN ca.suffix IS NOT NULL AND ca.suffix != '' AND ca.suffix != 'None' THEN CONCAT(' ', ca.suffix) ELSE '' END)
                WHEN ed.employee_type = 'proxy' THEN 
                    CONCAT(COALESCE(pa.first_name, ''), ' ', COALESCE(pa.middle_name, ''), ' ', COALESCE(pa.last_name, ''), 
                        CASE WHEN pa.suffix IS NOT NULL AND pa.suffix != '' AND pa.suffix != 'None' THEN CONCAT(' ', pa.suffix) ELSE '' END)
                ELSE 'N/A'
            END COLLATE utf8mb4_general_ci as customer_name,
            'Cash Advance' as notes,
            ed.amount as debit,
            NULL as credit,
            CASE 
                WHEN ed.employee_type = 'accounts' THEN COALESCE(a.branch, '')
                WHEN ed.employee_type = 'collector' THEN COALESCE(ca.branch, '')
                WHEN ed.employee_type = 'proxy' THEN COALESCE(pa.branch, '')
                ELSE ''
            END COLLATE utf8mb4_general_ci as branch,
            DATE(ed.end_date) as sort_date,
            ed.end_date as full_datetime
        FROM employee_deductions ed
        LEFT JOIN accounts a ON ed.employee_id = a.id AND ed.employee_type = 'accounts'
        LEFT JOIN collector_accounts ca ON ed.employee_id = ca.id AND ed.employee_type = 'collector'
        LEFT JOIN proxy_accounts pa ON ed.employee_id = pa.id AND ed.employee_type = 'proxy'
        WHERE ed.deduction_type = 'cash_advance' 
            AND ed.is_active = 1 
            AND ed.end_date IS NOT NULL

        UNION ALL
        
        SELECT 
            'Employee Allowances' as type,
            ea.effective_date as created_at,
            ea.employee_id as customer_id,
            CASE 
                WHEN ea.employee_type = 'accounts' THEN 
                    CONCAT(COALESCE(a.first_name, ''), ' ', COALESCE(a.middle_name, ''), ' ', COALESCE(a.last_name, ''), 
                        CASE WHEN a.suffix IS NOT NULL AND a.suffix != '' AND a.suffix != 'None' THEN CONCAT(' ', a.suffix) ELSE '' END)
                WHEN ea.employee_type = 'collector' THEN 
                    CONCAT(COALESCE(ca.first_name, ''), ' ', COALESCE(ca.middle_name, ''), ' ', COALESCE(ca.last_name, ''), 
                        CASE WHEN ca.suffix IS NOT NULL AND ca.suffix != '' AND ca.suffix != 'None' THEN CONCAT(' ', ca.suffix) ELSE '' END)
                WHEN ea.employee_type = 'proxy' THEN 
                    CONCAT(COALESCE(pa.first_name, ''), ' ', COALESCE(pa.middle_name, ''), ' ', COALESCE(pa.last_name, ''), 
                        CASE WHEN pa.suffix IS NOT NULL AND pa.suffix != '' AND pa.suffix != 'None' THEN CONCAT(' ', pa.suffix) ELSE '' END)
                ELSE 'N/A'
            END COLLATE utf8mb4_general_ci as customer_name,
            ea.allowance_type COLLATE utf8mb4_general_ci as notes,
            ea.amount as debit,
            NULL as credit,
            CASE 
                WHEN ea.employee_type = 'accounts' THEN COALESCE(a.branch, 'N/A')
                WHEN ea.employee_type = 'collector' THEN COALESCE(ca.branch, 'N/A')
                WHEN ea.employee_type = 'proxy' THEN COALESCE(pa.branch, 'N/A')
                ELSE 'N/A'
            END COLLATE utf8mb4_general_ci as branch,
            DATE(ea.effective_date) as sort_date,
            ea.effective_date as full_datetime
        FROM employee_allowances ea
        LEFT JOIN accounts a ON ea.employee_id = a.id AND ea.employee_type = 'accounts'
        LEFT JOIN collector_accounts ca ON ea.employee_id = ca.id AND ea.employee_type = 'collector'
        LEFT JOIN proxy_accounts pa ON ea.employee_id = pa.id AND ea.employee_type = 'proxy'
        WHERE ea.is_active = 1 
            AND ea.effective_date IS NOT NULL
    ";

    return $union_query;
}

/**
 * One page of the ledger plus the totals behind the summary cards.
 *
 * @param  mysqli $conn             Open connection.
 * @param  string $selected_branch  Branch filter ('' for all).
 * @param  string $start_date       Start date filter.
 * @param  string $end_date         End date filter.
 * @param  string $transaction_type Type filter.
 * @param  int    $limit_per_page   Rows per page.
 * @param  int    $offset           Row offset.
 * @return array<string, mixed>
 */
function cb_ledger(mysqli $conn, $selected_branch, $start_date, $end_date, $transaction_type, $limit_per_page, $offset)
{
    $union_query = cb_union_query();
    $debug = false;
    $error_message = '';

    $payments = array();
    $total_records = 0;
    $total_pages = 1;
    $total_credit = 0;
    $total_debit = 0;
    $ending_balance = 0;
    $running_balance = 0;

    // Build WHERE conditions
    $where_conditions = [];
    $params = [];
    $param_types = "";
    
    if (!empty($selected_branch)) {
        $where_conditions[] = "branch = ?";
        $params[] = $selected_branch;
        $param_types .= "s";
    }
    
    if (!empty($start_date)) {
        $where_conditions[] = "sort_date >= ?";
        $params[] = $start_date;
        $param_types .= "s";
    }
    
    if (!empty($end_date)) {
        $where_conditions[] = "sort_date <= ?";
        $params[] = $end_date;
        $param_types .= "s";
    }
    
    if (!empty($transaction_type)) {
        $where_conditions[] = "type = ?";
        $params[] = $transaction_type;
        $param_types .= "s";
    }
    
    $where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
    
    // Get total count
    $count_sql = "SELECT COUNT(*) as total FROM ($union_query) as combined $where_clause";
    
    if ($debug) {
        $error_message .= "Count SQL executed successfully<br>";
    }
    
    if (!empty($params)) {
        $count_stmt = $conn->prepare($count_sql);
        if ($count_stmt) {
            $count_stmt->bind_param($param_types, ...$params);
            $count_stmt->execute();
            $count_result = $count_stmt->get_result();
            $count_row = $count_result->fetch_assoc();
            $total_records = $count_row ? (int)$count_row['total'] : 0;
            $count_stmt->close();
        } else {
            $error_message .= "Count prepare failed: " . $conn->error . "<br>";
            $total_records = 0;
        }
    } else {
        $count_result = $conn->query($count_sql);
        if ($count_result) {
            $count_row = $count_result->fetch_assoc();
            $total_records = $count_row ? (int)$count_row['total'] : 0;
        } else {
            $error_message .= "Count query failed: " . $conn->error . "<br>";
            $total_records = 0;
        }
    }
    
    if ($debug) {
        $error_message .= "Total records found: " . $total_records . "<br>";
    }
    
    $total_pages = $total_records > 0 ? ceil($total_records / $limit_per_page) : 1;
    
    // Get paginated data
    $data_sql = "SELECT type, created_at, customer_id, customer_name, notes, debit, credit, branch, sort_date, full_datetime 
                 FROM ($union_query) as combined 
                 $where_clause 
                 ORDER BY full_datetime DESC 
                 LIMIT ? OFFSET ?";
    
    $params[] = $limit_per_page;
    $params[] = $offset;
    $param_types .= "ii";
    
    $stmt = $conn->prepare($data_sql);
    if ($stmt) {
        $stmt->bind_param($param_types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($debug) {
            $error_message .= "Data query executed. Rows: " . ($result ? $result->num_rows : 0) . "<br>";
        }
    } else {
        $error_message .= "Data prepare failed: " . $conn->error . "<br>";
        $result = false;
    }
    
    $all_records = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $all_records[] = $row;
        }
    }
    
    // Get ALL records for balance calculation
    $all_data_sql = "SELECT credit, debit, full_datetime 
                     FROM ($union_query) as combined 
                     $where_clause 
                     ORDER BY full_datetime ASC";
    
    if (!empty($params) && count($params) > 2) {
        $balance_params = array_slice($params, 0, count($params) - 2);
        $balance_types = substr($param_types, 0, -2);
        
        $balance_stmt = $conn->prepare($all_data_sql);
        if ($balance_stmt) {
            $balance_stmt->bind_param($balance_types, ...$balance_params);
            $balance_stmt->execute();
            $balance_result = $balance_stmt->get_result();
        } else {
            $balance_result = null;
            $error_message .= "Balance prepare failed: " . $conn->error . "<br>";
        }
    } else {
        $balance_stmt = $conn->prepare($all_data_sql);
        if ($balance_stmt) {
            $balance_stmt->execute();
            $balance_result = $balance_stmt->get_result();
        } else {
            $balance_result = null;
            $error_message .= "Balance prepare failed (no params): " . $conn->error . "<br>";
        }
    }
    
    // Calculate totals
    $total_credit = 0;
    $total_debit = 0;
    $running_balance = 0;
    $balance_by_index = [];
    
    if ($balance_result && $balance_result->num_rows > 0) {
        while ($row = $balance_result->fetch_assoc()) {
            $credit = isset($row['credit']) && $row['credit'] !== null ? (float)$row['credit'] : 0;
            $debit = isset($row['debit']) && $row['debit'] !== null ? (float)$row['debit'] : 0;
            
            $total_credit += $credit;
            $total_debit += $debit;
            $running_balance += ($credit - $debit);
            $balance_by_index[] = $running_balance;
        }
    }
    
    $ending_balance = !empty($balance_by_index) ? end($balance_by_index) : 0;
    
    // Assign balances to paginated records
    $payments = [];
    
    if (!empty($all_records) && !empty($balance_by_index)) {
        $asc_records_sql = "SELECT full_datetime, credit, debit 
                            FROM ($union_query) as combined 
                            $where_clause 
                            ORDER BY full_datetime ASC";
        
        if (!empty($params) && count($params) > 2) {
            $asc_params = array_slice($params, 0, count($params) - 2);
            $asc_types = substr($param_types, 0, -2);
            $asc_stmt = $conn->prepare($asc_records_sql);
            if ($asc_stmt) {
                $asc_stmt->bind_param($asc_types, ...$asc_params);
                $asc_stmt->execute();
                $asc_result = $asc_stmt->get_result();
            } else {
                $asc_result = null;
            }
        } else {
            $asc_stmt = $conn->prepare($asc_records_sql);
            if ($asc_stmt) {
                $asc_stmt->execute();
                $asc_result = $asc_stmt->get_result();
            } else {
                $asc_result = null;
            }
        }
        
        $balance_lookup = [];
        if ($asc_result && $asc_result->num_rows > 0) {
            $balance_idx = 0;
            while ($row = $asc_result->fetch_assoc()) {
                $datetime_key = $row['full_datetime'];
                if (isset($balance_by_index[$balance_idx])) {
                    $balance_lookup[$datetime_key] = $balance_by_index[$balance_idx];
                }
                $balance_idx++;
            }
        }
        
        foreach ($all_records as $record) {
            $datetime_key = $record['full_datetime'];
            $record['balance'] = isset($balance_lookup[$datetime_key]) ? $balance_lookup[$datetime_key] : 0;
            $payments[] = $record;
        }
        
        if (isset($asc_stmt)) $asc_stmt->close();
    } else {
        $payments = $all_records;
        foreach ($payments as &$record) {
            $record['balance'] = 0;
        }
    }
    
    if (isset($balance_stmt) && $balance_stmt) $balance_stmt->close();
    if (isset($stmt) && $stmt) $stmt->close();

    return array(
        'payments'        => $payments,
        'total_records'   => $total_records,
        'total_pages'     => $total_pages,
        'total_credit'    => $total_credit,
        'total_debit'     => $total_debit,
        'ending_balance'  => $ending_balance,
        'running_balance' => $running_balance,
        'error_message'   => $error_message,
    );
}

/**
 * Customer name for a ledger row, or "N/A".
 *
 * @param  array<string, mixed> $row Ledger row.
 * @return string
 */
function getCustomerName($row)
{
    if (isset($row['customer_name']) && !empty($row['customer_name']) && trim($row['customer_name']) !== '') {
        return trim($row['customer_name']);
    }

    return 'N/A';
}

/**
 * Date column text for a ledger row.
 *
 * Cash advances and allowances are date-only; everything else carries a time.
 *
 * @param  array<string, mixed> $payment Ledger row.
 * @return string
 */
function formatDateForDisplay($payment)
{
    if ($payment['type'] === 'Cash Advance' || $payment['type'] === 'Employee Allowances') {
        return date('m-d-Y', strtotime($payment['full_datetime'] ?? $payment['created_at']));
    }

    return date('m-d-Y h:i:s A', strtotime($payment['full_datetime'] ?? $payment['created_at']));
}

/**
 * Everything the view needs, in one call.
 *
 * @return array<string, mixed>
 */
function cb_context()
{
    cb_start_session();
    cb_handle_logout();

    if (!cb_is_authenticated()) {
        cb_redirect('index.php');
        exit;
    }

    $conn = db();

    $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
    $perPage = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 50;
    $limitPerPage = min($perPage, 200);
    $offset = ($page - 1) * $limitPerPage;

    $selectedBranch = isset($_GET['branch']) ? trim((string) $_GET['branch']) : '';
    $startDate = isset($_GET['start_date']) && !empty($_GET['start_date']) ? (string) $_GET['start_date'] : '';
    $endDate = isset($_GET['end_date']) && !empty($_GET['end_date']) ? (string) $_GET['end_date'] : '';
    $transactionType = isset($_GET['transaction_type']) ? (string) $_GET['transaction_type'] : '';

    $user = array();
    $branch = array(
        'user_branch'        => '',
        'user_account_type'  => '',
        'available_branches' => array(),
        'selected_branch'    => $selectedBranch,
    );
    $ledger = array(
        'payments'        => array(),
        'total_records'   => 0,
        'total_pages'     => 1,
        'total_credit'    => 0,
        'total_debit'     => 0,
        'ending_balance'  => 0,
        'running_balance' => 0,
        'error_message'   => '',
    );

    try {
        $user = cb_fetch_user($conn);
        $branch = cb_branch_context($user, $selectedBranch);
        $selectedBranch = $branch['selected_branch'];

        $ledger = cb_ledger($conn, $selectedBranch, $startDate, $endDate, $transactionType, $limitPerPage, $offset);
    } catch (Throwable $e) {
        error_log('[cashbook] ' . $e->getMessage());

        $ledger['error_message'] .= 'Exception: ' . $e->getMessage();
        $user = array(
            'first_name'   => $_SESSION['first_name'] ?? 'Admin',
            'middle_name'  => '',
            'last_name'    => '',
            'suffix'       => '',
            'account_type' => $_SESSION['account_type'] ?? 'User',
            'branch'       => $_SESSION['branch'] ?? '',
        );
    }

    return array(
        'user'                => $user,
        'user_branch'         => $branch['user_branch'],
        'user_account_type'   => $branch['user_account_type'],
        'available_branches'  => $branch['available_branches'],
        'selected_branch'     => $selectedBranch,
        'start_date'          => $startDate,
        'end_date'            => $endDate,
        'transaction_type'    => $transactionType,
        'transaction_types'   => cb_transaction_types(),
        'page'                => $page,
        'per_page'            => $perPage,
        'limit_per_page'      => $limitPerPage,
        'payments'            => $ledger['payments'],
        'total_records'       => $ledger['total_records'],
        'total_pages'         => $ledger['total_pages'],
        'total_credit'        => $ledger['total_credit'],
        'total_debit'         => $ledger['total_debit'],
        'ending_balance'      => $ledger['ending_balance'],
        'running_balance'     => $ledger['running_balance'],
        'error_message'       => $ledger['error_message'],
        'debug'               => false,
        'layout_menu'         => 'admin',
    );
}
