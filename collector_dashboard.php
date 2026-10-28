<?php
session_start();

// Redirect to login if not authenticated
if (!isset($_SESSION['logged_in'])) {
    header("Location: login_form.php");
    exit;
}

require 'includes/db_config.php';

// Initialize user variable
$user = [];

// Get user details - check both collector_accounts and proxy_accounts
try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    // Check account_source to know which table to query
    if ($_SESSION['account_source'] === 'collector_accounts') {
        $stmt = $conn->prepare("SELECT first_name, middle_name, last_name, suffix, account_type FROM collector_accounts WHERE id = ?");
    } elseif ($_SESSION['account_source'] === 'proxy_accounts') {
        $stmt = $conn->prepare("SELECT first_name, middle_name, last_name, suffix, account_type FROM proxy_accounts WHERE id = ?");
    } else {
        throw new Exception("Invalid account source");
    }
    
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception("User not found");
    }

    $user = $result->fetch_assoc();
    $stmt->close();

    // Get weekly collection data
    $weeklyData = [];
    
    if ($_SESSION['account_source'] === 'collector_accounts') {
        // Regular collector: Get their own collections
        $weeklyQuery = "SELECT 
                        YEARWEEK(payment_date, 1) as week_number,
                        MIN(payment_date) as week_start,
                        MAX(payment_date) as week_end,
                        SUM(amount) as weekly_total
                       FROM payments 
                       WHERE collector_id = ?
                       GROUP BY YEARWEEK(payment_date, 1)
                       ORDER BY week_start DESC
                       LIMIT 8";
        
        $weeklyStmt = $conn->prepare($weeklyQuery);
        $weeklyStmt->bind_param("i", $_SESSION['user_id']);
        $weeklyStmt->execute();
        $weeklyResult = $weeklyStmt->get_result();
        
        while ($row = $weeklyResult->fetch_assoc()) {
            $weeklyData[] = $row;
        }
        $weeklyStmt->close();
        
    } elseif ($_SESSION['account_source'] === 'proxy_accounts' && $user['account_type'] === 'Proxy Collector') {
        // Proxy collector: Get collections where they are marked as proxy in payments table
        $weeklyQuery = "SELECT 
                        YEARWEEK(payment_date, 1) as week_number,
                        MIN(payment_date) as week_start,
                        MAX(payment_date) as week_end,
                        SUM(amount) as weekly_total
                       FROM payments 
                       WHERE proxy = ?  -- Check the proxy column for proxy collector ID
                       GROUP BY YEARWEEK(payment_date, 1)
                       ORDER BY week_start DESC
                       LIMIT 8";
        
        $weeklyStmt = $conn->prepare($weeklyQuery);
        $weeklyStmt->bind_param("i", $_SESSION['user_id']);
        $weeklyStmt->execute();
        $weeklyResult = $weeklyStmt->get_result();
        
        while ($row = $weeklyResult->fetch_assoc()) {
            $weeklyData[] = $row;
        }
        $weeklyStmt->close();
    }

    // Reverse the array to show oldest to newest
    $weeklyData = array_reverse($weeklyData);

    $conn->close();

} catch (Exception $e) {
    error_log($e->getMessage());
    // You might want to redirect to an error page or back to login
}

// Logout function
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    setcookie('remember_me', '', time() - 3600, "/"); // Delete remember me cookie
    header("Location: login_form.php");
    exit;
}

// Function to format user name
function formatUserName($user) {
    $name = htmlspecialchars($user['first_name'] ?? '');
    
    if (!empty($user['middle_name'])) {
        $name .= ' ' . htmlspecialchars($user['middle_name'][0] . '.');
    }
    
    $name .= ' ' . htmlspecialchars($user['last_name'] ?? '');
    
    // Only add suffix if it's not empty or "None"
    if (!empty($user['suffix']) && strtolower(trim($user['suffix'])) !== 'none') {
        $name .= ' ' . htmlspecialchars($user['suffix']);
    }
    
    return $name;
}

// Shared layout chrome (includes/topbar.php + includes/sidebar.php) reads these.
if (!isset($user) || !is_array($user) || $user === array()) {
    $user = array(
        'account_type' => $_SESSION['account_type'] ?? '',
        'first_name'   => $_SESSION['first_name'] ?? '',
        'middle_name'  => $_SESSION['middle_name'] ?? '',
        'last_name'    => $_SESSION['last_name'] ?? '',
        'suffix'       => $_SESSION['suffix'] ?? '',
    );
}
// This page has always rendered the collector menu; keep that variant.
$layout_active = 'dashboard';
$layout_menu = 'collector';
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
        <title>Collector Dashboard - Prime City Admin</title>
        <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
        <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        <link href="assets/css/dash.css" rel="stylesheet" />
        <link href="assets/css/dash-ui.css" rel="stylesheet" />
        <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <link href="assets/css/collector-dashboard-style.css" rel="stylesheet" />
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
                                    <i class="fas fa-chart-line" aria-hidden="true"></i>
                                    Prime City &middot; Collector
                                </p>
                                <h1 class="dash-title">Collector Dashboard</h1>
                                <p class="dash-sub">
                                    Welcome back<?php echo formatUserName($user) !== '' ? ', ' . formatUserName($user) : ''; ?> &mdash; here is your weekly collection performance overview.
                                </p>
                            </div>

                            <?php if ($_SESSION['account_source'] === 'proxy_accounts' && isset($user['account_type']) && $user['account_type'] === 'Proxy Collector'): ?>
                                <div class="dash-head__actions">
                                    <span class="dash-pill">
                                        <i class="fas fa-user-shield" aria-hidden="true"></i>
                                        Proxy Collector
                                    </span>
                                </div>
                            <?php endif; ?>
                        </header>

                        <!-- Quick Access Cards -->
                        <div class="row g-3 dash-quick">
                            <div class="col-12 col-md-6">
                                <a href="customers_multiple_payment.php" class="card-link h-100 d-block">
                                    <div class="card dash-card quick-access-card h-100">
                                        <div class="card-body d-flex align-items-center gap-3">
                                            <span class="stat__icon"><i class="fas fa-money-bill-wave" aria-hidden="true"></i></span>
                                            <div>
                                                <h2 class="dash-card__title">Multiple Payments</h2>
                                                <p class="dash-card__hint mb-0">Process payments for your customers</p>
                                            </div>
                                            <i class="fas fa-arrow-right quick-access-arrow" aria-hidden="true"></i>
                                        </div>
                                    </div>
                                </a>
                            </div>
                            <div class="col-12 col-md-6">
                                <a href="customer_list.php" class="card-link h-100 d-block">
                                    <div class="card dash-card quick-access-card h-100">
                                        <div class="card-body d-flex align-items-center gap-3">
                                            <span class="stat__icon"><i class="fas fa-users" aria-hidden="true"></i></span>
                                            <div>
                                                <h2 class="dash-card__title">Customers</h2>
                                                <p class="dash-card__hint mb-0">View the customers assigned to you</p>
                                            </div>
                                            <i class="fas fa-arrow-right quick-access-arrow" aria-hidden="true"></i>
                                        </div>
                                    </div>
                                </a>
                            </div>
                        </div>

                        <!-- Collector Weekly Performance Graph -->
                        <div class="card dash-card mb-4">
                            <header class="dash-card__head">
                                <span class="dash-card__icon"><i class="fas fa-chart-line" aria-hidden="true"></i></span>
                                <div>
                                    <h2 class="dash-card__title">
                                        <?php if ($_SESSION['account_source'] === 'proxy_accounts' && isset($user['account_type']) && $user['account_type'] === 'Proxy Collector'): ?>
                                            Your Weekly Collection Performance (As Proxy)
                                        <?php else: ?>
                                            Your Weekly Collection Performance
                                        <?php endif; ?>
                                    </h2>
                                    <p class="dash-card__hint">Total collected each week</p>
                                </div>
                            </header>
                            <div class="card-body">
                                <?php if (empty($weeklyData)): ?>
                                    <div class="alert alert-info">
                                        <?php if ($_SESSION['account_source'] === 'proxy_accounts' && isset($user['account_type']) && $user['account_type'] === 'Proxy Collector'): ?>
                                            No collection data available for your proxy collections yet. Start collecting payments to see your performance chart.
                                        <?php else: ?>
                                            No collection data available yet. Start collecting payments to see your performance chart.
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="chart-container">
                                        <canvas id="collectorWeeklyChart"></canvas>
                                    </div>
                                    <div class="row mt-3">
                                        <div class="col-12">
                                            <p class="text-muted small">
                                                <i class="fas fa-info-circle me-1"></i>
                                                <?php if ($_SESSION['account_source'] === 'proxy_accounts' && isset($user['account_type']) && $user['account_type'] === 'Proxy Collector'): ?>
                                                    Showing your weekly collection totals as proxy for the past <?php echo count($weeklyData); ?> weeks.
                                                <?php else: ?>
                                                    Showing weekly collection totals for the past <?php echo count($weeklyData); ?> weeks.
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </main>
                <?php include __DIR__ . '/includes/footer.php'; ?>
            </div><!-- /#layoutSidenav_content -->
        </div><!-- /#layoutSidenav -->
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
		<script src="js/scripts.js"></script>
		<script src="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/umd/simple-datatables.min.js" crossorigin="anonymous"></script>
		<script src="js/datatables-simple-demo.js"></script>
        
        <script>
            <?php if (!empty($weeklyData)): ?>
            // Collector Weekly Performance Chart
            const weeklyCtx = document.getElementById('collectorWeeklyChart').getContext('2d');
            
            // Prepare data for the chart
            const weekLabels = [];
            const weekTotals = [];
            
            <?php foreach ($weeklyData as $week): ?>
                weekLabels.push("Week <?php echo date('M d', strtotime($week['week_start'])) . ' - ' . date('M d', strtotime($week['week_end'])); ?>");
                weekTotals.push(<?php echo $week['weekly_total']; ?>);
            <?php endforeach; ?>
            
            const collectorWeeklyChart = new Chart(weeklyCtx, {
                type: 'line',
                data: {
                    labels: weekLabels,
                    datasets: [{
                        label: 'Weekly Collection (₱)',
                        data: weekTotals,
                        backgroundColor: 'rgba(54, 162, 235, 0.2)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: 'rgba(54, 162, 235, 1)',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        pointHoverRadius: 7
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0,0,0,0.1)'
                            },
                            ticks: {
                                callback: function(value) {
                                    return '₱' + value.toLocaleString();
                                }
                            },
                            title: {
                                display: true,
                                text: 'Collection Amount'
                            }
                        },
                        x: {
                            grid: {
                                color: 'rgba(0,0,0,0.1)'
                            },
                            title: {
                                display: true,
                                text: 'Week Period'
                            }
                        }
                    },
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return '₱' + context.raw.toLocaleString();
                                }
                            }
                        },
                        legend: {
                            display: true,
                            position: 'top',
                            labels: {
                                font: {
                                    size: 14
                                }
                            }
                        }
                    },
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    }
                }
            });
            <?php endif; ?>
        </script>
    </body>
</html>
