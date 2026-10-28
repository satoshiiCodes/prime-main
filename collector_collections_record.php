<?php
/**
 * Prime City - Collector collections record page.
 *
 * Layout only: data comes from actions/collector-collections-actions.php,
 * the payment details modal calls api/collector-collections-endpoint.php,
 * layout chrome comes from includes/*, styles from assets/css and behaviour
 * from assets/js.
 */

require_once __DIR__ . '/actions/collector-collections-actions.php';

try {
    $context = cc_context();
} catch (Throwable $e) {
    error_log('[collector-collections] ' . $e->getMessage());

    // A collector that does not exist is a bad link, not a server error.
    if ($e->getMessage() === 'Collector not found') {
        cc_redirect('employee_lists.php');
        exit;
    }

    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
        <head>
            <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
            <meta charset="utf-8" />
            <title>Collector Collections - Unavailable</title>
            <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        </head>
        <body class="bg-light">
            <div class="container py-5">
                <div class="alert alert-danger">
                    <h4 class="alert-heading">The collections record is unavailable</h4>
                    <p class="mb-0">We could not load this collector's collections right now. Please try again in a moment.</p>
                </div>
            </div>
        </body>
    </html>
    <?php
    exit;
}

$collector_id = $context['collector_id'];
$collector = $context['collector'];
$collector_full_name = $context['collector_full_name'];
$active_payments = $context['active_payments'];
$inactive_payments = $context['inactive_payments'];
$past_due_loans = $context['past_due_loans'];
$summary = $context['summary'];

$user = $context['user'];

// Shared layout includes read these.
$layout_active = 'collector-collections';
$layout_menu = $context['layout_menu'];
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
        <meta charset="utf-8" />
        <meta http-equiv="X-UA-Compatible" content="IE=edge" />
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
        <meta name="description" content="Prime City collector collections record" />
        <meta name="author" content="Prime City" />
        <title>Collector Collections Record - Prime City</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" />
        <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
        <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet" />
        <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
        <link href="assets/css/dash.css" rel="stylesheet" />
    <link href="assets/css/dash-ui.css" rel="stylesheet" />
    <link href="assets/css/collector-collections-style.css" rel="stylesheet" />
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
                            <p class="dash-eyebrow">Prime City &middot; Collections</p>
                            <h1 class="dash-title">Collector Collections Record</h1>
                            <p class="dash-sub">Payments, past-due loans and fully paid accounts for this collector.</p>
                        </div>
                        <div class="dash-head__actions">
                            <a href="employee_lists.php" class="btn btn-outline-primary">
                                <i class="fas fa-arrow-left me-1" aria-hidden="true"></i> Back to Employees
                            </a>
                        </div>
                    </header>
                    
                    <!-- Collector Profile Section -->
                    <section class="collector-hero" aria-label="Collector profile">
                        <div class="collector-hero__avatar">
                            <?php if (!empty($collector['profile_image'])): ?>
                                <img src="<?php echo htmlspecialchars($collector['profile_image']); ?>" class="collector-hero__photo" alt="Profile Image"
                                     onerror="this.outerHTML='<span class=&quot;collector-hero__icon&quot;><i class=&quot;fas fa-user&quot;></i></span>'">
                            <?php else: ?>
                                <span class="collector-hero__icon"><i class="fas fa-user"></i></span>
                            <?php endif; ?>
                            <span class="collector-hero__dot collector-hero__dot--<?php echo $collector['account_status'] === 'Active' ? 'active' : 'inactive'; ?>" aria-hidden="true"></span>
                        </div>

                        <div class="collector-hero__ident">
                            <p class="collector-hero__eyebrow">Collector</p>
                            <h2 class="collector-hero__name">
                                <?php
                                    echo htmlspecialchars($collector['last_name']) . ', ' . htmlspecialchars($collector['first_name']);
                                    if (!empty($collector['middle_name'])) {
                                        echo ' ' . htmlspecialchars($collector['middle_name']);
                                    }
                                    if (!empty($collector['suffix']) && $collector['suffix'] !== 'None') {
                                        echo ', ' . htmlspecialchars($collector['suffix']);
                                    }
                                ?>
                            </h2>

                            <div class="collector-hero__meta">
                                <span class="pc-chip">
                                    <i class="fas fa-hashtag" aria-hidden="true"></i>
                                    ID <?php echo htmlspecialchars($collector['id']); ?>
                                </span>
                                <span class="pc-chip">
                                    <i class="fas fa-location-dot" aria-hidden="true"></i>
                                    <?php echo htmlspecialchars($collector['area']); ?>
                                </span>
                                <span class="pc-chip">
                                    <i class="fas fa-phone" aria-hidden="true"></i>
                                    <?php echo htmlspecialchars($collector['contact_number']); ?>
                                </span>
                            </div>
                        </div>

                        <div class="collector-hero__status">
                            <span class="pc-pill <?php echo $collector['account_status'] === 'Active' ? 'pc-pill--success' : 'pc-pill--danger'; ?>">
                                <i class="fas fa-circle" aria-hidden="true"></i>
                                <?php echo htmlspecialchars($collector['account_status']); ?>
                            </span>
                        </div>
                    </section>
                    <section class="dash-stats" aria-label="Collection summary">
                        <article class="stat">
                            <div class="stat__body">
                                <p class="stat__label">Today's collection</p>
                                <p class="stat__value">&#8369;<?php echo number_format($summary['today_collected'], 2); ?></p>
                                <p class="stat__meta">Collected today</p>
                            </div>
                            <span class="stat__icon"><i class="fas fa-check-circle" aria-hidden="true"></i></span>
                        </article>
                        <article class="stat stat--cyan">
                            <div class="stat__body">
                                <p class="stat__label">This week</p>
                                <p class="stat__value">&#8369;<?php echo number_format($summary['this_week_collected'], 2); ?></p>
                                <p class="stat__meta">Collected this week</p>
                            </div>
                            <span class="stat__icon"><i class="fas fa-calendar-week" aria-hidden="true"></i></span>
                        </article>
                        <article class="stat stat--navy">
                            <div class="stat__body">
                                <p class="stat__label">This month</p>
                                <p class="stat__value">&#8369;<?php echo number_format($summary['this_month_collected'], 2); ?></p>
                                <p class="stat__meta">Collected this month</p>
                            </div>
                            <span class="stat__icon"><i class="fas fa-calendar-days" aria-hidden="true"></i></span>
                        </article>
                        <article class="stat stat--green">
                            <div class="stat__body">
                                <p class="stat__label">Total collected</p>
                                <p class="stat__value">&#8369;<?php echo number_format($summary['total_paid_active'] + $summary['total_paid_inactive'], 2); ?></p>
                                <p class="stat__meta">Active and closed loans</p>
                            </div>
                            <span class="stat__icon"><i class="fas fa-money-bill-wave" aria-hidden="true"></i></span>
                        </article>
                    </section>

                    <!-- Active Loans Payment History Section -->
                    <section class="dash-card">
                        <header class="dash-card__head">
                            <span class="dash-card__icon"><i class="fas fa-receipt" aria-hidden="true"></i></span>
                            <div>
                                <h2 class="dash-card__title">Payment History (Active Loans)</h2>
                                <p class="dash-card__hint">Every payment recorded against a running loan.</p>
                            </div>
                            <button class="btn btn-outline-primary btn-sm ms-auto" onclick="window.print()">
                                <i class="fas fa-print me-1" aria-hidden="true"></i> Print
                            </button>
                        </header>
                        <div class="dash-card__body">
                            <?php if (count($active_payments) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table dash-table" id="activePaymentsTable">
                                        <thead>
                                            <tr>
                                                <th>Payment Date</th>
                                                <th>Amount</th>
                                                <th>Method</th>
                                                <th>Notes</th>
                                                <th>Customer</th>
                                                <th>Loan ID</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($active_payments as $payment): 
                                                // Format customer name
                                                $customer_name = htmlspecialchars($payment['customer_last_name']) . ', ' . 
                                                htmlspecialchars($payment['customer_first_name']);
                                    
                                                // Add suffix if exists and not 'None'
                                                if (!empty($payment['customer_suffix']) && $payment['customer_suffix'] !== 'None') {
                                                    $customer_name .= ' ' . htmlspecialchars($payment['customer_suffix']);
                                                }
                                                
                                                // Add middle name if exists
                                                if (!empty($payment['customer_middle_name'])) {
                                                    $customer_name .= ' ' . htmlspecialchars($payment['customer_middle_name']);
                                                }
                                                
                                                // Format payment date
                                                $payment_date = new DateTime($payment['payment_date']);
                                                $formatted_date = $payment_date->format('F j, Y - g:i A');
                                            ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($formatted_date); ?></td>
                                                    <td class="fw-bold">₱<?php echo number_format($payment['amount'], 2); ?></td>
                                                    <td><?php echo htmlspecialchars($payment['payment_method'] ?? 'N/A'); ?></td>
                                                    <td class="notes-column" title="<?php echo htmlspecialchars($payment['notes'] ?? ''); ?>">
                                                        <?php echo htmlspecialchars($payment['notes'] ?? 'N/A'); ?>
                                                    </td>
                                                    <td><?php echo $customer_name; ?></td>
                                                    <td><?php echo htmlspecialchars($payment['loan_id'] ?? 'N/A'); ?></td>
                                                    <td class="status-paid">
                                                        <i class="fas fa-check-circle me-1"></i> Paid
                                                    </td>
                                                    <td>
                                                        <button class="btn btn-sm btn-outline-primary view-payment-btn" data-payment-id="<?php echo $payment['id']; ?>" data-collector-name="<?php echo htmlspecialchars($collector_full_name); ?>">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        <button class="btn btn-sm btn-light print-payment-btn" data-payment-id="<?php echo $payment['id']; ?>">
                                                            <i class="fas fa-print"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="no-payments text-center py-5">
                                    <div>
                                        <i class="fas fa-money-bill-wave fa-4x text-muted mb-3"></i>
                                        <h4>No Active Loan Payments Found</h4>
                                        <p class="text-muted">This collector doesn't have any recorded payments for active loans yet.</p>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>

                    <!-- Past Due Loans Section -->
                    <div class="past-due-section">
                        <section class="dash-card">
                            <header class="dash-card__head">
                                <span class="dash-card__icon"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i></span>
                                <div>
                                    <h2 class="dash-card__title">List of Customers Past Due Loans</h2>
                                    <p class="dash-card__hint">Loans past their end date that still carry a balance.</p>
                                </div>
                                <span class="pc-pill pc-pill--danger ms-auto">
                                    <?php echo $summary['count_past_due']; ?> Past Due
                                </span>
                            </header>
                            <div class="dash-card__body">
                                <?php if (count($past_due_loans) > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table dash-table">
                                            <thead>
                                                <tr>
                                                    <th>Customer</th>
                                                    <th>Contact</th>
                                                    <th>Loan ID</th>
                                                    <th>Balance</th>
                                                    <th>End Date</th>
                                                    <th>Days Overdue</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($past_due_loans as $loan): 
                                                    // Format customer name
                                                    $customer_name = htmlspecialchars($loan['customer_last_name']) . ', ' . 
                                                                   htmlspecialchars($loan['customer_first_name']);
                                                    if (!empty($loan['customer_middle_name'])) {
                                                        $customer_name .= ' ' . htmlspecialchars($loan['customer_middle_name']);
                                                    }
                                                    if (!empty($loan['customer_suffix']) && $loan['customer_suffix'] !== 'None') {
                                                        $customer_name .= ' ' . htmlspecialchars($loan['customer_suffix']);
                                                    }
                                                    
                                                    // Calculate days overdue
                                                    $end_date = new DateTime($loan['end_date']);
                                                    $today = new DateTime();
                                                    $days_overdue = $today->diff($end_date)->days;
                                                ?>
                                                    <tr>
                                                        <td><?php echo $customer_name; ?></td>
                                                        <td><?php echo htmlspecialchars($loan['customer_contact'] ?? 'N/A'); ?></td>
                                                        <td><?php echo htmlspecialchars($loan['id']); ?></td>
                                                        <td class="fw-bold">₱<?php echo number_format($loan['balance'], 2); ?></td>
                                                        <td><?php echo htmlspecialchars($loan['end_date']); ?></td>
                                                        <td class="days-overdue status-overdue">
                                                            <?php echo $days_overdue; ?> days
                                                        </td>
                                                        <td>
                                                            <a href="customer_view_payment.php?id=<?php echo $loan['customer_id']; ?>" class="btn btn-sm btn-success">
                                                                <i class="fas fa-money-bill-wave"></i> Record Payment
                                                            </a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="no-payments text-center py-5">
                                        <div>
                                            <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                                            <h4>No Past Due Loans Found</h4>
                                            <p class="text-muted">This collector doesn't have any past due loans.</p>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </section>
                    </div>

                    <!-- Inactive Loans Payment History Section -->
                    <div class="inactive-loans-section">
                        <section class="dash-card">
                            <header class="dash-card__head">
                                <span class="dash-card__icon"><i class="fas fa-circle-check" aria-hidden="true"></i></span>
                                <div>
                                    <h2 class="dash-card__title">Payment History (Fully Paid Loans)</h2>
                                    <p class="dash-card__hint">Payments from loans this collector already closed.</p>
                                </div>
                            </header>
                            <div class="dash-card__body">
                                <?php if (count($inactive_payments) > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table dash-table">
                                            <thead>
                                                <tr>
                                                    <th>Payment Date</th>
                                                    <th>Amount</th>
                                                    <th>Method</th>
                                                    <th>Notes</th>
                                                    <th>Customer</th>
                                                    <th>Loan ID</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($inactive_payments as $payment): 
                                                    // Format customer name
                                                    $customer_name = htmlspecialchars($payment['customer_last_name']) . ', ' . 
                                                                   htmlspecialchars($payment['customer_first_name']);
                                                    if (!empty($payment['customer_middle_name'])) {
                                                        $customer_name .= ' ' . htmlspecialchars($payment['customer_middle_name']);
                                                    }
                                                    if (!empty($payment['customer_suffix']) && $payment['customer_suffix'] !== 'None') {
                                                        $customer_name .= ', ' . htmlspecialchars($payment['customer_suffix']);
                                                    }
                                                ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($payment['payment_date']); ?></td>
                                                        <td class="fw-bold">₱<?php echo number_format($payment['amount'], 2); ?></td>
                                                        <td><?php echo htmlspecialchars($payment['payment_method'] ?? 'N/A'); ?></td>
                                                        <td class="notes-column" title="<?php echo htmlspecialchars($payment['notes'] ?? ''); ?>">
                                                            <?php echo htmlspecialchars($payment['notes'] ?? 'N/A'); ?>
                                                        </td>
                                                        <td><?php echo $customer_name; ?></td>
                                                        <td><?php echo htmlspecialchars($payment['loan_id'] ?? 'N/A'); ?></td>
                                                        <td class="status-paid">
                                                            <i class="fas fa-check-circle me-1"></i> Paid
                                                        </td>
                                                        <td>
                                                            <button class="btn btn-sm btn-outline-primary view-payment-btn" data-payment-id="<?php echo $payment['id']; ?>" data-collector-name="<?php echo htmlspecialchars($collector_full_name); ?>">
                                                                <i class="fas fa-eye"></i> View
                                                            </button>
                                                            <button class="btn btn-sm btn-light print-payment-btn" data-payment-id="<?php echo $payment['id']; ?>">
                                                                <i class="fas fa-print"></i> Print
                                                            </button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="no-payments text-center py-5">
                                        <div>
                                            <i class="fas fa-money-bill-wave fa-4x text-muted mb-3"></i>
                                            <h4>No Inactive Loan Payments Found</h4>
                                            <p class="text-muted">This collector doesn't have any recorded payments for inactive loans.</p>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </section>
                    </div>
                </div>
            </main>

                <?php include __DIR__ . '/includes/footer.php'; ?>
            </div>
        </div>

        <!-- View Payment Modal -->
        <div class="modal fade" id="viewPaymentModal" tabindex="-1" aria-labelledby="viewPaymentModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="viewPaymentModalLabel">Payment Details</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" id="paymentDetailsContent">
                        <!-- Payment details will be loaded here -->
                        <div class="text-center py-4">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p class="mt-2">Loading payment details...</p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="button" class="btn btn-primary" id="printPaymentBtn">
                            <i class="fas fa-print"></i> Print Receipt
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
        <script src="js/scripts.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/umd/simple-datatables.min.js" crossorigin="anonymous"></script>
        <script src="assets/js/collector-collections.js"></script>
    </body>
</html>
