<?php
session_start();

// Redirect to login if not authenticated
if (!isset($_SESSION['logged_in'])) {
    header("Location: login_form.php");
    exit;
}

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database configuration
require 'includes/db_config.php';

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Function to get full name from a table
function getFullName($conn, $table, $id) {
    $sql = "SELECT first_name, middle_name, last_name, suffix FROM $table WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return "Error preparing statement";
    }
    
    $stmt->bind_param("i", $id);
    if (!$stmt->execute()) {
        return "Error executing query";
    }
    
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        
        // Build name parts array
        $nameParts = [];
        
        if (!empty($row['first_name'])) $nameParts[] = $row['first_name'];
        if (!empty($row['middle_name'])) $nameParts[] = $row['middle_name'];
        if (!empty($row['last_name'])) $nameParts[] = $row['last_name'];
        if (!empty($row['suffix']) && strtolower($row['suffix']) !== 'none') {
            $nameParts[] = $row['suffix'];
        }
        
        return trim(implode(' ', $nameParts)) ?: "No Name";
    }
    return "Unknown";
}

// Process payment submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_payment'])) {
    $response = ['success' => false, 'message' => 'Unknown error occurred'];
    
    try {
        // Validate required fields
        $required = [
            'loan_id' => 'Loan ID',
            'payment_amount' => 'Payment Amount',
            'payment_date' => 'Payment Date'
        ];
        
        $missing = [];
        foreach ($required as $field => $name) {
            if (empty($_POST[$field])) {
                $missing[] = $name;
            }
        }
        
        if (!empty($missing)) {
            throw new Exception("Missing required fields: " . implode(', ', $missing));
        }
        
        // Get and validate input
        $loan_id = intval($_POST['loan_id']);
        $payment_amount = floatval(str_replace(',', '', $_POST['payment_amount']));
        $payment_date = date('Y-m-d', strtotime($_POST['payment_date']));
        $notes = trim($_POST['notes'] ?? '');
        
        if (!$payment_date) {
            throw new Exception("Invalid payment date format");
        }
        
        if ($loan_id <= 0) {
            throw new Exception("Invalid loan selected");
        }
        
        if ($payment_amount <= 0) {
            throw new Exception("Payment amount must be greater than 0");
        }
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // 1. Insert payment record
            $payment_sql = "INSERT INTO collector_payments (
                loan_id, 
                payment_amount, 
                payment_date, 
                notes, 
                created_at
            ) VALUES (?, ?, ?, ?, NOW())";
            
            $payment_stmt = $conn->prepare($payment_sql);
            if (!$payment_stmt) {
                throw new Exception("Prepare failed for payment: " . $conn->error);
            }
            
            $payment_stmt->bind_param("idss", $loan_id, $payment_amount, $payment_date, $notes);
            
            if (!$payment_stmt->execute()) {
                throw new Exception("Execute failed for payment: " . $payment_stmt->error);
            }
            
            $payment_id = $payment_stmt->insert_id;
            $payment_stmt->close();
            
            // 2. Update loan balance
            $update_sql = "UPDATE collector_loans 
                          SET balance = balance - ?,
                              updated_at = NOW()
                          WHERE id = ?";
            
            $update_stmt = $conn->prepare($update_sql);
            if (!$update_stmt) {
                throw new Exception("Prepare failed for loan update: " . $conn->error);
            }
            
            $update_stmt->bind_param("di", $payment_amount, $loan_id);
            
            if (!$update_stmt->execute()) {
                throw new Exception("Execute failed for loan update: " . $update_stmt->error);
            }
            
            $rows_affected = $update_stmt->affected_rows;
            $update_stmt->close();
            
            // 3. Check if loan is fully paid
            $check_sql = "SELECT balance FROM collector_loans WHERE id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("i", $loan_id);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            $loan = $result->fetch_assoc();
            $check_stmt->close();
            
            if ($loan['balance'] <= 0) {
                // Mark loan as paid
                $paid_sql = "UPDATE collector_loans 
                            SET status = 'Paid',
                                updated_at = NOW()
                            WHERE id = ?";
                
                $paid_stmt = $conn->prepare($paid_sql);
                $paid_stmt->bind_param("i", $loan_id);
                $paid_stmt->execute();
                $paid_stmt->close();
            }
            
            // Commit transaction
            $conn->commit();
            
            $response = [
                'success' => true,
                'message' => 'Payment recorded successfully!',
                'payment_id' => $payment_id
            ];
            
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
        
    } catch (Exception $e) {
        $response = [
            'success' => false,
            'message' => $e->getMessage(),
            'error_details' => $conn->error ?? null
        ];
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Fetch active collector loans
$active_loans = [];
$loan_result = $conn->query("
    SELECT cl.id, cl.collector_id, cl.collector_name, cl.amount_loaned, 
           cl.daily_payment, cl.balance, cl.loan_term, cl.start_date, cl.end_date
    FROM collector_loans cl
    WHERE cl.status = 'Active'
    ORDER BY cl.collector_name, cl.start_date
");

if ($loan_result) {
    while ($row = $loan_result->fetch_assoc()) {
        $active_loans[] = $row;
    }
}

// Fetch payment history for a specific loan if requested
$payment_history = [];
$selected_loan_id = isset($_GET['loan_id']) ? intval($_GET['loan_id']) : 0;

if ($selected_loan_id > 0) {
    $history_stmt = $conn->prepare("
        SELECT id, payment_amount, payment_date, notes, created_at
        FROM collector_payments
        WHERE loan_id = ?
        ORDER BY payment_date DESC, created_at DESC
    ");
    $history_stmt->bind_param("i", $selected_loan_id);
    $history_stmt->execute();
    $history_result = $history_stmt->get_result();
    
    while ($row = $history_result->fetch_assoc()) {
        $payment_history[] = $row;
    }
    
    $history_stmt->close();
}

// Close connection
$conn->close();

// Shared layout chrome (includes/topbar.php + includes/sidebar.php) reads these.
if (!isset($user) || !is_array($user) || $user === array()) {
    $user = array(
        'account_type' => $_SESSION['account_type'] ?? '',
        'first_name'   => $_SESSION['first_name'] ?? '',
        'middle_name'  => $_SESSION['middle_name'] ?? '',
        'last_name'    => $_SESSION['last_name'] ?? '',
        'suffix'       => $_SESSION['suffix'] ?? '',
    );
}
$layout_active = 'collector-payments';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <meta name="description" content="" />
    <meta name="author" content="" />
    <title>Collector Payments - Prime City Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
    <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
    <link href="assets/css/dash.css" rel="stylesheet" />
    <link href="assets/css/dash-ui.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
    <style>
        .payment-container {
            max-width: 1200px;
            margin: 0 auto;
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .section-title {
            border-bottom: 1px solid #dee2e6;
            padding-bottom: 0.5rem;
            margin-bottom: 1.5rem;
            color: #0d6efd;
        }
        .loan-card {
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
            background-color: #f8f9fa;
        }
        .loan-card h5 {
            color: #0d6efd;
        }
        .loan-details {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 10px;
        }
        .loan-detail {
            flex: 1;
            min-width: 200px;
        }
        .loan-detail label {
            font-weight: bold;
            color: #495057;
        }
        .loan-detail span {
            display: block;
            padding: 5px;
            background-color: #fff;
            border-radius: 4px;
        }
        .payment-form {
            margin-top: 20px;
            padding: 20px;
            background-color: #f8f9fa;
            border-radius: 5px;
        }
        .payment-history {
            margin-top: 30px;
        }
        .table-responsive {
            overflow-x: auto;
        }
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 0.8rem;
        }
        .status-active {
            background-color: #d1e7dd;
            color: #0f5132;
        }
        .status-paid {
            background-color: #cfe2ff;
            color: #084298;
        }
        .status-defaulted {
            background-color: #f8d7da;
            color: #842029;
        }
        /* Toast Notification Styles */
        .toast {
            border: none;
            box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15);
            border-radius: 0.5rem;
            overflow: hidden;
        }
        .toast-header {
            padding: 0.75rem 1rem;
            border-bottom: none;
        }
        .toast-body {
            padding: 1rem;
            font-size: 1rem;
        }
        .toast-body i {
            font-size: 1.25rem;
        }
        .success-toast .toast-header {
            background-color: #28a745;
            color: white;
        }
        .success-toast .toast-body {
            background-color: #f8f9fa;
        }
    </style>
</head>
<body class="sb-nav-fixed">
    <?php include __DIR__ . '/includes/topbar.php'; ?>

    <div id="layoutSidenav">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <div id="layoutSidenav_content">
            <main>
            <div class="dash">
                <header class="dash-head">
                    <div>
                        <p class="dash-eyebrow">
                            <i class="fas fa-hand-holding-dollar" aria-hidden="true"></i>
                            Prime City &middot; Finance
                        </p>
                        <h1 class="dash-title">Collector Payments</h1>
                        <p class="dash-sub">Record repayments against active collector loans and review the payment history of each one.</p>
                    </div>

                    <div class="dash-head__actions">
                        <a class="btn btn-primary" href="admin.php">
                            <i class="fas fa-arrow-left me-1" aria-hidden="true"></i>
                            Back to dashboard
                        </a>
                    </div>
                </header>

                <div class="card dash-card mb-4">
                    <header class="dash-card__head">
                        <span class="dash-card__icon"><i class="fas fa-file-invoice-dollar" aria-hidden="true"></i></span>
                        <div>
                            <h2 class="dash-card__title">Active Collector Loans</h2>
                            <p class="dash-card__hint">Every loan that is still being collected</p>
                        </div>
                    </header>
                    <div class="card-body">

                    <?php if (empty($active_loans)): ?>
                        <div class="alert alert-info">No active collector loans found.</div>
                    <?php else: ?>
                        <div class="row">
                            <?php foreach ($active_loans as $loan): ?>
                                <div class="col-md-6">
                                    <div class="loan-card">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <h5><?= htmlspecialchars($loan['collector_name']) ?></h5>
                                            <span class="status-badge status-active">Active</span>
                                        </div>
                                        
                                        <div class="loan-details">
                                            <div class="loan-detail">
                                                <label>Loan ID</label>
                                                <span><?= $loan['id'] ?></span>
                                            </div>
                                            <div class="loan-detail">
                                                <label>Amount Loaned</label>
                                                <span>₱<?= number_format($loan['amount_loaned'], 2) ?></span>
                                            </div>
                                            <div class="loan-detail">
                                                <label>Daily Payment</label>
                                                <span>₱<?= number_format($loan['daily_payment'], 2) ?></span>
                                            </div>
                                            <div class="loan-detail">
                                                <label>Remaining Balance</label>
                                                <span>₱<?= number_format($loan['balance'], 2) ?></span>
                                            </div>
                                            <div class="loan-detail">
                                                <label>Term</label>
                                                <span><?= $loan['loan_term'] ?> days</span>
                                            </div>
                                            <div class="loan-detail">
                                                <label>Start Date</label>
                                                <span><?= date('M d, Y', strtotime($loan['start_date'])) ?></span>
                                            </div>
                                            <div class="loan-detail">
                                                <label>End Date</label>
                                                <span><?= date('M d, Y', strtotime($loan['end_date'])) ?></span>
                                            </div>
                                        </div>
                                        
                                        <div class="d-flex justify-content-end">
                                            <a href="collector_payments.php?loan_id=<?= $loan['id'] ?>" class="btn btn-primary btn-sm">
                                                <i class="fas fa-money-bill-wave me-1"></i> Record Payment
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    </div>
                </div>
                    
                    <?php if ($selected_loan_id > 0): ?>
                        <?php 
                            // Find the selected loan
                            $selected_loan = null;
                            foreach ($active_loans as $loan) {
                                if ($loan['id'] == $selected_loan_id) {
                                    $selected_loan = $loan;
                                    break;
                                }
                            }
                        ?>
                        
                        <?php if ($selected_loan): ?>
                            <div class="card dash-card mb-4">
                                <header class="dash-card__head">
                                    <span class="dash-card__icon"><i class="fas fa-money-bill-wave" aria-hidden="true"></i></span>
                                    <div>
                                        <h2 class="dash-card__title">Record Payment for <?= htmlspecialchars($selected_loan['collector_name']) ?></h2>
                                        <p class="dash-card__hint">Loan #<?= $selected_loan['id'] ?> &middot; remaining balance ₱<?= number_format($selected_loan['balance'], 2) ?></p>
                                    </div>
                                </header>
                                <div class="card-body">
                                <form id="paymentForm" method="post">
                                    <input type="hidden" name="loan_id" value="<?= $selected_loan['id'] ?>">
                                    
                                    <div class="row mb-3">
                                        <div class="col-md-4">
                                            <label for="payment_amount" class="form-label">Payment Amount</label>
                                            <div class="input-group">
                                                <span class="input-group-text">₱</span>
                                                <input type="text" class="form-control" id="payment_amount" name="payment_amount" required>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="payment_date" class="form-label">Payment Date</label>
                                            <input type="date" class="form-control" id="payment_date" name="payment_date" 
                                                   value="<?= date('Y-m-d') ?>" required>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="notes" class="form-label">Notes (Optional)</label>
                                            <input type="text" class="form-control" id="notes" name="notes">
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-12">
                                            <button type="submit" name="submit_payment" class="btn btn-primary">
                                                <i class="fas fa-save me-1"></i> Record Payment
                                            </button>
                                            <a href="collector_payments.php" class="btn btn-secondary">
                                                <i class="fas fa-times me-1"></i> Cancel
                                            </a>
                                        </div>
                                    </div>
                                    
                                    <div id="form-error" class="text-danger mt-2"></div>
                                </form>
                                </div>
                            </div>
                            
                            <div class="card dash-card mb-4">
                                <header class="dash-card__head">
                                    <span class="dash-card__icon"><i class="fas fa-receipt" aria-hidden="true"></i></span>
                                    <div>
                                        <h2 class="dash-card__title">Payment History</h2>
                                        <p class="dash-card__hint">Repayments already recorded for this loan</p>
                                    </div>
                                </header>
                                <div class="card-body">
                                <?php if (empty($payment_history)): ?>
                                    <div class="alert alert-info">No payment history found for this loan.</div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-striped table-hover dash-table">
                                            <thead>
                                                <tr>
                                                    <th>Payment Date</th>
                                                    <th>Amount</th>
                                                    <th>Notes</th>
                                                    <th>Recorded On</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($payment_history as $payment): ?>
                                                    <tr>
                                                        <td><?= date('M d, Y', strtotime($payment['payment_date'])) ?></td>
                                                        <td>₱<?= number_format($payment['payment_amount'], 2) ?></td>
                                                        <td><?= htmlspecialchars($payment['notes'] ?? '') ?></td>
                                                        <td><?= date('M d, Y h:i A', strtotime($payment['created_at'])) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-danger">Selected loan not found or is not active.</div>
                        <?php endif; ?>
                    <?php endif; ?>
            </div>
        </main>
        <?php include __DIR__ . '/includes/footer.php'; ?>
    </div>
    </div>

    <!-- Beautiful Toast Notification -->
    <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 11">
        <div id="successToast" class="toast success-toast" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="toast-header">
                <strong class="me-auto">Success</strong>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
            <div class="toast-body">
                <i class="fas fa-check-circle text-success me-2"></i>
                <span id="toastMessage">Payment recorded successfully!</span>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script src="js/scripts.js"></script>
    <script>
    // Function to format number with commas
    function formatNumberWithCommas(number) {
        return number.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    }

    // Function to parse number with commas
    function parseNumberWithCommas(numberString) {
        return parseFloat(numberString.replace(/,/g, ''));
    }

    // Format payment amount on blur
    $('#payment_amount').on('blur', function() {
        const value = parseNumberWithCommas($(this).val());
        if (!isNaN(value)) {
            $(this).val(formatNumberWithCommas(value.toFixed(2)));
        }
    });

    // Form submission handler
    async function handleFormSubmit(e) {
        e.preventDefault();
        const form = document.getElementById('paymentForm');
        const errorElement = document.getElementById('form-error');
        errorElement.textContent = '';
        
        try {
            // Validate form
            const paymentAmount = parseNumberWithCommas($('#payment_amount').val());
            const paymentDate = $('#payment_date').val();
            
            if (isNaN(paymentAmount) || paymentAmount <= 0) {
                throw new Error('Please enter a valid payment amount');
            }
            
            if (!paymentDate) {
                throw new Error('Please select a valid payment date');
            }
            
            const formData = new FormData(form);
            formData.set('payment_amount', paymentAmount); // Ensure we send the parsed number
            
            const response = await fetch('collector_payments.php', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                // Show beautiful toast notification
                const toastMessage = document.getElementById('toastMessage');
                toastMessage.innerHTML = `${data.message}<br>Payment ID: <strong>${data.payment_id}</strong>`;
                
                const toast = new bootstrap.Toast(document.getElementById('successToast'));
                toast.show();
                
                // Reload the page to show updated data
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            } else {
                throw new Error(data.message || 'Error submitting payment');
            }
        } catch (error) {
            console.error('Error:', error);
            errorElement.textContent = error.message;
        }
    }

    // Initialize the form when page loads
    $(document).ready(function() {
        // Set up form submission
        document.getElementById('paymentForm')?.addEventListener('submit', handleFormSubmit);
    });
    </script>
</body>
</html>
