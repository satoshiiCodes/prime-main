<?php
/**
 * Prime City - Savings reports actions.
 *
 * Server-side logic for savings_reports.php: the signed-in account, branch
 * scoping, the period/area/type filters, the transaction list with its display
 * helpers and the summary figures.
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
function sav_start_session()
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
function sav_is_authenticated()
{
    return isset($_SESSION['logged_in']);
}

/**
 * Redirect, falling back to JS when headers are already sent.
 *
 * @param  string $location Target path.
 * @return void
 */
function sav_redirect($location)
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
function sav_sign_out()
{
    sav_start_session();

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
function sav_handle_logout()
{
    if (!isset($_GET['logout'])) {
        return;
    }

    sav_sign_out();
    sav_redirect('index.php');
    exit;
}

/**
 * The four branches.
 *
 * @return array<int, string>
 */
function sav_branches()
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
function sav_fetch_user(mysqli $conn)
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
 * Branch-filter visibility, allowed branches and the effective selection.
 *
 * @param  array<string, mixed> $user    Signed-in account.
 * @param  string               $request Requested branch.
 * @return array<string, mixed>
 */
function sav_branch_context(array $user, $request)
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
 * The filter values taken from the query string.
 *
 * @return array<string, string>
 */
function sav_filters()
{
    return array(
        'time_period'               => isset($_GET['period']) ? (string) $_GET['period'] : 'daily',
        'selected_area'             => isset($_GET['area']) ? (string) $_GET['area'] : '',
        'selected_transaction_type' => isset($_GET['transaction_type']) ? (string) $_GET['transaction_type'] : '',
        'selected_date'             => isset($_GET['date']) ? (string) $_GET['date'] : date('Y-m-d'),
        'selected_week'             => isset($_GET['week']) ? (string) $_GET['week'] : date('Y-\WW'),
        'selected_month'            => isset($_GET['month']) ? (string) $_GET['month'] : date('Y-m'),
        'selected_year'             => isset($_GET['year']) ? (string) $_GET['year'] : date('Y'),
    );
}

/**
 * The SQL date predicate for savings_transactions, plus the week bounds.
 *
 * @param  array<string, string> $filters Filter values.
 * @return array<string, string>
 */
function sav_date_conditions(array $filters)
{
    $timePeriod = $filters['time_period'];
    $selectedWeek = $filters['selected_week'];
    $selectedMonth = $filters['selected_month'];
    $selectedYear = $filters['selected_year'];
    $selectedDate = $filters['selected_date'];

    $startDate = '';
    $endDate = '';

    if ($timePeriod == 'weekly' && !empty($selectedWeek)) {
        $weekParts = explode('-W', $selectedWeek);

        if (count($weekParts) >= 2) {
            $weekYear = $weekParts[0];
            $weekNumber = $weekParts[1];
            $startDate = date('Y-m-d', strtotime($weekYear . 'W' . str_pad($weekNumber, 2, '0', STR_PAD_LEFT)));
            $endDate = date('Y-m-d', strtotime($startDate . ' +6 days'));
        }
    }

    $condition = '';

    switch ($timePeriod) {
        case 'weekly':
            if (!empty($selectedWeek)) {
                $weekParts = explode('-W', $selectedWeek);
                $weekYear = $weekParts[0];
                $weekNumber = $weekParts[1];
                $condition = "AND YEARWEEK(st.transaction_date, 1) = {$weekYear}{$weekNumber}";
            } else {
                $condition = 'AND YEARWEEK(st.transaction_date, 1) = YEARWEEK(CURDATE(), 1)';
            }
            break;

        case 'monthly':
            if (!empty($selectedMonth)) {
                $monthParts = explode('-', $selectedMonth);
                $monthYear = $monthParts[0];
                $month = $monthParts[1];
                $condition = "AND YEAR(st.transaction_date) = {$monthYear} AND MONTH(st.transaction_date) = {$month}";
            } else {
                $condition = 'AND MONTH(st.transaction_date) = MONTH(CURDATE()) AND YEAR(st.transaction_date) = YEAR(CURDATE())';
            }
            break;

        case 'yearly':
            if (!empty($selectedYear)) {
                $condition = "AND YEAR(st.transaction_date) = {$selectedYear}";
            } else {
                $condition = 'AND YEAR(st.transaction_date) = YEAR(CURDATE())';
            }
            break;

        default: // daily
            if (!empty($selectedDate)) {
                $condition = "AND DATE(st.transaction_date) = '{$selectedDate}'";
            } else {
                $condition = 'AND DATE(st.transaction_date) = CURDATE()';
            }
    }

    return array(
        'savings_date_condition' => $condition,
        'start_date'             => $startDate,
        'end_date'               => $endDate,
    );
}

/**
 * Years that actually carry savings transactions, newest first.
 *
 * @param  mysqli $conn Open connection.
 * @return array<int, int>
 */
function sav_year_options(mysqli $conn)
{
    $years = array();

    try {
        $result = $conn->query(
            'SELECT DISTINCT YEAR(transaction_date) AS savings_year
               FROM savings_transactions
              WHERE transaction_date IS NOT NULL
              ORDER BY savings_year DESC'
        );

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $year = (int) $row['savings_year'];

                if ($year > 0) {
                    $years[] = $year;
                }
            }

            $result->free();
        }
    } catch (Throwable $e) {
        error_log('[savings-reports] years: ' . $e->getMessage());
    }

    if (empty($years)) {
        $years[] = (int) date('Y');
    }

    return $years;
}

/**
 * Human label for the selected transaction type.
 *
 * @param  string $type Selected type.
 * @return string
 */
function sav_transaction_type_label($type)
{
    if ($type == 'Deposit') {
        return 'Deposit';
    }

    if ($type == 'Withdrawal') {
        return 'Withdrawal (Payment)';
    }

    if ($type == 'Withdrawal Cash') {
        return 'Withdrawal (Cash)';
    }

    return htmlspecialchars($type);
}

/**
 * Add the per-row display values (name, badge, amount class) to a transaction.
 *
 * @param  array<string, mixed> $trans Transaction row.
 * @return array<string, mixed>
 */
function sav_decorate_transaction(array $trans)
{
    $middleInitial = !empty($trans['middle_name']) ? strtoupper($trans['middle_name'][0]) . '.' : '';
    $suffix = !empty($trans['suffix']) && strtolower($trans['suffix']) !== 'none' ? ' ' . htmlspecialchars($trans['suffix']) : '';

    $trans['full_name'] = htmlspecialchars($trans['last_name']) . ', ' .
        htmlspecialchars($trans['first_name']) . ' ' .
        $middleInitial .
        $suffix;

    $isDeposit = $trans['transaction_type'] == 'Deposit';

    $trans['is_deposit'] = $isDeposit;
    $trans['badge_class'] = $isDeposit ? 'deposit-badge' : 'withdrawal-badge';

    if ($trans['transaction_type'] == 'Deposit') {
        $trans['badge_text'] = 'Deposit';
    } elseif ($trans['transaction_type'] == 'Withdrawal Cash') {
        $trans['badge_text'] = 'Withdrawal (Cash)';
    } elseif ($trans['transaction_type'] == 'Withdrawal') {
        $trans['badge_text'] = 'Withdrawal (Payment)';
    } else {
        $trans['badge_text'] = htmlspecialchars($trans['transaction_type']);
    }

    if ($trans['transaction_type'] === 'Deposit') {
        $trans['type_slug'] = 'type-pill--deposit';
    } elseif ($trans['transaction_type'] === 'Withdrawal Cash') {
        $trans['type_slug'] = 'type-pill--cash';
    } else {
        $trans['type_slug'] = 'type-pill--withdrawal';
    }

    $trans['amount_class'] = $isDeposit ? 'positive' : 'negative';

    return $trans;
}

/**
 * Savings transactions for the filters, plus the summary figures.
 *
 * @param  mysqli               $conn      Open connection.
 * @param  array<string, string> $filters  Filter values.
 * @param  array<string, string> $dates    Result of sav_date_conditions().
 * @return array<string, mixed>
 */
function sav_transactions(mysqli $conn, array $filters, array $dates)
{
    $query = "SELECT
                st.id,
                st.transaction_type,
                st.amount,
                st.branch as transaction_branch,
                st.payment_method,
                st.transaction_date,
                st.collector_id,
                st.proxy,
                st.notes,
                st.saving_balance_after_payment,
                st.created_at,
                sa.id as savings_account_id,
                sa.account_number,
                sa.current_balance as savings_current_balance,
                ca.id as customer_id,
                ca.last_name,
                ca.first_name,
                ca.middle_name,
                ca.suffix,
                ca.area,
                ca.branch as customer_branch,
                ca.contact_number
            FROM savings_transactions st
            JOIN savings_accounts sa ON st.savings_account_id = sa.id
            JOIN customer_accounts ca ON sa.customer_id = ca.id
            WHERE 1=1";

    $params = array();
    $types = '';

    if (!empty($filters['selected_branch'])) {
        $query .= ' AND ca.branch = ?';
        $params[] = $filters['selected_branch'];
        $types .= 's';
    }

    if (!empty($filters['selected_area'])) {
        $query .= ' AND ca.area = ?';
        $params[] = $filters['selected_area'];
        $types .= 's';
    }

    if (!empty($filters['selected_transaction_type'])) {
        $query .= ' AND st.transaction_type = ?';
        $params[] = $filters['selected_transaction_type'];
        $types .= 's';
    }

    $query .= ' ' . $dates['savings_date_condition'];

    $query .= ' ORDER BY st.transaction_date DESC, st.created_at DESC';

    $stmt = $conn->prepare($query);

    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $transactions = array();
    $summary = array(
        'total_deposits'     => 0,
        'total_withdrawals'  => 0,
        'total_transactions' => 0,
        'unique_customers'   => 0,
    );

    $customerIds = array();

    while ($row = $result->fetch_assoc()) {
        $transactions[] = sav_decorate_transaction($row);

        if ($row['transaction_type'] == 'Deposit') {
            $summary['total_deposits'] += $row['amount'];
        } elseif ($row['transaction_type'] == 'Withdrawal' || $row['transaction_type'] == 'Withdrawal Cash') {
            $summary['total_withdrawals'] += $row['amount'];
        }

        $summary['total_transactions']++;

        if (!in_array($row['customer_id'], $customerIds)) {
            $customerIds[] = $row['customer_id'];
        }
    }

    $summary['unique_customers'] = count($customerIds);

    $stmt->close();

    return array('transactions' => $transactions, 'summary' => $summary);
}

/**
 * Everything the view needs, in one call.
 *
 * @return array<string, mixed>
 */
function sav_context()
{
    sav_start_session();
    sav_handle_logout();

    if (!sav_is_authenticated()) {
        sav_redirect('index.php');
        exit;
    }

    $conn = db();

    $filters = sav_filters();
    $dates = sav_date_conditions($filters);

    $user = array();
    $branchContext = array(
        'user_branch'           => '',
        'user_account_type'     => '',
        'disable_branch_filter' => false,
        'hide_branch_filter'    => false,
        'allowed_branches'      => array(),
        'selected_branch'       => '',
    );
    $transactions = array();
    $summary = array(
        'total_deposits' => 0,
        'total_withdrawals' => 0,
        'total_transactions' => 0,
        'unique_customers' => 0,
    );
    $error = null;

    try {
        $user = sav_fetch_user($conn);
        $branchContext = sav_branch_context($user, isset($_GET['branch']) ? (string) $_GET['branch'] : '');
    } catch (Throwable $e) {
        error_log('[savings-reports] ' . $e->getMessage());
    }

    // The branch selection feeds the query, so resolve it here.
    $filters['selected_branch'] = $branchContext['selected_branch'];

    try {
        $data = sav_transactions($conn, $filters, $dates);
        $transactions = $data['transactions'];
        $summary = $data['summary'];
    } catch (Throwable $e) {
        error_log('[savings-reports] ' . $e->getMessage());
        $error = 'Failed to load savings transactions. Please try again.';
    }

    return array_merge(
        array(
            'user'                    => $user,
            'branches'                => sav_branches(),
            'transactions'            => $transactions,
            'summary'                 => $summary,
            'error'                   => $error,
            'transaction_type_label'  => sav_transaction_type_label($filters['selected_transaction_type']),
            'layout_menu'             => 'admin',
            'years'                   => sav_year_options($conn),
        ),
        $branchContext,
        $filters,
        $dates
    );
}
