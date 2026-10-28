/* ==========================================================================
   Prime City - Area reports behaviour
   Period/date filter switching, the report DataTable and the two charts.
   Page data lives in #area-reports-data.
   ========================================================================== */

(function () {
    var pageConfigCache = null;

    /**
     * Page configuration from #area-reports-data.
     */
    function pageConfig() {
        if (pageConfigCache) { return pageConfigCache; }

        var node = document.getElementById('area-reports-data');

        if (!node) { pageConfigCache = {}; return pageConfigCache; }

        try {
            pageConfigCache = JSON.parse(node.textContent || '{}');
        } catch (error) {
            console.error('[area-reports] Could not parse page configuration.', error);
            pageConfigCache = {};
        }

        return pageConfigCache;
    }

    const pageCfg = pageConfig();

            // Function to toggle date filters based on period selection
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

            // Initialize DataTable
            window.addEventListener('DOMContentLoaded', event => {
                /* Areas are a short, fixed list - paging would hide the totals
                   footer, so only initialise the plugin for long tables. */
                const reportTable = document.getElementById('areaReportsTable');

                if (reportTable && reportTable.tBodies.length) {
                    const rowCount = reportTable.tBodies[0].rows.length;

                    if (rowCount > 20) {
                        new simpleDatatables.DataTable(reportTable, {
                            searchable: true,
                            perPage: 25,
                            fixedHeight: false,
                        });
                    }
                }
                
                // Initialize date pickers
                flatpickr("#date", {
                    dateFormat: "Y-m-d",
                    defaultDate: pageCfg.selectedDate
                });
                
                // Initialize the correct date filter on page load
                toggleDateFilters();
                
                // Add event listener for period change to auto-switch date filters
                document.getElementById('period').addEventListener('change', function() {
                    toggleDateFilters();
                });
                
                // Prepare data for charts
                const areaData = pageCfg.areaData || {};
                
                const areas = Object.keys(areaData);
                const amounts = Object.values(areaData);
                
                // Area Chart
                const areaCtx = document.getElementById('areaChart').getContext('2d');
                const areaChart = new Chart(areaCtx, {
                    type: 'bar',
                    data: {
                        labels: areas,
                        datasets: [{
                            label: 'Collection Amount',
                            data: amounts,
                            backgroundColor: [
                                'rgba(54, 162, 235, 0.7)',
                                'rgba(255, 99, 132, 0.7)',
                                'rgba(255, 206, 86, 0.7)',
                                'rgba(75, 192, 192, 0.7)',
                                'rgba(153, 102, 255, 0.7)',
                                'rgba(255, 159, 64, 0.7)',
                                'rgba(199, 199, 199, 0.7)',
                                'rgba(83, 102, 255, 0.7)',
                                'rgba(40, 159, 64, 0.7)',
                                'rgba(210, 99, 132, 0.7)'
                            ],
                            borderColor: [
                                'rgba(54, 162, 235, 1)',
                                'rgba(255, 99, 132, 1)',
                                'rgba(255, 206, 86, 1)',
                                'rgba(75, 192, 192, 1)',
                                'rgba(153, 102, 255, 1)',
                                'rgba(255, 159, 64, 1)',
                                'rgba(199, 199, 199, 1)',
                                'rgba(83, 102, 255, 1)',
                                'rgba(40, 159, 64, 1)',
                                'rgba(210, 99, 132, 1)'
                            ],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return '₱' + value.toLocaleString();
                                    }
                                }
                            }
                        },
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return '₱' + context.raw.toLocaleString();
                                    }
                                }
                            }
                        }
                    }
                });
                
                // Pie Chart
                const pieCtx = document.getElementById('pieChart').getContext('2d');
                const pieChart = new Chart(pieCtx, {
                    type: 'pie',
                    data: {
                        labels: areas,
                        datasets: [{
                            data: amounts,
                            backgroundColor: [
                                'rgba(54, 162, 235, 0.7)',
                                'rgba(255, 99, 132, 0.7)',
                                'rgba(255, 206, 86, 0.7)',
                                'rgba(75, 192, 192, 0.7)',
                                'rgba(153, 102, 255, 0.7)',
                                'rgba(255, 159, 64, 0.7)',
                                'rgba(199, 199, 199, 0.7)',
                                'rgba(83, 102, 255, 0.7)',
                                'rgba(40, 159, 64, 0.7)',
                                'rgba(210, 99, 132, 0.7)'
                            ],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.raw || 0;
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = Math.round((value / total) * 100);
                                        return `${label}: ₱${value.toLocaleString()} (${percentage}%)`;
                                    }
                                }
                            }
                        }
                    }
                });
            });

    // Called from the period select's inline handler.
    window.toggleDateFilters = toggleDateFilters;
})();
