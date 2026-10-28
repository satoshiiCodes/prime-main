/* ==========================================================================
   Prime City - shared toast
   Brand toasts on top of SweetAlert2: white card, gradient accent bar, tinted
   badge, close button and countdown bar. Styles live in assets/css/theme-logo.css
   (.pc-toast*). Used by customer_registration.php and customer_list.php.

   Usage: PCToast.show('success' | 'error' | 'warning' | 'info', title, text)
   ========================================================================== */

(function (window) {
    'use strict';

    var GLYPHS = {
        success: 'fa-circle-check',
        error: 'fa-circle-exclamation',
        warning: 'fa-triangle-exclamation',
        info: 'fa-circle-info'
    };

    function escapeHtml(value) {
        return String(value === undefined || value === null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function show(type, title, text) {
        if (typeof window.Swal === 'undefined') {
            return null;
        }

        var state = GLYPHS[type] ? type : 'info';

        return window.Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            showCloseButton: true,
            timer: 4600,
            timerProgressBar: true,
            padding: 0,
            customClass: {
                container: 'pc-toast-container',
                popup: 'pc-toast pc-toast--' + state,
                htmlContainer: 'pc-toast__container',
                timerProgressBar: 'pc-toast__timer'
            },
            showClass: { popup: 'pc-toast-in' },
            hideClass: { popup: 'pc-toast-out' },
            didOpen: function (toast) {
                toast.addEventListener('mouseenter', window.Swal.stopTimer);
                toast.addEventListener('mouseleave', window.Swal.resumeTimer);
            }
        }).fire({
            html: '<div class="pc-toast__row">'
                + '<span class="pc-toast__badge"><i class="fa-solid ' + GLYPHS[state] + '" aria-hidden="true"></i></span>'
                + '<span class="pc-toast__body">'
                + '<span class="pc-toast__title">' + escapeHtml(title) + '</span>'
                + '<span class="pc-toast__text">' + escapeHtml(text) + '</span>'
                + '</span>'
                + '</div>'
        });
    }

    window.PCToast = {
        show: show,
        success: function (title, text) { return show('success', title, text); },
        error: function (title, text) { return show('error', title, text); },
        warning: function (title, text) { return show('warning', title, text); },
        info: function (title, text) { return show('info', title, text); }
    };
})(window);
