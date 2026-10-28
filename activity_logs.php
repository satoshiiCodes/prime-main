<?php
/**
 * Prime City - Activity logs page.
 *
 * Layout only: data comes from actions/activity-logs-actions.php, layout chrome
 * from includes/*, styles from assets/css and behaviour from assets/js.
 *
 * Clearing the log table is handled by api/clear_activity_logs.php, called
 * over AJAX from the page's script.
 */

require_once __DIR__ . '/actions/activity-logs-actions.php';

try {
    $context = al_context();
} catch (Throwable $e) {
    error_log('[activity-logs] ' . $e->getMessage());
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
        <head>
            <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
            <meta charset="utf-8" />
            <title>Activity Logs - Unavailable</title>
            <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        </head>
        <body class="bg-light">
            <div class="container py-5">
                <div class="alert alert-danger">
                    <h4 class="alert-heading">The activity log is unavailable</h4>
                    <p class="mb-0">We could not prepare this page right now. Please try again in a moment.</p>
                </div>
            </div>
        </body>
    </html>
    <?php
    exit;
}

$user = $context['user'];
$is_admin = $context['is_admin'];
$page = $context['page'];
$filter_user = $context['filter_user'];
$filter_action = $context['filter_action'];
$filter_date_from = $context['filter_date_from'];
$filter_date_to = $context['filter_date_to'];
$activity_logs = $context['activity_logs'];
$total_records = $context['total_records'];
$total_pages = $context['total_pages'];
$distinct_actions = $context['distinct_actions'];
$users = $context['users'];

// Shared layout includes read these.
$layout_active = 'activity-logs';
$layout_menu = $context['layout_menu'];
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
        <meta charset="utf-8" />
        <meta http-equiv="X-UA-Compatible" content="IE=edge" />
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
        <meta name="description" content="Prime City activity logs" />
        <meta name="author" content="Prime City" />
        <title>Activity Logs - Prime City</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" />
        <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
        <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        <link href="assets/css/dash.css" rel="stylesheet" />
        <link href="assets/css/dash-ui.css" rel="stylesheet" />
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet" />
        <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
        <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
        <link href="assets/css/activity-logs-style.css?v=<?php echo is_file(__DIR__ . '/assets/css/activity-logs-style.css') ? filemtime(__DIR__ . '/assets/css/activity-logs-style.css') : 1; ?>" rel="stylesheet" />
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
                                <i class="fas fa-clipboard-list" aria-hidden="true"></i>
                                Prime City &middot; Reports
                            </p>
                            <h1 class="dash-title">Activity Logs</h1>
                            <p class="dash-sub">Every action recorded across the system, filterable by user, action and date.</p>
                        </div>

                        <div class="dash-head__actions">
                            <button class="btn btn-outline-danger" id="clearLogsBtn" <?php echo !$is_admin ? 'disabled' : ''; ?>>
                                <i class="fas fa-trash-alt" aria-hidden="true"></i> Clear All Logs
                            </button>
                        </div>
                    </header>
                    
                    <!-- Filter Section -->
                    <div class="card dash-card mb-4">
                        <header class="dash-card__head">
                            <span class="dash-card__icon"><i class="fas fa-filter" aria-hidden="true"></i></span>
                            <div>
                                <h2 class="dash-card__title">Filter Logs</h2>
                                <p class="dash-card__hint">Narrow the log down by user, action type or date range</p>
                            </div>
                        </header>
                        <div class="card-body">
                            <form method="GET" action="" class="log-filter">
                                <div class="filter-field">
                                    <label for="filter_user" class="filter-label">User</label>
                                    <select class="form-select" id="filter_user" name="filter_user">
                                        <option value="">All Users</option>
                                        <?php foreach ($users as $user): ?>
                                            <option value="<?php echo $user['id']; ?>" <?php echo $filter_user == $user['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($user['username'] . ' (' . $user['name'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="filter-field">
                                    <label for="filter_action" class="filter-label">Action</label>
                                    <select class="form-select" id="filter_action" name="filter_action">
                                        <option value="">All Actions</option>
                                        <?php foreach ($distinct_actions as $action): ?>
                                            <option value="<?php echo htmlspecialchars($action); ?>" <?php echo $filter_action == $action ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($action); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="filter-field">
                                    <label for="date_from" class="filter-label">Date From</label>
                                    <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>">
                                </div>

                                <div class="filter-field">
                                    <label for="date_to" class="filter-label">Date To</label>
                                    <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>">
                                </div>

                                <div class="filter-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-filter me-1"></i> Apply Filters
                                    </button>
                                    <a href="activity_logs.php" class="btn btn-secondary">
                                        <i class="fas fa-undo me-1"></i> Clear
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Summary Cards -->
                    <div class="dash-stats">
                        <article class="stat stat--cyan">
                            <div class="stat__body">
                                <p class="stat__label">Total Logs</p>
                                <p class="stat__value"><?php echo number_format($total_records); ?></p>
                                <p class="stat__meta">Entries matching the filters</p>
                            </div>
                            <span class="stat__icon"><i class="fas fa-history" aria-hidden="true"></i></span>
                        </article>

                        <article class="stat stat--navy">
                            <div class="stat__body">
                                <p class="stat__label">Unique Users</p>
                                <p class="stat__value"><?php echo count($users); ?></p>
                                <p class="stat__meta">Accounts that generated logs</p>
                            </div>
                            <span class="stat__icon"><i class="fas fa-users" aria-hidden="true"></i></span>
                        </article>

                        <article class="stat stat--green">
                            <div class="stat__body">
                                <p class="stat__label">Action Types</p>
                                <p class="stat__value"><?php echo count($distinct_actions); ?></p>
                                <p class="stat__meta">Distinct actions recorded</p>
                            </div>
                            <span class="stat__icon"><i class="fas fa-list" aria-hidden="true"></i></span>
                        </article>

                        <article class="stat stat--cyan">
                            <div class="stat__body">
                                <p class="stat__label">Date Range</p>
                                <p class="stat__value stat__value--text">
                                    <?php 
                                        if ($activity_logs) {
                                            $first = new DateTime($activity_logs[count($activity_logs)-1]['created_at']);
                                            $last = new DateTime($activity_logs[0]['created_at']);
                                            echo $first->format('M j, Y') . ' - ' . $last->format('M j, Y');
                                        } else {
                                            echo 'No data';
                                        }
                                    ?>
                                </p>
                                <p class="stat__meta">Oldest to newest entry shown</p>
                            </div>
                            <span class="stat__icon"><i class="fas fa-calendar" aria-hidden="true"></i></span>
                        </article>
                    </div>
                    
                    <!-- Activity Logs Section -->
                    <div class="card dash-card mb-4">
                        <header class="dash-card__head">
                            <span class="dash-card__icon"><i class="fas fa-clipboard-list" aria-hidden="true"></i></span>
                            <div>
                                <h2 class="dash-card__title">Activity Logs</h2>
                                <p class="dash-card__hint">Newest entries first, paged for easy reading</p>
                            </div>
                            <span class="dash-card__badge"><i class="fas fa-list-ol" aria-hidden="true"></i> <?php echo number_format($total_records); ?> entries</span>
                        </header>
                        <div class="card-body">
                            <?php if (count($activity_logs) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover dash-table">
                                        <thead>
                                            <tr>
                                                <th>Date & Time</th>
                                                <th>User</th>
                                                <th>Action</th>
                                                <th>Details</th>
                                                <th>IP Address</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($activity_logs as $log): 
                                                // Determine CSS class based on action type
                                                $action_class = '';
                                                if (strpos(strtolower($log['action']), 'login') !== false) {
                                                    $action_class = 'action-login';
                                                } elseif (strpos(strtolower($log['action']), 'logout') !== false) {
                                                    $action_class = 'action-logout';
                                                } elseif (strpos(strtolower($log['action']), 'create') !== false || strpos(strtolower($log['action']), 'add') !== false) {
                                                    $action_class = 'action-create';
                                                } elseif (strpos(strtolower($log['action']), 'update') !== false || strpos(strtolower($log['action']), 'edit') !== false) {
                                                    $action_class = 'action-update';
                                                } elseif (strpos(strtolower($log['action']), 'delete') !== false || strpos(strtolower($log['action']), 'remove') !== false) {
                                                    $action_class = 'action-delete';
                                                } elseif (strpos(strtolower($log['action']), 'view') !== false) {
                                                    $action_class = 'action-view';
                                                }
                                                
                                                // Format timestamp
                                                $timestamp = new DateTime($log['created_at']);
                                                $formatted_time = $timestamp->format('M j, Y g:i A');
                                                
                                                // Determine badge class based on account type
                                                $badge_class = '';
                                                if ($log['account_type'] === 'Admin') {
                                                    $badge_class = 'badge-admin';
                                                } elseif ($log['account_type'] === 'Collector') {
                                                    $badge_class = 'badge-collector';
                                                } elseif ($log['account_type'] === 'Customer') {
                                                    $badge_class = 'badge-customer';
                                                } elseif ($log['account_type'] === 'Area Manager') {
                                                    $badge_class = 'badge-area-manager';
                                                }
                                            ?>
                                                <tr class="<?php echo $action_class; ?>">
                                                    <td>
                                                        <div class="cell-strong"><?php echo $formatted_time; ?></div>
                                                        <small class="cell-meta"><?php echo $timestamp->format('H:i:s'); ?></small>
                                                    </td>
                                                    <td>
                                                        <div class="cell-strong"><?php echo htmlspecialchars($log['username']); ?></div>
                                                        <div class="cell-meta"><?php echo htmlspecialchars($log['user_name']); ?></div>
                                                        <span class="role-pill <?php echo $badge_class; ?>"><?php echo htmlspecialchars($log['account_type']); ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="action-pill <?php echo $action_class; ?>"><i class="fas fa-circle" aria-hidden="true"></i><?php echo htmlspecialchars($log['action']); ?></span>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($log['details'])): ?>
                                                            <button type="button" class="btn btn-sm btn-details view-details-btn" data-bs-toggle="modal" data-bs-target="#detailsModal" data-details="<?php echo htmlspecialchars($log['details']); ?>">
                                                                <i class="fas fa-eye" aria-hidden="true"></i> Details
                                                            </button>
                                                        <?php else: ?>
                                                            <span class="text-muted">No details</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><span class="cell-mono"><?php echo htmlspecialchars($log['ip_address']); ?></span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Footer: info left, pager right -->
                                <div class="table-footer">
                                    <span class="table-footer__info">Page <?php echo $page; ?> of <?php echo $total_pages; ?> &middot; <?php echo number_format($total_records); ?> entries</span>
                                <nav class="log-pager" aria-label="Activity logs pagination">
                                    <ul class="pagination justify-content-center">
                                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">
                                                <i class="fas fa-angle-double-left"></i>
                                            </a>
                                        </li>
                                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                                <i class="fas fa-angle-left"></i>
                                            </a>
                                        </li>
                                        
                                        <?php
                                        // Show page numbers
                                        $start_page = max(1, $page - 2);
                                        $end_page = min($total_pages, $start_page + 4);
                                        
                                        if ($end_page - $start_page < 4) {
                                            $start_page = max(1, $end_page - 4);
                                        }
                                        
                                        for ($i = $start_page; $i <= $end_page; $i++): ?>
                                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                                    <?php echo $i; ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>
                                        
                                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                                <i class="fas fa-angle-right"></i>
                                            </a>
                                        </li>
                                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>">
                                                <i class="fas fa-angle-double-right"></i>
                                            </a>
                                        </li>
                                    </ul>
                                </nav>
                                </div>
                            <?php else: ?>
                                <div class="log-empty">
                                    <span class="log-empty__icon"><i class="fas fa-clock-rotate-left" aria-hidden="true"></i></span>
                                    <h4>No Activity Logs Found</h4>
                                    <p>There are no activity logs matching your criteria.</p>
                                    <a href="activity_logs.php" class="btn btn-details mt-2"><i class="fas fa-undo me-1" aria-hidden="true"></i> Clear Filters</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Details Modal -->
                <div class="modal fade" id="detailsModal" tabindex="-1" aria-labelledby="detailsModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-xl modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <span class="log-modal__icon"><i class="fas fa-magnifying-glass-chart" aria-hidden="true"></i></span>
                                <div class="log-modal__heading">
                                    <h5 class="modal-title" id="detailsModalLabel">Activity Details</h5>
                                    <p class="log-modal__hint">Raw payload recorded for this entry</p>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <pre id="detailsContent" class="log-details"></pre>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                <button type="button" class="btn btn-primary" id="copyDetailsBtn">
                                    <i class="fas fa-copy me-1" aria-hidden="true"></i> Copy to Clipboard
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Clear Logs Confirmation Modal -->
                <div class="modal fade" id="clearLogsModal" tabindex="-1" aria-labelledby="clearLogsModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content danger-modal">
                            <div class="modal-header">
                                <span class="log-modal__icon"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i></span>
                                <div class="log-modal__heading">
                                    <h5 class="modal-title" id="clearLogsModalLabel">Clear All Logs</h5>
                                    <p class="log-modal__hint">This cannot be undone</p>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="danger-panel">
                                    <p class="mb-2">Are you sure you want to clear <strong>all</strong> activity logs?</p>
                                    <p class="mb-2">Total logs to be deleted: <strong><?php echo number_format($total_records); ?></strong></p>
                                    <p class="mb-0"><i class="fas fa-exclamation-triangle me-1" aria-hidden="true"></i>This will permanently delete every activity log record.</p>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-danger-solid" id="confirmClearLogsBtn">
                                    <i class="fas fa-trash-alt me-1" aria-hidden="true"></i> Clear All Logs
                                </button>
                            </div>
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
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <script src="assets/js/activity-logs.js?v=<?php echo is_file(__DIR__ . '/assets/js/activity-logs.js') ? filemtime(__DIR__ . '/assets/js/activity-logs.js') : 1; ?>"></script>
    </body>
</html>
