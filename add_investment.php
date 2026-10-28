<?php
/**
 * Prime City - Investments page.
 *
 * Layout only: data comes from actions/add-investment-actions.php, layout
 * chrome from includes/*, styles from assets/css and behaviour from assets/js.
 *
 * The edit form still posts to api/update_investment.php.
 */

require_once __DIR__ . '/actions/add-investment-actions.php';

try {
    $context = ai_context();
} catch (Throwable $e) {
    error_log('[add-investment] ' . $e->getMessage());
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
        <head>
            <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
            <meta charset="utf-8" />
            <title>Add Investment - Unavailable</title>
            <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        </head>
        <body class="bg-light">
            <div class="container py-5">
                <div class="alert alert-danger">
                    <h4 class="alert-heading">The investment page is unavailable</h4>
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
$filter = $context['filter'];
$branch_filter = $context['branch_filter'];
$investments = $context['investments'];
$hide_branch_filter = $context['hide_branch_filter'];
$show_all_branches_option = $context['show_all_branches_option'];
$error = $context['error'];
$success = $context['success'];

// Shared layout includes read these.
$layout_active = 'add-investment';
$layout_menu = $context['layout_menu'];

$pageConfig = array(
    'error'   => $error,
    'success' => $success,
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
        <meta name="description" content="Prime City investments" />
        <meta name="author" content="Prime City" />
        <title>Add Investment - Prime City Admin</title>
        <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
        <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        <link href="assets/css/dash.css" rel="stylesheet" />
        <link href="assets/css/dash-ui.css" rel="stylesheet" />
        <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
        <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
        <link href="assets/css/add-investment-style.css" rel="stylesheet" />
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
                                    <i class="fas fa-wallet" aria-hidden="true"></i>
                                    Prime City &middot; Finance
                                </p>
                                <h1 class="dash-title">Investment</h1>
                                <p class="dash-sub">Record capital movements and review the branch investment history.</p>
                            </div>

                            <div class="dash-head__actions">
                                <a class="btn btn-primary" href="admin.php">
                                    <i class="fas fa-arrow-left me-1" aria-hidden="true"></i>
                                    Back to dashboard
                                </a>
                            </div>
                        </header>
                        
                        <div class="card dash-card mb-4">
                            <header class="dash-card__head">
                                <span class="dash-card__icon"><i class="fas fa-plus-circle" aria-hidden="true"></i></span>
                                <div>
                                    <h2 class="dash-card__title">Add New Investment</h2>
                                    <p class="dash-card__hint">Date, type, branch and amount of the movement</p>
                                </div>
                            </header>
                            <div class="card-body">
                                <form action="add_investment.php" method="POST" id="investmentForm">
                                    <div class="form-section">
                                        <h5 class="section-title">Investment Details</h5>
                                    <div class="row mb-3">
                                        <div class="col-md-3">
                                            <div class="form-floating">
                                                <input class="form-control" id="date" name="date" type="text" placeholder="Select date and time" required />
                                                <label for="date">Investment Date & Time</label>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-floating">
                                                <select class="form-control" id="type" name="type" required>
                                                    <option value="">Select Type</option>
                                                    <option value="Deposited">Deposited</option>
                                                    <option value="Withdraw">Withdraw</option>
                                                    <option value="Other Income">Other Income</option>
                                                    <option value="Beginning Balance">Beginning Balance</option>
                                                </select>
                                                <label for="type">Investment Type</label>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-floating">
                                                <select class="form-control" id="branch" name="branch" required <?php echo (in_array($user['account_type'], ['Branch Manager', 'Proxy Collector-Cashier', 'Proxy Collector']) || (in_array($user['account_type'], ['Area Manager']) && count($user_branches) === 1)) ? 'disabled' : ''; ?>>
                                                    <option value="">Select Branch</option>
                                                    <?php foreach ($user_branches as $branch_option): ?>
                                                        <option value="<?php echo htmlspecialchars($branch_option); ?>" 
                                                            <?php echo ((in_array($user['account_type'], ['Branch Manager', 'Proxy Collector-Cashier', 'Proxy Collector']) && count($user_branches) === 1) || (in_array($user['account_type'], ['Area Manager']) && count($user_branches) === 1)) ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($branch_option); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <label for="branch">Branch</label>
                                                <?php if (in_array($user['account_type'], ['Branch Manager', 'Proxy Collector-Cashier', 'Proxy Collector']) || (in_array($user['account_type'], ['Area Manager']) && count($user_branches) === 1)): ?>
                                                    <input type="hidden" name="branch" value="<?php echo htmlspecialchars($user_branches[0]); ?>">
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-floating mb-3 mb-md-0">
                                                <input class="form-control" id="amount" name="amount" type="text" placeholder="Enter amount" required />
                                                <label for="amount">Amount</label>
                                            </div>
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
                                            <button type="submit" class="btn btn-primary btn-block">Add Investment</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Investment Table -->
                        <div class="card dash-card mb-4 investment-table">
                            <header class="dash-card__head">
                                <span class="dash-card__icon"><i class="fas fa-table" aria-hidden="true"></i></span>
                                <div>
                                    <h2 class="dash-card__title">Investment History</h2>
                                    <p class="dash-card__hint">Deposits, withdrawals and other income on record</p>
                                </div>
                                <div class="filter-buttons ms-auto">
                                    <div class="btn-group" role="group" aria-label="Investment filters">
                                        <?php
                                        // Build URLs for filter buttons that preserve branch selection
                                        $all_url = "add_investment.php?filter=all&branch=" . urlencode($branch_filter);
                                        $daily_url = "add_investment.php?filter=daily&branch=" . urlencode($branch_filter);
                                        $weekly_url = "add_investment.php?filter=weekly&branch=" . urlencode($branch_filter);
                                        $monthly_url = "add_investment.php?filter=monthly&branch=" . urlencode($branch_filter);
                                        ?>
                                        <a href="<?php echo $all_url; ?>" class="btn btn-sm <?php echo $filter == 'all' ? 'btn-primary' : 'btn-outline-primary'; ?>">All</a>
                                        <a href="<?php echo $daily_url; ?>" class="btn btn-sm <?php echo $filter == 'daily' ? 'btn-primary' : 'btn-outline-primary'; ?>">Daily</a>
                                        <a href="<?php echo $weekly_url; ?>" class="btn btn-sm <?php echo $filter == 'weekly' ? 'btn-primary' : 'btn-outline-primary'; ?>">Weekly</a>
                                        <a href="<?php echo $monthly_url; ?>" class="btn btn-sm <?php echo $filter == 'monthly' ? 'btn-primary' : 'btn-outline-primary'; ?>">Monthly</a>
                                    </div>
                                </div>
                            </header>
                            <div class="card-body">
                                <!-- Search (left) + Branch filter (right) -->
                                <div class="history-toolbar">
                                    <div class="history-toolbar__search" id="historySearchSlot">
                                        <i class="fas fa-search history-toolbar__icon" aria-hidden="true"></i>
                                        <input type="search" id="investmentSearch" class="form-control history-toolbar__input"
                                               placeholder="Search investments..." autocomplete="off"
                                               aria-label="Search investments" />
                                    </div>
                                    <div class="filter-section <?php echo $hide_branch_filter ? 'hidden-filter' : ''; ?>">
                                        <div class="filter-row">
                                            <span class="filter-label">Filter by Branch:</span>
                                            <div class="branch-filter-dropdown">
                                                <select class="form-select" id="branchFilter" onchange="applyBranchFilter(this.value)">
                                                    <?php if ($show_all_branches_option): ?>
                                                        <option value="all" <?php echo $branch_filter == 'all' ? 'selected' : ''; ?>>All Branches</option>
                                                    <?php endif; ?>
                                                    <?php foreach ($user_branches as $branch_option): ?>
                                                        <option value="<?php echo htmlspecialchars($branch_option); ?>" 
                                                            <?php echo $branch_filter == $branch_option ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($branch_option); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped table-hover dash-table" id="datatablesSimple">
                                        <thead>
                                            <tr>
                                                <th class="date-time-column">Date & Time</th>
                                                <th class="branch-column">Branch</th>
                                                <th>Type</th>
                                                <th>Amount</th>
                                                <th>Notes</th>
                                                <th class="action-column">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($investments)): ?>
                                                <?php foreach ($investments as $investment): 
                                                    // Extract just the note content without the type prefix
                                                    $note_parts = explode(' - ', $investment['notes'], 2);
                                                    $clean_note = count($note_parts) > 1 ? $note_parts[1] : $investment['notes'];
                                                ?>
                                                    <tr>
                                                        <td>
                                                            <?php 
                                                                // Format date for display with seconds
                                                                $date = DateTime::createFromFormat('Y-m-d H:i:s', $investment['date']);
                                                                echo $date ? $date->format('m-d-Y h:i:s A') : $investment['date'];
                                                            ?>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($investment['branch']); ?></td>
                                                        <td><?php echo htmlspecialchars($investment['type']); ?></td>
                                                        <td>₱<?php echo number_format($investment['amount'], 2); ?></td>
                                                        <td><?php echo htmlspecialchars($investment['notes']); ?></td>
                                                        <td>
                                                            <button class="btn btn-info btn-sm btn-action view-btn" data-id="<?php echo $investment['id']; ?>" 
                                                                    data-date="<?php echo $date ? $date->format('m-d-Y h:i:s A') : $investment['date']; ?>"
                                                                    data-branch="<?php echo htmlspecialchars($investment['branch']); ?>"
                                                                    data-type="<?php echo htmlspecialchars($investment['type']); ?>"
                                                                    data-amount="₱<?php echo number_format($investment['amount'], 2); ?>"
                                                                    data-notes="<?php echo htmlspecialchars($investment['notes']); ?>"
                                                                    data-bs-toggle="modal" data-bs-target="#viewModal">
                                                                <i class="fas fa-eye"></i>
                                                            </button>
                                                            <button class="btn btn-warning btn-sm btn-action edit-btn" data-id="<?php echo $investment['id']; ?>" 
                                                                    data-date="<?php echo $date ? $date->format('Y-m-d H:i:s') : $investment['date']; ?>"
                                                                    data-branch="<?php echo htmlspecialchars($investment['branch']); ?>"
                                                                    data-type="<?php echo htmlspecialchars($investment['type']); ?>"
                                                                    data-amount="<?php echo $investment['amount']; ?>"
                                                                    data-notes="<?php echo htmlspecialchars($clean_note); ?>"
                                                                    data-bs-toggle="modal" data-bs-target="#editModal">
                                                                <i class="fas fa-edit"></i>
                                                            </button>
                                                            <button class="btn btn-danger btn-sm btn-action delete-btn" data-id="<?php echo $investment['id']; ?>" data-bs-toggle="modal" data-bs-target="#deleteModal">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="6" class="text-center">No investments found.</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </main>

                <?php include __DIR__ . '/includes/footer.php'; ?>
            </div>
        </div>

        <!-- View Modal -->
        <div class="modal fade" id="viewModal" tabindex="-1" aria-labelledby="viewModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <span class="inv-modal__icon"><i class="fas fa-receipt" aria-hidden="true"></i></span>
                        <div class="inv-modal__heading">
                            <h5 class="modal-title" id="viewModalLabel">Investment Details</h5>
                            <p class="inv-modal__hint">Recorded capital movement</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="inv-view">
                            <div class="inv-view__item">
                                <span class="inv-view__label">Date &amp; Time</span>
                                <span class="inv-view__value" id="view-date"></span>
                            </div>
                            <div class="inv-view__item">
                                <span class="inv-view__label">Branch</span>
                                <span class="inv-view__value" id="view-branch"></span>
                            </div>
                            <div class="inv-view__item">
                                <span class="inv-view__label">Type</span>
                                <span class="inv-view__value" id="view-type"></span>
                            </div>
                            <div class="inv-view__item">
                                <span class="inv-view__label">Amount</span>
                                <span class="inv-view__value inv-view__value--amount" id="view-amount"></span>
                            </div>
                            <div class="inv-view__item inv-view__item--wide">
                                <span class="inv-view__label">Notes</span>
                                <span class="inv-view__value inv-view__value--notes" id="view-notes"></span>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary inv-modal__close" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit Modal -->
        <div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title" id="editModalLabel">Edit Investment</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form id="editForm" method="POST" action="api/update_investment.php">
                        <div class="modal-body">
                            <input type="hidden" id="edit-id" name="id">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="edit-date" class="form-label">Date & Time <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="edit-date" name="date" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="edit-branch" class="form-label">Branch <span class="text-danger">*</span></label>
                                    <select class="form-control" id="edit-branch" name="branch" required <?php echo (in_array($user['account_type'], ['Branch Manager', 'Proxy Collector-Cashier', 'Proxy Collector']) || (in_array($user['account_type'], ['Area Manager']) && count($user_branches) === 1)) ? 'disabled' : ''; ?>>
                                        <?php foreach ($user_branches as $branch_option): ?>
                                            <option value="<?php echo htmlspecialchars($branch_option); ?>"><?php echo htmlspecialchars($branch_option); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (in_array($user['account_type'], ['Branch Manager', 'Proxy Collector-Cashier', 'Proxy Collector']) || (in_array($user['account_type'], ['Area Manager']) && count($user_branches) === 1)): ?>
                                        <input type="hidden" name="branch" value="<?php echo htmlspecialchars($user_branches[0]); ?>">
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="edit-type" class="form-label">Type <span class="text-danger">*</span></label>
                                    <select class="form-control" id="edit-type" name="type" required>
                                        <option value="Beginning Balance">Beginning Balance</option>
                                        <option value="Deposited">Deposited</option>
                                        <option value="Withdraw">Withdraw</option>
                                        <option value="Other Income">Other Income</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="edit-amount" class="form-label">Amount <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="edit-amount" name="amount" required>
                                </div>
                                <div class="col-md-12 mb-3">
                                    <label for="edit-notes" class="form-label">Notes</label>
                                    <textarea class="form-control" id="edit-notes" name="notes" rows="3"></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title" id="deleteModalLabel">Confirm Deletion</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="text-center mb-4">
                            <i class="fas fa-exclamation-triangle fa-3x text-danger mb-3"></i>
                            <h5>Are you sure you want to delete this investment record?</h5>
                            <p class="text-muted">This action cannot be undone.</p>
                        </div>
                        <input type="hidden" id="delete-id">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-danger" id="confirm-delete">
                            <i class="fas fa-trash me-1"></i> Delete
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <script type="application/json" id="add-investment-data"><?php echo json_encode($pageConfig, $jsonFlags); ?></script>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
        <script src="js/scripts.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/umd/simple-datatables.min.js" crossorigin="anonymous"></script>
        <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <script src="assets/js/add-investment.js"></script>
    </body>
</html>
