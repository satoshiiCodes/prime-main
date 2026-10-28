<?php
/**
 * Prime City - New employee loan page.
 *
 * Layout only: data comes from actions/add-collector-loan-actions.php, every
 * JSON call goes to api/add-collector-loan-endpoint.php, layout chrome from
 * includes/*, styles from assets/css and behaviour from assets/js.
 */

require_once __DIR__ . '/actions/add-collector-loan-actions.php';

try {
    $context = acl_context();
} catch (Throwable $e) {
    error_log('[add-collector-loan] ' . $e->getMessage());
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
        <head>
            <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
            <meta charset="utf-8" />
            <title>Employee Loan - Unavailable</title>
            <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        </head>
        <body class="bg-light">
            <div class="container py-5">
                <div class="alert alert-danger">
                    <h4 class="alert-heading">The employee loan form is unavailable</h4>
                    <p class="mb-0">We could not prepare the form right now. Please try again in a moment.</p>
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
$is_branch_restricted = $context['is_branch_restricted'];
$is_area_manager = $context['is_area_manager'];
$is_admin = $context['is_admin'];
$preselected_branch = $context['preselected_branch'];
$has_multiple_branches = $context['has_multiple_branches'];
$collectors = $context['collectors'];
$success_message = $context['success_message'];
$error_message = $context['error_message'];

// Shared layout includes read these.
$layout_active = 'add-collector-loan';
$layout_menu = $context['layout_menu'];

$pageConfig = array(
    'endpoint'          => $context['endpoint'],
    'preselectedBranch' => $preselected_branch,
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
        <meta name="description" content="Prime City employee loan" />
        <meta name="author" content="Prime City" />
        <title>Employee Loan - Prime City Admin</title>
        <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
        <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        <link href="assets/css/dash.css" rel="stylesheet" />
        <link href="assets/css/dash-ui.css" rel="stylesheet" />
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
        <!-- SweetAlert2 CSS -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
        <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
        <link href="assets/css/add-collector-loan-style.css" rel="stylesheet" />
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
                                    <i class="fas fa-user-tie" aria-hidden="true"></i>
                                    Prime City &middot; Employee Loans
                                </p>
                                <h1 class="dash-title">New Employee Loan</h1>
                                <p class="dash-sub">Record a salary loan for an employee with its term and 15/30 payment.</p>
                            </div>

                            <div class="dash-head__actions">
                                <a class="btn btn-primary" href="employee_lists.php">
                                    <i class="fas fa-arrow-left me-1" aria-hidden="true"></i>
                                    Back to employees
                                </a>
                            </div>
                        </header>
                        
                        <!-- Display success/error messages -->
                        <?php if (!empty($success_message)): ?>
                            <div class="alert alert-success"><?php echo $success_message; ?></div>
                        <?php endif; ?>
                        <?php if (!empty($error_message)): ?>
                            <div class="alert alert-danger"><?php echo $error_message; ?></div>
                        <?php endif; ?>
                        
                        <div class="card registration-card dash-card mb-4">
                            <header class="dash-card__head">
                                <span class="dash-card__icon"><i class="fas fa-file-signature" aria-hidden="true"></i></span>
                                <div>
                                    <h2 class="dash-card__title">Add New Employee Loan</h2>
                                    <p class="dash-card__hint">Branch, employee and loan terms in a single form</p>
                                </div>
                            </header>
                            <div class="card-body">
                                <form action="add_collector_loan.php" method="post" id="collectorLoanForm">
                                    <div class="form-section">
                                        <h5 class="section-title">Employee & Branch</h5>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="branch">Branch:</label>
                                            <select id="branch" name="branch" required <?php echo ($is_branch_restricted && !$is_admin) ? 'disabled' : ''; ?>>
                                                <option value="">Select Branch</option>
                                                <?php if ($is_admin && (in_array('All Branches', $user_branches) || empty($user_branches))): ?>
                                                    <!-- Admin with All Branches access or no branch restrictions -->
                                                    <option value="Tuguegarao City">Tuguegarao City</option>
                                                    <option value="Gattaran">Gattaran</option>
                                                    <option value="Abulug">Abulug</option>
                                                    <option value="Ilagan">Ilagan</option>
                                                <?php elseif (($is_area_manager || $is_admin) && !empty($user_branches)): ?>
                                                    <!-- Area Manager or Admin with specific branches -->
                                                    <?php foreach ($user_branches as $branch): ?>
                                                        <?php if ($branch !== 'All Branches'): ?>
                                                            <option value="<?php echo htmlspecialchars($branch); ?>" 
                                                                <?php echo $preselected_branch === $branch ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($branch); ?>
                                                            </option>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                <?php elseif ($is_branch_restricted && !$is_admin): ?>
                                                    <!-- Restricted accounts (Branch Manager, Proxy Collector, etc.) but not Admin -->
                                                    <option value="<?php echo htmlspecialchars($preselected_branch); ?>" selected>
                                                        <?php echo htmlspecialchars($preselected_branch); ?>
                                                    </option>
                                                <?php else: ?>
                                                    <!-- Regular users can choose any branch -->
                                                    <option value="Tuguegarao City">Tuguegarao City</option>
                                                    <option value="Gattaran">Gattaran</option>
                                                    <option value="Abulug">Abulug</option>
                                                    <option value="Ilagan">Ilagan</option>
                                                <?php endif; ?>
                                            </select>
                                            <?php if ($is_branch_restricted && !$is_admin): ?>
                                                <input type="hidden" name="branch" value="<?php echo htmlspecialchars($preselected_branch); ?>">
                                                <div class="calculation-note">
                                                    Branch is automatically set to your assigned branch 
                                                    <?php if (count($user_branches) > 1): ?>
                                                        (<?php echo htmlspecialchars(implode(', ', $user_branches)); ?> - first branch selected)
                                                    <?php else: ?>
                                                        (<?php echo htmlspecialchars($preselected_branch); ?>)
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="form-group">
                                            <label for="collector_name">Employee Name:</label>
                                            <select id="collector_name" name="collector_name" class="select2-custom" required>
                                                <option value="">Select Branch First</option>
                                            </select>
                                            <div class="loading-spinner" id="collector_loading">
                                                <i class="fas fa-spinner fa-spin"></i> Loading employees...
                                            </div>
                                            <div class="calculation-note" id="collector_note">Only active employees are shown. Please select a branch first</div>
                                        </div>
                                    </div>
                                    
                                    <div class="form-section">
                                        <h5 class="section-title">Loan Terms</h5>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="loan_term">Loan Term:</label>
                                            <select id="loan_term" name="loan_term" required>
                                                <option value="6">3 Months (90 days)</option>
                                                <option value="8">4 Months (120 days)</option>
                                                <option value="10">5 Months (150 days)</option>
                                                <option value="12">6 Months (180 days)</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label for="amount_loaned">Amount Loaned:</label>
                                            <input type="text" id="amount_loaned" name="amount_loaned" step="1000" min="0" required>
                                        </div>
                                    </div>
                                    
                                    <div class="form-section">
                                        <h5 class="section-title">Computed Amounts</h5>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="daily_payment">15/30 Payment:</label>
                                            <input type="text" id="daily_payment" name="daily_payment" step="0.01" min="0" readonly required>
                                            <div class="calculation-note">Amount Loaned ÷ Loan Term</div>
                                        </div>
                                        <div class="form-group">
                                            <label for="balance">Total Balance:</label>
                                            <input type="text" id="balance" name="balance" step="0.01" min="0" readonly required>
                                            <div class="calculation-note">Same as Amount Loaned (no interest)</div>
                                            <div id="balance-error" class="error-message"></div>
                                        </div>
                                    </div>

                                    <div class="form-section">
                                        <h5 class="section-title">Schedule</h5>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Start Date (Today):</label>
                                            <div class="read-only-field" id="start_date_display"></div>
                                            <input type="hidden" id="start_date" name="start_date">
                                        </div>
                                        <div class="form-group">
                                            <label>End Date:</label>
                                            <div class="read-only-field" id="end_date_display"></div>
                                            <input type="hidden" id="end_date" name="end_date">
                                        </div>
                                    </div>
                                    </div>
                                    </div>
                                    </div>
                                    </div>

                                    <div class="form-group">
                                        <button type="submit" class="btn-submit">Add Employee Loan</button>
                                        <div id="form-error" class="error-message"></div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </main>

                <?php include __DIR__ . '/includes/footer.php'; ?>
            </div>
        </div>

        <!-- Beautiful Toast Notification -->
        <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 11">
            <div id="successToast" class="toast success-toast" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="toast-header">
                    <strong class="me-auto">Success</strong>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
                <div class="toast-body">
                    <i class="fas fa-check-circle text-success me-2"></i>
                    <span id="toastMessage">Employee loan added successfully!</span>
                </div>
            </div>
        </div>

        <script type="application/json" id="add-collector-loan-data"><?php echo json_encode($pageConfig, $jsonFlags); ?></script>

        <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
        <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
        <!-- SweetAlert2 JS -->
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
        <script src="js/scripts.js"></script>
        <script src="assets/js/add-collector-loan.js"></script>
    </body>
</html>
