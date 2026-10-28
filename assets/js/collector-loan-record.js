/* ==========================================================================
   Prime City - Collector loan record behaviour
   Payment history / edit / delete modals and the currency helpers.
   Page data lives in #collector-loan-data.
   ========================================================================== */

(function () {
    var pageConfigCache = null;

    /**
     * Page configuration from #collector-loan-data.
     */
    function pageConfig() {
        if (pageConfigCache) { return pageConfigCache; }

        var node = document.getElementById('collector-loan-data');

        if (!node) { pageConfigCache = {}; return pageConfigCache; }

        try {
            pageConfigCache = JSON.parse(node.textContent || '{}');
        } catch (error) {
            console.error('[collector-loan] Could not parse page configuration.', error);
            pageConfigCache = {};
        }

        return pageConfigCache;
    }

    const pageCfg = pageConfig();
            // Store is_admin status for JavaScript use
            const isAdmin = pageCfg.isAdmin;
            
            // Currency formatting function
            function formatCurrency(input) {
                // Remove non-numeric characters
                let value = input.value.replace(/[^\d.]/g, '');
                
                // Remove extra decimal points
                if ((value.match(/\./g) || []).length > 1) {
                    value = value.substring(0, value.lastIndexOf('.'));
                }
                
                // Format with commas
                if (value) {
                    // Split into whole and decimal parts
                    let parts = value.split('.');
                    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                    
                    // Rejoin and update the input value
                    input.value = parts.join('.');
                }
            }
            
            // Function to convert formatted currency back to number
            function parseCurrency(formattedValue) {
                return parseFloat(formattedValue.replace(/,/g, ''));
            }
            
            document.addEventListener('DOMContentLoaded', function() {
                let currentLoanId = null;
                let currentPaymentId = null;
                let currentPaymentData = null;
                
                // Handle view payments button clicks
                document.querySelectorAll('.view-payments').forEach(button => {
                    button.addEventListener('click', function() {
                        const loanId = this.getAttribute('data-loan-id');
                        const loanAmount = parseFloat(this.getAttribute('data-loan-amount'));
                        const paidAmount = parseFloat(this.getAttribute('data-paid-amount'));
                        const balance = parseFloat(this.getAttribute('data-balance'));
                        const status = this.getAttribute('data-status');
                        const term = this.getAttribute('data-term');
                        const startDate = this.getAttribute('data-start-date');
                        const endDate = this.getAttribute('data-end-date');
                        const dailyPayment = parseFloat(this.getAttribute('data-daily-payment'));
                        const payments = JSON.parse(this.getAttribute('data-payments'));
                        
                        // Store current loan ID for actions
                        currentLoanId = loanId;
                        
                        // Calculate progress percentage
                        const progress = loanAmount > 0 ? (paidAmount / loanAmount) * 100 : 0;
                        
                        // Update modal title
                        document.getElementById('viewPaymentsModalLabel').textContent = `Payment History - Loan #${loanId}`;
                        
                        // Update loan details
                        document.getElementById('modal-status').textContent = status.charAt(0).toUpperCase() + status.slice(1);
                        document.getElementById('modal-loan-amount').textContent = `₱${loanAmount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                        
                        // Calculate and display term as (loan_term * 15) days - show only the calculated days
                        const loanTerm = parseInt(term);
                        const calculatedTerm = loanTerm * 15;
                        document.getElementById('modal-term').textContent = `${calculatedTerm} days`;
                        
                        document.getElementById('modal-start-date').textContent = startDate;
                        document.getElementById('modal-end-date').textContent = endDate;
                        document.getElementById('modal-daily-payment').textContent = `₱${dailyPayment.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                        
                        // Update loan summary
                        document.getElementById('modal-loan-amount-summary').textContent = `₱${loanAmount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                        document.getElementById('modal-paid-amount').textContent = `₱${paidAmount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                        document.getElementById('modal-balance').textContent = `₱${balance.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                        
                        // Update repayment progress section
                        document.getElementById('progress-percentage').textContent = `${progress.toFixed(1)}%`;
                        document.getElementById('repayment-progress-bar').style.width = `${progress}%`;
                        document.getElementById('repayment-progress-bar').setAttribute('aria-valuenow', progress);
                        
                        const progressLabels = document.querySelectorAll('.repayment-progress-card .text-muted');
                        if (progressLabels.length >= 2) {
                            progressLabels[0].textContent = `₱${paidAmount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})} Paid`;
                            progressLabels[1].textContent = `₱${loanAmount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})} Total`;
                        }
                        
                        // Update payments table
                        const paymentsTableBody = document.getElementById('paymentsTableBody');
                        paymentsTableBody.innerHTML = '';
                        
                        if (payments.length > 0) {
                            document.getElementById('no-payments-message').classList.add('d-none');
                            paymentsTableBody.parentElement.classList.remove('d-none');
                            
                            payments.forEach(payment => {
                                const row = document.createElement('tr');
                                
                                const paymentDate = new Date(payment.payment_date);
                                const formattedDate = paymentDate.toLocaleDateString() + ' ' + paymentDate.toLocaleTimeString();
                                
                                const amount = parseFloat(payment.amount);
                                const formattedAmount = `₱${amount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                                
                                const recordedBy = payment.created_first_name && payment.created_last_name 
                                    ? `${payment.created_first_name} ${payment.created_last_name}` 
                                    : 'Unknown';
                                
                                // Build action buttons HTML based on admin status
                                let actionButtons = `
                                    <button class="btn btn-info btn-sm view-payment-details" 
                                            data-payment-id="${payment.id}"
                                            data-amount="${payment.amount}"
                                            data-date="${payment.payment_date}"
                                            data-notes="${payment.notes || ''}"
                                            data-recorded-by="${recordedBy}">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                `;
                                
                                // Only show edit and delete buttons for admin users
                                if (isAdmin) {
                                    actionButtons += `
                                        <button class="btn btn-warning btn-sm edit-payment" 
                                                data-payment-id="${payment.id}"
                                                data-amount="${payment.amount}"
                                                data-date="${payment.payment_date}"
                                                data-notes="${payment.notes || ''}">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-danger btn-sm delete-payment" 
                                                data-payment-id="${payment.id}">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    `;
                                }
                                
                                row.innerHTML = `
                                    <td>${formattedDate}</td>
                                    <td class="text-success fw-bold">${formattedAmount}</td>
                                    <td>${recordedBy}</td>
                                    <td>${payment.notes || 'No notes'}</td>
                                    <td class="payment-actions">
                                        ${actionButtons}
                                    </td>
                                `;
                                
                                paymentsTableBody.appendChild(row);
                            });
                            
                            // Add event listeners to the new action buttons
                            document.querySelectorAll('.view-payment-details').forEach(btn => {
                                btn.addEventListener('click', viewPaymentDetails);
                            });
                            
                            // Only add event listeners for edit and delete if admin
                            if (isAdmin) {
                                document.querySelectorAll('.edit-payment').forEach(btn => {
                                    btn.addEventListener('click', editPayment);
                                });
                                
                                document.querySelectorAll('.delete-payment').forEach(btn => {
                                    btn.addEventListener('click', deletePayment);
                                });
                            }
                        } else {
                            paymentsTableBody.parentElement.classList.add('d-none');
                            document.getElementById('no-payments-message').classList.remove('d-none');
                        }
                        
                        // Show the modal
                        const modal = new bootstrap.Modal(document.getElementById('viewPaymentsModal'));
                        modal.show();
                    });
                });
                
                // Handle loan deletion (only for admin users)
                if (isAdmin) {
                    document.querySelectorAll('.delete-loan').forEach(button => {
                        button.addEventListener('click', function() {
                            const loanId = this.getAttribute('data-loan-id');
                            
                            Swal.fire({
                                title: 'Are you sure?',
                                text: "This will delete the loan and all associated payments. This action cannot be undone!",
                                icon: 'warning',
                                showCancelButton: true,
                                confirmButtonColor: '#d33',
                                cancelButtonColor: '#3085d6',
                                confirmButtonText: 'Yes, delete it!',
                                cancelButtonText: 'Cancel'
                            }).then((result) => {
                                if (result.isConfirmed) {
                                    // Show loading indicator
                                    Swal.fire({
                                        title: 'Deleting...',
                                        text: 'Please wait while we delete the loan',
                                        allowOutsideClick: false,
                                        didOpen: () => {
                                            Swal.showLoading();
                                        }
                                    });
                                    
                                    // Send AJAX request to delete loan
                                    const formData = new FormData();
                                    formData.append('loan_id', loanId);
                                    
                                    fetch('api/delete_collector_loan.php', {
                                        method: 'POST',
                                        body: formData
                                    })
                                    .then(response => response.json())
                                    .then(data => {
                                        if (data.success) {
                                            Swal.fire({
                                                icon: 'success',
                                                title: 'Deleted!',
                                                text: data.message,
                                                confirmButtonColor: '#3085d6',
                                                confirmButtonText: 'OK'
                                            }).then(() => {
                                                // Reload the page to see changes
                                                location.reload();
                                            });
                                        } else {
                                            Swal.fire({
                                                icon: 'error',
                                                title: 'Error',
                                                text: data.message,
                                                confirmButtonColor: '#3085d6',
                                                confirmButtonText: 'OK'
                                            });
                                        }
                                    })
                                    .catch(error => {
                                        Swal.fire({
                                            icon: 'error',
                                            title: 'Error',
                                            text: 'An error occurred while deleting the loan.',
                                            confirmButtonColor: '#3085d6',
                                            confirmButtonText: 'OK'
                                        });
                                    });
                                }
                            });
                        });
                    });
                }
                
                // View Payment Details
                function viewPaymentDetails() {
                    const paymentId = this.getAttribute('data-payment-id');
                    const amount = this.getAttribute('data-amount');
                    const date = this.getAttribute('data-date');
                    const notes = this.getAttribute('data-notes');
                    const recordedBy = this.getAttribute('data-recorded-by');
                    
                    // Store payment data for printing
                    currentPaymentData = {
                        id: paymentId,
                        amount: amount,
                        date: date,
                        notes: notes,
                        recordedBy: recordedBy
                    };
                    
                    const paymentDate = new Date(date);
                    const formattedDate = paymentDate.toLocaleDateString() + ' ' + paymentDate.toLocaleTimeString();
                    const formattedAmount = `₱${parseFloat(amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                    
                    document.getElementById('viewPaymentModalLabel').textContent = `Payment Details #${paymentId}`;
                    document.getElementById('viewPaymentModalBody').innerHTML = `
                        <div class="mb-3">
                            <strong>Payment ID:</strong> ${paymentId}
                        </div>
                        <div class="mb-3">
                            <strong>Amount:</strong> ${formattedAmount}
                        </div>
                        <div class="mb-3">
                            <strong>Date:</strong> ${formattedDate}
                        </div>
                        <div class="mb-3">
                            <strong>Received By:</strong> ${recordedBy}
                        </div>
                        <div class="mb-3">
                            <strong>Notes:</strong> ${notes || 'No notes provided'}
                        </div>
                    `;
                    
                    const modal = new bootstrap.Modal(document.getElementById('viewPaymentModal'));
                    modal.show();
                }
                
                // Print Payment Receipt
                document.getElementById('printPaymentReceipt').addEventListener('click', function() {
                    if (currentPaymentData) {
                        window.open(`print_collector_payments.php?payment_id=${currentPaymentData.id}`, '_blank');
                    }
                });
                
                // Edit Payment (only for admin users)
                function editPayment() {
                    if (!isAdmin) return;
                    
                    const paymentId = this.getAttribute('data-payment-id');
                    const amount = this.getAttribute('data-amount');
                    
                    // Store current payment ID for saving
                    currentPaymentId = paymentId;
                    
                    document.getElementById('editPaymentModalLabel').textContent = `Edit Payment #${paymentId}`;
                    document.getElementById('edit_payment_id').value = paymentId;
                    document.getElementById('edit_loan_id').value = currentLoanId;
                    
                    // Format the amount with commas for display
                    const formattedAmount = parseFloat(amount).toLocaleString('en-US', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });
                    document.getElementById('edit_payment_amount').value = formattedAmount;
                    
                    const modal = new bootstrap.Modal(document.getElementById('editPaymentModal'));
                    modal.show();
                }
                
                // Delete Payment (only for admin users)
                function deletePayment() {
                    if (!isAdmin) return;
                    
                    const paymentId = this.getAttribute('data-payment-id');
                    
                    // Store current payment ID for deletion
                    currentPaymentId = paymentId;
                    
                    document.getElementById('deletePaymentModalLabel').textContent = `Delete Payment #${paymentId}`;
                    
                    const modal = new bootstrap.Modal(document.getElementById('deletePaymentModal'));
                    modal.show();
                }
                
                // Save Payment Changes (only for admin users)
                document.getElementById('savePaymentChanges').addEventListener('click', function() {
                    if (!isAdmin) return;
                    
                    // Parse the formatted currency value back to a number
                    const formattedAmount = document.getElementById('edit_payment_amount').value;
                    const amount = parseCurrency(formattedAmount);
                    
                    // Create form data
                    const formData = new FormData();
                    formData.append('payment_id', document.getElementById('edit_payment_id').value);
                    formData.append('loan_id', document.getElementById('edit_loan_id').value);
                    formData.append('amount', amount);
                    
                    // Send AJAX request to update payment
                    fetch('api/update_collector_payment.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Payment Updated',
                                text: data.message,
                                confirmButtonColor: '#3085d6',
                                confirmButtonText: 'OK'
                            }).then(() => {
                                // Close the modal
                                const modal = bootstrap.Modal.getInstance(document.getElementById('editPaymentModal'));
                                modal.hide();
                                
                                // Reload the page to see changes
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: data.message,
                                confirmButtonColor: '#3085d6',
                                confirmButtonText: 'OK'
                            });
                        }
                    })
                    .catch(error => {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'An error occurred while updating the payment.',
                            confirmButtonColor: '#3085d6',
                            confirmButtonText: 'OK'
                        });
                    });
                });
                
                // Confirm Delete Payment (only for admin users)
                document.getElementById('confirmDeletePayment').addEventListener('click', function() {
                    if (!isAdmin) return;
                    
                    // Send AJAX request to delete payment
                    fetch('api/delete_collector_payment.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            payment_id: currentPaymentId,
                            loan_id: currentLoanId
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Payment Deleted',
                                text: data.message,
                                confirmButtonColor: '#3085d6',
                                confirmButtonText: 'OK'
                            }).then(() => {
                                // Close the modal
                                const modal = bootstrap.Modal.getInstance(document.getElementById('deletePaymentModal'));
                                modal.hide();
                                
                                // Reload the page to see changes
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: data.message,
                                confirmButtonColor: '#3085d6',
                                confirmButtonText: 'OK'
                            });
                        }
                    })
                    .catch(error => {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'An error occurred while deleting the payment.',
                            confirmButtonColor: '#3085d6',
                            confirmButtonText: 'OK'
                        });
                    });
                });
                
                // Print Collector Loan Details
                document.getElementById('printLoanDetails').addEventListener('click', function() {
                    // Open the print page in a new window
                    window.open(`print_collector_loan.php?loan_id=${currentLoanId}`, '_blank');
                });
            });
    // The markup calls these from inline handlers.
    window.formatCurrency = formatCurrency;
    window.parseCurrency = parseCurrency;
})();
