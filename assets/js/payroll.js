/* ==========================================================================
   Prime City - Payroll behaviour
   The PDF / payslip export buttons: they forward the current filter values
   to the generator pages.
   ========================================================================== */

(function () {
        // Function to generate PDF with current filters
        function generatePayrollPDF() {
            // Get current filter values
            const dateFrom = document.getElementById('date_from')?.value || '';
            const dateTo = document.getElementById('date_to')?.value || '';
            const filterBranch = document.querySelector('select[name="filter_branch"]')?.value || 
                                document.querySelector('input[name="filter_branch"]')?.value || '';
            
            // Build URL with filter parameters
            let url = 'generate_payroll_pdf.php?';
            if (dateFrom) url += 'date_from=' + encodeURIComponent(dateFrom) + '&';
            if (dateTo) url += 'date_to=' + encodeURIComponent(dateTo) + '&';
            if (filterBranch) url += 'branch=' + encodeURIComponent(filterBranch);
            
            // Remove trailing & if present
            url = url.replace(/&$/, '');
            
            // Open in new tab
            window.open(url, '_blank');
        }

        // Function to generate payslip PDF with current filters
        function generatePayslipPDF() {
            // Get current filter values
            const dateFrom = document.getElementById('date_from')?.value || '';
            const dateTo = document.getElementById('date_to')?.value || '';
            const filterBranch = document.querySelector('select[name="filter_branch"]')?.value || 
                                document.querySelector('input[name="filter_branch"]')?.value || '';
            
            // Build URL with filter parameters
            let url = 'generate_payslip_pdf.php?';
            if (dateFrom) url += 'date_from=' + encodeURIComponent(dateFrom) + '&';
            if (dateTo) url += 'date_to=' + encodeURIComponent(dateTo) + '&';
            if (filterBranch) url += 'branch=' + encodeURIComponent(filterBranch);
            
            // Remove trailing & if present
            url = url.replace(/&$/, '');
            
            // Open in new tab
            window.open(url, '_blank');
        }

        // Initialize the data table
        document.addEventListener('DOMContentLoaded', function() {
            const attendanceTable = document.getElementById('attendanceTable');
            if (attendanceTable) {
                // Check if the table has more than just the "No records" row
                const hasData = attendanceTable.rows.length > 1 || 
                            (attendanceTable.rows.length === 1 && 
                                !attendanceTable.rows[0].cells[0].textContent.includes('No attendance records'));
                
                if (hasData) {
                    new simpleDatatables.DataTable(attendanceTable, {
                        perPage: 10,
                        perPageSelect: [5, 10, 15, 20, 31],
                        searchable: true,
                        sortable: true,
                        labels: {
                            placeholder: "Search records...",
                            perPage: "{select} entries per page",
                            noRows: "No records found",
                            info: "Showing {start} to {end} of {rows} entries"
                        }
                    });
                }
            }
            
            // Fix for sidebar dropdown issue
            document.addEventListener('click', function(e) {
                if (window.innerWidth < 768) {
                    const sidebar = document.getElementById('layoutSidenav_nav');
                    if (!sidebar.contains(e.target) && !e.target.closest('.sb-topnav')) {
                        sidebar.classList.remove('sb-sidenav-toggled');
                    }
                }
            });
        });

    // Called from inline handlers in the markup.
    window.generatePayrollPDF = generatePayrollPDF;
    window.generatePayslipPDF = generatePayslipPDF;
})();
