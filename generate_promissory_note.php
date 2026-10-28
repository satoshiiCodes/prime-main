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

// Get ID from either loan_id or financing_id parameter
if (isset($_GET['loan_id']) && !empty($_GET['loan_id'])) {
    $id_param = filter_var($_GET['loan_id'], FILTER_VALIDATE_INT);
    $param_name = 'loan_id';
} elseif (isset($_GET['financing_id']) && !empty($_GET['financing_id'])) {
    $id_param = filter_var($_GET['financing_id'], FILTER_VALIDATE_INT);
    $param_name = 'financing_id';
} else {
    die("Error: Either loan_id or financing_id parameter is required.");
}

// Validate ID
if ($id_param === false || $id_param <= 0) {
    die("Error: Invalid ID provided. Please provide a valid numeric ID.");
}

try {
    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    // Check connection
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }

    // Get logged-in user's information
    $user_id = $_SESSION['user_id'];
    $account_source = $_SESSION['account_source'] ?? 'accounts';
    
    // Query based on account source
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
    
    // Build user's full name for display
    $display_name_parts = [];
    if (!empty($user['first_name'])) $display_name_parts[] = $user['first_name'];
    
    // Add middle initial (first letter only) if exists
    if (!empty($user['middle_name'])) {
        $middle_initial = strtoupper(substr($user['middle_name'], 0, 1)) . '.';
        $display_name_parts[] = $middle_initial;
    }
    
    if (!empty($user['last_name'])) $display_name_parts[] = $user['last_name'];
    
    if (!empty($user['suffix']) && strtolower($user['suffix']) !== 'none') {
        $display_name_parts[] = $user['suffix'];
    }
    $user_display_name = trim(implode(' ', $display_name_parts));
    
    // Also build full name for other uses if needed
    $user_name_parts = [];
    if (!empty($user['first_name'])) $user_name_parts[] = $user['first_name'];
    if (!empty($user['middle_name'])) $user_name_parts[] = $user['middle_name'];
    if (!empty($user['last_name'])) $user_name_parts[] = $user['last_name'];
    if (!empty($user['suffix']) && strtolower($user['suffix']) !== 'none') {
        $user_name_parts[] = $user['suffix'];
    }
    $user_full_name = trim(implode(' ', $user_name_parts));
    
    // Get user's account type and branch
    $user_account_type = $user['account_type'] ?? '';
    $user_branch = $user['branch'] ?? '';

    // Initialize prepared_by_name
    $prepared_by_name = '';
    
    // Check if user is a Proxy Collector-Cashier
    if ($user_account_type === 'Proxy Collector-Cashier') {
        $prepared_by_name = $user_full_name;
    }

    // Get loan/financing details with prepared statement
    $sql = "SELECT 
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
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("i", $id_param);
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        die("Error: No record found with {$param_name}: " . $id_param);
    }
    
    $record = $result->fetch_assoc();
    $stmt->close();
    
    // Build customer full name
    $nameParts = [];
    if (!empty($record['customer_first_name'])) $nameParts[] = $record['customer_first_name'];
    if (!empty($record['customer_middle_name'])) $nameParts[] = $record['customer_middle_name'];
    if (!empty($record['customer_last_name'])) $nameParts[] = $record['customer_last_name'];
    if (!empty($record['customer_suffix']) && strtolower($record['customer_suffix']) !== 'none') {
        $nameParts[] = $record['customer_suffix'];
    }
    $customer_full_name = trim(implode(' ', $nameParts));
    
    // Format collector name
    $collector_name = trim(($record['collector_first_name'] ?? '') . ' ' . ($record['collector_last_name'] ?? ''));
    
    // Get customer and collector branches
    $customer_branch = $record['customer_branch'] ?? '';
    $collector_branch = $record['collector_branch'] ?? '';

    // IMPORTANT: Check if user has permission to generate documents for this customer/loan
    $restricted_account_types = ['Branch Manager', 'Proxy Collector-Cashier', 'Proxy Collector'];
    
    if (in_array($user_account_type, $restricted_account_types)) {
        // For restricted users, they can only access documents from their assigned branch
        $user_can_access = false;
        
        // Check if either customer or collector belongs to user's branch
        if (!empty($user_branch)) {
            if ($customer_branch === $user_branch || $collector_branch === $user_branch) {
                $user_can_access = true;
            }
        }
        
        // If user cannot access, show error
        if (!$user_can_access) {
            die("Error: You do not have permission to generate documents for this loan. You can only access documents from your assigned branch: " . htmlspecialchars($user_branch));
        }
    } elseif ($user_account_type === 'Area Manager') {
        // Area Manager can have multiple branches
        if (!empty($user_branch)) {
            $allowed_branches = array_map('trim', explode(',', $user_branch));
            $user_can_access = false;
            
            // Check if customer or collector branch is in allowed branches
            if (in_array($customer_branch, $allowed_branches) || in_array($collector_branch, $allowed_branches)) {
                $user_can_access = true;
            }
            
            if (!$user_can_access) {
                die("Error: You do not have permission to generate documents for this loan. Allowed branches: " . htmlspecialchars(implode(', ', $allowed_branches)));
            }
        }
    }

    // Format dates
    $date_released = date("F j, Y", strtotime($record['start_date']));
    $due_date = date("F j, Y", strtotime($record['end_date']));
    $current_date = date('F j, Y');

    // Calculate amounts
    $total_amount = $record['amount_financed'] + $record['interest_peso'];
    $promissory_note_amount = $total_amount;
    $cash_voucher_amount = $record['amount_financed'] - ($record['service_fee'] ?? 0);
    $balance = $total_amount - ($record['total_paid'] ?? 0);

    // Generate voucher number (format: V-YYYYMMDD-XXXX)
    $voucher_number = 'V-' . date('Ymd') . '-' . str_pad($id_param, 4, '0', STR_PAD_LEFT);

    // Determine manager name based on branch
    $manager_name = '';
    
    if (strtoupper($customer_branch) === 'TUGUEGARAO CITY') {
        $manager_name = 'Jehody A. Cureg';
    } elseif (strtoupper($customer_branch) === 'ILAGAN') {
        $manager_name = 'Rusel Jay V. Villanueva';
    } elseif (in_array(strtoupper($customer_branch), ['GATTARAN', 'ABULUG', 'APAYAO'])) {
        $manager_name = 'Vladimir S. Saludo';
    } else {
        // Default to user's display name if branch doesn't match
        $manager_name = $user_display_name;
    }

    // Function to convert amount to words
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
        
        // Handle negative numbers
        if ($number < 0) {
            $words[] = 'Negative';
            $number = abs($number);
        }
        
        // Split number into groups of three digits
        $groups = array();
        while ($number > 0) {
            $groups[] = $number % 1000;
            $number = floor($number / 1000);
        }
        
        // Process each group
        for ($i = count($groups) - 1; $i >= 0; $i--) {
            $group = $groups[$i];
            
            if ($group == 0) {
                continue;
            }
            
            $groupWords = array();
            
            // Hundreds
            $hundreds = floor($group / 100);
            if ($hundreds > 0) {
                $groupWords[] = $ones[$hundreds] . ' Hundred';
                $group %= 100;
            }
            
            // Tens and Ones
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
            
            // Add thousand, million, etc.
            if ($i > 0 && !empty($groupWords)) {
                $groupWords[] = $thousands[$i];
            }
            
            if (!empty($groupWords)) {
                $words[] = implode(' ', $groupWords);
            }
        }
        
        return implode(' ', $words);
    }

    // ===========================================
    // INFINITYFREE LOGO SOLUTION
    // ===========================================
    $logoHTML = '';
    $logoHTML_voucher = '';
    
    // Try to find and use the logo file
    $logoPaths = [
        'assets/images/PrimeLogo.png',
        'assets/images/PrimeLogo.png',
        __DIR__ . 'assets/images/PrimeLogo.png',
        __DIR__ . 'assets/images/PrimeLogo.png',
        'assets/images/PrimeLogo.png',
        'assets/images/PrimeLogo.png'
    ];
    
    $logoFound = false;
    $logoData = '';
    
    foreach ($logoPaths as $path) {
        if (file_exists($path)) {
            // For InfinityFree, try to use the file path directly
            $logoData = $path;
            $logoFound = true;
            break;
        }
    }
    
    if ($logoFound) {
        // Promissory note logo (larger)
        $logoHTML = '<img src="' . htmlspecialchars($logoData) . '" class="logo" alt="PrimeCity Logo">';
        // Cash voucher logo (bigger than before)
        $logoHTML_voucher = '<img src="' . htmlspecialchars($logoData) . '" class="logo-voucher" alt="PrimeCity Logo">';
    } else {
        // Create SVG logo as fallback
        $logoSVG = '<?xml version="1.0" encoding="UTF-8"?>
        <svg xmlns="http://www.w3.org/2000/svg" width="200" height="80" viewBox="0 0 200 80">
            <rect width="200" height="80" fill="#ffffff" stroke="#cccccc" stroke-width="1"/>
            <text x="100" y="35" font-family="Arial" font-size="16" font-weight="bold" text-anchor="middle" fill="#000000">PRIMECITY</text>
            <text x="100" y="55" font-family="Arial" font-size="12" text-anchor="middle" fill="#333333">FINANCIAL CONSULTANCY SERVICES</text>
        </svg>';
        
        $logoBase64 = 'data:image/svg+xml;base64,' . base64_encode($logoSVG);
        $logoHTML = '<img src="' . $logoBase64 . '" class="logo" alt="PrimeCity Logo">';
        $logoHTML_voucher = '<img src="' . $logoBase64 . '" class="logo-voucher" alt="PrimeCity Logo">';
    }
    // ===========================================

    // HTML content for both pages
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
        <style>
            /* Common styles */
            @page {
                margin: 0.5in;
            }
            body {
                font-family: Arial, sans-serif;
                line-height: 1.5;
                margin: 0;
            }
            .header {
                text-align: center;
                margin-bottom: 20px;
            }
            .logo {
                height: 150px;
                width: auto;
                margin-bottom: 10px;
            }
            /* BIGGER LOGO for cash voucher */
            .logo-voucher {
                height: 110px;
                width: auto;
                margin-bottom: 5px;
            }
            .underline {
                text-decoration: underline;
            }
            .text-center {
                text-align: center;
            }
            .text-left {
                text-align: left;
            }
            .text-right {
                text-align: right;
            }
            .text-uppercase {
                text-transform: uppercase;
            }
            .mt-4 {
                margin-top: 40px;
            }
            strong {
                font-weight: bold;
            }
            
            /* Promissory note specific styles */
            .note-title {
                text-align: center;
                font-size: 18px;
                font-weight: bold;
                margin: 20px 0;
                text-decoration: underline;
            }
            .content {
                margin: 20px 0;
            }
            .signature-section {
                margin-top: 60px;
            }
            .signature-line {
                border-top: 1px solid #000;
                width: 250px;
                margin: 40px 0 5px 0;
            }
            .signature-label {
                font-size: 14px;
                margin-top: -10px;
                font-weight: bold;
            }
            .signature-label-voucher {
                font-size: 13px;
                font-weight: bold;
            }
            .customer-info {
                margin-bottom: 20px;
                border: 1px solid #ddd;
                padding: 10px;
                background-color: #f9f9f9;
            }
            
            /* Co-maker section styles */
            .comaker-section {
                margin-top: 30px;
                text-align: right;
            }
            .comaker-line {
                border-top: 1px solid #000;
                display: inline-block;
                width: 200px;
                margin-bottom: 5px;
                margin-top: -69px;
            }
            .comaker-label {
                text-align: right;
                white-space: nowrap;
                font-size: 14px;
                margin: -76px 75px;
            }
            
            /* Cash voucher specific styles - FITTED ON HALF PAGE */
            .voucher-half-page {
                height: 5.5in;
                page-break-inside: avoid;
                overflow: hidden;
            }
            .voucher-container {
                max-width: 100%;
                margin: 0 auto;
                border: 1px solid #999;
                padding: 12px 18px;
            }
            .voucher-header {
                text-align: center;
                margin-bottom: 8px;
                border-bottom: 2px solid #000;
                padding-bottom: 6px;
            }
            .voucher-header h1 {
                margin: 4px 0 0 0;
                font-size: 20px;
                letter-spacing: 2px;
            }
            .voucher-number {
                text-align: right;
                margin-bottom: 4px;
                font-size: 10px;
            }
            .voucher-details {
                margin-bottom: 8px;
            }
            .voucher-details table {
                width: 100%;
                border-collapse: collapse;
            }
            .voucher-details table td {
                padding: 5px 6px;
                border-bottom: 1px solid #ddd;
                font-size: 12px;
            }
            .voucher-details table td:first-child {
                font-weight: bold;
                width: 28%;
            }
            .signature-table {
                width: 100%;
                margin-top: 22px;
            }
            .signature-table td {
                padding: 6px;
                text-align: center;
                width: 33.33%;
                font-size: 11px;
            }
            .voucher-signature-line {
                border-top: 1px solid #000;
                margin-top: 4px;
                padding-top: 4px;
            }
            .voucher-signature-line1 {
                border-top: 1px solid #000;
                margin-top: 26px;
                padding-top: 4px;
            }
            .amount-in-words {
                font-style: italic;
                margin: 8px 0;
                padding: 6px 8px;
                background-color: #f9f9f9;
                border-left: 3px solid #ccc;
                font-size: 11px;
            }
            
            /* Page break */
            .page-break {
                page-break-after: always;
            }
        </style>
    </head>
    <body>
        <!-- Page 1: Promissory Note -->
        <div class="header">
            ' . $logoHTML . '
            <div class="text-uppercase underline"><strong>PRIMECITY FINANCIAL CONSULTANCY SERVICES</strong></div>
        </div>
        
        <div class="note-title text-uppercase">--PROMISSORY NOTE--</div>
        
        <div class="content">
            <p>Date Released: ' . $date_released . '</p>
            <p>Due Date: ' . $due_date . '</p>
            
            <p>FOR VALUE RECEIVED, I/We jointly and severally promise to pay to 
            <strong class="underline">PRIMECITY FINANCIAL CONSULTANCY SERVICES (PFCS)</strong> or
            order, the sum <strong>' . amountToWords($promissory_note_amount) . '</strong> 
            (Php ' . number_format($promissory_note_amount, 2) . '), payables in DAILY instalment thereafter until fully
            and completely paid. In case of non-payment of this note after maturity
            date, I/We jointly and severally agree to pay the additional amount
            equivalent to <span class="underline">Five (5)</span> percent per month based on the
            total amount due as penalty interest, compounded monthly until fully
            paid.</p>
            
            <p>DEMAND AND NOTICE OF DISHONOR WAIVED. Holder(s) may accept partial
            payment(s) and grant renewal or extension of payment reserving it\'s
            their right against each and all parties to this note. Acceptance by
            holder(s) thereof or any partial payment(s) after due date shall not be
            considered as extending the time for payment or as</p>
            
            <p>All action arising from or connected with this note shall be brought
            only the proper place of execution of this note at the sole option of
            <strong class="underline">PRIMECITY FINANCIAL CONSULTANCY SERVICES (PFCS)</strong>.</p>
        </div>
        
        <div class="signature-section">
            <div class="text-left">';
    
    // Display "Prepared by:" based on user type
    if ($user_account_type === 'Proxy Collector-Cashier' && !empty($prepared_by_name)) {
        $html .= '<p>Prepared by: <span class="underline">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; ' . htmlspecialchars($user_display_name) . '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span></p>';
    } else {
        $html .= '<p>Prepared by: ________________________</p>';
    }
    
    $html .= '
                <p class="signature-label">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                                        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; 
                                        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                                        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; CASHIER</p>
            </div>
            
            <div class="text-left mt-4">
                <p>Approved by: <span class="underline">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; ' . htmlspecialchars($manager_name) . ' &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; </span></p>
                <p class="signature-label">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                                        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; 
                                        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                                        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; MANAGER</p>
            </div>
            
            <div class="mt-4">
                <p>Received: <span class="underline">&nbsp;&nbsp; ' . htmlspecialchars($customer_full_name) . ' &nbsp;&nbsp;</span>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                                        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                                        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; ________________________</p>
                <p class="signature-label">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                                        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                                        &nbsp;&nbsp;&nbsp;&nbsp; BORROWER 
                                        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                                        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                                        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                                        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                                        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; Co-Maker</p>
            </div>
        </div>
        
        <!-- Page break -->
        <div class="page-break"></div>
        
        <!-- Page 2: Cash Voucher (fitted on half page) -->
        <div class="voucher-half-page">
            <div class="voucher-container">
                <div class="voucher-number">Voucher No.: ' . htmlspecialchars($voucher_number) . '</div>
                
                <div class="voucher-header">
                    ' . $logoHTML_voucher . '
                    <h1>CASH VOUCHER</h1>
                </div>
                
                <div class="voucher-details">
                    <table>
                        <tr>
                            <td>Transaction Type:</td>
                            <td>Loan Disbursement</td>
                        </tr>
                        <tr>
                            <td>Date:</td>
                            <td>' . htmlspecialchars($date_released) . '</td>
                        </tr>
                        <tr>
                            <td>Amount:</td>
                            <td>PHP ' . number_format($cash_voucher_amount, 2) . '</td>
                        </tr>
                        <tr>
                            <td>To:</td>
                            <td>' . htmlspecialchars($customer_full_name) . '</td>
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
                        The sum of: ' . amountToWords($cash_voucher_amount) . ' Only
                    </div>
                </div>
                
                <table class="signature-table">
                    <tr>
                        <td>
                            <div>' . htmlspecialchars($collector_name) . '</div>
                            <div class="voucher-signature-line"></div>
                            <div class="signature-label-voucher">Account Officer</div>
                        </td>
                        <td>
                            <div>' . htmlspecialchars($manager_name) . '</div>
                            <div class="voucher-signature-line"></div>
                            <div class="signature-label-voucher">Manager</div>
                        </td>
                        <td>
                            <div class="voucher-signature-line1"></div>
                            <div class="signature-label-voucher">Signature</div>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </body>
    </html>
    ';

    // Configure Dompdf for InfinityFree
    $options = new Options();
    $options->set('isRemoteEnabled', true); // IMPORTANT: Enable for InfinityFree
    $options->set('defaultFont', 'Arial');
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', true);
    $options->set('chroot', __DIR__); // Set root directory
    
    // Additional options for better InfinityFree compatibility
    $options->set('tempDir', sys_get_temp_dir());
    $options->set('fontDir', sys_get_temp_dir());
    $options->set('fontCache', sys_get_temp_dir());

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('Legal', 'portrait');
    $dompdf->render();

    // Output the generated PDF to browser
    $dompdf->stream("loan_documents_{$id_param}.pdf", [
        "Attachment" => false
    ]);

} catch (Exception $e) {
    // Log the error
    error_log("Error generating documents: " . $e->getMessage());
    
    // Show user-friendly error message
    die("Error: " . htmlspecialchars($e->getMessage()));
} finally {
    // Close database connection if it exists
    if (isset($conn)) {
        $conn->close();
    }
}
