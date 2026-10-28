<?php
/**
 * Prime City - Area reports page.
 *
 * Layout only: data comes from actions/area-reports-actions.php, layout chrome
 * from includes/*, styles from assets/css and behaviour from assets/js.
 */

require_once __DIR__ . '/actions/area-reports-actions.php';

try {
    $context = ar_context();
} catch (Throwable $e) {
    error_log('[area-reports] ' . $e->getMessage());
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
        <head>
            <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
            <meta charset="utf-8" />
            <title>Area Reports - Unavailable</title>
            <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        </head>
        <body class="bg-light">
            <div class="container py-5">
                <div class="alert alert-danger">
                    <h4 class="alert-heading">The area report is unavailable</h4>
                    <p class="mb-0">We could not build the report right now. Please try again in a moment.</p>
                </div>
            </div>
        </body>
    </html>
    <?php
    exit;
}

// The per-row aggregation runs against the shared connection.
$conn = $context['conn'];

$user = $context['user'];
$branches = $context['branches'];
$user_branch = $context['user_branch'];
$user_account_type = $context['user_account_type'];
$disable_branch_filter = $context['disable_branch_filter'];
$hide_branch_filter = $context['hide_branch_filter'];
$allowed_branches = $context['allowed_branches'];
$selected_branch = $context['selected_branch'];

$time_period = $context['time_period'];
$selected_area = $context['selected_area'];
$selected_date = $context['selected_date'];
$selected_week = $context['selected_week'];
$selected_month = $context['selected_month'];
$selected_year = $context['selected_year'];
$years = isset($context['years']) ? $context['years'] : array();

$report_data = $context['report_data'];
$error = $context['error'];
$total_expenses = $context['total_expenses'];
$total_other_income = $context['total_other_income'];
$other_income_by_area = $context['other_income_by_area'];

$total_amount = $context['total_amount'];
$total_savings = $context['total_savings'];
$total_service_fee = $context['total_service_fee'];
$total_interest_rate = $context['total_interest_rate'];
$total_amount_financed = $context['total_amount_financed'];
$total_gross = $context['total_gross'];
$total_net = $context['total_net'];
$final_total_net = $context['final_total_net'];

// Shared layout includes read these.
$layout_active = 'area-reports';
$layout_menu = $context['layout_menu'];

$pageConfig = array(
    'selectedDate' => $selected_date,
    'areaData'     => $context['area_chart_data'],
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
        <meta name="description" content="Prime City area reports" />
        <meta name="author" content="Prime City" />
        <title>Area Reports - Prime City Admin</title>
        <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
        <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        <link href="assets/css/dash.css" rel="stylesheet" />
        <link href="assets/css/dash-ui.css" rel="stylesheet" />
        <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
        <link href="assets/css/area-reports-style.css?v=<?php echo is_file(__DIR__ . '/assets/css/area-reports-style.css') ? filemtime(__DIR__ . '/assets/css/area-reports-style.css') : 1; ?>" rel="stylesheet" />
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
                                    <i class="fas fa-map-location-dot" aria-hidden="true"></i>
                                    Prime City &middot; Reports
                                </p>
                                <h1 class="dash-title">MVL Performance</h1>
                                <p class="dash-sub">Collections, savings and financing totals broken down per area for the selected period.</p>
                            </div>
                        </header>
                        
                        <!-- Filters -->
                        <div class="card dash-card mb-4">
                            <header class="dash-card__head">
                                <span class="dash-card__icon"><i class="fas fa-filter" aria-hidden="true"></i></span>
                                <div>
                                    <h2 class="dash-card__title">Report Filters</h2>
                                    <p class="dash-card__hint">Choose the branch, period and area to report on</p>
                                </div>
                            </header>
                            <div class="card-body">
                                <form method="get" action="area_reports.php" id="filterForm">
                                    <!-- Branch Filter Section - Show based on user account type -->
                                    <?php if (!$hide_branch_filter): ?>
                                    <div class="filter-section mb-4">
                                        <div class="filter-row">
                                            <span class="filter-label"><i class="fas fa-code-branch" aria-hidden="true"></i> Filter by Branch:</span>
                                            <div class="branch-filter-dropdown">
                                                <select class="form-select" id="branchFilter" name="branch" <?php echo $disable_branch_filter ? 'disabled' : ''; ?>>
                                                    <?php if ($user_account_type == 'Admin'): ?>
                                                        <!-- Admin can see all branches -->
                                                        <option value="" <?php echo empty($selected_branch) ? 'selected' : ''; ?>>All Branches</option>
                                                        <option value="Tuguegarao City" <?php echo $selected_branch == 'Tuguegarao City' ? 'selected' : ''; ?>>Tuguegarao City</option>
                                                        <option value="Gattaran" <?php echo $selected_branch == 'Gattaran' ? 'selected' : ''; ?>>Gattaran</option>
                                                        <option value="Abulug" <?php echo $selected_branch == 'Abulug' ? 'selected' : ''; ?>>Abulug</option>
                                                        <option value="Ilagan" <?php echo $selected_branch == 'Ilagan' ? 'selected' : ''; ?>>Ilagan</option>
                                                    <?php elseif ($user_account_type == 'Area Manager'): ?>
                                                        <!-- Area Manager can only see their assigned branches -->
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
                                    <?php else: ?>
                                        <!-- Hidden input for branch when filter is hidden -->
                                        <input type="hidden" name="branch" value="<?php echo htmlspecialchars($selected_branch); ?>">
                                    <?php endif; ?>
                                    
                                    <!-- Main Filter Controls -->
                                    <div class="filter-form-row log-filter">
                                        <!-- Time Period -->
                                        <div class="filter-field">
                                            <label for="period" class="filter-label">Time Period</label>
                                            <select class="form-select" id="period" name="period" onchange="toggleDateFilters()">
                                                <option value="daily" <?= $time_period == 'daily' ? 'selected' : '' ?>>Daily</option>
                                                <option value="weekly" <?= $time_period == 'weekly' ? 'selected' : '' ?>>Weekly</option>
                                                <option value="monthly" <?= $time_period == 'monthly' ? 'selected' : '' ?>>Monthly</option>
                                                <option value="yearly" <?= $time_period == 'yearly' ? 'selected' : '' ?>>Yearly</option>
                                            </select>
                                        </div>
                                        
                                        <!-- Date Filters -->
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
                                        
                                        <!-- Area Filter -->
                                        <div class="filter-field">
                                            <label for="area" class="filter-label">Area</label>
                                            <select class="form-select" id="area" name="area">
                                                <option value="">All Areas</option>
                                                <?php for($i = 1; $i <= 10; $i++): ?>
                                                    <option value="Area <?= $i ?>" <?= $selected_area == "Area $i" ? 'selected' : '' ?>>Area <?= $i ?></option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <!-- Action Buttons -->
                                    <div class="filter-actions">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-filter me-1"></i>Apply Filters
                                        </button>
                                        <a href="area_reports.php" class="btn btn-secondary">
                                            <i class="fas fa-redo me-1"></i>Reset Filters
                                        </a>
                                    </div>
                                </form>
                            </div>
                        </div>
                        
                        <!-- Current Branch Display (if branch is selected) -->
                        <?php if (!empty($selected_branch)): ?>
                        <div class="scope-chip">
                            <i class="fas fa-building-columns" aria-hidden="true"></i>
                            <span>Currently viewing: <strong><?php echo htmlspecialchars($selected_branch); ?></strong> branch</span>
                            <?php if (!empty($selected_area)): ?>
                                <span class="scope-chip__sep"></span>
                                <span>Area: <strong><?php echo htmlspecialchars($selected_area); ?></strong></span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Summary Cards -->
                        <div class="row mb-4">
                            <div class="col-xl-4 col-md-6">
                                <div class="card report-card report-card--collection">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h5 class="card-title">Total Collection</h5>
                                                <h2 class="card-text">₱<?= number_format($total_amount, 2) ?></h2>
                                            </div>
                                            <i class="fas fa-money-bill-wave fa-3x"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-4 col-md-6">
                                <div class="card report-card report-card--savings">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h5 class="card-title">Total Net Savings</h5>
                                                <h2 class="card-text">₱<?= number_format($total_savings, 2) ?></h2>
                                            </div>
                                            <i class="fas fa-piggy-bank fa-3x"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-4 col-md-6">
                                <div class="card report-card report-card--financed">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h5 class="card-title">Total Amount Financed</h5>
                                                <h2 class="card-text">₱<?= number_format($total_amount_financed, 2) ?></h2>
                                            </div>
                                            <i class="fas fa-hand-holding-usd fa-3x"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-4 col-md-6">
                                <div class="card report-card report-card--interest">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h5 class="card-title">Total Interest Rate</h5>
                                                <h2 class="card-text">₱<?= number_format($total_interest_rate, 2) ?></h2>
                                            </div>
                                            <i class="fas fa-percent fa-3x"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Total Gross Card - Only show for Admin and Area Manager -->
                            <?php if ($user_account_type == 'Admin' || $user_account_type == 'Area Manager'): ?>
                            <div class="col-xl-4 col-md-6">
                                <div class="card report-card report-card--gross">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h5 class="card-title">Total Gross</h5>
                                                <h2 class="card-text">₱<?= number_format($total_gross, 2) ?></h2>
                                            </div>
                                            <i class="fas fa-calculator fa-3x"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Total Service Fee Card - Only show for Admin and Area Manager -->
                            <?php if ($user_account_type == 'Admin' || $user_account_type == 'Area Manager'): ?>
                            <div class="col-xl-4 col-md-6">
                                <div class="card report-card report-card--fee">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h5 class="card-title">Total Service Fee</h5>
                                                <h2 class="card-text">₱<?= number_format($total_service_fee, 2) ?></h2>
                                            </div>
                                            <i class="fas fa-file-invoice-dollar fa-3x"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Total Net Card - Only show for Admin and Area Manager -->
                            <?php if ($user_account_type == 'Admin' || $user_account_type == 'Area Manager'): ?>
                            <div class="col-xl-4 col-md-6">
                                <div class="card report-card report-card--net">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h5 class="card-title">Total Net</h5>
                                                <h2 class="card-text">₱<?= number_format($final_total_net, 2) ?></h2>
                                            </div>
                                            <i class="fas fa-coins fa-3x"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="col-xl-4 col-md-6">
                                <div class="card report-card report-card--other">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h5 class="card-title">Total Other Income (GCash Fees)</h5>
                                                <h2 class="card-text">₱<?= number_format($total_other_income, 2) ?></h2>
                                            </div>
                                            <i class="fas fa-coins fa-3x"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-4 col-md-6">
                                <div class="card report-card report-card--expenses">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h5 class="card-title">Total Expenses (Including Allowances)</h5>
                                                <h2 class="card-text">₱<?= number_format($total_expenses, 2) ?></h2>
                                            </div>
                                            <i class="fas fa-receipt fa-3x"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Area Reports Table -->
                        <div class="card dash-card mb-4">
                            <header class="dash-card__head">
                                <span class="dash-card__icon"><i class="fas fa-table" aria-hidden="true"></i></span>
                                <div>
                                    <h2 class="dash-card__title">Area Collection Details <?php echo !empty($selected_branch) ? " - " . htmlspecialchars($selected_branch) . " Branch" : ""; ?></h2>
                                    <p class="dash-card__hint">Per-area totals for the current filters</p>
                                </div>
                            </header>
                            <div class="card-body">
                                <?php if (!empty($error)): ?>
                                    <div class="notice-danger"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i> <?= $error ?></div>
                                <?php elseif (empty($report_data)): ?>
                                    <div class="report-empty">
                                        <span class="report-empty__icon"><i class="fas fa-folder-open" aria-hidden="true"></i></span>
                                        <h4>No Collection Data</h4>
                                        <p>No collection data found for the selected filters.
                                        <?php if (!empty($selected_branch)) echo "Branch: " . htmlspecialchars($selected_branch); ?>
                                        <?php if (!empty($selected_area)) echo " | Area: " . htmlspecialchars($selected_area); ?>
                                        </p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table dash-table report-table" id="areaReportsTable">
                                            <thead>
                                                <tr>
                                                    <th>Area</th>
                                                    <th>Branch</th>
                                                    <th>Period</th>
                                                    <th>Collection Amount</th>
                                                    <th>Net Savings (Deposits - Withdrawals)</th>
                                                    <th>Total Amount Financed</th>
                                                    <th>Total Interest Rate</th>
                                                    <th>Total Gross</th>
                                                    <th>Total Service Fee</th>
                                                    <th>Total Other Income (GCash Fees)</th>
                                                    <th>Total Net</th>
                                                    <th>Date Range</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($report_data as $data): 
                                                    // Ensure negative values show as 0
                                                    $net_value = $data['total_net'] < 0 ? 0 : $data['total_net'];
                                                    $savings_value = $data['total_savings'] < 0 ? 0 : $data['total_savings'];
                                                ?>
                                                    <tr>
                                                        <td>
                                                            <span class="badge bg-primary area-badge"><?= $data['area'] ?></span>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-info"><?= $data['branch'] ?></span>
                                                        </td>
                                                        <td>
                                                            <?php 
                                                            if ($time_period == 'weekly') {
                                                                echo "Week " . date('W', strtotime($data['start_date']));
                                                            } elseif ($time_period == 'monthly') {
                                                                echo date('F Y', strtotime($data['start_date']));
                                                            } elseif ($time_period == 'yearly') {
                                                                echo $selected_year;
                                                            } else {
                                                                echo date('M j, Y', strtotime($data['period']));
                                                            }
                                                            ?>
                                                        </td>
                                                        <td>₱<?= number_format($data['total_amount'], 2) ?></td>
                                                        <td>₱<?= number_format($savings_value, 2) ?></td>
                                                        <td>₱<?= number_format($data['total_amount_financed'], 2) ?></td>
                                                        <td>₱<?= number_format($data['total_interest_rate'], 2) ?></td>
                                                        <td>₱<?= number_format($data['total_gross'], 2) ?></td>
                                                        <td>₱<?= number_format($data['total_service_fee'], 2) ?></td>
                                                        <td>₱<?= number_format($data['total_other_income'], 2) ?></td>
                                                        <td>₱<?= number_format($net_value, 2) ?></td>
                                                        <td>
                                                            <?= date('M j', strtotime($data['start_date'])) ?> - 
                                                            <?= date('M j, Y', strtotime($data['end_date'])) ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                            <tfoot>
                                                <tr>
                                                    <th colspan="3">Total</th>
                                                    <th>₱<?= number_format($total_amount, 2) ?></th>
                                                    <th>₱<?= number_format($total_savings, 2) ?></th>
                                                    <th>₱<?= number_format($total_amount_financed, 2) ?></th>
                                                    <th>₱<?= number_format($total_interest_rate, 2) ?></th>
                                                    <th>₱<?= number_format($total_gross, 2) ?></th>
                                                    <th>₱<?= number_format($total_service_fee, 2) ?></th>
                                                    <th>₱<?= number_format($total_other_income, 2) ?></th>
                                                    <th>₱<?= number_format($final_total_net, 2) ?></th>
                                                    <th></th>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Charts Section -->
                        <div class="row">
                            <div class="col-lg-6">
                                <div class="card dash-card mb-4">
                                    <header class="dash-card__head">
                                        <span class="dash-card__icon"><i class="fas fa-chart-bar" aria-hidden="true"></i></span>
                                        <div>
                                            <h2 class="dash-card__title">Collection by Area <?php echo !empty($selected_branch) ? " - " . htmlspecialchars($selected_branch) : ""; ?></h2>
                                            <p class="dash-card__hint">Collection amount per area</p>
                                        </div>
                                    </header>
                                    <div class="card-body">
                                        <div class="chart-container">
                                            <canvas id="areaChart" width="100%" height="300"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="card dash-card mb-4">
                                    <header class="dash-card__head">
                                        <span class="dash-card__icon"><i class="fas fa-chart-pie" aria-hidden="true"></i></span>
                                        <div>
                                            <h2 class="dash-card__title">Area Distribution <?php echo !empty($selected_branch) ? " - " . htmlspecialchars($selected_branch) : ""; ?></h2>
                                            <p class="dash-card__hint">Share of collections by area</p>
                                        </div>
                                    </header>
                                    <div class="card-body">
                                        <div class="chart-container">
                                            <canvas id="pieChart" width="100%" height="300"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </main>

                <?php include __DIR__ . '/includes/footer.php'; ?>
            </div>
        </div>

        <script type="application/json" id="area-reports-data"><?php echo json_encode($pageConfig, $jsonFlags); ?></script>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
        <script src="js/scripts.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/umd/simple-datatables.min.js" crossorigin="anonymous"></script>
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
        <script src="assets/js/area-reports.js?v=<?php echo is_file(__DIR__ . '/assets/js/area-reports.js') ? filemtime(__DIR__ . '/assets/js/area-reports.js') : 1; ?>"></script>
    </body>
</html>
