/* ==========================================================================
   Prime City - GCash in/out behaviour
   Transaction table, customer search, edit modal, delete flow and flash
   messages. Page data lives in #gcash-in-out-data.
   ========================================================================== */

(function () {
    var pageConfigCache = null;

    /**
     * Page configuration from #gcash-in-out-data.
     */
    function pageConfig() {
        if (pageConfigCache) { return pageConfigCache; }

        var node = document.getElementById('gcash-in-out-data');

        if (!node) { pageConfigCache = {}; return pageConfigCache; }

        try {
            pageConfigCache = JSON.parse(node.textContent || '{}');
        } catch (error) {
            console.error('[gcash-in-out] Could not parse page configuration.', error);
            pageConfigCache = {};
        }

        return pageConfigCache;
    }

    const pageCfg = pageConfig();

            // Customer data from PHP
            const customers = pageCfg.customers || [];
            
            // Function to apply branch filter
            function applyBranchFilter(branch) {
                const currentUrl = new URL(window.location.href);
                currentUrl.searchParams.set('branch_filter', branch);
                window.location.href = currentUrl.toString();
            }
            
            // Function to filter customers based on search term
            function filterCustomers(searchTerm, areaFilter = null) {
                searchTerm = searchTerm.toLowerCase();
                return customers.filter(customer => {
                    const customerName = (
                        customer.first_name + ' ' + 
                        (customer.middle_name ? customer.middle_name + ' ' : '') + 
                        customer.last_name + 
                        (customer.suffix && customer.suffix !== 'None' ? ' ' + customer.suffix : '')
                    ).toLowerCase();
                    
                    const matchesSearch = customerName.includes(searchTerm);
                    const matchesArea = !areaFilter || customer.area === areaFilter;
                    
                    return matchesSearch && matchesArea;
                });
            }
            
            // Function to display customer dropdown
            function showCustomerDropdown(searchTerm, dropdownId, selectedArea = null) {
                const filteredCustomers = filterCustomers(searchTerm, selectedArea);
                const dropdown = $(`#${dropdownId}`);
                
                dropdown.empty();
                
                if (filteredCustomers.length === 0) {
                    dropdown.append('<div class="customer-option">No customers found</div>');
                } else {
                    filteredCustomers.forEach(customer => {
                        const customerName = (
                            customer.first_name + ' ' + 
                            (customer.middle_name ? customer.middle_name + ' ' : '') + 
                            customer.last_name + 
                            (customer.suffix && customer.suffix !== 'None' ? ' ' + customer.suffix : '')
                        );
                        
                        dropdown.append(`
                            <div class="customer-option" data-id="${customer.id}" data-name="${customerName}">
                                ${customerName} ${customer.area ? '(' + customer.area + ')' : ''}
                            </div>
                        `);
                    });
                }
                
                dropdown.show();
            }
            
            // In the form handling section - update the toggle logic
            function toggleSections(selectElement) {
                const selectedType = selectElement.val();
                const feeSection = $('#feeSection');
                const branchSection = $('#branch');
                const areaField = $('#area');
                const customerSection = $('#customerSection');
                const bankSection = $('#bankSection');
                const bankAccountNameSection = $('#bankAccountNameSection');
                
                // Branch is always required for all transaction types
                branchSection.prop('required', true);
                
                if (selectedType === 'Cash In' || selectedType === 'Cash Out') {
                    feeSection.show();
                    areaField.prop('required', true);
                    customerSection.show();
                    bankSection.hide();
                    bankAccountNameSection.hide();
                    $('#bank').prop('required', false);
                    $('#bank_account_name').prop('required', false);
                } else if (selectedType === 'Fund Transfer') {
                    feeSection.show();
                    areaField.prop('required', false);
                    customerSection.hide();
                    bankSection.show();
                    bankAccountNameSection.show();
                    $('#bank').prop('required', true);
                } else if (selectedType === 'Beginning Balance') {
                    // Beginning Balance - HIDE fee section and hide customer and bank sections
                    feeSection.hide();
                    areaField.prop('required', false);
                    customerSection.hide();
                    bankSection.hide();
                    bankAccountNameSection.hide();
                    $('#bank').prop('required', false);
                    $('#bank_account_name').prop('required', false);
                } else {
                    feeSection.hide();
                    areaField.prop('required', false);
                    customerSection.hide();
                    bankSection.hide();
                    bankAccountNameSection.hide();
                    $('#bank').prop('required', false);
                    $('#bank_account_name').prop('required', false);
                }
                
                // Filter customers by selected area
                filterCustomersByArea();
            }

            // Function to toggle edit modal sections based on transaction type
            function toggleEditSections(selectedType) {
                const editBankSection = $('.edit-bank-section');
                const editBankAccountSection = $('.edit-bank-account-section');
                const editCustomerSection = $('.edit-customer-section');
                const editFeeSection = $('#editFeeSection');
                
                // Branch is always required for all transaction types in edit modal
                $('#edit_branch').prop('required', true);
                
                if (selectedType === 'Cash In' || selectedType === 'Cash Out') {
                    editBankSection.hide();
                    editBankAccountSection.hide();
                    editCustomerSection.show();
                    editFeeSection.show();
                } else if (selectedType === 'Fund Transfer') {
                    editBankSection.show();
                    editBankAccountSection.show();
                    editCustomerSection.hide();
                    editFeeSection.show();
                } else if (selectedType === 'Beginning Balance') {
                    // Beginning Balance - HIDE fee section and hide customer and bank sections
                    editBankSection.hide();
                    editBankAccountSection.hide();
                    editCustomerSection.hide();
                    editFeeSection.hide();
                } else {
                    editBankSection.hide();
                    editBankAccountSection.hide();
                    editCustomerSection.hide();
                    editFeeSection.hide();
                }
            }
            
            // Function to filter customers by selected area
            function filterCustomersByArea() {
                const selectedArea = $('#area').val();
                const searchTerm = $('#customer_search').val();
                
                // Refresh customer dropdown based on area filter
                if (searchTerm) {
                    showCustomerDropdown(searchTerm, 'customer_dropdown', selectedArea);
                }
            }
            
            // Function to filter customers by selected area in the edit modal
            function filterEditCustomersByArea() {
                const selectedArea = $('#edit_area').val();
                const searchTerm = $('#edit_customer_search').val();
                
                // Refresh customer dropdown based on area filter
                if (searchTerm) {
                    showCustomerDropdown(searchTerm, 'edit_customer_dropdown', selectedArea);
                }
            }

            // Initialize datetime picker with custom format
            document.addEventListener('DOMContentLoaded', function() {
                flatpickr("#date", {
                    enableTime: true,
                    enableSeconds: true,
                    dateFormat: "m-d-Y h:i:S K",
                    defaultDate: new Date(),
                    time_24hr: false, // Show AM/PM format
                    allowInput: true  // the field stays typeable
                });

                // Initialize datetime picker for edit modal
                flatpickr("#edit_date", {
                    enableTime: true,
                    enableSeconds: true,
                    dateFormat: "m-d-Y h:i:S K",
                    time_24hr: false, // Show AM/PM format
                    allowInput: true
                });

                // Format amount input with commas
                const amountInput = document.getElementById('amount');
                const editAmountInput = document.getElementById('edit_amount');
                const feeInput = document.getElementById('fee');
                const editFeeInput = document.getElementById('edit_fee');
                
                function formatCurrencyInput(input) {
                    input.addEventListener('input', function(e) {
                        // Remove all non-digit characters except decimal point
                        let value = this.value.replace(/[^\d.]/g, '');
                        
                        // Split into parts before and after decimal
                        let parts = value.split('.');
                        let wholeNumber = parts[0];
                        let decimal = parts.length > 1 ? '.' + parts[1] : '';
                        
                        // Add commas to whole number part
                        wholeNumber = wholeNumber.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                        
                        // Combine and update value
                        this.value = wholeNumber + decimal;
                    });
                }
                
                formatCurrencyInput(amountInput);
                formatCurrencyInput(editAmountInput);
                formatCurrencyInput(feeInput);
                formatCurrencyInput(editFeeInput);

                // Initialize the data tables
                window.addEventListener('DOMContentLoaded', event => {
                    const datatablesSimple = document.getElementById('datatablesSimple');
                    if (datatablesSimple) {
                        new simpleDatatables.DataTable(datatablesSimple, {
                            perPage: 10,
                            searchable: true,
                            sortable: true
                        });
                    }
                    
                    const cashbookTable = document.getElementById('cashbookTable');
                    if (cashbookTable) {
                        new simpleDatatables.DataTable(cashbookTable, {
                            perPage: 10,
                            searchable: true,
                            sortable: true
                        });
                    }
                });

                // Show SweetAlert2 notifications for errors or success
                // Flash messages coming from PHP (see #gcash-in-out-data)
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

                // Handle delete button clicks
                $(document).on('click', '.delete-btn', function() {
                    const transactionId = $(this).data('id');
                    
                    Swal.fire({
                        title: 'Are you sure?',
                        text: "You won't be able to revert this!",
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#3085d6',
                        cancelButtonColor: '#d33',
                        confirmButtonText: 'Yes, delete it!'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            // Submit delete request
                            window.location.href = `gcash_in_out.php?delete_id=${transactionId}&branch_filter=${pageCfg.deleteBranchFilter}`;
                        }
                    });
                });

                // Handle edit button clicks
                $(document).on('click', '.edit-btn', function() {
                    const transactionId = $(this).data('id');
                    const transactionDate = $(this).data('date');
                    const transactionType = $(this).data('type');
                    const transactionAmount = $(this).data('amount');
                    const transactionFee = $(this).data('fee');
                    const transactionBranch = $(this).data('branch');
                    const transactionReference = $(this).data('reference');
                    const transactionNotes = $(this).data('notes');
                    const transactionArea = $(this).data('area');
                    const transactionCustomerId = $(this).data('customer-id');
                    const transactionBank = $(this).data('bank');
                    const transactionBankAccountName = $(this).data('bank-account-name');
                    
                    // Populate the modal fields
                    $('#edit_id').val(transactionId);
                    $('#edit_date').val(transactionDate);
                    $('#edit_type').val(transactionType);
                    $('#edit_amount').val(transactionAmount);
                    $('#edit_fee').val(transactionFee);
                    $('#edit_reference').val(transactionReference);
                    $('#edit_notes').val(transactionNotes);
                    $('#edit_area').val(transactionArea);
                    $('#edit_bank').val(transactionBank);
                    $('#edit_bank_account_name').val(transactionBankAccountName);
                    
                    // Set branch - handle different user types
                    // Set branch - handle different user types
                    if (pageCfg.isMultiBranch) {
                        // For Admin and Area Manager, set the dropdown
                        $('#edit_branch').val(transactionBranch);
                    } else {
                        // For restricted users, set the hidden field
                        $('#edit_branch_hidden').val(transactionBranch);
                        $('#edit_branch_display').val(transactionBranch);
                    }
                    
                    // Set customer if exists
                    if (transactionCustomerId) {
                        const customer = customers.find(c => c.id == transactionCustomerId);
                        if (customer) {
                            const customerName = (
                                customer.first_name + ' ' + 
                                (customer.middle_name ? customer.middle_name + ' ' : '') + 
                                customer.last_name + 
                                (customer.suffix && customer.suffix !== 'None' ? ' ' + customer.suffix : '')
                            );
                            
                            $('#edit_customer_id').val(transactionCustomerId);
                            $('#edit_selected_customer_name').text(customerName);
                            $('#edit_selected_customer').show();
                        }
                    }
                    
                    // Toggle sections based on type
                    toggleEditSections(transactionType);
                    
                    // Filter customers by area in the edit modal
                    filterEditCustomersByArea();
                    
                    // Show the modal
                    var editModal = new bootstrap.Modal(document.getElementById('editModal'));
                    editModal.show();
                });

                // Form submission handling
                $('#gcashForm').on('submit', function(e) {
                    // Remove commas from amount and fee before submitting
                    const amountInput = $('#amount');
                    const feeInput = $('#fee');
                    amountInput.val(amountInput.val().replace(/,/g, ''));
                    feeInput.val(feeInput.val().replace(/,/g, ''));
                });

                $('#editForm').on('submit', function(e) {
                    // Remove commas from amount and fee before submitting
                    const amountInput = $('#edit_amount');
                    const feeInput = $('#edit_fee');
                    amountInput.val(amountInput.val().replace(/,/g, ''));
                    feeInput.val(feeInput.val().replace(/,/g, ''));
                });

                // Toggle sections on type change
                $('#type').on('change', function() {
                    toggleSections($(this));
                });

                $('#edit_type').on('change', function() {
                    toggleEditSections($(this).val());
                });
                
                // Filter customers when area changes
                $('#area').on('change', function() {
                    filterCustomersByArea();
                });
                
                // Filter customers when area changes in edit modal
                $('#edit_area').on('change', function() {
                    filterEditCustomersByArea();
                });

                // Initialize sections on page load
                toggleSections($('#type'));
                
                // Customer search functionality
                $('#customer_search').on('input', function() {
                    if ($(this).hasClass('has-customer')) {
                        return;   /* a customer is already chosen */
                    }

                    const searchTerm = $(this).val();
                    const selectedArea = $('#area').val();
                    
                    if (searchTerm.length > 0) {
                        showCustomerDropdown(searchTerm, 'customer_dropdown', selectedArea);
                    } else {
                        $('#customer_dropdown').hide();
                    }
                });
                
                $('#edit_customer_search').on('input', function() {
                    if ($(this).hasClass('has-customer')) {
                        return;
                    }

                    const searchTerm = $(this).val();
                    const selectedArea = $('#edit_area').val();
                    
                    if (searchTerm.length > 0) {
                        showCustomerDropdown(searchTerm, 'edit_customer_dropdown', selectedArea);
                    } else {
                        $('#edit_customer_dropdown').hide();
                    }
                });
                
                // Handle customer selection from dropdown
                $(document).on('click', '#customer_dropdown .customer-option', function() {
                    const customerId = $(this).data('id');
                    const customerName = $(this).data('name');
                    
                    $('#customer_id').val(customerId);
                    $('#selected_customer_name').text(customerName);

                    /* the pick is shown inside the field itself */
                    $('#customer_search')
                        .val(customerName)
                        .addClass('has-customer')
                        .prop('readonly', true);
                    $('#clear_customer').prop('hidden', false);
                    $('#customer_dropdown').hide();
                });
                
                $(document).on('click', '#edit_customer_dropdown .customer-option', function() {
                    const customerId = $(this).data('id');
                    const customerName = $(this).data('name');
                    
                    $('#edit_customer_id').val(customerId);
                    $('#edit_selected_customer_name').text(customerName);

                    $('#edit_customer_search')
                        .val(customerName)
                        .addClass('has-customer')
                        .prop('readonly', true);
                    $('#edit_clear_customer').prop('hidden', false);
                    $('#edit_customer_dropdown').hide();
                });
                
                // Clear selected customer
                $('#clear_customer').on('click', function() {
                    $('#customer_id').val('');
                    $('#selected_customer_name').text('');
                    $('#customer_search')
                        .val('')
                        .removeClass('has-customer')
                        .prop('readonly', false)
                        .trigger('focus');
                    $(this).prop('hidden', true);
                    $('#customer_dropdown').hide();
                });
                
                $('#edit_clear_customer').on('click', function() {
                    $('#edit_customer_id').val('');
                    $('#edit_selected_customer_name').text('');
                    $('#edit_customer_search')
                        .val('')
                        .removeClass('has-customer')
                        .prop('readonly', false)
                        .trigger('focus');
                    $(this).prop('hidden', true);
                    $('#edit_customer_dropdown').hide();
                });
                
                // Hide dropdown when clicking outside
                $(document).on('click', function(e) {
                    if (!$(e.target).closest('.customer-search-container').length) {
                        $('#customer_dropdown').hide();
                        $('#edit_customer_dropdown').hide();
                    }
                });
            });

    // Called from the branch filter markup.
    window.applyBranchFilter = applyBranchFilter;
})();
