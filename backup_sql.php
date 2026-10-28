<?php
session_start();

// Redirect to login if not authenticated
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login_form.php");
    exit;
}

// Database configuration
require 'includes/db_config.php';

// Get user details for authorization check
try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    $stmt = $conn->prepare("SELECT account_type FROM accounts WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception("User not found");
    }

    $user = $result->fetch_assoc();
    $stmt->close();
    $conn->close();

} catch (Exception $e) {
    error_log($e->getMessage());
    // Redirect to error page or back to login
    header("Location: admin.php?error=user_not_found");
    exit;
}

// Check if user is Admin (only admins should be able to backup)
if ($user['account_type'] !== 'Admin') {
    header("Location: admin.php?error=unauthorized");
    exit;
}

// Logout function
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    setcookie('remember_me', '', time() - 3600, "/"); // Delete remember me cookie
    header("Location: login_form.php");
    exit;
}

// Process backup request
if (isset($_GET['backup'])) {
    backupDatabase();
    exit;
}

// Function to backup database
function backupDatabase() {
    global $servername, $username, $password, $dbname;
    
    try {
        // Connect to MySQL server
        $conn = new mysqli($servername, $username, $password, $dbname);
        
        if ($conn->connect_error) {
            throw new Exception("Connection failed: " . $conn->connect_error);
        }
        
        // Get all table names
        $tables = array();
        $result = $conn->query("SHOW TABLES");
        while ($row = $result->fetch_row()) {
            $tables[] = $row[0];
        }
        
        if (count($tables) === 0) {
            throw new Exception("No tables found in database");
        }
        
        // Initialize backup content
        $content = "-- Prime City Database Backup\n";
        $content .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $content .= "-- Database: " . $dbname . "\n\n";
        $content .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
        
        // Iterate through tables
        foreach ($tables as $table) {
            // Add DROP TABLE statement
            $content .= "DROP TABLE IF EXISTS `$table`;\n";
            
            // Get CREATE TABLE statement
            $createResult = $conn->query("SHOW CREATE TABLE `$table`");
            $createRow = $createResult->fetch_row();
            $content .= $createRow[1] . ";\n\n";
            
            // Get table data
            $dataResult = $conn->query("SELECT * FROM `$table`");
            
            if ($dataResult->num_rows > 0) {
                $content .= "-- Dumping data for table `$table`\n";
                $content .= "INSERT INTO `$table` VALUES ";
                
                $firstRow = true;
                while ($dataRow = $dataResult->fetch_row()) {
                    if (!$firstRow) {
                        $content .= ",\n";
                    }
                    
                    // Process each value
                    $values = array();
                    foreach ($dataRow as $value) {
                        if ($value === null) {
                            $values[] = "NULL";
                        } else {
                            $values[] = "'" . $conn->real_escape_string($value) . "'";
                        }
                    }
                    
                    $content .= "(" . implode(", ", $values) . ")";
                    $firstRow = false;
                }
                
                $content .= ";\n\n";
            }
        }
        
        $content .= "SET FOREIGN_KEY_CHECKS=1;\n";
        
        // Close connection
        $conn->close();
        
        // Set headers for download
        $backup_file_name = $dbname . '_backup_' . date('Y-m-d_H-i-s') . '.sql';
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $backup_file_name . '"');
        header('Content-Length: ' . strlen($content));
        
        // Output the content
        echo $content;
        exit;
        
    } catch (Exception $e) {
        error_log("Backup failed: " . $e->getMessage());
        header("Location: backup_sql.php?error=backup_failed");
        exit;
    }
}

// Shared layout includes (topbar/sidebar) read these.
$layout_active = 'backup-sql';

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
        <meta name="description" content="" />
        <meta name="author" content="" />
        <title>Database Backup - Prime City Admin</title>
        <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
        <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        <link href="assets/css/dash.css" rel="stylesheet" />
        <link href="assets/css/dash-ui.css" rel="stylesheet" />
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
        <!-- SweetAlert2 CSS -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
        <style>
            .backup-card {
                max-width: 600px;
                margin: 0 auto;
            }
            .backup-icon {
                font-size: 3rem;
                color: #0d6efd;
            }
            .btn-backup {
                padding: 1rem 2rem;
                font-size: 1.2rem;
            }
            .last-backup-info {
                background-color: #f8f9fa;
                border-radius: 0.375rem;
                padding: 1rem;
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
                                    <i class="fas fa-database" aria-hidden="true"></i>
                                    Prime City &middot; Management
                                </p>
                                <h1 class="dash-title">Database Backup</h1>
                                <p class="dash-sub">Download a full SQL dump of the Prime City database for safekeeping.</p>
                            </div>
                        </header>
                        
                        <?php if (isset($_GET['error'])): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <?php
                                $error = $_GET['error'];
                                if ($error == 'unauthorized') {
                                    echo 'You are not authorized to access this feature.';
                                } elseif ($error == 'backup_failed') {
                                    echo 'Database backup failed. Please try again.';
                                } else {
                                    echo 'An error occurred.';
                                }
                                ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (isset($_GET['success'])): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                Database backup completed successfully!
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>
                        
                        <div class="card backup-card mb-4">
                            <header class="dash-card__head">
                                <span class="dash-card__icon"><i class="fas fa-database" aria-hidden="true"></i></span>
                                <div>
                                    <h2 class="dash-card__title">Database Backup</h2>
                                    <p class="dash-card__hint">Create and download a restorable copy of the database</p>
                                </div>
                            </header>
                            <div class="card-body text-center">
                                <div class="mb-4">
                                    <i class="fas fa-database backup-icon"></i>
                                    <h3 class="mt-3">Create Database Backup</h3>
                                    <p class="text-muted">Download a complete backup of your database for safekeeping or migration.</p>
                                </div>
                                
                                <div class="last-backup-info mb-4">
                                    <h5>Backup Information</h5>
                                    <p class="mb-1"><strong>Database Name:</strong> <?php echo $dbname; ?></p>
                                    <p class="mb-0"><strong>Last Backup:</strong> 
                                        <?php
                                        // Check if backup directory exists and get latest backup
                                        $backup_dir = 'backups/';
                                        $latest_backup = 'Never';
                                        
                                        if (is_dir($backup_dir)) {
                                            $files = scandir($backup_dir, SCANDIR_SORT_DESCENDING);
                                            if (count($files) > 2) {
                                                // Assuming SQL files are named with timestamps
                                                foreach ($files as $file) {
                                                    if (pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
                                                        $latest_backup = date('F j, Y, g:i a', filemtime($backup_dir . $file));
                                                        break;
                                                    }
                                                }
                                            }
                                        }
                                        echo $latest_backup;
                                        ?>
                                    </p>
                                </div>
                                
                                <a href="backup_sql.php?backup=true" class="btn btn-primary btn-backup">
                                    <i class="fas fa-download me-2"></i> Download Backup Now
                                </a>
                                
                                <div class="mt-4">
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>
                                        <strong>Note:</strong> This will generate a complete SQL dump of your database that can be used to restore your system if needed.
                                    </div>
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
        <!-- SweetAlert2 JS -->
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Show success message if present
                <?php if (isset($_GET['success'])): ?>
                    Swal.fire({
                        icon: 'success',
                        title: 'Backup Complete',
                        text: 'Database backup was created successfully!',
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 3000,
                        timerProgressBar: true
                    });
                <?php endif; ?>
                
                // Show error message if present
                <?php if (isset($_GET['error'])): ?>
                    const error = '<?php echo $_GET['error']; ?>';
                    let errorMessage = 'An error occurred during backup.';
                    
                    if (error === 'unauthorized') {
                        errorMessage = 'You are not authorized to perform this action.';
                    } else if (error === 'backup_failed') {
                        errorMessage = 'Database backup failed. Please try again.';
                    }
                    
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: errorMessage,
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 5000,
                        timerProgressBar: true
                    });
                <?php endif; ?>
            });
        </script>
    </body>
</html>
