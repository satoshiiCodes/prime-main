<?php
/**
 * Prime City - Customer loan/payment view actions.
 *
 * Server-side logic for customer_view_payment.php: who is looking, which
 * customer and loans they are looking at, the summary totals, the automatic
 * monthly penalty pass and the payment calendar data.
 *
 * Produces no markup - the page includes it and renders.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Start the session once.
 *
 * @return void
 */
function cvp_start_session()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * Is a user signed in?
 *
 * @return bool
 */
function cvp_is_authenticated()
{
    return isset($_SESSION['logged_in'], $_SESSION['user_id']);
}

/**
 * Redirect, falling back to JS when headers are already sent.
 *
 * @param  string $location Target path.
 * @return void
 */
function cvp_redirect($location)
{
    if (!headers_sent()) {
        header('Location: ' . $location);
        return;
    }

    echo '<script>window.location.href = ' . json_encode($location) . ';</script>';
}

/**
 * Destroy the session and the remember-me cookie.
 *
 * @return void
 */
function cvp_sign_out()
{
    cvp_start_session();

    $_SESSION = array();

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_unset();
    session_destroy();
    setcookie('remember_me', '', time() - 3600, '/');
}

/**
 * Honour `?logout=true`.
 *
 * @return void
 */
function cvp_handle_logout()
{
    if (!isset($_GET['logout'])) {
        return;
    }

    cvp_sign_out();
    cvp_redirect('index.php');
    exit;
}

/**
 * The customer id from `?id=`, redirecting when it is missing.
 *
 * @return int
 */
function cvp_requested_customer_id()
{
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        cvp_redirect('customer_list.php');
        exit;
    }

    return intval($_GET['id']);
}

/**
 * Load one customer.
 *
 * @param  PDO $pdo        Open connection.
 * @param  int $customerId Customer primary key.
 * @return array<string, mixed>
 *
 * @throws RuntimeException When the customer does not exist.
 */
function cvp_fetch_customer(PDO $pdo, $customerId)
{
    $stmt = $pdo->prepare('SELECT * FROM customer_accounts WHERE id = :id');
    $stmt->bindParam(':id', $customerId, PDO::PARAM_INT);
    $stmt->execute();

    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        throw new RuntimeException('Customer not found');
    }

    return $customer;
}

/**
 * Loans for one customer, with paid/remaining/savings figures attached.
 *
 * @param  PDO    $pdo        Open connection.
 * @param  int    $customerId Customer primary key.
 * @param  string $group      `active` or `history`.
 * @return array<int, array<string, mixed>>
 */
function cvp_fetch_loans(PDO $pdo, $customerId, $group)
{
    if ($group === 'active') {
        $sql = "
            SELECT l.*, 
                   (SELECT SUM(amount) FROM payments WHERE loan_id = l.id) as total_paid,
                   ((l.amount_financed + l.interest_peso + l.penalty) - (SELECT IFNULL(SUM(amount), 0) FROM payments WHERE loan_id = l.id)) as remaining_balance,
                   c.first_name AS collector_first_name, c.last_name AS collector_last_name,
                   (SELECT current_balance FROM savings_accounts WHERE loan_id = l.id) as savings_balance,
                   (SELECT IFNULL(SUM(amount), 0) FROM savings_transactions st 
                    JOIN savings_accounts sa ON st.savings_account_id = sa.id 
                    WHERE sa.loan_id = l.id AND st.transaction_type IN ('Withdrawal', 'Withdrawal Cash')) as total_withdrawals
            FROM financing l
            LEFT JOIN collector_accounts c ON l.collector_id = c.id
            WHERE l.customer_id = :customer_id AND l.status = 'active'
            ORDER BY l.start_date DESC
        ";
    } else {
        $sql = "
            SELECT l.*, 
                   (SELECT SUM(amount) FROM payments WHERE loan_id = l.id) as total_paid,
                   ((l.amount_financed + l.interest_peso + l.penalty) - (SELECT IFNULL(SUM(amount), 0) FROM payments WHERE loan_id = l.id)) as remaining_balance,
                   c.first_name AS collector_first_name, c.last_name AS collector_last_name,
                   (SELECT status FROM savings_accounts WHERE loan_id = l.id) as savings_status,
                   (SELECT current_balance FROM savings_accounts WHERE loan_id = l.id) as savings_balance,
                   (SELECT SUM(st.amount) FROM savings_transactions st 
                    JOIN savings_accounts sa ON st.savings_account_id = sa.id 
                    WHERE sa.loan_id = l.id AND st.transaction_type = 'Deposit') as total_savings_deposits,
                   (SELECT IFNULL(SUM(amount), 0) FROM savings_transactions st 
                    JOIN savings_accounts sa ON st.savings_account_id = sa.id 
                    WHERE sa.loan_id = l.id AND st.transaction_type IN ('Withdrawal', 'Withdrawal Cash')) as total_withdrawals
            FROM financing l
            LEFT JOIN collector_accounts c ON l.collector_id = c.id
            WHERE l.customer_id = :customer_id AND l.status != 'active'
            ORDER BY l.id DESC
        ";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':customer_id', $customerId, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Total savings across every non-closed savings account.
 *
 * @param  PDO $pdo        Open connection.
 * @param  int $customerId Customer primary key.
 * @return float
 */
function cvp_fetch_total_savings(PDO $pdo, $customerId)
{
    $stmt = $pdo->prepare("
        SELECT SUM(current_balance) as total_savings 
        FROM savings_accounts 
        WHERE customer_id = :customer_id AND status != 'Closed'
    ");
    $stmt->bindParam(':customer_id', $customerId, PDO::PARAM_INT);
    $stmt->execute();

    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    return floatval($result['total_savings'] ?? 0);
}

/**
 * Summary figures shown in the cards at the top of the page.
 *
 * @param  array<int, array<string, mixed>> $activeLoans   Active loans.
 * @param  array<int, array<string, mixed>> $inactiveLoans Loan history.
 * @param  float                            $totalSavings  Savings balance.
 * @return array<string, mixed>
 */
function cvp_summary(array $activeLoans, array $inactiveLoans, $totalSavings)
{
    $totalActiveAmountFinanced = 0;
    $totalActiveInterestPeso = 0;
    $totalActivePaid = 0;
    $totalActiveBalance = 0;
    $totalActivePenalty = 0;

    foreach ($activeLoans as $loan) {
        $totalActiveAmountFinanced += floatval($loan['amount_financed'] ?? 0);
        $totalActiveInterestPeso += floatval($loan['interest_peso'] ?? 0);
        $totalActivePaid += floatval($loan['total_paid'] ?? 0);
        $totalActiveBalance += floatval($loan['remaining_balance'] ?? 0);
        $totalActivePenalty += floatval($loan['penalty'] ?? 0);
    }

    $totalInactiveAmountFinanced = 0;
    $totalInactiveInterestPeso = 0;
    $totalInactivePaid = 0;
    $totalInactivePenalty = 0;

    foreach ($inactiveLoans as $loan) {
        $totalInactiveAmountFinanced += floatval($loan['amount_financed'] ?? 0);
        $totalInactiveInterestPeso += floatval($loan['interest_peso'] ?? 0);
        $totalInactivePaid += floatval($loan['total_paid'] ?? 0);
        $totalInactivePenalty += floatval($loan['penalty'] ?? 0);
    }

    return array(
        'total_active_loans'            => count($activeLoans),
        'total_active_amount_financed'  => $totalActiveAmountFinanced,
        'total_active_interest_peso'    => $totalActiveInterestPeso,
        'total_active_paid'             => $totalActivePaid,
        'total_active_balance'          => $totalActiveBalance,
        'total_active_penalty'          => $totalActivePenalty,
        'total_inactive_loans'          => count($inactiveLoans),
        'total_inactive_amount_financed' => $totalInactiveAmountFinanced,
        'total_inactive_interest_peso'  => $totalInactiveInterestPeso,
        'total_inactive_paid'           => $totalInactivePaid,
        'total_inactive_penalty'        => $totalInactivePenalty,
        'total_savings'                 => $totalSavings,
        'total_all_penalties'           => $totalActivePenalty + $totalInactivePenalty,
    );
}

/**
 * Add the automatic monthly 5% penalty for overdue active loans.
 *
 * This is a write pass that used to sit inline in the page; it runs on every
 * view and only inserts penalties that do not exist yet.
 *
 * @param  PDO                          $pdo         Open connection.
 * @param  array<string, mixed>         $customer    Customer record.
 * @param  array<int, array<string, mixed>> $active_loans Active loans.
 * @return void
 */
function cvp_apply_automatic_penalties(PDO $pdo, array $customer, array $active_loans)
{
    // AUTOMATIC MONTHLY PENALTY CALCULATION AND ADDITION - FIXED VERSION
    foreach ($active_loans as $loan) {
        $loan_id = intval($loan['id']);
        
        // Check if automatic penalty is enabled for this loan
        $automatic_penalty_enabled = isset($loan['automatic_penalty_enabled']) ? (bool)$loan['automatic_penalty_enabled'] : true;
        
        if (!$automatic_penalty_enabled) {
            continue; // Skip penalty calculation for this loan
        }
        
        $end_date = new DateTime($loan['end_date']);
        $today = new DateTime();
        
        // Check if loan is overdue (past end date)
        if ($today > $end_date) {
            try {
                // Get all penalties for this loan to determine the last penalty month
                $stmt = $pdo->prepare("
                    SELECT created_at, type, amount
                    FROM penalties 
                    WHERE loan_id = :loan_id
                    ORDER BY created_at DESC
                    LIMIT 1
                ");
                $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
                $stmt->execute();
                $last_penalty = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Calculate current remaining balance for penalty calculation
                $remaining_balance = floatval($loan['remaining_balance'] ?? 0);
                
                // Determine which months need penalties
                $months_to_penalize = [];
                
                if ($last_penalty && $last_penalty['created_at']) {
                    // Get the last penalty date
                    $last_penalty_date = new DateTime($last_penalty['created_at']);
                    
                    // Calculate months since last penalty
                    $penalty_interval = $today->diff($last_penalty_date);
                    $months_since_last_penalty = ($penalty_interval->y * 12) + $penalty_interval->m;
                    
                    // If it's been at least one month since last penalty, add penalties for missing months
                    if ($months_since_last_penalty >= 1) {
                        for ($i = 1; $i <= $months_since_last_penalty; $i++) {
                            $penalty_month = clone $last_penalty_date;
                            $penalty_month->modify("+$i month");
                            
                            // Only add penalty if the penalty month is today or in the past
                            if ($penalty_month <= $today) {
                                $months_to_penalize[] = $penalty_month;
                            }
                        }
                    }
                } else {
                    // No previous penalty - start from the day after end_date
                    $first_penalty_date = clone $end_date;
                    $first_penalty_date->modify('+1 day'); // Start penalty the day after end_date
                    
                    // Calculate total months overdue from the day after end_date
                    $overdue_interval = $today->diff($first_penalty_date);
                    $total_months_overdue = ($overdue_interval->y * 12) + $overdue_interval->m;
                    
                    // Add 1 if there are additional days beyond complete months
                    if ($overdue_interval->d > 0 || $total_months_overdue == 0) {
                        $total_months_overdue++;
                    }
                    
                    // Add penalties for all overdue months starting from the day after end_date
                    for ($i = 1; $i <= $total_months_overdue; $i++) {
                        $penalty_month = clone $first_penalty_date;
                        
                        // For the first penalty, use the day after end_date
                        // For subsequent penalties, add full months
                        if ($i > 1) {
                            $penalty_month->modify("+" . ($i - 1) . " month");
                        }
                        
                        // Only add penalty if the penalty date is today or in the past
                        if ($penalty_month <= $today) {
                            $months_to_penalize[] = $penalty_month;
                        }
                    }
                }
                
                // Add penalties for each month that needs one
                $total_penalty_added = 0;
                foreach ($months_to_penalize as $penalty_date) {
                    // Adjust penalty date: if it falls on Sunday, move to Monday
                    if ($penalty_date->format('w') == 0) { // Sunday
                        $penalty_date->modify('+1 day');
                    }
                    
                    // Only process if penalty date is today or in the past
                    if ($penalty_date <= $today) {
                        // Calculate 5% penalty of remaining balance
                        $penalty_amount = $remaining_balance * 0.05;
                        
                        if ($penalty_amount > 0) {
                            // Get customer area and branch for the penalty record
                            $customer_area = $customer['area'] ?? null;
                            $customer_branch = $customer['branch'] ?? null;
                            
                            // Check if penalty already exists for this month
                            $stmt = $pdo->prepare("
                                SELECT id FROM penalties 
                                WHERE loan_id = :loan_id 
                                AND DATE(created_at) = :penalty_date
                                AND type = 'added'
                            ");
                            $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
                            $penalty_date_formatted = $penalty_date->format('Y-m-d');
                            $stmt->bindParam(':penalty_date', $penalty_date_formatted);
                            $stmt->execute();
                            $existing_penalty = $stmt->fetch(PDO::FETCH_ASSOC);
                            
                            if (!$existing_penalty) {
                                // Insert penalty into penalties table with type='added', area and branch from customer
                                $stmt = $pdo->prepare("
                                    INSERT INTO penalties (loan_id, area, type, amount, branch, notes, created_by, created_at)
                                    VALUES (:loan_id, :area, 'added', :amount, :branch, :notes, :created_by, :penalty_date)
                                ");
                                
                                $notes = "5% Monthly Penalty of remaining balance";
                                $created_by = $_SESSION['user_id'] ?? 1; // Use session user ID or default to 1
                                
                                $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
                                $stmt->bindParam(':area', $customer_area);
                                $stmt->bindParam(':amount', $penalty_amount);
                                $stmt->bindParam(':branch', $customer_branch);
                                $stmt->bindParam(':notes', $notes);
                                $stmt->bindParam(':created_by', $created_by, PDO::PARAM_INT);
                                $penalty_date_formatted = $penalty_date->format('Y-m-d');
                                $stmt->bindParam(':penalty_date', $penalty_date_formatted);
                                $stmt->execute();
                                
                                // Accumulate total penalty added
                                $total_penalty_added += $penalty_amount;
                                
                                // Update the remaining balance for subsequent penalty calculations
                                $remaining_balance += $penalty_amount;
                                
                                // Log the penalty addition for debugging
                                error_log("Auto penalty added: Loan ID $loan_id, Amount: $penalty_amount, Date: " . $penalty_date->format('Y-m-d'));
                            }
                        }
                    }
                }
                
                // Update total penalty in financing table if any penalties were added
                if ($total_penalty_added > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE financing 
                        SET penalty = penalty + :penalty_amount,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = :loan_id
                    ");
                    $stmt->bindParam(':penalty_amount', $total_penalty_added);
                    $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
                    $stmt->execute();
                    
                    // Update the balance column to include the new penalty automatically
                    $stmt = $pdo->prepare("
                        UPDATE financing 
                        SET balance = (amount_financed + interest_peso + penalty) - (SELECT IFNULL(SUM(amount), 0) FROM payments WHERE loan_id = :loan_id),
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = :loan_id
                    ");
                    $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
                    $stmt->execute();
                    
                    error_log("Total penalty updated for Loan ID $loan_id: ₱$total_penalty_added");
                }
                
            } catch (Exception $e) {
                error_log("Error processing automatic penalty for loan $loan_id: " . $e->getMessage());
                // Continue with other loans even if one fails
                continue;
            }
        }
    }
}

/**
 * Re-read the active loans after the penalty pass.
 *
 * @param  PDO $pdo        Open connection.
 * @param  int $customerId Customer primary key.
 * @return array<int, array<string, mixed>>
 */
function cvp_refresh_active_loans(PDO $pdo, $customerId)
{
    return cvp_fetch_loans($pdo, $customerId, 'active');
}

/**
 * Per-loan payment calendar data for the client-side calendar.
 *
 * @param  PDO                          $pdo         Open connection.
 * @param  array<int, array<string, mixed>> $active_loans Active loans.
 * @return array<int, array<string, mixed>>
 */
function cvp_build_calendar(PDO $pdo, array $active_loans)
{
    // Get payment calendar data for active loans
    $payment_calendar = [];
    foreach ($active_loans as $loan) {
        $loan_id = intval($loan['id']);
        $start_date = new DateTime($loan['start_date']);
        $end_date = new DateTime($loan['end_date']);
        $daily_payment = floatval($loan['daily_payment']);
        
        // Get ALL payments for this loan (including those after end_date), grouped by date
        $stmt = $pdo->prepare("
            SELECT 
                DATE(p.payment_date) as payment_date,
                SUM(p.amount) as total_amount,
                GROUP_CONCAT(p.payment_method SEPARATOR ', ') as payment_methods,
                GROUP_CONCAT(CONCAT(c.first_name, ' ', c.last_name) SEPARATOR ', ') as collector_names,
                GROUP_CONCAT(p.notes SEPARATOR '; ') as notes
            FROM payments p
            LEFT JOIN collector_accounts c ON p.collector_id = c.id
            WHERE p.loan_id = :loan_id
            GROUP BY DATE(p.payment_date)
            ORDER BY DATE(p.payment_date)
        ");
        $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
        $stmt->execute();
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Organize payments by date for easy lookup
        $payments_by_date = [];
        foreach ($payments as $payment) {
            $payment_date = $payment['payment_date'];
            $payments_by_date[$payment_date] = [
                'total_amount' => floatval($payment['total_amount']),
                'payment_methods' => $payment['payment_methods'],
                'collector_names' => $payment['collector_names'],
                'notes' => $payment['notes']
            ];
        }
        
        // Generate calendar days (excluding Sundays)
        $current_date = clone $start_date;
        $days = [];
        $today = new DateTime();
        $today->setTime(0, 0, 0);
        $total_underpayment = 0;
        $total_advance_payment = 0;
        $running_balance = 0;
        $day_count = 0;

        while ($current_date <= $end_date) {
            if ($current_date->format('w') != '0') {
                $date_str = $current_date->format('Y-m-d');
                $day_count++;
                
                $is_first_day = ($day_count == 1);
                
                if ($is_first_day) {
                    if (isset($payments_by_date[$date_str])) {
                        $payment = $payments_by_date[$date_str];
                        $payment_amount = floatval($payment['total_amount']);
                        
                        $day_status = 'paid';
                        $payment_note = 'Loan Released & Advance Payment: ₱' . number_format($payment_amount, 2);
                        $collector_name = $payment['collector_names'];
                        $payment_method = $payment['payment_methods'];
                        
                        $advance_payment = $payment_amount;
                        $running_balance += $advance_payment;
                        
                        $days[] = [
                            'date' => $date_str,
                            'day' => $current_date->format('j'),
                            'day_name' => $current_date->format('D'),
                            'status' => $day_status,
                            'payment_amount' => $payment_amount,
                            'expected_amount' => 0,
                            'payment_note' => $payment_note,
                            'collector_name' => $collector_name,
                            'payment_method' => $payment_method,
                            'is_loan_period' => true,
                            'underpayment' => 0,
                            'advance_payment' => $advance_payment,
                            'total_underpayment' => 0,
                            'total_advance_payment' => $advance_payment,
                            'running_balance' => $running_balance,
                            'day_count' => $day_count,
                            'is_release_day' => true,
                            'is_release_with_payment' => true
                        ];
                    } else {
                        $days[] = [
                            'date' => $date_str,
                            'day' => $current_date->format('j'),
                            'day_name' => $current_date->format('D'),
                            'status' => 'released',
                            'payment_amount' => 0,
                            'expected_amount' => 0,
                            'payment_note' => 'Loan Released',
                            'collector_name' => '',
                            'payment_method' => '',
                            'is_loan_period' => true,
                            'underpayment' => 0,
                            'advance_payment' => 0,
                            'total_underpayment' => 0,
                            'total_advance_payment' => 0,
                            'running_balance' => 0,
                            'day_count' => $day_count,
                            'is_release_day' => true,
                            'is_release_with_payment' => false
                        ];
                    }
                } else {
                    $day_status = 'pending';
                    $payment_amount = 0;
                    $payment_note = '';
                    $collector_name = '';
                    $payment_method = '';
                    $underpayment = 0;
                    $advance_payment = 0;
                    
                    if (isset($payments_by_date[$date_str])) {
                        $payment = $payments_by_date[$date_str];
                        $day_status = 'paid';
                        $payment_amount = $payment['total_amount'];
                        $payment_note = $payment['notes'];
                        $collector_name = $payment['collector_names'];
                        $payment_method = $payment['payment_methods'];
                        
                        $difference = $payment_amount - $daily_payment;
                        
                        if ($difference < 0) {
                            $underpayment = abs($difference);
                            $running_balance -= $underpayment;
                        } elseif ($difference > 0) {
                            $advance_payment = $difference;
                            $running_balance += $advance_payment;
                        }
                    } elseif ($current_date < $today) {
                        $day_status = 'missed';
                        $underpayment = $daily_payment;
                        $running_balance -= $underpayment;
                    }

                    if ($running_balance < 0) {
                        $total_underpayment = abs($running_balance);
                        $total_advance_payment = 0;
                    } else {
                        $total_underpayment = 0;
                        $total_advance_payment = $running_balance;
                    }

                    $days[] = [
                        'date' => $date_str,
                        'day' => $current_date->format('j'),
                        'day_name' => $current_date->format('D'),
                        'status' => $day_status,
                        'payment_amount' => $payment_amount,
                        'expected_amount' => $daily_payment,
                        'payment_note' => $payment_note,
                        'collector_name' => $collector_name,
                        'payment_method' => $payment_method,
                        'is_loan_period' => true,
                        'underpayment' => $underpayment,
                        'advance_payment' => $advance_payment,
                        'total_underpayment' => $total_underpayment,
                        'total_advance_payment' => $total_advance_payment,
                        'running_balance' => $running_balance,
                        'day_count' => $day_count
                    ];
                }
            }
            
            $current_date->modify('+1 day');
        }
        
        // Process overdue payments (after end_date)
        $overdue_days = [];
        $overdue_payment_dates = array_filter(array_keys($payments_by_date), function($date) use ($end_date) {
            $payment_date = new DateTime($date);
            return $payment_date > $end_date;
        });
        
        if (!empty($overdue_payment_dates)) {
            foreach ($overdue_payment_dates as $payment_date) {
                $payment = $payments_by_date[$payment_date];
                $payment_amount = floatval($payment['total_amount']);
                $payment_date_obj = new DateTime($payment_date);
                
                $overdue_days[] = [
                    'date' => $payment_date,
                    'day' => $payment_date_obj->format('j'),
                    'day_name' => $payment_date_obj->format('D'),
                    'status' => 'paid',
                    'payment_amount' => $payment_amount,
                    'expected_amount' => 0,
                    'payment_note' => 'Overdue Payment: ' . $payment['notes'],
                    'collector_name' => $payment['collector_names'],
                    'payment_method' => $payment['payment_methods'],
                    'is_loan_period' => false,
                    'underpayment' => 0,
                    'advance_payment' => $payment_amount,
                    'total_underpayment' => $total_underpayment,
                    'total_advance_payment' => $total_advance_payment + $payment_amount,
                    'running_balance' => $running_balance + $payment_amount,
                    'day_count' => $day_count + 1,
                    'is_overdue' => true
                ];
                
                $running_balance += $payment_amount;
                $total_advance_payment += $payment_amount;
                $day_count++;
            }
        }
        
        $all_days = array_merge($days, $overdue_days);
        
        $total_underpayment = max(0, -$running_balance);
        $total_advance_payment = max(0, $running_balance);
        
        $payment_calendar[$loan_id] = [
            'loan_id' => $loan_id,
            'start_date' => $loan['start_date'],
            'end_date' => $loan['end_date'],
            'daily_payment' => $daily_payment,
            'days' => $all_days,
            'total_underpayment' => $total_underpayment,
            'total_advance_payment' => $total_advance_payment,
            'running_balance' => $running_balance
        ];
    }
    return $payment_calendar;
}

/**
 * The signed-in account.
 *
 * The page probes every user table in order: accounts, proxy_accounts,
 * collector_accounts, then customer_accounts - preserving the original order
 * so an id that exists in more than one table resolves the same way.
 *
 * @param  PDO $pdo Open connection.
 * @return array<string, mixed>
 *
 * @throws RuntimeException When no table holds the session user.
 */
function cvp_fetch_user(PDO $pdo)
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    $stmt = $pdo->prepare('SELECT * FROM accounts WHERE id = :id');
    $stmt->bindParam(':id', $userId, PDO::PARAM_INT);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $stmt = $pdo->prepare('SELECT * FROM proxy_accounts WHERE id = :id');
        $stmt->bindParam(':id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $user['account_type'] = 'Proxy Collector';
        }
    }

    if (!$user) {
        $stmt = $pdo->prepare('SELECT * FROM collector_accounts WHERE id = :id');
        $stmt->bindParam(':id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $user['account_type'] = 'Collector';
        }
    }

    if (!$user) {
        $stmt = $pdo->prepare('SELECT * FROM customer_accounts WHERE id = :id');
        $stmt->bindParam(':id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $user['account_type'] = 'Customer';
        }
    }

    if (!$user) {
        throw new RuntimeException('User not found in any user table');
    }

    return $user;
}

/**
 * Role flags the view and the scripts depend on.
 *
 * @param  array<string, mixed> $user Current user record.
 * @return array<string, bool>
 */
function cvp_role_flags(array $user)
{
    $accountType = $user['account_type'] ?? '';

    return array(
        'is_admin'        => $accountType === 'Admin',
        'is_area_manager' => $accountType === 'Area Manager',
        'is_collector'    => $accountType === 'Collector',
        'is_proxy'        => $accountType === 'Proxy Collector',
        'is_customer'     => $accountType === 'Customer',
    );
}

/**
 * Which chrome the page renders.
 *
 * The original page used admin_menu for admin/area manager, the collector menu
 * for collectors and proxy collectors, and customer_menu for customers.
 *
 * @param  array<string, bool> $flags Role flags.
 * @return string
 */
function cvp_menu_variant(array $flags)
{
    if ($flags['is_customer']) {
        return 'customer';
    }

    if ($flags['is_collector'] || $flags['is_proxy']) {
        return 'collector';
    }

    return 'admin';
}

/**
 * Everything the view needs, in one call.
 *
 * @return array<string, mixed>
 *
 * @throws RuntimeException When the customer, account or database is unusable.
 */
function cvp_context()
{
    cvp_start_session();
    cvp_handle_logout();

    if (!cvp_is_authenticated()) {
        cvp_redirect('index.php');
        exit;
    }

    $customer_id = cvp_requested_customer_id();

    $pdo = db_pdo();

    $customer = cvp_fetch_customer($pdo, $customer_id);

    $active_loans = cvp_fetch_loans($pdo, $customer_id, 'active');
    $inactive_loans = cvp_fetch_loans($pdo, $customer_id, 'history');
    $total_savings = cvp_fetch_total_savings($pdo, $customer_id);

    $summary = cvp_summary($active_loans, $inactive_loans, $total_savings);

    cvp_apply_automatic_penalties($pdo, $customer, $active_loans);

    $active_loans = cvp_refresh_active_loans($pdo, $customer_id);

    $payment_calendar = cvp_build_calendar($pdo, $active_loans);

    $user = cvp_fetch_user($pdo);
    $flags = cvp_role_flags($user);

    return array(
        'customer_id'      => $customer_id,
        'customer'         => $customer,
        'active_loans'     => $active_loans,
        'inactive_loans'   => $inactive_loans,
        'summary'          => $summary,
        'total_savings'    => $total_savings,
        'payment_calendar' => $payment_calendar,
        'user'             => $user,
        'is_admin'         => $flags['is_admin'],
        'is_area_manager'  => $flags['is_area_manager'],
        'is_collector'     => $flags['is_collector'],
        'is_proxy'         => $flags['is_proxy'],
        'is_customer'      => $flags['is_customer'],
        'layout_menu'      => cvp_menu_variant($flags),
    );
}
