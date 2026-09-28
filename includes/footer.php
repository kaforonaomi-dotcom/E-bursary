<?php
/**
 * Global Page Footer
 * E-Bursary Management System
 */
if (!isset($basePath)) {
    $basePath = (strpos($_SERVER['SCRIPT_NAME'], '/admin/') !== false || strpos($_SERVER['SCRIPT_NAME'], '/applicant/') !== false) ? '../' : './';
}
?>
    <footer class="app-footer">
        <div class="container-fluid">
            <span>&copy; <?php echo date('Y'); ?> <?php echo e(isset($systemName) ? $systemName : 'E-Bursary Management System'); ?>. All rights reserved.</span>
        </div>
    </footer>
</div><!-- /.main-wrapper -->
</div><!-- /.d-flex -->

<!-- Bootstrap 5 Bundle JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- Custom App JS -->
<script src="<?php echo $basePath; ?>assets/app.js"></script>
</body>
</html>

