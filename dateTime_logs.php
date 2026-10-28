<?php
/**
 * Prime City - Date/time change log page.
 *
 * Layout only: data comes from actions/date-time-logs-actions.php, layout chrome
 * from includes/*, styles from assets/css and behaviour from assets/js.
 *
 * The CSV export is handled by api/export_logs.php, submitted by the page's
 * script.
 */

require_once __DIR__ . '/actions/date-time-logs-actions.php';

try {
    $context = dtl_context();
} catch (Throwable $e) {
    error_log('[date-time-logs] ' . $e->getMessage());
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
        <head>
            <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
            <meta charset="utf-8" />
            <title>Date Time Logs - Unavailable</title>
            <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        </head>
        <body class="bg-light">
            <div class="container py-5">
                <div class="alert alert-danger">
                    <h4 class="alert-heading">The date/time log is unavailable</h4>
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
$logs = $context['logs'];
$total_records = $context['total_records'];
$total_pages = $context['total_pages'];
$action_types = $context['action_types'];

// Shared layout includes read these.
$layout_active = 'date-time-logs';
$layout_menu = $context['layout_menu'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <meta name="description" content="Prime City date/time change log" />
    <meta name="author" content="Prime City" />
    <title>Date Time Logs - Prime City</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
    <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
    <link href="assets/css/dash.css" rel="stylesheet" />
    <link href="assets/css/dash-ui.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet" />
    <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link href="assets/css/date-time-logs-style.css?v=<?php echo is_file(__DIR__ . '/assets/css/date-time-logs-style.css') ? filemtime(__DIR__ . '/assets/css/date-time-logs-style.css') : 1; ?>" rel="stylesheet" />
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
                            <i class="fas fa-clock-rotate-left" aria-hidden="true"></i>
                            Prime City &middot; Reports
                        </p>
                        <h1 class="dash-title">Date Time Logs</h1>
                        <p class="dash-sub">Every system clock change, with the old and new date/time values captured.</p>
                    </div>

                    <div class="dash-head__actions">
                        <button class="btn btn-primary" onclick="exportToCSV()">
                            <i class="fas fa-download" aria-hidden="true"></i> Export to CSV
                        </button>
                    </div>
                </header>
                
                <!-- Filters Card -->
                <div class="card dash-card mb-4">
                    <header class="dash-card__head">
                        <span class="dash-card__icon"><i class="fas fa-filter" aria-hidden="true"></i></span>
                        <div>
                            <h2 class="dash-card__title">Filters</h2>
                            <p class="dash-card__hint">Filter the log by user, action type or date range</p>
                        </div>
                    </header>
                    <div class="card-body">
                        <form method="GET" action="dateTime_logs.php" class="log-filter">
                            <div class="filter-field">
                                <label for="filter_user" class="filter-label">User</label>
                                <input type="text" class="form-control" id="filter_user" name="filter_user"
                                       value="<?php echo htmlspecialchars($filter_user); ?>"
                                       placeholder="User ID or name">
                            </div>

                            <div class="filter-field">
                                <label for="filter_action" class="filter-label">Action Type</label>
                                <select class="form-select" id="filter_action" name="filter_action">
                                    <option value="">All Actions</option>
                                    <?php foreach ($action_types as $action): ?>
                                        <option value="<?php echo htmlspecialchars($action); ?>"
                                            <?php echo $filter_action === $action ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($action); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="filter-field">
                                <label for="filter_date_from" class="filter-label">Date From</label>
                                <input type="date" class="form-control" id="filter_date_from"
                                       name="filter_date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>">
                            </div>

                            <div class="filter-field">
                                <label for="filter_date_to" class="filter-label">Date To</label>
                                <input type="date" class="form-control" id="filter_date_to"
                                       name="filter_date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>">
                            </div>

                            <div class="filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter me-1"></i> Apply Filters
                                </button>
                                <a href="dateTime_logs.php" class="btn btn-secondary">
                                    <i class="fas fa-undo me-1"></i> Clear Filters
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Logs Table -->
                <div class="card dash-card mb-4">
                    <header class="dash-card__head">
                        <span class="dash-card__icon"><i class="fas fa-clock-rotate-left" aria-hidden="true"></i></span>
                        <div>
                            <h2 class="dash-card__title">Date Time Logs</h2>
                            <p class="dash-card__hint">Old and new clock values for each recorded change</p>
                        </div>
                        <span class="dash-card__badge"><i class="fas fa-list-ol" aria-hidden="true"></i> <?php echo number_format($total_records); ?> records</span>
                    </header>
                    <div class="card-body">
                        <?php if (count($logs) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover log-table dash-table">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>User ID</th>
                                            <th>User Name</th>
                                            <th>Old DateTime</th>
                                            <th>New DateTime</th>
                                            <th>Action Type</th>
                                            <th>IP Address</th>
                                            <th>User Agent</th>
                                            <th>Created At</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($logs as $log): 
                                            // Determine row class based on action type
                                            $row_class = '';
                                            if (strpos(strtolower($log['action_type']), 'login') !== false) {
                                                $row_class = 'action-login';
                                            } elseif (strpos(strtolower($log['action_type']), 'logout') !== false) {
                                                $row_class = 'action-logout';
                                            } elseif (strpos(strtolower($log['action_type']), 'create') !== false) {
                                                $row_class = 'action-created';
                                            } elseif (strpos(strtolower($log['action_type']), 'update') !== false) {
                                                $row_class = 'action-updated';
                                            } elseif (strpos(strtolower($log['action_type']), 'delete') !== false) {
                                                $row_class = 'action-deleted';
                                            } elseif (strpos(strtolower($log['action_type']), 'adjust') !== false || strpos(strtolower($log['action_type']), 'time') !== false) {
                                                $row_class = 'action-adjust';
                                            }
                                        ?>
                                            <tr class="<?php echo $row_class; ?>">
                                                <td><span class="cell-id">#<?php echo htmlspecialchars($log['id']); ?></span></td>
                                                <td><span class="cell-mono"><?php echo htmlspecialchars($log['user_id']); ?></span></td>
                                                <td><span class="cell-strong"><?php echo htmlspecialchars($log['user_name']); ?></span></td>
                                                <td><span class="cell-mono cell-old"><?php echo htmlspecialchars($log['old_datetime']); ?></span></td>
                                                <td><span class="cell-mono cell-new"><?php echo htmlspecialchars($log['new_datetime']); ?></span></td>
                                                <td>
                                                    <span class="action-pill <?php echo $row_class; ?>">
                                                        <i class="fas fa-circle" aria-hidden="true"></i><?php echo htmlspecialchars($log['action_type']); ?>
                                                    </span>
                                                </td>
                                                <td><span class="cell-mono"><?php echo htmlspecialchars($log['ip_address']); ?></span></td>
                                                <td class="notes-column" title="<?php echo htmlspecialchars($log['user_agent']); ?>">
                                                    <?php 
                                                    $user_agent = htmlspecialchars($log['user_agent']);
                                                    echo strlen($user_agent) > 50 ? substr($user_agent, 0, 50) . '...' : $user_agent;
                                                    ?>
                                                </td>
                                                <td><span class="cell-meta"><?php echo htmlspecialchars($log['created_at']); ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Footer: info left, pager right -->
                            <div class="table-footer">
                                <span class="table-footer__info">Page <?php echo $page; ?> of <?php echo max(1, $total_pages); ?> &middot; <?php echo number_format($total_records); ?> records</span>

                                <?php if ($total_pages > 1): ?>
                                <nav class="log-pager" aria-label="Logs pagination">
                                    <ul class="pagination">
                                        <?php
                                        $start_page = max(1, $page - 2);
                                        $end_page = min($total_pages, $start_page + 4);

                                        if ($end_page - $start_page < 4) {
                                            $start_page = max(1, $end_page - 4);
                                        }
                                        ?>

                                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" aria-label="Previous page"
                                               href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                                <i class="fas fa-angle-left" aria-hidden="true"></i>
                                            </a>
                                        </li>

                                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                                <a class="page-link" 
                                                   href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                                    <?php echo $i; ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>

                                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                            <a class="page-link" aria-label="Next page"
                                               href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                                <i class="fas fa-angle-right" aria-hidden="true"></i>
                                            </a>
                                        </li>
                                    </ul>
                                </nav>
                                <?php endif; ?>
                            </div>
                            
                        <?php else: ?>
                            <div class="log-empty">
                                <span class="log-empty__icon"><i class="fas fa-clock-rotate-left" aria-hidden="true"></i></span>
                                <h4>No Logs Found</h4>
                                <p>No date time logs match your current filters.</p>
                                <a href="dateTime_logs.php" class="btn btn-details mt-2"><i class="fas fa-undo me-1" aria-hidden="true"></i> Clear Filters</a>
                            </div>
                        <?php endif; ?>
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
    <script src="assets/js/date-time-logs.js?v=<?php echo is_file(__DIR__ . '/assets/js/date-time-logs.js') ? filemtime(__DIR__ . '/assets/js/date-time-logs.js') : 1; ?>"></script>
</body>
</html>
