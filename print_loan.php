<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['logged_in'])) {
    header("Location: index.php");
    exit();
}

// Check if loan ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid loan ID");
}

$loan_id = intval($_GET['id']);

// Database configuration
require 'includes/db_config.php';

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
    
    // Get loan details with penalty included in calculations
    $stmt = $conn->prepare("
        SELECT l.*, 
               (SELECT SUM(amount) FROM payments WHERE loan_id = l.id) as total_paid,
               ((l.amount_financed + l.interest_peso + l.penalty) - (SELECT IFNULL(SUM(amount), 0) FROM payments WHERE loan_id = l.id)) as remaining_balance,
               c.first_name AS collector_first_name, c.last_name AS collector_last_name,
               cust.first_name AS customer_first_name, cust.last_name AS customer_last_name,
               cust.middle_name AS customer_middle_name, cust.suffix AS customer_suffix,
               cust.address AS customer_address, cust.contact_number AS customer_contact,
               cust.notes AS customer_notes,
               cust.area AS customer_area
        FROM financing l
        LEFT JOIN collector_accounts c ON l.collector_id = c.id
        LEFT JOIN customer_accounts cust ON l.customer_id = cust.id
        WHERE l.id = :loan_id
    ");
    $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
    $stmt->execute();
    $loan = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$loan) {
        die("Loan not found");
    }
    
    // Calculate total amount (amount_financed + interest_peso + penalty)
    $total_amount = $loan['amount_financed'] + $loan['interest_peso'] + $loan['penalty'];
    $paid_amount = $loan['total_paid'] ?? 0;
    $remaining_balance = $loan['remaining_balance'] ?? ($total_amount - $paid_amount);
    
    // Format customer name
    $customer_name = $loan['customer_last_name'] . ', ' . $loan['customer_first_name'];
    if (!empty($loan['customer_middle_name'])) {
        $customer_name .= ' ' . $loan['customer_middle_name'];
    }
    if (!empty($loan['customer_suffix']) && $loan['customer_suffix'] !== 'None') {
        $customer_name .= ', ' . $loan['customer_suffix'];
    }
    
    // Format collector name
    $collector_name = ($loan['collector_first_name'] ?? '') . ' ' . ($loan['collector_last_name'] ?? '');
    if (trim($collector_name) === '') {
        $collector_name = 'N/A';
    }
    
    // Get payment history for this loan with proxy information - ASCENDING for timeline
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
    $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
    $stmt->execute();
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get savings account ID for this loan
    $stmt = $conn->prepare("
        SELECT id, current_balance FROM savings_accounts 
        WHERE loan_id = :loan_id 
        AND status != 'Closed'
        LIMIT 1
    ");
    $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
    $stmt->execute();
    $savings_account = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $savings_account_id = $savings_account['id'] ?? null;
    $initial_savings_balance = floatval($savings_account['current_balance'] ?? 0);
    
    // Get ALL DEPOSIT transactions for timeline calculations
    $all_deposit_transactions_asc = [];
    $all_withdrawal_transactions_asc = [];
    $deposits_by_datetime = [];
    
    // Get ALL savings transactions for display
    $savings_transactions_display = [];
    
    if ($savings_account_id) {
        // Get ALL transactions in ASC order for timeline calculations
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
        
        // For display - ASC order (OLDEST TO NEWEST) for the separate table
        $stmt_display = $conn->prepare("
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
        $stmt_display->bindParam(':savings_account_id', $savings_account_id);
        $stmt_display->execute();
        
        $savings_transactions_display = $stmt_display->fetchAll(PDO::FETCH_ASSOC);
        
        // Create a map of deposits by exact datetime
        foreach ($all_deposit_transactions_asc as $deposit) {
            $deposit_datetime = date('Y-m-d H:i:s', strtotime($deposit['transaction_date']));
            $deposits_by_datetime[$deposit_datetime] = [
                'amount' => floatval($deposit['amount']),
                'data' => $deposit
            ];
        }
    }
    
    // Calculate base loan amount (amount_financed + interest_peso) - WITHOUT penalty initially
    $base_loan_amount = (floatval($loan['amount_financed']) + floatval($loan['interest_peso']));
    $total_penalties = floatval($loan['penalty']);
    
    // Get penalties for this loan
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
    $penalties = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
    foreach ($penalties as $penalty) {
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
        
        foreach ($events as $event) {
            switch ($event['type']) {
                case 'payment':
                    $payment_amount += $event['amount'];
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
        
        // IMPORTANT: Payments made FROM savings also decrease the savings balance
        if ($payment_from_savings_amount > 0) {
            $current_savings_balance -= $payment_from_savings_amount;
        }
        
        // Store the balance after processing all events at this datetime
        $balance_at_date[$datetime] = max(0, $current_loan_balance);
        $savings_balance_at_date[$datetime] = max(0, $current_savings_balance);
        
        // Store processed events for combined history
        $processed_events_by_datetime[$datetime] = [
            'payment_amount' => $payment_amount,
            'penalty_amount' => $penalty_amount,
            'deposit_amount' => $deposit_amount,
            'withdrawal_amount' => $withdrawal_amount,
            'payment_from_savings_amount' => $payment_from_savings_amount,
            'payment_data' => $payment_data,
            'penalty_data' => $penalty_data,
            'deposit_data' => $deposit_data,
            'withdrawal_data' => $withdrawal_data,
            'event_types' => $event_types,
            'loan_balance' => $balance_at_date[$datetime],
            'savings_balance' => $savings_balance_at_date[$datetime]
        ];
    }
    
    // Create combined history array (ASC order for display)
    $combined_history = [];
    
    foreach ($processed_events_by_datetime as $datetime => $entry) {
        $loan_balance = $entry['loan_balance'];
        $savings_balance = $entry['savings_balance'];
        
        // Determine event type
        $has_payment = $entry['payment_amount'] > 0;
        $has_penalty = $entry['penalty_amount'] > 0;
        $has_deposit = $entry['deposit_amount'] > 0;
        $has_withdrawal = $entry['withdrawal_amount'] > 0;
        $has_payment_from_savings = $entry['payment_from_savings_amount'] > 0;
        
        if ($has_payment && $has_penalty && $has_deposit) {
            $type = 'payment_with_penalty_and_deposit';
        } elseif ($has_payment && $has_penalty) {
            $type = 'payment_with_penalty';
        } elseif ($has_payment && $has_deposit) {
            $type = 'both';
        } elseif ($has_penalty) {
            $type = 'penalty';
        } elseif ($has_deposit) {
            $type = 'deposit';
        } elseif ($has_withdrawal) {
            $type = 'withdrawal';
        } else {
            $type = 'payment';
        }
        
        $show_actions = $entry['payment_amount'] > 0;
        
        switch ($type) {
            case 'payment':
                $combined_history[] = [
                    'type' => 'payment',
                    'id' => $entry['payment_data']['id'],
                    'date' => $datetime,
                    'amount' => $entry['payment_amount'],
                    'penalty_amount' => 0.00,
                    'savings_amount' => 0.00,
                    'savings_balance_total' => $savings_balance,
                    'payment_method' => $entry['payment_data']['payment_method'] ?? '',
                    'notes' => $entry['payment_data']['notes'] ?? '',
                    'collector_first_name' => $entry['payment_data']['collector_first_name'] ?? '',
                    'collector_last_name' => $entry['payment_data']['collector_last_name'] ?? '',
                    'proxy_name' => $entry['payment_data']['proxy_name'] ?? '',
                    'loan_balance' => $loan_balance,
                    'is_penalty' => false,
                    'is_deposit' => false,
                    'is_withdrawal' => false,
                    'is_payment_from_savings' => $has_payment_from_savings,
                    'show_actions' => $show_actions
                ];
                break;
                
            case 'penalty':
                $combined_history[] = [
                    'type' => 'penalty',
                    'id' => 'penalty_' . $entry['penalty_data']['id'],
                    'date' => $datetime,
                    'amount' => 0.00,
                    'penalty_amount' => $entry['penalty_amount'],
                    'savings_amount' => 0.00,
                    'savings_balance_total' => $savings_balance,
                    'payment_method' => 'Penalty',
                    'notes' => $entry['penalty_data']['notes'] ?? '',
                    'collector_first_name' => $entry['penalty_data']['collector_first_name'] ?? '',
                    'collector_last_name' => $entry['penalty_data']['collector_last_name'] ?? '',
                    'proxy_name' => 'N/A',
                    'loan_balance' => $loan_balance,
                    'is_penalty' => true,
                    'is_deposit' => false,
                    'is_withdrawal' => false,
                    'is_payment_from_savings' => false,
                    'show_actions' => false
                ];
                break;
                
            case 'deposit':
                $combined_history[] = [
                    'type' => 'deposit',
                    'id' => 'deposit_' . $entry['deposit_data']['id'],
                    'date' => $datetime,
                    'amount' => 0.00,
                    'penalty_amount' => 0.00,
                    'savings_amount' => $entry['deposit_amount'],
                    'savings_balance_total' => $savings_balance,
                    'payment_method' => 'Deposit',
                    'notes' => $entry['deposit_data']['notes'] ?? '',
                    'collector_first_name' => $entry['deposit_data']['collector_first_name'] ?? '',
                    'collector_last_name' => $entry['deposit_data']['collector_last_name'] ?? '',
                    'proxy_name' => $entry['deposit_data']['proxy_name'] ?? 'N/A',
                    'loan_balance' => $loan_balance,
                    'is_penalty' => false,
                    'is_deposit' => true,
                    'is_withdrawal' => false,
                    'is_payment_from_savings' => false,
                    'show_actions' => false
                ];
                break;
                
            case 'withdrawal':
                $combined_history[] = [
                    'type' => 'withdrawal',
                    'id' => 'withdrawal_' . $entry['withdrawal_data']['id'],
                    'date' => $datetime,
                    'amount' => 0.00,
                    'penalty_amount' => 0.00,
                    'savings_amount' => -$entry['withdrawal_amount'],
                    'savings_balance_total' => $savings_balance,
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
                break;
                
            case 'both':
                $combined_history[] = [
                    'type' => 'both',
                    'id' => $entry['payment_data']['id'],
                    'date' => $datetime,
                    'amount' => $entry['payment_amount'],
                    'penalty_amount' => 0.00,
                    'savings_amount' => $entry['deposit_amount'],
                    'savings_balance_total' => $savings_balance,
                    'payment_method' => $entry['payment_data']['payment_method'] ?? '',
                    'notes' => $entry['payment_data']['notes'] ?? '',
                    'collector_first_name' => $entry['payment_data']['collector_first_name'] ?? '',
                    'collector_last_name' => $entry['payment_data']['collector_last_name'] ?? '',
                    'proxy_name' => $entry['payment_data']['proxy_name'] ?? '',
                    'loan_balance' => $loan_balance,
                    'is_penalty' => false,
                    'is_deposit' => false,
                    'is_withdrawal' => false,
                    'has_deposit' => true,
                    'is_payment_from_savings' => $has_payment_from_savings,
                    'show_actions' => $show_actions
                ];
                break;
                
            case 'payment_with_penalty':
                $combined_history[] = [
                    'type' => 'payment_with_penalty',
                    'id' => $entry['payment_data']['id'],
                    'date' => $datetime,
                    'amount' => $entry['payment_amount'],
                    'penalty_amount' => $entry['penalty_amount'],
                    'savings_amount' => 0.00,
                    'savings_balance_total' => $savings_balance,
                    'payment_method' => $entry['payment_data']['payment_method'] ?? '',
                    'notes' => ($entry['payment_data']['notes'] ?? '') . ' | Penalty: ' . ($entry['penalty_data']['notes'] ?? ''),
                    'collector_first_name' => $entry['payment_data']['collector_first_name'] ?? '',
                    'collector_last_name' => $entry['payment_data']['collector_last_name'] ?? '',
                    'proxy_name' => $entry['payment_data']['proxy_name'] ?? '',
                    'loan_balance' => $loan_balance,
                    'is_penalty' => false,
                    'is_deposit' => false,
                    'is_withdrawal' => false,
                    'is_payment_from_savings' => $has_payment_from_savings,
                    'show_actions' => $show_actions
                ];
                break;
                
            case 'payment_with_penalty_and_deposit':
                $combined_history[] = [
                    'type' => 'payment_with_penalty_and_deposit',
                    'id' => $entry['payment_data']['id'],
                    'date' => $datetime,
                    'amount' => $entry['payment_amount'],
                    'penalty_amount' => $entry['penalty_amount'],
                    'savings_amount' => $entry['deposit_amount'],
                    'savings_balance_total' => $savings_balance,
                    'payment_method' => $entry['payment_data']['payment_method'] ?? '',
                    'notes' => ($entry['payment_data']['notes'] ?? '') . ' | Penalty: ' . ($entry['penalty_data']['notes'] ?? ''),
                    'collector_first_name' => $entry['payment_data']['collector_first_name'] ?? '',
                    'collector_last_name' => $entry['payment_data']['collector_last_name'] ?? '',
                    'proxy_name' => $entry['payment_data']['proxy_name'] ?? '',
                    'loan_balance' => $loan_balance,
                    'is_penalty' => false,
                    'is_deposit' => false,
                    'is_withdrawal' => false,
                    'has_deposit' => true,
                    'is_payment_from_savings' => $has_payment_from_savings,
                    'show_actions' => $show_actions
                ];
                break;
        }
    }
    
    // Sort combined history by date (ASC order - oldest first for display)
    usort($combined_history, function($a, $b) {
        return strtotime($a['date']) - strtotime($b['date']);
    });
    
    // Process combined history for final output
    $processed_history = [];
    foreach ($combined_history as $entry) {
        $processed_entry = [
            'id' => $entry['id'],
            'payment_date' => $entry['date'],
            'amount' => $entry['amount'],
            'penalty_amount' => $entry['penalty_amount'],
            'savings_amount' => $entry['savings_amount'],
            'savings_balance' => $entry['savings_balance_total'],
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
            'show_actions' => $entry['show_actions'] ?? false
        ];
        
        // Set display text
        if ($entry['type'] === 'penalty') {
            $processed_entry['payment_method_display'] = 'Penalty Added';
            $processed_entry['amount'] = 0.00;
        } elseif ($entry['is_deposit'] ?? false) {
            $processed_entry['payment_method_display'] = 'Savings Deposit';
            $processed_entry['amount'] = 0.00;
        } elseif ($entry['is_withdrawal'] ?? false) {
            $processed_entry['payment_method_display'] = 'Savings Withdrawal';
            $processed_entry['amount'] = 0.00;
        } elseif ($entry['has_deposit'] ?? false) {
            $processed_entry['payment_method_display'] = 'Payment + Deposit';
        } else {
            $payment_method = strtolower($entry['payment_method']);
            // Check if this is a payment from savings
            if (stripos($entry['notes'] ?? '', 'payment from savings') !== false) {
                $processed_entry['payment_method_display'] = 'Payment from Savings';
                $processed_entry['is_payment_from_savings'] = true;
            } elseif (in_array($payment_method, ['cash', 'cash payment'])) {
                $processed_entry['payment_method_display'] = 'Cash Payment';
            } elseif (in_array($payment_method, ['gcash', 'gcash payment'])) {
                $processed_entry['payment_method_display'] = 'GCash Payment';
            } else {
                $processed_entry['payment_method_display'] = 'Others';
            }
        }
        
        $processed_history[] = $processed_entry;
    }
    
    // Set the processed history as loan payments
    $payments_desc = $processed_history;
    
    // Calculate progress percentage
    $progress_percentage = $total_amount > 0 ? ($paid_amount / $total_amount) * 100 : 0;
    
} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Details - Prime City</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @page {
            size: A4;
            margin: 10mm;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 12px;
            background-color: #fff;
            color: #000;
            padding: 0;
            margin: 0;
        }
        
        .container {
            width: 100%;
            max-width: 100%;
            padding: 0;
        }
        
        .loan-summary {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .summary-item {
            text-align: center;
            padding: 10px;
            background: white;
            border: 1px solid #ddd;
            border-radius: 6px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .summary-item h4 {
            font-size: 11px;
            margin-bottom: 5px;
            color: #6c757d;
            text-transform: uppercase;
            font-weight: 600;
        }
        
        .summary-item p {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 0;
        }
        
        .progress-container {
            background: white;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .progress {
            height: 20px;
            background-color: #e9ecef;
            border-radius: 10px;
            overflow: hidden;
        }
        
        .progress-bar {
            height: 100%;
            background: linear-gradient(45deg, #28a745, #20c997);
            text-align: center;
            line-height: 20px;
            color: white;
            font-weight: bold;
            font-size: 11px;
            transition: width 0.6s ease;
        }
        
        .details-section {
            margin-bottom: 20px;
            page-break-inside: avoid;
        }
        
        .section-title {
            font-size: 14px;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 2px solid #3498db;
            text-transform: uppercase;
        }
        
        .details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 15px;
        }
        
        .detail-card {
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 12px;
            background: white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .detail-row {
            display: flex;
            margin-bottom: 8px;
            padding-bottom: 5px;
            border-bottom: 1px dashed #f1f1f1;
        }
        
        .detail-row:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }
        
        .detail-label {
            font-weight: 600;
            width: 45%;
            color: #495057;
            font-size: 11px;
        }
        
        .detail-value {
            width: 55%;
            font-size: 11px;
            color: #212529;
        }
        
        .table-responsive {
            margin-top: 10px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
            margin-bottom: 15px;
        }
        
        th {
            background: linear-gradient(135deg, #3498db, #2980b9);
            color: white;
            font-weight: 600;
            padding: 8px 6px;
            text-align: left;
            font-size: 10px;
        }
        
        td {
            padding: 6px;
            border: 1px solid #dee2e6;
            font-size: 10px;
        }
        
        tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        
        .payment-cash {
            color: #28a745 !important;
            font-weight: bold;
        }
        
        .payment-gcash {
            color: #17a2b8 !important;
            font-weight: bold;
        }
        
        .payment-savings {
            color: #ffc107 !important;
            font-weight: bold;
        }
        
        .payment-other {
            color: #6c757d !important;
            font-weight: bold;
        }
        
        .text-danger {
            color: #dc3545 !important;
            font-weight: bold;
        }
        
        .text-warning {
            color: #ffc107 !important;
            font-weight: bold;
        }
        
        .text-info {
            color: #17a2b8 !important;
            font-weight: bold;
        }
        
        .badge {
            font-size: 10px;
            padding: 4px 8px;
            border-radius: 4px;
        }
        
        .badge-success {
            background-color: #28a745;
        }
        
        .badge-danger {
            background-color: #dc3545;
        }
        
        .badge-warning {
            background-color: #ffc107;
            color: #212529;
        }
        
        .badge-info {
            background-color: #17a2b8;
        }
        
        .badge-secondary {
            background-color: #6c757d;
        }
        
        .print-footer {
            margin-top: 25px;
            padding-top: 10px;
            border-top: 2px solid #333;
            text-align: center;
            font-size: 10px;
            color: #6c757d;
        }
        
        .no-print {
            display: none;
        }
        
        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 80px;
            color: rgba(0,0,0,0.1);
            z-index: -1;
            font-weight: bold;
            pointer-events: none;
        }
        
        @media print {
            .no-print {
                display: none !important;
            }
            
            body {
                padding: 0;
                margin: 0;
                background: white;
            }
            
            .container {
                padding: 0;
                margin: 0;
            }
            
            .loan-summary, .detail-card {
                box-shadow: none;
                border: 1px solid #ccc;
            }
            
            .watermark {
                display: block;
            }
            
            .progress-bar {
                background: #28a745 !important;
                -webkit-print-color-adjust: exact;
                color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .badge-success {
                background-color: #28a745 !important;
                -webkit-print-color-adjust: exact;
                color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .badge-danger {
                background-color: #dc3545 !important;
                -webkit-print-color-adjust: exact;
                color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .badge-warning {
                background-color: #ffc107 !important;
                -webkit-print-color-adjust: exact;
                color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .badge-info {
                background-color: #17a2b8 !important;
                -webkit-print-color-adjust: exact;
                color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .badge-secondary {
                background-color: #6c757d !important;
                -webkit-print-color-adjust: exact;
                color-adjust: exact;
                print-color-adjust: exact;
            }
            
            th {
                background: #3498db !important;
                -webkit-print-color-adjust: exact;
                color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .payment-cash {
                color: #28a745 !important;
                -webkit-print-color-adjust: exact;
                color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .payment-gcash {
                color: #17a2b8 !important;
                -webkit-print-color-adjust: exact;
                color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .payment-savings {
                color: #ffc107 !important;
                -webkit-print-color-adjust: exact;
                color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .payment-other {
                color: #6c757d !important;
                -webkit-print-color-adjust: exact;
                color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .text-danger {
                color: #dc3545 !important;
                -webkit-print-color-adjust: exact;
                color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .text-warning {
                color: #ffc107 !important;
                -webkit-print-color-adjust: exact;
                color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .text-info {
                color: #17a2b8 !important;
                -webkit-print-color-adjust: exact;
                color-adjust: exact;
                print-color-adjust: exact;
            }
        }
        
        @media screen {
            body {
                padding: 20px;
                background-color: #f8f9fa;
            }
            
            .container {
                background-color: white;
                padding: 25px;
                box-shadow: 0 4px 15px rgba(0,0,0,0.1);
                border-radius: 10px;
                max-width: 1200px;
                margin: 0 auto;
            }
            
            .watermark {
                display: none;
            }
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .btn {
            font-size: 12px;
            padding: 8px 15px;
            border-radius: 6px;
            font-weight: 500;
        }
        
        .company-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding: 10px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 6px;
            border: 1px solid #dee2e6;
        }
        
        .company-logo {
            flex: 1;
            text-align: left;
        }
        
        .document-info {
            flex: 2;
            text-align: center;
        }
        
        .print-info {
            flex: 1;
            text-align: right;
            font-size: 10px;
            color: #6c757d;
        }
        
        .legend {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin: 10px 0;
            flex-wrap: wrap;
            font-size: 10px;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .legend-color {
            width: 12px;
            height: 12px;
            border-radius: 3px;
            display: inline-block;
        }
        
        .legend-cash {
            background-color: #28a745;
        }
        
        .legend-gcash {
            background-color: #17a2b8;
        }
        
        .legend-savings {
            background-color: #ffc107;
        }
        
        .legend-other {
            background-color: #6c757d;
        }
        
        .withdrawal-amount {
            color: #dc3545 !important;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="watermark">PRIME CITY</div>
    
    <div class="container">
        <!-- Company Header -->
        <div class="company-info">
            <div class="company-logo">
                <strong>PrimeCity Finance Services</strong><br>
                <small>Your Trusted Financial Partner</small>
            </div>
            <div class="document-info">
                <h4 class="mb-1">LOAN DETAILS REPORT</h4>
                <p class="mb-0">Loan Account Statement</p>
            </div>
            <div class="print-info">
                Printed on: <span id="print-date"></span><br>
                Loan ID: <?php echo htmlspecialchars($loan['id']); ?>
            </div>
        </div>
        
        <!-- Action buttons (visible only on screen) -->
        <div class="action-buttons no-print">
            <button onclick="window.print()" class="btn btn-primary">
                <i class="fas fa-print"></i> Print Report
            </button>
            <button onclick="window.close()" class="btn btn-secondary">
                <i class="fas fa-times"></i> Close Window
            </button>
            <a href="customer_view_payment.php?id=<?php echo $loan['customer_id']; ?>" class="btn btn-info">
                <i class="fas fa-arrow-left"></i> Back to Customer
            </a>
        </div>
        
        <!-- Loan Summary Section -->
        <div class="loan-summary">
            <div class="summary-grid">
                <div class="summary-item">
                    <h4>Total Loan Amount</h4>
                    <p class="text-info">₱<?php echo number_format($total_amount, 2); ?></p>
                </div>
                <div class="summary-item">
                    <h4>Amount Paid</h4>
                    <p class="text-success">₱<?php echo number_format($paid_amount, 2); ?></p>
                </div>
                <div class="summary-item">
                    <h4>Remaining Balance</h4>
                    <p class="text-danger">₱<?php echo number_format($remaining_balance, 2); ?></p>
                </div>
                <div class="summary-item">
                    <h4>Total Penalty</h4>
                    <p class="text-warning">₱<?php echo number_format($loan['penalty'], 2); ?></p>
                </div>
                <?php if ($savings_account && $savings_account['current_balance'] > 0): ?>
                <div class="summary-item">
                    <h4>Savings Balance</h4>
                    <p class="text-success">₱<?php echo number_format($savings_account['current_balance'], 2); ?></p>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Progress Bar -->
            <div class="progress-container">
                <div class="d-flex justify-content-between mb-2">
                    <span>Repayment Progress</span>
                    <span><?php echo number_format(min($progress_percentage, 100), 1); ?>%</span>
                </div>
                <div class="progress">
                    <div class="progress-bar" role="progressbar" 
                         style="width: <?php echo min($progress_percentage, 100); ?>%" 
                         aria-valuenow="<?php echo $progress_percentage; ?>" 
                         aria-valuemin="0" 
                         aria-valuemax="100">
                        <?php echo number_format(min($progress_percentage, 100), 1); ?>%
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Loan and Customer Details -->
        <div class="details-section">
            <h3 class="section-title">Loan & Customer Information</h3>
            <div class="details-grid">
                <!-- Loan Information -->
                <div class="detail-card">
                    <h5 class="mb-3" style="color: #3498db; border-bottom: 1px solid #3498db; padding-bottom: 5px;">
                        <i class="fas fa-file-invoice-dollar me-2"></i>Loan Information
                    </h5>
                    <div class="detail-row">
                        <div class="detail-label">Loan ID:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($loan['id']); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Status:</div>
                        <div class="detail-value">
                            <?php 
                                $status_class = '';
                                if ($loan['status'] === 'active') {
                                    $status_class = 'badge-success';
                                    echo '<span class="badge ' . $status_class . '">Active</span>';
                                } elseif ($loan['status'] === 'completed') {
                                    $status_class = 'badge-info';
                                    echo '<span class="badge ' . $status_class . '">Completed</span>';
                                } elseif ($loan['status'] === 'defaulted') {
                                    $status_class = 'badge-danger';
                                    echo '<span class="badge ' . $status_class . '">Defaulted</span>';
                                } else {
                                    echo '<span class="badge badge-secondary">' . htmlspecialchars(ucfirst($loan['status'])) . '</span>';
                                }
                            ?>
                        </div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Start Date:</div>
                        <div class="detail-value"><?php echo formatDatetime($loan['start_date']); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">End Date:</div>
                        <div class="detail-value"><?php echo formatDateOnly($loan['end_date']); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Term:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($loan['loan_term']); ?> days</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Daily Payment:</div>
                        <div class="detail-value">₱<?php echo number_format($loan['daily_payment'], 2); ?></div>
                    </div>
                </div>
                
                <!-- Financial Details -->
                <div class="detail-card">
                    <h5 class="mb-3" style="color: #e74c3c; border-bottom: 1px solid #e74c3c; padding-bottom: 5px;">
                        <i class="fas fa-chart-line me-2"></i>Financial Details
                    </h5>
                    <div class="detail-row">
                        <div class="detail-label">Amount Financed:</div>
                        <div class="detail-value">₱<?php echo number_format($loan['amount_financed'], 2); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Interest (Peso):</div>
                        <div class="detail-value">₱<?php echo number_format($loan['interest_peso'], 2); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Interest Rate:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($loan['interest']); ?>%</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Service Fee:</div>
                        <div class="detail-value">₱<?php echo number_format($loan['service_fee'], 2); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Penalty Amount:</div>
                        <div class="detail-value text-warning">₱<?php echo number_format($loan['penalty'], 2); ?></div>
                    </div>
                </div>
                
                <!-- Customer Information -->
                <div class="detail-card">
                    <h5 class="mb-3" style="color: #27ae60; border-bottom: 1px solid #27ae60; padding-bottom: 5px;">
                        <i class="fas fa-user me-2"></i>Customer Information
                    </h5>
                    <div class="detail-row">
                        <div class="detail-label">Name:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($customer_name); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Contact:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($loan['customer_contact']); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Address:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($loan['customer_address']); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Area:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($loan['customer_area'] ?? 'N/A'); ?></div>
                    </div>
                </div>
                
                <!-- Collector Information -->
                <div class="detail-card">
                    <h5 class="mb-3" style="color: #9b59b6; border-bottom: 1px solid #9b59b6; padding-bottom: 5px;">
                        <i class="fas fa-user-tie me-2"></i>Collector Information
                    </h5>
                    <div class="detail-row">
                        <div class="detail-label">Name:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($collector_name); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Customer Notes:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($loan['customer_notes'] ?: 'No notes available'); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Loan Notes:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($loan['notes'] ?: 'No notes available'); ?></div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Payment History -->
        <div class="details-section">
            <h3 class="section-title">Payment History</h3>
            
            <!-- Payment Method Legend -->
            <div class="legend no-print">
                <div class="legend-item">
                    <span class="legend-color legend-cash"></span>
                    <span>Cash Payment</span>
                </div>
                <div class="legend-item">
                    <span class="legend-color legend-savings"></span>
                    <span>Payment from Savings</span>
                </div>
                <div class="legend-item">
                    <span class="legend-color legend-gcash"></span>
                    <span>GCash Payment</span>
                </div>
                <div class="legend-item">
                    <span class="legend-color legend-other"></span>
                    <span>Other Methods</span>
                </div>
            </div>
            
            <?php if (count($payments_desc) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Penalty</th>
                                <th>Savings Amount</th>
                                <th>Savings Balance</th>
                                <th>Loan Balance</th>
                                <th>Payment Method</th>
                                <th>Collector</th>
                                <th>Proxy</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments_desc as $payment): 
                                // Determine CSS class based on payment method
                                $payment_class = '';
                                $payment_method = strtolower($payment['payment_method']);
                                $notes = strtolower($payment['notes'] ?? '');
                                
                                // Check if notes contain "payment from savings" for ALL payment methods
                                $is_from_savings = $payment['is_payment_from_savings'] || (strpos($notes, 'payment from savings') !== false);
                                
                                // Apply different colors based on payment method AND source
                                if ($payment['is_deposit']) {
                                    $payment_class = 'payment-savings';
                                } elseif ($payment['is_withdrawal']) {
                                    $payment_class = 'withdrawal-amount';
                                } elseif ($is_from_savings) {
                                    $payment_class = 'payment-savings';
                                } elseif (in_array($payment_method, ['cash', 'cash payment'])) {
                                    $payment_class = 'payment-cash';
                                } elseif (in_array($payment_method, ['gcash', 'gcash payment'])) {
                                    $payment_class = 'payment-gcash';
                                } else {
                                    $payment_class = 'payment-other';
                                }
                                
                                // Format savings amount display (negative for withdrawals)
                                $savings_amount_display = '';
                                if ($payment['is_withdrawal']) {
                                    $savings_amount_display = '-₱' . number_format(abs($payment['savings_amount']), 2);
                                } elseif ($payment['is_deposit']) {
                                    $savings_amount_display = '₱' . number_format($payment['savings_amount'], 2);
                                } elseif ($payment['savings_amount'] > 0) {
                                    $savings_amount_display = '₱' . number_format($payment['savings_amount'], 2);
                                } else {
                                    $savings_amount_display = '-';
                                }
                            ?>
                                <tr>
                                    <td><?php echo formatDatetime($payment['payment_date']); ?></td>
                                    <?php if ($payment['is_deposit'] || $payment['is_withdrawal'] || $payment['is_penalty']): ?>
                                        <td class="<?php echo $payment_class; ?> fst-italic">-</td>
                                    <?php else: ?>
                                        <td class="<?php echo $payment_class; ?>">₱<?php echo number_format($payment['amount'], 2); ?></td>
                                    <?php endif; ?>
                                    <td class="<?php echo $payment['penalty_amount'] > 0 ? 'text-danger fw-bold' : 'text-muted'; ?>">
                                        <?php echo $payment['penalty_amount'] > 0 ? '₱' . number_format($payment['penalty_amount'], 2) : '₱0.00'; ?>
                                    </td>
                                    <td class="<?php echo $payment_class; ?>"><?php echo $savings_amount_display; ?></td>
                                    <td class="fw-bold">₱<?php echo number_format($payment['savings_balance'], 2); ?></td>
                                    <td class="text-danger fw-bold">₱<?php echo number_format($payment['loan_balance'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($payment['payment_method_display'] ?: 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars(($payment['collector_first_name'] ?? '') . ' ' . ($payment['collector_last_name'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars($payment['proxy_name'] ?: 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($payment['notes'] ?: 'None'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <!-- Total Row -->
                            <tr style="background-color: #e3f2fd; font-weight: bold;">
                                <td colspan="1" class="text-end">Total Paid:</td>
                                <td class="payment-cash">₱<?php echo number_format($paid_amount, 2); ?></td>
                                <td colspan="8"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle me-2"></i>
                    No payment history recorded for this loan.
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Savings Transaction History -->
        <?php if (isset($savings_transactions_display) && count($savings_transactions_display) > 0): ?>
        <div class="details-section">
            <h3 class="section-title">Savings Transaction History</h3>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Transaction Type</th>
                            <th>Amount</th>
                            <th>Payment Method</th>
                            <th>Collector</th>
                            <th>Proxy</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($savings_transactions_display as $transaction): ?>
                            <tr>
                                <td><?php echo formatDatetime($transaction['transaction_date']); ?></td>
                                <td>
                                    <?php if ($transaction['transaction_type'] == 'Deposit'): ?>
                                        <span class="badge badge-success">Deposit</span>
                                    <?php elseif ($transaction['transaction_type'] == 'Withdrawal Cash'): ?>
                                        <span class="badge badge-warning">Withdrawal</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary"><?php echo htmlspecialchars($transaction['transaction_type']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="<?php echo $transaction['transaction_type'] == 'Deposit' ? 'text-success' : 'text-danger'; ?> fw-bold">
                                    <?php echo $transaction['transaction_type'] == 'Deposit' ? '₱' : '-₱'; ?><?php echo number_format($transaction['amount'], 2); ?>
                                </td>
                                <td><?php echo htmlspecialchars($transaction['payment_method'] ?: 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars(($transaction['collector_first_name'] ?? '') . ' ' . ($transaction['collector_last_name'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars($transaction['proxy_name'] ?: 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($transaction['notes'] ?: 'None'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Penalties History -->
        <div class="details-section">
            <h3 class="section-title">Penalties History</h3>
            <?php if (count($penalties) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($penalties as $penalty): ?>
                                <tr>
                                    <td><?php echo formatDatetime($penalty['full_datetime']); ?></td>
                                    <td>
                                        <?php if ($penalty['type'] === 'added'): ?>
                                            <span class="badge badge-success">Added</span>
                                        <?php elseif ($penalty['type'] === 'waived'): ?>
                                            <span class="badge badge-danger">Waived</span>
                                        <?php elseif ($penalty['type'] === 'partial waiver'): ?>
                                            <span class="badge badge-warning">Partial Waiver</span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary"><?php echo htmlspecialchars($penalty['type']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="<?php echo $penalty['type'] === 'added' ? 'text-success' : 'text-danger'; ?> fw-bold">
                                        ₱<?php echo number_format($penalty['amount'], 2); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($penalty['notes'] ?: 'None'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <!-- Total Row -->
                            <tr style="background-color: #fff3cd; font-weight: bold;">
                                <td colspan="2" class="text-end">Total Penalties:</td>
                                <td class="text-warning">₱<?php echo number_format($loan['penalty'], 2); ?></td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle me-2"></i>
                    No penalties recorded for this loan.
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Print Footer -->
        <div class="print-footer">
            <p><strong>PrimeCity Finance Services</strong> | Your Trusted Financial Partner</p>
            <p>This is a computer-generated document. No signature is required.</p>
            <p class="mb-0">&copy; <?php echo date('Y'); ?> PrimeCity Finance Services. All rights reserved.</p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function formatPrintDate(date) {
            const months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
            const month = months[date.getMonth()];
            const day = date.getDate();
            const year = date.getFullYear();
            
            let hours = date.getHours();
            const minutes = date.getMinutes().toString().padStart(2, '0');
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12;
            hours = hours ? hours : 12;
            
            return `${month} ${day}, ${year} ${hours}:${minutes} ${ampm}`;
        }

        window.onload = function() {
            const now = new Date();
            document.getElementById('print-date').textContent = formatPrintDate(now);
            
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('autoprint') === '1') {
                window.print();
            }
        };

        window.onafterprint = function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('autoclose') === '1') {
                setTimeout(function() {
                    window.close();
                }, 500);
            }
        };
    </script>
</body>
</html>
