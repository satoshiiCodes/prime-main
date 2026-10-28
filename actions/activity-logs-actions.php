<?php
/**
 * Prime City - Activity logs actions.
 *
 * Server-side logic for activity_logs.php: the filtered/paginated log query,
 * the filter dropdown sources and the signed-in account (used only to decide
 * whether the clear-logs button is enabled).
 *
 * Read-only page: clearing the logs is performed by
 * api/clear_activity_logs.php, called over AJAX and left as-is.
 *
 * Produces no markup - the page includes it and renders.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Start the session once.
 *
 * @return void
 */
function al_start_session()
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
function al_is_authenticated()
{
    return isset($_SESSION['logged_in']);
}

/**
 * Redirect, falling back to JS when headers are already sent.
 *
 * @param  string $location Target path.
 * @return void
 */
function al_redirect($location)
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
function al_filters()
{
    return array(
        'user'      => isset($_GET['filter_user']) ? (string) $_GET['filter_user'] : '',
        'action'    => isset($_GET['filter_action']) ? (string) $_GET['filter_action'] : '',
        'date_from' => isset($_GET['date_from']) ? (string) $_GET['date_from'] : '',
        'date_to'   => isset($_GET['date_to']) ? (string) $_GET['date_to'] : '',
    );
}

/**
 * Build the WHERE clause and bound values for the given filters.
 *
 * @param  array<string, string> $filters Filter values.
 * @return array<string, mixed>
 */
function al_where(array $filters)
{
    $conditions = array();
    $params = array();

    if ($filters['user'] !== '') {
        $conditions[] = 'a.user_id = :user_id';
        $params[':user_id'] = $filters['user'];
    }

    if ($filters['action'] !== '') {
        $conditions[] = 'a.action LIKE :action';
        $params[':action'] = '%' . $filters['action'] . '%';
    }

    if ($filters['date_from'] !== '') {
        $conditions[] = 'DATE(a.created_at) >= :date_from';
        $params[':date_from'] = $filters['date_from'];
    }

    if ($filters['date_to'] !== '') {
        $conditions[] = 'DATE(a.created_at) <= :date_to';
        $params[':date_to'] = $filters['date_to'];
    }

    return array(
        'clause' => empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions),
        'params' => $params,
    );
}

/**
 * One page of activity logs, plus the total count and page count.
 *
 * @param  PDO                  $pdo     Open connection.
 * @param  array<string, string> $filters Filter values.
 * @param  int                  $page    Requested page.
 * @param  int                  $limit   Rows per page.
 * @return array<string, mixed>
 */
function al_logs(PDO $pdo, array $filters, $page, $limit)
{
    $where = al_where($filters);
    $offset = ($page - 1) * $limit;

    $countStmt = $pdo->prepare('SELECT COUNT(*) as total FROM activity_logs a ' . $where['clause']);

    foreach ($where['params'] as $key => $value) {
        $countStmt->bindValue($key, $value);
    }

    $countStmt->execute();
    $totalRecords = (int) $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = (int) ceil($totalRecords / $limit);

    $sql = "
        SELECT
            a.*,
            u.username,
            u.account_type,
            CONCAT(u.first_name, ' ', u.last_name) as user_name
        FROM activity_logs a
        LEFT JOIN accounts u ON a.user_id = u.id
        {$where['clause']}
        ORDER BY a.created_at DESC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);

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
 * Distinct actions, for the filter dropdown.
 *
 * @param  PDO $pdo Open connection.
 * @return array<int, string>
 */
function al_distinct_actions(PDO $pdo)
{
    $stmt = $pdo->query('SELECT DISTINCT action FROM activity_logs ORDER BY action');

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Accounts that can appear as the actor, for the filter dropdown.
 *
 * @param  PDO $pdo Open connection.
 * @return array<int, array<string, mixed>>
 */
function al_users(PDO $pdo)
{
    $stmt = $pdo->query("SELECT id, username, CONCAT(first_name, ' ', last_name) as name FROM accounts ORDER BY username");

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * The signed-in account, used only for the admin check.
 *
 * @param  PDO $pdo Open connection.
 * @return array<string, mixed>|null
 */
function al_current_user(PDO $pdo)
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
function al_context()
{
    al_start_session();

    if (!al_is_authenticated()) {
        al_redirect('index.php');
        exit;
    }

    $pdo = db_pdo();
    $filters = al_filters();
    $limit = 25;
    $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;

    if ($page < 1) {
        $page = 1;
    }

    $data = array('logs' => array(), 'total_records' => 0, 'total_pages' => 0);
    $distinctActions = array();
    $users = array();
    $user = null;

    try {
        $data = al_logs($pdo, $filters, $page, $limit);
        $distinctActions = al_distinct_actions($pdo);
        $users = al_users($pdo);
        $user = al_current_user($pdo);
    } catch (Throwable $e) {
        error_log('[activity-logs] ' . $e->getMessage());
    }

    // The clear-logs control is admin-only; without a usable account the page
    // is refused rather than mirroring the original's fatal error.
    if (!$user) {
        error_log('[activity-logs] signed-in account not found in accounts for user ' . ($_SESSION['user_id'] ?? '?'));
        al_redirect('index.php');
        exit;
    }

    return array(
        'user'              => $user,
        'is_admin'          => ($user['account_type'] === 'Admin'),
        'page'              => $page,
        'filter_user'       => $filters['user'],
        'filter_action'     => $filters['action'],
        'filter_date_from'  => $filters['date_from'],
        'filter_date_to'    => $filters['date_to'],
        'activity_logs'     => $data['logs'],
        'total_records'     => $data['total_records'],
        'total_pages'       => $data['total_pages'],
        'distinct_actions'  => $distinctActions,
        'users'             => $users,
        'layout_menu'       => 'admin',
    );
}
