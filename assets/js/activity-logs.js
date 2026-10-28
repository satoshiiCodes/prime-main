/* ==========================================================================
   Prime City - Activity logs behaviour
   The details viewer, clipboard copy and the clear-logs confirmation.
   Clearing itself is done by api/clear_activity_logs.php.
   ========================================================================== */

            document.addEventListener('DOMContentLoaded', function() {
                // View details button handler
                document.querySelectorAll('.view-details-btn').forEach(button => {
                    button.addEventListener('click', function() {
                        const details = this.getAttribute('data-details');
                        try {
                            // Try to parse as JSON and format it
                            const parsedDetails = JSON.parse(details);
                            document.getElementById('detailsContent').textContent = 
                                JSON.stringify(parsedDetails, null, 2);
                        } catch (e) {
                            // If not JSON, display as plain text
                            document.getElementById('detailsContent').textContent = details;
                        }
                    });
                });
                
                // Copy to clipboard button handler
                document.getElementById('copyDetailsBtn').addEventListener('click', function() {
                    const detailsContent = document.getElementById('detailsContent').textContent;
                    navigator.clipboard.writeText(detailsContent).then(() => {
                        // Show success message
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
                            icon: 'success',
                            title: 'Details copied to clipboard!'
                        });
                    }).catch(err => {
                        console.error('Failed to copy: ', err);
                        Swal.fire({
                            title: 'Error!',
                            text: 'Failed to copy to clipboard.',
                            icon: 'error',
                            confirmButtonText: 'OK'
                        });
                    });
                });
                
                // Clear logs button handler
                document.getElementById('clearLogsBtn').addEventListener('click', function() {
                    const clearModal = new bootstrap.Modal(document.getElementById('clearLogsModal'));
                    clearModal.show();
                });
                
                // Confirm clear logs button handler
                document.getElementById('confirmClearLogsBtn').addEventListener('click', function() {
                    // Show loading state
                    const confirmBtn = document.getElementById('confirmClearLogsBtn');
                    const originalBtnContent = confirmBtn.innerHTML;
                    confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Clearing...';
                    confirmBtn.disabled = true;
                    
                    // Send request to clear logs
                    fetch('api/clear_activity_logs.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        }
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
                        
                        // Show success message
                        Swal.fire({
                            title: 'Success!',
                            text: 'All activity logs have been cleared successfully!',
                            icon: 'success',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            // Close the clear modal
                            const clearModal = bootstrap.Modal.getInstance(document.getElementById('clearLogsModal'));
                            clearModal.hide();
                            
                            // Reload the page
                            location.reload();
                        });
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        Swal.fire({
                            title: 'Error!',
                            text: 'Error clearing logs: ' + error.message,
                            icon: 'error',
                            confirmButtonText: 'OK'
                        });
                    })
                    .finally(() => {
                        // Reset the button state
                        confirmBtn.innerHTML = originalBtnContent;
                        confirmBtn.disabled = false;
                    });
                });
            });
