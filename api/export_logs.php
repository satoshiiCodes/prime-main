<?php
/**
 * Prime City - Date/time change log CSV export.
 *
 * Called by dateTime_logs.php ("Export to CSV"), which posts the page's current
 * filters. Streams the same filtered rows the page shows, as a download.
 *
 * POST only (the caller builds a form); GET is honoured too so the URL can be
 * opened directly.
 *
 * The filter semantics are shared with the page: the posted values are mapped
 * onto the query string and dtl_where() builds the predicate, so the export can
 * never drift from the table.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../actions/date-time-logs-actions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(array('success' => false, 'error' => 'You are not signed in.'));
    exit;
}

if (!in_array(($_SERVER['REQUEST_METHOD'] ?? 'GET'), array('POST', 'GET'), true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(array('success' => false, 'error' => 'Method not allowed.'));
    exit;
}

// The page's filters, posted; tolerate a direct GET as well.
$filterKeys = array('filter_user', 'filter_action', 'filter_date_from', 'filter_date_to');

foreach ($filterKeys as $key) {
    if (isset($_POST[$key])) {
        $_GET[$key] = (string) $_POST[$key];
    }
}

/**
 * Neutralise spreadsheet formula injection in a cell.
 *
 * @param  mixed $value Raw cell value.
 * @return string
 */
function dtl_csv_cell($value)
{
    $cell = (string) $value;

    if ($cell !== '' && in_array($cell[0], array('=', '+', '-', '@', "\t", "\r"), true)) {
        $cell = "'" . $cell;
    }

    return $cell;
}

try {
    $pdo = db_pdo();
    $where = dtl_where(dtl_filters());

    $stmt = $pdo->prepare(
        'SELECT id, user_id, user_name, old_datetime, new_datetime, action_type, ip_address, user_agent, created_at '
        . 'FROM datetime_change_logs ' . $where['clause'] . ' ORDER BY created_at DESC'
    );
    $stmt->execute($where['params']);

    $filename = 'datetime_change_logs_' . date('Ymd_His') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');

    // UTF-8 BOM so Excel reads the file as UTF-8.
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, array(
        'ID',
        'User ID',
        'User Name',
        'Old DateTime',
        'New DateTime',
        'Action Type',
        'IP Address',
        'User Agent',
        'Created At',
    ));

    $rows = 0;

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, array(
            dtl_csv_cell($row['id']),
            dtl_csv_cell($row['user_id']),
            dtl_csv_cell($row['user_name']),
            dtl_csv_cell($row['old_datetime']),
            dtl_csv_cell($row['new_datetime']),
            dtl_csv_cell($row['action_type']),
            dtl_csv_cell($row['ip_address']),
            dtl_csv_cell($row['user_agent']),
            dtl_csv_cell($row['created_at']),
        ));

        $rows++;
    }

    fclose($out);

    error_log(sprintf(
        '[datetime-logs] exported %d rows for user %d',
        $rows,
        (int) ($_SESSION['user_id'] ?? 0)
    ));
} catch (Throwable $e) {
    error_log('[datetime-logs] export failed: ' . $e->getMessage());

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }

    echo json_encode(array('success' => false, 'error' => 'The export could not be generated.'));
}
