<?php
/**
 * Prime City - Expenses page.
 *
 * Layout only: data comes from actions/add-expenses-actions.php, layout chrome
 * from includes/*, styles from assets/css and behaviour from assets/js.
 */

require_once __DIR__ . '/actions/add-expenses-actions.php';

try {
    $context = ae_context();
} catch (Throwable $e) {
    error_log('[add-expenses] ' . $e->getMessage());
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
        <head>
            <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
            <meta charset="utf-8" />
            <title>Add Expenses - Unavailable</title>
            <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        </head>
        <body class="bg-light">
            <div class="container py-5">
                <div class="alert alert-danger">
                    <h4 class="alert-heading">The expenses page is unavailable</h4>
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
$all_branches = $context['all_branches'];
$available_branches = $context['available_branches'];
$available_branches_for_form = $context['available_branches_for_form'];
$can_see_branch_filter = $context['can_see_branch_filter'];
$user_branches_for_filter = $context['user_branches_for_filter'];
$is_restricted_user = $context['is_restricted_user'];
$filter = $context['filter'];
$branch_filter = $context['branch_filter'];
$expenses = $context['expenses'];
$total_summary = $context['total_summary'];
$current_datetime = $context['current_datetime'];
$success_message = $context['success_message'];
$error_message = $context['error_message'];

// Shared layout includes read these.
$layout_active = 'add-expenses';
$layout_menu = $context['layout_menu'];

$pageConfig = array(
    'success' => $success_message,
    'error'   => $error_message,
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
        <meta name="description" content="Prime City expenses" />
        <meta name="author" content="Prime City" />
        <title>Add Expenses - Prime City Admin</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet" />
        <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        <link href="assets/css/dash.css" rel="stylesheet" />
        <link href="assets/css/dash-ui.css" rel="stylesheet" />
        <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
        <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
        <link href="assets/css/add-expenses-style.css?v=<?php echo is_file(__DIR__ . '/assets/css/add-expenses-style.css') ? filemtime(__DIR__ . '/assets/css/add-expenses-style.css') : 1; ?>" rel="stylesheet" />
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
                                    <i class="fas fa-receipt" aria-hidden="true"></i>
                                    Prime City &middot; Finance
                                </p>
                                <h1 class="dash-title">Add Expenses</h1>
                                <p class="dash-sub">Log the branch operating expenses for a day and review past records.</p>
                            </div>

                            <div class="dash-head__actions">
                                <a class="btn btn-primary" href="admin.php">
                                    <i class="fas fa-arrow-left me-1" aria-hidden="true"></i>
                                    Back to dashboard
                                </a>
                            </div>
                        </header>
                        
                        <div class="card expense-card dash-card mb-4">
                            <header class="dash-card__head">
                                <span class="dash-card__icon"><i class="fas fa-money-bill-wave" aria-hidden="true"></i></span>
                                <div>
                                    <h2 class="dash-card__title">Expense Record</h2>
                                    <p class="dash-card__hint">Date, branch and the expense items for this entry</p>
                                </div>
                            </header>
                            <div class="card-body">
                                <form id="expenseForm" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST">
                                    <div class="form-section">
                                        <h5 class="section-title">Entry Details</h5>
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <div class="form-floating">
                                                <input type="datetime-local" class="form-control" id="expense_date" name="expense_date" 
                                                       value="<?php echo $current_datetime; ?>" required placeholder="Date">
                                                <label for="expense_date">Date</label>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-floating">
                                                <select class="form-select <?php echo $is_restricted_user ? 'restricted-branch' : ''; ?>" 
                                                        id="branch" name="branch" required 
                                                        <?php echo $is_restricted_user ? 'disabled' : ''; ?>>
                                                    <?php foreach ($available_branches_for_form as $branch_option): ?>
                                                        <option value="<?php echo htmlspecialchars($branch_option); ?>" <?php echo $branch_option === 'Tuguegarao City' ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($branch_option); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <label for="branch">Branch</label>
                                                <?php if ($is_restricted_user && !empty($available_branches_for_form)): ?>
                                                    <input type="hidden" name="branch" value="<?php echo htmlspecialchars($available_branches_for_form[0]); ?>">
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    </div>
                                    
                                    <div class="form-section">
                                        <h5 class="section-title">Expense Items</h5>
                                        <div class="row g-3">
                                            <div class="col-md-3">
                                                <div class="form-floating">
                                                    <input type="text" class="form-control amount-input" id="per_diem" name="per_diem" 
                                                           placeholder="Per Diem" oninput="formatNumber(this); calculateTotal()">
                                                    <label for="per_diem">Per Diem</label>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-floating">
                                                    <input type="text" class="form-control amount-input" id="load_allowance" name="load_allowance" 
                                                           placeholder="Load Allowance" oninput="formatNumber(this); calculateTotal()">
                                                    <label for="load_allowance">Load Allowance</label>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-floating">
                                                    <input type="text" class="form-control amount-input" id="salary" name="salary" 
                                                           placeholder="Salary" oninput="formatNumber(this); calculateTotal()">
                                                    <label for="salary">Salary</label>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-floating">
                                                    <input type="text" class="form-control amount-input" id="rental" name="rental" 
                                                           placeholder="Rental" oninput="formatNumber(this); calculateTotal()">
                                                    <label for="rental">Rental</label>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-floating">
                                                    <input type="text" class="form-control amount-input" id="bir" name="bir" 
                                                           placeholder="BIR" oninput="formatNumber(this); calculateTotal()">
                                                    <label for="bir">BIR</label>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-floating">
                                                    <input type="text" class="form-control amount-input" id="allowance" name="allowance" 
                                                           placeholder="Allowance" oninput="formatNumber(this); calculateTotal()">
                                                    <label for="allowance">Allowance</label>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-floating">
                                                    <input type="text" class="form-control amount-input" id="office_supplies" name="office_supplies" 
                                                           placeholder="Office Supplies" oninput="formatNumber(this); calculateTotal()">
                                                    <label for="office_supplies">Office Supplies</label>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-floating">
                                                    <input type="text" class="form-control amount-input" id="electric_bill" name="electric_bill" 
                                                           placeholder="Electric Bill" oninput="formatNumber(this); calculateTotal()">
                                                    <label for="electric_bill">Electric Bill</label>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-floating">
                                                    <input type="text" class="form-control amount-input" id="sss" name="sss" 
                                                           placeholder="SSS" oninput="formatNumber(this); calculateTotal()">
                                                    <label for="sss">SSS</label>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-floating">
                                                    <input type="text" class="form-control amount-input" id="philhealth" name="philhealth" 
                                                           placeholder="PhilHealth" oninput="formatNumber(this); calculateTotal()">
                                                    <label for="philhealth">PhilHealth</label>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-floating">
                                                    <input type="text" class="form-control amount-input" id="pagibig" name="pagibig" 
                                                           placeholder="Pag-IBIG" oninput="formatNumber(this); calculateTotal()">
                                                    <label for="pagibig">Pag-IBIG</label>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Total Expenses Section -->
                                        <div class="total-expenses mt-3">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <label>Total Expenses:</label>
                                                </div>
                                                <div class="col-md-6 text-end">
                                                    <span id="totalAmount" class="total-amount">0.00</span>
                                                    <input type="hidden" id="total_expenses" name="total_expenses" value="0">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="form-section">
                                        <h5 class="section-title">Remarks</h5>
                                        <div class="form-floating">
                                            <textarea class="form-control" id="remarks" name="remarks" rows="3" placeholder="Remarks" required></textarea>
                                            <label for="remarks">Remarks</label>
                                        </div>
                                    </div>
                                    
                                    <div class="dash-form__actions">
                                        <button type="submit" class="btn btn-primary">Save Expense</button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Expenses Table -->
                        <div class="card dash-card mb-4">
                            <header class="dash-card__head">
                                <span class="dash-card__icon"><i class="fas fa-table" aria-hidden="true"></i></span>
                                <div>
                                    <h2 class="dash-card__title">Expense Records</h2>
                                    <p class="dash-card__hint">Every recorded expense for the selected branch</p>
                                </div>
                                <div class="filter-buttons ms-auto">
                                    <div class="btn-group" role="group" aria-label="Expense filters">
                                        <a href="add_expenses.php?filter=all&branch=<?php echo $branch_filter; ?>" class="btn btn-sm <?php echo $filter == 'all' ? 'btn-primary' : 'btn-outline-primary'; ?>">All</a>
                                        <a href="add_expenses.php?filter=daily&branch=<?php echo $branch_filter; ?>" class="btn btn-sm <?php echo $filter == 'daily' ? 'btn-primary' : 'btn-outline-primary'; ?>">Daily</a>
                                        <a href="add_expenses.php?filter=weekly&branch=<?php echo $branch_filter; ?>" class="btn btn-sm <?php echo $filter == 'weekly' ? 'btn-primary' : 'btn-outline-primary'; ?>">Weekly</a>
                                        <a href="add_expenses.php?filter=monthly&branch=<?php echo $branch_filter; ?>" class="btn btn-sm <?php echo $filter == 'monthly' ? 'btn-primary' : 'btn-outline-primary'; ?>">Monthly</a>
                                    </div>
                                </div>
                            </header>
                            <div class="card-body">
                                <!-- Summary tiles (same band as the GCash Cashbook) -->
                                <section class="dash-stats dash-stats--three" aria-label="Expense summary">
                                    <article class="stat stat--green">
                                        <div class="stat__body">
                                            <p class="stat__label">Total Expenses</p>
                                            <p class="stat__value">&#8369;<?php echo number_format((float) ($total_summary['total_expenses'] ?? 0), 2); ?></p>
                                            <p class="stat__meta">Sum of every expense category below</p>
                                        </div>
                                        <span class="stat__icon"><i class="fas fa-money-bill-wave" aria-hidden="true"></i></span>
                                    </article>

                                    <article class="stat stat--cyan">
                                        <div class="stat__body">
                                            <p class="stat__label">Records</p>
                                            <p class="stat__value"><?php echo number_format(count($expenses)); ?></p>
                                            <p class="stat__meta">Expense entries in the current view</p>
                                        </div>
                                        <span class="stat__icon"><i class="fas fa-list-check" aria-hidden="true"></i></span>
                                    </article>

                                    <article class="stat stat--navy">
                                        <div class="stat__body">
                                            <p class="stat__label">Branch</p>
                                            <p class="stat__value"><?php echo $branch_filter === 'all' ? 'All Branches' : htmlspecialchars($branch_filter); ?></p>
                                            <p class="stat__meta">Scope of these expense records</p>
                                        </div>
                                        <span class="stat__icon"><i class="fas fa-code-branch" aria-hidden="true"></i></span>
                                    </article>
                                </section>
                                
                                <!-- Toolbar: search (left) + branch filter (right) -->
                                <div class="expense-toolbar">
                                    <div class="expense-toolbar__search">
                                        <i class="fas fa-search expense-toolbar__icon" aria-hidden="true"></i>
                                        <input type="search" id="expenseSearch" class="form-control expense-toolbar__input"
                                               placeholder="Search expenses..." autocomplete="off" aria-label="Search expense records" />
                                    </div>

                                <!-- Branch Filter Section -->
                                <?php 
                                // Define account types that should show filter
                                $single_branch_account_types = ['Branch Manager', 'Proxy Collector-Cashier', 'Proxy Collector'];
                                
                                // Show filter for Admin, Area Manager, and other non-restricted types
                                // Hide filter only for single-branch restricted users
                                $show_filter = !in_array($user_account_type, $single_branch_account_types);
                                ?>
                                
                                <?php if ($show_filter && $can_see_branch_filter && !empty($user_branches_for_filter)): ?>
                                <div class="filter-section">
                                    <div class="filter-row">
                                        <span class="filter-label">Filter by Branch:</span>
                                        <div class="branch-filter-dropdown">
                                            <select class="form-select" id="branchFilter" onchange="applyBranchFilter(this.value)">
                                                <?php if ($user_account_type === 'Admin'): ?>
                                                    <option value="all" <?php echo $branch_filter == 'all' ? 'selected' : ''; ?>>All Branches</option>
                                                <?php endif; ?>
                                                <?php foreach ($user_branches_for_filter as $branch_option): ?>
                                                    <option value="<?php echo htmlspecialchars($branch_option); ?>" <?php echo $branch_filter == $branch_option ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($branch_option); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <?php else: ?>
                                <!-- Hidden filter for restricted users with single branch -->
                                <div class="hidden-filter">
                                    <input type="hidden" id="branchFilter" value="<?php echo htmlspecialchars($branch_filter); ?>">
                                </div>
                                <?php endif; ?>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped table-hover dash-table" id="expensesTable" style="width:100%">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Expenses Date</th>
                                                <th>Branch</th>
                                                <th>Per Diem</th>
                                                <th>Load Allowance</th>
                                                <th>Salary</th>
                                                <th>Rental</th>
                                                <th>BIR</th>
                                                <th>Allowance</th>
                                                <th>Supplies</th>
                                                <th>Bills</th>
                                                <th>SSS</th>
                                                <th>PhilHealth</th>
                                                <th>Pag-IBIG</th>
                                                <th class="total-column">Total</th>
                                                <th>Remarks</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($expenses)): ?>
                                                <?php foreach ($expenses as $expense): ?>
                                                    <tr>
                                                        <td><?php echo date('M d, Y h:i A', strtotime($expense['expense_date'])); ?></td>
                                                        <td>
                                                            <span class="badge bg-secondary"><?php echo htmlspecialchars($expense['branch']); ?></span>
                                                        </td>
                                                        <td class="text-end"><?php echo number_format($expense['per_diem'], 2); ?></td>
                                                        <td class="text-end"><?php echo number_format($expense['load_allowance'], 2); ?></td>
                                                        <td class="text-end"><?php echo number_format($expense['salary'], 2); ?></td>
                                                        <td class="text-end"><?php echo number_format($expense['rental'], 2); ?></td>
                                                        <td class="text-end"><?php echo number_format($expense['bir'], 2); ?></td>
                                                        <td class="text-end"><?php echo number_format($expense['allowance'], 2); ?></td>
                                                        <td class="text-end"><?php echo number_format($expense['office_supplies'], 2); ?></td>
                                                        <td class="text-end"><?php echo number_format($expense['electric_bill'], 2); ?></td>
                                                        <td class="text-end"><?php echo number_format($expense['sss'], 2); ?></td>
                                                        <td class="text-end"><?php echo number_format($expense['philhealth'], 2); ?></td>
                                                        <td class="text-end"><?php echo number_format($expense['pagibig'], 2); ?></td>
                                                        <td class="text-end fw-bold"><?php echo number_format($expense['total_expenses'], 2); ?></td>
                                                        <td><?php echo htmlspecialchars($expense['remarks']); ?></td>
                                                        <td>
                                                            <div class="action-buttons">
                                                                <button class="btn btn-sm btn-warning" onclick="openEditModal(<?php echo $expense['id']; ?>)">
                                                                    <i class="fas fa-edit"></i>
                                                                </button>
                                                                <button onclick="confirmDelete(<?php echo $expense['id']; ?>)" class="btn btn-sm btn-danger">
                                                                    <i class="fas fa-trash-alt"></i>
                                                                </button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="16" class="text-center">No expense records found</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr class="summary-row">
                                                <td class="text-end fw-bold" colspan="2">Total:</td>
                                                <td class="text-end fw-bold"><?php echo number_format($total_summary['per_diem'], 2); ?></td>
                                                <td class="text-end fw-bold"><?php echo number_format($total_summary['load_allowance'], 2); ?></td>
                                                <td class="text-end fw-bold"><?php echo number_format($total_summary['salary'], 2); ?></td>
                                                <td class="text-end fw-bold"><?php echo number_format($total_summary['rental'], 2); ?></td>
                                                <td class="text-end fw-bold"><?php echo number_format($total_summary['bir'], 2); ?></td>
                                                <td class="text-end fw-bold"><?php echo number_format($total_summary['allowance'], 2); ?></td>
                                                <td class="text-end fw-bold"><?php echo number_format($total_summary['office_supplies'], 2); ?></td>
                                                <td class="text-end fw-bold"><?php echo number_format($total_summary['electric_bill'], 2); ?></td>
                                                <td class="text-end fw-bold"><?php echo number_format($total_summary['sss'], 2); ?></td>
                                                <td class="text-end fw-bold"><?php echo number_format($total_summary['philhealth'], 2); ?></td>
                                                <td class="text-end fw-bold"><?php echo number_format($total_summary['pagibig'], 2); ?></td>
                                                <td class="text-end fw-bold"><?php echo number_format($total_summary['total_expenses'], 2); ?></td>
                                                <td colspan="2"></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </main>

                <?php include __DIR__ . '/includes/footer.php'; ?>
            </div>
        </div>

        <!-- Edit Expense Modal -->
        <div class="modal fade" id="editExpenseModal" tabindex="-1" aria-labelledby="editExpenseModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <span class="exp-modal__icon"><i class="fas fa-pen-to-square" aria-hidden="true"></i></span>
                        <div class="exp-modal__heading">
                            <h5 class="modal-title" id="editExpenseModalLabel">Edit Expense Record</h5>
                            <p class="exp-modal__hint">Adjust the entry and its expense items</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form id="editExpenseForm" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="expense_id" id="edit_expense_id">
                            <div class="form-section">
                                <h5 class="section-title">Entry Details</h5>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="datetime-local" class="form-control" id="edit_expense_date" name="expense_date" required placeholder="Date">
                                        <label for="edit_expense_date">Date</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <select class="form-select <?php echo $is_restricted_user ? 'restricted-branch' : ''; ?>" 
                                                id="edit_branch" name="branch" required 
                                                <?php echo $is_restricted_user ? 'disabled' : ''; ?>>
                                            <?php foreach ($available_branches_for_form as $branch_option): ?>
                                                <option value="<?php echo htmlspecialchars($branch_option); ?>"><?php echo htmlspecialchars($branch_option); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <label for="edit_branch">Branch</label>
                                        <?php if ($is_restricted_user && !empty($available_branches_for_form)): ?>
                                            <input type="hidden" name="branch" value="<?php echo htmlspecialchars($available_branches_for_form[0]); ?>">
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            </div>
                            
                            <div class="form-section">
                                <h5 class="section-title">Expense Items</h5>
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <div class="form-floating">
                                            <input type="text" class="form-control amount-input" id="edit_per_diem" name="per_diem" 
                                                   placeholder="Per Diem" oninput="formatNumber(this); calculateEditTotal()">
                                            <label for="edit_per_diem">Per Diem</label>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-floating">
                                            <input type="text" class="form-control amount-input" id="edit_load_allowance" name="load_allowance" 
                                                   placeholder="Load Allowance" oninput="formatNumber(this); calculateEditTotal()">
                                            <label for="edit_load_allowance">Load Allowance</label>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-floating">
                                            <input type="text" class="form-control amount-input" id="edit_salary" name="salary" 
                                                   placeholder="Salary" oninput="formatNumber(this); calculateEditTotal()">
                                            <label for="edit_salary">Salary</label>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-floating">
                                            <input type="text" class="form-control amount-input" id="edit_rental" name="rental" 
                                                   placeholder="Rental" oninput="formatNumber(this); calculateEditTotal()">
                                            <label for="edit_rental">Rental</label>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-floating">
                                            <input type="text" class="form-control amount-input" id="edit_bir" name="bir" 
                                                   placeholder="BIR" oninput="formatNumber(this); calculateEditTotal()">
                                            <label for="edit_bir">BIR</label>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-floating">
                                            <input type="text" class="form-control amount-input" id="edit_allowance" name="allowance" 
                                                   placeholder="Allowance" oninput="formatNumber(this); calculateEditTotal()">
                                            <label for="edit_allowance">Allowance</label>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-floating">
                                            <input type="text" class="form-control amount-input" id="edit_office_supplies" name="office_supplies" 
                                                   placeholder="Office Supplies" oninput="formatNumber(this); calculateEditTotal()">
                                            <label for="edit_office_supplies">Office Supplies</label>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-floating">
                                            <input type="text" class="form-control amount-input" id="edit_electric_bill" name="electric_bill" 
                                                   placeholder="Electric Bill" oninput="formatNumber(this); calculateEditTotal()">
                                            <label for="edit_electric_bill">Electric Bill</label>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-floating">
                                            <input type="text" class="form-control amount-input" id="edit_sss" name="sss" 
                                                   placeholder="SSS" oninput="formatNumber(this); calculateEditTotal()">
                                            <label for="edit_sss">SSS</label>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-floating">
                                            <input type="text" class="form-control amount-input" id="edit_philhealth" name="philhealth" 
                                                   placeholder="PhilHealth" oninput="formatNumber(this); calculateEditTotal()">
                                            <label for="edit_philhealth">PhilHealth</label>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-floating">
                                            <input type="text" class="form-control amount-input" id="edit_pagibig" name="pagibig" 
                                                   placeholder="Pag-IBIG" oninput="formatNumber(this); calculateEditTotal()">
                                            <label for="edit_pagibig">Pag-IBIG</label>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Total Expenses Section -->
                                <div class="total-expenses mt-3">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <label>Total Expenses:</label>
                                        </div>
                                        <div class="col-md-6 text-end">
                                            <span id="edit_totalAmount" class="total-amount">0.00</span>
                                            <input type="hidden" id="edit_total_expenses" name="total_expenses" value="0">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-section">
                                <h5 class="section-title">Remarks</h5>
                                <div class="form-floating">
                                    <textarea class="form-control" id="edit_remarks" name="remarks" rows="3" placeholder="Remarks" required></textarea>
                                    <label for="edit_remarks">Remarks</label>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="submit" class="btn btn-primary">Update Expense</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script type="application/json" id="add-expenses-data"><?php echo json_encode($pageConfig, $jsonFlags); ?></script>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
        <script src="js/scripts.js"></script>
        <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
        <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
        <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <script src="assets/js/add-expenses.js?v=<?php echo is_file(__DIR__ . '/assets/js/add-expenses.js') ? filemtime(__DIR__ . '/assets/js/add-expenses.js') : 1; ?>"></script>
    </body>
</html>
