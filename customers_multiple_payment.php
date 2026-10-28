<?php
/**
 * Prime City - Multiple customer payments page.
 *
 * Layout only: data comes from actions/customers-multiple-payment-actions.php,
 * JSON traffic stays with the existing api/payments.php and
 * process_payments.php endpoints, layout chrome from includes/*, styles from
 * assets/css and behaviour from assets/js.
 */

require_once __DIR__ . '/actions/customers-multiple-payment-actions.php';

try {
    $context = cmp_context();
} catch (Throwable $e) {
    error_log('[customers-multiple-payment] ' . $e->getMessage());
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
        <head>
            <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
            <meta charset="utf-8" />
            <title>Customer Payments - Unavailable</title>
            <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        </head>
        <body class="bg-light">
            <div class="container py-5">
                <div class="alert alert-danger">
                    <h4 class="alert-heading">The payment form is unavailable</h4>
                    <p class="mb-0">We could not prepare the payment form right now. Please try again in a moment.</p>
                </div>
            </div>
        </body>
    </html>
    <?php
    exit;
}

$user = $context['user'];
$user_branch = $context['user_branch'];
$user_type = $context['user_type'];
$is_proxy_user = $context['is_proxy_user'];
$is_collector_user = $context['is_collector_user'];
$is_proxy_collector = $context['is_proxy_collector'];
$is_management_user = $context['is_management_user'];
$all_branches = $context['all_branches'];
$available_branches = $context['available_branches'];
$selectedBranch = $context['selected_branch'];
$collectors = $context['collectors'];
$proxyAccounts = $context['proxy_accounts'];
$collector_full_name = $context['collector_full_name'];

// Shared layout includes read these.
$layout_active = 'multiple-payments';
$layout_menu = $context['layout_menu'];

$pageConfig = array(
    'endpointBase'       => $context['endpoint_base'],
    'isCollectorUser'    => (bool) $is_collector_user,
    'isProxyUser'        => (bool) $is_proxy_user,
    'isProxyCollector'   => (bool) $is_proxy_collector,
    'userType'           => $user_type,
    'userBranch'         => $user_branch,
    'userId'             => (int) ($_SESSION['user_id'] ?? 0),
    'isManagementUser'   => (bool) $is_management_user,
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
    <meta name="description" content="Prime City multiple customer payments" />
    <meta name="author" content="Prime City" />
    <title>Customer Payments - Prime City Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
    <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
    <link href="assets/css/dash.css" rel="stylesheet" />
    <link href="assets/css/dash-ui.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="assets/css/customers-multiple-payment-style.css?v=<?php echo is_file(__DIR__ . '/assets/css/customers-multiple-payment-style.css') ? filemtime(__DIR__ . '/assets/css/customers-multiple-payment-style.css') : 1; ?>" rel="stylesheet" />
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
                            Prime City &middot; Finance
                        </p>
                        <h1 class="dash-title">Customer Payments</h1>
                        <p class="dash-sub">Record loan, savings deposit and savings withdrawal payments for several customers in one go.</p>
                    </div>

                    <div class="dash-head__actions">
                        <a class="btn btn-primary" href="<?php echo ($is_collector_user || ($is_proxy_user && $user_type === 'Proxy Collector')) ? 'collector_dashboard.php' : 'admin.php'; ?>">
                            <i class="fas fa-arrow-left me-1" aria-hidden="true"></i>
                            Back to dashboard
                        </a>
                    </div>
                </header>

                <div class="card payment-card dash-card mb-4">
                    <header class="dash-card__head">
                        <span class="dash-card__icon"><i class="fas fa-money-bill-wave" aria-hidden="true"></i></span>
                        <div>
                            <h2 class="dash-card__title">Customer Payments Form</h2>
                            <p class="dash-card__hint">Choose a collector, then add the customers and amounts to process</p>
                        </div>
                    </header>
                    <div class="card-body">
<?php
/* The branch control is printed either on its own row (admin / managers) or
   beside the collector field, so Collector accounts get both in one row. */
$render_branch_filter = static function () use ($all_branches, $available_branches, $is_collector_user, $selectedBranch, $user_branch, $user_type) {
    ?>
    <div class="form-floating">
        <select class="form-select" id="branch_filter" name="branch_filter" <?= $is_collector_user ? 'disabled' : '' ?>>
            <option value="">All Branches</option>
            <?php foreach ($all_branches as $branch):
                $is_available = in_array($branch, $available_branches);
                $is_selected = $selectedBranch === $branch;
                ?>
                <?php if ($is_collector_user): ?>
                    <?php if ($branch === $user_branch): ?>
                        <option value="<?= escape($branch) ?>" selected><?= escape($branch) ?> (Your Branch)</option>
                    <?php else: ?>
                        <option value="<?= escape($branch) ?>" disabled class="branch-disabled"><?= escape($branch) ?></option>
                    <?php endif; ?>
                <?php elseif ($user_type === 'Area Manager'): ?>
                    <?php if ($is_available): ?>
                        <option value="<?= escape($branch) ?>" <?= $is_selected ? 'selected' : '' ?>><?= escape($branch) ?></option>
                    <?php endif; ?>
                <?php else: ?>
                    <option value="<?= escape($branch) ?>" <?= $is_selected ? 'selected' : '' ?> <?= !$is_available ? 'disabled class="branch-disabled"' : '' ?>>
                        <?= escape($branch) ?><?php if (!$is_available && $branch === $user_branch): ?> (Your Branch)<?php endif; ?>
                    </option>
                <?php endif; ?>
            <?php endforeach; ?>
        </select>
        <label for="branch_filter">Filter by Branch</label>
    </div>
    <?php if ($is_collector_user): ?>
        <div class="form-text text-muted">
            <small>
                <i class="fas fa-info-circle me-1"></i>
                You can only view and process payments for your assigned branch: <?= escape($user_branch) ?>
            </small>
        </div>
    <?php elseif ($user_type === 'Area Manager'): ?>
        <div class="form-text text-muted">
            <small>
                <i class="fas fa-info-circle me-1"></i>
                Available branches: <?= implode(', ', $available_branches) ?>
            </small>
        </div>
    <?php elseif (in_array($user_type, ['Branch Manager', 'Proxy Collector-Cashier', 'Proxy Collector'], true)): ?>
        <div class="form-text text-muted">
            <small>
                <i class="fas fa-info-circle me-1"></i>
                <?= escape($user_branch) ?> branch.
            </small>
        </div>
    <?php endif; ?>
    <?php
};
?>
<?php if (!$is_proxy_collector && !$is_collector_user): ?>
<!-- Branch Filter - HIDDEN for Proxy Collector, merged with Collector for Collector accounts -->
<div class="row mb-4">
    <div class="col-md-12">
        <div class="row">
            <div class="col-md-6">
                <?php $render_branch_filter(); ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
                        
                        <?php if ($is_proxy_collector): ?>
                            <!-- Proxy Collector Info Box - STILL VISIBLE for Proxy Collector -->
                            <div class="proxy-collector-info mb-4">
                                <div class="row">
                                    <div class="col-md-12">
                                        <h6><i class="fas fa-user-check me-2"></i>Proxy Collector Mode</h6>
                                        <p class="mb-1">
                                            <strong>You are logged in as: </strong>
                                            <?php
                                            $proxy_name = $user['first_name'];
                                            if (!empty($user['middle_name'])) {
                                                $proxy_name .= ' ' . $user['middle_name'];
                                            }
                                            $proxy_name .= ' ' . $user['last_name'];
                                            if (!empty($user['suffix']) && $user['suffix'] != 'None') {
                                                $proxy_name .= ' ' . $user['suffix'];
                                            }
                                            echo escape($proxy_name) . ' (Proxy Collector)';
                                            ?>
                                        </p>
                                        <p class="mb-0">
                                            <small>
                                                <i class="fas fa-info-circle me-1"></i>
                                                You will be recorded as the proxy collector for all transactions. Please select the actual collector below.
                                            </small>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <form id="paymentForm" method="post" novalidate>
                            <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                            <input type="hidden" id="transactions" name="transactions" value="">
                            
                            <?php if ($is_proxy_collector): ?>
                                <input type="hidden" id="proxy_collector_id" name="proxy_collector_id" value="<?= escape($_SESSION['user_id']) ?>">
                            <?php endif; ?>
                            
                            <!-- Collector Information -->
                            <div class="form-section">
                                <?php if (!$is_collector_user): ?>
                                <h5 class="section-title">Collector</h5>
                                <?php endif; ?>
                                <div class="row mb-4">

                                    <?php if ($is_collector_user): ?>
                                    <!-- Collector accounts: branch and collector share one row -->
                                    <div class="col-md-6">
                                        <?php $render_branch_filter(); ?>
                                    </div>
                                    <?php endif; ?>

                                    <div class="col-md-6">
                                    <?php if ($is_collector_user): ?>
                                        <div class="form-floating">
                                            <?php
                                            $collector_full_name = $user['first_name'];
                                            if (!empty($user['middle_name'])) {
                                                $collector_full_name .= ' ' . $user['middle_name'];
                                            }
                                            $collector_full_name .= ' ' . $user['last_name'];
                                            if (!empty($user['suffix']) && $user['suffix'] != 'None') {
                                                $collector_full_name .= ' ' . $user['suffix'];
                                            }
                                            ?>
                                            <input type="text" class="form-control" id="collector_display"
                                                   value="<?= escape($collector_full_name) ?>"
                                                   readonly>
                                            <input type="hidden" id="collector_id" name="collector_id" class="select2-hidden-accessible" value="<?= escape($_SESSION['user_id']) ?>">
                                            <label for="collector_display">Collector</label>
                                        </div>
                                    <?php else: ?>
                                        <label for="collector_id" class="collector-label">Collector</label>
                                        <select class="form-select" id="collector_id" name="collector_id" required>
                                            <option value="">Select a Collector</option>
                                            <?php foreach ($collectors as $collector):
                                                $collector_branch = $collector['branch'] ?? '';
                                                $is_available = in_array($collector_branch, $available_branches);
                                                if ($is_available):
                                                    ?>
                                                    <option value="<?= escape($collector['id']) ?>"
                                                        data-branch="<?= escape($collector_branch) ?>"
                                                        data-name="<?= escape($collector['name']) ?>"
                                                        data-area="<?= escape($collector['area']) ?>">
                                                        <?= escape($collector['name']) ?> - <?= escape($collector['area']) ?>
                                                    </option>
                                                    <?php
                                                endif;
                                            endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                    </div>

                                    <div class="col-md-6">
                                        <?php if (!$is_collector_user && !$is_proxy_user && !$is_proxy_collector): ?>
                                            <label for="proxy_account_id" class="proxy-label">Proxy Collector <span class="label-optional">(optional)</span></label>
                                            <select class="form-select" id="proxy_account_id" name="proxy_account_id">
                                                <option value="">Select a Proxy Collector</option>
                                                <?php foreach ($proxyAccounts as $proxy): ?>
                                                    <option value="<?= escape($proxy['id']) ?>"
                                                        data-name="<?= escape($proxy['name']) ?>"
                                                        data-area="<?= escape($proxy['area'] ?? '') ?>">
                                                        <?= escape($proxy['name']) ?><?= !empty($proxy['area']) ? ' - ' . escape($proxy['area']) : '' ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="form-text text-muted">
                                                <small>
                                                    <i class="fas fa-info-circle me-1"></i>
                                                    Optional &mdash; pick the proxy collector that processed this batch.
                                                </small>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($is_proxy_collector): ?>
                                            <input type="hidden" name="is_proxy_collector" value="1">
                                            <input type="hidden" name="proxy_account_id" value="<?= escape($_SESSION['user_id']) ?>">
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="form-section">
                                <h5 class="section-title">Transaction Details</h5>
                            <!-- Payment Details -->
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="datetime-local" class="form-control" id="payment_date" name="payment_date" 
                                                value="<?= date('Y-m-d\TH:i') ?>" required
                                                <?= $is_management_user ? '' : 'readonly' ?>>
                                        <label for="payment_date">Transaction Date</label>
                                        <?php if (!$is_management_user): ?>
                                            <div class="form-text text-muted">
                                                <small>
                                                    <i class="fas fa-lock me-1"></i>
                                                    Transaction date is automatically set to the current date and time.
                                                </small>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <select class="form-select" id="payment_method" name="payment_method" required>
                                            <option value="">Select Payment Method</option>
                                            <option value="Cash">Cash</option>
                                            <option value="Bank Transfer">Bank Transfer</option>
                                            <option value="GCash">GCash</option>
                                            <option value="Check">Check</option>
                                            <option value="Other">Other</option>
                                        </select>
                                        <label for="payment_method">Payment Method</label>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Notes -->
                            <div class="mb-4">
                                <div class="form-floating">
                                    <textarea class="form-control" id="notes" name="notes" rows="2" style="height: 100px;"></textarea>
                                    <label for="notes">Notes</label>
                                </div>
                            </div>
                            </div>
                            
                            <div class="form-section">
                                <h5 class="section-title">Customer Transactions</h5>
                            <!-- Transactions Section -->
                            <div class="mb-4">
                            <div class="tx-toolbar">
                                    <div>
                                        <button type="button" class="btn btn-primary me-2" id="addCustomerBtn" 
                                            <?php if (!$is_collector_user) { echo 'disabled'; } ?>>
                                            <i class="fas fa-plus me-2"></i>Add Customers
                                        </button>
                                        <button type="button" class="btn btn-teal me-2" id="addSavingsOnlyBtn" 
                                            <?php if (!$is_collector_user) { echo 'disabled'; } ?>>
                                            <i class="fas fa-piggy-bank me-2"></i>Add Customers (Savings Bal. Only)
                                        </button>
                                        <button type="button" class="btn btn-warning" id="addPastDueBtn" 
                                            <?php if (!$is_collector_user) { echo 'disabled'; } ?>>
                                            <i class="fas fa-plus me-2"></i>Add Past Due
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="transactions-container">
                                    <table class="transactions-table" id="transactionsTable">
                                        <thead>
                                            <tr>
                                                <th width="120px">Area</th>
                                                <th width="200px">Customer</th>
                                                <th width="250px">Loan Payment</th>
                                                <th width="250px">Savings Deposit</th>
                                                <th width="250px">Savings Withdrawal</th>
                                                <th width="80px">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="transactionsBody">
                                            <tr class="empty-row">
                                                <td colspan="6" class="tx-empty">
                                                    <i class="fas fa-user-plus" aria-hidden="true"></i>
                                                    <span>No customers added yet &mdash; use <strong>Add Customers</strong> to start.</span>
                                                </td>
                                            </tr>
                                        </tbody>
                                        <tfoot>
                                            <tr class="totals-row">
                                                <td colspan="2"><strong>Totals:</strong></td>
                                                <td class="total-amount" id="totalLoanPayment">₱0.00</td>
                                                <td class="total-amount" id="totalSavingsDeposit">₱0.00</td>
                                                <td class="total-amount" id="totalSavingsWithdrawal">₱0.00</td>
                                                <td></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                            </div>
                            
                            <!-- Submit Button -->
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary btn-lg" id="submitBtn" disabled>
                                    <i class="fas fa-save me-2"></i>Process All Transactions
                                </button>
                            </div>
                            <div id="form-error" class="text-danger mt-2 text-center"></div>
                        </form>
                    </div>
                </div>
            </div>
        </main>
        </div>
    </div>

    <!-- Customer Selection Modal -->
    <div class="modal fade" id="customerModal" tabindex="-1" aria-labelledby="customerModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="customerModalLabel">Select Customers</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <input type="text" class="form-control" id="customerSearch" placeholder="Search customers...">
                        </div>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="selectAllCustomers">
                                <label class="form-check-label" for="selectAllCustomers">
                                    Select All Visible Customers
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="table-responsive" style="max-height: 400px;">
                        <table class="table table-hover dash-table">
                            <thead>
                                <tr>
                                    <th width="50px"></th>
                                    <th>Area</th>
                                    <th>Customer Name</th>
                                    <th>Loan Balance</th>
                                    <th>Savings Balance</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="customerTableBody">
                                <!-- Customers will be loaded here -->
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            Customers already added to transactions are hidden from this list.
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmCustomerBtn">Add Selected Customers</button>
                </div>
            </div>
        </div>
    </div>

    <script type="application/json" id="multiple-payment-data"><?php echo json_encode($pageConfig, $jsonFlags); ?></script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script src="js/scripts.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="assets/js/customers-multiple-payment.js?v=<?php echo is_file(__DIR__ . '/assets/js/customers-multiple-payment.js') ? filemtime(__DIR__ . '/assets/js/customers-multiple-payment.js') : 1; ?>"></script>
    <script src="assets/js/customers-multiple-payment-selects.js?v=<?php echo is_file(__DIR__ . '/assets/js/customers-multiple-payment-selects.js') ? filemtime(__DIR__ . '/assets/js/customers-multiple-payment-selects.js') : 1; ?>"></script>
</body>
</html>
