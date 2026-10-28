<?php
/**
 * Prime City - Dashboard data endpoint.
 *
 * Returns the chart collections as JSON so the dashboard can refresh a branch
 * without a full page reload.
 *
 *   GET api/dashboard-endpoint.php?branch=Gattaran
 *   GET api/dashboard-endpoint.php?branch=all&resource=collectors
 *
 * Resources: all (default), collectors, areas, summary.
 */

require_once __DIR__ . '/../actions/dashboard-actions.php';

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
function dashboard_json(array $payload, $status = 200)
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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    dashboard_json(array('ok' => false, 'error' => 'method_not_allowed'), 405);
}

$resource = strtolower(trim((string) ($_GET['resource'] ?? 'all')));
$allowed = array('all', 'collectors', 'areas', 'summary');

if (!in_array($resource, $allowed, true)) {
    dashboard_json(array(
        'ok'      => false,
        'error'   => 'unknown_resource',
        'allowed' => $allowed,
    ), 400);
}

try {
    $context = dashboard_context($_GET['branch'] ?? 'all', false);
} catch (DashboardAuthException $e) {
    dashboard_json(array('ok' => false, 'error' => 'unauthenticated'), 401);
} catch (Throwable $e) {
    error_log('[dashboard-endpoint] ' . $e->getMessage());
    dashboard_json(array('ok' => false, 'error' => 'server_error'), 500);
}

$payload = dashboard_chart_payload($context);
$summary = $context['summary'];

$response = array(
    'ok'          => true,
    'branch'      => $context['branch']['selected'],
    'branchLabel' => $context['branch']['label'],
    'branches'    => $context['branch']['available'],
    'generatedAt' => date('c'),
);

switch ($resource) {
    case 'collectors':
        $response['collectors'] = $payload['collectors'];
        break;

    case 'areas':
        $response['areas'] = $payload['areas'];
        break;

    case 'summary':
        $response['summary'] = $summary;
        break;

    default:
        $response['collectors'] = $payload['collectors'];
        $response['areas'] = $payload['areas'];
        $response['summary'] = $summary;
        break;
}

dashboard_json($response);
