/* ==========================================================================
   Prime City - Collector reports behaviour
   The report-type date input, the branch -> collector filter and the
   DataTable. Page data lives in #collector-reports-data.
   ========================================================================== */

(function () {
    var pageConfigCache = null;

    /**
     * Page configuration from #collector-reports-data.
     */
    function pageConfig() {
        if (pageConfigCache) { return pageConfigCache; }

        var node = document.getElementById('collector-reports-data');

        if (!node) { pageConfigCache = {}; return pageConfigCache; }

        try {
            pageConfigCache = JSON.parse(node.textContent || '{}');
        } catch (error) {
            console.error('[collector-reports] Could not parse page configuration.', error);
            pageConfigCache = {};
        }

        return pageConfigCache;
    }

    const pageCfg = pageConfig();

            // Function to apply branch filter
            function applyBranchFilter(branch) {
                const currentUrl = new URL(window.location.href);
                currentUrl.searchParams.set('branch', branch);
                window.location.href = currentUrl.toString();
            }

            // Report type drives the date control (yearly switches to a year list)
            function syncReportType(rebuild) {
                const typeSelect = document.getElementById('report_type');
                const dateField = document.getElementById('date_field');
                const dateControl = document.getElementById('date_filter');
                const dateLabel = document.getElementById('date_label');

                if (!typeSelect || !dateField || !dateControl) {
                    return;
                }

                const reportType = typeSelect.value;
                const currentYear = new Date().getFullYear();

                /* the caption follows the control: Date -> Year */
                if (dateLabel) {
                    dateLabel.textContent = reportType === 'yearly' ? 'Year' : 'Date';
                }

                /* on load the server already rendered the right control */
                if (rebuild === false) {
                    return;
                }

                let html = '';

                if (reportType === 'yearly') {
                    /* only the years that actually carry payment data */
                    const years = Array.isArray(pageCfg.years) && pageCfg.years.length
                        ? pageCfg.years
                        : [currentYear];
                    const preferred = years.indexOf(currentYear) !== -1
                        ? currentYear
                        : years[years.length - 1];

                    let options = '';

                    years.forEach(function (year) {
                        const selected = Number(year) === Number(preferred) ? ' selected' : '';
                        options += '<option value="' + year + '"' + selected + '>' + year + '</option>';
                    });

                    html = '<select class="form-select" id="date_filter" name="date_filter" required>' + options + '</select>';
                } else if (reportType === 'monthly') {
                    html = '<input type="month" class="form-control" id="date_filter" name="date_filter" value="' + pageCfg.currentMonth + '">';
                } else if (reportType === 'weekly') {
                    html = '<input type="week" class="form-control" id="date_filter" name="date_filter" value="' + pageCfg.currentWeek + '">';
                } else {
                    html = '<input type="date" class="form-control" id="date_filter" name="date_filter" value="' + pageCfg.currentDate + '">';
                }

                dateField.style.display = '';
                dateControl.outerHTML = html;
            }

            const reportTypeSelect = document.getElementById('report_type');

            if (reportTypeSelect) {
                reportTypeSelect.addEventListener('change', function () {
                    syncReportType(true);
                });
                syncReportType(false);
            }
            // Filter collectors based on selected branch (only for Admin and Area Manager)
            if (pageCfg.showBranchFilter) {
            document.getElementById('branchFilter').addEventListener('change', function() {
                const selectedBranch = this.value;
                const collectorSelect = document.getElementById('collector_id');
                const allOptions = collectorSelect.querySelectorAll('option');
                
                // Show/hide options based on branch (disabled also hides them in Select2)
                allOptions.forEach(option => {
                    if (option.value === '') {
                        option.disabled = false;
                        option.style.display = '';
                        return;
                    }

                    const optionBranch = option.getAttribute('data-branch');
                    const visible = selectedBranch === 'all' || optionBranch === selectedBranch;

                    option.disabled = !visible;
                    option.style.display = visible ? '' : 'none';
                });
                
                // Reset collector selection if the current selection doesn't match the branch
                const selectedOption = collectorSelect.options[collectorSelect.selectedIndex];
                if (selectedOption.value !== '' && selectedOption.getAttribute('data-branch') !== selectedBranch && selectedBranch !== 'all') {
                    collectorSelect.value = '';
                }
                
                // Update the hidden branch field
                document.getElementById('branch').value = selectedBranch;
            });

            // Initialize the collector dropdown based on current branch filter
            document.addEventListener('DOMContentLoaded', function() {
                const selectedBranch = document.getElementById('branchFilter').value;
                const collectorSelect = document.getElementById('collector_id');
                const allOptions = collectorSelect.querySelectorAll('option');
                
                // Show/hide options based on branch
                allOptions.forEach(option => {
                    if (option.value === '') {
                        option.disabled = false;
                        option.style.display = '';
                        return;
                    }

                    const optionBranch = option.getAttribute('data-branch');
                    const visible = selectedBranch === 'all' || optionBranch === selectedBranch;

                    option.disabled = !visible;
                    option.style.display = visible ? '' : 'none';
                });
            });
            }

    // ---- Collector dropdown: searchable, three lines ----------------------

    function initCollectorSelect() {
        if (!window.jQuery || !window.jQuery.fn || !window.jQuery.fn.select2) {
            return;
        }

        const $ = window.jQuery;
        const $select = $('#collector_id');

        if (!$select.length) {
            return;
        }

        $select.select2({
            width: '100%',
            placeholder: 'Search a collector…',
            allowClear: false,
            minimumResultsForSearch: 0,
            dropdownParent: $(document.body),
            templateResult: function (option) {
                if (!option.id) {
                    return option.text;
                }

                const $option = $(option.element);
                const $wrap = $('<div class="pc-opt"></div>');

                $('<span class="pc-opt__name"></span>')
                    .text($option.data('name') || option.text)
                    .appendTo($wrap);

                if ($option.data('area')) {
                    $('<span class="pc-opt__meta"></span>').text($option.data('area')).appendTo($wrap);
                }

                if ($option.data('branch')) {
                    $('<span class="pc-opt__meta"></span>').text($option.data('branch')).appendTo($wrap);
                }

                return $wrap;
            },
            templateSelection: function (option) {
                if (!option.id) {
                    return option.text;
                }

                return $(option.element).data('name') || option.text;
            }
        });
    }

    window.addEventListener('DOMContentLoaded', function () {
        initCollectorSelect();
    });

    // ---- Collection details table -----------------------------------------

    document.addEventListener('DOMContentLoaded', function () {
        var table = document.getElementById('collectionDetailsTable');

        if (!table || !table.tBodies.length || typeof simpleDatatables === 'undefined') {
            return;
        }

        /* Short lists keep the totals footer visible; long ones get paged. */
        if (table.tBodies[0].rows.length > 20) {
            new simpleDatatables.DataTable(table, {
                searchable: true,
                perPage: 25,
                perPageData: 25
            });
        }
    });
    // Called from the branch filter markup.
    window.applyBranchFilter = applyBranchFilter;
})();
