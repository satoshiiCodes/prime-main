/* ==========================================================================
   Prime City - Collector collections record behaviour
   DataTable setup plus the payment details / print actions.
   Page data lives in api/collector-collections-endpoint.php.
   ========================================================================== */

            document.addEventListener('DOMContentLoaded', function() {
                // Initialize DataTable for active payments
                if (document.getElementById('activePaymentsTable')) {
                    new simpleDatatables.DataTable("#activePaymentsTable", {
                        perPage: 5,
                        perPageSelect: [5, 10, 25, 50, 100],
                        labels: {
                            placeholder: "Search...",
                            perPage: "{select} entries per page",
                            noRows: "No payments found",
                            info: "Showing {start} to {end} of {rows} entries",
                        },
                        layout: {
                            top: "{select}{search}",
                            bottom: "{info}{pager}"
                        }
                    });
                }
                
                // View payment button handler
                document.querySelectorAll('.view-payment-btn').forEach(button => {
                    button.addEventListener('click', function() {
                        const paymentId = this.getAttribute('data-payment-id');
                        const collectorName = this.getAttribute('data-collector-name');
                        const modal = new bootstrap.Modal(document.getElementById('viewPaymentModal'));
                        
                        // Show loading state
                        document.getElementById('paymentDetailsContent').innerHTML = `
                            <div class="text-center py-4">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <p class="mt-2">Loading payment details...</p>
                            </div>
                        `;
                        
                        // Fetch payment details
                        fetch(`api/collector-collections-endpoint.php?action=payment-details&id=${paymentId}`)
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
                                
                                // Format customer name
                                let customerName = 'N/A';
                                if (data.customer_first_name || data.customer_last_name) {
                                    customerName = (data.customer_first_name || '') + ' ' + (data.customer_last_name || '');
                                }
                                
                                // Update modal content
                                document.getElementById('paymentDetailsContent').innerHTML = `
                                    <div class="payment-details">
                                        <div class="mb-3">
                                            <h5 class="fw-bold">Payment #${data.id}</h5>
                                            <p class="text-muted">${data.payment_date}</p>
                                        </div>
                                        
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <h6 class="fw-bold">Amount</h6>
                                                <p class="fs-4 fw-bold text-success">₱${parseFloat(data.amount).toFixed(2)}</p>
                                            </div>
                                            <div class="col-md-6">
                                                <h6 class="fw-bold">Status</h6>
                                                <p class="status-paid"><i class="fas fa-check-circle me-1"></i> Paid</p>
                                            </div>
                                        </div>
                                        
                                        <table class="table table-borderless payment-details-table">
                                            <tr>
                                                <th>Payment Method:</th>
                                                <td>${data.payment_method || 'N/A'}</td>
                                            </tr>
                                            <tr>
                                                <th>Loan Account:</th>
                                                <td>Loan #${data.loan_id || 'N/A'}</td>
                                            </tr>
                                            <tr>
                                                <th>Customer:</th>
                                                <td>${customerName}</td>
                                            </tr>
                                            <tr>
                                                <th>Collector:</th>
                                                <td>${collectorName}</td>
                                            </tr>
                                            <tr>
                                                <th>Notes:</th>
                                                <td>${data.notes || 'No notes available'}</td>
                                            </tr>
                                        </table>
                                    </div>
                                `;
                                
                                // Set up print button
                                document.getElementById('printPaymentBtn').onclick = function() {
                                    window.open(`print_payment.php?id=${paymentId}`, '_blank');
                                };
                                
                                // Show the modal
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
                    });
                });
                
                // Print payment button handler
                document.querySelectorAll('.print-payment-btn').forEach(button => {
                    button.addEventListener('click', function() {
                        const paymentId = this.getAttribute('data-payment-id');
                        window.open(`print_payment.php?id=${paymentId}`, '_blank');
                    });
                });
            });
