<?php
/**
 * Prime City - New loan (financing) page.
 *
 * Layout only: data comes from actions/add-financing-actions.php, every JSON
 * call goes to api/add-financing-endpoint.php, layout chrome from includes/*,
 * styles from assets/css and behaviour from assets/js.
 */

require_once __DIR__ . '/actions/add-financing-actions.php';

try {
    $context = af_context();
} catch (Throwable $e) {
    error_log('[add-financing] ' . $e->getMessage());
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
        <head>
            <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
            <meta charset="utf-8" />
            <title>New Loan - Unavailable</title>
            <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        </head>
        <body class="bg-light">
            <div class="container py-5">
                <div class="alert alert-danger">
                    <h4 class="alert-heading">The loan form is unavailable</h4>
                    <p class="mb-0">We could not prepare the new-loan form right now. Please try again in a moment.</p>
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
$is_admin = $context['is_admin'];
$is_area_manager = $context['is_area_manager'];
$is_restricted_user = $context['is_restricted_user'];
$show_branch_filter = $context['show_branch_filter'];
$branch_options = $context['branch_options'];
$pre_selected_customer_id = $context['pre_selected_customer_id'];
$pre_selected_customer = $context['pre_selected_customer'];
$pre_selected_branch = $context['pre_selected_branch'];
$pre_selected_collector_id = $context['pre_selected_collector_id'];
$pre_selected_customer_name = $context['pre_selected_customer_name'];
$pre_selected_collector_name = $context['pre_selected_collector_name'];
$customers = $context['customers'];
$collectors = $context['collectors'];

// Shared layout includes read these.
$layout_active = 'add-financing';
$layout_menu = $context['layout_menu'];

$pageConfig = array(
    'endpoint'                => 'api/add-financing-endpoint.php',
    'preSelectedCustomerId'   => $pre_selected_customer_id,
    'preSelectedCustomerName' => $pre_selected_customer_name !== '' ? $pre_selected_customer_name : null,
    'preSelectedBranch'       => $pre_selected_branch,
    'preSelectedCollectorId'  => $pre_selected_collector_id,
    'preSelectedCollectorName' => $pre_selected_collector_name !== '' ? $pre_selected_collector_name : null,
    'isAdmin'                 => (bool) $is_admin,
    'isAreaManager'           => (bool) $is_area_manager,
    'isRestrictedUser'        => (bool) $is_restricted_user,
    'userBranch'              => $user_branch,
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
        <meta name="description" content="Prime City new loan" />
        <meta name="author" content="Prime City" />
        <title>Add New Loan - Prime City Admin</title>
        <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
        <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        <link href="assets/css/dash.css" rel="stylesheet" />
        <link href="assets/css/dash-ui.css" rel="stylesheet" />
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
        <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
        <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
        <link href="assets/css/add-financing-style.css" rel="stylesheet" />
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
                                    <i class="fas fa-file-invoice-dollar" aria-hidden="true"></i>
                                    Prime City &middot; Loans
                                </p>
                                <h1 class="dash-title">New Loan</h1>
                                <p class="dash-sub">Create a customer financing loan with its terms, fees and computed payments.</p>
                            </div>

                            <div class="dash-head__actions">
                                <a class="btn btn-primary" href="customer_list.php">
                                    <i class="fas fa-arrow-left me-1" aria-hidden="true"></i>
                                    Back to customers
                                </a>
                            </div>
                        </header>
                        
                        <div class="card registration-card dash-card mb-4">
                            <header class="dash-card__head">
                                <span class="dash-card__icon"><i class="fas fa-file-signature" aria-hidden="true"></i></span>
                                <div>
                                    <h2 class="dash-card__title">Add New Loan</h2>
                                    <p class="dash-card__hint">Customer, collector and loan terms in a single form</p>
                                </div>
                            </header>
                            <div class="card-body">
                                <?php if ($pre_selected_customer): ?>
                                <div class="auto-selected-info">
                                    <strong><i class="fas fa-info-circle"></i> Auto-selected from Customer List:</strong><br>
                                    Customer: <strong><?php echo htmlspecialchars($pre_selected_customer['first_name'] . ' ' . $pre_selected_customer['last_name']); ?></strong> | 
                                    Branch: <strong><?php echo htmlspecialchars($pre_selected_branch); ?></strong>
                                    <?php if ($pre_selected_collector_id): ?>
                                    | Collector: <strong>Auto-selected from same area</strong>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                
                                <form action="add_financing.php" method="post" id="financingForm">
                                    
                                    <!-- Branch Filter -->
                                    <?php if ($show_branch_filter): ?>
                                    <div class="branch-filter">
                                        <label for="branch_filter">Filter by Branch:</label>
                                        <select id="branch_filter" name="branch_filter" class="form-control">
                                            <?php foreach ($branch_options as $value => $label): ?>
                                                <option value="<?= htmlspecialchars($value) ?>" 
                                                    <?= (($pre_selected_branch && $value === $pre_selected_branch) || (!$pre_selected_branch && ($user_branch === $value || ($user_branch === 'all' && $value === 'all')))) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($label) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <?php else: ?>
                                    <div class="branch-info">
                                        <strong>Branch:</strong> <?= htmlspecialchars($pre_selected_branch ?: $user_branch) ?>
                                    </div>
                                    <?php endif; ?>

                                    <div class="form-section">
                                        <h5 class="section-title">Loan Parties</h5>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="customer_name">Customer Name:</label>
                                            <select id="customer_name" name="customer_name" class="select2-custom" required>
                                                <option value="">Select Customer</option>
                                                <?php if ($pre_selected_customer_id && $pre_selected_customer_name): ?>
                                                    <option value="<?= $pre_selected_customer_id ?>" selected>
                                                        <?= htmlspecialchars($pre_selected_customer_name) ?>
                                                    </option>
                                                <?php endif; ?>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label for="collector_name">Collector Name:</label>
                                            <select id="collector_name" name="collector_name" class="select2-custom" required>
                                                <option value="">Select Collector</option>
                                                <?php if ($pre_selected_collector_id && $pre_selected_collector_name): ?>
                                                    <option value="<?= $pre_selected_collector_id ?>" selected>
                                                        <?= htmlspecialchars($pre_selected_collector_name) ?>
                                                    </option>
                                                <?php endif; ?>
                                            </select>
                                        </div>
                                    </div>
                                    </div>
                                    
                                    <div class="form-section">
                                        <h5 class="section-title">Loan Terms</h5>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="amount_financed">Amount Financed:</label>
                                            <input type="text" id="amount_financed" name="amount_financed" step="1000" min="0" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="loan_term">Loan Term:</label>
                                            <select id="loan_term" name="loan_term" required>
                                                <option value="46">Short Term (46 days)</option>
                                                <option value="70">Long Term (70 days)</option>
                                                <option value="78">78 days</option>
                                                <option value="90">90 days</option>
                                                <option value="100">100 days</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="interest">Interest Rate (%):</label>
                                            <input type="text" id="interest" name="interest" step="0.01" min="0" value="15" readonly required>
                                        </div>
                                        <div class="form-group">
                                            <label for="interest_amount">Interest (Peso Value):</label>
                                            <input type="text" id="interest_amount" name="interest_amount" step="0.01" min="0" readonly>
                                            <div class="calculation-note">Amount Financed × Interest Rate (%)</div>
                                        </div>
                                    </div>

                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="service_fee">Service Fee:</label>
                                            <input type="text" id="service_fee" name="service_fee" step="0.01" min="0" readonly>
                                        </div>
                                        <div class="form-group">
                                            <label for="daily_payment">Daily Payment:</label>
                                            <input type="text" id="daily_payment" name="daily_payment" step="0.01" min="0" readonly required>
                                            <div class="calculation-note">Total Balance ÷ Loan Term</div>
                                        </div>
                                    </div>
                                    </div>

                                    <div class="form-section">
                                        <h5 class="section-title">Computed Amounts</h5>
                                    <div class="form-row">
                                        <div class="form-group" hidden>
                                            <label for="savings">Savings:</label>
                                            <input type="text" id="savings" name="savings" step="0.01" min="0" value="0.00" disabled>
                                        </div>
                                        <div class="form-group">
                                            <label for="customer_net">Customer Net:</label>
                                            <input type="text" id="customer_net" name="customer_net" step="0.01" min="0" readonly>
                                            <div class="calculation-note">Amount Financed - Service Fee</div>
                                        </div>
                                        <div class="form-group">
                                            <label for="balance">Total Balance:</label>
                                            <input type="text" id="balance" name="balance" step="0.01" min="0" readonly required>
                                            <div class="calculation-note">Amount Financed + Interest</div>
                                            <div id="balance-error" class="error-message"></div>
                                        </div>
                                    </div>
                                    </div>

                                    <div class="form-section">
                                        <h5 class="section-title">Release Details</h5>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="loan_released_type">Loan Released Type:</label>
                                            <select id="loan_released_type" name="loan_released_type" required>
                                                <option value="">Select Type</option>
                                                <option value="Cash">Cash</option>
                                                <option value="GCash">GCash</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Start Date (Today):</label>
                                            <div class="read-only-field" id="start_date_display"></div>
                                            <!-- FIXED: Store client's local datetime in hidden field -->
                                            <input type="hidden" id="start_date" name="start_date">
                                        </div>
                                        <div class="form-group">
                                            <label>End Date:</label>
                                            <div class="read-only-field" id="end_date_display"></div>
                                            <input type="hidden" id="end_date" name="end_date">
                                        </div>
                                    </div>
                                    </div>

                                    <div class="form-group">
                                        <button type="submit" class="btn-submit">Add Financing</button>
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

        <script type="application/json" id="add-financing-data"><?php echo json_encode($pageConfig, $jsonFlags); ?></script>

        <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
        <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <script src="js/scripts.js"></script>
        <script src="assets/js/add-financing.js"></script>
    </body>
</html>
