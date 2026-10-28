<?php
/**
 * Prime City - Penalty report actions.
 *
 * Server-side logic for penalty_report.php: the signed-in account, branch
 * scoping, the period/area/loan filters, the penalty list with waiver details
 * and the six summary figures.
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
function pnl_start_session()
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
function pnl_is_authenticated()
{
    return isset($_SESSION['logged_in']);
}

/**
 * Redirect, falling back to JS when headers are already sent.
 *
 * @param  string $location Target path.
 * @return void
 */
function pnl_redirect($location)
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
function pnl_sign_out()
{
    pnl_start_session();

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
function pnl_handle_logout()
{
    if (!isset($_GET['logout'])) {
        return;
    }

    pnl_sign_out();
    pnl_redirect('index.php');
    exit;
}

/**
 * The four branches.
 *
 * @return array<int, string>
 */
function pnl_branches()
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
function pnl_fetch_user(mysqli $conn)
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
function pnl_branch_context(array $user)
{
    $userBranches = array();

    if (($user['account_type'] ?? '') === 'Admin') {
        $userBranches = pnl_branches();
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
 * Years that actually carry penalties, newest first.
 *
 * @param  mysqli $conn Open connection.
 * @return array<int, int>
 */
function pnl_year_options(mysqli $conn)
{
    $years = array();

    try {
        $result = $conn->query(
            'SELECT DISTINCT YEAR(created_at) AS penalty_year
               FROM penalties
              WHERE created_at IS NOT NULL
              ORDER BY penalty_year DESC'
        );

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $year = (int) $row['penalty_year'];

                if ($year > 0) {
                    $years[] = $year;
                }
            }

            $result->free();
        }
    } catch (Throwable $e) {
        error_log('[penalty-report] years: ' . $e->getMessage());
    }

    if (empty($years)) {
        $years[] = (int) date('Y');
    }

    return $years;
}

/**
 * The period and record filters from the query string.
 *
 * @return array<string, string>
 */
function pnl_filters()
{
    return array(
        'time_period'  => isset($_GET['period']) ? (string) $_GET['period'] : 'daily',
        'selected_date' => isset($_GET['date']) ? (string) $_GET['date'] : date('Y-m-d'),
        'selected_week' => isset($_GET['week']) ? (string) $_GET['week'] : date('Y-\WW'),
        'selected_month' => isset($_GET['month']) ? (string) $_GET['month'] : date('Y-m'),
        'selected_year' => isset($_GET['year']) ? (string) $_GET['year'] : date('Y'),
        'selected_branch' => isset($_GET['branch']) ? (string) $_GET['branch'] : 'all',
        'area_filter'  => isset($_GET['area']) ? (string) $_GET['area'] : '',
        'loan_id_filter' => isset($_GET['loan_id']) ? (string) $_GET['loan_id'] : '',
        'penalty_type' => isset($_GET['penalty_type']) ? (string) $_GET['penalty_type'] : '',
    );
}

/**
 * Resolve the inclusive date range for the selected period.
 *
 * @param  array<string, string> $filters Filter values.
 * @return array<string, string>
 */
function pnl_date_range(array $filters)
{
    $timePeriod = $filters['time_period'];
    $selectedWeek = $filters['selected_week'];
    $selectedMonth = $filters['selected_month'];
    $selectedYear = $filters['selected_year'];
    $selectedDate = $filters['selected_date'];

    $startDate = '';
    $endDate = '';

    if ($timePeriod === 'weekly' && !empty($selectedWeek)) {
        $weekParts = explode('-W', $selectedWeek);
        $weekYear = $weekParts[0];
        $weekNumber = $weekParts[1];
        $startDate = date('Y-m-d', strtotime($weekYear . 'W' . str_pad($weekNumber, 2, '0', STR_PAD_LEFT)));
        $endDate = date('Y-m-d', strtotime($startDate . ' +6 days'));
    }

    $dateFrom = '';
    $dateTo = '';

    switch ($timePeriod) {
        case 'weekly':
            if (!empty($selectedWeek)) {
                $dateFrom = $startDate;
                $dateTo = $endDate;
            } else {
                $dateFrom = date('Y-m-d', strtotime('monday this week'));
                $dateTo = date('Y-m-d', strtotime('sunday this week'));
            }
            break;
        case 'monthly':
            if (!empty($selectedMonth)) {
                $dateFrom = date('Y-m-01', strtotime($selectedMonth . '-01'));
                $dateTo = date('Y-m-t', strtotime($selectedMonth . '-01'));
            } else {
                $dateFrom = date('Y-m-01');
                $dateTo = date('Y-m-t');
            }
            break;
        case 'yearly':
            if (!empty($selectedYear)) {
                $dateFrom = $selectedYear . '-01-01';
                $dateTo = $selectedYear . '-12-31';
            } else {
                $dateFrom = date('Y-01-01');
                $dateTo = date('Y-12-31');
            }
            break;
        default: // daily
            if (!empty($selectedDate)) {
                $dateFrom = $selectedDate;
                $dateTo = $selectedDate;
            } else {
                $dateFrom = date('Y-m-d');
                $dateTo = date('Y-m-d');
            }
    }

    return array(
        'start_date' => $startDate,
        'end_date'   => $endDate,
        'date_from'  => $dateFrom,
        'date_to'    => $dateTo,
    );
}

/**
 * Build the WHERE clause and bound parameters shared by both queries.
 *
 * @param  array<string, string> $filters      Filter values.
 * @param  array<string, string> $range        Resolved date range.
 * @param  array<string, mixed>  $user         Signed-in account.
 * @param  array<int, string>    $userBranches Branches the account may see.
 * @return array<string, mixed>
 */
function pnl_where(array $filters, array $range, array $user, array $userBranches)
{
    $conditions = array();
    $params = array();
    $types = '';

    if (!empty($filters['selected_branch']) && $filters['selected_branch'] !== 'all') {
        $conditions[] = 'c.branch = ?';
        $params[] = $filters['selected_branch'];
        $types .= 's';
    } else {
        if (($user['account_type'] ?? '') !== 'Admin' && !empty($userBranches)) {
            if (count($userBranches) == 1) {
                $conditions[] = 'c.branch = ?';
                $params[] = $userBranches[0];
                $types .= 's';
            } else {
                $placeholders = str_repeat('?,', count($userBranches) - 1) . '?';
                $conditions[] = "c.branch IN ({$placeholders})";
                $params = array_merge($params, $userBranches);
                $types .= str_repeat('s', count($userBranches));
            }
        }
    }

    if (!empty($filters['area_filter'])) {
        $conditions[] = 'c.area = ?';
        $params[] = $filters['area_filter'];
        $types .= 's';
    }

    if (!empty($range['date_from']) && !empty($range['date_to'])) {
        $conditions[] = 'DATE(p.created_at) BETWEEN ? AND ?';
        $params[] = $range['date_from'];
        $params[] = $range['date_to'];
        $types .= 'ss';
    }

    if (!empty($filters['loan_id_filter'])) {
        $conditions[] = 'p.loan_id = ?';
        $params[] = $filters['loan_id_filter'];
        $types .= 'i';
    }

    if (!empty($filters['penalty_type'])) {
        if ($filters['penalty_type'] === 'partial_waiver') {
            $conditions[] = 'wl.penalty_id IS NOT NULL AND wl.remaining_amount > 0';
        } else {
            $conditions[] = 'p.type = ?';
            $params[] = $filters['penalty_type'];
            $types .= 's';
        }
    }

    return array(
        'clause' => empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions),
        'params' => $params,
        'types'  => $types,
    );
}

/**
 * Penalty rows with their loan, customer and waiver details.
 *
 * @param  mysqli               $conn     Open connection.
 * @param  array<string, mixed> $where    Result of pnl_where().
 * @return array<int, array<string, mixed>>
 */
function pnl_penalties(mysqli $conn, array $where)
{
    $query = "
        SELECT
            p.*,
            l.id as loan_id,
            l.amount_financed,
            l.interest_peso,
            l.penalty as total_loan_penalty,
            l.status as loan_status,
            l.start_date,
            l.end_date,
            c.id as customer_id,
            c.first_name,
            c.middle_name,
            c.last_name,
            c.suffix,
            c.contact_number,
            c.area as customer_area,
            c.branch as customer_branch,
            u.username as created_by_name,
            COALESCE(wl.original_amount, p.amount) as original_amount,
            COALESCE(wl.waived_amount, 0) as waived_amount,
            COALESCE(wl.remaining_amount, p.amount) as remaining_amount,
            wl.reason as waiver_reason,
            wl.additional_notes as waiver_additional_notes,
            wl.created_at as waiver_date
        FROM penalties p
        LEFT JOIN financing l ON p.loan_id = l.id
        LEFT JOIN customer_accounts c ON l.customer_id = c.id
        LEFT JOIN accounts u ON p.created_by = u.id
        LEFT JOIN penalty_waiver_logs wl ON p.id = wl.penalty_id
        {$where['clause']}
        ORDER BY p.created_at DESC
    ";

    $penalties = array();

    if (!empty($where['params'])) {
        $stmt = $conn->prepare($query);

        if (!$stmt) {
            throw new RuntimeException('Prepare failed: ' . $conn->error);
        }

        $stmt->bind_param($where['types'], ...$where['params']);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $stmt = null;
        $result = $conn->query($query);
    }

    while ($row = $result->fetch_assoc()) {
        $penalties[] = $row;
    }

    if ($stmt !== null) {
        $stmt->close();
    }

    return $penalties;
}

/**
 * The six summary figures for the same filters.
 *
 * @param  mysqli               $conn      Open connection.
 * @param  array<string, mixed> $where     Result of pnl_where().
 * @param  array<string, mixed> $fallback Zeroed summary.
 * @return array<string, mixed>
 */
function pnl_summary(mysqli $conn, array $where, array $fallback)
{
    $summaryQuery = "
        SELECT
            COUNT(*) as total_penalties,
            SUM(CASE WHEN p.type = 'added' THEN p.amount ELSE 0 END) as total_added,
            SUM(CASE WHEN p.type = 'waived' THEN p.amount ELSE 0 END) as total_waived,
            COUNT(DISTINCT p.loan_id) as affected_loans,
            COUNT(DISTINCT l.customer_id) as affected_customers,
            SUM(COALESCE(wl.waived_amount, 0)) as total_waived_amount,
            SUM(COALESCE(wl.remaining_amount, p.amount)) as total_remaining_amount
        FROM penalties p
        LEFT JOIN financing l ON p.loan_id = l.id
        LEFT JOIN customer_accounts c ON l.customer_id = c.id
        LEFT JOIN penalty_waiver_logs wl ON p.id = wl.penalty_id
        {$where['clause']}
    ";

    if (!empty($where['params'])) {
        $stmt = $conn->prepare($summaryQuery);

        if (!$stmt) {
            throw new RuntimeException('Prepare failed: ' . $conn->error);
        }

        $stmt->bind_param($where['types'], ...$where['params']);
        $stmt->execute();
        $summaryResult = $stmt->get_result();
    } else {
        $stmt = null;
        $summaryResult = $conn->query($summaryQuery);
    }

    $summary = $fallback;

    if ($summaryResult && $summaryResult->num_rows > 0) {
        $summary = $summaryResult->fetch_assoc();
    }

    if ($stmt !== null) {
        $stmt->close();
    }

    return $summary;
}

/**
 * Distinct customer areas, for the filter dropdown.
 *
 * @param  mysqli $conn Open connection.
 * @return array<int, string>
 */
function pnl_areas(mysqli $conn)
{
    $areas = array();

    $result = $conn->query("SELECT DISTINCT area FROM customer_accounts WHERE area IS NOT NULL AND area != '' ORDER BY area");

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $areas[] = $row['area'];
        }
    }

    return $areas;
}

/**
 * Everything the view needs, in one call.
 *
 * @return array<string, mixed>
 */
function pnl_context()
{
    pnl_start_session();
    pnl_handle_logout();

    if (!pnl_is_authenticated()) {
        pnl_redirect('index.php');
        exit;
    }

    $conn = db();

    $filters = pnl_filters();
    $range = pnl_date_range($filters);

    $user = array();
    $penalties = array();
    $areas = array();
    $branchContext = array(
        'user_branch'        => '',
        'user_branches'      => array(),
        'show_branch_filter' => false,
    );
    $summary = array(
        'total_penalties' => 0,
        'total_added' => 0,
        'total_waived' => 0,
        'affected_loans' => 0,
        'affected_customers' => 0,
        'total_waived_amount' => 0,
        'total_remaining_amount' => 0,
    );
    $error = '';

    try {
        $user = pnl_fetch_user($conn);
        $branchContext = pnl_branch_context($user);

        $where = pnl_where($filters, $range, $user, $branchContext['user_branches']);
        $penalties = pnl_penalties($conn, $where);
        $summary = pnl_summary($conn, $where, $summary);
        $areas = pnl_areas($conn);
    } catch (Throwable $e) {
        error_log('[penalty-report] ' . $e->getMessage());
        $error = 'Error: ' . $e->getMessage();
    }

    return array(
        'user'               => $user,
        'user_branch'        => $branchContext['user_branch'],
        'user_branches'      => $branchContext['user_branches'],
        'show_branch_filter' => $branchContext['show_branch_filter'],
        'branches'           => pnl_branches(),
        'time_period'        => $filters['time_period'],
        'selected_date'      => $filters['selected_date'],
        'selected_week'      => $filters['selected_week'],
        'selected_month'     => $filters['selected_month'],
        'selected_year'      => $filters['selected_year'],
        'selected_branch'    => $filters['selected_branch'],
        'area_filter'        => $filters['area_filter'],
        'loan_id_filter'     => $filters['loan_id_filter'],
        'penalty_type'       => $filters['penalty_type'],
        'date_from'          => $range['date_from'],
        'date_to'            => $range['date_to'],
        'start_date'         => $range['start_date'],
        'end_date'           => $range['end_date'],
        'areas'              => $areas,
        'years'              => pnl_year_options($conn),
        'penalties'          => $penalties,
        'summary'            => $summary,
        'error'              => $error,
        'layout_menu'        => 'admin',
    );
}
