<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/analytics.php';
trackVisit(getDB());

$siteName   = getSetting('site_name', 'YMR Marine');
$phone      = getSetting('phone');
$email      = getSetting('email');
$logo       = getSetting('logo', 'ymr_logo.png');
$empLogin   = getSetting('employee_login_url', '#');
$primary    = getSetting('primary_color', '#02bbff');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php
$currentUrl = "https://www.ymrmarine.com" . ($_SERVER['REQUEST_URI'] ?? '/');

// Allow per-page overrides before including this file
$pageTitle = $pageTitle ?? "Marine Survey Services | Bunker, Draft, Cargo & Pre-Purchase Inspection | YMR Marine Solutions LLP";

$pageDescription = $pageDescription ?? "YMR Marine Solutions LLP provides professional Pre Purchase Inspection, Bunker Survey, Draft Survey, Cargo Survey, On Hire & Off Hire Inspection services across Indian and international ports.";

$pageKeywords = $pageKeywords ?? "Marine Survey, Bunker Survey, Draft Survey, Cargo Survey, Pre Purchase Inspection, On Hire Survey, Off Hire Survey, Ship Inspection India, Marine Surveyor, YMR Marine Solutions";

$logoUrl = "https://www.ymrmarine.com/" . ltrim($logo, '/');
?>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title><?= $pageTitle ?></title>

<meta name="description" content="<?= $pageDescription ?>">
<meta name="keywords" content="<?= $pageKeywords ?>">
<meta name="author" content="YMR Marine Solutions LLP">
<meta name="robots" content="index, follow, max-image-preview:large">
<meta name="language" content="English">
<meta name="revisit-after" content="7 days">

<link rel="canonical" href="<?= $currentUrl ?>">

<!-- Open Graph -->
<meta property="og:type" content="website">
<meta property="og:title" content="<?= $pageTitle ?>">
<meta property="og:description" content="<?= $pageDescription ?>">
<meta property="og:url" content="<?= $currentUrl ?>">
<meta property="og:image" content="<?= $logoUrl ?>">
<meta property="og:site_name" content="YMR Marine Solutions LLP">

<!-- Twitter -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= $pageTitle ?>">
<meta name="twitter:description" content="<?= $pageDescription ?>">
<meta name="twitter:image" content="<?= $logoUrl ?>">

<!-- Theme -->
<meta name="theme-color" content="<?= e($primary) ?>">

<!-- Favicon -->
<!-- Favicon & App Icons -->
<link rel="icon" href="favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
<link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
<link rel="manifest" href="site.webmanifest">
<meta name="msapplication-TileColor" content="#02bbff">
<meta name="msapplication-TileImage" content="android-chrome-192x192.png" type="image/png">

<!-- Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&family=Lexend:wght@100..900&display=swap" rel="stylesheet">

<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">

<link rel="stylesheet" href="assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?: time() ?>">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/modern.css?v=<?= @filemtime(__DIR__ . '/../assets/css/modern.css') ?: time() ?>">

<style>
:root{
    --accent: <?= e($primary) ?>;
}
</style>

<!-- Structured Data -->
<script type="application/ld+json">
{
 "@context":"https://schema.org",
 "@type":"ProfessionalService",
 "name":"YMR Marine Solutions LLP",
 "url":"https://www.ymrmarine.com",
 "logo":"https://www.ymrmarine.com/<?= e($logo) ?>",
 "image":"https://www.ymrmarine.com/<?= e($logo) ?>",
 "telephone":"<?= e($phone) ?>",
 "email":"<?= e($email) ?>",
 "description":"Marine Survey Company providing Pre Purchase Inspection, Bunker Survey, Draft Survey, Cargo Survey, Condition Survey and Marine Consultancy.",
 "areaServed":"Worldwide",
 "serviceType":[
   "Pre Purchase Inspection",
   "Ship Pre Purchase Inspection",
   "Vessel Inspection",
   "Bunker Survey",
   "Draft Survey",
   "Cargo Survey",
   "Condition Survey",
   "On Hire Survey",
   "Off Hire Survey"
 ]
}
</script></head>
<body class="msite mhome">
<?php $navLogo = $logo; $navEmpLogin = $empLogin; $activeNav = 'home'; require __DIR__ . '/site_nav.php'; ?>
