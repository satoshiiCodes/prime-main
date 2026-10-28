<?php
/**
 * Prime City - Proxy loan record page.
 *
 * Layout only: data comes from actions/proxy-loan-actions.php, layout chrome
 * from includes/*, styles from assets/css and behaviour from assets/js.
 */

require_once __DIR__ . '/actions/proxy-loan-actions.php';

try {
    $context = pl_context();
} catch (Throwable $e) {
    error_log('[proxy-loan] ' . $e->getMessage());

    // A proxy account that does not exist is a bad link, not a server error.
    if ($e->getMessage() === 'Proxy not found') {
        cl_redirect('employee_lists.php');
        exit;
    }

    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
        <head>
            <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
            <meta charset="utf-8" />
            <title>Proxy Loan Record - Unavailable</title>
            <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        <link href="assets/css/dash.css" rel="stylesheet" />
        <link href="assets/css/dash-ui.css" rel="stylesheet" />
        </head>
        <body class="bg-light">
            <div class="container py-5">
                <div class="alert alert-danger">
                    <h4 class="alert-heading">The loan record is unavailable</h4>
                    <p class="mb-0">We could not load this proxy account's loans right now. Please try again in a moment.</p>
                </div>
            </div>
        </body>
    </html>
    <?php
    exit;
}

$proxy_id = $context['proxy_id'];
$proxy = $context['proxy'];
$proxy_loans = $context['proxy_loans'];
$completed_loans = $context['completed_loans'];
$payments = $context['payments'];
$summary = $context['summary'];
$is_admin = $context['is_admin'];

$user = $context['user'];

// Shared layout includes read these.
$layout_active = 'proxy-loan';
$layout_menu = $context['layout_menu'];

$pageConfig = array(
    'isAdmin' => $is_admin,
);

$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
        <meta charset="utf-8" />
        <meta http-equiv="X-UA-Compatible" content="IE=edge" />
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
        <meta name="description" content="Prime City proxy loan record" />
        <meta name="author" content="Prime City" />
        <title>Proxy Loan Record - Prime City</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" />
        <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
        <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        <link href="assets/css/dash.css" rel="stylesheet" />
        <link href="assets/css/dash-ui.css" rel="stylesheet" />
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet" />
        <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
        <!-- SweetAlert2 CSS -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
        <link href="assets/css/proxy-loan-record-style.css" rel="stylesheet" />
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
                                    <i class="fas fa-file-invoice-dollar" aria-hidden="true"></i>
                                    Prime City &middot; Employees
                                </p>
                                <h1 class="dash-title">Proxy Loan Record</h1>
                                <p class="dash-sub">Active and completed loans for this proxy account, with a live repayment summary.</p>
                            </div>

                            <div class="dash-head__actions">
                                <a class="btn btn-primary" href="employee_lists.php">
                                    <i class="fas fa-arrow-left me-1" aria-hidden="true"></i>
                                    Back to employees
                                </a>
                            </div>
                        </header>

                    <!-- Proxy Profile Section -->
                    <div class="pc-view__hero pc-view__hero--record">
                        <div class="pc-view__avatar">
                            <?php if (!empty($proxy['profile_image']) && file_exists(__DIR__ . '/' . $proxy['profile_image'])): ?>
                                <img src="<?php echo htmlspecialchars($proxy['profile_image']); ?>" alt="Profile Image">
                            <?php else: ?>
                                <i class="fas fa-user" aria-hidden="true"></i>
                            <?php endif; ?>
                        </div>

                        <div class="pc-view__identity">
                            <h2 class="pc-view__name">
                                <?php
                                    echo htmlspecialchars($proxy['last_name']) . ', ' . htmlspecialchars($proxy['first_name']);
                                    if (!empty($proxy['middle_name'])) {
                                        echo ' ' . htmlspecialchars($proxy['middle_name']);
                                    }
                                    if (!empty($proxy['suffix']) && $proxy['suffix'] !== 'None') {
                                        echo ', ' . htmlspecialchars($proxy['suffix']);
                                    }
                                ?>
                            </h2>

                            <p class="pc-view__meta">
                                Contact <?php echo htmlspecialchars($proxy['contact_number']); ?>
                                <?php if (!empty($proxy['area'])): ?>
                                    &middot; <?php echo htmlspecialchars($proxy['area']); ?>
                                <?php endif; ?>
                            </p>

                            <div class="pc-view__pills">
                                <span class="pc-view__pill pc-view__pill--dot pc-view__pill--<?php echo $proxy['account_status'] === 'Active' ? 'success' : 'danger'; ?>">
                                    <i class="fas fa-circle" aria-hidden="true"></i>
                                    <?php echo htmlspecialchars($proxy['account_status']); ?>
                                </span>
                                <span class="pc-view__pill pc-view__pill--brand"><i class="fas fa-hashtag" aria-hidden="true"></i> Proxy #<?php echo (int) $proxy['id']; ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="dash-stats">
                        <article class="stat stat--cyan">
                            <div class="stat__body">
                                <p class="stat__label">Paid (Active Loans)</p>
                                <p class="stat__value">&#8369;<?php echo number_format($summary['active_paid'], 2); ?></p>
                                <p class="stat__meta">Collected against running loans</p>
                            </div>
                        </article>

                        <article class="stat stat--navy">
                            <div class="stat__body">
                                <p class="stat__label">Balance (Active Loans)</p>
                                <p class="stat__value">&#8369;<?php echo number_format($summary['active_balance'], 2); ?></p>
                                <p class="stat__meta">Still to be collected</p>
                            </div>
                        </article>

                        <article class="stat stat--green">
                            <div class="stat__body">
                                <p class="stat__label">Total Loan Paid (Fully Paid)</p>
                                <p class="stat__value">&#8369;<?php echo number_format($summary['completed_total'], 2); ?></p>
                                <p class="stat__meta">Loans closed in full</p>
                            </div>
                        </article>

                        <article class="stat stat--cyan">
                            <div class="stat__body">
                                <p class="stat__label">Total Paid (All Loans)</p>
                                <p class="stat__value">&#8369;<?php echo number_format($summary['all_paid'], 2); ?></p>
                                <p class="stat__meta">Across every loan on record</p>
                            </div>
                        </article>
                    </div>

                    <div class="proxy-loans-section">
                    <div class="card dash-card mb-4">
                            <header class="dash-card__head">
                                <span class="dash-card__icon"><i class="fas fa-file-invoice-dollar" aria-hidden="true"></i></span>
                                <div>
                                    <h2 class="dash-card__title">Active Proxy Loans</h2>
                                    <p class="dash-card__hint">Loans still being collected</p>
                                </div>
                            </header>
                            <div class="card-body">
                                <?php if (count($proxy_loans) > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover dash-table">
                                            <thead>
                                                <tr>
                                                    <th>Loan ID</th>
                                                    <th>Start Date</th>
                                                    <th>Amount</th>
                                                    <th>Paid</th>
                                                    <th>Balance</th>
                                                    <th>Progress</th>
                                                    <th>Proxy</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($proxy_loans as $loan): 
                                                    // Calculate progress percentage
                                                    $paid_amount = $loan['amount_loaned'] - $loan['balance'];
                                                    $progress = $loan['amount_loaned'] > 0 ? ($paid_amount / $loan['amount_loaned']) * 100 : 0;
                                                    
                                                    // Format status with appropriate color
                                                    $status_class = '';
                                                    if ($loan['status'] === 'active') {
                                                        $status_class = 'status-active';
                                                    } elseif ($loan['status'] === 'completed') {
                                                        $status_class = 'status-completed';
                                                    } elseif ($loan['status'] === 'defaulted') {
                                                        $status_class = 'status-defaulted';
                                                    }
                                                    
                                                    // Format proxy name
                                                    $proxy_name = htmlspecialchars($proxy['last_name']) . ', ' . 
                                                                    htmlspecialchars($proxy['first_name']);
                                                    if (!empty($proxy['middle_name'])) {
                                                        $proxy_name .= ' ' . htmlspecialchars($proxy['middle_name']);
                                                    }
                                                    if (!empty($proxy['suffix']) && $proxy['suffix'] !== 'None') {
                                                        $proxy_name .= ', ' . htmlspecialchars($proxy['suffix']);
                                                    }
                                                    
                                                    // Get payments for this specific loan
                                                    $loan_payments = array_filter($payments, function($payment) use ($loan) {
                                                        return $payment['loan_id'] == $loan['id'];
                                                    });
                                                    
                                                    // Calculate actual term in days (loan_term * 15)
                                                    $actual_term_days = $loan['loan_term'] * 15;
                                                ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($loan['id']); ?></td>
                                                        <td><?php echo htmlspecialchars($loan['start_date']); ?></td>
                                                        <td class="fw-bold">₱<?php echo number_format($loan['amount_loaned'], 2); ?></td>
                                                        <td class="text-success">₱<?php echo number_format($paid_amount, 2); ?></td>
                                                        <td class="text-danger">₱<?php echo number_format($loan['balance'], 2); ?></td>
                                                        <td>
                                                            <div class="progress">
                                                                <div class="progress-bar bg-success" role="progressbar" 
                                                                     style="width: <?php echo $progress; ?>%" 
                                                                     aria-valuenow="<?php echo $progress; ?>" 
                                                                     aria-valuemin="0" aria-valuemax="100">
                                                                </div>
                                                            </div>
                                                            <small><?php echo number_format($progress, 1); ?>%</small>
                                                        </td>
                                                        <td><?php echo $proxy_name; ?></td>
                                                        <td class="<?php echo $status_class; ?>">
                                                            <i class="fas fa-circle-notch fa-spin me-1"></i><?php echo ucfirst(htmlspecialchars($loan['status'])); ?>
                                                        </td>
                                                        <td class="action-buttons">
                                                            <button class="btn btn-info btn-sm view-payments" 
                                                                    data-loan-id="<?php echo $loan['id']; ?>"
                                                                    data-loan-amount="<?php echo $loan['amount_loaned']; ?>"
                                                                    data-paid-amount="<?php echo $paid_amount; ?>"
                                                                    data-balance="<?php echo $loan['balance']; ?>"
                                                                    data-status="<?php echo $loan['status']; ?>"
                                                                    data-actual-term="<?php echo $actual_term_days; ?>"
                                                                    data-start-date="<?php echo $loan['start_date']; ?>"
                                                                    data-end-date="<?php echo $loan['end_date']; ?>"
                                                                    data-daily-payment="<?php echo $loan['daily_payment']; ?>"
                                                                    data-payments='<?php echo json_encode($loan_payments); ?>'>
                                                                <i class="fas fa-money-bill-wave"></i> View Payments
                                                            </button>
                                                            <?php if ($is_admin): ?>
                                                                <button class="btn btn-danger btn-sm delete-loan" 
                                                                        data-loan-id="<?php echo $loan['id']; ?>">
                                                                    <i class="fas fa-trash"></i> Delete
                                                                </button>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="no-payments text-center py-5">
                                        <div>
                                            <i class="fas fa-file-invoice-dollar fa-4x text-muted mb-3"></i>
                                            <h4>No Active Proxy Loans</h4>
                                            <p class="text-muted">This proxy doesn't have any active loans recorded yet.</p>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Completed/Closed Loans Section -->
                    <div class="proxy-loans-section">
                    <div class="card dash-card mb-4">
                            <header class="dash-card__head">
                                <span class="dash-card__icon"><i class="fas fa-circle-check" aria-hidden="true"></i></span>
                                <div>
                                    <h2 class="dash-card__title">Completed/Closed Loans</h2>
                                    <p class="dash-card__hint">Loans fully settled</p>
                                </div>
                            </header>
                            <div class="card-body">
                                <?php if (count($completed_loans) > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover dash-table">
                                            <thead>
                                                <tr>
                                                    <th>Loan ID</th>
                                                    <th>Start Date</th>
                                                    <th>End Date</th>
                                                    <th>Amount</th>
                                                    <th>Paid</th>
                                                    <th>Progress</th>
                                                    <th>Proxy</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($completed_loans as $loan): 
                                                    // Calculate progress percentage
                                                    $paid_amount = $loan['amount_loaned'] - $loan['balance'];
                                                    $progress = $loan['amount_loaned'] > 0 ? ($paid_amount / $loan['amount_loaned']) * 100 : 0;
                                                    
                                                    // Format status with appropriate color
                                                    $status_class = 'status-completed';
                                                    
                                                    // Format proxy name
                                                    $proxy_name = htmlspecialchars($proxy['last_name']) . ', ' . 
                                                                    htmlspecialchars($proxy['first_name']);
                                                    if (!empty($proxy['middle_name'])) {
                                                        $proxy_name .= ' ' . htmlspecialchars($proxy['middle_name']);
                                                    }
                                                    if (!empty($proxy['suffix']) && $proxy['suffix'] !== 'None') {
                                                        $proxy_name .= ', ' . htmlspecialchars($proxy['suffix']);
                                                    }
                                                    
                                                    // Get payments for this specific loan
                                                    $loan_payments = array_filter($payments, function($payment) use ($loan) {
                                                        return $payment['loan_id'] == $loan['id'];
                                                    });
                                                    
                                                    // Calculate actual term in days (loan_term * 15)
                                                    $actual_term_days = $loan['loan_term'] * 15;
                                                ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($loan['id']); ?></td>
                                                        <td><?php echo htmlspecialchars($loan['start_date']); ?></td>
                                                        <td><?php echo htmlspecialchars($loan['end_date']); ?></td>
                                                        <td class="fw-bold">₱<?php echo number_format($loan['amount_loaned'], 2); ?></td>
                                                        <td class="text-success">₱<?php echo number_format($paid_amount, 2); ?></td>
                                                        <td>
                                                            <div class="progress">
                                                                <div class="progress-bar bg-success" role="progressbar" 
                                                                     style="width: <?php echo $progress; ?>%" 
                                                                     aria-valuenow="<?php echo $progress; ?>" 
                                                                     aria-valuemin="0" aria-valuemax="100">
                                                                </div>
                                                            </div>
                                                            <small><?php echo number_format($progress, 1); ?>%</small>
                                                        </td>
                                                        <td><?php echo $proxy_name; ?></td>
                                                        <td class="<?php echo $status_class; ?>">
                                                            <i class="fas fa-check-circle me-1"></i><?php echo ucfirst(htmlspecialchars($loan['status'])); ?>
                                                        </td>
                                                        <td class="action-buttons">
                                                            <button class="btn btn-info btn-sm view-payments" 
                                                                    data-loan-id="<?php echo $loan['id']; ?>"
                                                                    data-loan-amount="<?php echo $loan['amount_loaned']; ?>"
                                                                    data-paid-amount="<?php echo $paid_amount; ?>"
                                                                    data-balance="<?php echo $loan['balance']; ?>"
                                                                    data-status="<?php echo $loan['status']; ?>"
                                                                    data-actual-term="<?php echo $actual_term_days; ?>"
                                                                    data-start-date="<?php echo $loan['start_date']; ?>"
                                                                    data-end-date="<?php echo $loan['end_date']; ?>"
                                                                    data-daily-payment="<?php echo $loan['daily_payment']; ?>"
                                                                    data-payments='<?php echo json_encode($loan_payments); ?>'>
                                                                <i class="fas fa-money-bill-wave"></i> View Payments
                                                            </button>
                                                            <?php if ($is_admin): ?>
                                                                <button class="btn btn-danger btn-sm delete-loan" 
                                                                        data-loan-id="<?php echo $loan['id']; ?>">
                                                                    <i class="fas fa-trash"></i> Delete
                                                                </button>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="no-payments text-center py-5">
                                        <div>
                                            <i class="fas fa-file-invoice-dollar fa-4x text-muted mb-3"></i>
                                            <h4>No Completed Loans</h4>
                                            <p class="text-muted">This proxy doesn't have any completed loans recorded yet.</p>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </main>

            <!-- View Payments Modal -->
            <div class="modal fade" id="viewPaymentsModal" tabindex="-1" aria-labelledby="viewPaymentsModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header bg-primary text-white">
                            <h5 class="modal-title" id="viewPaymentsModalLabel">Payment History</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row mb-4">
                                <div class="col-md-4">
                                    <div class="card bg-light">
                                        <div class="card-body text-center">
                                            <h6 class="card-title">Loan Amount</h6>
                                            <h4 class="text-primary" id="modal-loan-amount-summary">₱0.00</h4>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card bg-light">
                                        <div class="card-body text-center">
                                            <h6 class="card-title">Amount Paid</h6>
                                            <h4 class="text-success" id="modal-paid-amount">₱0.00</h4>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card bg-light">
                                        <div class="card-body text-center">
                                            <h6 class="card-title">Remaining Balance</h6>
                                            <h4 class="text-danger" id="modal-balance">₱0.00</h4>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <!-- Repayment Progress Section -->
                            <div class="card repayment-progress-card mb-4">
                                <div class="card-header bg-light">
                                    <h5 class="mb-0">Repayment Progress</h5>
                                </div>
                                <div class="card-body">
                                    <div class="d-flex justify-content-between mb-2">
                                        <span>Payment Completion</span>
                                        <span id="progress-percentage">0%</span>
                                    </div>
                                    <div class="progress progress-bar-container">
                                        <div class="progress-bar bg-success" id="repayment-progress-bar" role="progressbar" 
                                             style="width: 0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                    <div class="mt-2 d-flex justify-content-between">
                                        <small class="text-muted">₱0.00 Paid</small>
                                        <small class="text-muted">₱0.00 Total</small>
                                    </div>
                                </div>
                            </div>
                            <!-- Loan Details Section -->
                            <div class="loan-details-grid mb-4">
                                <div class="loan-detail-item">
                                    <span class="loan-detail-label">Status:</span>
                                    <span class="loan-detail-value" id="modal-status"></span>
                                </div>
                                <div class="loan-detail-item">
                                    <span class="loan-detail-label">Amount Loaned:</span>
                                    <span class="loan-detail-value" id="modal-loan-amount">₱0.00</span>
                                </div>
                                <div class="loan-detail-item">
                                    <span class="loan-detail-label">Term:</span>
                                    <span class="loan-detail-value" id="modal-term">0 days</span>
                                </div>
                                <div class="loan-detail-item">
                                    <span class="loan-detail-label">Start Date:</span>
                                    <span class="loan-detail-value" id="modal-start-date"></span>
                                </div>
                                <div class="loan-detail-item">
                                    <span class="loan-detail-label">Daily Payment:</span>
                                    <span class="loan-detail-value" id="modal-daily-payment">₱0.00</span>
                                </div>
                                <div class="loan-detail-item">
                                    <span class="loan-detail-label">End Date:</span>
                                    <span class="loan-detail-value" id="modal-end-date"></span>
                                </div>
                            </div>
                            
                            <h5 class="mb-3">Payment Records</h5>
                            <div class="payment-history">
                                <table class="table table-striped table-hover dash-table" id="paymentsTable">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Amount</th>
                                            <th>Received by</th>
                                            <th>Notes</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="paymentsTableBody">
                                        <!-- Payment data will be inserted here by JavaScript -->
                                    </tbody>
                                </table>
                                <div id="no-payments-message" class="text-center py-4 d-none">
                                    <i class="fas fa-receipt fa-3x text-muted mb-2"></i>
                                    <p class="text-muted">No payment records found for this loan.</p>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="button" class="btn btn-success" id="printLoanDetails">
                                <i class="fas fa-print"></i> Print Details
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- View Payment Details Modal -->
            <div class="modal fade" id="viewPaymentModal" tabindex="-1" aria-labelledby="viewPaymentModalLabel" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header bg-info text-white">
                            <h5 class="modal-title" id="viewPaymentModalLabel">Payment Details</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body" id="viewPaymentModalBody">
                            <!-- Payment details will be inserted here by JavaScript -->
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="button" class="btn btn-success" id="printPaymentReceipt">
                                <i class="fas fa-print"></i> Print Receipt
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Edit Payment Modal -->
            <div class="modal fade" id="editPaymentModal" tabindex="-1" aria-labelledby="editPaymentModalLabel" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header bg-warning text-dark">
                            <h5 class="modal-title" id="editPaymentModalLabel">Edit Payment</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form id="editPaymentForm">
                                <input type="hidden" id="edit_payment_id" name="payment_id">
                                <input type="hidden" id="edit_loan_id" name="loan_id">
                                <div class="mb-3">
                                    <label for="edit_payment_amount" class="form-label">Amount</label>
                                    <input type="text" class="form-control" id="edit_payment_amount" name="amount" required 
                                           oninput="formatCurrency(this)">
                                </div>
                            </form>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary" id="savePaymentChanges">Save Changes</button>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Delete Payment Confirmation Modal -->
            <div class="modal fade" id="deletePaymentModal" tabindex="-1" aria-labelledby="deletePaymentModalLabel" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header bg-danger text-white">
                            <h5 class="modal-title" id="deletePaymentModalLabel">Confirm Deletion</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p>Are you sure you want to delete this payment record? This action cannot be undone.</p>
                            <div class="alert alert-warning" role="alert">
                                <strong>Warning:</strong> Deleting a payment will affect the loan balance and repayment progress.
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-danger" id="confirmDeletePayment">Delete Payment</button>
                        </div>
                    </div>
                </div>
            </div>

                <?php include __DIR__ . '/includes/footer.php'; ?>
            </div>
        </div>

        <script type="application/json" id="proxy-loan-data"><?php echo json_encode($pageConfig, $jsonFlags); ?></script>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
        <script src="js/scripts.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/umd/simple-datatables.min.js" crossorigin="anonymous"></script>
        <!-- SweetAlert2 JS -->
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
        <script src="assets/js/proxy-loan-record.js"></script>
    </body>
</html>
