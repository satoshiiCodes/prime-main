<?php
/**
 * Prime City - Attendance upload page.
 *
 * Layout only: data comes from actions/upload-attendance-actions.php, layout
 * chrome from includes/*, styles from assets/css and behaviour from assets/js.
 */

require_once __DIR__ . '/actions/upload-attendance-actions.php';

try {
    $context = ua_context();
} catch (Throwable $e) {
    error_log('[upload-attendance] ' . $e->getMessage());
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
        <head>
            <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
            <meta charset="utf-8" />
            <title>Upload Attendance - Unavailable</title>
            <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        </head>
        <body class="bg-light">
            <div class="container py-5">
                <div class="alert alert-danger">
                    <h4 class="alert-heading">The attendance page is unavailable</h4>
                    <p class="mb-0">We could not prepare this page right now. Please try again in a moment.</p>
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
$available_branches = $context['available_branches'];
$branches = $context['branches'];
$filter_name = $context['filter_name'];
$filter_branch = $context['filter_branch'];
$filter_date_from = $context['filter_date_from'];
$filter_date_to = $context['filter_date_to'];
$filter_status = $context['filter_status'];
$attendance_records = $context['attendance_records'];
$activeEmployees = $context['activeEmployees'];
$editRecord = $context['editRecord'];
$error = $context['error'];
$duplicateCount = 0;
$message = '';

// One-shot alert carried over from the previous request.
$alertType = $_SESSION['alert_type'] ?? null;
$alertMessage = $_SESSION['alert_message'] ?? null;
$alertRefresh = !empty($_SESSION['refresh_page']);

unset(
    $_SESSION['alert_type'],
    $_SESSION['alert_message'],
    $_SESSION['refresh_page'],
    $_SESSION['duplicate_count'],
    $_SESSION['merged_count'],
    $_SESSION['overwritten_count'],
    $_SESSION['duplicate_records']
);

// Shared layout includes read these.
$layout_active = 'upload-attendance';
$layout_menu = $context['layout_menu'];

$pageConfig = array(
    'isEditing'  => $editRecord !== null,
    'editUserId' => $editRecord ? $editRecord['user_id'] : null,
    'alert'      => ($alertType !== null && $alertMessage !== null) ? array(
        'type'   => $alertType,
        'title'  => $alertType === 'success' ? 'Success' : 'Error',
        'text'   => $alertMessage,
        'reload' => $alertRefresh,
    ) : null,
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
    <meta name="description" content="Prime City attendance upload" />
    <meta name="author" content="Prime City" />
    <title>Upload Attendance - Prime City Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
    <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        <link href="assets/css/dash.css" rel="stylesheet" />
        <link href="assets/css/dash-ui.css" rel="stylesheet" />
    <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="assets/css/upload-attendance-style.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
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
                            <i class="fas fa-fingerprint" aria-hidden="true"></i>
                            Prime City &middot; Attendance
                        </p>
                        <h1 class="dash-title">Upload Attendance</h1>
                        <p class="dash-sub">Import an Excel attendance sheet, then review and edit the saved records.</p>
                    </div>
                </header>
                
                <div class="card dash-card mb-4">
                    <header class="dash-card__head">
                        <span class="dash-card__icon"><i class="fas fa-upload" aria-hidden="true"></i></span>
                        <div>
                            <h2 class="dash-card__title">Upload Excel File</h2>
                            <p class="dash-card__hint">Accepted formats: .xls and .xlsx up to 30MB</p>
                        </div>
                    </header>
                    <div class="card-body">
                        <div class="upload-container">
                            <form action="upload_attendance.php" method="post" enctype="multipart/form-data" id="uploadForm">
                                <div class="mb-0">
                                    <label class="upload-field-label" for="attendance_file">Select Excel File</label>
                                
                                    <label class="upload-drop" for="attendance_file" id="uploadDrop">
                                        <span class="upload-drop__icon"><i class="fas fa-file-excel" aria-hidden="true"></i></span>
                                        <span class="upload-drop__title">Drag &amp; drop your Excel file here</span>
                                        <span class="upload-drop__hint">or <strong>browse</strong> &mdash; .xls, .xlsx up to 30MB</span>
                                        <span class="upload-drop__file" id="uploadFileName" hidden>
                                            <i class="fas fa-paperclip" aria-hidden="true"></i>
                                            <span class="upload-drop__name"></span>
                                            <button type="button" class="upload-drop__clear" id="uploadClear" aria-label="Remove file">
                                                <i class="fas fa-xmark" aria-hidden="true"></i>
                                            </button>
                                        </span>
                                        <input class="upload-drop__input" type="file" id="attendance_file" name="attendance_file"
                                               accept=".xls,.xlsx" required aria-label="Excel file" />
                                    </label>

                                    <p class="upload-note">
                                        <i class="fas fa-circle-info" aria-hidden="true"></i>
                                        Only Excel files (.xls, .xlsx) are accepted. Maximum file size: 30MB.
                                    </p>
                                </div>

                                <div class="dash-form__actions">
                                    <button type="submit" class="btn btn-primary">Upload</button>
                                </div>
                            </form>

                            <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['attendance_file'])): ?>
                            <div class="debug-info">
                                <h6>Debug Information:</h6>
                                <p>If you're seeing NULL values in the database, check the following:</p>
                                <ul>
                                    <li>Make sure your Excel file follows the expected format</li>
                                    <li>Check that date values are properly formatted in Excel</li>
                                    <li>Verify that time values are either time-formatted or text in HH:MM format</li>
                                    <li>Verify that the User ID exists in the accounts, collector_accounts, or proxy_accounts tables</li>
                                    <li><strong>Confirm that the employee's account_status is set to 'Active'</strong></li>
                                    <li><strong>Department "Tuguegarao" will be converted to "Tuguegarao City"</strong></li>
                                    <li><strong>Late minutes are calculated from Time In (8:30 AM cutoff for most branches, 8:40 AM for Abulug)</strong></li>
                                    <li><strong>Records with branch "Apayao" will not be saved</strong></li>
                                    <li><strong>Only AM times populate before_noon_in (earliest AM) and PM times populate after_noon_out (latest PM)</strong></li>
                                    <li><strong>Times between 12:00 and 13:00 (inclusive) are treated as Halfday</strong></li>
                                    <li><strong>Day columns are detected dynamically — dates are anchored to the "Attendance date:" range from the sheet header</strong></li>
                                </ul>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Display all attendance records with filters -->
                <div class="card dash-card mb-4">
                    <header class="dash-card__head">
                        <span class="dash-card__icon"><i class="fas fa-table" aria-hidden="true"></i></span>
                        <div>
                            <h2 class="dash-card__title">All Attendance Records</h2>
                            <p class="dash-card__hint">Filter the saved records by name, status and date</p>
                        </div>
                        <button type="button" class="btn btn-primary btn-sm ms-auto" data-bs-toggle="modal" data-bs-target="#addAttendanceModal">
                            <i class="fas fa-plus me-1"></i> Add Attendance
                        </button>
                    </header>
                    <div class="card-body">
                        <form method="GET" action="upload_attendance.php" id="filterForm">
                            <input type="hidden" name="filter_branch" id="filterBranchValue"
                                   value="<?php echo htmlspecialchars($filter_branch); ?>" />
                            
                            <div class="filter-section">
                                <div class="row">
                                    <div class="col-md-12 mb-3">
                                        <h6 class="mb-2"><i class="fas fa-filter me-1"></i>Additional Filters</h6>
                                    </div>
                                    
                                    <div class="col-md-3 mb-2">
                                        <div class="filter-label">Name</div>
                                        <input type="text" class="form-control form-control-sm" name="filter_name" 
                                               value="<?php echo htmlspecialchars($filter_name); ?>" placeholder="Enter Name">
                                    </div>
                                    
                                    <div class="col-md-3 mb-2">
                                        <div class="filter-label">Status</div>
                                        <select class="form-select form-select-sm" name="filter_status">
                                            <option value="">All Status</option>
                                            <option value="Present" <?php echo $filter_status == 'Present' ? 'selected' : ''; ?>>Present</option>
                                            <option value="Halfday" <?php echo $filter_status == 'Halfday' ? 'selected' : ''; ?>>Halfday</option>
                                            <option value="Absent" <?php echo $filter_status == 'Absent' ? 'selected' : ''; ?>>Absent</option>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-3 mb-2">
                                        <div class="filter-label">Date From</div>
                                        <input type="date" class="form-control form-control-sm" name="filter_date_from" 
                                               value="<?php echo htmlspecialchars($filter_date_from); ?>">
                                    </div>
                                    
                                    <div class="col-md-3 mb-2">
                                        <div class="filter-label">Date To</div>
                                        <input type="date" class="form-control form-control-sm" name="filter_date_to" 
                                               value="<?php echo htmlspecialchars($filter_date_to); ?>">
                                    </div>
                                    
                                    <div class="filter-actions">
                                        <button type="submit" class="btn btn-primary btn-sm me-2">
                                            <i class="fas fa-search"></i> Apply Filters
                                        </button>
                                        <a href="upload_attendance.php" class="btn btn-secondary btn-sm me-2">
                                            <i class="fas fa-undo"></i> Clear Filters
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </form>

                        <!-- Toolbar: search (left) + branch filter (right) -->
                        <div class="attendance-toolbar">
                            <div class="attendance-toolbar__search">
                                <i class="fas fa-search attendance-toolbar__icon" aria-hidden="true"></i>
                                <input type="search" id="attendanceSearch" class="form-control attendance-toolbar__input"
                                       placeholder="Search records..." autocomplete="off" aria-label="Search attendance records" />
                            </div>
                                <?php if (!empty($branches)): ?>
                                <div class="filter-section">
                                    <div class="filter-row">
                                        <span class="filter-label"><i class="fas fa-code-branch" aria-hidden="true"></i> Filter by Branch:</span>
                                        <div class="branch-filter-dropdown">
                                            <select class="form-select" name="filter_branch" onchange="document.getElementById('filterBranchValue').value = this.value; document.getElementById('filterForm').submit()">
                                                <?php if (count($branches) > 1): ?>
                                                    <option value="" <?php echo $filter_branch == '' ? 'selected' : ''; ?>>
                                                        <?php echo strtoupper($user_branch) === 'ALL BRANCHES' ? 'All Branches' : 'All My Branches'; ?>
                                                    </option>
                                                <?php endif; ?>
                                                <?php foreach ($branches as $branch): ?>
                                                    <?php if (!empty($branch) && strcasecmp(trim($branch), 'Apayao') !== 0): ?>
                                                        <option value="<?php echo htmlspecialchars($branch); ?>" 
                                                            <?php echo $filter_branch == $branch ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($branch); ?>
                                                        </option>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>

                            </div>

                        
                        <div class="table-responsive">
                            <table id="attendanceTable" class="table table-striped table-bordered dash-table">
                                <thead>
                                    <tr>
                                        <th>Employee #</th>
                                        <th>Name</th>
                                        <th>Branch</th>
                                        <th>Date</th>
                                        <th>Time In</th>
                                        <th>Time Out</th>
                                        <th>Late (Min)</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($attendance_records)): ?>
                                        <?php 
                                        $duplicateRecords = isset($_SESSION['duplicate_records']) ? $_SESSION['duplicate_records'] : [];
                                        foreach ($attendance_records as $record): 
                                            $isDuplicate = false;
                                            foreach ($duplicateRecords as $dup) {
                                                if ($dup['user_id'] == $record['user_id'] && $dup['date'] == $record['date']) {
                                                    $isDuplicate = true;
                                                    break;
                                                }
                                            }
                                            $hasTimeIn = isset($record['before_noon_in']) && !is_null($record['before_noon_in']) && $record['before_noon_in'] !== '';
                                            $hasTimeOut = isset($record['after_noon_out']) && !is_null($record['after_noon_out']) && $record['after_noon_out'] !== '';
                                            
                                            $timeInIsHalfday = false;
                                            $timeOutIsHalfday = false;
                                            if ($hasTimeIn) {
                                                $timeInIsHalfday = isHalfDayTime($record['before_noon_in']);
                                            }
                                            if ($hasTimeOut) {
                                                $timeOutIsHalfday = isHalfDayTime($record['after_noon_out']);
                                            }
                                            
                                            if ($timeInIsHalfday || $timeOutIsHalfday) {
                                                $displayStatus = 'Halfday';
                                                $badgeClass = 'badge-halfday';
                                            } elseif ($hasTimeIn && $hasTimeOut) {
                                                $displayStatus = 'Present';
                                                $badgeClass = 'bg-success';
                                            } elseif ($hasTimeIn || $hasTimeOut) {
                                                $displayStatus = 'Halfday';
                                                $badgeClass = 'badge-halfday';
                                            } else {
                                                $displayStatus = 'Absent';
                                                $badgeClass = 'bg-danger';
                                            }
                                            
                                            $formattedDate = date('m-d-Y', strtotime($record['date']));
                                        ?>
                                            <tr class="<?php echo $isDuplicate ? 'duplicate-row' : ''; ?>">
                                                <td><?php echo htmlspecialchars($record['user_id']); ?></td>
                                                <td><?php echo htmlspecialchars($record['name']); ?></td>
                                                <td><?php echo htmlspecialchars($record['department']); ?></td>
                                                <td><?php echo htmlspecialchars($formattedDate); ?></td>
                                                <td>
                                                    <?php 
                                                    if ($hasTimeIn) {
                                                        echo htmlspecialchars(date('h:i A', strtotime($record['before_noon_in'])));
                                                    } else {
                                                        echo '<span class="text-muted">--:-- --</span>';
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php 
                                                    if ($hasTimeOut) {
                                                        echo htmlspecialchars(date('h:i A', strtotime($record['after_noon_out'])));
                                                    } else {
                                                        echo '<span class="text-muted">--:-- --</span>';
                                                    }
                                                    ?>
                                                </td>
                                                <td><?php echo isset($record['late_minutes']) && $record['late_minutes'] !== null ? htmlspecialchars($record['late_minutes']) : '0'; ?></td>
                                                <td>
                                                    <span class="badge <?php echo $badgeClass; ?>">
                                                        <?php echo $displayStatus; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <a href="?edit=<?php echo $record['id']; ?>" class="btn btn-action btn-edit" title="Edit Record">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <button type="button" class="btn btn-action btn-delete" 
                                                                onclick="confirmDelete(<?php echo $record['id']; ?>, '<?php echo htmlspecialchars($record['name']); ?>', '<?php echo $formattedDate; ?>')" 
                                                                title="Delete Record">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="text-center">No attendance records found.</td>
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

<!-- Add/Edit Attendance Modal -->
<div class="modal fade" id="addAttendanceModal" tabindex="-1" aria-labelledby="addAttendanceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                            <span class="att-modal__icon"><i class="fas fa-clipboard-user" aria-hidden="true"></i></span>
                            <div class="att-modal__heading">
                                <h5 class="modal-title" id="addAttendanceModalLabel"><span id="modalTitle">Add Attendance Record</span></h5>
                                <p class="att-modal__hint">Pick an employee, then set the date and times</p>
                            </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="upload_attendance.php" method="POST" id="addAttendanceForm">
                <div class="modal-body">
                    <input type="hidden" name="record_id" id="record_id" value="<?php echo $editRecord ? $editRecord['id'] : ''; ?>">
                    
                    <div class="form-section">
                        <h5 class="section-title">Employee</h5>
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label for="employee_select" class="form-label required-field">Select Employee</label>
                            <select class="form-control" id="employee_select" name="employee_select" <?php echo $editRecord ? 'disabled' : ''; ?> required>
                                <option value="">-- Select Employee --</option>
                                <?php foreach ($activeEmployees as $employee): ?>
                                    <?php if (strcasecmp(trim($employee['branch']), 'Apayao') !== 0): ?>
                                    <option value="<?php echo htmlspecialchars($employee['employee_number']); ?>"
                                            data-name="<?php echo htmlspecialchars($employee['formatted_name']); ?>"
                                            data-bio="<?php echo htmlspecialchars($employee['employee_number']); ?>"
                                            data-type="<?php echo htmlspecialchars($employee['account_type'] ?? ''); ?>"
                                            data-branch="<?php echo htmlspecialchars($employee['branch']); ?>"
                                            <?php echo ($editRecord && $editRecord['user_id'] == $employee['employee_number']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($employee['formatted_name']); ?>
                                    </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-hint">Search and select an employee from the dropdown</div>
                            <?php if ($editRecord): ?>
                                <input type="hidden" name="employee_number" id="employee_number_hidden" value="<?php echo htmlspecialchars($editRecord['user_id']); ?>">
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="employee_number" class="form-label required-field">Employee #</label>
                            <input type="text" class="form-control" id="employee_number" name="employee_number" 
                                   value="<?php echo $editRecord ? htmlspecialchars($editRecord['user_id']) : ''; ?>" 
                                   readonly required>
                            <div class="form-hint">Auto-filled from employee selection</div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="department" class="form-label required-field">Branch/Department</label>
                            <input type="text" class="form-control" id="department" name="department" 
                                   value="<?php echo $editRecord ? htmlspecialchars($editRecord['department']) : ''; ?>" 
                                   readonly required>
                            <div class="form-hint">Auto-filled from employee selection</div>
                        </div>
                    </div>
                    </div>
                    
                    <div class="form-section">
                        <h5 class="section-title">Attendance</h5>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="date" class="form-label required-field">Date</label>
                            <input type="date" class="form-control" id="date" name="date" 
                                   value="<?php echo $editRecord ? htmlspecialchars($editRecord['date']) : ''; ?>" 
                                   required>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label for="time_in" class="form-label">Time In</label>
                            <div class="time-field-container">
                                <input type="time" class="form-control" id="time_in" name="time_in" 
                                       value="<?php echo $editRecord && $editRecord['before_noon_in'] ? date('H:i', strtotime($editRecord['before_noon_in'])) : ''; ?>">
                                <button type="button" class="clear-time-btn" onclick="clearTimeField('time_in')" title="Clear time (set to NULL)">
                                    <i class="fas fa-times-circle"></i>
                                </button>
                            </div>
                            <div class="form-hint">Leave empty to set as NULL</div>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label for="time_out" class="form-label">Time Out</label>
                            <div class="time-field-container">
                                <input type="time" class="form-control" id="time_out" name="time_out" 
                                       value="<?php echo $editRecord && $editRecord['after_noon_out'] ? date('H:i', strtotime($editRecord['after_noon_out'])) : ''; ?>">
                                <button type="button" class="clear-time-btn" onclick="clearTimeField('time_out')" title="Clear time (set to NULL)">
                                    <i class="fas fa-times-circle"></i>
                                </button>
                            </div>
                            <div class="form-hint">Leave empty to set as NULL</div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <div id="timeValidationError" class="time-validation-error">
                                <i class="fas fa-exclamation-circle me-1"></i>
                                Time Out cannot be earlier than Time In when both are provided. Please adjust the times or clear one of them.
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="late_minutes" class="form-label">Late Minutes</label>
                            <input type="number" class="form-control" id="late_minutes" name="late_minutes" min="0" 
                                   value="<?php echo $editRecord && $editRecord['late_minutes'] !== null ? htmlspecialchars($editRecord['late_minutes']) : '0'; ?>" 
                                   readonly>
                            <div class="form-hint">Auto-calculated based on Time In (8:30 AM for most, 8:40 AM for Abulug)</div>
                            <div id="lateMinutesInfo" class="late-minutes-info"></div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status (Auto-calculated)</label>
                            <div class="form-control-plaintext" id="status_display">
                                <span class="badge bg-success" id="status_badge">
                                    <?php 
                                    if ($editRecord) {
                                        $hasTimeIn = isset($editRecord['before_noon_in']) && !is_null($editRecord['before_noon_in']) && $editRecord['before_noon_in'] !== '';
                                        $hasTimeOut = isset($editRecord['after_noon_out']) && !is_null($editRecord['after_noon_out']) && $editRecord['after_noon_out'] !== '';
                                        
                                        $editTimeInIsHalfday = $hasTimeIn ? isHalfDayTime($editRecord['before_noon_in']) : false;
                                        $editTimeOutIsHalfday = $hasTimeOut ? isHalfDayTime($editRecord['after_noon_out']) : false;
                                        
                                        if ($editTimeInIsHalfday || $editTimeOutIsHalfday) {
                                            echo 'Halfday';
                                        } elseif ($hasTimeIn && $hasTimeOut) {
                                            echo 'Present';
                                        } elseif ($hasTimeIn || $hasTimeOut) {
                                            echo 'Halfday';
                                        } else {
                                            echo 'Absent';
                                        }
                                    } else {
                                        echo 'Absent';
                                    }
                                    ?>
                                </span>
                                <small class="text-muted ms-2">(Based on time in/out)</small>
                            </div>
                        </div>
                    </div>
                    </div>
                    
                    <div class="form-section">
                        <h5 class="section-title">Reference</h5>
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Note:</strong> 
                        <ul class="mb-0 mt-1">
                            <li>Select an employee from the dropdown to auto-fill Employee Number and Branch.</li>
                            <li>Only <strong>ACTIVE Employees</strong> and <strong>Have Employee Number</strong> are shown in the dropdown.</li>
                            <li><strong>Apayao branch employees are excluded.</strong></li>
                            <li><strong>Late Minutes auto-calculates:</strong> Time In after 8:30 AM (8:40 AM for Abulug) will be marked as late.</li>
                            <li><strong>Time fields can be NULL:</strong> Leave Time In or Time Out empty to set them as NULL in the database.</li>
                            <li><strong>Time Validation:</strong> Time Out cannot be earlier than Time In when both are provided. You can clear one of them using the X button.</li>
                            <li>If both Time In and Time Out are empty, the record will be marked as <strong>Absent</strong>.</li>
                            <li>If only one of Time In or Time Out is provided, the record will be marked as <strong>Halfday</strong>.</li>
                            <li>If both Time In and Time Out are provided, the record will be marked as <strong>Present</strong>.</li>
                            <li><strong>If either time falls between 12:00 PM and 1:00 PM, the record will be marked as Halfday.</strong></li>
                        </ul>
                    </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="manual_add" class="btn btn-success" id="saveRecordBtn">
                        <i class="fas fa-save me-1"></i> <span id="saveBtnText">Save Record</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

    <script type="application/json" id="upload-attendance-data"><?php echo json_encode($pageConfig, $jsonFlags); ?></script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script src="js/scripts.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/umd/simple-datatables.min.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="assets/js/upload-attendance.js"></script>
</body>
</html>
