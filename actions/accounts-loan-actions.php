<?php
/**
 * Prime City - Accounts (employee) loan record actions.
 *
 * Server-side logic for accounts_loan_record.php. Office/employee loans live in
 * the same `collector_loans` / `collector_payments` tables as collector and
 * proxy loans, so the table-agnostic helpers in collector-loan-actions.php are
 * reused; only the owning account table (accounts) differs.
 */

require_once __DIR__ . '/collector-loan-actions.php';

/**
 * The account id from `?id=`.
 *
 * The original page already redirected to the collector list when the id was
 * missing or not numeric; that behaviour is kept.
 *
 * @return int
 */
function al_requested_account_id()
{
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        cl_redirect('employee_lists.php');
        exit;
    }

    return intval($_GET['id']);
}

/**
 * Load one office/employee account (the loan owner).
 *
 * @param  PDO $pdo       Open connection.
 * @param  int $accountId Account primary key.
 * @return array<string, mixed>
 *
 * @throws RuntimeException When the account does not exist.
 */
function al_fetch_owner(PDO $pdo, $accountId)
{
    $stmt = $pdo->prepare("
        SELECT id, first_name, middle_name, last_name, suffix, contact_number, account_type, branch
        FROM accounts
        WHERE id = ?
    ");
    $stmt->execute(array($accountId));

    $owner = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$owner) {
        throw new RuntimeException('Collector not found');
    }

    return $owner;
}

/**
 * Loans recorded against one office/employee account.
 *
 * @param  PDO    $pdo       Open connection.
 * @param  int    $accountId Account primary key.
 * @param  string $group     `current` (active + defaulted) or `completed`.
 * @return array<int, array<string, mixed>>
 */
function al_fetch_loans(PDO $pdo, $accountId, $group)
{
    $where = $group === 'completed'
        ? "cl.status = 'completed'"
        : "cl.status IN ('active', 'defaulted')";

    $stmt = $pdo->prepare("
        SELECT cl.*, 
               a.first_name AS customer_first_name, 
               a.middle_name AS customer_middle_name, 
               a.last_name AS customer_last_name,
               a.suffix AS customer_suffix,
               a.contact_number AS customer_contact
        FROM collector_loans cl
        JOIN accounts a ON cl.collector_id = a.id
        WHERE cl.collector_id = ? AND {$where}
        ORDER BY cl.created_at DESC
    ");
    $stmt->execute(array($accountId));

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Everything the view needs, in one call.
 *
 * @return array<string, mixed>
 *
 * @throws RuntimeException When the account or database is unavailable.
 */
function al_context()
{
    cl_start_session();
    cl_handle_logout();

    if (!cl_is_authenticated()) {
        cl_redirect('index.php');
        exit;
    }

    $collector_id = al_requested_account_id();

    $pdo = db_pdo();

    $collector = al_fetch_owner($pdo, $collector_id);

    $collector_loans = al_fetch_loans($pdo, $collector_id, 'current');
    $completed_loans = al_fetch_loans($pdo, $collector_id, 'completed');

    $payments = cl_fetch_payments($pdo, array_merge($collector_loans, $completed_loans));

    $summary = cl_summary($collector_loans, $completed_loans);

    return array(
        'collector_id'    => $collector_id,
        'collector'       => $collector,
        'collector_loans' => $collector_loans,
        'completed_loans' => $completed_loans,
        'payments'        => $payments,
        'summary'         => $summary,
        'user'            => cl_display_user($pdo),
        // The original page always rendered the admin chrome.
        'layout_menu'     => 'admin',
    );
}
