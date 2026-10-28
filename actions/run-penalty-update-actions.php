<?php
/**
 * Prime City - Automatic penalty update actions.
 *
 * Server-side logic for run_penalty_update.php: the signed-in account, branch
 * scoping, the permission check, the eligible-loan count and the last-run
 * timestamp.
 *
 * Read-only mount point: the actual penalty writing happens in
 * api/update_all_penalties.php, which is called over AJAX and left as-is.
 *
 * Produces no markup - the page includes it and renders.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Start the session once.
 *
 * @return void
 */
function rpu_start_session()
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
function rpu_is_authenticated()
{
    return isset($_SESSION['logged_in']);
}

/**
 * Redirect, falling back to JS when headers are already sent.
 *
 * @param  string $location Target path.
 * @return void
 */
function rpu_redirect($location)
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
function rpu_sign_out()
{
    rpu_start_session();

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
function rpu_handle_logout()
{
    if (!isset($_GET['logout'])) {
        return;
    }

    rpu_sign_out();
    rpu_redirect('index.php');
    exit;
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
function rpu_fetch_user(mysqli $conn)
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
 * Branches this account may filter by, plus the effective filter.
 *
 * @param  array<string, mixed> $user         Signed-in account.
 * @param  string               $branchFilter Requested filter.
 * @return array<string, mixed>
 */
function rpu_branch_context(array $user, $branchFilter)
{
    $accountType = $user['account_type'] ?? '';
    $userBranch = $user['branch'] ?? '';

    $restrictedTypes = array('Area Manager', 'Branch Manager', 'Proxy Collector-Cashier', 'Proxy Collector');
    $userBranches = array();
    $isRestricted = false;

    if (in_array($accountType, $restrictedTypes, true)) {
        $isRestricted = true;

        if (!empty($userBranch)) {
            $userBranches = array($userBranch);

            if ($branchFilter === '' || $branchFilter === 'all') {
                $branchFilter = $userBranch;
            }
        }
    } else {
        $userBranches = array('Tuguegarao City', 'Gattaran', 'Abulug', 'Ilagan');
    }

    // Area Manager spans several branches and is not "restricted".
    if ($accountType === 'Area Manager' && !empty($userBranch)) {
        $isRestricted = false;

        $areaManagerBranches = array_map('trim', explode(',', $userBranch));
        $userBranches = $areaManagerBranches;

        if ($branchFilter === '' || $branchFilter === 'all') {
            $branchFilter = $areaManagerBranches[0];
        } elseif (!in_array($branchFilter, $areaManagerBranches)) {
            $branchFilter = $areaManagerBranches[0];
        }
    }

    return array(
        'user_branches'      => $userBranches,
        'branch_filter'      => $branchFilter,
        'is_restricted_user' => $isRestricted,
    );
}

/**
 * How many loans the next run would touch.
 *
 * Only active loans past their end date, still allowed automatic penalties,
 * and without an "added" penalty in the current month are counted.
 *
 * @param  mysqli $conn         Open connection.
 * @param  string $branchFilter Branch filter ("all" for none).
 * @return int
 */
function rpu_overdue_count(mysqli $conn, $branchFilter)
{
    $sql = "
        SELECT COUNT(DISTINCT f.id) as count
        FROM financing f
        JOIN customer_accounts c ON f.customer_id = c.id
        LEFT JOIN penalties p ON f.id = p.loan_id
            AND p.type = 'added'
            AND YEAR(p.created_at) = YEAR(CURDATE())
            AND MONTH(p.created_at) = MONTH(CURDATE())
        WHERE f.status = 'active'
        AND f.end_date < CURDATE()
        AND (f.automatic_penalty_enabled IS NULL OR f.automatic_penalty_enabled = 1)
        AND p.id IS NULL
    ";

    if (!empty($branchFilter) && $branchFilter !== 'all') {
        $sql .= ' AND c.branch = ?';
    }

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    if (!empty($branchFilter) && $branchFilter !== 'all') {
        $stmt->bind_param('s', $branchFilter);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $count = 0;

    if ($result) {
        $row = $result->fetch_assoc();
        $count = $row['count'] ?? 0;
    }

    $stmt->close();

    return (int) $count;
}

/**
 * Last time a penalty run was logged.
 *
 * @param  mysqli $conn Open connection.
 * @return string
 */
function rpu_last_run(mysqli $conn)
{
    $result = $conn->query("
        SELECT created_at FROM system_logs
        WHERE action LIKE '%penalty%' OR action LIKE '%automatic penalty%'
        ORDER BY created_at DESC
        LIMIT 1
    ");

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();

        if ($row && $row['created_at']) {
            return date('M j, Y g:i A', strtotime($row['created_at']));
        }
    }

    return 'Never run';
}

/**
 * Everything the view needs, in one call.
 *
 * @return array<string, mixed>
 */
function rpu_context()
{
    rpu_start_session();
    rpu_handle_logout();

    if (!rpu_is_authenticated()) {
        rpu_redirect('index.php');
        exit;
    }

    $conn = db();

    $user = array();
    $userBranches = array();
    $isRestricted = false;
    $overdueCount = 0;
    $lastRun = 'Never run';
    $hasPermission = false;
    $branchFilter = isset($_GET['branch']) ? (string) $_GET['branch'] : '';

    try {
        $user = rpu_fetch_user($conn);

        $branch = rpu_branch_context($user, $branchFilter);
        $userBranches = $branch['user_branches'];
        $branchFilter = $branch['branch_filter'];
        $isRestricted = $branch['is_restricted_user'];

        if (!in_array($user['account_type'] ?? '', array('Admin', 'Area Manager'), true)) {
            $hasPermission = false;
        } else {
            $hasPermission = true;
            $overdueCount = rpu_overdue_count($conn, $branchFilter);
            $lastRun = rpu_last_run($conn);
        }
    } catch (Throwable $e) {
        error_log('[run-penalty-update] ' . $e->getMessage());
        // Defaults stay in place, matching the original's silent fallback.
    }

    return array(
        'user'               => $user,
        'user_branches'      => $userBranches,
        'is_restricted_user' => $isRestricted,
        'branch_filter'      => $branchFilter,
        'overdue_count'      => $overdueCount,
        'last_run'           => $lastRun,
        'has_permission'     => $hasPermission,
        'layout_menu'        => 'admin',
    );
}
