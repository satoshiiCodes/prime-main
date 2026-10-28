/* ==========================================================================
   Prime City - New loan form behaviour
   Select2 lookups, live calculations and the AJAX submit.
   Everything talks to api/add-financing-endpoint.php; page data lives in
   #add-financing-data.
   ========================================================================== */

(function () {
    var pageConfigCache = null;

    /**
     * Page configuration from #add-financing-data.
     */
    function pageConfig() {
        if (pageConfigCache) { return pageConfigCache; }

        var node = document.getElementById('add-financing-data');

        if (!node) { pageConfigCache = {}; return pageConfigCache; }

        try {
            pageConfigCache = JSON.parse(node.textContent || '{}');
        } catch (error) {
            console.error('[add-financing] Could not parse page configuration.', error);
            pageConfigCache = {};
        }

        return pageConfigCache;
    }

    const pageCfg = pageConfig();
            // Store pre-selected values from PHP
            const preSelectedCustomerId = pageCfg.preSelectedCustomerId;
            const preSelectedCustomerName = pageCfg.preSelectedCustomerName;
            const preSelectedBranch = pageCfg.preSelectedBranch;
            const preSelectedCollectorId = pageCfg.preSelectedCollectorId;
            const preSelectedCollectorName = pageCfg.preSelectedCollectorName;

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

            // Function to format datetime as YYYY-MM-DD HH:MM:SS
            function formatDateTime(date) {
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');
                const hours = String(date.getHours()).padStart(2, '0');
                const minutes = String(date.getMinutes()).padStart(2, '0');
                const seconds = String(date.getSeconds()).padStart(2, '0');
                return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
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
                const amount = parseNumberWithCommas(document.getElementById('amount_financed').value) || 0;
                const interestRate = parseNumberWithCommas(document.getElementById('interest').value) || 0;
                const loanTerm = parseInt(document.getElementById('loan_term').value) || 0;
                
                if (isNaN(amount) || isNaN(interestRate) || isNaN(loanTerm)) {
                    document.getElementById('balance-error').textContent = 'Please enter valid numbers';
                    return;
                }
                
                document.getElementById('balance-error').textContent = '';
                
                // Calculate service fee
                const serviceFee = calculateServiceFee(amount);
                document.getElementById('service_fee').value = formatNumberWithCommas(serviceFee.toFixed(2));

                // Calculate customer net (amount - service fee)
                const customerNet = amount > 0 ? Math.max(0, amount - serviceFee) : 0;
                document.getElementById('customer_net').value = formatNumberWithCommas(customerNet.toFixed(2));
                
                // Calculate interest amount (in peso value)
                const interestAmount = amount * (interestRate / 100);
                document.getElementById('interest_amount').value = formatNumberWithCommas(interestAmount.toFixed(2));
                
                // Calculate total balance
                const totalBalance = amount + interestAmount;
                document.getElementById('balance').value = formatNumberWithCommas(totalBalance.toFixed(2));
                
                // Calculate daily payment
                const dailyPayment = totalBalance / loanTerm;
                document.getElementById('daily_payment').value = formatNumberWithCommas(dailyPayment.toFixed(2));
            }

            // Calculate end date based on loan term
            function calculateEndDateFromTerm() {
                const startDate = new Date(); // Use current date for calculation
                const loanTerm = parseInt(document.getElementById('loan_term').value);
                
                if (isNaN(startDate.getTime()) || isNaN(loanTerm)) {
                    return;
                }
                
                const endDate = calculateEndDate(startDate, loanTerm);
                document.getElementById('end_date_display').textContent = formatDateUS(endDate);
                document.getElementById('end_date').value = formatDate(endDate);
            }

            // Calculate service fee based on amount financed
            function calculateServiceFee(amount) {
                if (amount <= 0) return 0;
                if (amount <= 9000) {
                    return 200;
                } else {
                    // Calculate how many 1000 increments above 9000
                    const increments = Math.ceil((amount - 9000) / 1000);
                    return 200 + (30 * increments);
                }
            }

            // Set current date and time - FIXED: Use client's local time and store in correct format
            function setCurrentDateTime() {
                const now = new Date();
                
                // Display only the date using client's local time
                document.getElementById('start_date_display').textContent = formatDateUS(now);
                
                // Store the full datetime in MySQL format in the hidden field
                const localDateTime = formatDateTime(now);
                document.getElementById('start_date').value = localDateTime;
                
                console.log('Start date set to:', localDateTime); // For debugging
                
                calculateEndDateFromTerm();
            }

            // Check if customer has active financing
            async function checkCustomerActiveFinancing(customerId) {
                if (!customerId) return false;
                
                try {
                    const response = await fetch('api/add-financing-endpoint.php?action=check-active-financing', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `check_active_financing=true&customer_id=${customerId}`
                    });
                    
                    const data = await response.json();
                    return data.has_active_financing;
                } catch (error) {
                    console.error('Error checking active financing:', error);
                    return false;
                }
            }

            // Get current branch filter value
            function getCurrentBranch() {
                const branchFilter = document.getElementById('branch_filter');
                // If branch filter doesn't exist (for restricted users), return the user's branch
                if (!branchFilter) {
                    return pageCfg.userBranch;
                }
                return branchFilter.value;
            }

            // Check user type for JavaScript usage
            const isAdmin = pageCfg.isAdmin;
            const isAreaManager = pageCfg.isAreaManager;
            const isRestrictedUser = pageCfg.isRestrictedUser;
            const userBranch = pageCfg.userBranch;

            // Form submission handler
            async function handleFormSubmit(e) {
                e.preventDefault();
                const form = document.getElementById('financingForm');
                const errorElement = document.getElementById('form-error');
                errorElement.textContent = '';
                
                try {
                    // Validate form
                    const amount = parseNumberWithCommas(document.getElementById('amount_financed').value);
                    const interest = parseNumberWithCommas(document.getElementById('interest').value);
                    const dailyPayment = parseNumberWithCommas(document.getElementById('daily_payment').value);
                    const endDate = document.getElementById('end_date').value;
                    const startDate = document.getElementById('start_date').value;
                    const customerId = document.getElementById('customer_name').value;
                    const loanReleasedType = document.getElementById('loan_released_type').value;
                    
                    console.log('Form validation - Start Date:', startDate, 'End Date:', endDate); // For debugging
                    
                    if (!startDate) {
                        throw new Error('Start date calculation failed. Please refresh the page.');
                    }
                    
                    if (!endDate) {
                        throw new Error('End date calculation failed. Please refresh the page.');
                    }
                    
                    if (isNaN(amount) || amount <= 0) {
                        throw new Error('Please enter a valid amount financed');
                    }
                    
                    if (isNaN(interest) || interest <= 0) {
                        throw new Error('Please enter a valid interest rate');
                    }
                    
                    if (isNaN(dailyPayment) || dailyPayment <= 0) {
                        throw new Error('Please enter a valid daily payment');
                    }
                    
                    if (!loanReleasedType) {
                        throw new Error('Please select a loan released type');
                    }
                    
                    // Check if customer has active financing
                    const hasActiveFinancing = await checkCustomerActiveFinancing(customerId);
                    if (hasActiveFinancing) {
                        throw new Error('This customer already has an active loan. Please close the existing loan before creating a new one.');
                    }
                    
                    const formData = new FormData(form);
                    
                    // Show loading state
                    const submitBtn = form.querySelector('.btn-submit');
                    const originalText = submitBtn.textContent;
                    submitBtn.textContent = 'Processing...';
                    submitBtn.disabled = true;
                    
                    const response = await fetch('api/add-financing-endpoint.php?action=create', {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: formData
                    });
                    
                    // Restore button state
                    submitBtn.textContent = originalText;
                    submitBtn.disabled = false;
                    
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        // Show SweetAlert2 success notification
                        Swal.fire({
                            title: 'Success!',
                            html: `${data.message}<br><br>Financing ID: <strong>${data.financing_id}</strong><br>Savings Account ID: <strong>${data.savings_account_id}</strong><br>Branch: <strong>${data.customer_branch}</strong>`,
                            icon: 'success',
                            showCancelButton: true,
                            confirmButtonText: 'Print Promissory Note',
                            cancelButtonText: 'Close',
                            showDenyButton: true,
                            denyButtonText: 'Add Another Loan'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                // Open promissory note in new tab
                                window.open(`generate_promissory_note.php?financing_id=${data.financing_id}`, '_blank');
                                // Refresh the page
                                window.location.reload();
                            } else if (result.isDenied) {
                                // Reset form for another entry
                                form.reset();
                                document.getElementById('interest').value = '15';
                                document.getElementById('loan_released_type').value = '';
                                setCurrentDateTime();
                                calculateFinancialValues();
                                $('#customer_name').val(null).trigger('change');
                                $('#collector_name').val(null).trigger('change');
                            } else {
                                // User clicked "Close", refresh the page
                                window.location.reload();
                            }
                        });
                        
                    } else {
                        throw new Error(data.message || 'Error submitting form');
                    }
                } catch (error) {
                    console.error('Error:', error);
                    // Show SweetAlert2 error notification
                    Swal.fire({
                        title: 'Error!',
                        text: error.message,
                        icon: 'error',
                        confirmButtonText: 'OK'
                    });
                }
            }

            // Initialize the form when page loads
            $(document).ready(function() {
                // Function to initialize Select2 dropdowns with current branch
                function initializeSelect2() {
                    const currentBranch = getCurrentBranch();
                    
                    // Initialize customer dropdown
                    $('#customer_name').select2({
                        placeholder: "Type to search...",
                        allowClear: true,
                        width: '100%',
                        ajax: {
                            url: 'api/add-financing-endpoint.php?action=search',
                            dataType: 'json',
                            delay: 300,
                            data: function(params) {
                                return {
                                    search: true,
                                    table: 'customers',
                                    q: params.term,
                                    branch: currentBranch
                                };
                            },
                            processResults: function(data, params) {
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
                            },
                            cache: true
                        },
                        /* Two-line rows: name, then the area in muted text. */
                        templateResult: function (item) {
                            if (!item.id) {
                                return item.text;
                            }

                            var name = item.name || item.text || '';
                            var area = item.area || '';

                            var node = document.createElement('div');
                            node.className = 'pc-opt';

                            var title = document.createElement('span');
                            title.className = 'pc-opt__name';
                            title.textContent = name;
                            node.appendChild(title);

                            if (area) {
                                var meta = document.createElement('span');
                                meta.className = 'pc-opt__meta';
                                meta.textContent = area;
                                node.appendChild(meta);
                            }

                            return node;
                        },
                        templateSelection: function (item) {
                            if (!item.id) {
                                return item.text;
                            }

                            return item.name || item.text || '';
                        },
                        escapeMarkup: function (markup) {
                            return markup;
                        },
                        minimumInputLength: 0
                    });

                    // Initialize collector dropdown
                    $('#collector_name').select2({
                        placeholder: "Type to search...",
                        allowClear: true,
                        width: '100%',
                        ajax: {
                            url: 'api/add-financing-endpoint.php?action=search',
                            dataType: 'json',
                            delay: 300,
                            data: function(params) {
                                return {
                                    search: true,
                                    table: 'collectors',
                                    q: params.term,
                                    branch: currentBranch,
                                    customer_id: $('#customer_name').val()
                                };
                            },
                            processResults: function(data, params) {
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
                            },
                            cache: true
                        },
                        /* Two-line rows: name, then the area in muted text. */
                        templateResult: function (item) {
                            if (!item.id) {
                                return item.text;
                            }

                            var name = item.name || item.text || '';
                            var area = item.area || '';

                            var node = document.createElement('div');
                            node.className = 'pc-opt';

                            var title = document.createElement('span');
                            title.className = 'pc-opt__name';
                            title.textContent = name;
                            node.appendChild(title);

                            if (area) {
                                var meta = document.createElement('span');
                                meta.className = 'pc-opt__meta';
                                meta.textContent = area;
                                node.appendChild(meta);
                            }

                            return node;
                        },
                        templateSelection: function (item) {
                            if (!item.id) {
                                return item.text;
                            }

                            return item.name || item.text || '';
                        },
                        escapeMarkup: function (markup) {
                            return markup;
                        },
                        minimumInputLength: 0
                    });
                }

                // Initialize Select2 dropdowns
                initializeSelect2();

                // If we have pre-selected values, set them after Select2 initialization
                if (preSelectedCustomerId && preSelectedCustomerName) {
                    // Create option for pre-selected customer
                    const customerOption = new Option(preSelectedCustomerName, preSelectedCustomerId, true, true);
                    $('#customer_name').append(customerOption).trigger('change');
                    
                    // If we have a pre-selected collector, set it too
                    if (preSelectedCollectorId && preSelectedCollectorName) {
                        setTimeout(() => {
                            const collectorOption = new Option(preSelectedCollectorName, preSelectedCollectorId, true, true);
                            $('#collector_name').append(collectorOption).trigger('change');
                        }, 500);
                    }
                }

                // When customer is selected, reset and reload collector dropdown with area filter
                $('#customer_name').on('change', function() {
                    const customerId = $(this).val();
                    const currentBranch = getCurrentBranch();
                    
                    if (customerId) {
                        // Reset collector dropdown
                        $('#collector_name').val(null).trigger('change');
                        
                        // Reinitialize collector dropdown with customer_id parameter
                        $('#collector_name').select2({
                            placeholder: "Type to search...",
                            allowClear: true,
                            width: '100%',
                            ajax: {
                                url: 'api/add-financing-endpoint.php?action=search',
                                dataType: 'json',
                                delay: 300,
                                data: function(params) {
                                    return {
                                        search: true,
                                        table: 'collectors',
                                        q: params.term,
                                        customer_id: customerId,
                                        branch: currentBranch
                                    };
                                },
                                processResults: function(data, params) {
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
                                },
                                cache: true
                            },
                            /* Two-line rows: name, then the area in muted text. */
                        templateResult: function (item) {
                            if (!item.id) {
                                return item.text;
                            }

                            var name = item.name || item.text || '';
                            var area = item.area || '';

                            var node = document.createElement('div');
                            node.className = 'pc-opt';

                            var title = document.createElement('span');
                            title.className = 'pc-opt__name';
                            title.textContent = name;
                            node.appendChild(title);

                            if (area) {
                                var meta = document.createElement('span');
                                meta.className = 'pc-opt__meta';
                                meta.textContent = area;
                                node.appendChild(meta);
                            }

                            return node;
                        },
                        templateSelection: function (item) {
                            if (!item.id) {
                                return item.text;
                            }

                            return item.name || item.text || '';
                        },
                        escapeMarkup: function (markup) {
                            return markup;
                        },
                        minimumInputLength: 0
                        });
                    }
                });

                // When branch filter changes, reset dropdowns and reinitialize (only for users with branch filter)
                if (isAdmin || isAreaManager) {
                    $('#branch_filter').on('change', function() {
                        // Reset both dropdowns
                        $('#customer_name').val(null).trigger('change');
                        $('#collector_name').val(null).trigger('change');
                        
                        // Reinitialize Select2 dropdowns with new branch
                        initializeSelect2();
                    });
                }

                // Format amount fields with commas on blur
                $('#amount_financed').on('blur', function() {
                    const value = parseNumberWithCommas($(this).val());
                    if (!isNaN(value)) {
                        $(this).val(formatNumberWithCommas(value));
                    }
                });

                // Set up other event listeners
                document.getElementById('amount_financed').addEventListener('input', calculateFinancialValues);
                document.getElementById('interest').addEventListener('input', calculateFinancialValues);
                document.getElementById('loan_term').addEventListener('change', function() {
                    calculateFinancialValues();
                    calculateEndDateFromTerm();
                });
                document.getElementById('financingForm').addEventListener('submit', handleFormSubmit);
                
                // Initialize values - FIXED: Use client's local time
                setCurrentDateTime();
                calculateFinancialValues();
            });
})();
