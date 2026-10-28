<?php
/**
 * Prime City - GCash in/out page.
 *
 * Layout only: data comes from actions/gcash-in-out-actions.php, layout chrome
 * from includes/*, styles from assets/css and behaviour from assets/js.
 */

require_once __DIR__ . '/actions/gcash-in-out-actions.php';

try {
    $context = gio_context();
} catch (Throwable $e) {
    error_log('[gcash-in-out] ' . $e->getMessage());
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
        <head>
            <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
            <meta charset="utf-8" />
            <title>GCash Transactions - Unavailable</title>
            <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        </head>
        <body class="bg-light">
            <div class="container py-5">
                <div class="alert alert-danger">
                    <h4 class="alert-heading">The GCash page is unavailable</h4>
                    <p class="mb-0">We could not prepare this page right now. Please try again in a moment.</p>
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
$user_branches = $context['user_branches'];
$selected_branch = $context['selected_branch'];
$show_filter = $context['show_filter'];
$restricted_account_types = $context['restricted_account_types'];
$multi_branch_account_types = $context['multi_branch_account_types'];
$areas = $context['areas'];
$banks = $context['banks'];
$branches = $context['branches'];
$transactions = $context['transactions'];
$customers = $context['customers'];
$customers_by_area = $context['customers_by_area'];
$payments = $context['payments'];
$total_credit = $context['total_credit'];
$total_debit = $context['total_debit'];
$ending_balance = $context['ending_balance'];
$error = $context['error'];
$success = $context['success'];

// Shared layout includes read these.
$layout_active = 'gcash-in-out';
$layout_menu = $context['layout_menu'];

$pageConfig = array(
    'customers'          => $customers,
    'error'              => $error,
    'success'            => $success,
    'deleteBranchFilter' => urlencode($selected_branch),
    'isRestrictedBranch' => in_array($user_account_type, $restricted_account_types),
    'isMultiBranch'      => in_array($user_account_type, $multi_branch_account_types),
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
        <meta name="description" content="Prime City GCash transactions" />
        <meta name="author" content="Prime City" />
        <title>GCash Transactions - Prime City Admin</title>
        <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
        <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        <link href="assets/css/dash.css" rel="stylesheet" />
        <link href="assets/css/dash-ui.css" rel="stylesheet" />
        <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
        <link href="https://cdn.datatables.net/1.13.1/css/jquery.dataTables.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
        <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
        <link href="assets/css/gcash-in-out-style.css" rel="stylesheet" />
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
                                    <i class="fas fa-mobile-screen-button" aria-hidden="true"></i>
                                    Prime City &middot; Finance
                                </p>
                                <h1 class="dash-title">GCash Transactions</h1>
                                <p class="dash-sub">Record GCash cash-in, cash-out and fund transfers, then review the GCash ledger.</p>
                            </div>
                        </header>
                        
                        <?php
                        // Calculate GCash balance and total fees
                        $balance = 0;
                        $totalFees = 0;
                        foreach ($transactions as $transaction) {
                            if ($transaction['type'] === 'Cash In' || $transaction['type'] === 'Beginning Balance') {
                                $balance += $transaction['amount'];
                            } else {
                                $balance -= $transaction['amount'];
                            }
                            $totalFees += $transaction['fee'];
                        }
                        ?>
                        
                        <!-- Unified Branch Filter Section -->
                        <?php 
                        // Define account types that should show filter (similar to admin.php)
                        $multi_branch_account_types = ['Admin', 'Area Manager'];
                        $restricted_account_types = ['Branch Manager', 'Proxy Collector-Cashier', 'Proxy Collector'];
                        
                        // Show filter only for Admin and Area Manager
                        $show_filter = in_array($user_account_type, $multi_branch_account_types);
                        ?>
                        
                        <?php if ($show_filter): ?>
                            <!-- Show branch filter for Admin and Area Manager -->
                            <div class="unified-filter-section">
                                <div class="filter-row">
                                    <span class="filter-label">Filter by Branch:</span>
                                    <div class="branch-filter-dropdown">
                                        <select class="form-select" id="branchFilter" onchange="applyBranchFilter(this.value)">
                                            <?php if ($user_account_type === 'Admin'): ?>
                                                <option value="all" <?php echo $selected_branch == 'all' ? 'selected' : ''; ?>>All Branches</option>
                                            <?php endif; ?>
                                            <?php foreach ($user_branches as $branch): ?>
                                                <option value="<?php echo htmlspecialchars($branch); ?>" <?php echo $selected_branch == $branch ? 'selected' : ''; ?>><?php echo htmlspecialchars($branch); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <?php if ($user_account_type === 'Area Manager'): ?>
                                    <div class="restricted-branch-notice">
                                        <i class="fas fa-info-circle"></i> 
                                        Area Manager: You can access <?php echo count($user_branches) > 1 ? 'multiple branches' : 'your assigned branch'; ?>.
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php elseif (in_array($user_account_type, $restricted_account_types)): ?>
                            <!-- Hidden branch filter for restricted users with notice -->
                            <div class="unified-filter-section">
                                <div class="restricted-branch-notice">
                                    <i class="fas fa-info-circle"></i> 
                                    Viewing data for your assigned branch: <strong><?php echo htmlspecialchars($user_branch); ?></strong>
                                </div>
                                <input type="hidden" id="branchFilter" value="<?php echo htmlspecialchars($user_branch); ?>">
                            </div>
                        <?php endif; ?>

                        <section class="dash-stats dash-stats--two" aria-label="GCash summary">
                            <article class="stat stat--green">
                                <div class="stat__body">
                                    <p class="stat__label">Current GCash Balance</p>
                                    <p class="stat__value">₱<?php echo number_format($total_credit, 2); ?></p>
                                    <p class="stat__meta">Net balance of every GCash transaction</p>
                                </div>
                                <span class="stat__icon"><i class="fas fa-wallet" aria-hidden="true"></i></span>
                            </article>

                            <article class="stat stat--cyan">
                                <div class="stat__body">
                                    <p class="stat__label">Total Fees</p>
                                    <p class="stat__value">₱<?php echo number_format($totalFees, 2); ?></p>
                                    <p class="stat__meta">Fees collected on GCash transactions</p>
                                </div>
                                <span class="stat__icon"><i class="fas fa-coins" aria-hidden="true"></i></span>
                            </article>
                        </section>
                        
                        <div class="card dash-card mb-4">
                            <header class="dash-card__head">
                                <span class="dash-card__icon"><i class="fas fa-plus-circle" aria-hidden="true"></i></span>
                                <div>
                                    <h2 class="dash-card__title">Record New GCash Transaction</h2>
                                    <p class="dash-card__hint">Log a cash in, cash out, fund transfer or beginning balance.</p>
                                </div>
                            </header>
                            <div class="card-body">
                                <form action="gcash_in_out.php" method="POST" id="gcashForm">
                                    <div class="form-section">
                                        <h5 class="section-title">Transaction Details</h5>
                                    <div class="row mb-3">
                                        <div class="col-md-3">
                                            <div class="form-floating">
                                                <input class="form-control" id="date" name="date" type="text" placeholder="Select date and time" required />
                                                <label for="date">Transaction Date & Time</label>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="form-floating">
                                                <select class="form-control" id="type" name="type" required>
                                                    <option value="">Select Type</option>
                                                    <option value="Cash In">Cash In</option>
                                                    <option value="Cash Out">Cash Out</option>
                                                    <option value="Fund Transfer">Fund Transfer</option>
                                                    <option value="Beginning Balance">Beginning Balance</option>
                                                </select>
                                                <label for="type">Transaction Type</label>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="form-floating">
                                                <?php if (in_array($user_account_type, $restricted_account_types)): ?>
                                                    <!-- Restricted users (Branch Manager, Proxy Collector-Cashier, Proxy Collector) - show readonly field -->
                                                    <input class="form-control restricted-field" type="text" 
                                                           value="<?php echo htmlspecialchars($user_branch); ?>" 
                                                           readonly 
                                                           title="Your account type is restricted to this branch" />
                                                    <label>Branch</label>
                                                    <input type="hidden" name="branch" value="<?php echo htmlspecialchars($user_branch); ?>">
                                                <?php elseif (in_array($user_account_type, $multi_branch_account_types)): ?>
                                                    <!-- Admin and Area Manager - show branch dropdown -->
                                                    <select class="form-control" id="branch" name="branch" required>
                                                        <option value="">Select Branch</option>
                                                        <?php foreach ($user_branches as $branch): ?>
                                                            <option value="<?php echo htmlspecialchars($branch); ?>"><?php echo htmlspecialchars($branch); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <label for="branch">Branch</label>
                                                <?php else: ?>
                                                    <!-- Other account types - show readonly field -->
                                                    <input class="form-control restricted-field" type="text" 
                                                           value="<?php echo htmlspecialchars($user_branch); ?>" 
                                                           readonly />
                                                    <label>Branch</label>
                                                    <input type="hidden" name="branch" value="<?php echo htmlspecialchars($user_branch); ?>">
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="form-floating">
                                                <select class="form-control" id="area" name="area">
                                                    <option value="">Select Area</option>
                                                    <?php foreach ($areas as $area): ?>
                                                        <option value="<?php echo htmlspecialchars($area); ?>"><?php echo htmlspecialchars($area); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <label for="area">Area</label>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-floating mb-3 mb-md-0">
                                                <input class="form-control" id="amount" name="amount" type="text" placeholder="Enter amount" required />
                                                <label for="amount">Amount</label>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row mb-3">
                                        <div class="col-md-3">
                                            <div class="form-floating mb-3 mb-md-0">
                                                <input class="form-control" id="reference" name="reference" type="text" placeholder="Reference number" />
                                                <label for="reference">Reference Number</label>
                                            </div>
                                        </div>
                                        <div class="col-md-2 fee-input-section" id="feeSection">
                                            <div class="form-floating">
                                                <input class="form-control" id="fee" name="fee" type="text" placeholder="Enter fee" value="0" />
                                                <label for="fee">Fee Amount</label>
                                            </div>
                                        </div>

                                        <div class="col-md-3 bank-select-section" id="bankSection">
                                            <div class="form-floating">
                                                <select class="form-control" id="bank" name="bank">
                                                    <option value="">Select Bank</option>
                                                    <?php foreach ($banks as $bank): ?>
                                                        <option value="<?php echo htmlspecialchars($bank); ?>"><?php echo htmlspecialchars($bank); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <label for="bank">Bank</label>
                                            </div>
                                        </div>

                                        <div class="col-md-4 bank-account-name-section" id="bankAccountNameSection">
                                            <div class="form-floating">
                                                <input class="form-control" id="bank_account_name" name="bank_account_name" type="text" placeholder="Enter bank account name" />
                                                <label for="bank_account_name">Bank Account Name</label>
                                            </div>
                                        </div>

                                        <div class="col-md-6 customer-select-section" id="customerSection">
                                            <div class="customer-search-container">
                                                <div class="form-floating">
                                                    <input type="text" class="form-control customer-search-input" id="customer_search" placeholder="Search customer..." autocomplete="off">
                                                    <label for="customer_search">Search Customer</label>
                                                </div>
                                                <button type="button" class="customer-clear-btn" id="clear_customer" aria-label="Clear selected customer" hidden>
                                                    <i class="fas fa-times" aria-hidden="true"></i>
                                                </button>
                                                <div class="customer-dropdown-container">
                                                    <div class="customer-dropdown" id="customer_dropdown"></div>
                                                </div>
                                                <span id="selected_customer_name" hidden></span>
                                                <span id="selected_customer" hidden></span>
                                            </div>
                                            <input type="hidden" id="customer_id" name="customer_id">
                                        </div>
                                    </div>
                                    
                                    </div>
                                    <div class="form-section">
                                        <h5 class="section-title">Notes</h5>
                                    <div class="mb-3">
                                        <div class="form-floating">
                                            <textarea class="form-control" id="notes" name="notes" placeholder="Enter notes" style="height: 100px"></textarea>
                                            <label for="notes">Notes</label>
                                        </div>
                                    </div>
                                    </div>
                                    
                                    <div class="dash-form__actions">
                                        <div class="d-grid">
                                            <button type="submit" class="btn btn-primary btn-block">Record Transaction</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                        
                        <div class="card dash-card mb-4">
                            <header class="dash-card__head">
                                <span class="dash-card__icon"><i class="fas fa-table" aria-hidden="true"></i></span>
                                <div>
                                    <h2 class="dash-card__title">GCash Transaction History</h2>
                                    <p class="dash-card__hint">Search, edit or delete any recorded GCash transaction.</p>
                                </div>
                            </header>
                            <div class="card-body">
                                
                                <table id="datatablesSimple" class="table table-striped table-bordered dash-table">
                                    <thead>
                                        <tr>
                                            <th>Date & Time</th>
                                            <th>Type</th>
                                            <th>Amount</th>
                                            <th>Fee</th>
                                            <th>Branch</th>
                                            <th>Area</th>
                                            <th>Bank</th>
                                            <th>Bank Account Name</th>
                                            <th>Customer</th>
                                            <th>Reference</th>
                                            <th>Notes</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($transactions as $transaction): 
                                            // Format the date as m-d-Y h:i:s A (e.g., 12-25-2023 02:30:45 PM)
                                            $formatted_date = date('m-d-Y h:i:s A', strtotime($transaction['transaction_date']));
                                            
                                            // Get customer name if exists
                                            $customer_name = 'N/A';
                                            if (!empty($transaction['customer_id'])) {
                                                foreach ($customers as $customer) {
                                                    if ($customer['id'] == $transaction['customer_id']) {
                                                        $customer_name = $customer['first_name'] . ' ' . 
                                                                        (!empty($customer['middle_name']) ? $customer['middle_name'] . ' ' : '') . 
                                                                        $customer['last_name'];
                                                        // Only add suffix if it exists and is not "None"
                                                        if (!empty($customer['suffix']) && $customer['suffix'] !== 'None') {
                                                            $customer_name .= ' ' . $customer['suffix'];
                                                        }
                                                        break;
                                                    }
                                                }
                                            }
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($formatted_date); ?></td>
                                            <td>
                                                <span class="<?php echo strtolower(str_replace(' ', '-', $transaction['type'])); ?>">
                                                    <?php echo htmlspecialchars($transaction['type']); ?>
                                                </span>
                                            </td>
                                            <td>₱<?php echo number_format($transaction['amount'], 2); ?></td>
                                            <td class="fee">₱<?php echo number_format($transaction['fee'], 2); ?></td>
                                            <td><?php echo $transaction['branch'] ? htmlspecialchars($transaction['branch']) : 'N/A'; ?></td>
                                            <td><?php echo $transaction['area'] ? htmlspecialchars($transaction['area']) : 'N/A'; ?></td>
                                            <td><?php echo $transaction['bank'] ? htmlspecialchars($transaction['bank']) : 'N/A'; ?></td>
                                            <td><?php echo $transaction['bank_account_name'] ? htmlspecialchars($transaction['bank_account_name']) : 'N/A'; ?></td>
                                            <td><?php echo htmlspecialchars($customer_name); ?></td>
                                            <td><?php echo $transaction['reference_number'] ? htmlspecialchars($transaction['reference_number']) : 'N/A'; ?></td>
                                            <td><?php echo $transaction['notes'] ? htmlspecialchars($transaction['notes']) : 'N/A'; ?></td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-primary edit-btn" 
                                                        data-id="<?php echo $transaction['id']; ?>"
                                                        data-date="<?php echo htmlspecialchars($formatted_date); ?>"
                                                        data-type="<?php echo htmlspecialchars($transaction['type']); ?>"
                                                        data-amount="<?php echo number_format($transaction['amount'], 2); ?>"
                                                        data-fee="<?php echo number_format($transaction['fee'], 2); ?>"
                                                        data-branch="<?php echo htmlspecialchars($transaction['branch']); ?>"
                                                        data-reference="<?php echo htmlspecialchars($transaction['reference_number']); ?>"
                                                        data-notes="<?php echo htmlspecialchars($transaction['notes']); ?>"
                                                        data-area="<?php echo htmlspecialchars($transaction['area']); ?>"
                                                        data-customer-id="<?php echo $transaction['customer_id']; ?>"
                                                        data-bank="<?php echo htmlspecialchars($transaction['bank']); ?>"
                                                        data-bank-account-name="<?php echo htmlspecialchars($transaction['bank_account_name']); ?>">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline-danger delete-btn" data-id="<?php echo $transaction['id']; ?>">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Cashbook Section -->
                        <div class="card dash-card mb-4">
                            <header class="dash-card__head">
                                <span class="dash-card__icon"><i class="fas fa-book" aria-hidden="true"></i></span>
                                <div>
                                    <h2 class="dash-card__title">GCash Cashbook</h2>
                                    <p class="dash-card__hint">Credit, debit and running balance of the GCash ledger.</p>
                                </div>
                            </header>
                            <div class="card-body">
                                <section class="dash-stats dash-stats--three" aria-label="GCash cashbook summary">
                                    <article class="stat stat--green">
                                        <div class="stat__body">
                                            <p class="stat__label">Total Credit</p>
                                            <p class="stat__value">₱<?php echo number_format($total_credit, 2); ?></p>
                                            <p class="stat__meta">Money in on the GCash ledger</p>
                                        </div>
                                    </article>

                                    <article class="stat stat--navy">
                                        <div class="stat__body">
                                            <p class="stat__label">Total Debit</p>
                                            <p class="stat__value">₱<?php echo number_format($total_debit, 2); ?></p>
                                            <p class="stat__meta">Money out on the GCash ledger</p>
                                        </div>
                                    </article>

                                    <article class="stat stat--cyan">
                                        <div class="stat__body">
                                            <p class="stat__label">Ending Balance</p>
                                            <p class="stat__value">₱<?php echo number_format($ending_balance, 2); ?></p>
                                            <p class="stat__meta">Balance carried forward</p>
                                        </div>
                                    </article>
                                </section>
                                
                                <table id="cashbookTable" class="table table-striped table-bordered dash-table">
                                    <thead>
                                        <tr>
                                            <th>Date and Time</th>
                                            <th>Name</th>
                                            <th>Type</th>
                                            <th>Description</th>
                                            <th>Branch</th>
                                            <th>Debit</th>
                                            <th>Credit</th>
                                            <th>Balance</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($payments as $payment): 
                                            // Format the date
                                            $formatted_date = date("m-d-Y h:i:s A", strtotime($payment['created_at']));
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($formatted_date); ?></td>
                                            <td><?php echo htmlspecialchars($payment['customer_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars(ucfirst($payment['type'])); ?></td>
                                            <td><?php echo htmlspecialchars($payment['notes']); ?></td>
                                            <td><?php echo $payment['branch'] ? htmlspecialchars($payment['branch']) : 'N/A'; ?></td>
                                            <td><?php echo isset($payment['debit']) && $payment['debit'] !== null ? '₱' . number_format($payment['debit'], 2) : '₱0.00'; ?></td>
                                            <td><?php echo isset($payment['credit']) && $payment['credit'] !== null ? '₱' . number_format($payment['credit'], 2) : '₱0.00'; ?></td>
                                            <td>₱<?php echo number_format($payment['balance'], 2); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </main>

                <?php include __DIR__ . '/includes/footer.php'; ?>
            </div>
        </div>

        <!-- Edit Transaction Modal -->
        <div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editModalLabel">Edit Transaction</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form action="gcash_in_out.php" method="POST" id="editForm">
                        <div class="modal-body">
                            <input type="hidden" name="edit_id" id="edit_id">
                            <div class="row mb-3">
                                <div class="col-md-3">
                                    <div class="form-floating">
                                        <input class="form-control" id="edit_date" name="edit_date" type="text" required />
                                        <label for="edit_date">Transaction Date & Time</label>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="form-floating">
                                        <select class="form-control" id="edit_type" name="edit_type" required>
                                            <option value="Cash In">Cash In</option>
                                            <option value="Cash Out">Cash Out</option>
                                            <option value="Beginning Balance">Beginning Balance</option>
                                            <option value="Fund Transfer">Fund Transfer</option>
                                        </select>
                                        <label for="edit_type">Transaction Type</label>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="form-floating">
                                        <?php if (in_array($user_account_type, $restricted_account_types)): ?>
                                            <!-- Restricted users - show readonly field -->
                                            <input class="form-control restricted-field" type="text" 
                                                   id="edit_branch_display" 
                                                   value="<?php echo htmlspecialchars($user_branch); ?>" 
                                                   readonly 
                                                   title="Your account type is restricted to this branch" />
                                            <label>Branch</label>
                                            <input type="hidden" name="edit_branch" id="edit_branch_hidden" value="<?php echo htmlspecialchars($user_branch); ?>">
                                        <?php elseif (in_array($user_account_type, $multi_branch_account_types)): ?>
                                            <!-- Admin and Area Manager - show branch dropdown -->
                                            <select class="form-control" id="edit_branch" name="edit_branch" required>
                                                <option value="">Select Branch</option>
                                                <?php foreach ($user_branches as $branch): ?>
                                                    <option value="<?php echo htmlspecialchars($branch); ?>"><?php echo htmlspecialchars($branch); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <label for="edit_branch">Branch</label>
                                        <?php else: ?>
                                            <!-- Other account types - show readonly field -->
                                            <input class="form-control restricted-field" type="text" 
                                                   id="edit_branch_display" 
                                                   value="<?php echo htmlspecialchars($user_branch); ?>" 
                                                   readonly />
                                            <label>Branch</label>
                                            <input type="hidden" name="edit_branch" id="edit_branch_hidden" value="<?php echo htmlspecialchars($user_branch); ?>">
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="form-floating">
                                        <select class="form-control" id="edit_area" name="edit_area">
                                            <option value="">Select Area</option>
                                            <?php foreach ($areas as $area): ?>
                                                <option value="<?php echo htmlspecialchars($area); ?>"><?php echo htmlspecialchars($area); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <label for="edit_area">Area</label>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-floating mb-3 mb-md-0">
                                        <input class="form-control" id="edit_amount" name="edit_amount" type="text" required />
                                        <label for="edit_amount">Amount</label>
                                    </div>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-md-3">
                                    <div class="form-floating mb-3 mb-md-0">
                                        <input class="form-control" id="edit_reference" name="edit_reference" type="text" />
                                        <label for="edit_reference">Reference Number</label>
                                    </div>
                                </div>
                                <div class="col-md-2 edit-fee-section" id="editFeeSection">
                                    <div class="form-floating">
                                        <input class="form-control" id="edit_fee" name="edit_fee" type="text" placeholder="Enter fee" />
                                        <label for="edit_fee">Fee Amount</label>
                                    </div>
                                </div>
                                <div class="col-md-3 edit-bank-section">
                                    <div class="form-floating">
                                        <select class="form-control" id="edit_bank" name="edit_bank">
                                            <option value="">Select Bank</option>
                                            <?php foreach ($banks as $bank): ?>
                                                <option value="<?php echo htmlspecialchars($bank); ?>"><?php echo htmlspecialchars($bank); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <label for="edit_bank">Bank</label>
                                    </div>
                                </div>
                                <div class="col-md-4 edit-bank-account-section">
                                    <div class="form-floating">
                                        <input class="form-control" id="edit_bank_account_name" name="edit_bank_account_name" type="text" placeholder="Enter bank account name" />
                                        <label for="edit_bank_account_name">Bank Account Name</label>
                                    </div>
                                </div>
                                <div class="col-md-6 edit-customer-section">
                                    <div class="customer-search-container">
                                        <div class="form-floating">
                                            <input type="text" class="form-control customer-search-input" id="edit_customer_search" placeholder="Search customer..." autocomplete="off">
                                            <label for="edit_customer_search">Search Customer</label>
                                        </div>
                                        <button type="button" class="customer-clear-btn" id="edit_clear_customer" aria-label="Clear selected customer" hidden>
                                            <i class="fas fa-times" aria-hidden="true"></i>
                                        </button>
                                        <div class="customer-dropdown-container">
                                            <div class="customer-dropdown" id="edit_customer_dropdown"></div>
                                        </div>
                                        <span id="edit_selected_customer_name" hidden></span>
                                        <span id="edit_selected_customer" hidden></span>
                                    </div>
                                    <input type="hidden" id="edit_customer_id" name="edit_customer_id">
                                </div>
                            </div>
                            <div class="mb-3">
                                <div class="form-floating">
                                    <textarea class="form-control" id="edit_notes" name="edit_notes" placeholder="Enter notes" style="height: 100px"></textarea>
                                    <label for="edit_notes">Notes</label>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script type="application/json" id="gcash-in-out-data"><?php echo json_encode($pageConfig, $jsonFlags); ?></script>

        <!-- Load jQuery first -->
        <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

        <!-- Then load Bootstrap (which depends on jQuery) -->
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>

        <!-- Then load other scripts that depend on jQuery -->
        <script src="js/scripts.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/umd/simple-datatables.min.js" crossorigin="anonymous"></script>
        <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <script src="https://cdn.datatables.net/1.13.1/js/jquery.dataTables.min.js"></script>
        <script src="assets/js/gcash-in-out.js"></script>
    </body>
</html>
