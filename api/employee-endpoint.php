<?php
/**
 * Prime City - Employee JSON endpoint.
 *
 * Read-only data for the employee list modals.
 *
 *   GET ?action=active-deductions&employee_id=31&employee_type=collector
 *   GET ?action=deduction-summary&employee_id=31&employee_type=collector
 *   GET ?action=active-allowances&employee_id=31&employee_type=collector
 *
 * The response shapes match the legacy api/get_active_deductions.php,
 * api/get_deduction_summary.php and api/get_active_allowances.php
 * handlers so the existing JavaScript keeps working unchanged.
 */

require_once __DIR__ . '/../actions/employee-actions.php';

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
function employee_json(array $payload, $status = 200)
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
 * Active deduction rows for one employee, with loan payment totals.
 *
 * @param  mysqli $conn         Open connection.
 * @param  int    $employeeId   Employee primary key.
 * @param  string $employeeType collector|proxy|accounts.
 * @return array<int, array<string, mixed>>
 */
function employee_active_deductions(mysqli $conn, $employeeId, $employeeType)
{
    $stmt = $conn->prepare(
        "SELECT * FROM employee_deductions
         WHERE employee_id = ? AND employee_type = ? AND is_active = 1
         ORDER BY
             CASE deduction_type
                 WHEN 'loan_payment' THEN 1
                 WHEN 'cash_advance' THEN 2
                 WHEN 'sss_loan' THEN 3
                 WHEN 'pagibig_loan' THEN 4
                 WHEN 'sss' THEN 5
                 WHEN 'pagibig' THEN 6
                 WHEN 'philhealth' THEN 7
             END,
             created_at DESC"
    );

    $stmt->bind_param('is', $employeeId, $employeeType);
    $stmt->execute();

    $result = $stmt->get_result();
    $deductions = array();

    while ($row = $result->fetch_assoc()) {
        if (in_array($row['deduction_type'], array('loan_payment', 'cash_advance', 'sss_loan', 'pagibig_loan'), true)) {
            $paymentStmt = $conn->prepare(
                'SELECT SUM(amount) as total_paid, COUNT(*) as payment_count
                 FROM deduction_payments
                 WHERE deduction_id = ?'
            );
            $paymentStmt->bind_param('i', $row['id']);
            $paymentStmt->execute();
            $paymentData = $paymentStmt->get_result()->fetch_assoc();

            $row['total_paid'] = isset($paymentData['total_paid']) ? $paymentData['total_paid'] : 0;
            $row['payment_count'] = isset($paymentData['payment_count']) ? $paymentData['payment_count'] : 0;
            $row['remaining_balance'] = $row['amount'] - $row['total_paid'];

            $paymentStmt->close();
        }

        foreach (array('effective_date', 'start_date', 'end_date', 'created_at') as $column) {
            if (!empty($row[$column])) {
                $row[$column] = date('M d, Y', strtotime($row[$column]));
            }
        }

        $deductions[] = $row;
    }

    $stmt->close();

    return $deductions;
}

/**
 * Totals per deduction type for one employee.
 *
 * @param  mysqli $conn         Open connection.
 * @param  int    $employeeId   Employee primary key.
 * @param  string $employeeType collector|proxy|accounts.
 * @return array<string, float>
 */
function employee_deduction_summary(mysqli $conn, $employeeId, $employeeType)
{
    $stmt = $conn->prepare(
        'SELECT d.*, COALESCE(SUM(dp.amount), 0) as total_paid
         FROM employee_deductions d
         LEFT JOIN deduction_payments dp ON d.id = dp.deduction_id
         WHERE d.employee_id = ? AND d.employee_type = ? AND d.is_active = 1
         GROUP BY d.id'
    );

    $stmt->bind_param('is', $employeeId, $employeeType);
    $stmt->execute();

    $result = $stmt->get_result();

    $summary = array(
        'loan_payment' => 0,
        'cash_advance' => 0,
        'sss'          => 0,
        'pagibig'      => 0,
        'philhealth'   => 0,
        'sss_loan'     => 0,
        'pagibig_loan' => 0,
        'total'        => 0,
    );

    $loanTypes = array('loan_payment', 'cash_advance', 'sss_loan', 'pagibig_loan');

    while ($row = $result->fetch_assoc()) {
        $type = $row['deduction_type'];
        $amount = (float) $row['amount'];
        $paid = (float) $row['total_paid'];

        if (!isset($summary[$type])) {
            continue;
        }

        if (in_array($type, $loanTypes, true)) {
            $remaining = $amount - $paid;
            if ($remaining > 0) {
                $summary[$type] += $remaining;
            }
        } else {
            $summary[$type] += $amount;
        }
    }

    $stmt->close();

    $summary['total'] = array_sum($summary);

    return $summary;
}

/**
 * Active allowances for one employee.
 *
 * @param  mysqli $conn         Open connection.
 * @param  int    $employeeId   Employee primary key.
 * @param  string $employeeType collector|proxy|accounts.
 * @return array<int, array<string, mixed>>
 */
function employee_active_allowances(mysqli $conn, $employeeId, $employeeType)
{
    $stmt = $conn->prepare(
        'SELECT id, allowance_type, amount, effective_date, end_date, notes
         FROM employee_allowances
         WHERE employee_id = ? AND employee_type = ? AND is_active = 1
         ORDER BY effective_date DESC'
    );

    $stmt->bind_param('is', $employeeId, $employeeType);
    $stmt->execute();

    $result = $stmt->get_result();
    $allowances = array();

    while ($row = $result->fetch_assoc()) {
        $allowances[] = $row;
    }

    $stmt->close();

    return $allowances;
}

employee_start_session();

if (!employee_is_authenticated()) {
    employee_json(array('success' => false, 'message' => 'Unauthorized'), 401);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    employee_json(array('success' => false, 'message' => 'Method not allowed'), 405);
}

$action = strtolower(trim((string) ($_GET['action'] ?? '')));
$employeeId = (int) ($_GET['employee_id'] ?? 0);
$employeeType = (string) ($_GET['employee_type'] ?? '');

if ($employeeId <= 0 || !in_array($employeeType, array('collector', 'proxy', 'accounts'), true)) {
    employee_json(array('success' => false, 'message' => 'Missing required parameters'), 400);
}

try {
    $conn = db();

    switch ($action) {
        case 'active-deductions':
            employee_json(array(
                'success'    => true,
                'deductions' => employee_active_deductions($conn, $employeeId, $employeeType),
            ));
            break;

        case 'deduction-summary':
            employee_json(array(
                'success' => true,
                'summary' => employee_deduction_summary($conn, $employeeId, $employeeType),
            ));
            break;

        case 'active-allowances':
            employee_json(array(
                'success'   => true,
                'allowances' => employee_active_allowances($conn, $employeeId, $employeeType),
            ));
            break;

        default:
            employee_json(array(
                'success' => false,
                'message' => 'Unsupported action',
                'allowed' => array('active-deductions', 'deduction-summary', 'active-allowances'),
            ), 400);
    }
} catch (Throwable $e) {
    error_log('[employee-endpoint] ' . $e->getMessage());
    employee_json(array('success' => false, 'message' => 'Could not load employee data.'), 500);
}
