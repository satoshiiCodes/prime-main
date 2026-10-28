<?php
/**
 * Prime City - Area reports actions.
 *
 * Server-side logic for area_reports.php: the signed-in account, branch scoping,
 * the period/area filters, the expense and other-income totals, and the
 * per area/branch aggregation that feeds the summary cards, table and charts.
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
function ar_start_session()
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
function ar_is_authenticated()
{
    return isset($_SESSION['logged_in']);
}

/**
 * Redirect, falling back to JS when headers are already sent.
 *
 * @param  string $location Target path.
 * @return void
 */
function ar_redirect($location)
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
function ar_sign_out()
{
    ar_start_session();

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
function ar_handle_logout()
{
    if (!isset($_GET['logout'])) {
        return;
    }

    ar_sign_out();
    ar_redirect('index.php');
    exit;
}

/**
 * The four branches.
 *
 * @return array<int, string>
 */
function ar_branches()
{
    return array('Tuguegarao City', 'Gattaran', 'Abulug', 'Ilagan');
}

/**
 * Load the signed-in account.
 *
 * @param  mysqli $conn Open connection.
 * @return array<string, mixed>
 *
 * @throws RuntimeException When the account cannot be found.
 */
function ar_fetch_user(mysqli $conn)
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    $stmt = $conn->prepare('SELECT first_name, middle_name, last_name, suffix, account_type, branch FROM accounts WHERE id = ?');
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
 * Branch-filter visibility and the effective branch selection.
 *
 * @param  array<string, mixed> $user    Signed-in account.
 * @param  string               $request Requested branch.
 * @return array<string, mixed>
 */
function ar_branch_context(array $user, $request)
{
    $userBranch = $user['branch'] ?? '';
    $accountType = $user['account_type'] ?? '';

    $disable = false;
    $hide = false;
    $allowed = array();
    $selected = '';

    if ($accountType === 'Admin') {
        $selected = $request;
    } elseif ($accountType === 'Area Manager') {
        $allowed = !empty($userBranch) ? array_map('trim', explode(',', $userBranch)) : array();
        $selected = $request;

        if (empty($selected) && !empty($allowed)) {
            $selected = $allowed[0];
        }
    } else {
        $restrictedTypes = array('Branch Manager', 'Proxy Collector-Cashier', 'Proxy Collector');

        if (in_array($accountType, $restrictedTypes, true)) {
            $disable = true;
            $hide = true;
            $selected = $userBranch;
        } else {
            $selected = $request;
        }
    }

    return array(
        'user_branch'           => $userBranch,
        'user_account_type'     => $accountType,
        'disable_branch_filter' => $disable,
        'hide_branch_filter'    => $hide,
        'allowed_branches'      => $allowed,
        'selected_branch'       => $selected,
    );
}

/**
 * Years that actually carry data for this report, newest first.
 *
 * @param  mysqli $conn Open connection.
 * @return array<int, int>
 */
function ar_year_options(mysqli $conn)
{
    $years = array();

    try {
        $result = $conn->query(
            'SELECT DISTINCT data_year FROM (
                 SELECT YEAR(payment_date) AS data_year FROM payments WHERE payment_date IS NOT NULL
                 UNION
                 SELECT YEAR(start_date) AS data_year FROM financing WHERE start_date IS NOT NULL
                 UNION
                 SELECT YEAR(transaction_date) AS data_year FROM savings_transactions WHERE transaction_date IS NOT NULL
             ) years
             WHERE data_year > 0
             ORDER BY data_year DESC'
        );

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $years[] = (int) $row['data_year'];
            }

            $result->free();
        }
    } catch (Throwable $e) {
        error_log('[area-reports] years: ' . $e->getMessage());
    }

    if (empty($years)) {
        $years[] = (int) date('Y');
    }

    return $years;
}

/**
 * The period and area filters from the query string.
 *
 * @return array<string, string>
 */
function ar_filters()
{
    return array(
        'time_period'   => isset($_GET['period']) ? (string) $_GET['period'] : 'daily',
        'selected_area' => isset($_GET['area']) ? (string) $_GET['area'] : '',
        'selected_date' => isset($_GET['date']) ? (string) $_GET['date'] : date('Y-m-d'),
        'selected_week' => isset($_GET['week']) ? (string) $_GET['week'] : date('Y-\WW'),
        'selected_month' => isset($_GET['month']) ? (string) $_GET['month'] : date('Y-m'),
        'selected_year' => isset($_GET['year']) ? (string) $_GET['year'] : date('Y'),
    );
}

/**
 * Build the report rows for the current filters.
 *
 * The body below is the original page's data pass, kept verbatim: it resolves
 * the area/branch combinations across financing, payments and savings, then
 * aggregates each one.
 *
 * @param  mysqli $conn            Open connection.
 * @param  string $selected_branch Branch filter ('' for all).
 * @param  string $time_period     daily|weekly|monthly|yearly.
 * @param  string $selected_area   Area filter.
 * @param  string $selected_date   Daily filter value.
 * @param  string $selected_week   Weekly filter value.
 * @param  string $selected_month  Monthly filter value.
 * @param  string $selected_year   Yearly filter value.
 * @return array<string, mixed>
 */
function ar_report_data(mysqli $conn, $selected_branch, $time_period, $selected_area, $selected_date, $selected_week, $selected_month, $selected_year)
{
    $report_data = array();
    $error = '';
    $total_expenses = 0;
    $total_other_income = 0;
    $other_income_by_area = array();

// Get report data
$report_data = [];
$time_period = isset($_GET['period']) ? $_GET['period'] : 'daily';
$selected_area = isset($_GET['area']) ? $_GET['area'] : '';
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$selected_week = isset($_GET['week']) ? $_GET['week'] : date('Y-\WW');
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$selected_year = isset($_GET['year']) ? $_GET['year'] : date('Y');

// Convert week format to date range
if ($time_period == 'weekly' && !empty($selected_week)) {
    $week_parts = explode('-W', $selected_week);
    $year = $week_parts[0];
    $week = $week_parts[1];
    $start_date = date('Y-m-d', strtotime($year . 'W' . str_pad($week, 2, '0', STR_PAD_LEFT)));
    $end_date = date('Y-m-d', strtotime($start_date . ' +6 days'));
}

// Get total expenses from expenses table (filtered by date and branch)
$total_expenses = 0;
try {
    $expenses_query = "SELECT SUM(total_expenses) as total FROM expenses WHERE 1=1";
    
    // Add branch condition for expenses
    if (!empty($selected_branch)) {
        $expenses_query .= " AND branch = '$selected_branch'";
    }
    
    // Add date conditions for expenses
    if ($time_period == 'weekly' && !empty($selected_week)) {
        $expenses_query .= " AND YEARWEEK(expense_date, 1) = YEARWEEK('$start_date', 1)";
    } elseif ($time_period == 'monthly' && !empty($selected_month)) {
        $expenses_query .= " AND YEAR(expense_date) = YEAR('$selected_month-01') AND MONTH(expense_date) = MONTH('$selected_month-01')";
    } elseif ($time_period == 'yearly' && !empty($selected_year)) {
        $expenses_query .= " AND YEAR(expense_date) = '$selected_year'";
    } elseif ($time_period == 'daily' && !empty($selected_date)) {
        $expenses_query .= " AND DATE(expense_date) = '$selected_date'";
    }
    
    $expenses_result = $conn->query($expenses_query);
    if ($expenses_result && $expenses_result->num_rows > 0) {
        $expenses_row = $expenses_result->fetch_assoc();
        $total_expenses = $expenses_row['total'] ? $expenses_row['total'] : 0;
    }
    
    // Get total employee allowances (filtered by effective_date and is_active = 1)
    $allowances_query = "SELECT SUM(ea.amount) as total_allowances 
                         FROM employee_allowances ea
                         WHERE ea.is_active = 1";
    
    // Add date conditions for allowances based on effective_date
    if ($time_period == 'weekly' && !empty($selected_week)) {
        $allowances_query .= " AND YEARWEEK(ea.effective_date, 1) = YEARWEEK('$start_date', 1)";
    } elseif ($time_period == 'monthly' && !empty($selected_month)) {
        $allowances_query .= " AND YEAR(ea.effective_date) = YEAR('$selected_month-01') AND MONTH(ea.effective_date) = MONTH('$selected_month-01')";
    } elseif ($time_period == 'yearly' && !empty($selected_year)) {
        $allowances_query .= " AND YEAR(ea.effective_date) = '$selected_year'";
    } elseif ($time_period == 'daily' && !empty($selected_date)) {
        $allowances_query .= " AND DATE(ea.effective_date) = '$selected_date'";
    }
    
    $allowances_result = $conn->query($allowances_query);
    if ($allowances_result && $allowances_result->num_rows > 0) {
        $allowances_row = $allowances_result->fetch_assoc();
        $total_employee_allowances = $allowances_row['total_allowances'] ? $allowances_row['total_allowances'] : 0;
        
        // Add employee allowances to total expenses
        $total_expenses += $total_employee_allowances;
    }
    
} catch (Exception $e) {
    error_log("Error fetching expenses: " . $e->getMessage());
}

// Get total other income (ONLY GCash fees - savings withdrawals EXCLUDED)
$total_other_income = 0;
$other_income_by_area = [];
try {
    // Get GCash fees by area ONLY (removed savings withdrawals)
    $gcash_query = "SELECT area, SUM(fee) as total FROM gcash_transactions WHERE 1=1";
    
    // Add branch condition for GCash
    if (!empty($selected_branch)) {
        $gcash_query .= " AND branch = '$selected_branch'";
    }
    
    // Add date conditions for GCash
    if ($time_period == 'weekly' && !empty($selected_week)) {
        $gcash_query .= " AND YEARWEEK(transaction_date, 1) = YEARWEEK('$start_date', 1)";
    } elseif ($time_period == 'monthly' && !empty($selected_month)) {
        $gcash_query .= " AND YEAR(transaction_date) = YEAR('$selected_month-01') AND MONTH(transaction_date) = MONTH('$selected_month-01')";
    } elseif ($time_period == 'yearly' && !empty($selected_year)) {
        $gcash_query .= " AND YEAR(transaction_date) = '$selected_year'";
    } elseif ($time_period == 'daily' && !empty($selected_date)) {
        $gcash_query .= " AND DATE(transaction_date) = '$selected_date'";
    }
    
    if (!empty($selected_area)) {
        $gcash_query .= " AND area = '$selected_area'";
    }
    
    $gcash_query .= " GROUP BY area";
    $gcash_result = $conn->query($gcash_query);
    
    while ($row = $gcash_result->fetch_assoc()) {
        if (!isset($other_income_by_area[$row['area']])) {
            $other_income_by_area[$row['area']] = 0;
        }
        $other_income_by_area[$row['area']] += $row['total'];
        $total_other_income += $row['total'];
    }
    
} catch (Exception $e) {
    error_log("Error fetching other income: " . $e->getMessage());
}

// Build date condition for financing queries
$financing_date_condition = "";
switch($time_period) {
    case 'weekly':
        if (!empty($selected_week)) {
            $week_parts = explode('-W', $selected_week);
            $year = $week_parts[0];
            $week = $week_parts[1];
            $financing_date_condition = "AND YEARWEEK(f.start_date, 1) = $year$week";
        } else {
            $financing_date_condition = "AND YEARWEEK(f.start_date, 1) = YEARWEEK(CURDATE(), 1)";
        }
        break;
    case 'monthly':
        if (!empty($selected_month)) {
            $month_parts = explode('-', $selected_month);
            $year = $month_parts[0];
            $month = $month_parts[1];
            $financing_date_condition = "AND YEAR(f.start_date) = $year AND MONTH(f.start_date) = $month";
        } else {
            $financing_date_condition = "AND MONTH(f.start_date) = MONTH(CURDATE()) AND YEAR(f.start_date) = YEAR(CURDATE())";
        }
        break;
    case 'yearly':
        if (!empty($selected_year)) {
            $financing_date_condition = "AND YEAR(f.start_date) = $selected_year";
        } else {
            $financing_date_condition = "AND YEAR(f.start_date) = YEAR(CURDATE())";
        }
        break;
    default: // daily
        if (!empty($selected_date)) {
            $financing_date_condition = "AND DATE(f.start_date) = '$selected_date'";
        } else {
            $financing_date_condition = "AND DATE(f.start_date) = CURDATE()";
        }
}

// Build date condition for savings transactions queries
$savings_transactions_date_condition = "";
switch($time_period) {
    case 'weekly':
        if (!empty($selected_week)) {
            $week_parts = explode('-W', $selected_week);
            $year = $week_parts[0];
            $week = $week_parts[1];
            $savings_transactions_date_condition = "AND YEARWEEK(st.transaction_date, 1) = $year$week";
        } else {
            $savings_transactions_date_condition = "AND YEARWEEK(st.transaction_date, 1) = YEARWEEK(CURDATE(), 1)";
        }
        break;
    case 'monthly':
        if (!empty($selected_month)) {
            $month_parts = explode('-', $selected_month);
            $year = $month_parts[0];
            $month = $month_parts[1];
            $savings_transactions_date_condition = "AND YEAR(st.transaction_date) = $year AND MONTH(st.transaction_date) = $month";
        } else {
            $savings_transactions_date_condition = "AND MONTH(st.transaction_date) = MONTH(CURDATE()) AND YEAR(st.transaction_date) = YEAR(CURDATE())";
        }
        break;
    case 'yearly':
        if (!empty($selected_year)) {
            $savings_transactions_date_condition = "AND YEAR(st.transaction_date) = $selected_year";
        } else {
            $savings_transactions_date_condition = "AND YEAR(st.transaction_date) = YEAR(CURDATE())";
        }
        break;
    default: // daily
        if (!empty($selected_date)) {
            $savings_transactions_date_condition = "AND DATE(st.transaction_date) = '$selected_date'";
        } else {
            $savings_transactions_date_condition = "AND DATE(st.transaction_date) = CURDATE()";
        }
}

try {
    // Determine date range based on period for payments
    $date_condition = "";
    $group_by = "DATE(p.payment_date)";
    
    switch($time_period) {
        case 'weekly':
            if (!empty($selected_week)) {
                $week_parts = explode('-W', $selected_week);
                $year = $week_parts[0];
                $week = $week_parts[1];
                $date_condition = "AND YEARWEEK(p.payment_date, 1) = $year$week";
            } else {
                $date_condition = "AND YEARWEEK(p.payment_date, 1) = YEARWEEK(CURDATE(), 1)";
            }
            $group_by = "YEARWEEK(p.payment_date, 1)";
            break;
        case 'monthly':
            if (!empty($selected_month)) {
                $month_parts = explode('-', $selected_month);
                $year = $month_parts[0];
                $month = $month_parts[1];
                $date_condition = "AND YEAR(p.payment_date) = $year AND MONTH(p.payment_date) = $month";
            } else {
                $date_condition = "AND MONTH(p.payment_date) = MONTH(CURDATE()) AND YEAR(p.payment_date) = YEAR(CURDATE())";
            }
            $group_by = "MONTH(p.payment_date)";
            break;
        case 'yearly':
            if (!empty($selected_year)) {
                $date_condition = "AND YEAR(p.payment_date) = $selected_year";
            } else {
                $date_condition = "AND YEAR(p.payment_date) = YEAR(CURDATE())";
            }
            $group_by = "YEAR(p.payment_date)";
            break;
        default: // daily
            if (!empty($selected_date)) {
                $date_condition = "AND DATE(p.payment_date) = '$selected_date'";
            } else {
                $date_condition = "AND DATE(p.payment_date) = CURDATE()";
            }
    }
    
    // Area condition
    $area_condition = "";
    if (!empty($selected_area)) {
        $area_condition = "AND c.area = ?";
    }
    
    // Branch condition - Now using the selected_branch from user logic
    $branch_condition = "";
    if (!empty($selected_branch)) {
        $branch_condition = "AND c.branch = ?";
    }
    
    // ============================================================
    // FIX: Merge area/branch combinations from ALL relevant sources
    // (financing, payments, savings) so no area is silently dropped.
    // Each source uses its own correct date condition.
    // ============================================================
    $area_branches_map = [];
    
    // 1) From financing records (uses $financing_date_condition)
    $areas_query = "SELECT DISTINCT ca.area, ca.branch 
                   FROM customer_accounts ca 
                   JOIN financing f ON ca.id = f.customer_id 
                   WHERE 1=1";
    
    $areas_params = [];
    $areas_types = "";
    
    if (!empty($selected_area)) {
        $areas_query .= " AND ca.area = ?";
        $areas_params[] = $selected_area;
        $areas_types .= "s";
    }
    
    if (!empty($selected_branch)) {
        $areas_query .= " AND ca.branch = ?";
        $areas_params[] = $selected_branch;
        $areas_types .= "s";
    }
    
    $areas_query .= " $financing_date_condition";
    
    $areas_stmt = $conn->prepare($areas_query);
    if (!empty($areas_types)) {
        $areas_stmt->bind_param($areas_types, ...$areas_params);
    }
    $areas_stmt->execute();
    $areas_result = $areas_stmt->get_result();
    
    while ($row = $areas_result->fetch_assoc()) {
        $key = $row['area'] . '|' . $row['branch'];
        $area_branches_map[$key] = $row;
    }
    $areas_stmt->close();
    
    // 2) From payments (uses $date_condition based on p.payment_date)
    $payments_areas_query = "SELECT DISTINCT c.area, c.branch 
                            FROM payments p
                            JOIN financing f ON p.loan_id = f.id
                            JOIN collector_accounts c ON p.collector_id = c.id
                            WHERE 1=1 $date_condition";
    
    $payments_areas_params = [];
    $payments_areas_types = "";
    
    if (!empty($selected_area)) {
        $payments_areas_query .= " AND c.area = ?";
        $payments_areas_params[] = $selected_area;
        $payments_areas_types .= "s";
    }
    
    if (!empty($selected_branch)) {
        $payments_areas_query .= " AND c.branch = ?";
        $payments_areas_params[] = $selected_branch;
        $payments_areas_types .= "s";
    }
    
    $payments_areas_stmt = $conn->prepare($payments_areas_query);
    if (!empty($payments_areas_types)) {
        $payments_areas_stmt->bind_param($payments_areas_types, ...$payments_areas_params);
    }
    $payments_areas_stmt->execute();
    $payments_areas_result = $payments_areas_stmt->get_result();
    
    while ($row = $payments_areas_result->fetch_assoc()) {
        $key = $row['area'] . '|' . $row['branch'];
        if (!isset($area_branches_map[$key])) {
            $area_branches_map[$key] = $row;
        }
    }
    $payments_areas_stmt->close();
    
    // 3) From savings transactions (uses $savings_transactions_date_condition)
    $savings_areas_query = "SELECT DISTINCT ca.area, ca.branch 
                           FROM savings_transactions st 
                           JOIN savings_accounts sa ON st.savings_account_id = sa.id 
                           JOIN customer_accounts ca ON sa.customer_id = ca.id 
                           WHERE 1=1 $savings_transactions_date_condition";
    
    $savings_areas_params = [];
    $savings_areas_types = "";
    
    if (!empty($selected_area)) {
        $savings_areas_query .= " AND ca.area = ?";
        $savings_areas_params[] = $selected_area;
        $savings_areas_types .= "s";
    }
    
    if (!empty($selected_branch)) {
        $savings_areas_query .= " AND ca.branch = ?";
        $savings_areas_params[] = $selected_branch;
        $savings_areas_types .= "s";
    }
    
    $savings_areas_stmt = $conn->prepare($savings_areas_query);
    if (!empty($savings_areas_types)) {
        $savings_areas_stmt->bind_param($savings_areas_types, ...$savings_areas_params);
    }
    $savings_areas_stmt->execute();
    $savings_areas_result = $savings_areas_stmt->get_result();
    
    while ($row = $savings_areas_result->fetch_assoc()) {
        $key = $row['area'] . '|' . $row['branch'];
        if (!isset($area_branches_map[$key])) {
            $area_branches_map[$key] = $row;
        }
    }
    $savings_areas_stmt->close();
    
    // Convert to indexed array and sort by area, then branch (matches old ORDER BY)
    $area_branches = array_values($area_branches_map);
    usort($area_branches, function($a, $b) {
        $cmp = strcmp($a['area'], $b['area']);
        if ($cmp !== 0) return $cmp;
        return strcmp($a['branch'], $b['branch']);
    });
    
    // Now process each area/branch combination
    foreach ($area_branches as $area_branch) {
        $area = $area_branch['area'];
        $branch = $area_branch['branch'];
        
        // Initialize row data
        $row = [
            'area' => $area,
            'branch' => $branch,
            'period' => '',
            'total_amount' => 0,
            'customer_count' => 0,
            'start_date' => '',
            'end_date' => ''
        ];
        
        // Determine period display based on time period
        switch($time_period) {
            case 'weekly':
                if (!empty($selected_week)) {
                    $row['period'] = $selected_week;
                } else {
                    $row['period'] = date('Y-\WW');
                }
                break;
            case 'monthly':
                if (!empty($selected_month)) {
                    $row['period'] = $selected_month;
                } else {
                    $row['period'] = date('Y-m');
                }
                break;
            case 'yearly':
                if (!empty($selected_year)) {
                    $row['period'] = $selected_year;
                } else {
                    $row['period'] = date('Y');
                }
                break;
            default: // daily
                if (!empty($selected_date)) {
                    $row['period'] = $selected_date;
                } else {
                    $row['period'] = date('Y-m-d');
                }
        }
        
        // Get total savings transactions for this area and branch
        // Calculate net savings (Deposits - Withdrawals) for the period using the "amount" field
        $savings_query = "SELECT 
                            SUM(CASE WHEN st.transaction_type = 'Deposit' THEN st.amount ELSE 0 END) as total_deposits,
                            SUM(CASE WHEN st.transaction_type = 'Withdrawal' OR st.transaction_type = 'Withdrawal_Cash' THEN st.amount ELSE 0 END) as total_withdrawals
                         FROM savings_transactions st 
                         JOIN savings_accounts sa ON st.savings_account_id = sa.id 
                         JOIN customer_accounts ca ON sa.customer_id = ca.id 
                         WHERE ca.area = ?";
        
        $savings_params = [$area];
        $savings_types = "s";
        
        if (!empty($selected_branch)) {
            $savings_query .= " AND ca.branch = ?";
            $savings_params[] = $selected_branch;
            $savings_types .= "s";
        } else {
            $savings_query .= " AND ca.branch = ?";
            $savings_params[] = $branch;
            $savings_types .= "s";
        }
        
        $savings_query .= " $savings_transactions_date_condition";
        
        $savings_stmt = $conn->prepare($savings_query);
        $savings_stmt->bind_param($savings_types, ...$savings_params);
        $savings_stmt->execute();
        $savings_result = $savings_stmt->get_result();
        $savings_row = $savings_result->fetch_assoc();
        
        $total_deposits = $savings_row['total_deposits'] ? $savings_row['total_deposits'] : 0;
        $total_withdrawals = $savings_row['total_withdrawals'] ? $savings_row['total_withdrawals'] : 0;
        
        // Calculate net savings for the period
        $row['total_savings'] = $total_deposits - $total_withdrawals;
        $savings_stmt->close();
        
        // Get total amount financed for this area and branch (ALL LOANS - including completed and not completed)
        $financing_query = "SELECT SUM(f.amount_financed) as total_amount_financed,
                                   SUM(f.service_fee) as total_service_fee,
                                   SUM(f.interest_peso) as total_interest_rate,
                                   COUNT(DISTINCT f.id) as loan_count,
                                   MIN(f.start_date) as first_loan_date,
                                   MAX(f.start_date) as last_loan_date
                            FROM financing f
                            JOIN customer_accounts ca ON f.customer_id = ca.id
                            WHERE ca.area = ?";
        
        $financing_params = [$area];
        $financing_types = "s";
        
        if (!empty($selected_branch)) {
            $financing_query .= " AND ca.branch = ?";
            $financing_params[] = $selected_branch;
            $financing_types .= "s";
        } else {
            $financing_query .= " AND ca.branch = ?";
            $financing_params[] = $branch;
            $financing_types .= "s";
        }
        
        $financing_query .= " $financing_date_condition";
        
        $financing_stmt = $conn->prepare($financing_query);
        $financing_stmt->bind_param($financing_types, ...$financing_params);
        $financing_stmt->execute();
        $financing_result = $financing_stmt->get_result();
        $financing_row = $financing_result->fetch_assoc();
        $row['total_amount_financed'] = $financing_row['total_amount_financed'] ? $financing_row['total_amount_financed'] : 0;
        $row['total_service_fee'] = $financing_row['total_service_fee'] ? $financing_row['total_service_fee'] : 0;
        $row['total_interest_rate'] = $financing_row['total_interest_rate'] ? $financing_row['total_interest_rate'] : 0;
        $row['loan_count'] = $financing_row['loan_count'] ? $financing_row['loan_count'] : 0;
        
        // Set start and end dates based on financing dates
        if (!empty($financing_row['first_loan_date'])) {
            $row['start_date'] = $financing_row['first_loan_date'];
        }
        if (!empty($financing_row['last_loan_date'])) {
            $row['end_date'] = $financing_row['last_loan_date'];
        }
        $financing_stmt->close();
        
        // Get total payments for this area and branch (only if there are payments)
        $payments_query = "SELECT SUM(p.amount) as total_amount,
                                  COUNT(DISTINCT p.customer_id) as customer_count,
                                  MIN(p.payment_date) as first_payment_date,
                                  MAX(p.payment_date) as last_payment_date
                           FROM payments p
                           JOIN financing f ON p.loan_id = f.id
                           JOIN collector_accounts c ON p.collector_id = c.id
                           WHERE c.area = ?";
        
        $payments_params = [$area];
        $payments_types = "s";
        
        if (!empty($selected_branch)) {
            $payments_query .= " AND c.branch = ?";
            $payments_params[] = $selected_branch;
            $payments_types .= "s";
        } else {
            $payments_query .= " AND c.branch = ?";
            $payments_params[] = $branch;
            $payments_types .= "s";
        }
        
        $payments_query .= " $date_condition";
        
        $payments_stmt = $conn->prepare($payments_query);
        $payments_stmt->bind_param($payments_types, ...$payments_params);
        $payments_stmt->execute();
        $payments_result = $payments_stmt->get_result();
        $payments_row = $payments_result->fetch_assoc();
        
        if ($payments_row['total_amount']) {
            $row['total_amount'] = $payments_row['total_amount'];
            $row['customer_count'] = $payments_row['customer_count'];
            
            // If we have payment dates, use them for start/end
            if (!empty($payments_row['first_payment_date']) && empty($row['start_date'])) {
                $row['start_date'] = $payments_row['first_payment_date'];
            }
            if (!empty($payments_row['last_payment_date']) && empty($row['end_date'])) {
                $row['end_date'] = $payments_row['last_payment_date'];
            }
        }
        $payments_stmt->close();
        
        // If no start/end dates from financing or payments, use current date
        if (empty($row['start_date'])) {
            switch($time_period) {
                case 'weekly':
                    $row['start_date'] = !empty($start_date) ? $start_date : date('Y-m-d', strtotime('monday this week'));
                    $row['end_date'] = !empty($end_date) ? $end_date : date('Y-m-d', strtotime('sunday this week'));
                    break;
                case 'monthly':
                    $row['start_date'] = !empty($selected_month) ? $selected_month . '-01' : date('Y-m-01');
                    $row['end_date'] = !empty($selected_month) ? date('Y-m-t', strtotime($selected_month . '-01')) : date('Y-m-t');
                    break;
                case 'yearly':
                    $row['start_date'] = !empty($selected_year) ? $selected_year . '-01-01' : date('Y-01-01');
                    $row['end_date'] = !empty($selected_year) ? $selected_year . '-12-31' : date('Y-12-31');
                    break;
                default: // daily
                    $row['start_date'] = !empty($selected_date) ? $selected_date : date('Y-m-d');
                    $row['end_date'] = $row['start_date'];
            }
        }
        
        // Add other income (ONLY GCash fees) to the row data
        $row['total_other_income'] = isset($other_income_by_area[$area]) ? $other_income_by_area[$area] : 0;
        
        // Calculate total gross and total net for each row
        // Total Net = (Total Gross + Total Service Fee + Total Other Income) - Total Expenses
        $row['total_gross'] = $row['total_amount'] * 0.1304;
        $row['total_net'] = ($row['total_gross'] + $row['total_service_fee'] + $row['total_other_income']) - $total_expenses;
        
        $report_data[] = $row;
    }
} catch (Exception $e) {
    error_log($e->getMessage());
    $error = "Failed to load report data. Please try again.";
}

    return array(
        'report_data'         => $report_data,
        'error'               => $error,
        'total_expenses'      => $total_expenses,
        'total_other_income'  => $total_other_income,
        'other_income_by_area' => $other_income_by_area,
    );
}

/**
 * Column totals across every report row, plus the final net.
 *
 * @param  array<int, array<string, mixed>> $report_data    Rows.
 * @param  float                            $total_expenses Expenses for the period.
 * @return array<string, float>
 */
function ar_totals(array $report_data, $total_expenses)
{
    $totals = array(
        'total_amount'         => 0,
        'total_savings'        => 0,
        'total_service_fee'    => 0,
        'total_interest_rate'  => 0,
        'total_amount_financed' => 0,
        'total_gross'          => 0,
        'total_net'            => 0,
        'total_other_income'   => 0,
        'final_total_net'      => 0,
    );

    foreach ($report_data as $data) {
        $totals['total_amount'] += $data['total_amount'];
        $totals['total_savings'] += $data['total_savings'];
        $totals['total_service_fee'] += $data['total_service_fee'];
        $totals['total_interest_rate'] += $data['total_interest_rate'];
        $totals['total_amount_financed'] += $data['total_amount_financed'];
        $totals['total_gross'] += $data['total_gross'];
        $totals['total_net'] += $data['total_net'];
        $totals['total_other_income'] += $data['total_other_income'];
    }

    // Total Net = (Total Gross + Total Service Fee + Total Other Income) - Total Expenses
    $final = ($totals['total_gross'] + $totals['total_service_fee'] + $totals['total_other_income']) - $total_expenses;
    $totals['final_total_net'] = $final < 0 ? 0 : $final;

    return $totals;
}

/**
 * Collection totals per area, for the charts.
 *
 * Rows sharing an area are summed, which is what the inline script did.
 *
 * @param  array<int, array<string, mixed>> $report_data Rows.
 * @return array<string, float>
 */
function ar_area_chart_data(array $report_data)
{
    $areaData = array();

    foreach ($report_data as $data) {
        if (!isset($areaData[$data['area']])) {
            $areaData[$data['area']] = 0;
        }

        $areaData[$data['area']] += $data['total_amount'];
    }

    return $areaData;
}

/**
 * Everything the view needs, in one call.
 *
 * @return array<string, mixed>
 */
function ar_context()
{
    ar_start_session();
    ar_handle_logout();

    if (!ar_is_authenticated()) {
        ar_redirect('index.php');
        exit;
    }

    $conn = db();

    $user = array();
    $branchContext = array(
        'user_branch'           => '',
        'user_account_type'     => '',
        'disable_branch_filter' => false,
        'hide_branch_filter'    => false,
        'allowed_branches'      => array(),
        'selected_branch'       => '',
    );

    try {
        $user = ar_fetch_user($conn);
        $branchContext = ar_branch_context($user, isset($_GET['branch']) ? (string) $_GET['branch'] : '');
    } catch (Throwable $e) {
        error_log('[area-reports] ' . $e->getMessage());
    }

    $filters = ar_filters();

    $report = ar_report_data(
        $conn,
        $branchContext['selected_branch'],
        $filters['time_period'],
        $filters['selected_area'],
        $filters['selected_date'],
        $filters['selected_week'],
        $filters['selected_month'],
        $filters['selected_year']
    );

    $totals = ar_totals($report['report_data'], $report['total_expenses']);

    return array_merge(
        array(
            'conn'                  => $conn,
            'user'                  => $user,
            'branches'              => ar_branches(),
            'area_chart_data'       => ar_area_chart_data($report['report_data']),
            'years'                 => ar_year_options($conn),
            'layout_menu'           => 'admin',
        ),
        $branchContext,
        $filters,
        $report,
        $totals
    );
}
