/* ==========================================================================
   Prime City - Date/time change log behaviour
   The CSV export button (posts the current filters to
   api/export_logs.php) and the tooltip initialiser.
   ========================================================================== */

(function () {
        function exportToCSV() {
            // Get current filter parameters
            const params = new URLSearchParams(window.location.search);
            
            // Show loading alert
            Swal.fire({
                title: 'Exporting to CSV',
                text: 'Please wait while we prepare your download...',
                icon: 'info',
                showConfirmButton: false,
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            // Create a form to submit the export request
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'api/export_logs.php';
            
            // Add all current filter parameters as hidden inputs
            for (const [key, value] of params) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = value;
                form.appendChild(input);
            }
            
            // Add CSRF token if available (you might need to adjust this based on your setup)
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
            if (csrfToken) {
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = csrfToken;
                form.appendChild(csrfInput);
            }
            
            // Append form to body and submit
            document.body.appendChild(form);
            form.submit();
            
            // Remove the form after submission
            document.body.removeChild(form);
            
            // Close the alert after a short delay
            setTimeout(() => {
                Swal.close();
            }, 2000);
        }
        
        // Initialize tooltips for truncated content
        document.addEventListener('DOMContentLoaded', function() {
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
            tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });

    // Called from the export button's inline handler.
    window.exportToCSV = exportToCSV;
})();
