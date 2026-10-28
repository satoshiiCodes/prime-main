<?php
/**
 * Prime City - Proxy loan record actions.
 *
 * Server-side logic for proxy_loan_record.php. Proxy loans live in the same
 * `collector_loans` / `collector_payments` tables as collector loans, so the
 * table-agnostic helpers in collector-loan-actions.php are reused verbatim;
 * only the owning account table (proxy_accounts) differs.
 */

require_once __DIR__ . '/collector-loan-actions.php';

/**
 * The proxy id from `?id=`, redirecting to the employee list when missing.
 *
 * The original page used `$_GET['id']` without validation.
 *
 * @return int
 */
function pl_requested_proxy_id()
{
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        cl_redirect('employee_lists.php');
        exit;
    }

    return intval($_GET['id']);
}

/**
 * Load one proxy account's profile columns.
 *
 * @param  PDO $pdo     Open connection.
 * @param  int $proxyId Proxy primary key.
 * @return array<string, mixed>
 *
 * @throws RuntimeException When the proxy account does not exist.
 */
function pl_fetch_proxy(PDO $pdo, $proxyId)
{
    $stmt = $pdo->prepare("
        SELECT id, first_name, middle_name, last_name, suffix, profile_image,
               contact_number, area, account_status
        FROM proxy_accounts
        WHERE id = ?
    ");
    $stmt->execute(array($proxyId));

    $proxy = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$proxy) {
        throw new RuntimeException('Proxy not found');
    }

    return $proxy;
}

/**
 * Proxy loans for one proxy account.
 *
 * @param  PDO    $pdo     Open connection.
 * @param  int    $proxyId Proxy primary key.
 * @param  string $group   `current` (active + defaulted) or `completed`.
 * @return array<int, array<string, mixed>>
 */
function pl_fetch_loans(PDO $pdo, $proxyId, $group)
{
    $where = $group === 'completed'
        ? "cl.status = 'completed'"
        : "cl.status IN ('active', 'defaulted')";

    $stmt = $pdo->prepare("
        SELECT cl.*, 
               p.first_name AS customer_first_name, 
               p.middle_name AS customer_middle_name, 
               p.last_name AS customer_last_name,
               p.suffix AS customer_suffix,
               p.contact_number AS customer_contact
        FROM collector_loans cl
        JOIN proxy_accounts p ON cl.collector_id = p.id
        WHERE cl.collector_id = ? AND {$where}
        ORDER BY cl.created_at DESC
    ");
    $stmt->execute(array($proxyId));

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Everything the view needs, in one call.
 *
 * @return array<string, mixed>
 *
 * @throws RuntimeException When the proxy account or database is unavailable.
 */
function pl_context()
{
    cl_start_session();
    cl_handle_logout();

    if (!cl_is_authenticated()) {
        cl_redirect('index.php');
        exit;
    }

    $proxy_id = pl_requested_proxy_id();

    $pdo = db_pdo();

    $proxy = pl_fetch_proxy($pdo, $proxy_id);

    $proxy_loans = pl_fetch_loans($pdo, $proxy_id, 'current');
    $completed_loans = pl_fetch_loans($pdo, $proxy_id, 'completed');

    $payments = cl_fetch_payments($pdo, array_merge($proxy_loans, $completed_loans));

    $summary = cl_summary($proxy_loans, $completed_loans);

    $accountUser = cl_fetch_account($pdo);
    $is_admin = is_array($accountUser) && $accountUser['account_type'] === 'Admin';

    return array(
        'proxy_id'        => $proxy_id,
        'proxy'           => $proxy,
        'proxy_loans'     => $proxy_loans,
        'completed_loans' => $completed_loans,
        'payments'        => $payments,
        'summary'         => $summary,
        'is_admin'        => $is_admin,
        'user'            => cl_display_user($pdo),
        'layout_menu'     => cl_menu_variant($pdo, $accountUser),
    );
}
