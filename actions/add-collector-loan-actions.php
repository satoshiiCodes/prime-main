<?php
/**
 * Prime City - New employee loan (collector loan) actions.
 *
 * Server-side logic for add_collector_loan.php: the signed-in user, their
 * branch access, the branch-scoped employee lookups and the loan insert.
 *
 * Produces no markup - the page includes it and renders.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Start the session once.
 *
 * @return void
 */
function acl_start_session()
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
function acl_is_authenticated()
{
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

/**
 * Redirect, falling back to JS when headers are already sent.
 *
 * @param  string $location Target path.
 * @return void
 */
function acl_redirect($location)
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
function acl_sign_out()
{
    acl_start_session();

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
function acl_handle_logout()
{
    if (!isset($_GET['logout'])) {
        return;
    }

    acl_sign_out();
    acl_redirect('index.php');
    exit;
}

/**
 * The three employee tables searched by this page.
 *
 * @return array<string, array<string, string>>
 */
function acl_tables()
{
    return array(
        'collector_accounts' => array('table' => 'collector_accounts', 'account_type_field' => 'account_type', 'status_field' => 'account_status'),
        'accounts'           => array('table' => 'accounts', 'account_type_field' => 'account_type', 'status_field' => 'account_status'),
        'proxy_accounts'     => array('table' => 'proxy_accounts', 'account_type_field' => 'account_type', 'status_field' => 'account_status'),
    );
}

/**
 * "First Middle Last Suffix", ignoring an empty/"None" suffix.
 *
 * @param  array<string, mixed> $row Row with the name columns.
 * @return string
 */
function acl_name_from_parts(array $row)
{
    $parts = array();

    if (!empty($row['first_name'])) {
        $parts[] = $row['first_name'];
    }
    if (!empty($row['middle_name'])) {
        $parts[] = $row['middle_name'];
    }
    if (!empty($row['last_name'])) {
        $parts[] = $row['last_name'];
    }
    if (!empty($row['suffix']) && strtolower((string) $row['suffix']) !== 'none') {
        $parts[] = $row['suffix'];
    }

    return trim(implode(' ', $parts)) ?: 'No Name';
}

/**
 * Load the signed-in account (accounts, or proxy_accounts by session source).
 *
 * @param  mysqli $conn Open connection.
 * @return array<string, mixed>
 *
 * @throws RuntimeException When the account cannot be found.
 */
function acl_fetch_user(mysqli $conn)
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

    if (!$user) {
        throw new RuntimeException('User not found');
    }

    return $user;
}

/**
 * Active employees from the three tables, in select2 shape.
 *
 * @param  mysqli $conn          Open connection.
 * @param  string $branch        Optional branch filter.
 * @param  string $search        Optional name filter.
 * @param  int    $limit         Maximum rows per table.
 * @param  bool   $includeBranch Include the branch field (the search feed does).
 * @param  bool   $dedupe        Drop repeated display names and cap at 50.
 * @return array<int, array<string, mixed>>
 */
function acl_collector_options(mysqli $conn, $branch, $search, $limit, $includeBranch = false, $dedupe = false)
{
    $options = array();

    foreach (acl_tables() as $key => $tableInfo) {
        $table = $tableInfo['table'];
        $accountTypeField = $tableInfo['account_type_field'];
        $statusField = $tableInfo['status_field'];

        $sql = "SELECT id, first_name, middle_name, last_name, suffix, branch, {$accountTypeField}, {$statusField}
                FROM {$table}
                WHERE {$statusField} = 'active'";

        $types = '';
        $values = array();

        if ($branch !== '') {
            $sql .= ' AND branch = ?';
            $types .= 's';
            $values[] = $branch;
        }

        if ($search !== '') {
            $sql .= ' AND (first_name LIKE ? OR middle_name LIKE ? OR last_name LIKE ?)';
            $types .= 'sss';
            $searchTerm = '%' . $search . '%';
            $values[] = $searchTerm;
            $values[] = $searchTerm;
            $values[] = $searchTerm;
        }

        $sql .= ' ORDER BY last_name, first_name LIMIT ' . (int) $limit;

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            continue;
        }

        if ($types !== '') {
            $stmt->bind_param($types, ...$values);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $accountType = $row[$accountTypeField] ?? 'Unknown';

            $option = array(
                'id'          => $key . '_' . $row['id'],
                'text'        => acl_name_from_parts($row) . ' (' . $accountType . ')',
                'original_id' => $row['id'],
                'table_type'  => $key,
            );

            if ($includeBranch) {
                $option['branch'] = $row['branch'];
            }

            $option['account_type'] = $accountType;

            $options[] = $option;
        }

        $stmt->close();
    }

    if (!$dedupe) {
        return $options;
    }

    $unique = array();
    $seen = array();

    foreach ($options as $option) {
        if (!in_array($option['text'], $seen)) {
            $seen[] = $option['text'];
            $unique[] = $option;
        }

        if (count($unique) >= 50) {
            break;
        }
    }

    return $unique;
}

/**
 * Name and account type for one employee.
 *
 * @param  mysqli $conn  Open connection.
 * @param  string $table Whitelisted table name.
 * @param  int    $id    Primary key.
 * @return array<string, string>
 */
function acl_collector_details(mysqli $conn, $table, $id)
{
    $allowed = array('collector_accounts', 'accounts', 'proxy_accounts');

    if (!in_array($table, $allowed, true)) {
        return array('name' => 'Unknown', 'account_type' => 'Unknown');
    }

    $stmt = $conn->prepare("SELECT first_name, middle_name, last_name, suffix, account_type FROM {$table} WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        return array('name' => 'Unknown', 'account_type' => 'Unknown');
    }

    return array(
        'name'         => acl_name_from_parts($row),
        'account_type' => $row['account_type'] ?? 'Unknown',
    );
}

/**
 * Does the employee already have an open loan?
 *
 * @param  mysqli $conn        Open connection.
 * @param  int    $collectorId Employee primary key.
 * @return bool
 */
function acl_has_active_loan(mysqli $conn, $collectorId)
{
    $stmt = $conn->prepare("SELECT id FROM collector_loans WHERE collector_id = ? AND balance > 0 AND status = 'active'");
    $stmt->bind_param('i', $collectorId);
    $stmt->execute();

    $result = $stmt->get_result();
    $has = $result && $result->num_rows > 0;
    $stmt->close();

    return $has;
}

/**
 * Validate the submitted form and insert the employee loan.
 *
 * @param  mysqli               $conn  Open connection.
 * @param  array<string, mixed> $input Raw POST data.
 * @return array<string, mixed>
 *
 * @throws RuntimeException On validation or storage failure.
 */
function acl_create_loan(mysqli $conn, array $input)
{
    $required = array(
        'collector_name' => 'Collector',
        'amount_loaned'  => 'Amount Loaned',
        'daily_payment'  => '15/30 Payment',
        'loan_term'      => 'Loan Term',
        'start_date'     => 'Start Date',
        'end_date'       => 'End Date',
        'branch'         => 'Branch',
    );

    $missing = array();

    foreach ($required as $field => $label) {
        if (empty($input[$field])) {
            $missing[] = $label;
        }
    }

    if ($missing) {
        throw new RuntimeException('Missing required fields: ' . implode(', ', $missing));
    }

    $collectorInput = (string) $input['collector_name'];
    $parts = explode('_', $collectorInput);

    if (count($parts) < 2) {
        throw new RuntimeException('Invalid collector selected - format error');
    }

    $tableType = $parts[0];
    $collectorId = end($parts);

    if (!is_numeric($collectorId)) {
        throw new RuntimeException('Invalid collector ID format - ID must be numeric');
    }

    $collectorId = intval($collectorId);

    $tableMap = array(
        'collector'          => 'collector_accounts',
        'collector_accounts' => 'collector_accounts',
        'accounts'           => 'accounts',
        'proxy'              => 'proxy_accounts',
        'proxy_accounts'     => 'proxy_accounts',
    );

    if (!isset($tableMap[$tableType])) {
        throw new RuntimeException('Invalid collector type: ' . $tableType);
    }

    $tableName = $tableMap[$tableType];

    $amountLoaned = floatval(str_replace(',', '', (string) $input['amount_loaned']));
    $dailyPayment = floatval(str_replace(',', '', (string) $input['daily_payment']));
    $balance = floatval(str_replace(',', '', (string) ($input['balance'] ?? 0)));
    $loanTerm = intval($input['loan_term']);
    $branch = trim((string) $input['branch']);

    if (!in_array($branch, array('Tuguegarao City', 'Gattaran', 'Abulug', 'Ilagan'), true)) {
        throw new RuntimeException('Invalid branch selected');
    }

    $startDate = date('Y-m-d', strtotime((string) $input['start_date']));
    $endDate = date('Y-m-d', strtotime((string) $input['end_date']));

    if (!$startDate || !$endDate) {
        throw new RuntimeException('Invalid date format');
    }

    if ($collectorId <= 0) {
        throw new RuntimeException('Invalid collector selected');
    }

    if ($amountLoaned <= 0 || $dailyPayment <= 0) {
        throw new RuntimeException('Amount loaned and 15/30 payment must be greater than 0');
    }

    if (acl_has_active_loan($conn, $collectorId)) {
        throw new RuntimeException('This collector already has an active loan. Cannot create a new loan until the existing one is paid.');
    }

    $details = acl_collector_details($conn, $tableName, $collectorId);
    $collectorName = $details['name'];
    $collectorAccountType = $details['account_type'];

    $conn->begin_transaction();

    try {
        $sql = "INSERT INTO collector_loans (
            collector_id, collector_name, collector_type,
            amount_loaned, daily_payment,
            balance, loan_term,
            start_date, end_date, branch, status,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            throw new RuntimeException('Prepare failed: ' . $conn->error);
        }

        $stmt->bind_param(
            'issddiisss',
            $collectorId, $collectorName, $collectorAccountType,
            $amountLoaned, $dailyPayment,
            $balance, $loanTerm,
            $startDate, $endDate, $branch
        );

        if (!$stmt->execute()) {
            throw new RuntimeException('Execute failed: ' . $stmt->error);
        }

        $loanId = $stmt->insert_id;
        $stmt->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    return array(
        'success' => true,
        'message' => 'Collector loan added successfully!',
        'loan_id' => $loanId,
    );
}

/**
 * Everything the view needs, in one call.
 *
 * @return array<string, mixed>
 *
 * @throws RuntimeException When the account or database is unavailable.
 */
function acl_context()
{
    acl_start_session();
    acl_handle_logout();

    if (!acl_is_authenticated()) {
        acl_redirect('index.php');
        exit;
    }

    $conn = db();

    $user = array(
        'first_name'   => $_SESSION['first_name'] ?? 'Admin',
        'middle_name'  => '',
        'last_name'    => '',
        'suffix'       => '',
        'account_type' => $_SESSION['account_type'] ?? 'User',
        'branch'       => $_SESSION['branch'] ?? '',
    );

    try {
        $user = acl_fetch_user($conn);
    } catch (Throwable $e) {
        error_log('[add-collector-loan] ' . $e->getMessage());
    }

    $userBranch = $user['branch'] ?? '';
    $userBranches = array();

    if (!empty($userBranch)) {
        $userBranches = array_map('trim', explode(',', $userBranch));
    }

    $collectors = acl_collector_options($conn, '', '', 30);

    $accountType = $user['account_type'] ?? '';
    $restricted = array('Branch Manager', 'Proxy Collector-Cashier', 'Proxy Collector');

    $isBranchRestricted = in_array($accountType, $restricted, true);
    $isAreaManager = $accountType === 'Area Manager';
    $isAdmin = $accountType === 'Admin';

    $preselectedBranch = '';

    if ($isBranchRestricted) {
        $preselectedBranch = $userBranch;
    } elseif (($isAreaManager || $isAdmin) && !empty($userBranches)) {
        $preselectedBranch = $userBranches[0];
    }

    return array(
        'user'                => $user,
        'user_branch'         => $userBranch,
        'user_branches'       => $userBranches,
        'is_branch_restricted' => $isBranchRestricted,
        'is_area_manager'     => $isAreaManager,
        'is_admin'            => $isAdmin,
        'preselected_branch'  => $preselectedBranch,
        'has_multiple_branches' => count($userBranches) > 1,
        'collectors'          => $collectors,
        'success_message'     => '',
        'error_message'       => '',
        'layout_menu'         => 'admin',
        'endpoint'            => 'api/add-collector-loan-endpoint.php',
    );
}
