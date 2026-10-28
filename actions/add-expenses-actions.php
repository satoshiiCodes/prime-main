<?php
/**
 * Prime City - Expenses actions.
 *
 * Server-side logic for add_expenses.php: the signed-in account, branch
 * scoping, the filtered expense list with totals, and the create / update /
 * delete handlers.
 *
 * Produces no markup - the page includes it and renders.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Start the session once.
 *
 * @return void
 */
function ae_start_session()
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
function ae_is_authenticated()
{
    return isset($_SESSION['logged_in']);
}

/**
 * Redirect, falling back to JS when headers are already sent.
 *
 * @param  string $location Target path.
 * @return void
 */
function ae_redirect($location)
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
function ae_sign_out()
{
    ae_start_session();

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
function ae_handle_logout()
{
    if (!isset($_GET['logout'])) {
        return;
    }

    ae_sign_out();
    ae_redirect('index.php');
    exit;
}

/**
 * The expense columns that carry money.
 *
 * @return array<int, string>
 */
function ae_money_columns()
{
    return array(
        'per_diem',
        'load_allowance',
        'salary',
        'rental',
        'bir',
        'allowance',
        'office_supplies',
        'electric_bill',
        'sss',
        'philhealth',
        'pagibig',
    );
}

/**
 * Account types limited to a single branch.
 *
 * @return array<int, string>
 */
function ae_restricted_types()
{
    return array('Branch Manager', 'Proxy Collector-Cashier', 'Proxy Collector');
}

/**
 * Load the signed-in account.
 *
 * @param  mysqli $conn Open connection.
 * @return array<string, mixed>
 */
function ae_fetch_user(mysqli $conn)
{
    $source = $_SESSION['account_source'] ?? 'accounts';
    $table = $source === 'accounts' ? 'accounts' : 'proxy_accounts';
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    $stmt = $conn->prepare("SELECT id, first_name, middle_name, last_name, suffix, account_type, branch FROM {$table} WHERE id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $result = $stmt->get_result();
    $user = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $user ?: array();
}

/**
 * Branch lists and permission flags for the form and the table filter.
 *
 * @param  array<string, mixed> $user Signed-in account.
 * @return array<string, mixed>
 */
function ae_branch_context(array $user)
{
    $allBranches = array('Tuguegarao City', 'Gattaran', 'Abulug', 'Ilagan');
    $restrictedTypes = ae_restricted_types();

    $accountType = $user['account_type'] ?? '';
    $userBranch = $user['branch'] ?? '';

    $availableBranches = array();

    if ($accountType === 'Admin') {
        $availableBranches = $allBranches;
    } elseif ($accountType === 'Area Manager') {
        if ($userBranch) {
            $availableBranches = array_map('trim', explode(',', $userBranch));
        } else {
            $availableBranches = array('Tuguegarao City');
        }
    } elseif (in_array($accountType, $restrictedTypes, true)) {
        if ($userBranch) {
            $availableBranches[] = $userBranch;
        }
    } else {
        $availableBranches = $allBranches;
    }

    $canSeeBranchFilter = false;
    $branchesForFilter = array();

    if ($accountType === 'Admin') {
        $canSeeBranchFilter = true;
        $branchesForFilter = $allBranches;
    } elseif ($accountType === 'Area Manager') {
        $canSeeBranchFilter = true;
        $branchesForFilter = !empty($availableBranches) ? $availableBranches : $allBranches;
    }

    if ($accountType === 'Admin') {
        $branchesForForm = $allBranches;
    } elseif ($accountType === 'Area Manager') {
        $branchesForForm = !empty($availableBranches) ? $availableBranches : $allBranches;
    } elseif (in_array($accountType, $restrictedTypes, true) && !empty($availableBranches)) {
        $branchesForForm = $availableBranches;
    } else {
        $branchesForForm = $allBranches;
    }

    return array(
        'all_branches'              => $allBranches,
        'available_branches'        => $availableBranches,
        'can_see_branch_filter'     => $canSeeBranchFilter,
        'user_branches_for_filter'  => $branchesForFilter,
        'available_branches_for_form' => $branchesForForm,
        'is_restricted_user'        => in_array($accountType, $restrictedTypes, true),
    );
}

/**
 * The expense rows for the current filters, plus column totals.
 *
 * @param  mysqli               $conn         Open connection.
 * @param  string               $filter       all|daily|weekly|monthly.
 * @param  string               $branchFilter Branch filter ("all" for none).
 * @param  array<string, mixed> $user         Signed-in account.
 * @return array<string, mixed>
 */
function ae_list(mysqli $conn, $filter, $branchFilter, array $user)
{
    $sql = "SELECT e.*,
            CASE
                WHEN e.user_source = 'accounts' AND a.id IS NOT NULL THEN CONCAT(a.first_name, ' ', a.last_name)
                WHEN e.user_source = 'proxy_accounts' AND p.id IS NOT NULL THEN CONCAT(p.first_name, ' ', p.last_name)
                ELSE 'Unknown User'
            END as recorded_by_name
            FROM expenses e
            LEFT JOIN accounts a ON e.recorded_by = a.id AND e.user_source = 'accounts'
            LEFT JOIN proxy_accounts p ON e.recorded_by = p.id AND e.user_source = 'proxy_accounts'
            WHERE 1=1";

    switch ($filter) {
        case 'daily':
            $sql .= ' AND DATE(e.expense_date) = CURDATE()';
            break;
        case 'weekly':
            $sql .= ' AND YEARWEEK(e.expense_date, 1) = YEARWEEK(CURDATE(), 1)';
            break;
        case 'monthly':
            $sql .= ' AND YEAR(e.expense_date) = YEAR(CURDATE()) AND MONTH(e.expense_date) = MONTH(CURDATE())';
            break;
        default:
            break;
    }

    if ($branchFilter !== 'all') {
        $sql .= " AND e.branch = '" . $conn->real_escape_string($branchFilter) . "'";
    }

    $accountType = $user['account_type'] ?? '';
    $userBranch = $user['branch'] ?? '';

    if ($accountType === 'Admin') {
        // All branches.
    } elseif ($accountType === 'Area Manager') {
        if (!empty($userBranch)) {
            $conditions = array();

            foreach (array_map('trim', explode(',', $userBranch)) as $branch) {
                $conditions[] = "e.branch = '" . $conn->real_escape_string($branch) . "'";
            }

            if (!empty($conditions)) {
                $sql .= ' AND (' . implode(' OR ', $conditions) . ')';
            }
        }
    } elseif (in_array($accountType, ae_restricted_types(), true) && !empty($userBranch)) {
        $sql .= " AND e.branch = '" . $conn->real_escape_string($userBranch) . "'";
    }

    $sql .= ' ORDER BY e.expense_date DESC';

    $result = $conn->query($sql);

    $expenses = array();

    $totals = array('total_expenses' => 0);

    foreach (ae_money_columns() as $column) {
        $totals[$column] = 0;
    }

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $expenses[] = $row;

            foreach (ae_money_columns() as $column) {
                $totals[$column] += (float) $row[$column];
            }

            $totals['total_expenses'] += (float) $row['total_expenses'];
        }
    }

    return array('expenses' => $expenses, 'total_summary' => $totals);
}

/**
 * Read the money fields from the submitted form.
 *
 * @param  array<string, mixed> $input Raw POST data.
 * @return array<string, mixed>
 */
function ae_input(array $input)
{
    $data = array(
        'expense_date' => isset($input['expense_date']) ? (string) $input['expense_date'] : '',
        'remarks'      => isset($input['remarks']) ? trim((string) $input['remarks']) : '',
        'expense_id'   => isset($input['expense_id']) ? $input['expense_id'] : null,
    );

    foreach (ae_money_columns() as $column) {
        $data[$column] = isset($input[$column]) ? (float) str_replace(',', '', (string) $input[$column]) : 0;
    }

    $data['total_expenses'] = isset($input['total_expenses']) ? (float) str_replace(',', '', (string) $input['total_expenses']) : 0;

    return $data;
}

/**
 * Insert or update one expense row.
 *
 * @param  mysqli               $conn  Open connection.
 * @param  array<string, mixed> $input Raw POST data.
 * @param  array<string, mixed> $user  Signed-in account.
 * @return void
 *
 * @throws RuntimeException On validation or storage failure.
 */
function ae_save(mysqli $conn, array $input, array $user)
{
    $data = ae_input($input);

    $accountType = $user['account_type'] ?? '';
    $userBranch = $user['branch'] ?? '';

    // Restricted users always record against their own branch.
    if (in_array($accountType, ae_restricted_types(), true)) {
        $branch = $userBranch;
    } else {
        $branch = isset($input['branch']) ? trim((string) $input['branch']) : 'Tuguegarao City';
    }

    if (empty($data['expense_date'])) {
        throw new RuntimeException('Expense date is required');
    }

    if (empty($branch)) {
        throw new RuntimeException('Branch is required');
    }

    if (empty($data['remarks'])) {
        throw new RuntimeException('Remarks is required');
    }

    if ($data['total_expenses'] == 0) {
        $data['total_expenses'] = 0;

        foreach (ae_money_columns() as $column) {
            $data['total_expenses'] += $data[$column];
        }
    }

    $formattedRemarks = 'Expenses - ' . $data['remarks'];

    if (!empty($data['expense_id'])) {
        $stmt = $conn->prepare('UPDATE expenses SET expense_date = ?, branch = ?, per_diem = ?, load_allowance = ?, salary = ?, rental = ?, bir = ?, allowance = ?, office_supplies = ?, electric_bill = ?, sss = ?, philhealth = ?, pagibig = ?, total_expenses = ?, remarks = ? WHERE id = ?');

        if (!$stmt) {
            throw new RuntimeException('Prepare failed: ' . $conn->error);
        }

        $stmt->bind_param(
            'ssddddddddddddsi',
            $data['expense_date'],
            $branch,
            $data['per_diem'],
            $data['load_allowance'],
            $data['salary'],
            $data['rental'],
            $data['bir'],
            $data['allowance'],
            $data['office_supplies'],
            $data['electric_bill'],
            $data['sss'],
            $data['philhealth'],
            $data['pagibig'],
            $data['total_expenses'],
            $formattedRemarks,
            $data['expense_id']
        );

        $message = 'Expense record updated successfully!';
    } else {
        $stmt = $conn->prepare('INSERT INTO expenses (expense_date, branch, per_diem, load_allowance, salary, rental, bir, allowance, office_supplies, electric_bill, sss, philhealth, pagibig, total_expenses, remarks, recorded_by, user_source) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

        if (!$stmt) {
            throw new RuntimeException('Prepare failed: ' . $conn->error);
        }

        $recordedById = (int) ($_SESSION['user_id'] ?? 0);
        $userSource = $_SESSION['account_source'] ?? 'accounts';

        $stmt->bind_param(
            'ssddddddddddddsis',
            $data['expense_date'],
            $branch,
            $data['per_diem'],
            $data['load_allowance'],
            $data['salary'],
            $data['rental'],
            $data['bir'],
            $data['allowance'],
            $data['office_supplies'],
            $data['electric_bill'],
            $data['sss'],
            $data['philhealth'],
            $data['pagibig'],
            $data['total_expenses'],
            $formattedRemarks,
            $recordedById,
            $userSource
        );

        $message = 'Expense record added successfully!';
    }

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Error saving expense: ' . $error);
    }

    $stmt->close();

    $_SESSION['success_message'] = $message;
}

/**
 * Delete one expense row.
 *
 * @param  mysqli $conn Open connection.
 * @param  mixed  $id   Expense id.
 * @return void
 *
 * @throws RuntimeException When the delete fails.
 */
function ae_delete(mysqli $conn, $id)
{
    $stmt = $conn->prepare('DELETE FROM expenses WHERE id = ?');

    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    $stmt->bind_param('i', $id);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Error deleting expense: ' . $error);
    }

    $stmt->close();

    $_SESSION['success_message'] = 'Expense record deleted successfully!';
}

/**
 * Everything the view needs, in one call.
 *
 * @return array<string, mixed>
 */
function ae_context()
{
    ae_start_session();
    ae_handle_logout();

    if (!ae_is_authenticated()) {
        ae_redirect('index.php');
        exit;
    }

    $conn = db();
    $user = ae_fetch_user($conn);

    $successMessage = isset($_SESSION['success_message']) ? (string) $_SESSION['success_message'] : '';
    $errorMessage = isset($_SESSION['error_message']) ? (string) $_SESSION['error_message'] : '';
    unset($_SESSION['success_message'], $_SESSION['error_message']);

    // Delete
    if (isset($_GET['delete_id'])) {
        try {
            ae_delete($conn, $_GET['delete_id']);
            ae_redirect('add_expenses.php');
            exit;
        } catch (Throwable $e) {
            error_log('[add-expenses] ' . $e->getMessage());
            // The original stored the raw message and re-rendered the page, so
            // it surfaces on the following request.
            $_SESSION['error_message'] = $e->getMessage();
        }
    }

    // Create / update
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        try {
            ae_save($conn, $_POST, $user);
            ae_redirect('add_expenses.php');
            exit;
        } catch (Throwable $e) {
            error_log('[add-expenses] ' . $e->getMessage());
            $_SESSION['error_message'] = $e->getMessage();
            ae_redirect('add_expenses.php');
            exit;
        }
    }

    $filter = isset($_GET['filter']) ? (string) $_GET['filter'] : 'all';
    $branchFilter = isset($_GET['branch']) ? (string) $_GET['branch'] : 'all';

    $list = array('expenses' => array(), 'total_summary' => array());

    try {
        $list = ae_list($conn, $filter, $branchFilter, $user);
    } catch (Throwable $e) {
        error_log('[add-expenses] ' . $e->getMessage());
    }

    $branch = ae_branch_context($user);

    $phTime = new DateTime('now', new DateTimeZone('Asia/Manila'));

    return array(
        'user'                        => $user,
        'user_account_type'           => $user['account_type'] ?? '',
        'user_branch'                 => $user['branch'] ?? '',
        'all_branches'                => $branch['all_branches'],
        'available_branches'          => $branch['available_branches'],
        'available_branches_for_form' => $branch['available_branches_for_form'],
        'can_see_branch_filter'       => $branch['can_see_branch_filter'],
        'user_branches_for_filter'    => $branch['user_branches_for_filter'],
        'is_restricted_user'          => $branch['is_restricted_user'],
        'filter'                      => $filter,
        'branch_filter'               => $branchFilter,
        'expenses'                    => $list['expenses'],
        'total_summary'               => $list['total_summary'],
        'current_datetime'            => $phTime->format('Y-m-d\TH:i'),
        'success_message'             => $successMessage,
        'error_message'               => $errorMessage,
        'layout_menu'                 => 'admin',
    );
}
