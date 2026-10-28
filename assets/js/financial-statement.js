/* ==========================================================================
   Prime City - Financial statement report behaviour
   The branch filter and the lazy DataTable initialisation per tab.
   Page data lives in #financial-statement-data.
   ========================================================================== */

(function () {
    var pageConfigCache = null;

    /**
     * Page configuration from #financial-statement-data.
     */
    function pageConfig() {
        if (pageConfigCache) { return pageConfigCache; }

        var node = document.getElementById('financial-statement-data');

        if (!node) { pageConfigCache = {}; return pageConfigCache; }

        try {
            pageConfigCache = JSON.parse(node.textContent || '{}');
        } catch (error) {
            console.error('[financial-statement] Could not parse page configuration.', error);
            pageConfigCache = {};
        }

        return pageConfigCache;
    }

    const pageCfg = pageConfig();

            // Function to apply branch filter
            function applyBranchFilter(branch) {
                if (!pageCfg.disableBranchFilter) {
                    const currentUrl = new URL(window.location.href);
                    if (branch === 'all') {
                        currentUrl.searchParams.delete('branch_filter');
                    } else {
                        currentUrl.searchParams.set('branch_filter', branch);
                    }
                    window.location.href = currentUrl.toString();
                } else {
                    // Do nothing if branch filter is disabled
                    console.log('Branch filter is disabled for your account type');
                }
            }

            document.addEventListener('DOMContentLoaded', function() {
                // Hide loading spinner when page is loaded
                document.getElementById('loadingSpinner').style.display = 'none';
                
                // Initialize DataTables with lazy loading for better performance
                const initializeDataTable = (tableId) => {
                    const table = document.getElementById(tableId);
                    if (table) {
                        return new simpleDatatables.DataTable(table, {
                            perPage: 25, // Show fewer rows per page
                            perPageSelect: [10, 25, 50, 100], // Page size options
                            fixedHeight: true, // Improve rendering performance
                        });
                    }
                    return null;
                };
                
                // Initialize tables only when their tab is clicked (lazy loading)
                const tabEls = document.querySelectorAll('button[data-bs-toggle="tab"]');
                const dataTables = {};
                
                tabEls.forEach(tabEl => {
                    tabEl.addEventListener('shown.bs.tab', function (event) {
                        const target = event.target.getAttribute('data-bs-target');
                        let tableId = '';
                        
                        switch(target) {
                            case '#loans':
                                tableId = 'loansTable';
                                break;
                            case '#savings':
                                tableId = 'savingsTable';
                                break;
                            case '#collections':
                                tableId = 'collectionsTable';
                                break;
                            case '#daily-collections':
                                tableId = 'dailyCollectionsTable';
                                break;
                            case '#weekly-collections':
                                tableId = 'weeklyCollectionsTable';
                                break;
                            case '#monthly-collections':
                                tableId = 'monthlyCollectionsTable';
                                break;
                        }
                        
                        if (tableId && !dataTables[tableId]) {
                            dataTables[tableId] = initializeDataTable(tableId);
                        }
                    });
                });
                
                // Initialize the first tab by default
                dataTables['loansTable'] = initializeDataTable('loansTable');
            });

    // Called from the branch filter markup.
    window.applyBranchFilter = applyBranchFilter;
})();
