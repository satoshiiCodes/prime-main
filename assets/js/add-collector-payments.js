/* ==========================================================================
   Prime City - Employee loan payments behaviour
   Payment rows, employee/loan lookups and the AJAX submit.
   Everything talks to api/add-collector-payments-endpoint.php.
   ========================================================================== */

(function () {
    const PAYMENTS_ENDPOINT = 'api/add-collector-payments-endpoint.php';

        // Store the original system time when page loads
        const originalSystemTime = new Date();
        
        // Function to format number with commas
        function formatNumberWithCommas(number) {
            return number.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        }

        // Function to parse number with commas
        function parseNumberWithCommas(numberString) {
            return parseFloat(numberString.replace(/,/g, ''));
        }

        // Function to format currency
        function formatCurrency(amount) {
            return '₱' + formatNumberWithCommas(amount.toFixed(2));
        }

        // Store all payment rows data
        const paymentRowsData = {};
        let paymentRowCounter = 1;   /* rows are numbered from 1 */

        // Initialize Select2 for a specific element
        /**
         * Split "Name (Type)" into its parts, preferring the cached
         * name / account_type when present.
         */
        function employeeParts(item) {
            var text = (item && item.text ? String(item.text) : '').trim();
            var el = item && item.element ? item.element : null;
            var name = el ? (el.getAttribute('data-name') || '') : '';
            var role = el ? (el.getAttribute('data-type') || '') : '';
            var match = /^(.*)\s\(([^()]*)\)$/.exec(text);

            if (!name) {
                name = match ? match[1].trim() : text;
            }

            if (!role && match) {
                role = match[2].trim();
            }

            return { name: name, role: role };
        }

        function initSelect2(element) {
            $(element).select2({
                placeholder: "Type to search...",
                allowClear: true,
                width: '100%',
                templateResult: function (item) {
                    if (!item.id || !item.text) {
                        return item.text;
                    }

                    var parts = employeeParts(item);
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

                    return employeeParts(item).name;
                },
                escapeMarkup: function (markup) {
                    return markup;
                }
            });
        }

        // Initialize Select2 for loan selects (without search)
        function initLoanSelect2(element) {
            $(element).select2({
                minimumResultsForSearch: -1, // This disables the search box
                width: '100%'
            });
        }

        // Get currently selected collector IDs across all rows
        function getSelectedCollectorIds() {
            const selectedIds = [];
            Object.keys(paymentRowsData).forEach(rowIndex => {
                const collectorSelect = $(`#collector_name_${rowIndex}`);
                const collectorId = collectorSelect.val();
                if (collectorId && collectorId !== '') {
                    selectedIds.push(collectorId);
                }
            });
            return selectedIds;
        }

        // Update collector dropdowns to hide already selected collectors
        function updateCollectorDropdowns() {
            const selectedCollectorIds = getSelectedCollectorIds();
            
            Object.keys(paymentRowsData).forEach(rowIndex => {
                const $collectorSelect = $(`#collector_name_${rowIndex}`);
                const currentSelectedId = $collectorSelect.val();
                
                // Store current selection
                const currentSelection = $collectorSelect.val();
                
                // Remove all options except the placeholder
                $collectorSelect.find('option:not(:first)').remove();
                
                // Get all available collectors from the original data
                const availableCollectors = getAllAvailableCollectors();
                
                if (availableCollectors && availableCollectors.length > 0) {
                    // Add available collectors, hiding already selected ones
                    availableCollectors.forEach(collector => {
                        // Only show collector if it's not selected in another row OR it's the current selection for this row
                        if (!selectedCollectorIds.includes(collector.id.toString()) || collector.id.toString() === currentSelectedId) {
                            $collectorSelect.append($('<option>', {
                                value: collector.id,
                                text: collector.text,
                                'data-name': collector.name || '',
                                'data-type': collector.account_type || '',
                                selected: collector.id.toString() === currentSelectedId
                            }));
                        }
                    });
                }
                
                // If no collectors available after filtering, show message
                if ($collectorSelect.find('option').length === 1) {
                    $collectorSelect.append('<option value="">No available collectors</option>');
                }
                
                // Refresh Select2 to show changes
                if ($collectorSelect.hasClass('select2-hidden-accessible')) {
                    $collectorSelect.trigger('change.select2');
                }
            });
        }

        // Get all available collectors from the server (cached)
        let availableCollectorsCache = null;
        function getAllAvailableCollectors() {
            return availableCollectorsCache;
        }

        // Check for duplicate collectors
        function checkForDuplicateCollectors() {
            const selectedCollectors = {};
            const duplicates = [];
            
            // Collect all selected collector IDs
            Object.keys(paymentRowsData).forEach(rowIndex => {
                const collectorSelect = $(`#collector_name_${rowIndex}`);
                const collectorId = collectorSelect.val();
                const collectorName = collectorSelect.find('option:selected').text();
                
                if (collectorId && collectorId !== '') {
                    if (selectedCollectors[collectorId]) {
                        duplicates.push({
                            collectorId: collectorId,
                            collectorName: collectorName,
                            rowIndex: rowIndex
                        });
                    } else {
                        selectedCollectors[collectorId] = {
                            name: collectorName,
                            rowIndex: rowIndex
                        };
                    }
                }
            });
            
            // Show/hide duplicate warning
            const warningElement = $('#duplicate_collector_warning');
            const warningText = $('#duplicate_warning_text');
            
            if (duplicates.length > 0) {
                const duplicateNames = [...new Set(duplicates.map(d => d.collectorName))];
                warningText.text('Warning: Duplicate collector detected - ' + duplicateNames.join(', ') + '. Please ensure each collector appears only once.');
                warningElement.show();
                
                // Highlight duplicate rows
                Object.keys(paymentRowsData).forEach(rowIndex => {
                    const collectorSelect = $(`#collector_name_${rowIndex}`);
                    const collectorId = collectorSelect.val();
                    
                    if (duplicates.some(d => d.collectorId === collectorId)) {
                        collectorSelect.addClass('has-error');
                    } else {
                        collectorSelect.removeClass('has-error');
                    }
                });
                
                return true;
            } else {
                warningElement.hide();
                
                // Remove error highlighting
                Object.keys(paymentRowsData).forEach(rowIndex => {
                    $(`#collector_name_${rowIndex}`).removeClass('has-error');
                });
                
                return false;
            }
        }

        // Load collectors for selected branch in a specific row
        function loadCollectorsByBranch(rowIndex, branch) {
            const $collectorSelect = $(`#collector_name_${rowIndex}`);
            const $loanSelect = $(`#loan_id_${rowIndex}`);
            const $loanBalanceDisplay = $(`#loan_balance_${rowIndex}`);
            const $loanSelectGroup = $(`#loan_select_group_${rowIndex}`);
            const $noLoansMessage = $(`#no_loans_message_${rowIndex}`);
            
            /* no branch chosen: list every collector */
            branch = branch || '';

            $.ajax({
                url: PAYMENTS_ENDPOINT + '?action=collectors-by-branch',
                data: { branch: branch },
                dataType: 'json',
                success: function(response) {
                    if (response.error) {
                        console.error(response.error);
                        $collectorSelect.empty().append('<option value="">Error loading collectors</option>');
                        return;
                    }
                    
                    // Cache the available collectors
                    availableCollectorsCache = response.collectors || [];
                    
                    $collectorSelect.empty().append('<option value="">Select Collector</option>');
                    
                    if (availableCollectorsCache && availableCollectorsCache.length > 0) {
                        // We'll populate the dropdown in updateCollectorDropdowns()
                    } else {
                        $collectorSelect.append('<option value="">' + (branch ? 'No collectors found for this branch' : 'No collectors found') + '</option>');
                    }
                    
                    // Clear loan selection when branch changes
                    $loanSelect.empty().append('<option value="">Select Collector First</option>');
                    $loanBalanceDisplay.text('');
                    $loanSelectGroup.hide();
                    if ($noLoansMessage.length) $noLoansMessage.hide();
                    updatePaymentRowData(rowIndex, 'balance', 0);
                    updatePaymentRowData(rowIndex, 'daily_payment', 0);
                    
                    // Reinitialize Select2
                    initSelect2($collectorSelect);
                    initLoanSelect2($loanSelect);
                    
                    // Update dropdowns to hide already selected collectors
                    updateCollectorDropdowns();
                    
                    updateSummary();
                    checkForDuplicateCollectors();
                },
                error: function(xhr, status, error) {
                    console.error('Error loading collectors:', error);
                    $collectorSelect.empty().append('<option value="">Error loading collectors</option>');
                    $loanSelect.empty().append('<option value="">Select Collector First</option>');
                    $loanBalanceDisplay.text('');
                    $loanSelectGroup.hide();
                    if ($noLoansMessage.length) $noLoansMessage.hide();
                    updatePaymentRowData(rowIndex, 'balance', 0);
                    updatePaymentRowData(rowIndex, 'daily_payment', 0);
                    updateSummary();
                    updateCollectorDropdowns();
                    checkForDuplicateCollectors();
                }
            });
        }

        // Load loans for selected collector in a specific row
        function loadCollectorLoans(rowIndex, collectorId) {
            const $loanSelect = $(`#loan_id_${rowIndex}`);
            const $loanBalanceDisplay = $(`#loan_balance_${rowIndex}`);
            const $loanSelectGroup = $(`#loan_select_group_${rowIndex}`);
            const $noLoansMessage = $(`#no_loans_message_${rowIndex}`);
            
            if (!collectorId || collectorId <= 0) {
                $loanSelect.empty().append('<option value="">Select Collector First</option>');
                $loanBalanceDisplay.text('');
                $loanSelectGroup.hide();
                if ($noLoansMessage.length) $noLoansMessage.hide();
                updatePaymentRowData(rowIndex, 'balance', 0);
                updatePaymentRowData(rowIndex, 'daily_payment', 0);
                updateSummary();
                updateCollectorDropdowns();
                checkForDuplicateCollectors();
                return;
            }
            
            $.ajax({
                url: PAYMENTS_ENDPOINT + '?action=loans',
                data: { collector_id: collectorId },
                dataType: 'json',
                success: function(response) {
                    if (response.error) {
                        console.error(response.error);
                        $loanSelect.empty().append('<option value="">Error loading loans</option>');
                        $loanBalanceDisplay.text('');
                        $loanSelectGroup.hide();
                        if ($noLoansMessage.length) $noLoansMessage.hide();
                        return;
                    }
                    
                    $loanSelect.empty();
                    
                    if (response.loans && response.loans.length > 0) {
                        // Hide any existing "no loans" message
                        if ($noLoansMessage.length) $noLoansMessage.hide();
                        
                        // Show loan select only if there are 2 or more loans
                        if (response.loans.length >= 2) {
                            $loanSelectGroup.show();
                            $loanSelect.append('<option value="">Select Loan</option>');
                            
                            $.each(response.loans, function(index, loan) {
                                $loanSelect.append($('<option>', {
                                    value: loan.id,
                                    text: loan.text,
                                    'data-amount-loaned': loan.amount_loaned,
                                    'data-balance': loan.balance,
                                    'data-daily-payment': loan.daily_payment,
                                    'data-start-date': loan.start_date,
                                    'data-end-date': loan.end_date
                                }));
                            });
                        } else {
                            // For single loan, auto-select it but keep the select hidden
                            $loanSelectGroup.hide();
                            const loan = response.loans[0];
                            $loanSelect.empty().append($('<option>', {
                                value: loan.id,
                                text: loan.text,
                                selected: true,
                                'data-balance': loan.balance
                            }));
                            
                            // Update loan balance display
                            $loanBalanceDisplay.text('Current Balance: ₱' + formatNumberWithCommas(loan.balance));
                            
                            // Store the raw balance and daily payment values
                            updatePaymentRowData(rowIndex, 'balance', loan.balance);
                            updatePaymentRowData(rowIndex, 'daily_payment', loan.daily_payment);
                        }
                    } else {
                        // No active loans found - show message and hide loan select
                        $loanSelect.append('<option value="">No active loans found</option>');
                        $loanBalanceDisplay.text('');
                        $loanSelectGroup.hide();
                        
                        // Show "no loans" message
                        if (!$noLoansMessage.length) {
                            // Create the message if it doesn't exist
                            const noLoansMessage = $('#noLoansMessageTemplate').html().replace(/{index}/g, rowIndex);
                            $(`[data-index="${rowIndex}"]`).append(noLoansMessage);
                        } else {
                            $noLoansMessage.show();
                        }
                        
                        updatePaymentRowData(rowIndex, 'balance', 0);
                        updatePaymentRowData(rowIndex, 'daily_payment', 0);
                    }
                    
                    // Reinitialize Select2
                    initLoanSelect2($loanSelect);
                    updateSummary();
                    updateCollectorDropdowns();
                    checkForDuplicateCollectors();
                },
                error: function(xhr, status, error) {
                    console.error('Error loading loans:', error);
                    $loanSelect.empty().append('<option value="">Error loading loans</option>');
                    $loanBalanceDisplay.text('');
                    $loanSelectGroup.hide();
                    if ($noLoansMessage.length) $noLoansMessage.hide();
                    updatePaymentRowData(rowIndex, 'balance', 0);
                    updatePaymentRowData(rowIndex, 'daily_payment', 0);
                    updateSummary();
                    updateCollectorDropdowns();
                    checkForDuplicateCollectors();
                }
            });
        }

        // Validate payment amount in a specific row
        function validatePaymentAmount(rowIndex, amount) {
            // Parse the amount (which might have commas)
            const amountNum = parseNumberWithCommas(amount);
            
            if (isNaN(amountNum)) {
                return { valid: false, message: 'Please enter a valid number' };
            }
            
            if (amountNum <= 0) {
                return { valid: false, message: 'Payment must be greater than 0' };
            }
            
            const rowData = paymentRowsData[rowIndex];
            if (rowData && rowData.balance > 0 && amountNum > rowData.balance) {
                return { 
                    valid: false, 
                    message: 'Payment cannot exceed current balance of ₱' + formatNumberWithCommas(rowData.balance.toFixed(2))
                };
            }
            
            return { valid: true };
        }

        // Set current date and time with seconds
        function setCurrentDateTime() {
            const now = new Date();
            // Format for datetime-local input (YYYY-MM-DDTHH:MM:SS)
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            
            const datetimeString = `${year}-${month}-${day}T${hours}:${minutes}:${seconds}`;
            $('#payment_date').val(datetimeString);
        }

        /**
         * Renumber the row badges from the current DOM order, so the
         * numbers stay sequential after a row is removed.
         */
        function renumberPaymentRows() {
            $('#paymentRowsContainer .payment-row').each(function(index) {
                $(this).find('.payment-row-title').text('Payment #' + (index + 1));
            });
        }

        // Add a new payment row
        function addPaymentRow() {
            const rowIndex = paymentRowCounter++;
            const template = $('#paymentRowTemplate').html().replace(/{index}/g, rowIndex);
            
            $('#paymentRowsContainer').append(template);
            
            // Initialize Select2 for the new selects
            initSelect2(`#collector_name_${rowIndex}`);
            initLoanSelect2(`#loan_id_${rowIndex}`);
            
            // Hide loan select by default (will be shown if collector has multiple loans)
            $(`#loan_select_group_${rowIndex}`).hide();
            
            // Initialize payment row data
            paymentRowsData[rowIndex] = {
                balance: 0,
                daily_payment: 0,
                amount: 0
            };

            renumberPaymentRows();
            
            // Set up event listeners for the new row
            $(`#collector_name_${rowIndex}`).on('change', function() {
                const collectorId = $(this).val();
                loadCollectorLoans(rowIndex, collectorId);
                updatePaymentRowData(rowIndex, 'balance', 0);
                updatePaymentRowData(rowIndex, 'daily_payment', 0);
                updateSummary();
                updateCollectorDropdowns();
                checkForDuplicateCollectors();
            });
            
            $(`#loan_id_${rowIndex}`).on('change', function() {
                const loanId = $(this).val();
                const selectedOption = $(this).find('option:selected');
                const $loanBalanceDisplay = $(`#loan_balance_${rowIndex}`);
                const $noLoansMessage = $(`#no_loans_message_${rowIndex}`);
                
                if (loanId && loanId > 0) {
                    // Hide "no loans" message if shown
                    if ($noLoansMessage.length) $noLoansMessage.hide();
                    
                    const balance = parseFloat(selectedOption.data('balance'));
                    const dailyPayment = parseFloat(selectedOption.data('daily-payment'));
                    
                    // Update loan balance display
                    $loanBalanceDisplay.text('Current Balance: ₱' + formatNumberWithCommas(balance));
                    
                    // Store the raw balance and daily payment values
                    updatePaymentRowData(rowIndex, 'balance', balance);
                    updatePaymentRowData(rowIndex, 'daily_payment', dailyPayment);
                    
                    // Highlight if balance is zero
                    if (balance <= 0) {
                        $(`#payment_amount_${rowIndex}`).addClass('has-error');
                        $(`#amount-error_${rowIndex}`).text('This loan has no remaining balance');
                    } else {
                        $(`#payment_amount_${rowIndex}`).removeClass('has-error');
                        $(`#amount-error_${rowIndex}`).text('');
                    }
                } else {
                    $loanBalanceDisplay.text('');
                    updatePaymentRowData(rowIndex, 'balance', 0);
                    updatePaymentRowData(rowIndex, 'daily_payment', 0);
                }
                
                updateSummary();
                updateCollectorDropdowns();
                checkForDuplicateCollectors();
            });
            
            $(`#payment_amount_${rowIndex}`).on('input', function() {
                const paymentAmount = $(this).val();
                
                if (paymentAmount) {
                    const validation = validatePaymentAmount(rowIndex, paymentAmount);
                    const $errorElement = $(`#amount-error_${rowIndex}`);
                    
                    if (!validation.valid) {
                        $errorElement.text(validation.message);
                        $(this).addClass('has-error');
                    } else {
                        $errorElement.text('');
                        $(this).removeClass('has-error');
                    }
                }
                
                updateSummary();
                updateCollectorDropdowns();
                checkForDuplicateCollectors();
            });
            
            // Format amount field with commas on blur
            $(`#payment_amount_${rowIndex}`).on('blur', function() {
                const value = parseNumberWithCommas($(this).val());
                if (!isNaN(value)) {
                    $(this).val(formatNumberWithCommas(value));
                    updatePaymentRowData(rowIndex, 'amount', value);
                    updateSummary();
                    updateCollectorDropdowns();
                    checkForDuplicateCollectors();
                }
            });
            
            // Load collectors based on current branch selection
            const currentBranch = $('#branch').val();
            if (currentBranch) {
                loadCollectorsByBranch(rowIndex, currentBranch);
            }
            
            // Update summary
            updateSummary();
            updateCollectorDropdowns();
            checkForDuplicateCollectors();
            
            return rowIndex;
        }

        // Remove a payment row
        function removePaymentRow(rowIndex) {
            $(`[data-index="${rowIndex}"]`).remove();
            delete paymentRowsData[rowIndex];
            updateSummary();
            updateCollectorDropdowns();
            checkForDuplicateCollectors();
        }

        // Update payment row data
        function updatePaymentRowData(rowIndex, key, value) {
            if (paymentRowsData[rowIndex]) {
                paymentRowsData[rowIndex][key] = value;
                
                // If updating amount, also update the value in the form
                if (key === 'amount') {
                    $(`#payment_amount_${rowIndex}`).val(formatNumberWithCommas(value));
                }
            }
        }

        // Update the summary section
        function updateSummary() {
            let totalPayments = 0;
            let totalAmount = 0;
            
            // Count active payment rows and sum amounts
            Object.keys(paymentRowsData).forEach(rowIndex => {
                const rowData = paymentRowsData[rowIndex];
                if (rowData) {
                    totalPayments++;
                    
                    // Get the amount from the input field (might be formatted with commas)
                    const amountInput = $(`#payment_amount_${rowIndex}`).val();
                    if (amountInput) {
                        const amount = parseNumberWithCommas(amountInput);
                        if (!isNaN(amount)) {
                            totalAmount += amount;
                            updatePaymentRowData(rowIndex, 'amount', amount);
                        }
                    }
                }
            });
            
            $('#totalPaymentsCount').text(totalPayments);
            $('#totalPaymentsAmount').text(formatCurrency(totalAmount));
        }

        // Update all payment rows when branch changes
        function updateAllPaymentRowsForBranch(branch) {
            Object.keys(paymentRowsData).forEach(rowIndex => {
                loadCollectorsByBranch(rowIndex, branch);
            });
        }

        // Form submission handler
        async function handleFormSubmit(e) {
            e.preventDefault();
            const form = document.getElementById('collectorPaymentForm');
            const errorElement = document.getElementById('form-error');
            errorElement.textContent = '';
            
            try {
                // Validate form
                const paymentDate = $('#payment_date').val();
                const branch = $('#branch').val();
                const paymentRows = Object.keys(paymentRowsData).length;
                
                if (!paymentDate) {
                    throw new Error('Please select a valid payment date');
                }
                
                if (!branch) {
                    throw new Error('Please select a branch');
                }
                
                if (paymentRows === 0) {
                    throw new Error('Please add at least one payment');
                }
                
                // Check for duplicate collectors before submission
                if (checkForDuplicateCollectors()) {
                    throw new Error('Please remove duplicate collectors before submitting');
                }
                
                // Validate each payment row
                Object.keys(paymentRowsData).forEach(rowIndex => {
                    const collectorId = $(`#collector_name_${rowIndex}`).val();
                    const loanId = $(`#loan_id_${rowIndex}`).val();
                    const paymentAmount = $(`#payment_amount_${rowIndex}`).val();
                    
                    if (!collectorId || collectorId <= 0) {
                        throw new Error(`Please select a valid collector for Payment #${parseInt(rowIndex)+1}`);
                    }
                    
                    if (!loanId || loanId <= 0) {
                        throw new Error(`Please select a valid loan for Payment #${parseInt(rowIndex)+1}`);
                    }
                    
                    // Check if collector has active loans
                    const $noLoansMessage = $(`#no_loans_message_${rowIndex}`);
                    if ($noLoansMessage.length && $noLoansMessage.is(':visible')) {
                        throw new Error(`Collector in Payment #${parseInt(rowIndex)+1} has no active loans with remaining balance`);
                    }
                    
                    // Validate payment amount
                    const validation = validatePaymentAmount(rowIndex, paymentAmount);
                    if (!validation.valid) {
                        throw new Error(`Payment #${parseInt(rowIndex)+1}: ${validation.message}`);
                    }
                });
                
                const formData = new FormData(form);
                const response = await fetch(PAYMENTS_ENDPOINT + '?action=create', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Show SweetAlert2 success notification
                    let htmlContent = `<div style="text-align: left;">
                        <p><strong>${data.message}</strong></p>
                        <p><strong>Branch:</strong> ${data.branch}</p>
                        <p><strong>Total Amount:</strong> ${formatCurrency(data.total_amount)}</p>
                        <hr style="margin: 10px 0;">
                        <div style="max-height: 300px; overflow-y: auto;">`;
                    
                    data.payments.forEach(payment => {
                        htmlContent += `<p style="margin-bottom: 8px;">
                            <i class="fas fa-check-circle text-success me-2"></i>
                            ${payment.collector_name} (${payment.account_type}): ₱${formatNumberWithCommas(payment.amount)} 
                            (Loan #${payment.loan_id}, New Balance: ₱${formatNumberWithCommas(payment.new_balance)})
                            ${payment.status_updated ? '<br><span class="text-success"><i class="fas fa-check"></i> Loan marked as Completed!</span>' : ''}
                        </p>`;
                    });
                    
                    htmlContent += `</div></div>`;
                    
                    Swal.fire({
                        title: 'Success!',
                        html: htmlContent,
                        icon: 'success',
                        confirmButtonColor: '#3085d6',
                        confirmButtonText: 'OK',
                        width: '600px'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            window.location.reload();
                        }
                    });
                    
                    // Auto-refresh after 5 seconds if user doesn't click OK
                    setTimeout(() => {
                        window.location.reload();
                    }, 5000);
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
                    confirmButtonColor: '#3085d6',
                    confirmButtonText: 'OK'
                });
                
                // Scroll to the error
                errorElement.textContent = error.message;
                errorElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

        // Initialize the form when page loads
        $(document).ready(function() {
            // Set current date and time when page loads
            setCurrentDateTime();
            
            // Add first payment row by default
            addPaymentRow();

            // With no branch selected the dropdown lists every collector
            if (!$('#branch').is(':disabled') && !$('#branch').val()) {
                updateAllPaymentRowsForBranch('');
            }
            
            // Set up event listener for add payment button
            $('#addPaymentBtn').click(function() {
                addPaymentRow();

                // with no branch chosen, fill the new row with every collector
                if (!$('#branch').val()) {
                    const indexes = Object.keys(paymentRowsData);

                    if (indexes.length) {
                        loadCollectorsByBranch(indexes[indexes.length - 1], '');
                    }
                }
            });
            
            // Set up form submission handler
            document.getElementById('collectorPaymentForm').addEventListener('submit', handleFormSubmit);
            
            // Set up branch change listener (only if branch is not disabled)
            const branchSelect = $('#branch');
            if (!branchSelect.is(':disabled')) {
                branchSelect.on('change', function() {
                    const branch = $(this).val();
                    updateAllPaymentRowsForBranch(branch);
                    updateCollectorDropdowns();
                    checkForDuplicateCollectors();
                });
            } else {
                // If branch is disabled (restricted user), load collectors for their branch immediately
                const userBranch = branchSelect.val();
                if (userBranch) {
                    updateAllPaymentRowsForBranch(userBranch);
                }
            }
            
            // Check for datetime changes periodically (the helper is optional)
            if (typeof checkDateTimeChanges === 'function') {
                setInterval(checkDateTimeChanges, 60000);
            }
            
            // Also check when the payment date is changed manually
            $('#payment_date').on('change', function() {
                const selectedDate = new Date($(this).val());
                const currentTime = new Date();
                const timeDiff = Math.abs(selectedDate - currentTime);
                const fiveMinutes = 5 * 60 * 1000; // 5 minutes in milliseconds
                
                if (timeDiff > fiveMinutes) {
                    const warningElement = $('#datetime_warning');
                    const warningText = $('#datetime_warning_text');
                    
                    if (selectedDate > currentTime) {
                        warningText.text('Warning: You are setting a future date. This will be logged for security purposes.');
                    } else {
                        warningText.text('Warning: You are setting a past date. This will be logged for security purposes.');
                    }
                    
                    warningElement.show();
                }
            });
            
            // Confirm before removing payment row
            window.removePaymentRow = function(rowIndex) {
                Swal.fire({
                    title: 'Remove Payment?',
                    text: 'Are you sure you want to remove this payment?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#3085d6',
                    cancelButtonColor: '#d33',
                    confirmButtonText: 'Yes, remove it!'
                }).then((result) => {
                    if (result.isConfirmed) {
                        $(`[data-index="${rowIndex}"]`).remove();
                        delete paymentRowsData[rowIndex];
                        renumberPaymentRows();
                        updateSummary();
                        updateCollectorDropdowns();
                        checkForDuplicateCollectors();
                    }
                });
            };
        });
})();
