<?php
/**
 * Prime City - Amount financed report actions.
 *
 * Server-side logic for amount_financed.php: the signed-in account, branch
 * scoping, the period/area filters, the active-loan list with its derived
 * fields and the summary figures.
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
function amf_start_session()
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
function amf_is_authenticated()
{
    return isset($_SESSION['logged_in']);
}

/**
 * Redirect, falling back to JS when headers are already sent.
 *
 * @param  string $location Target path.
 * @return void
 */
function amf_redirect($location)
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
function amf_sign_out()
{
    amf_start_session();

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
function amf_handle_logout()
{
    if (!isset($_GET['logout'])) {
        return;
    }

    amf_sign_out();
    amf_redirect('index.php');
    exit;
}

/**
 * The four branches.
 *
 * @return array<int, string>
 */
function amf_branches()
{
    return array('Tuguegarao City', 'Gattaran', 'Abulug', 'Ilagan');
}

/**
 * Load the signed-in account.
 *
 * This page has only ever read the `accounts` table.
 *
 * @param  mysqli $conn Open connection.
 * @return array<string, mixed>
 *
 * @throws RuntimeException When the account cannot be found.
 */
function amf_fetch_user(mysqli $conn)
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
 * Branches this account may see, and whether the branch filter is shown.
 *
 * @param  array<string, mixed> $user Signed-in account.
 * @return array<string, mixed>
 */
function amf_branch_context(array $user)
{
    $userBranches = array();

    if (($user['account_type'] ?? '') === 'Admin') {
        $userBranches = amf_branches();
    } elseif (($user['account_type'] ?? '') === 'Area Manager') {
        if (!empty($user['branch'])) {
            $userBranches = array_map('trim', explode(',', $user['branch']));
        }
    } else {
        if (!empty($user['branch'])) {
            $userBranches = array(trim($user['branch']));
        }
    }

    return array(
        'user_branch'        => $user['branch'] ?? '',
        'user_branches'      => $userBranches,
        'show_branch_filter' => in_array($user['account_type'] ?? '', array('Admin', 'Area Manager'), true),
    );
}

/**
 * The filter values taken from the query string.
 *
 * @return array<string, string>
 */
function amf_filters()
{
    return array(
        'selected_branch' => isset($_GET['branch']) ? (string) $_GET['branch'] : 'all',
        'filter_period'   => isset($_GET['filter_period']) ? (string) $_GET['filter_period'] : 'daily',
        'custom_date'     => isset($_GET['custom_date']) ? (string) $_GET['custom_date'] : date('Y-m-d'),
        'week_date'       => isset($_GET['week']) ? (string) $_GET['week'] : date('Y-\WW'),
        'month_year'      => isset($_GET['month']) ? (string) $_GET['month'] : date('Y-m'),
        'year'            => isset($_GET['year']) ? (string) $_GET['year'] : date('Y'),
        'selected_area'   => isset($_GET['area']) ? (string) $_GET['area'] : 'all',
    );
}

/**
 * Distinct customer areas, for the filter dropdown.
 *
 * @param  mysqli $conn Open connection.
 * @return array<int, string>
 */
function amf_areas(mysqli $conn)
{
    $areas = array();

    $result = $conn->query("SELECT DISTINCT area FROM customer_accounts WHERE area IS NOT NULL AND area != '' ORDER BY area");

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $areas[] = $row['area'];
        }

        $result->close();
    }

    return $areas;
}

/**
 * Resolve the date range (and the week labels) for the selected period.
 *
 * @param  array<string, string> $filters Filter values.
 * @return array<string, string>
 */
function amf_date_range(array $filters)
{
    $weekStartDate = '';
    $weekEndDate = '';

    if ($filters['filter_period'] == 'weekly' && !empty($filters['week_date'])) {
        $weekParts = explode('-W', $filters['week_date']);

        if (count($weekParts) == 2) {
            $yearWeek = $weekParts[0];
            $weekNum = $weekParts[1];
            $weekStartDate = date('Y-m-d', strtotime($yearWeek . 'W' . str_pad($weekNum, 2, '0', STR_PAD_LEFT)));
            $weekEndDate = date('Y-m-d', strtotime($weekStartDate . ' +6 days'));
        }
    }

    $dateFrom = date('Y-m-d');
    $dateTo = date('Y-m-d');
    $weekStartFormatted = '';
    $weekEndFormatted = '';

    switch ($filters['filter_period']) {
        case 'daily':
            $dateFrom = $dateTo = $filters['custom_date'];
            break;

        case 'weekly':
            if (!empty($weekStartDate) && !empty($weekEndDate)) {
                $dateFrom = $weekStartDate;
                $dateTo = $weekEndDate;

                $weekStartFormatted = date('M j, Y', strtotime($weekStartDate));
                $weekEndFormatted = date('M j, Y', strtotime($weekEndDate));
            } else {
                $weekStart = new DateTime();
                $weekStart->modify('Monday this week');
                $dateFrom = $weekStart->format('Y-m-d');

                $weekEnd = clone $weekStart;
                $weekEnd->modify('Sunday this week');
                $dateTo = $weekEnd->format('Y-m-d');

                $weekStartFormatted = $weekStart->format('M j, Y');
                $weekEndFormatted = $weekEnd->format('M j, Y');
            }
            break;

        case 'monthly':
            $monthYearParts = explode('-', $filters['month_year']);

            if (count($monthYearParts) == 2) {
                $monthYear = $monthYearParts[0];
                $month = $monthYearParts[1];

                $dateFrom = date('Y-m-01', strtotime("{$monthYear}-{$month}-01"));
                $dateTo = date('Y-m-t', strtotime("{$monthYear}-{$month}-01"));
            }
            break;

        case 'yearly':
            $dateFrom = $filters['year'] . '-01-01';
            $dateTo = $filters['year'] . '-12-31';
            break;
    }

    return array(
        'week_start_date'      => $weekStartDate,
        'week_end_date'        => $weekEndDate,
        'week_start_formatted' => $weekStartFormatted,
        'week_end_formatted'   => $weekEndFormatted,
        'date_from'            => $dateFrom,
        'date_to'              => $dateTo,
    );
}

/**
 * Format a customer name as "Last, First Suffix Middle".
 *
 * @param  array<string, mixed> $customer Customer row.
 * @param  string               $fallback Name stored on the loan.
 * @return string
 */
function amf_formatted_name(array $customer, $fallback)
{
    $parts = array();

    if (!empty($customer['last_name']) && $customer['last_name'] !== 'None') {
        $parts[] = trim($customer['last_name']) . ',';
    } elseif (!empty($fallback)) {
        $parts[] = trim($fallback) . ',';
    } else {
        $parts[] = 'Unknown,';
    }

    if (!empty($customer['first_name']) && $customer['first_name'] !== 'None') {
        $parts[] = trim($customer['first_name']);
    }

    if (!empty($customer['suffix']) && $customer['suffix'] !== 'None') {
        $parts[] = trim($customer['suffix']);
    }

    if (!empty($customer['middle_name']) && $customer['middle_name'] !== 'None') {
        $parts[] = trim($customer['middle_name']);
    }

    return rtrim(implode(' ', $parts), ', ');
}

/**
 * Active loans for the period, with the customer details folded in.
 *
 * The customer columns come from the same join the query already used for the
 * area filter, so the per-row lookup the original performed is not needed.
 *
 * @param  mysqli               $conn         Open connection.
 * @param  array<string, string> $filters     Filter values.
 * @param  array<string, string> $range       Resolved date range.
 * @param  array<string, mixed> $user        Signed-in account.
 * @param  array<int, string>   $userBranches Branches the account may see.
 * @return array<int, array<string, mixed>>
 */
function amf_financing(mysqli $conn, array $filters, array $range, array $user, array $userBranches)
{
    $query = "SELECT
                f.id,
                f.customer_id,
                f.customer_name,
                f.collector_id,
                f.collector_name,
                f.amount_financed,
                f.interest,
                f.interest_peso,
                f.service_fee,
                f.savings,
                f.daily_payment,
                f.customer_net,
                f.balance,
                f.penalty,
                f.loan_term,
                f.start_date,
                f.end_date,
                f.status,
                f.branch,
                f.notes,
                f.loan_released_type,
                f.created_at,
                ca.branch AS collector_branch,
                ca.account_status AS collector_status,
                cust.id AS cust_id,
                cust.first_name AS cust_first_name,
                cust.middle_name AS cust_middle_name,
                cust.last_name AS cust_last_name,
                cust.suffix AS cust_suffix,
                cust.area AS cust_area,
                COALESCE(SUM(p.amount), 0) AS total_paid,
                COUNT(p.id) AS payment_count
            FROM financing f
            LEFT JOIN collector_accounts ca ON f.collector_id = ca.id
            LEFT JOIN payments p ON f.id = p.loan_id
            LEFT JOIN customer_accounts cust ON f.customer_id = cust.id
            WHERE f.status = 'active'";

    $params = array();
    $types = '';

    if (!empty($filters['selected_branch']) && $filters['selected_branch'] !== 'all') {
        $query .= ' AND f.branch = ?';
        $params[] = $filters['selected_branch'];
        $types .= 's';
    } else {
        if (($user['account_type'] ?? '') !== 'Admin') {
            if (!empty($userBranches)) {
                $placeholders = str_repeat('?,', count($userBranches) - 1) . '?';
                $query .= " AND f.branch IN ({$placeholders})";
                $params = array_merge($params, $userBranches);
                $types .= str_repeat('s', count($userBranches));
            }
        }
    }

    if (!empty($filters['selected_area']) && $filters['selected_area'] !== 'all') {
        $query .= ' AND cust.area = ?';
        $params[] = $filters['selected_area'];
        $types .= 's';
    }

    $query .= ' AND DATE(f.start_date) BETWEEN ? AND ?';
    $params[] = $range['date_from'];
    $params[] = $range['date_to'];
    $types .= 'ss';

    $query .= ' GROUP BY f.id ORDER BY f.start_date DESC, f.id DESC';

    $stmt = $conn->prepare($query);

    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $financingData = array();

    while ($row = $result->fetch_assoc()) {
        $customer = array(
            'first_name'  => $row['cust_first_name'],
            'middle_name' => $row['cust_middle_name'],
            'last_name'   => $row['cust_last_name'],
            'suffix'      => $row['cust_suffix'],
            'area'        => $row['cust_area'],
        );

        if ($row['cust_id'] !== null) {
            $row['formatted_customer_name'] = amf_formatted_name($customer, $row['customer_name']);
            $row['customer_area'] = !empty($customer['area']) && $customer['area'] !== 'None' ? $customer['area'] : 'N/A';
        } else {
            $row['formatted_customer_name'] = $row['customer_name'];
            $row['customer_area'] = 'N/A';
        }

        $row['total_amount'] = $row['amount_financed'] + $row['interest_peso'];
        $row['remaining_balance'] = $row['balance'];
        $row['payment_progress'] = $row['amount_financed'] > 0
            ? (($row['amount_financed'] - $row['balance']) / $row['amount_financed']) * 100
            : 0;

        $financingData[] = $row;
    }

    $stmt->close();

    return $financingData;
}

/**
 * Summary figures across the reported loans.
 *
 * @param  array<int, array<string, mixed>> $financingData Rows.
 * @return array<string, float>
 */
function amf_summary(array $financingData)
{
    $summary = array(
        'total_financed'  => 0,
        'total_interest'  => 0,
        'total_loans'     => 0,
        'total_payments'  => 0,
        'avg_loan_amount' => 0,
    );

    foreach ($financingData as $record) {
        $summary['total_financed'] += $record['amount_financed'];
        $summary['total_interest'] += $record['interest_peso'];
        $summary['total_payments'] += $record['total_paid'];
        $summary['total_loans']++;
    }

    if ($summary['total_loans'] > 0) {
        $summary['avg_loan_amount'] = $summary['total_financed'] / $summary['total_loans'];
    }

    return $summary;
}

/**
 * Years that actually carry financing data, newest first.
 *
 * @param  mysqli $conn Open connection.
 * @return array<int, int>
 */
function amf_year_options(mysqli $conn)
{
    $years = array();

    try {
        $result = $conn->query(
            'SELECT DISTINCT YEAR(start_date) AS financing_year
               FROM financing
              WHERE start_date IS NOT NULL
              ORDER BY financing_year DESC'
        );

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $year = (int) $row['financing_year'];

                if ($year > 0) {
                    $years[] = $year;
                }
            }

            $result->free();
        }
    } catch (Throwable $e) {
        error_log('[amount-financed] years: ' . $e->getMessage());
    }

    if (empty($years)) {
        $years[] = (int) date('Y');
    }

    return $years;
}

/**
 * Everything the view needs, in one call.
 *
 * @return array<string, mixed>
 */
function amf_context()
{
    amf_start_session();
    amf_handle_logout();

    if (!amf_is_authenticated()) {
        amf_redirect('index.php');
        exit;
    }

    $conn = db();

    $filters = amf_filters();
    $range = amf_date_range($filters);

    $user = array();
    $areas = array();
    $financingData = array();
    $summary = array(
        'total_financed' => 0,
        'total_interest' => 0,
        'total_loans' => 0,
        'total_payments' => 0,
        'avg_loan_amount' => 0,
    );
    $branchContext = array(
        'user_branch'        => '',
        'user_branches'      => array(),
        'show_branch_filter' => false,
    );
    $error = null;

    try {
        $user = amf_fetch_user($conn);
        $branchContext = amf_branch_context($user);

        $areas = amf_areas($conn);
        $financingData = amf_financing($conn, $filters, $range, $user, $branchContext['user_branches']);
        $summary = amf_summary($financingData);
    } catch (Throwable $e) {
        error_log('[amount-financed] ' . $e->getMessage());
        $error = 'An error occurred while retrieving data. Please try again.';
    }

    return array(
        'user'                 => $user,
        'user_branch'          => $branchContext['user_branch'],
        'user_branches'        => $branchContext['user_branches'],
        'show_branch_filter'   => $branchContext['show_branch_filter'],
        'branches'             => amf_branches(),
        'selected_branch'      => $filters['selected_branch'],
        'filter_period'        => $filters['filter_period'],
        'custom_date'          => $filters['custom_date'],
        'week_date'            => $filters['week_date'],
        'month_year'           => $filters['month_year'],
        'year'                 => $filters['year'],
        'selected_area'        => $filters['selected_area'],
        'date_from'            => $range['date_from'],
        'date_to'              => $range['date_to'],
        'week_start_date'      => $range['week_start_date'],
        'week_end_date'        => $range['week_end_date'],
        'week_start_formatted' => $range['week_start_formatted'],
        'week_end_formatted'   => $range['week_end_formatted'],
        'areas'                => $areas,
        'financing_data'       => $financingData,
        'summary'              => $summary,
        'year_options'         => amf_year_options($conn),
        'current_week'         => date('Y-\WW'),
        'error'                => $error,
        'layout_menu'          => 'admin',
    );
}
