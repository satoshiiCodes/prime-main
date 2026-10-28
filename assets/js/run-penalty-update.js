/* ==========================================================================
   Prime City - Automatic penalty update behaviour
   The confirm dialog and the AJAX call to api/update_all_penalties.php.
   Page data lives in #run-penalty-update-data.
   ========================================================================== */

(function () {
    var pageConfigCache = null;

    /**
     * Page configuration from #run-penalty-update-data.
     */
    function pageConfig() {
        if (pageConfigCache) { return pageConfigCache; }

        var node = document.getElementById('run-penalty-update-data');

        if (!node) { pageConfigCache = {}; return pageConfigCache; }

        try {
            pageConfigCache = JSON.parse(node.textContent || '{}');
        } catch (error) {
            console.error('[run-penalty-update] Could not parse page configuration.', error);
            pageConfigCache = {};
        }

        return pageConfigCache;
    }

    const pageCfg = pageConfig();

            // Function to apply branch filter
            function applyBranchFilter(branch) {
                const currentUrl = new URL(window.location.href);
                currentUrl.searchParams.set('branch', branch);
                window.location.href = currentUrl.toString();
            }

            document.getElementById('runUpdateBtn').addEventListener('click', function() {
                const btn = this;
                const originalText = btn.innerHTML;
                const branchFilter = document.getElementById('branch').value;
                
                // Show confirmation dialog
                Swal.fire({
                    title: 'Run Automatic Penalty Update?',
                    html: `This will add 5% monthly penalties to all overdue loans that have automatic penalty enabled and don't have penalties for the current month.<br><br>
                          <strong>Note:</strong> This process may take several minutes depending on the number of overdue loans.<br>
                          <strong>Eligible Loans:</strong> ${pageCfg.overdueCount}` + 
                          (branchFilter && branchFilter !== 'all' ? `<br><strong>Branch Filter:</strong> ${branchFilter}` : 
                          (branchFilter === 'all' ? `<br><strong>Branch Filter:</strong> All Branches` : '')),
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#28a745',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: '<i class="fas fa-play-circle me-2"></i>Run Update',
                    cancelButtonText: 'Cancel',
                    backdrop: true,
                    allowOutsideClick: false
                }).then((result) => {
                    if (result.isConfirmed) {
                        runPenaltyUpdate(btn, originalText, branchFilter);
                    }
                });
            });
            
            function runPenaltyUpdate(btn, originalText, branchFilter) {
                // Show loading state
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Processing...';
                btn.disabled = true;
                
                // Clear previous results
                document.getElementById('resultsContent').innerHTML = '';
                document.getElementById('resultsSection').style.display = 'none';
                
                // Show processing alert
                Swal.fire({
                    title: 'Processing Penalties...',
                    html: 'Please wait while we update penalties for all eligible overdue loans.<br><br><div class="spinner-border text-primary" role="status"></div>',
                    icon: 'info',
                    showConfirmButton: false,
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                // Prepare request data
                const formData = new FormData();
                if (branchFilter && branchFilter !== 'all') {
                    formData.append('branch', branchFilter);
                } else if (branchFilter === 'all') {
                    formData.append('branch', 'all');
                }
                
                // Make AJAX request to update penalties
                fetch('api/update_all_penalties.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    // First check if response is OK
                    if (!response.ok) {
                        throw new Error(`Server returned ${response.status}: ${response.statusText}`);
                    }
                    
                    // Get response as text first to handle potential errors
                    return response.text().then(text => {
                        try {
                            return JSON.parse(text);
                        } catch (e) {
                            console.error('Response text:', text);
                            throw new Error('Invalid JSON response from server. Check server logs for PHP errors.');
                        }
                    });
                })
                .then(data => {
                    Swal.close();
                    
                    if (data.success) {
                        displayResults(data.results);
                        Swal.fire({
                            title: 'Success!',
                            html: `Penalty update completed successfully!<br><br>
                                  <strong>Processed:</strong> ${data.results.total_loans_processed} loans<br>
                                  <strong>Penalties Added:</strong> ${data.results.penalties_added}<br>
                                  <strong>Total Amount:</strong> ₱${data.results.total_penalty_amount ? data.results.total_penalty_amount.toFixed(2) : '0.00'}<br>
                                  <strong>Timestamp:</strong> ${new Date().toLocaleString()}`,
                            icon: 'success',
                            confirmButtonText: 'OK',
                            confirmButtonColor: '#28a745'
                        });
                    } else {
                        throw new Error(data.error || data.message || 'Unknown error occurred');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire({
                        title: 'Error!',
                        html: `Error running penalty update:<br><strong>${error.message}</strong><br><br>Please check the server logs for more details.`,
                        icon: 'error',
                        confirmButtonText: 'OK',
                        confirmButtonColor: '#dc3545'
                    });
                })
                .finally(() => {
                    // Reset button state
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                });
            }
            
            function displayResults(results) {
                const resultsContent = document.getElementById('resultsContent');
                let html = '';
                
                // Summary
                html += `
                    <div class="alert alert-success mb-3">
                        <h6><i class="fas fa-check-circle me-2"></i>Update Summary</h6>
                        <div class="row">
                            <div class="col-md-4">
                                <strong>Total Loans Processed:</strong> ${results.total_loans_processed || 0}
                            </div>
                            <div class="col-md-4">
                                <strong>Penalties Added:</strong> ${results.penalties_added || 0}
                            </div>
                            <div class="col-md-4">
                                <strong>Total Penalty Amount:</strong> ₱${results.total_penalty_amount ? results.total_penalty_amount.toFixed(2) : '0.00'}
                            </div>
                        </div>
                    </div>
                `;
                
                // Processed loans
                if (results.detailed_results && results.detailed_results.length > 0) {
                    html += `<h6><i class="fas fa-list me-2"></i>Processed Loans:</h6>`;
                    
                    // Group by status for better organization
                    const successLoans = results.detailed_results.filter(loan => loan.penalties_added > 0);
                    const skippedLoans = results.detailed_results.filter(loan => loan.penalties_added === 0 && !loan.automatic_penalty_disabled);
                    const disabledLoans = results.detailed_results.filter(loan => loan.automatic_penalty_disabled);
                    
                    if (successLoans.length > 0) {
                        html += `<h6 class="mt-3 text-success">Successful Updates:</h6>`;
                        successLoans.forEach(loan => {
                            html += `
                                <div class="loan-item success">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <strong>${loan.customer_name || `Loan #${loan.loan_id}`}</strong>
                                        </div>
                                        <div><span class="status-success">Penalties Added</span></div>
                                    </div>
                                    <small class="text-muted">Added ${loan.penalties_added} penalties totaling ₱${loan.penalty_amount ? loan.penalty_amount.toFixed(2) : '0.00'}</small>
                                </div>
                            `;
                        });
                    }
                    
                    if (skippedLoans.length > 0) {
                        html += `<h6 class="mt-3 text-muted">Skipped Loans:</h6>`;
                        skippedLoans.forEach(loan => {
                            html += `
                                <div class="loan-item skipped">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <strong>${loan.customer_name || `Loan #${loan.loan_id}`}</strong>
                                        </div>
                                        <div><span class="status-skipped">SKIPPED</span></div>
                                    </div>
                                    <small class="text-muted">No penalties needed or already up to date</small>
                                </div>
                            `;
                        });
                    }
                    
                    if (disabledLoans.length > 0) {
                        html += `<h6 class="mt-3 text-warning">Automatic Penalty Disabled:</h6>`;
                        disabledLoans.forEach(loan => {
                            html += `
                                <div class="loan-item disabled">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <strong>${loan.customer_name || `Loan #${loan.loan_id}`}</strong>
                                        </div>
                                        <div><span class="status-skipped">DISABLED</span></div>
                                    </div>
                                    <small class="text-muted">Automatic penalty is disabled for this loan</small>
                                </div>
                            `;
                        });
                    }
                } else {
                    html += `<div class="alert alert-warning">No loans were processed.</div>`;
                }
                
                // Errors
                if (results.errors && results.errors.length > 0) {
                    html += `<h6 class="mt-3 text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Errors:</h6>`;
                    results.errors.forEach(error => {
                        html += `
                            <div class="loan-item error">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div><strong>Error</strong></div>
                                    <div><span class="status-error">Error</span></div>
                                </div>
                                <small class="text-danger">${error}</small>
                            </div>
                        `;
                    });
                }
                
                resultsContent.innerHTML = html;
                document.getElementById('resultsSection').style.display = 'block';
                
                // Scroll to results
                document.getElementById('resultsSection').scrollIntoView({ 
                    behavior: 'smooth' 
                });
            }

    // Called from the branch filter markup.
    window.applyBranchFilter = applyBranchFilter;
})();
