/**
 * Prime City - Employee list UI glue.
 *
 * Keeps the page visually aligned with customer_list.php by adding a clear (X)
 * button to the simple-datatables search fields.
 * (Flash messages are shown by employee-lists.js via the shared PC toast.)
 */

(function () {
    'use strict';

    function wireSearchClear() {
        var inputs = document.querySelectorAll('.datatable-search .datatable-input');

        Array.prototype.forEach.call(inputs, function (input) {
            var wrapper = input.closest('.datatable-search');

            if (!wrapper || wrapper.querySelector('.datatable-clear')) {
                return;
            }

            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'datatable-clear';
            button.setAttribute('aria-label', 'Clear search');
            button.innerHTML = '&times;';
            button.hidden = input.value === '';

            button.addEventListener('click', function () {
                input.value = '';
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.focus();
                button.hidden = true;
            });

            input.addEventListener('input', function () {
                button.hidden = input.value === '';
            });

            wrapper.appendChild(button);
        });
    }

    /* Modal dialogs: centred + scrollable, like the customer modals. */
    function centreDialogs() {
        var dialogs = document.querySelectorAll('.modal .modal-dialog');

        Array.prototype.forEach.call(dialogs, function (dialog) {
            dialog.classList.add('modal-dialog-centered', 'modal-dialog-scrollable');
        });
    }

    /* ------------------------------------------------------------------
       Header CTA follows the active tab (Collector / Proxy / Account)
       ------------------------------------------------------------------ */

    function syncPrimaryAction(tabId) {
        var button = document.getElementById('primaryAddBtn');
        var label = document.getElementById('primaryAddBtnLabel');
        var icon = document.getElementById('primaryAddBtnIcon');

        if (!button || !label || !icon) {
            return;
        }

        var key = 'collector';

        if (tabId === 'proxy-tab') {
            key = 'proxy';
        } else if (tabId === 'accounts-tab') {
            key = 'accounts';
        }

        var href = button.getAttribute('data-href-' + key);
        var text = button.getAttribute('data-label-' + key);
        var iconClass = button.getAttribute('data-icon-' + key);

        if (href) {
            button.setAttribute('href', href);
        }

        if (text) {
            label.textContent = text;
        }

        if (iconClass) {
            icon.className = 'fas ' + iconClass + ' me-1';
        }
    }

    function currentTabId() {
        var active = document.querySelector('#employeesTabs .nav-link.active');

        return active ? active.id : 'collector-tab';
    }

    /* ------------------------------------------------------------------
       Branch filter: share the row with the data-table search field
       ------------------------------------------------------------------ */

    function placeBranchFilter() {
        var filter = document.getElementById('branchFilter');

        if (!filter) {
            return;
        }

        var block = filter.closest('.unified-filter-container');

        if (!block) {
            return;
        }

        var pane = document.querySelector('.tab-content .tab-pane.active') || document;
        var toolbar = pane.querySelector('.datatable-top');

        if (!toolbar) {
            return;
        }

        if (block.parentNode !== toolbar) {
            toolbar.appendChild(block);
        }

        block.classList.add('is-inline');
    }

    /* ------------------------------------------------------------------
       Row dropdowns: let the menu escape the framed table while it is open
       ------------------------------------------------------------------ */

    var DROPDOWN_FRAME = '.table-responsive, .datatable-container, .dash-card, .card';

    function releaseFrames(toggle, open) {
        var frame = toggle.closest(DROPDOWN_FRAME);

        while (frame) {
            frame.classList.toggle('pc-dropdown-open', open);
            frame = frame.parentElement ? frame.parentElement.closest(DROPDOWN_FRAME) : null;
        }
    }

    function releaseAllFrames() {
        document.querySelectorAll('.pc-dropdown-open').forEach(function (node) {
            node.classList.remove('pc-dropdown-open');
        });
    }

    document.addEventListener('show.bs.dropdown', function (event) {
        releaseAllFrames();
        releaseFrames(event.target, true);
    });

    document.addEventListener('hide.bs.dropdown', function () {
        releaseAllFrames();
    });

    document.addEventListener('DOMContentLoaded', function () {
        /* simple-datatables builds its search box shortly after load */
        setTimeout(wireSearchClear, 400);
        setTimeout(wireSearchClear, 1200);

        setTimeout(placeBranchFilter, 500);
        setTimeout(placeBranchFilter, 1300);

        /* keep the filter with whichever tab is showing */
        document.querySelectorAll('[data-bs-toggle="tab"]').forEach(function (tab) {
            tab.addEventListener('shown.bs.tab', function () {
                placeBranchFilter();
                wireSearchClear();
            });
        });

        centreDialogs();

        /* header CTA reflects whichever tab is open */
        syncPrimaryAction(currentTabId());

        document.querySelectorAll('[data-bs-toggle="tab"]').forEach(function (tab) {
            tab.addEventListener('shown.bs.tab', function (event) {
                syncPrimaryAction(event.target.id);
            });
        });
    });
})();
