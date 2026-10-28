<?php
/**
 * Prime City - GCash in/out actions.
 *
 * Server-side logic for gcash_in_out.php: the signed-in account, branch
 * scoping, the transaction list, the cashbook ledger, the customer list and
 * the create / edit / delete handlers.
 *
 * Produces no markup - the page includes it and renders.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Start the session once.
 *
 * @return void
 */
function gio_start_session()
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
function gio_is_authenticated()
{
    return isset($_SESSION['logged_in']);
}

/**
 * Redirect, falling back to JS when headers are already sent.
 *
 * @param  string $location Target path.
 * @return void
 */
function gio_redirect($location)
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
function gio_sign_out()
{
    gio_start_session();

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
function gio_handle_logout()
{
    if (!isset($_GET['logout'])) {
        return;
    }

    gio_sign_out();
    gio_redirect('index.php');
    exit;
}

/**
 * Fixed reference lists used by the form.
 *
 * @return array<string, array<int, string>>
 */
function gio_lists()
{
    return array(
        'areas'    => array('Area 1', 'Area 2', 'Area 3', 'Area 4', 'Area 5', 'Area 6', 'Area 7', 'Area 8', 'Area 9', 'Area 10'),
        'banks'    => array('GCASH', 'Cashbook', 'BPI', 'BDO', 'Metrobank', 'UnionBank', 'Landbank', 'PNB', 'Security Bank', 'RCBC', 'China Bank', 'Maybank'),
        'branches' => array('Tuguegarao City', 'Gattaran', 'Abulug', 'Ilagan'),
    );
}

/**
 * Load the signed-in account.
 *
 * @param  mysqli $conn Open connection.
 * @return array<string, mixed>
 *
 * @throws RuntimeException When the account cannot be found.
 */
function gio_fetch_user(mysqli $conn)
{
    $source = $_SESSION['account_source'] ?? 'accounts';
    $table = $source === 'accounts' ? 'accounts' : 'proxy_accounts';
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    $stmt = $conn->prepare("SELECT first_name, middle_name, last_name, suffix, account_type, branch FROM {$table} WHERE id = ?");
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
 * Branch access for this account, plus the effective branch filter.
 *
 * @param  array<string, mixed> $user           Signed-in account.
 * @param  string               $selectedBranch Requested filter.
 * @return array<string, mixed>
 */
function gio_branch_context(array $user, $selectedBranch)
{
    $lists = gio_lists();
    $branches = $lists['branches'];

    $accountType = $user['account_type'] ?? '';
    $userBranch = $user['branch'] ?? '';

    $multiBranchTypes = array('Admin', 'Area Manager');
    $restrictedTypes = array('Branch Manager', 'Proxy Collector-Cashier', 'Proxy Collector');

    if ($accountType === 'Admin') {
        $userBranches = $branches;
    } elseif ($accountType === 'Area Manager') {
        if (!empty($userBranch)) {
            $userBranches = array_map('trim', explode(',', $userBranch));
            $userBranches = array_intersect($userBranches, $branches);
        } else {
            $userBranches = array($userBranch);
        }
    } else {
        $userBranches = array($userBranch);
    }

    if ($selectedBranch !== 'all') {
        if (!in_array($selectedBranch, $userBranches)) {
            $selectedBranch = !empty($userBranches) ? reset($userBranches) : 'all';
        }
    } elseif (!in_array($accountType, $multiBranchTypes, true)) {
        $selectedBranch = !empty($userBranches) ? reset($userBranches) : 'all';
    }

    return array(
        'user_account_type'     => $accountType,
        'user_branch'           => $userBranch,
        'user_branches'         => array_values($userBranches),
        'selected_branch'       => $selectedBranch,
        'is_restricted'         => in_array($accountType, $restrictedTypes, true),
        'is_multi_branch'       => in_array($accountType, $multiBranchTypes, true),
        'show_filter'           => in_array($accountType, $multiBranchTypes, true),
    );
}

/**
 * GCash transactions owned by this user, newest first.
 *
 * @param  mysqli $conn           Open connection.
 * @param  int    $userId         Account id.
 * @param  string $selectedBranch Branch filter ("all" for none).
 * @return array<int, array<string, mixed>>
 */
function gio_transactions(mysqli $conn, $userId, $selectedBranch)
{
    $base = 'SELECT id, transaction_date, amount, fee, branch, reference_number, notes, type, area, customer_id, bank, bank_account_name FROM gcash_transactions WHERE account_id = ?';

    if ($selectedBranch !== 'all') {
        $stmt = $conn->prepare($base . ' AND branch = ? ORDER BY transaction_date DESC');
        $stmt->bind_param('is', $userId, $selectedBranch);
    } else {
        $stmt = $conn->prepare($base . ' ORDER BY transaction_date DESC');
        $stmt->bind_param('i', $userId);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $transactions = array();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $transactions[] = $row;
        }
    }

    $stmt->close();

    return $transactions;
}

/**
 * All customers, plus the same rows grouped by area.
 *
 * @param  mysqli $conn Open connection.
 * @return array<string, mixed>
 */
function gio_customers(mysqli $conn)
{
    $customers = array();
    $byArea = array();

    $stmt = $conn->prepare('SELECT id, first_name, middle_name, last_name, suffix, area FROM customer_accounts ORDER BY last_name, first_name');
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $customers[] = $row;

            $area = $row['area'] ? $row['area'] : 'No Area';

            if (!isset($byArea[$area])) {
                $byArea[$area] = array();
            }

            $byArea[$area][] = $row;
        }
    }

    $stmt->close();

    return array('customers' => $customers, 'by_area' => $byArea);
}

/**
 * Cashbook ledger across gcash payments, transactions, savings and loans.
 *
 * @param  mysqli $conn           Open connection.
 * @param  string $selectedBranch Branch filter ("all" for none).
 * @return array<string, mixed>
 */
function gio_cashbook(mysqli $conn, $selectedBranch)
{
    $branchSql = '';

    if ($selectedBranch !== 'all') {
        $branchSql = "'" . $conn->real_escape_string($selectedBranch) . "'";
    }

    $query = "SELECT
                'Customer Gcash Payment' as type,
                p.payment_date as created_at,
                p.customer_id,
                CONCAT(c.first_name, ' ', COALESCE(c.middle_name, ''), ' ', c.last_name, CASE WHEN c.suffix IS NOT NULL AND c.suffix != '' AND c.suffix != 'None' THEN CONCAT(' ', c.suffix) ELSE '' END) as customer_name,
                p.notes,
                NULL as debit,
                p.amount as credit,
                NULL as balance,
                p.branch
            FROM payments p
            LEFT JOIN customer_accounts c ON p.customer_id = c.id
            WHERE p.payment_method = 'Gcash'";

    if ($selectedBranch !== 'all') {
        $query .= ' AND p.branch = ' . $branchSql;
    }

    $query .= " UNION ALL

            SELECT
                i.type,
                i.transaction_date as created_at,
                i.account_id as customer_id,
                CONCAT(c.first_name, ' ', COALESCE(c.middle_name, ''), ' ', c.last_name,
                    CASE WHEN c.suffix IS NOT NULL AND c.suffix != '' AND c.suffix != 'None'
                        THEN CONCAT(' ', c.suffix) ELSE '' END) as customer_name,
                i.notes,
                CASE
                    WHEN i.type IN ('Cash Out', 'Fund Transfer') THEN i.amount
                    ELSE NULL
                END as debit,
                CASE
                    WHEN i.type IN ('Cash In', 'Beginning Balance') THEN i.amount
                    ELSE NULL
                END as credit,
                NULL as balance,
                i.branch
            FROM gcash_transactions i
            LEFT JOIN accounts c ON i.account_id = c.id";

    if ($selectedBranch !== 'all') {
        $query .= ' WHERE i.branch = ' . $branchSql;
    }

    $query .= " UNION ALL

            SELECT
                CASE
                    WHEN st.transaction_type = 'Withdrawal' THEN 'Customer Gcash Withdrawal'
                    ELSE 'Customer Gcash Deposit'
                END as type,
                st.transaction_date as created_at,
                sa.customer_id,
                CONCAT(c.first_name, ' ', COALESCE(c.middle_name, ''), ' ', c.last_name, CASE WHEN c.suffix IS NOT NULL AND c.suffix != '' AND c.suffix != 'None' THEN CONCAT(' ', c.suffix) ELSE '' END) as customer_name,
                st.notes,
                CASE WHEN st.transaction_type = 'Withdrawal' THEN st.amount ELSE NULL END as debit,
                CASE WHEN st.transaction_type = 'Deposit' THEN st.amount ELSE NULL END as credit,
                NULL as balance,
                st.branch
            FROM savings_transactions st
            LEFT JOIN savings_accounts sa ON st.savings_account_id = sa.id
            LEFT JOIN customer_accounts c ON sa.customer_id = c.id
            WHERE st.payment_method = 'Gcash'
            AND st.transaction_type IN ('Deposit', 'Withdrawal')";

    if ($selectedBranch !== 'all') {
        $query .= ' AND st.branch = ' . $branchSql;
    }

    $query .= " UNION ALL

            SELECT
                'Customer Loan(Gcash Released)' as type,
                f.created_at,
                f.customer_id,
                CONCAT(c.first_name, ' ', COALESCE(c.middle_name, ''), ' ', c.last_name, CASE WHEN c.suffix IS NOT NULL AND c.suffix != '' AND c.suffix != 'None' THEN CONCAT(' ', c.suffix) ELSE '' END) as customer_name,
                f.notes,
                f.customer_net as debit,
                NULL as credit,
                NULL as balance,
                f.branch
            FROM financing f
            LEFT JOIN customer_accounts c ON f.customer_id = c.id
            WHERE f.loan_released_type = 'GCash'";

    if ($selectedBranch !== 'all') {
        $query .= ' AND f.branch = ' . $branchSql;
    }

    $query .= ' ORDER BY created_at DESC';

    $result = $conn->query($query);

    $payments = array();
    $totalCredit = 0;
    $totalDebit = 0;
    $endingBalance = 0;

    if ($result) {
        $allRecords = array();

        while ($row = $result->fetch_assoc()) {
            $allRecords[] = $row;
        }

        $recordsWithBalance = array();
        $balance = 0;

        foreach (array_reverse($allRecords) as $record) {
            $credit = isset($record['credit']) && $record['credit'] !== null ? (float) $record['credit'] : 0;
            $debit = isset($record['debit']) && $record['debit'] !== null ? (float) $record['debit'] : 0;

            $totalDebit += $debit;
            $totalCredit += $credit;

            $balance += ($credit - $debit);
            $record['balance'] = $balance;
            $recordsWithBalance[] = $record;
        }

        $payments = array_reverse($recordsWithBalance);

        if (!empty($payments)) {
            $endingBalance = $payments[0]['balance'];
        }
    }

    return array(
        'payments'       => $payments,
        'total_credit'   => $totalCredit,
        'total_debit'    => $totalDebit,
        'ending_balance' => $endingBalance,
    );
}

/**
 * Read the submitted fields shared by the create and edit forms.
 *
 * @param  array<string, mixed> $input Raw POST data.
 * @param  string               $suffix "edit" for the edit form, "" for create.
 * @return array<string, mixed>
 */
function gio_input(array $input, $suffix)
{
    $prefix = $suffix === '' ? '' : $suffix . '_';

    $customerId = null;

    if (isset($input[$prefix . 'customer_id']) && !empty($input[$prefix . 'customer_id'])) {
        $customerId = (int) $input[$prefix . 'customer_id'];
    }

    return array(
        'date'              => isset($input[$prefix . 'date']) ? trim((string) $input[$prefix . 'date']) : '',
        'amount'            => isset($input[$prefix . 'amount']) ? str_replace(',', '', trim((string) $input[$prefix . 'amount'])) : '',
        'fee'               => isset($input[$prefix . 'fee']) ? str_replace(',', '', trim((string) $input[$prefix . 'fee'])) : 0,
        'branch'            => isset($input[$prefix . 'branch']) ? trim((string) $input[$prefix . 'branch']) : null,
        'reference'         => isset($input[$prefix . 'reference']) ? trim((string) $input[$prefix . 'reference']) : '',
        'notes'             => isset($input[$prefix . 'notes']) ? trim((string) $input[$prefix . 'notes']) : '',
        'type'              => isset($input[$prefix . 'type']) ? trim((string) $input[$prefix . 'type']) : '',
        'area'              => isset($input[$prefix . 'area']) ? trim((string) $input[$prefix . 'area']) : '',
        'customer_id'       => $customerId,
        'bank'              => isset($input[$prefix . 'bank']) ? trim((string) $input[$prefix . 'bank']) : null,
        'bank_account_name' => isset($input[$prefix . 'bank_account_name']) ? trim((string) $input[$prefix . 'bank_account_name']) : null,
    );
}

/**
 * Shared validation for a transaction payload.
 *
 * @param  array<string, mixed> $data Parsed input.
 * @return string|null Error message, or null when valid.
 */
function gio_validate(array $data)
{
    if (empty($data['date']) || empty($data['amount']) || empty($data['type']) || empty($data['branch'])) {
        return 'Date, Amount, Type, and Branch are required fields.';
    }

    if (!is_numeric($data['amount']) || $data['amount'] <= 0) {
        return 'Amount must be a positive number.';
    }

    if (!is_numeric($data['fee']) || $data['fee'] < 0) {
        return 'Fee must be a non-negative number.';
    }

    if (!in_array($data['type'], array('Cash In', 'Cash Out', 'Beginning Balance', 'Fund Transfer'), true)) {
        return 'Invalid transaction type selected.';
    }

    if (($data['type'] === 'Cash In' || $data['type'] === 'Cash Out') && empty($data['area'])) {
        return 'Area is required for Cash In and Cash Out transactions.';
    }

    if ($data['type'] === 'Fund Transfer' && empty($data['bank'])) {
        return 'Bank is required for Fund Transfer transactions.';
    }

    return null;
}

/**
 * Convert the form's "m-d-Y h:i:s A" date to MySQL format.
 *
 * @param  string $date Submitted date.
 * @return string
 *
 * @throws RuntimeException When the format is not recognised.
 */
function gio_mysql_date($date)
{
    $dateObject = DateTime::createFromFormat('m-d-Y h:i:s A', $date);

    if (!$dateObject) {
        throw new RuntimeException('Invalid date format');
    }

    return $dateObject->format('Y-m-d H:i:s');
}

/**
 * Insert a new GCash transaction.
 *
 * @param  mysqli               $conn  Open connection.
 * @param  array<string, mixed> $input Raw POST data.
 * @return void
 *
 * @throws RuntimeException When the insert fails.
 */
function gio_create(mysqli $conn, array $input)
{
    $data = gio_input($input, '');
    $error = gio_validate($data);

    if ($error !== null) {
        $_SESSION['error'] = $error;
        return;
    }

    $mysqlDate = gio_mysql_date($data['date']);

    if ($data['type'] === 'Fund Transfer') {
        $data['customer_id'] = null;
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);

    $stmt = $conn->prepare('INSERT INTO gcash_transactions (account_id, transaction_date, amount, fee, branch, reference_number, notes, type, area, customer_id, bank, bank_account_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    $stmt->bind_param(
        'isdssssssiss',
        $userId,
        $mysqlDate,
        $data['amount'],
        $data['fee'],
        $data['branch'],
        $data['reference'],
        $data['notes'],
        $data['type'],
        $data['area'],
        $data['customer_id'],
        $data['bank'],
        $data['bank_account_name']
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Error recording transaction: ' . $error);
    }

    $stmt->close();

    $_SESSION['success'] = 'GCash transaction recorded successfully!';
}

/**
 * Update an existing GCash transaction.
 *
 * @param  mysqli               $conn  Open connection.
 * @param  array<string, mixed> $input Raw POST data.
 * @return void
 *
 * @throws RuntimeException When the update fails.
 */
function gio_update(mysqli $conn, array $input)
{
    $editId = filter_var($input['edit_id'] ?? 0, FILTER_SANITIZE_NUMBER_INT);
    $data = gio_input($input, 'edit');
    $error = gio_validate($data);

    if ($error !== null) {
        $_SESSION['error'] = $error;
        return;
    }

    $mysqlDate = gio_mysql_date($data['date']);

    if ($data['type'] === 'Fund Transfer') {
        $data['customer_id'] = null;
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);

    $stmt = $conn->prepare('UPDATE gcash_transactions SET transaction_date = ?, amount = ?, fee = ?, branch = ?, reference_number = ?, notes = ?, type = ?, area = ?, customer_id = ?, bank = ?, bank_account_name = ? WHERE id = ? AND account_id = ?');

    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    $stmt->bind_param(
        'sdsssssssssii',
        $mysqlDate,
        $data['amount'],
        $data['fee'],
        $data['branch'],
        $data['reference'],
        $data['notes'],
        $data['type'],
        $data['area'],
        $data['customer_id'],
        $data['bank'],
        $data['bank_account_name'],
        $editId,
        $userId
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Error updating transaction: ' . $error);
    }

    $stmt->close();

    $_SESSION['success'] = 'GCash transaction updated successfully!';
}

/**
 * Delete one of this user's GCash transactions.
 *
 * @param  mysqli $conn   Open connection.
 * @param  mixed  $id     Transaction id.
 * @param  int    $userId Owner account id.
 * @return void
 *
 * @throws RuntimeException When the delete fails.
 */
function gio_delete(mysqli $conn, $id, $userId)
{
    $deleteId = filter_var($id, FILTER_SANITIZE_NUMBER_INT);

    $stmt = $conn->prepare('DELETE FROM gcash_transactions WHERE id = ? AND account_id = ?');

    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    $stmt->bind_param('ii', $deleteId, $userId);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Error deleting transaction: ' . $error);
    }

    $stmt->close();

    $_SESSION['success'] = 'Transaction deleted successfully!';
}

/**
 * Everything the view needs, in one call.
 *
 * @return array<string, mixed>
 */
function gio_context()
{
    gio_start_session();
    gio_handle_logout();

    if (!gio_is_authenticated()) {
        gio_redirect('index.php');
        exit;
    }

    $conn = db();
    $lists = gio_lists();

    $user = array();
    $error = '';
    $success = '';
    $transactions = array();
    $customers = array();
    $customersByArea = array();
    $payments = array();
    $totalCredit = 0;
    $totalDebit = 0;
    $endingBalance = 0;
    $branchFilter = isset($_GET['branch_filter']) ? (string) $_GET['branch_filter'] : 'all';

    $branchContext = array(
        'user_account_type' => '',
        'user_branch'       => '',
        'user_branches'     => array(),
        'selected_branch'   => 'all',
        'is_restricted'     => false,
        'is_multi_branch'   => false,
        'show_filter'       => false,
    );

    try {
        $user = gio_fetch_user($conn);
        $branchContext = gio_branch_context($user, $branchFilter);

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $selectedBranch = $branchContext['selected_branch'];

        $transactions = gio_transactions($conn, $userId, $selectedBranch);

        $customerData = gio_customers($conn);
        $customers = $customerData['customers'];
        $customersByArea = $customerData['by_area'];

        $cashbook = gio_cashbook($conn, $selectedBranch);
        $payments = $cashbook['payments'];
        $totalCredit = $cashbook['total_credit'];
        $totalDebit = $cashbook['total_debit'];
        $endingBalance = $cashbook['ending_balance'];
    } catch (Throwable $e) {
        error_log('[gcash-in-out] ' . $e->getMessage());
        $error = 'Database error occurred. Please try again later.';
    }

    // Create / edit
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        try {
            if (isset($_POST['edit_id'])) {
                gio_update($conn, $_POST);
            } else {
                gio_create($conn, $_POST);
            }

            // Both handlers redirect on success; only validation errors fall through.
            if (empty($_SESSION['error'])) {
                gio_redirect('gcash_in_out.php?branch_filter=' . urlencode($branchContext['selected_branch']));
                exit;
            }
        } catch (Throwable $e) {
            error_log('[gcash-in-out] ' . $e->getMessage());
            $_SESSION['error'] = isset($_POST['edit_id'])
                ? 'Failed to update transaction. Please try again.'
                : 'Failed to record transaction. Please try again.';
        }
    }

    // Delete
    if (isset($_GET['delete_id'])) {
        try {
            gio_delete($conn, $_GET['delete_id'], (int) ($_SESSION['user_id'] ?? 0));
        } catch (Throwable $e) {
            error_log('[gcash-in-out] ' . $e->getMessage());
            $_SESSION['error'] = 'Failed to delete transaction. Please try again.';
        }

        gio_redirect('gcash_in_out.php?branch_filter=' . urlencode($branchContext['selected_branch']));
        exit;
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
        'user'                    => $user,
        'user_account_type'       => $branchContext['user_account_type'],
        'user_branch'             => $branchContext['user_branch'],
        'user_branches'           => $branchContext['user_branches'],
        'selected_branch'         => $branchContext['selected_branch'],
        'show_filter'             => $branchContext['show_filter'],
        'restricted_account_types' => array('Branch Manager', 'Proxy Collector-Cashier', 'Proxy Collector'),
        'multi_branch_account_types' => array('Admin', 'Area Manager'),
        'areas'                   => $lists['areas'],
        'banks'                   => $lists['banks'],
        'branches'                => $lists['branches'],
        'transactions'            => $transactions,
        'customers'               => $customers,
        'customers_by_area'       => $customersByArea,
        'payments'                => $payments,
        'total_credit'            => $totalCredit,
        'total_debit'             => $totalDebit,
        'ending_balance'          => $endingBalance,
        'error'                   => $error,
        'success'                 => $success,
        'layout_menu'             => 'admin',
    );
}
