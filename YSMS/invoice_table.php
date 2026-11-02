<?php
// invoice_table.php — the Invoice Airtable page lives at invoices_airtable.php.
require_once 'config/config.php';
checkAuth();
header('Location: invoices_airtable.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 302);
exit;
