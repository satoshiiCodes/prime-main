<?php
session_start();

// Redirect to login if not authenticated
if (!isset($_SESSION['logged_in'])) {
    header("Location: login_form.php");
    exit;
}

// Database configuration
require 'includes/db_config.php';

// Initialize user variable
$user = [];

// Get user details
try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    $stmt = $conn->prepare("SELECT first_name, middle_name, last_name, suffix, account_type FROM accounts WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception("User not found");
    }

    $user = $result->fetch_assoc();
    $stmt->close();
    
} catch (Exception $e) {
    error_log($e->getMessage());
    // You might want to redirect to an error page or back to login
}

// Function to format collector name
function formatCollectorName($first, $middle, $last, $suffix) {
    $name = $first;
    if (!empty($middle)) {
        $name .= ' ' . strtoupper(substr($middle, 0, 1)) . '.';
    }
    $name .= ' ' . $last;
    if (!empty($suffix)) {
        $name .= ' ' . $suffix;
    }
    return $name;
}

// Initialize variables for filters
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$collector_id = isset($_GET['collector_id']) ? $_GET['collector_id'] : 'all';

// Get all collectors for dropdown
$collectors = [];
try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    $stmt = $conn->prepare("SELECT id, first_name, middle_name, last_name, suffix FROM collector_accounts ORDER BY last_name, first_name");
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $row['full_name'] = formatCollectorName(
                $row['first_name'],
                $row['middle_name'],
                $row['last_name'],
                $row['suffix']
            );
            $collectors[] = $row;
        }
    }
    
} catch (Exception $e) {
    $error_message = "Error fetching collectors: " . $e->getMessage();
}

// Get attendance report data based on filters
$attendance_data = [];
$summary_data = [];
try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    // Base query for attendance data
    $query = "SELECT 
                ca.id,
                ca.attendance_date,
                ca.time_in,
                ca.time_out,
                c.id as collector_id,
                c.first_name,
                c.middle_name,
                c.last_name,
                c.suffix,
                TIMESTAMPDIFF(MINUTE, ca.time_in, IFNULL(ca.time_out, NOW())) as duration_minutes
              FROM collector_attendance ca
              JOIN collector_accounts c ON ca.collector_id = c.id
              WHERE ca.attendance_date BETWEEN ? AND ?";
    
    // Add collector filter if not 'all'
    if ($collector_id !== 'all') {
        $query .= " AND c.id = ?";
    }
    
    $query .= " ORDER BY ca.attendance_date DESC, ca.time_in DESC";
    
    $stmt = $conn->prepare($query);
    
    if ($collector_id !== 'all') {
        $stmt->bind_param("ssi", $start_date, $end_date, $collector_id);
    } else {
        $stmt->bind_param("ss", $start_date, $end_date);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $row['collector_name'] = formatCollectorName(
                $row['first_name'],
                $row['middle_name'],
                $row['last_name'],
                $row['suffix']
            );
            
            // Format duration for display
            if ($row['time_out']) {
                $hours = floor($row['duration_minutes'] / 60);
                $minutes = $row['duration_minutes'] % 60;
                $row['duration'] = sprintf("%dh %02dm", $hours, $minutes);
            } else {
                $row['duration'] = 'In Progress';
            }
            
            $attendance_data[] = $row;
        }
    }
    
    // Get summary data
    $summary_query = "SELECT 
                        COUNT(DISTINCT ca.collector_id) as total_collectors,
                        COUNT(ca.id) as total_records,
                        SUM(CASE WHEN ca.time_out IS NULL THEN 1 ELSE 0 END) as active_sessions,
                        SEC_TO_TIME(AVG(TIMESTAMPDIFF(SECOND, ca.time_in, ca.time_out))) as avg_duration
                      FROM collector_attendance ca
                      WHERE ca.attendance_date BETWEEN ? AND ?";
    
    if ($collector_id !== 'all') {
        $summary_query .= " AND ca.collector_id = ?";
    }
    
    $stmt = $conn->prepare($summary_query);
    
    if ($collector_id !== 'all') {
        $stmt->bind_param("ssi", $start_date, $end_date, $collector_id);
    } else {
        $stmt->bind_param("ss", $start_date, $end_date);
    }
    
    $stmt->execute();
    $summary_result = $stmt->get_result();
    
    if ($summary_result->num_rows > 0) {
        $summary_data = $summary_result->fetch_assoc();
        
        // Format average duration
        if ($summary_data['avg_duration']) {
            $parts = explode(':', $summary_data['avg_duration']);
            $summary_data['avg_duration_formatted'] = $parts[0] . 'h ' . $parts[1] . 'm';
        } else {
            $summary_data['avg_duration_formatted'] = 'N/A';
        }
    }
    
} catch (Exception $e) {
    $error_message = "Error generating report: " . $e->getMessage();
}

// Close connection
if (isset($conn)) {
    $conn->close();
}

// Logout function
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    setcookie('remember_me', '', time() - 3600, "/"); // Delete remember me cookie
    header("Location: login_form.php");
    exit;
}

// Shared layout includes (topbar/sidebar) read these.
$layout_active = 'biometrics-report';

if (!is_array($user)) {
    $user = array();
}

$user += array(
    'first_name'  => $_SESSION['first_name'] ?? 'Admin',
    'middle_name' => $_SESSION['middle_name'] ?? '',
    'last_name'   => $_SESSION['last_name'] ?? '',
    'suffix'      => $_SESSION['suffix'] ?? '',
);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <meta name="description" content="Collector Biometrics Attendance Report" />
    <meta name="author" content="" />
    <title>Attendance Report - Prime City Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        <link href="assets/css/dash.css" rel="stylesheet" />
        <link href="assets/css/dash-ui.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <style>
        .report-container {
            margin: 0;
        }
        .filter-card {
            margin-bottom: 20px;
        }
        .summary-card {
            margin-bottom: 20px;
        }
        .summary-value {
            font-size: 1.5rem;
            font-weight: bold;
        }
        .table th {
            white-space: nowrap;
        }
        .badge-in-progress {
            background-color: #0dcaf0;
            color: #000;
        }
        .badge-completed {
            background-color: #198754;
        }
        .badge-active {
            background-color: #ffc107;
            color: #000;
        }
        /* Added spacing for the attendance records section */
        .attendance-records-section {
            margin-top: 2rem;
        }
        .table-container {
            margin-top: 1.5rem;
        }
    </style>
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
                            Prime City &middot; Biometrics
                        </p>
                        <h1 class="dash-title">Attendance Report</h1>
                        <p class="dash-sub">Collector biometrics attendance for the selected date range.</p>
                    </div>
                </header>
                
                <div class="report-container">
                    <!-- Filters -->
                    <div class="card dash-card filter-card">
                        <header class="dash-card__head">
                            <span class="dash-card__icon"><i class="fas fa-filter" aria-hidden="true"></i></span>
                            <div>
                                <h2 class="dash-card__title">Report Filters</h2>
                                <p class="dash-card__hint">Narrow the report by date range and collector</p>
                            </div>
                        </header>
                        <div class="card-body">
                            <form method="GET" action="biometrics_report.php">
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="start_date" class="form-label">Start Date</label>
                                            <input type="date" class="form-control" id="start_date" name="start_date" 
                                                   value="<?php echo htmlspecialchars($start_date); ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="end_date" class="form-label">End Date</label>
                                            <input type="date" class="form-control" id="end_date" name="end_date" 
                                                   value="<?php echo htmlspecialchars($end_date); ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="collector_id" class="form-label">Collector</label>
                                            <select class="form-select" id="collector_id" name="collector_id">
                                                <option value="all">All Collectors</option>
                                                <?php foreach ($collectors as $collector): ?>
                                                    <option value="<?php echo $collector['id']; ?>" 
                                                        <?php if ($collector_id == $collector['id']) echo 'selected'; ?>>
                                                        <?php echo htmlspecialchars($collector['full_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-2 d-flex align-items-end">
                                        <button type="submit" class="btn btn-primary w-100">
                                            <i class="fas fa-search me-1"></i> Generate
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Summary Cards -->
                    <div class="row">
                        <div class="col-md-3">
                            <div class="card dash-card summary-card h-100">
                                <header class="dash-card__head">
                                    <span class="dash-card__icon"><i class="fas fa-users" aria-hidden="true"></i></span>
                                    <div>
                                        <h2 class="dash-card__title">Collectors</h2>
                                        <p class="dash-card__hint">Unique collectors in range</p>
                                    </div>
                                </header>
                                <div class="card-body text-center">
                                    <div class="summary-value text-primary">
                                        <?php echo isset($summary_data['total_collectors']) ? $summary_data['total_collectors'] : '0'; ?>
                                    </div>
                                    <div class="text-muted">Unique Collectors</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card dash-card summary-card h-100">
                                <header class="dash-card__head">
                                    <span class="dash-card__icon"><i class="fas fa-clock" aria-hidden="true"></i></span>
                                    <div>
                                        <h2 class="dash-card__title">Attendance</h2>
                                        <p class="dash-card__hint">Total attendance records</p>
                                    </div>
                                </header>
                                <div class="card-body text-center">
                                    <div class="summary-value text-info">
                                        <?php echo isset($summary_data['total_records']) ? $summary_data['total_records'] : '0'; ?>
                                    </div>
                                    <div class="text-muted">Total Records</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card dash-card summary-card h-100">
                                <header class="dash-card__head">
                                    <span class="dash-card__icon"><i class="fas fa-user-clock" aria-hidden="true"></i></span>
                                    <div>
                                        <h2 class="dash-card__title">Active</h2>
                                        <p class="dash-card__hint">Sessions still open</p>
                                    </div>
                                </header>
                                <div class="card-body text-center">
                                    <div class="summary-value text-warning">
                                        <?php echo isset($summary_data['active_sessions']) ? $summary_data['active_sessions'] : '0'; ?>
                                    </div>
                                    <div class="text-muted">Active Sessions</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card dash-card summary-card h-100">
                                <header class="dash-card__head">
                                    <span class="dash-card__icon"><i class="fas fa-stopwatch" aria-hidden="true"></i></span>
                                    <div>
                                        <h2 class="dash-card__title">Duration</h2>
                                        <p class="dash-card__hint">Average session length</p>
                                    </div>
                                </header>
                                <div class="card-body text-center">
                                    <div class="summary-value text-success">
                                        <?php echo isset($summary_data['avg_duration_formatted']) ? $summary_data['avg_duration_formatted'] : 'N/A'; ?>
                                    </div>
                                    <div class="text-muted">Avg. Duration</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Attendance Records Section with added space -->
                    <div class="attendance-records-section">
                        <div class="card dash-card">
                            <header class="dash-card__head">
                                <span class="dash-card__icon"><i class="fas fa-table" aria-hidden="true"></i></span>
                                <div>
                                    <h2 class="dash-card__title">Attendance Records</h2>
                                    <p class="dash-card__hint">Every biometrics punch in the selected range</p>
                                </div>
                                <div class="ms-auto">
                                    <button class="btn btn-sm btn-primary" id="exportExcel">
                                        <i class="fas fa-file-excel me-1"></i> Export
                                    </button>
                                </div>
                            </header>
                            <div class="card-body">
                                <?php if (!empty($error_message)): ?>
                                    <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
                                <?php endif; ?>
                                
                                <div class="table-container table-responsive">
                                    <table class="table table-striped table-hover dash-table" id="attendanceTable">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Date</th>
                                                <th>Collector</th>
                                                <th>Time In</th>
                                                <th>Time Out</th>
                                                <th>Duration</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($attendance_data)): ?>
                                                <?php foreach ($attendance_data as $record): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars(date('M j, Y', strtotime($record['attendance_date']))); ?></td>
                                                        <td><?php echo htmlspecialchars($record['collector_name']); ?></td>
                                                        <td><?php echo htmlspecialchars(date('h:i A', strtotime($record['time_in']))); ?></td>
                                                        <td>
                                                            <?php if ($record['time_out']): ?>
                                                                <?php echo htmlspecialchars(date('h:i A', strtotime($record['time_out']))); ?>
                                                            <?php else: ?>
                                                                <span class="badge badge-active">Active</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($record['duration']); ?></td>
                                                        <td>
                                                            <?php if ($record['time_out']): ?>
                                                                <span class="badge bg-success">Completed</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-primary">In Progress</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="6" class="text-center">No attendance records found for the selected filters</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
        <?php include __DIR__ . '/includes/footer.php'; ?>
    </div>
</div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize DataTable
            $('#attendanceTable').DataTable({
                responsive: true,
                order: [[0, 'desc'], [2, 'desc']],
                dom: '<"top"f>rt<"bottom"lip><"clear">',
                pageLength: 25
            });
            
            // Export to Excel
            document.getElementById('exportExcel').addEventListener('click', function() {
                // Get the table data
                const table = document.getElementById('attendanceTable');
                const workbook = XLSX.utils.table_to_book(table);
                
                // Generate file name
                const startDate = document.getElementById('start_date').value;
                const endDate = document.getElementById('end_date').value;
                const collectorSelect = document.getElementById('collector_id');
                const collectorName = collectorSelect.options[collectorSelect.selectedIndex].text;
                
                let fileName = 'Attendance_Report_' + startDate + '_to_' + endDate;
                if (collectorSelect.value !== 'all') {
                    fileName += '_' + collectorName.replace(/\s+/g, '_');
                }
                
                // Export the workbook
                XLSX.writeFile(workbook, fileName + '.xlsx');
            });
            
            // Set end date max to today
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('end_date').max = today;
            
            // Validate date range
            document.querySelector('form').addEventListener('submit', function(e) {
                const startDate = new Date(document.getElementById('start_date').value);
                const endDate = new Date(document.getElementById('end_date').value);
                
                if (startDate > endDate) {
                    alert('Start date cannot be after end date');
                    e.preventDefault();
                }
            });
        });
    </script>
</body>
</html>
