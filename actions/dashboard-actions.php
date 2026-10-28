<?php
/**
 * Prime City - Dashboard actions.
 *
 * Business logic for the dashboard: session handling, authentication, branch
 * scoping and the collection queries that feed the charts.
 *
 * This file produces no output, so it can be included from both the page
 * (dashboard.php) and the JSON endpoint (api/dashboard-endpoint.php).
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Raised when the dashboard is used by an unauthenticated visitor.
 */
class DashboardAuthException extends RuntimeException
{
}

/**
 * Account types that may only ever see their own branch.
 *
 * @return array<int, string>
 */
function dashboard_restricted_account_types()
{
    return array('Branch Manager', 'Proxy Collector-Cashier', 'Proxy Collector');
}

/**
 * Branches available to the Admin account type.
 *
 * @return array<int, string>
 */
function dashboard_all_branches()
{
    return array('Tuguegarao City', 'Gattaran', 'Abulug', 'Ilagan');
}

/**
 * Start the session once, whatever entry point we came through.
 *
 * @return void
 */
function dashboard_start_session()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * Is there a signed-in user in the session?
 *
 * @return bool
 */
function dashboard_is_authenticated()
{
    return isset($_SESSION['logged_in'], $_SESSION['user_id'])
        && $_SESSION['logged_in'] === true;
}

/**
 * Redirect the browser, falling back to JS when headers are already sent.
 *
 * @param  string $location Target URL.
 * @return void
 */
function dashboard_redirect($location)
{
    if (!headers_sent()) {
        header('Location: ' . $location);
        return;
    }

    echo '<script>window.location.href = ' . json_encode($location) . ';</script>';
}

/**
 * Destroy the current session and remember-me cookie.
 *
 * @return void
 */
function dashboard_sign_out()
{
    dashboard_start_session();

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
 * Honour `?logout=true` before anything else happens.
 *
 * @return void
 */
function dashboard_handle_logout()
{
    if (!isset($_GET['logout'])) {
        return;
    }

    dashboard_sign_out();
    dashboard_redirect('index.php');
    exit;
}

/**
 * Fallback user built from the session, used when the database is unavailable.
 *
 * @return array<string, string>
 */
function dashboard_session_user()
{
    return array(
        'first_name'   => (string) ($_SESSION['first_name'] ?? 'Admin'),
        'middle_name'  => '',
        'last_name'    => '',
        'suffix'       => '',
        'account_type' => (string) ($_SESSION['account_type'] ?? 'User'),
        'branch'       => (string) ($_SESSION['branch'] ?? ''),
    );
}

/**
 * Load the signed-in user's profile from the table that owns the account.
 *
 * @param  mysqli $conn Open connection.
 * @return array<string, string>
 *
 * @throws RuntimeException When the account cannot be found.
 */
function dashboard_fetch_user(mysqli $conn)
{
    $source = $_SESSION['account_source'] ?? 'accounts';
    $table = ($source === 'proxy_accounts') ? 'proxy_accounts' : 'accounts';

    $stmt = $conn->prepare(
        "SELECT first_name, middle_name, last_name, suffix, account_type, branch
         FROM {$table}
         WHERE id = ?
         LIMIT 1"
    );

    $userId = (int) ($_SESSION['user_id'] ?? 0);
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
 * Run a prepared statement and return every row.
 *
 * @param  mysqli             $conn   Open connection.
 * @param  string             $sql    Statement with `?` placeholders.
 * @param  array<int, mixed>  $params Values for the placeholders.
 * @param  string             $types  mysqli type string; guessed when empty.
 * @return array<int, array<string, mixed>>
 */
function dashboard_query(mysqli $conn, $sql, array $params = array(), $types = '')
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
 * Work out which branches a user may see and which one is selected.
 *
 * @param  string               $requested Branch requested through `?branch=`.
 * @param  array<string, mixed> $user      Current user record.
 * @return array<string, mixed>
 */
function dashboard_resolve_branch($requested, array $user)
{
    $requested = trim((string) $requested);
    $selected = $requested === '' ? 'all' : $requested;

    $accountType = trim((string) ($user['account_type'] ?? ''));
    $userBranch = trim((string) ($user['branch'] ?? ''));

    $restricted = dashboard_restricted_account_types();
    $available = array();

    if ($accountType === 'Admin') {
        $available = dashboard_all_branches();
    } elseif ($accountType === 'Area Manager') {
        if ($userBranch !== '') {
            $available = array_values(array_filter(array_map('trim', explode(',', $userBranch)), 'strlen'));

            if ($selected === 'all' || !in_array($selected, $available, true)) {
                $selected = $available[0];
            }
        } else {
            $available = array('Tuguegarao City');
            $selected = 'Tuguegarao City';
        }
    } elseif (in_array($accountType, $restricted, true)) {
        if ($userBranch !== '') {
            $available = array($userBranch);

            if ($selected === 'all' || $selected !== $userBranch) {
                $selected = $userBranch;
            }
        }
    } else {
        $available = dashboard_all_branches();
    }

    return array(
        'available'       => $available,
        'selected'        => $selected,
        'label'           => $selected === 'all' ? 'All Branches' : $selected,
        'is_all'          => $selected === 'all',
        'show_filter'     => !in_array($accountType, $restricted, true),
        'show_all_option' => $accountType === 'Admin',
    );
}

/**
 * Collection totals per collector, newest figures first.
 *
 * @param  mysqli $conn   Open connection.
 * @param  string $branch Selected branch or `all`.
 * @return array<int, array<string, mixed>>
 */
function dashboard_collector_data(mysqli $conn, $branch)
{
    $sql = "SELECT c.id,
                   CONCAT(c.last_name, ', ', c.first_name) AS collector_name,
                   SUM(p.amount) AS total_collection
            FROM payments p
            JOIN collector_accounts c ON p.collector_id = c.id";

    $params = array();
    $types = '';

    if ($branch !== 'all') {
        $sql .= " WHERE c.branch = ?";
        $params[] = $branch;
        $types = 's';
    }

    $sql .= " GROUP BY c.id, collector_name ORDER BY total_collection DESC";

    $rows = dashboard_query($conn, $sql, $params, $types);

    $collectors = array();
    foreach ($rows as $row) {
        $collectors[] = array(
            'id'    => (int) $row['id'],
            'name'  => (string) $row['collector_name'],
            'total' => (float) $row['total_collection'],
        );
    }

    return $collectors;
}

/**
 * Collection totals per area.
 *
 * @param  mysqli $conn   Open connection.
 * @param  string $branch Selected branch or `all`.
 * @return array<int, array<string, mixed>>
 */
function dashboard_area_data(mysqli $conn, $branch)
{
    $sql = "SELECT c.area,
                   SUM(p.amount) AS total_collection
            FROM payments p
            JOIN collector_accounts c ON p.collector_id = c.id";

    $params = array();
    $types = '';

    if ($branch !== 'all') {
        $sql .= " WHERE c.branch = ?";
        $params[] = $branch;
        $types = 's';
    }

    $sql .= " GROUP BY c.area ORDER BY c.area";

    $rows = dashboard_query($conn, $sql, $params, $types);

    $areas = array();
    foreach ($rows as $row) {
        $areas[] = array(
            'area'  => (string) $row['area'],
            'label' => 'Area ' . $row['area'],
            'total' => (float) $row['total_collection'],
        );
    }

    return $areas;
}

/**
 * High level numbers derived from the chart data.
 *
 * @param  array<int, array<string, mixed>> $collectors Collector rows.
 * @param  array<int, array<string, mixed>> $areas      Area rows.
 * @return array<string, mixed>
 */
function dashboard_summary(array $collectors, array $areas)
{
    $total = 0.0;
    $top = null;

    foreach ($collectors as $collector) {
        $amount = (float) $collector['total'];
        $total += $amount;

        if ($top === null || $amount > $top['total']) {
            $top = array('name' => $collector['name'], 'total' => $amount);
        }
    }

    $areaTotal = 0.0;
    foreach ($areas as $area) {
        $areaTotal += (float) $area['total'];
    }

    return array(
        'total_collection'      => round($total, 2),
        'area_total_collection' => round($areaTotal, 2),
        'collector_count'       => count($collectors),
        'area_count'            => count($areas),
        'top_collector'         => $top,
    );
}

/**
 * Everything the dashboard needs, in one call.
 *
 * @param  string $requestedBranch Branch requested through `?branch=`.
 * @param  bool   $redirectOnFail  Redirect guests to the login page; when
 *                                 false a DashboardAuthException is thrown so
 *                                 API callers can answer with JSON.
 * @return array<string, mixed>
 *
 * @throws DashboardAuthException When not signed in and $redirectOnFail is false.
 */
function dashboard_context($requestedBranch = 'all', $redirectOnFail = true)
{
    dashboard_start_session();

    if (!dashboard_is_authenticated()) {
        if ($redirectOnFail) {
            dashboard_redirect('index.php');
            exit;
        }

        throw new DashboardAuthException('Not authenticated.');
    }

    $conn = null;
    $error = null;
    $user = dashboard_session_user();

    try {
        $conn = db();
        $user = dashboard_fetch_user($conn);
    } catch (Throwable $e) {
        // Keep the page usable with session data if the database is down.
        error_log('[dashboard] ' . $e->getMessage());
        $error = $e->getMessage();
        $conn = null;
    }

    $branch = dashboard_resolve_branch($requestedBranch, $user);

    $collectors = array();
    $areas = array();

    if ($conn instanceof mysqli) {
        try {
            $collectors = dashboard_collector_data($conn, $branch['selected']);
            $areas = dashboard_area_data($conn, $branch['selected']);
        } catch (Throwable $e) {
            error_log('[dashboard] ' . $e->getMessage());
            $error = $e->getMessage();
        }
    }

    return array(
        'user'       => $user,
        'branch'     => $branch,
        'collectors' => $collectors,
        'areas'      => $areas,
        'summary'    => dashboard_summary($collectors, $areas),
        'endpoint'   => 'api/dashboard-endpoint.php',
        'error'      => $error,
    );
}

/**
 * Shape the chart collections for JSON transport.
 *
 * @param  array<string, mixed> $context Result of dashboard_context().
 * @return array<string, mixed>
 */
function dashboard_chart_payload(array $context)
{
    $collectors = array();
    foreach ($context['collectors'] as $collector) {
        $collectors[] = array(
            'label' => $collector['name'],
            'total' => $collector['total'],
        );
    }

    $areas = array();
    foreach ($context['areas'] as $area) {
        $areas[] = array(
            'label' => $area['label'],
            'total' => $area['total'],
        );
    }

    return array(
        'branch'      => $context['branch']['selected'],
        'branchLabel' => $context['branch']['label'],
        'endpoint'    => $context['endpoint'],
        'collectors'  => $collectors,
        'areas'       => $areas,
    );
}
