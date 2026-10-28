<?php
/**
 * Prime City - Collector collections record actions.
 *
 * Server-side logic for collector_collections_record.php: the collector
 * profile, their payment history (active and inactive loans), past due loans
 * and the collection summary.
 *
 * Produces no markup - the page includes it and renders.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Start the session once.
 *
 * @return void
 */
function cc_start_session()
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
function cc_is_authenticated()
{
    return isset($_SESSION['logged_in'], $_SESSION['user_id']);
}

/**
 * Redirect, falling back to JS when headers are already sent.
 *
 * @param  string $location Target path.
 * @return void
 */
function cc_redirect($location)
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
function cc_sign_out()
{
    cc_start_session();

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
function cc_handle_logout()
{
    if (!isset($_GET['logout'])) {
        return;
    }

    cc_sign_out();
    cc_redirect('index.php');
    exit;
}

/**
 * The collector id from `?id=`, redirecting to the list when missing.
 *
 * @return int
 */
function cc_requested_collector_id()
{
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        cc_redirect('employee_lists.php');
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
function cc_fetch_collector(PDO $pdo, $collectorId)
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
 * Full name used in the title, the table and the modal.
 *
 * @param  array<string, mixed> $collector Collector record.
 * @return string
 */
function cc_collector_name(array $collector)
{
    $name = trim(
        (string) ($collector['first_name'] ?? '') . ' '
        . (string) ($collector['middle_name'] ?? '') . ' '
        . (string) ($collector['last_name'] ?? '')
    );

    if (!empty($collector['suffix']) && $collector['suffix'] !== 'None') {
        $name .= ', ' . $collector['suffix'];
    }

    return $name;
}

/**
 * Payments recorded by a collector, split by loan status.
 *
 * @param  PDO    $pdo         Open connection.
 * @param  int    $collectorId Collector primary key.
 * @param  string $group       `active` or `inactive`.
 * @return array<int, array<string, mixed>>
 */
function cc_fetch_payments(PDO $pdo, $collectorId, $group)
{
    $operator = $group === 'active' ? '=' : '!=';

    $stmt = $pdo->prepare("
        SELECT p.*, 
               c.first_name AS customer_first_name, 
               c.middle_name AS customer_middle_name, 
               c.last_name AS customer_last_name,
               c.suffix AS customer_suffix
        FROM payments p
        JOIN customer_accounts c ON p.customer_id = c.id
        WHERE p.collector_id = ?
        AND EXISTS (
            SELECT 1 FROM financing 
            WHERE id = p.loan_id 
            AND status {$operator} 'active'
        )
        ORDER BY p.payment_date DESC
    ");
    $stmt->execute(array($collectorId));

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Active loans past their end date.
 *
 * @param  PDO $pdo         Open connection.
 * @param  int $collectorId Collector primary key.
 * @return array<int, array<string, mixed>>
 */
function cc_fetch_past_due(PDO $pdo, $collectorId)
{
    $stmt = $pdo->prepare("
        SELECT f.*, 
               c.first_name AS customer_first_name, 
               c.middle_name AS customer_middle_name, 
               c.last_name AS customer_last_name,
               c.suffix AS customer_suffix,
               c.contact_number AS customer_contact
        FROM financing f
        JOIN customer_accounts c ON f.customer_id = c.id
        WHERE f.collector_id = ?
        AND f.status = 'active'
        AND f.end_date < CURDATE()
        ORDER BY f.end_date ASC
    ");
    $stmt->execute(array($collectorId));

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Today / this week / this month collection totals.
 *
 * @param  PDO $pdo         Open connection.
 * @param  int $collectorId Collector primary key.
 * @return array<string, mixed>
 */
function cc_fetch_stats(PDO $pdo, $collectorId)
{
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN DATE(payment_date) = CURDATE() THEN amount ELSE 0 END) as today,
            SUM(CASE WHEN YEARWEEK(payment_date, 1) = YEARWEEK(CURDATE(), 1) THEN amount ELSE 0 END) as this_week,
            SUM(CASE WHEN YEAR(payment_date) = YEAR(CURDATE()) AND MONTH(payment_date) = MONTH(CURDATE()) THEN amount ELSE 0 END) as this_month
        FROM payments 
        WHERE collector_id = ?
    ");
    $stmt->execute(array($collectorId));

    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    return $stats ?: array('today' => 0, 'this_week' => 0, 'this_month' => 0);
}

/**
 * Summary cards.
 *
 * @param  array<int, array<string, mixed>> $activePayments   Payments on active loans.
 * @param  array<int, array<string, mixed>> $inactivePayments Payments on closed loans.
 * @param  array<int, array<string, mixed>> $pastDueLoans     Overdue loans.
 * @param  array<string, mixed>             $stats            Date-range totals.
 * @return array<string, mixed>
 */
function cc_summary(array $activePayments, array $inactivePayments, array $pastDueLoans, array $stats)
{
    $totalPaidActive = 0;
    foreach ($activePayments as $payment) {
        $totalPaidActive += $payment['amount'];
    }

    $totalPaidInactive = 0;
    foreach ($inactivePayments as $payment) {
        $totalPaidInactive += $payment['amount'];
    }

    $totalPastDue = 0;
    foreach ($pastDueLoans as $loan) {
        $totalPastDue += $loan['balance'];
    }

    return array(
        'total_paid_active'     => $totalPaidActive,
        'total_paid_inactive'   => $totalPaidInactive,
        'total_payments_active' => count($activePayments),
        'total_payments_inactive' => count($inactivePayments),
        'today_collected'       => $stats['today'] ?? 0,
        'this_week_collected'   => $stats['this_week'] ?? 0,
        'this_month_collected'  => $stats['this_month'] ?? 0,
        'total_past_due'        => $totalPastDue,
        'count_past_due'        => count($pastDueLoans),
    );
}

/**
 * The signed-in account, for the layout chrome.
 *
 * Probes every user table so the topbar/sidebar show the right name for any
 * account type (the original page only looked in `accounts`).
 *
 * @param  PDO $pdo Open connection.
 * @return array<string, mixed>
 */
function cc_fetch_user(PDO $pdo)
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
function cc_context()
{
    cc_start_session();
    cc_handle_logout();

    if (!cc_is_authenticated()) {
        cc_redirect('index.php');
        exit;
    }

    $collector_id = cc_requested_collector_id();

    $pdo = db_pdo();

    $collector = cc_fetch_collector($pdo, $collector_id);

    $active_payments = cc_fetch_payments($pdo, $collector_id, 'active');
    $inactive_payments = cc_fetch_payments($pdo, $collector_id, 'inactive');
    $past_due_loans = cc_fetch_past_due($pdo, $collector_id);
    $stats = cc_fetch_stats($pdo, $collector_id);

    $summary = cc_summary($active_payments, $inactive_payments, $past_due_loans, $stats);

    return array(
        'collector_id'      => $collector_id,
        'collector'         => $collector,
        'collector_full_name' => cc_collector_name($collector),
        'active_payments'   => $active_payments,
        'inactive_payments' => $inactive_payments,
        'past_due_loans'    => $past_due_loans,
        'summary'           => $summary,
        'user'              => cc_fetch_user($pdo),
        // The original page always rendered the admin chrome.
        'layout_menu'       => 'admin',
    );
}
