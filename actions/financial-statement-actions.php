<?php
/**
 * Prime City - Financial statement report actions.
 *
 * Server-side logic for financial_statement_report.php: the signed-in account,
 * branch scoping, the aggregate summary query and the six detailed report
 * queries (loans, savings, collections, daily/weekly/monthly collections).
 *
 * Read-only page.
 *
 * Produces no markup - the page includes it and renders.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Name / area / branch caches, filled once per request.
 *
 * The lookup helpers below read them through `global`, which works because this
 * file is included at script scope - the same contract the original page used.
 */
$customerCache = array();
$collectorCache = array();
$areaCache = array();
$branchCache = array();

/**
 * Start the session once.
 *
 * @return void
 */
function fs_start_session()
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
function fs_is_authenticated()
{
    return isset($_SESSION['logged_in']);
}

/**
 * Redirect, falling back to JS when headers are already sent.
 *
 * @param  string $location Target path.
 * @return void
 */
function fs_redirect($location)
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
function fs_sign_out()
{
    fs_start_session();

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
function fs_handle_logout()
{
    if (!isset($_GET['logout'])) {
        return;
    }

    fs_sign_out();
    fs_redirect('index.php');
    exit;
}

/**
 * The branches a report may cover.
 *
 * @return array<int, string>
 */
function fs_branches()
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
function fs_fetch_user(mysqli $conn)
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
function fs_branch_context(array $user, $request)
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
        'user_branch'          => $userBranch,
        'user_account_type'    => $accountType,
        'disable_branch_filter' => $disable,
        'hide_branch_filter'   => $hide,
        'allowed_branches'     => $allowed,
        'selected_branch'      => $selected,
    );
}

/**
 * Fill the customer / collector / area / branch caches in one pass each.
 *
 * @param  mysqli $conn Open connection.
 * @return void
 */
function fs_preload_customer_data(mysqli $conn)
{
    global $customerCache, $collectorCache, $areaCache, $branchCache;

    try {
        $result = $conn->query('SELECT id, CONCAT(first_name, " ", last_name) as name, area, branch FROM customer_accounts');

        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $customerCache[$row['id']] = $row['name'];
                $areaCache[$row['id']] = $row['area'];
                $branchCache[$row['id']] = $row['branch'];
            }
        }

        $result = $conn->query('SELECT id, CONCAT(first_name, " ", last_name) as name FROM collector_accounts');

        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $collectorCache[$row['id']] = $row['name'];
            }
        }
    } catch (Throwable $e) {
        error_log('[financial-statement] ' . $e->getMessage());
    }
}

/**
 * Customer name from the cache.
 *
 * @param  mixed $customer_id Customer primary key.
 * @return string
 */
function getCustomerName($customer_id)
{
    global $customerCache;

    return $customerCache[$customer_id] ?? 'Unknown Customer';
}

/**
 * Collector name from the cache.
 *
 * @param  mixed $collector_id Collector primary key.
 * @return string
 */
function getCollectorName($collector_id)
{
    global $collectorCache;

    return $collectorCache[$collector_id] ?? 'Unknown Collector';
}

/**
 * Customer area from the cache.
 *
 * @param  mixed $customer_id Customer primary key.
 * @return string
 */
function getCustomerArea($customer_id)
{
    global $areaCache;

    return $areaCache[$customer_id] ?? 'Unknown Area';
}

/**
 * Customer branch from the cache.
 *
 * @param  mixed $customer_id Customer primary key.
 * @return string
 */
function getCustomerBranch($customer_id)
{
    global $branchCache;

    return $branchCache[$customer_id] ?? 'Unknown Branch';
}

/**
 * The ten summary metrics, all zeroed.
 *
 * @return array<string, int>
 */
function fs_empty_financial_data()
{
    return array(
        'total_amount_financed' => 0,
        'total_interest_peso'   => 0,
        'total_service_fee'     => 0,
        'total_customer_net'    => 0,
        'total_balance'         => 0,
        'total_savings'         => 0,
        'total_collections'     => 0,
        'daily_collections'     => 0,
        'weekly_collections'    => 0,
        'monthly_collections'   => 0,
    );
}

/**
 * The ten summary metrics, from one UNION round trip.
 *
 * @param  mysqli $conn           Open connection.
 * @param  string $selectedBranch Branch filter ('all' / '' for none).
 * @return array<string, float>
 */
function fs_financial_data(mysqli $conn, $selectedBranch)
{
    $financialData = fs_empty_financial_data();

    $branchWhere = '';

    if (!empty($selectedBranch) && $selectedBranch != 'all') {
        $branchWhere = ' WHERE branch = ?';
    }

    $sql = "SELECT
                'amount_financed' as metric, SUM(amount_financed) as value FROM financing" . $branchWhere . "
            UNION ALL
            SELECT
                'interest_peso' as metric, SUM(interest_peso) as value FROM financing" . $branchWhere . "
            UNION ALL
            SELECT
                'service_fee' as metric, SUM(service_fee) as value FROM financing" . $branchWhere . "
            UNION ALL
            SELECT
                'customer_net' as metric, SUM(customer_net) as value FROM financing" . $branchWhere . "
            UNION ALL
            SELECT
                'balance' as metric, SUM(balance) as value FROM financing" . $branchWhere . "
            UNION ALL
            SELECT
                'savings' as metric, SUM(current_balance) as value FROM savings_accounts WHERE status = 'Active'" .
                (!empty($selectedBranch) && $selectedBranch != 'all' ? ' AND branch = ?' : '') . "
            UNION ALL
            SELECT
                'total_collections' as metric, SUM(amount) as value FROM payments" .
                (!empty($selectedBranch) && $selectedBranch != 'all' ? ' WHERE branch = ?' : '') . "
            UNION ALL
            SELECT
                'daily_collections' as metric, SUM(amount) as value FROM payments WHERE DATE(payment_date) = CURDATE()" .
                (!empty($selectedBranch) && $selectedBranch != 'all' ? ' AND branch = ?' : '') . "
            UNION ALL
            SELECT
                'weekly_collections' as metric, SUM(amount) as value FROM payments WHERE YEARWEEK(payment_date, 1) = YEARWEEK(CURDATE(), 1)" .
                (!empty($selectedBranch) && $selectedBranch != 'all' ? ' AND branch = ?' : '') . "
            UNION ALL
            SELECT
                'monthly_collections' as metric, SUM(amount) as value FROM payments WHERE MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE())" .
                (!empty($selectedBranch) && $selectedBranch != 'all' ? ' AND branch = ?' : '') . '';

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    if (!empty($selectedBranch) && $selectedBranch != 'all') {
        // The branch placeholder appears ten times in the union.
        $allParams = array();
        $allTypes = '';

        for ($i = 0; $i < 10; $i++) {
            $allParams[] = $selectedBranch;
            $allTypes .= 's';
        }

        $stmt->bind_param($allTypes, ...$allParams);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $map = array(
            'amount_financed'    => 'total_amount_financed',
            'interest_peso'      => 'total_interest_peso',
            'service_fee'        => 'total_service_fee',
            'customer_net'       => 'total_customer_net',
            'balance'            => 'total_balance',
            'savings'            => 'total_savings',
            'total_collections'  => 'total_collections',
            'daily_collections'  => 'daily_collections',
            'weekly_collections' => 'weekly_collections',
            'monthly_collections' => 'monthly_collections',
        );

        while ($row = $result->fetch_assoc()) {
            if (isset($map[$row['metric']])) {
                $financialData[$map[$row['metric']]] = $row['value'] ?? 0;
            }
        }
    }

    $stmt->close();

    return $financialData;
}

/**
 * Per-loan payment totals, one row per loan.
 *
 * @param  mysqli $conn Open connection.
 * @return array<int, array<string, mixed>>
 */
function fs_loan_payment_data(mysqli $conn)
{
    $data = array();

    try {
        $result = $conn->query('SELECT loan_id, SUM(amount) as total_payments, COUNT(*) as payment_count FROM payments GROUP BY loan_id');

        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $data[$row['loan_id']] = array(
                    'total_payments' => $row['total_payments'],
                    'payment_count'  => $row['payment_count'],
                );
            }
        }
    } catch (Throwable $e) {
        error_log('[financial-statement] ' . $e->getMessage());
    }

    return $data;
}

/**
 * Run a report query and return all rows.
 *
 * @param  mysqli $conn   Open connection.
 * @param  string $sql    Query with optional trailing placeholders.
 * @param  string $types  bind_param types.
 * @param  array<int, mixed> $params Bound values.
 * @return array<int, array<string, mixed>>
 */
function fs_fetch_all(mysqli $conn, $sql, $types = '', array $params = array())
{
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $rows = array();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    $stmt->close();

    return $rows;
}

/**
 * Loans tab: financing rows, optionally narrowed by area and branch.
 *
 * @param  mysqli $conn   Open connection.
 * @param  string $area   Area filter.
 * @param  string $branch Branch filter ('all' / '' for none).
 * @return array<int, array<string, mixed>>
 */
function fs_loan_report_rows(mysqli $conn, $area, $branch)
{
    $sql = "SELECT
                f.id,
                f.customer_id,
                f.customer_name,
                f.amount_financed,
                f.interest_peso,
                f.service_fee,
                f.balance,
                f.daily_payment,
                f.start_date,
                f.status,
                f.branch
            FROM financing f";

    $conditions = array();
    $params = array();
    $types = '';

    if (!empty($area)) {
        $conditions[] = 'f.customer_id IN (SELECT id FROM customer_accounts WHERE area = ?)';
        $params[] = $area;
        $types .= 's';
    }

    if (!empty($branch) && $branch != 'all') {
        $conditions[] = 'f.branch = ?';
        $params[] = $branch;
        $types .= 's';
    }

    if (!empty($conditions)) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }

    $sql .= ' ORDER BY f.customer_name';

    return fs_fetch_all($conn, $sql, $types, $params);
}

/**
 * Savings tab.
 *
 * @param  mysqli $conn   Open connection.
 * @param  string $branch Branch filter.
 * @return array<int, array<string, mixed>>
 */
function fs_savings_rows(mysqli $conn, $branch)
{
    $sql = "SELECT
                sa.account_number,
                sa.customer_id,
                sa.current_balance,
                sa.status,
                sa.branch
            FROM savings_accounts sa";

    $params = array();
    $types = '';

    if (!empty($branch) && $branch != 'all') {
        $sql .= ' WHERE sa.branch = ?';
        $params[] = $branch;
        $types .= 's';
    }

    $sql .= ' ORDER BY sa.account_number';

    return fs_fetch_all($conn, $sql, $types, $params);
}

/**
 * Collections tab (capped at 1000 rows).
 *
 * @param  mysqli $conn   Open connection.
 * @param  string $branch Branch filter.
 * @return array<int, array<string, mixed>>
 */
function fs_collections_rows(mysqli $conn, $branch)
{
    $sql = "SELECT
                p.loan_id,
                p.customer_id,
                p.collector_id,
                p.amount,
                p.payment_date,
                p.payment_method,
                p.branch
            FROM payments p";

    $params = array();
    $types = '';

    if (!empty($branch) && $branch != 'all') {
        $sql .= ' WHERE p.branch = ?';
        $params[] = $branch;
        $types .= 's';
    }

    $sql .= ' ORDER BY p.payment_date DESC LIMIT 1000';

    return fs_fetch_all($conn, $sql, $types, $params);
}

/**
 * Daily collections tab (capped at 100 rows).
 *
 * @param  mysqli $conn   Open connection.
 * @param  string $branch Branch filter.
 * @return array<int, array<string, mixed>>
 */
function fs_daily_collections(mysqli $conn, $branch)
{
    $sql = "SELECT
                DATE(payment_date) as payment_day,
                branch,
                SUM(amount) as daily_total,
                COUNT(*) as payment_count
            FROM payments";

    $params = array();
    $types = '';

    if (!empty($branch) && $branch != 'all') {
        $sql .= ' WHERE branch = ?';
        $params[] = $branch;
        $types .= 's';
    }

    $sql .= ' GROUP BY DATE(payment_date), branch ORDER BY payment_day DESC LIMIT 100';

    return fs_fetch_all($conn, $sql, $types, $params);
}

/**
 * Weekly collections tab (capped at 100 rows).
 *
 * @param  mysqli $conn   Open connection.
 * @param  string $branch Branch filter.
 * @return array<int, array<string, mixed>>
 */
function fs_weekly_collections(mysqli $conn, $branch)
{
    $sql = "SELECT
                YEAR(payment_date) as year,
                WEEK(payment_date, 1) as week,
                branch,
                SUM(amount) as weekly_total,
                COUNT(*) as payment_count
            FROM payments";

    $params = array();
    $types = '';

    if (!empty($branch) && $branch != 'all') {
        $sql .= ' WHERE branch = ?';
        $params[] = $branch;
        $types .= 's';
    }

    $sql .= ' GROUP BY YEAR(payment_date), WEEK(payment_date, 1), branch ORDER BY year DESC, week DESC LIMIT 100';

    return fs_fetch_all($conn, $sql, $types, $params);
}

/**
 * Monthly collections tab (capped at 100 rows).
 *
 * @param  mysqli $conn   Open connection.
 * @param  string $branch Branch filter.
 * @return array<int, array<string, mixed>>
 */
function fs_monthly_collections(mysqli $conn, $branch)
{
    $sql = "SELECT
                MONTH(payment_date) as month,
                YEAR(payment_date) as year,
                branch,
                SUM(amount) as monthly_total,
                COUNT(*) as payment_count
            FROM payments";

    $params = array();
    $types = '';

    if (!empty($branch) && $branch != 'all') {
        $sql .= ' WHERE branch = ?';
        $params[] = $branch;
        $types .= 's';
    }

    $sql .= ' GROUP BY YEAR(payment_date), MONTH(payment_date), branch ORDER BY year DESC, month DESC LIMIT 100';

    return fs_fetch_all($conn, $sql, $types, $params);
}

/**
 * Everything the view needs, in one call.
 *
 * @return array<string, mixed>
 */
function fs_context()
{
    fs_start_session();
    fs_handle_logout();

    if (!fs_is_authenticated()) {
        fs_redirect('index.php');
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
    $financialData = fs_empty_financial_data();
    $loanPaymentData = array();

    try {
        $user = fs_fetch_user($conn);
        $branchContext = fs_branch_context($user, isset($_GET['branch_filter']) ? (string) $_GET['branch_filter'] : '');
        $financialData = fs_financial_data($conn, $branchContext['selected_branch']);
    } catch (Throwable $e) {
        error_log('[financial-statement] ' . $e->getMessage());
    }

    fs_preload_customer_data($conn);
    $loanPaymentData = fs_loan_payment_data($conn);

    return array(
        'conn'                  => $conn,
        'user'                  => $user,
        'user_branch'           => $branchContext['user_branch'],
        'user_account_type'     => $branchContext['user_account_type'],
        'disable_branch_filter' => $branchContext['disable_branch_filter'],
        'hide_branch_filter'    => $branchContext['hide_branch_filter'],
        'allowed_branches'      => $branchContext['allowed_branches'],
        'selected_branch'       => $branchContext['selected_branch'],
        'selected_area'         => isset($_GET['area_filter']) ? (string) $_GET['area_filter'] : '',
        'branches'              => fs_branches(),
        'financialData'         => $financialData,
        'loanPaymentData'       => $loanPaymentData,
        'layout_menu'           => 'admin',
    );
}
