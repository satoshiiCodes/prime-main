<?php
/**
 * Prime City - New loan (financing) actions.
 *
 * Server-side logic for add_financing.php: the signed-in user, branch scoping,
 * the pre-selected customer/collector, the initial dropdown options, the loan
 * creation transaction and every helper the JSON endpoint shares.
 *
 * Produces no markup - the page includes it and renders.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Start the session once.
 *
 * @return void
 */
function af_start_session()
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
function af_is_authenticated()
{
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

/**
 * Redirect, falling back to JS when headers are already sent.
 *
 * @param  string $location Target path.
 * @return void
 */
function af_redirect($location)
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
function af_sign_out()
{
    af_start_session();

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
function af_handle_logout()
{
    if (!isset($_GET['logout'])) {
        return;
    }

    af_sign_out();
    af_redirect('index.php');
    exit;
}

/**
 * Load the signed-in account (accounts, or proxy_accounts by session source).
 *
 * @param  mysqli $conn Open connection.
 * @return array<string, mixed>
 *
 * @throws RuntimeException When the account cannot be found.
 */
function af_fetch_user(mysqli $conn)
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
 * "First Middle Last Suffix" from a row, ignoring an empty/"None" suffix.
 *
 * @param  array<string, mixed> $row Row with first/middle/last/suffix.
 * @return string
 */
function af_name_from_parts(array $row)
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
 * Customer/collector name from any of the person tables.
 *
 * @param  mysqli $conn  Open connection.
 * @param  string $table Table name (whitelisted below).
 * @param  int    $id    Primary key.
 * @return string
 */
function af_full_name(mysqli $conn, $table, $id)
{
    $allowed = array('customer_accounts', 'collector_accounts', 'accounts', 'proxy_accounts');

    if (!in_array($table, $allowed, true)) {
        return 'Unknown';
    }

    $stmt = $conn->prepare("SELECT first_name, middle_name, last_name, suffix FROM {$table} WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ? af_name_from_parts($row) : 'Unknown';
}

/**
 * A customer's area.
 *
 * @param  mysqli $conn       Open connection.
 * @param  int    $customerId Customer primary key.
 * @return string|null
 */
function af_customer_area(mysqli $conn, $customerId)
{
    $stmt = $conn->prepare('SELECT area FROM customer_accounts WHERE id = ?');
    $stmt->bind_param('i', $customerId);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ? $row['area'] : null;
}

/**
 * A customer's branch.
 *
 * @param  mysqli $conn       Open connection.
 * @param  int    $customerId Customer primary key.
 * @return string|null
 */
function af_customer_branch(mysqli $conn, $customerId)
{
    $stmt = $conn->prepare('SELECT branch FROM customer_accounts WHERE id = ?');
    $stmt->bind_param('i', $customerId);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ? $row['branch'] : null;
}

/**
 * Does the customer already have an Active loan?
 *
 * @param  mysqli $conn       Open connection.
 * @param  int    $customerId Customer primary key.
 * @return bool
 */
function af_has_active_financing(mysqli $conn, $customerId)
{
    $stmt = $conn->prepare("SELECT id FROM financing WHERE customer_id = ? AND status = 'Active'");
    $stmt->bind_param('i', $customerId);
    $stmt->execute();

    $result = $stmt->get_result();
    $has = $result && $result->num_rows > 0;
    $stmt->close();

    return $has;
}

/**
 * Branch filter flags and options for the current account type.
 *
 * @param  string|null $accountType Current user's account type.
 * @param  string|null $userBranch  Current user's branch column.
 * @return array<string, mixed>
 */
function af_branch_context($accountType, $userBranch)
{
    $isAdmin = in_array($accountType, array('Admin'), true);
    $isAreaManager = in_array($accountType, array('Area Manager'), true);
    $isRestricted = in_array($accountType, array('Branch Manager', 'Proxy Collector-Cashier', 'Proxy Collector'), true);

    $showFilter = false;
    $options = array();

    if ($isAdmin) {
        $showFilter = true;
        $options = array(
            'all'             => 'All Branches',
            'Tuguegarao City' => 'Tuguegarao City',
            'Gattaran'        => 'Gattaran',
            'Abulug'          => 'Abulug',
            'Ilagan'          => 'Ilagan',
        );
    } elseif ($isAreaManager) {
        $showFilter = true;

        if ($userBranch && $userBranch !== 'all') {
            $options['all'] = 'All Available Branches';

            foreach (explode(',', (string) $userBranch) as $branch) {
                $branch = trim($branch);
                if ($branch !== '') {
                    $options[$branch] = $branch;
                }
            }
        } else {
            $options = array(
                'all'             => 'All Branches',
                'Tuguegarao City' => 'Tuguegarao City',
                'Gattaran'        => 'Gattaran',
                'Abulug'          => 'Abulug',
                'Ilagan'          => 'Ilagan',
            );
        }
    }

    return array(
        'is_admin'           => $isAdmin,
        'is_area_manager'    => $isAreaManager,
        'is_restricted_user' => $isRestricted,
        'show_branch_filter' => $showFilter,
        'branch_options'     => $options,
    );
}

/**
 * The customer passed through `?customer_id=` plus the collector for its area.
 *
 * @param  mysqli $conn       Open connection.
 * @param  int    $customerId Customer primary key.
 * @return array<string, mixed>
 */
function af_pre_selected(mysqli $conn, $customerId)
{
    $data = array(
        'customer'       => null,
        'branch'         => null,
        'collector_id'   => null,
        'customer_name'  => '',
        'collector_name' => '',
    );

    if (!$customerId) {
        return $data;
    }

    $stmt = $conn->prepare('SELECT id, first_name, middle_name, last_name, suffix, area, branch FROM customer_accounts WHERE id = ?');
    $stmt->bind_param('i', $customerId);
    $stmt->execute();

    $result = $stmt->get_result();
    $customer = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$customer) {
        return $data;
    }

    $data['customer'] = $customer;
    $data['branch'] = $customer['branch'];
    $data['customer_name'] = af_name_from_parts($customer) . ' - ' . (!empty($customer['area']) ? $customer['area'] : 'No Area');

    $stmt = $conn->prepare('SELECT id, first_name, middle_name, last_name, suffix, area FROM collector_accounts WHERE area = ? AND branch = ? LIMIT 1');
    $stmt->bind_param('ss', $customer['area'], $customer['branch']);
    $stmt->execute();

    $result = $stmt->get_result();
    $collector = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if ($collector) {
        $data['collector_id'] = $collector['id'];
        $data['collector_name'] = af_name_from_parts($collector) . ' - ' . (!empty($collector['area']) ? $collector['area'] : 'No Area');
    }

    return $data;
}

/**
 * Dropdown options shown before the user starts searching.
 *
 * @param  mysqli      $conn       Open connection.
 * @param  string|null $userBranch Current user's branch column.
 * @return array<string, array<int, array<string, mixed>>>
 */
function af_initial_options(mysqli $conn, $userBranch)
{
    $filtered = $userBranch && $userBranch !== 'all';
    $options = array('customers' => array(), 'collectors' => array());

    $tables = array(
        'customers'  => 'customer_accounts',
        'collectors' => 'collector_accounts',
    );

    foreach ($tables as $key => $table) {
        $sql = "SELECT id, first_name, middle_name, last_name, suffix, area, branch FROM {$table}";

        if ($filtered) {
            $sql .= ' WHERE branch = ?';
        }

        $sql .= ' ORDER BY last_name, first_name LIMIT 100';

        $stmt = $conn->prepare($sql);

        if ($filtered) {
            $stmt->bind_param('s', $userBranch);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $area = trim((string) ($row['area'] ?? ''));
            $options[$key][] = array(
                'id'   => $row['id'],
                'text' => af_name_from_parts($row) . ' - ' . ($area !== '' ? $area : 'No Area'),
                'name' => af_name_from_parts($row),
                'area' => $area !== '' ? $area : 'No area',
            );
        }

        $stmt->close();
    }

    return $options;
}

/**
 * Select2 search results for customers or collectors.
 *
 * @param  mysqli              $conn   Open connection.
 * @param  array<string, mixed> $params table / q / branch / customer_id.
 * @return array<int, array<string, mixed>>
 */
function af_search_options(mysqli $conn, array $params)
{
    $tableKey = (string) ($params['table'] ?? '');
    $table = $tableKey === 'customers' ? 'customer_accounts' : 'collector_accounts';
    $search = trim((string) ($params['q'] ?? ''));
    $customerId = isset($params['customer_id']) ? intval($params['customer_id']) : null;
    $branch = $params['branch'] ?? null;

    $sql = "SELECT id, first_name, middle_name, last_name, suffix, area, branch
            FROM {$table}
            WHERE CONCAT(first_name, ' ', last_name) LIKE ?";

    $types = 's';
    $values = array('%' . $search . '%');

    if ($branch && $branch !== 'all') {
        $sql .= ' AND branch = ?';
        $types .= 's';
        $values[] = $branch;
    }

    if ($tableKey === 'collectors' && $customerId) {
        $customerArea = af_customer_area($conn, $customerId);

        if ($customerArea) {
            $sql .= ' AND area = ?';
            $types .= 's';
            $values[] = $customerArea;
        }
    }

    $sql .= ' ORDER BY last_name, first_name LIMIT 50';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();

    $result = $stmt->get_result();
    $options = array();

    while ($row = $result->fetch_assoc()) {
        $area = trim((string) ($row['area'] ?? ''));
        $options[] = array(
            'id'   => (int) $row['id'],
            'text' => af_name_from_parts($row) . ' - ' . ($area !== '' ? $area : 'No Area'),
            'name' => af_name_from_parts($row),
            'area' => $area !== '' ? $area : 'No area',
        );
    }

    $stmt->close();

    return $options;
}

/**
 * Create the loan and its savings account in one transaction.
 *
 * @param  mysqli               $conn  Open connection.
 * @param  array<string, mixed> $input Raw POST data.
 * @return array<string, mixed>
 *
 * @throws RuntimeException On validation or storage failure.
 */
function af_create_financing(mysqli $conn, array $input)
{
    $required = array(
        'customer_name'      => 'Customer',
        'collector_name'     => 'Collector',
        'amount_financed'    => 'Amount Financed',
        'interest'           => 'Interest Rate',
        'daily_payment'      => 'Daily Payment',
        'loan_term'          => 'Loan Term',
        'start_date'         => 'Start Date',
        'end_date'           => 'End Date',
        'loan_released_type' => 'Loan Released Type',
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

    $customerId = intval($input['customer_name']);
    $collectorId = intval($input['collector_name']);
    $amountFinanced = floatval(str_replace(',', '', (string) $input['amount_financed']));
    $interestRate = floatval(str_replace(',', '', (string) $input['interest']));
    $interestPeso = floatval(str_replace(',', '', (string) ($input['interest_amount'] ?? 0)));
    $savings = floatval(str_replace(',', '', (string) ($input['savings'] ?? 0)));
    $dailyPayment = floatval(str_replace(',', '', (string) $input['daily_payment']));
    $balance = floatval(str_replace(',', '', (string) ($input['balance'] ?? 0)));
    $serviceFee = floatval(str_replace(',', '', (string) ($input['service_fee'] ?? 0)));
    $customerNet = floatval(str_replace(',', '', (string) ($input['customer_net'] ?? 0)));
    $loanTerm = intval($input['loan_term']);
    $loanReleasedType = (string) $input['loan_released_type'];

    if (!in_array($loanReleasedType, array('Cash', 'GCash'), true)) {
        throw new RuntimeException('Invalid loan released type selected');
    }

    $startDate = (string) $input['start_date'];
    $endDate = date('Y-m-d', strtotime((string) $input['end_date']));

    if (!$startDate || !$endDate) {
        throw new RuntimeException('Invalid date format');
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $startDate)) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            $startDate .= ' 00:00:00';
        } else {
            throw new RuntimeException('Invalid start date format');
        }
    }

    if ($customerId <= 0 || $collectorId <= 0) {
        throw new RuntimeException('Invalid customer or collector selected');
    }

    if ($amountFinanced <= 0 || $interestRate <= 0 || $dailyPayment <= 0) {
        throw new RuntimeException('Amount financed, interest rate, and daily payment must be greater than 0');
    }

    if (af_has_active_financing($conn, $customerId)) {
        throw new RuntimeException('This customer already has an active loan. Please close the existing loan before creating a new one.');
    }

    $customerName = af_full_name($conn, 'customer_accounts', $customerId);
    $collectorName = af_full_name($conn, 'collector_accounts', $collectorId);

    $customerBranch = af_customer_branch($conn, $customerId);

    if (!$customerBranch) {
        throw new RuntimeException('Could not determine customer branch');
    }

    $conn->begin_transaction();

    try {
        $sql = "INSERT INTO financing (
            customer_id, customer_name,
            collector_id, collector_name,
            amount_financed, interest,
            interest_peso, savings, daily_payment,
            balance, loan_term,
            start_date, end_date,
            service_fee,
            customer_net,
            loan_released_type,
            branch,
            created_at,
            notes,
            status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, 'Active')";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            throw new RuntimeException('Prepare failed: ' . $conn->error);
        }

        $customerNet = $amountFinanced - $serviceFee;
        $notes = 'New Loan';

        $stmt->bind_param(
            'isisdddddiisssdsss',
            $customerId, $customerName,
            $collectorId, $collectorName,
            $amountFinanced, $interestRate,
            $interestPeso, $savings, $dailyPayment,
            $balance, $loanTerm,
            $startDate, $endDate,
            $serviceFee,
            $customerNet,
            $loanReleasedType,
            $customerBranch,
            $notes
        );

        if (!$stmt->execute()) {
            throw new RuntimeException('Execute failed: ' . $stmt->error);
        }

        $financingId = $stmt->insert_id;
        $stmt->close();

        $accountNumber = 'SAV-' . date('Ymd') . '-' . str_pad($financingId, 4, '0', STR_PAD_LEFT);

        $savingsStmt = $conn->prepare("INSERT INTO savings_accounts (
                customer_id,
                loan_id,
                account_number,
                current_balance,
                status,
                branch,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, 'Active', ?, NOW(), NOW())");

        if (!$savingsStmt) {
            throw new RuntimeException('Prepare failed for savings account: ' . $conn->error);
        }

        $savingsStmt->bind_param('iisds', $customerId, $financingId, $accountNumber, $savings, $customerBranch);

        if (!$savingsStmt->execute()) {
            throw new RuntimeException('Execute failed for savings account: ' . $savingsStmt->error);
        }

        $savingsAccountId = $savingsStmt->insert_id;
        $savingsStmt->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    return array(
        'success'            => true,
        'message'            => 'Financing and savings account added successfully!',
        'financing_id'       => $financingId,
        'savings_account_id' => $savingsAccountId,
        'customer_branch'    => $customerBranch,
        'start_date_saved'   => $startDate,
    );
}

/**
 * Everything the view needs, in one call.
 *
 * @return array<string, mixed>
 *
 * @throws RuntimeException When the account or database is unavailable.
 */
function af_context()
{
    af_start_session();
    af_handle_logout();

    if (!af_is_authenticated()) {
        af_redirect('index.php');
        exit;
    }

    $conn = db();

    $user = af_fetch_user($conn);
    $userBranch = $user['branch'] ?? null;
    $userAccountType = $user['account_type'] ?? null;

    $branch = af_branch_context($userAccountType, $userBranch);

    $preSelectedId = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : null;
    $preSelected = af_pre_selected($conn, $preSelectedId);

    $options = af_initial_options($conn, $userBranch);

    return array(
        'user'                        => $user,
        'user_branch'                 => $userBranch,
        'user_account_type'           => $userAccountType,
        'is_admin'                    => $branch['is_admin'],
        'is_area_manager'             => $branch['is_area_manager'],
        'is_restricted_user'          => $branch['is_restricted_user'],
        'show_branch_filter'          => $branch['show_branch_filter'],
        'branch_options'              => $branch['branch_options'],
        'pre_selected_customer_id'    => $preSelectedId,
        'pre_selected_customer'       => $preSelected['customer'],
        'pre_selected_branch'         => $preSelected['branch'],
        'pre_selected_collector_id'   => $preSelected['collector_id'],
        'pre_selected_customer_name'  => $preSelected['customer_name'],
        'pre_selected_collector_name' => $preSelected['collector_name'],
        'customers'                   => $options['customers'],
        'collectors'                  => $options['collectors'],
        'layout_menu'                 => 'admin',
    );
}