<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['logged_in'])) {
    header("Location: login.php");
    exit();
}

// Validate input
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: customer_list.php");
    exit();
}

$payment_id = $_GET['id'];

// Database configuration
require 'includes/db_config.php';

try {
    // Create connection
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get payment details with customer and collector info
    $stmt = $conn->prepare("
        SELECT 
            p.*,
            c.first_name AS customer_first_name,
            c.last_name AS customer_last_name,
            c.middle_name AS customer_middle_name,
            c.address AS customer_address,
            c.contact_number AS customer_contact,
            col.first_name AS collector_first_name,
            col.last_name AS collector_last_name
        FROM payments p
        LEFT JOIN customer_accounts c ON p.customer_id = c.id
        LEFT JOIN collector_accounts col ON p.collector_id = col.id
        WHERE p.id = :id
    ");
    $stmt->bindParam(':id', $payment_id);
    $stmt->execute();
    
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$payment) {
        throw new Exception("Payment not found");
    }
    
    // Format names
    $customer_name = $payment['customer_last_name'] . ', ' . $payment['customer_first_name'];
    if (!empty($payment['customer_middle_name'])) {
        $customer_name .= ' ' . $payment['customer_middle_name'];
    }
    
    $collector_name = '';
    if (!empty($payment['collector_first_name']) || !empty($payment['collector_last_name'])) {
        $collector_name = $payment['collector_first_name'] . ' ' . $payment['collector_last_name'];
    }
    
} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
} catch(Exception $e) {
    die("Error: " . $e->getMessage());
}

// Get current user (for printed by)
try {
    $user_conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $user_conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $user_conn->prepare("SELECT * FROM accounts WHERE id = :id");
    $stmt->bindParam(':id', $_SESSION['user_id']);
    $stmt->execute();
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
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
    <title>Payment Receipt - Prime City</title>
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
                <p class="receipt-subtitle">Official Payment Receipt</p>
            </div>
            
            <div class="row mb-4">
                <div class="col-md-6">
                    <p><strong>Receipt #:</strong> <?php echo str_pad($payment['id'], 6, '0', STR_PAD_LEFT); ?></p>
                    <p><strong>Date:</strong> <?php echo date('F j, Y h:i A', strtotime($payment['payment_date'])); ?></p>
                </div>
                <div class="col-md-6 text-end">
                    <p><strong>Status:</strong> <span class="badge bg-success">Paid</span></p>
                </div>
            </div>
            
            <div class="receipt-details">
                <h5>Customer Information</h5>
                <p><strong>Name:</strong> <?php echo htmlspecialchars($customer_name); ?></p>
                <p><strong>Address:</strong> <?php echo htmlspecialchars($payment['customer_address']); ?></p>
                <p><strong>Contact:</strong> <?php echo htmlspecialchars($payment['customer_contact']); ?></p>
            </div>
            
            <div class="receipt-details">
                <h5>Payment Details</h5>
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Loan Payment</td>
                            <td class="amount-paid">₱<?php echo number_format($payment['amount'], 2); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Payment Method:</strong></td>
                            <td><?php echo htmlspecialchars($payment['payment_method']); ?></td>
                        </tr>
                        <?php if (!empty($collector_name)): ?>
                        <tr>
                            <td><strong>Collected By:</strong></td>
                            <td><?php echo htmlspecialchars($collector_name); ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <?php if (!empty($payment['notes'])): ?>
                <div class="mt-3">
                    <p><strong>Notes:</strong></p>
                    <p><?php echo htmlspecialchars($payment['notes']); ?></p>
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
                <p>Thank you for your payment!</p>
                <p>Printed by: <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?> on <?php echo date('F j, Y h:i A'); ?></p>
            </div>
        </div>
        
        <div class="text-center no-print mt-3 mb-5">
            <button class="btn btn-primary" onclick="window.print()">
                <i class="fas fa-print"></i> Print Receipt
            </button>
            <a href="customer_view_payment.php?id=<?php echo $payment['customer_id']; ?>" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left"></i> Back to Customer
            </a>
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
