<?php
session_start();

// Redirect to login if not authenticated
if (!isset($_SESSION['logged_in'])) {
    header("Location: login_form.php");
    exit;
}

// Database configuration
require 'includes/db_config.php';

// Initialize variables
$user = [];
$message = '';
$attendance_status = '';
$time_in = '';
$time_out = '';
$collector_name = '';
$today = date('Y-m-d');
$current_server_time = time();
$time_tampering_detected = false; // Initialize time tampering flag

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
    $message = "Error retrieving user information";
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

// Check for time tampering in session
if (isset($_SESSION['time_tampering_detected']) && $_SESSION['time_tampering_detected']) {
    $time_tampering_detected = true;
}

// Handle manual fingerprint scan simulation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['simulated_fingerprint']) && !$time_tampering_detected) {
    try {
        $conn = new mysqli($servername, $username, $password, $dbname);
        
        if ($conn->connect_error) {
            throw new Exception("Connection failed: " . $conn->connect_error);
        }

        // Generate a random fingerprint hash for simulation
        $fingerprint_data = bin2hex(random_bytes(32));
        
        // Find collector by fingerprint data (thumb or index)
        $stmt = $conn->prepare("SELECT id, first_name, middle_name, last_name, suffix FROM collector_accounts 
                              WHERE fingerprint_thumb = ? OR fingerprint_index = ?");
        $stmt->bind_param("ss", $fingerprint_data, $fingerprint_data);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $collector = $result->fetch_assoc();
            $collector_id = $collector['id'];
            $collector_name = formatCollectorName(
                $collector['first_name'],
                $collector['middle_name'],
                $collector['last_name'],
                $collector['suffix']
            );
            
            // Use server time reference for all time calculations
            $attendance_date = date('Y-m-d', $_SESSION['server_time_reference']);
            $time_in = date('H:i:s', $_SESSION['server_time_reference']);
            $time_out = null;
            
            // Check if there's an open time in record (no time out) for today
            $stmt = $conn->prepare("SELECT * FROM collector_attendance 
                                   WHERE collector_id = ? 
                                   AND attendance_date = ?
                                   AND time_out IS NULL
                                   ORDER BY time_in DESC LIMIT 1");
            $stmt->bind_param("is", $collector_id, $attendance_date);
            $stmt->execute();
            $attendance_result = $stmt->get_result();
            
            if ($attendance_result->num_rows > 0) {
                // Existing time in without time out - record time out
                $attendance = $attendance_result->fetch_assoc();
                $time_out = date('H:i:s', $_SESSION['server_time_reference']);
                $stmt = $conn->prepare("UPDATE collector_attendance 
                                       SET time_out = ?
                                       WHERE id = ?");
                $stmt->bind_param("si", $time_out, $attendance['id']);
                $stmt->execute();
                $attendance_status = 'Time Out';
                $message = "Time Out recorded for $collector_name at " . date('h:i A', strtotime($time_out));
                
                // Log the attendance
                logAttendanceEvent($conn, $collector_id, 'time_out', $_SESSION['server_time_reference']);
            } else {
                // No open time in - record new time in
                $stmt = $conn->prepare("INSERT INTO collector_attendance 
                                      (collector_id, attendance_date, time_in) 
                                      VALUES (?, ?, ?)");
                $stmt->bind_param("iss", $collector_id, $attendance_date, $time_in);
                $stmt->execute();
                $attendance_status = 'Time In';
                $message = "Time In recorded for $collector_name at " . date('h:i A', strtotime($time_in));
                
                // Log the attendance
                logAttendanceEvent($conn, $collector_id, 'time_in', $_SESSION['server_time_reference']);
            }
        } else {
            $message = "Fingerprint not recognized. Please try again or contact administrator.";
            
            // Log failed attempt
            logSecurityEvent($conn, 'unknown_fingerprint_attempt', $_SESSION['user_id'], $_SERVER['REMOTE_ADDR']);
        }
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        error_log($message);
    }
    
    // Redirect to prevent form resubmission
    header("Location: collector_biometrics.php?message=" . urlencode($message) . "&status=" . urlencode($attendance_status) . "&collector=" . urlencode($collector_name));
    exit;
}

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    // Get recent attendance records
    $recent_attendance = [];
    $stmt = $conn->prepare("SELECT ca.attendance_date, ca.time_in, ca.time_out, 
                          c.first_name, c.middle_name, c.last_name, c.suffix
                          FROM collector_attendance ca
                          JOIN collector_accounts c ON ca.collector_id = c.id
                          ORDER BY ca.attendance_date DESC, ca.time_in DESC LIMIT 10");
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            // Format the collector name for display
            $row['collector_name'] = formatCollectorName(
                $row['first_name'],
                $row['middle_name'],
                $row['last_name'],
                $row['suffix']
            );
            $recent_attendance[] = $row;
        }
    }
    
    // Get today's attendance summary
    $today_summary = [];
    $stmt = $conn->prepare("SELECT 
                           COUNT(DISTINCT collector_id) AS total_collectors,
                           SUM(CASE WHEN time_out IS NULL THEN 1 ELSE 0 END) AS currently_in
                           FROM collector_attendance
                           WHERE attendance_date = ?");
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $summary_result = $stmt->get_result();
    
    if ($summary_result->num_rows > 0) {
        $today_summary = $summary_result->fetch_assoc();
    }
    
} catch (Exception $e) {
    $message = "Error: " . $e->getMessage();
    error_log($message);
}

// Close connection
if (isset($conn)) {
    $conn->close();
}

// Helper function to log security events
function logSecurityEvent($conn, $event_type, $user_id, $ip_address) {
    try {
        $stmt = $conn->prepare("INSERT INTO security_logs 
                               (event_type, user_id, ip_address, event_time) 
                               VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("sis", $event_type, $user_id, $ip_address);
        $stmt->execute();
    } catch (Exception $e) {
        error_log("Failed to log security event: " . $e->getMessage());
    }
}

// Helper function to log attendance events
function logAttendanceEvent($conn, $collector_id, $event_type, $timestamp) {
    try {
        $event_time = date('Y-m-d H:i:s', $timestamp);
        $stmt = $conn->prepare("INSERT INTO attendance_logs 
                               (collector_id, event_type, event_time, server_time_reference) 
                               VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $collector_id, $event_type, $event_time, $event_time);
        $stmt->execute();
    } catch (Exception $e) {
        error_log("Failed to log attendance event: " . $e->getMessage());
    }
}

// Get message from URL if redirected
if (isset($_GET['message'])) {
    $message = $_GET['message'];
    $attendance_status = $_GET['status'] ?? '';
    $collector_name = $_GET['collector'] ?? '';
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
$layout_active = 'collector-biometrics';

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
    <meta name="description" content="Collector Biometrics Attendance System" />
    <meta name="author" content="" />
    <title>Collector Biometrics - Prime City Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        <link href="assets/css/dash.css" rel="stylesheet" />
        <link href="assets/css/dash-ui.css" rel="stylesheet" />
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        .biometrics-container {
            margin: 0;
        }
        .scanner-area {
            border: 2px dashed #ccc;
            height: 300px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            background-color: #f8f9fa;
            position: relative;
            overflow: hidden;
        }
        .scanner-area.active {
            border-color: #0d6efd;
            background-color: #e7f1ff;
        }
        .scanner-icon {
            font-size: 5rem;
            color: #6c757d;
        }
        .scanner-area.active .scanner-icon {
            color: #0d6efd;
            animation: pulse 1.5s infinite;
        }
        .scanner-area.disabled {
            border-color: #dc3545;
            background-color: #f8d7da;
        }
        .scanner-area.disabled .scanner-icon {
            color: #dc3545;
        }
        .attendance-status {
            font-size: 1.2rem;
            font-weight: bold;
            text-align: center;
            margin: 20px 0;
        }
        .time-in {
            color: #198754;
        }
        .time-out {
            color: #dc3545;
        }
        .recent-attendance {
            margin-top: 30px;
        }
        .current-time {
            font-size: 1.2rem;
            text-align: center;
            margin-bottom: 20px;
            font-weight: bold;
        }
        .summary-card {
            margin-bottom: 20px;
        }
        .summary-value {
            font-size: 1.5rem;
            font-weight: bold;
        }
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        .collector-info {
            text-align: center;
            margin-bottom: 20px;
        }
        .collector-name {
            font-size: 1.5rem;
            font-weight: bold;
        }
        .table th {
            white-space: nowrap;
        }
        .disabled-button {
            opacity: 0.5;
            cursor: not-allowed;
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
                        <h1 class="dash-title">Collector Biometrics</h1>
                        <p class="dash-sub">Scan a fingerprint to record collector time in and time out.</p>
                    </div>
                </header>
                
                <div class="biometrics-container">
                    <!-- Today's Summary -->
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card dash-card summary-card">
                                <header class="dash-card__head">
                                    <span class="dash-card__icon"><i class="fas fa-users" aria-hidden="true"></i></span>
                                    <div>
                                        <h2 class="dash-card__title">Today's Attendance Summary</h2>
                                        <p class="dash-card__hint">Collectors recorded today</p>
                                    </div>
                                </header>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6 text-center">
                                            <div class="mb-3">
                                                <div>Total Collectors</div>
                                                <div class="summary-value text-primary">
                                                    <?php echo isset($today_summary['total_collectors']) ? $today_summary['total_collectors'] : '0'; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6 text-center">
                                            <div class="mb-3">
                                                <div>Currently Working</div>
                                                <div class="summary-value text-success">
                                                    <?php echo isset($today_summary['currently_in']) ? $today_summary['currently_in'] : '0'; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card dash-card">
                                <header class="dash-card__head">
                                    <span class="dash-card__icon"><i class="fas fa-clock" aria-hidden="true"></i></span>
                                    <div>
                                        <h2 class="dash-card__title">Current Date &amp; Time (Philippines)</h2>
                                        <p class="dash-card__hint">Server clock used for attendance punches</p>
                                    </div>
                                </header>
                                <div class="card-body text-center">
                                    <div class="current-time" id="currentTime">
                                        Loading Philippines time...
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Scanner Section -->
                    <div class="card dash-card mb-4">
                        <header class="dash-card__head">
                            <span class="dash-card__icon"><i class="fas fa-fingerprint" aria-hidden="true"></i></span>
                            <div>
                                <h2 class="dash-card__title">Fingerprint Scanner</h2>
                                <p class="dash-card__hint">Tap the scanner to log a punch</p>
                            </div>
                        </header>
                        <div class="card-body">
                            <?php if ($time_tampering_detected): ?>
                                <div class="alert alert-danger text-center">
                                    <i class="fas fa-ban me-2"></i>
                                    Attendance recording disabled due to time discrepancy. Please ensure your device clock is synchronized with Philippines time.
                                </div>
                                <div class="scanner-area disabled">
                                    <div class="scanner-icon">
                                        <i class="fas fa-ban"></i>
                                    </div>
                                </div>
                                <div class="text-center">
                                    <button class="btn btn-secondary disabled-button" disabled>
                                        Scanner Disabled
                                    </button>
                                </div>
                            <?php else: ?>
                                <div class="scanner-area" id="scannerArea">
                                    <div class="scanner-icon">
                                        <i class="fas fa-fingerprint"></i>
                                    </div>
                                </div>
                                
                                <form id="fingerprintForm" method="POST">
                                    <input type="hidden" name="simulated_fingerprint" value="1">
                                </form>
                                
                                <?php if (!empty($collector_name)): ?>
                                    <div class="collector-info">
                                        <div class="collector-name"><?php echo htmlspecialchars($collector_name); ?></div>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="text-center">
                                    <button class="btn btn-primary" id="manualTrigger">
                                        Scan Fingerprint
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Recent Attendance -->
                    <div class="card dash-card recent-attendance">
                        <header class="dash-card__head">
                            <span class="dash-card__icon"><i class="fas fa-history" aria-hidden="true"></i></span>
                            <div>
                                <h2 class="dash-card__title">Recent Attendance Records</h2>
                                <p class="dash-card__hint">Latest collector punches</p>
                            </div>
                        </header>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover dash-table">
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
                                        <?php if (!empty($recent_attendance)): ?>
                                            <?php foreach ($recent_attendance as $record): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars(date('M j, Y', strtotime($record['attendance_date']))); ?></td>
                                                    <td><?php echo htmlspecialchars($record['collector_name']); ?></td>
                                                    <td><?php echo htmlspecialchars(date('h:i A', strtotime($record['time_in']))); ?></td>
                                                    <td>
                                                        <?php if ($record['time_out']): ?>
                                                            <?php echo htmlspecialchars(date('h:i A', strtotime($record['time_out']))); ?>
                                                        <?php else: ?>
                                                            <span class="badge bg-warning text-dark">Active</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($record['time_out']): ?>
                                                            <?php 
                                                                $time_in = new DateTime($record['time_in']);
                                                                $time_out = new DateTime($record['time_out']);
                                                                $interval = $time_in->diff($time_out);
                                                                echo $interval->format('%h hr %i min');
                                                            ?>
                                                        <?php endif; ?>
                                                    </td>
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
                                                <td colspan="6" class="text-center">No attendance records found</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
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
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
document.addEventListener('DOMContentLoaded', function() {
    const scannerArea = document.getElementById('scannerArea');
    const fingerprintForm = document.getElementById('fingerprintForm');
    const manualTrigger = document.getElementById('manualTrigger');
    const currentTimeElement = document.getElementById('currentTime');
    
    // Variable to track if time loading failed
    let timeLoadFailed = false;

    // Function to format date for comparison (without seconds)
    function formatDateForComparison(date) {
        const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true };
        return date.toLocaleDateString('en-US', options);
    }

    // Function to disable scanner
    function disableScanner() {
        if (scannerArea) {
            scannerArea.classList.add('disabled');
            scannerArea.innerHTML = '<div class="scanner-icon"><i class="fas fa-ban"></i></div>';
        }
        if (manualTrigger) {
            manualTrigger.disabled = true;
            manualTrigger.classList.add('disabled-button');
            manualTrigger.textContent = 'Scanner Disabled';
        }
        timeLoadFailed = true;
    }

    // Function to fetch Philippines time from WorldTimeAPI
    async function fetchPhilippinesTime() {
        try {
            const response = await fetch('https://worldtimeapi.org/api/timezone/Asia/Manila');
            const data = await response.json();
            const phTime = new Date(data.datetime);
            updateTimeDisplay(phTime);

            // Calculate the offset between API time and local time
            const localTime = new Date();
            const offsetMs = phTime.getTime() - localTime.getTime();

            // Compare formatted times (without seconds)
            const phFormatted = formatDateForComparison(phTime);
            const localFormatted = formatDateForComparison(localTime);

            if (phFormatted !== localFormatted) {
                showTimeMismatchAlert(phFormatted, localFormatted);
                // Set time tampering flag in session
                fetch('set_time_tampering_flag.php');
            }

            // Update the clock every second using the offset
            setInterval(() => {
                const now = new Date();
                const adjustedTime = new Date(now.getTime() + offsetMs);
                updateTimeDisplay(adjustedTime);
            }, 1000);

        } catch (error) {
            console.error('Error fetching Philippines time:', error);
            currentTimeElement.textContent = "Unable to load Date and Time. Please Refresh the Page.";
            
            // Disable the scanner when time loading fails
            disableScanner();
            
            // Show error alert
            Swal.fire({
                icon: 'error',
                title: 'Time Sync Error',
                text: 'Unable to verify Philippines time. Attendance recording has been disabled.',
                confirmButtonText: 'Refresh the Page'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.reload(); // Refresh the page
                }
            });
        }
    }

    // Show alert when time mismatch is detected
    function showTimeMismatchAlert(phTime, localTime) {
        disableScanner(); // Disable scanner on time mismatch
        
        Swal.fire({
            icon: 'error',
            title: 'Time Discrepancy Detected',
            html: `The system detected a difference between your local time and Philippines time.<br><br>
                <strong>Philippines Time:</strong> ${phTime}<br>
                <strong>Your Local Time:</strong> ${localTime}<br><br>
                Please ensure your device clock is synchronized with Philippines time for accurate attendance recording.`,
            confirmButtonText: 'OK',
            allowOutsideClick: false
        }).then(() => {
            // Reload the page to disable the scanner
            window.location.reload();
        });
    }

    // Format and display the time
    function updateTimeDisplay(date) {
        const options = {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: true
        };
        currentTimeElement.textContent = date.toLocaleDateString('en-US', options);
    }

    // Initialize the time display
    fetchPhilippinesTime();

    <?php if (!$time_tampering_detected): ?>
    // Only enable fingerprint scanning if no time tampering detected
    // Handle manual fingerprint scan simulation
    manualTrigger.addEventListener('click', function() {
        // Check if time loading failed
        if (timeLoadFailed) {
            Swal.fire({
                icon: 'error',
                title: 'Scanner Disabled',
                text: 'Cannot scan fingerprint due to time synchronization issues.',
                confirmButtonText: 'OK'
            });
            return;
        }

        // Show scanning animation
        scannerArea.classList.add('active');
        
        // Simulate scan delay
        setTimeout(function() {
            // Submit the form
            fingerprintForm.submit();
        }, 1500);
    });
    <?php endif; ?>

    // Show messages if any
    <?php if (!empty($message)): ?>
    Swal.fire({
        icon: '<?php echo !empty($attendance_status) ? "success" : "error"; ?>',
        title: '<?php echo !empty($attendance_status) ? $attendance_status : "Error"; ?>',
        text: '<?php echo addslashes($message); ?>',
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true,
        background: '<?php echo !empty($attendance_status) ? ($attendance_status == "Time In" ? "#198754" : "#dc3545") : "#dc3545"; ?>',
        color: '#ffffff',
        iconColor: '#ffffff'
    });
    <?php endif; ?>
});
</script>

</body>
</html>
