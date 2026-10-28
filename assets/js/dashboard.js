/* ==========================================================================
   Prime City - Dashboard behaviour

   Renders the collection charts and refreshes them when the branch filter
   changes, using api/dashboard-endpoint.php. Falls back to a normal form
   submit when JavaScript or the request itself fails.

   Chart colours come from the logo (see assets/css/theme-logo.css):
   blues #0a5fe6 / #0099fa and greens #3ba602 / #8fea08.
   ========================================================================== */

(function () {
    'use strict';

    // Logo blues and greens, in a fixed order so areas keep their colour.
    var PIE_COLORS = [
        'rgba(10, 95, 230, 0.85)',
        'rgba(59, 166, 2, 0.85)',
        'rgba(0, 153, 250, 0.85)',
        'rgba(12, 63, 143, 0.85)',
        'rgba(143, 234, 8, 0.85)',
        'rgba(27, 123, 242, 0.85)',
        'rgba(47, 143, 10, 0.85)',
        'rgba(7, 48, 125, 0.85)',
        'rgba(0, 153, 250, 0.55)',
        'rgba(59, 166, 2, 0.55)'
    ];

    var PIE_BORDERS = [
        'rgba(10, 95, 230, 1)',
        'rgba(59, 166, 2, 1)',
        'rgba(0, 153, 250, 1)',
        'rgba(12, 63, 143, 1)',
        'rgba(143, 234, 8, 1)',
        'rgba(27, 123, 242, 1)',
        'rgba(47, 143, 10, 1)',
        'rgba(7, 48, 125, 1)',
        'rgba(0, 153, 250, 1)',
        'rgba(59, 166, 2, 1)'
    ];

    var INK = '#0d3b7a';
    var INK_MUTED = '#5b7699';
    var GRID = 'rgba(10, 61, 156, 0.09)';

    var state = {
        config: null,
        charts: { collector: null, area: null }
    };

    /* ----------------------------------------------------------------------
       Helpers
       ---------------------------------------------------------------------- */

    function byId(id) {
        return document.getElementById(id);
    }

    function readConfig() {
        var node = byId('dashboard-data');

        if (!node) {
            return null;
        }

        try {
            return JSON.parse(node.textContent || '{}');
        } catch (error) {
            console.error('[dashboard] Could not parse initial data.', error);
            return null;
        }
    }

    function peso(value) {
        var amount = Number(value) || 0;
        return '\u20B1' + amount.toLocaleString();
    }

    function pesoExact(value) {
        var amount = Number(value) || 0;
        return '\u20B1' + amount.toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function normalize(payload) {
        return {
            branch: payload.branch || 'all',
            branchLabel: payload.branchLabel || 'All Branches',
            collectors: Array.isArray(payload.collectors) ? payload.collectors : [],
            areas: Array.isArray(payload.areas) ? payload.areas : [],
            summary: payload.summary || null
        };
    }

    /* ----------------------------------------------------------------------
       Chart theming
       ---------------------------------------------------------------------- */

    function applyChartDefaults() {
        if (typeof Chart === 'undefined') {
            return;
        }

        Chart.defaults.font.family = '"Inter", "Segoe UI", system-ui, -apple-system, Arial, sans-serif';
        Chart.defaults.font.size = 12;
        Chart.defaults.color = INK_MUTED;
    }

    /* ----------------------------------------------------------------------
       Chart rendering
       ---------------------------------------------------------------------- */

    function destroyChart(key) {
        if (state.charts[key]) {
            state.charts[key].destroy();
            state.charts[key] = null;
        }
    }

    function ensureCanvas(body, canvasId) {
        var canvas = byId(canvasId);

        if (canvas) {
            return canvas;
        }

        var wrapper = document.createElement('div');
        wrapper.className = 'chart-container';

        canvas = document.createElement('canvas');
        canvas.id = canvasId;

        wrapper.appendChild(canvas);
        body.innerHTML = '';
        body.appendChild(wrapper);

        return canvas;
    }

    function showEmptyState(body, title, message) {
        body.innerHTML = '';

        var alert = document.createElement('div');
        alert.className = 'alert alert-info';
        alert.setAttribute('role', 'alert');
        alert.textContent = message;

        body.appendChild(alert);
    }

    function renderCollectorChart(data) {
        var body = byId('collectorChartBody');

        if (!body || typeof Chart === 'undefined') {
            return;
        }

        destroyChart('collector');

        if (!data.collectors.length) {
            showEmptyState(body, 'Collector Collection Performance', 'No collection data available for ' + data.branchLabel + '.');
            return;
        }

        var canvas = ensureCanvas(body, 'collectorChart');
        var labels = data.collectors.map(function (row) { return row.label; });
        var totals = data.collectors.map(function (row) { return Number(row.total) || 0; });

        state.charts.collector = new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Total Collection (\u20B1)',
                    data: totals,
                    backgroundColor: 'rgba(10, 95, 230, 0.78)',
                    hoverBackgroundColor: 'rgba(10, 95, 230, 1)',
                    borderColor: 'rgba(10, 95, 230, 1)',
                    borderWidth: 0,
                    borderRadius: 10,
                    borderSkipped: false,
                    maxBarThickness: 48
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 550 },
                layout: { padding: { top: 6 } },
                scales: {
                    x: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: {
                            color: INK_MUTED,
                            font: { size: 11 },
                            maxRotation: 0,
                            autoSkip: false,
                            callback: function (value) {
                                var label = String(this.getLabelForValue(value) || '');
                                return label.length > 16 ? label.slice(0, 15) + '\u2026' : label;
                            }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        border: { display: false },
                        grid: { color: GRID, drawTicks: false },
                        ticks: {
                            color: INK_MUTED,
                            padding: 8,
                            callback: function (value) {
                                return '\u20B1' + Number(value).toLocaleString();
                            }
                        }
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0a2f6b',
                        titleColor: '#ffffff',
                        bodyColor: 'rgba(255, 255, 255, 0.9)',
                        padding: 12,
                        cornerRadius: 10,
                        displayColors: false,
                        titleFont: { weight: '600' },
                        callbacks: {
                            label: function (context) {
                                return pesoExact(context.raw);
                            }
                        }
                    }
                }
            }
        });
    }

    function renderAreaChart(data) {
        var body = byId('areaChartBody');

        if (!body || typeof Chart === 'undefined') {
            return;
        }

        destroyChart('area');

        if (!data.areas.length) {
            showEmptyState(body, 'Area Collection Performance', 'No area data available for ' + data.branchLabel + '.');
            return;
        }

        var canvas = ensureCanvas(body, 'areaChart');
        var labels = data.areas.map(function (row) { return row.label; });
        var totals = data.areas.map(function (row) { return Number(row.total) || 0; });

        state.charts.area = new Chart(canvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Total Collection (\u20B1)',
                    data: totals,
                    backgroundColor: totals.map(function (row, index) {
                        return PIE_COLORS[index % PIE_COLORS.length];
                    }),
                    borderColor: '#ffffff',
                    borderWidth: 3,
                    hoverBorderColor: '#ffffff',
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                animation: { duration: 550 },
                layout: { padding: 4 },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: INK,
                            usePointStyle: true,
                            pointStyle: 'circle',
                            boxWidth: 8,
                            boxHeight: 8,
                            padding: 14,
                            font: { size: 12 }
                        }
                    },
                    tooltip: {
                        backgroundColor: '#0a2f6b',
                        titleColor: '#ffffff',
                        bodyColor: 'rgba(255, 255, 255, 0.9)',
                        padding: 12,
                        cornerRadius: 10,
                        callbacks: {
                            label: function (context) {
                                var label = context.label || '';
                                var value = Number(context.raw) || 0;
                                var values = context.dataset.data || [];
                                var total = values.reduce(function (carry, item) {
                                    return carry + (Number(item) || 0);
                                }, 0);
                                var percentage = total > 0 ? Math.round((value / total) * 100) : 0;

                                return label + ': ' + peso(value) + ' (' + percentage + '%)';
                            }
                        }
                    }
                }
            }
        });
    }

    function renderTitles(data) {
        var collectorTitle = byId('collectorChartTitle');
        var areaTitle = byId('areaChartTitle');

        if (collectorTitle) {
            collectorTitle.textContent = 'Collector Collection Performance - ' + data.branchLabel;
        }
        if (areaTitle) {
            areaTitle.textContent = 'Area Collection Performance - ' + data.branchLabel;
        }
    }

    /* ----------------------------------------------------------------------
       Summary tiles
       ---------------------------------------------------------------------- */

    function renderSummary(data) {
        var label = byId('branchSummaryLabel');
        var totalLabel = byId('statTotalMeta');

        if (label) {
            label.textContent = data.branchLabel;
        }
        if (totalLabel) {
            totalLabel.textContent = data.branchLabel;
        }

        var collectors = data.collectors;
        var topTotal = 0;
        var topName = null;

        collectors.forEach(function (row) {
            var amount = Number(row.total) || 0;
            if (topName === null || amount > topTotal) {
                topTotal = amount;
                topName = row.label;
            }
        });

        var total = collectors.reduce(function (carry, row) {
            return carry + (Number(row.total) || 0);
        }, 0);

        var totalNode = byId('statTotal');
        if (totalNode) {
            totalNode.textContent = pesoExact(total);
        }

        var topNode = byId('statTop');
        var topAmountNode = byId('statTopAmount');

        if (topNode) {
            topNode.textContent = topName === null ? 'No collections yet' : topName;
        }
        if (topAmountNode) {
            topAmountNode.textContent = topName === null ? '' : pesoExact(topTotal);
        }

        var countNode = byId('statCollectors');
        if (countNode) {
            countNode.textContent = String(collectors.length);
        }

        var areaNode = byId('statAreas');
        if (areaNode) {
            areaNode.textContent = String(data.areas.length);
        }
    }

    function render(payload) {
        var data = normalize(payload);
        renderTitles(data);
        renderSummary(data);
        renderCollectorChart(data);
        renderAreaChart(data);
    }

    /* ----------------------------------------------------------------------
       Loading state
       ---------------------------------------------------------------------- */

    function setLoading(isLoading) {
        ['collectorChartBody', 'areaChartBody'].forEach(function (id) {
            var body = byId(id);
            if (body) {
                body.classList.toggle('is-loading', isLoading);
            }
        });

        document.querySelectorAll('.chart-loading').forEach(function (node) {
            node.classList.toggle('is-visible', isLoading);
        });
    }

    /* ----------------------------------------------------------------------
       Branch filter
       ---------------------------------------------------------------------- */

    function withBranch(url, branch) {
        var next = new URL(url, window.location.href);
        next.searchParams.set('branch', branch);
        return next.toString();
    }

    function syncUrl(branch) {
        if (!window.history || !window.history.replaceState) {
            return;
        }

        try {
            window.history.replaceState(null, '', withBranch(window.location.href, branch));
        } catch (error) {
            /* Cross-origin or restricted history - the filter still works. */
        }
    }

    function selectValue(value) {
        var select = byId('branchFilter');
        if (select && select.value !== value) {
            select.value = value;
        }
    }

    function submitForm() {
        var select = byId('branchFilter');
        var form = select && select.form;

        if (form) {
            form.submit();
            return;
        }

        window.location.href = withBranch(window.location.href, select ? select.value : 'all');
    }

    function updateBranch(branch) {
        var config = state.config;

        if (!config || !config.endpoint || typeof window.fetch !== 'function') {
            submitForm();
            return;
        }

        setLoading(true);

        window.fetch(withBranch(config.endpoint, branch), {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('Request failed with status ' + response.status);
            }
            return response.json();
        }).then(function (payload) {
            if (!payload || payload.ok !== true) {
                throw new Error('Unexpected response payload');
            }

            setLoading(false);
            render(payload);

            if (payload.branch) {
                selectValue(payload.branch);
                syncUrl(payload.branch);
            }
        }).catch(function (error) {
            console.warn('[dashboard] Live refresh failed, reloading the page.', error);
            setLoading(false);
            submitForm();
        });
    }

    function bindFilter() {
        var select = byId('branchFilter');

        if (!select || select.tagName !== 'SELECT') {
            return;
        }

        var form = select.form;

        if (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                updateBranch(select.value);
            });
        }

        select.addEventListener('change', function () {
            updateBranch(select.value);
        });
    }

    /* ----------------------------------------------------------------------
       Boot
       ---------------------------------------------------------------------- */

    function init() {
        state.config = readConfig();

        if (!state.config) {
            return;
        }

        applyChartDefaults();
        render(state.config);
        bindFilter();
    }

    // Kept for inline callers / backwards compatibility.
    window.applyBranchFilter = function (branch) {
        updateBranch(branch);
    };

    document.addEventListener('DOMContentLoaded', init);
})();
