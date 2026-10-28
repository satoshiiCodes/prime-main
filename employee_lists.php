<?php
/**
 * Prime City - Employee list page.
 *
 * Layout only: data and scoping come from actions/employee-actions.php,
 * asynchronous calls go to api/employee-endpoint.php, layout chrome from
 * includes/*, styles from assets/css and behaviour from assets/js.
 */

require_once __DIR__ . '/actions/employee-actions.php';

employee_start_session();
employee_handle_logout();

try {
    $context = employee_context();
} catch (Throwable $e) {
    error_log('[employee-list] ' . $e->getMessage());
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
        <head>
            <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
            <meta charset="utf-8" />
            <title>Employees - Unavailable</title>
            <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        </head>
        <body class="bg-light">
            <div class="container py-5">
                <div class="alert alert-danger">
                    <h4 class="alert-heading">The employee list is unavailable</h4>
                    <p class="mb-0">We could not load the employee records right now. Please try again in a moment.</p>
                </div>
            </div>
        </body>
    </html>
    <?php
    exit;
}

$user = $context['user'];
$user_table = $context['user_table'];
$user_branch = $context['user_branch'];
$is_admin = $context['is_admin'];
$is_proxy_cashier = $context['is_proxy_cashier'];
$is_restricted_user = $context['is_restricted_user'];
$show_branch_filter = $context['show_branch_filter'];
$show_all_branches_option = $context['show_all_branches_option'];
$available_branches = $context['available_branches'];
$branch_filter = $context['branch_filter'];
$collectors = $context['collectors'];
$proxy_accounts = $context['proxy_accounts'];
$accounts = $context['accounts'];
$show_collector_tab = $context['show_collector_tab'];
$show_proxy_tab = $context['show_proxy_tab'];
$show_accounts_tab = $context['show_accounts_tab'];
$active_tab = $context['active_tab'];
$success_message = $context['success_message'];
$error_message = $context['error_message'];

// Shared layout includes read these.
$layout_active = 'employee-list';
$layout_menu = $context['layout_menu'];

$pageConfig = array(
    'success'  => $success_message,
    'error'    => $error_message,
    'endpoint' => $context['endpoint'],
);

$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
        <meta charset="utf-8" />
        <meta http-equiv="X-UA-Compatible" content="IE=edge" />
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
        <meta name="description" content="Prime City employees" />
        <meta name="author" content="Prime City" />
        <title>Employees - Prime City Admin</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
        <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        <link href="assets/css/dash.css" rel="stylesheet" />
        <link href="assets/css/dash-ui.css" rel="stylesheet" />
        <link href="assets/css/employee-lists.css" rel="stylesheet" />
        <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    </head>
    <body class="sb-nav-fixed">
        <div class="toast-container"></div>

        <!-- Image View Modal -->
        <div class="modal fade" id="imageViewModal" tabindex="-1" aria-labelledby="imageViewModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="imageViewModalLabel">Collector Profile Image</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body text-center p-0">
                        <img id="fullSizeImage" src="" class="img-fluid" alt="Full Size Profile Image">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Salary Management Modal -->
        <div class="modal fade" id="salaryModal" tabindex="-1" aria-labelledby="salaryModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="salaryModalLabel">Manage Daily Salary</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form id="salaryForm">
                        <div class="modal-body">
                            <input type="hidden" id="salaryAccountId" name="account_id">
                            <input type="hidden" id="salaryAccountType" name="account_type">
                            
                            <div class="mb-3">
                                <label for="employeeName" class="form-label">Employee Name</label>
                                <input type="text" class="form-control" id="employeeName" readonly>
                            </div>
                            
                            <div class="mb-3">
                                <label for="currentSalary" class="form-label">Current Daily Salary</label>
                                <div class="input-group">
                                    <span class="input-group-text">₱</span>
                                    <input type="text" class="form-control" id="currentSalary" readonly>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="newSalary" class="form-label required-field">New Daily Salary</label>
                                <div class="input-group">
                                    <span class="input-group-text">₱</span>
                                    <input type="text" class="form-control" id="newSalary" name="salary_display" required>
                                    <input type="hidden" id="newSalaryRaw" name="salary">
                                </div>
                                <div class="form-text">Amount will be formatted with commas automatically</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="effectiveDate" class="form-label required-field">Effective Date</label>
                                <input type="date" class="form-control" id="effectiveDate" name="effective_date" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="salaryNote" class="form-label">Notes (Optional)</label>
                                <textarea class="form-control" id="salaryNote" name="note" rows="2"></textarea>
                            </div>
                            
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                Daily salary changes will take effect on the specified date. Previous salaries will be saved in history.
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Update Daily Salary</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Salary History Modal -->
        <div class="modal fade" id="salaryHistoryModal" tabindex="-1" aria-labelledby="salaryHistoryModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="salaryHistoryModalLabel">Daily Salary History</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div id="salaryHistoryContent">
                            <!-- Salary history will be loaded here -->
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Deduction Management Modal -->
        <div class="modal fade" id="deductionModal" tabindex="-1" aria-labelledby="deductionModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="deductionModalLabel">Manage Deductions</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="deductionEmployeeId" name="employee_id">
                        <input type="hidden" id="deductionEmployeeType" name="employee_type">
                        
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <h6>Employee Information</h6>
                                <p class="mb-1"><strong>Name:</strong> <span id="deductionEmployeeName"></span></p>
                                <p><strong>Current Daily Salary:</strong> ₱<span id="deductionEmployeeSalary"></span></p>
                            </div>
                        </div>
                        
                        <!-- Deduction Summary -->
                        <div class="deduction-summary">
                            <h6 class="mb-3">Deduction Summary</h6>
                            <div class="deduction-summary-item">
                                <span>Loan Payment</span>
                                <span class="deduction-total" id="summaryLoan">₱0.00</span>
                            </div>
                            <div class="deduction-summary-item">
                                <span>Cash Advance</span>
                                <span class="deduction-total" id="summaryCashAdvance">₱0.00</span>
                            </div>
                            <div class="deduction-summary-item">
                                <span>SSS Contribution</span>
                                <span class="deduction-total" id="summarySSS">₱0.00</span>
                            </div>
                            <div class="deduction-summary-item">
                                <span>PAG-IBIG Contribution</span>
                                <span class="deduction-total" id="summaryPagIBIG">₱0.00</span>
                            </div>
                            <div class="deduction-summary-item">
                                <span>PhilHealth Contribution</span>
                                <span class="deduction-total" id="summaryPhilHealth">₱0.00</span>
                            </div>
                            <div class="deduction-summary-item">
                                <span>SSS Loan</span>
                                <span class="deduction-total" id="summarySSSLoan">₱0.00</span>
                            </div>
                            <div class="deduction-summary-item">
                                <span>PAG-IBIG Loan</span>
                                <span class="deduction-total" id="summaryPagIBIGLoan">₱0.00</span>
                            </div>
                            <div class="deduction-summary-item">
                                <strong>Total Deductions</strong>
                                <strong class="text-danger" id="summaryTotal">₱0.00</strong>
                            </div>
                        </div>
                        
                        <!-- Add Deduction Form -->
                        <h6 class="mb-3">Add New Deduction</h6>
                        <form id="deductionForm">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="deductionType" class="form-label required-field">Deduction Type</label>
                                    <select class="form-select" id="deductionType" name="deduction_type" required>
                                        <option value="">Select Type</option>
                                        <option value="loan_payment">Loan Payment</option>
                                        <option value="cash_advance">Cash Advance</option>
                                        <option value="sss">SSS</option>
                                        <option value="pagibig">PAG-IBIG</option>
                                        <option value="philhealth">PhilHealth</option>
                                        <option value="sss_loan">SSS Loan</option>
                                        <option value="pagibig_loan">PAG-IBIG Loan</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="deductionAmount" class="form-label required-field">Amount</label>
                                    <div class="input-group">
                                        <span class="input-group-text">₱</span>
                                        <input type="text" class="form-control" id="deductionAmount" name="amount_display" required>
                                        <input type="hidden" id="deductionAmountRaw" name="amount">
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3" id="deductionFrequencyField">
                                    <label for="deductionFrequency" class="form-label">Frequency</label>
                                    <select class="form-select" id="deductionFrequency" name="frequency" style="background-color: #fff; cursor: pointer;">
                                        <option value="one_time">One Time</option>
                                        <option value="monthly">Monthly</option>
                                        <option value="semi_monthly">Semi-Monthly</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="row" id="loanFields" style="display: none;">
                                <div class="col-md-6 mb-3">
                                    <label for="loanStartDate" class="form-label required-field">Start Date</label>
                                    <input type="date" class="form-control" id="loanStartDate" name="start_date">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="loanEndDate" class="form-label required-field">End Date</label>
                                    <input type="date" class="form-control" id="loanEndDate" name="end_date">
                                </div>
                            </div>
                            
                            <div class="row" id="governmentFields" style="display: none;">
                                <div class="col-md-12 mb-3">
                                    <label for="deductionEffectiveDate" class="form-label required-field">Effective Date</label>
                                    <input type="date" class="form-control" id="deductionEffectiveDate" name="effective_date">
                                    <div class="form-text">For government contributions (SSS, PAG-IBIG, PhilHealth)</div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label for="deductionNotes" class="form-label">Notes</label>
                                    <textarea class="form-control" id="deductionNotes" name="notes" rows="2"></textarea>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">Add Deduction</button>
                        </form>
                        
                        <hr class="my-4">
                        
                        <!-- Active Deductions Table -->
                        <h6 class="mb-3">Active Deductions</h6>
                        <div class="table-responsive">
                            <table class="table table-striped table-bordered" id="activeDeductionsTable">
                                <thead>
                                    <tr>
                                        <th>Type</th>
                                        <th>Amount</th>
                                        <th>Frequency</th>
                                        <th>Period</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="activeDeductionsBody">
                                    <tr>
                                        <td colspan="6" class="text-center">No active deductions</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Deduction History Modal -->
        <div class="modal fade" id="deductionHistoryModal" tabindex="-1" aria-labelledby="deductionHistoryModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="deductionHistoryModalLabel">Deduction History</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div id="deductionHistoryContent">
                            <!-- Deduction history will be loaded here -->
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Payment Tracking Modal -->
        <div class="modal fade" id="paymentModal" tabindex="-1" aria-labelledby="paymentModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="paymentModalLabel">Record Payment</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form id="paymentForm">
                        <div class="modal-body">
                            <input type="hidden" id="paymentDeductionId" name="deduction_id">
                            <input type="hidden" id="paymentEmployeeId" name="employee_id">
                            <input type="hidden" id="paymentEmployeeType" name="employee_type">
                            
                            <div class="mb-3">
                                <label for="paymentDeductionInfo" class="form-label">Deduction</label>
                                <input type="text" class="form-control" id="paymentDeductionInfo" readonly>
                            </div>
                            
                            <div class="mb-3">
                                <label for="paymentAmount" class="form-label required-field">Payment Amount</label>
                                <div class="input-group">
                                    <span class="input-group-text">₱</span>
                                    <input type="text" class="form-control" id="paymentAmount" name="amount_display" required>
                                    <input type="hidden" id="paymentAmountRaw" name="amount">
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="paymentDate" class="form-label required-field">Payment Date</label>
                                <input type="date" class="form-control" id="paymentDate" name="payment_date" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="paymentPeriod" class="form-label">Payment Period</label>
                                <select class="form-select" id="paymentPeriod" name="payment_period">
                                    <option value="">Select Period (Optional)</option>
                                    <option value="1st_half">1st Half (1-15)</option>
                                    <option value="2nd_half">2nd Half (16-30/31)</option>
                                    <option value="custom">Custom</option>
                                </select>
                                <input type="text" class="form-control mt-2" id="customPeriod" name="custom_period" placeholder="Enter custom period" style="display: none;">
                            </div>
                            
                            <div class="mb-3">
                                <label for="paymentNotes" class="form-label">Notes</label>
                                <textarea class="form-control" id="paymentNotes" name="notes" rows="2"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Record Payment</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Allowance Management Modal -->
        <div class="modal fade" id="allowanceModal" tabindex="-1" aria-labelledby="allowanceModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="allowanceModalLabel">Manage Allowances</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="allowanceEmployeeId" name="employee_id">
                        <input type="hidden" id="allowanceEmployeeType" name="employee_type">
                        
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <h6>Employee Information</h6>
                                <p class="mb-1"><strong>Name:</strong> <span id="allowanceEmployeeName"></span></p>
                                <p><strong>Current Daily Salary:</strong> ₱<span id="allowanceEmployeeSalary"></span></p>
                            </div>
                        </div>
                        
                        <!-- Add Allowance Form -->
                        <h6 class="mb-3">Add New Allowance</h6>
                        <form id="allowanceForm">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="allowanceType" class="form-label required-field">Allowance Type</label>
                                    <select class="form-select" id="allowanceType" name="allowance_type" required>
                                        <option value="">Select Type</option>
                                        <option value="Rice Allowance">Rice Allowance</option>
                                        <option value="COLA">COLA</option>
                                        <option value="Motor Rental">Motor Rental</option>
                                        <option value="Incentive">Incentive</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="allowanceAmount" class="form-label required-field">Amount</label>
                                    <div class="input-group">
                                        <span class="input-group-text">₱</span>
                                        <input type="text" class="form-control" id="allowanceAmount" name="amount_display" required>
                                        <input type="hidden" id="allowanceAmountRaw" name="amount">
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="allowanceEffectiveDate" class="form-label required-field">Effective Date</label>
                                    <input type="date" class="form-control" id="allowanceEffectiveDate" name="effective_date" required>
                                    <div class="form-text">Date when allowance starts</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="allowanceEndDate" class="form-label">End Date (Optional)</label>
                                    <input type="date" class="form-control" id="allowanceEndDate" name="end_date">
                                    <div class="form-text">Leave blank for indefinite</div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label for="allowanceNotes" class="form-label">Notes</label>
                                    <textarea class="form-control" id="allowanceNotes" name="notes" rows="2"></textarea>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">Add Allowance</button>
                        </form>
                        
                        <hr class="my-4">
                        
                        <!-- Active Allowances Table -->
                        <h6 class="mb-3">Active Allowances</h6>
                        <div class="table-responsive">
                            <table class="table table-striped table-bordered" id="activeAllowancesTable">
                                <thead>
                                    <tr>
                                        <th>Type</th>
                                        <th>Amount</th>
                                        <th>Effective Date</th>
                                        <th>End Date</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="activeAllowancesBody">
                                    <tr>
                                        <td colspan="6" class="text-center">No active allowances</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Allowance History Modal -->
        <div class="modal fade" id="allowanceHistoryModal" tabindex="-1" aria-labelledby="allowanceHistoryModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="allowanceHistoryModalLabel">Allowance History</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div id="allowanceHistoryContent">
                            <!-- Allowance history will be loaded here -->
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Edit Collector Modal -->
        <div class="modal fade" id="editCollectorModal" tabindex="-1" aria-labelledby="editCollectorModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="editCollectorModalLabel">Edit Collector</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form id="editCollectorForm" enctype="multipart/form-data">
                        <div class="modal-body">
                            <input type="hidden" id="editCollectorId" name="id">
                            <input type="hidden" id="editAccountSource" name="account_source" value="collector">
                            <input type="hidden" id="editAccountTypeHidden" name="account_type" value="Collector">

                            <!-- Account Information Section -->
                            <div class="form-section">
                                <h5 class="section-title">Account Information</h5>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="editEmployeeNumber" class="form-label">Employee Number</label>
                                            <input type="text" class="form-control" id="editEmployeeNumber" name="employee_number">
                                            <div class="form-text">Unique employee identification number</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="editAccountStatus" class="form-label required-field">Account Status</label>
                                            <select class="form-select" id="editAccountStatus" name="account_status" required>
                                                <option value="Active">Active</option>
                                                <option value="Inactive">Inactive</option>
                                                <option value="Suspended">Suspended</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="editBranch" class="form-label required-field">Branch</label>
                                            <select class="form-select" id="editBranch" name="branch" required>
                                                <option value="">Select Branch</option>
                                                <option value="Tuguegarao City">Tuguegarao City</option>
                                                <option value="Gattaran">Gattaran</option>
                                                <option value="Abulug">Abulug</option>
                                                <option value="Ilagan">Ilagan</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="editAccountType" class="form-label required-field">Account Type</label>
                                            <select class="form-select readonly-field" id="editAccountType" name="account_type_display" required readonly style="pointer-events: none;">
                                                <option value="Collector">Collector</option>
                                                <option value="Proxy Collector" disabled>Proxy Collector</option>
                                                <option value="Proxy Collector-Cashier" disabled>Proxy Collector-Cashier</option>
                                            </select>
                                            <div class="form-text text-muted">Account type cannot be changed for collectors</div>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="editUsername" class="form-label required-field">Username</label>
                                            <input type="text" class="form-control" id="editUsername" name="username" autocomplete="off" data-lpignore="true" required>
                                            <div class="form-text">Username must be unique</div>
                                        </div>
                                    </div>

                                    <div class="form-section">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="editPassword" class="form-label">New Password</label>
                                                    <input type="password" class="form-control" id="editPassword" name="password" autocomplete="new-password" data-lpignore="true" data-form-type="other">
                                                    <div class="form-text">Leave blank to keep current password</div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="editConfirmPassword" class="form-label">Confirm Password</label>
                                                    <input type="password" class="form-control" id="editConfirmPassword" name="confirm_password" autocomplete="new-password" data-lpignore="true" data-form-type="other">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-12">
                                        <div class="mb-3">
                                            <img id="editProfileImagePreview" src="#" alt="Profile Preview" class="profile-image-preview mt-2 d-none">
                                            <div class="collector-image">
                                                <label for="editProfileImage" class="form-label">Profile Image</label>
                                                <input type="file" class="form-control" id="editProfileImage" name="profile_image" accept="image/*">
                                                <input type="hidden" id="editCurrentProfileImage" name="current_profile_image">
                                                <div class="form-text">Max file size: 5MB. Allowed formats: JPG, PNG, GIF</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Personal Information Section -->
                            <div class="form-section">
                                <h5 class="section-title">Personal Information</h5>
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="editLastName" class="form-label required-field">Last Name</label>
                                            <input type="text" class="form-control" id="editLastName" name="last_name" required>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="editFirstName" class="form-label required-field">First Name</label>
                                            <input type="text" class="form-control" id="editFirstName" name="first_name" required>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="editMiddleName" class="form-label">Middle Name</label>
                                            <input type="text" class="form-control" id="editMiddleName" name="middle_name">
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="editSuffix" class="form-label">Suffix</label>
                                            <select class="form-select" id="editSuffix" name="suffix">
                                                <option value="None">None</option>
                                                <option value="Jr">Jr</option>
                                                <option value="Sr">Sr</option>
                                                <option value="II">II</option>
                                                <option value="III">III</option>
                                                <option value="IV">IV</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="editBirthDate" class="form-label required-field">Birth Date</label>
                                            <input type="date" class="form-control" id="editBirthDate" name="birth_date" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="editPlaceOfBirth" class="form-label required-field">Place of Birth</label>
                                            <input type="text" class="form-control" id="editPlaceOfBirth" name="place_of_birth" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="editStatus" class="form-label required-field">Status</label>
                                            <select class="form-select" id="editStatus" name="status" required>
                                                <option value="Single">Single</option>
                                                <option value="Married">Married</option>
                                                <option value="Widowed">Widowed</option>
                                                <option value="Separated">Separated</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="editGender" class="form-label required-field">Gender</label>
                                            <select class="form-select" id="editGender" name="gender" required>
                                                <option value="Male">Male</option>
                                                <option value="Female">Female</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-12">
                                        <div class="mb-3">
                                            <label for="editSpouseName" class="form-label">Spouse Name (if applicable)</label>
                                            <input type="text" class="form-control" id="editSpouseName" name="spouse_name">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Contact Information Section -->
                            <div class="form-section">
                                <h5 class="section-title">Contact Information</h5>
                                <div class="row">
                                    <div class="col-md-12">
                                        <div class="mb-3">
                                            <label for="editAddress" class="form-label required-field">Address</label>
                                            <textarea class="form-control" id="editAddress" name="address" rows="3" required></textarea>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="editContactNumber" class="form-label required-field">Contact Number</label>
                                            <input type="text" class="form-control" id="editContactNumber" name="contact_number" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="editArea" class="form-label required-field">Area</label>
                                            <select class="form-select" id="editArea" name="area" required>
                                                <option value="Area 1">Area 1</option>
                                                <option value="Area 2">Area 2</option>
                                                <option value="Area 3">Area 3</option>
                                                <option value="Area 4">Area 4</option>
                                                <option value="Area 5">Area 5</option>
                                                <option value="Area 6">Area 6</option>
                                                <option value="Area 7">Area 7</option>
                                                <option value="Area 8">Area 8</option>
                                                <option value="Area 9">Area 9</option>
                                                <option value="Area 10">Area 10</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="submit" class="btn btn-primary">Save changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Edit Proxy Modal -->
        <div class="modal fade" id="editProxyModal" tabindex="-1" aria-labelledby="editProxyModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="editProxyModalLabel">Edit Proxy</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form id="editProxyForm" enctype="multipart/form-data">
                        <div class="modal-body">
                            <input type="hidden" id="editProxyId" name="id">
                            <input type="hidden" id="editProxyAccountSource" name="account_source" value="proxy">

                            <!-- Account Information Section -->
                            <div class="form-section">
                                <h5 class="section-title">Account Information</h5>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="editProxyEmployeeNumber" class="form-label">Employee Number</label>
                                            <input type="text" class="form-control" id="editProxyEmployeeNumber" name="employee_number">
                                            <div class="form-text">Unique employee identification number</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="editProxyAccountStatus" class="form-label required-field">Account Status</label>
                                            <select class="form-select" id="editProxyAccountStatus" name="account_status" required>
                                                <option value="Active">Active</option>
                                                <option value="Inactive">Inactive</option>
                                                <option value="Suspended">Suspended</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="editProxyBranch" class="form-label required-field">Branch</label>
                                            <select class="form-select" id="editProxyBranch" name="branch" required>
                                                <option value="">Select Branch</option>
                                                <option value="Tuguegarao City">Tuguegarao City</option>
                                                <option value="Gattaran">Gattaran</option>
                                                <option value="Abulug">Abulug</option>
                                                <option value="Ilagan">Ilagan</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="editProxyAccountType" class="form-label required-field">Account Type</label>
                                            <select class="form-select" id="editProxyAccountType" name="account_type" required>
                                                <option value="Proxy Collector">Proxy Collector</option>
                                                <option value="Proxy Collector-Cashier">Proxy Collector-Cashier</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="editProxyUsername" class="form-label required-field">Username</label>
                                            <input type="text" class="form-control" id="editProxyUsername" name="username" autocomplete="off" data-lpignore="true" required>
                                            <div class="form-text">Username must be unique</div>
                                        </div>
                                    </div>

                                    <div class="form-section">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="editProxyPassword" class="form-label">New Password</label>
                                                    <input type="password" class="form-control" id="editProxyPassword" name="password" autocomplete="new-password" data-lpignore="true" data-form-type="other">
                                                    <div class="form-text">Leave blank to keep current password</div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="editProxyConfirmPassword" class="form-label">Confirm Password</label>
                                                    <input type="password" class="form-control" id="editProxyConfirmPassword" name="confirm_password" autocomplete="new-password" data-lpignore="true" data-form-type="other">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-12">
                                        <div class="mb-3">
                                            <img id="editProxyProfileImagePreview" src="#" alt="Profile Preview" class="profile-image-preview mt-2 d-none">
                                            <div class="collector-image">
                                                <label for="editProxyProfileImage" class="form-label">Profile Image</label>
                                                <input type="file" class="form-control" id="editProxyProfileImage" name="profile_image" accept="image/*">
                                                <input type="hidden" id="editProxyCurrentProfileImage" name="current_profile_image">
                                                <div class="form-text">Max file size: 5MB. Allowed formats: JPG, PNG, GIF</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Personal Information Section -->
                            <div class="form-section">
                                <h5 class="section-title">Personal Information</h5>
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="editProxyLastName" class="form-label required-field">Last Name</label>
                                            <input type="text" class="form-control" id="editProxyLastName" name="last_name" required>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="editProxyFirstName" class="form-label required-field">First Name</label>
                                            <input type="text" class="form-control" id="editProxyFirstName" name="first_name" required>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="editProxyMiddleName" class="form-label">Middle Name</label>
                                            <input type="text" class="form-control" id="editProxyMiddleName" name="middle_name">
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="editProxySuffix" class="form-label">Suffix</label>
                                            <select class="form-select" id="editProxySuffix" name="suffix">
                                                <option value="None">None</option>
                                                <option value="Jr">Jr</option>
                                                <option value="Sr">Sr</option>
                                                <option value="II">II</option>
                                                <option value="III">III</option>
                                                <option value="IV">IV</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="editProxyBirthDate" class="form-label required-field">Birth Date</label>
                                            <input type="date" class="form-control" id="editProxyBirthDate" name="birth_date" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="editProxyPlaceOfBirth" class="form-label required-field">Place of Birth</label>
                                            <input type="text" class="form-control" id="editProxyPlaceOfBirth" name="place_of_birth" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="editProxyStatus" class="form-label required-field">Status</label>
                                            <select class="form-select" id="editProxyStatus" name="status" required>
                                                <option value="Single">Single</option>
                                                <option value="Married">Married</option>
                                                <option value="Widowed">Widowed</option>
                                                <option value="Separated">Separated</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="editProxyGender" class="form-label required-field">Gender</label>
                                            <select class="form-select" id="editProxyGender" name="gender" required>
                                                <option value="Male">Male</option>
                                                <option value="Female">Female</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-12">
                                        <div class="mb-3">
                                            <label for="editProxySpouseName" class="form-label">Spouse Name (if applicable)</label>
                                            <input type="text" class="form-control" id="editProxySpouseName" name="spouse_name">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Contact Information Section -->
                            <div class="form-section">
                                <h5 class="section-title">Contact Information</h5>
                                <div class="row">
                                    <div class="col-md-12">
                                        <div class="mb-3">
                                            <label for="editProxyAddress" class="form-label required-field">Address</label>
                                            <textarea class="form-control" id="editProxyAddress" name="address" rows="3" required></textarea>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="editProxyContactNumber" class="form-label required-field">Contact Number</label>
                                            <input type="text" class="form-control" id="editProxyContactNumber" name="contact_number" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="editProxyArea" class="form-label required-field">Area</label>
                                            <select class="form-select" id="editProxyArea" name="area" required>
                                                <option value="Area 1">Area 1</option>
                                                <option value="Area 2">Area 2</option>
                                                <option value="Area 3">Area 3</option>
                                                <option value="Area 4">Area 4</option>
                                                <option value="Area 5">Area 5</option>
                                                <option value="Area 6">Area 6</option>
                                                <option value="Area 7">Area 7</option>
                                                <option value="Area 8">Area 8</option>
                                                <option value="Area 9">Area 9</option>
                                                <option value="Area 10">Area 10</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="submit" class="btn btn-primary">Save changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- View Customers Modal -->
        <div class="modal fade" id="viewCustomersModal" tabindex="-1" aria-labelledby="viewCustomersModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="viewCustomersModalLabel">Collector Customers List - <span id="areaTitle"></span></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Customer Name</th>
                                        <th>Contact Number</th>
                                        <th>Area</th>
                                        <th>Branch</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody id="customersListBody">
                                    <!-- Customers will be loaded here via AJAX -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- View Account Modal -->
        <div class="modal fade" id="viewAccountModal" tabindex="-1" aria-labelledby="viewAccountModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="viewAccountModalLabel">Account Details</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" id="accountDetails">
                        <!-- Account details will be loaded here via AJAX -->
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit Account Modal -->
        <div class="modal fade" id="editAccountModal" tabindex="-1" aria-labelledby="editAccountModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="editAccountModalLabel">Edit Account</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form id="editAccountModalForm" onsubmit="return handleEditFormSubmission(this)">
                        <div class="modal-body" id="editAccountForm">
                            <!-- Edit form fields are loaded here via AJAX -->
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="submit" class="btn btn-primary">Save changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- View Proxy Modal -->
        <div class="modal fade" id="viewProxyModal" tabindex="-1" aria-labelledby="viewProxyModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="viewProxyModalLabel">Proxy Account Details</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" id="proxyDetails">
                        <!-- Proxy details will be loaded here via AJAX -->
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- View Collector Details Modal -->
        <div class="modal fade" id="viewCollectorModal" tabindex="-1" aria-labelledby="viewCollectorModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="viewCollectorModalLabel">Collector Account Details</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" id="collectorDetails">
                        <!-- Collector details will be loaded here via AJAX -->
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <?php include __DIR__ . '/includes/topbar.php'; ?>

        <div id="layoutSidenav">
            <?php include __DIR__ . '/includes/sidebar.php'; ?>

            <div id="layoutSidenav_content">
                <main>
                    <div class="dash">
                        <header class="dash-head">
                            <div>
                                <p class="dash-eyebrow">Prime City &middot; Employees</p>
                                <h1 class="dash-title">Employees</h1>
                                <p class="dash-sub">Collector, proxy and system accounts available to you.</p>
                            </div>
                            <?php if (!$is_proxy_cashier && $user['account_type'] !== 'Proxy Collector' && $user['account_type'] !== 'Collector'): ?>
                            <div class="dash-head__actions">
                                <a class="btn btn-primary" id="primaryAddBtn" href="collector_registration.php"
                                   data-label="Add New Collector" data-icon="fa-plus"
                                   data-href-collector="collector_registration.php"
                                   data-label-collector="Add New Collector"
                                   data-icon-collector="fa-plus"
                                   data-href-proxy="proxy_registration.php"
                                   data-label-proxy="Add New Proxy"
                                   data-icon-proxy="fa-plus"
                                   data-href-accounts="accounts_registration.php"
                                   data-label-accounts="Add New Account"
                                   data-icon-accounts="fa-user-plus">
                                    <i class="fas fa-plus me-1" id="primaryAddBtnIcon" aria-hidden="true"></i>
                                    <span id="primaryAddBtnLabel">Add New Collector</span>
                                </a>
                            </div>
                            <?php endif; ?>
                        </header>

                        <section class="dash-stats dash-stats--three" aria-label="Employee summary">
                            <article class="stat">
                                <div class="stat__body">
                                    <p class="stat__label">Collectors</p>
                                    <p class="stat__value"><?php echo count($collectors); ?></p>
                                    <p class="stat__meta">Collector accounts</p>
                                </div>
                                <span class="stat__icon"><i class="fas fa-users" aria-hidden="true"></i></span>
                            </article>

                            <article class="stat stat--cyan">
                                <div class="stat__body">
                                    <p class="stat__label">Proxy accounts</p>
                                    <p class="stat__value"><?php echo count($proxy_accounts); ?></p>
                                    <p class="stat__meta">Proxy collector accounts</p>
                                </div>
                                <span class="stat__icon"><i class="fas fa-user-friends" aria-hidden="true"></i></span>
                            </article>

                            <article class="stat stat--navy">
                                <div class="stat__body">
                                    <p class="stat__label">System accounts</p>
                                    <p class="stat__value"><?php echo count($accounts); ?></p>
                                    <p class="stat__meta">Employees and managers</p>
                                </div>
                                <span class="stat__icon"><i class="fas fa-user-shield" aria-hidden="true"></i></span>
                            </article>
                        </section>
                        
                        <!-- Branch filter: moved into the table toolbar by employee-lists-ui.js -->
                        <?php if ($show_branch_filter): ?>
                        <div class="unified-filter-container dash-toolbar">
                            <div class="filter-group">
                                <label class="filter-label">Filter by Branch:</label>
                                <select class="form-select filter-select" id="branchFilter" onchange="filterByBranch()">
                                    <?php if ($show_all_branches_option): ?>
                                        <option value="">All Branches</option>
                                    <?php endif; ?>
                                    
                                    <?php if ($user['account_type'] === 'Area Manager' && !empty($available_branches)): ?>
                                        <!-- FIXED: For Area Manager, show only their assigned branches -->
                                        <?php foreach($available_branches as $branch_option): ?>
                                            <option value="<?php echo htmlspecialchars($branch_option); ?>" <?php echo $branch_filter === $branch_option ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($branch_option); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <!-- For Admin, show all branches -->
                                        <option value="Tuguegarao City" <?php echo $branch_filter === 'Tuguegarao City' ? 'selected' : ''; ?>>Tuguegarao City</option>
                                        <option value="Gattaran" <?php echo $branch_filter === 'Gattaran' ? 'selected' : ''; ?>>Gattaran</option>
                                        <option value="Abulug" <?php echo $branch_filter === 'Abulug' ? 'selected' : ''; ?>>Abulug</option>
                                        <option value="Ilagan" <?php echo $branch_filter === 'Ilagan' ? 'selected' : ''; ?>>Ilagan</option>
                                    <?php endif; ?>
                                </select>
                                
                                <!-- FIXED: Show Area Manager's assigned branches -->
                                <?php if ($user['account_type'] === 'Area Manager' && !empty($available_branches)): ?>
                                    <span class="badge bg-info ms-2">
                                        Assigned Branches: <?php echo htmlspecialchars(implode(', ', $available_branches)); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Tabs Navigation -->
                        <ul class="nav nav-tabs dash-tabs" id="employeesTabs" role="tablist">
                            <?php 
                                // Determine which tabs to show based on user type
                                
                                // Case 1: User is a Proxy Collector (from proxy_accounts table)
                                $is_proxy_collector_only = ($user['account_type'] === 'Proxy Collector' && $user_table === 'proxy_accounts');
                                
                                // Case 2: User is a Proxy Collector-Cashier (from proxy_accounts table)
                                $is_proxy_cashier_user = ($user['account_type'] === 'Proxy Collector-Cashier' && $user_table === 'proxy_accounts');
                                
                                // Case 3: User is a regular Collector (from collector table)
                                $is_collector_only = ($user['account_type'] === 'Collector' && $user_table === 'collector');
                                
                                // Case 4: Admin or Management users
                                $is_admin_or_manager = ($is_admin || $user['account_type'] === 'Area Manager' || $user['account_type'] === 'Branch Manager');
                                
                                // Determine which tab should be active by default
                                $show_collector_tab = false;
                                $show_proxy_tab = false;
                                $show_accounts_tab = false;
                                $active_tab = 'collector'; // default
                                
                                if ($is_proxy_collector_only) {
                                    $show_proxy_tab = true;
                                    $active_tab = 'proxy';
                                } 
                                elseif ($is_collector_only) {
                                    $show_collector_tab = true;
                                    $active_tab = 'collector';
                                }
                                elseif ($is_proxy_cashier_user) {
                                    $show_collector_tab = true;
                                    $show_proxy_tab = true;
                                    $active_tab = 'collector';
                                }
                                elseif ($is_admin_or_manager) {
                                    $show_collector_tab = true;
                                    $show_proxy_tab = true;
                                    if ($is_admin) {
                                        $show_accounts_tab = true;
                                    }
                                    $active_tab = 'collector';
                                }
                                else {
                                    $show_collector_tab = true;
                                    $active_tab = 'collector';
                                }
                            ?>
                            
                            <?php if ($show_collector_tab): ?>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?php echo $active_tab === 'collector' ? 'active' : ''; ?>" id="collector-tab" data-bs-toggle="tab" data-bs-target="#collector-tab-pane" type="button" role="tab" aria-controls="collector-tab-pane" aria-selected="<?php echo $active_tab === 'collector' ? 'true' : 'false'; ?>">
                                    <i class="fas fa-users me-1"></i> Collector Accounts
                                </button>
                            </li>
                            <?php endif; ?>
                            
                            <?php if ($show_proxy_tab): ?>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?php echo $active_tab === 'proxy' ? 'active' : ''; ?>" id="proxy-tab" data-bs-toggle="tab" data-bs-target="#proxy-tab-pane" type="button" role="tab" aria-controls="proxy-tab-pane" aria-selected="<?php echo $active_tab === 'proxy' ? 'true' : 'false'; ?>">
                                    <i class="fas fa-user-friends me-1"></i> Proxy Accounts
                                </button>
                            </li>
                            <?php endif; ?>
                            
                            <?php if ($show_accounts_tab): ?>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?php echo $active_tab === 'accounts' ? 'active' : ''; ?>" id="accounts-tab" data-bs-toggle="tab" data-bs-target="#accounts-tab-pane" type="button" role="tab" aria-controls="accounts-tab-pane" aria-selected="<?php echo $active_tab === 'accounts' ? 'true' : 'false'; ?>">
                                    <i class="fas fa-user-circle me-1"></i> Accounts List
                                </button>
                            </li>
                            <?php endif; ?>
                        </ul>
                        
                        <!-- Tabs Content -->
                        <div class="tab-content" id="employeesTabsContent">
                            <!-- Collector Accounts Tab -->
                            <div class="tab-pane fade <?php echo $active_tab === 'collector' ? 'show active' : ''; ?>" id="collector-tab-pane" role="tabpanel" aria-labelledby="collector-tab" tabindex="0">
                                <div class="card mb-4">
                                    <div class="card-header">
                                        <div class="card-header-content">
                                            <i class="fas fa-table me-1"></i>
                                            <?php echo (($user['account_type'] === 'Collector') && ($user_table === 'accounts' || $user_table === 'collector')) ? 'My Account' : 'Collector Accounts'; ?>
                                            <?php if ($branch_filter || ($is_restricted_user && !empty($user_branch))): ?>
                                                <?php $display_branch = $branch_filter ?: $user_branch; ?>
                                                <span class="badge bg-primary">Branch: <?php echo htmlspecialchars($display_branch); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="card-body dash-card__body">
                                        <?php 
                                        $display_collectors = $collectors;
                                        
                                        $is_collector_user_check = ($user['account_type'] === 'Collector');
                                        
                                        if ($is_collector_user_check) {
                                            $display_collectors = array_filter($collectors, function($collector) use ($user) {
                                                return isset($collector['id']) && $collector['id'] == $_SESSION['user_id'];
                                            });
                                        }
                                        
                                        if (count($display_collectors) === 0): ?>
                                            <div class="no-results-container">
                                                <div class="no-results-icon"><i class="fas fa-search"></i></div>
                                                <h4 class="text-muted">No Collector Accounts Found</h4>
                                                <?php if ($user['account_type'] === 'Collector'): ?>
                                                    <p class="text-muted">
                                                        Your account information is managed in the system.<br>
                                                        Please contact an administrator if you need to update your information.
                                                    </p>
                                                <?php elseif ($branch_filter || ($is_restricted_user && !empty($user_branch))): ?>
                                                    <?php $display_branch = $branch_filter ?: $user_branch; ?>
                                                    <p class="text-muted">
                                                        Branch: <?php echo htmlspecialchars($display_branch); ?><br>
                                                        No collector accounts found for this branch.
                                                    </p>
                                                    <a href="employee_lists.php" class="btn btn-primary mt-3">Clear Filter</a>
                                                <?php else: ?>
                                                    <p class="text-muted">No collector accounts have been registered yet.</p>
                                                    <?php if (!$is_proxy_cashier && $user['account_type'] !== 'Collector'): ?>
                                                    <a href="collector_registration.php" class="btn btn-primary mt-3">Add New Collector</a>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table dash-table" id="collectorAccountsTable">
                                                <thead>
                                                    <tr>
                                                        <th>Profile</th>
                                                        <th>Collector</th>
                                                        <th>Area</th>
                                                        <th>Branch</th>
                                                        <th>Daily Salary</th>
                                                        <th>Deductions</th>
                                                        <th>Status</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach($display_collectors as $collector): ?>
                                                        <?php
                                                        $fullName = htmlspecialchars($collector['last_name']) . ', ' . 
                                                        htmlspecialchars($collector['first_name']) . 
                                                        ($collector['middle_name'] ? ' ' . htmlspecialchars($collector['middle_name']) : '') . 
                                                        ($collector['suffix'] && $collector['suffix'] != 'None' ? ' ' . htmlspecialchars($collector['suffix']) : '');
                                                        
                                                        $activeDeductions = getEmployeeUniqueActiveDeductions($collector['id'], 'collector');
                                                        ?>
                                                        <tr>
                                                            <td class="profile-image-cell">
                                                                <?php if (!empty($collector['profile_image']) && file_exists($collector['profile_image'])): ?>
                                                                    <img src="<?php echo htmlspecialchars($collector['profile_image']); ?>" alt="Profile Image" class="profile-img" data-image-src="<?php echo htmlspecialchars($collector['profile_image']); ?>" data-collector-name="<?php echo $fullName; ?>">
                                                                <?php else: ?>
                                                                    <div class="no-image-icon" data-collector-name="<?php echo $fullName; ?>"><i class="fas fa-user"></i></div>
                                                                <?php endif; ?>
                                                            </td>
                                                            
                                                            <td class="collector-name"><?php echo $fullName; ?></td>
                                                            
                                                            <td class="collector-area"><?php echo htmlspecialchars($collector['area']); ?></td>
                                                            
                                                            <td class="collector-area"><?php echo !empty($collector['branch']) ? htmlspecialchars($collector['branch']) : 'N/A'; ?></td>
                                                            
                                                            <td class="collector-salary">
                                                                <?php if (!empty($collector['current_salary']) && $collector['current_salary'] > 0): ?>
                                                                    ₱<?php echo number_format($collector['current_salary'], 2); ?>
                                                                    <?php if (!empty($collector['salary_effective_date'])): ?>
                                                                        <span class="salary-effective-date">Effective: <?php echo date('M d, Y', strtotime($collector['salary_effective_date'])); ?></span>
                                                                    <?php endif; ?>
                                                                <?php else: ?>
                                                                    <span class="text-muted">Not set</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            
                                                            <td class="collector-deductions">
                                                                <?php if (!empty($activeDeductions)): ?>
                                                                    <div class="deductions-container">
                                                                        <?php foreach($activeDeductions as $deduction): ?>
                                                                            <?php 
                                                                            $deductionClass = '';
                                                                            $deductionLabel = '';
                                                                            switch($deduction) {
                                                                                case 'sss':
                                                                                    $deductionClass = 'deduction-sss';
                                                                                    $deductionLabel = 'SSS';
                                                                                    break;
                                                                                case 'pagibig':
                                                                                    $deductionClass = 'deduction-pagibig';
                                                                                    $deductionLabel = 'PAG-IBIG';
                                                                                    break;
                                                                                case 'philhealth':
                                                                                    $deductionClass = 'deduction-philhealth';
                                                                                    $deductionLabel = 'PhilHealth';
                                                                                    break;
                                                                                case 'loan_payment':
                                                                                    $deductionClass = 'deduction-loan';
                                                                                    $deductionLabel = 'Loan';
                                                                                    break;
                                                                                case 'cash_advance':
                                                                                    $deductionClass = 'deduction-cashadvance';
                                                                                    $deductionLabel = 'Cash Adv';
                                                                                    break;
                                                                                case 'sss_loan':
                                                                                    $deductionClass = 'deduction-sss-loan';
                                                                                    $deductionLabel = 'SSS Loan';
                                                                                    break;
                                                                                case 'pagibig_loan':
                                                                                    $deductionClass = 'deduction-pagibig-loan';
                                                                                    $deductionLabel = 'PAG-IBIG Loan';
                                                                                    break;
                                                                            }
                                                                            ?>
                                                                            <span class="badge deduction-badge <?php echo $deductionClass; ?>"><?php echo $deductionLabel; ?></span>
                                                                        <?php endforeach; ?>
                                                                    </div>
                                                                <?php else: ?>
                                                                    <span class="no-deductions">No active deductions</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            
                                                            <?php
                                                            $statusClass = '';
                                                            if ($collector['account_status'] == 'Active') {
                                                                $statusClass = 'text-success collector-status';
                                                            } elseif ($collector['account_status'] == 'Inactive') {
                                                                $statusClass = 'text-secondary collector-status';
                                                            } elseif ($collector['account_status'] == 'Suspended') {
                                                                $statusClass = 'text-danger collector-status';
                                                            }
                                                            ?>
                                                            <td class="<?php echo $statusClass; ?>"><?php echo htmlspecialchars($collector['account_status']); ?></td>
                                                            
                                                            <td class="action-buttons">
                                                                <div class="action-buttons-container">
                                                                    <button class="btn btn-primary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                                        <i class="fas fa-cog"></i> Menu
                                                                    </button>
                                                                    <ul class="dropdown-menu">
                                                                        <?php if (!$is_collector_user_check): ?>
                                                                            <li><h6 class="dropdown-header">Salary Management</h6></li>
                                                                            <li><a class="dropdown-item manage-salary-btn" href="#" data-id="<?php echo $collector['id']; ?>" data-type="collector" data-name="<?php echo $fullName; ?>" data-salary="<?php echo $collector['current_salary'] ?? 0; ?>"><i class="fas fa-coins"></i> Daily Salary</a></li>
                                                                            <li><a class="dropdown-item view-salary-history-btn" href="#" data-id="<?php echo $collector['id']; ?>" data-type="collector" data-name="<?php echo $fullName; ?>"><i class="fas fa-history"></i> View Salary History</a></li>
                                                                            <li><hr class="dropdown-divider"></li>
                                                                            <li><h6 class="dropdown-header">Deduction Management</h6></li>
                                                                            <li><a class="dropdown-item manage-deductions-btn" href="#" data-id="<?php echo $collector['id']; ?>" data-type="collector" data-name="<?php echo $fullName; ?>" data-salary="<?php echo $collector['current_salary'] ?? 0; ?>"><i class="fas fa-calculator"></i> Manage Deductions</a></li>
                                                                            <li><a class="dropdown-item view-deductions-history-btn" href="#" data-id="<?php echo $collector['id']; ?>" data-type="collector" data-name="<?php echo $fullName; ?>"><i class="fas fa-list-alt"></i> View Deductions History</a></li>
                                                                            <li><hr class="dropdown-divider"></li>
                                                                            <li><h6 class="dropdown-header">Allowance Management</h6></li>
                                                                            <li><a class="dropdown-item manage-allowances-btn" href="#" data-id="<?php echo $collector['id']; ?>" data-type="collector" data-name="<?php echo $fullName; ?>" data-salary="<?php echo $collector['current_salary'] ?? 0; ?>"><i class="fas fa-gift"></i> Manage Allowances</a></li>
                                                                            <li><a class="dropdown-item view-allowance-history-btn" href="#" data-id="<?php echo $collector['id']; ?>" data-type="collector" data-name="<?php echo $fullName; ?>"><i class="fas fa-history"></i> View Allowance History</a></li>
                                                                            <li><hr class="dropdown-divider"></li>
                                                                            <li><h6 class="dropdown-header">Actions</h6></li>
                                                                            <li><a class="dropdown-item view-collections-btn" href="#" data-id="<?php echo $collector['id']; ?>"><i class="fas fa-money-bill-wave"></i> View Collections</a></li>
                                                                            <li><a class="dropdown-item view-customers-btn" href="#" data-id="<?php echo $collector['id']; ?>" data-area="<?php echo htmlspecialchars($collector['area']); ?>" data-branch="<?php echo htmlspecialchars($collector['branch']); ?>"><i class="fas fa-users"></i> View Customers</a></li>
                                                                        <?php else: ?>
                                                                            <li><a class="dropdown-item view-deductions-history-btn" href="#" data-id="<?php echo $collector['id']; ?>" data-type="collector" data-name="<?php echo $fullName; ?>"><i class="fas fa-list-alt"></i> View Deductions History</a></li>
                                                                        <?php endif; ?>
                                                                        <li><a class="dropdown-item view-loans-btn" href="#" data-id="<?php echo $collector['id']; ?>" onclick="window.location.href='collector_loan_record.php?id=<?php echo $collector['id']; ?>'"><i class="fas fa-hand-holding-usd"></i> View Loans</a></li>
                                                                        <li><hr class="dropdown-divider"></li>
                                                                        <li><a class="dropdown-item view-details-btn" href="#" data-id="<?php echo $collector['id']; ?>"><i class="fas fa-eye"></i> View Details</a></li>
                                                                        <?php if (!$is_proxy_cashier && $user['account_type'] !== 'Collector' && !$is_collector_user_check): ?>
                                                                        <li><a class="dropdown-item edit-btn" href="#" data-id="<?php echo $collector['id']; ?>"><i class="fas fa-edit"></i> Edit</a></li>
                                                                        <li><a class="dropdown-item text-danger delete-btn" href="#" data-id="<?php echo $collector['id']; ?>"><i class="fas fa-trash-alt"></i> Delete</a></li>
                                                                        <?php endif; ?>
                                                                    </ul>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Proxy Accounts Tab -->
                            <div class="tab-pane fade <?php echo $active_tab === 'proxy' ? 'show active' : ''; ?>" id="proxy-tab-pane" role="tabpanel" aria-labelledby="proxy-tab" tabindex="0">
                                <div class="card mb-4">
                                    <div class="card-header">
                                        <div class="card-header-content">
                                            <i class="fas fa-table me-1"></i>
                                            <?php echo (($user['account_type'] === 'Proxy Collector') && $user_table === 'proxy_accounts') ? 'My Account' : 'Proxy Accounts'; ?>
                                            <?php if ($branch_filter || ($is_restricted_user && !empty($user_branch))): ?>
                                                <?php $display_branch = $branch_filter ?: $user_branch; ?>
                                                <span class="badge bg-primary">Branch: <?php echo htmlspecialchars($display_branch); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="card-body dash-card__body">
                                        <?php 
                                        $display_proxy_accounts = $proxy_accounts;
                                        
                                        $is_proxy_collector_user_check = ($user['account_type'] === 'Proxy Collector' && $user_table === 'proxy_accounts');
                                        
                                        if ($is_proxy_collector_user_check) {
                                            $display_proxy_accounts = array_filter($proxy_accounts, function($proxy) use ($user) {
                                                return isset($proxy['id']) && $proxy['id'] == $_SESSION['user_id'];
                                            });
                                        }
                                        
                                        if (count($display_proxy_accounts) === 0): ?>
                                            <div class="no-results-container">
                                                <div class="no-results-icon"><i class="fas fa-search"></i></div>
                                                <h4 class="text-muted">No Proxy Accounts Found</h4>
                                                <?php if ($user['account_type'] === 'Proxy Collector'): ?>
                                                    <p class="text-muted">
                                                        Your account information is managed in the system.<br>
                                                        Please contact an administrator if you need to update your information.
                                                    </p>
                                                <?php elseif ($branch_filter || ($is_restricted_user && !empty($user_branch))): ?>
                                                    <?php $display_branch = $branch_filter ?: $user_branch; ?>
                                                    <p class="text-muted">
                                                        Branch: <?php echo htmlspecialchars($display_branch); ?><br>
                                                        No proxy accounts found for this branch.
                                                    </p>
                                                    <a href="employee_lists.php" class="btn btn-primary mt-3">Clear Filter</a>
                                                <?php else: ?>
                                                    <p class="text-muted">No proxy accounts have been registered yet.</p>
                                                    <?php if (!$is_proxy_cashier && $user['account_type'] !== 'Proxy Collector'): ?>
                                                    <a href="proxy_registration.php" class="btn btn-primary mt-3">Add New Proxy</a>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table dash-table" id="proxyAccountsTable">
                                                <thead>
                                                    <tr>
                                                        <th>Profile</th>
                                                        <th>Proxy Name</th>
                                                        <th>Account Type</th>
                                                        <th>Branch</th>
                                                        <th>Daily Salary</th>
                                                        <th>Deductions</th>
                                                        <th>Status</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach($display_proxy_accounts as $proxy): ?>
                                                        <?php
                                                        $fullName = htmlspecialchars($proxy['last_name']) . ', ' . 
                                                        htmlspecialchars($proxy['first_name']) . 
                                                        ($proxy['middle_name'] ? ' ' . htmlspecialchars($proxy['middle_name']) : '') . 
                                                        ($proxy['suffix'] && $proxy['suffix'] != 'None' ? ' ' . htmlspecialchars($proxy['suffix']) : '');
                                                        
                                                        $activeDeductions = getEmployeeUniqueActiveDeductions($proxy['id'], 'proxy');
                                                        ?>
                                                        <tr>
                                                            <td class="profile-image-cell">
                                                                <?php if (!empty($proxy['profile_image']) && file_exists($proxy['profile_image'])): ?>
                                                                    <img src="<?php echo htmlspecialchars($proxy['profile_image']); ?>" alt="Profile Image" class="profile-img" data-image-src="<?php echo htmlspecialchars($proxy['profile_image']); ?>" data-collector-name="<?php echo $fullName; ?>">
                                                                <?php else: ?>
                                                                    <div class="no-image-icon" data-collector-name="<?php echo $fullName; ?>"><i class="fas fa-user"></i></div>
                                                                <?php endif; ?>
                                                            </td>
                                                            
                                                            <td class="collector-name"><?php echo $fullName; ?></td>
                                                            
                                                            <td class="collector-area">
                                                                <span class="badge <?php echo $proxy['account_type'] === 'Proxy Collector-Cashier' ? 'bg-success' : 'bg-info'; ?>">
                                                                    <?php echo htmlspecialchars($proxy['account_type']); ?>
                                                                </span>
                                                            </td>
                                                            
                                                            <td class="collector-area"><?php echo !empty($proxy['branch']) ? htmlspecialchars($proxy['branch']) : 'N/A'; ?></td>
                                                            
                                                            <td class="collector-salary">
                                                                <?php if (!empty($proxy['current_salary']) && $proxy['current_salary'] > 0): ?>
                                                                    ₱<?php echo number_format($proxy['current_salary'], 2); ?>
                                                                    <?php if (!empty($proxy['salary_effective_date'])): ?>
                                                                        <span class="salary-effective-date">Effective: <?php echo date('M d, Y', strtotime($proxy['salary_effective_date'])); ?></span>
                                                                    <?php endif; ?>
                                                                <?php else: ?>
                                                                    <span class="text-muted">Not set</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            
                                                            <td class="collector-deductions">
                                                                <?php if (!empty($activeDeductions)): ?>
                                                                    <div class="deductions-container">
                                                                        <?php foreach($activeDeductions as $deduction): ?>
                                                                            <?php 
                                                                            $deductionClass = '';
                                                                            $deductionLabel = '';
                                                                            switch($deduction) {
                                                                                case 'sss':
                                                                                    $deductionClass = 'deduction-sss';
                                                                                    $deductionLabel = 'SSS';
                                                                                    break;
                                                                                case 'pagibig':
                                                                                    $deductionClass = 'deduction-pagibig';
                                                                                    $deductionLabel = 'PAG-IBIG';
                                                                                    break;
                                                                                case 'philhealth':
                                                                                    $deductionClass = 'deduction-philhealth';
                                                                                    $deductionLabel = 'PhilHealth';
                                                                                    break;
                                                                                case 'loan_payment':
                                                                                    $deductionClass = 'deduction-loan';
                                                                                    $deductionLabel = 'Loan';
                                                                                    break;
                                                                                case 'cash_advance':
                                                                                    $deductionClass = 'deduction-cashadvance';
                                                                                    $deductionLabel = 'Cash Adv';
                                                                                    break;
                                                                                case 'sss_loan':
                                                                                    $deductionClass = 'deduction-sss-loan';
                                                                                    $deductionLabel = 'SSS Loan';
                                                                                    break;
                                                                                case 'pagibig_loan':
                                                                                    $deductionClass = 'deduction-pagibig-loan';
                                                                                    $deductionLabel = 'PAG-IBIG Loan';
                                                                                    break;
                                                                            }
                                                                            ?>
                                                                            <span class="badge deduction-badge <?php echo $deductionClass; ?>"><?php echo $deductionLabel; ?></span>
                                                                        <?php endforeach; ?>
                                                                    </div>
                                                                <?php else: ?>
                                                                    <span class="no-deductions">No active deductions</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            
                                                            <?php
                                                            $statusClass = '';
                                                            if ($proxy['account_status'] == 'Active') {
                                                                $statusClass = 'text-success collector-status';
                                                            } elseif ($proxy['account_status'] == 'Inactive') {
                                                                $statusClass = 'text-secondary collector-status';
                                                            } elseif ($proxy['account_status'] == 'Suspended') {
                                                                $statusClass = 'text-danger collector-status';
                                                            }
                                                            ?>
                                                            <td class="<?php echo $statusClass; ?>"><?php echo htmlspecialchars($proxy['account_status']); ?></td>
                                                            
                                                            <td class="action-buttons">
                                                                <div class="action-buttons-container">
                                                                    <button class="btn btn-primary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                                        <i class="fas fa-cog"></i> Menu
                                                                    </button>
                                                                    <ul class="dropdown-menu">
                                                                        <?php if (!$is_proxy_collector_user_check): ?>
                                                                            <li><h6 class="dropdown-header">Salary Management</h6></li>
                                                                            <li><a class="dropdown-item manage-salary-btn" href="#" data-id="<?php echo $proxy['id']; ?>" data-type="proxy" data-name="<?php echo $fullName; ?>" data-salary="<?php echo $proxy['current_salary'] ?? 0; ?>"><i class="fas fa-coins"></i> Daily Salary</a></li>
                                                                            <li><a class="dropdown-item view-salary-history-btn" href="#" data-id="<?php echo $proxy['id']; ?>" data-type="proxy" data-name="<?php echo $fullName; ?>"><i class="fas fa-history"></i> View Salary History</a></li>
                                                                            <li><hr class="dropdown-divider"></li>
                                                                            <li><h6 class="dropdown-header">Deduction Management</h6></li>
                                                                            <li><a class="dropdown-item manage-deductions-btn" href="#" data-id="<?php echo $proxy['id']; ?>" data-type="proxy" data-name="<?php echo $fullName; ?>" data-salary="<?php echo $proxy['current_salary'] ?? 0; ?>"><i class="fas fa-calculator"></i> Manage Deductions</a></li>
                                                                            <li><a class="dropdown-item view-deductions-history-btn" href="#" data-id="<?php echo $proxy['id']; ?>" data-type="proxy" data-name="<?php echo $fullName; ?>"><i class="fas fa-list-alt"></i> View Deductions History</a></li>
                                                                            <li><hr class="dropdown-divider"></li>
                                                                            <li><h6 class="dropdown-header">Allowance Management</h6></li>
                                                                            <li><a class="dropdown-item manage-allowances-btn" href="#" data-id="<?php echo $proxy['id']; ?>" data-type="proxy" data-name="<?php echo $fullName; ?>" data-salary="<?php echo $proxy['current_salary'] ?? 0; ?>"><i class="fas fa-gift"></i> Manage Allowances</a></li>
                                                                            <li><a class="dropdown-item view-allowance-history-btn" href="#" data-id="<?php echo $proxy['id']; ?>" data-type="proxy" data-name="<?php echo $fullName; ?>"><i class="fas fa-history"></i> View Allowance History</a></li>
                                                                            <li><hr class="dropdown-divider"></li>
                                                                            <li><h6 class="dropdown-header">Actions</h6></li>
                                                                        <?php else: ?>
                                                                            <li><a class="dropdown-item view-deductions-history-btn" href="#" data-id="<?php echo $proxy['id']; ?>" data-type="proxy" data-name="<?php echo $fullName; ?>"><i class="fas fa-list-alt"></i> View Deductions History</a></li>
                                                                        <?php endif; ?>
                                                                        <li><a class="dropdown-item view-loans-btn" href="#" data-id="<?php echo $proxy['id']; ?>" onclick="window.location.href='proxy_loan_record.php?id=<?php echo $proxy['id']; ?>'"><i class="fas fa-hand-holding-usd"></i> View Loans</a></li>
                                                                        <li><a class="dropdown-item view-proxy-btn" href="#" data-id="<?php echo $proxy['id']; ?>"><i class="fas fa-eye"></i> View Details</a></li>
                                                                        <?php if (!$is_proxy_cashier && $user['account_type'] !== 'Proxy Collector' && !$is_proxy_collector_user_check): ?>
                                                                        <li><a class="dropdown-item edit-proxy-btn" href="#" data-id="<?php echo $proxy['id']; ?>"><i class="fas fa-edit"></i> Edit</a></li>
                                                                        <li><a class="dropdown-item text-danger delete-proxy-btn" href="#" data-id="<?php echo $proxy['id']; ?>"><i class="fas fa-trash-alt"></i> Delete</a></li>
                                                                        <?php endif; ?>
                                                                    </ul>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Accounts List Tab - Only for Admin -->
                            <?php if ($is_admin): ?>
                            <div class="tab-pane fade <?php echo $active_tab === 'accounts' ? 'show active' : ''; ?>" id="accounts-tab-pane" role="tabpanel" aria-labelledby="accounts-tab" tabindex="0">
                                <div class="card mb-4">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <div>
                                            <i class="fas fa-table me-1"></i>
                                            All Accounts
                                            <?php if ($branch_filter || ($is_restricted_user && !empty($user_branch))): ?>
                                                <?php $display_branch = $branch_filter ?: $user_branch; ?>
                                                <span class="badge bg-primary">Branch: <?php echo htmlspecialchars($display_branch); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="card-body dash-card__body">
                                        <?php if (empty($accounts)): ?>
                                            <div class="alert alert-info" role="alert">
                                                No accounts found. 
                                                <a href="accounts_registration.php" class="alert-link">Register a new account</a> to get started.
                                            </div>
                                        <?php else: ?>
                                            <div class="table-responsive">
                                                <table class="table dash-table accounts-table" id="accountsTable">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th>Full Name</th>
                                                            <th>Account Type</th>
                                                            <th>Branch</th>
                                                            <th>Daily Salary</th>
                                                            <th>Deductions</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($accounts as $account): ?>
                                                        <?php
                                                        $activeDeductions = getEmployeeUniqueActiveDeductions($account['id'], 'accounts');
                                                        
                                                        $full_name = htmlspecialchars($account['last_name']) . ', ' . htmlspecialchars($account['first_name']);
                                                        if (!empty($account['middle_name'])) {
                                                            $full_name .= ' ' . htmlspecialchars(substr($account['middle_name'], 0, 1)) . '.';
                                                        }
                                                        if (!empty($account['suffix'])) {
                                                            $full_name .= ' ' . htmlspecialchars($account['suffix']);
                                                        }
                                                        ?>
                                                        <tr>
                                                            <td><?php echo $full_name; ?></td>
                                                            <td>
                                                                <?php 
                                                                    $badge_class = '';
                                                                    switch($account['account_type']) {
                                                                        case 'Admin':
                                                                            $badge_class = 'badge-admin';
                                                                            break;
                                                                        case 'Area Manager':
                                                                            $badge_class = 'badge-area-manager';
                                                                            break;
                                                                        case 'Branch Manager':
                                                                            $badge_class = 'badge-branch-manager';
                                                                            break;
                                                                        case 'Proxy Collector-Cashier':
                                                                            $badge_class = 'badge-proxy-collector-cashier';
                                                                            break;
                                                                        case 'Proxy Collector':
                                                                            $badge_class = 'badge-proxy-collector';
                                                                            break;
                                                                        default:
                                                                            $badge_class = 'bg-secondary';
                                                                    }
                                                                ?>
                                                                <span class="badge <?php echo $badge_class; ?>"><?php echo htmlspecialchars($account['account_type']); ?></span>
                                                            </td>
                                                            <td><?php echo htmlspecialchars($account['branch']); ?></td>
                                                            
                                                            <td class="collector-salary">
                                                                <?php if (!empty($account['current_salary']) && $account['current_salary'] > 0): ?>
                                                                    ₱<?php echo number_format($account['current_salary'], 2); ?>
                                                                    <?php if (!empty($account['salary_effective_date'])): ?>
                                                                        <span class="salary-effective-date">Effective: <?php echo date('M d, Y', strtotime($account['salary_effective_date'])); ?></span>
                                                                    <?php endif; ?>
                                                                <?php else: ?>
                                                                    <span class="text-muted">Not set</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            
                                                            <td class="collector-deductions">
                                                                <?php if (!empty($activeDeductions)): ?>
                                                                    <div class="deductions-container">
                                                                        <?php foreach($activeDeductions as $deduction): ?>
                                                                            <?php 
                                                                            $deductionClass = '';
                                                                            $deductionLabel = '';
                                                                            switch($deduction) {
                                                                                case 'sss':
                                                                                    $deductionClass = 'deduction-sss';
                                                                                    $deductionLabel = 'SSS';
                                                                                    break;
                                                                                case 'pagibig':
                                                                                    $deductionClass = 'deduction-pagibig';
                                                                                    $deductionLabel = 'PAG-IBIG';
                                                                                    break;
                                                                                case 'philhealth':
                                                                                    $deductionClass = 'deduction-philhealth';
                                                                                    $deductionLabel = 'PhilHealth';
                                                                                    break;
                                                                                case 'loan_payment':
                                                                                    $deductionClass = 'deduction-loan';
                                                                                    $deductionLabel = 'Loan';
                                                                                    break;
                                                                                case 'cash_advance':
                                                                                    $deductionClass = 'deduction-cashadvance';
                                                                                    $deductionLabel = 'Cash Adv';
                                                                                    break;
                                                                                case 'sss_loan':
                                                                                    $deductionClass = 'deduction-sss-loan';
                                                                                    $deductionLabel = 'SSS Loan';
                                                                                    break;
                                                                                case 'pagibig_loan':
                                                                                    $deductionClass = 'deduction-pagibig-loan';
                                                                                    $deductionLabel = 'PAG-IBIG Loan';
                                                                                    break;
                                                                            }
                                                                            ?>
                                                                            <span class="badge deduction-badge <?php echo $deductionClass; ?>"><?php echo $deductionLabel; ?></span>
                                                                        <?php endforeach; ?>
                                                                    </div>
                                                                <?php else: ?>
                                                                    <span class="no-deductions">No active deductions</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            
                                                            <td class="action-buttons">
                                                                <div class="action-buttons-container">
                                                                    <button class="btn btn-primary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                                        <i class="fas fa-cog"></i> Menu
                                                                    </button>
                                                                    <ul class="dropdown-menu">
                                                                        <li><h6 class="dropdown-header">Salary Management</h6></li>
                                                                        <li><a class="dropdown-item manage-salary-btn" href="#" data-id="<?php echo $account['id']; ?>" data-type="accounts" data-name="<?php echo $full_name; ?>" data-salary="<?php echo $account['current_salary'] ?? 0; ?>"><i class="fas fa-coins"></i> Daily Salary</a></li>
                                                                        <li><a class="dropdown-item view-salary-history-btn" href="#" data-id="<?php echo $account['id']; ?>" data-type="accounts" data-name="<?php echo $full_name; ?>"><i class="fas fa-history"></i> View Salary History</a></li>
                                                                        <li><hr class="dropdown-divider"></li>
                                                                        <li><h6 class="dropdown-header">Deduction Management</h6></li>
                                                                        <li><a class="dropdown-item manage-deductions-btn" href="#" data-id="<?php echo $account['id']; ?>" data-type="accounts" data-name="<?php echo $full_name; ?>" data-salary="<?php echo $account['current_salary'] ?? 0; ?>"><i class="fas fa-calculator"></i> Manage Deductions</a></li>
                                                                        <li><a class="dropdown-item view-deductions-history-btn" href="#" data-id="<?php echo $account['id']; ?>" data-type="accounts" data-name="<?php echo $full_name; ?>"><i class="fas fa-list-alt"></i> View Deductions History</a></li>
                                                                        <li><hr class="dropdown-divider"></li>
                                                                        <li><h6 class="dropdown-header">Allowance Management</h6></li>
                                                                        <li><a class="dropdown-item manage-allowances-btn" href="#" data-id="<?php echo $account['id']; ?>" data-type="accounts" data-name="<?php echo $full_name; ?>" data-salary="<?php echo $account['current_salary'] ?? 0; ?>"><i class="fas fa-gift"></i> Manage Allowances</a></li>
                                                                        <li><a class="dropdown-item view-allowance-history-btn" href="#" data-id="<?php echo $account['id']; ?>" data-type="accounts" data-name="<?php echo $full_name; ?>"><i class="fas fa-history"></i> View Allowance History</a></li>
                                                                        <li><hr class="dropdown-divider"></li>
                                                                        <li><h6 class="dropdown-header">Actions</h6></li>
                                                                        <li><a class="dropdown-item view-account" href="#" data-id="<?php echo $account['id']; ?>"><i class="fas fa-eye"></i> View Details</a></li>
                                                                        <li><a class="dropdown-item view-loans-btn" href="#" data-id="<?php echo $account['id']; ?>" onclick="window.location.href='accounts_loan_record.php?id=<?php echo $account['id']; ?>'"><i class="fas fa-hand-holding-usd"></i> View Loans</a></li>
                                                                        <li><a class="dropdown-item edit-account" href="#" data-id="<?php echo $account['id']; ?>"><i class="fas fa-edit"></i> Edit</a></li>
                                                                        <li><a class="dropdown-item text-danger delete-account-btn" href="#" data-id="<?php echo $account['id']; ?>" data-name="<?php echo htmlspecialchars($account['first_name'] . ' ' . $account['last_name']); ?>"><i class="fas fa-trash-alt"></i> Delete</a></li>
                                                                    </ul>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </main>
                <?php include __DIR__ . '/includes/footer.php'; ?>
            </div>
        </div>

        <script type="application/json" id="employee-list-data"><?php echo json_encode($pageConfig, $jsonFlags); ?></script>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
        <script src="js/scripts.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/umd/simple-datatables.min.js" crossorigin="anonymous"></script>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <script src="assets/js/pc-toast.js"></script>
        <script src="assets/js/employee-lists.js"></script>
        <script src="assets/js/employee-lists-ui.js"></script>
    </body>
</html>
