<?php
// Add error handling at the very top
error_reporting(0); // Turn off error display to prevent HTML output
ini_set('display_errors', 0);

session_start();

// Set JSON header immediately
header('Content-Type: application/json');

// Check if user is logged in and is admin/area manager
if (!isset($_SESSION['logged_in']) || !isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized: Please log in first'
    ]);
    exit();
}

// Check if user has permission (Admin or Area Manager)
require '../includes/db_config.php';

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $conn->prepare("SELECT account_type FROM accounts WHERE id = :id");
    $stmt->bindParam(':id', $_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user || !in_array($user['account_type'], ['Admin', 'Area Manager'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Unauthorized access. Only Admin and Area Managers can run this process.'
        ]);
        exit();
    }
} catch(PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
    exit();
}

/**
 * Function to log system activity
 */
function logSystemActivity($conn, $action, $details = '') {
    try {
        $stmt = $conn->prepare("
            INSERT INTO system_logs (user_id, action, details, created_at) 
            VALUES (:user_id, :action, :details, NOW())
        ");
        $stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->bindParam(':action', $action);
        $stmt->bindParam(':details', $details);
        $stmt->execute();
        return true;
    } catch (Exception $e) {
        error_log("Error logging system activity: " . $e->getMessage());
        return false;
    }
}

/**
 * Function to get the last penalty run timestamp
 */
function getLastPenaltyRun($conn) {
    $last_run = "Never run";
    
    $log_sql = "
        SELECT created_at FROM system_logs 
        WHERE action LIKE '%penalty%' OR action LIKE '%automatic penalty%'
        ORDER BY created_at DESC 
        LIMIT 1
    ";
    $stmt = $conn->prepare($log_sql);
    $stmt->execute();
    $log_result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($log_result && $log_result['created_at']) {
        $last_run = date('M j, Y g:i A', strtotime($log_result['created_at']));
    }
    
    return $last_run;
}

/**
 * Function to check if penalty already exists for current month
 */
function hasCurrentMonthPenalty($conn, $loan_id) {
    $stmt = $conn->prepare("
        SELECT id FROM penalties 
        WHERE loan_id = :loan_id 
        AND type = 'added' 
        AND YEAR(created_at) = YEAR(CURDATE()) 
        AND MONTH(created_at) = MONTH(CURDATE())
        LIMIT 1
    ");
    $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
}

/**
 * Function to check if automatic penalty is enabled for a loan
 */
function isAutomaticPenaltyEnabled($conn, $loan_id) {
    $stmt = $conn->prepare("
        SELECT automatic_penalty_enabled FROM financing 
        WHERE id = :loan_id
    ");
    $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // If automatic_penalty_enabled is NULL or 1, consider it enabled
    // If automatic_penalty_enabled is 0, consider it disabled
    return ($result && ($result['automatic_penalty_enabled'] === null || $result['automatic_penalty_enabled'] == 1));
}

/**
 * Function to format customer name from parts
 * Format: "Last Name, First Name Middle Name Suffix" (do not display "None" for suffix)
 */
function formatCustomerName($last_name, $first_name, $middle_name, $suffix) {
    $name_parts = [];
    
    // Add last name first
    if (!empty($last_name)) {
        $name_parts[] = $last_name;
    }
    
    // Add first name
    if (!empty($first_name)) {
        $name_parts[] = $first_name;
    }
    
    // Add middle name (full name, not just initial)
    if (!empty($middle_name)) {
        $name_parts[] = $middle_name;
    }
    
    // Add suffix only if it exists and is not "None"
    if (!empty($suffix) && strtoupper($suffix) !== 'NONE') {
        $name_parts[] = $suffix;
    }
    
    // Format as "Last Name, First Name Middle Name Suffix"
    if (count($name_parts) > 1) {
        $last_name_part = array_shift($name_parts);
        return $last_name_part . ', ' . implode(' ', $name_parts);
    } elseif (count($name_parts) === 1) {
        return $name_parts[0];
    }
    
    return 'Unknown Customer';
}

/**
 * Function to get customer name for a loan
 */
function getCustomerNameForLoan($conn, $customer_id) {
    $stmt = $conn->prepare("
        SELECT last_name, first_name, middle_name, suffix 
        FROM customer_accounts 
        WHERE id = :customer_id
    ");
    $stmt->bindParam(':customer_id', $customer_id, PDO::PARAM_INT);
    $stmt->execute();
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($customer) {
        return formatCustomerName(
            $customer['last_name'],
            $customer['first_name'],
            $customer['middle_name'],
            $customer['suffix']
        );
    }
    
    return null;
}

/**
 * Function to process automatic penalties for all overdue loans
 */
function processAllAutomaticPenalties($conn, $branch_filter = '') {
    $results = [
        'total_loans_processed' => 0,
        'penalties_added' => 0,
        'total_penalty_amount' => 0,
        'errors' => [],
        'detailed_results' => []
    ];
    
    try {
        // Build SQL query with optional branch filter - EXCLUDE loans that already have penalties for current month
        $sql = "
            SELECT DISTINCT l.*, 
                   c.area, c.branch,
                   ((l.amount_financed + l.interest_peso + l.penalty) - (SELECT IFNULL(SUM(amount), 0) FROM payments WHERE loan_id = l.id)) as remaining_balance
            FROM financing l
            JOIN customer_accounts c ON l.customer_id = c.id
            LEFT JOIN penalties p ON l.id = p.loan_id 
                AND p.type = 'added' 
                AND YEAR(p.created_at) = YEAR(CURDATE()) 
                AND MONTH(p.created_at) = MONTH(CURDATE())
            WHERE l.status = 'active' 
            AND l.end_date < CURDATE()
            AND p.id IS NULL  -- Exclude loans that already have penalty for current month
        ";
        
        // Add branch filter if provided and not 'all'
        if (!empty($branch_filter) && $branch_filter !== 'all') {
            $sql .= " AND c.branch = :branch";
        }
        
        $sql .= " ORDER BY l.end_date ASC";
        
        $stmt = $conn->prepare($sql);
        
        // Bind branch parameter if filter is provided and not 'all'
        if (!empty($branch_filter) && $branch_filter !== 'all') {
            $stmt->bindParam(':branch', $branch_filter);
        }
        
        $stmt->execute();
        $overdue_loans = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $results['total_loans_processed'] = count($overdue_loans);
        
        foreach ($overdue_loans as $loan) {
            $loan_id = intval($loan['id']);
            $customer_name = getCustomerNameForLoan($conn, $loan['customer_id']);
            
            $loan_result = [
                'loan_id' => $loan_id,
                'customer_id' => $loan['customer_id'],
                'customer_name' => $customer_name,
                'branch' => $loan['branch'],
                'end_date' => $loan['end_date'],
                'penalties_added' => 0,
                'penalty_amount' => 0,
                'automatic_penalty_disabled' => false,
                'errors' => []
            ];
            
            try {
                // Check if automatic penalty is enabled for this loan
                if (!isAutomaticPenaltyEnabled($conn, $loan_id)) {
                    $loan_result['automatic_penalty_disabled'] = true;
                    $loan_result['errors'][] = "Automatic penalty is disabled for this loan";
                    $results['detailed_results'][] = $loan_result;
                    continue; // Skip this loan
                }
                
                // Check again if penalty already exists for current month (double-check)
                if (hasCurrentMonthPenalty($conn, $loan_id)) {
                    $loan_result['errors'][] = "Loan already has penalty for current month";
                    $results['detailed_results'][] = $loan_result;
                    continue; // Skip this loan
                }
                
                $end_date = new DateTime($loan['end_date']);
                $today = new DateTime();
                
                // Check if loan is overdue (past end date)
                if ($today > $end_date) {
                    // Get all penalties for this loan to determine the last penalty month
                    $stmt = $conn->prepare("
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
                                $customer_area = $loan['area'] ?? null;
                                $customer_branch = $loan['branch'] ?? null;
                                
                                // Check if penalty already exists for this specific month
                                $stmt = $conn->prepare("
                                    SELECT id FROM penalties 
                                    WHERE loan_id = :loan_id 
                                    AND YEAR(created_at) = YEAR(:penalty_date)
                                    AND MONTH(created_at) = MONTH(:penalty_date)
                                    AND type = 'added'
                                ");
                                $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
                                $penalty_date_formatted = $penalty_date->format('Y-m-d');
                                $stmt->bindParam(':penalty_date', $penalty_date_formatted);
                                $stmt->execute();
                                $existing_penalty = $stmt->fetch(PDO::FETCH_ASSOC);
                                
                                if (!$existing_penalty) {
                                    // Insert penalty into penalties table with type='added', area and branch from customer
                                    $stmt = $conn->prepare("
                                        INSERT INTO penalties (loan_id, area, type, amount, branch, notes, created_by, created_at)
                                        VALUES (:loan_id, :area, 'added', :amount, :branch, :notes, :created_by, :penalty_date)
                                    ");
                                    
                                    $notes = "5% Monthly Penalty of remaining balance (Auto)";
                                    $created_by = $_SESSION['user_id'] ?? 1;
                                    
                                    $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
                                    $stmt->bindParam(':area', $customer_area);
                                    $stmt->bindParam(':amount', $penalty_amount);
                                    $stmt->bindParam(':branch', $customer_branch);
                                    $stmt->bindParam(':notes', $notes);
                                    $stmt->bindParam(':created_by', $created_by, PDO::PARAM_INT);
                                    $penalty_date_formatted = $penalty_date->format('Y-m-d');
                                    $stmt->bindParam(':penalty_date', $penalty_date_formatted);
                                    
                                    if ($stmt->execute()) {
                                        // Accumulate total penalty added
                                        $total_penalty_added += $penalty_amount;
                                        
                                        // Update the remaining balance for subsequent penalty calculations
                                        $remaining_balance += $penalty_amount;
                                        
                                        $loan_result['penalties_added']++;
                                        $loan_result['penalty_amount'] += $penalty_amount;
                                    }
                                }
                            }
                        }
                    }
                    
                    // Update total penalty in financing table if any penalties were added
                    if ($total_penalty_added > 0) {
                        $stmt = $conn->prepare("
                            UPDATE financing 
                            SET penalty = penalty + :penalty_amount,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE id = :loan_id
                        ");
                        $stmt->bindParam(':penalty_amount', $total_penalty_added);
                        $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
                        $stmt->execute();
                        
                        // Update the balance column to include the new penalty automatically
                        $stmt = $conn->prepare("
                            UPDATE financing 
                            SET balance = (amount_financed + interest_peso + penalty) - (SELECT IFNULL(SUM(amount), 0) FROM payments WHERE loan_id = :loan_id),
                                updated_at = CURRENT_TIMESTAMP
                            WHERE id = :loan_id
                        ");
                        $stmt->bindParam(':loan_id', $loan_id, PDO::PARAM_INT);
                        $stmt->execute();
                        
                        $results['penalties_added'] += $loan_result['penalties_added'];
                        $results['total_penalty_amount'] += $loan_result['penalty_amount'];
                    }
                }
                
            } catch (Exception $e) {
                $error_msg = "Error processing automatic penalty for loan $loan_id: " . $e->getMessage();
                $loan_result['errors'][] = $error_msg;
                $results['errors'][] = $error_msg;
                // Continue with other loans even if one fails
            }
            
            $results['detailed_results'][] = $loan_result;
        }
        
        return $results;
        
    } catch (Exception $e) {
        throw new Exception("Error in batch penalty processing: " . $e->getMessage());
    }
}

// Main execution
try {
    $start_time = microtime(true);
    
    // Get branch filter from POST data
    $branch_filter = isset($_POST['branch']) ? trim($_POST['branch']) : '';
    
    // Process all automatic penalties with optional branch filter
    $results = processAllAutomaticPenalties($conn, $branch_filter);
    
    // Log the penalty run activity
    $log_details = "Processed {$results['total_loans_processed']} loans, added {$results['penalties_added']} penalties totaling ₱" . 
                  ($results['total_penalty_amount'] ?? 0) . ". Branch filter: " . ($branch_filter ?: 'All branches');
    logSystemActivity($conn, "Automatic Penalty Update", $log_details);
    
    $end_time = microtime(true);
    $execution_time = round($end_time - $start_time, 2);
    
    // Prepare response
    $response = [
        'success' => true,
        'message' => 'Automatic penalty processing completed successfully',
        'execution_time' => $execution_time . ' seconds',
        'timestamp' => date('Y-m-d H:i:s'),
        'branch_filter' => $branch_filter,
        'results' => $results
    ];
    
} catch (Exception $e) {
    $response = [
        'success' => false,
        'message' => 'Error processing automatic penalties: ' . $e->getMessage(),
        'results' => []
    ];
}

// Return JSON response
echo json_encode($response);
exit();
?>