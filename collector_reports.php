<?php
/**
 * Prime City - Collector reports page.
 *
 * Layout only: data comes from actions/collector-reports-actions.php, layout
 * chrome from includes/*, styles from assets/css and behaviour from assets/js.
 */

require_once __DIR__ . '/actions/collector-reports-actions.php';

try {
    $context = crep_context();
} catch (Throwable $e) {
    error_log('[collector-reports] ' . $e->getMessage());
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
        <head>
            <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
            <meta charset="utf-8" />
            <title>Collector Reports - Unavailable</title>
            <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        </head>
        <body class="bg-light">
            <div class="container py-5">
                <div class="alert alert-danger">
                    <h4 class="alert-heading">The collector report is unavailable</h4>
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
$user_branches = $context['user_branches'];
$show_branch_filter = $context['show_branch_filter'];
$branches = $context['branches'];
$selected_branch = $context['selected_branch'];
$selected_collector = $context['selected_collector'];
$report_type = $context['report_type'];
$date_filter = $context['date_filter'];
$collectors = $context['collectors'];
$reports = $context['reports'];
$summary = $context['summary'];
$collector_name = $context['collector_name'];
$collector_branch = $context['collector_branch'];
$report_total = $context['report_total'];
$report_period_label = $context['report_period_label'];
$year_options = $context['years'];

if (empty($year_options)) {
    $year_options = array((int) date('Y'));
}

// Shared layout includes read these.
$layout_active = 'collector-reports';
$layout_menu = $context['layout_menu'];

$pageConfig = array(
    'showBranchFilter' => (bool) $show_branch_filter,
    'yearsHtml'        => $context['year_options_html'],
    'years'            => array_values($year_options),
    'currentMonth'     => $context['current_month'],
    'currentWeek'      => $context['current_week'],
    'currentDate'      => $context['current_date'],
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
        <meta name="description" content="Prime City collector reports" />
        <meta name="author" content="Prime City" />
        <title>Collector Reports - Prime City Admin</title>
        <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
        <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        <link href="assets/css/dash.css" rel="stylesheet" />
        <link href="assets/css/dash-ui.css" rel="stylesheet" />
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
        <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="assets/css/collector-reports-style.css?v=<?php echo is_file(__DIR__ . '/assets/css/collector-reports-style.css') ? filemtime(__DIR__ . '/assets/css/collector-reports-style.css') : 1; ?>" rel="stylesheet" />
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
                                    <i class="fas fa-user-check" aria-hidden="true"></i>
                                    Prime City &middot; Reports
                                </p>
                                <h1 class="dash-title">Collector Reports</h1>
                                <p class="dash-sub">Pick a collector and a period to review collections, savings and amount financed.</p>
                            </div>
                        </header>
                        
                        <!-- Filter Section -->
                        <div class="card dash-card mb-4">
                            <header class="dash-card__head">
                                <span class="dash-card__icon"><i class="fas fa-filter" aria-hidden="true"></i></span>
                                <div>
                                    <h2 class="dash-card__title">Report Filters</h2>
                                    <p class="dash-card__hint">Pick the collector, report type and period to report on</p>
                                </div>
                            </header>
                            <div class="card-body">
                            <?php if ($show_branch_filter): ?>
                                <!-- Branch Filter Section for Admin and Area Manager -->
                                <div class="filter-section">
                                    <div class="filter-row">
                                        <span class="filter-label"><i class="fas fa-code-branch" aria-hidden="true"></i> Filter by Branch:</span>
                                        <div class="branch-filter-dropdown">
                                            <select class="form-select" id="branchFilter" onchange="applyBranchFilter(this.value)">
                                                <?php if ($user['account_type'] === 'Admin'): ?>
                                                    <option value="all" <?php echo $selected_branch == 'all' ? 'selected' : ''; ?>>All Branches</option>
                                                <?php endif; ?>
                                                
                                                <?php foreach ($user_branches as $branch): ?>
                                                    <option value="<?= htmlspecialchars($branch) ?>" <?php echo $selected_branch == $branch ? 'selected' : ''; ?>>
                                                        <?= htmlspecialchars($branch) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                                </select>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <!-- Hidden branch info for non-Admin/Area Manager users -->
                                <div class="hidden-branch-info">
                                    <strong>Branch:</strong> <?= htmlspecialchars($user_branch) ?>
                                    <input type="hidden" name="branch" id="branch" value="<?= htmlspecialchars($user_branch) ?>">
                                </div>
                            <?php endif; ?>
                            
                            <form method="get" action="collector_reports.php">
                                <?php if ($show_branch_filter): ?>
                                    <input type="hidden" name="branch" id="branch" value="<?= htmlspecialchars($selected_branch) ?>">
                                <?php else: ?>
                                    <input type="hidden" name="branch" id="branch" value="<?= htmlspecialchars($user_branch) ?>">
                                <?php endif; ?>
                                
                                <div class="log-filter">
                                    <div class="filter-field">
                                        <label for="collector_id" class="filter-label">Select Collector</label>
                                        <select class="form-select collector-select" id="collector_id" name="collector_id" required>
                                            <option value="">Search a collector?</option>
                                            <?php 
                                            // Filter collectors based on user type and branch selection
                                            $filtered_collectors = $collectors;
                                            if (!$show_branch_filter) {
                                                // For non-Admin/Area Manager, only show collectors from their branch
                                                $filtered_collectors = array_filter($collectors, function($collector) use ($user_branch) {
                                                    return $collector['branch'] === $user_branch;
                                                });
                                            } elseif ($selected_branch !== 'all') {
                                                // For Admin/Area Manager with specific branch selected
                                                $filtered_collectors = array_filter($collectors, function($collector) use ($selected_branch) {
                                                    return $collector['branch'] === $selected_branch;
                                                });
                                            }
                                            
                                            foreach ($filtered_collectors as $collector): ?>
                                                <option value="<?= $collector['id'] ?>" <?= $selected_collector == $collector['id'] ? 'selected' : '' ?>
                                                    data-name="<?= htmlspecialchars($collector['name']) ?>"
                                                    data-area="<?= htmlspecialchars($collector['area'] ?? '') ?>"
                                                    data-branch="<?= htmlspecialchars($collector['branch']) ?>">
                                                    <?= htmlspecialchars($collector['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="filter-field">
                                        <label for="report_type" class="filter-label">Report Type</label>
                                        <select class="form-select" id="report_type" name="report_type">
                                            <option value="daily" <?= $report_type == 'daily' ? 'selected' : '' ?>>Daily</option>
                                            <option value="weekly" <?= $report_type == 'weekly' ? 'selected' : '' ?>>Weekly</option>
                                            <option value="monthly" <?= $report_type == 'monthly' ? 'selected' : '' ?>>Monthly</option>
                                            <option value="yearly" <?= $report_type == 'yearly' ? 'selected' : '' ?>>Yearly</option>
                                        </select>
                                    </div>
                                    <div class="filter-field" id="date_field">
                                        <label for="date_filter" class="filter-label" id="date_label"><?= $report_type === 'yearly' ? 'Year' : 'Date' ?></label>
                                        <?php if ($report_type == 'yearly'): ?>
                                            <select class="form-select" id="date_filter" name="date_filter" required>
                                                <?php
                                                $selected_year_value = (string) $date_filter;

                                                if (strlen($selected_year_value) !== 4) {
                                                    $selected_year_value = date('Y', strtotime((string) $date_filter) ?: time());
                                                }

                                                $current_year_value = (string) date('Y');

                                                if (!in_array((int) $selected_year_value, $year_options, true)) {
                                                    $selected_year_value = in_array((int) $current_year_value, $year_options, true)
                                                        ? $current_year_value
                                                        : (string) end($year_options);
                                                }

                                                foreach ($year_options as $year):
                                                    ?>
                                                    <option value="<?= $year ?>"<?= $selected_year_value === (string) $year ? ' selected' : '' ?>><?= $year ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php elseif ($report_type == 'monthly'): ?>
                                            <input type="month" class="form-control" id="date_filter" name="date_filter" 
                                                value="<?= htmlspecialchars($date_filter) ?>">
                                        <?php elseif ($report_type == 'weekly'): ?>
                                            <input type="week" class="form-control" id="date_filter" name="date_filter" 
                                                value="<?= htmlspecialchars($date_filter) ?>">
                                        <?php else: ?>
                                            <input type="date" class="form-control" id="date_filter" name="date_filter" 
                                                value="<?= htmlspecialchars($date_filter) ?>">
                                        <?php endif; ?>
                                    </div>
                                    <div class="filter-actions">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-chart-line me-1" aria-hidden="true"></i> Generate Report
                                        </button>
                                    </div>
                                </div>
                            </form>
                            </div>
                        </div>

                        <?php if ($selected_collector): ?>
                            <!-- Debug info (optional - can be removed in production) -->
                            <?php if (count($reports) > 0 && $summary['total_transactions'] == 0): ?>
                                <div class="duplicate-warning">
                                    <strong>Note:</strong> Database query returned 0 transactions, but showing <?= count($reports) ?> transactions from detailed report.
                                </div>
                            <?php endif; ?>
                            
                            <!-- Summary Cards -->
                            <div class="row mb-4">
                                <div class="col-md-4">
                                    <div class="card report-card report-summary h-100">
                                        <div class="card-body">
                                            <h5 class="card-title">Report Summary</h5>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <p class="mb-1"><strong>Report Period:</strong></p>
                                                    <p class="mb-1"><strong>Collector:</strong></p>
                                                    <p class="mb-1"><strong>Branch:</strong></p>
                                                </div>
                                                <div class="col-md-6">
                                                    <p class="mb-1">
                                                        <?= $report_period_label ?>
                                                    </p>
                                                    <p class="mb-1">
                                                        <?= htmlspecialchars($collector_name) ?>
                                                    </p>
                                                    <p class="mb-1"><?= htmlspecialchars($collector_branch) ?></p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card report-card report-card--collected h-100">
                                        <div class="card-body">
                                            <h5 class="card-title">Total Collected</h5>
                                            <h2 class="text-success">₱<?= number_format($report_total, 2) ?></h2>
                                            <p class="text-muted mb-0">Loan Payments</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card report-card report-card--financed h-100">
                                        <div class="card-body">
                                            <h5 class="card-title">Total Amount Financed</h5>
                                            <h2 class="text-info">₱<?= isset($summary['total_amount_financed']) ? number_format($summary['total_amount_financed'], 2) : '0.00' ?></h2>
                                            <p class="text-muted mb-0">New loans started</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="card report-card report-card--transactions h-100">
                                        <div class="card-body">
                                            <h5 class="card-title">Total Transactions</h5>
                                            <h2 class="text-primary"><?= isset($summary['total_transactions']) ? $summary['total_transactions'] : '0' ?></h2>
                                            <p class="text-muted mb-0">All loan payments</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Additional Summary Row -->
                            <div class="row mb-4">
                                <div class="col-md-4">
                                    <div class="card report-card report-card--savings h-100">
                                        <div class="card-body">
                                            <h6 class="card-title">Savings Deposits</h6>
                                            <h4 class="text-success">₱<?= isset($summary['total_savings_deposited']) ? number_format($summary['total_savings_deposited'], 2) : '0.00' ?></h4>
                                            <p class="text-muted mb-0 small"><?= isset($summary['total_savings_transactions']) ? $summary['total_savings_transactions'] : '0' ?> deposit(s)</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card report-card report-card--withdrawal h-100">
                                        <div class="card-body">
                                            <h6 class="card-title">Savings Payment</h6>
                                            <h4 class="text-danger">₱<?= isset($summary['total_savings_withdrawn']) ? number_format($summary['total_savings_withdrawn'], 2) : '0.00' ?></h4>
                                            <p class="text-muted mb-0 small"><?= isset($summary['total_savings_withdrawal_transactions']) ? $summary['total_savings_withdrawal_transactions'] : '0' ?> withdrawal(s)</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card report-card report-card--cash h-100">
                                        <div class="card-body">
                                            <h6 class="card-title">Savings Cash Withdrawal</h6>
                                            <h4 class="text-warning">₱<?= isset($summary['total_savings_withdrawal_cash']) ? number_format($summary['total_savings_withdrawal_cash'], 2) : '0.00' ?></h4>
                                            <p class="text-muted mb-0 small"><?= isset($summary['total_savings_withdrawal_cash_transactions']) ? $summary['total_savings_withdrawal_cash_transactions'] : '0' ?> cash withdrawal(s)</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Detailed Report -->
                            <div class="card dash-card mb-4">
                                <header class="dash-card__head">
                                    <span class="dash-card__icon"><i class="fas fa-table" aria-hidden="true"></i></span>
                                    <div>
                                        <h2 class="dash-card__title">Collection Details</h2>
                                        <p class="dash-card__hint">Every loan payment collected in the selected period</p>
                                    </div>
                                </header>
                                <div class="card-body">
                                    <?php if (!empty($reports)): ?>
                                        <div class="table-responsive">
                                            <table class="table dash-table report-table" id="collectionDetailsTable">
                                                <thead>
                                                    <tr>
                                                        <th>Date</th>
                                                        <th>Customer</th>
                                                        <th>Loan Amount</th>
                                                        <th>Current Balance</th>
                                                        <th>Payment Amount</th>
                                                        <th>Payment Method</th>
                                                        <th>Loan Details</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($reports as $report): ?>
                                                        <tr>
                                                            <td><?= date('M j, Y h:i A', strtotime($report['payment_date'])) ?></td>
                                                            <td><?= htmlspecialchars($report['customer_first_name'] . ' ' . $report['customer_last_name']) ?></td>
                                                            <td>₱<?= number_format($report['total_loan_amount'], 2) ?></td>
                                                            <td>₱<?= number_format($report['balance_after_payment'], 2) ?></td>
                                                            <td>₱<?= number_format($report['amount'], 2) ?></td>
                                                            <td><?= htmlspecialchars($report['payment_method']) ?></td>
                                                            <td>
                                                                <?= $report['loan_term'] ?> days<br>
                                                                <?= date('M j, Y', strtotime($report['start_date'])) ?> to <?= date('M j, Y', strtotime($report['end_date'])) ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                                <tfoot>
                                                    <tr class="table-secondary">
                                                        <td colspan="4" class="text-end"><strong>Totals:</strong></td>
                                                        <td><strong>₱<?= number_format($report_total, 2) ?></strong></td>
                                                        <td colspan="2">
                                                            <strong><?= count($reports) ?> loan transactions</strong>
                                                        </td>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                    <?php else: ?>
                                        <div class="notice-info"><i class="fas fa-circle-info" aria-hidden="true"></i> No loan collections found for the selected period.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="notice-info"><i class="fas fa-circle-info" aria-hidden="true"></i> Please select a collector to generate reports.</div>
                        <?php endif; ?>
                    </div>
                </main>
                <?php include __DIR__ . '/includes/footer.php'; ?>
            </div>
        </div>

        <script type="application/json" id="collector-reports-data"><?php echo json_encode($pageConfig, $jsonFlags); ?></script>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
        <script src="js/scripts.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/umd/simple-datatables.min.js" crossorigin="anonymous"></script>
        <script src="js/datatables-simple-demo.js"></script>
        <script src="assets/js/collector-reports.js?v=<?php echo is_file(__DIR__ . '/assets/js/collector-reports.js') ? filemtime(__DIR__ . '/assets/js/collector-reports.js') : 1; ?>"></script>
    </body>
</html>
