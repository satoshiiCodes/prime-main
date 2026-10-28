<?php
/**
 * Prime City - Penalty report page.
 *
 * Layout only: data comes from actions/penalty-report-actions.php, layout chrome
 * from includes/*, styles from assets/css and behaviour from assets/js.
 */

require_once __DIR__ . '/actions/penalty-report-actions.php';

try {
    $context = pnl_context();
} catch (Throwable $e) {
    error_log('[penalty-report] ' . $e->getMessage());
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
        <head>
            <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
            <meta charset="utf-8" />
            <title>Penalty Report - Unavailable</title>
            <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        </head>
        <body class="bg-light">
            <div class="container py-5">
                <div class="alert alert-danger">
                    <h4 class="alert-heading">The penalty report is unavailable</h4>
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
$time_period = $context['time_period'];
$selected_date = $context['selected_date'];
$selected_week = $context['selected_week'];
$selected_month = $context['selected_month'];
$selected_year = $context['selected_year'];
$years = isset($context['years']) ? $context['years'] : array();
$selected_branch = $context['selected_branch'];
$area_filter = $context['area_filter'];
$loan_id_filter = $context['loan_id_filter'];
$penalty_type = $context['penalty_type'];
$date_from = $context['date_from'];
$date_to = $context['date_to'];
$start_date = $context['start_date'];
$end_date = $context['end_date'];
$areas = $context['areas'];
$penalties = $context['penalties'];
$summary = $context['summary'];
$error = $context['error'];

// Shared layout includes read these.
$layout_active = 'penalty-report';
$layout_menu = $context['layout_menu'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <meta name="description" content="Prime City penalty report" />
    <meta name="author" content="Prime City" />
    <title>Penalty Report - Prime City</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
    <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
    <link href="assets/css/dash.css" rel="stylesheet" />
    <link href="assets/css/dash-ui.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet" />
    <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link href="assets/css/penalty-report-style.css?v=<?php echo is_file(__DIR__ . '/assets/css/penalty-report-style.css') ? filemtime(__DIR__ . '/assets/css/penalty-report-style.css') : 1; ?>" rel="stylesheet" />
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
                            <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
                            Prime City &middot; Reports
                        </p>
                        <h1 class="dash-title">Penalty Report</h1>
                        <p class="dash-sub">Comprehensive overview of all penalties and waivers, filterable by branch, area, loan and date.</p>
                    </div>
                </header>
                
                <!-- Summary Cards -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card report-card report-card--penalties">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="bg-primary bg-opacity-25 p-3 rounded me-3">
                                        <i class="fas fa-exclamation-triangle text-primary summary-icon"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-0">Total Penalties</h6>
                                        <p class="mb-0 fw-bold fs-4"><?php echo number_format($summary['total_penalties'] ?? 0); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card report-card report-card--added">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="bg-danger bg-opacity-25 p-3 rounded me-3">
                                        <i class="fas fa-plus-circle text-danger summary-icon"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-0">Total Added</h6>
                                        <p class="mb-0 fw-bold fs-4">₱<?php echo number_format($summary['total_added'] ?? 0, 2); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card report-card report-card--waived">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="bg-success bg-opacity-25 p-3 rounded me-3">
                                        <i class="fas fa-hand-holding-heart text-success summary-icon"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-0">Total Waived</h6>
                                        <p class="mb-0 fw-bold fs-4">₱<?php echo number_format($summary['total_waived_amount'] ?? 0, 2); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card report-card report-card--remaining">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="bg-warning bg-opacity-25 p-3 rounded me-3">
                                        <i class="fas fa-money-bill-wave text-warning summary-icon"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-0">Total Remaining</h6>
                                        <p class="mb-0 fw-bold fs-4">₱<?php echo number_format($summary['total_remaining_amount'] ?? 0, 2); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card report-card report-card--customers">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="bg-info bg-opacity-25 p-3 rounded me-3">
                                        <i class="fas fa-users text-info summary-icon"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-0">Affected Customers</h6>
                                        <p class="mb-0 fw-bold fs-4"><?php echo number_format($summary['affected_customers'] ?? 0); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card report-card report-card--loans">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="bg-secondary bg-opacity-25 p-3 rounded me-3">
                                        <i class="fas fa-file-invoice-dollar text-secondary summary-icon"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-0">Affected Loans</h6>
                                        <p class="mb-0 fw-bold fs-4"><?php echo number_format($summary['affected_loans'] ?? 0); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="card dash-card mb-4">
                    <header class="dash-card__head">
                        <span class="dash-card__icon"><i class="fas fa-filter" aria-hidden="true"></i></span>
                        <div>
                            <h2 class="dash-card__title">Filter Penalties</h2>
                            <p class="dash-card__hint">Narrow the report by branch, period, area, loan or penalty type</p>
                        </div>
                    </header>
                    <div class="card-body">
                    
                    <form method="get" action="penalty_report.php" id="filterForm">
                        <!-- Branch Filter Row -->
                        <?php if ($show_branch_filter): ?>
                        <div class="filter-section">
                            <div class="filter-row">
                                <span class="filter-label"><i class="fas fa-code-branch" aria-hidden="true"></i> Filter by Branch</span>
                                <div class="branch-filter-dropdown">
                                    <select class="form-select" id="branch" name="branch">
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
                            <input type="hidden" name="branch" id="branch" value="<?= htmlspecialchars($user_branch) ?>">
                        <?php endif; ?>
                        
                        <!-- Main Filter Controls (like reference code) -->
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
                                    <?php foreach ($areas as $area): ?>
                                        <option value="<?php echo htmlspecialchars($area); ?>" 
                                            <?php echo ($area_filter === $area) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($area); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Loan ID Filter -->
                            <div class="filter-field">
                                <label for="loan_id" class="filter-label">Loan ID</label>
                                <input type="number" class="form-control" id="loan_id" name="loan_id" 
                                       value="<?php echo htmlspecialchars($loan_id_filter); ?>" 
                                       placeholder="Enter Loan ID">
                            </div>
                            
                            <!-- Penalty Type Filter -->
                            <div class="filter-field">
                                <label for="penalty_type" class="filter-label">Penalty Type</label>
                                <select class="form-select" id="penalty_type" name="penalty_type">
                                    <option value="">All Types</option>
                                    <option value="added" <?php echo ($penalty_type === 'added') ? 'selected' : ''; ?>>Added</option>
                                    <option value="waived" <?php echo ($penalty_type === 'waived') ? 'selected' : ''; ?>>Waived</option>
                                    <option value="partial_waiver" <?php echo ($penalty_type === 'partial_waiver') ? 'selected' : ''; ?>>Partial Waiver</option>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Display current date range -->
                        <div class="date-range-display mt-3">
                            <i class="fas fa-clock me-2"></i>
                            <strong>Filtering period:</strong> 
                            <?php echo date('F d, Y', strtotime($date_from)); ?> to 
                            <?php echo date('F d, Y', strtotime($date_to)); ?>
                            <span class="badge bg-secondary ms-2">
                                <?php echo ucfirst($time_period); ?> view
                            </span>
                        </div>
                        
                        <!-- Action Buttons -->
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter me-1"></i>Apply Filters
                            </button>
                            <a href="penalty_report.php" class="btn btn-secondary">
                                <i class="fas fa-redo me-1"></i>Reset Filters
                            </a>
                        </div>
                    </form>
                    </div>
                </div>
                
                <!-- Penalties Table -->
                <div class="card dash-card">
                    <header class="dash-card__head">
                        <span class="dash-card__icon"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i></span>
                        <div>
                            <h2 class="dash-card__title">Penalty Details</h2>
                            <p class="dash-card__hint">Penalties and waivers matching the current filters</p>
                        </div>
                                                <span class="dash-card__badge"><i class="fas fa-list-ol" aria-hidden="true"></i> <?php echo count($penalties); ?> records</span>
                    </header>
                    <div class="card-body">
                        <?php if (count($penalties) > 0): ?>
                            <div class="table-responsive">
                                <table class="table dash-table report-table" id="penaltiesTable">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Penalty ID</th>
                                            <th>Loan ID</th>
                                            <th>Customer</th>
                                            <th>Branch</th>
                                            <th>Area</th>
                                            <th>Type</th>
                                            <th>Amount Details</th>
                                            <th>Notes</th>
                                            <th>Created By</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($penalties as $penalty): 
                                            $customer_name = htmlspecialchars($penalty['first_name'] . ' ' . $penalty['last_name']);
                                            if (!empty($penalty['middle_name'])) {
                                                $customer_name = htmlspecialchars($penalty['first_name'] . ' ' . $penalty['middle_name'] . ' ' . $penalty['last_name']);
                                            }
                                            if (!empty($penalty['suffix']) && $penalty['suffix'] !== 'None') {
                                                $customer_name .= ' ' . htmlspecialchars($penalty['suffix']);
                                            }
                                            
                                            $has_waiver = $penalty['waived_amount'] > 0;
                                            $is_fully_waived = $penalty['waived_amount'] == $penalty['original_amount'];
                                            $is_partial_waiver = $has_waiver && !$is_fully_waived;
                                            
                                            if ($penalty['type'] === 'waived') {
                                                $row_class = 'penalty-waived';
                                                $badge_class = 'bg-danger';
                                                $badge_text = 'Waived';
                                            } elseif ($is_fully_waived) {
                                                $row_class = 'penalty-waived';
                                                $badge_class = 'bg-success';
                                                $badge_text = 'Fully Waived';
                                            } elseif ($is_partial_waiver) {
                                                $row_class = 'penalty-partial';
                                                $badge_class = 'bg-warning';
                                                $badge_text = 'Partial Waiver';
                                            } else {
                                                $row_class = 'penalty-added';
                                                $badge_class = 'bg-success';
                                                $badge_text = 'Added';
                                            }
                                            
                                            $badge_text_slug = strtolower(str_replace(' ', '-', $badge_text));
                                            $penalty_date = date('M j, Y', strtotime($penalty['created_at']));
                                            $waiver_percentage = $penalty['original_amount'] > 0 ? 
                                                ($penalty['waived_amount'] / $penalty['original_amount']) * 100 : 0;
                                        ?>
                                            <tr class="<?php echo $row_class; ?>">
                                                <td><?php echo $penalty_date; ?></td>
                                                <td>#<?php echo htmlspecialchars($penalty['id']); ?></td>
                                                <td>
                                                    <a href="customer_view_payment.php?id=<?php echo $penalty['customer_id']; ?>" 
                                                       class="text-decoration-none" target="_blank">
                                                        #<?php echo htmlspecialchars($penalty['loan_id']); ?>
                                                    </a>
                                                </td>
                                                <td>
                                                    <div class="customer-name"><?php echo $customer_name; ?></div>
                                                    <div class="loan-info"><?php echo htmlspecialchars($penalty['contact_number']); ?></div>
                                                </td>
                                                <td><?php echo htmlspecialchars($penalty['customer_branch'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($penalty['area'] ?? 'N/A'); ?></td>
                                                <td><span class="status-pill <?php echo 'status-pill--' . $badge_text_slug; ?>"><?php echo $badge_text; ?></span></td>
                                                <td>
                                                    <div class="amount-breakdown">
                                                        <div class="d-flex justify-content-between">
                                                            <span>Original:</span>
                                                            <span class="fw-bold">₱<?php echo number_format($penalty['original_amount'], 2); ?></span>
                                                        </div>
                                                        <?php if ($has_waiver): ?>
                                                            <div class="d-flex justify-content-between">
                                                                <span>Waived:</span>
                                                                <span class="text-danger fw-bold">-₱<?php echo number_format($penalty['waived_amount'], 2); ?></span>
                                                            </div>
                                                            <div class="progress">
                                                                <div class="progress-bar bg-success" role="progressbar" 
                                                                     style="width: <?php echo $waiver_percentage; ?>%"></div>
                                                            </div>
                                                        <?php endif; ?>
                                                        <div class="d-flex justify-content-between mt-1">
                                                            <span>Remaining:</span>
                                                            <span class="fw-bold text-success">₱<?php echo number_format($penalty['remaining_amount'], 2); ?></span>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="notes-column" title="<?php echo htmlspecialchars($penalty['notes'] ?? 'No notes'); ?>">
                                                    <?php echo htmlspecialchars($penalty['notes'] ?? 'No notes'); ?>
                                                    <?php if (!empty($penalty['waiver_reason'])): ?>
                                                        <div class="waiver-info small">
                                                            <strong>Waiver Reason:</strong> <?php echo htmlspecialchars($penalty['waiver_reason']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($penalty['created_by_name'] ?? 'System'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="report-empty">
                                <span class="report-empty__icon"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i></span>
                                <h4>No Penalties Found</h4>
                                <p>No penalties match your current filter criteria.</p>
                                <a href="penalty_report.php" class="btn-empty">
                                    <i class="fas fa-rotate me-2" aria-hidden="true"></i>Clear Filters
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>

            <?php include __DIR__ . '/includes/footer.php'; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/scripts.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/umd/simple-datatables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <script src="assets/js/penalty-report.js?v=<?php echo is_file(__DIR__ . '/assets/js/penalty-report.js') ? filemtime(__DIR__ . '/assets/js/penalty-report.js') : 1; ?>"></script>
</body>
</html>
