/* ==========================================================================
   Prime City - Penalty report behaviour
   The period/date filter switcher and the report DataTable.
   ========================================================================== */

(function () {
        // Function to toggle date filters based on period selection (like reference code)
        function toggleDateFilters() {
            const period = document.getElementById('period').value;
            
            // Hide all date filters
            document.querySelectorAll('.date-filter').forEach(filter => {
                filter.classList.remove('active');
            });
            
            // Show the appropriate date filter based on selected period
            switch(period) {
                case 'daily':
                    document.getElementById('daily-filter').classList.add('active');
                    break;
                case 'weekly':
                    document.getElementById('weekly-filter').classList.add('active');
                    break;
                case 'monthly':
                    document.getElementById('monthly-filter').classList.add('active');
                    break;
                case 'yearly':
                    document.getElementById('yearly-filter').classList.add('active');
                    break;
            }
        }
    
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize the correct date filter on page load
            toggleDateFilters();
            
            // Add event listener for period change
            document.getElementById('period').addEventListener('change', function() {
                toggleDateFilters();
            });
            
            // Initialize DataTable
            const penaltiesTable = document.getElementById('penaltiesTable');

            if (penaltiesTable && penaltiesTable.tBodies.length && penaltiesTable.tBodies[0].rows.length > 20) {
                new simpleDatatables.DataTable("#penaltiesTable", {
                    perPage: 25,
                    perPageSelect: [10, 25, 50, 100],
                    searchable: true,
                    sortable: true,
                    labels: {
                        placeholder: "Search penalties...",
                        perPage: "entries per page",
                        noRows: "No penalties found",
                        info: "Showing {start} to {end} of {rows} entries"
                    }
                });
            }
        });

    // Called from the period select's inline handler.
    window.toggleDateFilters = toggleDateFilters;
})();
