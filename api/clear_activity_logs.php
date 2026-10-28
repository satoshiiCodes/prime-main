<?php
/**
 * Prime City - Clear the activity log.
 *
 * Called by activity_logs.php ("Clear logs" -> confirm). Empties the
 * activity_logs table and answers with JSON.
 *
 * POST only. Admin only - the same rule the page uses to render the control.
 *
 * Response: {"success": true, "deleted": <int>}
 *           {"success": false, "error": "<message>"}
 */

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

/**
 * Send a JSON error and stop.
 *
 * @param  int    $status  HTTP status code.
 * @param  string $message Message for the caller.
 * @return void
 */
function cal_fail($status, $message)
{
    http_response_code($status);
    echo json_encode(array('success' => false, 'error' => $message));
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    cal_fail(401, 'You are not signed in.');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    cal_fail(405, 'This endpoint only accepts POST.');
}

$userId = (int) ($_SESSION['user_id'] ?? 0);

try {
    $conn = db();

    $stmt = $conn->prepare('SELECT account_type FROM accounts WHERE id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $account = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$account) {
        cal_fail(403, 'Your account could not be found.');
    }

    if (($account['account_type'] ?? '') !== 'Admin') {
        cal_fail(403, 'Only an administrator may clear the activity log.');
    }

    if (!$conn->query('DELETE FROM activity_logs')) {
        throw new RuntimeException('Delete failed: ' . $conn->error);
    }

    $deleted = max(0, (int) $conn->affected_rows);

    // The table is the audit trail, so the clear itself is recorded in the
    // server log rather than as a surviving row.
    error_log(sprintf(
        '[activity-logs] cleared %d rows by user %d (%s) from %s',
        $deleted,
        $userId,
        (string) ($_SERVER['REMOTE_ADDR'] ?? '-'),
        (string) ($_SERVER['HTTP_USER_AGENT'] ?? '-')
    ));

    echo json_encode(array('success' => true, 'deleted' => $deleted));
} catch (Throwable $e) {
    error_log('[activity-logs] clear failed: ' . $e->getMessage());
    cal_fail(500, 'The activity log could not be cleared.');
}
