<?php
/**
 * Prime City - Multiple customer payments actions.
 *
 * Server-side logic for customers_multiple_payment.php: the signed-in user and
 * their role, the branch they may work in, the collector/proxy lists and the
 * CSRF token.
 *
 * The page talks to api/payments.php and process_payments.php for everything
 * else - those endpoints already exist and are left untouched.
 *
 * Produces no markup - the page includes it and renders.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Output escaping used throughout the markup.
 *
 * Kept with its original name because the view calls escape() directly.
 *
 * @param  mixed $value Value to escape.
 * @return string
 */
if (!function_exists('escape')) {
    function escape($value)
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Start the session once.
 *
 * @return void
 */
function cmp_start_session()
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
function cmp_is_authenticated()
{
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

/**
 * Redirect, falling back to JS when headers are already sent.
 *
 * @param  string $location Target path.
 * @return void
 */
function cmp_redirect($location)
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
function cmp_sign_out()
{
    cmp_start_session();

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
function cmp_handle_logout()
{
    if (!isset($_GET['logout'])) {
        return;
    }

    cmp_sign_out();
    cmp_redirect('index.php');
    exit;
}

/**
 * Brute-force escaping helper that matches includes/config.php.
 *
 * @param  mixed $value Value to escape.
 * @return string
 */
function cmp_escape($value)
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Load the signed-in account and the role flags that go with it.
 *
 * @param  mysqli $conn Open connection.
 * @return array<string, mixed>
 *
 * @throws RuntimeException When the account source or the account is invalid.
 */
function cmp_fetch_identity(mysqli $conn)
{
    $source = $_SESSION['account_source'] ?? 'accounts';
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    $tables = array(
        'accounts'           => 'accounts',
        'proxy_accounts'     => 'proxy_accounts',
        'collector_accounts' => 'collector_accounts',
    );

    if (!isset($tables[$source])) {
        throw new RuntimeException('Invalid account source');
    }

    $isProxyUser = ($source === 'proxy_accounts');
    $isCollectorUser = ($source === 'collector_accounts');
    $isProxyCollector = $isProxyUser && (($_SESSION['account_type'] ?? '') === 'Proxy Collector');

    $stmt = $conn->prepare('SELECT first_name, middle_name, last_name, suffix, account_type, branch FROM ' . $tables[$source] . ' WHERE id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $result = $stmt->get_result();
    $user = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$user) {
        throw new RuntimeException('User not found');
    }

    return array(
        'user'               => $user,
        'user_branch'        => $user['branch'] ?? '',
        'user_type'          => $user['account_type'] ?? '',
        'is_proxy_user'      => $isProxyUser,
        'is_collector_user'  => $isCollectorUser,
        'is_proxy_collector' => $isProxyCollector,
    );
}

/**
 * Branches the current account may work in.
 *
 * @param  string $userType   Account type.
 * @param  string $userBranch Branch column.
 * @return array<int, string>
 */
function cmp_available_branches($userType, $userBranch)
{
    $allBranches = array('Tuguegarao City', 'Gattaran', 'Abulug', 'Ilagan');
    $available = $allBranches;

    if ($userType === 'Admin') {
        return $allBranches;
    }

    if ($userType === 'Area Manager') {
        if ($userBranch !== '') {
            $userBranches = array_map('trim', explode(',', $userBranch));
            $available = array_intersect($allBranches, $userBranches);

            if (empty($available)) {
                $available = $allBranches;
            }
        }

        return array_values($available);
    }

    $restricted = array('Branch Manager', 'Proxy Collector-Cashier', 'Proxy Collector', 'Collector');

    if (in_array($userType, $restricted, true) && $userBranch !== '') {
        return array($userBranch);
    }

    return $available;
}

/**
 * Active collectors, optionally limited to one branch.
 *
 * @param  mysqli      $conn   Open connection.
 * @param  string|null $branch Branch filter.
 * @return array<int, array<string, mixed>>
 */
function cmp_fetch_collectors(mysqli $conn, $branch)
{
    $sql = 'SELECT id, first_name, middle_name, last_name, suffix, area, branch
            FROM collector_accounts
            WHERE account_status = \'Active\'';

    if ($branch) {
        $sql .= ' AND branch = ?';
    }

    $sql .= $branch ? ' ORDER BY last_name, first_name' : ' ORDER BY branch, first_name, last_name';

    $stmt = $conn->prepare($sql);

    if ($branch) {
        $stmt->bind_param('s', $branch);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $collectors = array();

    while ($row = $result->fetch_assoc()) {
        $name = $row['first_name'];

        if (!empty($row['middle_name'])) {
            $name .= ' ' . $row['middle_name'];
        }

        $name .= ' ' . $row['last_name'];

        if (!empty($row['suffix']) && $row['suffix'] != 'None') {
            $name .= ' ' . $row['suffix'];
        }

        $collectors[] = array(
            'id'     => $row['id'],
            'name'   => trim($name),
            'area'   => $row['area'],
            'branch' => $row['branch'] ?? '',
        );
    }

    $stmt->close();

    return $collectors;
}

/**
 * Proxy accounts that can be attached to a payment.
 *
 * @param  mysqli $conn Open connection.
 * @return array<int, array<string, mixed>>
 */
function cmp_fetch_proxy_accounts(mysqli $conn)
{
    $proxyAccounts = array();

    try {
        $result = $conn->query("SELECT id, CONCAT(first_name, ' ', last_name) AS name, area, branch FROM proxy_accounts WHERE account_type IN ('Proxy Collector', 'Proxy Collector-Cashier')");

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $proxyAccounts[] = $row;
            }
        }
    } catch (Throwable $e) {
        error_log('[customers-multiple-payment] ' . $e->getMessage());
    }

    return $proxyAccounts;
}

/**
 * Which chrome the page renders.
 *
 * The original included proxy_menu.php for proxy cashiers, but that file does
 * not exist - those users got no navigation at all plus two include warnings.
 *
 * @param  array<string, mixed> $identity Result of cmp_fetch_identity().
 * @return string
 */
function cmp_menu_variant(array $identity)
{
    $accountType = (string) ($identity['user_type'] ?? '');

    /* Same rule as payment_history.php: only Collectors and Proxy Collectors use
       the collector rail. The Proxy Collector-Cashier keeps the admin rail, so
       both pages render an identical sidebar for that account type. */
    if (in_array($accountType, array('Collector', 'Proxy Collector'), true)) {
        return 'collector';
    }

    return 'admin';
}

/**
 * Everything the view needs, in one call.
 *
 * @return array<string, mixed>
 *
 * @throws RuntimeException When the account or database is unavailable.
 */
function cmp_context()
{
    cmp_start_session();
    cmp_handle_logout();

    if (!cmp_is_authenticated()) {
        cmp_redirect('index.php');
        exit;
    }

    $conn = db();

    try {
        $identity = cmp_fetch_identity($conn);
    } catch (Throwable $e) {
        error_log('[customers-multiple-payment] ' . $e->getMessage());

        // The original logged these users out.
        cmp_sign_out();
        cmp_redirect('index.php');
        exit;
    }

    $user = $identity['user'];
    $userBranch = $identity['user_branch'];
    $userType = $identity['user_type'];

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    $allBranches = array('Tuguegarao City', 'Gattaran', 'Abulug', 'Ilagan');
    $availableBranches = cmp_available_branches($userType, $userBranch);

    $selectedBranch = $_GET['branch'] ?? '';

    if ($identity['is_proxy_collector'] && $userBranch !== '' && $selectedBranch === '') {
        $selectedBranch = $userBranch;
    }

    $collectors = cmp_fetch_collectors($conn, $selectedBranch);
    $proxyAccounts = cmp_fetch_proxy_accounts($conn);

    $collectorFullName = '';

    if ($identity['is_collector_user']) {
        $collectorFullName = $user['first_name'];

        if (!empty($user['middle_name'])) {
            $collectorFullName .= ' ' . $user['middle_name'];
        }

        $collectorFullName .= ' ' . $user['last_name'];

        if (!empty($user['suffix']) && $user['suffix'] != 'None') {
            $collectorFullName .= ' ' . $user['suffix'];
        }
    }

    return array(
        'user'               => $user,
        'user_branch'        => $userBranch,
        'user_type'          => $userType,
        'is_proxy_user'      => $identity['is_proxy_user'],
        'is_collector_user'  => $identity['is_collector_user'],
        'is_proxy_collector' => $identity['is_proxy_collector'],
        'is_management_user' => in_array($userType, array('Admin', 'Area Manager', 'Branch Manager'), true),
        'all_branches'       => $allBranches,
        'available_branches' => $availableBranches,
        'selected_branch'    => $selectedBranch,
        'collectors'         => $collectors,
        'proxy_accounts'     => $proxyAccounts,
        'collector_full_name' => $collectorFullName,
        'layout_menu'        => cmp_menu_variant($identity),
        'endpoint_base'      => 'api/',
    );
}
