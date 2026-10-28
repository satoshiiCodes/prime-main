<?php
/**
 * Prime City - Payroll page.
 *
 * Layout only: data comes from actions/payroll-actions.php, layout chrome from
 * includes/*, styles from assets/css and behaviour from assets/js.
 */

require_once __DIR__ . '/actions/payroll-actions.php';

try {
    $context = pr_context();
} catch (Throwable $e) {
    error_log('[payroll] ' . $e->getMessage());
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
        <head>
            <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
            <meta charset="utf-8" />
            <title>Payroll - Unavailable</title>
            <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        </head>
        <body class="bg-light">
            <div class="container py-5">
                <div class="alert alert-danger">
                    <h4 class="alert-heading">The payroll page is unavailable</h4>
                    <p class="mb-0">We could not prepare this page right now. Please try again in a moment.</p>
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
$available_branches = $context['available_branches'];
$restricted_account_types = $context['restricted_account_types'];
$filter_date_from = $context['filter_date_from'];
$filter_date_to = $context['filter_date_to'];
$filter_branch = $context['filter_branch'];
$attendance_records = $context['attendance_records'];
$filtered_records = $context['filtered_records'];
$summary = $context['summary'];
$error = $context['error'];

// Shared layout includes read these.
$layout_active = 'payroll';
$layout_menu = $context['layout_menu'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <meta name="description" content="Prime City payroll" />
    <meta name="author" content="Prime City" />
    <title>Payroll - Prime City Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
    <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
    <link href="assets/css/dash.css" rel="stylesheet" />
    <link href="assets/css/dash-ui.css" rel="stylesheet" />
    <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
    <link href="assets/css/payroll-style.css" rel="stylesheet" />
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
                                Prime City &middot; Finance
                            </p>
                            <h1 class="dash-title">Payroll</h1>
                            <p class="dash-sub">Attendance records and payroll totals for the branches and dates you filter.</p>
                        </div>
                    </header>
                    
                    <!-- Filter Form -->
                    <div class="card dash-card mb-4">
                        <header class="dash-card__head">
                            <span class="dash-card__icon"><i class="fas fa-filter" aria-hidden="true"></i></span>
                            <div>
                                <h2 class="dash-card__title">Filter Records</h2>
                                <p class="dash-card__hint">Narrow the attendance data by branch and date range.</p>
                            </div>
                        </header>
                        <div class="card-body">
                            <form method="post" class="filter-form" id="filterForm">
                                <!-- Branch Filter Section -->
                                <?php 
                                // Show filter for Admin, Area Manager, and other non-restricted types
                                // Hide filter only for single-branch restricted users
                                $show_filter = !in_array($user_account_type, $restricted_account_types);
                                ?>
                                
                                <?php if ($show_filter && !empty($available_branches)): ?>
                                <div class="filter-section mb-3">
                                    <div class="filter-row">
                                        <span class="filter-label">Filter by Branch:</span>
                                        <div class="branch-filter-dropdown">
                                            <?php
                                            // The payroll filter always offers these four branches.
                                            $payroll_branches = array('Tuguegarao City', 'Abulug', 'Gattaran', 'Ilagan');
                                            ?>
                                            <select class="form-select" name="filter_branch" onchange="this.form.submit()">
                                                <option value="" <?php echo $filter_branch === '' ? 'selected' : ''; ?>>All Branches</option>
                                                <?php foreach ($payroll_branches as $branch_option): ?>
                                                    <option value="<?php echo htmlspecialchars($branch_option); ?>"
                                                        <?php echo $filter_branch === $branch_option ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($branch_option); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <?php elseif (!empty($available_branches)): ?>
                                <!-- Hidden filter for restricted users -->
                                <input type="hidden" name="filter_branch" value="<?php echo htmlspecialchars($filter_branch); ?>">
                                <?php endif; ?>
                                
                                <div class="filter-dates">
                                    <div class="filter-field">
                                        <label for="date_from" class="filter-label">Date From</label>
                                        <input type="date" class="form-control filter-control" id="date_from" name="date_from"
                                               value="<?php echo htmlspecialchars($filter_date_from); ?>">
                                    </div>
                                    <div class="filter-field">
                                        <label for="date_to" class="filter-label">Date To</label>
                                        <input type="date" class="form-control filter-control" id="date_to" name="date_to"
                                               value="<?php echo htmlspecialchars($filter_date_to); ?>">
                                    </div>

                                    <div class="filter-actions">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-filter me-1"></i> Apply Filters
                                        </button>
                                        <a href="payroll.php" class="btn btn-secondary">
                                            <i class="fas fa-undo me-1"></i> Clear Filters
                                        </a>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Summary Cards -->
                    <?php if (!empty($summary)): ?>
                    <section class="dash-stats" aria-label="Payroll summary">
                        <article class="stat stat--navy">
                            <div class="stat__body">
                                <p class="stat__label">Total Records</p>
                                <p class="stat__value"><?php echo $summary['total_records']; ?></p>
                                <p class="stat__meta">Attendance entries in range</p>
                            </div>
                            <span class="stat__icon"><i class="fas fa-clipboard-list" aria-hidden="true"></i></span>
                        </article>

                        <article class="stat stat--green">
                            <div class="stat__body">
                                <p class="stat__label">Present</p>
                                <p class="stat__value"><?php echo $summary['present_count']; ?></p>
                                <p class="stat__meta">Employees marked present</p>
                            </div>
                            <span class="stat__icon"><i class="fas fa-user-check" aria-hidden="true"></i></span>
                        </article>

                        <article class="stat stat--cyan">
                            <div class="stat__body">
                                <p class="stat__label">Absent</p>
                                <p class="stat__value"><?php echo $summary['absent_count']; ?></p>
                                <p class="stat__meta">Employees marked absent</p>
                            </div>
                            <span class="stat__icon"><i class="fas fa-user-times" aria-hidden="true"></i></span>
                        </article>

                        <article class="stat">
                            <div class="stat__body">
                                <p class="stat__label">Late Minutes</p>
                                <p class="stat__value"><?php echo isset($summary['total_late_minutes']) ? $summary['total_late_minutes'] : 0; ?></p>
                                <p class="stat__meta">Total minutes late</p>
                            </div>
                            <span class="stat__icon"><i class="fas fa-clock" aria-hidden="true"></i></span>
                        </article>
                    </section>
                    <?php endif; ?>
                    
                    <!-- Attendance Records Table -->
                    <div class="card dash-card mb-4">
                        <header class="dash-card__head">
                            <span class="dash-card__icon"><i class="fas fa-table" aria-hidden="true"></i></span>
                            <div>
                                <h2 class="dash-card__title">
                                    Attendance Records
                                    <?php if (!empty($filter_branch)): ?>
                                        <span class="badge bg-info ms-2">Branch: <?php echo htmlspecialchars($filter_branch); ?></span>
                                    <?php elseif (strtoupper($user_branch) !== 'ALL BRANCHES' && !empty($available_branches)): ?>
                                        <span class="badge bg-info ms-2">All My Branches</span>
                                    <?php endif; ?>
                                </h2>
                                <p class="dash-card__hint">Daily time in, time out, late minutes and status per employee.</p>
                            </div>
                            <div class="dash-head__actions ms-auto">
                                <button type="button" class="btn btn-primary btn-sm" onclick="generatePayslipPDF()">
                                    <i class="fas fa-file-invoice me-1"></i> Generate Payslip PDF
                                </button>
                                <button type="button" class="btn btn-danger btn-sm" onclick="generatePayrollPDF()">
                                    <i class="fas fa-file-pdf me-1"></i> Generate Payroll PDF
                                </button>
                            </div>
                        </header>
                        <div class="card-body">
                            <?php if (!empty($error)): ?>
                                <div class="alert alert-danger"><?php echo $error; ?></div>
                            <?php endif; ?>
                            
                            <?php if (empty($filtered_records)): ?>
                                <div class="alert alert-info">
                                    No attendance records found. 
                                    <?php if (!empty($filter_date_from) || !empty($filter_date_to) || !empty($filter_branch)): ?>
                                        Try adjusting your filters.
                                    <?php else: ?>
                                        Upload attendance data first.
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table id="attendanceTable" class="table table-striped table-bordered dash-table">
                                        <thead>
                                            <tr>
                                                <th>Employee #</th>
                                                <th>Name</th>
                                                <th>Branch</th>
                                                <th>Date</th>
                                                <th>Time In</th>
                                                <th>Time Out</th>
                                                <th>Late (Min)</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($filtered_records as $record): ?>
                                                <?php
                                                $hasTimeIn = isset($record['before_noon_in']) && !is_null($record['before_noon_in']) && $record['before_noon_in'] !== '';
                                                $hasTimeOut = isset($record['after_noon_out']) && !is_null($record['after_noon_out']) && $record['after_noon_out'] !== '';
                                                ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($record['user_id']); ?></td>
                                                    <td><?php echo htmlspecialchars($record['name']); ?></td>
                                                    <td><?php echo htmlspecialchars($record['department']); ?></td>
                                                    <td><?php echo date('m-d-Y', strtotime($record['date'])); ?></td>
                                                    <td>
                                                        <?php 
                                                        if ($hasTimeIn) {
                                                            echo htmlspecialchars(date('h:i A', strtotime($record['before_noon_in'])));
                                                        } else {
                                                            echo '-';
                                                        }
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        if ($hasTimeOut) {
                                                            echo htmlspecialchars(date('h:i A', strtotime($record['after_noon_out'])));
                                                        } else {
                                                            echo '-';
                                                        }
                                                        ?>
                                                    </td>
                                                    <td><?php echo $record['late_minutes'] ?? '-'; ?></td>
                                                    <td>
                                                        <?php 
                                                        $badge_class = '';
                                                        if ($record['status'] === 'Present') {
                                                            $badge_class = 'badge-present';
                                                        } elseif ($record['status'] === 'Absent') {
                                                            $badge_class = 'badge-absent';
                                                        } elseif ($record['status'] === 'Halfday') {
                                                            $badge_class = 'badge-halfday';
                                                        } elseif ($record['status'] === 'Late/Early') {
                                                            $badge_class = 'badge-late';
                                                        }
                                                        ?>
                                                        <span class="badge <?php echo $badge_class; ?> status-badge"><?php echo htmlspecialchars($record['status']); ?></span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
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
    <script src="js/scripts.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/umd/simple-datatables.min.js" crossorigin="anonymous"></script>
    <script src="assets/js/payroll.js"></script>
</body>
</html>
