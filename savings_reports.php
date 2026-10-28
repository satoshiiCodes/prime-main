<?php
/**
 * Prime City - Savings transactions report page.
 *
 * Layout only: data comes from actions/savings-reports-actions.php, layout
 * chrome from includes/*, styles from assets/css and behaviour from assets/js.
 */

require_once __DIR__ . '/actions/savings-reports-actions.php';

try {
    $context = sav_context();
} catch (Throwable $e) {
    error_log('[savings-reports] ' . $e->getMessage());
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
        <head>
            <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
            <meta charset="utf-8" />
            <title>Savings Transactions - Unavailable</title>
            <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        </head>
        <body class="bg-light">
            <div class="container py-5">
                <div class="alert alert-danger">
                    <h4 class="alert-heading">The savings report is unavailable</h4>
                    <p class="mb-0">We could not build the report right now. Please try again in a moment.</p>
                </div>
            </div>
        </body>
    </html>
    <?php
    exit;
}

$user = $context['user'];
$user_branch = $context['user_branch'];
$user_account_type = $context['user_account_type'];
$disable_branch_filter = $context['disable_branch_filter'];
$hide_branch_filter = $context['hide_branch_filter'];
$allowed_branches = $context['allowed_branches'];
$selected_branch = $context['selected_branch'];
$branches = $context['branches'];
$time_period = $context['time_period'];
$selected_area = $context['selected_area'];
$selected_transaction_type = $context['selected_transaction_type'];
$selected_date = $context['selected_date'];
$selected_week = $context['selected_week'];
$selected_month = $context['selected_month'];
$selected_year = $context['selected_year'];
$years = isset($context['years']) ? $context['years'] : array();
$start_date = $context['start_date'];
$end_date = $context['end_date'];
$transactions = $context['transactions'];
$summary = $context['summary'];
$error = $context['error'];
$transaction_type_label = $context['transaction_type_label'];

// Shared layout includes read these.
$layout_active = 'savings-reports';
$layout_menu = $context['layout_menu'];

$pageConfig = array(
    'disableBranchFilter' => (bool) $disable_branch_filter,
    'selectedDate'        => $selected_date,
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
        <meta name="description" content="Savings Transactions Reports - Prime City" />
        <meta name="author" content="Prime City" />
        <title>Savings Transactions - Prime City Admin</title>
        <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
        <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        <link href="assets/css/dash.css" rel="stylesheet" />
        <link href="assets/css/dash-ui.css" rel="stylesheet" />
        <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
        <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
        <link href="assets/css/savings-reports-style.css?v=<?php echo is_file(__DIR__ . '/assets/css/savings-reports-style.css') ? filemtime(__DIR__ . '/assets/css/savings-reports-style.css') : 1; ?>" rel="stylesheet" />
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
                                <i class="fas fa-piggy-bank" aria-hidden="true"></i>
                                Prime City &middot; Reports
                            </p>
                            <h1 class="dash-title">Savings Transactions</h1>
                            <p class="dash-sub">Deposits, payments and cash withdrawals across the selected branch and period.</p>
                        </div>
                    </header>
                    
                    <!-- Filters -->
                    <div class="card dash-card mb-4">
                        <header class="dash-card__head">
                            <span class="dash-card__icon"><i class="fas fa-filter" aria-hidden="true"></i></span>
                            <div>
                                <h2 class="dash-card__title">Transaction Filters</h2>
                                <p class="dash-card__hint">Narrow the report by branch, period, area and transaction type</p>
                            </div>
                        </header>
                        <div class="card-body">
                            <form method="get" action="savings_reports.php">
                                <!-- Branch Filter Section -->
                                <?php if (!$hide_branch_filter): ?>
                                <div class="filter-section mb-4">
                                    <div class="filter-row">
                                        <span class="filter-label"><i class="fas fa-code-branch" aria-hidden="true"></i> Filter by Branch:</span>
                                        <div class="branch-filter-dropdown">
                                            <select class="form-select" id="branchFilter" onchange="applyBranchFilter(this.value)" <?php echo $disable_branch_filter ? 'disabled' : ''; ?>>
                                                <?php if ($user_account_type == 'Admin'): ?>
                                                    <option value="" <?php echo empty($selected_branch) ? 'selected' : ''; ?>>All Branches</option>
                                                    <option value="Tuguegarao City" <?php echo $selected_branch == 'Tuguegarao City' ? 'selected' : ''; ?>>Tuguegarao City</option>
                                                    <option value="Gattaran" <?php echo $selected_branch == 'Gattaran' ? 'selected' : ''; ?>>Gattaran</option>
                                                    <option value="Abulug" <?php echo $selected_branch == 'Abulug' ? 'selected' : ''; ?>>Abulug</option>
                                                    <option value="Ilagan" <?php echo $selected_branch == 'Ilagan' ? 'selected' : ''; ?>>Ilagan</option>
                                                <?php elseif ($user_account_type == 'Area Manager'): ?>
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
                                <?php else: ?>
                                    <input type="hidden" name="branch" value="<?php echo htmlspecialchars($selected_branch); ?>">
                                <?php endif; ?>
                                
                                <!-- Main Filter Controls -->
                                <div class="filter-form-row log-filter">
                                    <div class="filter-field">
                                        <label for="period" class="filter-label">Time Period</label>
                                        <select class="form-select" id="period" name="period" onchange="toggleDateFilters()">
                                            <option value="daily" <?= $time_period == 'daily' ? 'selected' : '' ?>>Daily</option>
                                            <option value="weekly" <?= $time_period == 'weekly' ? 'selected' : '' ?>>Weekly</option>
                                            <option value="monthly" <?= $time_period == 'monthly' ? 'selected' : '' ?>>Monthly</option>
                                            <option value="yearly" <?= $time_period == 'yearly' ? 'selected' : '' ?>>Yearly</option>
                                        </select>
                                    </div>
                                    
                                    <div class="filter-field filter-field--dates">
                                        <div class="date-filter <?= $time_period == 'daily' ? 'active' : '' ?>" id="daily-filter">
                                            <label for="date" class="filter-label">Select Date</label>
                                            <input type="date" class="form-control" id="date" name="date" value="<?= $selected_date ?>">
                                        </div>
                                        <div class="date-filter <?= $time_period == 'weekly' ? 'active' : '' ?>" id="weekly-filter">
                                            <label for="week" class="filter-label">Select Week</label>
                                            <input type="week" class="form-control" id="week" name="week" value="<?= $selected_week ?>">
                                        </div>
                                        <div class="date-filter <?= $time_period == 'monthly' ? 'active' : '' ?>" id="monthly-filter">
                                            <label for="month" class="filter-label">Select Month</label>
                                            <input type="month" class="form-control" id="month" name="month" value="<?= $selected_month ?>">
                                        </div>
                                        <div class="date-filter <?= $time_period == 'yearly' ? 'active' : '' ?>" id="yearly-filter">
                                            <label for="year" class="filter-label">Select Year</label>
                                            <select class="form-select" id="year" name="year">
                                                <?php
                                                $year_choices = !empty($years) ? $years : array((int) date('Y'));

                                                if (!in_array((int) $selected_year, $year_choices, true)) {
                                                    $selected_year = (string) $year_choices[0];
                                                }
                                                ?>
                                                <?php foreach ($year_choices as $y): ?>
                                                    <option value="<?= $y ?>" <?= $selected_year == $y ? 'selected' : '' ?>><?= $y ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="filter-field">
                                        <label for="area" class="filter-label">Area</label>
                                        <select class="form-select" id="area" name="area">
                                            <option value="">All Areas</option>
                                            <?php for($i = 1; $i <= 10; $i++): ?>
                                                <option value="Area <?= $i ?>" <?= $selected_area == "Area $i" ? 'selected' : '' ?>>Area <?= $i ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    
                                    <!-- NEW: Transaction Type Filter -->
                                    <div class="filter-field">
                                        <label for="transaction_type" class="filter-label">Transaction Type</label>
                                        <select class="form-select" id="transaction_type" name="transaction_type">
                                            <option value="">All Types</option>
                                            <option value="Deposit" <?= $selected_transaction_type == 'Deposit' ? 'selected' : '' ?>>Deposit</option>
                                            <option value="Withdrawal" <?= $selected_transaction_type == 'Withdrawal' ? 'selected' : '' ?>>Withdrawal (Payment)</option>
                                            <option value="Withdrawal Cash" <?= $selected_transaction_type == 'Withdrawal Cash' ? 'selected' : '' ?>>Withdrawal (Cash)</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="filter-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-filter me-1"></i>Apply Filters
                                    </button>
                                    <a href="savings_reports.php" class="btn btn-secondary">
                                        <i class="fas fa-redo me-1"></i>Reset Filters
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Current Filters Display -->
                    <?php if (!empty($selected_branch) || !empty($selected_area) || !empty($selected_transaction_type)): ?>
                    <div class="scope-chip">
                        <i class="fas fa-building-columns" aria-hidden="true"></i>
                        <span>Currently viewing:</span>
                        <?php if (!empty($selected_branch)): ?>
                            <strong><?php echo htmlspecialchars($selected_branch); ?></strong> branch
                        <?php endif; ?>
                        <?php if (!empty($selected_area)): ?>
                            | Area: <strong><?php echo htmlspecialchars($selected_area); ?></strong>
                        <?php endif; ?>
                        <?php if (!empty($selected_transaction_type)): ?>
                            | Transaction Type: <strong>
                            <?= $transaction_type_label ?>
                            </strong>
                        <?php endif; ?>
                        <?php
                        // Display date range
                        switch($time_period) {
                            case 'daily':
                                echo " | Date: <strong>" . date('F j, Y', strtotime($selected_date)) . "</strong>";
                                break;
                            case 'weekly':
                                if (!empty($selected_week)) {
                                    echo " | Week: <strong>" . date('F j', strtotime($start_date)) . " - " . date('F j, Y', strtotime($end_date)) . "</strong>";
                                }
                                break;
                            case 'monthly':
                                if (!empty($selected_month)) {
                                    echo " | Month: <strong>" . date('F Y', strtotime($selected_month . '-01')) . "</strong>";
                                }
                                break;
                            case 'yearly':
                                if (!empty($selected_year)) {
                                    echo " | Year: <strong>$selected_year</strong>";
                                }
                                break;
                        }
                        ?>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Summary Cards -->
                    <div class="row mb-4">
                        <div class="col-xl-6 col-md-6">
                            <div class="card report-card report-card--deposits">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h5 class="card-title">Total Deposits</h5>
                                            <h2 class="card-text">₱<?= number_format($summary['total_deposits'], 2) ?></h2>
                                            <small><?= number_format($summary['unique_customers']) ?> Active Savers</small>
                                        </div>
                                        <span class="report-card__icon"><i class="fas fa-arrow-down" aria-hidden="true"></i></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-6 col-md-6">
                            <div class="card report-card report-card--withdrawals">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h5 class="card-title">Total Withdrawals</h5>
                                            <h2 class="card-text">₱<?= number_format($summary['total_withdrawals'], 2) ?></h2>
                                            <small><?= number_format($summary['total_transactions']) ?> Total Transactions</small>
                                        </div>
                                        <span class="report-card__icon"><i class="fas fa-arrow-up" aria-hidden="true"></i></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Savings Transactions Table -->
                    <div class="card dash-card mb-4">
                        <header class="dash-card__head">
                            <span class="dash-card__icon"><i class="fas fa-table" aria-hidden="true"></i></span>
                            <div>
                                <h2 class="dash-card__title">Savings Transactions Details</h2>
                                <p class="dash-card__hint">Every savings movement recorded for the current filters</p>
                            </div>
                            <span class="dash-card__badge"><i class="fas fa-list-ol" aria-hidden="true"></i> <?= count($transactions) ?> transactions</span>
                        </header>
                        <div class="card-body">
                            <?php if (!empty($error)): ?>
                                <div class="notice-danger"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i> <?= $error ?></div>
                            <?php elseif (empty($transactions)): ?>
                                <div class="report-empty">
                                    <span class="report-empty__icon"><i class="fas fa-piggy-bank" aria-hidden="true"></i></span>
                                    <h4>No Savings Transactions</h4>
                                    <p>No savings transactions found for the selected filters.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table dash-table report-table" id="savingsTransactionsTable">
                                        <thead>
                                            <tr>
                                                <th>Date & Time</th>
                                                <th>Customer Name</th>
                                                <th>Area</th>
                                                <th>Branch</th>
                                                <th>Transaction Type</th>
                                                <th>Amount</th>
                                                <th>Payment Method</th>
                                                <th>Notes</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($transactions as $trans): 
                                            ?>
                                                <tr>
                                                    <td>
                                                        <?= date('M j, Y g:i A', strtotime($trans['transaction_date'])) ?>
                                                    </td>
                                                    <td><span class="cell-strong"><?= htmlspecialchars($trans['full_name']) ?></span></td>
                                                    <td><span class="area-pill"><?= htmlspecialchars($trans['area']) ?></span></td>
                                                    <td><span class="branch-pill"><?= htmlspecialchars($trans['customer_branch']) ?></span></td>
                                                    <td><span class="type-pill <?= $trans['type_slug'] ?>"><?= $trans['badge_text'] ?></span></td>
                                                    <td class="text-end"><span class="amount amount--<?= $trans['amount_class'] ?>">&#8369;<?= number_format($trans['amount'], 2) ?></span></td>
                                                    <td><span class="cell-meta"><?= !empty($trans['payment_method']) ? htmlspecialchars($trans['payment_method']) : '&mdash;' ?></span></td>
                                                    <td class="cell-notes"><?= !empty($trans['notes']) ? htmlspecialchars($trans['notes']) : '&mdash;' ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>

                                <div class="table-total">
                                    <span class="table-total__label">Total amount for the current filters</span>
                                    <span class="table-total__value">&#8369;<?= number_format($summary['total_deposits'] + $summary['total_withdrawals'], 2) ?></span>
                                </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </main>

                <?php include __DIR__ . '/includes/footer.php'; ?>
            </div>
        </div>

        <script type="application/json" id="savings-reports-data"><?php echo json_encode($pageConfig, $jsonFlags); ?></script>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
        <script src="js/scripts.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/umd/simple-datatables.min.js" crossorigin="anonymous"></script>
        <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
        <script src="assets/js/savings-reports.js?v=<?php echo is_file(__DIR__ . '/assets/js/savings-reports.js') ? filemtime(__DIR__ . '/assets/js/savings-reports.js') : 1; ?>"></script>
    </body>
</html>
