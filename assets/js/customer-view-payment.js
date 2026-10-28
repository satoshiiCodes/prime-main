/* ==========================================================================
   Prime City - Customer loan history behaviour
   Modals, payments, savings, penalties, the payment calendar and the
   configuration rendered by customer_view_payment.php.
   ========================================================================== */

(function () {
    var pageConfigCache = null;

    /**
     * Page configuration from #customer-view-payment-data.
     */
    function pageConfig() {
        if (pageConfigCache) {
            return pageConfigCache;
        }

        var node = document.getElementById('customer-view-payment-data');

        if (!node) {
            pageConfigCache = {};
            return pageConfigCache;
        }

        try {
            pageConfigCache = JSON.parse(node.textContent || '{}');
        } catch (error) {
            console.error('[customer-view-payment] Could not parse page configuration.', error);
            pageConfigCache = {};
        }

        return pageConfigCache;
    }

        document.addEventListener('DOMContentLoaded', function() {
            const pageCfg = pageConfig();
            // View loan button handler
            document.querySelectorAll('.view-loan-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const loanId = this.getAttribute('data-loan-id');
                    const modal = new bootstrap.Modal(document.getElementById('viewLoanModal'));
                    
                    document.getElementById('loanDetailsContent').innerHTML = `
                        <div class="text-center py-4">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p class="mt-2">Loading loan details...</p>
                        </div>
                    `;
                    
                    const promissoryNoteBtn = document.getElementById('promissoryNoteBtn');
                    if (promissoryNoteBtn) {
                        promissoryNoteBtn.href = `generate_promissory_note.php?loan_id=${loanId}`;
                    }
                    
                    fetch(`api/get_loan_details.php?id=${loanId}`)
                        .then(response => {
                            if (!response.ok) {
                                throw new Error('Network response was not ok');
                            }
                            return response.json();
                        })
                        .then(data => {
                            if (data.error) {
                                throw new Error(data.error);
                            }
                            
                            let collectorName = 'N/A';
                            if (data.collector_first_name || data.collector_last_name) {
                                collectorName = (data.collector_first_name || '') + ' ' + (data.collector_last_name || '');
                            }
                            
                            let customerName = 'N/A';
                            if (data.customer_first_name || data.customer_last_name) {
                                customerName = (data.customer_last_name || '') + ', ' + (data.customer_first_name || '');
                                if (data.customer_middle_name) {
                                    customerName += ' ' + data.customer_middle_name;
                                }
                                if (data.customer_suffix && data.customer_suffix !== 'None') {
                                    customerName += ', ' + data.customer_suffix;
                                }
                            }
                            
                            const totalAmount = (parseFloat(data.amount_financed) || 0) + (parseFloat(data.interest_peso) || 0) + (parseFloat(data.penalty) || 0);
                            
                            const paidAmount = parseFloat(data.total_paid) || 0;
                            const progress = totalAmount > 0 ? (paidAmount / totalAmount) * 100 : 0;
                            const remainingBalance = totalAmount - paidAmount;
                            
                            function formatNumber(num) {
                                return parseFloat(num).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
                            }
                            
                            let statusDisplay = '';
                            if (data.status === 'active') {
                                statusDisplay = `
                                    <span class="status-active">
                                        <i class="fas fa-circle-notch fa-spin me-1"></i> Active
                                    </span>
                                `;
                            } else if (data.status === 'completed') {
                                statusDisplay = `
                                    <span class="status-completed">
                                        <i class="fas fa-check-circle me-1"></i> Completed
                                    </span>
                                `;
                            } else if (data.status === 'defaulted') {
                                statusDisplay = `
                                    <span class="status-defaulted">
                                        <i class="fas fa-exclamation-circle me-1"></i> Defaulted
                                    </span>
                                `;
                            }
                            
                            document.getElementById('loanDetailsContent').innerHTML = `
                                <div class="loan-details">
                                    <div class="mb-3">
                                        <h5 class="fw-bold">Loan #${data.id}</h5>
                                        <p class="text-muted">${data.start_date ? data.start_date.split(' ')[0] : 'N/A'}</p>
                                    </div>

                                    <div class="row mb-4">
                                        <div class="col-md-12">
                                            <div class="card border-1 shadow-sm">
                                                <div class="card-body text-center">
                                                    <h6 class="text-muted">Loan Amount</h6>
                                                    <p class="fs-4 fw-bold">₱${formatNumber(totalAmount.toFixed(2))}</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row mb-4">
                                        <div class="col-md-3">
                                            <div class="card border-1 shadow-sm">
                                                <div class="card-body text-center">
                                                    <h6 class="text-muted">Amount Paid</h6>
                                                    <p class="fs-4 fw-bold text-success">₱${formatNumber(paidAmount.toFixed(2))}</p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="card border-1 shadow-sm">
                                                <div class="card-body text-center">
                                                    <h6 class="text-muted">Remaining Balance</h6>
                                                    <p class="fs-4 fw-bold text-danger">₱${formatNumber(remainingBalance.toFixed(2))}</p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="card border-1 shadow-sm">
                                                <div class="card-body text-center">
                                                    <h6 class="text-muted">Total Savings</h6>
                                                    <p class="fs-4 fw-bold text-info">₱${formatNumber(parseFloat(data.total_savings || 0).toFixed(2))}</p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="card border-1 shadow-sm">
                                                <div class="card-body text-center">
                                                    <h6 class="text-muted">Total Penalty</h6>
                                                    <p class="fs-4 fw-bold text-warning">₱${formatNumber(parseFloat(data.penalty || 0).toFixed(2))}</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <h6 class="fw-bold">Repayment Progress</h6>
                                        <div class="progress pc-progress-bar" role="progressbar"
                                            aria-valuenow="${progress}"
                                            aria-valuemin="0"
                                            aria-valuemax="100">
                                            <div class="progress-bar" style="width: ${Math.min(progress, 100)}%"></div>
                                            <span class="pc-progress-bar__label">${Math.min(progress, 100).toFixed(1)}%</span>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <table class="table table-borderless loan-details-table">
                                                <tr>
                                                    <th>Status:</th>
                                                    <td>${statusDisplay}</td>
                                                </tr>
                                                <tr>
                                                    <th>Amount Financed:</th>
                                                    <td>₱${formatNumber(parseFloat(data.amount_financed || 0).toFixed(2))}</td>
                                                </tr>
                                                <tr>
                                                    <th>Interest (Peso):</th>
                                                    <td>₱${formatNumber(parseFloat(data.interest_peso || 0).toFixed(2))}</td>
                                                </tr>
                                                <tr>
                                                    <th>Savings:</th>
                                                    <td class="text-info fw-bold">₱${formatNumber(parseFloat(data.total_savings || 0).toFixed(2))}</td>
                                                </tr>
                                                <tr>
                                                    <th>Penalty:</th>
                                                    <td class="text-danger fw-bold">₱${formatNumber(parseFloat(data.penalty || 0).toFixed(2))}</td>
                                                </tr>
                                                <tr>
                                                    <th>Interest Rate:</th>
                                                    <td>${data.interest || 'N/A'}%</td>
                                                </tr>
                                                <tr>
                                                    <th>Term:</th>
                                                    <td>${data.loan_term || 'N/A'} days</td>
                                                </tr>
                                                <tr>
                                                    <th>Start Date:</th>
                                                    <td>${data.start_date ? data.start_date.split(' ')[0] : 'N/A'}</td>
                                                </tr>
                                                <tr>
                                                    <th>End Date:</th>
                                                    <td>${data.end_date || 'N/A'}</td>
                                                </tr>
                                            </table>
                                        </div>
                                        <div class="col-md-6">
                                            <table class="table table-borderless loan-details-table">
                                                <tr>
                                                    <th>Customer:</th>
                                                    <td>${customerName}</td>
                                                </tr>
                                                <tr>
                                                    <th>Collector:</th>
                                                    <td>${collectorName}</td>
                                                </tr>
                                                <tr>
                                                    <th>Daily Payment:</th>
                                                    <td>₱${formatNumber(parseFloat(data.daily_payment || 0).toFixed(2))}</td>
                                                </tr>
                                                <tr>
                                                    <th>Service Fee:</th>
                                                    <td>₱${formatNumber(parseFloat(data.service_fee || 0).toFixed(2))}</td>
                                                </tr>
                                                <tr>
                                                    <th>Notes:</th>
                                                    <td>${data.notes || 'No notes available'}</td>
                                                </tr>
                                            </table>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-4">
                                        <h6 class="fw-bold mb-3">Payment History</h6>
                                        
                                        <div class="alert alert-light mb-3">
                                            <div class="row g-2">
                                                <div class="col-md-3">
                                                    <span class="text-success"><i class="fas fa-circle me-1"></i></span> Cash Payment
                                                </div>
                                                <div class="col-md-4">
                                                    <span class="text-warning"><i class="fas fa-circle me-1"></i></span> Payment from Savings
                                                </div>
                                                <div class="col-md-3">
                                                    <span class="text-info"><i class="fas fa-circle me-1"></i></span> GCash Payment
                                                </div>
                                                <div class="col-md-2">
                                                    <span class="text-secondary"><i class="fas fa-circle me-1"></i></span> Other Methods
                                                </div>
                                            </div>
                                        </div>
                                        
                                        ${data.payments && data.payments.length > 0 ? `
                                            <div class="table-responsive">
                                                <table class="table table-sm table-bordered table-hover">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th class="text-nowrap">Date</th>
                                                            <th class="text-nowrap">Amount</th>
                                                            <th class="text-nowrap">Penalty</th>
                                                            <th class="text-nowrap">Savings Amount</th>
                                                            <th class="text-nowrap">Savings Balance</th>
                                                            <th class="text-nowrap">Loan Balance</th>
                                                            <th class="text-nowrap">Method</th>
                                                            <th class="text-nowrap">Collector</th>
                                                            <th class="text-nowrap">Proxy</th>
                                                            <th class="text-nowrap">Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        ${data.payments.map(payment => {
                                                            let amountClass = '';
                                                            let methodIcon = '';
                                                            
                                                            const isFromSavings = payment.notes && 
                                                                                payment.notes.toLowerCase().includes('payment from savings');
                                                            
                                                            if (isFromSavings) {
                                                                amountClass = 'text-warning fw-bold';
                                                                methodIcon = '<i class="fas fa-piggy-bank me-1"></i>';
                                                            } else if (payment.payment_method === 'Cash') {
                                                                amountClass = 'text-success fw-bold';
                                                                methodIcon = '<i class="fas fa-money-bill-wave me-1"></i>';
                                                            } else if (payment.payment_method === 'GCash') {
                                                                amountClass = 'text-info fw-bold';
                                                                methodIcon = '<i class="fas fa-mobile-alt me-1"></i>';
                                                            } else {
                                                                amountClass = 'text-secondary';
                                                                methodIcon = '<i class="fas fa-money-check me-1"></i>';
                                                            }
                                                            
                                                            const penaltyAmount = parseFloat(payment.penalty_amount || 0);
                                                            const penaltyClass = penaltyAmount > 0 ? 'text-danger fw-bold' : 'text-muted';
                                                            
                                                            const savingsAmount = parseFloat(payment.savings_amount || 0);
                                                            const savingsBalance = parseFloat(payment.savings_balance || 0);
                                                            const loanBalance = parseFloat(payment.loan_balance || 0);
                                                            
                                                            const showPaymentActions = pageCfg.canManagePayments;
                                                            
                                                            const displayDate = payment.payment_date ? payment.payment_date : 'N/A';
                                                            
                                                            return `
                                                                <tr>
                                                                    <td class="text-nowrap">${displayDate}</td>
                                                                    <td class="${amountClass} text-nowrap">₱${formatNumber(payment.amount)}</td>
                                                                    <td class="${penaltyClass} text-nowrap">₱${formatNumber(penaltyAmount.toFixed(2))}</td>
                                                                    <td class="fw-bold text-nowrap">₱${formatNumber(savingsAmount.toFixed(2))}</td>
                                                                    <td class="fw-bold text-nowrap">₱${formatNumber(savingsBalance.toFixed(2))}</td>
                                                                    <td class="fw-bold text-danger text-nowrap">₱${formatNumber(loanBalance.toFixed(2))}</td>
                                                                    <td class="text-nowrap">${methodIcon}${payment.payment_method_display || payment.payment_method || 'N/A'}</td>
                                                                    <td class="text-nowrap">${payment.collector_first_name || ''} ${payment.collector_last_name || ''}</td>
                                                                    <td class="text-nowrap">${payment.proxy_name || 'N/A'}</td>
                                                                    <td class="text-nowrap">
                                                                        ${payment.show_actions && showPaymentActions ? `
                                                                            <button class="btn btn-sm btn-primary view-payment-btn" data-payment-id="${payment.id}" title="View">
                                                                                <i class="fas fa-eye"></i>
                                                                            </button>
                                                                            ${pageCfg.canEditDelete ? `
                                                                                <button class="btn btn-sm btn-warning edit-payment-btn" data-payment-id="${payment.id}" data-payment-amount="${payment.amount}" title="Edit">
                                                                                    <i class="fas fa-edit"></i>
                                                                                </button>
                                                                            ` : ''}
                                                                            ${pageCfg.canEditDelete ? `
                                                                                <button class="btn btn-sm btn-danger delete-payment-btn" data-payment-id="${payment.id}" data-payment-amount="${payment.amount}" title="Delete">
                                                                                    <i class="fas fa-trash-alt"></i>
                                                                                </button>
                                                                            ` : ''}
                                                                        ` : `
                                                                            <span class="text-muted">No Actions</span>
                                                                        `}
                                                                    </td>
                                                                </tr>
                                                            `;
                                                        }).join('')}
                                                    </tbody>
                                                </table>
                                            </div>
                                        ` : `
                                            <div class="alert alert-info">
                                                <i class="fas fa-info-circle me-2"></i>
                                                No payment history recorded for this loan.
                                            </div>
                                        `}
                                    </div>
                                    
                                    <div class="mt-4">
                                        <h6 class="fw-bold mb-3">Savings Transaction History</h6>
                                        ${data.savings_deposits && data.savings_deposits.length > 0 ? `
                                            <div class="table-responsive">
                                                <table class="table table-sm">
                                                    <thead>
                                                        <tr>
                                                            <th>Date</th>
                                                            <th>Type</th>
                                                            <th>Amount</th>
                                                            <th>Method</th>
                                                            <th>Collector</th>
                                                            <th>Proxy</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        ${data.savings_deposits.map(deposit => {
                                                            let amountClass = 'fw-bold ';
                                                            if (deposit.transaction_type === 'Deposit') {
                                                                amountClass += 'text-success';
                                                            } else if (deposit.transaction_type === 'Withdrawal Cash') {
                                                                amountClass += 'text-danger';
                                                            } else {
                                                                amountClass += 'text-secondary';
                                                            }
                                                            
                                                            const showDepositActions = pageCfg.canManagePayments;
                                                            
                                                            return `
                                                                <tr>
                                                                    <td>${deposit.transaction_date}</td>
                                                                    <td>${deposit.transaction_type || 'N/A'}</td>
                                                                    <td class="${amountClass}">₱${formatNumber(deposit.amount)}</td>
                                                                    <td>${deposit.payment_method || 'N/A'}</td>
                                                                    <td>${deposit.collector_first_name || ''} ${deposit.collector_last_name || ''}</td>
                                                                    <td>${deposit.proxy_name || 'N/A'}</td>
                                                                    <td>
                                                                        ${deposit.show_actions !== false && showDepositActions ? `
                                                                            <button class="btn btn-sm btn-primary view-savings-deposit-btn" data-deposit-id="${deposit.id}">
                                                                                <i class="fas fa-eye"></i>
                                                                            </button>
                                                                            ${pageCfg.canEditDelete ? `
                                                                                <button class="btn btn-sm btn-warning edit-savings-deposit-btn" data-deposit-id="${deposit.id}" data-deposit-amount="${deposit.amount}">
                                                                                    <i class="fas fa-edit"></i>
                                                                                </button>
                                                                            ` : ''}
                                                                            ${pageCfg.canEditDelete ? `
                                                                                <button class="btn btn-sm btn-danger delete-savings-deposit-btn" data-deposit-id="${deposit.id}" data-deposit-amount="${deposit.amount}">
                                                                                    <i class="fas fa-trash-alt"></i>
                                                                                </button>
                                                                            ` : ''}
                                                                        ` : `
                                                                            <span class="text-muted">No Actions</span>
                                                                        `}
                                                                    </td>
                                                                </tr>
                                                            `;
                                                        }).join('')}
                                                    </tbody>
                                                </table>
                                            </div>
                                        ` : `
                                            <div class="alert alert-info">
                                                <i class="fas fa-info-circle me-2"></i>
                                                No savings deposit history recorded for this loan.
                                            </div>
                                        `}
                                    </div>
                                    
                                    <div class="mt-4">
                                        <h6 class="fw-bold mb-3">Penalties History</h6>
                                        ${data.penalties && data.penalties.length > 0 ? `
                                            <div class="table-responsive">
                                                <table class="table table-sm">
                                                    <thead>
                                                        <tr>
                                                            <th>Date</th>
                                                            <th>Type</th>
                                                            <th>Amount</th>
                                                            <th>Notes</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        ${data.penalties.map(penalty => {
                                                            let badgeClass = '';
                                                            let badgeText = '';
                                                            
                                                            switch(penalty.type) {
                                                                case 'added':
                                                                    badgeClass = 'penalty-type-added';
                                                                    badgeText = 'Added';
                                                                    break;
                                                                case 'waived':
                                                                    badgeClass = 'penalty-type-waived';
                                                                    badgeText = 'Waived';
                                                                    break;
                                                                case 'partial waiver':
                                                                    badgeClass = 'penalty-type-partial-waiver';
                                                                    badgeText = 'Partial Waiver';
                                                                    break;
                                                                default:
                                                                    badgeClass = 'bg-secondary';
                                                                    badgeText = penalty.type || 'Unknown';
                                                            }
                                                            
                                                            return `
                                                                <tr>
                                                                    <td>${penalty.date || 'N/A'}</td>
                                                                    <td>
                                                                        <span class="badge ${badgeClass}">${badgeText}</span>
                                                                    </td>
                                                                    <td class="fw-bold ${penalty.type === 'added' ? 'text-success' : (penalty.type === 'waived' ? 'text-danger' : 'text-warning')}">
                                                                        ₱${formatNumber(penalty.amount)}
                                                                    </td>
                                                                    <td class="notes-column" title="${penalty.notes || 'No notes'}">${penalty.notes || 'No notes'}</td>
                                                                </tr>
                                                            `;
                                                        }).join('')}
                                                    </tbody>
                                                </table>
                                            </div>
                                        ` : `
                                            <div class="alert alert-info">
                                                <i class="fas fa-info-circle me-2"></i>
                                                No penalties history recorded for this loan.
                                            </div>
                                        `}
                                    </div>
                                </div>
                            `;

                            const printLoanBtn = document.getElementById('printLoanBtn');
                            if (printLoanBtn) {
                                printLoanBtn.onclick = function() {
                                    window.open(`print_loan.php?id=${loanId}`, '_blank');
                                };
                            }
                            
                            const refreshLoanBtn = document.getElementById('refreshLoanBtn');
                            if (refreshLoanBtn) {
                                refreshLoanBtn.addEventListener('click', function() {
                                    const modalTitle = document.getElementById('viewLoanModalLabel');
                                    const loanIdMatch = modalTitle.textContent.match(/Loan #(\d+)/);
                                    if (!loanIdMatch) return;
                                    
                                    const loanId = loanIdMatch[1];
                                    
                                    document.getElementById('loanDetailsContent').innerHTML = `
                                        <div class="text-center py-4">
                                            <div class="spinner-border text-primary" role="status">
                                                <span class="visually-hidden">Loading...</span>
                                            </div>
                                            <p class="mt-2">Loading loan details...</p>
                                        </div>
                                    `;
                                    
                                    const promissoryNoteBtn = document.getElementById('promissoryNoteBtn');
                                    if (promissoryNoteBtn) {
                                        promissoryNoteBtn.href = `generate_promissory_note.php?loan_id=${loanId}`;
                                    }
                                    
                                    fetch(`api/get_loan_details.php?id=${loanId}`)
                                        .then(response => {
                                            if (!response.ok) {
                                                throw new Error('Network response was not ok');
                                            }
                                            return response.json();
                                        })
                                        .then(data => {
                                            if (data.error) {
                                                throw new Error(data.error);
                                            }
                                            
                                            let collectorName = 'N/A';
                                            if (data.collector_first_name || data.collector_last_name) {
                                                collectorName = (data.collector_first_name || '') + ' ' + (data.collector_last_name || '');
                                            }
                                            
                                            let customerName = 'N/A';
                                            if (data.customer_first_name || data.customer_last_name) {
                                                customerName = (data.customer_last_name || '') + ', ' + (data.customer_first_name || '');
                                                if (data.customer_middle_name) {
                                                    customerName += ' ' + data.customer_middle_name;
                                                }
                                                if (data.customer_suffix && data.customer_suffix !== 'None') {
                                                    customerName += ', ' + data.customer_suffix;
                                                }
                                            }
                                            
                                            const totalAmount = (parseFloat(data.amount_financed) || 0) + (parseFloat(data.interest_peso) || 0) + (parseFloat(data.penalty) || 0);
                                            
                                            const paidAmount = parseFloat(data.total_paid) || 0;
                                            const progress = totalAmount > 0 ? (paidAmount / totalAmount) * 100 : 0;
                                            const remainingBalance = totalAmount - paidAmount;
                                            
                                            function formatNumber(num) {
                                                return parseFloat(num).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
                                            }
                                            
                                            let statusDisplay = '';
                                            if (data.status === 'active') {
                                                statusDisplay = `
                                                    <span class="status-active">
                                                        <i class="fas fa-circle-notch fa-spin me-1"></i> Active
                                                    </span>
                                                `;
                                            } else if (data.status === 'completed') {
                                                statusDisplay = `
                                                    <span class="status-completed">
                                                        <i class="fas fa-check-circle me-1"></i> Completed
                                                    </span>
                                                `;
                                            } else if (data.status === 'defaulted') {
                                                statusDisplay = `
                                                    <span class="status-defaulted">
                                                        <i class="fas fa-exclamation-circle me-1"></i> Defaulted
                                                    </span>
                                                `;
                                            }
                                            
                                            document.getElementById('loanDetailsContent').innerHTML = `
                                                <div class="loan-details">
                                                    <div class="mb-3">
                                                        <h5 class="fw-bold">Loan #${data.id}</h5>
                                                        <p class="text-muted">${data.start_date ? data.start_date.split(' ')[0] : 'N/A'}</p>
                                                    </div>
                                                    
                                                    <div class="row mb-4">
                                                        <div class="col-md-3">
                                                            <div class="card border-0 shadow-sm">
                                                                <div class="card-body text-center">
                                                                    <h6 class="text-muted">Loan Amount</h6>
                                                                    <p class="fs-4 fw-bold">₱${formatNumber(totalAmount.toFixed(2))}</p>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-3">
                                                            <div class="card border-0 shadow-sm">
                                                                <div class="card-body text-center">
                                                                    <h6 class="text-muted">Amount Paid</h6>
                                                                    <p class="fs-4 fw-bold text-success">₱${formatNumber(paidAmount.toFixed(2))}</p>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-3">
                                                            <div class="card border-0 shadow-sm">
                                                                <div class="card-body text-center">
                                                                    <h6 class="text-muted">Remaining Balance</h6>
                                                                    <p class="fs-4 fw-bold text-danger">₱${formatNumber(remainingBalance.toFixed(2))}</p>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-3">
                                                            <div class="card border-0 shadow-sm">
                                                                <div class="card-body text-center">
                                                                    <h6 class="text-muted">Total Savings</h6>
                                                                    <p class="fs-4 fw-bold text-info">₱${formatNumber(parseFloat(data.total_savings || 0).toFixed(2))}</p>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-3">
                                                            <div class="card border-0 shadow-sm">
                                                                <div class="card-body text-center">
                                                                    <h6 class="text-muted">Total Penalty</h6>
                                                                    <p class="fs-4 fw-bold text-warning">₱${formatNumber(parseFloat(data.penalty || 0).toFixed(2))}</p>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="mb-3">
                                                        <h6 class="fw-bold">Repayment Progress</h6>
                                                        <div class="progress mb-2" style="height: 20px;">
                                                            <div class="progress-bar bg-success" role="progressbar" 
                                                                style="width: ${Math.min(progress, 100)}%" 
                                                                aria-valuenow="${progress}" 
                                                                aria-valuemin="0" 
                                                                aria-valuemax="100">
                                                                ${Math.min(progress, 100).toFixed(1)}%
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="row">
                                                        <div class="col-md-6">
                                                            <table class="table table-borderless loan-details-table">
                                                                <tr>
                                                                    <th>Status:</th>
                                                                    <td>${statusDisplay}</td>
                                                                </tr>
                                                                <tr>
                                                                    <th>Amount Financed:</th>
                                                                    <td>₱${formatNumber(parseFloat(data.amount_financed || 0).toFixed(2))}</td>
                                                                </tr>
                                                                <tr>
                                                                    <th>Interest (Peso):</th>
                                                                    <td>₱${formatNumber(parseFloat(data.interest_peso || 0).toFixed(2))}</td>
                                                                </tr>
                                                                <tr>
                                                                    <th>Total Savings:</th>
                                                                    <td class="text-info fw-bold">₱${formatNumber(parseFloat(data.total_savings || 0).toFixed(2))}</td>
                                                                </tr>
                                                                <tr>
                                                                    <th>Penalty:</th>
                                                                    <td class="text-danger fw-bold">₱${formatNumber(parseFloat(data.penalty || 0).toFixed(2))}</td>
                                                                </tr>
                                                                <tr>
                                                                    <th>Interest Rate:</th>
                                                                    <td>${data.interest || 'N/A'}%</td>
                                                                </tr>
                                                                <tr>
                                                                    <th>Term:</th>
                                                                    <td>${data.loan_term || 'N/A'} days</td>
                                                                </tr>
                                                                <tr>
                                                                    <th>Start Date:</th>
                                                                    <td>${data.start_date ? data.start_date.split(' ')[0] : 'N/A'}</td>
                                                                </tr>
                                                                <tr>
                                                                    <th>End Date:</th>
                                                                    <td>${data.end_date || 'N/A'}</td>
                                                                </tr>
                                                            </table>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <table class="table table-borderless loan-details-table">
                                                                <tr>
                                                                    <th>Customer:</th>
                                                                    <td>${customerName}</td>
                                                                </tr>
                                                                <tr>
                                                                    <th>Collector:</th>
                                                                    <td>${collectorName}</td>
                                                                </tr>
                                                                <tr>
                                                                    <th>Daily Payment:</th>
                                                                    <td>₱${formatNumber(parseFloat(data.daily_payment || 0).toFixed(2))}</td>
                                                                </tr>
                                                                <tr>
                                                                    <th>Service Fee:</th>
                                                                    <td>₱${formatNumber(parseFloat(data.service_fee || 0).toFixed(2))}</td>
                                                                </tr>
                                                                <tr>
                                                                    <th>Notes:</th>
                                                                    <td>${data.notes || 'No notes available'}</td>
                                                                </tr>
                                                            </table>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="mt-4">
                                                        <h6 class="fw-bold mb-3">Payment History</h6>
                                                        
                                                        <div class="alert alert-light mb-3">
                                                            <div class="row g-2">
                                                                <div class="col-md-3">
                                                                    <span class="text-success"><i class="fas fa-circle me-1"></i></span> Cash Payment
                                                                </div>
                                                                <div class="col-md-4">
                                                                    <span class="text-warning"><i class="fas fa-circle me-1"></i></span> Payment from Savings
                                                                </div>
                                                                <div class="col-md-3">
                                                                    <span class="text-info"><i class="fas fa-circle me-1"></i></span> GCash Payment
                                                                </div>
                                                                <div class="col-md-2">
                                                                    <span class="text-secondary"><i class="fas fa-circle me-1"></i></span> Other Methods
                                                                </div>
                                                            </div>
                                                        </div>
                                                        
                                                        ${data.payments && data.payments.length > 0 ? `
                                                            <div class="table-responsive">
                                                                <table class="table table-sm table-bordered table-hover">
                                                                    <thead class="table-light">
                                                                        <tr>
                                                                            <th class="text-nowrap">Date</th>
                                                                            <th class="text-nowrap">Amount</th>
                                                                            <th class="text-nowrap">Penalty</th>
                                                                            <th class="text-nowrap">Savings Amount</th>
                                                                            <th class="text-nowrap">Savings Balance</th>
                                                                            <th class="text-nowrap">Loan Balance</th>
                                                                            <th class="text-nowrap">Method</th>
                                                                            <th class="text-nowrap">Collector</th>
                                                                            <th class="text-nowrap">Proxy</th>
                                                                            <th class="text-nowrap">Actions</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                        ${data.payments.map(payment => {
                                                                            let amountClass = '';
                                                                            let methodIcon = '';
                                                                            
                                                                            const isFromSavings = payment.notes && 
                                                                                                payment.notes.toLowerCase().includes('payment from savings');
                                                                            
                                                                            if (isFromSavings) {
                                                                                amountClass = 'text-warning fw-bold';
                                                                                methodIcon = '<i class="fas fa-piggy-bank me-1"></i>';
                                                                            } else if (payment.payment_method === 'Cash') {
                                                                                amountClass = 'text-success fw-bold';
                                                                                methodIcon = '<i class="fas fa-money-bill-wave me-1"></i>';
                                                                            } else if (payment.payment_method === 'GCash') {
                                                                                amountClass = 'text-info fw-bold';
                                                                                methodIcon = '<i class="fas fa-mobile-alt me-1"></i>';
                                                                            } else {
                                                                                amountClass = 'text-secondary';
                                                                                methodIcon = '<i class="fas fa-money-check me-1"></i>';
                                                                            }
                                                                            
                                                                            const penaltyAmount = parseFloat(payment.penalty_amount || 0);
                                                                            const penaltyClass = penaltyAmount > 0 ? 'text-danger fw-bold' : 'text-muted';
                                                                            
                                                                            const savingsAmount = parseFloat(payment.savings_amount || 0);
                                                                            const savingsBalance = parseFloat(payment.savings_balance || 0);
                                                                            const loanBalance = parseFloat(payment.loan_balance || 0);
                                                                            
                                                                            const showPaymentActions = pageCfg.canManagePayments;
                                                                            
                                                                            const displayDate = payment.payment_date ? payment.payment_date : 'N/A';
                                                                            
                                                                            return `
                                                                                <tr>
                                                                                    <td class="text-nowrap">${displayDate}</td>
                                                                                    <td class="${amountClass} text-nowrap">₱${formatNumber(payment.amount)}</td>
                                                                                    <td class="${penaltyClass} text-nowrap">₱${formatNumber(penaltyAmount.toFixed(2))}</td>
                                                                                    <td class="fw-bold text-nowrap">₱${formatNumber(savingsAmount.toFixed(2))}</td>
                                                                                    <td class="fw-bold text-nowrap">₱${formatNumber(savingsBalance.toFixed(2))}</td>
                                                                                    <td class="fw-bold text-danger text-nowrap">₱${formatNumber(loanBalance.toFixed(2))}</td>
                                                                                    <td class="text-nowrap">${methodIcon}${payment.payment_method_display || payment.payment_method || 'N/A'}</td>
                                                                                    <td class="text-nowrap">${payment.collector_first_name || ''} ${payment.collector_last_name || ''}</td>
                                                                                    <td class="text-nowrap">${payment.proxy_name || 'N/A'}</td>
                                                                                    <td class="text-nowrap">
                                                                                        ${payment.show_actions && showPaymentActions ? `
                                                                                            <div class="btn-group btn-group-sm" role="group">
                                                                                                <button class="btn btn-primary view-payment-btn" data-payment-id="${payment.id}" title="View">
                                                                                                    <i class="fas fa-eye"></i>
                                                                                                </button>
                                                                                                ${pageCfg.canEditDelete ? `
                                                                                                    <button class="btn btn-warning edit-payment-btn" data-payment-id="${payment.id}" data-payment-amount="${payment.amount}" title="Edit">
                                                                                                        <i class="fas fa-edit"></i>
                                                                                                    </button>
                                                                                                ` : ''}
                                                                                                ${pageCfg.canEditDelete ? `
                                                                                                    <button class="btn btn-danger delete-payment-btn" data-payment-id="${payment.id}" data-payment-amount="${payment.amount}" title="Delete">
                                                                                                        <i class="fas fa-trash-alt"></i>
                                                                                                    </button>
                                                                                                ` : ''}
                                                                                            </div>
                                                                                        ` : `
                                                                                            <span class="text-muted">No Actions</span>
                                                                                        `}
                                                                                    </td>
                                                                                </tr>
                                                                            `;
                                                                        }).join('')}
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                        ` : `
                                                            <div class="alert alert-info">
                                                                <i class="fas fa-info-circle me-2"></i>
                                                                No payment history recorded for this loan.
                                                            </div>
                                                        `}
                                                    </div>
                                                    
                                                    <div class="mt-4">
                                                        <h6 class="fw-bold mb-3">Savings Transaction History</h6>
                                                        ${data.savings_deposits && data.savings_deposits.length > 0 ? `
                                                            <div class="table-responsive">
                                                                <table class="table table-sm">
                                                                    <thead>
                                                                        <tr>
                                                                            <th>Date</th>
                                                                            <th>Type</th>
                                                                            <th>Amount</th>
                                                                            <th>Method</th>
                                                                            <th>Collector</th>
                                                                            <th>Proxy</th>
                                                                            <th>Actions</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                        ${data.savings_deposits.map(deposit => {
                                                                            let amountClass = 'fw-bold ';
                                                                            if (deposit.transaction_type === 'Deposit') {
                                                                                amountClass += 'text-success';
                                                                            } else if (deposit.transaction_type === 'Withdrawal Cash') {
                                                                                amountClass += 'text-danger';
                                                                            } else {
                                                                                amountClass += 'text-secondary';
                                                                            }
                                                                            
                                                                            const showDepositActions = pageCfg.canManagePayments;
                                                                            
                                                                            return `
                                                                                <tr>
                                                                                    <td>${deposit.transaction_date}</td>
                                                                                    <td>${deposit.transaction_type || 'N/A'}</td>
                                                                                    <td class="${amountClass}">₱${formatNumber(deposit.amount)}</td>
                                                                                    <td>${deposit.payment_method || 'N/A'}</td>
                                                                                    <td>${deposit.collector_first_name || ''} ${deposit.collector_last_name || ''}</td>
                                                                                    <td>${deposit.proxy_name || 'N/A'}</td>
                                                                                    <td>
                                                                                        ${deposit.show_actions !== false && showDepositActions ? `
                                                                                            <button class="btn btn-sm btn-primary view-savings-deposit-btn" data-deposit-id="${deposit.id}">
                                                                                                <i class="fas fa-eye"></i>
                                                                                            </button>
                                                                                            ${pageCfg.canEditDelete ? `
                                                                                                <button class="btn btn-sm btn-warning edit-savings-deposit-btn" data-deposit-id="${deposit.id}" data-deposit-amount="${deposit.amount}">
                                                                                                    <i class="fas fa-edit"></i>
                                                                                                </button>
                                                                                            ` : ''}
                                                                                            ${pageCfg.canEditDelete ? `
                                                                                                <button class="btn btn-sm btn-danger delete-savings-deposit-btn" data-deposit-id="${deposit.id}" data-deposit-amount="${deposit.amount}">
                                                                                                    <i class="fas fa-trash-alt"></i>
                                                                                                </button>
                                                                                            ` : ''}
                                                                                        ` : `
                                                                                            <span class="text-muted">No Actions</span>
                                                                                        `}
                                                                                    </td>
                                                                                </tr>
                                                                            `;
                                                                        }).join('')}
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                        ` : `
                                                            <div class="alert alert-info">
                                                                <i class="fas fa-info-circle me-2"></i>
                                                                No savings deposit history recorded for this loan.
                                                            </div>
                                                        `}
                                                    </div>
                                                    
                                                    <div class="mt-4">
                                                        <h6 class="fw-bold mb-3">Penalties History</h6>
                                                        ${data.penalties && data.penalties.length > 0 ? `
                                                            <div class="table-responsive">
                                                                <table class="table table-sm">
                                                                    <thead>
                                                                        <tr>
                                                                            <th>Date</th>
                                                                            <th>Type</th>
                                                                            <th>Amount</th>
                                                                            <th>Notes</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                        ${data.penalties.map(penalty => {
                                                                            let badgeClass = '';
                                                                            let badgeText = '';
                                                                            
                                                                            switch(penalty.type) {
                                                                                case 'added':
                                                                                    badgeClass = 'penalty-type-added';
                                                                                    badgeText = 'Added';
                                                                                    break;
                                                                                case 'waived':
                                                                                    badgeClass = 'penalty-type-waived';
                                                                                    badgeText = 'Waived';
                                                                                    break;
                                                                                case 'partial waiver':
                                                                                    badgeClass = 'penalty-type-partial-waiver';
                                                                                    badgeText = 'Partial Waiver';
                                                                                    break;
                                                                                default:
                                                                                    badgeClass = 'bg-secondary';
                                                                                    badgeText = penalty.type || 'Unknown';
                                                                            }
                                                                            
                                                                            return `
                                                                                <tr>
                                                                                    <td>${penalty.date || 'N/A'}</td>
                                                                                    <td>
                                                                                        <span class="badge ${badgeClass}">${badgeText}</span>
                                                                                    </td>
                                                                                    <td class="fw-bold ${penalty.type === 'added' ? 'text-success' : (penalty.type === 'waived' ? 'text-danger' : 'text-warning')}">
                                                                                        ₱${formatNumber(penalty.amount)}
                                                                                    </td>
                                                                                    <td class="notes-column" title="${penalty.notes || 'No notes'}">${penalty.notes || 'No notes'}</td>
                                                                                </tr>
                                                                            `;
                                                                        }).join('')}
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                        ` : `
                                                            <div class="alert alert-info">
                                                                <i class="fas fa-info-circle me-2"></i>
                                                                No penalties history recorded for this loan.
                                                            </div>
                                                        `}
                                                    </div>
                                                </div>
                                            `;

                                            const printLoanBtn = document.getElementById('printLoanBtn');
                                            if (printLoanBtn) {
                                                printLoanBtn.onclick = function() {
                                                    window.open(`print_loan.php?id=${loanId}`, '_blank');
                                                };
                                            }
                                            
                                            document.querySelectorAll('.view-payment-btn').forEach(btn => {
                                                btn.addEventListener('click', function() {
                                                    const paymentId = this.getAttribute('data-payment-id');
                                                    viewPaymentDetails(paymentId);
                                                });
                                            });
                                            
                                            document.querySelectorAll('.edit-payment-btn').forEach(btn => {
                                                btn.addEventListener('click', function() {
                                                    const paymentId = this.getAttribute('data-payment-id');
                                                    const paymentAmount = this.getAttribute('data-payment-amount');
                                                    
                                                    document.getElementById('editPaymentId').value = paymentId;
                                                    document.getElementById('editPaymentAmount').value = formatNumberInput(paymentAmount);
                                                    
                                                    const editModal = new bootstrap.Modal(document.getElementById('editPaymentModal'));
                                                    editModal.show();
                                                });
                                            });
                                            
                                            document.querySelectorAll('.delete-payment-btn').forEach(btn => {
                                                btn.addEventListener('click', function() {
                                                    const paymentId = this.getAttribute('data-payment-id');
                                                    const paymentAmount = this.getAttribute('data-payment-amount');
                                                    
                                                    document.getElementById('paymentAmountToDelete').textContent = 
                                                        '₱' + formatNumber(paymentAmount);
                                                    
                                                    const confirmBtn = document.getElementById('confirmDeletePaymentBtn');
                                                    confirmBtn.onclick = function() {
                                                        deletePayment(paymentId, loanId);
                                                    };
                                                    
                                                    const deleteModal = new bootstrap.Modal(document.getElementById('deletePaymentModal'));
                                                    deleteModal.show();
                                                });
                                            });

                                            document.querySelectorAll('.view-savings-deposit-btn').forEach(btn => {
                                                btn.addEventListener('click', function() {
                                                    const depositId = this.getAttribute('data-deposit-id');
                                                    viewSavingsDepositDetails(depositId);
                                                });
                                            });
                                            
                                            document.querySelectorAll('.edit-savings-deposit-btn').forEach(btn => {
                                                btn.addEventListener('click', function() {
                                                    const depositId = this.getAttribute('data-deposit-id');
                                                    const depositAmount = this.getAttribute('data-deposit-amount');
                                                    
                                                    document.getElementById('editSavingsDepositId').value = depositId;
                                                    document.getElementById('editSavingsDepositAmount').value = formatNumberInput(depositAmount);
                                                    
                                                    const editModal = new bootstrap.Modal(document.getElementById('editSavingsDepositModal'));
                                                    editModal.show();
                                                });
                                            });
                                            
                                            document.querySelectorAll('.delete-savings-deposit-btn').forEach(btn => {
                                                btn.addEventListener('click', function() {
                                                    const depositId = this.getAttribute('data-deposit-id');
                                                    const depositAmount = this.getAttribute('data-deposit-amount');
                                                    
                                                    document.getElementById('savingsDepositAmountToDelete').textContent = 
                                                        '₱' + formatNumber(depositAmount);
                                                    
                                                    const confirmBtn = document.getElementById('confirmDeleteSavingsDepositBtn');
                                                    confirmBtn.onclick = function() {
                                                        deleteSavingsDeposit(depositId, loanId);
                                                    };
                                                    
                                                    const deleteModal = new bootstrap.Modal(document.getElementById('deleteSavingsDepositModal'));
                                                    deleteModal.show();
                                                });
                                            });
                                        })
                                        .catch(error => {
                                            console.error('Error:', error);
                                            document.getElementById('loanDetailsContent').innerHTML = `
                                                <div class="alert alert-danger">
                                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                                    Error refreshing loan details: ${error.message}
                                                </div>
                                            `;
                                        });
                                });
                            }
                            
                            document.querySelectorAll('.view-payment-btn').forEach(btn => {
                                btn.addEventListener('click', function() {
                                    const paymentId = this.getAttribute('data-payment-id');
                                    viewPaymentDetails(paymentId);
                                });
                            });
                            
                            document.querySelectorAll('.delete-payment-btn').forEach(btn => {
                                btn.addEventListener('click', function() {
                                    const paymentId = this.getAttribute('data-payment-id');
                                    const paymentAmount = this.getAttribute('data-payment-amount');
                                    
                                    document.getElementById('paymentAmountToDelete').textContent = 
                                        '₱' + formatNumber(paymentAmount);
                                    
                                    const confirmBtn = document.getElementById('confirmDeletePaymentBtn');
                                    confirmBtn.onclick = function() {
                                        deletePayment(paymentId, loanId);
                                    };
                                    
                                    const deleteModal = new bootstrap.Modal(document.getElementById('deletePaymentModal'));
                                    deleteModal.show();
                                });
                            });

                            document.querySelectorAll('.edit-payment-btn').forEach(btn => {
                                btn.addEventListener('click', function() {
                                    const paymentId = this.getAttribute('data-payment-id');
                                    const paymentAmount = this.getAttribute('data-payment-amount');
                                    
                                    document.getElementById('editPaymentId').value = paymentId;
                                    document.getElementById('editPaymentAmount').value = formatNumberInput(paymentAmount);
                                    
                                    const editModal = new bootstrap.Modal(document.getElementById('editPaymentModal'));
                                    editModal.show();
                                });
                            });

                            document.querySelectorAll('.view-savings-deposit-btn').forEach(btn => {
                                btn.addEventListener('click', function() {
                                    const depositId = this.getAttribute('data-deposit-id');
                                    viewSavingsDepositDetails(depositId);
                                });
                            });
                            
                            document.querySelectorAll('.delete-savings-deposit-btn').forEach(btn => {
                                btn.addEventListener('click', function() {
                                    const depositId = this.getAttribute('data-deposit-id');
                                    const depositAmount = this.getAttribute('data-deposit-amount');
                                    
                                    document.getElementById('savingsDepositAmountToDelete').textContent = 
                                        '₱' + formatNumber(depositAmount);
                                    
                                    const confirmBtn = document.getElementById('confirmDeleteSavingsDepositBtn');
                                    confirmBtn.onclick = function() {
                                        deleteSavingsDeposit(depositId, loanId);
                                    };
                                    
                                    const deleteModal = new bootstrap.Modal(document.getElementById('deleteSavingsDepositModal'));
                                    deleteModal.show();
                                });
                            });

                            document.querySelectorAll('.edit-savings-deposit-btn').forEach(btn => {
                                btn.addEventListener('click', function() {
                                    const depositId = this.getAttribute('data-deposit-id');
                                    const depositAmount = this.getAttribute('data-deposit-amount');
                                    
                                    document.getElementById('editSavingsDepositId').value = depositId;
                                    document.getElementById('editSavingsDepositAmount').value = formatNumberInput(depositAmount);
                                    
                                    const editModal = new bootstrap.Modal(document.getElementById('editSavingsDepositModal'));
                                    editModal.show();
                                });
                            });

                            modal.show();
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            document.getElementById('loanDetailsContent').innerHTML = `
                                <div class="alert alert-danger">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    Error loading loan details: ${error.message}
                                </div>
                            `;
                            modal.show();
                        });
                });
            });

            // Helper function to format numbers with commas while typing
            function formatNumberInput(value) {
                let num = value.replace(/[^\d.]/g, '');
                let parts = num.split('.');
                let wholePart = parts[0];
                let decimalPart = parts.length > 1 ? '.' + parts[1].substring(0, 2) : '';
                wholePart = wholePart.replace(/\B(?=(\d{3})+(?!\d))/g, ",");
                return wholePart + decimalPart;
            }

            function parseFormattedNumber(value) {
                return parseFloat(value.replace(/,/g, ''));
            }

            const editPaymentAmountInput = document.getElementById('editPaymentAmount');
            if (editPaymentAmountInput) {
                editPaymentAmountInput.addEventListener('input', function(e) {
                    let cursorPos = this.selectionStart;
                    let originalLength = this.value.length;
                    this.value = formatNumberInput(this.value);
                    let newLength = this.value.length;
                    cursorPos = cursorPos + (newLength - originalLength);
                    this.setSelectionRange(cursorPos, cursorPos);
                });
            }

            const editSavingsDepositAmountInput = document.getElementById('editSavingsDepositAmount');
            if (editSavingsDepositAmountInput) {
                editSavingsDepositAmountInput.addEventListener('input', function(e) {
                    let cursorPos = this.selectionStart;
                    let originalLength = this.value.length;
                    this.value = formatNumberInput(this.value);
                    let newLength = this.value.length;
                    cursorPos = cursorPos + (newLength - originalLength);
                    this.setSelectionRange(cursorPos, cursorPos);
                });
            }

            const editPenaltyAmountInput = document.getElementById('editPenaltyAmount');
            if (editPenaltyAmountInput) {
                editPenaltyAmountInput.addEventListener('input', function(e) {
                    let cursorPos = this.selectionStart;
                    let originalLength = this.value.length;
                    this.value = formatNumberInput(this.value);
                    let newLength = this.value.length;
                    cursorPos = cursorPos + (newLength - originalLength);
                    this.setSelectionRange(cursorPos, cursorPos);
                });
            }

            // ============================================================
            // PRINT SAVINGS WITHDRAW VOUCHER (MODAL + PRINT BUTTON)
            // ============================================================
            let currentVoucherLoanId = null;
            let currentVoucherCustomerId = null;

            document.querySelectorAll('.print-savings-withdraw-voucher-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const loanId = this.getAttribute('data-loan-id');
                    const customerId = this.getAttribute('data-customer-id');
                    
                    currentVoucherLoanId = loanId;
                    currentVoucherCustomerId = customerId;
                    
                    const modal = new bootstrap.Modal(document.getElementById('savingsWithdrawVoucherModal'));
                    
                    // Show loading
                    document.getElementById('savingsWithdrawVoucherContent').innerHTML = `
                        <div class="text-center py-4">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p class="mt-2">Loading withdrawal history...</p>
                        </div>
                    `;
                    
                    // Fetch withdrawal history
                    fetch(`api/get_savings_withdrawals.php?loan_id=${loanId}`)
                        .then(response => {
                            if (!response.ok) {
                                throw new Error('Network response was not ok');
                            }
                            return response.json();
                        })
                        .then(data => {
                            if (data.error) {
                                throw new Error(data.error);
                            }
                            
                            const formatNumber = num => {
                                return parseFloat(num).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
                            };
                            
                            const withdrawals = data.withdrawals || [];
                            const totalAmount = parseFloat(data.total_amount || 0);
                            
                            let customerName = data.customer_name || 'N/A';
                            let collectorName = data.collector_name || 'N/A';
                            let branchName = data.branch || 'N/A';
                            let loanIdDisplay = data.loan_id || loanId;
                            
                            let rowsHtml = '';
                            if (withdrawals.length > 0) {
                                withdrawals.forEach((w, index) => {
                                    // Determine badge class based on transaction type
                                    let typeBadgeClass = 'bg-danger';
                                    if (w.transaction_type === 'Withdrawal') {
                                        typeBadgeClass = 'bg-warning text-dark';
                                    } else if (w.transaction_type === 'Withdrawal Cash') {
                                        typeBadgeClass = 'bg-danger';
                                    }
                                    
                                    rowsHtml += `
                                        <tr>
                                            <td>${index + 1}</td>
                                            <td class="text-nowrap">${w.transaction_date_formatted || w.transaction_date || 'N/A'}</td>
                                            <td>
                                                <span class="pc-pill ${w.transaction_type === 'Withdrawal' ? 'pc-pill--warning' : 'pc-pill--danger'}">
                                                    ${w.transaction_type || 'N/A'}
                                                </span>
                                            </td>
                                            <td class="fw-bold text-danger">₱${formatNumber(w.amount)}</td>
                                            <td>${w.payment_method || 'Cash'}</td>
                                            <td>${w.collector_name || 'N/A'}</td>
                                            <td class="notes-column" title="${w.notes || ''}">${w.notes || '-'}</td>
                                            <td class="text-nowrap">
                                                <button class="btn btn-sm btn-primary print-single-voucher-btn" 
                                                        data-transaction-id="${w.id}"
                                                        title="Print voucher for this transaction">
                                                    <i class="fas fa-print"></i> Print
                                                </button>
                                            </td>
                                        </tr>
                                    `;
                                });
                            } else {
                                rowsHtml = `
                                    <tr>
                                        <td colspan="8" class="text-center py-3 text-muted">
                                            <i class="fas fa-info-circle me-2"></i>
                                            No withdrawal transactions found for this loan.
                                        </td>
                                    </tr>
                                `;
                            }
                            
                            document.getElementById('savingsWithdrawVoucherContent').innerHTML = `
                                <div class="pc-pay">
                                    <header class="pc-pay__head">
                                        <span class="pc-pay__icon"><i class="fas fa-money-bill-wave" aria-hidden="true"></i></span>
                                        <div class="pc-pay__ident">
                                            <h5 class="pc-pay__id">Savings withdrawals</h5>
                                            <p class="pc-pay__date">Loan #${loanIdDisplay} · ${customerName}</p>
                                        </div>
                                        <span class="pc-pill pc-pill--info">
                                            <i class="fas fa-receipt" aria-hidden="true"></i> ${withdrawals.length} voucher(s)
                                        </span>
                                    </header>

                                    <div class="pc-pay__figures">
                                        <div class="pc-pay__figure pc-pay__figure--neutral">
                                            <span class="pc-pay__figure-label">Transactions</span>
                                            <span class="pc-pay__figure-value">${withdrawals.length}</span>
                                        </div>
                                        <div class="pc-pay__figure">
                                            <span class="pc-pay__figure-label">Total withdrawn</span>
                                            <span class="pc-pay__figure-value">&#8369;${formatNumber(totalAmount)}</span>
                                        </div>
                                    </div>

                                    <dl class="pc-pay__grid">
                                        <div class="pc-pay__row">
                                            <dt>Loan account</dt>
                                            <dd>Loan #${loanIdDisplay}</dd>
                                        </div>
                                        <div class="pc-pay__row">
                                            <dt>Branch</dt>
                                            <dd>${branchName}</dd>
                                        </div>
                                        <div class="pc-pay__row pc-pay__row--wide">
                                            <dt>Collector</dt>
                                            <dd>${collectorName}</dd>
                                        </div>
                                    </dl>

                                    <div class="pc-pay__table-wrap">
                                        <table class="table swv-transactions-table">
                                            <thead>
                                                <tr>
                                                    <th>#</th>
                                                    <th>Date</th>
                                                    <th>Type</th>
                                                    <th>Amount</th>
                                                    <th>Method</th>
                                                    <th>Collector</th>
                                                    <th>Notes</th>
                                                    <th>Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                ${rowsHtml}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            `;
                            
                            // Show/hide print all button
                            const printBtn = document.getElementById('printSavingsWithdrawVoucherBtn');
                            if (withdrawals.length > 0) {
                                printBtn.disabled = false;
                                printBtn.style.display = 'inline-block';
                            } else {
                                printBtn.disabled = true;
                                printBtn.style.display = 'none';
                            }
                            
                            // Attach per-row Print handlers
                            document.querySelectorAll('.print-single-voucher-btn').forEach(btn => {
                                btn.addEventListener('click', function() {
                                    const transactionId = this.getAttribute('data-transaction-id');
                                    window.open(`generate_savings_withdraw_voucher.php?transaction_id=${transactionId}`, '_blank');
                                });
                            });
                            
                            modal.show();
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            document.getElementById('savingsWithdrawVoucherContent').innerHTML = `
                                <div class="alert alert-danger">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    Error loading withdrawal history: ${error.message}
                                </div>
                            `;
                            document.getElementById('printSavingsWithdrawVoucherBtn').style.display = 'none';
                            modal.show();
                        });
                });
            });

            // Print ALL voucher button handler (bottom of modal)
            const printSavingsWithdrawVoucherBtn = document.getElementById('printSavingsWithdrawVoucherBtn');
            if (printSavingsWithdrawVoucherBtn) {
                printSavingsWithdrawVoucherBtn.addEventListener('click', function() {
                    if (!currentVoucherLoanId) return;
                    window.open(`generate_savings_withdraw_voucher.php?loan_id=${currentVoucherLoanId}`, '_blank');
                });
            }

            // ============================================================
            // EDIT AMOUNT FINANCED
            // ============================================================
            document.querySelectorAll('.edit-amount-financed-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const loanId = this.getAttribute('data-loan-id');
                    const currentAmount = parseFloat(this.getAttribute('data-current-amount'));
                    
                    document.getElementById('editLoanId').value = loanId;
                    document.getElementById('originalAmountFinanced').value = currentAmount;
                    document.getElementById('editAmountFinanced').value = formatNumberInput(currentAmount.toFixed(2));
                    document.getElementById('currentAmountDisplay').textContent = '₱' + formatNumber(currentAmount.toFixed(2));
                    
                    const editModal = new bootstrap.Modal(document.getElementById('editAmountFinancedModal'));
                    editModal.show();
                });
            });

            const editAmountFinancedInput = document.getElementById('editAmountFinanced');
            if (editAmountFinancedInput) {
                editAmountFinancedInput.addEventListener('input', function(e) {
                    let cursorPos = this.selectionStart;
                    let originalLength = this.value.length;
                    this.value = formatNumberInput(this.value);
                    let newLength = this.value.length;
                    cursorPos = cursorPos + (newLength - originalLength);
                    this.setSelectionRange(cursorPos, cursorPos);
                });
            }

            const confirmEditAmountFinancedBtn = document.getElementById('confirmEditAmountFinancedBtn');
            if (confirmEditAmountFinancedBtn) {
                confirmEditAmountFinancedBtn.addEventListener('click', function() {
                    const loanId = document.getElementById('editLoanId').value;
                    const formattedAmount = document.getElementById('editAmountFinanced').value;
                    const newAmount = parseFormattedNumber(formattedAmount);
                    
                    if (isNaN(newAmount) || newAmount <= 0) {
                        Swal.fire({
                            title: 'Invalid Amount',
                            text: 'Please enter a valid amount greater than 0.',
                            icon: 'error',
                            confirmButtonText: 'OK'
                        });
                        return;
                    }
                    
                    const originalAmount = parseFloat(document.getElementById('originalAmountFinanced').value);
                    const difference = newAmount - originalAmount;
                    
                    Swal.fire({
                        title: 'Confirm Amount Change',
                        html: `You are about to change the amount financed from <strong>₱${formatNumber(originalAmount.toFixed(2))}</strong> to <strong>₱${formatNumber(newAmount.toFixed(2))}</strong>.<br><br>
                            <strong>Difference:</strong> ${difference >= 0 ? '+' : ''}₱${formatNumber(Math.abs(difference).toFixed(2))}<br><br>
                            This will recalculate interest, total balance, and daily payment.`,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#198754',
                        cancelButtonColor: '#6c757d',
                        confirmButtonText: 'Yes, Update It!',
                        cancelButtonText: 'Cancel'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            updateAmountFinanced(loanId, newAmount);
                        }
                    });
                });
            }

            function updateAmountFinanced(loanId, newAmount) {
                const confirmBtn = document.getElementById('confirmEditAmountFinancedBtn');
                const originalBtnContent = confirmBtn.innerHTML;
                confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Updating...';
                confirmBtn.disabled = true;
                
                fetch('api/update_amount_financed.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        loan_id: loanId,
                        new_amount: newAmount
                    })
                })
                .then(response => {
                    const contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        return response.text().then(text => {
                            console.error('Non-JSON response:', text);
                            throw new Error('Server error. Please try again.');
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.error) {
                        throw new Error(data.error);
                    }
                    
                    if (!data.success) {
                        throw new Error(data.message || 'Update failed');
                    }
                    
                    Swal.fire({
                        title: 'Success!',
                        html: `Amount financed updated successfully!<br><br>
                            <strong>New Amount Financed:</strong> ₱${formatNumber(data.new_amount.toFixed(2))}<br>
                            <strong>New Interest Amount:</strong> ₱${formatNumber(data.new_interest.toFixed(2))}<br>
                            <strong>Total Balance:</strong> ₱${formatNumber(data.total_balance.toFixed(2))}<br>
                            <strong>Daily Payment:</strong> ₱${formatNumber(data.daily_payment.toFixed(2))}`,
                        icon: 'success',
                        confirmButtonText: 'OK'
                    }).then(() => {
                        const editModal = bootstrap.Modal.getInstance(document.getElementById('editAmountFinancedModal'));
                        editModal.hide();
                        location.reload();
                    });
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire({
                        title: 'Error!',
                        text: 'Error updating amount financed: ' + error.message,
                        icon: 'error',
                        confirmButtonText: 'OK'
                    });
                })
                .finally(() => {
                    confirmBtn.innerHTML = originalBtnContent;
                    confirmBtn.disabled = false;
                });
            }

            // Confirm edit payment button handler
            const confirmEditPaymentBtn = document.getElementById('confirmEditPaymentBtn');
            if (confirmEditPaymentBtn) {
                confirmEditPaymentBtn.addEventListener('click', function() {
                    const paymentId = document.getElementById('editPaymentId').value;
                    const formattedAmount = document.getElementById('editPaymentAmount').value;
                    const newAmount = parseFormattedNumber(formattedAmount);
                    
                    if (isNaN(newAmount) || newAmount <= 0) {
                        Swal.fire({
                            title: 'Invalid Amount',
                            text: 'Please enter a valid payment amount greater than 0.',
                            icon: 'error',
                            confirmButtonText: 'OK'
                        });
                        return;
                    }
                    
                    const confirmBtn = document.getElementById('confirmEditPaymentBtn');
                    const originalBtnContent = confirmBtn.innerHTML;
                    confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...';
                    confirmBtn.disabled = true;
                    
                    fetch('api/update_payment.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `id=${paymentId}&amount=${newAmount}`
                    })
                    .then(response => {
                        if (!response.ok) {
                            return response.json().then(err => { throw new Error(err.error || 'Network response was not ok'); });
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.error) {
                            throw new Error(data.error);
                        }
                        
                        Swal.fire({
                            title: 'Success!',
                            text: 'Payment amount updated successfully!',
                            icon: 'success',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            const editModal = bootstrap.Modal.getInstance(document.getElementById('editPaymentModal'));
                            editModal.hide();
                            location.reload();
                        });
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        Swal.fire({
                            title: 'Error!',
                            text: 'Error updating payment: ' + error.message,
                            icon: 'error',
                            confirmButtonText: 'OK'
                        });
                    })
                    .finally(() => {
                        confirmBtn.innerHTML = originalBtnContent;
                        confirmBtn.disabled = false;
                    });
                });
            }

            // Confirm edit savings deposit button handler
            const confirmEditSavingsDepositBtn = document.getElementById('confirmEditSavingsDepositBtn');
            if (confirmEditSavingsDepositBtn) {
                confirmEditSavingsDepositBtn.addEventListener('click', function() {
                    const depositId = document.getElementById('editSavingsDepositId').value;
                    const formattedAmount = document.getElementById('editSavingsDepositAmount').value;
                    const newAmount = parseFormattedNumber(formattedAmount);
                    
                    if (isNaN(newAmount) || newAmount <= 0) {
                        Swal.fire({
                            title: 'Invalid Amount',
                            text: 'Please enter a valid deposit amount greater than 0.',
                            icon: 'error',
                            confirmButtonText: 'OK'
                        });
                        return;
                    }
                    
                    const confirmBtn = document.getElementById('confirmEditSavingsDepositBtn');
                    const originalBtnContent = confirmBtn.innerHTML;
                    confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...';
                    confirmBtn.disabled = true;
                    
                    fetch('api/update_savings_deposit.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `id=${depositId}&amount=${newAmount}`
                    })
                    .then(response => {
                        if (!response.ok) {
                            return response.json().then(err => { throw new Error(err.error || 'Network response was not ok'); });
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.error) {
                            throw new Error(data.error);
                        }
                        
                        Swal.fire({
                            title: 'Success!',
                            text: 'Savings deposit amount updated successfully!',
                            icon: 'success',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            const editModal = bootstrap.Modal.getInstance(document.getElementById('editSavingsDepositModal'));
                            editModal.hide();
                            location.reload();
                        });
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        Swal.fire({
                            title: 'Error!',
                            text: 'Error updating savings deposit: ' + error.message,
                            icon: 'error',
                            confirmButtonText: 'OK'
                        });
                    })
                    .finally(() => {
                        confirmBtn.innerHTML = originalBtnContent;
                        confirmBtn.disabled = false;
                    });
                });
            }

            // Confirm edit penalty button handler
            const confirmEditPenaltyBtn = document.getElementById('confirmEditPenaltyBtn');
            if (confirmEditPenaltyBtn) {
                confirmEditPenaltyBtn.addEventListener('click', function() {
                    const penaltyId = document.getElementById('editPenaltyId').value;
                    const formattedAmount = document.getElementById('editPenaltyAmount').value;
                    const newAmount = parseFormattedNumber(formattedAmount);
                    
                    if (isNaN(newAmount) || newAmount <= 0) {
                        Swal.fire({
                            title: 'Invalid Amount',
                            text: 'Please enter a valid penalty amount greater than 0.',
                            icon: 'error',
                            confirmButtonText: 'OK'
                        });
                        return;
                    }
                    
                    const confirmBtn = document.getElementById('confirmEditPenaltyBtn');
                    const originalBtnContent = confirmBtn.innerHTML;
                    confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...';
                    confirmBtn.disabled = true;
                    
                    fetch('api/update_penalty.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `id=${penaltyId}&amount=${newAmount}`
                    })
                    .then(response => {
                        if (!response.ok) {
                            return response.json().then(err => { throw new Error(err.error || 'Network response was not ok'); });
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.error) {
                            throw new Error(data.error);
                        }
                        
                        Swal.fire({
                            title: 'Success!',
                            text: 'Penalty amount updated successfully!',
                            icon: 'success',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            const editModal = bootstrap.Modal.getInstance(document.getElementById('editPenaltyModal'));
                            editModal.hide();
                            location.reload();
                        });
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        Swal.fire({
                            title: 'Error!',
                            text: 'Error updating penalty: ' + error.message,
                            icon: 'error',
                            confirmButtonText: 'OK'
                        });
                    })
                    .finally(() => {
                        confirmBtn.innerHTML = originalBtnContent;
                        confirmBtn.disabled = false;
                    });
                });
            }

            // Print loan button handler
            document.querySelectorAll('.print-loan-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const loanId = this.getAttribute('data-loan-id');
                    window.open(`print_loan.php?id=${loanId}`, '_blank');
                });
            });
            
            // Closed savings button handler
            document.querySelectorAll('.closed-savings-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const loanId = this.getAttribute('data-loan-id');
                    
                    Swal.fire({
                        title: 'Close Savings Account?',
                        text: 'Are you sure you want to close the savings account for this loan? This action cannot be undone.',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#3085d6',
                        cancelButtonColor: '#d33',
                        confirmButtonText: 'Yes, close it!'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            closeSavingsAccount(loanId);
                        }
                    });
                });
            });
            
            // Delete loan button handler
            document.querySelectorAll('.delete-loan-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const loanId = this.getAttribute('data-loan-id');
                    const row = this.closest('tr');
                    const amountCell = row.querySelector('td:nth-child(3)');
                    const amountValue = amountCell ? (amountCell.querySelector('.pc-table__value') || amountCell) : null;
                    const loanAmount = amountValue ? amountValue.textContent.trim() : '';
                    const balanceCell = row.querySelector('td:nth-child(8)');
                    const loanBalance = balanceCell ? balanceCell.textContent.trim() : '';
                    
                    
                    /* Financed figure: prefer the data attribute, else the sub-line. */
                    let loanFinanced = '';
                    
                    if (amountCell && amountCell.getAttribute('data-financed')) {
                        const financedNumber = parseFloat(amountCell.getAttribute('data-financed'));
                    
                        if (!isNaN(financedNumber)) {
                            loanFinanced = '\u20b1' + financedNumber.toLocaleString('en-US', {
                                minimumFractionDigits: 2,
                                maximumFractionDigits: 2
                            });
                        }
                    }
                    
                    if (!loanFinanced && amountCell) {
                        const subLine = amountCell.querySelector('.pc-table__sub');
                        const subMatch = subLine ? subLine.textContent.match(/[\d,]+\.\d{2}/) : null;
                    
                        if (subMatch) {
                            loanFinanced = '\u20b1' + subMatch[0];
                        }
                    }
                    document.getElementById('loanAmountToDelete').textContent = loanAmount;
                    document.getElementById('loanBalanceToDelete').textContent = loanBalance;
                    
                    const financedNode = document.getElementById('loanFinancedToDelete');
                    
                    if (financedNode) {
                        financedNode.textContent = loanFinanced || '\u20b10.00';
                    }
                    
                    const confirmBtn = document.getElementById('confirmDeleteLoanBtn');
                    confirmBtn.onclick = function() {
                        deleteLoan(loanId);
                    };
                    
                    const deleteModal = new bootstrap.Modal(document.getElementById('deleteLoanModal'));
                    deleteModal.show();
                });
            });

            function formatNumber(num) {
                return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
            }

            function unformatNumber(str) {
                return parseFloat(str.replace(/,/g, ''));
            }

            // Stop automatic penalty button handler
            document.querySelectorAll('.stop-penalty-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const loanId = this.getAttribute('data-loan-id');
                    
                    Swal.fire({
                        title: 'Stop Automatic Penalty?',
                        text: 'Are you sure you want to stop automatic penalty calculation for this loan?',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#3085d6',
                        cancelButtonColor: '#d33',
                        confirmButtonText: 'Yes, stop it!',
                        cancelButtonText: 'Cancel'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            toggleAutomaticPenalty(loanId, false);
                        }
                    });
                });
            });

            // Enable automatic penalty button handler
            document.querySelectorAll('.enable-penalty-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const loanId = this.getAttribute('data-loan-id');
                    
                    Swal.fire({
                        title: 'Enable Automatic Penalty?',
                        text: 'Are you sure you want to enable automatic penalty calculation for this loan?',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonColor: '#198754',
                        cancelButtonColor: '#6c757d',
                        confirmButtonText: 'Yes, enable it!',
                        cancelButtonText: 'Cancel'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            toggleAutomaticPenalty(loanId, true);
                        }
                    });
                });
            });

            function toggleAutomaticPenalty(loanId, enable) {
                const action = enable ? 'enable' : 'disable';
                const actionText = enable ? 'enabling' : 'disabling';
                
                Swal.fire({
                    title: `${actionText.charAt(0).toUpperCase() + actionText.slice(1)} Automatic Penalty...`,
                    text: 'Please wait while we update the penalty settings.',
                    icon: 'info',
                    showConfirmButton: false,
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                fetch('api/toggle_automatic_penalty.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        loan_id: loanId,
                        enable: enable
                    })
                })
                .then(response => {
                    if (!response.ok) {
                        return response.json().then(err => { 
                            throw new Error(err.error || 'Network response was not ok'); 
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.error) {
                        throw new Error(data.error);
                    }
                    
                    Swal.fire({
                        title: 'Success!',
                        text: data.message || `Automatic penalty has been ${action}d successfully!`,
                        icon: 'success',
                        confirmButtonText: 'OK'
                    }).then(() => {
                        location.reload();
                    });
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire({
                        title: 'Error!',
                        text: 'Error updating automatic penalty: ' + error.message,
                        icon: 'error',
                        confirmButtonText: 'OK'
                    });
                });
            }

            // Waive penalty button handler
            document.querySelectorAll('.waive-penalty-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const loanId = this.getAttribute('data-loan-id');
                    
                    fetch(`api/get_penalties_for_waive.php?loan_id=${loanId}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.error) {
                                throw new Error(data.error);
                            }
                            
                            document.getElementById('waiveLoanId').value = loanId;
                            
                            const penaltiesTableBody = document.getElementById('penaltiesTableBody');
                            penaltiesTableBody.innerHTML = '';
                            
                            if (data.penalties && data.penalties.length > 0) {
                                data.penalties.forEach(penalty => {
                                    const row = document.createElement('tr');
                                    row.innerHTML = `
                                        <td>
                                            <div class="form-check">
                                                <input class="form-check-input penalty-checkbox" type="checkbox" 
                                                       data-penalty-id="${penalty.id}" 
                                                       data-original-amount="${penalty.amount}"
                                                       data-current-amount="${penalty.amount}">
                                            </div>
                                        </td>
                                        <td>${penalty.date}</td>
                                        <td class="text-danger fw-bold">₱${parseFloat(penalty.original_amount).toFixed(2)}</td>
                                        <td class="text-primary fw-bold">₱${parseFloat(penalty.amount).toFixed(2)}</td>
                                        <td>
                                            <input type="text" class="form-control form-control-sm waive-amount-input" 
                                                   value="${parseFloat(penalty.amount).toFixed(2)}" 
                                                   data-penalty-id="${penalty.id}"
                                                   disabled
                                                   style="width: 100px;">
                                        </td>
                                        <td class="small">${penalty.notes || 'No notes'}</td>
                                    `;
                                    penaltiesTableBody.appendChild(row);
                                });
                            } else {
                                penaltiesTableBody.innerHTML = `
                                    <tr>
                                        <td colspan="6" class="text-center py-3 text-muted">
                                            <i class="fas fa-info-circle me-2"></i>
                                            No penalties found for this loan.
                                        </td>
                                    </tr>
                                `;
                            }
                            
                            document.getElementById('waiveReason').value = '';
                            document.getElementById('additionalNotes').value = '';
                            updateSummary();
                            
                            const waiveModal = new bootstrap.Modal(document.getElementById('waivePenaltyModal'));
                            waiveModal.show();
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            Swal.fire({
                                title: 'Error!',
                                text: error.message,
                                icon: 'error',
                                confirmButtonText: 'OK'
                            });
                        });
                });
            });

            const selectAllPenaltiesCheckbox = document.getElementById('selectAllPenalties');
            if (selectAllPenaltiesCheckbox) {
                selectAllPenaltiesCheckbox.addEventListener('change', function() {
                    const checkboxes = document.querySelectorAll('.penalty-checkbox');
                    
                    checkboxes.forEach(checkbox => {
                        checkbox.checked = this.checked;
                        
                        const penaltyId = checkbox.getAttribute('data-penalty-id');
                        const amountInput = document.querySelector(`.waive-amount-input[data-penalty-id="${penaltyId}"]`);
                        if (amountInput) {
                            amountInput.disabled = !this.checked;
                        }
                    });
                    
                    updateSummary();
                });
            }

            document.addEventListener('change', function(e) {
                if (e.target.classList.contains('penalty-checkbox')) {
                    const penaltyId = e.target.getAttribute('data-penalty-id');
                    const amountInput = document.querySelector(`.waive-amount-input[data-penalty-id="${penaltyId}"]`);
                    
                    if (amountInput) {
                        amountInput.disabled = !e.target.checked;
                    }
                    
                    updateSummary();
                    updateSelectAllCheckbox();
                }
            });

            document.addEventListener('input', function(e) {
                if (e.target.classList.contains('waive-amount-input')) {
                    const penaltyId = e.target.getAttribute('data-penalty-id');
                    const checkbox = document.querySelector(`.penalty-checkbox[data-penalty-id="${penaltyId}"]`);
                    const currentAmount = parseFloat(checkbox.getAttribute('data-current-amount'));
                    
                    let value = e.target.value.replace(/[^\d.]/g, '');
                    
                    const decimalSplit = value.split('.');
                    if (decimalSplit.length > 2) {
                        value = decimalSplit[0] + '.' + decimalSplit.slice(1).join('');
                    }
                    
                    if (decimalSplit.length > 1) {
                        value = decimalSplit[0] + '.' + decimalSplit[1].slice(0, 2);
                    }
                    
                    const numericValue = parseFloat(value) || 0;
                    if (numericValue > currentAmount) {
                        value = currentAmount.toFixed(2);
                        Swal.fire({
                            title: 'Invalid Amount',
                            text: `Waive amount cannot exceed current penalty amount of ₱${currentAmount.toFixed(2)}`,
                            icon: 'warning',
                            toast: true,
                            position: 'top-end',
                            showConfirmButton: false,
                            timer: 3000
                        });
                    }
                    
                    const parts = value.split('.');
                    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ",");
                    e.target.value = parts.join('.');
                    
                    updateSummary();
                }
            });

            function updateSummary() {
                let totalSelected = 0;
                let totalWaive = 0;
                
                const checkboxes = document.querySelectorAll('.penalty-checkbox:checked');
                checkboxes.forEach(checkbox => {
                    const penaltyId = checkbox.getAttribute('data-penalty-id');
                    const amountInput = document.querySelector(`.waive-amount-input[data-penalty-id="${penaltyId}"]`);
                    
                    if (amountInput) {
                        const amount = parseFloat(amountInput.value.replace(/,/g, '')) || 0;
                        totalWaive += amount;
                        
                        const currentAmount = parseFloat(checkbox.getAttribute('data-current-amount'));
                        totalSelected += currentAmount;
                    }
                });
                
                const totalSelectedAmountElement = document.getElementById('totalSelectedAmount');
                const totalWaiveAmountElement = document.getElementById('totalWaiveAmount');
                
                if (totalSelectedAmountElement) {
                    totalSelectedAmountElement.textContent = `₱${totalSelected.toFixed(2)}`;
                }
                
                if (totalWaiveAmountElement) {
                    totalWaiveAmountElement.textContent = `₱${totalWaive.toFixed(2)}`;
                }
            }

            function updateSelectAllCheckbox() {
                const checkboxes = document.querySelectorAll('.penalty-checkbox');
                const selectAllCheckbox = document.getElementById('selectAllPenalties');
                
                if (!selectAllCheckbox) return;
                
                if (checkboxes.length === 0) {
                    selectAllCheckbox.checked = false;
                    selectAllCheckbox.disabled = true;
                    return;
                }
                
                selectAllCheckbox.disabled = false;
                const checkedCount = document.querySelectorAll('.penalty-checkbox:checked').length;
                selectAllCheckbox.checked = checkedCount === checkboxes.length;
                selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < checkboxes.length;
            }

            const confirmWaivePenaltyBtn = document.getElementById('confirmWaivePenaltyBtn');
            if (confirmWaivePenaltyBtn) {
                confirmWaivePenaltyBtn.addEventListener('click', function() {
                    const loanId = document.getElementById('waiveLoanId').value;
                    const reason = document.getElementById('waiveReason').value;
                    const additionalNotes = document.getElementById('additionalNotes').value;
                    
                    if (!reason.trim()) {
                        Swal.fire({
                            title: 'Reason Required',
                            text: 'Please provide a reason for waiving the penalties.',
                            icon: 'warning',
                            confirmButtonText: 'OK'
                        });
                        return;
                    }
                    
                    const penaltiesToWaive = [];
                    const checkboxes = document.querySelectorAll('.penalty-checkbox:checked');
                    
                    if (checkboxes.length === 0) {
                        Swal.fire({
                            title: 'No Penalties Selected',
                            text: 'Please select at least one penalty to waive.',
                            icon: 'warning',
                            confirmButtonText: 'OK'
                        });
                        return;
                    }
                    
                    let hasInvalidAmount = false;
                    checkboxes.forEach(checkbox => {
                        const penaltyId = checkbox.getAttribute('data-penalty-id');
                        const amountInput = document.querySelector(`.waive-amount-input[data-penalty-id="${penaltyId}"]`);
                        const currentAmount = parseFloat(checkbox.getAttribute('data-current-amount'));
                        
                        if (amountInput) {
                            const waiveAmount = parseFloat(amountInput.value.replace(/,/g, '')) || 0;
                            
                            if (waiveAmount <= 0) {
                                hasInvalidAmount = true;
                                Swal.fire({
                                    title: 'Invalid Amount',
                                    text: `Waive amount must be greater than 0 for penalty ID: ${penaltyId}`,
                                    icon: 'error',
                                    confirmButtonText: 'OK'
                                });
                                return;
                            }
                            
                            if (waiveAmount > currentAmount) {
                                hasInvalidAmount = true;
                                Swal.fire({
                                    title: 'Amount Exceeded',
                                    text: `Waive amount cannot exceed current penalty amount of ₱${currentAmount.toFixed(2)} for penalty ID: ${penaltyId}`,
                                    icon: 'error',
                                    confirmButtonText: 'OK'
                                });
                                return;
                            }
                            
                            penaltiesToWaive.push({
                                penalty_id: penaltyId,
                                amount: waiveAmount,
                                current_amount: currentAmount
                            });
                        }
                    });
                    
                    if (hasInvalidAmount) return;
                    
                    const totalWaiveAmount = penaltiesToWaive.reduce((sum, penalty) => sum + penalty.amount, 0);
                    
                    Swal.fire({
                        title: 'Confirm Penalty Waiver',
                        html: `You are about to waive <strong>₱${totalWaiveAmount.toFixed(2)}</strong> from ${penaltiesToWaive.length} penalty(ies).<br><br>
                              <strong>Reason:</strong> ${reason}<br><br>
                              This action cannot be undone.`,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#198754',
                        cancelButtonColor: '#6c757d',
                        confirmButtonText: 'Yes, Waive Penalties',
                        cancelButtonText: 'Cancel'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            processPenaltyWaiver(loanId, penaltiesToWaive, reason, additionalNotes);
                        }
                    });
                });
            }

            function processPenaltyWaiver(loanId, penaltiesToWaive, reason, additionalNotes) {
                const confirmBtn = document.getElementById('confirmWaivePenaltyBtn');
                const originalBtnContent = confirmBtn.innerHTML;
                confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...';
                confirmBtn.disabled = true;
                
                fetch('api/waive_penalty.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        loan_id: loanId,
                        penalties: penaltiesToWaive,
                        reason: reason,
                        additional_notes: additionalNotes,
                        created_by: pageCfg.currentUserId
                    })
                })
                .then(response => {
                    if (!response.ok) {
                        return response.json().then(err => { 
                            throw new Error(err.error || 'Network response was not ok'); 
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.error) {
                        throw new Error(data.error);
                    }
                    
                    Swal.fire({
                        title: 'Success!',
                        html: `Penalties waived successfully!<br><br>
                              <strong>Total Waived:</strong> ₱${data.total_waived}<br>
                              <strong>Penalties Processed:</strong> ${data.processed_count}<br>
                              <strong>New Loan Balance:</strong> ₱${data.new_balance}`,
                        icon: 'success',
                        confirmButtonText: 'OK'
                    }).then(() => {
                        const waiveModal = bootstrap.Modal.getInstance(document.getElementById('waivePenaltyModal'));
                        waiveModal.hide();
                        location.reload();
                    });
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire({
                        title: 'Error!',
                        text: 'Error waiving penalties: ' + error.message,
                        icon: 'error',
                        confirmButtonText: 'OK'
                    });
                })
                .finally(() => {
                    confirmBtn.innerHTML = originalBtnContent;
                    confirmBtn.disabled = false;
                });
            }
            
            async function closeSavingsAccount(loanId) {
                try {
                    const response = await fetch('api/close_savings.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `loan_id=${loanId}`
                    });

                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }

                    const result = await response.json();
                    
                    if (result.error) {
                        throw new Error(result.error);
                    }

                    Swal.fire({
                        title: 'Success!',
                        text: 'Savings account closed successfully!',
                        icon: 'success',
                        confirmButtonText: 'OK'
                    }).then(() => {
                        location.reload();
                    });

                } catch (error) {
                    console.error('Error:', error);
                    Swal.fire({
                        title: 'Error!',
                        text: 'Error closing savings account: ' + error.message,
                        icon: 'error',
                        confirmButtonText: 'OK'
                    });
                }
            }
            
            async function deleteLoan(loanId) {
                const confirmBtn = document.getElementById('confirmDeleteLoanBtn');
                const originalBtnContent = confirmBtn.innerHTML;
                confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Deleting...';
                confirmBtn.disabled = true;

                try {
                    const response = await fetch('api/delete_loan.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ id: loanId })
                    });

                    const data = await response.json();

                    if (!response.ok) {
                        throw new Error(data.error || `Server responded with status ${response.status}`);
                    }

                    if (data.error) {
                        throw new Error(data.error);
                    }

                    Swal.fire({
                        title: 'Success!',
                        text: data.message || 'Loan deleted successfully!',
                        icon: 'success',
                        confirmButtonText: 'OK'
                    }).then(() => {
                        const deleteModal = bootstrap.Modal.getInstance(document.getElementById('deleteLoanModal'));
                        if (deleteModal) {
                            deleteModal.hide();
                        }
                        location.reload();
                    });

                } catch (error) {
                    console.error('Error:', error);
                    Swal.fire({
                        title: 'Error!',
                        text: 'Error deleting loan: ' + (error.message || 'Unknown error occurred'),
                        icon: 'error',
                        confirmButtonText: 'OK'
                    });
                    
                } finally {
                    confirmBtn.innerHTML = originalBtnContent;
                    confirmBtn.disabled = false;
                }
            }
            
            function viewPaymentDetails(paymentId) {
                const modal = new bootstrap.Modal(document.getElementById('viewPaymentModal'));
                
                document.getElementById('paymentDetailsContent').innerHTML = `
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2">Loading payment details...</p>
                    </div>
                `;
                
                fetch(`api/get_payment_details.php?id=${paymentId}`)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.error) {
                            throw new Error(data.error);
                        }
                        
                        let collectorName = 'N/A';
                        if (data.collector_first_name || data.collector_last_name) {
                            collectorName = (data.collector_first_name || '') + ' ' + (data.collector_last_name || '');
                        }
                        
                        let customerName = 'N/A';
                        if (data.customer_first_name || data.customer_last_name) {
                            customerName = (data.customer_last_name || '') + ', ' + (data.customer_first_name || '');
                            if (data.customer_middle_name) {
                                customerName += ' ' + data.customer_middle_name;
                            }
                            if (data.customer_suffix && data.customer_suffix !== 'None') {
                                customerName += ', ' + data.customer_suffix;
                            }
                        }
                        
                        const formatNumber = num => {
                            return parseFloat(num).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
                        };
                        
                        document.getElementById('paymentDetailsContent').innerHTML = `
                            <div class="pc-pay">
                                <header class="pc-pay__head">
                                    <span class="pc-pay__icon"><i class="fas fa-receipt" aria-hidden="true"></i></span>
                                    <div class="pc-pay__ident">
                                        <h5 class="pc-pay__id">Payment #${data.id}</h5>
                                        <p class="pc-pay__date">${data.payment_date}</p>
                                    </div>
                                    <span class="pc-pill pc-pill--success"><i class="fas fa-check-circle" aria-hidden="true"></i> Paid</span>
                                </header>

                                <div class="pc-pay__amount">
                                    <span class="pc-pay__amount-label">Amount paid</span>
                                    <span class="pc-pay__amount-value">&#8369;${formatNumber(parseFloat(data.amount).toFixed(2))}</span>
                                </div>

                                <dl class="pc-pay__grid">
                                    <div class="pc-pay__row">
                                        <dt>Payment method</dt>
                                        <dd>${data.payment_method || 'N/A'}</dd>
                                    </div>
                                    <div class="pc-pay__row">
                                        <dt>Loan account</dt>
                                        <dd>Loan #${data.loan_id || 'N/A'}</dd>
                                    </div>
                                    <div class="pc-pay__row">
                                        <dt>Collector</dt>
                                        <dd>${collectorName}</dd>
                                    </div>
                                    <div class="pc-pay__row">
                                        <dt>Proxy</dt>
                                        <dd>${data.proxy_name || 'N/A'}</dd>
                                    </div>
                                    <div class="pc-pay__row pc-pay__row--wide">
                                        <dt>Customer</dt>
                                        <dd>${customerName}</dd>
                                    </div>
                                    <div class="pc-pay__row pc-pay__row--wide">
                                        <dt>Notes</dt>
                                        <dd>${data.notes || 'No notes available'}</dd>
                                    </div>
                                </dl>
                            </div>
                        `;
                        
                        const printPaymentBtn = document.getElementById('printPaymentBtn');
                        if (printPaymentBtn) {
                            printPaymentBtn.onclick = function() {
                                window.open(`print_payment.php?id=${paymentId}`, '_blank');
                            };
                        }
                        
                        modal.show();
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        document.getElementById('paymentDetailsContent').innerHTML = `
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Error loading payment details: ${error.message}
                            </div>
                        `;
                        modal.show();
                    });
            }
            
            function viewSavingsDepositDetails(depositId) {
                const modal = new bootstrap.Modal(document.getElementById('viewSavingsDepositModal'));
                
                document.getElementById('savingsDepositDetailsContent').innerHTML = `
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2">Loading savings deposit details...</p>
                    </div>
                `;
                
                fetch(`api/get_savings_deposit_details.php?id=${depositId}`)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.error) {
                            throw new Error(data.error);
                        }
                        
                        let collectorName = 'N/A';
                        if (data.collector_first_name || data.collector_last_name) {
                            collectorName = (data.collector_first_name || '') + ' ' + (data.collector_last_name || '');
                        }
                        
                        let customerName = 'N/A';
                        if (data.customer_first_name || data.customer_last_name) {
                            customerName = (data.customer_last_name || '') + ', ' + (data.customer_first_name || '');
                            if (data.customer_middle_name) {
                                customerName += ' ' + data.customer_middle_name;
                            }
                            if (data.customer_suffix && data.customer_suffix !== 'None') {
                                customerName += ', ' + data.customer_suffix;
                            }
                        }
                        
                        const formatNumber = num => {
                            return parseFloat(num).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
                        };
                        
                        let statusText = 'Deposited';
                        let statusIcon = 'fas fa-check-circle';
                        
                        if (data.transaction_type === 'Withdrawal Cash') {
                            statusText = 'Cash Savings Withdrawal';
                            statusIcon = 'fas fa-money-bill-wave';
                        }
                        
                        document.getElementById('savingsDepositDetailsContent').innerHTML = `
                            <div class="pc-pay">
                                <header class="pc-pay__head">
                                    <span class="pc-pay__icon"><i class="fas fa-piggy-bank" aria-hidden="true"></i></span>
                                    <div class="pc-pay__ident">
                                        <h5 class="pc-pay__id">Savings ${data.transaction_type === 'Withdrawal Cash' ? 'Withdrawal' : 'Deposit'} #${data.id}</h5>
                                        <p class="pc-pay__date">${data.transaction_date}</p>
                                    </div>
                                    <span class="pc-pill ${data.transaction_type === 'Withdrawal Cash' ? 'pc-pill--danger' : 'pc-pill--success'}">
                                        <i class="${statusIcon}" aria-hidden="true"></i> ${statusText}
                                    </span>
                                </header>

                                <div class="pc-pay__amount ${data.transaction_type === 'Withdrawal Cash' ? 'pc-pay__amount--debit' : ''}">
                                    <span class="pc-pay__amount-label">Amount</span>
                                    <span class="pc-pay__amount-value">&#8369;${formatNumber(parseFloat(data.amount).toFixed(2))}</span>
                                </div>

                                <dl class="pc-pay__grid">
                                    <div class="pc-pay__row">
                                        <dt>Transaction type</dt>
                                        <dd>${data.transaction_type || 'N/A'}</dd>
                                    </div>
                                    <div class="pc-pay__row">
                                        <dt>Payment method</dt>
                                        <dd>${data.payment_method || 'N/A'}</dd>
                                    </div>
                                    <div class="pc-pay__row">
                                        <dt>Loan account</dt>
                                        <dd>Loan #${data.loan_id || 'N/A'}</dd>
                                    </div>
                                    <div class="pc-pay__row">
                                        <dt>Collector</dt>
                                        <dd>${collectorName}</dd>
                                    </div>
                                    <div class="pc-pay__row">
                                        <dt>Proxy</dt>
                                        <dd>${data.proxy_name || 'N/A'}</dd>
                                    </div>
                                    <div class="pc-pay__row pc-pay__row--wide">
                                        <dt>Customer</dt>
                                        <dd>${customerName}</dd>
                                    </div>
                                    <div class="pc-pay__row pc-pay__row--wide">
                                        <dt>Notes</dt>
                                        <dd>${data.notes || 'No notes available'}</dd>
                                    </div>
                                </dl>
                            </div>
                        `;
                        
                        const printSavingsDepositBtn = document.getElementById('printSavingsDepositBtn');
                        if (printSavingsDepositBtn) {
                            printSavingsDepositBtn.onclick = function() {
                                window.open(`print_savings_deposit.php?id=${depositId}`, '_blank');
                            };
                        }
                        
                        modal.show();
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        document.getElementById('savingsDepositDetailsContent').innerHTML = `
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Error loading savings deposit details: ${error.message}
                            </div>
                        `;
                        modal.show();
                    });
            }
            
            function viewPenaltyDetails(penaltyId) {
                const modal = new bootstrap.Modal(document.getElementById('viewPenaltyModal'));
                
                document.getElementById('penaltyDetailsContent').innerHTML = `
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2">Loading penalty details...</p>
                    </div>
                `;
                
                fetch(`api/get_penalty_details.php?id=${penaltyId}`)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.error) {
                            throw new Error(data.error);
                        }
                        
                        let collectorName = 'N/A';
                        if (data.collector_first_name || data.collector_last_name) {
                            collectorName = (data.collector_first_name || '') + ' ' + (data.collector_last_name || '');
                        }
                        
                        let customerName = 'N/A';
                        if (data.customer_first_name || data.customer_last_name) {
                            customerName = (data.customer_last_name || '') + ', ' + (data.customer_first_name || '');
                            if (data.customer_middle_name) {
                                customerName += ' ' + data.customer_middle_name;
                            }
                            if (data.customer_suffix && data.customer_suffix !== 'None') {
                                customerName += ', ' + data.customer_suffix;
                            }
                        }
                        
                        const formatNumber = num => {
                            return parseFloat(num).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
                        };
                        
                        let statusText = data.type || 'N/A';
                        let statusClass = '';
                        let statusIcon = '';
                        
                        if (data.type === 'added') {
                            statusClass = 'text-danger';
                            statusIcon = 'fas fa-exclamation-circle';
                        } else if (data.type === 'waived') {
                            statusClass = 'text-success';
                            statusIcon = 'fas fa-hand-holding-heart';
                        } else if (data.type === 'partial waiver') {
                            statusClass = 'text-warning';
                            statusIcon = 'fas fa-hand-holding-usd';
                        } else {
                            statusClass = 'text-secondary';
                            statusIcon = 'fas fa-info-circle';
                        }
                        
                        document.getElementById('penaltyDetailsContent').innerHTML = `
                            <div class="penalty-details">
                                <div class="mb-3">
                                    <h5 class="fw-bold">Penalty #${data.id}</h5>
                                    <p class="text-muted">${data.date || 'N/A'}</p>
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <h6 class="fw-bold">Amount</h6>
                                        <p class="fs-4 fw-bold ${statusClass}">
                                            ₱${formatNumber(parseFloat(data.amount).toFixed(2))}
                                        </p>
                                    </div>
                                    <div class="col-md-6">
                                        <h6 class="fw-bold">Status</h6>
                                        <p class="${statusClass}">
                                            <i class="${statusIcon} me-1"></i> ${statusText}
                                        </p>
                                    </div>
                                </div>
                                
                                <table class="table table-borderless penalty-details-table">
                                    <tr>
                                        <th>Penalty Type:</th>
                                        <td>${data.type || 'N/A'}</td>
                                    </tr>
                                    <tr>
                                        <th>Loan Account:</th>
                                        <td>Loan #${data.loan_id || 'N/A'}</td>
                                    </tr>
                                    <tr>
                                        <th>Notes:</th>
                                        <td>${data.notes || 'No notes available'}</td>
                                    </tr>
                                    <tr>
                                        <th>Collector:</th>
                                        <td>${collectorName}</td>
                                    </tr>
                                    <tr>
                                        <th>Customer:</th>
                                        <td>${customerName}</td>
                                    </tr>
                                </table>
                            </div>
                        `;
                        
                        const printPenaltyBtn = document.getElementById('printPenaltyBtn');
                        if (printPenaltyBtn) {
                            printPenaltyBtn.onclick = function() {
                                window.open(`print_penalty.php?id=${penaltyId}`, '_blank');
                            };
                        }
                        
                        modal.show();
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        document.getElementById('penaltyDetailsContent').innerHTML = `
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Error loading penalty details: ${error.message}
                            </div>
                        `;
                        modal.show();
                    });
            }
            
            function deletePayment(paymentId, loanId) {
                const confirmBtn = document.getElementById('confirmDeletePaymentBtn');
                confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Deleting...';
                confirmBtn.disabled = true;

                fetch(`api/delete_payment.php?id=${paymentId}&loan_id=${loanId}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `id=${paymentId}&loan_id=${loanId}`
                })
                .then(response => {
                    if (!response.ok) {
                        return response.json().then(err => { throw new Error(err.error || 'Network response was not ok'); });
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.error) {
                        throw new Error(data.error);
                    }
                    
                    Swal.fire({
                        title: 'Success!',
                        text: 'Payment deleted successfully!',
                        icon: 'success',
                        confirmButtonText: 'OK'
                    }).then(() => {
                        const deleteModal = bootstrap.Modal.getInstance(document.getElementById('deletePaymentModal'));
                        deleteModal.hide();
                        
                        const loanModal = bootstrap.Modal.getInstance(document.getElementById('viewLoanModal'));
                        loanModal.hide();
                        
                        location.reload();
                    });
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire({
                        title: 'Error!',
                        text: 'Error deleting payment: ' + error.message,
                        icon: 'error',
                        confirmButtonText: 'OK'
                    });
                    
                    confirmBtn.innerHTML = '<i class="fas fa-trash-alt me-1"></i> Delete Payment';
                    confirmBtn.disabled = false;
                });
            }

            function deleteSavingsDeposit(depositId, loanId) {
                const confirmBtn = document.getElementById('confirmDeleteSavingsDepositBtn');
                confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Deleting...';
                confirmBtn.disabled = true;

                fetch(`api/delete_savings_deposit.php?id=${depositId}&loan_id=${loanId}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `id=${depositId}&loan_id=${loanId}`
                })
                .then(response => {
                    if (!response.ok) {
                        return response.json().then(err => { throw new Error(err.error || 'Network response was not ok'); });
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.error) {
                        throw new Error(data.error);
                    }
                    
                    Swal.fire({
                        title: 'Success!',
                        text: 'Savings deposit deleted successfully!',
                        icon: 'success',
                        confirmButtonText: 'OK'
                    }).then(() => {
                        const deleteModal = bootstrap.Modal.getInstance(document.getElementById('deleteSavingsDepositModal'));
                        deleteModal.hide();
                        
                        const loanModal = bootstrap.Modal.getInstance(document.getElementById('viewLoanModal'));
                        loanModal.hide();
                        
                        location.reload();
                    });
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire({
                        title: 'Error!',
                        text: 'Error deleting savings deposit: ' + error.message,
                        icon: 'error',
                        confirmButtonText: 'OK'
                    });
                    
                    confirmBtn.innerHTML = '<i class="fas fa-trash-alt me-1"></i> Delete Deposit';
                    confirmBtn.disabled = false;
                });
            }

            function deletePenalty(penaltyId, loanId) {
                const confirmBtn = document.getElementById('confirmDeletePenaltyBtn');
                confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Deleting...';
                confirmBtn.disabled = true;

                fetch('api/delete_penalty.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `id=${penaltyId}&loan_id=${loanId}`
                })
                .then(response => {
                    if (!response.ok) {
                        return response.json().then(err => { throw new Error(err.error || 'Network response was not ok'); });
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.error) {
                        throw new Error(data.error);
                    }
                    
                    Swal.fire({
                        title: 'Success!',
                        text: 'Penalty deleted successfully! Loan balance has been updated.',
                        icon: 'success',
                        confirmButtonText: 'OK'
                    }).then(() => {
                        const deleteModal = bootstrap.Modal.getInstance(document.getElementById('deletePenaltyModal'));
                        deleteModal.hide();
                        
                        const loanModal = bootstrap.Modal.getInstance(document.getElementById('viewLoanModal'));
                        loanModal.hide();
                        
                        location.reload();
                    });
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire({
                        title: 'Error!',
                        text: 'Error deleting penalty: ' + error.message,
                        icon: 'error',
                        confirmButtonText: 'OK'
                    });
                    
                    confirmBtn.innerHTML = '<i class="fas fa-trash-alt me-1"></i> Delete Penalty';
                    confirmBtn.disabled = false;
                });
            }

            function showToast(message, type = 'success') {
                const Toast = Swal.mixin({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 3000,
                    timerProgressBar: true,
                    didOpen: (toast) => {
                        toast.addEventListener('mouseenter', Swal.stopTimer)
                        toast.addEventListener('mouseleave', Swal.resumeTimer)
                    }
                });
                
                Toast.fire({
                    icon: type,
                    title: message
                });
            }
            
            // Payment Calendar Functionality
            if (pageCfg.hasActiveLoans) {
            const paymentCalendarData = pageCfg.paymentCalendar;
            let currentLoanId = document.getElementById('loan-select').value;
            let currentDate = new Date();
            let currentMonth = currentDate.getMonth();
            let currentYear = currentDate.getFullYear();
            
            /* Money shown in the calendar totals: thousands separators. */
            function formatCalendarAmount(value) {
                const amount = Math.max(0, Number(value) || 0);

                return '\u20b1' + amount.toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            }

            function renderCalendar() {
                const calendarTitle = document.getElementById('calendar-title');
                const calendarDays = document.getElementById('calendar-days');
                
                const firstDay = new Date(currentYear, currentMonth, 2);
                const daysInMonth = new Date(currentYear, currentMonth + 1, 0).getDate();
                
                const loanData = paymentCalendarData[currentLoanId];
                const paymentDays = {};
                
                if (loanData && loanData.days) {
                    loanData.days.forEach(day => {
                        paymentDays[day.date] = day;
                    });
                }
                
                const totalUnderpayment = loanData ? loanData.total_underpayment : 0;
                const totalAdvancePayment = loanData ? loanData.total_advance_payment : 0;

                document.getElementById('total-underpayment').textContent = formatCalendarAmount(totalUnderpayment);
                document.getElementById('total-advance-payment').textContent = formatCalendarAmount(totalAdvancePayment);
                
                const monthNames = ["January", "February", "March", "April", "May", "June", 
                                "July", "August", "September", "October", "November", "December"];
                calendarTitle.textContent = `${monthNames[currentMonth]} ${currentYear}`;
                
                calendarDays.innerHTML = '';
                
                let startingDay = firstDay.getDay();
                startingDay = startingDay === 0 ? 6 : startingDay -1;
                
                for (let i = 0; i < startingDay; i++) {
                    const emptyDay = document.createElement('div');
                    emptyDay.className = 'calendar-day';
                    calendarDays.appendChild(emptyDay);
                }
                
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                const loanStartDate = loanData ? new Date(loanData.start_date) : null;
                const loanEndDate = loanData ? new Date(loanData.end_date) : null;
                
                for (let day = 1; day <= daysInMonth; day++) {
                    const date = new Date(currentYear, currentMonth, day);
                    const dateStr = date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
                    const dayOfWeek = date.getDay();
                    
                    const dayElement = document.createElement('div');
                    dayElement.className = 'calendar-day';
                    
                    if (date.getTime() === today.getTime()) {
                        dayElement.classList.add('calendar-day-today');
                    }
                    
                    if (dayOfWeek === 0) {
                        dayElement.classList.add('calendar-day-sunday');
                        dayElement.innerHTML = `
                            <div class="calendar-day-number">${day}</div>
                        `;
                        calendarDays.appendChild(dayElement);
                        continue;
                    }
                    
                    const paymentDay = paymentDays[dateStr];
                    let statusClass = '';
                    let statusText = '';
                    let paymentInfo = '';
                    
                    if (paymentDay) {
                        if (paymentDay.status === 'released' || paymentDay.is_release_day) {
                            if (paymentDay.is_release_with_payment) {
                                statusClass = 'status-released';
                                if (window.innerWidth > 576) {
                                statusText = `Loan Released (Advance Payment ${formatCalendarAmount(paymentDay.payment_amount)})`;
                                } else {
                                    statusText = 'Released + Paid';
                                }
                                
                                paymentInfo = `
                                    <div class="calendar-day-content" data-bs-toggle="popover" data-bs-placement="top" 
                                        data-bs-content="<strong>Loan Released</strong><br>
                                        <strong>Advance Payment:</strong> ${formatCalendarAmount(paymentDay.payment_amount)}<br>
                                        <strong>Collector:</strong> ${paymentDay.collector_name || 'N/A'}<br>
                                        <strong>Method:</strong> ${paymentDay.payment_method || 'N/A'}<br>
                                        <strong>Notes:</strong> ${paymentDay.payment_note || 'None'}">
                                        <div class="calendar-day-number">${day}</div>
                                        <div class="calendar-day-status ${statusClass}">${statusText}</div>
                                    </div>
                                `;
                            } else {
                                statusClass = 'status-released';
                                statusText = 'Loan Released';
                                paymentInfo = `
                                    <div class="calendar-day-content">
                                        <div class="calendar-day-number">${day}</div>
                                        <div class="calendar-day-status ${statusClass}">${statusText}</div>
                                    </div>
                                `;
                            }
                        } else if (paymentDay.status === 'paid') {
                            if (paymentDay.is_overdue || (loanEndDate && date > loanEndDate)) {
                                statusClass = 'status-overdue';
                                if (window.innerWidth > 576) {
                                statusText = 'Overdue | Paid: ' + formatCalendarAmount(paymentDay.payment_amount);
                                } else {
                                    statusText = 'Overdue';
                                }
                            } else {
                                statusClass = 'status-paid';
                                if (window.innerWidth > 576) {
                                statusText = 'Paid: ' + formatCalendarAmount(paymentDay.payment_amount);
                                    if (paymentDay.underpayment > 0) {
                                statusText += ' (Under: ' + formatCalendarAmount(paymentDay.underpayment) + ')';
                                    }
                                } else {
                                    statusText = 'Paid';
                                    if (paymentDay.underpayment > 0) {
                                statusText += ' (' + formatCalendarAmount(paymentDay.underpayment) + ')';
                                    }
                                }
                            }
                            
                            paymentInfo = `
                                <div class="calendar-day-content" data-bs-toggle="popover" data-bs-placement="top" 
                                    data-bs-content="<strong>Collector:</strong> ${paymentDay.collector_name || 'N/A'}<br>
                                    <strong>Method:</strong> ${paymentDay.payment_method || 'N/A'}<br>
                                        <strong>Amount:</strong> ${formatCalendarAmount(paymentDay.payment_amount)}<br>
                                    ${paymentDay.is_overdue ? '<strong>Status:</strong> Overdue Payment<br>' : ''}
                                        ${paymentDay.expected_amount > 0 ? '<strong>Expected:</strong> ' + formatCalendarAmount(paymentDay.expected_amount) + '<br>' : ''}
                                        <strong>Underpayment:</strong> ${formatCalendarAmount(Math.max(0, parseFloat(paymentDay.underpayment)))}<br>
                                        <strong>Advance:</strong> ${formatCalendarAmount(Math.max(0, parseFloat(paymentDay.advance_payment)))}<br>
                                        <strong>Total Underpayment:</strong> ${formatCalendarAmount(Math.max(0, parseFloat(paymentDay.total_underpayment)))}<br>
                                        <strong>Total Advance:</strong> ${formatCalendarAmount(Math.max(0, parseFloat(paymentDay.total_advance_payment)))}<br>
                                        <strong>Running Balance:</strong> ${formatCalendarAmount(paymentDay.running_balance || 0)}<br>
                                    <strong>Notes:</strong> ${paymentDay.payment_note || 'None'}">
                                    <div class="calendar-day-number">${day}</div>
                                    <div class="calendar-day-status ${statusClass}">${statusText}</div>
                                </div>
                            `;
                        } else if (paymentDay.status === 'missed') {
                            statusClass = 'status-missed';
                            if (window.innerWidth > 576) {
                                statusText = 'No Payment';
                                if (paymentDay.underpayment > 0) {
                                statusText += ' (Under: ' + formatCalendarAmount(paymentDay.underpayment) + ')';
                                }
                            } else {
                                statusText = 'No Payment';
                                if (paymentDay.underpayment > 0) {
                                statusText += ' (' + formatCalendarAmount(paymentDay.underpayment) + ')';
                                }
                            }
                            paymentInfo = `
                                <div class="calendar-day-content">
                                    <div class="calendar-day-number">${day}</div>
                                    <div class="calendar-day-status ${statusClass}">${statusText}</div>
                                </div>
                            `;
                        } else {
                            statusClass = 'status-pending';
                            if (window.innerWidth > 576) {
                                statusText = 'Pending';
                            } else {
                                statusText = 'Pending';
                            }
                            paymentInfo = `
                                <div class="calendar-day-content">
                                    <div class="calendar-day-number">${day}</div>
                                    <div class="calendar-day-status ${statusClass}">${statusText}</div>
                                </div>
                            `;
                        }
                    } else {
                        const isInLoanPeriod = loanStartDate && loanEndDate && 
                                            date >= loanStartDate && 
                                            date <= loanEndDate;
                        
                        const isOverduePeriod = loanEndDate && date > loanEndDate;
                        
                        if (isInLoanPeriod) {
                            const isFirstDay = date.getTime() === new Date(loanData.start_date).getTime();
                            
                            if (isFirstDay) {
                                statusClass = 'status-released';
                                statusText = 'Loan Released';
                                paymentInfo = `
                                    <div class="calendar-day-content">
                                        <div class="calendar-day-number">${day}</div>
                                        <div class="calendar-day-status ${statusClass}">${statusText}</div>
                                    </div>
                                `;
                            } else if (date < today) {
                                statusClass = 'status-missed';
                                statusText = 'No Payment';
                                if (window.innerWidth > 576) {
                                statusText += ' (Under: ' + (loanData ? formatCalendarAmount(loanData.daily_payment) : '₱0.00') + ')';
                                } else {
                                statusText += ' (' + (loanData ? formatCalendarAmount(loanData.daily_payment) : '₱0.00') + ')';
                                }
                            } else {
                                statusClass = 'status-pending';
                                statusText = 'Pending';
                            }
                        } else if (isOverduePeriod && date < today) {
                            statusClass = 'status-missed';
                            statusText = 'Overdue';
                        } else {
                            statusClass = '';
                            statusText = '';
                        }
                        
                        paymentInfo = `
                            <div class="calendar-day-content">
                                <div class="calendar-day-number">${day}</div>
                                ${statusText ? `<div class="calendar-day-status ${statusClass}">${statusText}</div>` : ''}
                            </div>
                        `;
                    }
                    
                    dayElement.innerHTML = paymentInfo;
                    calendarDays.appendChild(dayElement);
                }
                
                const popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
                popoverTriggerList.map(function (popoverTriggerEl) {
                    return new bootstrap.Popover(popoverTriggerEl, {
                        container: 'body',
                        html: true,
                        trigger: 'hover',
                        customClass: 'payment-details-popover'
                    });
                });
            }
            
            document.getElementById('prev-month').addEventListener('click', function() {
                currentMonth--;
                if (currentMonth < 0) {
                    currentMonth = 11;
                    currentYear--;
                }
                renderCalendar();
            });
            
            document.getElementById('next-month').addEventListener('click', function() {
                currentMonth++;
                if (currentMonth > 11) {
                    currentMonth = 0;
                    currentYear++;
                }
                renderCalendar();
            });
            
            document.getElementById('current-month').addEventListener('click', function() {
                const today = new Date();
                currentMonth = today.getMonth();
                currentYear = today.getFullYear();
                renderCalendar();
            });
            
            document.getElementById('loan-select').addEventListener('change', function() {
                currentLoanId = this.value;
                renderCalendar();
            });
            
            window.addEventListener('resize', function() {
                renderCalendar();
            });
            
            renderCalendar();
            }
        });

    /* ======================================================================
       Stacked modals
       Any modal opened on top of Loan Details should dim it the way the page
       is dimmed behind a single modal. Bootstrap puts the second backdrop
       below the first modal, so we add our own layer between the two dialogs
       and raise the top modal above it.
       ====================================================================== */

    (function () {
        var OVERLAY_CLASS = 'pc-stack-backdrop';
        var STACK_Z_INDEX = '1057';

        document.addEventListener('DOMContentLoaded', function () {
            var loanModal = document.getElementById('viewLoanModal');

            if (!loanModal) {
                return;
            }

            var overlay = null;
            var raised = [];

            function addOverlay() {
                if (overlay) {
                    return;
                }

                overlay = document.createElement('div');
                overlay.className = OVERLAY_CLASS;
                document.body.appendChild(overlay);
            }

            function removeOverlayIfIdle() {
                var stillOpen = document.querySelector('.modal.show:not(#viewLoanModal)');

                if (stillOpen) {
                    return;
                }

                if (overlay && overlay.parentNode) {
                    overlay.parentNode.removeChild(overlay);
                }
                overlay = null;

                raised.forEach(function (modal) {
                    modal.style.zIndex = '';
                });
                raised = [];
            }

            /* Every modal except Loan Details itself can stack on top of it. */
            Array.prototype.forEach.call(document.querySelectorAll('.modal'), function (modal) {
                if (modal === loanModal) {
                    return;
                }

                modal.addEventListener('show.bs.modal', function () {
                    if (!loanModal.classList.contains('show')) {
                        return;
                    }

                    modal.style.zIndex = STACK_Z_INDEX;

                    if (raised.indexOf(modal) === -1) {
                        raised.push(modal);
                    }

                    addOverlay();
                });

                modal.addEventListener('hidden.bs.modal', removeOverlayIfIdle);
            });
        });
    })();
})();
