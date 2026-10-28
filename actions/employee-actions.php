<?php
/**
 * Prime City - Employee list actions.
 *
 * Server-side logic for employee_lists.php: authentication, branch scoping,
 * the three employee listings (collectors, proxy accounts, accounts), active
 * deduction badges and tab visibility.
 *
 * Produces no markup - the page includes it and renders.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Account types that can only see their own branch.
 *
 * @return array<int, string>
 */
function employee_restricted_account_types()
{
    return array('Area Manager', 'Branch Manager', 'Proxy Collector-Cashier', 'Proxy Collector');
}

/**
 * Branches offered in the filter for admins.
 *
 * Kept exactly as the original page had it.
 *
 * @return array<int, string>
 */
function employee_admin_branches()
{
    return array('Apayao');
}

/**
 * Start the session once.
 *
 * @return void
 */
function employee_start_session()
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
function employee_is_authenticated()
{
    return isset($_SESSION['logged_in'], $_SESSION['user_id'])
        && $_SESSION['logged_in'] === true;
}

/**
 * Redirect, falling back to JS when headers are already sent.
 *
 * @param  string $location Target path.
 * @return void
 */
function employee_redirect($location)
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
function employee_sign_out()
{
    employee_start_session();

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
function employee_handle_logout()
{
    if (!isset($_GET['logout'])) {
        return;
    }

    employee_sign_out();
    employee_redirect('index.php');
    exit;
}

/**
 * Map `account_source` to the table holding that account.
 *
 * @param  string $source Session account source.
 * @return string
 */
function employee_table_for_source($source)
{
    $tables = array(
        'accounts'           => 'accounts',
        'collector_accounts' => 'collector_accounts',
        'proxy_accounts'     => 'proxy_accounts',
    );

    return isset($tables[$source]) ? $tables[$source] : 'accounts';
}

/**
 * Load the signed-in account from the table that owns it.
 *
 * @param  mysqli $conn Open connection.
 * @return array<string, mixed>
 *
 * @throws RuntimeException When the account no longer exists.
 */
function employee_fetch_user(mysqli $conn)
{
    $source = $_SESSION['account_source'] ?? 'accounts';
    $table = employee_table_for_source($source);
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    $stmt = $conn->prepare(
        "SELECT first_name, middle_name, last_name, suffix, account_type, branch
         FROM {$table}
         WHERE id = ?
         LIMIT 1"
    );

    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $result = $stmt->get_result();
    $user = $result ? $result->fetch_assoc() : null;

    if ($result) {
        $result->free();
    }
    $stmt->close();

    if (!$user) {
        throw new RuntimeException('User not found for id ' . $userId);
    }

    return $user;
}

/**
 * Work out the branch filter for the current user.
 *
 * @param  array<string, mixed> $user            Current user record.
 * @param  string               $userTable       Table the account came from.
 * @param  mixed                $requestedBranch Raw `?branch=` value.
 * @return array<string, mixed>
 */
function employee_branch_state(array $user, $userTable, $requestedBranch)
{
    $accountType = (string) ($user['account_type'] ?? '');
    $userBranch = (string) ($user['branch'] ?? '');

    $isRestricted = in_array($accountType, employee_restricted_account_types(), true);

    $showFilter = false;
    $showAllOption = false;
    $available = array();

    if ($accountType === 'Admin' && $userTable === 'accounts') {
        $showFilter = true;
        $showAllOption = true;
        $available = employee_admin_branches();
    } elseif ($accountType === 'Area Manager' && $userTable === 'accounts') {
        $showFilter = true;
        $showAllOption = false;

        if ($userBranch !== '') {
            $available = array_values(array_filter(array_map('trim', explode(',', $userBranch))));
        }
    }

    // The original page escaped the raw value here and escaped it again when
    // printing; keep the same behaviour so the rendered filter is unchanged.
    $branchFilter = is_string($requestedBranch) && $requestedBranch !== ''
        ? htmlspecialchars($requestedBranch)
        : '';

    if ($userTable === 'proxy_accounts' || ($isRestricted && $userBranch !== '' && $accountType !== 'Area Manager')) {
        $branchFilter = $userBranch;
    }

    if ($accountType === 'Area Manager' && $userTable === 'accounts') {
        if ($branchFilter === '') {
            if ($available) {
                $branchFilter = $available[0];
            }
        } elseif (!in_array($branchFilter, $available, true)) {
            $branchFilter = $available ? $available[0] : '';
        }
    }

    return array(
        'is_restricted'            => $isRestricted,
        'show_branch_filter'       => $showFilter,
        'show_all_branches_option' => $showAllOption,
        'available_branches'       => $available,
        'branch_filter'            => $branchFilter,
    );
}

/**
 * Run a prepared statement and return every row.
 *
 * @param  mysqli            $conn   Open connection.
 * @param  string            $sql    Statement with `?` placeholders.
 * @param  array<int, mixed> $params Values for the placeholders.
 * @param  string            $types  mysqli type string; guessed when empty.
 * @return array<int, array<string, mixed>>
 */
function employee_rows(mysqli $conn, $sql, array $params = array(), $types = '')
{
    $stmt = $conn->prepare($sql);

    if ($params) {
        if ($types === '') {
            $types = str_repeat('s', count($params));
        }
        $stmt->bind_param($types, ...array_values($params));
    }

    $stmt->execute();

    $result = $stmt->get_result();
    $rows = array();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
    }

    $stmt->close();

    return $rows;
}

/**
 * Branch restriction fragment for one of the listing queries.
 *
 * @param  string               $alias  Table alias used in the query.
 * @param  array<string, mixed> $user   Current user record.
 * @param  string               $table  Table the account came from.
 * @param  array<string, mixed> $state  Result of employee_branch_state().
 * @return array{0: string, 1: array<int, string>}
 */
function employee_branch_clause($alias, array $user, $table, array $state)
{
    $accountType = (string) ($user['account_type'] ?? '');
    $userBranch = (string) ($user['branch'] ?? '');
    $branchFilter = (string) $state['branch_filter'];
    $available = $state['available_branches'];

    if ($table === 'proxy_accounts' && $userBranch !== '') {
        return array(' AND ' . $alias . '.branch = ?', array($userBranch));
    }

    if ($state['is_restricted'] && $table === 'accounts' && $accountType !== 'Area Manager' && $userBranch !== '') {
        return array(' AND ' . $alias . '.branch = ?', array($userBranch));
    }

    if ($accountType === 'Area Manager' && $table === 'accounts') {
        if ($branchFilter !== '' && in_array($branchFilter, $available, true)) {
            return array(' AND ' . $alias . '.branch = ?', array($branchFilter));
        }

        return array('', array());
    }

    if ($branchFilter !== '') {
        return array(' AND ' . $alias . '.branch = ?', array($branchFilter));
    }

    return array('', array());
}

/**
 * Salary sub-select used by all three listings.
 *
 * @param  string $alias Table alias.
 * @param  string $source `source_table` value in salary_history.
 * @return string
 */
function employee_salary_columns($alias, $source)
{
    $source = str_replace("'", "", $source);

    return ",
            (SELECT daily_salary FROM salary_history
             WHERE account_id = {$alias}.id AND source_table = '{$source}'
             AND effective_date <= CURDATE()
             ORDER BY effective_date DESC, id DESC LIMIT 1) as current_salary,
            (SELECT effective_date FROM salary_history
             WHERE account_id = {$alias}.id AND source_table = '{$source}'
             AND effective_date <= CURDATE()
             ORDER BY effective_date DESC, id DESC LIMIT 1) as salary_effective_date";
}

/**
 * Collector accounts with current salary.
 *
 * @param  mysqli               $conn  Open connection.
 * @param  array<string, mixed> $user  Current user record.
 * @param  string               $table Table the account came from.
 * @param  array<string, mixed> $state Branch state.
 * @return array<int, array<string, mixed>>
 */
function employee_fetch_collectors(mysqli $conn, array $user, $table, array $state)
{
    list($clause, $params) = employee_branch_clause('c', $user, $table, $state);

    $sql = 'SELECT c.*' . employee_salary_columns('c', 'collector')
        . ' FROM collector_accounts c WHERE 1=1' . $clause
        . ' ORDER BY c.last_name, c.first_name';

    return employee_rows($conn, $sql, $params);
}

/**
 * Proxy accounts with current salary.
 *
 * @param  mysqli               $conn  Open connection.
 * @param  array<string, mixed> $user  Current user record.
 * @param  string               $table Table the account came from.
 * @param  array<string, mixed> $state Branch state.
 * @return array<int, array<string, mixed>>
 */
function employee_fetch_proxies(mysqli $conn, array $user, $table, array $state)
{
    list($clause, $params) = employee_branch_clause('p', $user, $table, $state);

    $sql = 'SELECT p.*' . employee_salary_columns('p', 'proxy')
        . ' FROM proxy_accounts p WHERE 1=1' . $clause
        . ' ORDER BY p.last_name, p.first_name';

    return employee_rows($conn, $sql, $params);
}

/**
 * Management/office accounts with current salary.
 *
 * @param  mysqli               $conn  Open connection.
 * @param  array<string, mixed> $user  Current user record.
 * @param  string               $table Table the account came from.
 * @param  array<string, mixed> $state Branch state.
 * @return array<int, array<string, mixed>>
 */
function employee_fetch_accounts(mysqli $conn, array $user, $table, array $state)
{
    list($clause, $params) = employee_branch_clause('a', $user, $table, $state);

    $sql = 'SELECT a.*' . employee_salary_columns('a', 'accounts')
        . ' FROM accounts a WHERE 1=1' . $clause
        . ' ORDER BY a.registration_date DESC';

    return employee_rows($conn, $sql, $params);
}

/**
 * Active deduction types keyed by employee, loaded once per type.
 *
 * Replaces one query per rendered row with one query per employee type.
 *
 * @param  string $employeeType collector|proxy|accounts.
 * @return array<int, array<int, string>>
 */
function employee_deductions_map($employeeType)
{
    static $cache = array();

    if (array_key_exists($employeeType, $cache)) {
        return $cache[$employeeType];
    }

    $map = array();

    try {
        // Ordered by id and de-duplicated on first appearance, which is the
        // order the original per-row `SELECT DISTINCT deduction_type` returned.
        $stmt = db()->prepare(
            'SELECT employee_id, deduction_type
             FROM employee_deductions
             WHERE employee_type = ? AND is_active = 1
             ORDER BY id'
        );

        $stmt->bind_param('s', $employeeType);
        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $id = (int) $row['employee_id'];

            if (!isset($map[$id])) {
                $map[$id] = array();
            }

            if (!in_array($row['deduction_type'], $map[$id], true)) {
                $map[$id][] = (string) $row['deduction_type'];
            }
        }

        $stmt->close();
    } catch (Throwable $e) {
        error_log('[employee-list] ' . $e->getMessage());
    }

    $cache[$employeeType] = $map;

    return $map;
}

/**
 * Unique active deduction types for one employee.
 *
 * Kept with its original name and signature because the view calls it per row.
 *
 * @param  int    $employee_id   Employee primary key.
 * @param  string $employee_type collector|proxy|accounts.
 * @return array<int, string>
 */
function getEmployeeUniqueActiveDeductions($employee_id, $employee_type)
{
    $map = employee_deductions_map($employee_type);
    $id = (int) $employee_id;

    return isset($map[$id]) ? $map[$id] : array();
}

/**
 * Which tabs the current account may see, and which one opens first.
 *
 * @param  array<string, mixed> $user    Current user record.
 * @param  string               $table   Table the account came from.
 * @param  bool                 $isAdmin Admin from the accounts table.
 * @return array<string, mixed>
 */
function employee_tab_state(array $user, $table, $isAdmin)
{
    $accountType = (string) ($user['account_type'] ?? '');

    $isProxyCollectorOnly = ($accountType === 'Proxy Collector' && $table === 'proxy_accounts');
    $isProxyCashierUser = ($accountType === 'Proxy Collector-Cashier' && $table === 'proxy_accounts');
    $isCollectorOnly = ($accountType === 'Collector' && $table === 'collector_accounts');
    $isAdminOrManager = ($isAdmin || $accountType === 'Area Manager' || $accountType === 'Branch Manager');

    $showCollectorTab = false;
    $showProxyTab = false;
    $showAccountsTab = false;
    $activeTab = 'collector';

    if ($isProxyCollectorOnly) {
        $showProxyTab = true;
        $activeTab = 'proxy';
    } elseif ($isCollectorOnly) {
        $showCollectorTab = true;
        $activeTab = 'collector';
    } elseif ($isProxyCashierUser) {
        $showCollectorTab = true;
        $showProxyTab = true;
        $activeTab = 'collector';
    } elseif ($isAdminOrManager) {
        $showCollectorTab = true;
        $showProxyTab = true;
        if ($isAdmin) {
            $showAccountsTab = true;
        }
        $activeTab = 'collector';
    } else {
        $showCollectorTab = true;
        $activeTab = 'collector';
    }

    return array(
        'show_collector_tab' => $showCollectorTab,
        'show_proxy_tab'     => $showProxyTab,
        'show_accounts_tab'  => $showAccountsTab,
        'active_tab'         => $activeTab,
    );
}

/**
 * Navigation variant for includes/sidebar.php.
 *
 * The legacy page used collector_menu.php for anyone present in
 * collector_accounts, and for Proxy Collector accounts from proxy_accounts.
 *
 * @param  array<string, mixed> $user  Current user record.
 * @param  string               $table Table the account came from.
 * @return string
 */
function employee_menu_variant(array $user, $table)
{
    if ($table === 'collector_accounts' || ($user['account_type'] ?? '') === 'Proxy Collector') {
        return 'collector';
    }

    return 'admin';
}

/**
 * Everything the employee list view needs, in one call.
 *
 * @return array<string, mixed>
 *
 * @throws RuntimeException When the account or database is unavailable.
 */
function employee_context()
{
    employee_start_session();

    if (!employee_is_authenticated()) {
        employee_redirect('index.php');
        exit;
    }

    $conn = db();
    $user = employee_fetch_user($conn);

    $table = employee_table_for_source($_SESSION['account_source'] ?? 'accounts');
    $userBranch = (string) ($user['branch'] ?? '');
    $accountType = (string) ($user['account_type'] ?? '');

    $isAdmin = ($accountType === 'Admin' && $table === 'accounts');
    $isProxyCashier = ($accountType === 'Proxy Collector-Cashier');

    $state = employee_branch_state($user, $table, isset($_GET['branch']) ? $_GET['branch'] : null);

    $errorMessage = '';

    $collectors = array();
    $proxyAccounts = array();
    $accounts = array();

    try {
        $collectors = employee_fetch_collectors($conn, $user, $table, $state);
    } catch (Throwable $e) {
        error_log('[employee-list] ' . $e->getMessage());
    }

    try {
        $proxyAccounts = employee_fetch_proxies($conn, $user, $table, $state);
    } catch (Throwable $e) {
        error_log('[employee-list] ' . $e->getMessage());
    }

    try {
        $accounts = employee_fetch_accounts($conn, $user, $table, $state);
    } catch (Throwable $e) {
        error_log('[employee-list] ' . $e->getMessage());
        $errorMessage = 'Error retrieving accounts: ' . $e->getMessage();
    }

    $tabs = employee_tab_state($user, $table, $isAdmin);

    return array(
        'user'                    => $user,
        'user_table'              => $table,
        'user_branch'             => $userBranch,
        'is_admin'                => $isAdmin,
        'is_proxy_cashier'        => $isProxyCashier,
        'is_restricted_user'      => $state['is_restricted'],
        'show_branch_filter'      => $state['show_branch_filter'],
        'show_all_branches_option' => $state['show_all_branches_option'],
        'available_branches'      => $state['available_branches'],
        'branch_filter'           => $state['branch_filter'],
        'collectors'              => $collectors,
        'proxy_accounts'          => $proxyAccounts,
        'accounts'                => $accounts,
        'show_collector_tab'      => $tabs['show_collector_tab'],
        'show_proxy_tab'          => $tabs['show_proxy_tab'],
        'show_accounts_tab'       => $tabs['show_accounts_tab'],
        'active_tab'              => $tabs['active_tab'],
        'layout_menu'             => employee_menu_variant($user, $table),
        'success_message'         => '',
        'error_message'           => $errorMessage,
        'endpoint'                => 'api/employee-endpoint.php',
    );
}
