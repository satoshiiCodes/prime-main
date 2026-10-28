<?php
/**
 * Prime City - Automatic penalty update page.
 *
 * Layout only: data comes from actions/run-penalty-update-actions.php, layout
 * chrome from includes/*, styles from assets/css and behaviour from assets/js.
 *
 * The update itself is performed by api/update_all_penalties.php, called
 * over AJAX from the page's script.
 */

require_once __DIR__ . '/actions/run-penalty-update-actions.php';

try {
    $context = rpu_context();
} catch (Throwable $e) {
    error_log('[run-penalty-update] ' . $e->getMessage());
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
        <head>
            <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
            <meta charset="utf-8" />
            <title>Automatic Penalty Update - Unavailable</title>
            <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        </head>
        <body class="bg-light">
            <div class="container py-5">
                <div class="alert alert-danger">
                    <h4 class="alert-heading">The penalty page is unavailable</h4>
                    <p class="mb-0">We could not prepare this page right now. Please try again in a moment.</p>
                </div>
            </div>
        </body>
    </html>
    <?php
    exit;
}

$user = $context['user'];
$user_branches = $context['user_branches'];
$is_restricted_user = $context['is_restricted_user'];
$branch_filter = $context['branch_filter'];
$overdue_count = $context['overdue_count'];
$last_run = $context['last_run'];
$has_permission = $context['has_permission'];

// Shared layout includes read these.
$layout_active = 'run-penalty-update';
$layout_menu = $context['layout_menu'];

$pageConfig = array(
    'overdueCount' => $overdue_count,
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
        <meta name="description" content="Prime City automatic penalty update" />
        <meta name="author" content="Prime City" />
        <title>Automatic Penalty Update - Prime City Admin</title>
        <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
        <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        <link href="assets/css/dash.css" rel="stylesheet" />
        <link href="assets/css/dash-ui.css" rel="stylesheet" />
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
        <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
        <link href="assets/css/run-penalty-update-style.css" rel="stylesheet" />
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
                                    <i class="fas fa-calculator" aria-hidden="true"></i>
                                    Prime City &middot; Loans
                                </p>
                                <h1 class="dash-title">Automatic Penalty Update</h1>
                                <p class="dash-sub">Apply the monthly 5% penalty to every overdue loan in one run.</p>
                            </div>
                        </header>
                        
                        <!-- Your existing dashboard content goes here -->
                        
                        <!-- Automatic Penalty Update Section -->
                        <?php if (isset($has_permission) && $has_permission): ?>
                        <div class="row justify-content-center mt-5">
                            <div class="col-md-10">
                                <div class="card mb-4">
                                    <header class="dash-card__head">
                                        <span class="dash-card__icon"><i class="fas fa-calculator" aria-hidden="true"></i></span>
                                        <div>
                                            <h2 class="dash-card__title">Automatic Penalty Update</h2>
                                            <p class="dash-card__hint">Adds 5% monthly penalties to overdue loans</p>
                                        </div>
                                    </header>
                                    <div class="card-body">
                                        <!-- Branch Filter Section -->
                                        <div class="filter-section <?php echo ($user['account_type'] === 'Branch Manager' || $user['account_type'] === 'Proxy Collector' || $user['account_type'] === 'Proxy Collector-Cashier') ? 'hidden-filter' : ''; ?>">
                                            <div class="filter-row">
                                                <span class="filter-label">Filter by Branch:</span>
                                                <div class="branch-filter-dropdown">
                                                    <select class="form-select" id="branch" name="branch" onchange="applyBranchFilter(this.value)">
                                                        <?php if ($user['account_type'] === 'Admin'): ?>
                                                            <option value="all" <?php echo $branch_filter == 'all' ? 'selected' : ''; ?>>All Branches</option>
                                                            <?php foreach ($user_branches as $branch_option): ?>
                                                                <option value="<?php echo htmlspecialchars($branch_option); ?>" 
                                                                    <?php echo $branch_filter == $branch_option ? 'selected' : ''; ?>>
                                                                    <?php echo htmlspecialchars($branch_option); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        <?php else: ?>
                                                            <?php foreach ($user_branches as $branch_option): ?>
                                                                <option value="<?php echo htmlspecialchars($branch_option); ?>" 
                                                                    <?php echo $branch_filter == $branch_option ? 'selected' : ''; ?>>
                                                                    <?php echo htmlspecialchars($branch_option); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        <?php endif; ?>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="alert alert-info">
                                            <h6><i class="fas fa-info-circle me-2"></i>About This Tool</h6>
                                            <p class="mb-0">
                                                This tool will automatically add 5% monthly penalties to all overdue loans. 
                                                Penalties are calculated based on the remaining balance and are added for each overdue month.
                                                <strong>Loans with automatic penalty disabled will be skipped.</strong>
                                                Loans that already have penalties for the current month will also be skipped.
                                                <?php if (!empty($branch_filter) && $branch_filter !== 'all'): ?>
                                                <br><strong>Currently filtered by:</strong> <?php echo htmlspecialchars($branch_filter); ?> branch
                                                <?php elseif ($branch_filter === 'all' && $user['account_type'] === 'Admin'): ?>
                                                <br><strong>Currently filtered by:</strong> All Branches
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                        
                                        <div class="row mb-4">
                                            <div class="col-md-6">
                                                <div class="card border-warning stat-card">
                                                    <div class="card-body text-center">
                                                        <h6 class="text-warning">Overdue Loans Count</h6>
                                                        <h2 class="text-warning"><?php echo $overdue_count; ?></h2>
                                                        <small class="text-muted">
                                                            Loans eligible for penalties (automatic penalty enabled & no current month penalties)
                                                            <?php if (!empty($branch_filter) && $branch_filter !== 'all'): ?>
                                                            <br>(<?php echo htmlspecialchars($branch_filter); ?> branch)
                                                            <?php elseif ($branch_filter === 'all' && $user['account_type'] === 'Admin'): ?>
                                                            <br>(All Branches)
                                                            <?php endif; ?>
                                                        </small>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="card border-success stat-card">
                                                    <div class="card-body text-center">
                                                        <h6 class="text-success">Last Run</h6>
                                                        <h5 class="text-success"><?php echo $last_run; ?></h5>
                                                        <small class="text-muted">Last automatic penalty update</small>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="text-center mb-4">
                                            <button id="runUpdateBtn" class="btn btn-run btn-lg">
                                                <i class="fas fa-play-circle me-2"></i>
                                                Run Automatic Penalty Update
                                                <?php if (!empty($branch_filter) && $branch_filter !== 'all'): ?>
                                                <br><small>(<?php echo htmlspecialchars($branch_filter); ?> branch)</small>
                                                <?php elseif ($branch_filter === 'all' && $user['account_type'] === 'Admin'): ?>
                                                <br><small>(All Branches)</small>
                                                <?php endif; ?>
                                            </button>
                                        </div>
                                        
                                        <div id="resultsSection" class="results-section" style="display: none;">
                                            <h5 class="mb-3 border-bottom pb-2">
                                                <i class="fas fa-list-alt me-2"></i>
                                                Update Results
                                            </h5>
                                            <div id="resultsContent"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="row justify-content-center mt-5">
                            <div class="col-md-8">
                                <div class="card border-danger">
                                    <header class="dash-card__head">
                                        <span class="dash-card__icon"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i></span>
                                        <div>
                                            <h2 class="dash-card__title">Access Denied</h2>
                                            <p class="dash-card__hint">This tool is limited to Admin and Area Manager accounts</p>
                                        </div>
                                    </header>
                                    <div class="card-body text-center">
                                        <i class="fas fa-lock fa-3x text-danger mb-3"></i>
                                        <h5 class="text-danger">You don't have permission to access this feature</h5>
                                        <p class="text-muted">Only Admin and Area Manager accounts can run automatic penalty updates.</p>
                                        <a href="admin.php" class="btn btn-primary">Return to Dashboard</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                    </div>
                </main>

                <?php include __DIR__ . '/includes/footer.php'; ?>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.js"></script>
        <script src="js/scripts.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/umd/simple-datatables.min.js" crossorigin="anonymous"></script>
        <script src="js/datatables-simple-demo.js"></script>

        <?php if ($has_permission): ?>
        <script type="application/json" id="run-penalty-update-data"><?php echo json_encode($pageConfig, $jsonFlags); ?></script>
        <script src="assets/js/run-penalty-update.js"></script>
        <?php endif; ?>
    </body>
</html>
