<?php
/**
 * Prime City - Top navigation bar.
 *
 * Expects `$user` (account_type, first_name, ...) in the including scope.
 * Every account type (admin, manager, collector, proxy collector, cashier)
 * shows the same Prime City logo mark in the top bar.
 */

$topbar_user = isset($user) && is_array($user) ? $user : array();
$topbar_account_type = (string) ($topbar_user['account_type'] ?? '');

// Pages that force a menu variant also force the matching brand, so the top
// bar and the sidebar never disagree.
$topbar_is_collector = isset($layout_menu)
    ? ((string) $layout_menu === 'collector')
    : in_array($topbar_account_type, array('Collector', 'Proxy Collector'), true);

$topbar_name = trim((string) ($topbar_user['first_name'] ?? '')) ?: 'Admin';
$topbar_brand_href = $topbar_is_collector ? 'collector_dashboard.php' : 'dashboard.php';

// Identity for the account chip and the menu header.
$topbar_full_name = trim(implode(' ', array_filter(array(
    trim((string) ($topbar_user['first_name'] ?? '')),
    trim((string) ($topbar_user['middle_name'] ?? '')) !== '' ? strtoupper(substr((string) $topbar_user['middle_name'], 0, 1)) . '.' : '',
    trim((string) ($topbar_user['last_name'] ?? '')),
    trim((string) ($topbar_user['suffix'] ?? '')),
))));

if ($topbar_full_name === '') {
    $topbar_full_name = $topbar_name;
}

$topbar_role = $topbar_account_type !== '' ? $topbar_account_type : 'Signed in';
$topbar_initial = strtoupper(substr($topbar_name, 0, 1));
?>
<style>
    /* Navbar brand (logo variant): the mark is centred inside the brand section
       itself (the 225px slot that lines up with the sidebar), and made as large
       as that slot allows. The bar height and the page offsets live in
       assets/css/theme-logo.css (--pc-nav-h). */
    .sb-topnav .navbar-brand.brand-logo {
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0;
        padding: 0;
        line-height: 0;
    }

    .sb-topnav .navbar-brand.brand-logo img {
        height: clamp(50px, 6vw, 74px);
        width: auto;
        max-width: 90%;
        object-fit: contain;
        display: block;
        margin: 0 auto;
    }

    @media (max-width: 991.98px) {
        .sb-topnav .navbar-brand.brand-logo {
            width: auto;
            min-width: 0;
            padding: 0 0.5rem;
        }

        .sb-topnav .navbar-brand.brand-logo img {
            height: 56px;
            max-width: 50vw;
        }
    }

    @media (max-width: 576px) {
        .sb-topnav .navbar-brand.brand-logo img {
            height: 48px;
            max-width: 46vw;
        }
    }

    /* Prevent navbar from overflowing on very small screens */
    .sb-topnav.navbar {
        flex-wrap: nowrap;
    }
</style>

<nav class="sb-topnav navbar navbar-expand navbar-dark bg-dark">
    <?php /* Same brand for every account type - collectors included. */ ?>
    <a class="navbar-brand brand-logo" href="<?php echo htmlspecialchars($topbar_brand_href, ENT_QUOTES, 'UTF-8'); ?>">
        <img src="assets/images/prime-logo-stroke.png" alt="Prime City">
    </a>

    <button class="btn btn-link btn-sm order-1 order-lg-0 me-4 me-lg-0" id="sidebarToggle" type="button" aria-label="Toggle navigation">
        <i class="fas fa-bars"></i>
    </button>

    <form class="d-none d-md-inline-block form-inline ms-auto me-0 me-md-3 my-2 my-md-0">
        <div class="input-group" hidden>
            <input class="form-control" type="text" placeholder="Search for..." aria-label="Search for..." aria-describedby="btnNavbarSearch" />
            <button class="btn btn-primary" id="btnNavbarSearch" type="button">
                <i class="fas fa-search"></i>
            </button>
        </div>
    </form>

    <ul class="navbar-nav ms-auto ms-md-0 me-2 me-lg-3">
        <li class="nav-item dropdown">
            <a class="nav-link pc-user-toggle dropdown-toggle" id="navbarDropdown" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account menu">
                <span class="pc-avatar" aria-hidden="true"><?php echo htmlspecialchars($topbar_initial, ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="pc-user">
                    <span class="pc-user__name"><?php echo htmlspecialchars($topbar_name, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="pc-user__role"><?php echo htmlspecialchars($topbar_role, ENT_QUOTES, 'UTF-8'); ?></span>
                </span>
                <i class="fas fa-chevron-down pc-caret" aria-hidden="true"></i>
            </a>
            <ul class="dropdown-menu dropdown-menu-end pc-menu" aria-labelledby="navbarDropdown">
                <li class="pc-menu__head">
                    <span class="pc-avatar pc-avatar--lg" aria-hidden="true"><?php echo htmlspecialchars($topbar_initial, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="pc-menu__identity">
                        <strong><?php echo htmlspecialchars($topbar_full_name, ENT_QUOTES, 'UTF-8'); ?></strong>
                        <small><?php echo htmlspecialchars($topbar_role, ENT_QUOTES, 'UTF-8'); ?></small>
                    </span>
                </li>
                <li><hr class="dropdown-divider" /></li>
                <li>
                    <a class="dropdown-item pc-menu__item" href="#">
                        <i class="fas fa-gear" aria-hidden="true"></i>
                        <span>Settings</span>
                    </a>
                </li>
                <li>
                    <a class="dropdown-item pc-menu__item" href="change_password.php">
                        <i class="fas fa-key" aria-hidden="true"></i>
                        <span>Change password</span>
                    </a>
                </li>
                <li><hr class="dropdown-divider" /></li>
                <li>
                    <a class="dropdown-item pc-menu__item pc-menu__logout" href="?logout=true" id="logoutLink">
                        <i class="fas fa-arrow-right-from-bracket" aria-hidden="true"></i>
                        <span>Sign out</span>
                    </a>
                </li>
            </ul>
        </li>
    </ul>
</nav>

<script>
(function () {
    var link = document.getElementById('logoutLink');
    if (!link) {
        return;
    }

    link.addEventListener('click', function (event) {
        event.preventDefault();

        var target = '?logout=true';

        if (typeof Swal === 'undefined') {
            window.location.href = target;
            return;
        }

        Swal.fire({
            title: 'Are you sure?',
            text: 'You are about to log out from the system!',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, logout!'
        }).then(function (result) {
            if (result.isConfirmed) {
                window.location.href = target;
            }
        });
    });
})();
</script>
