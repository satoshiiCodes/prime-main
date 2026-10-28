// js/employee_lists.js

document.addEventListener('DOMContentLoaded', function() {
    // Initialize DataTables
    setTimeout(function() {
        try {
            // Initialize collector accounts table
            const collectorTable = document.getElementById('collectorAccountsTable');
            if (collectorTable && collectorTable.querySelector('tbody tr')) {
                if (typeof simpleDatatables !== 'undefined') {
                    const collectorDataTable = new simpleDatatables.DataTable("#collectorAccountsTable", {
                        perPage: 10,
                        searchable: true,
                        sortable: true,
                        columns: [
                            { select: 0, sortable: false },
                            { select: 1, sort: "asc" },
                            { select: 2, sortable: true },
                            { select: 3, sortable: true },
                            { select: 4, sortable: true },
                            { select: 5, sortable: true },
                            { select: 6, sortable: true },
                            { select: 7, sortable: false }
                        ]
                    });
                }
            }

            // Initialize proxy accounts table
            const proxyTable = document.getElementById('proxyAccountsTable');
            if (proxyTable && proxyTable.querySelector('tbody tr')) {
                if (typeof simpleDatatables !== 'undefined') {
                    const proxyDataTable = new simpleDatatables.DataTable("#proxyAccountsTable", {
                        perPage: 10,
                        searchable: true,
                        sortable: true,
                        columns: [
                            { select: 0, sortable: false },
                            { select: 1, sort: "asc" },
                            { select: 2, sortable: true },
                            { select: 3, sortable: true },
                            { select: 4, sortable: true },
                            { select: 5, sortable: true },
                            { select: 6, sortable: true },
                            { select: 7, sortable: false }
                        ]
                    });
                }
            } else if (proxyTable && proxyTable.querySelector('tbody tr') === null) {
                if (typeof simpleDatatables !== 'undefined') {
                    try {
                        const proxyDataTable = new simpleDatatables.DataTable("#proxyAccountsTable", {
                            perPage: 10,
                            searchable: true,
                            sortable: true
                        });
                    } catch(e) {
                        console.log('Proxy table initialization skipped - table may be hidden or not exist');
                    }
                }
            }

            // Initialize accounts table (only if present)
            const accountsTable = document.getElementById('accountsTable');
            if (accountsTable && accountsTable.querySelector('tbody tr')) {
                if (typeof simpleDatatables !== 'undefined') {
                    const accountsDataTable = new simpleDatatables.DataTable("#accountsTable", {
                        perPage: 10,
                        searchable: true,
                        sortable: true,
                        columns: [
                            { select: 0, sort: "asc" },
                            { select: 1, sortable: true },
                            { select: 2, sortable: true },
                            { select: 3, sortable: true },
                            { select: 4, sortable: true },
                            { select: 5, sortable: false }
                        ]
                    });
                }
            }
        } catch (e) {
            console.error('DataTable initialization error:', e);
        }
    }, 100);

    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Initialize modals
    const editCollectorModal = new bootstrap.Modal(document.getElementById('editCollectorModal'));
    const editProxyModal = new bootstrap.Modal(document.getElementById('editProxyModal'));
    const viewCustomersModal = new bootstrap.Modal(document.getElementById('viewCustomersModal'));
    const imageViewModal = new bootstrap.Modal(document.getElementById('imageViewModal'));
    const viewAccountModal = new bootstrap.Modal(document.getElementById('viewAccountModal'));
    const editAccountModal = new bootstrap.Modal(document.getElementById('editAccountModal'));
    const viewProxyModal = new bootstrap.Modal(document.getElementById('viewProxyModal'));
    const viewCollectorModal = new bootstrap.Modal(document.getElementById('viewCollectorModal'));
    const salaryModal = new bootstrap.Modal(document.getElementById('salaryModal'));
    const salaryHistoryModal = new bootstrap.Modal(document.getElementById('salaryHistoryModal'));
    const deductionModal = new bootstrap.Modal(document.getElementById('deductionModal'));
    const deductionHistoryModal = new bootstrap.Modal(document.getElementById('deductionHistoryModal'));
    const paymentModal = new bootstrap.Modal(document.getElementById('paymentModal'));
    const allowanceModal = new bootstrap.Modal(document.getElementById('allowanceModal'));
    const allowanceHistoryModal = new bootstrap.Modal(document.getElementById('allowanceHistoryModal'));

    // Set default effective date to today in YYYY-MM-DD format
    const effectiveDateInput = document.getElementById('effectiveDate');
    if (effectiveDateInput) {
        const today = new Date();
        const year = today.getFullYear();
        const month = String(today.getMonth() + 1).padStart(2, '0');
        const day = String(today.getDate()).padStart(2, '0');
        effectiveDateInput.value = `${year}-${month}-${day}`;
    }

    // Set default dates for deduction modals
    const deductionEffectiveDate = document.getElementById('deductionEffectiveDate');
    if (deductionEffectiveDate) {
        const today = new Date();
        const year = today.getFullYear();
        const month = String(today.getMonth() + 1).padStart(2, '0');
        const day = String(today.getDate()).padStart(2, '0');
        deductionEffectiveDate.value = `${year}-${month}-${day}`;
    }

    const loanStartDate = document.getElementById('loanStartDate');
    const loanEndDate = document.getElementById('loanEndDate');
    if (loanStartDate) {
        const today = new Date();
        const year = today.getFullYear();
        const month = String(today.getMonth() + 1).padStart(2, '0');
        const day = String(today.getDate()).padStart(2, '0');
        loanStartDate.value = `${year}-${month}-${day}`;

        const nextYear = new Date(today);
        nextYear.setFullYear(today.getFullYear() + 1);
        const endYear = nextYear.getFullYear();
        const endMonth = String(nextYear.getMonth() + 1).padStart(2, '0');
        const endDay = String(nextYear.getDate()).padStart(2, '0');
        loanEndDate.value = `${endYear}-${endMonth}-${endDay}`;
    }

    const paymentDate = document.getElementById('paymentDate');
    if (paymentDate) {
        const today = new Date();
        const year = today.getFullYear();
        const month = String(today.getMonth() + 1).padStart(2, '0');
        const day = String(today.getDate()).padStart(2, '0');
        paymentDate.value = `${year}-${month}-${day}`;
    }

    const allowanceEffectiveDate = document.getElementById('allowanceEffectiveDate');
    if (allowanceEffectiveDate) {
        const today = new Date();
        const year = today.getFullYear();
        const month = String(today.getMonth() + 1).padStart(2, '0');
        const day = String(today.getDate()).padStart(2, '0');
        allowanceEffectiveDate.value = `${year}-${month}-${day}`;
    }

    // Utility functions
    function formatNumberWithCommas(x) {
        if (!x && x !== 0) return '';
        let num = parseFloat(x.toString().replace(/,/g, ''));
        if (isNaN(num)) return '';
        return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function removeCommas(x) {
        if (!x) return '';
        return x.toString().replace(/,/g, '');
    }

    function setupSalaryFormatting(inputElement, hiddenElement) {
        if (!inputElement || !hiddenElement) return;

        inputElement.addEventListener('input', function(e) {
            let value = this.value.replace(/,/g, '');
            value = value.replace(/[^0-9.]/g, '');

            const parts = value.split('.');
            if (parts.length > 2) {
                value = parts[0] + '.' + parts.slice(1).join('');
            }

            if (parts.length === 2) {
                parts[1] = parts[1].slice(0, 2);
                value = parts.join('.');
            }

            if (value) {
                hiddenElement.value = value;
                if (value.includes('.')) {
                    const [intPart, decPart] = value.split('.');
                    const formattedInt = parseInt(intPart || '0').toLocaleString('en-US');
                    this.value = formattedInt + '.' + decPart;
                } else {
                    this.value = parseInt(value || '0').toLocaleString('en-US');
                }
            } else {
                hiddenElement.value = '';
                this.value = '';
            }
        });

        inputElement.addEventListener('blur', function() {
            if (this.value) {
                let rawValue = removeCommas(this.value);
                let num = parseFloat(rawValue);
                if (!isNaN(num)) {
                    hiddenElement.value = num.toFixed(2);
                    this.value = formatNumberWithCommas(num.toFixed(2));
                }
            }
        });

        inputElement.addEventListener('paste', function(e) {
            e.preventDefault();
            let pastedText = (e.clipboardData || window.clipboardData).getData('text');
            pastedText = pastedText.replace(/,/g, '').replace(/[^0-9.]/g, '');

            const parts = pastedText.split('.');
            if (parts.length > 2) {
                pastedText = parts[0] + '.' + parts.slice(1).join('');
            }

            if (parts.length === 2) {
                parts[1] = parts[1].slice(0, 2);
                pastedText = parts.join('.');
            }

            if (pastedText) {
                hiddenElement.value = pastedText;
                if (pastedText.includes('.')) {
                    const [intPart, decPart] = pastedText.split('.');
                    const formattedInt = parseInt(intPart || '0').toLocaleString('en-US');
                    this.value = formattedInt + '.' + decPart;
                } else {
                    this.value = parseInt(pastedText || '0').toLocaleString('en-US');
                }
            }
        });
    }

    // Setup salary formatting for the modal
    const newSalaryInput = document.getElementById('newSalary');
    const newSalaryRaw = document.getElementById('newSalaryRaw');
    setupSalaryFormatting(newSalaryInput, newSalaryRaw);

    // Setup deduction amount formatting
    const deductionAmount = document.getElementById('deductionAmount');
    const deductionAmountRaw = document.getElementById('deductionAmountRaw');
    setupSalaryFormatting(deductionAmount, deductionAmountRaw);

    // Setup payment amount formatting
    const paymentAmount = document.getElementById('paymentAmount');
    const paymentAmountRaw = document.getElementById('paymentAmountRaw');
    setupSalaryFormatting(paymentAmount, paymentAmountRaw);

    // Setup allowance amount formatting
    const allowanceAmount = document.getElementById('allowanceAmount');
    const allowanceAmountRaw = document.getElementById('allowanceAmountRaw');
    setupSalaryFormatting(allowanceAmount, allowanceAmountRaw);

    // Deduction type change handler with hidden input
    const deductionType = document.getElementById('deductionType');
    const loanFields = document.getElementById('loanFields');
    const governmentFields = document.getElementById('governmentFields');
    const frequencyField = document.getElementById('deductionFrequencyField');
    const freqSelect = document.getElementById('deductionFrequency');

    if (deductionType) {
        deductionType.addEventListener('change', function() {
            const type = this.value;

            // Reset UI
            if (loanFields) loanFields.style.display = 'none';
            if (governmentFields) governmentFields.style.display = 'none';
            if (frequencyField) frequencyField.style.display = 'block';
            if (freqSelect) {
                freqSelect.disabled = false;
                freqSelect.style.backgroundColor = '';
                freqSelect.style.cursor = '';
            }
            const existingHidden = document.getElementById('hiddenFrequency');
            if (existingHidden) existingHidden.remove();

            // Loan / Cash Advance / SSS Loan / PAG-IBIG Loan
            if (type === 'loan_payment' || type === 'cash_advance' || type === 'sss_loan' || type === 'pagibig_loan') {
                if (loanFields) loanFields.style.display = 'flex';
                if (frequencyField) frequencyField.style.display = 'block';
                if (freqSelect) {
                    freqSelect.value = 'one_time';
                    freqSelect.disabled = true;
                    freqSelect.style.backgroundColor = '#e9ecef';
                    freqSelect.style.cursor = 'not-allowed';
                    let hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.id = 'hiddenFrequency';
                    hidden.name = 'frequency';
                    hidden.value = 'one_time';
                    freqSelect.parentNode.appendChild(hidden);
                }
            }
            // Government contributions
            else if (type === 'sss' || type === 'pagibig' || type === 'philhealth') {
                if (governmentFields) governmentFields.style.display = 'block';
                if (freqSelect) {
                    freqSelect.value = 'monthly';
                    freqSelect.disabled = true;
                    freqSelect.style.backgroundColor = '#e9ecef';
                    freqSelect.style.cursor = 'not-allowed';
                    let hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.id = 'hiddenFrequency';
                    hidden.name = 'frequency';
                    hidden.value = 'monthly';
                    freqSelect.parentNode.appendChild(hidden);
                }
                if (frequencyField) frequencyField.style.display = 'none';
            }
            // All other types
            else {
                if (freqSelect) {
                    freqSelect.disabled = false;
                    freqSelect.style.backgroundColor = '';
                    freqSelect.style.cursor = '';
                }
            }
        });
    }

    // Payment period change handler
    const paymentPeriod = document.getElementById('paymentPeriod');
    const customPeriod = document.getElementById('customPeriod');
    if (paymentPeriod) {
        paymentPeriod.addEventListener('change', function() {
            if (this.value === 'custom') {
                customPeriod.style.display = 'block';
            } else {
                customPeriod.style.display = 'none';
            }
        });
    }

    // ===== ALLOWANCE FUNCTIONS =====
    function loadActiveAllowances(employeeId, employeeType) {
        const activeAllowancesBody = document.getElementById('activeAllowancesBody');
        activeAllowancesBody.innerHTML = '<tr><td colspan="6" class="text-center"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>';

        fetch('api/employee-endpoint.php?action=active-allowances&employee_id=' + employeeId + '&employee_type=' + employeeType)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.allowances.length > 0) {
                        let html = '';
                        for (const allowance of data.allowances) {
                            let typeClass = '';
                            if (allowance.allowance_type === 'Rice Allowance') {
                                typeClass = 'allowance-rice';
                            } else if (allowance.allowance_type === 'COLA') {
                                typeClass = 'allowance-cola';
                            } else if (allowance.allowance_type === 'Motor Rental') {
                                typeClass = 'allowance-motor';
                            } else if (allowance.allowance_type === 'Incentive') {
                                typeClass = 'allowance-incentive';
                            }

                            html += '<tr>';
                            html += '<td><span class="badge ' + typeClass + '">' + allowance.allowance_type + '</span></td>';
                            html += '<td>₱' + parseFloat(allowance.amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '</td>';
                            html += '<td>' + (allowance.effective_date || 'N/A') + '</td>';
                            html += '<td>' + (allowance.end_date || 'N/A') + '</td>';
                            html += '<td><span class="badge bg-success">Active</span></td>';
                            html += '<td>';
                            html += '<button class="btn btn-sm btn-danger deactivate-allowance-btn" data-id="' + allowance.id + '"><i class="fas fa-ban"></i></button>';
                            html += '</td>';
                            html += '</tr>';
                        }
                        activeAllowancesBody.innerHTML = html;
                    } else {
                        activeAllowancesBody.innerHTML = '<tr><td colspan="6" class="text-center">No active allowances</td></tr>';
                    }
                } else {
                    throw new Error(data.message || 'Failed to load allowances');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                activeAllowancesBody.innerHTML = '<tr><td colspan="6" class="text-center text-danger">Error loading allowances</td></tr>';
            });
    }

    // Function to load allowance history
    function loadAllowanceHistory(employeeId, employeeType, employeeName) {
        document.getElementById('allowanceHistoryModalLabel').textContent = 'Allowance History - ' + employeeName;
        document.getElementById('allowanceHistoryContent').innerHTML = `
            <div class="text-center">
                <div class="spinner-border" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-2">Loading allowance history...</p>
            </div>
        `;

        fetch('api/get_allowance_history.php?employee_id=' + employeeId + '&employee_type=' + employeeType)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    let html = '';
                    if (data.allowances.length > 0) {
                        html = '<div class="table-responsive"><table class="table table-striped table-bordered allowance-history-table">';
                        html += '<thead><tr><th>Type</th><th>Amount</th><th>Frequency</th><th>Effective Date</th><th>End Date</th><th>Status</th><th>Created By</th></tr></thead><tbody>';

                        for (const allowance of data.allowances) {
                            let typeClass = '';
                            if (allowance.allowance_type === 'Rice Allowance') {
                                typeClass = 'allowance-rice';
                            } else if (allowance.allowance_type === 'COLA') {
                                typeClass = 'allowance-cola';
                            } else if (allowance.allowance_type === 'Motor Rental') {
                                typeClass = 'allowance-motor';
                            } else if (allowance.allowance_type === 'Incentive') {
                                typeClass = 'allowance-incentive';
                            }

                            const createdByName = allowance.first_name && allowance.last_name 
                                ? allowance.first_name + ' ' + allowance.last_name 
                                : 'N/A';

                            html += '<tr>';
                            html += '<td><span class="badge ' + typeClass + '">' + allowance.allowance_type + '</span></td>';
                            html += '<td class="allowance-amount">₱' + parseFloat(allowance.amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '</td>';
                            html += '<td>' + (allowance.frequency ? allowance.frequency.replace('_', ' ') : 'N/A') + '</td>';
                            html += '<td>' + (allowance.effective_date || 'N/A') + '</td>';
                            html += '<td>' + (allowance.end_date || 'N/A') + '</td>';
                            html += '<td>' + (allowance.is_active ?
                                '<span class="badge bg-success">Active</span>' :
                                '<span class="badge bg-danger">Inactive</span>') + '</td>';
                            html += '<td>' + createdByName + '</td>';
                            html += '</tr>';
                        }

                        html += '</tbody></table></div>';
                    } else {
                        html = '<div class="alert alert-info">No allowance history found for this employee.</div>';
                    }
                    document.getElementById('allowanceHistoryContent').innerHTML = html;
                } else {
                    throw new Error(data.message || 'Failed to load allowance history');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('allowanceHistoryContent').innerHTML = `
                    <div class="alert alert-danger" role="alert">
                        Error loading allowance history: ${error.message}
                    </div>
                `;
            });

        allowanceHistoryModal.show();
    }

    // Event delegation for allowance button
    document.addEventListener('click', function(e) {
        if (e.target.closest('.manage-allowances-btn')) {
            e.preventDefault();
            const btn = e.target.closest('.manage-allowances-btn');
            const employeeId = btn.getAttribute('data-id');
            const employeeType = btn.getAttribute('data-type');
            const employeeName = btn.getAttribute('data-name');
            const employeeSalary = btn.getAttribute('data-salary');

            document.getElementById('allowanceEmployeeId').value = employeeId;
            document.getElementById('allowanceEmployeeType').value = employeeType;
            document.getElementById('allowanceEmployeeName').textContent = employeeName;

            const salaryNum = parseFloat(employeeSalary);
            if (!isNaN(salaryNum) && salaryNum > 0) {
                document.getElementById('allowanceEmployeeSalary').textContent = formatNumberWithCommas(salaryNum.toFixed(2));
            } else {
                document.getElementById('allowanceEmployeeSalary').textContent = '0.00';
            }

            document.getElementById('allowanceForm').reset();
            loadActiveAllowances(employeeId, employeeType);

            allowanceModal.show();
        }

        // View Allowance History button click
        if (e.target.closest('.view-allowance-history-btn')) {
            e.preventDefault();
            const btn = e.target.closest('.view-allowance-history-btn');
            const employeeId = btn.getAttribute('data-id');
            const employeeType = btn.getAttribute('data-type');
            const employeeName = btn.getAttribute('data-name');

            loadAllowanceHistory(employeeId, employeeType, employeeName);
        }

        // Deactivate allowance button click
        if (e.target.closest('.deactivate-allowance-btn')) {
            const btn = e.target.closest('.deactivate-allowance-btn');
            const allowanceId = btn.getAttribute('data-id');

            Swal.fire({
                title: 'Deactivate Allowance?',
                text: 'Are you sure you want to deactivate this allowance?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, deactivate it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch('api/deactivate_allowance.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'allowance_id=' + allowanceId
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Deactivated!',
                                text: 'Allowance has been deactivated.',
                                timer: 2000,
                                showConfirmButton: false
                            }).then(() => {
                                const employeeId = document.getElementById('allowanceEmployeeId').value;
                                const employeeType = document.getElementById('allowanceEmployeeType').value;
                                loadActiveAllowances(employeeId, employeeType);
                            });
                        } else {
                            throw new Error(data.message || 'Failed to deactivate allowance');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        Swal.fire({
                            icon: 'error',
                            title: 'Error!',
                            text: 'Error deactivating allowance: ' + error.message
                        });
                    });
                }
            });
        }
    });

    // Allowance Form Submit
    const allowanceForm = document.getElementById('allowanceForm');
    if (allowanceForm) {
        allowanceForm.addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalBtnContent = submitBtn.innerHTML;

            const rawAmount = document.getElementById('allowanceAmountRaw').value;

            if (!rawAmount || parseFloat(rawAmount) <= 0) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Please enter a valid amount greater than 0'
                });
                return;
            }

            formData.set('employee_id', document.getElementById('allowanceEmployeeId').value);
            formData.set('employee_type', document.getElementById('allowanceEmployeeType').value);
            formData.set('amount', rawAmount);

            const effectiveDate = formData.get('effective_date');
            if (!effectiveDate) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Please select an effective date'
                });
                return;
            }

            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';
            submitBtn.disabled = true;

            fetch('api/add_allowance.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: data.message || 'Allowance added successfully!',
                        timer: 2000,
                        showConfirmButton: true
                    }).then(() => {
                        allowanceForm.reset();
                        const employeeId = document.getElementById('allowanceEmployeeId').value;
                        const employeeType = document.getElementById('allowanceEmployeeType').value;
                        loadActiveAllowances(employeeId, employeeType);
                    });
                } else {
                    throw new Error(data.message || 'Failed to add allowance');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Error adding allowance: ' + error.message
                });
            })
            .finally(() => {
                submitBtn.innerHTML = originalBtnContent;
                submitBtn.disabled = false;
            });
        });
    }

    // ===== SALARY & DEDUCTION EVENT DELEGATION =====
    document.addEventListener('click', function(e) {
        // Manage Salary button click
        if (e.target.closest('.manage-salary-btn')) {
            e.preventDefault();
            const btn = e.target.closest('.manage-salary-btn');
            const accountId = btn.getAttribute('data-id');
            const accountType = btn.getAttribute('data-type');
            const accountName = btn.getAttribute('data-name');
            const currentSalary = btn.getAttribute('data-salary');

            document.getElementById('salaryAccountId').value = accountId;
            document.getElementById('salaryAccountType').value = accountType;
            document.getElementById('employeeName').value = accountName;

            const currentSalaryNum = parseFloat(currentSalary);
            if (!isNaN(currentSalaryNum) && currentSalaryNum > 0) {
                document.getElementById('currentSalary').value = formatNumberWithCommas(currentSalaryNum.toFixed(2));
            } else {
                document.getElementById('currentSalary').value = '0.00';
            }

            if (newSalaryInput) {
                newSalaryInput.value = '';
            }
            if (newSalaryRaw) {
                newSalaryRaw.value = '';
            }

            salaryModal.show();
        }

        // View Salary History button click
        if (e.target.closest('.view-salary-history-btn')) {
            e.preventDefault();
            const btn = e.target.closest('.view-salary-history-btn');
            const accountId = btn.getAttribute('data-id');
            const accountType = btn.getAttribute('data-type');
            const accountName = btn.getAttribute('data-name');

            document.getElementById('salaryHistoryModalLabel').textContent = 'Daily Salary History - ' + accountName;
            document.getElementById('salaryHistoryContent').innerHTML = `
                <div class="text-center">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading salary history...</p>
                </div>
            `;

            fetch('api/get_salary_history.php?account_id=' + accountId + '&account_type=' + accountType)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let html = '';
                        if (data.history.length > 0) {
                            html = '<div class="table-responsive"><table class="table table-striped table-bordered salary-history-table">';
                            html += '<thead><tr><th>Effective Date</th><th>Daily Salary</th><th>Change</th><th>Updated By</th><th>Notes</th></tr></thead><tbody>';

                            for (let i = 0; i < data.history.length; i++) {
                                const item = data.history[i];
                                const prevSalary = i < data.history.length - 1 ? data.history[i + 1].salary : 0;
                                const change = item.salary - prevSalary;
                                const changeClass = change > 0 ? 'salary-increase' : (change < 0 ? 'salary-decrease' : '');
                                const changeIcon = change > 0 ? '↑' : (change < 0 ? '↓' : '');

                                const formattedSalary = parseFloat(item.salary).toLocaleString('en-US', {
                                    minimumFractionDigits: 2,
                                    maximumFractionDigits: 2
                                });

                                let formattedChange = '';
                                if (change !== 0) {
                                    formattedChange = changeIcon + ' ₱' + Math.abs(change).toLocaleString('en-US', {
                                        minimumFractionDigits: 2,
                                        maximumFractionDigits: 2
                                    });
                                } else {
                                    formattedChange = '-';
                                }

                                html += '<tr>';
                                html += '<td>' + item.effective_date + '</td>';
                                html += '<td class="salary-amount">₱' + formattedSalary + '</td>';
                                html += '<td class="' + changeClass + '">' + formattedChange + '</td>';
                                html += '<td>' + (item.updated_by_name || 'N/A') + '</td>';
                                html += '<td>' + (item.note || '-') + '</td>';
                                html += '</tr>';
                            }

                            html += '</tbody></table></div>';
                        } else {
                            html = '<div class="alert alert-info">No salary history found for this employee.</div>';
                        }
                        document.getElementById('salaryHistoryContent').innerHTML = html;
                    } else {
                        throw new Error(data.message || 'Failed to load salary history');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('salaryHistoryContent').innerHTML = `
                        <div class="alert alert-danger" role="alert">
                            Error loading salary history: ${error.message}
                        </div>
                    `;
                });

            salaryHistoryModal.show();
        }

        // Manage Deductions button click
        if (e.target.closest('.manage-deductions-btn')) {
            e.preventDefault();
            const btn = e.target.closest('.manage-deductions-btn');
            const employeeId = btn.getAttribute('data-id');
            const employeeType = btn.getAttribute('data-type');
            const employeeName = btn.getAttribute('data-name');
            const employeeSalary = btn.getAttribute('data-salary');

            document.getElementById('deductionEmployeeId').value = employeeId;
            document.getElementById('deductionEmployeeType').value = employeeType;
            document.getElementById('deductionEmployeeName').textContent = employeeName;

            const salaryNum = parseFloat(employeeSalary);
            if (!isNaN(salaryNum) && salaryNum > 0) {
                document.getElementById('deductionEmployeeSalary').textContent = formatNumberWithCommas(salaryNum.toFixed(2));
            } else {
                document.getElementById('deductionEmployeeSalary').textContent = '0.00';
            }

            document.getElementById('deductionForm').reset();
            if (loanFields) loanFields.style.display = 'none';
            if (governmentFields) governmentFields.style.display = 'none';

            loadActiveDeductions(employeeId, employeeType);
            loadDeductionSummary(employeeId, employeeType);

            deductionModal.show();
        }

        // View Deductions History button click - UPDATED VERSION
        if (e.target.closest('.view-deductions-history-btn')) {
            e.preventDefault();
            const btn = e.target.closest('.view-deductions-history-btn');
            const employeeId = btn.getAttribute('data-id');
            const employeeType = btn.getAttribute('data-type');
            const employeeName = btn.getAttribute('data-name');

            document.getElementById('deductionHistoryModalLabel').textContent = 'Deduction History - ' + employeeName;
            document.getElementById('deductionHistoryContent').innerHTML = `
                <div class="text-center">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading deduction history...</p>
                </div>
            `;

            fetch('api/get_deductions_history.php?employee_id=' + employeeId + '&employee_type=' + employeeType)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let html = '';
                        if (data.deductions.length > 0) {
                            html = '<div class="table-responsive"><table class="table table-striped table-bordered deduction-history-table">';
                            html += '<thead><tr><th>Type</th><th>Amount</th><th>Frequency</th><th>Period</th><th>Status</th></tr></thead><tbody>';

                            for (const deduction of data.deductions) {
                                const typeClass = deduction.deduction_type === 'loan_payment' ? 'deduction-loan-color' :
                                                (deduction.deduction_type === 'cash_advance' ? 'deduction-cashadvance-color' :
                                                (deduction.deduction_type === 'sss' ? 'deduction-sss-color' :
                                                (deduction.deduction_type === 'pagibig' ? 'deduction-pagibig-color' :
                                                (deduction.deduction_type === 'philhealth' ? 'deduction-philhealth-color' :
                                                (deduction.deduction_type === 'sss_loan' ? 'deduction-sss-loan-color' : 'deduction-pagibig-loan-color')))));

                                let periodText = '';
                                if (deduction.start_date && deduction.end_date) {
                                    periodText = `${deduction.start_date} to ${deduction.end_date}`;
                                } else if (deduction.effective_date) {
                                    periodText = `Effective: ${deduction.effective_date}`;
                                } else {
                                    periodText = 'N/A';
                                }

                                let displayType = deduction.deduction_type.replace('_', ' ').toUpperCase();
                                if (displayType === 'PAGIBIG') {
                                    displayType = 'PAG-IBIG';
                                } else if (displayType === 'PAGIBIG LOAN') {
                                    displayType = 'PAG-IBIG LOAN';
                                }

                                let frequencyClass = '';
                                let frequencyDisplay = '';
                                if (deduction.frequency === 'monthly') {
                                    frequencyClass = 'frequency-monthly';
                                    frequencyDisplay = 'Monthly';
                                } else if (deduction.frequency === 'one_time') {
                                    frequencyClass = 'frequency-onetime';
                                    frequencyDisplay = 'One Time';
                                } else if (deduction.frequency === 'semi_monthly') {
                                    frequencyClass = 'frequency-semi-monthly';
                                    frequencyDisplay = 'Semi-Monthly';
                                } else {
                                    frequencyDisplay = deduction.frequency.replace('_', ' ');
                                }

                                html += '<tr>';
                                html += '<td class="' + typeClass + '"><strong>' + displayType + '</strong></td>';
                                html += '<td class="deduction-amount">₱' + parseFloat(deduction.amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '</td>';
                                html += '<td><span class="badge ' + frequencyClass + '">' + frequencyDisplay + '</span></td>';
                                html += '<td>' + periodText + '</td>';
                                html += '<td>' + (deduction.is_active ?
                                    '<span class="badge bg-success">Active</span>' :
                                    '<span class="badge bg-danger">Inactive</span>') + '</td>';
                                html += '</tr>';
                            }

                            html += '</tbody></table></div>';
                        } else {
                            html = '<div class="alert alert-info">No deduction history found for this employee.</div>';
                        }
                        document.getElementById('deductionHistoryContent').innerHTML = html;
                    } else {
                        throw new Error(data.message || 'Failed to load deduction history');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('deductionHistoryContent').innerHTML = `
                        <div class="alert alert-danger" role="alert">
                            Error loading deduction history: ${error.message}
                        </div>
                    `;
                });

            deductionHistoryModal.show();
        }

        // Image click for full view
        if (e.target.closest('.profile-img, .no-image-icon')) {
            const collectorImage = e.target.closest('.profile-img, .no-image-icon');
            const row = collectorImage.closest('tr');
            const collectorName = collectorImage.getAttribute('data-collector-name') || (row ? row.querySelector('td:nth-child(2)')?.textContent.trim() : '');

            document.getElementById('imageViewModalLabel').textContent = collectorName + "'s Profile";

            let imageSrc = '';
            if (collectorImage.classList.contains('profile-img')) {
                imageSrc = collectorImage.getAttribute('src');
            } else {
                imageSrc = 'data:image/svg+xml;charset=UTF-8,%3Csvg xmlns="http://www.w3.org/2000/svg" width="400" height="300" viewBox="0 0 400 300" fill="%23f8f9fa"%3E%3Crect width="400" height="300"/%3E%3Ctext x="200" y="150" font-family="Arial" font-size="20" text-anchor="middle" fill="%236c757d"%3ENo Image Available%3C/text%3E%3C/svg%3E';
            }

            const fullSizeImg = document.getElementById('fullSizeImage');
            fullSizeImg.src = imageSrc;
            fullSizeImg.alt = collectorName + "'s Profile Image";

            imageViewModal.show();
        }

        // Edit Collector button click
        if (e.target.closest('.edit-btn')) {
            const editBtn = e.target.closest('.edit-btn');
            const collectorId = editBtn.getAttribute('data-id');

            fetch('api/get_collector_details.php?id=' + collectorId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('editCollectorId').value = data.collector.id;
                        document.getElementById('editAccountSource').value = 'collector';
                        document.getElementById('editAccountTypeHidden').value = data.collector.account_type;
                        document.getElementById('editEmployeeNumber').value = data.collector.employee_number || '';
                        document.getElementById('editUsername').value = data.collector.username;
                        document.getElementById('editLastName').value = data.collector.last_name;
                        document.getElementById('editFirstName').value = data.collector.first_name;
                        document.getElementById('editMiddleName').value = data.collector.middle_name;
                        document.getElementById('editSuffix').value = data.collector.suffix;
                        document.getElementById('editBirthDate').value = data.collector.birth_date;
                        document.getElementById('editPlaceOfBirth').value = data.collector.place_of_birth;
                        document.getElementById('editStatus').value = data.collector.status;
                        document.getElementById('editGender').value = data.collector.gender;
                        document.getElementById('editSpouseName').value = data.collector.spouse_name;
                        document.getElementById('editAddress').value = data.collector.address;
                        document.getElementById('editContactNumber').value = data.collector.contact_number;
                        document.getElementById('editArea').value = data.collector.area;
                        document.getElementById('editBranch').value = data.collector.branch;
                        document.getElementById('editAccountType').value = data.collector.account_type;
                        document.getElementById('editAccountStatus').value = data.collector.account_status;

                        const profileImagePreview = document.getElementById('editProfileImagePreview');
                        if (data.collector.profile_image) {
                            profileImagePreview.src = data.collector.profile_image;
                            profileImagePreview.classList.remove('d-none');
                            document.getElementById('editCurrentProfileImage').value = data.collector.profile_image;
                        } else {
                            profileImagePreview.src = 'assets/img/default-profile.png';
                            profileImagePreview.classList.remove('d-none');
                        }

                        editCollectorModal.show();
                    } else {
                        throw new Error(data.message || 'Failed to load collector details');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Error loading collector details: ' + error.message
                    });
                });
        }

        // View Collector Details button click
        if (e.target.closest('.view-details-btn')) {
            const viewBtn = e.target.closest('.view-details-btn');
            const collectorId = viewBtn.getAttribute('data-id');

            document.getElementById('collectorDetails').innerHTML = `
                <div class="text-center">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading collector details...</p>
                </div>
            `;

            fetch('api/get_collector_details_view.php?id=' + collectorId)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('collectorDetails').innerHTML = data;
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('collectorDetails').innerHTML = `
                        <div class="alert alert-danger" role="alert">
                            Error loading collector details. Please try again.
                        </div>
                    `;
                });

            viewCollectorModal.show();
        }

        // Edit Proxy button click
        if (e.target.closest('.edit-proxy-btn')) {
            const editBtn = e.target.closest('.edit-proxy-btn');
            const proxyId = editBtn.getAttribute('data-id');

            fetch('api/get_proxy_details.php?id=' + proxyId + '&format=json')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('editProxyId').value = data.proxy.id;
                        document.getElementById('editProxyAccountSource').value = 'proxy';
                        document.getElementById('editProxyEmployeeNumber').value = data.proxy.employee_number || '';
                        document.getElementById('editProxyUsername').value = data.proxy.username;
                        document.getElementById('editProxyLastName').value = data.proxy.last_name;
                        document.getElementById('editProxyFirstName').value = data.proxy.first_name;
                        document.getElementById('editProxyMiddleName').value = data.proxy.middle_name;
                        document.getElementById('editProxySuffix').value = data.proxy.suffix;
                        document.getElementById('editProxyBirthDate').value = data.proxy.birth_date;
                        document.getElementById('editProxyPlaceOfBirth').value = data.proxy.place_of_birth;
                        document.getElementById('editProxyStatus').value = data.proxy.status;
                        document.getElementById('editProxyGender').value = data.proxy.gender;
                        document.getElementById('editProxySpouseName').value = data.proxy.spouse_name;
                        document.getElementById('editProxyAddress').value = data.proxy.address;
                        document.getElementById('editProxyContactNumber').value = data.proxy.contact_number;
                        document.getElementById('editProxyArea').value = data.proxy.area;
                        document.getElementById('editProxyBranch').value = data.proxy.branch;
                        document.getElementById('editProxyAccountType').value = data.proxy.account_type;
                        document.getElementById('editProxyAccountStatus').value = data.proxy.account_status;

                        const profileImagePreview = document.getElementById('editProxyProfileImagePreview');
                        if (data.proxy.profile_image) {
                            profileImagePreview.src = data.proxy.profile_image;
                            profileImagePreview.classList.remove('d-none');
                            document.getElementById('editProxyCurrentProfileImage').value = data.proxy.profile_image;
                        } else {
                            profileImagePreview.src = 'assets/img/default-profile.png';
                            profileImagePreview.classList.remove('d-none');
                        }

                        editProxyModal.show();
                    } else {
                        throw new Error(data.message || 'Failed to load proxy details');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Error loading proxy details: ' + error.message
                    });
                });
        }

        // View Collections button click
        if (e.target.closest('.view-collections-btn')) {
            const viewBtn = e.target.closest('.view-collections-btn');
            const collectorId = viewBtn.getAttribute('data-id');
            window.location.href = 'collector_collections_record.php?id=' + collectorId;
        }

        // View Customers button click
        if (e.target.closest('.view-customers-btn')) {
            const viewBtn = e.target.closest('.view-customers-btn');
            const area = viewBtn.getAttribute('data-area');
            const branch = viewBtn.getAttribute('data-branch');

            document.getElementById('areaTitle').textContent = area + ' - ' + branch;

            const customersListBody = document.getElementById('customersListBody');
            customersListBody.innerHTML = '<tr><td colspan="6" class="text-center"><i class="fas fa-spinner fa-spin"></i> Loading customers...</td></tr>';

            viewCustomersModal.show();

            fetch('api/get_customers_by_area.php?area=' + encodeURIComponent(area) + '&branch=' + encodeURIComponent(branch))
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        customersListBody.innerHTML = '';
                        if (data.customers.length > 0) {
                            data.customers.forEach((customer, index) => {
                                const row = document.createElement('tr');
                                row.innerHTML = `
                                    <td>${index + 1}</td>
                                    <td>${customer.formatted_name}</td>
                                    <td>${customer.contact_number || 'N/A'}</td>
                                    <td>${customer.area || 'N/A'}</td>
                                    <td>${customer.branch || 'N/A'}</td>
                                    <td>${customer.status || 'N/A'}</td>
                                `;
                                customersListBody.appendChild(row);
                            });
                        } else {
                            customersListBody.innerHTML = '<tr><td colspan="6" class="text-center">No customers found in this area and branch</td></tr>';
                        }
                    } else {
                        throw new Error(data.message || 'Failed to load customers');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    customersListBody.innerHTML = '<tr><td colspan="6" class="text-center text-danger">Error loading customers: ' + error.message + '</td></tr>';
                });
        }

        // View Proxy button click
        if (e.target.closest('.view-proxy-btn')) {
            const viewBtn = e.target.closest('.view-proxy-btn');
            const proxyId = viewBtn.getAttribute('data-id');

            document.getElementById('proxyDetails').innerHTML = `
                <div class="text-center">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading proxy details...</p>
                </div>
            `;

            fetch('api/get_proxy_details.php?id=' + proxyId)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('proxyDetails').innerHTML = data;
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('proxyDetails').innerHTML = `
                        <div class="alert alert-danger" role="alert">
                            Error loading proxy details. Please try again.
                        </div>
                    `;
                });

            viewProxyModal.show();
        }

        // View Account button click
        if (e.target.closest('.view-account')) {
            const viewBtn = e.target.closest('.view-account');
            const accountId = viewBtn.getAttribute('data-id');

            document.getElementById('accountDetails').innerHTML = `
                <div class="text-center">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading account details...</p>
                </div>
            `;

            fetch('api/get_account_details.php?id=' + accountId)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('accountDetails').innerHTML = data;
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('accountDetails').innerHTML = `
                        <div class="alert alert-danger" role="alert">
                            Error loading account details. Please try again.
                        </div>
                    `;
                });

            viewAccountModal.show();
        }

        // Edit Account button click
        if (e.target.closest('.edit-account')) {
            const editBtn = e.target.closest('.edit-account');
            const accountId = editBtn.getAttribute('data-id');

            document.getElementById('editAccountForm').innerHTML = `
                <div class="text-center">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading edit form...</p>
                </div>
            `;

            fetch('api/edit_account_form.php?id=' + accountId)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('editAccountForm').innerHTML = data;
                    initializeEditForm();
                })
                .catch(error => {
                    console.error('Error loading edit form:', error);
                    document.getElementById('editAccountForm').innerHTML = `
                        <div class="alert alert-danger" role="alert">
                            Error loading edit form. Please try again.
                            <br>Error: ${error.message}
                        </div>
                    `;
                });

            editAccountModal.show();
        }

        // Delete Account button click
        if (e.target.closest('.delete-account-btn')) {
            e.preventDefault();

            const btn = e.target.closest('.delete-account-btn');
            const accountId = btn.getAttribute('data-id');
            const accountName = btn.getAttribute('data-name');

            Swal.fire({
                title: 'Delete Account?',
                text: `Are you sure you want to delete the account for ${accountName}? This action cannot be undone.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel',
                showLoaderOnConfirm: true,
                preConfirm: () => {
                    return fetch('api/delete_account.php?id=' + accountId)
                        .then(response => response.json())
                        .then(data => {
                            if (!data.success) {
                                throw new Error(data.message || 'Failed to delete account');
                            }
                            return data;
                        })
                        .catch(error => {
                            Swal.showValidationMessage(`Request failed: ${error.message}`);
                        });
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Deleted!',
                        text: `Account for ${accountName} has been deleted successfully.`,
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                }
            });
        }

        // Delete Collector button click
        if (e.target.closest('.delete-btn')) {
            const deleteBtn = e.target.closest('.delete-btn');
            const collectorId = deleteBtn.getAttribute('data-id');
            const row = deleteBtn.closest('tr');
            const collectorName = row ? row.querySelector('td:nth-child(2)')?.textContent : '';

            Swal.fire({
                title: 'Are you sure?',
                text: `You are about to delete collector: ${collectorName}. This action cannot be undone.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    deleteCollector(collectorId, row);
                }
            });
        }

        // Delete Proxy button click
        if (e.target.closest('.delete-proxy-btn')) {
            const deleteBtn = e.target.closest('.delete-proxy-btn');
            const proxyId = deleteBtn.getAttribute('data-id');
            const row = deleteBtn.closest('tr');
            const proxyName = row ? row.querySelector('td:nth-child(2)')?.textContent : '';

            Swal.fire({
                title: 'Are you sure?',
                text: `You are about to delete proxy: ${proxyName}. This action cannot be undone.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    deleteProxy(proxyId, row);
                }
            });
        }
    });

    // Function to load active deductions
    function loadActiveDeductions(employeeId, employeeType) {
        const activeDeductionsBody = document.getElementById('activeDeductionsBody');
        activeDeductionsBody.innerHTML = '<tr><td colspan="6" class="text-center"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>';

        fetch('api/employee-endpoint.php?action=active-deductions&employee_id=' + employeeId + '&employee_type=' + employeeType)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.deductions.length > 0) {
                        let html = '';
                        for (const deduction of data.deductions) {
                            let typeClass = '';
                            if (deduction.deduction_type === 'loan_payment') {
                                typeClass = 'deduction-loan';
                            } else if (deduction.deduction_type === 'cash_advance') {
                                typeClass = 'deduction-cashadvance';
                            } else if (deduction.deduction_type === 'sss') {
                                typeClass = 'deduction-sss';
                            } else if (deduction.deduction_type === 'pagibig') {
                                typeClass = 'deduction-pagibig';
                            } else if (deduction.deduction_type === 'philhealth') {
                                typeClass = 'deduction-philhealth';
                            } else if (deduction.deduction_type === 'sss_loan') {
                                typeClass = 'deduction-sss-loan';
                            } else if (deduction.deduction_type === 'pagibig_loan') {
                                typeClass = 'deduction-pagibig-loan';
                            }

                            let periodText = '';
                            if (deduction.start_date && deduction.end_date) {
                                periodText = `${deduction.start_date} to ${deduction.end_date}`;
                            } else if (deduction.effective_date) {
                                periodText = `Effective: ${deduction.effective_date}`;
                            } else {
                                periodText = 'N/A';
                            }

                            let displayType = deduction.deduction_type.replace('_', ' ').toUpperCase();
                            if (displayType === 'PAGIBIG') {
                                displayType = 'PAG-IBIG';
                            } else if (displayType === 'PAGIBIG LOAN') {
                                displayType = 'PAG-IBIG LOAN';
                            }

                            html += '<tr>';
                            html += '<td><span class="badge ' + typeClass + '">' + displayType + '</span></td>';
                            html += '<td>₱' + parseFloat(deduction.amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '</td>';
                            html += '<td>' + deduction.frequency.replace('_', ' ') + '</td>';
                            html += '<td>' + periodText + '</td>';
                            html += '<td><span class="badge bg-success">Active</span></td>';
                            html += '<td>';
                            html += '<button class="btn btn-sm btn-danger deactivate-deduction-btn" data-id="' + deduction.id + '" data-employee-id="' + employeeId + '" data-employee-type="' + employeeType + '" title="Deactivate Deduction" aria-label="Deactivate Deduction"><i class="fas fa-ban"></i></button>';
                            html += '</td>';
                            html += '</tr>';
                        }
                        activeDeductionsBody.innerHTML = html;
                    } else {
                        activeDeductionsBody.innerHTML = '<tr><td colspan="6" class="text-center">No active deductions</td></tr>';
                    }
                } else {
                    throw new Error(data.message || 'Failed to load deductions');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                activeDeductionsBody.innerHTML = '<tr><td colspan="6" class="text-center text-danger">Error loading deductions</td></tr>';
            });
    }

    // Function to load deduction summary
    function loadDeductionSummary(employeeId, employeeType) {
        fetch('api/employee-endpoint.php?action=deduction-summary&employee_id=' + employeeId + '&employee_type=' + employeeType)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('summarySSS').textContent = '₱' + parseFloat(data.summary.sss || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                    document.getElementById('summaryPagIBIG').textContent = '₱' + parseFloat(data.summary.pagibig || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                    document.getElementById('summaryPhilHealth').textContent = '₱' + parseFloat(data.summary.philhealth || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                    document.getElementById('summarySSSLoan').textContent = '₱' + parseFloat(data.summary.sss_loan || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                    document.getElementById('summaryPagIBIGLoan').textContent = '₱' + parseFloat(data.summary.pagibig_loan || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                    document.getElementById('summaryLoan').textContent = '₱' + parseFloat(data.summary.loan_payment || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                    document.getElementById('summaryCashAdvance').textContent = '₱' + parseFloat(data.summary.cash_advance || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

                    const total = data.summary.total || 0;
                    document.getElementById('summaryTotal').textContent = '₱' + parseFloat(total).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                }
            })
            .catch(error => {
                console.error('Error loading deduction summary:', error);
            });
    }

    // Salary Form Submit
    const salaryForm = document.getElementById('salaryForm');
    if (salaryForm) {
        salaryForm.addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalBtnContent = submitBtn.innerHTML;

            const rawSalary = document.getElementById('newSalaryRaw').value;
            let effectiveDate = formData.get('effective_date');

            if (!effectiveDate) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Please select an effective date'
                });
                return;
            }

            const datePattern = /^\d{4}-\d{2}-\d{2}$/;
            if (!datePattern.test(effectiveDate)) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Invalid date format. Please use YYYY-MM-DD format'
                });
                return;
            }

            const dateParts = effectiveDate.split('-');
            const year = parseInt(dateParts[0]);
            const month = parseInt(dateParts[1]);
            const day = parseInt(dateParts[2]);

            if (month < 1 || month > 12) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Invalid month. Month must be between 01 and 12'
                });
                return;
            }

            if (day < 1 || day > 31) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Invalid day. Day must be between 01 and 31'
                });
                return;
            }

            const date = new Date(year, month - 1, day);
            if (date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Invalid date: Please select a valid calendar date'
                });
                return;
            }

            if (!rawSalary || parseFloat(rawSalary) <= 0) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Please enter a valid daily salary amount greater than 0'
                });
                return;
            }

            formData.set('salary', rawSalary);
            if (formData.has('salary_display')) {
                formData.delete('salary_display');
            }

            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
            submitBtn.disabled = true;

            fetch('api/update_salary.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    let message = data.message;
                    if (data.action === 'updated') {
                        message = 'Daily salary updated successfully! Previous entry for this date was overwritten.';
                    } else if (data.action === 'inserted') {
                        message = 'New daily salary record created successfully!';
                    }

                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: message,
                        timer: 3000,
                        showConfirmButton: true
                    }).then(() => {
                        const salaryModalEl = document.getElementById('salaryModal');
                        const salaryModal = bootstrap.Modal.getInstance(salaryModalEl);
                        if (salaryModal) {
                            salaryModal.hide();
                        }
                        location.reload();
                    });
                } else {
                    throw new Error(data.message || 'Failed to update salary');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Error updating daily salary: ' + error.message
                });
            })
            .finally(() => {
                submitBtn.innerHTML = originalBtnContent;
                submitBtn.disabled = false;
            });
        });
    }

    // Deduction Form Submit (Improved Version)
    const deductionForm = document.getElementById('deductionForm');
    if (deductionForm) {
        deductionForm.addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalBtnContent = submitBtn.innerHTML;

            const rawAmount = document.getElementById('deductionAmountRaw').value;

            if (!rawAmount || parseFloat(rawAmount) <= 0) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Please enter a valid amount greater than 0'
                });
                return;
            }

            formData.set('employee_id', document.getElementById('deductionEmployeeId').value);
            formData.set('employee_type', document.getElementById('deductionEmployeeType').value);
            formData.set('amount', rawAmount);

            const deductionType = formData.get('deduction_type');

            if (deductionType === 'loan_payment' || deductionType === 'cash_advance' || deductionType === 'sss_loan' || deductionType === 'pagibig_loan') {
                const startDate = formData.get('start_date');
                const endDate = formData.get('end_date');

                if (!startDate || !endDate) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Please specify start and end dates for loan/cash advance'
                    });
                    return;
                }

                if (new Date(startDate) > new Date(endDate)) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Start date must be before end date'
                    });
                    return;
                }
            } else if (deductionType === 'sss' || deductionType === 'pagibig' || deductionType === 'philhealth') {
                const effectiveDate = formData.get('effective_date');
                if (!effectiveDate) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Please specify effective date for government contribution'
                    });
                    return;
                }
            }

            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';
            submitBtn.disabled = true;

            fetch('api/add_deduction.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: data.message || 'Deduction added successfully!',
                        timer: 2000,
                        showConfirmButton: true
                    }).then(() => {
                        deductionForm.reset();
                        if (loanFields) loanFields.style.display = 'none';
                        if (governmentFields) governmentFields.style.display = 'none';

                        const employeeId = document.getElementById('deductionEmployeeId').value;
                        const employeeType = document.getElementById('deductionEmployeeType').value;
                        loadActiveDeductions(employeeId, employeeType);
                        loadDeductionSummary(employeeId, employeeType);
                    });
                } else {
                    if (data.message.includes('already exists')) {
                        Swal.fire({
                            icon: 'warning',
                            title: 'Duplicate Deduction',
                            text: data.message,
                            showConfirmButton: true,
                            confirmButtonText: 'OK'
                        });
                    } else {
                        throw new Error(data.message || 'Failed to add deduction');
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Error adding deduction: ' + error.message
                });
            })
            .finally(() => {
                submitBtn.innerHTML = originalBtnContent;
                submitBtn.disabled = false;
            });
        });
    }

    // Payment Form Submit
    const paymentForm = document.getElementById('paymentForm');
    if (paymentForm) {
        paymentForm.addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalBtnContent = submitBtn.innerHTML;

            const rawAmount = document.getElementById('paymentAmountRaw').value;

            if (!rawAmount || parseFloat(rawAmount) <= 0) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Please enter a valid amount greater than 0'
                });
                return;
            }

            formData.set('amount', rawAmount);

            const period = formData.get('payment_period');
            if (period === 'custom') {
                const customPeriod = document.getElementById('customPeriod').value;
                if (customPeriod) {
                    formData.set('payment_period', customPeriod);
                }
            }

            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Recording...';
            submitBtn.disabled = true;

            fetch('api/record_payment.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: 'Payment recorded successfully!',
                        timer: 2000,
                        showConfirmButton: true
                    }).then(() => {
                        paymentModal.hide();

                        const employeeId = document.getElementById('deductionEmployeeId').value;
                        const employeeType = document.getElementById('deductionEmployeeType').value;
                        loadActiveDeductions(employeeId, employeeType);
                        loadDeductionSummary(employeeId, employeeType);
                    });
                } else {
                    throw new Error(data.message || 'Failed to record payment');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Error recording payment: ' + error.message
                });
            })
            .finally(() => {
                submitBtn.innerHTML = originalBtnContent;
                submitBtn.disabled = false;
            });
        });
    }

    // Record deduction payment: open the payment modal for the clicked row.
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.record-deduction-payment-btn');
        if (!btn) {
            return;
        }

        const deductionId = btn.getAttribute('data-id');
        const employeeId = btn.getAttribute('data-employee-id') || '';
        const employeeType = btn.getAttribute('data-employee-type') || '';
        const label = btn.getAttribute('data-label') || 'Deduction';
        const amount = parseFloat(btn.getAttribute('data-amount') || '0');

        document.getElementById('paymentDeductionId').value = deductionId;
        document.getElementById('paymentEmployeeId').value = employeeId;
        document.getElementById('paymentEmployeeType').value = employeeType;
        document.getElementById('paymentDeductionInfo').value = label + ' - \u20B1' + amount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

        // Start each payment from a clean form.
        document.getElementById('paymentAmount').value = '';
        document.getElementById('paymentAmountRaw').value = '';
        document.getElementById('paymentNotes').value = '';
        document.getElementById('paymentPeriod').value = '';
        document.getElementById('customPeriod').style.display = 'none';

        paymentModal.show();
    });
    // Deactivate deduction button click (Improved Version)
    document.addEventListener('click', function(e) {
        if (e.target.closest('.deactivate-deduction-btn')) {
            const btn = e.target.closest('.deactivate-deduction-btn');
            const deductionId = btn.getAttribute('data-id');

            Swal.fire({
                title: 'Deactivate Deduction?',
                text: 'Are you sure you want to deactivate this deduction? This will remove it from active deductions.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, deactivate it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch('api/deactivate_deduction.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'deduction_id=' + deductionId
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Deactivated!',
                                text: 'Deduction has been deactivated.',
                                timer: 2000,
                                showConfirmButton: false
                            }).then(() => {
                                const employeeId = document.getElementById('deductionEmployeeId').value;
                                const employeeType = document.getElementById('deductionEmployeeType').value;
                                loadActiveDeductions(employeeId, employeeType);
                                loadDeductionSummary(employeeId, employeeType);
                            });
                        } else {
                            throw new Error(data.message || 'Failed to deactivate deduction');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        Swal.fire({
                            icon: 'error',
                            title: 'Error!',
                            text: 'Error deactivating deduction: ' + error.message
                        });
                    });
                }
            });
        }
    });

    // Profile image change handlers
    const editProfileImage = document.getElementById('editProfileImage');
    if (editProfileImage) {
        editProfileImage.addEventListener('change', function(e) {
            const preview = document.getElementById('editProfileImagePreview');
            const file = e.target.files[0];

            if (file && file.size > 5 * 1024 * 1024) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'File size exceeds 5MB limit'
                });
                this.value = '';
                return;
            }

            const validTypes = ['image/jpeg', 'image/png', 'image/gif'];
            if (file && !validTypes.includes(file.type)) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Invalid file type. Please upload JPG, PNG, or GIF images only'
                });
                this.value = '';
                return;
            }

            const reader = new FileReader();
            reader.onloadend = function() {
                preview.src = reader.result;
                preview.classList.remove('d-none');
            };

            if (file) {
                reader.readAsDataURL(file);
            } else {
                const currentImage = document.getElementById('editCurrentProfileImage').value;
                preview.src = currentImage || 'assets/img/default-profile.png';
                preview.classList.remove('d-none');
            }
        });
    }

    const editProxyProfileImage = document.getElementById('editProxyProfileImage');
    if (editProxyProfileImage) {
        editProxyProfileImage.addEventListener('change', function(e) {
            const preview = document.getElementById('editProxyProfileImagePreview');
            const file = e.target.files[0];

            if (file && file.size > 5 * 1024 * 1024) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'File size exceeds 5MB limit'
                });
                this.value = '';
                return;
            }

            const validTypes = ['image/jpeg', 'image/png', 'image/gif'];
            if (file && !validTypes.includes(file.type)) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Invalid file type. Please upload JPG, PNG, or GIF images only'
                });
                this.value = '';
                return;
            }

            const reader = new FileReader();
            reader.onloadend = function() {
                preview.src = reader.result;
                preview.classList.remove('d-none');
            };

            if (file) {
                reader.readAsDataURL(file);
            } else {
                const currentImage = document.getElementById('editProxyCurrentProfileImage').value;
                preview.src = currentImage || 'assets/img/default-profile.png';
                preview.classList.remove('d-none');
            }
        });
    }

    // Edit Collector Form Submit
    const editCollectorForm = document.getElementById('editCollectorForm');
    if (editCollectorForm) {
        editCollectorForm.addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalBtnContent = submitBtn.innerHTML;

            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            submitBtn.disabled = true;

            fetch('api/update_collector.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: 'Collector updated successfully!',
                        confirmButtonColor: '#3085d6',
                        timer: 2000,
                        showConfirmButton: true
                    }).then(() => {
                        editCollectorModal.hide();
                        location.reload();
                    });
                } else {
                    throw new Error(data.message || 'Failed to update collector');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Error updating collector: ' + error.message
                });
            })
            .finally(() => {
                submitBtn.innerHTML = originalBtnContent;
                submitBtn.disabled = false;
            });
        });
    }

    // Edit Proxy Form Submit
    const editProxyForm = document.getElementById('editProxyForm');
    if (editProxyForm) {
        editProxyForm.addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalBtnContent = submitBtn.innerHTML;

            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            submitBtn.disabled = true;

            fetch('api/update_proxy.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: 'Proxy updated successfully!',
                        confirmButtonColor: '#3085d6',
                        timer: 2000,
                        showConfirmButton: true
                    }).then(() => {
                        editProxyModal.hide();
                        location.reload();
                    });
                } else {
                    throw new Error(data.message || 'Failed to update proxy');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Error updating proxy: ' + error.message
                });
            })
            .finally(() => {
                submitBtn.innerHTML = originalBtnContent;
                submitBtn.disabled = false;
            });
        });
    }

    // Delete Collector function
    function deleteCollector(id, rowElement) {
        const deleteBtn = rowElement ? rowElement.querySelector('.delete-btn') : null;
        const originalContent = deleteBtn ? deleteBtn.innerHTML : '';

        fetch('api/delete_collector.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `id=${id}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: 'Collector deleted successfully!',
                    confirmButtonColor: '#3085d6',
                    timer: 2000,
                    showConfirmButton: true
                }).then(() => {
                    location.reload();
                });
            } else {
                throw new Error(data.message || 'Failed to delete collector');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: 'Error deleting collector: ' + error.message
            });
        });
    }

    // Delete Proxy function
    function deleteProxy(id, rowElement) {
        fetch('api/delete_proxy.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `id=${id}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: 'Proxy account deleted successfully!',
                    confirmButtonColor: '#3085d6',
                    timer: 2000,
                    showConfirmButton: true
                }).then(() => {
                    location.reload();
                });
            } else {
                throw new Error(data.message || 'Failed to delete proxy account');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: 'Error deleting proxy account: ' + error.message
            });
        });
    }

    function showToast(type, message) {
        const toastContainer = document.querySelector('.toast-container');
        const toast = document.createElement('div');
        toast.className = `toast align-items-center text-white bg-${type === 'success' ? 'success' : 'danger'} border-0`;
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', 'assertive');
        toast.setAttribute('aria-atomic', 'true');

        toast.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">
                    ${message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        `;

        toastContainer.appendChild(toast);

        const bsToast = new bootstrap.Toast(toast);
        bsToast.show();

        toast.addEventListener('hidden.bs.toast', () => {
            toast.remove();
        });
    }
});

// ===== GLOBAL FUNCTIONS (used in inline onclick attributes) =====

function filterByBranch() {
    const branchFilter = document.getElementById('branchFilter');
    const branch = branchFilter.value;

    let url = 'employee_lists.php';
    if (branch) {
        url += '?branch=' + encodeURIComponent(branch);
    }

    window.location.href = url;
}

function initializeEditForm() {
    console.log('Edit form initialized');

    if (typeof toggleBranchSelection === 'function') {
        toggleBranchSelection();
    }

    const contactNumberInput = document.getElementById('contact_number');
    if (contactNumberInput) {
        contactNumberInput.addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11);
        });
    }

    const accountTypeSelect = document.getElementById('account_type');
    if (accountTypeSelect) {
        accountTypeSelect.addEventListener('change', function() {
            if (typeof toggleBranchSelection === 'function') {
                toggleBranchSelection();
            }
        });
    }
}

function handleEditFormSubmission(form) {
    console.log('Edit form submission started');

    const formData = new FormData(form);

    const accountType = formData.get('account_type');

    if (accountType === 'Area Manager') {
        const branchCheckboxes = form.querySelectorAll('input[name="branches[]"]:checked');
        if (branchCheckboxes.length === 0) {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Please select at least one branch for Area Manager'
            });
            return false;
        }
    } else if (accountType !== 'Admin') {
        const branch = formData.get('branch');
        if (!branch) {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Please select a branch'
            });
            return false;
        }
    }

    const password = formData.get('password');
    const confirmPassword = formData.get('confirm_password');

    if (password && password !== confirmPassword) {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Passwords do not match'
        });
        return false;
    }

    if (password && password.length < 8) {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Password must be at least 8 characters long'
        });
        return false;
    }

    const contactNumber = formData.get('contact_number');
    if (!/^09\d{9}$/.test(contactNumber)) {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Contact number must be 11 digits starting with "09"'
        });
        return false;
    }

    fetch('api/update_account.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Success',
                text: data.message,
                timer: 2000,
                showConfirmButton: false
            }).then(() => {
                const editModal = bootstrap.Modal.getInstance(document.getElementById('editAccountModal'));
                if (editModal) {
                    editModal.hide();
                }
                location.reload();
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: data.message
            });
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'An error occurred while updating the account.'
        });
    });

    return false;
}

function toggleBranchSelection() {
    const accountType = document.getElementById('account_type').value;
    const singleBranchContainer = document.getElementById('singleBranchContainer');
    const multipleBranchesContainer = document.getElementById('multipleBranchesContainer');
    const branchesContainer = multipleBranchesContainer ? multipleBranchesContainer.querySelector('.branches-container') : null;
    const branchSelect = document.getElementById('branch');

    if (accountType === 'Area Manager') {
        if (singleBranchContainer) singleBranchContainer.style.display = 'none';
        if (multipleBranchesContainer) multipleBranchesContainer.style.display = 'block';
        if (branchesContainer) branchesContainer.style.display = 'block';

    } else {
        if (singleBranchContainer) singleBranchContainer.style.display = 'block';
        if (multipleBranchesContainer) multipleBranchesContainer.style.display = 'none';
        if (branchesContainer) branchesContainer.style.display = 'none';

        const branchCheckboxes = document.querySelectorAll('.branch-checkbox');
        branchCheckboxes.forEach(checkbox => {
            checkbox.checked = false;
        });

        if (branchSelect) {
            const allOptions = branchSelect.options;

            if (accountType === 'Admin') {
                for (let i = 0; i < allOptions.length; i++) {
                    if (allOptions[i].value === 'All Branches') {
                        allOptions[i].style.display = 'block';
                        allOptions[i].disabled = false;
                    } else {
                        allOptions[i].style.display = 'none';
                    }
                }
                branchSelect.value = 'All Branches';
                branchSelect.disabled = true;
                branchSelect.classList.add('admin-branch-field');

            } else if (accountType === 'Branch Manager') {
                for (let i = 0; i < allOptions.length; i++) {
                    if (allOptions[i].value === 'All Branches') {
                        allOptions[i].style.display = 'none';
                    } else {
                        allOptions[i].style.display = 'block';
                        allOptions[i].disabled = false;
                    }
                }
                branchSelect.disabled = false;
                branchSelect.classList.remove('admin-branch-field');

                if (branchSelect.value === 'All Branches') {
                    branchSelect.value = '';
                }

            } else {
                for (let i = 0; i < allOptions.length; i++) {
                    allOptions[i].style.display = 'block';
                    allOptions[i].disabled = false;
                }
                branchSelect.disabled = false;
                branchSelect.classList.remove('admin-branch-field');
            }
        }
    }
}
/* ==========================================================================
   Prime City - Page flash messages
   Rendered by employee_lists.php into #employee-list-data.
   ========================================================================== */
document.addEventListener('DOMContentLoaded', function () {
    var node = document.getElementById('employee-list-data');

    if (!node || typeof Swal === 'undefined') {
        return;
    }

    var config;

    try {
        config = JSON.parse(node.textContent || '{}');
    } catch (error) {
        console.error('[employee-list] Could not parse page configuration.', error);
        return;
    }

    /* Flash messages use the shared PC toast skin (see assets/js/pc-toast.js). */
    if (config.success) {
        if (window.PCToast) {
            window.PCToast.success('Saved', config.success);
        } else {
            Swal.fire({
                icon: 'success',
                title: 'Success',
                text: config.success,
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000,
                timerProgressBar: true
            });
        }
    }

    if (config.error) {
        if (window.PCToast) {
            window.PCToast.error('Something went wrong', config.error);
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: config.error,
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 5000,
                timerProgressBar: true
            });
        }
    }
});
