<?php
/**
 * Prime City - Customer JSON endpoint.
 *
 * Backs the customer list page's asynchronous calls.
 *
 *   GET  ?action=requirements&customer_id=12
 *   POST ?action=delete-customer        id=12
 *   POST ?action=delete-requirement     customer_id=12&file_index=0
 *
 * Every response is JSON with a `success` boolean.
 */

require_once __DIR__ . '/../actions/customer-actions.php';

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
function customer_json(array $payload, $status = 200)
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

/**
 * Map a thrown exception to a JSON error response.
 *
 * @param  Throwable $e Failure to report.
 * @return void
 */
function customer_json_fail(Throwable $e)
{
    $code = (int) $e->getCode();

    if ($code !== 401 && $code !== 403 && $code !== 404 && $code !== 409) {
        $code = 400;
    }

    if ($code >= 500) {
        error_log('[customer-endpoint] ' . $e->getMessage());
    }

    customer_json(array(
        'success' => false,
        'error'   => 'request_failed',
        'message' => $e->getMessage(),
    ), $code);
}

customer_start_session();

if (!customer_is_authenticated()) {
    customer_json(array(
        'success' => false,
        'error'   => 'unauthenticated',
        'message' => 'Your session has expired. Please sign in again.',
    ), 401);
}

try {
    $pdo = db_pdo();
    $user = customer_fetch_user($pdo);
} catch (Throwable $e) {
    error_log('[customer-endpoint] ' . $e->getMessage());
    customer_json(array(
        'success' => false,
        'error'   => 'server_error',
        'message' => 'Could not load your account. Please try again.',
    ), 500);
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = strtolower(trim((string) ($_GET['action'] ?? $_POST['action'] ?? '')));

$accountType = (string) ($user['account_type'] ?? '');
$canManage = !in_array($accountType, array('Collector', 'Proxy Collector'), true);
$canDelete = $canManage && $accountType !== 'Proxy Collector-Cashier';

switch ($action) {
    case 'requirements':
        if ($method !== 'GET') {
            header('Allow: GET');
            customer_json(array('success' => false, 'error' => 'method_not_allowed'), 405);
        }

        $customerId = filter_input(INPUT_GET, 'customer_id', FILTER_VALIDATE_INT) ?: 0;

        if ($customerId <= 0) {
            customer_json(array(
                'success' => false,
                'error'   => 'invalid_customer',
                'message' => 'A valid customer is required.',
            ), 400);
        }

        try {
            $customer = customer_assert_visible($pdo, $user, $customerId);
        } catch (Throwable $e) {
            customer_json_fail($e);
        }

        $requirements = array();

        if (!empty($customer['requirements'])) {
            foreach (explode(',', (string) $customer['requirements']) as $index => $path) {
                $requirements[] = array(
                    'index' => $index,
                    'name'  => basename((string) $path),
                    'path'  => (string) $path,
                );
            }
        }

        customer_json(array(
            'success'      => true,
            'customerId'   => $customerId,
            'requirements' => $requirements,
        ));
        break;

    case 'delete-customer':
        if ($method !== 'POST') {
            header('Allow: POST');
            customer_json(array('success' => false, 'error' => 'method_not_allowed'), 405);
        }

        if (!$canDelete) {
            customer_json(array(
                'success' => false,
                'error'   => 'forbidden',
                'message' => 'You are not allowed to delete customers.',
            ), 403);
        }

        $customerId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;

        if ($customerId <= 0) {
            customer_json(array(
                'success' => false,
                'error'   => 'invalid_customer',
                'message' => 'A valid customer ID is required.',
            ), 400);
        }

        try {
            customer_assert_visible($pdo, $user, $customerId);
        } catch (Throwable $e) {
            customer_json_fail($e);
        }

        try {
            $stmt = $pdo->prepare('DELETE FROM customer_accounts WHERE id = ?');
            $stmt->execute(array($customerId));

            if ($stmt->rowCount() === 0) {
                customer_json(array(
                    'success' => false,
                    'error'   => 'not_found',
                    'message' => 'No customer found with that ID.',
                ), 404);
            }
        } catch (PDOException $e) {
            // Foreign key block: the customer still owns loans/payments/savings.
            if ($e->getCode() === '23000') {
                customer_json(array(
                    'success' => false,
                    'error'   => 'active_loan',
                    'message' => 'Cannot delete Customer with an Active Loan.',
                ), 409);
            }

            error_log('[customer-endpoint] ' . $e->getMessage());
            customer_json(array(
                'success' => false,
                'error'   => 'delete_failed',
                'message' => 'Could not delete this customer.',
            ), 500);
        }

        customer_json(array(
            'success' => true,
            'message' => 'Customer deleted successfully.',
        ));
        break;

    case 'delete-requirement':
        if ($method !== 'POST') {
            header('Allow: POST');
            customer_json(array('success' => false, 'error' => 'method_not_allowed'), 405);
        }

        if (!$canManage) {
            customer_json(array(
                'success' => false,
                'error'   => 'forbidden',
                'message' => 'You are not allowed to change requirements.',
            ), 403);
        }

        $customerId = filter_input(INPUT_POST, 'customer_id', FILTER_VALIDATE_INT) ?: 0;
        $fileIndex = filter_input(INPUT_POST, 'file_index', FILTER_VALIDATE_INT);

        if ($customerId <= 0 || $fileIndex === null || $fileIndex === false) {
            customer_json(array(
                'success' => false,
                'error'   => 'invalid_request',
                'message' => 'Invalid parameters.',
            ), 400);
        }

        try {
            $customer = customer_assert_visible($pdo, $user, $customerId);
        } catch (Throwable $e) {
            customer_json_fail($e);
        }

        $requirements = empty($customer['requirements'])
            ? array()
            : explode(',', (string) $customer['requirements']);

        if (!isset($requirements[$fileIndex])) {
            customer_json(array(
                'success' => false,
                'error'   => 'not_found',
                'message' => 'File not found.',
            ), 404);
        }

        $fullPath = customer_requirement_path($requirements[$fileIndex]);

        if ($fullPath !== null) {
            @unlink($fullPath);
        }

        array_splice($requirements, $fileIndex, 1);

        $stmt = $pdo->prepare('UPDATE customer_accounts SET requirements = ? WHERE id = ?');
        $stmt->execute(array(implode(',', $requirements), $customerId));

        customer_json(array(
            'success'   => true,
            'message'   => 'Requirement deleted successfully.',
            'remaining' => count($requirements),
        ));
        break;

    default:
        customer_json(array(
            'success' => false,
            'error'   => 'unknown_action',
            'message' => 'Unsupported action.',
        ), 400);
}
