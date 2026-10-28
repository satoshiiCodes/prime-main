/* ==========================================================================
   Prime City - Expenses behaviour
   Amount formatting, the branch filter, DataTables setup, the edit modal
   and flash messages. Page data lives in #add-expenses-data.
   ========================================================================== */

(function () {
    var pageConfigCache = null;

    /**
     * Page configuration from #add-expenses-data.
     */
    function pageConfig() {
        if (pageConfigCache) { return pageConfigCache; }

        var node = document.getElementById('add-expenses-data');

        if (!node) { pageConfigCache = {}; return pageConfigCache; }

        try {
            pageConfigCache = JSON.parse(node.textContent || '{}');
        } catch (error) {
            console.error('[add-expenses] Could not parse page configuration.', error);
            pageConfigCache = {};
        }

        return pageConfigCache;
    }

    const pageCfg = pageConfig();

            // Function to format numbers with commas in real-time
            function formatNumber(input) {
                // Remove all non-digit characters except decimal point
                let value = input.value.replace(/[^\d.]/g, '');
                
                // Split into integer and decimal parts
                let parts = value.split('.');
                let integerPart = parts[0];
                let decimalPart = parts.length > 1 ? '.' + parts[1] : '';
                
                // Add commas to integer part
                integerPart = integerPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                
                // Update the input value
                input.value = integerPart + decimalPart;
            }

            // Function to apply branch filter
            function applyBranchFilter(branch) {
                const currentUrl = new URL(window.location.href);
                currentUrl.searchParams.set('branch', branch);
                window.location.href = currentUrl.toString();
            }
            
            // Show SweetAlert for success/error messages
            // Flash messages coming from PHP (see #add-expenses-data)
            if (pageCfg.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: pageCfg.success,
                    confirmButtonColor: '#3085d6',
                    timer: 3000,
                    showConfirmButton: true
                });
            }

            if (pageCfg.error) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: pageCfg.error,
                    confirmButtonColor: '#3085d6'
                });
            }
            
            document.addEventListener('DOMContentLoaded', function() {
                // Initialize DataTable with Bootstrap styling
                var expenseTable = $('#expensesTable').DataTable({
                    responsive: true,
                    lengthChange: false,   /* fixed at 10 rows per page - no length control */
                    pageLength: 10,
                    dom: 'rt<"bottom"ip>',
                    language: {
                        search: "_INPUT_",
                        searchPlaceholder: "Search...",
                        info: "Showing _START_ to _END_ of _TOTAL_ entries",
                        infoEmpty: "Showing 0 to 0 of 0 entries",
                        infoFiltered: "(filtered from _MAX_ total entries)",
                        paginate: {
                            first: "\u00AB",
                            last: "\u00BB",
                            next: "\u203A",
                            previous: "\u2039"
                        }
                    },
                    columnDefs: [
                        { targets: [2,3,4,5,6,7,8,9,10,11,12,13], className: 'dt-body-right' },
                        { targets: [0,1,14,15], className: 'dt-body-left' }
                    ]
                });

                // Toolbar search drives the table
                var expenseSearch = document.getElementById('expenseSearch');

                if (expenseSearch) {
                    expenseSearch.addEventListener('input', function () {
                        expenseTable.search(this.value).draw();
                    });
                }

                // Keep the footer outside the sideways-scrolling table box
                var $wrapper = $('#expensesTable_wrapper');
                var $scrollBox = $wrapper.closest('.table-responsive');

                if ($scrollBox.length) {
                    $scrollBox.after($wrapper.find('.bottom'));
                }

                // Calculate total on page load
                calculateTotal();
                
                // Form validation
                document.getElementById('expenseForm').addEventListener('submit', function(e) {
                    // Ensure all amount fields have valid numbers
                    const amountInputs = document.querySelectorAll('.amount-input');
                    let isValid = true;
                    
                    amountInputs.forEach(input => {
                        if (input.value === '') input.value = '0';
                        let num = parseFloat(input.value.replace(/,/g, ''));
                        if (isNaN(num)) {
                            isValid = false;
                        }
                    });
                    
                    if (!isValid) {
                        e.preventDefault();
                        Swal.fire({
                            icon: 'error',
                            title: 'Invalid Input',
                            text: 'Please enter valid numbers for all amount fields',
                            confirmButtonColor: '#3085d6',
                        });
                        return false;
                    }
                    
                    return true;
                });
                
                // Edit form validation
                document.getElementById('editExpenseForm').addEventListener('submit', function(e) {
                    const amountInputs = document.querySelectorAll('#editExpenseForm .amount-input');
                    let isValid = true;
                    
                    amountInputs.forEach(input => {
                        if (input.value === '') input.value = '0';
                        let num = parseFloat(input.value.replace(/,/g, ''));
                        if (isNaN(num)) {
                            isValid = false;
                        }
                    });
                    
                    if (!isValid) {
                        e.preventDefault();
                        Swal.fire({
                            icon: 'error',
                            title: 'Invalid Input',
                            text: 'Please enter valid numbers for all amount fields',
                            confirmButtonColor: '#3085d6',
                        });
                        return false;
                    }
                    
                    return true;
                });
            });
            
            function calculateTotal() {
                let total = 0;
                const inputs = [
                    'per_diem', 
                    'load_allowance', 
                    'salary', 
                    'rental', 
                    'bir', 
                    'allowance', 
                    'office_supplies', 
                    'electric_bill',
                    'sss',
                    'philhealth',
                    'pagibig'
                ];
                
                inputs.forEach(id => {
                    const element = document.getElementById(id);
                    if (element) {
                        const value = element.value.replace(/,/g, '');
                        const num = parseFloat(value) || 0;
                        total += num;
                    }
                });
                
                const totalAmountSpan = document.getElementById('totalAmount');
                const totalExpensesInput = document.getElementById('total_expenses');
                
                if (totalAmountSpan) {
                    totalAmountSpan.textContent = total.toLocaleString('en-US', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });
                }
                
                if (totalExpensesInput) {
                    totalExpensesInput.value = total;
                }
            }
            
            function calculateEditTotal() {
                let total = 0;
                const inputs = [
                    'edit_per_diem', 
                    'edit_load_allowance', 
                    'edit_salary', 
                    'edit_rental', 
                    'edit_bir', 
                    'edit_allowance', 
                    'edit_office_supplies', 
                    'edit_electric_bill',
                    'edit_sss',
                    'edit_philhealth',
                    'edit_pagibig'
                ];
                
                inputs.forEach(id => {
                    const element = document.getElementById(id);
                    if (element) {
                        const value = element.value.replace(/,/g, '');
                        const num = parseFloat(value) || 0;
                        total += num;
                    }
                });
                
                const editTotalAmountSpan = document.getElementById('edit_totalAmount');
                const editTotalExpensesInput = document.getElementById('edit_total_expenses');
                
                if (editTotalAmountSpan) {
                    editTotalAmountSpan.textContent = total.toLocaleString('en-US', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });
                }
                
                if (editTotalExpensesInput) {
                    editTotalExpensesInput.value = total;
                }
            }
            
            function confirmDelete(id) {
                Swal.fire({
                    title: 'Are you sure?',
                    text: "You won't be able to revert this!",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#3085d6',
                    cancelButtonColor: '#d33',
                    confirmButtonText: 'Yes, delete it!',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = 'add_expenses.php?delete_id=' + id;
                    }
                });
            }
            
            function openEditModal(id) {
                // Fetch expense data via AJAX
                fetch(`api/get_expense.php?id=${id}`)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data && data.id) {
                            // Populate the modal form fields
                            document.getElementById('edit_expense_id').value = data.id;
                            document.getElementById('edit_expense_date').value = data.expense_date;
                            document.getElementById('edit_branch').value = data.branch;
                            document.getElementById('edit_per_diem').value = parseFloat(data.per_diem).toLocaleString('en-US');
                            document.getElementById('edit_load_allowance').value = parseFloat(data.load_allowance).toLocaleString('en-US');
                            document.getElementById('edit_salary').value = parseFloat(data.salary).toLocaleString('en-US');
                            document.getElementById('edit_rental').value = parseFloat(data.rental).toLocaleString('en-US');
                            document.getElementById('edit_bir').value = parseFloat(data.bir).toLocaleString('en-US');
                            document.getElementById('edit_allowance').value = parseFloat(data.allowance).toLocaleString('en-US');
                            document.getElementById('edit_office_supplies').value = parseFloat(data.office_supplies).toLocaleString('en-US');
                            document.getElementById('edit_electric_bill').value = parseFloat(data.electric_bill).toLocaleString('en-US');
                            document.getElementById('edit_sss').value = parseFloat(data.sss || 0).toLocaleString('en-US');
                            document.getElementById('edit_philhealth').value = parseFloat(data.philhealth || 0).toLocaleString('en-US');
                            document.getElementById('edit_pagibig').value = parseFloat(data.pagibig || 0).toLocaleString('en-US');
                            
                            // Remove "Expenses - " prefix from remarks for editing
                            let remarks = data.remarks;
                            if (remarks && remarks.startsWith('Expenses - ')) {
                                remarks = remarks.substring(11);
                            }
                            document.getElementById('edit_remarks').value = remarks || '';
                            
                            // Calculate and display total
                            calculateEditTotal();
                            
                            // Show the modal
                            const editModal = new bootstrap.Modal(document.getElementById('editExpenseModal'));
                            editModal.show();
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: 'Failed to load expense data',
                                confirmButtonColor: '#3085d6'
                            });
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Error loading expense data: ' + error.message,
                            confirmButtonColor: '#3085d6'
                        });
                    });
            }

    // Functions the markup calls from inline handlers.
    window.formatNumber = formatNumber;
    window.calculateTotal = calculateTotal;
    window.calculateEditTotal = calculateEditTotal;
    window.applyBranchFilter = applyBranchFilter;
    window.openEditModal = openEditModal;
    window.confirmDelete = confirmDelete;
})();
