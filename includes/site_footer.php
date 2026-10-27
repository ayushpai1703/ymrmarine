<?php
/**
 * Website footer (2026 look). Expects $footer = [logo, footerText, email, phone, address, copyright]
 * and optional $footerServices (rows with title / slug); loads the services itself otherwise.
 * Also runs the shared page script: sticky nav, mobile menu, smooth anchors, reveal on scroll.
 */
$f = $footer;
$footerServices = $footerServices ?? [];
if (!$footerServices && function_exists('getDB')) {
    try {
        require_once __DIR__ . '/service_helpers.php';
        $footerServices = getDB()->query('SELECT title, slug FROM services WHERE is_active = 1 ORDER BY sort_order LIMIT 6')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $footerServices = []; }
}
?>
<footer class="mfoot">
  <div class="mwrap">
    <div class="mfoot-grid">
      <div class="mfoot-brand">
        <a href="index.php" class="mlogo">
          <?php if (!empty($f['logo'])): ?><img src="<?= e($f['logo']) ?>" width="30" height="34" alt=""><?php endif; ?>
          <span><b>YMR Marine</b><small>Solutions LLP</small></span>
        </a>
        <p><?= e($f['footerText'] ?? '') ?></p>
        <div class="mfoot-social">
          <a href="https://www.linkedin.com/company/ymr-marine-solutions-llp" aria-label="LinkedIn" target="_blank" rel="noopener"><i class="fab fa-linkedin-in"></i></a>
          <?php if (!empty($f['email'])): ?><a href="mailto:<?= e($f['email']) ?>" aria-label="Email"><i class="fas fa-envelope"></i></a><?php endif; ?>
          <?php if (!empty($f['phone'])): ?><a href="tel:<?= e(preg_replace('/\s+/', '', $f['phone'])) ?>" aria-label="Phone"><i class="fas fa-phone"></i></a><?php endif; ?>
        </div>
      </div>
      <div>
        <h4>Services</h4>
        <ul>
          <?php foreach (array_slice($footerServices, 0, 6) as $fs):
              $href = function_exists('servicePageUrl') ? servicePageUrl($fs) : 'index.php#services'; ?>
          <li><a href="<?= e($href) ?>"><?= e($fs['title']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div>
        <h4>Company</h4>
        <ul>
          <li><a href="about-us.php">About Us</a></li>
          <li><a href="index.php#why-us">Why Choose Us</a></li>
          <li><a href="index.php#team">Our Team</a></li>
          <li><a href="ports.php">Port Coverage</a></li>
          <li><a href="index.php#testimonials">Clients</a></li>
          <li><a href="contact.php">Contact</a></li>
        </ul>
      </div>
      <div>
        <h4>Contact</h4>
        <ul>
          <?php if (!empty($f['email'])): ?><li><a href="mailto:<?= e($f['email']) ?>"><?= e($f['email']) ?></a></li><?php endif; ?>
          <?php if (!empty($f['phone'])): ?><li><a href="tel:<?= e(preg_replace('/\s+/', '', $f['phone'])) ?>"><?= e($f['phone']) ?></a></li><?php endif; ?>
          <?php if (!empty($f['address'])): ?><li><a href="contact.php"><?= e($f['address']) ?></a></li><?php endif; ?>
        </ul>
      </div>
    </div>
    <div class="mfoot-word" aria-hidden="true">YMR MARINE</div>
    <div class="mfoot-copy"><span><?= e($f['copyright'] ?? '') ?></span><span>Designed for excellence in maritime services</span></div>
  </div>
</footer>
<script>
(function () {
  var nav = document.getElementById('mainNav');
  if (!nav) return;
  var onScroll = function () { nav.classList.toggle('scrolled', window.scrollY > 30); };
  window.addEventListener('scroll', onScroll, { passive: true }); onScroll();
  var toggle = document.getElementById('navToggle'), mobile = document.getElementById('navMobile');
  if (toggle && mobile) {
    toggle.addEventListener('click', function () {
      var open = !mobile.classList.contains('open');
      mobile.classList.toggle('open', open); toggle.classList.toggle('open', open); toggle.setAttribute('aria-expanded', String(open));
    });
    mobile.querySelectorAll('a').forEach(function (a) { a.addEventListener('click', function () { mobile.classList.remove('open'); toggle.classList.remove('open'); toggle.setAttribute('aria-expanded', 'false'); }); });
  }
  document.querySelectorAll('a[href^="#"]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      var id = a.getAttribute('href'); if (id === '#') return;
      var t = document.querySelector(id); if (!t) return;
      e.preventDefault();
      window.scrollTo({ top: t.getBoundingClientRect().top + scrollY - 96, behavior: 'smooth' });
    });
  });
  var els = document.querySelectorAll('.reveal');
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (en) { en.forEach(function (x) { if (x.isIntersecting) { x.target.classList.add('visible'); io.unobserve(x.target); } }); }, { threshold: 0.1 });
    els.forEach(function (el) { io.observe(el); });
  } else { els.forEach(function (el) { el.classList.add('visible'); }); }
})();
</script>
