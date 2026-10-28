<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['logged_in'])) {
    header("HTTP/1.1 403 Forbidden");
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Validate input
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("HTTP/1.1 400 Bad Request");
    echo json_encode(['error' => 'Invalid loan ID']);
    exit();
}

$loan_id = $_GET['id'];

// Database configuration
require '../includes/db_config.php';

// Helper function to format datetime
function formatDatetime($datetime) {
    if (empty($datetime)) return 'N/A';
    $timestamp = strtotime($datetime);
    return date('m-d-Y h:i A', $timestamp);
}

// Helper function to format date only
function formatDateOnly($date) {
    if (empty($date)) return 'N/A';
    $timestamp = strtotime($date);
    return date('m-d-Y', $timestamp);
}

try {
    // Create connection
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get loan details with customer and collector info
    $stmt = $conn->prepare("
        SELECT 
            f.*,
            c.first_name AS customer_first_name,
            c.last_name AS customer_last_name,
            c.middle_name AS customer_middle_name,
            c.suffix AS customer_suffix,
            c.area AS customer_area,
            c.branch AS customer_branch,
            col.first_name AS collector_first_name,
            col.last_name AS collector_last_name
        FROM financing f
        LEFT JOIN customer_accounts c ON f.customer_id = c.id
        LEFT JOIN collector_accounts col ON f.collector_id = col.id
        WHERE f.id = :id
    ");
    $stmt->bindParam(':id', $loan_id);
    $stmt->execute();
    
    $loan = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$loan) {
        throw new Exception("Loan not found");
    }
    
    // Get payment history for this loan with proxy information - ORDERED ASCENDING (oldest first)
    $stmt = $conn->prepare("
        SELECT p.*, 
               col.first_name AS collector_first_name,
               col.last_name AS collector_last_name,
               COALESCE(CONCAT(px.first_name, ' ', px.last_name), 'N/A') AS proxy_name
        FROM payments p
        LEFT JOIN collector_accounts col ON p.collector_id = col.id
        LEFT JOIN proxy_accounts px ON p.proxy = px.id
        WHERE p.loan_id = :loan_id
        ORDER BY p.payment_date ASC
    ");
    $stmt->bindParam(':loan_id', $loan_id);
    $stmt->execute();
    
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate total paid
    $loan['total_paid'] = array_sum(array_column($payments, 'amount'));
    
    // ===== GET PENALTIES FOR THIS LOAN - ORDERED ASCENDING (oldest first) =====
    $stmt = $conn->prepare("
        SELECT 
            p.id,
            p.loan_id,
            p.amount,
            p.notes,
            p.created_by,
            p.created_at,
            p.updated_at,
            c.first_name AS collector_first_name,
            c.last_name AS collector_last_name,
            p.type,
            DATE(p.created_at) AS date,
            p.created_at AS full_datetime
        FROM penalties p
        LEFT JOIN collector_accounts c ON p.created_by = c.id
        WHERE p.loan_id = :loan_id
        ORDER BY p.created_at ASC
    ");
    $stmt->bindParam(':loan_id', $loan_id);
    $stmt->execute();

    $loan['penalties'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ===== GET TOTAL SAVINGS BALANCE FOR THIS LOAN (INCLUDING CLOSED ACCOUNTS) =====
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(SUM(sa.current_balance), 0) as total_savings
        FROM savings_accounts sa
        WHERE sa.loan_id = :loan_id
    ");
    $stmt->bindParam(':loan_id', $loan_id);
    $stmt->execute();
    
    $savings_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $loan['total_savings'] = floatval($savings_result['total_savings'] ?? 0);
    
    // ===== Get savings account ID for this loan (INCLUDING CLOSED ACCOUNTS) =====
    $stmt = $conn->prepare("
        SELECT id, current_balance FROM savings_accounts 
        WHERE loan_id = :loan_id
        LIMIT 1
    ");
    $stmt->bindParam(':loan_id', $loan_id);
    $stmt->execute();
    $savings_account = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $savings_account_id = $savings_account['id'] ?? null;
    $initial_savings_balance = floatval($savings_account['current_balance'] ?? 0);
    
    // Get ALL DEPOSIT transactions for timeline calculations (ASC order)
    $all_deposit_transactions_asc = [];
    $all_withdrawal_transactions_asc = [];
    $deposits_by_datetime = []; // Map for quick lookup
    
    // ===== Get ALL savings transactions for display (ASC order - OLDEST TO NEWEST) =====
    $loan['savings_deposits'] = [];
    
    if ($savings_account_id) {
        // For timeline calculations - ASC order (get ALL transactions)
        $stmt_asc = $conn->prepare("
            SELECT 
                st.*,
                c.first_name AS collector_first_name,
                c.last_name AS collector_last_name,
                c.middle_name AS collector_middle_name,
                c.suffix AS collector_suffix,
                COALESCE(CONCAT(px.first_name, ' ', px.last_name), 'N/A') AS proxy_name
            FROM savings_transactions st
            LEFT JOIN collector_accounts c ON st.collector_id = c.id
            LEFT JOIN proxy_accounts px ON st.proxy = px.id
            WHERE st.savings_account_id = :savings_account_id
            ORDER BY st.transaction_date ASC
        ");
        $stmt_asc->bindParam(':savings_account_id', $savings_account_id);
        $stmt_asc->execute();
        
        $all_savings_transactions_asc = $stmt_asc->fetchAll(PDO::FETCH_ASSOC);
        
        // Separate deposits and withdrawals
        foreach ($all_savings_transactions_asc as $transaction) {
            if ($transaction['transaction_type'] === 'Deposit') {
                $all_deposit_transactions_asc[] = $transaction;
            } elseif ($transaction['transaction_type'] === 'Withdrawal Cash') {
                $all_withdrawal_transactions_asc[] = $transaction;
            }
        }
        
        // For display - ASC order (OLDEST TO NEWEST)
        $stmt_desc = $conn->prepare("
            SELECT 
                st.*,
                c.first_name AS collector_first_name,
                c.last_name AS collector_last_name,
                c.middle_name AS collector_middle_name,
                c.suffix AS collector_suffix,
                COALESCE(CONCAT(px.first_name, ' ', px.last_name), 'N/A') AS proxy_name
            FROM savings_transactions st
            LEFT JOIN collector_accounts c ON st.collector_id = c.id
            LEFT JOIN proxy_accounts px ON st.proxy = px.id
            WHERE st.savings_account_id = :savings_account_id
            AND st.transaction_type IN ('Deposit', 'Withdrawal Cash')
            ORDER BY st.transaction_date ASC
        ");
        $stmt_desc->bindParam(':savings_account_id', $savings_account_id);
        $stmt_desc->execute();
        
        $loan['savings_deposits'] = $stmt_desc->fetchAll(PDO::FETCH_ASSOC);
        
        // Create a map of deposits by exact datetime
        foreach ($all_deposit_transactions_asc as $deposit) {
            $deposit_datetime = date('Y-m-d H:i:s', strtotime($deposit['transaction_date']));
            $deposits_by_datetime[$deposit_datetime] = [
                'amount' => floatval($deposit['amount']),
                'data' => $deposit
            ];
        }
    }
    
    // Calculate total loan amount (amount_financed + interest_peso) - WITHOUT penalty initially
    $base_loan_amount = (floatval($loan['amount_financed']) + floatval($loan['interest_peso']));
    $total_penalties = floatval($loan['penalty']);
    $total_loan_amount = $base_loan_amount + $total_penalties;

    // Create loan balance timeline - CORRECTED: Start with base amount, penalties are added when they occur
    $loan_balance_timeline = [];

    // Initialize with the base loan amount at the start (without penalties)
    $loan_balance_timeline['1970-01-01 00:00:00'] = [
        'type' => 'initial',
        'balance' => $base_loan_amount
    ];

    // Track all events in chronological order for proper balance calculation
    $all_timeline_events = [];

    // Add payment dates to timeline
    foreach ($payments as $payment) {
        $payment_datetime = date('Y-m-d H:i:s', strtotime($payment['payment_date']));
        $all_timeline_events[] = [
            'datetime' => $payment_datetime,
            'type' => 'payment',
            'data' => $payment,
            'amount' => floatval($payment['amount']),
            'is_from_savings' => (stripos($payment['notes'] ?? '', 'payment from savings') !== false)
        ];
    }

    // Add penalty dates to timeline - PENALTIES INCREASE THE BALANCE
    foreach ($loan['penalties'] as $penalty) {
        // Only add 'added' type penalties to the timeline (penalties that increase balance)
        if ($penalty['type'] === 'added') {
            $penalty_datetime = date('Y-m-d H:i:s', strtotime($penalty['full_datetime']));
            $all_timeline_events[] = [
                'datetime' => $penalty_datetime,
                'type' => 'penalty',
                'data' => $penalty,
                'amount' => floatval($penalty['amount'])
            ];
        }
    }

    // Add deposit dates to timeline (these increase savings balance)
    if ($savings_account_id) {
        foreach ($all_deposit_transactions_asc as $deposit) {
            $deposit_datetime = date('Y-m-d H:i:s', strtotime($deposit['transaction_date']));
            $all_timeline_events[] = [
                'datetime' => $deposit_datetime,
                'type' => 'deposit',
                'data' => $deposit,
                'amount' => floatval($deposit['amount'])
            ];
        }
        
        // Add withdrawal dates to timeline (withdrawals decrease savings balance)
        foreach ($all_withdrawal_transactions_asc as $withdrawal) {
            $withdrawal_datetime = date('Y-m-d H:i:s', strtotime($withdrawal['transaction_date']));
            $all_timeline_events[] = [
                'datetime' => $withdrawal_datetime,
                'type' => 'withdrawal',
                'data' => $withdrawal,
                'amount' => floatval($withdrawal['amount'])
            ];
        }
    }

    // Sort all events by datetime
    usort($all_timeline_events, function($a, $b) {
        return strtotime($a['datetime']) - strtotime($b['datetime']);
    });

    // Group events by datetime to handle multiple events on the same day
    $events_by_datetime = [];
    foreach ($all_timeline_events as $event) {
        $datetime = $event['datetime'];
        if (!isset($events_by_datetime[$datetime])) {
            $events_by_datetime[$datetime] = [];
        }
        $events_by_datetime[$datetime][] = $event;
    }

    // Process events chronologically and calculate balance at each point
    $current_loan_balance = $base_loan_amount;
    $current_savings_balance = 0;
    $balance_at_date = [];
    $savings_balance_at_date = [];
    $processed_events_by_datetime = [];

    foreach ($events_by_datetime as $datetime => $events) {
        $payment_amount = 0;
        $penalty_amount = 0;
        $deposit_amount = 0;
        $withdrawal_amount = 0;
        $payment_from_savings_amount = 0;
        $payment_data = null;
        $penalty_data = null;
        $deposit_data = null;
        $withdrawal_data = null;
        $event_types = [];
        
        // Store individual payment data for separate rows
        $individual_payments = [];
        
        foreach ($events as $event) {
            switch ($event['type']) {
                case 'payment':
                    $payment_amount += $event['amount'];
                    $individual_payments[] = $event['data'];
                    $payment_data = $event['data'];
                    $event_types[] = 'payment';
                    if (isset($event['is_from_savings']) && $event['is_from_savings'] === true) {
                        $payment_from_savings_amount += $event['amount'];
                    }
                    break;
                case 'penalty':
                    $penalty_amount += $event['amount'];
                    $penalty_data = $event['data'];
                    $event_types[] = 'penalty';
                    break;
                case 'deposit':
                    $deposit_amount += $event['amount'];
                    $deposit_data = $event['data'];
                    $event_types[] = 'deposit';
                    break;
                case 'withdrawal':
                    $withdrawal_amount += $event['amount'];
                    $withdrawal_data = $event['data'];
                    $event_types[] = 'withdrawal';
                    break;
            }
        }
        
        // First apply penalties (they increase loan balance)
        if ($penalty_amount > 0) {
            $current_loan_balance += $penalty_amount;
        }
        
        // Then apply payments (they decrease loan balance)
        if ($payment_amount > 0) {
            $current_loan_balance -= $payment_amount;
        }
        
        // Apply deposits (they increase savings balance)
        if ($deposit_amount > 0) {
            $current_savings_balance += $deposit_amount;
        }
        
        // Apply withdrawals (they decrease savings balance)
        if ($withdrawal_amount > 0) {
            $current_savings_balance -= $withdrawal_amount;
        }
        
        // Payments made FROM savings also decrease the savings balance
        if ($payment_from_savings_amount > 0) {
            $current_savings_balance -= $payment_from_savings_amount;
        }
        
        // Store the balance after processing all events at this datetime
        $balance_at_date[$datetime] = max(0, $current_loan_balance);
        $savings_balance_at_date[$datetime] = $current_savings_balance;
        
        // Store processed events for combined history
        $processed_events_by_datetime[$datetime] = [
            'payment_amount' => $payment_amount,
            'penalty_amount' => $penalty_amount,
            'deposit_amount' => $deposit_amount,
            'withdrawal_amount' => $withdrawal_amount,
            'payment_from_savings_amount' => $payment_from_savings_amount,
            'individual_payments' => $individual_payments,
            'payment_data' => $payment_data,
            'penalty_data' => $penalty_data,
            'deposit_data' => $deposit_data,
            'withdrawal_data' => $withdrawal_data,
            'event_types' => $event_types,
            'loan_balance' => $balance_at_date[$datetime],
            'savings_balance' => $savings_balance_at_date[$datetime],
            'total_savings_amount' => $deposit_amount
        ];
    }

    // Create combined history array (oldest first for display)
    $combined_history = [];

    foreach ($processed_events_by_datetime as $datetime => $entry) {
        $loan_balance = $entry['loan_balance'];
        $savings_balance = $entry['savings_balance'];
        $total_savings_at_datetime = $entry['total_savings_amount'];
        
        // Get the savings balance BEFORE any payments from savings at this datetime
        $savings_balance_before_payments = $entry['savings_balance'] + $entry['payment_from_savings_amount'];
        
        // Track running savings balance within this datetime for sequential payments
        $temp_savings_balance = $savings_balance_before_payments;
        
        // Add SEPARATE rows for each individual payment
        if (!empty($entry['individual_payments'])) {
            $payment_index = 0;
            
            foreach ($entry['individual_payments'] as $payment) {
                $payment_method = strtolower($payment['payment_method'] ?? '');
                $is_from_savings = (stripos($payment['notes'] ?? '', 'payment from savings') !== false);
                
                // For savings amount column: show deposit amount only on first row
                $savings_amount_to_show = ($payment_index === 0) ? $total_savings_at_datetime : 0;
                
                // If this is a payment from savings, decrease the temporary savings balance FIRST
                if ($is_from_savings) {
                    $temp_savings_balance -= $payment['amount'];
                }
                
                // Then show the balance (which is AFTER deduction for payments from savings)
                $savings_balance_for_row = $temp_savings_balance;
                
                $combined_history[] = [
                    'type' => 'payment',
                    'id' => $payment['id'],
                    'date' => $datetime,
                    'amount' => floatval($payment['amount']),
                    'penalty_amount' => 0.00,
                    'savings_amount' => $savings_amount_to_show,
                    'savings_balance' => $savings_balance_for_row,
                    'payment_method' => $payment['payment_method'] ?? '',
                    'notes' => $payment['notes'] ?? '',
                    'collector_first_name' => $payment['collector_first_name'] ?? '',
                    'collector_last_name' => $payment['collector_last_name'] ?? '',
                    'proxy_name' => $payment['proxy_name'] ?? '',
                    'loan_balance' => $loan_balance,
                    'is_penalty' => false,
                    'is_deposit' => false,
                    'is_payment_from_savings' => $is_from_savings,
                    'show_actions' => true,
                    'payment_method_raw' => $payment_method,
                    'is_first_payment_at_datetime' => ($payment_index === 0)
                ];
                $payment_index++;
            }
        } else if ($entry['penalty_amount'] > 0 && $entry['penalty_data']) {
            // If there are no payments but there is a penalty, show penalty row
            $combined_history[] = [
                'type' => 'penalty',
                'id' => 'penalty_' . $entry['penalty_data']['id'],
                'date' => $datetime,
                'amount' => 0.00,
                'penalty_amount' => $entry['penalty_amount'],
                'savings_amount' => 0.00,
                'savings_balance' => $savings_balance,
                'payment_method' => 'Penalty',
                'notes' => $entry['penalty_data']['notes'] ?? '',
                'collector_first_name' => $entry['penalty_data']['collector_first_name'] ?? '',
                'collector_last_name' => $entry['penalty_data']['collector_last_name'] ?? '',
                'proxy_name' => 'N/A',
                'loan_balance' => $loan_balance,
                'is_penalty' => true,
                'is_deposit' => false,
                'is_payment_from_savings' => false,
                'show_actions' => false
            ];
        } else if ($entry['deposit_amount'] > 0 && empty($entry['individual_payments'])) {
            // Only show deposit row if there are NO payments at this datetime
            $combined_history[] = [
                'type' => 'deposit',
                'id' => 'deposit_' . $entry['deposit_data']['id'],
                'date' => $datetime,
                'amount' => 0.00,
                'penalty_amount' => 0.00,
                'savings_amount' => $entry['deposit_amount'],
                'savings_balance' => $savings_balance,
                'payment_method' => 'Deposit',
                'notes' => $entry['deposit_data']['notes'] ?? '',
                'collector_first_name' => $entry['deposit_data']['collector_first_name'] ?? '',
                'collector_last_name' => $entry['deposit_data']['collector_last_name'] ?? '',
                'proxy_name' => $entry['deposit_data']['proxy_name'] ?? 'N/A',
                'loan_balance' => $loan_balance,
                'is_penalty' => false,
                'is_deposit' => true,
                'is_payment_from_savings' => false,
                'show_actions' => false
            ];
        } else if ($entry['withdrawal_amount'] > 0 && empty($entry['individual_payments'])) {
            // Only show withdrawal row if there are NO payments at this datetime
            $combined_history[] = [
                'type' => 'withdrawal',
                'id' => 'withdrawal_' . $entry['withdrawal_data']['id'],
                'date' => $datetime,
                'amount' => 0.00,
                'penalty_amount' => 0.00,
                'savings_amount' => -$entry['withdrawal_amount'],
                'savings_balance' => $savings_balance,
                'payment_method' => 'Withdrawal',
                'notes' => $entry['withdrawal_data']['notes'] ?? '',
                'collector_first_name' => $entry['withdrawal_data']['collector_first_name'] ?? '',
                'collector_last_name' => $entry['withdrawal_data']['collector_last_name'] ?? '',
                'proxy_name' => $entry['withdrawal_data']['proxy_name'] ?? 'N/A',
                'loan_balance' => $loan_balance,
                'is_penalty' => false,
                'is_deposit' => false,
                'is_withdrawal' => true,
                'is_payment_from_savings' => false,
                'show_actions' => false
            ];
        }
    }

    // Sort combined history by date (oldest first for display)
    usort($combined_history, function($a, $b) {
        return strtotime($a['date']) - strtotime($b['date']);
    });
    
    // Process combined history for final output
    $processed_history = [];
    foreach ($combined_history as $entry) {
        $processed_entry = [
            'id' => $entry['id'],
            'payment_date' => formatDatetime($entry['date']),
            'amount' => $entry['amount'],
            'penalty_amount' => $entry['penalty_amount'],
            'savings_amount' => $entry['savings_amount'],
            'savings_balance' => $entry['savings_balance'],
            'loan_balance' => $entry['loan_balance'],
            'payment_method' => $entry['payment_method'],
            'notes' => $entry['notes'],
            'collector_first_name' => $entry['collector_first_name'],
            'collector_last_name' => $entry['collector_last_name'],
            'proxy_name' => $entry['proxy_name'],
            'is_penalty' => $entry['is_penalty'] ?? false,
            'is_deposit' => $entry['is_deposit'] ?? false,
            'is_withdrawal' => $entry['is_withdrawal'] ?? false,
            'is_payment_from_savings' => $entry['is_payment_from_savings'] ?? false,
            'show_actions' => $entry['show_actions'] ?? false,
            'is_first_payment_at_datetime' => $entry['is_first_payment_at_datetime'] ?? false
        ];
        
        // Set display text and format amount with payment method in parentheses
        if ($entry['type'] === 'penalty') {
            $processed_entry['payment_method_display'] = 'Penalty Added';
            $processed_entry['amount'] = 0.00;
            $processed_entry['amount_formatted'] = 'Penalty Added';
        } elseif ($entry['is_deposit'] ?? false) {
            $processed_entry['payment_method_display'] = 'Savings Deposit';
            $processed_entry['amount'] = 0.00;
            $processed_entry['amount_formatted'] = 'Savings Deposit';
        } elseif ($entry['is_withdrawal'] ?? false) {
            $processed_entry['payment_method_display'] = 'Savings Withdrawal';
            $processed_entry['amount'] = 0.00;
            $processed_entry['amount_formatted'] = 'Savings Withdrawal';
            $processed_entry['savings_amount'] = -abs($processed_entry['savings_amount']);
        } else {
            $payment_method = strtolower($entry['payment_method']);
            $display_method = '';
            
            // Check if this is a payment from savings
            if (isset($entry['is_payment_from_savings']) && $entry['is_payment_from_savings'] === true) {
                $display_method = 'Payment from Savings';
                $processed_entry['is_payment_from_savings'] = true;
            } elseif (in_array($payment_method, ['cash', 'cash payment'])) {
                $display_method = 'Cash Payment';
            } elseif (in_array($payment_method, ['gcash', 'gcash payment'])) {
                $display_method = 'GCash Payment';
            } else {
                $display_method = 'Others';
            }
            
            $processed_entry['payment_method_display'] = $display_method;
            // Format amount with payment method in parentheses
            $processed_entry['amount_formatted'] = number_format($entry['amount'], 2) . ' (' . $display_method . ')';
        }
        
        $processed_history[] = $processed_entry;
    }
    
    // Format dates in loan object
    $loan['start_date'] = formatDatetime($loan['start_date']);
    $loan['end_date'] = formatDateOnly($loan['end_date']);
    $loan['registration_date'] = formatDatetime($loan['created_at'] ?? $loan['start_date']);
    
    // Format penalty dates
    foreach ($loan['penalties'] as &$penalty) {
        $penalty['date'] = formatDatetime($penalty['full_datetime']);
        $penalty['full_datetime'] = formatDatetime($penalty['full_datetime']);
    }
    
    // Format savings deposit dates
    foreach ($loan['savings_deposits'] as &$deposit) {
        $deposit['transaction_date'] = formatDatetime($deposit['transaction_date']);
    }
    
    // Set the processed history
    $loan['payments'] = $processed_history;
    
    echo json_encode($loan);
    
} catch(PDOException $e) {
    header("HTTP/1.1 500 Internal Server Error");
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} catch(Exception $e) {
    header("HTTP/1.1 404 Not Found");
    echo json_encode(['error' => $e->getMessage()]);
}
?>