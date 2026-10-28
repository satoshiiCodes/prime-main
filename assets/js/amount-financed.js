/* ==========================================================================
   Prime City - Amount financed report behaviour
   The loan DataTable and the period input switcher.
   ========================================================================== */

(function () {
            // Initialize DataTable
            document.addEventListener('DOMContentLoaded', function() {
                const financingTable = document.getElementById('financingTable');

                /* short lists keep the totals footer visible */
                if (financingTable && financingTable.tBodies.length && financingTable.tBodies[0].rows.length > 20) {
                    const dataTable = new simpleDatatables.DataTable("#financingTable", {
                        perPage: 10,
                        perPageSelect: [10, 25, 50, 100],
                        labels: {
                            placeholder: "Search active loans...",
                            perPage: "Show {select} entries",
                            noRows: "No active loans found",
                            info: "Showing {start} to {end} of {rows} active loans"
                        }
                    });
                }

                // Initialize period inputs visibility
                togglePeriodInputs();
            });

            /* local YYYY-MM-DD (toISOString() is UTC and lands a day early in UTC+8) */
            function localDateString(date) {
                const pad = function (value) { return String(value).padStart(2, '0'); };

                return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
            }

            /* a max below the current value silently blocks the form submit */
            function applyMax(element, limit) {
                if (!element) {
                    return;
                }

                element.max = (element.value && element.value > limit) ? element.value : limit;
            }

            function togglePeriodInputs() {
                const filterPeriod = document.getElementById('filter_period').value;

                // Hide all period input groups
                document.querySelectorAll('.period-input-group').forEach(group => {
                    group.classList.remove('active');
                });

                // Show only the selected period input group
                document.getElementById(filterPeriod + '_group').classList.add('active');

                const now = new Date();
                const today = localDateString(now);
                const currentMonth = today.slice(0, 7); // YYYY-MM format

                // Get current week for max value
                const firstDayOfYear = new Date(now.getFullYear(), 0, 1);
                const days = Math.floor((now - firstDayOfYear) / (24 * 60 * 60 * 1000));
                const weekNumber = Math.ceil((days + firstDayOfYear.getDay() + 1) / 7);
                const currentWeek = now.getFullYear() + '-W' + weekNumber.toString().padStart(2, '0');

                // Update max attributes (never below the selected value)
                applyMax(document.getElementById('custom_date'), today);
                applyMax(document.getElementById('week'), currentWeek);
                applyMax(document.getElementById('month'), currentMonth);
            }

    // Called from the period select's inline handler.
    window.togglePeriodInputs = togglePeriodInputs;
})();
