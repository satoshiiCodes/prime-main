<?php
/**
 * Prime City - Collector loan record actions.
 *
 * Server-side logic for collector_loan_record.php: the collector profile,
 * their active/completed collector loans, every payment recorded against those
 * loans, the summary figures and who may delete payments.
 *
 * Produces no markup - the page includes it and renders.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Start the session once.
 *
 * @return void
 */
function cl_start_session()
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
function cl_is_authenticated()
{
    return isset($_SESSION['logged_in'], $_SESSION['user_id']);
}

/**
 * Redirect, falling back to JS when headers are already sent.
 *
 * @param  string $location Target path.
 * @return void
 */
function cl_redirect($location)
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
function cl_sign_out()
{
    cl_start_session();

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
function cl_handle_logout()
{
    if (!isset($_GET['logout'])) {
        return;
    }

    cl_sign_out();
    cl_redirect('index.php');
    exit;
}

/**
 * The collector id from `?id=`, redirecting to the list when missing.
 *
 * The original page used `$_GET['id']` without validation.
 *
 * @return int
 */
function cl_requested_collector_id()
{
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        cl_redirect('employee_lists.php');
        exit;
    }

    return intval($_GET['id']);
}

/**
 * Load one collector's profile columns.
 *
 * @param  PDO $pdo         Open connection.
 * @param  int $collectorId Collector primary key.
 * @return array<string, mixed>
 *
 * @throws RuntimeException When the collector does not exist.
 */
function cl_fetch_collector(PDO $pdo, $collectorId)
{
    $stmt = $pdo->prepare("
        SELECT id, first_name, middle_name, last_name, suffix, profile_image,
               contact_number, area, account_status
        FROM collector_accounts
        WHERE id = ?
    ");
    $stmt->execute(array($collectorId));

    $collector = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$collector) {
        throw new RuntimeException('Collector not found');
    }

    return $collector;
}

/**
 * Collector loans for one collector.
 *
 * @param  PDO    $pdo         Open connection.
 * @param  int    $collectorId Collector primary key.
 * @param  string $group       `current` (active + defaulted) or `completed`.
 * @return array<int, array<string, mixed>>
 */
function cl_fetch_loans(PDO $pdo, $collectorId, $group)
{
    $where = $group === 'completed'
        ? "cl.status = 'completed'"
        : "cl.status IN ('active', 'defaulted')";

    $stmt = $pdo->prepare("
        SELECT cl.*, 
               c.first_name AS customer_first_name, 
               c.middle_name AS customer_middle_name, 
               c.last_name AS customer_last_name,
               c.suffix AS customer_suffix,
               c.contact_number AS customer_contact
        FROM collector_loans cl
        JOIN collector_accounts c ON cl.collector_id = c.id
        WHERE cl.collector_id = ? AND {$where}
        ORDER BY cl.created_at DESC
    ");
    $stmt->execute(array($collectorId));

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Payments recorded against a set of loans.
 *
 * @param  PDO                             $pdo   Open connection.
 * @param  array<int, array<string, mixed>> $loans Loans to load payments for.
 * @return array<int, array<string, mixed>>
 */
function cl_fetch_payments(PDO $pdo, array $loans)
{
    if (!$loans) {
        return array();
    }

    $loanIds = array_column($loans, 'id');
    $placeholders = implode(',', array_fill(0, count($loanIds), '?'));

    $stmt = $pdo->prepare("
        SELECT cp.*, a.first_name as created_first_name, a.last_name as created_last_name
        FROM collector_payments cp
        LEFT JOIN accounts a ON cp.created_by = a.id
        WHERE cp.loan_id IN ($placeholders)
        ORDER BY cp.payment_date DESC
    ");
    $stmt->execute($loanIds);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Summary figures for the cards.
 *
 * @param  array<int, array<string, mixed>> $currentLoans   Active + defaulted loans.
 * @param  array<int, array<string, mixed>> $completedLoans Completed loans.
 * @return array<string, float>
 */
function cl_summary(array $currentLoans, array $completedLoans)
{
    $summary = array(
        'active_paid'      => 0,
        'active_balance'   => 0,
        'completed_total'  => 0,
        'all_paid'         => 0,
    );

    foreach ($currentLoans as $loan) {
        $summary['active_paid'] += $loan['amount_loaned'] - $loan['balance'];
        $summary['active_balance'] += $loan['balance'];
    }

    foreach ($completedLoans as $loan) {
        $summary['completed_total'] += $loan['amount_loaned'];
        $summary['all_paid'] += $loan['amount_loaned'] - $loan['balance'];
    }

    $summary['all_paid'] += $summary['active_paid'];

    return $summary;
}

/**
 * The session user's row from the `accounts` table, if it has one.
 *
 * @param  PDO $pdo Open connection.
 * @return array<string, mixed>|null
 */
function cl_fetch_account(PDO $pdo)
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    $stmt = $pdo->prepare('SELECT first_name, middle_name, last_name, suffix, account_type FROM accounts WHERE id = ?');
    $stmt->execute(array($userId));

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $user ?: null;
}

/**
 * Which chrome the page renders, replicating the original inline checks.
 *
 * Collector menu when the session user is a Collector in collector_accounts,
 * a Collector in accounts, or a Proxy Collector in proxy_accounts - otherwise
 * the admin menu.
 *
 * @param  PDO                       $pdo         Open connection.
 * @param  array<string, mixed>|null $accountUser Row from `accounts`.
 * @return string
 */
function cl_menu_variant(PDO $pdo, $accountUser)
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    try {
        $stmt = $pdo->prepare('SELECT account_type FROM collector_accounts WHERE id = ?');
        $stmt->execute(array($userId));
        $collectorRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($collectorRow && $collectorRow['account_type'] === 'Collector') {
            return 'collector';
        }

        if (is_array($accountUser) && isset($accountUser['account_type']) && $accountUser['account_type'] === 'Collector') {
            return 'collector';
        }

        $stmt = $pdo->prepare('SELECT account_type FROM proxy_accounts WHERE id = ?');
        $stmt->execute(array($userId));
        $proxyRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($proxyRow && $proxyRow['account_type'] === 'Proxy Collector') {
            return 'collector';
        }
    } catch (Throwable $e) {
        error_log('[collector-loan] ' . $e->getMessage());
    }

    return 'admin';
}

/**
 * Account row for the top bar / sidebar footer, whatever table holds it.
 *
 * @param  PDO $pdo Open connection.
 * @return array<string, mixed>
 */
function cl_display_user(PDO $pdo)
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    $tables = array(
        'accounts'           => null,
        'proxy_accounts'     => 'Proxy Collector',
        'collector_accounts' => 'Collector',
        'customer_accounts'  => 'Customer',
    );

    foreach ($tables as $table => $forcedType) {
        $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE id = :id");
        $stmt->bindParam(':id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            if ($forcedType !== null) {
                $user['account_type'] = $forcedType;
            }

            return $user;
        }
    }

    return array(
        'first_name'   => (string) ($_SESSION['first_name'] ?? 'Admin'),
        'middle_name'  => '',
        'last_name'    => '',
        'suffix'       => '',
        'account_type' => 'User',
    );
}

/**
 * Everything the view needs, in one call.
 *
 * @return array<string, mixed>
 *
 * @throws RuntimeException When the collector or database is unavailable.
 */
function cl_context()
{
    cl_start_session();
    cl_handle_logout();

    if (!cl_is_authenticated()) {
        cl_redirect('index.php');
        exit;
    }

    $collector_id = cl_requested_collector_id();

    $pdo = db_pdo();

    $collector = cl_fetch_collector($pdo, $collector_id);

    $collector_loans = cl_fetch_loans($pdo, $collector_id, 'current');
    $completed_loans = cl_fetch_loans($pdo, $collector_id, 'completed');

    $payments = cl_fetch_payments($pdo, array_merge($collector_loans, $completed_loans));

    $summary = cl_summary($collector_loans, $completed_loans);

    $accountUser = cl_fetch_account($pdo);
    $is_admin = is_array($accountUser) && $accountUser['account_type'] === 'Admin';

    return array(
        'collector_id'      => $collector_id,
        'collector'         => $collector,
        'collector_loans'   => $collector_loans,
        'completed_loans'   => $completed_loans,
        'payments'          => $payments,
        'summary'           => $summary,
        'is_admin'          => $is_admin,
        'user'              => cl_display_user($pdo),
        'layout_menu'       => cl_menu_variant($pdo, $accountUser),
    );
}
