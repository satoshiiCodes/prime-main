<?php
/**
 * Prime City - Sidebar navigation.
 *
 * Expects `$user` (account_type, first_name, ...) plus an optional
 * `$layout_active` key ('dashboard', 'customer-list', ...) in the including
 * scope. Renders `#layoutSidenav_nav`; the surrounding `#layoutSidenav`
 * column belongs to the page layout.
 *
 * Collector and Proxy Collector accounts get the collector menu, every other
 * account type gets the admin menu - same split the legacy menu includes had.
 *
 * Active state: every page sets `$layout_active`; the maps below turn that into
 *   - `active` on the nested link, plus `aria-current="page"`
 *   - `active` on its parent section header
 *   - `show` on the parent collapse panel, so the section is already open.
 */

$layout_active = isset($layout_active) ? (string) $layout_active : '';
$sidebar_user = isset($user) && is_array($user) ? $user : array();

$sidebar_account_type = (string) ($sidebar_user['account_type'] ?? '');
$sidebar_account_source = $_SESSION['account_source'] ?? 'accounts';

// Pages whose notion of "collector menu" differs from the account type can
// force the variant through $layout_menu.
$is_collector_menu = isset($layout_menu)
    ? ((string) $layout_menu === 'collector')
    : in_array($sidebar_account_type, array('Collector', 'Proxy Collector'), true);

/* ---------------------------------------------------------------------------
 * Which pages belong to which menu entry. Record/detail pages keep their parent
 * link highlighted so the rail always shows where the user is.
 * ------------------------------------------------------------------------ */

$nav_customers = array(
    'customer-list',
    'customer-registration',
    'customer-dashboard',
    'customer-history',
    'accounts-registration',
    'accounts-loan',
);

$nav_employees = array(
    'employee-list',
    'collector-registration',
    'proxy-registration',
    'collector-loan',
    'proxy-loan',
    'collector-collections',
    'collector-payments',
    'collector-biometrics',
);

$nav_customer_loans = array('add-financing', 'multiple-payments', 'payment-history');
$nav_employee_loans = array('add-collector-loan', 'add-collector-payments');
$nav_others = array('add-investment', 'gcash-in-out', 'add-expenses');
$nav_biometrics = array('upload-attendance', 'payroll', 'biometrics-report');
$nav_penalty = array('run-penalty-update');
$nav_logs = array('activity-logs', 'date-time-logs');
$nav_cashbook = array('cashbook');
$nav_backup = array('backup-sql');
$nav_reports = array(
    'financial-statement',
    'area-reports',
    'collector-reports',
    'penalty-report',
    'amount-financed',
    'savings-reports',
);

$customers_active = in_array($layout_active, $nav_customers, true);
$employees_active = in_array($layout_active, $nav_employees, true);
$accounts_section_active = $customers_active || $employees_active;

$customer_loans_active = in_array($layout_active, $nav_customer_loans, true);
$employee_loans_active = in_array($layout_active, $nav_employee_loans, true);
$others_active = in_array($layout_active, $nav_others, true);
$biometrics_active = in_array($layout_active, $nav_biometrics, true);
$penalty_active = in_array($layout_active, $nav_penalty, true);
$logs_active = in_array($layout_active, $nav_logs, true);
$cashbook_active = in_array($layout_active, $nav_cashbook, true);
$backup_active = in_array($layout_active, $nav_backup, true);
$reports_active = in_array($layout_active, $nav_reports, true);

/* Small helpers so every link and header is written the same way. */
$nav_link_class = static function (bool $is_active): string {
    return 'nav-link' . ($is_active ? ' active' : '');
};

$nav_link_current = static function (bool $is_active): string {
    return $is_active ? ' aria-current="page"' : '';
};

$nav_section_class = static function (bool $is_active): string {
    return 'nav-link collapsed' . ($is_active ? ' active' : '');
};

$nav_section_expanded = static function (bool $is_active): string {
    return $is_active ? 'true' : 'false';
};

$nav_panel_class = static function (bool $is_active): string {
    return 'collapse' . ($is_active ? ' show' : '');
};

$is_proxy_user = $sidebar_account_source === 'proxy_accounts';
$is_proxy_cashier = $is_proxy_user && $sidebar_account_type === 'Proxy Collector-Cashier';

$show_others_section = !$is_proxy_user || $is_proxy_cashier;
$show_cashbook_section = !$is_proxy_user || $is_proxy_cashier;
$show_full_menu = !$is_proxy_user;
$can_view_mvl_performance = in_array($sidebar_account_type, array('Admin', 'Branch Manager', 'Area Manager'), true);

/* The footer shows the account type on its own line under "Logged in as:". */
$sidebar_role_label = $sidebar_account_type !== '' ? $sidebar_account_type : 'Admin';
?>
<style>
    /* Sidebar footer - glassmorphism panel */
    .sb-sidenav-dark .sb-sidenav-footer {
        position: relative;
        margin: 0.85rem 0.7rem 0.95rem !important;
        padding: 0.8rem 0.95rem !important;
        border: 1px solid rgba(255, 255, 255, 0.18) !important;
        border-radius: 14px;
        background: linear-gradient(135deg, rgba(255, 255, 255, 0.16) 0%, rgba(255, 255, 255, 0.05) 100%) !important;
        -webkit-backdrop-filter: blur(16px) saturate(160%);
        backdrop-filter: blur(16px) saturate(160%);
        box-shadow:
            0 12px 28px rgba(3, 12, 24, 0.42),
            inset 0 1px 0 rgba(255, 255, 255, 0.28);
        overflow: hidden;
        color: rgba(232, 244, 255, 0.86) !important;
    }

    /* top light streak that sells the glass edge */
    .sb-sidenav-dark .sb-sidenav-footer::before {
        content: "";
        position: absolute;
        inset: 0 0 auto 0;
        height: 1px;
        background: linear-gradient(90deg, transparent 0%, rgba(255, 255, 255, 0.65) 50%, transparent 100%);
        pointer-events: none;
    }

    /* soft brand glow in the bottom-right corner */
    .sb-sidenav-dark .sb-sidenav-footer::after {
        content: "";
        position: absolute;
        right: -14%;
        bottom: -60%;
        width: 110px;
        height: 110px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(59, 166, 2, 0.18) 0%, transparent 72%);
        pointer-events: none;
    }

    .sb-sidenav-dark .sb-sidenav-footer {
        text-align: center;
    }

    .sb-sidenav-dark .sb-sidenav-footer .footer-label {
        position: relative;
        z-index: 1;
        display: block;
        color: rgba(226, 241, 255, 0.82);
        font-size: 0.76rem;
        letter-spacing: 0.01em;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .sb-sidenav-dark .sb-sidenav-footer .footer-role {
        position: relative;
        z-index: 1;
        display: block;
        margin-top: 0.1rem;
        font-size: 0.88rem;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .sb-sidenav-dark .sb-sidenav-footer .user-account-type {
        color: #7bd03f !important;
        font-weight: 700;
        text-shadow: 0 1px 6px rgba(59, 166, 2, 0.35);
    }

    /* Safari/older Edge fallback when backdrop-filter is unavailable */
    @supports not ((backdrop-filter: blur(1px)) or (-webkit-backdrop-filter: blur(1px))) {
        .sb-sidenav-dark .sb-sidenav-footer {
            background: rgba(10, 32, 58, 0.92) !important;
        }
    }
</style>

<div id="layoutSidenav_nav">
    <nav class="sb-sidenav accordion sb-sidenav-dark" id="sidenavAccordion">
        <div class="sb-sidenav-menu">
            <div class="nav">
                <?php if ($is_collector_menu): ?>
                <!-- Collector navigation -->
                <div class="sb-sidenav-menu-heading">Main</div>
                <a class="<?php echo $nav_link_class($layout_active === 'dashboard'); ?>" href="collector_dashboard.php"<?php echo $nav_link_current($layout_active === 'dashboard'); ?>>
                    <div class="sb-nav-link-icon"><i class="fas fa-tachometer-alt"></i></div>
                    Dashboard
                </a>

                <div class="sb-sidenav-menu-heading">Customer Management</div>
                <a class="<?php echo $nav_link_class($layout_active === 'customer-list'); ?>" href="customer_list.php"<?php echo $nav_link_current($layout_active === 'customer-list'); ?>>
                    <div class="sb-nav-link-icon"><i class="fas fa-users"></i></div>
                    Customer List
                </a>
                <a class="<?php echo $nav_link_class($layout_active === 'multiple-payments'); ?>" href="customers_multiple_payment.php"<?php echo $nav_link_current($layout_active === 'multiple-payments'); ?>>
                    <div class="sb-nav-link-icon"><i class="fas fa-money-bill-wave"></i></div>
                    Multiple Payments
                </a>
                <a class="<?php echo $nav_link_class($layout_active === 'payment-history'); ?>" href="payment_history.php"<?php echo $nav_link_current($layout_active === 'payment-history'); ?>>
                    <div class="sb-nav-link-icon"><i class="fas fa-history"></i></div>
                    Payment History
                </a>

                <div class="sb-sidenav-menu-heading">Loan &amp; Deductions</div>
                <a class="<?php echo $nav_link_class(in_array($layout_active, array('my-account', 'employee-list'), true)); ?>" href="employee_lists.php"<?php echo $nav_link_current(in_array($layout_active, array('my-account', 'employee-list'), true)); ?>>
                    <div class="sb-nav-link-icon"><i class="fas fa-users-cog"></i></div>
                    My Account
                </a>

                <?php else: ?>
                <!-- Admin navigation -->
                <div class="sb-sidenav-menu-heading">Main</div>
                <a class="<?php echo $nav_link_class($layout_active === 'dashboard'); ?>" href="dashboard.php"<?php echo $nav_link_current($layout_active === 'dashboard'); ?>>
                    <div class="sb-nav-link-icon"><i class="fas fa-tachometer-alt"></i></div>
                    Dashboard
                </a>

                <!-- Management Sections -->
                <div class="sb-sidenav-menu-heading">Management</div>

                <!-- Accounts Section -->
                <a class="<?php echo $nav_section_class($accounts_section_active); ?>" href="#" data-bs-toggle="collapse" data-bs-target="#collapseAccounts" aria-expanded="<?php echo $nav_section_expanded($accounts_section_active); ?>" aria-controls="collapseAccounts">
                    <div class="sb-nav-link-icon"><i class="fas fa-users-cog"></i></div>
                    Accounts
                    <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>
                </a>
                <div class="<?php echo $nav_panel_class($accounts_section_active); ?>" id="collapseAccounts" aria-labelledby="headingOne" data-bs-parent="#sidenavAccordion">
                    <nav class="sb-sidenav-menu-nested nav">
                        <a class="<?php echo $nav_link_class($customers_active); ?>" href="customer_list.php"<?php echo $nav_link_current($customers_active); ?>><i class="fas fa-user-tie me-2"></i>Customers</a>
                        <a class="<?php echo $nav_link_class($employees_active); ?>" href="employee_lists.php"<?php echo $nav_link_current($employees_active); ?>><i class="fas fa-users me-2"></i>Employees</a>
                    </nav>
                </div>

                <!-- Customer Loans Section -->
                <a class="<?php echo $nav_section_class($customer_loans_active); ?>" href="#" data-bs-toggle="collapse" data-bs-target="#collapseCustomerLoans" aria-expanded="<?php echo $nav_section_expanded($customer_loans_active); ?>" aria-controls="collapseCustomerLoans">
                    <div class="sb-nav-link-icon"><i class="fas fa-hand-holding-usd"></i></div>
                    Customer Loans
                    <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>
                </a>
                <div class="<?php echo $nav_panel_class($customer_loans_active); ?>" id="collapseCustomerLoans" aria-labelledby="headingOne" data-bs-parent="#sidenavAccordion">
                    <nav class="sb-sidenav-menu-nested nav">
                        <a class="<?php echo $nav_link_class($layout_active === 'add-financing'); ?>" href="add_financing.php"<?php echo $nav_link_current($layout_active === 'add-financing'); ?>><i class="fas fa-file-contract me-2"></i>New Loan</a>
                        <a class="<?php echo $nav_link_class($layout_active === 'multiple-payments'); ?>" href="customers_multiple_payment.php"<?php echo $nav_link_current($layout_active === 'multiple-payments'); ?>><i class="fas fa-money-bill-wave me-2"></i>Add Payment</a>
                        <a class="<?php echo $nav_link_class($layout_active === 'payment-history'); ?>" href="payment_history.php"<?php echo $nav_link_current($layout_active === 'payment-history'); ?>><i class="fas fa-history me-2"></i>Payment History</a>
                    </nav>
                </div>

                <!-- Employee Loans Section -->
                <a class="<?php echo $nav_section_class($employee_loans_active); ?>" href="#" data-bs-toggle="collapse" data-bs-target="#collapseCollectorLoans" aria-expanded="<?php echo $nav_section_expanded($employee_loans_active); ?>" aria-controls="collapseCollectorLoans">
                    <div class="sb-nav-link-icon"><i class="fas fa-hand-holding-usd"></i></div>
                    Employee Loans
                    <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>
                </a>
                <div class="<?php echo $nav_panel_class($employee_loans_active); ?>" id="collapseCollectorLoans" aria-labelledby="headingOne" data-bs-parent="#sidenavAccordion">
                    <nav class="sb-sidenav-menu-nested nav">
                        <a class="<?php echo $nav_link_class($layout_active === 'add-collector-loan'); ?>" href="add_collector_loan.php"<?php echo $nav_link_current($layout_active === 'add-collector-loan'); ?>><i class="fas fa-file-contract me-2"></i>New Employee Loan</a>
                        <a class="<?php echo $nav_link_class($layout_active === 'add-collector-payments'); ?>" href="add_collector_payments.php"<?php echo $nav_link_current($layout_active === 'add-collector-payments'); ?>><i class="fas fa-money-bill-wave me-2"></i>Add Employee Payment</a>
                    </nav>
                </div>

                <?php if ($show_others_section): ?>
                <!-- Others Section -->
                <a class="<?php echo $nav_section_class($others_active); ?>" href="#" data-bs-toggle="collapse" data-bs-target="#collapseOthers" aria-expanded="<?php echo $nav_section_expanded($others_active); ?>" aria-controls="collapseOthers">
                    <div class="sb-nav-link-icon"><i class="fas fa-wallet"></i></div>
                    Others
                    <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>
                </a>
                <div class="<?php echo $nav_panel_class($others_active); ?>" id="collapseOthers" aria-labelledby="headingOne" data-bs-parent="#sidenavAccordion">
                    <nav class="sb-sidenav-menu-nested nav">
                        <?php if (!$is_proxy_user): ?>
                        <a class="<?php echo $nav_link_class($layout_active === 'add-investment'); ?>" href="add_investment.php"<?php echo $nav_link_current($layout_active === 'add-investment'); ?>><i class="fas fa-plus-circle me-2"></i>Add Investment</a>
                        <?php endif; ?>
                        <a class="<?php echo $nav_link_class($layout_active === 'gcash-in-out'); ?>" href="gcash_in_out.php"<?php echo $nav_link_current($layout_active === 'gcash-in-out'); ?>><i class="fas fa-money-bill-wave me-2"></i>Gcash</a>
                        <a class="<?php echo $nav_link_class($layout_active === 'add-expenses'); ?>" href="add_expenses.php"<?php echo $nav_link_current($layout_active === 'add-expenses'); ?>><i class="fas fa-receipt me-2"></i>Add Expenses</a>
                    </nav>
                </div>
                <?php endif; ?>

                <?php if ($show_full_menu): ?>
                <!-- Biometrics Section -->
                <a class="<?php echo $nav_section_class($biometrics_active); ?>" href="#" data-bs-toggle="collapse" data-bs-target="#collapseBiometrics" aria-expanded="<?php echo $nav_section_expanded($biometrics_active); ?>" aria-controls="collapseBiometrics">
                    <div class="sb-nav-link-icon"><i class="fas fa-fingerprint"></i></div>
                    Biometrics
                    <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>
                </a>
                <div class="<?php echo $nav_panel_class($biometrics_active); ?>" id="collapseBiometrics" aria-labelledby="headingOne" data-bs-parent="#sidenavAccordion">
                    <nav class="sb-sidenav-menu-nested nav">
                        <a class="<?php echo $nav_link_class($layout_active === 'upload-attendance'); ?>" href="upload_attendance.php"<?php echo $nav_link_current($layout_active === 'upload-attendance'); ?>><i class="fas fa-clock me-2"></i>Upload Attendance</a>
                        <a class="<?php echo $nav_link_class($layout_active === 'payroll'); ?>" href="payroll.php"<?php echo $nav_link_current($layout_active === 'payroll'); ?>><i class="fas fa-file-alt me-2"></i>Payroll</a>
                    </nav>
                </div>

                <!-- Penalty Management Section -->
                <a class="<?php echo $nav_section_class($penalty_active); ?>" href="#" data-bs-toggle="collapse" data-bs-target="#collapsePenalty" aria-expanded="<?php echo $nav_section_expanded($penalty_active); ?>" aria-controls="collapsePenalty">
                    <div class="sb-nav-link-icon"><i class="fas fa-exclamation-triangle"></i></div>
                    Penalty Management
                    <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>
                </a>
                <div class="<?php echo $nav_panel_class($penalty_active); ?>" id="collapsePenalty" aria-labelledby="headingOne" data-bs-parent="#sidenavAccordion">
                    <nav class="sb-sidenav-menu-nested nav">
                        <a class="<?php echo $nav_link_class($layout_active === 'run-penalty-update'); ?>" href="run_penalty_update.php"<?php echo $nav_link_current($layout_active === 'run-penalty-update'); ?>><i class="fas fa-calculator me-2"></i>Run Penalty Update</a>
                    </nav>
                </div>

                <!-- Logs Section -->
                <a class="<?php echo $nav_section_class($logs_active); ?>" href="#" data-bs-toggle="collapse" data-bs-target="#collapseLogs" aria-expanded="<?php echo $nav_section_expanded($logs_active); ?>" aria-controls="collapseLogs">
                    <div class="sb-nav-link-icon"><i class="fas fa-clipboard-list"></i></div>
                    Logs
                    <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>
                </a>
                <div class="<?php echo $nav_panel_class($logs_active); ?>" id="collapseLogs" aria-labelledby="headingOne" data-bs-parent="#sidenavAccordion">
                    <nav class="sb-sidenav-menu-nested nav">
                        <a class="<?php echo $nav_link_class($layout_active === 'activity-logs'); ?>" href="activity_logs.php"<?php echo $nav_link_current($layout_active === 'activity-logs'); ?>><i class="fas fa-history me-2"></i>Activity Logs</a>
                        <a class="<?php echo $nav_link_class($layout_active === 'date-time-logs'); ?>" href="dateTime_logs.php"<?php echo $nav_link_current($layout_active === 'date-time-logs'); ?>><i class="fas fa-clock me-2"></i>Date &amp; Time Logs</a>
                    </nav>
                </div>

                <!-- Financial Management Section -->
                <div class="sb-sidenav-menu-heading">Financial Management</div>
                <?php endif; ?>

                <?php if ($show_cashbook_section): ?>
                <!-- Cashbook Section -->
                <a class="<?php echo $nav_section_class($cashbook_active); ?>" href="#" data-bs-toggle="collapse" data-bs-target="#collapseCashbook" aria-expanded="<?php echo $nav_section_expanded($cashbook_active); ?>" aria-controls="collapseCashbook">
                    <div class="sb-nav-link-icon"><i class="fas fa-cash-register"></i></div>
                    Cashbook
                    <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>
                </a>
                <div class="<?php echo $nav_panel_class($cashbook_active); ?>" id="collapseCashbook" aria-labelledby="headingOne" data-bs-parent="#sidenavAccordion">
                    <nav class="sb-sidenav-menu-nested nav">
                        <a class="<?php echo $nav_link_class($layout_active === 'cashbook'); ?>" href="cashbook.php"<?php echo $nav_link_current($layout_active === 'cashbook'); ?>><i class="fas fa-book me-2"></i>View Cashbook</a>
                    </nav>
                </div>
                <?php endif; ?>

                <?php if ($show_full_menu): ?>
                <!-- Backup Section -->
                <a class="<?php echo $nav_section_class($backup_active); ?>" href="#" data-bs-toggle="collapse" data-bs-target="#collapseBackup" aria-expanded="<?php echo $nav_section_expanded($backup_active); ?>" aria-controls="collapseBackup">
                    <div class="sb-nav-link-icon"><i class="fas fa-database"></i></div>
                    Backup
                    <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>
                </a>
                <div class="<?php echo $nav_panel_class($backup_active); ?>" id="collapseBackup" aria-labelledby="headingOne" data-bs-parent="#sidenavAccordion">
                    <nav class="sb-sidenav-menu-nested nav">
                        <a class="<?php echo $nav_link_class($layout_active === 'backup-sql'); ?>" href="backup_sql.php"<?php echo $nav_link_current($layout_active === 'backup-sql'); ?>><i class="fas fa-download me-2"></i>Download Backup</a>
                    </nav>
                </div>

                <!-- Reports Section -->
                <div class="sb-sidenav-menu-heading">Reports</div>

                <a class="<?php echo $nav_section_class($reports_active); ?>" href="#" data-bs-toggle="collapse" data-bs-target="#collapseReports" aria-expanded="<?php echo $nav_section_expanded($reports_active); ?>" aria-controls="collapseReports">
                    <div class="sb-nav-link-icon"><i class="fas fa-file-alt"></i></div>
                    Reports
                    <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>
                </a>
                <div class="<?php echo $nav_panel_class($reports_active); ?>" id="collapseReports" aria-labelledby="headingTwo" data-bs-parent="#sidenavAccordion">
                    <nav class="sb-sidenav-menu-nested nav">
                        <a class="<?php echo $nav_link_class($layout_active === 'financial-statement'); ?>" href="financial_statement_report.php"<?php echo $nav_link_current($layout_active === 'financial-statement'); ?>><i class="fas fa-file-invoice-dollar me-2"></i>Financial Statement Reports</a>
                        <?php if ($can_view_mvl_performance): ?>
                        <a class="<?php echo $nav_link_class($layout_active === 'area-reports'); ?>" href="area_reports.php"<?php echo $nav_link_current($layout_active === 'area-reports'); ?>><i class="fas fa-map-marked-alt me-2"></i>MVL Performance</a>
                        <?php endif; ?>
                        <a class="<?php echo $nav_link_class($layout_active === 'collector-reports'); ?>" href="collector_reports.php"<?php echo $nav_link_current($layout_active === 'collector-reports'); ?>><i class="fas fa-user-shield me-2"></i>Collector Reports</a>
                        <a class="<?php echo $nav_link_class($layout_active === 'penalty-report'); ?>" href="penalty_report.php"<?php echo $nav_link_current($layout_active === 'penalty-report'); ?>><i class="fas fa-exclamation-triangle me-2"></i>Penalty Report</a>
                        <a class="<?php echo $nav_link_class($layout_active === 'amount-financed'); ?>" href="amount_financed.php"<?php echo $nav_link_current($layout_active === 'amount-financed'); ?>><i class="fas fa-chart-line me-2"></i>Amount Financed Report</a>
                        <a class="<?php echo $nav_link_class($layout_active === 'savings-reports'); ?>" href="savings_reports.php"<?php echo $nav_link_current($layout_active === 'savings-reports'); ?>><i class="fas fa-piggy-bank me-2"></i>Savings Reports</a>
                    </nav>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="sb-sidenav-footer">
            <div class="footer-label">Logged in as:</div>
            <div class="footer-role user-account-type"><?php echo htmlspecialchars($sidebar_role_label, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
    </nav>
</div>
