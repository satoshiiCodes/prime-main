<?php
/**
 * Prime City - Cashbook page.
 *
 * Layout only: data comes from actions/cashbook-actions.php, layout chrome from
 * includes/*, styles from assets/css and behaviour from assets/js.
 */

require_once __DIR__ . '/actions/cashbook-actions.php';

try {
    $context = cb_context();
} catch (Throwable $e) {
    error_log('[cashbook] ' . $e->getMessage());
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
        <head>
            <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
            <meta charset="utf-8" />
            <title>Cashbook - Unavailable</title>
            <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        </head>
        <body class="bg-light">
            <div class="container py-5">
                <div class="alert alert-danger">
                    <h4 class="alert-heading">The cashbook is unavailable</h4>
                    <p class="mb-0">We could not prepare the cashbook right now. Please try again in a moment.</p>
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
$selected_branch = $context['selected_branch'];
$start_date = $context['start_date'];
$end_date = $context['end_date'];
$transaction_type = $context['transaction_type'];
$transaction_types = $context['transaction_types'];
$page = $context['page'];
$per_page = $context['per_page'];
$limit_per_page = $context['limit_per_page'];
$payments = $context['payments'];
$total_records = $context['total_records'];
$total_pages = $context['total_pages'];
$total_credit = $context['total_credit'];
$total_debit = $context['total_debit'];
$ending_balance = $context['ending_balance'];
$running_balance = $context['running_balance'];
$error_message = $context['error_message'];
$debug = $context['debug'];

// Shared layout includes read these.
$layout_active = 'cashbook';
$layout_menu = $context['layout_menu'];

$pageConfig = array(
    'page'       => $page,
    'totalPages' => $total_pages,
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
        <meta name="description" content="Prime City cashbook" />
        <meta name="author" content="Prime City" />
        <title>Cashbook - Prime City Admin</title>
        <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
        <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        <link href="assets/css/dash.css" rel="stylesheet" />
        <link href="assets/css/dash-ui.css" rel="stylesheet" />
        <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
        <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
        <link href="assets/css/cashbook-style.css?v=<?php echo is_file(__DIR__ . '/assets/css/cashbook-style.css') ? filemtime(__DIR__ . '/assets/css/cashbook-style.css') : 1; ?>" rel="stylesheet" />
    </head>
    <body class="sb-nav-fixed">
        <div class="loading-overlay" id="loadingOverlay">
            <div class="loading-spinner">
                <i class="fas fa-spinner fa-spin"></i> Loading...
            </div>
        </div>

        <?php include __DIR__ . '/includes/topbar.php'; ?>

        <div id="layoutSidenav">
            <?php include __DIR__ . '/includes/sidebar.php'; ?>

            <div id="layoutSidenav_content">
            <main>
                <div class="dash">
                    <header class="dash-head">
                        <div>
                            <p class="dash-eyebrow">
                                <i class="fas fa-book" aria-hidden="true"></i>
                                Prime City &middot; Finance
                            </p>
                            <h1 class="dash-title">Cashbook</h1>
                            <p class="dash-sub">Every credit, debit and running balance recorded for the branches you can see.</p>
                        </div>
                    </header>
                    
                    <?php if ($debug && !empty($error_message)): ?>
                    <div class="debug-info">
                        <strong>Debug Information:</strong><br>
                        <?php echo $error_message; ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php 
                    $single_branch_account_types = ['Branch Manager', 'Proxy Collector-Cashier', 'Proxy Collector'];
                    $show_branch_filter = !in_array($user_account_type, $single_branch_account_types);
                    ?>
                    
                    <div class="filter-section">
                        <form method="GET" action="" id="filterForm" onsubmit="showLoading(); return true;">
                            <div class="filter-row">
                                <?php if ($show_branch_filter): ?>
                                <div class="filter-field">
                                    <label class="filter-label" for="branch">Branch</label>
                                    <select name="branch" id="branch" class="form-select">
                                        <option value="">All Branches</option>
                                        <?php if ($user_account_type === 'Admin'): ?>
                                            <option value="Tuguegarao City" <?php echo $selected_branch == 'Tuguegarao City' ? 'selected' : ''; ?>>Tuguegarao City</option>
                                            <option value="Gattaran" <?php echo $selected_branch == 'Gattaran' ? 'selected' : ''; ?>>Gattaran</option>
                                            <option value="Abulug" <?php echo $selected_branch == 'Abulug' ? 'selected' : ''; ?>>Abulug</option>
                                            <option value="Ilagan" <?php echo $selected_branch == 'Ilagan' ? 'selected' : ''; ?>>Ilagan</option>
                                        <?php elseif ($user_account_type === 'Area Manager'): ?>
                                            <?php foreach ($available_branches as $branch): ?>
                                                <?php if (!empty($branch)): ?>
                                                    <option value="<?php echo htmlspecialchars($branch); ?>" <?php echo $selected_branch == $branch ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($branch); ?>
                                                    </option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <?php foreach ($available_branches as $branch): ?>
                                                <option value="<?php echo htmlspecialchars($branch); ?>" <?php echo $selected_branch == $branch ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($branch); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <?php else: ?>
                                <input type="hidden" name="branch" value="<?php echo htmlspecialchars($selected_branch); ?>">
                                <?php endif; ?>
                                
                                <div class="filter-field">
                                    <label class="filter-label" for="start_date">Date From</label>
                                    <input type="date" class="form-control" name="start_date" id="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                                </div>

                                <div class="filter-field">
                                    <label class="filter-label" for="end_date">Date To</label>
                                    <input type="date" class="form-control" name="end_date" id="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                                </div>

                                <div class="filter-field">
                                    <label class="filter-label" for="transaction_type">Transaction Type</label>
                                    <select name="transaction_type" id="transaction_type" class="form-select">
                                        <option value="">All Types</option>
                                        <?php foreach ($transaction_types as $key => $label): ?>
                                            <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $transaction_type == $key ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="filter-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-filter me-1"></i> Apply Filters
                                    </button>
                                    <a href="cashbook.php<?php echo !empty($selected_branch) && !$show_branch_filter ? '?branch=' . urlencode($selected_branch) : ''; ?>" class="btn btn-secondary" onclick="showLoading();">
                                        <i class="fas fa-undo me-1"></i> Reset
                                    </a>
                                </div>
                            </div>
                            <input type="hidden" name="page" value="1">
                            <input type="hidden" name="per_page" value="<?php echo $per_page; ?>">
                        </form>
                    </div>
                    
                    <section class="dash-stats dash-stats--three" aria-label="Cashbook summary">
                        <article class="stat stat--green">
                            <div class="stat__body">
                                <p class="stat__label">Total Credit <?php echo !empty($selected_branch) ? "(" . htmlspecialchars($selected_branch) . ")" : "(All Branches)"; ?></p>
                                <p class="stat__value">₱<?php echo number_format($total_credit, 2); ?></p>
                                <p class="stat__meta">Money received in the selected period</p>
                            </div>
                            <span class="stat__icon"><i class="fas fa-arrow-down" aria-hidden="true"></i></span>
                        </article>

                        <article class="stat stat--navy">
                            <div class="stat__body">
                                <p class="stat__label">Total Debit <?php echo !empty($selected_branch) ? "(" . htmlspecialchars($selected_branch) . ")" : "(All Branches)"; ?></p>
                                <p class="stat__value">₱<?php echo number_format($total_debit, 2); ?></p>
                                <p class="stat__meta">Money released in the selected period</p>
                            </div>
                            <span class="stat__icon"><i class="fas fa-arrow-up" aria-hidden="true"></i></span>
                        </article>

                        <article class="stat stat--cyan">
                            <div class="stat__body">
                                <p class="stat__label">Ending Balance <?php echo !empty($selected_branch) ? "(" . htmlspecialchars($selected_branch) . ")" : "(All Branches)"; ?></p>
                                <p class="stat__value">₱<?php echo number_format($ending_balance, 2); ?></p>
                                <p class="stat__meta">Balance carried at the end of the period</p>
                            </div>
                            <span class="stat__icon"><i class="fas fa-money-bill-wave" aria-hidden="true"></i></span>
                        </article>
                    </section>
                    
                    <div class="card dash-card mb-4">
                            <header class="dash-card__head">
                                <span class="dash-card__icon"><i class="fas fa-table" aria-hidden="true"></i></span>
                                <div>
                                    <h2 class="dash-card__title">Transactions History</h2>
                                    <p class="dash-card__hint">
                                        <?php 
                                        $filter_summary = [];
                                        if (!empty($selected_branch)) $filter_summary[] = "Branch: " . htmlspecialchars($selected_branch);
                                        if (!empty($start_date)) $filter_summary[] = "From: " . date("m/d/Y", strtotime($start_date));
                                        if (!empty($end_date)) $filter_summary[] = "To: " . date("m/d/Y", strtotime($end_date));
                                        if (!empty($transaction_type)) $filter_summary[] = "Type: " . htmlspecialchars($transaction_types[$transaction_type] ?? $transaction_type);
                                        echo !empty($filter_summary) ? implode(" | ", $filter_summary) : "All records";
                                        ?>
                                        <span class="dash-card__badge"><i class="fas fa-list-ol" aria-hidden="true"></i> <?php echo number_format($total_records); ?> records</span>
                                    </p>
                                </div>
                            </header>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table dash-table cashbook-table">
                                    <thead>
                                        <tr>
                                            <th>Date and Time</th>
                                            <th>Name</th>
                                            <th>Branch</th>
                                            <th>Type</th>
                                            <th>Description</th>
                                            <th>Debit</th>
                                            <th>Credit</th>
                                            <th>Balance</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($payments)): ?>
                                            <tr>
                                                <td colspan="8">
                                                    <div class="cashbook-empty">
                                                        <span class="cashbook-empty__icon"><i class="fas fa-receipt" aria-hidden="true"></i></span>
                                                        <strong>No transactions found</strong>
                                                        <span>Try a different branch, date range or transaction type.</span>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($payments as $payment): 
                                                $formatted_date = formatDateForDisplay($payment);
                                                $type_display = $payment['type'];
                                                $display_map = [
                                                    'Customer Loan(Cash Released)' => 'Customer Loan (Cash Released)',
                                                    'Deposited' => 'Investment Deposit',
                                                    'Withdraw' => 'Investment Withdrawal',
                                                    'Beginning Balance' => 'Investment Beginning Balance',
                                                    'Other Income' => 'Investment Other Income',
                                                    'Customer Deposit' => 'Savings Deposit',
                                                    'Customer Savings Withdrawal' => 'Savings Withdrawal',
                                                    'Customer Savings Withdrawal (Cash)' => 'Savings Withdrawal (Cash)',
                                                    'Collector Payment' => 'Employee Loan Payment',
                                                    'Cash Advance' => 'Cash Advance',
                                                    'Employee Allowances' => 'Employee Allowances'
                                                ];
                                                $type_display = $display_map[$type_display] ?? $type_display;
                                            ?>
                                            <tr>
                                                <td><span class="cell-meta"><?php echo htmlspecialchars($formatted_date); ?></span></td>
                                                <td><span class="cell-strong"><?php echo htmlspecialchars(getCustomerName($payment)); ?></span></td>
                                                <td><span class="branch-pill"><?php echo htmlspecialchars($payment['branch'] ?? 'N/A'); ?></span></td>
                                                <td><span class="type-pill"><?php echo htmlspecialchars($type_display); ?></span></td>
                                                <td><span class="cell-notes" title="<?php echo htmlspecialchars($payment['notes'] ?? ''); ?>"><?php echo htmlspecialchars($payment['notes'] ?? '-'); ?></span></td>
                                                <td class="text-end"><span class="amount amount--debit"><?php echo (isset($payment['debit']) && $payment['debit'] !== null && $payment['debit'] > 0) ? '&#8369;' . number_format($payment['debit'], 2) : '&mdash;'; ?></span></td>
                                                <td class="text-end"><span class="amount amount--credit"><?php echo (isset($payment['credit']) && $payment['credit'] !== null && $payment['credit'] > 0) ? '&#8369;' . number_format($payment['credit'], 2) : '&mdash;'; ?></span></td>
                                                <td class="text-end"><span class="amount amount--balance">&#8369;<?php echo number_format($payment['balance'] ?? 0, 2); ?></span></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <?php if ($total_pages > 1): ?>
                            <div class="pagination-container">
                                <div class="table-footer__meta">
                                    <div class="records-info">
                                        Showing <?php echo $total_records > 0 ? min(($page - 1) * $limit_per_page + 1, $total_records) : 0; ?> to 
                                        <?php echo min($page * $limit_per_page, $total_records); ?> of 
                                        <?php echo number_format($total_records); ?> records
                                    </div>
                                    <div class="per-page-selector">
                                        <label for="perPageSelect">Rows</label>
                                        <select id="perPageSelect" onchange="changePerPage(this.value)">
                                            <option value="20" <?php echo $per_page == 20 ? 'selected' : ''; ?>>20</option>
                                            <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50</option>
                                            <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100</option>
                                            <option value="200" <?php echo $per_page == 200 ? 'selected' : ''; ?>>200</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <ul class="pagination">
                                    <li class="<?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                        <a href="javascript:void(0)" onclick="goToPage(<?php echo $page - 1; ?>)" aria-label="Previous page"><i class="fas fa-angle-left" aria-hidden="true"></i></a>
                                    </li>
                                    <?php
                                    $start_page = max(1, $page - 2);
                                    $end_page = min($total_pages, $page + 2);
                                    
                                    if ($start_page > 1) {
                                        echo '<li><a href="javascript:void(0)" onclick="goToPage(1)">1</a></li>';
                                        if ($start_page > 2) echo '<li class="disabled"><span>...</span></li>';
                                    }
                                    
                                    for ($i = $start_page; $i <= $end_page; $i++) {
                                        echo '<li class="' . ($i == $page ? 'active' : '') . '">';
                                        echo '<a href="javascript:void(0)" onclick="goToPage(' . $i . ')">' . $i . '</a>';
                                        echo '</li>';
                                    }
                                    
                                    if ($end_page < $total_pages) {
                                        if ($end_page < $total_pages - 1) echo '<li class="disabled"><span>...</span></li>';
                                        echo '<li><a href="javascript:void(0)" onclick="goToPage(' . $total_pages . ')">' . $total_pages . '</a></li>';
                                    }
                                    ?>
                                    <li class="<?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                        <a href="javascript:void(0)" onclick="goToPage(<?php echo $page + 1; ?>)" aria-label="Next page"><i class="fas fa-angle-right" aria-hidden="true"></i></a>
                                    </li>
                                </ul>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </main>

                <?php include __DIR__ . '/includes/footer.php'; ?>
            </div>
        </div>

        <script type="application/json" id="cashbook-data"><?php echo json_encode($pageConfig, $jsonFlags); ?></script>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
        <script src="js/scripts.js"></script>
        <script src="assets/js/cashbook.js?v=<?php echo is_file(__DIR__ . '/assets/js/cashbook.js') ? filemtime(__DIR__ . '/assets/js/cashbook.js') : 1; ?>"></script>
    </body>
</html>
