<?php
/**
 * Prime City - Page footer.
 *
 * The closing layout columns (`#layoutSidenav_content`, `#layoutSidenav`) are
 * owned by dashboard.php so the layout stays in one place.
 */

$footer_year = date('Y');
?>
<footer class="py-4 bg-light mt-auto">
    <div class="container-fluid px-4">
        <div class="small text-center text-muted">Copyright &copy; Prime City <?php echo htmlspecialchars($footer_year, ENT_QUOTES, 'UTF-8'); ?></div>
    </div>
</footer>
