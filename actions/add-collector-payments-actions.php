<?php
/**
 * Prime City - Employee loan payments actions.
 *
 * Server-side logic for add_collector_payments.php: the signed-in user, branch
 * scoping, the collector/loan lookups, the date-drift audit log and the
 * multi-payment transaction.
 *
 * Produces no markup - the page includes it and renders.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Start the session once.
 *
 * @return void
 */
function acp_start_session()
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
function acp_is_authenticated()
{
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

/**
 * Redirect, falling back to JS when headers are already sent.
 *
 * @param  string $location Target path.
 * @return void
 */
function acp_redirect($location)
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
function acp_sign_out()
{
    acp_start_session();

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
function acp_handle_logout()
{
    if (!isset($_GET['logout'])) {
        return;
    }

    acp_sign_out();
    acp_redirect('index.php');
    exit;
}

/**
 * The employee tables that can hold a collector.
 *
 * @return array<int, string>
 */
function acp_employee_tables()
{
    return array('collector_accounts', 'accounts', 'proxy_accounts');
}

/**
 * "First Middle Last Suffix", ignoring an empty/"None" suffix.
 *
 * @param  array<string, mixed> $row Row with the name columns.
 * @return string
 */
function acp_name_from_parts(array $row)
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
 * Load the signed-in account.
 *
 * @param  mysqli $conn Open connection.
 * @return array<string, mixed>
 */
function acp_fetch_user(mysqli $conn)
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
 * Name for one employee, whichever table they live in.
 *
 * @param  mysqli $conn  Open connection.
 * @param  string $table Whitelisted table.
 * @param  int    $id    Primary key.
 * @return string
 */
function acp_full_name(mysqli $conn, $table, $id)
{
    if (!in_array($table, acp_employee_tables(), true)) {
        return 'Unknown';
    }

    $stmt = $conn->prepare("SELECT first_name, middle_name, last_name, suffix FROM {$table} WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ? acp_name_from_parts($row) : 'Unknown';
}

/**
 * Best-effort client IP for the audit trail.
 *
 * @return string
 */
function acp_client_ip()
{
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_X_FORWARDED_FOR'];
    }

    return $_SERVER['REMOTE_ADDR'] ?? '';
}

/**
 * Create the audit table if it is missing.
 *
 * The original page ran this DDL on every request.
 *
 * @param  mysqli $conn Open connection.
 * @return void
 */
function acp_ensure_datetime_log_table(mysqli $conn)
{
    $sql = "
    CREATE TABLE IF NOT EXISTS datetime_change_logs (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT(11) NOT NULL,
        user_name VARCHAR(255) NOT NULL,
        old_datetime DATETIME NOT NULL,
        new_datetime DATETIME NOT NULL,
        action_type VARCHAR(100) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        user_agent TEXT,
        created_at DATETIME NOT NULL,
        INDEX idx_user_id (user_id),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    if (!$conn->query($sql)) {
        error_log('[add-collector-payments] could not create datetime_change_logs: ' . $conn->error);
    }
}

/**
 * Record a date/time change in the audit table.
 *
 * @param  mysqli $conn        Open connection.
 * @param  int    $userId      Acting user.
 * @param  string $userName    Acting user's name.
 * @param  string $oldDatetime Previous value.
 * @param  string $newDatetime New value.
 * @param  string $actionType  Why it changed.
 * @return bool
 */
function acp_log_datetime_change(mysqli $conn, $userId, $userName, $oldDatetime, $newDatetime, $actionType)
{
    $stmt = $conn->prepare(
        'INSERT INTO datetime_change_logs (user_id, user_name, old_datetime, new_datetime, action_type, ip_address, user_agent, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
    );

    if (!$stmt) {
        error_log('[add-collector-payments] datetime log prepare failed: ' . $conn->error);
        return false;
    }

    $ipAddress = acp_client_ip();
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $stmt->bind_param('issssss', $userId, $userName, $oldDatetime, $newDatetime, $actionType, $ipAddress, $userAgent);

    if (!$stmt->execute()) {
        error_log('[add-collector-payments] datetime log failed: ' . $stmt->error);
        $stmt->close();
        return false;
    }

    $stmt->close();

    return true;
}

/**
 * Flag a large jump between the stored and the current clock.
 *
 * @param  mysqli               $conn Open connection.
 * @param  array<string, mixed> $user Signed-in account.
 * @return void
 */
function acp_track_datetime_drift(mysqli $conn, array $user)
{
    if (!isset($_SESSION['last_datetime_check'])) {
        $_SESSION['last_datetime_check'] = time();
        return;
    }

    $currentTime = time();
    $lastCheck = (int) $_SESSION['last_datetime_check'];
    $timeDiff = $currentTime - $lastCheck;

    if (abs($timeDiff) > 300) {
        $userName = isset($user['first_name']) ? $user['first_name'] . ' ' . ($user['last_name'] ?? '') : 'Unknown User';

        acp_log_datetime_change(
            $conn,
            $_SESSION['user_id'] ?? 0,
            $userName,
            date('Y-m-d H:i:s', $lastCheck),
            date('Y-m-d H:i:s', $currentTime),
            $timeDiff > 0 ? 'Time Forward Adjustment' : 'Time Backward Adjustment'
        );
    }

    $_SESSION['last_datetime_check'] = $currentTime;
}

/**
 * Branch options and the restricted flag for the current account.
 *
 * @param  string $accountType Account type.
 * @param  string $userBranch  Branch column.
 * @return array<string, mixed>
 */
function acp_branch_context($accountType, $userBranch)
{
    $allBranches = array('Tuguegarao City', 'Gattaran', 'Abulug', 'Ilagan');
    $restrictedTypes = array('Branch Manager', 'Proxy Collector-Cashier', 'Proxy Collector');

    $isRestricted = in_array($accountType, $restrictedTypes, true);
    $options = array();

    if ($accountType === 'Admin' && $userBranch === 'All Branches') {
        $options = $allBranches;
        $isRestricted = false;
    } elseif ($accountType === 'Area Manager') {
        if (!empty($userBranch)) {
            $assigned = array_map('trim', explode(',', $userBranch));
            $options = array_intersect($allBranches, $assigned);
        } else {
            $options = $allBranches;
        }

        $isRestricted = false;
    } elseif (in_array($accountType, $restrictedTypes, true)) {
        $options = !empty($userBranch) ? array($userBranch) : $allBranches;
        $isRestricted = true;
    } else {
        $options = $allBranches;
        $isRestricted = false;
    }

    if (empty($options)) {
        $options = $allBranches;
    }

    return array(
        'all_branches'        => $allBranches,
        'branch_options'      => array_values($options),
        'is_restricted_user'  => $isRestricted,
    );
}

/**
 * Employees selectable on first load (tagged with their source table).
 *
 * @param  mysqli $conn Open connection.
 * @return array<int, array<string, mixed>>
 */
function acp_initial_collectors(mysqli $conn)
{
    $queries = array(
        array(
            'sql'   => "SELECT id, first_name, middle_name, last_name, suffix, account_type, 'collector_accounts' AS source_table FROM collector_accounts ORDER BY last_name, first_name LIMIT 100",
        ),
        array(
            'sql'   => "SELECT id, first_name, middle_name, last_name, suffix, account_type, 'accounts' AS source_table FROM accounts WHERE account_type IN ('Collector', 'Branch Manager', 'Proxy Collector-Cashier', 'Proxy Collector', 'Admin', 'Area Manager') ORDER BY last_name, first_name LIMIT 100",
        ),
        array(
            'sql'   => "SELECT id, first_name, middle_name, last_name, suffix, account_type, 'proxy_accounts' AS source_table FROM proxy_accounts WHERE account_type IN ('Proxy Collector-Cashier', 'Proxy Collector') ORDER BY last_name, first_name LIMIT 100",
        ),
    );

    $all = array();

    foreach ($queries as $query) {
        $result = $conn->query($query['sql']);

        if (!$result) {
            continue;
        }

        while ($row = $result->fetch_assoc()) {
            $all[] = array(
                'id'           => $row['id'],
                'text'         => acp_name_from_parts($row) . ' (' . ($row['account_type'] ?? 'Collector') . ')',
                'source_table' => $row['source_table'],
                'account_type' => $row['account_type'] ?? 'Collector',
            );
        }
    }

    return $all;
}

/**
 * Employees for one branch, de-duplicated by name and sorted.
 *
 * @param  mysqli $conn   Open connection.
 * @param  string $branch Branch name.
 * @return array<int, array<string, mixed>>
 */
function acp_collectors_by_branch(mysqli $conn, $branch)
{
    $sources = array(
        array(
            'table'    => 'collector_accounts',
            'filter'   => '',
            'order_by' => 'last_name, first_name',
        ),
        array(
            'table'    => 'accounts',
            'filter'   => " AND account_type IN ('Collector', 'Branch Manager', 'Proxy Collector-Cashier', 'Proxy Collector', 'Area Manager')",
            'order_by' => 'last_name, first_name',
        ),
        array(
            'table'    => 'proxy_accounts',
            'filter'   => " AND account_type IN ('Proxy Collector-Cashier', 'Proxy Collector')",
            'order_by' => 'last_name, first_name',
        ),
    );

    $collectors = array();

    foreach ($sources as $source) {
        $table = $source['table'];
        $sql = "SELECT id, first_name, middle_name, last_name, suffix, account_type, '{$table}' AS source_table
                  FROM {$table}
                  WHERE 1 = 1{$source['filter']}";

        if ($branch !== '') {
            $sql .= ' AND branch = ?';
        }

        $sql .= " ORDER BY {$source['order_by']}";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            continue;
        }

        if ($branch !== '') {
            $stmt->bind_param('s', $branch);
        }

        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $collectors[] = array(
                'id'           => $row['id'],
                'text'         => acp_name_from_parts($row) . ' (' . ($row['account_type'] ?? 'Collector') . ')',
                'source_table' => $row['source_table'],
                'account_type' => $row['account_type'] ?? 'Collector',
            );
        }

        $stmt->close();
    }

    $unique = array();
    $seen = array();

    foreach ($collectors as $collector) {
        $nameKey = strtolower(trim($collector['text']));

        if (!in_array($nameKey, $seen)) {
            $seen[] = $nameKey;
            $unique[] = $collector;
        }
    }

    usort($unique, function ($a, $b) {
        return strcmp($a['text'], $b['text']);
    });

    return $unique;
}

/**
 * Open loans for one employee.
 *
 * @param  mysqli $conn        Open connection.
 * @param  int    $collectorId Employee primary key.
 * @return array<int, array<string, mixed>>
 */
function acp_loans(mysqli $conn, $collectorId)
{
    $stmt = $conn->prepare(
        "SELECT id, amount_loaned, balance, daily_payment, start_date, end_date
         FROM collector_loans
         WHERE collector_id = ? AND balance > 0 AND status = 'active'
         ORDER BY start_date DESC"
    );
    $stmt->bind_param('i', $collectorId);
    $stmt->execute();

    $result = $stmt->get_result();
    $loans = array();

    while ($row = $result->fetch_assoc()) {
        $loans[] = array(
            'id'            => $row['id'],
            'text'          => 'Loan #' . $row['id'] . ' - (Balance: ₱' . number_format($row['balance'], 2) . ')',
            'amount_loaned' => $row['amount_loaned'],
            'balance'       => $row['balance'],
            'daily_payment' => $row['daily_payment'],
            'start_date'    => $row['start_date'],
            'end_date'      => $row['end_date'],
        );
    }

    $stmt->close();

    return $loans;
}

/**
 * Find which table holds an employee and return their display details.
 *
 * @param  mysqli $conn        Open connection.
 * @param  int    $collectorId Employee primary key.
 * @return array<string, string>
 */
function acp_resolve_collector(mysqli $conn, $collectorId)
{
    foreach (acp_employee_tables() as $table) {
        $stmt = $conn->prepare("SELECT first_name, middle_name, last_name, suffix, account_type FROM {$table} WHERE id = ?");

        if (!$stmt) {
            continue;
        }

        $stmt->bind_param('i', $collectorId);
        $stmt->execute();

        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $stmt->close();

            return array(
                'name'         => acp_name_from_parts($row) ?: 'Unknown Collector',
                'account_type' => $row['account_type'] ?? 'Collector',
            );
        }

        $stmt->close();
    }

    return array('name' => 'Unknown Collector', 'account_type' => 'Collector');
}

/**
 * Record the submitted payments, updating each loan balance.
 *
 * @param  mysqli               $conn  Open connection.
 * @param  array<string, mixed> $input Raw POST data.
 * @param  array<string, mixed> $user  Signed-in account.
 * @return array<string, mixed>
 *
 * @throws RuntimeException On validation or storage failure.
 */
function acp_process_payments(mysqli $conn, array $input, array $user)
{
    if (!isset($input['payments']) || !is_array($input['payments']) || count($input['payments']) === 0) {
        throw new RuntimeException('No payment data received');
    }

    $createdBy = $_SESSION['user_id'] ?? 0;
    $paymentDate = date('Y-m-d H:i:s', strtotime((string) ($input['payment_date'] ?? '')));
    $branch = trim((string) ($input['branch'] ?? ''));
    $notes = 'Collector Payment - ' . trim((string) ($input['notes'] ?? ''));

    if (!$paymentDate || $paymentDate === '1970-01-01 00:00:00') {
        throw new RuntimeException('Invalid payment date');
    }

    if ($branch === '') {
        throw new RuntimeException('Branch is required');
    }

    $userName = isset($user['first_name']) ? $user['first_name'] . ' ' . ($user['last_name'] ?? '') : 'Unknown User';

    $currentDatetime = date('Y-m-d H:i:s');

    if (abs(strtotime($paymentDate) - strtotime($currentDatetime)) > 300) {
        acp_ensure_datetime_log_table($conn);
        acp_log_datetime_change($conn, $createdBy, $userName, $currentDatetime, $paymentDate, 'Manual Payment Date Adjustment');
    }

    $processed = array();

    $conn->begin_transaction();

    try {
        foreach ($input['payments'] as $paymentData) {
            $collectorId = intval($paymentData['collector_id'] ?? 0);
            $loanId = intval($paymentData['loan_id'] ?? 0);
            $paymentAmount = floatval(str_replace(',', '', (string) ($paymentData['amount'] ?? 0)));

            if ($collectorId <= 0) {
                throw new RuntimeException('Invalid collector ID in payment data');
            }

            if ($loanId <= 0) {
                throw new RuntimeException('Invalid loan ID in payment data');
            }

            if ($paymentAmount <= 0) {
                throw new RuntimeException('Payment amount must be greater than 0');
            }

            $stmt = $conn->prepare('SELECT balance, daily_payment FROM collector_loans WHERE id = ? AND collector_id = ?');
            $stmt->bind_param('ii', $loanId, $collectorId);
            $stmt->execute();

            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                $stmt->close();
                throw new RuntimeException('Loan not found for collector');
            }

            $loan = $result->fetch_assoc();
            $currentBalance = $loan['balance'];
            $stmt->close();

            if ($currentBalance <= 0) {
                throw new RuntimeException('Loan #' . $loanId . ' has no remaining balance to pay');
            }

            if ($paymentAmount > $currentBalance) {
                throw new RuntimeException(
                    'Payment amount (₱' . number_format($paymentAmount, 2) . ') for Loan #' . $loanId
                    . ' cannot exceed current balance (₱' . number_format($currentBalance, 2) . ')'
                );
            }

            $collector = acp_resolve_collector($conn, $collectorId);
            $collectorName = $collector['name'];
            $accountType = $collector['account_type'];

            $stmt = $conn->prepare(
                'INSERT INTO collector_payments (collector_id, collector_name, loan_id, amount, payment_date, branch, notes, created_at, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)'
            );

            if (!$stmt) {
                throw new RuntimeException('Prepare failed: ' . $conn->error);
            }

            $stmt->bind_param('isidsssi', $collectorId, $collectorName, $loanId, $paymentAmount, $paymentDate, $branch, $notes, $createdBy);

            if (!$stmt->execute()) {
                throw new RuntimeException('Execute failed: ' . $stmt->error);
            }

            $paymentId = $stmt->insert_id;
            $stmt->close();

            $stmt = $conn->prepare('UPDATE collector_loans SET balance = balance - ? WHERE id = ? AND collector_id = ?');

            if (!$stmt) {
                throw new RuntimeException('Prepare failed: ' . $conn->error);
            }

            $stmt->bind_param('dii', $paymentAmount, $loanId, $collectorId);

            if (!$stmt->execute()) {
                throw new RuntimeException('Execute failed: ' . $stmt->error);
            }

            if ($stmt->affected_rows === 0) {
                throw new RuntimeException('Failed to update loan balance for Loan #' . $loanId);
            }

            $stmt->close();

            $stmt = $conn->prepare("UPDATE collector_loans SET status = 'completed', end_date = CURDATE() WHERE id = ? AND collector_id = ? AND balance <= 0");

            if (!$stmt) {
                throw new RuntimeException('Prepare failed: ' . $conn->error);
            }

            $stmt->bind_param('ii', $loanId, $collectorId);
            $stmt->execute();
            $statusUpdated = $stmt->affected_rows > 0;
            $stmt->close();

            $stmt = $conn->prepare('SELECT balance, status FROM collector_loans WHERE id = ?');
            $stmt->bind_param('i', $loanId);
            $stmt->execute();

            $result = $stmt->get_result();
            $loan = $result->fetch_assoc();
            $stmt->close();

            $processed[] = array(
                'payment_id'     => $paymentId,
                'loan_id'        => $loanId,
                'amount'         => $paymentAmount,
                'new_balance'    => $loan['balance'],
                'new_status'     => $loan['status'],
                'status_updated' => $statusUpdated,
                'collector_name' => $collectorName,
                'account_type'   => $accountType,
            );
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    return array(
        'success'      => true,
        'message'      => 'Payments recorded successfully!',
        'payments'     => $processed,
        'total_amount' => array_sum(array_column($processed, 'amount')),
        'branch'       => $branch,
    );
}

/**
 * Everything the view needs, in one call.
 *
 * @return array<string, mixed>
 *
 * @throws RuntimeException When the database is unavailable.
 */
function acp_context()
{
    acp_start_session();
    acp_handle_logout();

    if (!acp_is_authenticated()) {
        acp_redirect('index.php');
        exit;
    }

    $conn = db();

    $user = acp_fetch_user($conn);

    if (!$user) {
        // The original fell back to whatever the session remembered.
        $user = array(
            'first_name'   => $_SESSION['first_name'] ?? 'Admin',
            'middle_name'  => '',
            'last_name'    => '',
            'suffix'       => '',
            'account_type' => $_SESSION['account_type'] ?? 'User',
            'branch'       => $_SESSION['branch'] ?? '',
        );
    }

    acp_ensure_datetime_log_table($conn);
    acp_track_datetime_drift($conn, $user);

    $accountType = $user['account_type'] ?? '';
    $userBranch = $user['branch'] ?? '';
    $branch = acp_branch_context($accountType, $userBranch);

    $collectors = array();

    try {
        $collectors = acp_initial_collectors($conn);
    } catch (Throwable $e) {
        error_log('[add-collector-payments] ' . $e->getMessage());
    }

    return array(
        'user'              => $user,
        'user_account_type' => $accountType,
        'user_branch'       => $userBranch,
        'all_branches'      => $branch['all_branches'],
        'user_branch_options' => $branch['branch_options'],
        'is_restricted_user' => $branch['is_restricted_user'],
        'collectors'        => $collectors,
        'success_message'   => '',
        'error_message'     => '',
        'layout_menu'       => 'admin',
    );
}
