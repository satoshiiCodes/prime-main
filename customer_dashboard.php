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
$customerLoans = [];
$totalActiveLoans = 0;
$totalBalance = 0;
$totalActiveLoanAmount = 0;
$totalActivePenalty = 0;
$totalActiveSavings = 0;
$latestPayments = [];

// Get user details and loan information
try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    // Get user details from customer_accounts table
    $stmt = $conn->prepare("SELECT id, first_name, middle_name, last_name, suffix, account_type FROM customer_accounts WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception("User not found");
    }

    $user = $result->fetch_assoc();
    $stmt->close();

    // Get customer's loans from financing table
    $loanQuery = $conn->prepare("
        SELECT 
            f.id,
            f.customer_name,
            f.collector_name,
            f.amount_financed,
            f.interest,
            f.interest_peso,
            f.service_fee,
            f.savings,
            f.daily_payment,
            f.customer_net,
            f.balance,
            f.penalty,
            f.loan_term,
            f.start_date,
            f.end_date,
            f.status,
            (f.daily_payment * 30) as monthly_payment,
            (f.amount_financed + f.interest_peso) as total_loan_amount
        FROM financing f
        WHERE f.customer_id = ?
        ORDER BY f.start_date DESC
        LIMIT 5
    ");
    $loanQuery->bind_param("i", $_SESSION['user_id']);
    $loanQuery->execute();
    $loanResult = $loanQuery->get_result();
    
    while ($row = $loanResult->fetch_assoc()) {
        $customerLoans[] = $row;
        if ($row['status'] == 'active') {
            $totalActiveLoans++;
            $totalBalance += $row['balance'];
            $totalActiveLoanAmount += ($row['amount_financed'] + $row['interest_peso']);
            $totalActivePenalty += $row['penalty'];
        }
    }
    $loanQuery->close();

    // Get customer's active savings accounts balance
    $savingsQuery = $conn->prepare("
        SELECT SUM(current_balance) as total_savings 
        FROM savings_accounts 
        WHERE customer_id = ? 
        AND status = 'Active'
    ");
    $savingsQuery->bind_param("i", $_SESSION['user_id']);
    $savingsQuery->execute();
    $savingsResult = $savingsQuery->get_result();
    
    if ($savingsResult->num_rows > 0) {
        $savingsData = $savingsResult->fetch_assoc();
        $totalActiveSavings = $savingsData['total_savings'] ?? 0;
    }
    $savingsQuery->close();

    // Get 6 latest payments
    $paymentsQuery = $conn->prepare("
        SELECT 
            p.payment_date,
            p.amount as amount_paid,
            p.payment_method,
            p.notes,
            p.balance_after_payment,
            f.customer_name,
            f.loan_term
        FROM payments p
        LEFT JOIN financing f ON p.loan_id = f.id
        WHERE p.customer_id = ?
        ORDER BY p.payment_date DESC
        LIMIT 6
    ");
    $paymentsQuery->bind_param("i", $_SESSION['user_id']);
    $paymentsQuery->execute();
    $paymentsResult = $paymentsQuery->get_result();
    
    while ($row = $paymentsResult->fetch_assoc()) {
        $latestPayments[] = $row;
    }
    $paymentsQuery->close();

    $conn->close();

} catch (Exception $e) {
    error_log($e->getMessage());
}

// Logout function
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    setcookie('remember_me', '', time() - 3600, "/");
    header("Location: login_form.php");
    exit;
}

// Shared layout (includes/topbar.php + includes/sidebar.php) reads these.
// The chrome needs an array $user with an identity; fill whatever is missing
// (e.g. when the lookup above found no matching customer row) from the session
// so the top bar and sidebar never render blank.
$layout_user_defaults = array(
    'account_type' => $_SESSION['account_type'] ?? '',
    'first_name'   => $_SESSION['first_name'] ?? '',
    'middle_name'  => $_SESSION['middle_name'] ?? '',
    'last_name'    => $_SESSION['last_name'] ?? '',
    'suffix'       => $_SESSION['suffix'] ?? '',
);

if (!is_array($user)) {
    $user = $layout_user_defaults;
} else {
    foreach ($layout_user_defaults as $layout_user_key => $layout_user_value) {
        if (!isset($user[$layout_user_key]) || $user[$layout_user_key] === '') {
            $user[$layout_user_key] = $layout_user_value;
        }
    }
}
unset($layout_user_defaults, $layout_user_key, $layout_user_value);

$layout_active = 'customer-dashboard';
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
        <title>Dashboard - Prime City Finance</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
        <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        <link href="assets/css/dash.css" rel="stylesheet" />
        <link href="assets/css/dash-ui.css" rel="stylesheet" />
        <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
        <link href="assets/css/customer-dashboard-style.css" rel="stylesheet" />
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
                                <i class="fas fa-gauge-high" aria-hidden="true"></i>
                                Prime City &middot; Customer
                            </p>
                            <h1 class="dash-title">Welcome back, <?php echo htmlspecialchars($user['first_name'] ?? ''); ?>!</h1>
                            <p class="dash-sub user-info">
                                <i class="fas fa-user-circle me-2"></i>
                                <?php echo htmlspecialchars($user['account_type'] ?? ''); ?> Account
                                &middot; Last login: Today
                            </p>
                        </div>

                        <div class="dash-head__actions">
                            <a class="btn btn-primary" href="customer_view_payment.php?id=<?php echo $_SESSION['user_id']; ?>">
                                <i class="fas fa-file-invoice-dollar me-1" aria-hidden="true"></i>
                                Loan Details
                            </a>
                        </div>
                    </header>
                    
                    <!-- Account summary -->
                    <section class="dash-stats" aria-label="Account summary">
                        <article class="stat animate-fade-in-up" style="animation-delay: 0.1s">
                            <div class="stat__body">
                                <p class="stat__label">Total Active Loan</p>
                                <p class="stat__value">&#8369;<?php echo number_format($totalActiveLoanAmount, 2); ?></p>
                                <p class="stat__meta"><?php echo $totalActiveLoans; ?> active loan<?php echo $totalActiveLoans !== 1 ? 's' : ''; ?></p>
                            </div>
                            <span class="stat__icon"><i class="fas fa-hand-holding-usd" aria-hidden="true"></i></span>
                        </article>
                        
                        <article class="stat stat--green animate-fade-in-up" style="animation-delay: 0.2s">
                            <div class="stat__body">
                                <p class="stat__label">Total Savings</p>
                                <p class="stat__value">&#8369;<?php echo number_format($totalActiveSavings, 2); ?></p>
                                <p class="stat__meta">Secure savings balance</p>
                            </div>
                            <span class="stat__icon"><i class="fas fa-piggy-bank" aria-hidden="true"></i></span>
                        </article>
                        
                        <article class="stat stat--cyan animate-fade-in-up" style="animation-delay: 0.3s">
                            <div class="stat__body">
                                <p class="stat__label">Total Penalty</p>
                                <p class="stat__value">&#8369;<?php echo number_format($totalActivePenalty, 2); ?></p>
                                <p class="stat__meta">Pay early to avoid penalties</p>
                            </div>
                            <span class="stat__icon"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i></span>
                        </article>
                        
                        <article class="stat stat--navy animate-fade-in-up" style="animation-delay: 0.4s">
                            <div class="stat__body">
                                <p class="stat__label">Remaining Balance</p>
                                <p class="stat__value">&#8369;<?php echo number_format($totalBalance, 2); ?></p>
                                <p class="stat__meta">Updated in real-time</p>
                            </div>
                            <span class="stat__icon"><i class="fas fa-balance-scale" aria-hidden="true"></i></span>
                        </article>
                    </section>
                    
                    <!-- Active loans -->
                    <section class="card dash-card dash-section animate-fade-in-up" style="animation-delay: 0.5s">
                        <header class="dash-card__head">
                            <span class="dash-card__icon"><i class="fas fa-file-contract" aria-hidden="true"></i></span>
                            <div>
                                <h2 class="dash-card__title">Active Loans</h2>
                                <p class="dash-card__hint">Your latest financing records</p>
                            </div>
                        </header>
                        <div class="card-body">
                        
                        <?php if (count($customerLoans) > 0): ?>
                            <?php foreach ($customerLoans as $index => $loan): ?>
                                <div class="loan-card-modern" style="animation-delay: <?php echo 0.5 + ($index * 0.1); ?>s">
                                    <div class="loan-header-modern">
                                        <div class="loan-amount-modern">
                                            ₱<?php echo number_format($loan['amount_financed'] + ($loan['interest_peso'] ?? 0), 2); ?>
                                        </div>
                                        <div class="loan-status status-<?php echo $loan['status'] ?? 'unknown'; ?>">
                                            <?php echo ucfirst($loan['status'] ?? 'unknown'); ?>
                                        </div>
                                    </div>
                                    
                                    <div class="loan-details-grid">
                                        <div class="detail-item-modern">
                                            <span class="detail-label">Collector</span>
                                            <span class="detail-value"><?php echo htmlspecialchars($loan['collector_name'] ?? 'N/A'); ?></span>
                                        </div>
                                        <div class="detail-item-modern">
                                            <span class="detail-label">Loan Term</span>
                                            <span class="detail-value"><?php echo $loan['loan_term'] ?? '0'; ?> months</span>
                                        </div>
                                        <div class="detail-item-modern">
                                            <span class="detail-label">Daily Payment</span>
                                            <span class="detail-value">₱<?php echo number_format($loan['daily_payment'] ?? 0, 2); ?></span>
                                        </div>
                                        <div class="detail-item-modern">
                                            <span class="detail-label">Start Date</span>
                                            <span class="detail-value"><?php echo !empty($loan['start_date']) ? date('M j, Y', strtotime($loan['start_date'])) : 'N/A'; ?></span>
                                        </div>
                                        <?php if (!empty($loan['end_date'])): ?>
                                        <div class="detail-item-modern">
                                            <span class="detail-label">End Date</span>
                                            <span class="detail-value"><?php echo date('M j, Y', strtotime($loan['end_date'])); ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <i class="fas fa-file-contract"></i>
                                </div>
                                <h3 class="empty-state-title">No Active Loans</h3>
                                <p class="empty-state-text">You don't have any active loans at the moment.</p>
                                <a href="apply_loan.php" class="btn btn-primary">
                                    <i class="fas fa-plus-circle me-2"></i>
                                    Apply for a Loan
                                </a>
                            </div>
                        <?php endif; ?>
                        </div>
                    </section>
                    
                    <!-- Recent activity and support -->
                    <div class="row animate-fade-in-up" style="animation-delay: 0.6s">
                        <div class="col-lg-8 mb-4">
                            <section class="card dash-card h-100">
                                <header class="dash-card__head">
                                    <span class="dash-card__icon"><i class="fas fa-clock-rotate-left" aria-hidden="true"></i></span>
                                    <div>
                                        <h2 class="dash-card__title">Recent Payments</h2>
                                        <p class="dash-card__hint">Your latest posted payments</p>
                                    </div>
                                </header>
                                <div class="card-body">
                                
                                <?php if (count($latestPayments) > 0): ?>
                                    <?php foreach ($latestPayments as $payment): ?>
                                        <div class="payment-item-modern">
                                            <div class="payment-date-modern">
                                                <i class="fas fa-calendar-day me-2"></i>
                                                <?php echo !empty($payment['payment_date']) ? date('M d, Y', strtotime($payment['payment_date'])) : 'N/A'; ?>
                                            </div>
                                            <?php if (!empty($payment['payment_method'])): ?>
                                            <div class="payment-method-badge">
                                                <?php echo htmlspecialchars($payment['payment_method']); ?>
                                            </div>
                                            <?php endif; ?>
                                            <div class="payment-amount-modern">
                                                ₱<?php echo number_format($payment['amount_paid'] ?? 0, 2); ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <div class="empty-state-icon">
                                            <i class="fas fa-file-invoice-dollar"></i>
                                        </div>
                                        <h3 class="empty-state-title">No Payment History</h3>
                                        <p class="empty-state-text">You haven't made any payments yet.</p>
                                        <?php if (count($customerLoans) > 0): ?>
                                            <a href="make_payment.php" class="btn btn-primary">
                                                <i class="fas fa-credit-card me-2"></i>
                                                Make Your First Payment
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                </div>
                            </section>
                        </div>
                        
                        <div class="col-lg-4 mb-4">
                            <section class="card dash-card h-100">
                                <header class="dash-card__head">
                                    <span class="dash-card__icon"><i class="fas fa-headset" aria-hidden="true"></i></span>
                                    <div>
                                        <h2 class="dash-card__title">Complaints &amp; Questions</h2>
                                        <p class="dash-card__hint">We are here to help</p>
                                    </div>
                                </header>
                                <div class="card-body">
                                
                                <div class="mb-4">
                                    <h5 class="fw-bold mb-3">Have complaints or questions?</h5>
                                    <p class="text-muted mb-4">Send us a message or call.</p>
                                    
                                    <div class="d-flex align-items-center mb-3">
                                        <div class="bg-primary text-white rounded-circle p-2 me-3">
                                            <i class="fas fa-phone"></i>
                                        </div>
                                        <div>
                                            <small class="text-muted">Phone Number</small>
                                            <div class="fw-bold">0905-967-7034</div>
                                        </div>
                                    </div>
                                    
                                    <div class="d-flex align-items-center mb-3">
                                        <div class="bg-success text-white rounded-circle p-2 me-3">
                                            <i class="fas fa-envelope"></i>
                                        </div>
                                        <div>
                                            <small class="text-muted">Email Address</small>
                                            <div class="fw-bold">support@primecity.com</div>
                                        </div>
                                    </div>
                                    
                                    <div class="d-flex align-items-center">
                                        <div class="bg-warning text-white rounded-circle p-2 me-3">
                                            <i class="fas fa-clock"></i>
                                        </div>
                                        <div>
                                            <small class="text-muted">Office Hours</small>
                                            <div class="fw-bold">Mon-Sat, 8AM-5PM</div>
                                        </div>
                                    </div>
                                </div>
                                </div>
                            </section>
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
        <script src="js/datatables-simple-demo.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        
        <script>
            // Enhanced animations and interactions
            document.addEventListener('DOMContentLoaded', function() {
                // Add loading animation to cards
                const cards = document.querySelectorAll('.animate-fade-in-up');
                cards.forEach((card, index) => {
                    card.style.opacity = '0';
                    card.style.transform = 'translateY(30px)';
                    card.style.transition = 'all 0.8s cubic-bezier(0.34, 1.56, 0.64, 1)';
                    
                    setTimeout(() => {
                        card.style.opacity = '1';
                        card.style.transform = 'translateY(0)';
                    }, 100 + (index * 100));
                });
                
                // Hover effects for stat cards
                const statCards = document.querySelectorAll('.stat-card-modern');
                statCards.forEach(card => {
                    card.addEventListener('mouseenter', function() {
                        this.style.transform = 'translateY(-8px) scale(1.02)';
                        this.style.zIndex = '100';
                    });
                    
                    card.addEventListener('mouseleave', function() {
                        this.style.transform = 'translateY(0) scale(1)';
                        this.style.zIndex = '1';
                    });
                });
                
                // Hover effects for header buttons
                const headerButtons = document.querySelectorAll('.header-btn');
                headerButtons.forEach(button => {
                    button.addEventListener('mouseenter', function() {
                        this.style.transform = 'translateY(-3px)';
                    });
                    
                    button.addEventListener('mouseleave', function() {
                        this.style.transform = 'translateY(0)';
                    });
                });
                
                // Real-time clock update
                function updateClock() {
                    const now = new Date();
                    const timeString = now.toLocaleTimeString('en-US', { 
                        hour: '2-digit', 
                        minute: '2-digit',
                        hour12: true 
                    });
                    const dateString = now.toLocaleDateString('en-US', {
                        weekday: 'long',
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric'
                    });
                    
                    const userInfoElement = document.querySelector('.user-info');
                    if (userInfoElement) {
                        userInfoElement.innerHTML = `
                            <i class="fas fa-user-circle me-2"></i>
                            <?php echo htmlspecialchars($user['account_type'] ?? ''); ?> Account
                            • ${dateString} • ${timeString}
                        `;
                    }
                }
                
                // Update clock every minute
                updateClock();
                setInterval(updateClock, 60000);
                
                // Add tooltips to payment methods
                const paymentBadges = document.querySelectorAll('.payment-method-badge');
                paymentBadges.forEach(badge => {
                    badge.setAttribute('title', 'Payment Method');
                    badge.setAttribute('data-bs-toggle', 'tooltip');
                });
                
                // Initialize Bootstrap tooltips
                const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
                tooltipTriggerList.map(function (tooltipTriggerEl) {
                    return new bootstrap.Tooltip(tooltipTriggerEl);
                });
                
                // Add smooth scrolling for anchor links
                document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                    anchor.addEventListener('click', function (e) {
                        e.preventDefault();
                        const target = document.querySelector(this.getAttribute('href'));
                        if (target) {
                            target.scrollIntoView({
                                behavior: 'smooth',
                                block: 'start'
                            });
                        }
                    });
                });
                
                // Add active class to current page nav item
                const currentPage = window.location.pathname.split('/').pop();
                const navLinks = document.querySelectorAll('.navbar-nav .nav-link');
                
                navLinks.forEach(link => {
                    const linkHref = link.getAttribute('href');
                    if (linkHref && (linkHref.includes(currentPage) || (currentPage === '' && linkHref.includes('dashboard.php')))) {
                        link.classList.add('active');
                        link.style.color = '#667eea';
                    }
                });
            });
            
            // Add confetti effect on successful actions (if needed)
            function showSuccessAnimation() {
                if (typeof confetti === 'function') {
                    confetti({
                        particleCount: 100,
                        spread: 70,
                        origin: { y: 0.6 }
                    });
                }
            }
            
            // Check for success message in URL
            if (window.location.search.includes('success=true')) {
                setTimeout(showSuccessAnimation, 500);
            }
        </script>
        
        <!-- Optional: Add confetti library for celebrations -->
        <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.5.1/dist/confetti.browser.min.js"></script>
    </body>
</html>
