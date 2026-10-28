/* ==========================================================================
   Prime City - Savings transactions report behaviour
   The report DataTable, the period/date filter switcher and the branch
   filter. Page data lives in #savings-reports-data.
   ========================================================================== */

(function () {
    var pageConfigCache = null;

    function pageConfig() {
        if (pageConfigCache) { return pageConfigCache; }

        var node = document.getElementById('savings-reports-data');

        if (!node) { pageConfigCache = {}; return pageConfigCache; }

        try {
            pageConfigCache = JSON.parse(node.textContent || '{}');
        } catch (error) {
            console.error('[savings-reports] Could not parse page configuration.', error);
            pageConfigCache = {};
        }

        return pageConfigCache;
    }

    const pageCfg = pageConfig();

    /* Called from the branch filter's inline onchange. */
    function applyBranchFilter(branch) {
        if (pageCfg.disableBranchFilter) {
            return;
        }

        const currentUrl = new URL(window.location.href);
        currentUrl.searchParams.delete('branch');

        if (branch) {
            currentUrl.searchParams.set('branch', branch);
        }

        window.location.href = currentUrl.toString();
    }

    /* Called from the period select's inline onchange. */
    function toggleDateFilters() {
        const periodSelect = document.getElementById('period');

        if (!periodSelect) {
            return;
        }

        document.querySelectorAll('.date-filter').forEach(function (filter) {
            filter.classList.remove('active');
        });

        const target = document.getElementById(periodSelect.value + '-filter');

        if (target) {
            target.classList.add('active');
        }
    }

    window.addEventListener('DOMContentLoaded', function () {
        /* Short lists keep the totals footer visible. */
        const savingsTable = document.getElementById('savingsTransactionsTable');

        if (savingsTable && savingsTable.tBodies.length && savingsTable.tBodies[0].rows.length > 20) {
            new simpleDatatables.DataTable(savingsTable, {
                searchable: true,
                fixedHeight: false,
                perPage: 25,
                perPageSelect: [10, 25, 50, 100]
            });
        }

        /* Date picker for the daily view. */
        if (typeof flatpickr === 'function' && document.getElementById('date')) {
            flatpickr('#date', {
                dateFormat: 'Y-m-d',
                defaultDate: pageCfg.selectedDate
            });
        }

        toggleDateFilters();
    });

    // Functions the markup calls from inline handlers.
    window.applyBranchFilter = applyBranchFilter;
    window.toggleDateFilters = toggleDateFilters;
})();
