<?php
/**
 * Prime City - Collector reports actions.
 *
 * Server-side logic for collector_reports.php: the signed-in account, the
 * collector/branch filters, the per-period payment report and the five summary
 * aggregates behind the cards.
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
function crep_start_session()
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
function crep_is_authenticated()
{
    return isset($_SESSION['logged_in']);
}

/**
 * Redirect, falling back to JS when headers are already sent.
 *
 * @param  string $location Target path.
 * @return void
 */
function crep_redirect($location)
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
function crep_sign_out()
{
    crep_start_session();

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
function crep_handle_logout()
{
    if (!isset($_GET['logout'])) {
        return;
    }

    crep_sign_out();
    crep_redirect('index.php');
    exit;
}

/**
 * The four branches.
 *
 * @return array<int, string>
 */
function crep_branches()
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
function crep_fetch_user(mysqli $conn)
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
function crep_branch_context(array $user)
{
    $branches = crep_branches();
    $userBranches = array();

    if (($user['account_type'] ?? '') === 'Admin') {
        $userBranches = $branches;
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
 * Active collectors, optionally limited to one branch.
 *
 * @param  mysqli $conn           Open connection.
 * @param  string $selectedBranch Branch filter ('all' for none).
 * @return array<int, array<string, mixed>>
 */
function crep_collectors(mysqli $conn, $selectedBranch)
{
    $sql = "SELECT id, CONCAT(first_name, ' ', last_name) AS name, branch, area FROM collector_accounts WHERE account_status = 'Active'";

    $stmt = null;

    if (!empty($selectedBranch) && $selectedBranch !== 'all') {
        $sql .= ' AND branch = ?';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $selectedBranch);
    } else {
        $stmt = $conn->prepare($sql);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $collectors = array();

    while ($row = $result->fetch_assoc()) {
        $collectors[] = $row;
    }

    $stmt->close();

    return $collectors;
}

/**
 * Years that actually carry payment data, ascending.
 *
 * @param  mysqli $conn Open connection.
 * @return array<int, int>
 */
function crep_payment_years(mysqli $conn)
{
    $years = array();

    try {
        $result = $conn->query(
            "SELECT DISTINCT YEAR(payment_date) AS payment_year
               FROM payments
              WHERE payment_date IS NOT NULL
              ORDER BY payment_year"
        );

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $year = (int) $row['payment_year'];

                if ($year > 0) {
                    $years[] = $year;
                }
            }

            $result->free();
        }
    } catch (Throwable $e) {
        error_log('[collector-reports] years: ' . $e->getMessage());
    }

    return $years;
}

/**
 * Date predicates for the requested report type.
 *
 * @param  string $reportType daily|weekly|monthly|yearly.
 * @param  string $dateFilter Anchor date.
 * @return array<string, mixed>
 */
function crep_date_params($reportType, $dateFilter)
{
    $dateCondition = '';
    $paymentParams = array();
    $loanParams = array();

    switch ($reportType) {
        case 'daily':
            $dateCondition = 'DATE(payment_date) = ?';
            $paymentParams = array($dateFilter);
            $loanParams = array($dateFilter, $dateFilter);
            break;
        case 'weekly':
            $weekStart = date('Y-m-d', strtotime($dateFilter . ' -' . (date('w', strtotime($dateFilter)) - 1) . ' days'));
            $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));
            $dateCondition = 'DATE(payment_date) BETWEEN ? AND ?';
            $paymentParams = array($weekStart, $weekEnd);
            $loanParams = array($weekStart, $weekEnd);
            break;
        case 'monthly':
            $monthStart = date('Y-m-01', strtotime($dateFilter));
            $monthEnd = date('Y-m-t', strtotime($dateFilter));
            $dateCondition = 'DATE(payment_date) BETWEEN ? AND ?';
            $paymentParams = array($monthStart, $monthEnd);
            $loanParams = array($monthStart, $monthEnd);
            break;
        case 'yearly':
            $yearStart = $dateFilter . '-01-01';
            $yearEnd = $dateFilter . '-12-31';
            $dateCondition = 'DATE(payment_date) BETWEEN ? AND ?';
            $paymentParams = array($yearStart, $yearEnd);
            $loanParams = array($yearStart, $yearEnd);
            break;
    }

    return array(
        'date_condition' => $dateCondition,
        'payment_params' => $paymentParams,
        'loan_params'    => $loanParams,
    );
}

/**
 * Detailed payment rows for one collector in the period.
 *
 * @param  mysqli $conn          Open connection.
 * @param  int    $collectorId   Collector id.
 * @param  string $reportType    daily|weekly|monthly|yearly.
 * @param  string $dateCondition Payment date predicate.
 * @param  array<int, string> $paymentParams Bound dates.
 * @return array<int, array<string, mixed>>
 */
function crep_reports(mysqli $conn, $collectorId, $reportType, $dateCondition, array $paymentParams)
{
    $query = "SELECT
                p.id,
                p.payment_date,
                p.amount,
                p.payment_method,
                p.balance_after_payment,
                p.customer_id,
                p.loan_id,
                c.first_name AS customer_first_name,
                c.last_name AS customer_last_name,
                f.amount_financed,
                f.interest_peso,
                (f.amount_financed + f.interest_peso) AS total_loan_amount,
                f.balance,
                f.loan_term,
                f.start_date,
                f.end_date
            FROM payments p
            JOIN customer_accounts c ON p.customer_id = c.id
            JOIN financing f ON p.loan_id = f.id
            WHERE p.collector_id = ?
            AND {$dateCondition}
            ORDER BY p.payment_date DESC";

    $stmt = $conn->prepare($query);

    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    if ($reportType === 'daily') {
        $stmt->bind_param('is', $collectorId, $paymentParams[0]);
    } else {
        $stmt->bind_param('iss', $collectorId, $paymentParams[0], $paymentParams[1]);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $reports = array();

    while ($row = $result->fetch_assoc()) {
        $reports[] = $row;
    }

    $stmt->close();

    return $reports;
}

/**
 * The five summary aggregates, combined, with the row-based fallback.
 *
 * @param  mysqli $conn         Open connection.
 * @param  int    $collectorId  Collector id.
 * @param  string $reportType   daily|weekly|monthly|yearly.
 * @param  string $paymentDateCondition Payment date predicate.
 * @param  array<int, string> $paymentParams Bound dates for the payment query.
 * @param  array<int, string> $loanParams    Bound dates for the loan/savings queries.
 * @param  array<int, array<string, mixed>> $reports Detailed rows.
 * @param  string $dateFilter   Anchor date (for the fallback log line).
 * @return array<string, mixed>
 */
function crep_summary(mysqli $conn, $collectorId, $reportType, $paymentDateCondition, array $paymentParams, array $loanParams, array $reports, $dateFilter)
{
    $queries = array(
        'payments' => array(
            'sql' => "SELECT COUNT(id) AS total_transactions, SUM(amount) AS total_collected
                      FROM payments
                      WHERE collector_id = ?
                      AND {$paymentDateCondition}",
            'bind' => $reportType === 'daily' ? 'is' : 'iss',
            'params' => array_merge(array($collectorId), $paymentParams),
            'fallback' => array('total_transactions' => 0, 'total_collected' => 0),
        ),
        'financing' => array(
            'sql' => "SELECT COALESCE(SUM(f.amount_financed), 0) AS total_amount_financed
                      FROM financing f
                      WHERE f.collector_id = ?
                      AND DATE(f.start_date) BETWEEN ? AND ?",
            'bind' => 'iss',
            'params' => array_merge(array($collectorId), $loanParams),
            'fallback' => array('total_amount_financed' => 0),
        ),
        'deposits' => array(
            'sql' => "SELECT COUNT(st.id) AS total_savings_transactions, COALESCE(SUM(st.amount), 0) AS total_savings_deposited
                      FROM savings_transactions st
                      WHERE st.collector_id = ?
                      AND st.transaction_type = 'Deposit'
                      AND DATE(st.transaction_date) BETWEEN ? AND ?",
            'bind' => 'iss',
            'params' => array_merge(array($collectorId), $loanParams),
            'fallback' => array('total_savings_transactions' => 0, 'total_savings_deposited' => 0),
        ),
        'withdrawals' => array(
            'sql' => "SELECT COUNT(st.id) AS total_savings_withdrawal_transactions, COALESCE(SUM(st.amount), 0) AS total_savings_withdrawn
                      FROM savings_transactions st
                      WHERE st.collector_id = ?
                      AND st.transaction_type = 'Withdrawal'
                      AND DATE(st.transaction_date) BETWEEN ? AND ?",
            'bind' => 'iss',
            'params' => array_merge(array($collectorId), $loanParams),
            'fallback' => array('total_savings_withdrawal_transactions' => 0, 'total_savings_withdrawn' => 0),
        ),
        'cash' => array(
            'sql' => "SELECT COUNT(st.id) AS total_savings_withdrawal_cash_transactions, COALESCE(SUM(st.amount), 0) AS total_savings_withdrawal_cash
                      FROM savings_transactions st
                      WHERE st.collector_id = ?
                      AND st.transaction_type = 'Withdrawal Cash'
                      AND DATE(st.transaction_date) BETWEEN ? AND ?",
            'bind' => 'iss',
            'params' => array_merge(array($collectorId), $loanParams),
            'fallback' => array('total_savings_withdrawal_cash_transactions' => 0, 'total_savings_withdrawal_cash' => 0),
        ),
    );

    $rows = array();

    foreach ($queries as $key => $query) {
        $stmt = $conn->prepare($query['sql']);

        if (!$stmt) {
            throw new RuntimeException('Prepare failed: ' . $conn->error);
        }

        $stmt->bind_param($query['bind'], ...$query['params']);
        $stmt->execute();

        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $rows[$key] = $result->fetch_assoc();
        } else {
            $rows[$key] = $query['fallback'];
        }

        $stmt->close();
    }

    $summary = array(
        'total_transactions' => $rows['payments']['total_transactions'] ?? 0,
        'total_collected' => $rows['payments']['total_collected'] ?? 0,
        'total_amount_financed' => $rows['financing']['total_amount_financed'] ?? 0,
        'total_savings_transactions' => $rows['deposits']['total_savings_transactions'] ?? 0,
        'total_savings_deposited' => $rows['deposits']['total_savings_deposited'] ?? 0,
        'total_savings_withdrawal_transactions' => $rows['withdrawals']['total_savings_withdrawal_transactions'] ?? 0,
        'total_savings_withdrawn' => $rows['withdrawals']['total_savings_withdrawn'] ?? 0,
        'total_savings_withdrawal_cash_transactions' => $rows['cash']['total_savings_withdrawal_cash_transactions'] ?? 0,
        'total_savings_withdrawal_cash' => $rows['cash']['total_savings_withdrawal_cash'] ?? 0,
    );

    // If the aggregate says zero but rows came back, trust the rows.
    if ($summary['total_transactions'] == 0 && count($reports) > 0) {
        $manualTotal = 0;

        foreach ($reports as $report) {
            $manualTotal += $report['amount'];
        }

        $summary['total_transactions'] = count($reports);
        $summary['total_collected'] = $manualTotal;

        error_log('Database returned 0 transactions but reports array has ' . count($reports) . ' entries. Collector: ' . $collectorId . ', Date: ' . $dateFilter);
    }

    return $summary;
}

/**
 * Human label for the report period.
 *
 * @param  string $reportType daily|weekly|monthly|yearly.
 * @param  string $dateFilter Anchor date.
 * @return string
 */
function crep_period_label($reportType, $dateFilter)
{
    if ($reportType === 'daily') {
        return date('F j, Y', strtotime($dateFilter));
    }

    if ($reportType === 'weekly') {
        $weekStart = date('F j, Y', strtotime($dateFilter . ' -' . (date('w', strtotime($dateFilter)) - 1) . ' days'));
        $weekEnd = date('F j, Y', strtotime($weekStart . ' +6 days'));

        return $weekStart . ' to ' . $weekEnd;
    }

    if ($reportType === 'monthly') {
        return date('F Y', strtotime($dateFilter));
    }

    if ($reportType === 'yearly') {
        return 'Year ' . $dateFilter;
    }

    return '';
}

/**
 * Everything the view needs, in one call.
 *
 * @return array<string, mixed>
 */
function crep_context()
{
    crep_start_session();
    crep_handle_logout();

    if (!crep_is_authenticated()) {
        crep_redirect('index.php');
        exit;
    }

    $conn = db();

    $user = array();
    $reports = array();
    $collectors = array();
    $summary = array();
    $branchContext = array(
        'user_branch'        => '',
        'user_branches'      => array(),
        'show_branch_filter' => false,
    );

    $selectedBranch = isset($_GET['branch']) ? (string) $_GET['branch'] : 'all';
    $selectedCollector = isset($_GET['collector_id']) ? intval($_GET['collector_id']) : null;
    $reportType = isset($_GET['report_type']) ? (string) $_GET['report_type'] : 'daily';
    $dateFilter = isset($_GET['date_filter']) ? (string) $_GET['date_filter'] : date('Y-m-d');

    /* the yearly report has no date picker - it always works on a year */
    if ($reportType === 'yearly' && !preg_match('/^\d{4}$/', $dateFilter)) {
        $yearTimestamp = strtotime($dateFilter);
        $dateFilter = date('Y', $yearTimestamp ?: time());
    }

    try {
        $user = crep_fetch_user($conn);
        $branchContext = crep_branch_context($user);

        $collectors = crep_collectors($conn, $selectedBranch);

        if ($selectedCollector) {
            if (!in_array($reportType, array('daily', 'weekly', 'monthly', 'yearly'), true)) {
                $reportType = 'daily';
            }

            $dates = crep_date_params($reportType, $dateFilter);

            $reports = crep_reports($conn, $selectedCollector, $reportType, $dates['date_condition'], $dates['payment_params']);

            $summary = crep_summary($conn, $selectedCollector, $reportType, $dates['date_condition'], $dates['payment_params'], $dates['loan_params'], $reports, $dateFilter);
        }
    } catch (Throwable $e) {
        error_log('[collector-reports] ' . $e->getMessage());
    }

    // Collector details for the header card.
    $collectorName = '';
    $collectorBranch = '';

    foreach ($collectors as $collector) {
        if ($collector['id'] == $selectedCollector) {
            $collectorName = $collector['name'];
            $collectorBranch = $collector['branch'];
            break;
        }
    }

    $reportTotal = 0;

    foreach ($reports as $report) {
        $reportTotal += $report['amount'];
    }

    return array(
        'user'                => $user,
        'user_branch'         => $branchContext['user_branch'],
        'user_branches'       => $branchContext['user_branches'],
        'show_branch_filter'  => $branchContext['show_branch_filter'],
        'branches'            => crep_branches(),
        'selected_branch'     => $selectedBranch,
        'selected_collector'  => $selectedCollector,
        'report_type'         => $reportType,
        'date_filter'         => $dateFilter,
        'collectors'          => $collectors,
        'reports'             => $reports,
        'summary'             => $summary,
        'collector_name'      => $collectorName,
        'collector_branch'    => $collectorBranch,
        'report_total'        => $reportTotal,
        'report_period_label' => crep_period_label($reportType, $dateFilter),
        'years'               => crep_payment_years($conn),
        'year_options_html'   => implode('', array_map(function ($year) {
            return "<option value='{$year}'>{$year}</option>";
        }, crep_payment_years($conn))),
        'current_month'       => date('Y-m'),
        'current_week'        => date('Y-\WW'),
        'current_date'        => date('Y-m-d'),
        'layout_menu'         => 'admin',
    );
}
