<?php
/**
 * Prime City - Payment history report page.
 *
 * Layout only: all data and helpers come from actions/payment-history-actions.php,
 * layout chrome from includes/*, styles from assets/css and behaviour from
 * assets/js.
 */

require_once __DIR__ . '/actions/payment-history-actions.php';

try {
    $context = ph_context();
} catch (Throwable $e) {
    error_log('[payment-history] ' . $e->getMessage());
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
        <head>
            <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
            <meta charset="utf-8" />
            <title>Payment History - Unavailable</title>
            <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        </head>
        <body class="bg-light">
            <div class="container py-5">
                <div class="alert alert-danger">
                    <h4 class="alert-heading">The payment history report is unavailable</h4>
                    <p class="mb-0">We could not build the report right now. Please try again in a moment.</p>
                </div>
            </div>
        </body>
    </html>
    <?php
    exit;
}

$user = $context['user'];
$user_account_type = $context['user_account_type'];
$user_branch = $context['user_branch'];
$user_area = $context['user_area'];
$available_branches = $context['available_branches'];
$selected_branch = $context['selected_branch'];
$selected_area = $context['selected_area'];
$areas = $context['areas'];
$start_date = $context['start_date'];
$end_date = $context['end_date'];
$due_date_filter = $context['due_date_filter'];
$custom_due_date = $context['custom_due_date'];
$show_completed = $context['show_completed'];
$show_missed_payments = $context['show_missed_payments'];
$all_dates_in_range = $context['all_dates_in_range'];
$date_headers = $context['date_headers'];
$customers_by_area = $context['customers_by_area'];
$daily_payments = $context['daily_payments'];
$savings_deposits = $context['savings_deposits'];
$savings_payments = $context['savings_payments'];
$customer_last_payments = $context['customer_last_payments'];

// Shared layout includes read these.
$layout_active = 'payment-history';
$layout_menu = $context['layout_menu'];

$pageConfig = array(
    'selectedBranch' => $selected_branch,
);

$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=now" />
    <meta name="description" content="Prime City payment history report" />
    <meta name="author" content="Prime City" />
    <title>Payment History - All Customers</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
    <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
    <link href="assets/css/dash.css" rel="stylesheet" />
    <link href="assets/css/dash-ui.css" rel="stylesheet" />
    <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
    <link href="assets/css/payment-history-style.css" rel="stylesheet" />
</head>
<body class="sb-nav-fixed">
    <?php include __DIR__ . '/includes/topbar.php'; ?>

    <div id="layoutSidenav">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <div id="layoutSidenav_content">
        <main>
            <div class="dash">
                <header class="dash-head no-print">
                    <div>
                        <p class="dash-eyebrow">
                            <i class="fas fa-receipt" aria-hidden="true"></i>
                            Prime City &middot; Reports
                        </p>
                        <h1 class="dash-title">All Payment History</h1>
                        <p class="dash-sub">Daily and savings collections for every customer in the selected branch, area and date range.</p>
                    </div>

                    <div class="dash-head__actions">
                        <a href="print_payment_history.php?<?php echo http_build_query($_GET); ?>" class="btn btn-primary btn-sm no-print" target="_blank">
                            <i class="fas fa-file-pdf"></i> Print All
                        </a>
                        <a href="customer_list.php" class="btn btn-secondary btn-sm no-print">
                            <i class="fas fa-arrow-left"></i> Back to Customers
                        </a>
                    </div>
                </header>

                <div class="card dash-card mb-4">
                    <header class="dash-card__head no-print">
                        <span class="dash-card__icon"><i class="fas fa-table" aria-hidden="true"></i></span>
                        <div>
                            <h2 class="dash-card__title">Payment History - All Customers</h2>
                            <p class="dash-card__hint">Collections grid grouped by area for the selected period</p>
                        </div>
                    </header>
                    <div class="card-body">

                        <!-- User Info and Branch Filter Section -->
                        <div class="filter-section no-print">
                            <?php 
                            // Define account types that should show filter
                            $single_branch_account_types = ['Branch Manager', 'Proxy Collector-Cashier', 'Proxy Collector', 'Collector'];
                            $show_filter = !in_array($user_account_type, $single_branch_account_types);
                            
                            // Collector specific info
                            if ($user_account_type === 'Collector'): ?>
                                <div class="collector-restricted">
                                    <strong><i class="fas fa-user-tie"></i> Collector Account</strong><br>
                                    <strong>Branch:</strong> <?php echo htmlspecialchars($user_branch); ?> | 
                                    <strong>Area:</strong> <?php echo htmlspecialchars($user_area); ?>
                                    <p class="mb-0" style="font-size: 12px; color: #666;">
                                        <i class="fas fa-info-circle"></i> You can only view customers in your assigned branch and area.
                                    </p>
                                </div>
                            <?php elseif ($user_account_type === 'Proxy Collector'): ?>
                                <div class="collector-restricted">
                                    <strong><i class="fas fa-user-tie"></i> Proxy Collector Account</strong><br>
                                    <strong>Branch:</strong> <?php echo htmlspecialchars($user_branch); ?>
                                    <p class="mb-0" style="font-size: 12px; color: #666;">
                                        <i class="fas fa-info-circle"></i> You can only view customers in your assigned branch.
                                    </p>
                                </div>
                            <?php elseif (!$show_filter): ?>
                                <div class="user-branch-info">
                                    <strong>User Type:</strong> <?php echo htmlspecialchars($user_account_type); ?> | 
                                    <strong>Assigned Branch:</strong> <?php echo htmlspecialchars($user_branch); ?>
                                </div>
                            <?php else: ?>
                                <div class="filter-row">
                                    <span class="filter-label">Filter by Branch:</span>
                                    <div class="branch-filter-dropdown">
                                        <select class="form-select" id="branchFilter" onchange="applyBranchFilter(this.value)">
                                            <?php if ($user_account_type === 'Admin'): ?>
                                                <option value="all" <?php echo $selected_branch == 'all' ? 'selected' : ''; ?>>All Branches</option>
                                            <?php endif; ?>
                                            
                                            <?php foreach ($available_branches as $branch): ?>
                                                <option value="<?php echo htmlspecialchars($branch); ?>" <?php echo ($selected_branch == $branch) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($branch); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Filter Form -->
                        <div class="filter-container no-print">
                            <form method="get" action="" class="filter-form">
                                <input type="hidden" name="branch" id="branchInput" value="<?php echo htmlspecialchars($selected_branch); ?>">
                                <div class="row g-3">
                                    <div class="col-md-2">
                                        <label for="area" class="form-label">Area</label>
                                        <select class="form-select" id="area" name="area" <?php echo ($user_account_type === 'Collector' && $user_area) ? 'disabled' : ''; ?>>
                                            <option value="">All Areas</option>
                                            <?php foreach ($areas as $area): ?>
                                                <option value="<?php echo htmlspecialchars($area); ?>" <?php echo ($selected_area == $area) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($area); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if ($user_account_type === 'Collector' && $user_area): ?>
                                            <input type="hidden" name="area" value="<?php echo htmlspecialchars($user_area); ?>">
                                            <small class="form-text text-muted">Fixed to your assigned area</small>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-2">
                                        <label for="due_date" class="form-label">Due Date</label>
                                        <select class="form-select" id="due_date" name="due_date">
                                            <option value="">All Due Dates</option>
                                            <option value="past_due" <?php echo ($due_date_filter == 'past_due') ? 'selected' : ''; ?>>Past Due</option>
                                            <option value="today" <?php echo ($due_date_filter == 'today') ? 'selected' : ''; ?>>Today</option>
                                            <option value="future" <?php echo ($due_date_filter == 'future') ? 'selected' : ''; ?>>Future</option>
                                            <option value="this_week" <?php echo ($due_date_filter == 'this_week') ? 'selected' : ''; ?>>This Week</option>
                                            <option value="next_week" <?php echo ($due_date_filter == 'next_week') ? 'selected' : ''; ?>>Next Week</option>
                                            <option value="this_month" <?php echo ($due_date_filter == 'this_month') ? 'selected' : ''; ?>>This Month</option>
                                            <option value="custom" <?php echo ($due_date_filter == 'custom') ? 'selected' : ''; ?>>Custom Date</option>
                                        </select>
                                        <div id="customDueDateContainer" class="custom-due-date-container <?php echo ($due_date_filter == 'custom') ? 'show' : ''; ?>">
                                            <label for="custom_due_date" class="form-label mt-2">Select Date:</label>
                                            <input type="date" class="form-control" id="custom_due_date" name="custom_due_date" value="<?php echo htmlspecialchars($custom_due_date); ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <label for="start_date" class="form-label">Start Date</label>
                                        <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label for="end_date" class="form-label">End Date</label>
                                        <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <div class="form-check mt-4">
                                            <input class="form-check-input" type="checkbox" id="show_completed" name="show_completed" value="1" <?php echo $show_completed ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="show_completed">
                                                Show Completed Loans
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="form-check mt-4">
                                            <input class="form-check-input" type="checkbox" id="show_missed_payments" name="show_missed_payments" value="1" <?php echo $show_missed_payments ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="show_missed_payments" style="color: #dc3545; font-weight: bold;">
                                                Show Only Missed Payments
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-md-12 filter-buttons">
                                        <button type="submit" class="btn btn-primary">Apply Filters</button>
                                        <a href="payment_history.php" class="btn btn-secondary">Reset Filters</a>
                                    </div>
                                </div>
                            </form>
                        </div>

                        <!-- Missed Payment Filter Info -->
                        <?php if ($show_missed_payments): ?>
                        <div class="missed-filter-info no-print">
                            <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                            <strong>Showing customers with missed payments:</strong> 
                            This filter shows customers who have missed at least one daily payment during the selected date range.
                            <strong>Logic:</strong> A day is counted as "missed" if there's NO payment in either "Daily" OR "Savings Pay" columns for that day.
                            <strong>Net Calculation:</strong> (Daily Amount - Total Paid) for each day. Positive = Underpayment, Negative = Advance Payment.
                            <strong>Payment days:</strong> Monday to Saturday (Sunday excluded)
                            <strong>Date Range:</strong> <?php echo date('F j, Y', strtotime($start_date)); ?> to <?php echo date('F j, Y', strtotime($end_date)); ?>
                            (<?php 
                                $days_count = count($all_dates_in_range ?? []);
                                echo $days_count . ' day' . ($days_count != 1 ? 's' : '');
                            ?> total, <?php 
                                $payment_days_count = 0;
                                if (isset($all_dates_in_range)) {
                                    foreach ($all_dates_in_range as $date) {
                                        $day_of_week = date('N', strtotime($date));
                                        if ($day_of_week != 7) {
                                            $payment_days_count++;
                                        }
                                    }
                                }
                                echo $payment_days_count . ' payment day' . ($payment_days_count != 1 ? 's' : '') . ' (Mon-Sat)';
                            ?>)
                        </div>
                        <?php endif; ?>

                        <!-- Payment Method Legend -->
                        <div class="payment-legend no-print">
                            <div class="payment-legend-item">
                                <div class="payment-legend-color legend-cash"></div>
                                <span class="text-cash">Cash</span>
                            </div>
                            <div class="payment-legend-item">
                                <div class="payment-legend-color legend-gcash"></div>
                                <span class="text-gcash">GCash</span>
                            </div>
                            <div class="payment-legend-item">
                                <div class="payment-legend-color legend-bank"></div>
                                <span class="text-bank">Bank Transfer</span>
                            </div>
                            <div class="payment-legend-item">
                                <div class="payment-legend-color legend-check"></div>
                                <span class="text-check">Check</span>
                            </div>
                            <div class="payment-legend-item">
                                <div class="payment-legend-color legend-other"></div>
                                <span class="text-other">Others</span>
                            </div>
                        </div>

                        <!-- Loop through each area -->
                        <?php if (!empty($customers_by_area)): ?>
                            <?php foreach ($customers_by_area as $area => $customer_types): ?>
                            <div class="area-section">
                                <!-- Active Customers for this area -->
                                <?php if (!empty($customer_types['active'])): 
                                    $active_category_totals = calculateCategoryTotals($customer_types['active'], $date_headers, $daily_payments, $savings_deposits, $savings_payments);
                                    $active_column_totals = calculateColumnTotals($customer_types['active'], $date_headers, $daily_payments, $savings_deposits, $savings_payments);
                                ?>
                                <div class="customer-row">
                                    <div class="header">
                                        <img src="assets/images/prime-logo-row.png" alt="Prime City Logo" class="logo">
                                        <p><?php echo htmlspecialchars($area); ?></p>
                                        <?php if (!empty($selected_branch) && $selected_branch != 'all'): ?>
                                        <p>Branch: <?php echo htmlspecialchars($selected_branch); ?></p>
                                        <?php elseif ($selected_branch == 'all'): ?>
                                        <p>Branch: All Branches</p>
                                        <?php else: ?>
                                        <p>Branch: <?php echo htmlspecialchars($selected_branch); ?></p>
                                        <?php endif; ?>
                                        <p><?php echo date('F j, Y', strtotime($start_date)); ?> to <?php echo date('F j, Y', strtotime($end_date)); ?></p>
                                        <?php if ($show_missed_payments): ?>
                                        <p style="color: #dc3545; font-weight: bold;">
                                            <i class="fas fa-exclamation-triangle"></i> Showing Only Customers with Missed Payments
                                        </p>
                                        <?php endif; ?>
                                        <div class="status-header active-header">
                                            ACTIVE CUSTOMERS - AREA: <?php echo htmlspecialchars($area); ?>
                                        </div>
                                    </div>

                                    <table class="payment-table">
                                        <thead>
                                            <tr>
                                                <th rowspan="2" class="col-no">No.</th>
                                                <th rowspan="2" class="col-name">Names</th>
                                                <th rowspan="2" class="col-due-date">Due Date</th>
                                                <th rowspan="2" class="col-balance">Balance</th>
                                                <th rowspan="2" class="col-daily">Daily</th>
                                                <?php foreach ($date_headers as $date): ?>
                                                <th colspan="3" class="col-date-group"><?php echo date('d', strtotime($date)); ?></th>
                                                <?php endforeach; ?>
                                            </tr>
                                            <tr>
                                                <?php foreach ($date_headers as $date): ?>
                                                <th class="col-payment">Daily</th>
                                                <th class="col-payment">Savings</th>
                                                <th class="col-payment">Savings Pay</th>
                                                <?php endforeach; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $counter = 1; ?>
                                            <?php foreach ($customer_types['active'] as $customer): ?>
                                            <tr>
                                                <td><?php echo $counter++; ?></td>
                                                <td class="text-left">
                                                    <?php echo formatName($customer); ?>
                                                    <?php if ($show_missed_payments && isset($customer['missed_payment_count'])): ?>
                                                        <?php echo displayMissedPaymentInfo($customer); ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo !empty($customer['end_date']) ? date('m/d/Y', strtotime($customer['end_date'])) : 'N/A'; ?></td>
                                                <td class="text-right"><?php echo formatNumber($customer['balance']); ?></td>
                                                <td><?php echo formatNumber($customer['daily_payment']); ?></td>
                                                <?php foreach ($date_headers as $date): 
                                                    $daily_payment = getPaymentDisplay($customer['id'], $date, $daily_payments);
                                                    $savings_deposit = getPaymentDisplay($customer['id'], $date, $savings_deposits);
                                                    $savings_payment = getPaymentDisplay($customer['id'], $date, $savings_payments);
                                                ?>
                                                    <td class="text-right <?php echo ($daily_payment && $daily_payment['multiple']) ? 'multiple-payment' : ''; ?>">
                                                        <?php if ($daily_payment) echo $daily_payment['html']; ?>
                                                    </td>
                                                    <td class="text-right <?php echo ($savings_deposit && $savings_deposit['multiple']) ? 'multiple-payment' : ''; ?>">
                                                        <?php if ($savings_deposit) echo $savings_deposit['html']; ?>
                                                    </td>
                                                    <td class="text-right <?php echo ($savings_payment && $savings_payment['multiple']) ? 'multiple-payment' : ''; ?>">
                                                        <?php if ($savings_payment) echo $savings_payment['html']; ?>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                            <?php endforeach; ?>
                                            
                                            <!-- Add 5 blank rows -->
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <tr class="blank-rows">
                                                <td>&nbsp;</td>
                                                <td>&nbsp;</td>
                                                <td>&nbsp;</td>
                                                <td>&nbsp;</td>
                                                <td>&nbsp;</td>
                                                <?php foreach ($date_headers as $date): ?>
                                                <td>&nbsp;</td>
                                                <td>&nbsp;</td>
                                                <td>&nbsp;</td>
                                                <?php endforeach; ?>
                                            </tr>
                                            <?php endfor; ?>
                                            
                                            <!-- Add TOTAL row -->
                                            <tr class="total-row">
                                                <td colspan="2" class="text-left">TOTAL</td>
                                                <td>&nbsp;</td>
                                                <td class="text-right">₱<?php echo calculateTotalBalance($customer_types['active']); ?></td>
                                                <td class="text-right">₱<?php echo calculateTotalDaily($customer_types['active']); ?></td>
                                                <?php foreach ($date_headers as $date): ?>
                                                <td class="text-right"><?php echo formatTotalDisplay($active_column_totals[$date]['daily']); ?></td>
                                                <td class="text-right"><?php echo formatTotalDisplay($active_column_totals[$date]['savings']); ?></td>
                                                <td class="text-right"><?php echo formatTotalDisplay($active_column_totals[$date]['savings_pay']); ?></td>
                                                <?php endforeach; ?>
                                            </tr>
                                        </tbody>
                                    </table>

                                    <div class="totals-row">
                                        <div class="row">
                                            <div class="col-md-3">
                                                <strong>Total Daily Payment:</strong> ₱<?php echo formatNumber($active_category_totals['daily']); ?>
                                            </div>
                                            <div class="col-md-3">
                                                <strong>Total Savings Deposit:</strong> ₱<?php echo formatNumber($active_category_totals['savings']); ?>
                                            </div>
                                            <div class="col-md-3">
                                                <strong>Total Savings Payment:</strong> ₱<?php echo formatNumber($active_category_totals['savings_pay']); ?>
                                            </div>
                                            <div class="col-md-3">
                                                <strong>Total Customers Balance:</strong> ₱<?php echo calculateTotalBalance($customer_types['active']); ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="signature-container">
                                        <div class="signature-line"></div>
                                        <p>Collector's Name & Signature</p>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <!-- Past Due Customers for this area -->
                                <?php if (!empty($customer_types['past_due'])): 
                                    $past_due_category_totals = calculateCategoryTotals($customer_types['past_due'], $date_headers, $daily_payments, $savings_deposits, $savings_payments);
                                    $past_due_column_totals = calculateColumnTotals($customer_types['past_due'], $date_headers, $daily_payments, $savings_deposits, $savings_payments);
                                ?>
                                <div class="customer-row">
                                    <div class="header">
                                        <img src="assets/images/prime-logo-row.png" alt="Prime City Logo" class="logo">
                                        <p><?php echo htmlspecialchars($area); ?></p>
                                        <?php if (!empty($selected_branch) && $selected_branch != 'all'): ?>
                                        <p>Branch: <?php echo htmlspecialchars($selected_branch); ?></p>
                                        <?php elseif ($selected_branch == 'all'): ?>
                                        <p>Branch: All Branches</p>
                                        <?php else: ?>
                                        <p>Branch: <?php echo htmlspecialchars($selected_branch); ?></p>
                                        <?php endif; ?>
                                        <p><?php echo date('F j, Y', strtotime($start_date)); ?> to <?php echo date('F j, Y', strtotime($end_date)); ?></p>
                                        <?php if ($show_missed_payments): ?>
                                        <p style="color: #dc3545; font-weight: bold;">
                                            <i class="fas fa-exclamation-triangle"></i> Showing Only Customers with Missed Payments
                                        </p>
                                        <?php endif; ?>
                                        <div class="status-header past-due-header">
                                            PAST DUE CUSTOMERS - AREA: <?php echo htmlspecialchars($area); ?>
                                        </div>
                                    </div>

                                    <table class="payment-table">
                                        <thead>
                                            <tr>
                                                <th rowspan="2" class="col-no">No.</th>
                                                <th rowspan="2" class="col-name">Names</th>
                                                <th rowspan="2" class="col-due-date">Due Date</th>
                                                <th rowspan="2" class="col-balance">Balance</th>
                                                <th rowspan="2" class="col-daily">Daily</th>
                                                <?php foreach ($date_headers as $date): ?>
                                                <th colspan="3" class="col-date-group"><?php echo date('d', strtotime($date)); ?></th>
                                                <?php endforeach; ?>
                                            </tr>
                                            <tr>
                                                <?php foreach ($date_headers as $date): ?>
                                                <th class="col-payment">Daily</th>
                                                <th class="col-payment">Savings</th>
                                                <th class="col-payment">Savings Pay</th>
                                                <?php endforeach; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $counter = 1; ?>
                                            <?php foreach ($customer_types['past_due'] as $customer): ?>
                                            <tr>
                                                <td><?php echo $counter++; ?></td>
                                                <td class="text-left">
                                                    <?php echo formatName($customer); ?>
                                                    <?php if ($show_missed_payments && isset($customer['missed_payment_count'])): ?>
                                                        <?php echo displayMissedPaymentInfo($customer); ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo !empty($customer['end_date']) ? date('m/d/Y', strtotime($customer['end_date'])) : 'N/A'; ?></td>
                                                <td class="text-right"><?php echo formatNumber($customer['balance']); ?></td>
                                                <td><?php echo formatNumber($customer['daily_payment']); ?></td>
                                                <?php foreach ($date_headers as $date): 
                                                    $daily_payment = getPaymentDisplay($customer['id'], $date, $daily_payments);
                                                    $savings_deposit = getPaymentDisplay($customer['id'], $date, $savings_deposits);
                                                    $savings_payment = getPaymentDisplay($customer['id'], $date, $savings_payments);
                                                ?>
                                                    <td class="text-right <?php echo ($daily_payment && $daily_payment['multiple']) ? 'multiple-payment' : ''; ?>">
                                                        <?php if ($daily_payment) echo $daily_payment['html']; ?>
                                                    </td>
                                                    <td class="text-right <?php echo ($savings_deposit && $savings_deposit['multiple']) ? 'multiple-payment' : ''; ?>">
                                                        <?php if ($savings_deposit) echo $savings_deposit['html']; ?>
                                                    </td>
                                                    <td class="text-right <?php echo ($savings_payment && $savings_payment['multiple']) ? 'multiple-payment' : ''; ?>">
                                                        <?php if ($savings_payment) echo $savings_payment['html']; ?>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                            <?php endforeach; ?>
                                            
                                            <!-- Add 1 blank row -->
                                            <tr class="blank-rows">
                                                <td>&nbsp;</td>
                                                <td>&nbsp;</td>
                                                <td>&nbsp;</td>
                                                <td>&nbsp;</td>
                                                <td>&nbsp;</td>
                                                <?php foreach ($date_headers as $date): ?>
                                                <td>&nbsp;</td>
                                                <td>&nbsp;</td>
                                                <td>&nbsp;</td>
                                                <?php endforeach; ?>
                                            </tr>
                                            
                                            <!-- Add TOTAL row -->
                                            <tr class="total-row">
                                                <td colspan="2" class="text-left">TOTAL</td>
                                                <td>&nbsp;</td>
                                                <td class="text-right">₱<?php echo calculateTotalBalance($customer_types['past_due']); ?></td>
                                                <td class="text-right">₱<?php echo calculateTotalDaily($customer_types['past_due']); ?></td>
                                                <?php foreach ($date_headers as $date): ?>
                                                <td class="text-right"><?php echo formatTotalDisplay($past_due_column_totals[$date]['daily']); ?></td>
                                                <td class="text-right"><?php echo formatTotalDisplay($past_due_column_totals[$date]['savings']); ?></td>
                                                <td class="text-right"><?php echo formatTotalDisplay($past_due_column_totals[$date]['savings_pay']); ?></td>
                                                <?php endforeach; ?>
                                            </tr>
                                        </tbody>
                                    </table>

                                    <div class="totals-row">
                                        <div class="row">
                                            <div class="col-md-3">
                                                <strong>Total Daily Payment:</strong> ₱<?php echo formatNumber($past_due_category_totals['daily']); ?>
                                            </div>
                                            <div class="col-md-3">
                                                <strong>Total Savings Deposit:</strong> ₱<?php echo formatNumber($past_due_category_totals['savings']); ?>
                                            </div>
                                            <div class="col-md-3">
                                                <strong>Total Savings Payment:</strong> ₱<?php echo formatNumber($past_due_category_totals['savings_pay']); ?>
                                            </div>
                                            <div class="col-md-3">
                                                <strong>Total Customers Balance:</strong> ₱<?php echo calculateTotalBalance($customer_types['past_due']); ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="signature-container">
                                        <div class="signature-line"></div>
                                        <p>Collector's Name & Signature</p>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <!-- Completed Customers for this area -->
                                <?php if ($show_completed && !empty($customer_types['completed'])): 
                                    $completed_category_totals = calculateCategoryTotals($customer_types['completed'], $date_headers, $daily_payments, $savings_deposits, $savings_payments);
                                    $completed_column_totals = calculateColumnTotals($customer_types['completed'], $date_headers, $daily_payments, $savings_deposits, $savings_payments);
                                ?>
                                <div class="customer-row">
                                    <div class="header">
                                        <img src="assets/images/prime-logo-row.png" alt="Prime City Logo" class="logo">
                                        <p><?php echo htmlspecialchars($area); ?></p>
                                        <?php if (!empty($selected_branch) && $selected_branch != 'all'): ?>
                                        <p>Branch: <?php echo htmlspecialchars($selected_branch); ?></p>
                                        <?php elseif ($selected_branch == 'all'): ?>
                                        <p>Branch: All Branches</p>
                                        <?php else: ?>
                                        <p>Branch: <?php echo htmlspecialchars($selected_branch); ?></p>
                                        <?php endif; ?>
                                        <p><?php echo date('F j, Y', strtotime($start_date)); ?> to <?php echo date('F j, Y', strtotime($end_date)); ?></p>
                                        <?php if ($show_missed_payments): ?>
                                        <p style="color: #dc3545; font-weight: bold;">
                                            <i class="fas fa-exclamation-triangle"></i> Showing Only Customers with Missed Payments (Before Completion)
                                        </p>
                                        <?php endif; ?>
                                        <div class="status-header completed-header">
                                            COMPLETED CUSTOMERS - AREA: <?php echo htmlspecialchars($area); ?>
                                        </div>
                                    </div>

                                    <table class="payment-table">
                                        <thead>
                                            <tr>
                                                <th rowspan="2" class="col-no">No.</th>
                                                <th rowspan="2" class="col-name">Names</th>
                                                <th rowspan="2" class="col-due-date">Due Date</th>
                                                <th rowspan="2" class="col-balance">Balance</th>
                                                <th rowspan="2" class="col-daily">Daily</th>
                                                <th colspan="2" class="col-last-payment">Last Payment</th>
                                                <?php foreach ($date_headers as $date): ?>
                                                <th colspan="3" class="col-date-group"><?php echo date('d', strtotime($date)); ?></th>
                                                <?php endforeach; ?>
                                            </tr>
                                            <tr>
                                                <th class="col-payment">Amount</th>
                                                <th class="col-payment">Date</th>
                                                <?php foreach ($date_headers as $date): ?>
                                                <th class="col-payment">Daily</th>
                                                <th class="col-payment">Savings</th>
                                                <th class="col-payment">Savings Pay</th>
                                                <?php endforeach; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $counter = 1; ?>
                                            <?php foreach ($customer_types['completed'] as $customer): 
                                                $last_payment_info = getLastPaymentInfo($customer['id'], $customer_last_payments);
                                            ?>
                                            <tr>
                                                <td><?php echo $counter++; ?></td>
                                                <td class="text-left">
                                                    <?php echo formatName($customer); ?>
                                                    <?php if ($show_missed_payments && isset($customer['missed_payment_count'])): ?>
                                                        <?php echo displayMissedPaymentInfo($customer); ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo !empty($customer['end_date']) ? date('m/d/Y', strtotime($customer['end_date'])) : 'N/A'; ?></td>
                                                <td class="text-right"><?php echo formatNumber($customer['balance']); ?></td>
                                                <td><?php echo formatNumber($customer['daily_payment']); ?></td>
                                                <?php if ($last_payment_info): ?>
                                                    <td class="text-right <?php echo getPaymentMethodClass($last_payment_info['method']); ?>">
                                                        ₱<?php echo $last_payment_info['amount']; ?>
                                                    </td>
                                                    <td><?php echo $last_payment_info['date']; ?></td>
                                                <?php else: ?>
                                                    <td colspan="2" class="text-center">No payment data</td>
                                                <?php endif; ?>
                                                <?php foreach ($date_headers as $date): 
                                                    $daily_payment = getPaymentDisplay($customer['id'], $date, $daily_payments);
                                                    $savings_deposit = getPaymentDisplay($customer['id'], $date, $savings_deposits);
                                                    $savings_payment = getPaymentDisplay($customer['id'], $date, $savings_payments);
                                                ?>
                                                    <td class="text-right <?php echo ($daily_payment && $daily_payment['multiple']) ? 'multiple-payment' : ''; ?>">
                                                        <?php if ($daily_payment) echo $daily_payment['html']; ?>
                                                    </td>
                                                    <td class="text-right <?php echo ($savings_deposit && $savings_deposit['multiple']) ? 'multiple-payment' : ''; ?>">
                                                        <?php if ($savings_deposit) echo $savings_deposit['html']; ?>
                                                    </td>
                                                    <td class="text-right <?php echo ($savings_payment && $savings_payment['multiple']) ? 'multiple-payment' : ''; ?>">
                                                        <?php if ($savings_payment) echo $savings_payment['html']; ?>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                            <?php endforeach; ?>
                                            
                                            <!-- Add TOTAL row -->
                                            <tr class="total-row">
                                                <td colspan="2" class="text-left">TOTAL</td>
                                                <td>&nbsp;</td>
                                                <td class="text-right">₱<?php echo calculateTotalBalance($customer_types['completed']); ?></td>
                                                <td class="text-right">₱<?php echo calculateTotalDaily($customer_types['completed']); ?></td>
                                                <td colspan="2">&nbsp;</td>
                                                <?php foreach ($date_headers as $date): ?>
                                                <td class="text-right"><?php echo formatTotalDisplay($completed_column_totals[$date]['daily']); ?></td>
                                                <td class="text-right"><?php echo formatTotalDisplay($completed_column_totals[$date]['savings']); ?></td>
                                                <td class="text-right"><?php echo formatTotalDisplay($completed_column_totals[$date]['savings_pay']); ?></td>
                                                <?php endforeach; ?>
                                            </tr>
                                        </tbody>
                                    </table>

                                    <div class="totals-row">
                                        <div class="row">
                                            <div class="col-md-3">
                                                <strong>Total Daily Payment:</strong> ₱<?php echo formatNumber($completed_category_totals['daily']); ?>
                                            </div>
                                            <div class="col-md-3">
                                                <strong>Total Savings Deposit:</strong> ₱<?php echo formatNumber($completed_category_totals['savings']); ?>
                                            </div>
                                            <div class="col-md-3">
                                                <strong>Total Savings Payment:</strong> ₱<?php echo formatNumber($completed_category_totals['savings_pay']); ?>
                                            </div>
                                            <div class="col-md-3">
                                                <strong>Total Customers Balance:</strong> ₱<?php echo calculateTotalBalance($customer_types['completed']); ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="signature-container">
                                        <div class="signature-line"></div>
                                        <p>Collector's Name & Signature</p>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="alert alert-info text-center">
                                No customers found for the selected filters.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>

            <?php include __DIR__ . '/includes/footer.php'; ?>
        </div>
    </div>

    <script type="application/json" id="payment-history-data"><?php echo json_encode($pageConfig, $jsonFlags); ?></script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script src="js/scripts.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/umd/simple-datatables.min.js" crossorigin="anonymous"></script>
    <script src="assets/js/payment-history.js"></script>
</body>
</html>
