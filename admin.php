<?php
/**
 * Legacy entry point.
 *
 * The dashboard now lives in dashboard.php (layout), actions/, api/, config/
 * and includes/. This shim keeps existing links, bookmarks and post-login
 * redirects working by forwarding the request - query string included - to the
 * new page. It can be removed once every reference points at dashboard.php.
 */

$legacyQuery = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
$legacyTarget = 'dashboard.php' . ($legacyQuery !== '' ? '?' . $legacyQuery : '');

if (!headers_sent()) {
    header('Location: ' . $legacyTarget, true, 302);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
        <meta charset="utf-8" />
        <meta http-equiv="refresh" content="0; url=<?php echo htmlspecialchars($legacyTarget, ENT_QUOTES, 'UTF-8'); ?>" />
        <title>Redirecting - Prime City</title>
    </head>
    <body>
        <p>This page has moved to <a href="<?php echo htmlspecialchars($legacyTarget, ENT_QUOTES, 'UTF-8'); ?>">dashboard.php</a>.</p>
    </body>
</html>
