<?php
/**
 * Prime City - Customer loan history / payment view page.
 *
 * Layout only: data comes from actions/customer-view-payment-actions.php,
 * layout chrome from includes/* (the customer portal keeps customer_menu.php),
 * styles from assets/css and behaviour from assets/js.
 */

require_once __DIR__ . '/actions/customer-view-payment-actions.php';

try {
    $context = cvp_context();
} catch (Throwable $e) {
    error_log('[customer-view-payment] ' . $e->getMessage());
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
        <head>
            <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
            <meta charset="utf-8" />
            <title>Customer Loan History - Unavailable</title>
            <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        </head>
        <body class="bg-light">
            <div class="container py-5">
                <div class="alert alert-danger">
                    <h4 class="alert-heading">The customer record is unavailable</h4>
                    <p class="mb-0">We could not load this customer's loans right now. Please try again in a moment.</p>
                </div>
            </div>
        </body>
    </html>
    <?php
    exit;
}

$customer_id = $context['customer_id'];
$customer = $context['customer'];
$active_loans = $context['active_loans'];
$inactive_loans = $context['inactive_loans'];
$summary = $context['summary'];
$total_savings = $context['total_savings'];
$payment_calendar = $context['payment_calendar'];

$user = $context['user'];
$is_admin = $context['is_admin'];
$is_area_manager = $context['is_area_manager'];
$is_collector = $context['is_collector'];
$is_proxy = $context['is_proxy'];
$is_customer = $context['is_customer'];

// Shared layout includes read these.
$layout_active = 'customer-history';
$layout_menu = $context['layout_menu'];
$is_customer_portal = $layout_menu === 'customer';

$pageConfig = array(
    'canManagePayments' => !$is_proxy && !$is_collector && !$is_customer,
    'canEditDelete'     => $is_admin || $is_area_manager,
    'currentUserId'     => (int) ($_SESSION['user_id'] ?? 1),
    'hasActiveLoans'    => count($active_loans) > 0,
    'paymentCalendar'   => $payment_calendar,
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
        <meta name="description" content="Prime City customer loan history" />
        <meta name="author" content="Prime City" />
        <title>Customer Loan History - Prime City</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" />
        <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
        <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet" />
        <link href="assets/css/dash.css" rel="stylesheet" />
    <link href="assets/css/customer-view-payment-style.css" rel="stylesheet" />
        <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
        <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    </head>
    <body class="sb-nav-fixed<?php echo $is_customer_portal ? ' customer-portal' : ''; ?>">
        <?php if ($is_customer_portal): ?>
        <?php include __DIR__ . '/customer_menu.php'; ?>

        <div id="layoutSidenav_content">
        <?php else: ?>
        <?php include __DIR__ . '/includes/topbar.php'; ?>

        <div id="layoutSidenav">
            <?php include __DIR__ . '/includes/sidebar.php'; ?>

            <div id="layoutSidenav_content">
        <?php endif; ?>
            <main>
                <div class="dash">
                    <header class="dash-head">
                        <div>
                            <p class="dash-eyebrow">
                                <i class="fas fa-file-invoice-dollar" aria-hidden="true"></i>
                                Prime City &middot; Customers
                            </p>
                            <h1 class="dash-title">Customer Loan History</h1>
                            <p class="dash-sub">Loans, payments, penalties and savings for this customer.</p>
                        </div>

                        <div class="dash-head__actions">
                            <?php if ($is_admin || $is_area_manager || $is_collector): ?>
                            <a href="customer_list.php" class="btn btn-primary">
                                <i class="fas fa-arrow-left me-1" aria-hidden="true"></i>
                                Back to Customers
                            </a>
                            <?php else: ?>
                            <a href="javascript:history.back()" class="btn btn-primary">
                                <i class="fas fa-arrow-left me-1" aria-hidden="true"></i>
                                Back
                            </a>
                            <?php endif; ?>
                        </div>
                    </header>
                    
                    <!-- Compact Modern Customer Profile Section -->
                    <section class="dash-card modern-profile-card">
                        <div class="dash-card__body p-3 p-md-4">
                            <div class="row g-3 g-md-4 align-items-center">
                                <div class="col-md-3 col-lg-2 text-center">
                                    <?php if (!empty($customer['profile_image'])): ?>
                                        <div class="position-relative mb-2 mb-md-3 d-inline-block">
                                            <img src="<?php echo htmlspecialchars($customer['profile_image']); ?>" 
                                                class="compact-profile-img rounded-circle shadow" 
                                                alt="<?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']); ?>"
                                                onerror="this.style.display='none'; var f = document.getElementById('profileImageFallback'); if (f) { f.classList.remove('d-none'); }">
                                            <span class="status-badge-sm bg-<?php echo $customer['status'] === 'Active' ? 'success' : ($customer['status'] === 'Inactive' ? 'danger' : 'warning'); ?> border border-white"></span>
                                        </div>
                                    <?php endif; ?>

                                    <div id="profileImageFallback" class="position-relative mb-2 mb-md-3 d-inline-block <?php echo !empty($customer['profile_image']) ? 'd-none' : ''; ?>">
                                        <div class="compact-profile-img rounded-circle bg-light d-flex align-items-center justify-content-center shadow">
                                            <i class="fas fa-user fa-2x text-secondary"></i>
                                        </div>
                                        <span class="status-badge-sm bg-<?php echo $customer['status'] === 'Active' ? 'success' : ($customer['status'] === 'Inactive' ? 'danger' : 'warning'); ?> border border-white"></span>
                                    </div>

                                    <div class="mt-2">
                                        <span class="badge rounded-pill bg-<?php echo $customer['status'] === 'Active' ? 'success' : ($customer['status'] === 'Inactive' ? 'danger' : 'warning'); ?>">
                                            <?php echo htmlspecialchars($customer['status']); ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="col-md-9 col-lg-10">
                                    <div class="row g-2 g-md-3">
                                        <div class="col-12 mb-2">
                                            <h4 class="fw-bold mb-1">
                                                <?php 
                                                    echo htmlspecialchars($customer['first_name']);
                                                    if (!empty($customer['middle_name'])) {
                                                        echo ' ' . htmlspecialchars($customer['middle_name']);
                                                    }
                                                    echo ' ' . htmlspecialchars($customer['last_name']);
                                                    if (!empty($customer['suffix']) && $customer['suffix'] !== 'None') {
                                                        echo htmlspecialchars($customer['suffix']);
                                                    }
                                                ?>
                                            </h4>
                                            <p class="text-muted mb-2">
                                                <i class="fas fa-calendar-alt me-1"></i>
                                                Registered: <?php echo htmlspecialchars($customer['registration_date']); ?>
                                            </p>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div class="info-row mb-2">
                                                <span class="info-label">
                                                    <i class="fas fa-phone text-primary me-2"></i>Contact:
                                                </span>
                                                <span class="info-value fw-semibold">
                                                    <?php echo htmlspecialchars($customer['contact_number']); ?>
                                                </span>
                                            </div>
                                            <div class="info-row">
                                                <span class="info-label">
                                                    <i class="fas fa-map-marker-alt text-primary me-2"></i>Address:
                                                </span>
                                                <span class="info-value">
                                                    <?php echo htmlspecialchars($customer['address']); ?>
                                                </span>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div class="info-row mb-2">
                                                <span class="info-label">
                                                    <i class="fas fa-map-marked-alt text-primary me-2"></i>Area:
                                                </span>
                                                <span class="info-value fw-semibold">
                                                    <?php echo htmlspecialchars($customer['area'] ?? 'N/A'); ?>
                                                </span>
                                            </div>
                                            <div class="info-row">
                                                <span class="info-label">
                                                    <i class="fas fa-code-branch text-primary me-2"></i>Branch:
                                                </span>
                                                <span class="info-value fw-semibold">
                                                    <?php echo htmlspecialchars($customer['branch'] ?? 'N/A'); ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- Loan Summary Section -->
                    <section class="dash-stats" aria-label="Loan summary">
                        <article class="stat">
                            <div class="stat__body">
                                <p class="stat__label">Amount Financed</p>
                                <p class="stat__value">&#8369;<?php echo number_format($summary['total_active_amount_financed'], 2); ?></p>
                                <p class="stat__meta"><i class="fas fa-chart-line me-1" aria-hidden="true"></i>Active loans</p>
                            </div>
                            <span class="stat__icon"><i class="fas fa-money-bill-wave" aria-hidden="true"></i></span>
                        </article>

                        <article class="stat stat--cyan">
                            <div class="stat__body">
                                <p class="stat__label">Interest</p>
                                <p class="stat__value">&#8369;<?php echo number_format($summary['total_active_interest_peso'], 2); ?></p>
                                <p class="stat__meta">On active loans</p>
                            </div>
                            <span class="stat__icon"><i class="fas fa-percent" aria-hidden="true"></i></span>
                        </article>

                        <article class="stat stat--green">
                            <div class="stat__body">
                                <p class="stat__label">Total Paid</p>
                                <p class="stat__value">&#8369;<?php echo number_format($summary['total_active_paid'], 2); ?></p>
                                <p class="stat__meta">Active loans</p>
                            </div>
                            <span class="stat__icon"><i class="fas fa-circle-check" aria-hidden="true"></i></span>
                        </article>

                        <article class="stat stat--navy">
                            <div class="stat__body">
                                <p class="stat__label">Outstanding Balance</p>
                                <p class="stat__value">&#8369;<?php echo number_format($summary['total_active_balance'], 2); ?></p>
                                <p class="stat__meta">Still to collect</p>
                            </div>
                            <span class="stat__icon"><i class="fas fa-scale-balanced" aria-hidden="true"></i></span>
                        </article>

                        <article class="stat stat--green">
                            <div class="stat__body">
                                <p class="stat__label">Savings</p>
                                <p class="stat__value">&#8369;<?php echo number_format($summary['total_savings'], 2); ?></p>
                                <p class="stat__meta">Current balance</p>
                            </div>
                            <span class="stat__icon"><i class="fas fa-piggy-bank" aria-hidden="true"></i></span>
                        </article>

                        <article class="stat stat--cyan">
                            <div class="stat__body">
                                <p class="stat__label">Active Penalty</p>
                                <p class="stat__value">&#8369;<?php echo number_format($summary['total_active_penalty'], 2); ?></p>
                                <p class="stat__meta">Unpaid</p>
                            </div>
                            <span class="stat__icon"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i></span>
                        </article>

                        <article class="stat stat--navy">
                            <div class="stat__body">
                                <p class="stat__label">All Penalties</p>
                                <p class="stat__value">&#8369;<?php echo number_format($summary['total_all_penalties'], 2); ?></p>
                                <p class="stat__meta">Lifetime total</p>
                            </div>
                            <span class="stat__icon"><i class="fas fa-receipt" aria-hidden="true"></i></span>
                        </article>
                    </section>
                    <!-- Payment Calendar Section -->
                    <?php if (count($active_loans) > 0): ?>
                    <section class="dash-card">
                        <header class="dash-card__head">
                            <span class="dash-card__icon"><i class="fas fa-calendar-days" aria-hidden="true"></i></span>
                            <div>
                                <h2 class="dash-card__title">Payment Calendar</h2>
                                <p class="dash-card__hint">Due dates, underpayments and advance payments</p>
                            </div>
                        </header>
                        <div class="dash-card__body">
                            <div class="calendar-loan-selector mb-4" hidden>
                                <label for="loan-select" class="form-label">Select Loan:</label>
                                <select class="form-select" id="loan-select">
                                    <?php foreach ($active_loans as $loan): 
                                        $total_amount = $loan['amount_financed'] + $loan['interest_peso'] + $loan['penalty'];
                                        $paid_amount = $loan['total_paid'] ?? 0;
                                        $remaining_balance = $loan['remaining_balance'] ?? ($total_amount - $paid_amount);
                                    ?>
                                        <option value="<?php echo $loan['id']; ?>">
                                            Loan #<?php echo $loan['id']; ?> - 
                                            ₱<?php echo number_format($total_amount, 2); ?> - 
                                            <?php echo date('Y-m-d', strtotime($loan['start_date'])); ?> to <?php echo $loan['end_date']; ?> - 
                                            Balance: ₱<?php echo number_format($remaining_balance, 2); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="underpayment-summary pc-cal-summary">
                                <div class="pc-cal-chip pc-cal-chip--under">
                                    <span class="pc-cal-chip__label">Total underpayment</span>
                                    <span class="underpayment-amount" id="total-underpayment">₱0.00</span>
                                </div>
                                <div class="pc-cal-chip pc-cal-chip--advance">
                                    <span class="pc-cal-chip__label">Advance payment</span>
                                    <span class="advance-payment-amount" id="total-advance-payment">₱0.00</span>
                                </div>
                            </div>

                            <div class="payment-calendar">
                                <div class="calendar-header">
                                    <div class="calendar-title" id="calendar-title">July 2025</div>
                                    <div class="calendar-nav">
                                        <button class="btn btn-sm btn-outline-secondary" id="prev-month" aria-label="Previous month">
                                            <i class="fas fa-chevron-left" aria-hidden="true"></i>
                                        </button>
                                        <button class="btn btn-sm btn-primary" id="current-month">
                                            Today
                                        </button>
                                        <button class="btn btn-sm btn-outline-secondary" id="next-month" aria-label="Next month">
                                            <i class="fas fa-chevron-right" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="calendar-grid">
                                    <div class="calendar-day-header calendar-day-sunday">Sun</div>
                                    <div class="calendar-day-header">Mon</div>
                                    <div class="calendar-day-header">Tue</div>
                                    <div class="calendar-day-header">Wed</div>
                                    <div class="calendar-day-header">Thu</div>
                                    <div class="calendar-day-header">Fri</div>
                                    <div class="calendar-day-header">Sat</div>
                                </div>
                                
                                <div class="calendar-grid" id="calendar-days">
                                    <!-- Calendar days will be populated by JavaScript -->
                                </div>
                            </div>
                        </div>
                    </section>
                    <?php endif; ?>
                    
                    <!-- Active Loans Section -->
                    <section class="dash-card">
                        <header class="dash-card__head">
                            <span class="dash-card__icon"><i class="fas fa-hand-holding-dollar" aria-hidden="true"></i></span>
                            <div>
                                <h2 class="dash-card__title">Active Loans</h2>
                                <p class="dash-card__hint">Running loans with their payment progress</p>
                            </div>
                            <?php if (!$is_proxy && !$is_collector && !$is_customer): ?>
                            <button class="btn btn-outline-primary btn-sm ms-auto" onclick="window.print()">
                                <i class="fas fa-print me-1" aria-hidden="true"></i> Print
                            </button>
                            <?php endif; ?>
                        </header>
                        <div class="dash-card__body">
                            <?php if (count($active_loans) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Loan ID</th>
                                                <th>Start Date</th>
                                                <th>Amount</th>
                                                <th>Savings Balance</th>
                                                <th>Savings Withdrawals</th>
                                                <th>Penalty</th>
                                                <th>Paid</th>
                                                <th>Balance</th>
                                                <th>Progress</th>
                                                <th>Collector</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($active_loans as $loan): 
                                                $total_amount = $loan['amount_financed'] + $loan['interest_peso'] + $loan['penalty'];
                                                
                                                $collector_name = 'N/A';
                                                if (!empty($loan['collector_first_name']) || !empty($loan['collector_last_name'])) {
                                                    $collector_name = htmlspecialchars(($loan['collector_first_name'] ?? '') . ' ' . ($loan['collector_last_name'] ?? ''));
                                                }
                                                
                                                $paid_amount = floatval($loan['total_paid'] ?? 0);
                                                $progress = ($paid_amount / $total_amount) * 100;
                                                $remaining_balance = floatval($loan['remaining_balance'] ?? $total_amount);
                                                
                                                $today = new DateTime();
                                                $endDate = new DateTime($loan['end_date']);
                                                $isPastDue = $endDate < $today;
                                                
                                                $automatic_penalty_enabled = isset($loan['automatic_penalty_enabled']) ? (bool)$loan['automatic_penalty_enabled'] : true;
                                                
                                                $savings_balance = floatval($loan['savings_balance'] ?? 0);
                                                $total_withdrawals = floatval($loan['total_withdrawals'] ?? 0);
                                            ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($loan['id']); ?></td>
                                                    <td><?php echo date('m-d-Y', strtotime($loan['start_date'])); ?></td>
                                                    <td class="pc-table__amount" data-financed="<?php echo number_format($loan['amount_financed'], 2, '.', ''); ?>">
                                                        <span class="pc-table__value">&#8369;<?php echo number_format($total_amount, 2); ?></span>
                                                        <small class="pc-table__sub">Financed &#8369;<?php echo number_format($loan['amount_financed'], 2); ?></small>
                                                    </td>
                                                    <td class="text-info fw-bold">₱<?php echo number_format($savings_balance, 2); ?></td>
                                                    <td class="text-danger fw-bold">₱<?php echo number_format($total_withdrawals, 2); ?></td>
                                                    <td class="text-danger fw-bold">₱<?php echo number_format($loan['penalty'], 2); ?></td>
                                                    <td class="text-success">₱<?php echo number_format($paid_amount, 2); ?></td>
                                                    <td class="text-danger">₱<?php echo number_format($remaining_balance, 2); ?></td>
                                                    <td>
                                                    <div class="pc-progress">
                                                        <div class="pc-progress__track">
                                                            <span class="pc-progress__bar" style="width: <?php echo min($progress, 100); ?>%"></span>
                                                        </div>
                                                        <span class="pc-progress__label"><?php echo number_format(min($progress, 100), 1); ?>%</span>
                                                    </div>
                                                    </td>
                                                    <td><?php echo $collector_name; ?></td>
                                                    <td class="<?php echo $isPastDue ? 'status-defaulted' : 'status-active'; ?>">
                                                        <?php if ($isPastDue): ?>
                                                        <span class="pc-pill pc-pill--danger"><i class="fas fa-exclamation-circle" aria-hidden="true"></i> Past Due</span>
                                                        <?php else: ?>
                                                        <span class="pc-pill pc-pill--success"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Active</span>
                                                        <?php endif; ?>
                                                        <br>
                                                        <span class="badge automatic-penalty-status <?php echo $automatic_penalty_enabled ? 'bg-success' : 'bg-danger'; ?>">
                                                            <i class="fas fa-<?php echo $automatic_penalty_enabled ? 'play' : 'pause'; ?> me-1"></i>
                                                            Auto Penalty: <?php echo $automatic_penalty_enabled ? 'ON' : 'OFF'; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div class="action-buttons">
                                                            <?php if ($is_admin || $is_area_manager || $is_proxy): ?>
                                                            <a href="generate_promissory_note.php?loan_id=<?php echo $loan['id']; ?>" class="btn btn-sm btn-info" target="_blank" title="Promissory Note & Cash Voucher">
                                                                <i class="fas fa-file-contract"></i>
                                                            </a>
                                                            <?php endif; ?>
                                                            <button class="btn btn-sm btn-primary view-loan-btn" title="View Payments" data-loan-id="<?php echo $loan['id']; ?>">
                                                                <i class="fas fa-eye"></i>
                                                            </button>
                                                            <!-- PRINT SAVINGS WITHDRAW VOUCHER BUTTON -->
                                                            <?php if ($is_admin || $is_area_manager || $is_collector || $is_proxy): ?>
                                                                <button class="btn btn-sm btn-secondary print-savings-withdraw-voucher-btn" 
                                                                        title="Print Savings Withdraw Voucher" 
                                                                        data-loan-id="<?php echo $loan['id']; ?>"
                                                                        data-customer-id="<?php echo $customer_id; ?>">
                                                                    <i class="fas fa-money-bill-wave"></i>
                                                                </button>
                                                            <?php endif; ?>
                                                            <?php if ($is_admin || $is_area_manager): ?>
                                                                <button class="btn btn-sm btn-warning edit-amount-financed-btn" 
                                                                        title="Edit Amount Financed" 
                                                                        data-loan-id="<?php echo $loan['id']; ?>"
                                                                        data-current-amount="<?php echo $loan['amount_financed']; ?>">
                                                                    <i class="fas fa-edit"></i>
                                                                </button>
                                                            <?php endif; ?>
                                                            <?php if ($is_admin || $is_area_manager): ?>
                                                                <?php if ($automatic_penalty_enabled): ?>
                                                                    <button class="btn btn-sm btn-warning stop-penalty-btn" title="Stop Auto Penalty" data-loan-id="<?php echo $loan['id']; ?>">
                                                                        <i class="fas fa-pause"></i>
                                                                    </button>
                                                                <?php else: ?>
                                                                    <button class="btn btn-sm btn-success enable-penalty-btn" title="Enable Auto Penalty" data-loan-id="<?php echo $loan['id']; ?>">
                                                                        <i class="fas fa-play"></i>
                                                                    </button>
                                                                <?php endif; ?>
                                                                <button class="btn btn-sm btn-success waive-penalty-btn"  data-loan-id="<?php echo $loan['id']; ?>">
                                                                    <i class="fas fa-hand-holding-heart"></i>
                                                                </button>
                                                            <?php endif; ?>
                                                            <?php if ($is_admin || $is_area_manager): ?>
                                                                <button class="btn btn-sm btn-danger delete-loan-btn" title="Delete" data-loan-id="<?php echo $loan['id']; ?>">
                                                                    <i class="fas fa-trash-alt"></i>
                                                                </button>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="no-loans text-center py-5">
                                    <div>
                                        <i class="fas fa-hand-holding-usd fa-4x text-muted mb-3"></i>
                                        <h4>No Active Loans Found</h4>
                                        <p class="text-muted">This customer doesn't have any active loans at the moment.</p>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>
                    
                    <!-- Inactive Loans Section -->
                    <div class="inactive-loans-section">
                        <section class="dash-card">
                            <header class="dash-card__head">
                                <span class="dash-card__icon"><i class="fas fa-clock-rotate-left" aria-hidden="true"></i></span>
                                <div>
                                    <h2 class="dash-card__title">Completed / Closed Loans</h2>
                                    <p class="dash-card__hint">Fully paid and closed loans</p>
                                </div>
                            </header>
                            <div class="dash-card__body">
                                <?php if (count($inactive_loans) > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Loan ID</th>
                                                    <th>Start Date</th>
                                                    <th>Amount</th>
                                                    <th>Savings Deposits</th>
                                                    <th>Savings Withdrawals</th>
                                                    <th>Savings Balance</th>
                                                    <th>Penalty</th>
                                                    <th>Paid</th>
                                                    <th>Status</th>
                                                    <th>Collector</th>
                                                    <th>End Date</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($inactive_loans as $loan): 
                                                    $total_amount = $loan['amount_financed'] + $loan['interest_peso'] + $loan['penalty'];
                                                    
                                                    $total_savings_deposits = floatval($loan['total_savings_deposits'] ?? 0);
                                                    $total_withdrawals = floatval($loan['total_withdrawals'] ?? 0);
                                                    $savings_balance = floatval($loan['savings_balance'] ?? 0);
                                                    
                                                    $collector_name = 'N/A';
                                                    if (!empty($loan['collector_first_name']) || !empty($loan['collector_last_name'])) {
                                                        $collector_name = htmlspecialchars(($loan['collector_first_name'] ?? '') . ' ' . ($loan['collector_last_name'] ?? ''));
                                                    }
                                                    
                                                    $status_class = 'status-completed';
                                                    $status_icon = 'fas fa-check-circle';
                                                    if ($loan['status'] === 'defaulted') {
                                                        $status_class = 'status-defaulted';
                                                        $status_icon = 'fas fa-exclamation-circle';
                                                    }
                                                    
                                                    $has_savings_balance = ($loan['savings_balance'] && $loan['savings_balance'] > 0);
                                                ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($loan['id']); ?></td>
                                                        <td><?php echo date('m-d-Y', strtotime($loan['start_date'])); ?></td>
                                                        <td class="fw-bold">₱<?php echo number_format($total_amount, 2); ?></td>
                                                        <td class="text-info fw-bold">₱<?php echo number_format($total_savings_deposits, 2); ?></td>
                                                        <td class="text-danger fw-bold">₱<?php echo number_format($total_withdrawals, 2); ?></td>
                                                        <td class="text-success fw-bold">₱<?php echo number_format($savings_balance, 2); ?></td>
                                                        <td class="text-danger fw-bold">₱<?php echo number_format($loan['penalty'], 2); ?></td>
                                                        <td class="text-success">₱<?php echo number_format($loan['total_paid'] ?? 0, 2); ?></td>
                                                        <td class="<?php echo $status_class; ?>">
                                                            <i class="<?php echo $status_icon; ?> me-1"></i> 
                                                            <?php echo ucfirst($loan['status']); ?>
                                                        </td>
                                                        <td><?php echo $collector_name; ?></td>
                                                        <td><?php echo htmlspecialchars($loan['end_date'] ?? 'N/A'); ?></td>
                                                        <td>
                                                            <div class="action-buttons">
                                                                <button class="btn btn-sm btn-primary view-loan-btn" title="View" data-loan-id="<?php echo $loan['id']; ?>">
                                                                    <i class="fas fa-eye"></i> 
                                                                </button>
                                                                <button class="btn btn-sm btn-light print-loan-btn" title="Print" data-loan-id="<?php echo $loan['id']; ?>">
                                                                    <i class="fas fa-print"></i> 
                                                                </button>
                                                                <?php if ($is_admin || $is_area_manager || $is_collector || $is_proxy): ?>
                                                                    <button class="btn btn-sm btn-secondary print-savings-withdraw-voucher-btn" 
                                                                            title="Print Savings Withdraw Voucher" 
                                                                            data-loan-id="<?php echo $loan['id']; ?>"
                                                                            data-customer-id="<?php echo $customer_id; ?>">
                                                                        <i class="fas fa-money-bill-wave"></i>
                                                                    </button>
                                                                <?php endif; ?>
                                                                <?php if ($loan['savings_status'] && $loan['savings_status'] !== 'Closed'): ?>
                                                                    <?php if ($has_savings_balance): ?>
                                                                        <button class="btn btn-sm btn-secondary" disabled title="Cannot close savings with balance">
                                                                            <i class="fas fa-piggy-bank"></i> Savings Have a Balance
                                                                        </button>
                                                                    <?php else: ?>
                                                                        <button class="btn btn-sm btn-success closed-savings-btn" title="Close Savings" data-loan-id="<?php echo $loan['id']; ?>">
                                                                            <i class="fas fa-piggy-bank"></i>
                                                                        </button>
                                                                    <?php endif; ?>
                                                                <?php else: ?>
                                                                    <span class="badge bg-secondary">Savings Closed</span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="dash-empty">
                                        <i class="fas fa-clock-rotate-left" aria-hidden="true"></i>
                                        <p class="fw-semibold mb-1">No completed loans</p>
                                        <p class="mb-0">This customer has no completed or closed loans yet.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </section>
                    </div>
                </div>
                
                <!-- View Loan Modal -->
                <div class="modal fade pc-modal" id="viewLoanModal" tabindex="-1" aria-labelledby="viewLoanModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header pc-modal__head bg-primary text-white">
                                <h5 class="modal-title" id="viewLoanModalLabel">Loan Details</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body pc-modal__body" id="loanDetailsContent">
                                <div class="text-center py-4">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                    <p class="mt-2">Loading loan details...</p>
                                </div>
                            </div>
                            <div class="modal-footer pc-modal__foot">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                <?php if (!$is_collector && !$is_proxy && !$is_customer): ?>
                                <button type="button" class="btn btn-primary" id="printLoanBtn">
                                    <i class="fas fa-print"></i> Print Details
                                </button>
                                <a href="#" class="btn btn-info" id="promissoryNoteBtn" target="_blank">
                                    <i class="fas fa-file-contract"></i>
                                </a>
                                <button type="button" class="btn btn-success" id="refreshLoanBtn">
                                    <i class="fas fa-sync-alt"></i> Refresh
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============================================================ -->
                <!-- SAVINGS WITHDRAW VOUCHER MODAL                                -->
                <!-- ============================================================ -->
                <div class="modal fade pc-modal" id="savingsWithdrawVoucherModal" tabindex="-1" aria-labelledby="savingsWithdrawVoucherModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header pc-modal__head bg-primary text-white">
                                <h5 class="modal-title" id="savingsWithdrawVoucherModalLabel">
                                    <i class="fas fa-money-bill-wave me-2"></i>Savings Withdrawal Voucher History
                                </h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body pc-modal__body" id="savingsWithdrawVoucherContent">
                                <div class="text-center py-4">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                    <p class="mt-2">Loading withdrawal history...</p>
                                </div>
                            </div>
                            <div class="modal-footer pc-modal__foot">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                <button type="button" class="btn btn-primary" id="printSavingsWithdrawVoucherBtn">
                                    <i class="fas fa-print"></i> Print All Voucher
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- View Payment Modal -->
                <div class="modal fade pc-modal" id="viewPaymentModal" tabindex="-1" aria-labelledby="viewPaymentModalLabel" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header pc-modal__head bg-primary text-white">
                                <h5 class="modal-title" id="viewPaymentModalLabel">Payment Details</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body pc-modal__body" id="paymentDetailsContent">
                                <div class="text-center py-4">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                    <p class="mt-2">Loading payment details...</p>
                                </div>
                            </div>
                            <div class="modal-footer pc-modal__foot">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                <?php if (!$is_collector && !$is_proxy && !$is_customer): ?>
                                <button type="button" class="btn btn-primary" id="printPaymentBtn">
                                    <i class="fas fa-print"></i> Print Receipt
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- View Savings Deposit Modal -->
                <div class="modal fade pc-modal" id="viewSavingsDepositModal" tabindex="-1" aria-labelledby="viewSavingsDepositModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header pc-modal__head bg-primary text-white">
                                <h5 class="modal-title" id="viewSavingsDepositModalLabel">Savings Transaction Details</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body pc-modal__body" id="savingsDepositDetailsContent">
                                <div class="text-center py-4">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                    <p class="mt-2">Loading savings deposit details...</p>
                                </div>
                            </div>
                            <div class="modal-footer pc-modal__foot">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                <?php if (!$is_collector && !$is_proxy && !$is_customer): ?>
                                <button type="button" class="btn btn-primary" id="printSavingsDepositBtn">
                                    <i class="fas fa-print"></i> Print Receipt
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Delete Payment Confirmation Modal -->
                <div class="modal fade pc-modal" id="deletePaymentModal" tabindex="-1" aria-labelledby="deletePaymentModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header pc-modal__head bg-danger text-white">
                                <h5 class="modal-title" id="deletePaymentModalLabel">Confirm Payment Deletion</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                                <div class="modal-body pc-modal__body">
                                    <div class="pc-pay pc-pay--danger">
                                        <header class="pc-pay__head">
                                            <span class="pc-pay__icon pc-pay__icon--danger"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i></span>
                                            <div class="pc-pay__ident">
                                                <h5 class="pc-pay__id">This payment will be removed</h5>
                                                <p class="pc-pay__date">Deleting recalculates the loan balances and cannot be undone.</p>
                                            </div>
                                        </header>

                                        <div class="pc-pay__amount pc-pay__amount--debit">
                                            <span class="pc-pay__amount-label">Payment amount</span>
                                            <span class="pc-pay__amount-value" id="paymentAmountToDelete">&#8369;0.00</span>
                                        </div>

                                        <dl class="pc-pay__grid">
                                            <div class="pc-pay__row pc-pay__row--wide">
                                                <dt>What happens next</dt>
                                                <dd>The payment is deleted permanently and the loan's running balances are recomputed.</dd>
                                            </div>
                                        </dl>
                                    </div>
                                </div>
                            <div class="modal-footer pc-modal__foot">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-danger" id="confirmDeletePaymentBtn">
                                    <i class="fas fa-trash-alt me-1"></i> Delete Payment
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Delete Savings Deposit Confirmation Modal -->
                <div class="modal fade pc-modal" id="deleteSavingsDepositModal" tabindex="-1" aria-labelledby="deleteSavingsDepositModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header pc-modal__head bg-danger text-white">
                                <h5 class="modal-title" id="deleteSavingsDepositModalLabel">Confirm Savings Deposit Deletion</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                                <div class="modal-body pc-modal__body">
                                    <div class="pc-pay pc-pay--danger">
                                        <header class="pc-pay__head">
                                            <span class="pc-pay__icon pc-pay__icon--danger"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i></span>
                                            <div class="pc-pay__ident">
                                                <h5 class="pc-pay__id">This savings deposit will be removed</h5>
                                                <p class="pc-pay__date">Deleting adjusts the savings balance and cannot be undone.</p>
                                            </div>
                                        </header>

                                        <div class="pc-pay__amount pc-pay__amount--debit">
                                            <span class="pc-pay__amount-label">Deposit amount</span>
                                            <span class="pc-pay__amount-value" id="savingsDepositAmountToDelete">&#8369;0.00</span>
                                        </div>

                                        <dl class="pc-pay__grid">
                                            <div class="pc-pay__row pc-pay__row--wide">
                                                <dt>What happens next</dt>
                                                <dd>The transaction is deleted permanently and the savings balance it fed is recomputed.</dd>
                                            </div>
                                        </dl>
                                    </div>
                                </div>
                            <div class="modal-footer pc-modal__foot">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-danger" id="confirmDeleteSavingsDepositBtn">
                                    <i class="fas fa-trash-alt me-1"></i> Delete Deposit
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Edit Payment Modal -->
                <div class="modal fade pc-modal" id="editPaymentModal" tabindex="-1" aria-labelledby="editPaymentModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header pc-modal__head bg-primary text-white">
                                <h5 class="modal-title" id="editPaymentModalLabel">Edit Payment Amount</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body pc-modal__body">
                                <form id="editPaymentForm" class="pc-pay">
                                    <input type="hidden" id="editPaymentId">

                                    <div class="pc-pay__head">
                                        <span class="pc-pay__icon"><i class="fas fa-pen-to-square" aria-hidden="true"></i></span>
                                        <div class="pc-pay__ident">
                                            <h5 class="pc-pay__id">Adjust payment amount</h5>
                                            <p class="pc-pay__date">Saving recalculates the receipt and the running balances for this loan.</p>
                                        </div>
                                    </div>

                                    <div class="pc-pay__field-group">
                                        <label for="editPaymentAmount" class="pc-pay__field-label">Payment amount</label>
                                        <div class="pc-pay__field">
                                            <span class="pc-pay__currency">&#8369;</span>
                                            <input type="text" class="form-control" id="editPaymentAmount" inputmode="decimal" autocomplete="off" required>
                                        </div>
                                    </div>
                                </form>
                            </div>
                            <div class="modal-footer pc-modal__foot">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-warning" id="confirmEditPaymentBtn">
                                    <i class="fas fa-save me-1"></i> Save Changes
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Edit Savings Deposit Modal -->
                <div class="modal fade pc-modal" id="editSavingsDepositModal" tabindex="-1" aria-labelledby="editSavingsDepositModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header pc-modal__head bg-primary text-white">
                                <h5 class="modal-title" id="editSavingsDepositModalLabel">Edit Savings Deposit Amount</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body pc-modal__body">
                                <form id="editSavingsDepositForm" class="pc-pay">
                                    <input type="hidden" id="editSavingsDepositId">

                                    <div class="pc-pay__head">
                                        <span class="pc-pay__icon"><i class="fas fa-piggy-bank" aria-hidden="true"></i></span>
                                        <div class="pc-pay__ident">
                                            <h5 class="pc-pay__id">Adjust savings deposit</h5>
                                            <p class="pc-pay__date">Saving updates this transaction and the savings balance it feeds.</p>
                                        </div>
                                    </div>

                                    <div class="pc-pay__field-group">
                                        <label for="editSavingsDepositAmount" class="pc-pay__field-label">Deposit amount</label>
                                        <div class="pc-pay__field">
                                            <span class="pc-pay__currency">&#8369;</span>
                                            <input type="text" class="form-control" id="editSavingsDepositAmount" inputmode="decimal" autocomplete="off" required>
                                        </div>
                                    </div>
                                </form>
                            </div>
                            <div class="modal-footer pc-modal__foot">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-warning" id="confirmEditSavingsDepositBtn">
                                    <i class="fas fa-save me-1"></i> Save Changes
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Delete Loan Confirmation Modal -->
                <div class="modal fade pc-modal" id="deleteLoanModal" tabindex="-1" aria-labelledby="deleteLoanModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header pc-modal__head bg-danger text-white">
                                <h5 class="modal-title" id="deleteLoanModalLabel">Confirm Loan Deletion</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                                <div class="modal-body pc-modal__body">
                                    <div class="pc-pay pc-pay--danger">
                                        <header class="pc-pay__head">
                                            <span class="pc-pay__icon pc-pay__icon--danger"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i></span>
                                            <div class="pc-pay__ident">
                                                <h5 class="pc-pay__id">This loan will be removed</h5>
                                                <p class="pc-pay__date">Deleting a loan also removes every payment recorded against it.</p>
                                            </div>
                                        </header>

                                        <div class="pc-pay__figures">
                                            <div class="pc-pay__figure">
                                                <span class="pc-pay__figure-label">Loan amount</span>
                                                <span class="pc-pay__figure-value" id="loanAmountToDelete">&#8369;0.00</span>
                                            </div>
                                            <div class="pc-pay__figure">
                                                <span class="pc-pay__figure-label">Financed</span>
                                                <span class="pc-pay__figure-value" id="loanFinancedToDelete">&#8369;0.00</span>
                                            </div>
                                        </div>

                                        <dl class="pc-pay__grid">
                                            <div class="pc-pay__row pc-pay__row--wide pc-pay__row--center">
                                                <dt>Remaining balance</dt>
                                                <dd><span id="loanBalanceToDelete" class="pc-pay__debit">&#8369;0.00</span></dd>
                                            </div>
                                            <div class="pc-pay__row pc-pay__row--wide">
                                                <dt>What happens next</dt>
                                                <dd>The loan and all of its payments are deleted permanently and the customer totals are recomputed.</dd>
                                            </div>
                                        </dl>
                                    </div>
                                </div>
                            <div class="modal-footer pc-modal__foot">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-danger" id="confirmDeleteLoanBtn">
                                    <i class="fas fa-trash-alt me-1"></i> Delete Loan
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Waive Penalty Modal -->
                <div class="modal fade pc-modal" id="waivePenaltyModal" tabindex="-1" aria-labelledby="waivePenaltyModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header pc-modal__head bg-success text-white">
                                <h5 class="modal-title" id="waivePenaltyModalLabel">Waive Penalty</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body pc-modal__body">
                                <form id="waivePenaltyForm" class="pc-pay">
                                    <input type="hidden" id="waiveLoanId">

                                    <div class="pc-pay__head">
                                        <span class="pc-pay__icon pc-pay__icon--success"><i class="fas fa-hand-holding-heart" aria-hidden="true"></i></span>
                                        <div class="pc-pay__ident">
                                            <h5 class="pc-pay__id">Waive penalties</h5>
                                            <p class="pc-pay__date">Tick the penalties to forgive, then set how much of each one to waive.</p>
                                        </div>
                                    </div>

                                    <div class="pc-pay__table-wrap">
                                        <table class="table waive-penalty-table">
                                            <thead>
                                                <tr>
                                                    <th>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" id="selectAllPenalties">
                                                            <label class="form-check-label" for="selectAllPenalties">All</label>
                                                        </div>
                                                    </th>
                                                    <th>Date</th>
                                                    <th>Original</th>
                                                    <th>Current</th>
                                                    <th>Waive amount</th>
                                                    <th>Notes</th>
                                                </tr>
                                            </thead>
                                            <tbody id="penaltiesTableBody">
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="pc-pay__figures">
                                        <div class="pc-pay__figure pc-pay__figure--neutral">
                                            <span class="pc-pay__figure-label">Selected penalties</span>
                                            <span class="pc-pay__figure-value" id="totalSelectedAmount">&#8369;0.00</span>
                                        </div>
                                        <div class="pc-pay__figure pc-pay__figure--success">
                                            <span class="pc-pay__figure-label">Total to waive</span>
                                            <span class="pc-pay__figure-value" id="totalWaiveAmount">&#8369;0.00</span>
                                        </div>
                                    </div>

                                    <div class="pc-pay__field-group">
                                        <label for="waiveReason" class="pc-pay__field-label">Reason for waiving <span class="pc-pay__required">*</span></label>
                                        <textarea class="form-control" id="waiveReason" rows="3" placeholder="Enter the reason for waiving these penalties..." required></textarea>
                                        <p class="pc-pay__hint">This reason is recorded in the penalty waiver logs.</p>
                                    </div>

                                    <div class="pc-pay__field-group">
                                        <label for="additionalNotes" class="pc-pay__field-label">Additional notes <span class="pc-pay__optional">(optional)</span></label>
                                        <textarea class="form-control" id="additionalNotes" rows="2" placeholder="Any additional notes..."></textarea>
                                    </div>
                                </form>
                            </div>
                            <div class="modal-footer pc-modal__foot">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-success" id="confirmWaivePenaltyBtn">
                                    <i class="fas fa-hand-holding-heart me-1"></i> Waive Selected Penalties
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- View Penalty Modal -->
                <div class="modal fade pc-modal" id="viewPenaltyModal" tabindex="-1" aria-labelledby="viewPenaltyModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class ="modal-content">
                            <div class="modal-header pc-modal__head bg-primary text-white">
                                <h5 class="modal-title" id="viewPenaltyModalLabel">Penalty Details</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body pc-modal__body" id="penaltyDetailsContent">
                                <div class="text-center py-4">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                    <p class="mt-2">Loading penalty details...</p>
                                </div>
                            </div>
                            <div class="modal-footer pc-modal__foot">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                <?php if (!$is_collector && !$is_proxy && !$is_customer): ?>
                                <button type="button" class="btn btn-primary" id="printPenaltyBtn">
                                    <i class="fas fa-print"></i> Print Details
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Edit Penalty Modal -->
                <div class="modal fade pc-modal" id="editPenaltyModal" tabindex="-1" aria-labelledby="editPenaltyModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header pc-modal__head bg-warning text-white">
                                <h5 class="modal-title" id="editPenaltyModalLabel">Edit Penalty Amount</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body pc-modal__body">
                                <form id="editPenaltyForm">
                                    <input type="hidden" id="editPenaltyId">
                                    <div class="mb-3">
                                        <label for="editPenaltyAmount" class="form-label">Penalty Amount</label>
                                        <input type="text" class="form-control" id="editPenaltyAmount" required>
                                    </div>
                                </form>
                            </div>
                            <div class="modal-footer pc-modal__foot">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-warning" id="confirmEditPenaltyBtn">
                                    <i class="fas fa-save me-1"></i> Save Changes
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Delete Penalty Confirmation Modal -->
                <div class="modal fade pc-modal" id="deletePenaltyModal" tabindex="-1" aria-labelledby="deletePenaltyModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header pc-modal__head bg-danger text-white">
                                <h5 class="modal-title" id="deletePenaltyModalLabel">Confirm Penalty Deletion</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body pc-modal__body">
                                <p>Are you sure you want to delete this penalty? This action cannot be undone.</p>
                                <p class="fw-bold">Penalty Amount: <span id="penaltyAmountToDelete">₱0.00</span></p>
                                <p class="text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Deleting this penalty will reduce the loan balance.</p>
                            </div>
                            <div class="modal-footer pc-modal__foot">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-danger" id="confirmDeletePenaltyBtn">
                                    <i class="fas fa-trash-alt me-1"></i> Delete Penalty
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Edit Amount Financed Modal -->
                <div class="modal fade pc-modal" id="editAmountFinancedModal" tabindex="-1" aria-labelledby="editAmountFinancedModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header pc-modal__head bg-primary text-white">
                                <h5 class="modal-title" id="editAmountFinancedModalLabel">Edit Amount Financed</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body pc-modal__body">
                                <form id="editAmountFinancedForm" class="pc-pay">
                                    <input type="hidden" id="editLoanId">
                                    <input type="hidden" id="originalAmountFinanced">

                                    <div class="pc-pay__head">
                                        <span class="pc-pay__icon"><i class="fas fa-pen-to-square" aria-hidden="true"></i></span>
                                        <div class="pc-pay__ident">
                                            <h5 class="pc-pay__id">Adjust amount financed</h5>
                                            <p class="pc-pay__date">Saving recalculates the interest, the total balance and the daily payment.</p>
                                        </div>
                                    </div>

                                    <div class="pc-pay__field-group">
                                        <label for="editAmountFinanced" class="pc-pay__field-label">New amount financed</label>
                                        <div class="pc-pay__field">
                                            <span class="pc-pay__currency">&#8369;</span>
                                            <input type="text" class="form-control" id="editAmountFinanced" inputmode="decimal" autocomplete="off" required>
                                        </div>
                                    </div>

                                    <dl class="pc-pay__grid">
                                        <div class="pc-pay__row pc-pay__row--wide">
                                            <dt>Current amount</dt>
                                            <dd><span id="currentAmountDisplay" class="pc-pay__debit">&#8369;0.00</span></dd>
                                        </div>
                                        <div class="pc-pay__row pc-pay__row--wide">
                                            <dt>What gets recalculated</dt>
                                            <dd>
                                                <ul class="pc-pay__list">
                                                    <li>Interest amount (15% of the new amount)</li>
                                                    <li>Total balance</li>
                                                    <li>Daily payment</li>
                                                </ul>
                                            </dd>
                                        </div>
                                    </dl>
                                </form>
                            </div>
                            <div class="modal-footer pc-modal__foot">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-warning" id="confirmEditAmountFinancedBtn">
                                    <i class="fas fa-save me-1"></i> Update Amount
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </main>

                <?php include __DIR__ . '/includes/footer.php'; ?>
            </div>
        <?php if (!$is_customer_portal): ?>
        </div>
        <?php endif; ?>

        <script type="application/json" id="customer-view-payment-data"><?php echo json_encode($pageConfig, $jsonFlags); ?></script>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
        <script src="js/scripts.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/umd/simple-datatables.min.js" crossorigin="anonymous"></script>
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <script src="assets/js/customer-view-payment.js"></script>
    </body>
</html>
