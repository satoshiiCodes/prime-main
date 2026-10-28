/* ==========================================================================
   Prime City - Payment history report behaviour
   Branch filter submit and the custom due-date toggle.
   Page data lives in #payment-history-data.
   ========================================================================== */

(function () {
    var pageConfigCache = null;

    /**
     * Page configuration from #payment-history-data.
     */
    function pageConfig() {
        if (pageConfigCache) { return pageConfigCache; }

        var node = document.getElementById('payment-history-data');

        if (!node) { pageConfigCache = {}; return pageConfigCache; }

        try {
            pageConfigCache = JSON.parse(node.textContent || '{}');
        } catch (error) {
            console.error('[payment-history] Could not parse page configuration.', error);
            pageConfigCache = {};
        }

        return pageConfigCache;
    }

    const pageCfg = pageConfig();

        // Function to apply branch filter
        function applyBranchFilter(branch) {
            document.getElementById('branchInput').value = branch;
            document.querySelector('.filter-form').submit();
        }

        // Function to show/hide custom due date field
        function toggleCustomDueDate() {
            const dueDateSelect = document.getElementById('due_date');
            const customDateContainer = document.getElementById('customDueDateContainer');
            
            if (dueDateSelect.value === 'custom') {
                customDateContainer.classList.add('show');
            } else {
                customDateContainer.classList.remove('show');
            }
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            const branchFilter = document.getElementById('branchFilter');
            if (branchFilter) {
                branchFilter.value = pageCfg.selectedBranch;
            }
            
            const dueDateSelect = document.getElementById('due_date');
            if (dueDateSelect) {
                dueDateSelect.addEventListener('change', toggleCustomDueDate);
                toggleCustomDueDate();
            }
        });

    // Called from inline handlers in the markup.
    window.applyBranchFilter = applyBranchFilter;
    window.toggleCustomDueDate = toggleCustomDueDate;
})();
