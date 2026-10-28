/* ==========================================================================
   Prime City - Sign-in behaviour
   Password reveal, required-field check and the inline toast.
   ========================================================================== */

(function () {
    'use strict';

    const form = document.getElementById('loginForm');
    const password = document.getElementById('password');
    const reveal = document.getElementById('togglePassword');
    const submit = document.getElementById('loginSubmit');
    const toast = document.getElementById('loginToast');
    const toastMessage = document.getElementById('toastMessage');
    const toastClose = document.getElementById('toastClose');

    let toastTimer = null;

    /**
     * Show the toast for a few seconds.
     *
     * @param {string} message Message to display.
     */
    function showToast(message) {
        if (!toast || !toastMessage) {
            return;
        }

        toastMessage.textContent = message;
        toast.classList.add('is-visible');

        if (toastTimer) {
            window.clearTimeout(toastTimer);
        }

        toastTimer = window.setTimeout(hideToast, 5000);
    }

    /**
     * Hide the toast.
     */
    function hideToast() {
        if (!toast) {
            return;
        }

        toast.classList.remove('is-visible');

        if (toastTimer) {
            window.clearTimeout(toastTimer);
            toastTimer = null;
        }
    }

    if (toastClose) {
        toastClose.addEventListener('click', hideToast);
    }

    /**
     * Toggle the password field between text and password.
     */
    function togglePasswordVisibility() {
        if (!password || !reveal) {
            return;
        }

        const isHidden = password.type === 'password';
        const icon = reveal.querySelector('i');

        password.type = isHidden ? 'text' : 'password';
        reveal.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        reveal.setAttribute('aria-pressed', isHidden ? 'true' : 'false');

        if (icon) {
            icon.classList.toggle('fa-eye', !isHidden);
            icon.classList.toggle('fa-eye-slash', isHidden);
        }

        password.focus({ preventScroll: true });
    }

    if (reveal) {
        reveal.addEventListener('click', togglePasswordVisibility);
    }

    // Inline handler kept for parity with the original markup.
    window.togglePasswordVisibility = togglePasswordVisibility;

    /**
     * Block an empty submit and say why.
     */
    if (form) {
        form.addEventListener('submit', function (event) {
            const usernameField = document.getElementById('username');
            const username = usernameField ? usernameField.value.trim() : '';
            const secret = password ? password.value.trim() : '';

            if (!username || !secret) {
                event.preventDefault();
                showToast('Please enter both your username and password.');
                (username ? password : document.getElementById('username'))?.focus();
                return;
            }

            // Give the operator feedback that something is happening.
            if (submit) {
                submit.disabled = true;
                submit.innerHTML = '<i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Signing in…';
            }
        });
    }
})();
