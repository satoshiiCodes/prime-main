<?php
/**
 * Prime City - New employee loan JSON endpoint.
 *
 * Everything add_collector_loan.php used to answer with JSON:
 *
 *   GET  ?action=search&q=…&branch=…
 *   GET  ?action=collectors-by-branch&branch=…
 *   POST ?action=create          the new employee loan form
 *
 * The response shapes match what the page's JavaScript already expects.
 */

require_once __DIR__ . '/../actions/add-collector-loan-actions.php';

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
function acl_json(array $payload, $status = 200)
{
    if (!headers_sent()) {
        http_response_code($status);
    }

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

acl_start_session();

if (!acl_is_authenticated()) {
    acl_json(array('success' => false, 'message' => 'Unauthorized', 'results' => array()), 401);
}

$action = strtolower(trim((string) ($_GET['action'] ?? '')));
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    $conn = db();

    switch ($action) {
        case 'search':
            acl_json(array(
                'results' => acl_collector_options(
                    $conn,
                    trim((string) ($_GET['branch'] ?? '')),
                    trim((string) ($_GET['q'] ?? '')),
                    50,
                    true,
                    true
                ),
            ));
            break;

        case 'collectors-by-branch':
            $branch = trim((string) ($_GET['branch'] ?? ''));

            if ($branch === '') {
                acl_json(array('results' => array()));
            }

            acl_json(array('results' => acl_collector_options($conn, $branch, '', 50)));
            break;

        case 'create':
            if ($method !== 'POST') {
                header('Allow: POST');
                acl_json(array('success' => false, 'message' => 'Method not allowed'), 405);
            }

            try {
                acl_json(acl_create_loan($conn, $_POST));
            } catch (Throwable $e) {
                error_log('[add-collector-loan-endpoint] ' . $e->getMessage());
                acl_json(array(
                    'success'       => false,
                    'message'       => $e->getMessage(),
                    'error_details' => $conn->error ?? null,
                ));
            }
            break;

        default:
            acl_json(array(
                'success' => false,
                'message' => 'Unsupported action',
                'allowed' => array('search', 'collectors-by-branch', 'create'),
            ), 400);
    }
} catch (Throwable $e) {
    error_log('[add-collector-loan-endpoint] ' . $e->getMessage());
    acl_json(array('success' => false, 'message' => 'Could not complete the request.', 'results' => array()), 500);
}
