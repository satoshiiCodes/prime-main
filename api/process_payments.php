<?php
// Set timezone to Philippines
date_default_timezone_set('Asia/Manila');

// Database configuration
$dbConfig = [
    'host' => 'localhost',
    'username' => 'root',
    'password' => '',
    'name' => 'db_prime_city',
    'charset' => 'utf8mb4'
];

// Start session and set JSON header
session_start();
header('Content-Type: application/json');

// Disable error display to prevent HTML in JSON response
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Verify authentication
if (!isset($_SESSION['logged_in'])) {
    http_response_code(401);
    die(json_encode(['status' => 'error', 'message' => 'Unauthorized']));
}

// Verify CSRF token
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Invalid CSRF token']));
}

// Database connection
try {
    $conn = new mysqli($dbConfig['host'], $dbConfig['username'], $dbConfig['password'], $dbConfig['name']);
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }
    $conn->set_charset($dbConfig['charset']);
} catch (Exception $e) {
    http_response_code(500);
    die(json_encode(['status' => 'error', 'message' => 'Database connection failed']));
}

class EnhancedPaymentProcessor {
    private $conn;
    private $processedData = [];
    private $errors = [];
    private $loansToCheck = [];           // Track loans that need completion check
    private $withdrawalTransactions = []; // Track withdrawal transactions for voucher printing

    public function __construct($conn) {
        $this->conn = $conn;
    }

    public function processPayments($data) {
        try {
            // Validate basic inputs
            $this->validateInputs($data);

            // Begin transaction
            $this->conn->begin_transaction();

            // Process each transaction
            foreach ($data['transactions'] as $index => $transaction) {
                $this->processSingleTransaction($transaction, $data, $index);
            }

            // Check if any transactions were processed
            if (empty($this->processedData['transactions'])) {
                throw new Exception("No valid transactions were processed. Please check that all transactions have valid amounts and account selections.");
            }

            // Process proxy payments if applicable
            if ($data['is_proxy_collector'] && !empty($this->processedData['proxy_payments'])) {
                $this->processProxyPayments();
            }

            // Process proxy savings transactions if applicable
            if ($data['is_proxy_collector'] && !empty($this->processedData['proxy_savings_transactions'])) {
                $this->processProxySavingsTransactions();
            }

            // Check and update loan completion status AFTER all transactions
            $this->checkAndUpdateLoansCompletionStatus();

            // Commit transaction
            $this->conn->commit();

            return [
                'status' => 'success',
                'message' => $this->getSuccessMessage(),
                'data' => $this->processedData,
                'withdrawal_transactions' => $this->withdrawalTransactions
            ];

        } catch (Exception $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

    private function validateInputs($data) {
        $required = ['collector_id', 'payment_date', 'payment_method', 'transactions'];
        $missing = [];

        foreach ($required as $field) {
            if (empty($data[$field])) {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            throw new Exception("Missing required fields: " . implode(', ', $missing));
        }

        if (!DateTime::createFromFormat('Y-m-d\TH:i', $data['payment_date'])) {
            throw new Exception("Invalid date format");
        }

        if ($this->isSunday($data['payment_date'])) {
            throw new Exception("Payments are not allowed on Sundays");
        }

        if (empty($data['transactions']) || !is_array($data['transactions'])) {
            throw new Exception("No transactions provided");
        }

        if ($data['collector_id'] <= 0) {
            throw new Exception("Invalid collector selected");
        }
    }

    private function processSingleTransaction($transaction, $paymentData, $index) {
        $customerId = (int)$transaction['customer_id'];
        $customerName = $this->getCustomerName($customerId);
        $customerBranch = $this->getCustomerBranch($customerId);

        $processedTxn = [
            'customer_id' => $customerId,
            'customer_name' => $customerName,
            'customer_branch' => $customerBranch,
            'loan_payment' => null,
            'savings_deposit' => null,
            'savings_withdrawal' => null
        ];

        $transactionOperations = 0;

        // Process Loan Payment
        if (!empty($transaction['loan_payment']['account_id']) &&
            $transaction['loan_payment']['amount'] > 0) {

            $loanResult = $this->processLoanPayment(
                $transaction['loan_payment'],
                $customerId,
                $customerName,
                $customerBranch,
                $paymentData,
                $index
            );

            if ($loanResult) {
                $processedTxn['loan_payment'] = $loanResult;
                $transactionOperations++;

                $loanId = (int)$transaction['loan_payment']['account_id'];
                $this->loansToCheck[$loanId] = $customerId;
            }
        }

        // Process Savings Deposit
        if (!empty($transaction['savings_deposit']['account_id']) &&
            $transaction['savings_deposit']['amount'] > 0) {

            $depositResult = $this->processSavingsDeposit(
                $transaction['savings_deposit'],
                $customerId,
                $customerName,
                $customerBranch,
                $paymentData,
                $index
            );

            if ($depositResult) {
                $processedTxn['savings_deposit'] = $depositResult;
                $transactionOperations++;

                $savingsId = (int)$transaction['savings_deposit']['account_id'];
                $loanId = $this->getLoanIdFromSavings($savingsId, $customerId);
                if ($loanId) {
                    $this->loansToCheck[$loanId] = $customerId;
                }
            }
        }

        // Process Savings Withdrawal
        if (!empty($transaction['savings_withdrawal']['account_id']) &&
            $transaction['savings_withdrawal']['amount'] > 0) {

            $savingsId = (int)$transaction['savings_withdrawal']['account_id'];

            $withdrawalResult = $this->processSavingsWithdrawal(
                $transaction['savings_withdrawal'],
                $customerId,
                $customerName,
                $customerBranch,
                $paymentData,
                $index
            );

            if ($withdrawalResult) {
                $processedTxn['savings_withdrawal'] = $withdrawalResult;
                $transactionOperations++;

                // Track the associated loan for this savings account
                $loanId = $this->getLoanIdFromSavings($savingsId, $customerId);
                if ($loanId) {
                    $this->loansToCheck[$loanId] = $customerId;
                }

                if (isset($withdrawalResult['loan_application'])) {
                    $loanId = $withdrawalResult['loan_application']['loan_id'];
                    $this->loansToCheck[$loanId] = $customerId;
                }

                // Track withdrawal transaction for voucher printing.
                // Include loan_id so front-end can group withdrawals per customer.
                $withdrawalLoanId = $this->getLoanIdFromSavings($savingsId, $customerId);

                $this->withdrawalTransactions[] = [
                    'transaction_id' => $withdrawalResult['transaction_id'],
                    'transaction_type' => $withdrawalResult['transaction_type'],
                    'amount' => $withdrawalResult['amount'],
                    'customer_id' => $customerId,
                    'customer_name' => $customerName,
                    'branch' => $customerBranch,
                    'loan_id' => $withdrawalLoanId
                ];
            }
        }

        if ($transactionOperations > 0) {
            $this->processedData['transactions'][] = $processedTxn;
            $this->processedData['total_operations'] = ($this->processedData['total_operations'] ?? 0) + $transactionOperations;
        }
    }

    private function processLoanPayment($loanPayment, $customerId, $customerName, $customerBranch, $paymentData, $index) {
        $loanId = (int)$loanPayment['account_id'];
        $amount = $this->parseAmount($loanPayment['amount']);

        $loan = $this->validateLoan($loanId, $customerId, $customerName);
        $this->validatePaymentDate($paymentData['payment_date'], $loan['start_date'], $loanId);

        if ($amount > $loan['balance']) {
            throw new Exception("Loan payment amount (₱" . number_format($amount, 2) . ") exceeds balance (₱" . number_format($loan['balance'], 2) . ") for customer: $customerName (Loan ID: $loanId)");
        }

        $newBalance = max(0, $loan['balance'] - $amount);
        $notes = "Payment - " . ($paymentData['notes'] ?? '');

        $paymentId = $this->insertPaymentRecord([
            'customer_id' => $customerId,
            'loan_id' => $loanId,
            'collector_id' => $paymentData['collector_id'],
            'proxy_account_id' => $paymentData['is_proxy_collector'] ? $paymentData['proxy_account_id'] : null,
            'amount' => $amount,
            'branch' => $customerBranch,
            'payment_date' => $paymentData['payment_date'],
            'balance_after_payment' => $newBalance,
            'payment_method' => $paymentData['payment_method'],
            'notes' => $notes
        ]);

        $this->updateLoanBalance($loanId, $newBalance);

        if ($paymentData['is_proxy_collector'] && $paymentData['proxy_account_id']) {
            $this->processedData['proxy_payments'][] = [
                'payment_id' => $paymentId,
                'proxy_account_id' => $paymentData['proxy_account_id']
            ];
        }

        return [
            'account_id' => $loanId,
            'amount' => $amount,
            'new_balance' => $newBalance,
            'payment_id' => $paymentId,
            'branch' => $customerBranch
        ];
    }

    private function processSavingsDeposit($depositData, $customerId, $customerName, $customerBranch, $paymentData, $index) {
        $savingsId = (int)$depositData['account_id'];
        $amount = $this->parseAmount($depositData['amount']);

        $savings = $this->validateSavingsAccount($savingsId, $customerId, $customerName);

        $newBalance = $savings['current_balance'] + $amount;
        $notes = "Savings Deposit - " . ($paymentData['notes'] ?? '');

        $transactionId = $this->insertSavingsTransaction([
            'savings_account_id' => $savingsId,
            'transaction_type' => 'Deposit',
            'amount' => $amount,
            'branch' => $customerBranch,
            'transaction_date' => $paymentData['payment_date'],
            'collector_id' => $paymentData['collector_id'],
            'proxy_account_id' => $paymentData['is_proxy_collector'] ? $paymentData['proxy_account_id'] : null,
            'payment_method' => $paymentData['payment_method'],
            'notes' => $notes,
            'balance_after_payment' => $newBalance
        ]);

        $this->updateSavingsBalance($savingsId, $newBalance);

        if ($paymentData['is_proxy_collector'] && $paymentData['proxy_account_id']) {
            $this->processedData['proxy_savings_transactions'][] = [
                'transaction_id' => $transactionId,
                'proxy_account_id' => $paymentData['proxy_account_id']
            ];
        }

        return [
            'account_id' => $savingsId,
            'amount' => $amount,
            'new_balance' => $newBalance,
            'transaction_id' => $transactionId,
            'branch' => $customerBranch
        ];
    }

    private function processSavingsWithdrawal($withdrawalData, $customerId, $customerName, $customerBranch, $paymentData, $index) {
        $savingsId = (int)$withdrawalData['account_id'];
        $amount = $this->parseAmount($withdrawalData['amount']);
        $applyToLoan = !empty($withdrawalData['apply_to_loan']);

        $savings = $this->validateSavingsAccount($savingsId, $customerId, $customerName);

        if ($amount > $savings['current_balance']) {
            throw new Exception("Savings withdrawal amount (₱" . number_format($amount, 2) . ") exceeds balance (₱" . number_format($savings['current_balance'], 2) . ") for customer: $customerName");
        }

        $savingsNewBalance = $savings['current_balance'] - $amount;

        $transactionType = $applyToLoan ? 'Withdrawal' : 'Withdrawal Cash';
        $notes = "Savings Withdrawal" . (!empty($paymentData['notes']) ? " - " . $paymentData['notes'] : "");

        $transactionId = $this->insertSavingsTransaction([
            'savings_account_id' => $savingsId,
            'transaction_type' => $transactionType,
            'amount' => $amount,
            'branch' => $customerBranch,
            'transaction_date' => $paymentData['payment_date'],
            'collector_id' => $paymentData['collector_id'],
            'proxy_account_id' => $paymentData['is_proxy_collector'] ? $paymentData['proxy_account_id'] : null,
            'payment_method' => $paymentData['payment_method'],
            'notes' => $notes,
            'balance_after_payment' => $savingsNewBalance
        ]);

        $this->updateSavingsBalance($savingsId, $savingsNewBalance);

        if ($paymentData['is_proxy_collector'] && $paymentData['proxy_account_id']) {
            $this->processedData['proxy_savings_transactions'][] = [
                'transaction_id' => $transactionId,
                'proxy_account_id' => $paymentData['proxy_account_id']
            ];
        }

        $result = [
            'account_id' => $savingsId,
            'amount' => $amount,
            'new_balance' => $savingsNewBalance,
            'transaction_id' => $transactionId,
            'transaction_type' => $transactionType,
            'branch' => $customerBranch
        ];

        if ($applyToLoan && !empty($savings['loan_id'])) {
            $loanResult = $this->applyWithdrawalToLoan(
                $savings['loan_id'],
                $customerId,
                $amount,
                $customerBranch,
                $paymentData,
                $notes
            );

            if ($loanResult) {
                $result['loan_application'] = $loanResult;
            }
        }

        return $result;
    }

    private function applyWithdrawalToLoan($loanId, $customerId, $amount, $customerBranch, $paymentData, $notes) {
        $loan = $this->validateLoan($loanId, $customerId, '');
        $this->validatePaymentDate($paymentData['payment_date'], $loan['start_date'], $loanId);

        $loanNewBalance = max(0, $loan['balance'] - $amount);

        $paymentId = $this->insertPaymentRecord([
            'customer_id' => $customerId,
            'loan_id' => $loanId,
            'collector_id' => $paymentData['collector_id'],
            'proxy_account_id' => $paymentData['is_proxy_collector'] ? $paymentData['proxy_account_id'] : null,
            'amount' => $amount,
            'branch' => $customerBranch,
            'payment_date' => $paymentData['payment_date'],
            'balance_after_payment' => $loanNewBalance,
            'payment_method' => $paymentData['payment_method'],
            'notes' => "Payment from Savings - " . $notes
        ]);

        $this->updateLoanBalance($loanId, $loanNewBalance);

        if ($paymentData['is_proxy_collector'] && $paymentData['proxy_account_id']) {
            $this->processedData['proxy_payments'][] = [
                'payment_id' => $paymentId,
                'proxy_account_id' => $paymentData['proxy_account_id']
            ];
        }

        return [
            'loan_id' => $loanId,
            'amount_applied' => $amount,
            'new_loan_balance' => $loanNewBalance,
            'payment_id' => $paymentId,
            'branch' => $customerBranch
        ];
    }

    private function insertPaymentRecord($data) {
        $stmt = $this->conn->prepare("
            INSERT INTO payments (
                customer_id, loan_id, collector_id, proxy,
                amount, branch, payment_date, balance_after_payment,
                payment_method, notes, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt->bind_param(
            "iiisdsssss",
            $data['customer_id'],
            $data['loan_id'],
            $data['collector_id'],
            $data['proxy_account_id'],
            $data['amount'],
            $data['branch'],
            $data['payment_date'],
            $data['balance_after_payment'],
            $data['payment_method'],
            $data['notes']
        );

        if (!$stmt->execute()) {
            throw new Exception("Failed to insert payment record: " . $stmt->error);
        }

        return $this->conn->insert_id;
    }

    private function insertSavingsTransaction($data) {
        $stmt = $this->conn->prepare("
            INSERT INTO savings_transactions (
                savings_account_id, transaction_type, amount, branch,
                transaction_date, collector_id, proxy, payment_method, notes,
                saving_balance_after_payment, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt->bind_param(
            "isdssiissd",
            $data['savings_account_id'],
            $data['transaction_type'],
            $data['amount'],
            $data['branch'],
            $data['transaction_date'],
            $data['collector_id'],
            $data['proxy_account_id'],
            $data['payment_method'],
            $data['notes'],
            $data['balance_after_payment']
        );

        if (!$stmt->execute()) {
            throw new Exception("Failed to insert savings transaction: " . $stmt->error);
        }

        return $this->conn->insert_id;
    }

    private function updateLoanBalance($loanId, $newBalance) {
        $automaticPenaltyEnabled = ($newBalance == 0) ? 0 : 1;

        $stmt = $this->conn->prepare("
            UPDATE financing SET
                balance = ?,
                automatic_penalty_enabled = ?,
                updated_at = NOW()
            WHERE id = ?
        ");

        $stmt->bind_param("dii", $newBalance, $automaticPenaltyEnabled, $loanId);

        if (!$stmt->execute()) {
            throw new Exception("Failed to update loan balance: " . $stmt->error);
        }

        if ($newBalance == 0) {
            error_log("Loan ID {$loanId}: Balance reached 0.00, automatic_penalty_enabled set to 0");
        }
    }

    private function updateSavingsBalance($savingsId, $newBalance) {
        $stmt = $this->conn->prepare("
            UPDATE savings_accounts SET
                current_balance = ?,
                updated_at = NOW()
            WHERE id = ?
        ");

        $stmt->bind_param("di", $newBalance, $savingsId);

        if (!$stmt->execute()) {
            throw new Exception("Failed to update savings balance: " . $stmt->error);
        }
    }

    private function getLoanIdFromSavings($savingsId, $customerId) {
        $stmt = $this->conn->prepare("
            SELECT loan_id FROM savings_accounts
            WHERE id = ? AND customer_id = ?
        ");
        $stmt->bind_param("ii", $savingsId, $customerId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            return $row['loan_id'] ? (int)$row['loan_id'] : null;
        }
        return null;
    }

    private function checkAndUpdateLoansCompletionStatus() {
        foreach ($this->loansToCheck as $loanId => $customerId) {
            $this->checkAndUpdateSingleLoanCompletion($loanId, $customerId);
        }
    }

    private function checkAndUpdateSingleLoanCompletion($loanId, $customerId) {
        $loanStmt = $this->conn->prepare("
            SELECT balance, status FROM financing
            WHERE id = ? AND customer_id = ? AND status = 'active'
        ");
        $loanStmt->bind_param("ii", $loanId, $customerId);
        $loanStmt->execute();
        $loanResult = $loanStmt->get_result();

        if ($loanResult->num_rows === 1) {
            $loan = $loanResult->fetch_assoc();
            $loanBalance = (float)$loan['balance'];

            $savingsStmt = $this->conn->prepare("
                SELECT id, current_balance, status
                FROM savings_accounts
                WHERE loan_id = ? AND customer_id = ? AND status = 'Active'
            ");
            $savingsStmt->bind_param("ii", $loanId, $customerId);
            $savingsStmt->execute();
            $savingsResult = $savingsStmt->get_result();

            $totalSavingsBalance = 0;
            $savingsAccounts = [];

            while ($savingsRow = $savingsResult->fetch_assoc()) {
                $savingsBalance = (float)$savingsRow['current_balance'];
                $totalSavingsBalance += $savingsBalance;
                $savingsAccounts[] = [
                    'id' => $savingsRow['id'],
                    'balance' => $savingsBalance
                ];
            }

            error_log("Checking Loan {$loanId} for Customer {$customerId}: Loan Balance = {$loanBalance}, Total Savings Balance = {$totalSavingsBalance}");

            if ($loanBalance == 0 && $totalSavingsBalance == 0) {
                $updateStmt = $this->conn->prepare("
                    UPDATE financing SET
                        status = 'completed',
                        automatic_penalty_enabled = 0,
                        updated_at = NOW()
                    WHERE id = ? AND customer_id = ? AND status = 'active'
                ");
                $updateStmt->bind_param("ii", $loanId, $customerId);

                if ($updateStmt->execute()) {
                    error_log("SUCCESS: Loan {$loanId} marked as COMPLETED - Loan Balance: 0, Total Savings Balance: 0");

                    if (!empty($savingsAccounts)) {
                        foreach ($savingsAccounts as $savingsAccount) {
                            $savingsUpdateStmt = $this->conn->prepare("
                                UPDATE savings_accounts SET
                                    status = 'Closed',
                                    updated_at = NOW()
                                WHERE id = ?
                            ");
                            $savingsUpdateStmt->bind_param("i", $savingsAccount['id']);
                            $savingsUpdateStmt->execute();
                            error_log("Savings Account {$savingsAccount['id']} marked as Completed (Loan {$loanId})");
                        }
                    }
                } else {
                    error_log("ERROR: Failed to mark loan {$loanId} as completed: " . $updateStmt->error);
                }
            } else {
                error_log("Loan {$loanId} NOT completed - Loan Balance: {$loanBalance}, Total Savings Balance: {$totalSavingsBalance}");
            }

            if ($loanBalance == 0) {
                $this->disableAutomaticPenalty($loanId);
            }
        } else {
            error_log("Loan {$loanId} for Customer {$customerId} not found or not active");
        }
    }

    private function disableAutomaticPenalty($loanId) {
        $stmt = $this->conn->prepare("
            UPDATE financing SET
                automatic_penalty_enabled = 0,
                updated_at = NOW()
            WHERE id = ? AND balance = 0
        ");
        $stmt->bind_param("i", $loanId);

        if (!$stmt->execute()) {
            error_log("Failed to disable automatic penalty for loan {$loanId}: " . $stmt->error);
        } else {
            error_log("Loan {$loanId}: Automatic penalty disabled due to zero balance");
        }
    }

    private function processProxyPayments() {
        foreach ($this->processedData['proxy_payments'] as $proxyPayment) {
            $stmt = $this->conn->prepare("
                INSERT INTO proxy_payments (payment_id, proxy_account_id, created_at)
                VALUES (?, ?, NOW())
            ");

            $stmt->bind_param("ii", $proxyPayment['payment_id'], $proxyPayment['proxy_account_id']);

            if (!$stmt->execute()) {
                throw new Exception("Failed to record proxy payment: " . $stmt->error);
            }
        }
    }

    private function processProxySavingsTransactions() {
        foreach ($this->processedData['proxy_savings_transactions'] as $proxySavingsTransaction) {
            $stmt = $this->conn->prepare("
                INSERT INTO proxy_savings_transactions (savings_transaction_id, proxy_account_id, created_at)
                VALUES (?, ?, NOW())
            ");

            $stmt->bind_param("ii", $proxySavingsTransaction['transaction_id'], $proxySavingsTransaction['proxy_account_id']);

            if (!$stmt->execute()) {
                throw new Exception("Failed to record proxy savings transaction: " . $stmt->error);
            }
        }
    }

    private function validateLoan($loanId, $customerId, $customerName) {
        $stmt = $this->conn->prepare("
            SELECT f.id, f.customer_id, f.balance, f.status, f.start_date, f.automatic_penalty_enabled,
                   ca.first_name, ca.last_name
            FROM financing f
            JOIN customer_accounts ca ON f.customer_id = ca.id
            WHERE f.id = ? AND f.customer_id = ? AND f.status = 'active'
        ");

        $stmt->bind_param("ii", $loanId, $customerId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows !== 1) {
            throw new Exception("Loan not found or does not belong to customer: $customerName (Loan ID: $loanId)");
        }

        return $result->fetch_assoc();
    }

    private function validateSavingsAccount($savingsId, $customerId, $customerName) {
        $stmt = $this->conn->prepare("
            SELECT s.id, s.customer_id, s.current_balance, s.status, s.loan_id
            FROM savings_accounts s
            WHERE s.id = ? AND s.customer_id = ? AND s.status = 'Active'
        ");

        $stmt->bind_param("ii", $savingsId, $customerId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows !== 1) {
            throw new Exception("Savings account not found or does not belong to customer: $customerName");
        }

        return $result->fetch_assoc();
    }

    private function validatePaymentDate($paymentDate, $startDate, $loanId) {
        $paymentDateOnly = date('Y-m-d', strtotime($paymentDate));
        $startDateOnly = date('Y-m-d', strtotime($startDate));

        if ($paymentDateOnly < $startDateOnly) {
            throw new Exception("Payment cannot be before the loan start date (Loan ID: $loanId, Start Date: $startDate)");
        }
    }

    private function parseAmount($amount) {
        if (is_numeric($amount)) {
            return (float) $amount;
        }

        if (is_string($amount)) {
            $cleanAmount = preg_replace('/[^\d.]/', '', $amount);
            return (float) $cleanAmount;
        }

        return 0;
    }

    private function isSunday($date) {
        return date('w', strtotime($date)) == 0;
    }

    private function getCustomerName($customerId) {
        $stmt = $this->conn->prepare("
            SELECT first_name, middle_name, last_name, suffix
            FROM customer_accounts
            WHERE id = ?
        ");

        $stmt->bind_param("i", $customerId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();

            $firstName = trim($row['first_name']);
            $middleName = trim($row['middle_name'] ?? '');
            $lastName = trim($row['last_name']);
            $suffix = trim($row['suffix'] ?? '');

            $name = $lastName . ', ' . $firstName;

            if (!empty($middleName)) {
                $name .= ' ' . $middleName;
            }

            if (!empty($suffix) && strtolower($suffix) !== 'none') {
                $name .= ' ' . $suffix;
            }

            return $name;
        }

        return "Unknown Customer";
    }

    private function getCustomerBranch($customerId) {
        $stmt = $this->conn->prepare("
            SELECT branch
            FROM customer_accounts
            WHERE id = ?
        ");

        $stmt->bind_param("i", $customerId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            return $row['branch'] ?? 'Tuguegarao City';
        }

        return 'Tuguegarao City';
    }

    private function getSuccessMessage() {
        $customerCount = count($this->processedData['transactions']);
        $operationCount = $this->processedData['total_operations'] ?? 0;

        return "Successfully processed $operationCount transaction(s) for $customerCount customer(s)";
    }
}

// Main execution
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Method not allowed");
    }

    $inputData = [
        'collector_id' => (int)$_POST['collector_id'],
        'payment_date' => $_POST['payment_date'],
        'payment_method' => $_POST['payment_method'],
        'notes' => $_POST['notes'] ?? '',
        'transactions' => json_decode($_POST['transactions'], true),
        'is_proxy_collector' => isset($_POST['is_proxy_collector']) && $_POST['is_proxy_collector'] == '1',
        'proxy_account_id' => isset($_POST['proxy_account_id']) ? (int)$_POST['proxy_account_id'] : null
    ];

    $processor = new EnhancedPaymentProcessor($conn);
    $result = $processor->processPayments($inputData);

    echo json_encode($result);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>