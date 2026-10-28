<?php
session_start();

// Set Manila timezone
date_default_timezone_set('Asia/Manila');

// Redirect to login if not authenticated
if (!isset($_SESSION['logged_in'])) {
    header("Location: login_form.php");
    exit;
}

// Check if loan ID is provided
if (!isset($_GET['loan_id']) || !is_numeric($_GET['loan_id'])) {
    die("Invalid loan ID");
}

$loan_id = $_GET['loan_id'];

// Database configuration
require_once 'includes/db_config1.php';

try {
    $conn = getDBConnection();
    
    // Get loan details
    $stmt = $conn->prepare("
        SELECT cl.*, 
               c.first_name, c.middle_name, c.last_name, c.suffix,
               c.contact_number, c.area, c.profile_image, c.account_type
        FROM collector_loans cl
        JOIN collector_accounts c ON cl.collector_id = c.id
        WHERE cl.id = ?
    ");
    $stmt->execute([$loan_id]);
    $loan = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$loan) {
        throw new Exception("Loan not found");
    }
    
    // Get payments for this loan
    $stmt = $conn->prepare("
        SELECT cp.*, a.first_name as created_first_name, a.last_name as created_last_name
        FROM collector_payments cp
        LEFT JOIN accounts a ON cp.created_by = a.id
        WHERE cp.loan_id = ?
        ORDER BY cp.payment_date DESC
    ");
    $stmt->execute([$loan_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate totals
    $paid_amount = $loan['amount_loaned'] - $loan['balance'];
    $progress = $loan['amount_loaned'] > 0 ? ($paid_amount / $loan['amount_loaned']) * 100 : 0;
    
    // Calculate term display (loan_term * 15)
    $calculated_term = $loan['loan_term'] * 15;
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Loan Details - Prime City</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            color: #333;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
        }
        .company-name {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .report-title {
            font-size: 18px;
        }
        .loan-info {
            margin-bottom: 20px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-bottom: 15px;
        }
        .info-item {
            margin-bottom: 5px;
        }
        .info-label {
            font-weight: bold;
        }
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        .summary-card {
            border: 1px solid #ddd;
            padding: 15px;
            text-align: center;
            border-radius: 5px;
        }
        .summary-card h3 {
            margin: 0 0 10px 0;
            font-size: 16px;
        }
        .summary-card .amount {
            font-size: 18px;
            font-weight: bold;
        }
        .progress-bar {
            height: 10px;
            background-color: #e9ecef;
            border-radius: 5px;
            margin-top: 5px;
        }
        .progress-fill {
            height: 100%;
            background-color: #198754;
            border-radius: 5px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f8f9fa;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .text-success {
            color: #198754;
        }
        .no-payments {
            text-align: center;
            padding: 20px;
            font-style: italic;
            color: #6c757d;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 12px;
            color: #6c757d;
        }
        @media print {
            body {
                padding: 0;
            }
            .no-print {
                display: none;
            }
            .header {
                margin-bottom: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="company-name">Prime City</div>
        <div class="report-title">Collector Loan Details</div>
        <div>Generated on: <?php echo date('F j, Y g:i A'); ?></div>
    </div>

    <div class="loan-info">
        <h2>Loan Information</h2>
        <div class="info-grid">
            <div class="info-item">
                <span class="info-label">Loan ID:</span> #<?php echo htmlspecialchars($loan['id']); ?>
            </div>
            <div class="info-item">
                <span class="info-label">Status:</span> <?php echo ucfirst(htmlspecialchars($loan['status'])); ?>
            </div>
            <div class="info-item">
                <span class="info-label">Collector:</span> 
                <?php 
                    echo htmlspecialchars($loan['last_name']) . ', ' . htmlspecialchars($loan['first_name']);
                    if (!empty($loan['middle_name'])) {
                        echo ' ' . htmlspecialchars($loan['middle_name']);
                    }
                    if (!empty($loan['suffix']) && $loan['suffix'] !== 'None') {
                        echo ', ' . htmlspecialchars($loan['suffix']);
                    }
                ?>
            </div>
            <div class="info-item">
                <span class="info-label">Account Type:</span> <?php echo ucfirst(htmlspecialchars($loan['account_type'])); ?>
            </div>
            <div class="info-item">
                <span class="info-label">Contact:</span> <?php echo htmlspecialchars($loan['contact_number']); ?>
            </div>
            <div class="info-item">
                <span class="info-label">Area:</span> <?php echo htmlspecialchars($loan['area']); ?>
            </div>
            <div class="info-item">
                <span class="info-label">Start Date:</span> <?php echo htmlspecialchars($loan['start_date']); ?>
            </div>
            <div class="info-item">
                <span class="info-label">Term:</span> <?php echo $calculated_term; ?> days
            </div>
            <div class="info-item">
                <span class="info-label">End Date:</span> <?php echo htmlspecialchars($loan['end_date']); ?>
            </div>
            <div class="info-item">
                <span class="info-label">15/30 Payment:</span> ₱<?php echo number_format($loan['daily_payment'], 2); ?>
            </div>
        </div>
    </div>

    <div class="summary-cards">
        <div class="summary-card">
            <h3>Loan Amount</h3>
            <div class="amount">₱<?php echo number_format($loan['amount_loaned'], 2); ?></div>
        </div>
        <div class="summary-card">
            <h3>Amount Paid</h3>
            <div class="amount text-success">₱<?php echo number_format($paid_amount, 2); ?></div>
        </div>
        <div class="summary-card">
            <h3>Remaining Balance</h3>
            <div class="amount">₱<?php echo number_format($loan['balance'], 2); ?></div>
        </div>
    </div>

    <div class="progress-section">
        <h3>Repayment Progress: <?php echo number_format($progress, 1); ?>%</h3>
        <div class="progress-bar">
            <div class="progress-fill" style="width: <?php echo $progress; ?>%"></div>
        </div>
        <div class="text-center">
            <small>₱<?php echo number_format($paid_amount, 2); ?> Paid of ₱<?php echo number_format($loan['amount_loaned'], 2); ?> Total</small>
        </div>
    </div>

    <div class="payments-section">
        <h2>Payment History</h2>
        <?php if (count($payments) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Received By</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $payment): ?>
                        <tr>
                            <td><?php echo date('M j, Y g:i A', strtotime($payment['payment_date'])); ?></td>
                            <td class="text-right">₱<?php echo number_format($payment['amount'], 2); ?></td>
                            <td>
                                <?php 
                                    if ($payment['created_first_name'] && $payment['created_last_name']) {
                                        echo htmlspecialchars($payment['created_first_name'] . ' ' . $payment['created_last_name']);
                                    } else {
                                        echo 'Unknown';
                                    }
                                ?>
                            </td>
                            <td><?php echo htmlspecialchars($payment['notes'] ?: 'No notes'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="no-payments">No payment records found for this loan.</div>
        <?php endif; ?>
    </div>

    <div class="footer">
        <p>Prime City - Employee Loan History</p>
        <p>This is a computer-generated report. No signature is required.</p>
    </div>

    <div class="no-print" style="margin-top: 20px; text-align: center;">
        <button onclick="window.print()" style="padding: 10px 20px; background-color: #4e73df; color: white; border: none; border-radius: 5px; cursor: pointer;">
            Print This Report
        </button>
        <button onclick="window.close()" style="padding: 10px 20px; background-color: #6c757d; color: white; border: none; border-radius: 5px; cursor: pointer; margin-left: 10px;">
            Close Window
        </button>
    </div>

    <script>
        // Auto-print when the page loads
        window.onload = function() {
            window.print();
        };
    </script>
</body>
</html>
