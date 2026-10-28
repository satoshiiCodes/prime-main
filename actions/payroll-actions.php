<?php
/**
 * Prime City - Payroll actions.
 *
 * Server-side logic for payroll.php: the signed-in account, branch scoping, the
 * date/branch filters and the attendance summary behind the payroll table.
 *
 * Read-only page: nothing is written back.
 *
 * Produces no markup - the page includes it and renders.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Start the session once.
 *
 * @return void
 */
function pr_start_session()
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
function pr_is_authenticated()
{
    return isset($_SESSION['logged_in']);
}

/**
 * Redirect, falling back to JS when headers are already sent.
 *
 * @param  string $location Target path.
 * @return void
 */
function pr_redirect($location)
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
function pr_sign_out()
{
    pr_start_session();

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
function pr_handle_logout()
{
    if (!isset($_GET['logout'])) {
        return;
    }

    pr_sign_out();
    pr_redirect('index.php');
    exit;
}

/**
 * Account types limited to a single branch.
 *
 * @return array<int, string>
 */
function pr_restricted_types()
{
    return array('Branch Manager', 'Proxy Collector-Cashier', 'Proxy Collector');
}

/**
 * Load the signed-in account.
 *
 * @param  mysqli $conn Open connection.
 * @return array<string, mixed>
 */
function pr_fetch_user(mysqli $conn)
{
    $source = $_SESSION['account_source'] ?? 'accounts';
    $table = $source === 'accounts' ? 'accounts' : 'proxy_accounts';
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    $stmt = $conn->prepare("SELECT first_name, middle_name, last_name, suffix, account_type, branch FROM {$table} WHERE id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $result = $stmt->get_result();
    $user = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $user ?: array();
}

/**
 * Branches the account may pick from.
 *
 * Payroll is scoped to whoever the account is allowed to see; an "All Branches"
 * login only gets Apayao here, which is what the original did.
 *
 * @param  string $userBranch Branch column.
 * @return array<int, string>
 */
function pr_available_branches($userBranch)
{
    if (strtoupper($userBranch) === 'ALL BRANCHES') {
        return array('Apayao');
    }

    if (!empty($userBranch)) {
        return array_map('trim', explode(',', $userBranch));
    }

    return array('Tuguegarao City');
}

/**
 * Attendance rows for the filters, plus summary counts.
 *
 * @param  mysqli               $conn              Open connection.
 * @param  array<string, string> $filters          date_from / date_to / branch.
 * @param  string               $userBranch        Branch column of the account.
 * @param  array<int, string>   $availableBranches Branches the account may see.
 * @return array<string, mixed>
 */
function pr_records(mysqli $conn, array $filters, $userBranch, array $availableBranches)
{
    $sql = 'SELECT * FROM attendance WHERE 1=1';
    $params = array();
    $types = '';

    if (!empty($filters['branch'])) {
        $sql .= ' AND department = ?';
        $params[] = $filters['branch'];
        $types .= 's';
    } elseif (strtoupper($userBranch) !== 'ALL BRANCHES' && !empty($availableBranches)) {
        $placeholders = implode(',', array_fill(0, count($availableBranches), '?'));
        $sql .= " AND department IN ($placeholders)";

        foreach ($availableBranches as $branch) {
            $params[] = $branch;
            $types .= 's';
        }
    }

    if (!empty($filters['date_from'])) {
        $sql .= ' AND date >= ?';
        $params[] = $filters['date_from'];
        $types .= 's';
    }

    if (!empty($filters['date_to'])) {
        $sql .= ' AND date <= ?';
        $params[] = $filters['date_to'];
        $types .= 's';
    }

    $sql .= ' ORDER BY date DESC, user_id';

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $records = array();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $records[] = $row;
        }
    }

    $stmt->close();

    $summary = array();

    if (!empty($records)) {
        $presentCount = 0;
        $absentCount = 0;
        $lateCount = 0;
        $totalLateMinutes = 0;

        foreach ($records as $record) {
            if ($record['status'] === 'Present') {
                $presentCount++;
            } elseif ($record['status'] === 'Absent') {
                $absentCount++;
            } elseif ($record['status'] === 'Late/Early') {
                $lateCount++;
            }

            if (isset($record['late_minutes']) && is_numeric($record['late_minutes'])) {
                $totalLateMinutes += intval($record['late_minutes']);
            }
        }

        $summary = array(
            'total_records'      => count($records),
            'present_count'      => $presentCount,
            'absent_count'       => $absentCount,
            'late_count'         => $lateCount,
            'total_late_minutes' => $totalLateMinutes,
        );
    }

    return array('records' => $records, 'summary' => $summary);
}

/**
 * Everything the view needs, in one call.
 *
 * @return array<string, mixed>
 */
function pr_context()
{
    pr_start_session();
    pr_handle_logout();

    if (!pr_is_authenticated()) {
        pr_redirect('index.php');
        exit;
    }

    $conn = db();
    $user = pr_fetch_user($conn);

    $userBranch = $user['branch'] ?? '';
    $userAccountType = $user['account_type'] ?? '';

    // Filters arrive by POST (the form posts to itself); a first visit defaults
    // to today.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $filterDateFrom = isset($_POST['date_from']) ? trim((string) $_POST['date_from']) : '';
        $filterDateTo = isset($_POST['date_to']) ? trim((string) $_POST['date_to']) : '';
        $filterBranch = isset($_POST['filter_branch']) ? trim((string) $_POST['filter_branch']) : '';
    } else {
        $today = date('Y-m-d');
        $filterDateFrom = $today;
        $filterDateTo = $today;
        $filterBranch = '';
    }

    $availableBranches = pr_available_branches($userBranch);

    if (in_array($userAccountType, pr_restricted_types(), true) && empty($filterBranch)) {
        $filterBranch = $availableBranches[0];
    }

    $data = array('records' => array(), 'summary' => array());
    $error = '';

    try {
        $data = pr_records($conn, array(
            'date_from' => $filterDateFrom,
            'date_to'   => $filterDateTo,
            'branch'    => $filterBranch,
        ), $userBranch, $availableBranches);
    } catch (Throwable $e) {
        error_log('[payroll] ' . $e->getMessage());
        $error = 'Error fetching attendance records: ' . $e->getMessage();
    }

    return array(
        'user'                     => $user,
        'user_branch'              => $userBranch,
        'user_account_type'        => $userAccountType,
        'available_branches'       => $availableBranches,
        'restricted_account_types' => pr_restricted_types(),
        'filter_date_from'         => $filterDateFrom,
        'filter_date_to'           => $filterDateTo,
        'filter_branch'            => $filterBranch,
        'attendance_records'       => $data['records'],
        'filtered_records'         => $data['records'],
        'summary'                  => $data['summary'],
        'error'                    => $error,
        'layout_menu'              => 'admin',
    );
}
