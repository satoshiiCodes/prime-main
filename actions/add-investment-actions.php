<?php
/**
 * Prime City - Investments actions.
 *
 * Server-side logic for add_investment.php: the signed-in account, the branch
 * scoping, the create and delete handlers, the filtered list and the flash
 * messages.
 *
 * Produces no markup - the page includes it and renders.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Start the session once.
 *
 * @return void
 */
function ai_start_session()
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
function ai_is_authenticated()
{
    return isset($_SESSION['logged_in']);
}

/**
 * Redirect, falling back to JS when headers are already sent.
 *
 * @param  string $location Target path.
 * @return void
 */
function ai_redirect($location)
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
function ai_sign_out()
{
    ai_start_session();

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
function ai_handle_logout()
{
    if (!isset($_GET['logout'])) {
        return;
    }

    ai_sign_out();
    ai_redirect('index.php');
    exit;
}

/**
 * The four branches every investment belongs to.
 *
 * @return array<int, string>
 */
function ai_all_branches()
{
    return array('Tuguegarao City', 'Gattaran', 'Abulug', 'Ilagan');
}

/**
 * The account types allowed to record an investment.
 *
 * @return array<int, string>
 */
function ai_investment_types()
{
    return array('Beginning Balance', 'Deposited', 'Withdraw', 'Other Income');
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
function ai_fetch_user(mysqli $conn)
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
 * Branch list plus the filter defaults for this account type.
 *
 * @param  array<string, mixed> $user         Signed-in account.
 * @param  string               $branchFilter Current branch filter.
 * @return array<string, mixed>
 */
function ai_branch_context(array $user, $branchFilter)
{
    $accountType = $user['account_type'] ?? '';
    $userBranch = $user['branch'] ?? '';

    $restrictedTypes = array('Branch Manager', 'Proxy Collector-Cashier', 'Proxy Collector');

    $isRestricted = false;
    $userBranches = array();

    if (in_array($accountType, $restrictedTypes, true)) {
        $isRestricted = true;

        if (!empty($userBranch)) {
            $userBranches = array($userBranch);

            if ($branchFilter === 'all') {
                $branchFilter = $userBranch;
            }
        }
    } elseif ($accountType === 'Area Manager') {
        if (!empty($userBranch)) {
            $userBranches = array_map('trim', explode(',', $userBranch));

            if ($branchFilter === 'all' && count($userBranches) === 1) {
                $branchFilter = $userBranches[0];
            }
        }
    } else {
        $userBranches = ai_all_branches();
    }

    return array(
        'user_branches'      => $userBranches,
        'branch_filter'      => $branchFilter,
        'is_restricted_user' => $isRestricted,
        'hide_branch_filter' => $isRestricted || ($accountType === 'Area Manager' && count($userBranches) === 1),
        'show_all_branches'  => $accountType === 'Admin',
    );
}

/**
 * Validate and store one investment.
 *
 * @param  mysqli $conn Open connection.
 * @param  array<string, mixed> $input Raw POST data.
 * @return string|null Error message, or null on success.
 *
 * @throws RuntimeException When the insert fails.
 */
function ai_store(mysqli $conn, array $input)
{
    $date = isset($input['date']) ? trim((string) $input['date']) : '';
    $amount = isset($input['amount']) ? str_replace(',', '', trim((string) $input['amount'])) : '';
    $notes = isset($input['notes']) ? trim((string) $input['notes']) : '';
    $type = isset($input['type']) ? trim((string) $input['type']) : '';
    $branch = isset($input['branch']) ? trim((string) $input['branch']) : '';

    if (empty($date) || empty($amount) || empty($type) || empty($branch)) {
        return 'Date, Amount, Type, and Branch are required fields.';
    }

    if (!is_numeric($amount) || $amount <= 0) {
        return 'Amount must be a positive number.';
    }

    if (!in_array($type, ai_investment_types(), true)) {
        return 'Invalid investment type selected.';
    }

    if (!in_array($branch, ai_all_branches(), true)) {
        return 'Invalid branch selected.';
    }

    $formattedNotes = $type . ' - ' . $notes;

    $dateObject = DateTime::createFromFormat('m-d-Y h:i:s A', $date);

    if (!$dateObject) {
        return 'Failed to add investment. Please try again.';
    }

    $mysqlDate = $dateObject->format('Y-m-d H:i:s');
    $accountId = (int) ($_SESSION['user_id'] ?? 0);

    $stmt = $conn->prepare('INSERT INTO investments (account_id, branch, date, amount, notes, type) VALUES (?, ?, ?, ?, ?, ?)');

    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    $stmt->bind_param('issdss', $accountId, $branch, $mysqlDate, $amount, $formattedNotes, $type);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Error adding investment: ' . $error);
    }

    $stmt->close();

    return null;
}

/**
 * Delete one investment by id.
 *
 * @param  mysqli $conn Open connection.
 * @param  int    $id   Investment primary key.
 * @return string|null Error message, or null on success.
 *
 * @throws RuntimeException When the delete fails.
 */
function ai_delete(mysqli $conn, $id)
{
    $stmt = $conn->prepare('DELETE FROM investments WHERE id = ?');

    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    $stmt->bind_param('i', $id);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Error deleting investment: ' . $error);
    }

    $stmt->close();

    return null;
}

/**
 * Investments matching the filters, newest first.
 *
 * @param  mysqli               $conn         Open connection.
 * @param  string               $filter       all|daily|weekly|monthly.
 * @param  string               $branchFilter Branch or "all".
 * @param  array<string, mixed> $user         Signed-in account.
 * @param  array<int, string>   $userBranches Branches this user may see.
 * @param  bool                 $isRestricted Restricted account flag.
 * @return array<int, array<string, mixed>>
 */
function ai_list(mysqli $conn, $filter, $branchFilter, array $user, array $userBranches, $isRestricted)
{
    $sql = 'SELECT id, branch, date, amount, notes, type FROM investments WHERE 1=1';

    switch ($filter) {
        case 'daily':
            $sql .= ' AND DATE(date) = CURDATE()';
            break;
        case 'weekly':
            $sql .= ' AND YEARWEEK(date, 1) = YEARWEEK(CURDATE(), 1)';
            break;
        case 'monthly':
            $sql .= ' AND YEAR(date) = YEAR(CURDATE()) AND MONTH(date) = MONTH(CURDATE())';
            break;
        default:
            break;
    }

    $types = '';
    $values = array();

    if ($branchFilter !== 'all') {
        $sql .= ' AND branch = ?';
        $types .= 's';
        $values[] = $branchFilter;
    } elseif ($isRestricted && !empty($userBranches)) {
        $sql .= ' AND branch = ?';
        $types .= 's';
        $values[] = $userBranches[0];
    } elseif (($user['account_type'] ?? '') === 'Area Manager' && !empty($userBranches)) {
        if (count($userBranches) > 1) {
            $sql .= ' AND branch IN (' . implode(',', array_fill(0, count($userBranches), '?')) . ')';
            $types .= str_repeat('s', count($userBranches));
            $values = array_merge($values, array_values($userBranches));
        } else {
            $sql .= ' AND branch = ?';
            $types .= 's';
            $values[] = $userBranches[0];
        }
    }

    $sql .= ' ORDER BY date DESC';

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$values);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $investments = array();

    while ($row = $result->fetch_assoc()) {
        $investments[] = $row;
    }

    $stmt->close();

    return $investments;
}

/**
 * Everything the view needs, in one call.
 *
 * @return array<string, mixed>
 */
function ai_context()
{
    ai_start_session();
    ai_handle_logout();

    if (!ai_is_authenticated()) {
        ai_redirect('index.php');
        exit;
    }

    $conn = db();

    $user = array();
    $error = '';
    $success = '';
    $investments = array();
    $userBranches = array();
    $isRestricted = false;
    $branchFilter = isset($_GET['branch']) ? (string) $_GET['branch'] : 'all';
    $filter = isset($_GET['filter']) ? (string) $_GET['filter'] : 'all';

    try {
        $user = ai_fetch_user($conn);
        $userLoaded = true;
    } catch (Throwable $e) {
        error_log('[add-investment] ' . $e->getMessage());
        $error = 'Database error occurred. Please try again later.';
        $userLoaded = false;

        // The original left $user empty, which made every markup read of
        // $user['account_type'] raise an undefined-key warning. Keep the same
        // (empty) values without the noise.
        $user = array(
            'first_name'   => '',
            'middle_name'  => '',
            'last_name'    => '',
            'suffix'       => '',
            'account_type' => '',
            'branch'       => '',
        );
    }

    if ($userLoaded) {
        $branch = ai_branch_context($user, $branchFilter);
        $userBranches = $branch['user_branches'];
        $branchFilter = $branch['branch_filter'];
        $isRestricted = $branch['is_restricted_user'];
    } else {
        // No branch scoping when the account could not be loaded - this is what
        // the original did by leaving the branch logic inside the failed try.
        $branch = array(
            'hide_branch_filter' => false,
            'show_all_branches'  => false,
        );
    }

    // Create
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        try {
            $validationError = ai_store($conn, $_POST);

            if ($validationError !== null) {
                $_SESSION['error'] = $validationError;
            } else {
                $_SESSION['success'] = 'Investment added successfully!';
                ai_redirect('add_investment.php');
                exit;
            }
        } catch (Throwable $e) {
            error_log('[add-investment] ' . $e->getMessage());
            $_SESSION['error'] = 'Failed to add investment. Please try again.';
        }
    }

    // Delete
    if (isset($_GET['delete_id'])) {
        try {
            ai_delete($conn, intval($_GET['delete_id']));
            $_SESSION['success'] = 'Investment deleted successfully!';
        } catch (Throwable $e) {
            error_log('[add-investment] ' . $e->getMessage());
            $_SESSION['error'] = 'Failed to delete investment. Please try again.';
        }

        ai_redirect('add_investment.php');
        exit;
    }

    try {
        $investments = ai_list($conn, $filter, $branchFilter, $user, $userBranches, $isRestricted);
    } catch (Throwable $e) {
        error_log('[add-investment] ' . $e->getMessage());
    }

    if (isset($_SESSION['error'])) {
        $error = (string) $_SESSION['error'];
        unset($_SESSION['error']);
    }

    if (isset($_SESSION['success'])) {
        $success = (string) $_SESSION['success'];
        unset($_SESSION['success']);
    }

    return array(
        'user'                   => $user,
        'user_branches'          => $userBranches,
        'is_restricted_user'     => $isRestricted,
        'filter'                 => $filter,
        'branch_filter'          => $branchFilter,
        'investments'            => $investments,
        'hide_branch_filter'     => $branch['hide_branch_filter'],
        'show_all_branches_option' => $branch['show_all_branches'],
        'error'                  => $error,
        'success'                => $success ?? '',
        'layout_menu'            => 'admin',
    );
}
