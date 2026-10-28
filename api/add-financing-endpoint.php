<?php
/**
 * Prime City - New loan JSON endpoint.
 *
 * Everything add_financing.php used to answer with JSON:
 *
 *   GET  ?action=search&table=customers|collectors&q=…&branch=…&customer_id=…
 *   POST ?action=check-active-financing     customer_id=N
 *   POST ?action=create                     the new-loan form
 *
 * The response shapes match what the page's JavaScript already expects.
 */

require_once __DIR__ . '/../actions/add-financing-actions.php';

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
function af_json(array $payload, $status = 200)
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

af_start_session();

if (!af_is_authenticated()) {
    af_json(array('success' => false, 'message' => 'Unauthorized', 'results' => array()), 401);
}

$action = strtolower(trim((string) ($_GET['action'] ?? '')));
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    $conn = db();

    switch ($action) {
        case 'search':
            $results = af_search_options($conn, array(
                'table'       => $_GET['table'] ?? '',
                'q'           => $_GET['q'] ?? '',
                'branch'      => $_GET['branch'] ?? null,
                'customer_id' => $_GET['customer_id'] ?? null,
            ));

            af_json(array('results' => $results));
            break;

        case 'check-active-financing':
            if ($method !== 'POST') {
                header('Allow: POST');
                af_json(array('success' => false, 'message' => 'Method not allowed'), 405);
            }

            $customerId = intval($_POST['customer_id'] ?? 0);

            af_json(array('has_active_financing' => af_has_active_financing($conn, $customerId)));
            break;

        case 'create':
            if ($method !== 'POST') {
                header('Allow: POST');
                af_json(array('success' => false, 'message' => 'Method not allowed'), 405);
            }

            try {
                af_json(af_create_financing($conn, $_POST));
            } catch (Throwable $e) {
                error_log('[add-financing-endpoint] ' . $e->getMessage());
                af_json(array(
                    'success'       => false,
                    'message'       => $e->getMessage(),
                    'error_details' => $conn->error ?? null,
                ));
            }
            break;

        default:
            af_json(array(
                'success' => false,
                'message' => 'Unsupported action',
                'allowed' => array('search', 'check-active-financing', 'create'),
            ), 400);
    }
} catch (Throwable $e) {
    error_log('[add-financing-endpoint] ' . $e->getMessage());
    af_json(array('success' => false, 'message' => 'Could not complete the request.', 'results' => array()), 500);
}
