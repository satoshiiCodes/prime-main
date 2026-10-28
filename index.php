<?php
/**
 * Prime City - Sign-in page.
 *
 * Layout only: the session bounce and error message come from
 * actions/login-actions.php, styles from assets/css and behaviour from
 * assets/js. The form posts to api/login_process.php, unchanged.
 */

require_once __DIR__ . '/actions/login-actions.php';

try {
    $context = login_context();
} catch (Throwable $e) {
    error_log('[login] ' . $e->getMessage());
    $context = array('error' => '', 'year' => date('Y'), 'app_name' => 'Prime City', 'tagline' => '');
}

$error = $context['error'];
$year = $context['year'];
$app_name = $context['app_name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="description" content="<?= htmlspecialchars($app_name) ?> - sign in to the lending management system" />
    <meta name="robots" content="noindex, nofollow" />
    <meta name="color-scheme" content="light" />
    <title>Sign in &middot; <?= htmlspecialchars($app_name) ?></title>
    <link rel="preconnect" href="https://use.fontawesome.com" />
    <link href="https://use.fontawesome.com/releases/v6.3.0/css/all.css" rel="stylesheet" />
    <link href="assets/css/login-style.css" rel="stylesheet" />
</head>
<body class="auth">
    <aside class="auth__brand">
        <a class="auth__logo" href="index.php" aria-label="<?= htmlspecialchars($app_name) ?> home">
            <img src="assets/images/prime-logo-row.png" alt="<?= htmlspecialchars($app_name) ?>" />
        </a>

        <div class="auth__copy">
            <p class="auth__eyebrow">
                <i class="fas fa-shield-halved" aria-hidden="true"></i>
                Secure sign-in
            </p>
            <h1 class="auth__headline">Lending operations, without the paperwork.</h1>
            <p class="auth__subline">
                Loans, collections, savings and payroll for every branch &mdash; one console for the whole team.
            </p>
            <ul class="auth__points">
                <li>
                    <i class="fas fa-user-check" aria-hidden="true"></i>
                    <span>Role-based access for admins, managers, collectors and customers</span>
                </li>
                <li>
                    <i class="fas fa-clock-rotate-left" aria-hidden="true"></i>
                    <span>Every payment, penalty and adjustment written to the audit trail</span>
                </li>
                <li>
                    <i class="fas fa-chart-line" aria-hidden="true"></i>
                    <span>Branch and area reporting refreshed as you work</span>
                </li>
            </ul>
        </div>

        <p class="auth__footnote">&copy; <?= htmlspecialchars((string) $year) ?> <?= htmlspecialchars($app_name) ?>. All rights reserved.</p>
    </aside>

    <main class="auth__form-side">
        <div class="auth__card">
            <div class="auth__card-head">
                <h2 class="auth__card-title">Welcome back</h2>
                <p class="auth__card-subtitle">Sign in to continue to your dashboard.</p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="auth__alert" role="alert" id="loginError">
                    <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form id="loginForm" action="api/login_process.php" method="post">
                <div class="auth__field">
                    <label class="auth__label" for="username">Username</label>
                    <div class="auth__control">
                        <i class="fas fa-user" aria-hidden="true"></i>
                        <input
                            class="auth__input"
                            type="text"
                            id="username"
                            name="username"
                            placeholder="Enter your username"
                            autocomplete="username"
                            autocapitalize="none"
                            spellcheck="false"
                            required
                            <?= $error !== '' ? 'aria-invalid="true" aria-describedby="loginError"' : '' ?>
                        />
                    </div>
                </div>

                <div class="auth__field">
                    <label class="auth__label" for="password">Password</label>
                    <div class="auth__control auth__control--password">
                        <i class="fas fa-lock" aria-hidden="true"></i>
                        <input
                            class="auth__input"
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Enter your password"
                            autocomplete="current-password"
                            required
                        />
                        <button
                            class="auth__reveal"
                            type="button"
                            id="togglePassword"
                            aria-label="Show password"
                            aria-pressed="false"
                            tabindex="0"
                        >
                            <i class="fas fa-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>

                <div class="auth__row">
                    <label class="auth__check" for="rememberMe">
                        <input type="checkbox" id="rememberMe" name="rememberMe" value="1" />
                        <span>Keep me signed in</span>
                    </label>
                </div>

                <button class="auth__submit" type="submit" id="loginSubmit">
                    <i class="fas fa-arrow-right-to-bracket" aria-hidden="true"></i>
                    Sign in
                </button>
            </form>

            <p class="auth__legal">
                Access is restricted to authorised personnel. Sign-in attempts are logged.
            </p>
        </div>
    </main>

    <div class="auth__toast" id="loginToast" role="alert" aria-live="assertive" aria-atomic="true">
        <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
        <span id="toastMessage">Something went wrong.</span>
        <button type="button" id="toastClose" aria-label="Dismiss">
            <i class="fas fa-xmark" aria-hidden="true"></i>
        </button>
    </div>

    <script src="assets/js/login.js"></script>
</body>
</html>
