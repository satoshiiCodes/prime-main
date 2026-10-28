<?php
/**
 * Prime City - Financial statement report page.
 *
 * Layout only: data comes from actions/financial-statement-actions.php, layout
 * chrome from includes/*, styles from assets/css and behaviour from assets/js.
 */

require_once __DIR__ . '/actions/financial-statement-actions.php';

try {
    $context = fs_context();
} catch (Throwable $e) {
    error_log('[financial-statement] ' . $e->getMessage());
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
        <head>
            <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
            <meta charset="utf-8" />
            <title>Financial Statement Report - Unavailable</title>
            <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        </head>
        <body class="bg-light">
            <div class="container py-5">
                <div class="alert alert-danger">
                    <h4 class="alert-heading">The report is unavailable</h4>
                    <p class="mb-0">We could not build the financial statement right now. Please try again in a moment.</p>
                </div>
            </div>
        </body>
    </html>
    <?php
    exit;
}

// The report tab queries run against the shared connection.
$conn = $context['conn'];

$user = $context['user'];
$user_branch = $context['user_branch'];
$user_account_type = $context['user_account_type'];
$disable_branch_filter = $context['disable_branch_filter'];
$hide_branch_filter = $context['hide_branch_filter'];
$allowed_branches = $context['allowed_branches'];
$selected_branch = $context['selected_branch'];
$selected_area = $context['selected_area'];
$branches = $context['branches'];
$financialData = $context['financialData'];
$loanPaymentData = $context['loanPaymentData'];

// Shared layout includes read these.
$layout_active = 'financial-statement';
$layout_menu = $context['layout_menu'];

$pageConfig = array(
    'disableBranchFilter' => (bool) $disable_branch_filter,
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
        <meta name="description" content="Prime City financial statement report" />
        <meta name="author" content="Prime City" />
        <title>Financial Statement Report - Prime City Admin</title>
        <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
        <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        <link href="assets/css/dash.css" rel="stylesheet" />
        <link href="assets/css/dash-ui.css" rel="stylesheet" />
        <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
        <link href="assets/css/financial-statement-style.css?v=<?php echo is_file(__DIR__ . '/assets/css/financial-statement-style.css') ? filemtime(__DIR__ . '/assets/css/financial-statement-style.css') : 1; ?>" rel="stylesheet" />
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
                                    <i class="fas fa-chart-pie" aria-hidden="true"></i>
                                    Prime City &middot; Reports
                                </p>
                                <h1 class="dash-title">Financial Statement Report</h1>
                                <p class="dash-sub">Loan, savings, and collection totals for the selected branch, with detailed breakdowns.</p>
                            </div>

                            <div class="dash-head__actions">
                                <a class="btn btn-primary" href="dashboard.php">
                                    <i class="fas fa-arrow-left me-1" aria-hidden="true"></i>
                                    Back to dashboard
                                </a>
                            </div>
                        </header>
                    
                    <!-- Loading Spinner -->
                    <div id="loadingSpinner" class="loading-spinner">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2">Loading financial data...</p>
                    </div>

                    <!-- Filter Section - Show based on user account type -->
                    <?php if (!$hide_branch_filter): ?>
                    <div class="filter-section">
                        <div class="filter-row">
                            <span class="filter-label"><i class="fas fa-code-branch" aria-hidden="true"></i> Filter by Branch:</span>
                            <div class="branch-filter-dropdown">
                                <select class="form-select" id="branchFilter" onchange="applyBranchFilter(this.value)" <?php echo $disable_branch_filter ? 'disabled' : ''; ?>>
                                    <?php if ($user_account_type == 'Admin'): ?>
                                        <!-- Admin can see all branches -->
                                        <option value="all" <?php echo (empty($selected_branch) || $selected_branch == 'all') ? 'selected' : ''; ?>>All Branches</option>
                                        <option value="Tuguegarao City" <?php echo $selected_branch == 'Tuguegarao City' ? 'selected' : ''; ?>>Tuguegarao City</option>
                                        <option value="Gattaran" <?php echo $selected_branch == 'Gattaran' ? 'selected' : ''; ?>>Gattaran</option>
                                        <option value="Abulug" <?php echo $selected_branch == 'Abulug' ? 'selected' : ''; ?>>Abulug</option>
                                        <option value="Ilagan" <?php echo $selected_branch == 'Ilagan' ? 'selected' : ''; ?>>Ilagan</option>
                                    <?php elseif ($user_account_type == 'Area Manager'): ?>
                                        <!-- Area Manager can only see their assigned branches (no "All My Branches" option) -->
                                        <?php if (!empty($allowed_branches)): ?>
                                            <?php foreach ($allowed_branches as $branch): ?>
                                                <option value="<?php echo htmlspecialchars($branch); ?>" <?php echo $selected_branch == $branch ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($branch); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <option value="">No branches assigned</option>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <!-- Other account types that can see branch filter but it's auto-selected -->
                                        <option value="<?php echo htmlspecialchars($selected_branch); ?>" selected>
                                            <?php echo htmlspecialchars($selected_branch); ?>
                                        </option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <?php if ($disable_branch_filter): ?>
                            <small class="text-muted">(Branch filter is automatically set for your account type)</small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Loan Summary Section -->
                    <div class="summary-section">
                        <h3 class="section-title">Loan Summary <?php echo !empty($selected_branch) && $selected_branch != 'all' ? " - " . htmlspecialchars($selected_branch) : ""; ?></h3>
                        <div class="row financial-summary">
                            <div class="col-md-4">
                                <div class="card financial-card total-amount h-100">
                                    <div class="card-body">
                                        <div class="card-header">Total Amount Financed</div>
                                        <div class="card-value">₱<?php echo number_format($financialData['total_amount_financed'], 2); ?></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card financial-card total-interest h-100">
                                    <div class="card-body">
                                        <div class="card-header">Total Interest Rate (in PESO)</div>
                                        <div class="card-value">₱<?php echo number_format($financialData['total_interest_peso'], 2); ?></div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <div class="card financial-card total-customer-net h-100">
                                    <div class="card-body">
                                        <div class="card-header">Total Customer Net</div>
                                        <div class="card-value">₱<?php echo number_format($financialData['total_customer_net'], 2); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if ($user_account_type == 'Admin' || $user_account_type == 'Area Manager'): ?>
                        <div class="row financial-summary">
                            <div class="col-md-6">
                                <div class="card financial-card gross-amount h-100">
                                    <div class="card-body">
                                        <div class="card-header">Gross Amount</div>
                                        <div class="card-value">₱<?php echo number_format($financialData['total_amount_financed'] + $financialData['total_interest_peso'], 2); ?></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card financial-card total-service-fee h-100">
                                    <div class="card-body">
                                        <div class="card-header">Total Service Fee</div>
                                        <div class="card-value">₱<?php echo number_format($financialData['total_service_fee'], 2); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="row financial-summary">
                            <div class="col-md-6">
                                <div class="card financial-card total-balance h-100">
                                    <div class="card-body">
                                        <div class="card-header">Total Outstanding Balance</div>
                                        <div class="card-value">₱<?php echo number_format($financialData['total_balance'], 2); ?></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card financial-card total-collections h-100">
                                    <div class="card-body">
                                        <div class="card-header">Total Collections</div>
                                        <div class="card-value">₱<?php echo number_format($financialData['total_collections'], 2); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Savings Summary Section -->
                    <div class="summary-section">
                        <h3 class="section-title">Savings Summary <?php echo !empty($selected_branch) && $selected_branch != 'all' ? " - " . htmlspecialchars($selected_branch) : ""; ?></h3>
                        <div class="row">
                            <div class="col-md-12">
                                <div class="card financial-card total-savings h-100">
                                    <div class="card-body">
                                        <div class="card-header">Total Customers Savings</div>
                                        <div class="card-value">₱<?php echo number_format($financialData['total_savings'], 2); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Collections Summary Section -->
                    <div class="summary-section">
                        <h3 class="section-title">Collections Summary <?php echo !empty($selected_branch) && $selected_branch != 'all' ? " - " . htmlspecialchars($selected_branch) : ""; ?></h3>
                        <div class="row financial-summary">
                            <div class="col-md-4">
                                <div class="card financial-card daily-collections h-100">
                                    <div class="card-body">
                                        <div class="card-header">Today's Collections</div>
                                        <div class="card-value">₱<?php echo number_format($financialData['daily_collections'], 2); ?></div>
                                        <small class="text-muted"><?php echo date('F j, Y'); ?></small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card financial-card weekly-collections h-100">
                                    <div class="card-body">
                                        <div class="card-header">This Week's Collections</div>
                                        <div class="card-value">₱<?php echo number_format($financialData['weekly_collections'], 2); ?></div>
                                        <small class="text-muted">Week <?php echo date('W'); ?> of <?php echo date('Y'); ?></small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card financial-card monthly-collections h-100">
                                    <div class="card-body">
                                        <div class="card-header">This Month's Collections</div>
                                        <div class="card-value">₱<?php echo number_format($financialData['monthly_collections'], 2); ?></div>
                                        <small class="text-muted"><?php echo date('F Y'); ?></small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Detailed Reports Section -->
                    <div class="card dash-card mb-4">
                        <header class="dash-card__head">
                            <span class="dash-card__icon"><i class="fas fa-table" aria-hidden="true"></i></span>
                            <div>
                                <h2 class="dash-card__title">Detailed Financial Reports<?php echo !empty($selected_branch) && $selected_branch != 'all' ? " - " . htmlspecialchars($selected_branch) : ""; ?></h2>
                                <p class="dash-card__hint">Loan portfolio, savings accounts, and collection breakdowns</p>
                            </div>
                        </header>
                        <div class="card-body">
                            <div class="tabs-bar">
                                <ul class="nav nav-tabs" id="financialTabs" role="tablist">
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link active" id="loans-tab" data-bs-toggle="tab" data-bs-target="#loans" type="button" role="tab">Loan Portfolio</button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="savings-tab" data-bs-toggle="tab" data-bs-target="#savings" type="button" role="tab">Savings Accounts</button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="collections-tab" data-bs-toggle="tab" data-bs-target="#collections" type="button" role="tab">All Collections</button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="daily-collections-tab" data-bs-toggle="tab" data-bs-target="#daily-collections" type="button" role="tab">Daily Collections</button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="weekly-collections-tab" data-bs-toggle="tab" data-bs-target="#weekly-collections" type="button" role="tab">Weekly Collections</button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="monthly-collections-tab" data-bs-toggle="tab" data-bs-target="#monthly-collections" type="button" role="tab">Monthly Collections</button>
                                    </li>
                                </ul>
                                <div class="branch-filter">
                                    <span class="filter-label"><i class="fas fa-building-columns" aria-hidden="true"></i> Current Branch</span>
                                    <span class="branch-filter__value"><?php echo !empty($selected_branch) && $selected_branch != 'all' ? htmlspecialchars($selected_branch) : "All Branches"; ?></span>
                                </div>
                            </div>
                            <div class="tab-content" id="financialTabsContent">
                                <!-- Loan Portfolio Tab -->
                                <div class="tab-pane fade show active" id="loans" role="tabpanel">
                                    <!-- Filter Container for Area and Branch -->
                                    <div class="filter-container">
                                        <form method="get" class="area-filter">
                                            <input type="hidden" name="branch_filter" value="<?php echo htmlspecialchars($selected_branch); ?>">
                                            <div class="filter-field">
                                                <label for="area_filter">Filter by Area</label>
                                                <select name="area_filter" id="area_filter" class="form-select">
                                                    <option value="">All Areas</option>
                                                    <?php for($i = 1; $i <= 10; $i++): ?>
                                                        <option value="Area <?php echo $i; ?>" <?php echo $selected_area == "Area $i" ? 'selected' : ''; ?>>
                                                            Area <?php echo $i; ?>
                                                        </option>
                                                    <?php endfor; ?>
                                                </select>
                                            </div>
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-filter me-1" aria-hidden="true"></i> Apply Area Filter
                                            </button>
                                        </form>

                                    </div>
                                    
                                    <table class="table mt-3 dash-table report-table" id="loansTable">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Customer Name</th>
                                                <th>Area</th>
                                                <th>Branch</th>
                                                <th>Amount Financed</th>
                                                <th>Interest (Peso)</th>
                                                <?php if ($user_account_type == 'Admin' || $user_account_type == 'Area Manager'): ?>
                                                <th>Gross Amount</th>
                                                <th>Service Fee</th>
                                                <?php endif; ?>
                                                <th>Balance</th>
                                                <th>Underpayment</th>
                                                <th>Advance Payment</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
<?php
try {
    $rows = fs_loan_report_rows($conn, $selected_area, $selected_branch);
    if (!empty($rows)) {
        foreach ($rows as $row) {
            $area = getCustomerArea($row['customer_id']);
            $branch = $row['branch'] ?? getCustomerBranch($row['customer_id']);

            // Initialize underpayment and advance payment values
            $underpayment = "₱0.00";
            $advance_payment = "₱0.00";
            $underpayment_class = "";
            $advance_payment_class = "";

            // Only calculate for active loans
            if ($row['status'] == 'active') {
            $payment_data = $loanPaymentData[$row['id']] ?? ['total_payments' => 0, 'payment_count' => 0];
            $total_payments = $payment_data['total_payments'];
            $payment_count = $payment_data['payment_count'];

            // Calculate expected total payment (daily_payment * number of days)
            $expected_total = $row['daily_payment'] * $payment_count;

            // Calculate underpayment and advance payment
            $underpayment_amount = max(0, $expected_total - $total_payments);
            $advance_payment_amount = max(0, $total_payments - $expected_total);

            // Format values
            $underpayment = ($underpayment_amount > 0) ? "₱" . number_format($underpayment_amount, 2) : "₱0.00";
            $advance_payment = ($advance_payment_amount > 0) ? "₱" . number_format($advance_payment_amount, 2) : "₱0.00";

            // Set CSS classes for styling
            $underpayment_class = $underpayment_amount > 0 ? 'underpayment' : '';
            $advance_payment_class = $advance_payment_amount > 0 ? 'advance-payment' : '';
            }

            echo "<tr>";
            echo "<td>" . date('M d, Y', strtotime($row['start_date'])) . "</td>";
            echo "<td>" . htmlspecialchars($row['customer_name']) . "</td>";
            echo "<td>" . htmlspecialchars($area) . "</td>";
            echo "<td>" . htmlspecialchars($branch) . "</td>";
            echo "<td>₱" . number_format($row['amount_financed'], 2) . "</td>";
            echo "<td>₱" . number_format($row['interest_peso'], 2) . "</td>";

            // Only show Gross Amount and Service Fee for Admin and Area Manager
            if ($user_account_type == 'Admin' || $user_account_type == 'Area Manager') {
            echo "<td>₱" . number_format($row['amount_financed'] + $row['interest_peso'], 2) . "</td>";
            echo "<td>₱" . number_format($row['service_fee'], 2) . "</td>";
            }

            echo "<td>₱" . number_format($row['balance'], 2) . "</td>";
            echo "<td class='$underpayment_class'>" . $underpayment . "</td>";
            echo "<td class='$advance_payment_class'>" . $advance_payment . "</td>";
            $status_slug = strtolower((string) $row['status']);
            echo "<td><span class='status-pill status-pill--" . htmlspecialchars($status_slug) . "'>" . ucfirst($row['status']) . "</span></td>";
            echo "</tr>";
        }
    } else {
        echo "<tr><td colspan='";
        echo ($user_account_type == 'Admin' || $user_account_type == 'Area Manager') ? '12' : '10';
        echo "' class='text-center'>No loan records found";
        if (!empty($selected_area)) echo " for area: $selected_area";
        if (!empty($selected_branch) && $selected_branch != 'all') echo " in branch: $selected_branch";
        echo "</td></tr>";
    }
} catch (Exception $e) {
    error_log($e->getMessage());
    echo "<tr><td colspan='";
    echo ($user_account_type == 'Admin' || $user_account_type == 'Area Manager') ? '12' : '10';
    echo "' class='text-center text-danger'>Error loading loan data</td></tr>";
}
?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Savings Accounts Tab -->
                                <div class="tab-pane fade" id="savings" role="tabpanel">
                                    <table class="table mt-3 dash-table report-table" id="savingsTable">
                                        <thead>
                                            <tr>
                                                <th>Account Number</th>
                                                <th>Customer Name</th>
                                                <th>Branch</th>
                                                <th>Current Balance</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
<?php
try {
    $rows = fs_savings_rows($conn, $selected_branch);
    if (!empty($rows)) {
        foreach ($rows as $row) {
            $customer_name = getCustomerName($row['customer_id']);
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['account_number']) . "</td>";
            echo "<td>" . htmlspecialchars($customer_name) . "</td>";
            echo "<td>" . htmlspecialchars($row['branch']) . "</td>";
            echo "<td>₱" . number_format($row['current_balance'], 2) . "</td>";
            $status_slug = strtolower((string) $row['status']);
            echo "<td><span class='status-pill status-pill--" . htmlspecialchars($status_slug) . "'>" . ucfirst($row['status']) . "</span></td>";
            echo "</tr>";
        }
    } else {
        echo "<tr><td colspan='5' class='text-center'>No savings accounts found";
        if (!empty($selected_branch) && $selected_branch != 'all') echo " in branch: $selected_branch";
        echo "</td></tr>";
    }
} catch (Exception $e) {
    error_log($e->getMessage());
    echo "<tr><td colspan='5' class='text-center text-danger'>Error loading savings data</td></tr>";
}
?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- All Collections Tab -->
                                <div class="tab-pane fade" id="collections" role="tabpanel">
                                    <table class="table mt-3 dash-table report-table" id="collectionsTable">
                                        <thead>
                                            <tr>
                                                <th>Loan ID</th>
                                                <th>Customer Name</th>
                                                <th>Collector Name</th>
                                                <th>Branch</th>
                                                <th>Amount</th>
                                                <th>Payment Date</th>
                                                <th>Payment Method</th>
                                            </tr>
                                        </thead>
                                        <tbody>
<?php
try {
    $rows = fs_collections_rows($conn, $selected_branch);
    if (!empty($rows)) {
        foreach ($rows as $row) {
            $customer_name = getCustomerName($row['customer_id']);
            $collector_name = getCollectorName($row['collector_id']);
            echo "<tr>";
            echo "<td>" . $row['loan_id'] . "</td>";
            echo "<td>" . htmlspecialchars($customer_name) . "</td>";
            echo "<td>" . htmlspecialchars($collector_name) . "</td>";
            echo "<td>" . htmlspecialchars($row['branch']) . "</td>";
            echo "<td>₱" . number_format($row['amount'], 2) . "</td>";
            echo "<td>" . date('M d, Y h:i A', strtotime($row['payment_date'])) . "</td>";
            echo "<td>" . ucfirst($row['payment_method']) . "</td>";
            echo "</tr>";
        }
    } else {
        echo "<tr><td colspan='7' class='text-center'>No payment records found";
        if (!empty($selected_branch) && $selected_branch != 'all') echo " in branch: $selected_branch";
        echo "</td></tr>";
    }
} catch (Exception $e) {
    error_log($e->getMessage());
    echo "<tr><td colspan='7' class='text-center text-danger'>Error loading payment data</td></tr>";
}
?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Daily Collections Tab -->
                                <div class="tab-pane fade" id="daily-collections" role="tabpanel">
                                    <table class="table mt-3 dash-table report-table" id="dailyCollectionsTable">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Branch</th>
                                                <th>Total Collections</th>
                                                <th>Number of Payments</th>
                                            </tr>
                                        </thead>
                                        <tbody>
<?php
try {
    $rows = fs_daily_collections($conn, $selected_branch);
    if (!empty($rows)) {
        foreach ($rows as $row) {
            echo "<tr>";
            echo "<td>" . date('F j, Y', strtotime($row['payment_day'])) . "</td>";
            echo "<td>" . htmlspecialchars($row['branch']) . "</td>";
            echo "<td>₱" . number_format($row['daily_total'], 2) . "</td>";
            echo "<td>" . $row['payment_count'] . "</td>";
            echo "</tr>";
        }
    } else {
        echo "<tr><td colspan='4' class='text-center'>No daily collection records found";
        if (!empty($selected_branch) && $selected_branch != 'all') echo " in branch: $selected_branch";
        echo "</td></tr>";
    }
} catch (Exception $e) {
    error_log($e->getMessage());
    echo "<tr><td colspan='4' class='text-center text-danger'>Error loading daily collection data</td></tr>";
}
?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Weekly Collections Tab -->
                                <div class="tab-pane fade" id="weekly-collections" role="tabpanel">
                                    <table class="table mt-3 dash-table report-table" id="weeklyCollectionsTable">
                                        <thead>
                                            <tr>
                                                <th>Week</th>
                                                <th>Year</th>
                                                <th>Branch</th>
                                                <th>Total Collections</th>
                                                <th>Number of Payments</th>
                                            </tr>
                                        </thead>
                                        <tbody>
<?php
try {
    $rows = fs_weekly_collections($conn, $selected_branch);
    if (!empty($rows)) {
        foreach ($rows as $row) {
            echo "<tr>";
            echo "<td>Week " . $row['week'] . "</td>";
            echo "<td>" . $row['year'] . "</td>";
            echo "<td>" . htmlspecialchars($row['branch']) . "</td>";
            echo "<td>₱" . number_format($row['weekly_total'], 2) . "</td>";
            echo "<td>" . $row['payment_count'] . "</td>";
            echo "</tr>";
        }
    } else {
        echo "<tr><td colspan='5' class='text-center'>No weekly collection records found";
        if (!empty($selected_branch) && $selected_branch != 'all') echo " in branch: $selected_branch";
        echo "</td></tr>";
    }
} catch (Exception $e) {
    error_log($e->getMessage());
    echo "<tr><td colspan='5' class='text-center text-danger'>Error loading weekly collection data</td></tr>";
}
?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Monthly Collections Tab -->
                                <div class="tab-pane fade" id="monthly-collections" role="tabpanel">
                                    <table class="table mt-3 dash-table report-table" id="monthlyCollectionsTable">
                                        <thead>
                                            <tr>
                                                <th>Month</th>
                                                <th>Year</th>
                                                <th>Branch</th>
                                                <th>Total Collections</th>
                                                <th>Number of Payments</th>
                                            </tr>
                                        </thead>
                                        <tbody>
<?php
try {
    $rows = fs_monthly_collections($conn, $selected_branch);
    if (!empty($rows)) {
        foreach ($rows as $row) {
            echo "<tr>";
            echo "<td>" . date('F', mktime(0, 0, 0, $row['month'], 1)) . "</td>";
            echo "<td>" . $row['year'] . "</td>";
            echo "<td>" . htmlspecialchars($row['branch']) . "</td>";
            echo "<td>₱" . number_format($row['monthly_total'], 2) . "</td>";
            echo "<td>" . $row['payment_count'] . "</td>";
            echo "</tr>";
        }
    } else {
        echo "<tr><td colspan='5' class='text-center'>No monthly collection records found";
        if (!empty($selected_branch) && $selected_branch != 'all') echo " in branch: $selected_branch";
        echo "</td></tr>";
    }
} catch (Exception $e) {
    error_log($e->getMessage());
    echo "<tr><td colspan='5' class='text-center text-danger'>Error loading monthly collection data</td></tr>";
}
?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>

                <?php include __DIR__ . '/includes/footer.php'; ?>
            </div>
        </div>

        <script type="application/json" id="financial-statement-data"><?php echo json_encode($pageConfig, $jsonFlags); ?></script>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
        <script src="js/scripts.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/umd/simple-datatables.min.js" crossorigin="anonymous"></script>
        <script src="assets/js/financial-statement.js"></script>
    </body>
</html>
