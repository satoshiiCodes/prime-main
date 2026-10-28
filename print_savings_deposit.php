<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['logged_in'])) {
    header("Location: login.php");
    exit();
}

// Check if deposit ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid deposit ID");
}

$deposit_id = intval($_GET['id']);

// Database configuration
require 'includes/db_config.php';

try {
    // Create connection
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get savings deposit details
    $stmt = $conn->prepare("
        SELECT 
            st.*,
            sa.account_number,
            sa.current_balance,
            c.first_name AS customer_first_name,
            c.middle_name AS customer_middle_name,
            c.last_name AS customer_last_name,
            c.suffix AS customer_suffix,
            c.address AS customer_address,
            c.contact_number AS customer_contact,
            col.first_name AS collector_first_name,
            col.last_name AS collector_last_name,
            col.contact_number AS collector_contact
        FROM savings_transactions st
        LEFT JOIN savings_accounts sa ON st.savings_account_id = sa.id
        LEFT JOIN customer_accounts c ON sa.customer_id = c.id
        LEFT JOIN collector_accounts col ON st.collector_id = col.id
        WHERE st.id = :id
    ");
    $stmt->bindParam(':id', $deposit_id, PDO::PARAM_INT);
    $stmt->execute();
    $deposit = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$deposit) {
        throw new Exception("Savings deposit not found");
    }
    
    // Format customer name
    $customer_name = $deposit['customer_last_name'] . ', ' . $deposit['customer_first_name'];
    if (!empty($deposit['customer_middle_name'])) {
        $customer_name .= ' ' . $deposit['customer_middle_name'];
    }
    if (!empty($deposit['customer_suffix']) && $deposit['customer_suffix'] !== 'None') {
        $customer_name .= ', ' . $deposit['customer_suffix'];
    }
    
    // Format collector name
    $collector_name = '';
    if (!empty($deposit['collector_first_name']) || !empty($deposit['collector_last_name'])) {
        $collector_name = ($deposit['collector_first_name'] ?? '') . ' ' . ($deposit['collector_last_name'] ?? '');
    }
    
    // Get current user (for printed by)
    $user_stmt = $conn->prepare("SELECT * FROM accounts WHERE id = :id");
    $user_stmt->bindParam(':id', $_SESSION['user_id']);
    $user_stmt->execute();
    $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        throw new Exception("User not found");
    }
    
} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
} catch(Exception $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Savings Deposit Receipt - Prime City</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @media print {
            body {
                padding: 0;
                margin: 0;
                font-size: 12pt;
            }
            .no-print {
                display: none !important;
            }
            .container {
                width: 100%;
                max-width: 100%;
                padding: 0;
            }
            .receipt-container {
                border: none !important;
                box-shadow: none !important;
            }
        }
        body {
            background-color: #f8f9fa;
        }
        .receipt-container {
            max-width: 800px;
            margin: 20px auto;
            padding: 30px;
            background-color: white;
            border: 1px solid #ddd;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .receipt-header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 15px;
        }
        .receipt-title {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .receipt-subtitle {
            font-size: 16px;
            color: #666;
        }
        .receipt-logo {
            max-width: 120px;
            margin-bottom: 15px;
        }
        .receipt-details {
            margin-bottom: 30px;
        }
        .receipt-details h5 {
            border-bottom: 1px solid #ddd;
            padding-bottom: 5px;
            margin-bottom: 15px;
        }
        .receipt-footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 1px solid #ddd;
            text-align: center;
            font-size: 12px;
            color: #666;
        }
        .signature-line {
            border-top: 1px solid #333;
            width: 200px;
            margin: 40px auto 10px;
        }
        .signature-label {
            text-align: center;
            font-size: 14px;
        }
        .amount-paid {
            font-size: 20px;
            font-weight: bold;
            color: #198754;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="receipt-container">
            <div class="receipt-header">
                <h1 class="receipt-title">PRIMECITY FINANCE SERVICES</h1>
                <p class="receipt-subtitle">Savings Deposit Receipt</p>
            </div>
            
            <div class="row mb-4">
                <div class="col-md-6">
                    <p><strong>Receipt #:</strong> <?php echo 'SD-' . str_pad($deposit['id'], 6, '0', STR_PAD_LEFT); ?></p>
                    <p><strong>Date:</strong> <?php echo date('F j, Y', strtotime($deposit['transaction_date'])); ?></p>
                    <p><strong>Time:</strong> <?php echo date('h:i A', strtotime($deposit['transaction_date'])); ?></p>
                </div>
                <div class="col-md-6 text-end">
                    <p><strong>Status:</strong> <span class="badge bg-success">Completed</span></p>
                </div>
            </div>
            
            <div class="receipt-details">
                <h5>Customer Information</h5>
                <p><strong>Name:</strong> <?php echo htmlspecialchars($customer_name); ?></p>
                <p><strong>Account #:</strong> <?php echo htmlspecialchars($deposit['account_number'] ?? 'N/A'); ?></p>
                <p><strong>Address:</strong> <?php echo htmlspecialchars($deposit['customer_address']); ?></p>
                <p><strong>Contact:</strong> <?php echo htmlspecialchars($deposit['customer_contact']); ?></p>
            </div>
            
            <div class="receipt-details">
                <h5>Transaction Details</h5>
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Savings Deposit</td>
                            <td class="amount-paid">₱<?php echo number_format($deposit['amount'], 2); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Transaction Type:</strong></td>
                            <td><?php echo htmlspecialchars($deposit['transaction_type']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Payment Method:</strong></td>
                            <td><?php echo htmlspecialchars($deposit['payment_method'] ?? 'N/A'); ?></td>
                        </tr>
                        <?php if (!empty($collector_name)): ?>
                        <tr>
                            <td><strong>Collected By:</strong></td>
                            <td><?php echo htmlspecialchars($collector_name); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td><strong>Previous Balance:</strong></td>
                            <td>₱<?php echo number_format(($deposit['saving_balance_after_payment'] - $deposit['amount']), 2); ?></td>
                        </tr>
                        <tr>
                            <td><strong>New Balance:</strong></td>
                            <td class="amount-paid">₱<?php echo number_format($deposit['saving_balance_after_payment'], 2); ?></td>
                        </tr>
                    </tbody>
                </table>
                
                <?php if (!empty($deposit['notes'])): ?>
                <div class="mt-3">
                    <p><strong>Notes:</strong></p>
                    <p><?php echo htmlspecialchars($deposit['notes']); ?></p>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="row mt-4">
                <div class="col-md-6">
                    <div class="signature-line"></div>
                    <p class="signature-label">Customer Signature</p>
                </div>
                <div class="col-md-6">
                    <div class="signature-line"></div>
                    <p class="signature-label">Authorized Signature</p>
                </div>
            </div>
            
            <div class="receipt-footer">
                <p>Thank you for your deposit!</p>
                <p>Printed by: <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?> on <?php echo date('F j, Y h:i A'); ?></p>
            </div>
        </div>
        
        <div class="text-center no-print mt-3 mb-5">
            <button class="btn btn-primary" onclick="window.print()">
                <i class="fas fa-print"></i> Print Receipt
            </button>
            <button onclick="window.close()" class="btn btn-outline-secondary">
                <i class="fas fa-times"></i> Close Window
            </button>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js"></script>
    <script>
        // Auto-print when page loads (optional)
        window.addEventListener('load', function() {
            if (window.location.search.includes('autoprint')) {
                window.print();
            }
        });
    </script>
</body>
</html>
