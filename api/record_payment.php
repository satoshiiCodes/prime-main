<?php
/**
 * Prime City - Record a deduction payment.
 *
 * Called by employee_lists.php ("Record Payment" modal). Inserts one row into
 * deduction_payments, which get_deduction_summary.php / get_active_deductions.php
 * / get_deductions_history.php already read to compute paid and remaining
 * amounts.
 *
 * POST only, multipart/form-data or urlencoded.
 * Fields: deduction_id, amount, payment_date, payment_period, notes
 *
 * Response: {"success": true, "id": <int>, "message": "..."}
 *           {"success": false, "message": "..."}
 */

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

/**
 * Send a JSON failure and stop.
 *
 * @param  int    $status  HTTP status code.
 * @param  string $message Message for the caller.
 * @return void
 */
function rp_fail($status, $message)
{
    http_response_code($status);
    echo json_encode(array('success' => false, 'message' => $message));
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    rp_fail(401, 'You are not signed in.');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    rp_fail(405, 'This endpoint only accepts POST.');
}

$deductionId = (int) ($_POST['deduction_id'] ?? 0);
$rawAmount = str_replace(',', '', trim((string) ($_POST['amount'] ?? '')));
$paymentDate = trim((string) ($_POST['payment_date'] ?? ''));
$paymentPeriod = trim((string) ($_POST['payment_period'] ?? ''));
$notes = trim((string) ($_POST['notes'] ?? ''));

if ($deductionId <= 0) {
    rp_fail(422, 'No deduction was selected.');
}

if (!is_numeric($rawAmount) || (float) $rawAmount <= 0) {
    rp_fail(422, 'Please enter a payment amount greater than 0.');
}

$amount = round((float) $rawAmount, 2);

if ($paymentDate === '') {
    $paymentDate = date('Y-m-d');
}

$parsed = DateTime::createFromFormat('Y-m-d', $paymentDate);

if (!$parsed || $parsed->format('Y-m-d') !== $paymentDate) {
    rp_fail(422, 'The payment date is not a valid date.');
}

// The modal offers 1st half / 2nd half / a free-text custom period.
if ($paymentPeriod === 'custom') {
    $paymentPeriod = trim((string) ($_POST['custom_period'] ?? ''));
}

try {
    $conn = db();

    $stmt = $conn->prepare('SELECT id, employee_id, employee_type, amount, is_active FROM employee_deductions WHERE id = ?');
    $stmt->bind_param('i', $deductionId);
    $stmt->execute();
    $result = $stmt->get_result();
    $deduction = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$deduction) {
        rp_fail(404, 'That deduction no longer exists.');
    }

    if ((int) $deduction['is_active'] !== 1) {
        rp_fail(409, 'That deduction is no longer active.');
    }

    $insert = $conn->prepare(
        'INSERT INTO deduction_payments (deduction_id, payment_date, amount, payment_period, notes, created_at) '
        . 'VALUES (?, ?, ?, ?, ?, NOW())'
    );
    $insert->bind_param('isdss', $deductionId, $paymentDate, $amount, $paymentPeriod, $notes);

    if (!$insert->execute()) {
        throw new RuntimeException('Insert failed: ' . $insert->error);
    }

    $newId = (int) $insert->insert_id;
    $insert->close();

    error_log(sprintf(
        '[employee-lists] deduction payment %d recorded: deduction %d, %s, %.2f, by user %d',
        $newId,
        $deductionId,
        $paymentDate,
        $amount,
        (int) ($_SESSION['user_id'] ?? 0)
    ));

    echo json_encode(array(
        'success' => true,
        'id'      => $newId,
        'message' => 'Payment recorded successfully!',
    ));
} catch (Throwable $e) {
    error_log('[employee-lists] record payment failed: ' . $e->getMessage());
    rp_fail(500, 'The payment could not be recorded.');
}
