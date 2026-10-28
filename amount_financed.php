<?php
/**
 * Prime City - Amount financed report page.
 *
 * Layout only: data comes from actions/amount-financed-actions.php, layout
 * chrome from includes/*, styles from assets/css and behaviour from assets/js.
 */

require_once __DIR__ . '/actions/amount-financed-actions.php';

try {
    $context = amf_context();
} catch (Throwable $e) {
    error_log('[amount-financed] ' . $e->getMessage());
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
        <head>
            <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
            <meta charset="utf-8" />
            <title>Amount Financed Report - Unavailable</title>
            <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        </head>
        <body class="bg-light">
            <div class="container py-5">
                <div class="alert alert-danger">
                    <h4 class="alert-heading">The report is unavailable</h4>
                    <p class="mb-0">We could not build the amount-financed report right now. Please try again in a moment.</p>
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
$filter_period = $context['filter_period'];
$custom_date = $context['custom_date'];
$week_date = $context['week_date'];
$month_year = $context['month_year'];
$year = $context['year'];
$selected_area = $context['selected_area'];
$date_from = $context['date_from'];
$date_to = $context['date_to'];
$week_start_date = $context['week_start_date'];
$week_end_date = $context['week_end_date'];
$week_start_formatted = $context['week_start_formatted'];
$week_end_formatted = $context['week_end_formatted'];
$areas = $context['areas'];
$financing_data = $context['financing_data'];
$summary = $context['summary'];
$year_options = $context['year_options'];
$current_week = $context['current_week'];
$error = $context['error'];

// Shared layout includes read these.
$layout_active = 'amount-financed';
$layout_menu = $context['layout_menu'];
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
        <meta charset="utf-8" />
        <meta http-equiv="X-UA-Compatible" content="IE=edge" />
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
        <meta name="description" content="Prime City amount financed report" />
        <meta name="author" content="Prime City" />
        <title>Amount Financed Report - Prime City Admin</title>
        <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
        <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        <link href="assets/css/dash.css" rel="stylesheet" />
        <link href="assets/css/dash-ui.css" rel="stylesheet" />
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" />
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
        <link href="assets/css/amount-financed-style.css?v=<?php echo is_file(__DIR__ . '/assets/css/amount-financed-style.css') ? filemtime(__DIR__ . '/assets/css/amount-financed-style.css') : 1; ?>" rel="stylesheet" />
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
                                    <i class="fas fa-money-check-dollar" aria-hidden="true"></i>
                                    Prime City &middot; Reports
                                </p>
                                <h1 class="dash-title">Amount Financed Report</h1>
                                <p class="dash-sub">Active loan principal released for the selected branch, area and period.</p>
                            </div>
                        </header>
                        
                        <!-- Filter Section -->
                        <div class="card dash-card mb-4">
                            <header class="dash-card__head">
                                <span class="dash-card__icon"><i class="fas fa-filter" aria-hidden="true"></i></span>
                                <div>
                                    <h2 class="dash-card__title">Report Filters</h2>
                                    <p class="dash-card__hint">Pick the branch, area and period to report on</p>
                                </div>
                            </header>
                            <div class="card-body">
                            <form method="get" action="amount_financed.php" class="filter-form">
                                <div class="filter-row log-filter">
                                    <?php if ($show_branch_filter): ?>
                                        <div class="filter-group">
                                            <label for="branch" class="filter-label">Branch</label>
                                            <select class="form-select" id="branch" name="branch">
                                                <?php if ($user['account_type'] === 'Admin'): ?>
                                                    <option value="all" <?= $selected_branch == 'all' ? 'selected' : '' ?>>All Branches</option>
                                                <?php endif; ?>
                                                <?php foreach ($user_branches as $branch): ?>
                                                    <option value="<?= htmlspecialchars($branch) ?>" <?= $selected_branch == $branch ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($branch) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    <?php else: ?>
                                        <input type="hidden" name="branch" value="<?= htmlspecialchars($user_branch) ?>">
                                    <?php endif; ?>
                                    
                                    <!-- Area Filter -->
                                    <div class="filter-group">
                                        <label for="area" class="filter-label">Customer Area</label>
                                        <select class="form-select" id="area" name="area">
                                            <option value="all" <?= $selected_area == 'all' ? 'selected' : '' ?>>All Areas</option>
                                            <?php foreach ($areas as $area): ?>
                                                <option value="<?= htmlspecialchars($area) ?>" <?= $selected_area == $area ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($area) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="filter-group">
                                        <label for="filter_period" class="filter-label">Filter Period</label>
                                        <select class="form-select" id="filter_period" name="filter_period" onchange="togglePeriodInputs()">
                                            <option value="daily" <?= $filter_period == 'daily' ? 'selected' : '' ?>>Daily</option>
                                            <option value="weekly" <?= $filter_period == 'weekly' ? 'selected' : '' ?>>Weekly</option>
                                            <option value="monthly" <?= $filter_period == 'monthly' ? 'selected' : '' ?>>Monthly</option>
                                            <option value="yearly" <?= $filter_period == 'yearly' ? 'selected' : '' ?>>Yearly</option>
                                        </select>
                                    </div>

                                    <!-- Daily Input -->
                                    <div class="filter-group period-input-group <?= $filter_period == 'daily' ? 'active' : '' ?>" id="daily_group">
                                        <div>
                                            <label for="custom_date" class="filter-label">Select Date</label>
                                            <input type="date" class="form-control" id="custom_date" name="custom_date" 
                                                value="<?= htmlspecialchars($custom_date) ?>" max="<?= max(date('Y-m-d'), $custom_date) ?>">
                                        </div>
                                    </div>
                                    
                                    <!-- Weekly Input -->
                                    <div class="filter-group period-input-group <?= $filter_period == 'weekly' ? 'active' : '' ?>" id="weekly_group">
                                        <div>
                                            <label for="week" class="filter-label">Select Week</label>
                                            <input type="week" class="form-control" id="week" name="week" 
                                                value="<?= htmlspecialchars($week_date) ?>">
                                            <?php if ($filter_period == 'weekly' && !empty($week_start_formatted) && !empty($week_end_formatted)): ?>
                                                <div class="period-note"><?= $week_start_formatted ?> to <?= $week_end_formatted ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <!-- Monthly Input -->
                                    <div class="filter-group period-input-group <?= $filter_period == 'monthly' ? 'active' : '' ?>" id="monthly_group">
                                        <div>
                                            <label for="month" class="filter-label">Select Month</label>
                                            <input type="month" class="form-control" id="month" name="month" 
                                                value="<?= htmlspecialchars($month_year) ?>" max="<?= max(date('Y-m'), substr((string) $month_year, 0, 7)) ?>">
                                        </div>
                                    </div>
                                    
                                    <!-- Yearly Input -->
                                    <div class="filter-group period-input-group <?= $filter_period == 'yearly' ? 'active' : '' ?>" id="yearly_group">
                                        <div>
                                            <label for="year" class="filter-label">Select Year</label>
                                            <select class="form-select" id="year" name="year">
                                                <?php foreach ($year_options as $year_option): ?>
                                                    <option value="<?= $year_option ?>" <?= $year == $year_option ? 'selected' : '' ?>>
                                                        <?= $year_option ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <!-- Filter Actions -->
                                    <div class="filter-actions">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-filter"></i> Apply
                                        </button>
                                        <a href="amount_financed.php" class="btn btn-secondary">
                                            <i class="fas fa-sync"></i> Reset
                                        </a>
                                    </div>
                                </div>
                            </form>
                            </div>
                        </div>

                        <!-- Summary Statistics -->
                        <div class="row mb-4">
                            <div class="col-xl-4 col-md-6">
                                <div class="card report-card report-card--total h-100">
                                    <div class="card-body">
                                        <h6 class="card-title">Total Active Amount Financed</h6>
                                        <h2>&#8369;<?= number_format($summary['total_financed'], 2) ?></h2>
                                        <p class="card-meta">Principal released on active loans</p>
                                    </div>
                                </div>
                            </div>

                            <div class="col-xl-4 col-md-6">
                                <div class="card report-card report-card--loans h-100">
                                    <div class="card-body">
                                        <h6 class="card-title">Active Loans</h6>
                                        <h2><?= number_format($summary['total_loans']) ?></h2>
                                        <p class="card-meta">Loans currently being repaid</p>
                                    </div>
                                </div>
                            </div>

                            <div class="col-xl-4 col-md-6">
                                <div class="card report-card report-card--average h-100">
                                    <div class="card-body">
                                        <h6 class="card-title">Average Loan Amount</h6>
                                        <h2>&#8369;<?= number_format($summary['avg_loan_amount'], 2) ?></h2>
                                        <p class="card-meta">Average principal per active loan</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Period Info -->
                        <div class="row mb-3">
                            <div class="col-12">
                                <div class="notice-info">
                                    <i class="fas fa-circle-info" aria-hidden="true"></i> Showing active loans for 
                                    <?php if ($filter_period == 'daily'): ?>
                                        <strong><?= date('F j, Y', strtotime($date_from)) ?></strong>
                                    <?php elseif ($filter_period == 'weekly'): ?>
                                        <strong>Week of <?= $week_start_formatted ?> to <?= $week_end_formatted ?></strong>
                                        <?php if (!empty($week_date)): ?>
                                            (Week <?= date('W', strtotime($week_start_date)) ?> of <?= date('Y', strtotime($week_start_date)) ?>)
                                        <?php endif; ?>
                                    <?php elseif ($filter_period == 'monthly'): ?>
                                        <strong><?= date('F Y', strtotime($date_from)) ?></strong>
                                    <?php elseif ($filter_period == 'yearly'): ?>
                                        <strong>Year <?= date('Y', strtotime($date_from)) ?></strong>
                                    <?php endif; ?>
                                    <?php if ($selected_branch != 'all'): ?>
                                        in <strong><?= htmlspecialchars($selected_branch) ?></strong> branch
                                    <?php endif; ?>
                                    <?php if ($selected_area != 'all'): ?>
                                        from <strong><?= htmlspecialchars($selected_area) ?></strong> area
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Detailed Report -->
                        <div class="card dash-card mb-4">
                            <header class="dash-card__head">
                                <span class="dash-card__icon"><i class="fas fa-table" aria-hidden="true"></i></span>
                                <div>
                                    <h2 class="dash-card__title">Active Loan Financing Details</h2>
                                    <p class="dash-card__hint">Active loans matched by the current filters</p>
                                </div>
                                <span class="dash-card__badge"><i class="fas fa-list-ol" aria-hidden="true"></i> <?= count($financing_data) ?> active loans</span>
                            </header>
                            <div class="card-body">
                                <?php if (!empty($financing_data)): ?>
                                    <div class="table-responsive">
                                        <table class="table dash-table report-table" id="financingTable">
                                            <thead>
                                                <tr>
                                                    <th>Start Date</th>
                                                    <th>End Date</th>
                                                    <th>Customer</th>
                                                    <th>Area</th>
                                                    <th>Collector</th>
                                                    <th>Amount Financed</th>
                                                    <th>Balance</th>
                                                    <th>Loan Term</th>
                                                    <th>Branch</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($financing_data as $loan): ?>
                                                    <tr>
                                                        <td><?= date('M j, Y', strtotime($loan['start_date'])) ?></td>
                                                        <td><?= date('M j, Y', strtotime($loan['end_date'])) ?></td>
                                                        <td>
                                                            <span class="cell-strong"><?= htmlspecialchars($loan['formatted_customer_name']) ?></span>
                                                            <div class="cell-meta">ID: <?= $loan['customer_id'] ?></div>
                                                        </td>
                                                        <td><?= htmlspecialchars($loan['customer_area']) ?></td>
                                                        <td>
                                                            <span class="cell-strong"><?= htmlspecialchars($loan['collector_name']) ?></span>
                                                            <div class="cell-meta">ID: <?= $loan['collector_id'] ?></div>
                                                        </td>
                                                        <td class="text-end"><span class="amount amount--financed">&#8369;<?= number_format($loan['amount_financed'], 2) ?></span></td>
                                                        <td class="text-end"><span class="amount amount--balance">&#8369;<?= number_format($loan['balance'], 2) ?></span></td>
                                                        <td class="text-center"><span class="term-pill"><?= $loan['loan_term'] ?> days</span></td>
                                                        <td><span class="branch-pill"><?= htmlspecialchars($loan['branch']) ?></span></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                            <tfoot>
                                                <tr>
                                                    <td colspan="5" class="text-end"><strong>Totals:</strong></td>
                                                    <td class="text-end"><strong>&#8369;<?= number_format($summary['total_financed'], 2) ?></strong></td>
                                                    <td colspan="3">
                                                        <strong><?= $summary['total_loans'] ?> active loans</strong>
                                                        <br>
                                                        <small>Showing only active loans</small>
                                                    </td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                <?php elseif (isset($error)): ?>
                                    <div class="alert alert-danger"><?= $error ?></div>
                                <?php else: ?>
                                    <div class="report-empty">
                                        <span class="report-empty__icon"><i class="fas fa-folder-open" aria-hidden="true"></i></span>
                                        <h4>No Active Financing</h4>
                                        <p>No active financing records found for the selected filters.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </main>

                <?php include __DIR__ . '/includes/footer.php'; ?>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
        <script src="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/umd/simple-datatables.min.js" crossorigin="anonymous"></script>
        <script src="assets/js/amount-financed.js?v=<?php echo is_file(__DIR__ . '/assets/js/amount-financed.js') ? filemtime(__DIR__ . '/assets/js/amount-financed.js') : 1; ?>"></script>
    </body>
</html>
