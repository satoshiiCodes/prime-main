<?php
session_start();

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || !isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Database configuration
require_once '../includes/db_config.php';

// Validate loan_id
if (!isset($_GET['loan_id']) || !is_numeric($_GET['loan_id'])) {
    echo json_encode(['error' => 'Invalid loan_id']);
    exit();
}

$loan_id = intval($_GET['loan_id']);

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get loan + customer + collector + branch info
    $stmt = $conn->prepare("
        SELECT 
            f.id AS loan_id,
            f.customer_id,
            c.first_name AS customer_first_name,
            c.middle_name AS customer_middle_name,
            c.last_name AS customer_last_name,
            c.suffix AS customer_suffix,
            c.branch AS customer_branch,
            col.first_name AS collector_first_name,
            col.last_name AS collector_last_name,
            col.branch AS collector_branch
        FROM financing f
        JOIN customer_accounts c ON f.customer_id = c.id
        LEFT JOIN collector_accounts col ON f.collector_id = col.id
        WHERE f.id = :loan_id
    ");
    $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
    $stmt->execute();
    $loan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$loan) {
        echo json_encode(['error' => 'Loan not found']);
        exit();
    }

    // Build customer full name
    $customerNameParts = [];
    if (!empty($loan['customer_first_name'])) $customerNameParts[] = $loan['customer_first_name'];
    if (!empty($loan['customer_middle_name'])) $customerNameParts[] = $loan['customer_middle_name'];
    if (!empty($loan['customer_last_name'])) $customerNameParts[] = $loan['customer_last_name'];
    if (!empty($loan['customer_suffix']) && strtolower($loan['customer_suffix']) !== 'none') {
        $customerNameParts[] = $loan['customer_suffix'];
    }
    $customer_name = trim(implode(' ', $customerNameParts));

    $collector_name = trim(($loan['collector_first_name'] ?? '') . ' ' . ($loan['collector_last_name'] ?? ''));
    if (empty($collector_name)) {
        $collector_name = 'N/A';
    }

    // Branch: prefer customer branch, fallback to collector branch
    $branch = $loan['customer_branch'] ?? $loan['collector_branch'] ?? 'N/A';

    // Get ALL withdrawal transactions for this loan (both 'Withdrawal' and 'Withdrawal Cash')
    $stmt = $conn->prepare("
        SELECT 
            st.id,
            st.transaction_type,
            st.amount,
            st.branch,
            st.payment_method,
            st.transaction_date,
            st.notes,
            st.saving_balance_after_payment,
            c.first_name AS collector_first_name,
            c.last_name AS collector_last_name
        FROM savings_transactions st
        JOIN savings_accounts sa ON st.savings_account_id = sa.id
        LEFT JOIN collector_accounts c ON st.collector_id = c.id
        WHERE sa.loan_id = :loan_id 
          AND st.transaction_type IN ('Withdrawal', 'Withdrawal Cash')
        ORDER BY st.transaction_date ASC
    ");
    $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $withdrawals = [];
    $total_amount = 0.0;

    foreach ($rows as $row) {
        $collector_display = trim(($row['collector_first_name'] ?? '') . ' ' . ($row['collector_last_name'] ?? ''));
        if (empty($collector_display)) {
            $collector_display = 'N/A';
        }

        $transaction_date_formatted = !empty($row['transaction_date']) 
            ? date("M d, Y g:i A", strtotime($row['transaction_date'])) 
            : 'N/A';

        // ------------------------------------------------------------
        // OVERRIDE DISPLAY FOR 'Withdrawal' TYPE
        // For 'Withdrawal' transaction type, force:
        //   - payment_method to "Payment"
        //   - notes to "Savings Payment"
        // (These overrides apply to display only; DB is not modified)
        // ------------------------------------------------------------
        $display_payment_method = $row['payment_method'] ?? 'Cash';
        $display_notes = $row['notes'] ?? '';

        if ($row['transaction_type'] === 'Withdrawal') {
            $display_payment_method = 'Payment';
            $display_notes = 'Savings Payment';
        }

        $withdrawals[] = [
            'id' => $row['id'],
            'transaction_type' => $row['transaction_type'],
            'amount' => floatval($row['amount']),
            'payment_method' => $display_payment_method,
            'transaction_date' => $row['transaction_date'],
            'transaction_date_formatted' => $transaction_date_formatted,
            'collector_name' => $collector_display,
            'notes' => $display_notes,
            'saving_balance_after_payment' => floatval($row['saving_balance_after_payment'] ?? 0),
        ];

        $total_amount += floatval($row['amount']);
    }

    echo json_encode([
        'success' => true,
        'loan_id' => $loan_id,
        'customer_name' => $customer_name,
        'collector_name' => $collector_name,
        'branch' => $branch,
        'withdrawals' => $withdrawals,
        'total_amount' => $total_amount,
        'count' => count($withdrawals)
    ]);

} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
}
?>