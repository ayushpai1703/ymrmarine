<?php
/** Expects $chrome, optional $services for footer */
$c = $chrome;
$services = $services ?? [];
?>
<?php
$footer = $c;
$footerServices = $services;
require __DIR__ . '/site_footer.php';
?>
<?php require_once __DIR__ . '/callback_widget.php'; ?>
</body>
</html>
