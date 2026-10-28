<?php
session_start();

// Redirect to login if not authenticated
if (!isset($_SESSION['logged_in'])) {
    header("Location: login_form.php");
    exit;
}

// Check if payment ID is provided
if (!isset($_GET['payment_id']) || !is_numeric($_GET['payment_id'])) {
    die("Invalid payment ID");
}

$payment_id = $_GET['payment_id'];

// Database configuration
require_once 'includes/db_config1.php';

try {
    $conn = getDBConnection();
    
    // Get payment details
    $stmt = $conn->prepare("
        SELECT cp.*, 
               cl.amount_loaned, cl.balance, cl.loan_term, cl.daily_payment,
               ca.first_name AS collector_first_name, 
               ca.middle_name AS collector_middle_name, 
               ca.last_name AS collector_last_name,
               ca.suffix AS collector_suffix,
               a.first_name AS recorded_first_name,
               a.last_name AS recorded_last_name
        FROM collector_payments cp
        JOIN collector_loans cl ON cp.loan_id = cl.id
        JOIN collector_accounts ca ON cl.collector_id = ca.id
        LEFT JOIN accounts a ON cp.created_by = a.id
        WHERE cp.id = ?
    ");
    $stmt->execute([$payment_id]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$payment) {
        die("Payment not found");
    }
    
    // Format collector name
    $collector_name = $payment['collector_last_name'] . ', ' . $payment['collector_first_name'];
    if (!empty($payment['collector_middle_name'])) {
        $collector_name .= ' ' . $payment['collector_middle_name'];
    }
    if (!empty($payment['collector_suffix']) && $payment['collector_suffix'] !== 'None') {
        $collector_name .= ', ' . $payment['collector_suffix'];
    }
    
    // Format recorded by name
    $recorded_by = $payment['recorded_first_name'] && $payment['recorded_last_name'] 
        ? $payment['recorded_first_name'] . ' ' . $payment['recorded_last_name'] 
        : 'Unknown';
    
} catch (Exception $e) {
    die("Error retrieving payment details: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Receipt - Prime City</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            color: #333;
        }
        .receipt-container {
            max-width: 800px;
            margin: 0 auto;
            border: 1px solid #ddd;
            padding: 20px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #4e73df;
            padding-bottom: 10px;
        }
        .company-name {
            font-size: 24px;
            font-weight: bold;
            color: #4e73df;
        }
        .receipt-title {
            font-size: 20px;
            margin-top: 10px;
        }
        .details-section {
            margin-bottom: 20px;
        }
        .detail-row {
            display: flex;
            margin-bottom: 8px;
        }
        .detail-label {
            font-weight: bold;
            width: 150px;
        }
        .detail-value {
            flex: 1;
        }
        .amount-highlight {
            font-size: 18px;
            font-weight: bold;
            color: #28a745;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            border-top: 1px solid #ddd;
            padding-top: 10px;
            font-size: 14px;
            color: #666;
        }
        .signature-area {
            margin-top: 50px;
            display: flex;
            justify-content: space-between;
        }
        .signature-line {
            border-top: 1px solid #333;
            width: 200px;
            text-align: center;
            padding-top: 5px;
        }
        @media print {
            body {
                padding: 0;
            }
            .receipt-container {
                border: none;
                box-shadow: none;
            }
            .no-print {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <div class="header">
            <div class="company-name">PRIMECITY FINANCE SERVICES</div>
            <div class="receipt-title">PAYMENT RECEIPT</div>
        </div>
        
        <div class="details-section">
            <div class="detail-row">
                <div class="detail-label">Receipt Number:</div>
                <div class="detail-value">#<?php echo htmlspecialchars($payment['id']); ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Date:</div>
                <div class="detail-value"><?php echo date('F j, Y, g:i a', strtotime($payment['payment_date'])); ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Loan ID:</div>
                <div class="detail-value">#<?php echo htmlspecialchars($payment['loan_id']); ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Collector:</div>
                <div class="detail-value"><?php echo htmlspecialchars($collector_name); ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Amount Paid:</div>
                <div class="detail-value amount-highlight">₱<?php echo number_format($payment['amount'], 2); ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Payment Method:</div>
                <div class="detail-value">Cash</div>
            </div>
            <?php if (!empty($payment['notes'])): ?>
            <div class="detail-row">
                <div class="detail-label">Notes:</div>
                <div class="detail-value"><?php echo htmlspecialchars($payment['notes']); ?></div>
            </div>
            <?php endif; ?>
            <div class="detail-row">
                <div class="detail-label">Received By:</div>
                <div class="detail-value"><?php echo htmlspecialchars($recorded_by); ?></div>
            </div>
        </div>
        
        <div class="signature-area">
            <div></div>
            <div class="signature-line">Borrower's Signature</div>
        </div>
        
        <div class="footer">
            <p>Thank you for your payment!</p>
            <p>PRIMECITY FINANCE SERVICES</p>
            <p>Generated on: <?php echo date('F j, Y'); ?></p>
        </div>
    </div>
    
    <div class="no-print" style="text-align: center; margin-top: 20px;">
        <button onclick="window.print()" style="padding: 10px 20px; background-color: #4e73df; color: white; border: none; border-radius: 4px; cursor: pointer;">
            Print Receipt
        </button>
        <button onclick="window.close()" style="padding: 10px 20px; background-color: #6c757d; color: white; border: none; border-radius: 4px; cursor: pointer; margin-left: 10px;">
            Close Window
        </button>
    </div>
</body>
</html>
