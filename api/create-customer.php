<?php
/**
 * Prime City - Create customer (JSON).
 *
 * Backs the "Add New Customer" modal on customer_list.php. Same validation and
 * insert as the full registration page (actions/customer-registration-actions.php),
 * but answers with JSON instead of redirecting.
 *
 *   POST (multipart/form-data) -> {"success": true, "message": "..."}
 *                              or {"success": false, "message": "..."}
 */

require_once __DIR__ . '/../actions/customer-registration-actions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

/**
 * Emit a JSON response and stop.
 *
 * @param  array<string, mixed> $payload
 * @param  int                  $status
 * @return void
 */
function create_customer_json(array $payload, $status = 200)
{
    if (!headers_sent()) {
        http_response_code($status);
    }

    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

registration_start_session();

if (!registration_is_authenticated()) {
    create_customer_json(array('success' => false, 'message' => 'Your session has expired. Please sign in again.'), 401);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    create_customer_json(array('success' => false, 'message' => 'POST required.'), 405);
}

try {
    registration_store(db(), registration_upload_dirs());

    create_customer_json(array(
        'success' => true,
        'message' => 'Customer registered successfully!',
    ));
} catch (Throwable $error) {
    error_log('[create-customer] ' . $error->getMessage());

    create_customer_json(array(
        'success' => false,
        'message' => $error->getMessage(),
    ), 422);
}
