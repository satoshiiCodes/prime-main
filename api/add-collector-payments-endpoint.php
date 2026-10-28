<?php
/**
 * Prime City - Employee loan payments JSON endpoint.
 *
 * Everything add_collector_payments.php used to answer with JSON:
 *
 *   GET  ?action=collectors-by-branch&branch=…
 *   GET  ?action=loans&collector_id=N
 *   POST ?action=create            the multi-payment form
 *
 * The response shapes match what the page's JavaScript already expects.
 */

require_once __DIR__ . '/../actions/add-collector-payments-actions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

/**
 * Emit a JSON response and stop.
 *
 * @param  array<string, mixed> $payload Response body.
 * @param  int                  $status  HTTP status code.
 * @return void
 */
function acp_json(array $payload, $status = 200)
{
    if (!headers_sent()) {
        http_response_code($status);
    }

    // Plain json_encode so the payload is byte-identical to the original page
    // (PHP escapes ₱ as \u20b1 by default, which the browser decodes the same).
    echo json_encode($payload);
    exit;
}

acp_start_session();

if (!acp_is_authenticated()) {
    acp_json(array('success' => false, 'message' => 'Unauthorized'), 401);
}

$action = strtolower(trim((string) ($_GET['action'] ?? '')));
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    $conn = db();

    switch ($action) {
        case 'collectors-by-branch':
            $branch = trim((string) ($_GET['branch'] ?? ''));


            acp_json(array('collectors' => acp_collectors_by_branch($conn, $branch)));
            break;

        case 'loans':
            $collectorId = intval($_GET['collector_id'] ?? 0);

            if ($collectorId <= 0) {
                acp_json(array('error' => 'Invalid collector ID'));
            }

            acp_json(array('loans' => acp_loans($conn, $collectorId)));
            break;

        case 'create':
            if ($method !== 'POST') {
                header('Allow: POST');
                acp_json(array('success' => false, 'message' => 'Method not allowed'), 405);
            }

            // Same clock-drift audit the page performed on every request.
            acp_ensure_datetime_log_table($conn);
            acp_track_datetime_drift($conn, acp_fetch_user($conn));

            try {
                acp_json(acp_process_payments($conn, $_POST, acp_fetch_user($conn)));
            } catch (Throwable $e) {
                error_log('[add-collector-payments-endpoint] ' . $e->getMessage());
                acp_json(array(
                    'success'       => false,
                    'message'       => $e->getMessage(),
                    'error_details' => $conn->error ?? null,
                ));
            }
            break;

        default:
            acp_json(array(
                'success' => false,
                'message' => 'Unsupported action',
                'allowed' => array('collectors-by-branch', 'loans', 'create'),
            ), 400);
    }
} catch (Throwable $e) {
    error_log('[add-collector-payments-endpoint] ' . $e->getMessage());
    acp_json(array('success' => false, 'message' => 'Could not complete the request.'), 500);
}
