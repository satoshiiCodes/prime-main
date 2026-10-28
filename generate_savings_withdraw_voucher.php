<?php
require_once 'vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

session_start();

// Check if user is authenticated
if (!isset($_SESSION['logged_in'])) {
    header("Location: login.php");
    exit();
}

// Database configuration
require 'includes/db_config.php';

// ============================================================
// PARAMETER PARSING
// ============================================================
// Supported modes:
//  - transaction_ids=1,2,3  → multiple vouchers, one per customer (multi mode)
//  - transaction_id=1       → single transaction voucher (single mode)
//  - loan_id=1              → "Print All Voucher" for a loan (loan mode)
$transaction_ids_raw = $_GET['transaction_ids'] ?? null;
$transaction_ids = [];

if ($transaction_ids_raw) {
    foreach (explode(',', $transaction_ids_raw) as $tid) {
        $tid = filter_var(trim($tid), FILTER_VALIDATE_INT);
        if ($tid && $tid > 0) {
            $transaction_ids[] = $tid;
        }
    }
}

$transaction_id = isset($_GET['transaction_id']) ? filter_var($_GET['transaction_id'], FILTER_VALIDATE_INT) : null;
$loan_id = isset($_GET['loan_id']) ? filter_var($_GET['loan_id'], FILTER_VALIDATE_INT) : null;

// Flag: multi-transaction mode if the request came with transaction_ids
$is_multi_request = !empty($transaction_ids);

if (empty($transaction_ids) && (!$transaction_id || $transaction_id <= 0) && (!$loan_id || $loan_id <= 0)) {
    die("Error: Either transaction_ids, transaction_id, or loan_id parameter is required.");
}

try {
    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);

    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }

    // Get logged-in user's information
    $user_id = $_SESSION['user_id'];
    $account_source = $_SESSION['account_source'] ?? 'accounts';

    if ($account_source === 'accounts') {
        $user_sql = "SELECT first_name, middle_name, last_name, suffix, account_type, branch FROM accounts WHERE id = ?";
    } else {
        $user_sql = "SELECT first_name, middle_name, last_name, suffix, account_type, branch FROM proxy_accounts WHERE id = ?";
    }

    $user_stmt = $conn->prepare($user_sql);
    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();

    if ($user_result->num_rows === 0) {
        throw new Exception("User not found in database.");
    }

    $user = $user_result->fetch_assoc();
    $user_stmt->close();

    // Build user's full name
    $user_name_parts = [];
    if (!empty($user['first_name'])) $user_name_parts[] = $user['first_name'];
    if (!empty($user['middle_name'])) $user_name_parts[] = $user['middle_name'];
    if (!empty($user['last_name'])) $user_name_parts[] = $user['last_name'];
    if (!empty($user['suffix']) && strtolower($user['suffix']) !== 'none') {
        $user_name_parts[] = $user['suffix'];
    }
    $user_full_name = trim(implode(' ', $user_name_parts));

    $user_display_name_parts = [];
    if (!empty($user['first_name'])) $user_display_name_parts[] = $user['first_name'];
    if (!empty($user['middle_name'])) {
        $middle_initial = strtoupper(substr($user['middle_name'], 0, 1)) . '.';
        $user_display_name_parts[] = $middle_initial;
    }
    if (!empty($user['last_name'])) $user_display_name_parts[] = $user['last_name'];
    if (!empty($user['suffix']) && strtolower($user['suffix']) !== 'none') {
        $user_display_name_parts[] = $user['suffix'];
    }
    $user_display_name = trim(implode(' ', $user_display_name_parts));

    $user_account_type = $user['account_type'] ?? '';
    $user_branch = $user['branch'] ?? '';

    // ============================================================
    // BUILD VOUCHER JOBS
    //  - transaction_ids=1,2,3  → one job per ID (single-transaction algorithm)
    //  - transaction_id=1       → one job (single transaction)
    //  - loan_id=1              → one job (Print All Voucher — loan mode)
    // ============================================================
    $voucherJobs = [];

    if (!empty($transaction_ids)) {
        foreach ($transaction_ids as $tid) {
            $voucherJobs[] = ['transaction_id' => $tid];
        }
    } elseif ($transaction_id) {
        $voucherJobs[] = ['transaction_id' => $transaction_id];
    } else {
        $voucherJobs[] = ['loan_id' => $loan_id];
    }

    // ============================================================
    // LOGO RESOLUTION (shared for all vouchers)
    // ============================================================
    $logoHTML = '';
    $logoFound = false;
    $logoData = '';

    $logoPaths = [
        'assets/images/PrimeLogo.png',
        'assets/images/PrimeLogo.png',
        __DIR__ . 'assets/images/PrimeLogo.png',
        __DIR__ . 'assets/images/PrimeLogo.png',
        'assets/images/PrimeLogo.png',
        'assets/images/PrimeLogo.png'
    ];

    foreach ($logoPaths as $path) {
        if (file_exists($path)) {
            $logoData = $path;
            $logoFound = true;
            break;
        }
    }

    if ($logoFound) {
        $logoHTML = '<img src="' . htmlspecialchars($logoData) . '" class="logo" alt="PrimeCity Logo">';
    } else {
        // SVG fallback logo
        $logoSVG = '<?xml version="1.0" encoding="UTF-8"?>
        <svg xmlns="http://www.w3.org/2000/svg" width="200" height="80" viewBox="0 0 200 80">
            <rect width="200" height="80" fill="#ffffff" stroke="#cccccc" stroke-width="1"/>
            <text x="100" y="35" font-family="Arial" font-size="16" font-weight="bold" text-anchor="middle" fill="#000000">PRIMECITY</text>
            <text x="100" y="55" font-family="Arial" font-size="12" text-anchor="middle" fill="#333333">FINANCIAL CONSULTANCY SERVICES</text>
        </svg>';
        $logoHTML = '<img src="data:image/svg+xml;base64,' . base64_encode($logoSVG) . '" class="logo" alt="PrimeCity Logo">';
    }

    // ============================================================
    // HELPERS (amount-to-words)
    // ============================================================
    if (!function_exists('amountToWords')) {
        function amountToWords($number) {
            if ($number == 0) {
                return 'Zero Pesos';
            }

            $whole = floor($number);
            $fraction = round(($number - $whole) * 100);

            $words = convertNumberToWords($whole);
            $result = $words . ' Pesos';

            if ($fraction > 0) {
                $result .= ' and ' . convertNumberToWords($fraction) . ' Centavos';
            }

            return $result;
        }
    }

    if (!function_exists('convertNumberToWords')) {
        function convertNumberToWords($number) {
            if ($number == 0) {
                return 'Zero';
            }

            $ones = array(
                '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
                'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
                'Seventeen', 'Eighteen', 'Nineteen'
            );

            $tens = array(
                '', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'
            );

            $thousands = array('', 'Thousand', 'Million', 'Billion');

            $words = array();

            if ($number < 0) {
                $words[] = 'Negative';
                $number = abs($number);
            }

            $groups = array();
            while ($number > 0) {
                $groups[] = $number % 1000;
                $number = floor($number / 1000);
            }

            for ($i = count($groups) - 1; $i >= 0; $i--) {
                $group = $groups[$i];

                if ($group == 0) {
                    continue;
                }

                $groupWords = array();

                $hundreds = floor($group / 100);
                if ($hundreds > 0) {
                    $groupWords[] = $ones[$hundreds] . ' Hundred';
                    $group %= 100;
                }

                if ($group > 0) {
                    if ($group < 20) {
                        $groupWords[] = $ones[$group];
                    } else {
                        $tensDigit = floor($group / 10);
                        $onesDigit = $group % 10;
                        $groupWords[] = $tens[$tensDigit];
                        if ($onesDigit > 0) {
                            $groupWords[] = $ones[$onesDigit];
                        }
                    }
                }

                if ($i > 0 && !empty($groupWords)) {
                    $groupWords[] = $thousands[$i];
                }

                if (!empty($groupWords)) {
                    $words[] = implode(' ', $groupWords);
                }
            }

            return implode(' ', $words);
        }
    }

    // ============================================================
    // PROCESS EACH VOUCHER JOB
    // ============================================================
    $voucherHtmlBlocks = [];

    foreach ($voucherJobs as $job) {

        // Reset per-job variables
        $withdrawals = [];
        $record = null;
        $is_multi_mode = false;

        if (!empty($job['transaction_id'])) {
            // --- SINGLE TRANSACTION MODE (original algorithm) ---
            $transaction_id = (int)$job['transaction_id'];

            $single_sql = "
                SELECT 
                    st.id AS transaction_id,
                    st.transaction_type,
                    st.amount,
                    st.payment_method,
                    st.transaction_date,
                    st.notes,
                    st.saving_balance_after_payment,
                    sa.loan_id,
                    sa.account_number,
                    c.first_name AS collector_first_name,
                    c.last_name AS collector_last_name,
                    cu.first_name AS customer_first_name,
                    cu.middle_name AS customer_middle_name,
                    cu.last_name AS customer_last_name,
                    cu.suffix AS customer_suffix,
                    cu.branch AS customer_branch,
                    f.start_date,
                    f.end_date
                FROM savings_transactions st
                JOIN savings_accounts sa ON st.savings_account_id = sa.id
                JOIN customer_accounts cu ON sa.customer_id = cu.id
                LEFT JOIN financing f ON sa.loan_id = f.id
                LEFT JOIN collector_accounts c ON st.collector_id = c.id
                WHERE st.id = ?
                  AND st.transaction_type IN ('Withdrawal', 'Withdrawal Cash')
            ";

            $stmt = $conn->prepare($single_sql);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }

            $stmt->bind_param("i", $transaction_id);
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }

            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                $stmt->close();
                continue;
            }

            $record = $result->fetch_assoc();
            $stmt->close();

            $loan_id = intval($record['loan_id']);

            $collector_display = trim(($record['collector_first_name'] ?? '') . ' ' . ($record['collector_last_name'] ?? ''));
            if (empty($collector_display)) {
                $collector_display = 'N/A';
            }

            // OVERRIDE DISPLAY FOR 'Withdrawal' TYPE
            $display_payment_method = $record['payment_method'] ?? 'Cash';
            $display_notes = $record['notes'] ?? '';
            if ($record['transaction_type'] === 'Withdrawal') {
                $display_payment_method = 'Payment';
                $display_notes = 'Savings Payment';
            }

            $withdrawals[] = [
                'transaction_type' => $record['transaction_type'],
                'amount' => floatval($record['amount']),
                'payment_method' => $display_payment_method,
                'transaction_date' => $record['transaction_date'],
                'notes' => $display_notes,
                'collector_first_name' => $record['collector_first_name'],
                'collector_last_name' => $record['collector_last_name'],
                'collector_display' => $collector_display,
            ];

        } else {
            // --- LOAN MODE — "Print All Voucher" (original algorithm) ---
            $loan_id = (int)$job['loan_id'];

            $loan_sql = "SELECT 
                            f.*, 
                            c.first_name AS customer_first_name,
                            c.middle_name AS customer_middle_name,
                            c.last_name AS customer_last_name,
                            c.suffix AS customer_suffix,
                            c.address AS customer_address,
                            c.contact_number AS customer_contact,
                            c.branch AS customer_branch,
                            col.first_name AS collector_first_name,
                            col.last_name AS collector_last_name,
                            col.branch AS collector_branch
                        FROM financing f
                        JOIN customer_accounts c ON f.customer_id = c.id
                        LEFT JOIN collector_accounts col ON f.collector_id = col.id
                        WHERE f.id = ?";

            $stmt = $conn->prepare($loan_sql);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }

            $stmt->bind_param("i", $loan_id);
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }

            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                $stmt->close();
                continue;
            }

            $record = $result->fetch_assoc();
            $stmt->close();

            $withdrawals_sql = "
                SELECT st.*, 
                       sa.account_number,
                       c.first_name AS collector_first_name, 
                       c.last_name AS collector_last_name
                FROM savings_transactions st
                JOIN savings_accounts sa ON st.savings_account_id = sa.id
                LEFT JOIN collector_accounts c ON st.collector_id = c.id
                WHERE sa.loan_id = ? 
                  AND st.transaction_type IN ('Withdrawal', 'Withdrawal Cash')
                ORDER BY st.transaction_date ASC
            ";

            $stmt = $conn->prepare($withdrawals_sql);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }

            $stmt->bind_param("i", $loan_id);
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }

            $withdrawals_result = $stmt->get_result();
            while ($row = $withdrawals_result->fetch_assoc()) {
                $collector_display = trim(($row['collector_first_name'] ?? '') . ' ' . ($row['collector_last_name'] ?? ''));
                if (empty($collector_display)) {
                    $collector_display = 'N/A';
                }
                $row['collector_display'] = $collector_display;

                // OVERRIDE DISPLAY FOR 'Withdrawal' TYPE
                $display_payment_method = $row['payment_method'] ?? 'Cash';
                $display_notes = $row['notes'] ?? '';
                if ($row['transaction_type'] === 'Withdrawal') {
                    $display_payment_method = 'Payment';
                    $display_notes = 'Savings Payment';
                }
                $row['payment_method'] = $display_payment_method;
                $row['notes'] = $display_notes;

                $withdrawals[] = $row;
            }
            $stmt->close();
        }

        if (empty($withdrawals)) {
            continue;
        }

        // Build customer full name (original)
        $nameParts = [];
        if (!empty($record['customer_first_name'])) $nameParts[] = $record['customer_first_name'];
        if (!empty($record['customer_middle_name'])) $nameParts[] = $record['customer_middle_name'];
        if (!empty($record['customer_last_name'])) $nameParts[] = $record['customer_last_name'];
        if (!empty($record['customer_suffix']) && strtolower($record['customer_suffix']) !== 'none') {
            $nameParts[] = $record['customer_suffix'];
        }
        $customer_full_name = trim(implode(' ', $nameParts));

        // Format collector name (original)
        $collector_name = trim(($record['collector_first_name'] ?? '') . ' ' . ($record['collector_last_name'] ?? ''));
        if (empty($collector_name)) {
            $collector_name = 'N/A';
        }

        // Get customer and collector branches (original)
        $customer_branch = $record['customer_branch'] ?? '';
        $collector_branch = $record['collector_branch'] ?? $record['customer_branch'] ?? '';

        // ============================================================
        // PERMISSION CHECK (original)
        // ============================================================
        $restricted_account_types = ['Branch Manager', 'Proxy Collector-Cashier', 'Proxy Collector'];

        if (in_array($user_account_type, $restricted_account_types)) {
            $user_can_access = false;

            if (!empty($user_branch)) {
                if ($customer_branch === $user_branch || $collector_branch === $user_branch) {
                    $user_can_access = true;
                }
            }

            if (!$user_can_access) {
                die("Error: You do not have permission to generate documents for this loan. You can only access documents from your assigned branch: " . htmlspecialchars($user_branch));
            }
        } elseif ($user_account_type === 'Area Manager') {
            if (!empty($user_branch)) {
                $allowed_branches = array_map('trim', explode(',', $user_branch));
                $user_can_access = false;

                if (in_array($customer_branch, $allowed_branches) || in_array($collector_branch, $allowed_branches)) {
                    $user_can_access = true;
                }

                if (!$user_can_access) {
                    die("Error: You do not have permission to generate documents for this loan. Allowed branches: " . htmlspecialchars(implode(', ', $allowed_branches)));
                }
            }
        }

        // Calculate total withdrawals (original)
        $total_withdrawals = 0;
        foreach ($withdrawals as $w) {
            $total_withdrawals += floatval($w['amount']);
        }

        // Format dates (original)
        $current_date = date('F j, Y');

        // Generate voucher number (original)
        if (!empty($job['transaction_id'])) {
            $voucher_number = 'SWV-' . date('Ymd') . '-T' . str_pad($job['transaction_id'], 5, '0', STR_PAD_LEFT);
        } else {
            $voucher_number = 'SWV-' . date('Ymd') . '-' . str_pad($loan_id, 4, '0', STR_PAD_LEFT);
        }

        // Determine manager name based on branch (original)
        $manager_name = '';

        if (strtoupper($customer_branch) === 'TUGUEGARAO CITY') {
            $manager_name = 'Jehody A. Cureg';
        } elseif (strtoupper($customer_branch) === 'ILAGAN') {
            $manager_name = 'Rusel Jay V. Villanueva';
        } elseif (in_array(strtoupper($customer_branch), ['GATTARAN', 'ABULUG', 'APAYAO'])) {
            $manager_name = 'Vladimir S. Saludo';
        } else {
            $manager_name = $user_display_name;
        }

        // Determine voucher title (original)
        $voucher_title = 'SAVINGS WITHDRAWAL VOUCHER';

        // Determine payment method (original)
        if (!empty($job['transaction_id'])) {
            $display_method = $withdrawals[0]['payment_method'] ?? 'Cash';
        } else {
            $display_method = !empty($withdrawals) ? ($withdrawals[0]['payment_method'] ?? 'Cash') : 'Cash';
        }

        // ============================================================
        // BUILD VOUCHER HTML — WITH BIGGER LOGO & COMPACT DATA
        // ============================================================
        $voucherHtml = '
        <div class="voucher-container">
            <div class="voucher-number">Voucher No.: ' . htmlspecialchars($voucher_number) . '</div>

            <div class="voucher-header">
                ' . $logoHTML . '
                <h1>' . htmlspecialchars($voucher_title) . '</h1>
            </div>

            <div class="voucher-details">
                <table>
                    <tr>
                        <td>Date:</td>
                        <td>' . htmlspecialchars($current_date) . '</td>
                    </tr>
                    <tr>
                        <td>Total Amount:</td>
                        <td>PHP ' . number_format($total_withdrawals, 2) . '</td>
                    </tr>
                    <tr>
                        <td>To:</td>
                        <td>' . htmlspecialchars($customer_full_name) . '</td>
                    </tr>
                    <tr>
                        <td>Method:</td>
                        <td>' . htmlspecialchars($display_method) . '</td>
                    </tr>
                    <tr>
                        <td>Collector:</td>
                        <td>' . htmlspecialchars($collector_name) . '</td>
                    </tr>
                    <tr>
                        <td>Branch:</td>
                        <td>' . htmlspecialchars($customer_branch) . '</td>
                    </tr>
                </table>

                <div class="amount-in-words">
                    The sum of: ' . amountToWords($total_withdrawals) . ' Only
                </div>
            </div>';

        // ============================================================
        // WITHDRAWAL TRANSACTIONS TABLE
        // Shown ONLY in loan mode (?loan_id=X)
        // Hidden for ?transaction_id=X AND ?transaction_ids=1,2,3
        // ============================================================
        $show_transactions_table = (!empty($job['loan_id']));

        if ($show_transactions_table) {
            $voucherHtml .= '
                <h3 style="font-size: 13px; margin: 10px 0;">Withdrawal Transactions</h3>
                <table class="transactions-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Customer</th>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Collector</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>';

            $counter = 1;
            foreach ($withdrawals as $withdrawal) {
                $transaction_date = date("F j, Y g:i A", strtotime($withdrawal['transaction_date']));

                // Collector display
                if (isset($withdrawal['collector_display'])) {
                    $collector_display = $withdrawal['collector_display'];
                } else {
                    $collector_display = trim(($withdrawal['collector_first_name'] ?? '') . ' ' . ($withdrawal['collector_last_name'] ?? ''));
                    if (empty($collector_display)) {
                        $collector_display = 'N/A';
                    }
                }

                // Customer name for this row
                $row_customer_name = $withdrawal['customer_full_name'] ?? $customer_full_name;

                $voucherHtml .= '
                            <tr>
                                <td>' . $counter . '</td>
                                <td>' . htmlspecialchars($row_customer_name) . '</td>
                                <td>' . htmlspecialchars($transaction_date) . '</td>
                                <td>' . htmlspecialchars($withdrawal['transaction_type'] ?? 'N/A') . '</td>
                                <td>PHP ' . number_format($withdrawal['amount'], 2) . '</td>
                                <td>' . htmlspecialchars($withdrawal['payment_method'] ?? 'Cash') . '</td>
                                <td>' . htmlspecialchars($collector_display) . '</td>
                                <td>' . htmlspecialchars($withdrawal['notes'] ?? '') . '</td>
                            </tr>';
                $counter++;
            }

            $voucherHtml .= '
                            <tr class="total-row">
                                <td colspan="4" style="text-align: right;"><strong>TOTAL</strong></td>
                                <td colspan="4"><strong>PHP ' . number_format($total_withdrawals, 2) . '</strong></td>
                            </tr>
                        </tbody>
                    </table>';
        }

        $voucherHtml .= '
            <table class="signature-table">
                <tr>
                    <td>
                        <div>' . htmlspecialchars($collector_name) . '</div>
                        <div class="voucher-signature-line"></div>
                        <div style="font-weight: bold;">Account Officer</div>
                    </td>
                    <td>
                        <div>' . htmlspecialchars($manager_name) . '</div>
                        <div class="voucher-signature-line"></div>
                        <div style="font-weight: bold;">Manager</div>
                    </td>
                    <td>
                        <div class="voucher-signature-line1"></div>
                        <div style="font-weight: bold;">Signature</div>
                    </td>
                </tr>
            </table>
        </div>';

        $voucherHtmlBlocks[] = $voucherHtml;
    }

    if (empty($voucherHtmlBlocks)) {
        die("Error: No withdrawal transactions found for the specified record.");
    }

    // ============================================================
    // BUILD COMBINED HTML — TWO VOUCHERS PER PAGE
    // ============================================================
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
        <style>
            body {
                font-family: Arial, sans-serif;
                line-height: 1.4;
                margin: 0;
                padding: 0;
            }
            .underline {
                text-decoration: underline;
            }
            .text-center { text-align: center; }
            .text-left { text-align: left; }
            .text-right { text-align: right; }
            .text-uppercase { text-transform: uppercase; }
            .page {
                page-break-after: always;
            }
            .page:last-child {
                page-break-after: auto;
            }
            .voucher-container {
                max-width: 800px;
                margin: 0 auto 25px auto;
                border: 1px solid #ddd;
                padding: 18px;
                box-shadow: 0 0 10px rgba(0,0,0,0.1);
            }
            .voucher-header {
                text-align: center;
                margin-bottom: 15px;
                border-bottom: 2px solid #000;
                padding-bottom: 8px;
            }
            .voucher-header h1 {
                margin: 0;
                font-size: 20px;
                letter-spacing: 1px;
            }
            /* BIGGER LOGO */
            .logo {
                height: 120px;
                width: auto;
                margin-bottom: 6px;
            }
            .voucher-number {
                text-align: right;
                margin-bottom: 8px;
                font-size: 10px;
            }
            .voucher-details {
                margin-bottom: 15px;
            }
            .voucher-details table {
                width: 100%;
                border-collapse: collapse;
            }
            .voucher-details table td {
                padding: 6px 8px;
                border-bottom: 1px solid #ddd;
                font-size: 13px;
            }
            .voucher-details table td:first-child {
                font-weight: bold;
                width: 30%;
            }
            .signature-table {
                width: 100%;
                margin-top: 35px;
            }
            .signature-table td {
                padding: 8px;
                text-align: center;
                width: 33.33%;
                font-size: 12px;
            }
            .voucher-signature-line {
                border-top: 1px solid #000;
                margin-top: 5px;
                padding-top: 5px;
            }
            .voucher-signature-line1 {
                border-top: 1px solid #000;
                margin-top: 25px;
                padding-top: 5px;
            }
            .amount-in-words {
                font-style: italic;
                margin: 10px 0;
                padding: 8px;
                background-color: #f9f9f9;
                border-left: 3px solid #ccc;
                font-size: 12px;
            }
            .transactions-table {
                width: 100%;
                border-collapse: collapse;
                margin: 10px 0;
            }
            .transactions-table th {
                background-color: #f2f2f2;
                border: 1px solid #ddd;
                padding: 6px;
                text-align: left;
                font-size: 11px;
            }
            .transactions-table td {
                border: 1px solid #ddd;
                padding: 6px;
                font-size: 11px;
            }
            .total-row {
                font-weight: bold;
                background-color: #f9f9f9;
            }
        </style>
    </head>
    <body>';

    // Group voucher blocks into pages of two
    $voucherHtmlBlocks = array_values($voucherHtmlBlocks);
    $totalVouchers = count($voucherHtmlBlocks);
    $perPage = 2;

    for ($i = 0; $i < $totalVouchers; $i += $perPage) {
        $html .= '<div class="page">';
        for ($j = 0; $j < $perPage; $j++) {
            $idx = $i + $j;
            if ($idx >= $totalVouchers) {
                break;
            }
            $html .= $voucherHtmlBlocks[$idx];
        }
        $html .= '</div>';
    }

    $html .= '
    </body>
    </html>';

    // Configure Dompdf
    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'Arial');
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', true);
    $options->set('chroot', __DIR__);
    $options->set('tempDir', sys_get_temp_dir());
    $options->set('fontDir', sys_get_temp_dir());
    $options->set('fontCache', sys_get_temp_dir());

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('Legal', 'portrait');
    $dompdf->render();

    if ($is_multi_request) {
        $filename = "savings_withdrawal_vouchers_" . date('Ymd_His') . ".pdf";
    } elseif ($transaction_id) {
        $filename = "savings_withdrawal_voucher_T{$transaction_id}.pdf";
    } else {
        $filename = "savings_withdrawal_voucher_L{$loan_id}.pdf";
    }

    $dompdf->stream($filename, [
        "Attachment" => false
    ]);

} catch (Exception $e) {
    error_log("Error generating savings withdrawal voucher: " . $e->getMessage());
    die("Error: " . htmlspecialchars($e->getMessage()));
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
