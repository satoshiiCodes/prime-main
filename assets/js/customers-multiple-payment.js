/* ==========================================================================
   Prime City - Multiple customer payments behaviour
   The EnhancedPaymentProcessor class: customer picker, transaction rows and
   the AJAX submit to process_payments.php. Page data lives in
   #multiple-payment-data; JSON calls keep using api/.
   ========================================================================== */

(function () {
    var pageConfigCache = null;

    /**
     * Page configuration from #multiple-payment-data.
     */
    function pageConfig() {
        if (pageConfigCache) { return pageConfigCache; }

        var node = document.getElementById('multiple-payment-data');

        if (!node) { pageConfigCache = {}; return pageConfigCache; }

        try {
            pageConfigCache = JSON.parse(node.textContent || '{}');
        } catch (error) {
            console.error('[multiple-payment] Could not parse page configuration.', error);
            pageConfigCache = {};
        }

        return pageConfigCache;
    }

    const pageCfg = pageConfig();
        class EnhancedPaymentProcessor {
            constructor() {
                this.API_BASE_URL = 'api/';
                this.transactions = new Map();
                this.allCustomers = [];
                this.isPastDueMode = false;
                this.isSavingsOnlyMode = false;
                this.currentlySelectedCustomerIds = new Set();
                this.isCollectorUser = pageCfg.isCollectorUser;
                this.isProxyUser = pageCfg.isProxyUser;
                this.isProxyCollector = pageCfg.isProxyCollector;
                this.userType = pageCfg.userType;
                this.userBranch = pageCfg.userBranch;
                this.userId = pageCfg.userId;
                this.isManagementUser = pageCfg.isManagementUser;
                
                this.initializeElements();
                this.attachEventListeners();
                this.initializeCollectorUser();
            }
            
            initializeElements() {
                this.form = document.getElementById('paymentForm');
                this.transactionsBody = document.getElementById('transactionsBody');
                this.transactionsInput = document.getElementById('transactions');
                this.addCustomerBtn = document.getElementById('addCustomerBtn');
                this.addSavingsOnlyBtn = document.getElementById('addSavingsOnlyBtn');
                this.addPastDueBtn = document.getElementById('addPastDueBtn');
                this.collectorSelect = document.getElementById('collector_id');
                this.collectorDisplay = document.getElementById('collector_display');
                this.branchFilter = document.getElementById('branch_filter');
                this.customerModal = new bootstrap.Modal(document.getElementById('customerModal'));
                this.confirmCustomerBtn = document.getElementById('confirmCustomerBtn');
                this.submitBtn = document.getElementById('submitBtn');
                this.isProxyCollectorCheckbox = document.getElementById('is_proxy_collector');
                this.proxyCollectorContainer = document.getElementById('proxyCollectorContainer');
                this.proxyAccountSelect = document.getElementById('proxy_account_id');
                this.errorElement = document.getElementById('form-error');
                
                if (this.isCollectorUser) {
                    this.collectorId = document.getElementById('collector_id').value;
                }
            }
            
            initializeCollectorUser() {
                if (this.isCollectorUser) {
                    this.addCustomerBtn.disabled = false;
                    this.addSavingsOnlyBtn.disabled = false;
                    this.addPastDueBtn.disabled = false;
                }
            }
            
            attachEventListeners() {
                if (!this.isCollectorUser && !this.isProxyCollector && this.branchFilter) {
                    this.branchFilter.addEventListener('change', () => this.handleBranchFilterChange());
                }
                
                if (this.collectorSelect) {
                    this.collectorSelect.addEventListener('change', () => this.handleCollectorChange());
                }
                
                this.addCustomerBtn.addEventListener('click', () => this.showCustomerModal('current'));
                this.addSavingsOnlyBtn.addEventListener('click', () => this.showCustomerModal('savings_only'));
                this.addPastDueBtn.addEventListener('click', () => this.showCustomerModal('past_due'));
                
                this.confirmCustomerBtn.addEventListener('click', () => this.confirmCustomerSelection());
                
                this.form.addEventListener('submit', (e) => this.handleFormSubmit(e));
                
                document.getElementById('customerSearch').addEventListener('input', (e) => this.filterCustomers(e.target.value));
            }
            
            handleBranchFilterChange() {
                const selectedBranch = this.branchFilter.value;
                const url = new URL(window.location);
                
                if (selectedBranch) {
                    url.searchParams.set('branch', selectedBranch);
                } else {
                    url.searchParams.delete('branch');
                }
                
                if (this.transactions.size > 0) {
                    if (confirm('Changing branch will clear all current transactions. Continue?')) {
                        window.location.href = url.toString();
                    } else {
                        this.branchFilter.value = '';
                    }
                } else {
                    window.location.href = url.toString();
                }
            }
            
            handleCollectorChange() {
                const collectorId = this.collectorSelect ? this.collectorSelect.value : null;

                if (this.isProxyCollector || !this.isCollectorUser) {
                    this.addCustomerBtn.disabled = !collectorId;
                    this.addSavingsOnlyBtn.disabled = !collectorId;
                    this.addPastDueBtn.disabled = !collectorId;
                }

                if (this.transactions.size > 0) {
                    if (confirm('Changing collector will clear all current transactions. Continue?')) {
                        this.clearAllTransactions();
                    } else if (this.collectorSelect) {
                        this.collectorSelect.value = '';

                        if (window.pcCollectorSelect) {
                            window.pcCollectorSelect.refresh();
                        }
                        this.collectorSelect.disabled = false;
                    }
                }
            }
            
            async showCustomerModal(mode) {
                let collectorId;
                
                if (this.isCollectorUser) {
                    collectorId = this.collectorId || this.userId;
                } else {
                    collectorId = this.collectorSelect ? this.collectorSelect.value : null;
                    if (!collectorId) {
                        this.showAlert('warning', 'Select Collector', 'Please select a collector first');
                        return;
                    }
                }
                
                this.isPastDueMode = (mode === 'past_due');
                this.isSavingsOnlyMode = (mode === 'savings_only');
                
                let modalTitle = 'Select Customers';
                if (mode === 'past_due') {
                    modalTitle = 'Select Past Due Customers';
                } else if (mode === 'savings_only') {
                    modalTitle = 'Select Customers (Savings Balance Only)';
                }
                document.getElementById('customerModalLabel').textContent = modalTitle;
                
                await this.loadCustomers(collectorId, mode);
                this.customerModal.show();
            }
            
            async loadCustomers(collectorId, mode) {
                const customerTableBody = document.getElementById('customerTableBody');
                customerTableBody.innerHTML = '<tr><td colspan="6" class="text-center py-4">Loading customers...</td></tr>';
                
                try {
                    let endpoint;
                    if (mode === 'past_due') {
                        endpoint = `payments.php?action=get_past_due_customers&collector_id=${collectorId}`;
                    } else if (mode === 'savings_only') {
                        endpoint = `payments.php?action=get_savings_only_customers&collector_id=${collectorId}`;
                    } else {
                        endpoint = `payments.php?action=get_current_customers&collector_id=${collectorId}`;
                    }
                    
                    const response = await fetch(this.API_BASE_URL + endpoint);
                    const data = await response.json();
                    
                    if (data.status !== 'success') {
                        throw new Error(data.message);
                    }
                    
                    this.allCustomers = data.data.customers;
                    this.renderCustomerTable();
                } catch (error) {
                    console.error('Error loading customers:', error);
                    customerTableBody.innerHTML = '<tr><td colspan="6" class="text-center text-danger py-4">Error loading customers</td></tr>';
                }
            }
            
            renderCustomerTable() {
                const customerTableBody = document.getElementById('customerTableBody');
                customerTableBody.innerHTML = '';
                
                if (this.allCustomers.length === 0) {
                    let message = 'No customers found';
                    if (this.isPastDueMode) {
                        message = 'No past due customers found';
                    } else if (this.isSavingsOnlyMode) {
                        message = 'No customers with savings only balance found';
                    }
                    customerTableBody.innerHTML = `<tr><td colspan="6" class="text-center py-4">${message}</td></tr>`;
                    return;
                }
                
                const selectedCustomerIds = this.getSelectedCustomerIds();
                this.currentlySelectedCustomerIds = new Set(selectedCustomerIds);
                
                let visibleCustomersCount = 0;
                
                this.allCustomers.forEach(customer => {
                    const isAlreadySelected = selectedCustomerIds.includes(customer.id.toString());
                    
                    const row = document.createElement('tr');
                    row.dataset.customerId = customer.id;
                    
                    if (isAlreadySelected) {
                        row.classList.add('customer-already-added');
                    } else {
                        visibleCustomersCount++;
                    }
                    
                    let statusBadge = 'status-active';
                    let statusText = 'Active';
                    if (this.isPastDueMode) {
                        statusBadge = 'status-past-due';
                        statusText = 'Past Due';
                    } else if (this.isSavingsOnlyMode) {
                        statusBadge = 'status-savings-only';
                        statusText = 'Savings Only';
                    }
                    
                    row.innerHTML = `
                        <td>
                            <input type="checkbox" class="customer-checkbox" value="${customer.id}" 
                                ${isAlreadySelected ? 'disabled' : ''}>
                        </td>
                        <td>${customer.area}</td>
                        <td>${customer.name}</td>
                        <td>₱${this.formatNumber(customer.loan_balance || 0)}</td>
                        <td>₱${this.formatNumber(customer.savings_balance || 0)}</td>
                        <td><span class="customer-badge ${statusBadge}">
                            ${statusText}
                        </span></td>
                    `;
                    customerTableBody.appendChild(row);
                });
                
                if (visibleCustomersCount === 0) {
                    customerTableBody.innerHTML = `
                        <tr>
                            <td colspan="6" class="text-center py-4">
                                <div class="text-muted">
                                    <i class="fas fa-info-circle fa-2x mb-3"></i>
                                    <p class="mb-1">All available customers have already been added to transactions.</p>
                                    <small>Remove some customers from the transactions table to add more.</small>
                                </div>
                            </td>
                        </tr>
                    `;
                }
                
                this.initializeCustomerCheckboxes();
            }
            
            getSelectedCustomerIds() {
                return Array.from(this.transactions.values()).map(transaction => transaction.customer_id.toString());
            }
            
            initializeCustomerCheckboxes() {
                const checkboxes = document.querySelectorAll('.customer-checkbox');
                const selectAll = document.getElementById('selectAllCustomers');
                
                if (checkboxes.length > 0) {
                    selectAll.addEventListener('change', () => {
                        checkboxes.forEach(checkbox => {
                            const row = checkbox.closest('tr');
                            if (!row.classList.contains('customer-already-added') && row.style.display !== 'none') {
                                checkbox.checked = selectAll.checked;
                            }
                        });
                    });
                    
                    checkboxes.forEach(checkbox => {
                        checkbox.addEventListener('change', () => {
                            if (!checkbox.checked) {
                                selectAll.checked = false;
                            } else {
                                const allVisibleChecked = Array.from(checkboxes).every(cb => {
                                    const row = cb.closest('tr');
                                    return row.classList.contains('customer-already-added') || 
                                           row.style.display === 'none' || 
                                           cb.checked;
                                });
                                selectAll.checked = allVisibleChecked;
                            }
                        });
                    });
                }
                
                selectAll.checked = false;
                selectAll.disabled = checkboxes.length === 0;
            }
            
            filterCustomers(searchTerm) {
                const rows = document.querySelectorAll('#customerTableBody tr');
                const term = searchTerm.toLowerCase().trim();
                let visibleCount = 0;
                
                const existingMessage = document.querySelector('#customerTableBody .no-search-results');
                if (existingMessage) {
                    existingMessage.remove();
                }
                
                if (term === '') {
                    rows.forEach(row => {
                        if (row.cells.length < 3) return;
                        
                        if (row.classList.contains('no-search-results') || row.querySelector('.text-center')) {
                            row.style.display = 'none';
                            return;
                        }
                        
                        if (!row.classList.contains('customer-already-added')) {
                            row.style.display = '';
                            visibleCount++;
                        } else {
                            row.style.display = 'none';
                        }
                    });
                } else {
                    rows.forEach(row => {
                        if (row.cells.length < 3) return;
                        
                        if (row.classList.contains('no-search-results') || row.querySelector('.text-center')) {
                            row.style.display = 'none';
                            return;
                        }
                        
                        const customerName = row.cells[2].textContent.toLowerCase();
                        const customerArea = row.cells[1].textContent.toLowerCase();
                        const isVisible = customerName.includes(term) || customerArea.includes(term);
                        
                        row.style.display = isVisible ? '' : 'none';
                        
                        if (isVisible) {
                            visibleCount++;
                        }
                    });
                }
                
                this.updateSelectAllCheckbox();
                
                if (visibleCount === 0 && term.length > 0) {
                    const messageRow = document.createElement('tr');
                    messageRow.className = 'no-search-results';
                    messageRow.innerHTML = `
                        <td colspan="6" class="text-center py-4">
                            <div class="text-muted">
                                <i class="fas fa-search fa-2x mb-3"></i>
                                <p class="mb-1">No customers found matching "${searchTerm}"</p>
                                <small>Try a different search term or check if customers are already added to transactions.</small>
                            </div>
                        </td>
                    `;
                    document.getElementById('customerTableBody').appendChild(messageRow);
                }
            }
            
            updateSelectAllCheckbox() {
                const checkboxes = document.querySelectorAll('.customer-checkbox');
                const selectAll = document.getElementById('selectAllCustomers');
                
                if (checkboxes.length === 0) {
                    selectAll.disabled = true;
                    return;
                }
                
                const visibleCheckboxes = Array.from(checkboxes).filter(cb => {
                    const row = cb.closest('tr');
                    return !row.classList.contains('customer-already-added') && 
                           row.style.display !== 'none' && 
                           !row.classList.contains('no-search-results') &&
                           !cb.disabled;
                });
                
                if (visibleCheckboxes.length === 0) {
                    selectAll.disabled = true;
                    return;
                }
                
                selectAll.disabled = false;
                
                const allVisibleChecked = visibleCheckboxes.every(cb => cb.checked);
                selectAll.checked = allVisibleChecked;
            }
            
            confirmCustomerSelection() {
                const selectedCustomers = Array.from(document.querySelectorAll('.customer-checkbox:checked'));
                const enabledSelectedCustomers = selectedCustomers.filter(checkbox => !checkbox.disabled);
                
                if (enabledSelectedCustomers.length === 0) {
                    this.showAlert('warning', 'No Selection', 'Please select at least one customer');
                    return;
                }
                
                this.customerModal.hide();
                
                const customersToAdd = [];
                enabledSelectedCustomers.forEach(checkbox => {
                    const customerId = checkbox.value;
                    const customerData = this.allCustomers.find(c => c.id.toString() === customerId);
                    
                    if (customerData) {
                        customersToAdd.push(customerData);
                        this.addTransactionRow(customerData);
                    }
                });
                
                // Bulk load accounts for all newly added customers
                this.bulkLoadAccounts(customersToAdd);
                
                // Reset modal
                document.querySelectorAll('.customer-checkbox').forEach(cb => cb.checked = false);
                document.getElementById('selectAllCustomers').checked = false;
                document.getElementById('customerSearch').value = '';
            }
            
            addTransactionRow(customerData) {
                const transactionId = `txn_${Date.now()}_${customerData.id}`;
                
                const transaction = {
                    id: transactionId,
                    customer_id: customerData.id,
                    customer_name: customerData.name,
                    customer_area: customerData.area,
                    loan_payment: { account_id: '', amount: 0 },
                    savings_deposit: { account_id: '', amount: 0 },
                    savings_withdrawal: { account_id: '', amount: 0, apply_to_loan: true },
                    accountsLoaded: false
                };
                
                this.transactions.set(transactionId, transaction);
                
                const row = document.createElement('tr');
                row.id = transactionId;
                row.className = 'transaction-row';
                row.innerHTML = this.getTransactionRowHTML(transaction);
                
                this.transactionsBody.appendChild(row);
                
                // Disable all selects and amount inputs initially
                row.querySelectorAll('select').forEach(select => select.disabled = true);
                row.querySelectorAll('.amount-input').forEach(input => input.disabled = true);
            }
            
            async bulkLoadAccounts(customersData) {
                const customerIds = customersData.map(c => c.id);
                
                try {
                    const response = await fetch(`${this.API_BASE_URL}payments.php?action=get_accounts_bulk`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({ customer_ids: customerIds })
                    });
                    
                    const data = await response.json();
                    
                    if (data.status !== 'success') {
                        throw new Error(data.message || 'Failed to load accounts');
                    }
                    
                    const accountsMap = data.data;
                    
                    // Create a map from customer_id to transactionId for unloaded transactions
                    const customerToTransactionMap = {};
                    for (const [txId, tx] of this.transactions.entries()) {
                        if (!tx.accountsLoaded) {
                            customerToTransactionMap[tx.customer_id] = txId;
                        }
                    }
                    
                    for (const customerData of customersData) {
                        const transactionId = customerToTransactionMap[customerData.id];
                        if (!transactionId) continue;
                        
                        const accountsData = accountsMap[customerData.id]?.accounts;
                        if (accountsData) {
                            this.populateAccountDropdowns(transactionId, accountsData);
                            this.attachTransactionEventListeners(transactionId);
                            this.autoSelectAccounts(transactionId, accountsData);
                            this.handleLoanCompletion(transactionId, accountsData);
                            
                            // Mark as loaded
                            const transaction = this.transactions.get(transactionId);
                            if (transaction) {
                                transaction.accountsLoaded = true;
                            }
                        } else {
                            // No accounts data returned; leave disabled
                            console.warn('No accounts data for customer', customerData.id);
                        }
                    }
                    
                    this.updateTransactionsInput();
                    this.calculateTotals();
                    
                } catch (error) {
                    console.error('Error bulk loading accounts:', error);
                    this.showAlert('error', 'Error', 'Failed to load customer accounts. Please try again.');
                }
            }
            
            getTransactionRowHTML(transaction) {
                return `
                    <td>${transaction.customer_area}</td>
                    <td>
                        <strong>${transaction.customer_name}</strong>
                        <input type="hidden" name="customer_id" value="${transaction.customer_id}">
                    </td>
                    <td>
                        <select class="form-select account-select loan-account-select" data-transaction-id="${transaction.id}">
                            <option value="">Select Loan</option>
                        </select>
                        <input type="text" class="form-control amount-input mt-2 formatted-number loan-amount-input" 
                            placeholder="Amount" data-transaction-id="${transaction.id}" disabled>
                        <div class="loan-balance balance-display mt-1" style="display: none;"></div>
                        <div class="loan-account-id account-id-display" style="display: none;"></div>
                    </td>
                    <td>
                        <select class="form-select account-select savings-account-select" data-transaction-id="${transaction.id}">
                            <option value="">Select Savings</option>
                        </select>
                        <input type="text" class="form-control amount-input mt-2 formatted-number savings-deposit-input" 
                            placeholder="Amount" data-transaction-id="${transaction.id}" disabled>
                        <div class="savings-balance balance-display mt-1" style="display: none;"></div>
                        <div class="savings-deposit-account-id account-id-display" style="display: none;"></div>
                    </td>
                    <td>
                        <select class="form-select account-select savings-withdrawal-select" data-transaction-id="${transaction.id}">
                            <option value="">Select Savings</option>
                        </select>
                        <input type="text" class="form-control amount-input mt-2 formatted-number savings-withdrawal-input" 
                            placeholder="Amount" data-transaction-id="${transaction.id}" disabled>
                        <div class="savings-balance balance-display mt-1" style="display: none;"></div>
                        <div class="savings-withdrawal-account-id account-id-display" style="display: none;"></div>
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input apply-to-loan-checkbox" type="checkbox" 
                                data-transaction-id="${transaction.id}" checked>
                            <label class="form-check-label small">Apply to loan</label>
                        </div>
                    </td>
                    <td class="transaction-actions">
                        <button type="button" class="btn btn-sm btn-outline-danger remove-transaction" 
                            data-transaction-id="${transaction.id}">
                            <i class="fas fa-times"></i>
                        </button>
                    </td>
                `;
            }
            
            populateAccountDropdowns(transactionId, accounts) {
                const row = document.getElementById(transactionId);
                if (!row) return;
                
                const loanSelect = row.querySelector('.loan-account-select');
                this.populateDropdown(loanSelect, accounts.loans || [], 'loan');
                
                const savingsSelect = row.querySelector('.savings-account-select');
                const withdrawalSelect = row.querySelector('.savings-withdrawal-select');
                this.populateDropdown(savingsSelect, accounts.savings || [], 'savings');
                this.populateDropdown(withdrawalSelect, accounts.savings || [], 'savings');
            }
            
            populateDropdown(selectElement, accounts, type) {
                selectElement.innerHTML = '<option value="">Select ' + (type === 'loan' ? 'Loan' : 'Savings') + '</option>';
                
                if (accounts.length === 0) {
                    selectElement.disabled = true;
                    selectElement.innerHTML = '<option value="">No accounts available</option>';
                    return;
                }
                
                selectElement.disabled = false;
                
                accounts.forEach(account => {
                    const option = document.createElement('option');
                    option.value = account.id;
                    
                    if (type === 'loan') {
                        option.textContent = `Loan #${account.id}`;
                        option.dataset.balance = account.balance;
                        option.dataset.accountId = account.id;
                    } else {
                        const displayId = account.loan_id ? account.loan_id : account.id;
                        option.textContent = `Savings #${displayId}`;
                        option.dataset.balance = account.current_balance;
                        option.dataset.accountId = displayId;
                    }
                    
                    selectElement.appendChild(option);
                });
            }
            
            autoSelectAccounts(transactionId, accounts) {
                const row = document.getElementById(transactionId);
                if (!row) return;
                
                const loanSelect = row.querySelector('.loan-account-select');
                const loanAmountInput = row.querySelector('.loan-amount-input');
                const loanBalanceDisplay = row.querySelector('.loan-balance');
                const loanAccountIdDisplay = row.querySelector('.loan-account-id');
                
                if (accounts.loans && accounts.loans.length === 1) {
                    const loan = accounts.loans[0];
                    loanSelect.value = loan.id;
                    
                    loanAmountInput.disabled = false;
                    loanBalanceDisplay.textContent = `Balance: ₱${this.formatNumber(loan.balance)}`;
                    loanBalanceDisplay.style.display = 'block';
                    loanBalanceDisplay.className = `loan-balance balance-display mt-1 ${parseFloat(loan.balance) > 0 ? 'text-danger' : 'text-success'}`;
                    
                    loanAccountIdDisplay.textContent = `Loan #${loan.id}`;
                    loanAccountIdDisplay.style.display = 'block';
                    
                    loanSelect.classList.add('auto-selected');
                    loanSelect.classList.add('hidden-select');
                    
                    this.updateTransactionAccount(transactionId, 'loan_payment', 'account_id', loan.id);
                }
                
                const savingsSelect = row.querySelector('.savings-account-select');
                const savingsDepositInput = row.querySelector('.savings-deposit-input');
                const savingsDepositBalanceDisplay = row.querySelectorAll('.savings-balance')[0];
                const savingsDepositAccountIdDisplay = row.querySelector('.savings-deposit-account-id');
                
                if (accounts.savings && accounts.savings.length === 1) {
                    const savings = accounts.savings[0];
                    savingsSelect.value = savings.id;
                    
                    savingsDepositInput.disabled = false;
                    savingsDepositBalanceDisplay.textContent = `Balance: ₱${this.formatNumber(savings.current_balance)}`;
                    savingsDepositBalanceDisplay.style.display = 'block';
                    savingsDepositBalanceDisplay.className = `savings-balance balance-display mt-1 ${parseFloat(savings.current_balance) > 0 ? 'text-success' : 'text-muted'}`;
                    
                    const displayId = savings.loan_id ? savings.loan_id : savings.id;
                    savingsDepositAccountIdDisplay.textContent = `Savings #${displayId}`;
                    savingsDepositAccountIdDisplay.style.display = 'block';
                    
                    savingsSelect.classList.add('auto-selected');
                    savingsSelect.classList.add('hidden-select');
                    
                    this.updateTransactionAccount(transactionId, 'savings_deposit', 'account_id', savings.id);
                }
                
                const withdrawalSelect = row.querySelector('.savings-withdrawal-select');
                const savingsWithdrawalInput = row.querySelector('.savings-withdrawal-input');
                const savingsWithdrawalBalanceDisplay = row.querySelectorAll('.savings-balance')[1];
                const savingsWithdrawalAccountIdDisplay = row.querySelector('.savings-withdrawal-account-id');
                const applyToLoanCheckbox = row.querySelector('.apply-to-loan-checkbox');
                
                if (accounts.savings && accounts.savings.length === 1) {
                    const savings = accounts.savings[0];
                    withdrawalSelect.value = savings.id;
                    
                    savingsWithdrawalInput.disabled = false;
                    savingsWithdrawalBalanceDisplay.textContent = `Balance: ₱${this.formatNumber(savings.current_balance)}`;
                    savingsWithdrawalBalanceDisplay.style.display = 'block';
                    savingsWithdrawalBalanceDisplay.className = `savings-balance balance-display mt-1 ${parseFloat(savings.current_balance) > 0 ? 'text-success' : 'text-muted'}`;
                    
                    const displayId = savings.loan_id ? savings.loan_id : savings.id;
                    savingsWithdrawalAccountIdDisplay.textContent = `Savings #${displayId}`;
                    savingsWithdrawalAccountIdDisplay.style.display = 'block';
                    
                    applyToLoanCheckbox.disabled = false;
                    
                    withdrawalSelect.classList.add('auto-selected');
                    withdrawalSelect.classList.add('hidden-select');
                    
                    this.updateTransactionAccount(transactionId, 'savings_withdrawal', 'account_id', savings.id);
                }
            }
            
            handleLoanCompletion(transactionId, accounts) {
                const row = document.getElementById(transactionId);
                if (!row) return;
                
                const loanSelect = row.querySelector('.loan-account-select');
                const loanAmountInput = row.querySelector('.loan-amount-input');
                const applyToLoanCheckbox = row.querySelector('.apply-to-loan-checkbox');
                const savingsSelect = row.querySelector('.savings-account-select');
                const savingsDepositInput = row.querySelector('.savings-deposit-input');
                const withdrawalSelect = row.querySelector('.savings-withdrawal-select');
                const savingsWithdrawalInput = row.querySelector('.savings-withdrawal-input');
                
                const hasActiveLoans = accounts.loans && accounts.loans.some(loan => parseFloat(loan.balance) > 0);
                const hasSavingsBalance = accounts.savings && accounts.savings.some(savings => parseFloat(savings.current_balance) > 0);
                
                if (!hasActiveLoans && !hasSavingsBalance) {
                    const loanTd = row.querySelector('td:nth-child(3)');
                    loanTd.classList.add('fully-completed-section');
                    
                    loanSelect.disabled = true;
                    loanAmountInput.disabled = true;
                    loanAmountInput.placeholder = "Fully Completed";
                    
                    applyToLoanCheckbox.checked = false;
                    applyToLoanCheckbox.disabled = true;
                    
                    this.updateTransactionAccount(transactionId, 'loan_payment', 'account_id', '');
                    this.updateTransactionAccount(transactionId, 'loan_payment', 'amount', 0);
                    this.updateTransactionAccount(transactionId, 'savings_withdrawal', 'apply_to_loan', false);
                    
                    const loanBalanceDisplay = row.querySelector('.loan-balance');
                    if (loanBalanceDisplay) {
                        loanBalanceDisplay.textContent = 'Fully Completed';
                        loanBalanceDisplay.className = 'loan-balance balance-display mt-1 text-success';
                        loanBalanceDisplay.style.display = 'block';
                    }
                    
                    if (savingsSelect) savingsSelect.disabled = true;
                    if (savingsDepositInput) savingsDepositInput.disabled = true;
                    if (withdrawalSelect) withdrawalSelect.disabled = true;
                    if (savingsWithdrawalInput) savingsWithdrawalInput.disabled = true;
                    
                    this.updateTransactionAccount(transactionId, 'savings_deposit', 'account_id', '');
                    this.updateTransactionAccount(transactionId, 'savings_deposit', 'amount', 0);
                    this.updateTransactionAccount(transactionId, 'savings_withdrawal', 'account_id', '');
                    this.updateTransactionAccount(transactionId, 'savings_withdrawal', 'amount', 0);
                } else if (!hasActiveLoans && hasSavingsBalance) {
                    const loanTd = row.querySelector('td:nth-child(3)');
                    loanTd.classList.add('loan-completed-section');
                    
                    loanSelect.disabled = true;
                    loanAmountInput.disabled = true;
                    loanAmountInput.placeholder = "Loan Completed";
                    
                    applyToLoanCheckbox.checked = false;
                    applyToLoanCheckbox.disabled = true;
                    
                    this.updateTransactionAccount(transactionId, 'loan_payment', 'account_id', '');
                    this.updateTransactionAccount(transactionId, 'loan_payment', 'amount', 0);
                    this.updateTransactionAccount(transactionId, 'savings_withdrawal', 'apply_to_loan', false);
                    
                    const loanBalanceDisplay = row.querySelector('.loan-balance');
                    if (loanBalanceDisplay) {
                        loanBalanceDisplay.textContent = 'Loan Completed';
                        loanBalanceDisplay.className = 'loan-balance balance-display mt-1 text-success';
                        loanBalanceDisplay.style.display = 'block';
                    }
                }
            }
            
            attachTransactionEventListeners(transactionId) {
                const row = document.getElementById(transactionId);
                if (!row) return;

                const loanSelect = row.querySelector('.loan-account-select');
                const loanAmountInput = row.querySelector('.loan-amount-input');
                const loanBalanceDisplay = row.querySelector('.loan-balance');
                const loanAccountIdDisplay = row.querySelector('.loan-account-id');

                loanSelect.addEventListener('change', () => {
                    const selectedOption = loanSelect.options[loanSelect.selectedIndex];
                    const balance = selectedOption.dataset.balance || 0;
                    const accountId = selectedOption.dataset.accountId || '';
                    
                    loanAmountInput.disabled = !loanSelect.value;
                    loanBalanceDisplay.textContent = `Balance: ₱${this.formatNumber(balance)}`;
                    loanBalanceDisplay.style.display = loanSelect.value ? 'block' : 'none';
                    loanBalanceDisplay.className = `loan-balance balance-display mt-1 ${parseFloat(balance) > 0 ? 'text-danger' : 'text-success'}`;
                    
                    loanAccountIdDisplay.textContent = accountId ? `Loan #${accountId}` : '';
                    loanAccountIdDisplay.style.display = loanSelect.value ? 'block' : 'none';
                    
                    if (loanSelect.value) {
                        loanSelect.classList.remove('auto-selected');
                        loanSelect.classList.remove('hidden-select');
                    }
                    
                    this.updateTransactionAccount(transactionId, 'loan_payment', 'account_id', loanSelect.value);
                });

                loanAmountInput.addEventListener('input', (e) => {
                    this.formatAmountInput(e.target);
                    this.updateTransactionAmount(transactionId, 'loan_payment', e.target.value);
                    this.calculateTotals();
                });

                const savingsSelect = row.querySelector('.savings-account-select');
                const savingsDepositInput = row.querySelector('.savings-deposit-input');
                const savingsDepositBalanceDisplay = row.querySelectorAll('.savings-balance')[0];
                const savingsDepositAccountIdDisplay = row.querySelector('.savings-deposit-account-id');

                savingsSelect.addEventListener('change', () => {
                    const selectedOption = savingsSelect.options[savingsSelect.selectedIndex];
                    const balance = selectedOption.dataset.balance || 0;
                    const accountId = selectedOption.dataset.accountId || '';
                    
                    savingsDepositInput.disabled = !savingsSelect.value;
                    savingsDepositBalanceDisplay.textContent = `Balance: ₱${this.formatNumber(balance)}`;
                    savingsDepositBalanceDisplay.style.display = savingsSelect.value ? 'block' : 'none';
                    savingsDepositBalanceDisplay.className = `savings-balance balance-display mt-1 ${parseFloat(balance) > 0 ? 'text-success' : 'text-muted'}`;
                    
                    savingsDepositAccountIdDisplay.textContent = accountId ? `Savings #${accountId}` : '';
                    savingsDepositAccountIdDisplay.style.display = savingsSelect.value ? 'block' : 'none';
                    
                    if (savingsSelect.value) {
                        savingsSelect.classList.remove('auto-selected');
                        savingsSelect.classList.remove('hidden-select');
                    }
                    
                    this.updateTransactionAccount(transactionId, 'savings_deposit', 'account_id', savingsSelect.value);
                });

                savingsDepositInput.addEventListener('input', (e) => {
                    this.formatAmountInput(e.target);
                    this.updateTransactionAmount(transactionId, 'savings_deposit', e.target.value);
                    this.calculateTotals();
                });

                const withdrawalSelect = row.querySelector('.savings-withdrawal-select');
                const savingsWithdrawalInput = row.querySelector('.savings-withdrawal-input');
                const savingsWithdrawalBalanceDisplay = row.querySelectorAll('.savings-balance')[1];
                const savingsWithdrawalAccountIdDisplay = row.querySelector('.savings-withdrawal-account-id');
                const applyToLoanCheckbox = row.querySelector('.apply-to-loan-checkbox');

                withdrawalSelect.addEventListener('change', () => {
                    const selectedOption = withdrawalSelect.options[withdrawalSelect.selectedIndex];
                    const balance = selectedOption.dataset.balance || 0;
                    const accountId = selectedOption.dataset.accountId || '';
                    
                    savingsWithdrawalInput.disabled = !withdrawalSelect.value;
                    savingsWithdrawalBalanceDisplay.textContent = `Balance: ₱${this.formatNumber(balance)}`;
                    savingsWithdrawalBalanceDisplay.style.display = withdrawalSelect.value ? 'block' : 'none';
                    savingsWithdrawalBalanceDisplay.className = `savings-balance balance-display mt-1 ${parseFloat(balance) > 0 ? 'text-success' : 'text-muted'}`;
                    
                    savingsWithdrawalAccountIdDisplay.textContent = accountId ? `Savings #${accountId}` : '';
                    savingsWithdrawalAccountIdDisplay.style.display = withdrawalSelect.value ? 'block' : 'none';
                    
                    applyToLoanCheckbox.disabled = !withdrawalSelect.value;
                    
                    if (withdrawalSelect.value) {
                        withdrawalSelect.classList.remove('auto-selected');
                        withdrawalSelect.classList.remove('hidden-select');
                    }
                    
                    this.updateTransactionAccount(transactionId, 'savings_withdrawal', 'account_id', withdrawalSelect.value);
                });

                savingsWithdrawalInput.addEventListener('input', (e) => {
                    this.formatAmountInput(e.target);
                    this.updateTransactionAmount(transactionId, 'savings_withdrawal', e.target.value);
                    this.calculateTotals();
                });

                applyToLoanCheckbox.addEventListener('change', (e) => {
                    this.updateTransactionAccount(transactionId, 'savings_withdrawal', 'apply_to_loan', e.target.checked);
                });

                const removeBtn = row.querySelector('.remove-transaction');
                removeBtn.addEventListener('click', () => this.removeTransaction(transactionId));
            }
            
            formatAmountInput(input) {
                const cursorPosition = input.selectionStart;
                const originalLength = input.value.length;
                
                let value = input.value.replace(/[^\d.]/g, '');
                const parts = value.split('.');
                parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                const formattedValue = parts.length > 1 ? parts[0] + '.' + parts[1] : parts[0];
                
                input.value = formattedValue;
                
                const newLength = input.value.length;
                const lengthDifference = newLength - originalLength;
                
                if (lengthDifference !== 0) {
                    input.setSelectionRange(
                        cursorPosition === originalLength ? newLength : cursorPosition + lengthDifference,
                        cursorPosition === originalLength ? newLength : cursorPosition + lengthDifference
                    );
                } else {
                    input.setSelectionRange(cursorPosition, cursorPosition);
                }
            }
            
            updateTransactionAccount(transactionId, type, field, value) {
                const transaction = this.transactions.get(transactionId);
                if (transaction && transaction[type]) {
                    transaction[type][field] = value;
                    this.updateTransactionsInput();
                }
            }
            
            updateTransactionAmount(transactionId, type, value) {
                const transaction = this.transactions.get(transactionId);
                if (transaction && transaction[type]) {
                    transaction[type].amount = this.parseFormattedNumber(value);
                    this.updateTransactionsInput();
                }
            }
            
            removeTransaction(transactionId) {
                const row = document.getElementById(transactionId);
                if (row) row.remove();
                
                this.transactions.delete(transactionId);
                this.updateTransactionsInput();
                this.calculateTotals();
                
                this.refreshCustomerModal();
            }
            
            refreshCustomerModal() {
                if (this.customerModal._isShown) {
                    this.renderCustomerTable();
                }
            }
            
            calculateTotals() {
                let loanTotal = 0;
                let depositTotal = 0;
                let withdrawalTotal = 0;
                
                this.transactions.forEach(transaction => {
                    loanTotal += transaction.loan_payment.amount || 0;
                    depositTotal += transaction.savings_deposit.amount || 0;
                    withdrawalTotal += transaction.savings_withdrawal.amount || 0;
                });
                
                document.getElementById('totalLoanPayment').textContent = '₱' + this.formatNumber(loanTotal);
                document.getElementById('totalSavingsDeposit').textContent = '₱' + this.formatNumber(depositTotal);
                document.getElementById('totalSavingsWithdrawal').textContent = '₱' + this.formatNumber(withdrawalTotal);
                
                const hasValidTransactions = Array.from(this.transactions.values()).some(txn => 
                    txn.loan_payment.amount > 0 || txn.savings_deposit.amount > 0 || txn.savings_withdrawal.amount > 0
                );
                
                this.submitBtn.disabled = !hasValidTransactions;
            }
            
            updateTransactionsInput() {
                const validTransactions = Array.from(this.transactions.values()).filter(txn => 
                    txn.loan_payment.amount > 0 || txn.savings_deposit.amount > 0 || txn.savings_withdrawal.amount > 0
                );
                
                this.transactionsInput.value = JSON.stringify(validTransactions);
            }
            
            clearAllTransactions() {
                this.transactions.clear();
                this.transactionsBody.innerHTML = '';
                this.updateTransactionsInput();
                this.calculateTotals();
            }
            
            async handleFormSubmit(e) {
                e.preventDefault();
                this.errorElement.textContent = '';
                
                const formData = new FormData(this.form);
                
                if (!this.isProxyCollector) {
                    const proxyId = this.proxyAccountSelect ? this.proxyAccountSelect.value : '';

                    if (proxyId) {
                        /* optional: record which proxy processed the batch */
                        formData.set('proxy_account_id', proxyId);
                        formData.set('is_proxy_collector', '1');
                    } else {
                        formData.delete('proxy_account_id');
                        formData.set('is_proxy_collector', '0');
                    }
                }
                
                const validTransactions = JSON.parse(this.transactionsInput.value);
                
                if (validTransactions.length === 0) {
                    this.showError('Please add at least one valid transaction');
                    return;
                }
                
                this.submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';
                this.submitBtn.disabled = true;
                
                try {
                    const response = await fetch(this.API_BASE_URL + 'process_payments.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (!response.ok) {
                        throw new Error(data.message || 'Server error');
                    }
                    
                    if (data.status === 'success') {
                        // Show success message first
                        await this.showAlert('success', 'Success', data.message);
                        
                        // ============================================================
                        // Open ONE combined PDF that contains one voucher page per customer.
                        // ============================================================
                        const withdrawalTransactions = data.withdrawal_transactions || [];
                        
                        if (withdrawalTransactions.length > 0) {
                            // Collect all transaction IDs into a comma-separated list
                            const transactionIds = withdrawalTransactions
                                .map(function(txn) { return txn.transaction_id; })
                                .join(',');
                            
                            // Open ONE PDF document with one page per customer
                            window.open(
                                'generate_savings_withdraw_voucher.php?transaction_ids=' + transactionIds,
                                '_blank'
                            );
                            
                            Swal.fire({
                                icon: 'info',
                                title: 'Vouchers Opened',
                                text: 'A single PDF with one savings withdrawal voucher per customer has been opened.',
                                timer: 3000,
                                showConfirmButton: false
                            }).then(() => {
                                window.location.reload();
                            });
                        } else {
                            window.location.reload();
                        }
                    } else {
                        throw new Error(data.message || 'Error recording transactions');
                    }
                } catch (error) {
                    console.error('Error:', error);
                    this.showError(error.message);
                    
                    await this.showAlert('error', 'Processing Error', error.message);
                } finally {
                    this.submitBtn.innerHTML = '<i class="fas fa-save me-2"></i>Process All Transactions';
                    this.submitBtn.disabled = false;
                }
            }
            
            formatNumber(num) {
                if (num === null || num === undefined || isNaN(num)) return '0.00';
                return parseFloat(num).toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            }
            
            parseFormattedNumber(str) {
                if (!str || str === '') return 0;
                const cleanStr = String(str).replace(/,/g, '');
                const number = parseFloat(cleanStr);
                return isNaN(number) ? 0 : number;
            }
            
            showAlert(icon, title, text) {
                return Swal.fire({
                    icon,
                    title,
                    text,
                    confirmButtonColor: '#3085d6',
                });
            }
            
            showError(message) {
                this.errorElement.textContent = message;
                this.errorElement.style.display = 'block';
            }
            
            disableTransactionSection(transactionId) {
                const row = document.getElementById(transactionId);
                if (row) {
                    row.querySelectorAll('select, input').forEach(element => {
                        element.disabled = true;
                    });
                }
            }
        }
        
        document.addEventListener('DOMContentLoaded', () => {
            window.paymentProcessor = new EnhancedPaymentProcessor();
        });
})();
