<?php
/**
 * Prime City - Employee loan payments page.
 *
 * Layout only: data comes from actions/add-collector-payments-actions.php,
 * every JSON call goes to api/add-collector-payments-endpoint.php, layout
 * chrome from includes/*, styles from assets/css and behaviour from assets/js.
 */

require_once __DIR__ . '/actions/add-collector-payments-actions.php';

try {
    $context = acp_context();
} catch (Throwable $e) {
    error_log('[add-collector-payments] ' . $e->getMessage());
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
        <head>
            <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
            <meta charset="utf-8" />
            <title>Employee Loan Payments - Unavailable</title>
            <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        </head>
        <body class="bg-light">
            <div class="container py-5">
                <div class="alert alert-danger">
                    <h4 class="alert-heading">The payment form is unavailable</h4>
                    <p class="mb-0">We could not prepare the form right now. Please try again in a moment.</p>
                </div>
            </div>
        </body>
    </html>
    <?php
    exit;
}

$user = $context['user'];
$user_account_type = $context['user_account_type'];
$user_branch = $context['user_branch'];
$all_branches = $context['all_branches'];
$user_branch_options = $context['user_branch_options'];
$is_restricted_user = $context['is_restricted_user'];
$collectors = $context['collectors'];
$success_message = $context['success_message'];
$error_message = $context['error_message'];

// Shared layout includes read these.
$layout_active = 'add-collector-payments';
$layout_menu = $context['layout_menu'];
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
        <meta charset="utf-8" />
        <meta http-equiv="X-UA-Compatible" content="IE=edge" />
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
        <meta name="description" content="Prime City employee loan payments" />
        <meta name="author" content="Prime City" />
        <title>Employee Loan Payments - Prime City Admin</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
        <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
        <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        <link href="assets/css/dash.css" rel="stylesheet" />
        <link href="assets/css/dash-ui.css" rel="stylesheet" />
        <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
        <link href="assets/css/add-collector-payments-style.css" rel="stylesheet" />
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
                                    <i class="fas fa-money-bill-wave" aria-hidden="true"></i>
                                    Prime City &middot; Employee Loans
                                </p>
                                <h1 class="dash-title">Employee Loan Payments</h1>
                                <p class="dash-sub">Post one or many employee loan payments in a single batch.</p>
                            </div>

                            <div class="dash-head__actions">
                                <a class="btn btn-primary" href="employee_lists.php">
                                    <i class="fas fa-arrow-left me-1" aria-hidden="true"></i>
                                    Back to employees
                                </a>
                            </div>
                        </header>
                        
                        <div class="card registration-card dash-card mb-4">
                            <header class="dash-card__head">
                                <span class="dash-card__icon"><i class="fas fa-receipt" aria-hidden="true"></i></span>
                                <div>
                                    <h2 class="dash-card__title">Add Employee Loan Payments</h2>
                                    <p class="dash-card__hint">Branch, payment date and the payments in this batch</p>
                                </div>
                            </header>
                            <div class="card-body">
                                <form action="add_collector_payments.php" method="post" id="collectorPaymentForm">
                                    <div class="form-section">
                                        <h5 class="section-title">Batch Details</h5>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="branch">Branch:</label>
                                            <select id="branch" name="branch" class="form-select" required 
                                                <?php echo $is_restricted_user ? 'disabled' : ''; ?>>
                                                <option value="">Select Branch</option>
                                                <?php foreach ($all_branches as $branch): ?>
                                                    <?php 
                                                    $is_available = in_array($branch, $user_branch_options);
                                                    $is_selected = ($is_restricted_user && $branch === $user_branch) || (!$is_restricted_user && $branch === ($_POST['branch'] ?? ''));
                                                    
                                                    // Only display available branches
                                                    if ($is_available): ?>
                                                    <option value="<?php echo htmlspecialchars($branch); ?>" 
                                                        <?php echo $is_selected ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($branch); ?>
                                                    </option>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </select>
                                            <?php if ($is_restricted_user): ?>
                                                <input type="hidden" name="branch" value="<?php echo htmlspecialchars($user_branch); ?>">
                                                <div class="branch-disabled-note">
                                                    <i class="fas fa-info-circle"></i>
                                                    Branch is automatically set to your assigned branch (<?php echo htmlspecialchars($user_branch); ?>) and cannot be changed.
                                                </div>
                                            <?php elseif ($user_account_type === 'Area Manager'): ?>
                                                <div class="area-manager-note">
                                                    <i class="fas fa-info-circle"></i>
                                                    You can select from your assigned branches: <?php echo htmlspecialchars(implode(', ', $user_branch_options)); ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="calculation-note">Select branch to filter collectors</div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="form-group">
                                            <label for="payment_date">Payment Date:</label>
                                            <input type="datetime-local" id="payment_date" name="payment_date" step="1" required>
                                            <div class="calculation-note">Date and time when payments were received (including seconds)</div>
                                            <div id="datetime_warning" class="datetime-warning">
                                                <i class="fas fa-exclamation-triangle"></i>
                                                <span id="datetime_warning_text"></span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label for="notes">Notes:</label>
                                        <textarea id="notes" name="notes" rows="3" placeholder="Notes about these payments"></textarea>
                                    </div>
                                    </div>
                                    
                                    <div class="form-section">
                                        <h5 class="section-title">Payments</h5>
                                    
                                    <div id="duplicate_collector_warning" class="duplicate-collector-warning">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        <span id="duplicate_warning_text"></span>
                                    </div>

                                    <div class="payment-rows-container" id="paymentRowsContainer">
                                        <!-- Payment rows will be added here dynamically -->
                                    </div>
                                    
                                    <button type="button" id="addPaymentBtn" class="add-payment-btn">
                                        <i class="fas fa-plus"></i> Add Payment
                                    </button>

                                    <div class="summary-card">
                                        <div class="summary-row">
                                            <span class="summary-label">Total Payments:</span>
                                            <span class="summary-value" id="totalPaymentsCount">0</span>
                                        </div>
                                        <div class="summary-row">
                                            <span class="summary-label">Total Amount:</span>
                                            <span class="summary-value" id="totalPaymentsAmount">₱0.00</span>
                                        </div>
                                    </div>
                                    </div>

                                    <div class="form-group mt-3">
                                        <button type="submit" class="btn-submit">Record All Payments</button>
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

        <!-- Payment Row Template (hidden) -->
        <div id="paymentRowTemplate" style="display: none;">
            <div class="payment-row" data-index="{index}">
                <div class="payment-row-header">
                    <span class="payment-row-title">Payment #{index}</span>
                    <button type="button" class="remove-payment" onclick="removePaymentRow({index})">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="form-row">
                    <div class="form-group collector-select-group" id="collector_select_group_{index}">
                        <label for="collector_name_{index}">Employee Name:</label>
                        <select id="collector_name_{index}" name="payments[{index}][collector_id]" class="select2-custom collector-select" required>
                            <option value="">Select Branch First</option>
                        </select>
                    </div>
                    <div class="form-group loan-select-group" id="loan_select_group_{index}">
                        <label for="loan_id_{index}">Loan:</label>
                        <select id="loan_id_{index}" name="payments[{index}][loan_id]" class="select2-custom loan-select" required>
                            <option value="">Select Collector First</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="payment_amount_{index}">Payment Amount:</label>
                        <input type="text" id="payment_amount_{index}" name="payments[{index}][amount]" value="0.00" required>
                        <div class="calculation-note">Enter the payment amount received</div>
                        <div id="loan_balance_{index}" class="loan-balance-display"></div>
                        <div id="amount-error_{index}" class="error-message"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- No Loans Message Template (hidden) -->
        <div id="noLoansMessageTemplate" style="display: none;">
            <div class="no-loans-message" id="no_loans_message_{index}">
                <i class="fas fa-info-circle me-2"></i>
                This collector has no active loans with remaining balance.
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
        <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <script src="assets/js/add-collector-payments.js"></script>
    </body>
</html>
