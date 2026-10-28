/* ==========================================================================
   Prime City - New employee loan behaviour
   Branch-scoped employee lookups, live calculations and the AJAX submit.
   Everything talks to api/add-collector-loan-endpoint.php; page data lives
   in #add-collector-loan-data.
   ========================================================================== */

(function () {
    var pageConfigCache = null;

    /**
     * Page configuration from #add-collector-loan-data.
     */
    function pageConfig() {
        if (pageConfigCache) { return pageConfigCache; }

        var node = document.getElementById('add-collector-loan-data');

        if (!node) { pageConfigCache = {}; return pageConfigCache; }

        try {
            pageConfigCache = JSON.parse(node.textContent || '{}');
        } catch (error) {
            console.error('[add-collector-loan] Could not parse page configuration.', error);
            pageConfigCache = {};
        }

        return pageConfigCache;
    }

    const pageCfg = pageConfig();
        // Function to format number with commas
        function formatNumberWithCommas(number) {
            return number.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        }

        // Function to parse number with commas
        function parseNumberWithCommas(numberString) {
            return parseFloat(numberString.replace(/,/g, ''));
        }

        // Function to format date as YYYY-MM-DD
        function formatDate(date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        }

        // Function to format date as MM/DD/YYYY
        function formatDateUS(date) {
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            const year = date.getFullYear();
            return `${month}/${day}/${year}`;
        }

        // Function to calculate end date excluding Sundays
        function calculateEndDate(startDate, daysToAdd) {
            let endDate = new Date(startDate);
            let daysAdded = 0;
            
            while (daysAdded < daysToAdd) {
                endDate.setDate(endDate.getDate() + 1);
                // Skip Sundays (day 0)
                if (endDate.getDay() !== 0) {
                    daysAdded++;
                }
            }
            return endDate;
        }

        // Calculate all financial values
        function calculateFinancialValues() {
            const amount = parseNumberWithCommas(document.getElementById('amount_loaned').value) || 0;
            const loanTerm = parseInt(document.getElementById('loan_term').value) || 0;
            
            if (isNaN(amount) || isNaN(loanTerm)) {
                document.getElementById('balance-error').textContent = 'Please enter valid numbers';
                return;
            }
            
            document.getElementById('balance-error').textContent = '';
            
            // Calculate total balance (same as amount loaned for collectors)
            document.getElementById('balance').value = formatNumberWithCommas(amount.toFixed(2));
            
            // Calculate period payment (every 15/30 days)
            const periodPayment = amount / loanTerm;
            document.getElementById('daily_payment').value = formatNumberWithCommas(periodPayment.toFixed(2));
        }

        // Calculate end date based on loan term
        function calculateEndDateFromTerm() {
            const startDate = new Date(document.getElementById('start_date').value);
            const loanTerm = parseInt(document.getElementById('loan_term').value);
            
            if (isNaN(startDate.getTime()) || isNaN(loanTerm)) {
                return;
            }
            
            // Calculate total days (loan term * 15 days per period)
            const totalDays = loanTerm * 15;
            const endDate = calculateEndDate(startDate, totalDays);
            document.getElementById('end_date_display').textContent = formatDateUS(endDate);
            document.getElementById('end_date').value = formatDate(endDate);
        }

        // Set current date and time
        function setCurrentDateTime() {
            const now = new Date();
            document.getElementById('start_date_display').textContent = formatDateUS(now);
            document.getElementById('start_date').value = formatDate(now);
            calculateEndDateFromTerm();
        }

        /**
         * Split an employee label into its name and account type.
         * AJAX results carry account_type; local <option>s carry
         * "Name (Type)", so the suffix is parsed when needed.
         */
        function employeeLabel(item) {
            var text = (item && item.text ? String(item.text) : '').trim();
            var match = /^(.*)\s\(([^()]*)\)$/.exec(text);
            var name = match ? match[1].trim() : text;
            var role = (item && item.account_type) ? String(item.account_type) : (match ? match[2].trim() : '');

            return { name: name, role: role };
        }

        // Function to update collector dropdown state
        function updateCollectorDropdownState(branch) {
            const collectorSelect = $('#collector_name');
            const collectorNote = $('#collector_note');
            
            if (!branch || branch === '') {
                // No branch selected - disable and show message
                collectorSelect.prop('disabled', true);
                collectorSelect.empty().append('<option value="">Select Branch First</option>');
                collectorSelect.val(null).trigger('change');
                collectorNote.text('Please select a branch first');
            } else {
                // Branch selected - enable and load collectors
                collectorSelect.prop('disabled', false);
                collectorNote.text('Type to search for active employees...');
                loadCollectorsByBranch(branch);
            }
        }

        // Function to load collectors by branch from all tables (only active accounts)
        function loadCollectorsByBranch(branch) {
            const collectorSelect = $('#collector_name');
            const loadingSpinner = $('#collector_loading');
            
            if (!branch) {
                updateCollectorDropdownState('');
                return;
            }
            
            loadingSpinner.show();
            collectorSelect.prop('disabled', true);
            
            $.ajax({
                url: pageCfg.endpoint + '?action=collectors-by-branch',
                type: 'GET',
                data: {
                    
                    branch: branch
                },
                dataType: 'json',
                success: function(data) {
                    collectorSelect.html('<option value="">Select Employee</option>');
                    
                    if (data.results && data.results.length > 0) {
                        $.each(data.results, function(index, collector) {
                            collectorSelect.append(
                                $('<option>', {
                                    value: collector.id,
                                    text: collector.text,
                                    'data-type': collector.table_type
                                })
                            );
                        });
                        collectorSelect.prop('disabled', false);
                    } else {
                        collectorSelect.append(
                            $('<option>', {
                                value: '',
                                text: 'No active employees found for this branch'
                            })
                        );
                    }
                    
                    loadingSpinner.hide();
                },
                error: function(xhr, status, error) {
                    console.error('Error loading employees:', error);
                    collectorSelect.html('<option value="">Error loading employees</option>');
                    loadingSpinner.hide();
                    
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Failed to load employees for this branch',
                        confirmButtonColor: '#0d6efd'
                    });
                }
            });
        }

        // Form submission handler
        async function handleFormSubmit(e) {
            e.preventDefault();
            const form = document.getElementById('collectorLoanForm');
            const errorElement = document.getElementById('form-error');
            errorElement.textContent = '';
            
            try {
                // Validate form
                const amount = parseNumberWithCommas(document.getElementById('amount_loaned').value);
                const periodPayment = parseNumberWithCommas(document.getElementById('daily_payment').value);
                const endDate = document.getElementById('end_date').value;
                const collectorId = document.getElementById('collector_name').value;
                const branch = document.getElementById('branch').value;
                
                if (!endDate) {
                    throw new Error('End date calculation failed. Please refresh the page.');
                }
                
                if (isNaN(amount) || amount <= 0) {
                    throw new Error('Please enter a valid amount loaned');
                }
                
                if (isNaN(periodPayment) || periodPayment <= 0) {
                    throw new Error('Please enter a valid 15/30 payment');
                }
                
                if (!collectorId) {
                    throw new Error('Please select an employee');
                }
                
                if (!branch) {
                    throw new Error('Please select a branch');
                }
                
                // Don't parse the collector ID here - let the PHP handle it
                // Just send the full ID string (e.g., "collector_accounts_123")
                
                const formData = new FormData(form);
                const response = await fetch(pageCfg.endpoint + '?action=create', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Show beautiful toast notification
                    const toastMessage = document.getElementById('toastMessage');
                    toastMessage.innerHTML = `${data.message}<br>Loan ID: <strong>${data.loan_id}</strong>`;
                    
                    const toast = new bootstrap.Toast(document.getElementById('successToast'));
                    toast.show();
                    
                    // Show SweetAlert success message
                    await Swal.fire({
                        icon: 'success',
                        title: 'Success',
                        html: `${data.message}<br><br>Loan ID: <strong>${data.loan_id}</strong>`,
                        confirmButtonColor: '#0d6efd',
                        timer: 3000,
                        timerProgressBar: true
                    });
                    
                    form.reset();
                    setCurrentDateTime();
                    calculateFinancialValues();
                    
                    // Reset Select2 dropdown and update state
                    const currentBranch = $('#branch').val();
                    updateCollectorDropdownState(currentBranch);
                    
                } else {
                    throw new Error(data.message || 'Error submitting form');
                }
            } catch (error) {
                console.error('Error:', error);
                errorElement.textContent = error.message;
                
                // Show SweetAlert error message
                await Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: error.message,
                    confirmButtonColor: '#0d6efd'
                });
            }
        }

        // Initialize the form when page loads
        $(document).ready(function() {
            // Initialize Select2 for dropdown with branch-aware search across all tables (only active accounts)
            $('#collector_name').select2({
                placeholder: "Select Branch First",
                allowClear: true,
                width: '100%',
                dropdownAutoWidth: true,
                minimumInputLength: 0, // Allow empty search to show all options
                /* two-line rows: name on line 1, account type muted on line 2 */
                templateResult: function (item) {
                    if (!item.id || !item.text) {
                        return item.text;
                    }

                    var parts = employeeLabel(item);
                    var row = document.createElement('div');
                    row.className = 'pc-opt';

                    var name = document.createElement('span');
                    name.className = 'pc-opt__name';
                    name.textContent = parts.name;
                    row.appendChild(name);

                    if (parts.role) {
                        var meta = document.createElement('span');
                        meta.className = 'pc-opt__meta';
                        meta.textContent = parts.role;
                        row.appendChild(meta);
                    }

                    return row;
                },
                templateSelection: function (item) {
                    if (!item.id || !item.text) {
                        return item.text;
                    }

                    return employeeLabel(item).name;
                },
                escapeMarkup: function (markup) {
                    return markup;
                },
                ajax: {
                    url: pageCfg.endpoint + '?action=search',
                    dataType: 'json',
                    delay: 300,
                    data: function(params) {
                        return {
                            search: true,
                            q: params.term || '', // Send empty string if no search term
                            branch: $('#branch').val() // Pass selected branch to search
                        };
                    },
                    processResults: function(data, params) {
                        // If no search term and no results, return empty results
                        if (!params.term && (!data || !data.results || data.results.length === 0)) {
                            return { results: [] };
                        }
                        
                        if (!data || !data.results) {
                            console.error('Invalid response format:', data);
                            return { results: [] };
                        }
                        
                        return {
                            results: data.results,
                            pagination: {
                                more: false
                            }
                        };
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX error:', status, error);
                        // Don't show error for empty searches
                        if (xhr.status !== 0) {
                            Swal.fire({
                                icon: 'error',
                                title: 'Search Error',
                                text: 'An error occurred while searching for employees',
                                confirmButtonColor: '#0d6efd'
                            });
                        }
                    },
                    cache: true
                }
            });

            // Format amount fields with commas on blur
            $('#amount_loaned').on('blur', function() {
                const value = parseNumberWithCommas($(this).val());
                if (!isNaN(value)) {
                    $(this).val(formatNumberWithCommas(value));
                }
            });

            // Branch change event listener
            $('#branch').on('change', function() {
                const branch = $(this).val();
                updateCollectorDropdownState(branch);
            });

            // Set up other event listeners
            document.getElementById('amount_loaned').addEventListener('input', calculateFinancialValues);
            document.getElementById('loan_term').addEventListener('change', function() {
                calculateFinancialValues();
                calculateEndDateFromTerm();
            });
            document.getElementById('collectorLoanForm').addEventListener('submit', handleFormSubmit);
            
            // Initialize values
            setCurrentDateTime();
            calculateFinancialValues();
            
            // Set initial state based on preselected branch
            // Set initial state based on preselected branch
            if (pageCfg.preselectedBranch) {
                // If there's a preselected branch, load collectors for it
                updateCollectorDropdownState(pageCfg.preselectedBranch);
            } else {
                // No preselected branch, keep collector disabled
                updateCollectorDropdownState('');
            }
        });

    // Functions the markup calls from inline handlers.
    window.formatNumberWithCommas = formatNumberWithCommas;
    window.parseNumberWithCommas = parseNumberWithCommas;
    window.updateCollectorDropdownState = updateCollectorDropdownState;
    window.calculateFinancialValues = calculateFinancialValues;
})();
