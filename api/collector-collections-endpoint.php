<?php
/**
 * Prime City - Collector collections JSON endpoint.
 *
 * Backs the payment details modal on collector_collections_record.php.
 *
 *   GET ?action=payment-details&id=1234
 *
 * The response is the flat payment row (plus customer/collector/proxy names),
 * identical to api/get_payment_details.php, which stays in place for the
 * payment modal on customer_view_payment.php.
 */

require_once __DIR__ . '/../actions/collector-collections-actions.php';

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
function cc_json(array $payload, $status = 200)
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

cc_start_session();

if (!cc_is_authenticated()) {
    cc_json(array('error' => 'Unauthorized access'), 401);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    cc_json(array('error' => 'Method not allowed'), 405);
}

$action = strtolower(trim((string) ($_GET['action'] ?? '')));

if ($action !== 'payment-details') {
    cc_json(array(
        'error'   => 'Unsupported action',
        'allowed' => array('payment-details'),
    ), 400);
}

$paymentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$paymentId) {
    cc_json(array('error' => 'Invalid payment ID'), 400);
}

try {
    $pdo = db_pdo();

    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            c.first_name AS customer_first_name,
            c.last_name AS customer_last_name,
            c.middle_name AS customer_middle_name,
            c.suffix AS customer_suffix,
            col.first_name AS collector_first_name,
            col.last_name AS collector_last_name,
            COALESCE(CONCAT(px.first_name, ' ', px.last_name), 'N/A') AS proxy_name
        FROM payments p
        LEFT JOIN customer_accounts c ON p.customer_id = c.id
        LEFT JOIN collector_accounts col ON p.collector_id = col.id
        LEFT JOIN proxy_accounts px ON p.proxy = px.id
        WHERE p.id = :id
    ");
    $stmt->bindParam(':id', $paymentId, PDO::PARAM_INT);
    $stmt->execute();

    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        cc_json(array('error' => 'Payment not found'), 404);
    }

    $payment['customer_name'] = $payment['customer_last_name'] . ', ' . $payment['customer_first_name'];

    if (!empty($payment['customer_middle_name'])) {
        $payment['customer_name'] .= ' ' . $payment['customer_middle_name'];
    }

    cc_json($payment);
} catch (Throwable $e) {
    error_log('[collector-collections-endpoint] ' . $e->getMessage());
    cc_json(array('error' => 'Database error'), 500);
}
