/**
 * Prime City - dropdowns on the multiple-payments page.
 *
 * Both the Collector and the (optional) Proxy Account render as two lines:
 *   row 1  full name
 *   row 2  area, muted
 *
 * The native <select> elements stay, so the page's own change handler,
 * validation and form submission keep working untouched.
 */

(function () {
    'use strict';

    var FIELDS = ['#collector_id', '#proxy_account_id'];

    function dataOf(item, key) {
        if (!item || !item.element) {
            return '';
        }

        return item.element.getAttribute('data-' + key) || '';
    }

    function templateResult(item) {
        if (!item.id) {
            return item.text;               /* the placeholder option */
        }

        var row = document.createElement('div');
        row.className = 'pc-opt';

        var name = document.createElement('span');
        name.className = 'pc-opt__name';
        name.textContent = dataOf(item, 'name') || item.text || '';
        row.appendChild(name);

        var area = dataOf(item, 'area');

        if (area) {
            var meta = document.createElement('span');
            meta.className = 'pc-opt__meta';
            meta.textContent = area;
            row.appendChild(meta);
        }

        return row;
    }

    function templateSelection(item) {
        if (!item.id) {
            return item.text;
        }

        return dataOf(item, 'name') || item.text || '';
    }

    function enhance($, selector) {
        var $select = $(selector);
        var el = $select.get(0);

        /* Collector accounts render a hidden input with the same id, so only a
           real <select> is enhanced (otherwise an empty widget appears). */
        if (!$select.length || !el || el.tagName !== 'SELECT' || $select.hasClass('select2-hidden-accessible')) {
            return;
        }

        var $first = $select.find('option').first();
        var placeholder = ($first.attr('value') === '') ? $first.text().trim() : '';

        $select.select2({
            placeholder: placeholder,
            allowClear: false,
            width: '100%',
            templateResult: templateResult,
            templateSelection: templateSelection,
            escapeMarkup: function (markup) {
                return markup;
            }
        });

        /* Select2 triggers a jQuery change only; the page uses
           addEventListener, so forward the selection natively. */
        $select.on('select2:select select2:clear', function () {
            var el = $select.get(0);

            if (el) {
                el.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    }

    function init() {
        if (!window.jQuery || !window.jQuery.fn || !window.jQuery.fn.select2) {
            return;
        }

        var $ = window.jQuery;

        FIELDS.forEach(function (selector) {
            enhance($, selector);
        });

        /* keep the widgets in step when the page resets a native value */
        window.pcSelect2 = {
            refresh: function (selector) {
                var $select = $(selector || '#collector_id');

                if ($select.length && $select.hasClass('select2-hidden-accessible')) {
                    $select.trigger('change.select2');
                }
            }
        };

        /* backwards compatible alias used by the page */
        window.pcCollectorSelect = {
            refresh: function () {
                window.pcSelect2.refresh('#collector_id');
            }
        };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
