<?php
/**
 * Prime City - Sign-in page actions.
 *
 * Server-side logic for index.php: the session, the "already signed in" bounce
 * to the right dashboard, and the error message handed back by
 * api/login_process.php as ?error=...
 *
 * Produces no markup - the page includes it and renders.
 */

/**
 * Start the session once.
 *
 * @return void
 */
function login_start_session()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * Is a user signed in?
 *
 * @return bool
 */
function login_is_authenticated()
{
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

/**
 * The landing page for an account type.
 *
 * @param  string $accountType Account type from the session.
 * @return string
 */
function login_dashboard_for($accountType)
{
    if ($accountType === 'Customer') {
        return 'customer_dashboard.php';
    }

    if ($accountType === 'Collector' || $accountType === 'Proxy Collector') {
        return 'collector_dashboard.php';
    }

    // Admin, Area Manager, Branch Manager, Proxy Collector-Cashier, ...
    return 'admin.php';
}

/**
 * Send a signed-in visitor straight to their dashboard.
 *
 * The original skipped this when ?login_success was present; that flag is kept
 * so the behaviour is unchanged.
 *
 * @return void
 */
function login_redirect_if_authenticated()
{
    if (!login_is_authenticated()) {
        return;
    }

    if (!isset($_SESSION['account_type'])) {
        return;
    }

    if (isset($_GET['login_success'])) {
        return;
    }

    $target = login_dashboard_for((string) $_SESSION['account_type']);

    if (!headers_sent()) {
        header('Location: ' . $target);
    }

    exit;
}

/**
 * The message handed back by the sign-in endpoint, ready to render.
 *
 * @return string
 */
function login_error_message()
{
    if (!isset($_GET['error']) || $_GET['error'] === '') {
        return '';
    }

    return trim((string) $_GET['error']);
}

/**
 * Everything the view needs, in one call.
 *
 * @return array<string, mixed>
 */
function login_context()
{
    login_start_session();
    login_redirect_if_authenticated();

    return array(
        'error'      => login_error_message(),
        'year'       => date('Y'),
        'app_name'   => 'Prime City',
        'tagline'    => 'Lending and collections, in one place',
    );
}
