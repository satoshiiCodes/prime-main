<?php
/**
 * Prime City - Date/time change log actions.
 *
 * Server-side logic for dateTime_logs.php: the filtered/paginated query over
 * datetime_change_logs, the action-type list and the signed-in account.
 *
 * Read-only page. Rows are written by the pages that detect clock changes; the
 * CSV export is handled by api/export_logs.php, called from the browser.
 *
 * Produces no markup - the page includes it and renders.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Start the session once.
 *
 * @return void
 */
function dtl_start_session()
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
function dtl_is_authenticated()
{
    return isset($_SESSION['logged_in']);
}

/**
 * Redirect, falling back to JS when headers are already sent.
 *
 * @param  string $location Target path.
 * @return void
 */
function dtl_redirect($location)
{
    if (!headers_sent()) {
        header('Location: ' . $location);
        return;
    }

    echo '<script>window.location.href = ' . json_encode($location) . ';</script>';
}

/**
 * The filter values taken from the query string.
 *
 * @return array<string, string>
 */
function dtl_filters()
{
    return array(
        'user'      => isset($_GET['filter_user']) ? (string) $_GET['filter_user'] : '',
        'action'    => isset($_GET['filter_action']) ? (string) $_GET['filter_action'] : '',
        'date_from' => isset($_GET['filter_date_from']) ? (string) $_GET['filter_date_from'] : '',
        'date_to'   => isset($_GET['filter_date_to']) ? (string) $_GET['filter_date_to'] : '',
    );
}

/**
 * Build the WHERE clause and bound values for the given filters.
 *
 * @param  array<string, string> $filters Filter values.
 * @return array<string, mixed>
 */
function dtl_where(array $filters)
{
    $conditions = array();
    $params = array();

    if ($filters['user'] !== '') {
        $conditions[] = '(user_name LIKE :user OR user_id = :user_id)';
        $params[':user'] = '%' . $filters['user'] . '%';
        $params[':user_id'] = $filters['user'];
    }

    if ($filters['action'] !== '') {
        $conditions[] = 'action_type LIKE :action';
        $params[':action'] = '%' . $filters['action'] . '%';
    }

    if ($filters['date_from'] !== '') {
        $conditions[] = 'created_at >= :date_from';
        $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
    }

    if ($filters['date_to'] !== '') {
        $conditions[] = 'created_at <= :date_to';
        $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
    }

    return array(
        'clause' => empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions),
        'params' => $params,
    );
}

/**
 * One page of date/time change logs, plus the total count and page count.
 *
 * @param  PDO                   $pdo     Open connection.
 * @param  array<string, string> $filters Filter values.
 * @param  int                   $page    Requested page.
 * @param  int                   $limit   Rows per page.
 * @return array<string, mixed>
 */
function dtl_logs(PDO $pdo, array $filters, $page, $limit)
{
    $where = dtl_where($filters);
    $offset = ($page - 1) * $limit;

    $countStmt = $pdo->prepare('SELECT COUNT(*) as total FROM datetime_change_logs ' . $where['clause']);

    foreach ($where['params'] as $key => $value) {
        $countStmt->bindValue($key, $value);
    }

    $countStmt->execute();
    $totalRecords = (int) $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = (int) ceil($totalRecords / $limit);

    $stmt = $pdo->prepare('SELECT * FROM datetime_change_logs ' . $where['clause'] . ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset');

    foreach ($where['params'] as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return array(
        'logs'          => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'total_records' => $totalRecords,
        'total_pages'   => $totalPages,
    );
}

/**
 * Distinct action types, for the filter dropdown.
 *
 * @param  PDO $pdo Open connection.
 * @return array<int, string>
 */
function dtl_action_types(PDO $pdo)
{
    $stmt = $pdo->prepare('SELECT DISTINCT action_type FROM datetime_change_logs ORDER BY action_type');
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * The signed-in account.
 *
 * The template never used it beyond an admin flag it didn't render, but the
 * lookup doubles as the access check for this page.
 *
 * @param  PDO $pdo Open connection.
 * @return array<string, mixed>|null
 */
function dtl_current_user(PDO $pdo)
{
    $stmt = $pdo->prepare('SELECT * FROM accounts WHERE id = :id');
    $stmt->bindParam(':id', $_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->execute();

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $user ?: null;
}

/**
 * Everything the view needs, in one call.
 *
 * @return array<string, mixed>
 */
function dtl_context()
{
    dtl_start_session();

    if (!dtl_is_authenticated()) {
        dtl_redirect('index.php');
        exit;
    }

    $pdo = db_pdo();
    $filters = dtl_filters();
    $limit = 25;
    $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;

    if ($page < 1) {
        $page = 1;
    }

    $data = array('logs' => array(), 'total_records' => 0, 'total_pages' => 0);
    $actionTypes = array();
    $user = null;

    try {
        $data = dtl_logs($pdo, $filters, $page, $limit);
        $actionTypes = dtl_action_types($pdo);
        $user = dtl_current_user($pdo);
    } catch (Throwable $e) {
        error_log('[date-time-logs] ' . $e->getMessage());
    }

    // Without a usable account the page is refused rather than mirroring the
    // original's fatal error.
    if (!$user) {
        error_log('[date-time-logs] signed-in account not found in accounts for user ' . ($_SESSION['user_id'] ?? '?'));
        dtl_redirect('index.php');
        exit;
    }

    return array(
        'user'             => $user,
        'is_admin'         => ($user['account_type'] === 'Admin'),
        'page'             => $page,
        'filter_user'      => $filters['user'],
        'filter_action'    => $filters['action'],
        'filter_date_from' => $filters['date_from'],
        'filter_date_to'   => $filters['date_to'],
        'logs'             => $data['logs'],
        'total_records'    => $data['total_records'],
        'total_pages'      => $data['total_pages'],
        'action_types'     => $actionTypes,
        'layout_menu'      => 'admin',
    );
}
