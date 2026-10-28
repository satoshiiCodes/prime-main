<?php
/**
 * Prime City - Customer list live updates (Server-Sent Events).
 *
 * Keeps one connection open and pushes a `customers` event whenever the
 * customer table changes (registration, edit, delete) so the list page can
 * update itself without a reload.
 *
 * Payload:
 *   { "total": 71, "active": 71, "inactive": 0, "other": 0,
 *     "branches": 3, "signature": "…", "at": "2026-10-26 11:02:44" }
 *
 * The connection is deliberately short-lived (STREAM_SECONDS): the browser's
 * EventSource reconnects on its own, which keeps a single PHP worker from being
 * held open forever on Apache.
 */

require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['logged_in'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not authenticated';
    exit;
}

/* The stream must not hold the session lock: the page keeps making AJAX calls. */
session_write_close();

/* ---- streaming plumbing ------------------------------------------------ */
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
@set_time_limit(0);
ignore_user_abort(false);

while (ob_get_level() > 0) {
    @ob_end_flush();
}

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
header('X-Content-Type-Options: nosniff');

define('STREAM_SECONDS', 55);
define('POLL_MICROSECONDS', 1200000);
define('HEARTBEAT_SECONDS', 15);

/**
 * Compact fingerprint of the customer table plus the counters shown on the page.
 *
 * @param  PDO $pdo
 * @return array<string, mixed>
 */
function customer_snapshot(PDO $pdo)
{
    $sql = "SELECT
                COUNT(*) AS total,
                COALESCE(SUM(status = 'Active'), 0) AS active,
                COALESCE(SUM(status = 'Inactive'), 0) AS inactive,
                COALESCE(SUM(COALESCE(status, '') NOT IN ('Active', 'Inactive')), 0) AS other,
                COUNT(DISTINCT branch) AS branches,
                COALESCE(BIT_XOR(CRC32(CONCAT_WS('|',
                    id, last_name, first_name, middle_name, suffix, username, branch, area,
                    status, contact_number, contact_number_2, registration_date,
                    profile_image, requirements
                ))), 0) AS signature
            FROM customer_accounts";

    $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return array(
            'total' => 0,
            'active' => 0,
            'inactive' => 0,
            'other' => 0,
            'branches' => 0,
            'signature' => '0',
            'at' => date('Y-m-d H:i:s'),
        );
    }

    return array(
        'total' => (int) $row['total'],
        'active' => (int) $row['active'],
        'inactive' => (int) $row['inactive'],
        'other' => (int) $row['other'],
        'branches' => (int) $row['branches'],
        'signature' => (string) $row['signature'],
        'at' => date('Y-m-d H:i:s'),
    );
}

/**
 * Write one SSE frame.
 *
 * @param  string $event
 * @param  array<string, mixed> $payload
 * @return void
 */
function stream_event($event, array $payload)
{
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
    @flush();
}

echo "retry: 3000\n\n";
@flush();

$started = time();
$lastSignature = null;
$lastBeat = time();

while (!connection_aborted() && (time() - $started) < STREAM_SECONDS) {
    try {
        $snapshot = customer_snapshot(db_pdo());
    } catch (Throwable $error) {
        stream_event('stream-error', array('message' => 'Could not read customers'));
        break;
    }

    if ($snapshot['signature'] !== $lastSignature) {
        $lastSignature = $snapshot['signature'];
        stream_event('customers', $snapshot);
        $lastBeat = time();
    } elseif ((time() - $lastBeat) >= HEARTBEAT_SECONDS) {
        echo ": keep-alive\n\n";
        @flush();
        $lastBeat = time();
    }

    usleep(POLL_MICROSECONDS);
}

/* Tell the client this leg is over; EventSource reconnects automatically. */
stream_event('stream-end', array('reason' => 'timeout', 'at' => date('Y-m-d H:i:s')));
