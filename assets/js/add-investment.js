/* ==========================================================================
   Prime City - Investments behaviour
   Date pickers, amount formatting, table init, modals and flash messages.
   Page data lives in #add-investment-data.
   ========================================================================== */

(function () {
    var pageConfigCache = null;

    /**
     * Page configuration from #add-investment-data.
     */
    function pageConfig() {
        if (pageConfigCache) { return pageConfigCache; }

        var node = document.getElementById('add-investment-data');

        if (!node) { pageConfigCache = {}; return pageConfigCache; }

        try {
            pageConfigCache = JSON.parse(node.textContent || '{}');
        } catch (error) {
            console.error('[add-investment] Could not parse page configuration.', error);
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

            // Initialize datetime picker with the correct format
            document.addEventListener('DOMContentLoaded', function() {
                flatpickr("#date", {
                    enableTime: true,
                    enableSeconds: true,
                    dateFormat: "m-d-Y h:i:S K", // Changed to the requested format
                    defaultDate: new Date(),
                    time_24hr: false // Show AM/PM format
                });

                // Initialize datetime picker for edit form
                let editDatePicker = flatpickr("#edit-date", {
                    enableTime: true,
                    enableSeconds: true,
                    dateFormat: "m-d-Y h:i:S K",
                    time_24hr: false
                });

                // Format amount input with commas
                const amountInput = document.getElementById('amount');
                const editAmountInput = document.getElementById('edit-amount');
                
                amountInput.addEventListener('input', function(e) {
                    formatCurrency(this);
                });

                editAmountInput.addEventListener('input', function(e) {
                    formatCurrency(this);
                });

                function formatCurrency(input) {
                    // Remove all non-digit characters except decimal point
                    let value = input.value.replace(/[^\d.]/g, '');
                    
                    // Split into parts before and after decimal
                    let parts = value.split('.');
                    let wholeNumber = parts[0];
                    let decimal = parts.length > 1 ? '.' + parts[1] : '';
                    
                    // Add commas to whole number part
                    wholeNumber = wholeNumber.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                    
                    // Combine and update value
                    input.value = wholeNumber + decimal;
                }

                // Initialize DataTables (the CDN script may be unavailable)
                let dataTable = null;

                if (typeof simpleDatatables !== 'undefined') {
                    dataTable = new simpleDatatables.DataTable("#datatablesSimple", {
                        perPage: 10,
                        sortable: true,
                        searchable: true,
                        labels: {
                            placeholder: "Search investments...",
                            perPage: "{select} entries per page",
                            noRows: "No investments found",
                            info: "Showing {start} to {end} of {rows} entries"
                        }
                    });
                }

                // Toolbar search (left) drives the table; the branch filter
                // lives on the same row on the right.
                (function wireHistorySearch() {
                    const input = document.getElementById('investmentSearch');
                    const table = document.getElementById('datatablesSimple');

                    if (!input || !table) {
                        return;
                    }

                    input.addEventListener('input', function () {
                        const term = this.value.trim();

                        if (dataTable && typeof dataTable.search === 'function') {
                            dataTable.search(term);
                            return;
                        }

                        /* fallback: filter the plain table rows */
                        const needle = term.toLowerCase();

                        table.querySelectorAll('tbody tr').forEach(function (row) {
                            const text = (row.textContent || '').toLowerCase();
                            row.style.display = (!needle || text.indexOf(needle) !== -1) ? '' : 'none';
                        });
                    });
                })();

                // Show SweetAlert2 notifications for errors or success
                // Flash messages coming from PHP (see #add-investment-data)
                if (pageCfg.error) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: pageCfg.error,
                        confirmButtonColor: '#3085d6',
                    });
                }

                if (pageCfg.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success',
                        text: pageCfg.success,
                        confirmButtonColor: '#3085d6',
                    });
                }

                // View button click handler
                document.querySelectorAll('.view-btn').forEach(button => {
                    button.addEventListener('click', function() {
                        document.getElementById('view-date').textContent = this.getAttribute('data-date');
                        document.getElementById('view-branch').textContent = this.getAttribute('data-branch');
                        document.getElementById('view-type').textContent = this.getAttribute('data-type');
                        document.getElementById('view-amount').textContent = this.getAttribute('data-amount');
                        document.getElementById('view-notes').textContent = this.getAttribute('data-notes');
                    });
                });

                // Edit button click handler
                document.querySelectorAll('.edit-btn').forEach(button => {
                    button.addEventListener('click', function() {
                        document.getElementById('edit-id').value = this.getAttribute('data-id');
                        
                        // Convert date from database format to display format
                        const dbDate = this.getAttribute('data-date');
                        const dateObj = new Date(dbDate);
                        const formattedDate = dateObj.toLocaleString('en-US', {
                            month: '2-digit',
                            day: '2-digit',
                            year: 'numeric',
                            hour: '2-digit',
                            minute: '2-digit',
                            second: '2-digit',
                            hour12: true
                        }).replace(',', '');
                        
                        // Set the date in the flatpickr instance
                        editDatePicker.setDate(formattedDate, true, "m-d-Y h:i:S K");
                        
                        document.getElementById('edit-branch').value = this.getAttribute('data-branch');
                        document.getElementById('edit-type').value = this.getAttribute('data-type');
                        
                        // Format amount with commas
                        const amount = parseFloat(this.getAttribute('data-amount'));
                        document.getElementById('edit-amount').value = amount.toLocaleString('en-US', {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2
                        });
                        
                        document.getElementById('edit-notes').value = this.getAttribute('data-notes');
                    });
                });

                // Delete button click handler
                document.querySelectorAll('.delete-btn').forEach(button => {
                    button.addEventListener('click', function() {
                        const id = this.getAttribute('data-id');
                        document.getElementById('delete-id').value = id;
                    });
                });

                // Confirm delete button handler
                document.getElementById('confirm-delete').addEventListener('click', function() {
                    const id = document.getElementById('delete-id').value;
                    window.location.href = `add_investment.php?delete_id=${id}`;
                });

                // Form submission with SweetAlert confirmation
                document.getElementById('investmentForm').addEventListener('submit', function(e) {
                    // Validate form
                    const date = document.getElementById('date').value;
                    const type = document.getElementById('type').value;
                    const branch = document.getElementById('branch').value;
                    const amount = parseFloat(document.getElementById('amount').value.replace(/,/g, ''));
                    
                    if (!date || !type || !branch || !amount) {
                        e.preventDefault();
                        Swal.fire({
                            icon: 'error',
                            title: 'Missing Information',
                            text: 'Please fill in all required fields.',
                            confirmButtonColor: '#3085d6',
                        });
                        return;
                    }
                });

                // Edit form submission with SweetAlert confirmation
                document.getElementById('editForm').addEventListener('submit', function(e) {
                    // Validate form
                    const date = document.getElementById('edit-date').value;
                    const type = document.getElementById('edit-type').value;
                    const branch = document.getElementById('edit-branch').value;
                    const amount = parseFloat(document.getElementById('edit-amount').value.replace(/,/g, ''));
                    
                    if (!date || !type || !branch || !amount) {
                        e.preventDefault();
                        Swal.fire({
                            icon: 'error',
                            title: 'Missing Information',
                            text: 'Please fill in all required fields.',
                            confirmButtonColor: '#3085d6',
                        });
                    }
                });
            });

    // Called from the branch filter markup.
    window.applyBranchFilter = applyBranchFilter;
})();
