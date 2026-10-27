<?php
/**
 * Website navigation (2026 look, assets/css/modern.css). Used by index.php (includes/header.php),
 * the inner pages (includes/public_layout_start.php) and service.php.
 * Expects: $navLogo, $navEmpLogin; optional $activeNav (home|about|services|ports|contact).
 */
$activeNav = $activeNav ?? '';
$navHome = $activeNav === 'home';
$navHash = function (string $id) use ($navHome): string { return ($navHome ? '' : 'index.php') . '#' . $id; };
$navCls = function (string $key) use ($activeNav): string { return $activeNav === $key ? ' class="active" aria-current="page"' : ''; };
?>
<nav class="mnav" id="mainNav" aria-label="Main">
  <div class="mwrap">
    <div class="mnav-in">
      <a href="index.php" class="mlogo" aria-label="YMR Marine Solutions LLP — Home">
        <?php if (!empty($navLogo)): ?><img src="<?= e($navLogo) ?>" width="30" height="34" alt=""><?php endif; ?>
        <span><b>YMR Marine</b><small>Solutions LLP</small></span>
      </a>
      <div class="mnav-links">
        <a href="index.php"<?= $navCls('home') ?>>Home</a>
        <a href="about-us.php"<?= $navCls('about') ?>>About</a>
        <a href="<?= e($navHash('services')) ?>"<?= $navCls('services') ?>>Services</a>
        <a href="<?= e($navHash('why-us')) ?>">Why Us</a>
        <a href="<?= e($navHash('team')) ?>">Team</a>
        <a href="ports.php"<?= $navCls('ports') ?>>Ports</a>
        <a href="<?= e($navHash('testimonials')) ?>">Clients</a>
        <a href="contact.php"<?= $navCls('contact') ?>>Contact</a>
      </div>
      <div class="mnav-r">
        <a href="<?= e($navEmpLogin ?? '#') ?>" class="mpill line" target="_blank" rel="noopener">Employee Login</a>
        <a href="<?= e($navHome ? '#contact' : 'contact.php') ?>" class="mpill solid">Request a Survey</a>
        <button class="mnav-toggle" id="navToggle" type="button" aria-label="Open menu" aria-expanded="false" aria-controls="navMobile"><span></span><span></span><span></span></button>
      </div>
    </div>
    <div class="mnav-mobile" id="navMobile">
      <a href="index.php"<?= $navCls('home') ?>>Home</a>
      <a href="about-us.php"<?= $navCls('about') ?>>About</a>
      <a href="<?= e($navHash('services')) ?>">Services</a>
      <a href="<?= e($navHash('why-us')) ?>">Why Us</a>
      <a href="<?= e($navHash('team')) ?>">Team</a>
      <a href="ports.php"<?= $navCls('ports') ?>>Ports</a>
      <a href="<?= e($navHash('testimonials')) ?>">Clients</a>
      <a href="contact.php"<?= $navCls('contact') ?>>Contact</a>
      <a href="<?= e($navEmpLogin ?? '#') ?>" class="mpill line" target="_blank" rel="noopener">Employee Login</a>
    </div>
  </div>
</nav>
