/* ==========================================================================
   Prime City - Cashbook behaviour
   The loading overlay, pagination helpers and per-page switching.
   Page data lives in #cashbook-data.
   ========================================================================== */

(function () {
    var pageConfigCache = null;

    /**
     * Page configuration from #cashbook-data.
     */
    function pageConfig() {
        if (pageConfigCache) { return pageConfigCache; }

        var node = document.getElementById('cashbook-data');

        if (!node) { pageConfigCache = {}; return pageConfigCache; }

        try {
            pageConfigCache = JSON.parse(node.textContent || '{}');
        } catch (error) {
            console.error('[cashbook] Could not parse page configuration.', error);
            pageConfigCache = {};
        }

        return pageConfigCache;
    }

    const pageCfg = pageConfig();

        function showLoading() {
            document.getElementById('loadingOverlay').style.display = 'flex';
        }
        
        function hideLoading() {
            document.getElementById('loadingOverlay').style.display = 'none';
        }
        
        function goToPage(page) {
            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set('page', page);
            showLoading();
            window.location.href = currentUrl.toString();
        }
        
        function changePerPage(perPage) {
            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set('per_page', perPage);
            currentUrl.searchParams.set('page', '1');
            showLoading();
            window.location.href = currentUrl.toString();
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            hideLoading();
        });
        
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'ArrowLeft') {
                e.preventDefault();
                if (pageCfg.page > 1) goToPage(pageCfg.page - 1);
            } else if (e.ctrlKey && e.key === 'ArrowRight') {
                e.preventDefault();
                if (pageCfg.page < pageCfg.totalPages) goToPage(pageCfg.page + 1);
            }
        });

    // Functions the markup calls from inline handlers.
    window.showLoading = showLoading;
    window.hideLoading = hideLoading;
    window.goToPage = goToPage;
    window.changePerPage = changePerPage;
})();
