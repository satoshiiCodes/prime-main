<?php
/**
 * Prime City - Customer registration page.
 *
 * Layout only: validation, branch options and the insert come from
 * actions/customer-registration-actions.php, the availability check goes to
 * api/customer-registration-endpoint.php, layout chrome from includes/*,
 * styles from assets/css and behaviour from assets/js.
 */

require_once __DIR__ . '/actions/customer-registration-actions.php';

try {
    $context = registration_context();
} catch (Throwable $e) {
    error_log('[customer-registration] ' . $e->getMessage());
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
        <head>
            <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
            <meta charset="utf-8" />
            <title>Customer Registration - Unavailable</title>
            <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        </head>
        <body class="bg-light">
            <div class="container py-5">
                <div class="alert alert-danger">
                    <h4 class="alert-heading">The registration form is unavailable</h4>
                    <p class="mb-0">We could not prepare the registration form right now. Please try again in a moment.</p>
                </div>
            </div>
        </body>
    </html>
    <?php
    exit;
}

$user = $context['user'];
$available_branches = $context['available_branches'];
$branch_disabled = $context['branch_disabled'];
$selected_branch = $context['selected_branch'];
$success_message = $context['success_message'];
$error_message = $context['error_message'];

// Shared layout includes read these.
$layout_active = 'customer-registration';
$layout_menu = $context['layout_menu'];

$pageConfig = array(
    'success'  => $success_message,
    'error'    => $error_message,
    'endpoint' => $context['endpoint'],
);

$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <meta name="description" content="Prime City customer registration" />
    <title>Customer Registration - Prime City Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
    <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
    <link href="assets/css/dash.css" rel="stylesheet" />
    <link href="assets/css/customer-registration-style.css" rel="stylesheet" />
    <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
</head>
<body class="sb-nav-fixed">
    <?php include __DIR__ . '/includes/topbar.php'; ?>

    <div id="layoutSidenav">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <div id="layoutSidenav_content">
        <main>
                    <div class="dash">
                        <header class="dash-head">
                            <div>
                                <p class="dash-eyebrow">
                                    <i class="fas fa-user-plus" aria-hidden="true"></i>
                                    Prime City &middot; Customers
                                </p>
                                <h1 class="dash-title">Register Customer</h1>
                                <p class="dash-sub">Personal, contact, employment and account details in one form.</p>
                            </div>

                            <div class="dash-head__actions">
                                <a class="btn btn-primary" href="customer_list.php">
                                    <i class="fas fa-arrow-left me-1" aria-hidden="true"></i>
                                    Back to list
                                </a>
                            </div>
                        </header>

                        <section class="dash-card">
                            <header class="dash-card__head">
                                <span class="dash-card__icon"><i class="fas fa-address-card" aria-hidden="true"></i></span>
                                <div>
                                    <h2 class="dash-card__title">Customer Registration</h2>
                                    <p class="dash-card__hint">Fields marked with an asterisk are required</p>
                                </div>
                            </header>

                            <form id="customerRegistrationForm" class="dash-form" action="customer_registration.php" method="POST" enctype="multipart/form-data">
<?php include __DIR__ . '/includes/customer-registration-form.php'; ?>

                            <div class="dash-form__actions">
                                <a class="btn btn-outline-secondary" href="customer_list.php">Cancel</a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-check me-1" aria-hidden="true"></i>
                                    Register Customer
                                </button>
                            </div>
                            </form>
                        </section>
                    </div>
        </main>
            <?php include __DIR__ . '/includes/footer.php'; ?>
        </div>
    </div>

    <script type="application/json" id="customer-registration-data"><?php echo json_encode($pageConfig, $jsonFlags); ?></script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script src="js/scripts.js"></script>
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="assets/js/pc-toast.js"></script>
    <script src="assets/js/customer-registration.js"></script>
</body>
</html>
