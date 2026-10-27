<?php
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/service_helpers.php';
ensureServicePageColumns(getDB());
seedServicePagesIfNeeded(getDB());

$pdo = getDB();

// Fetch all dynamic data
$hero = $pdo->query('SELECT * FROM hero WHERE id = 1')->fetch() ?: [];
$about = $pdo->query('SELECT * FROM about WHERE id = 1')->fetch() ?: [];
$services = $pdo->query('SELECT * FROM services WHERE is_active = 1 ORDER BY sort_order')->fetchAll();
$whyUs = $pdo->query('SELECT * FROM why_us WHERE is_active = 1 ORDER BY sort_order')->fetchAll();
$team = $pdo->query('SELECT * FROM team WHERE is_active = 1 ORDER BY sort_order')->fetchAll();
$ports = $pdo->query('SELECT * FROM ports WHERE is_active = 1 ORDER BY sort_order')->fetchAll();
$testimonials = $pdo->query('SELECT * FROM testimonials WHERE is_active = 1 ORDER BY sort_order')->fetchAll();

$address = getSetting('address');
$mapEmbed = getSetting('map_embed');
$footerText = getSetting('footer_text');
$copyright = getSetting('copyright');

// Stats value "5000+" → number and suffix (the suffix gets the accent colour).
$statSplit = function (string $v): array {
    return preg_match('/^(.*?\d)(\D*)$/u', $v, $m) ? [$m[1], $m[2]] : [$v, ''];
};
$heroTitle = (string)($hero['title'] ?? 'Marine Surveys Done Right.');
$heroHl = (string)($hero['title_highlight'] ?? 'Right.');
$heroMain = trim($heroHl !== '' ? str_replace($heroHl, '', $heroTitle) : $heroTitle);
$heroStats = [];
for ($i = 1; $i <= 4; $i++) {
    $v = (string)($hero['stat' . $i . '_value'] ?? '');
    if ($v !== '') $heroStats[] = [$v, (string)($hero['stat' . $i . '_label'] ?? '')];
}
$aboutStats = [];
for ($i = 1; $i <= 4; $i++) {
    $v = (string)($about['stat' . $i . '_value'] ?? '');
    if ($v !== '') $aboutStats[] = [$v, (string)($about['stat' . $i . '_label'] ?? '')];
}
$featured = array_values(array_filter($services, function ($s) { return !empty($s['is_featured']); }));
$others = array_values(array_filter($services, function ($s) { return empty($s['is_featured']); }));
$avGrad = ['#0a6fd8,#02bbff', '#7c5cff,#a78bfa', '#0ea5a4,#34d399', '#f59e0b,#fbbf24'];
?>

<!-- ════════ HERO ════════ -->
<header class="mhero" id="home">
  <div class="mhero-mesh"<?= !empty($hero['bg_image']) ? ' style="--hero-img:url(\'' . e($hero['bg_image']) . '\')"' : '' ?>></div>
  <div class="mhero-lines"></div>
  <div class="mwrap">
    <div class="mhero-grid">
      <div class="mhero-copy">
        <div class="meyebrow"><?= e($hero['eyebrow'] ?? 'Your Global Survey Partner') ?></div>
        <h1><?= e($heroMain) ?><?php if ($heroHl !== ''): ?> <span class="mgrad"><?= e($heroHl) ?></span><?php endif; ?></h1>
        <?php if (!empty($hero['subtitle'])): ?><p class="mlead"><?= $hero['subtitle'] ?></p><?php endif; ?>
        <div class="mcta">
          <a href="<?= e($hero['btn1_link'] ?? 'contact.php') ?>" class="mbtn mbtn-main"><?= e($hero['btn1_text'] ?? 'Request a Survey') ?> <span class="marrow"><i class="fas fa-arrow-right"></i></span></a>
          <a href="<?= e($hero['btn2_link'] ?? '#services') ?>" class="mbtn mbtn-ghost"><?= e($hero['btn2_text'] ?? 'Our Services') ?></a>
        </div>
        <?php if ($testimonials): ?>
        <a href="#testimonials" class="mtrust">
          <span class="mdots"><?php foreach (array_slice($testimonials, 0, 4) as $i => $t): ?><span style="background:linear-gradient(135deg,<?= $avGrad[$i % 4] ?>)"><?= e($t['avatar_initials']) ?></span><?php endforeach; ?></span>
          <span><b>★★★★★</b>&nbsp; What our clients say</span>
        </a>
        <?php endif; ?>
      </div>
      <div class="mviz" aria-hidden="true">
        <div class="mring r4"></div><div class="mring r3"></div><div class="mring r2"></div><div class="mring r1"></div>
        <div class="msweep"></div>
        <div class="mcore"><?php if ($logo): ?><img src="<?= e($logo) ?>" alt=""><?php endif; ?></div>
        <span class="mblip" style="left:22%;top:30%"></span><span class="mblip" style="left:74%;top:24%"></span><span class="mblip" style="left:68%;top:70%"></span><span class="mblip" style="left:30%;top:74%"></span>
        <?php if (!empty($heroStats[3])): ?><div class="mgcard" style="left:2%;top:12%"><div class="k"><?= e($heroStats[3][0]) ?></div><div class="l"><?= e($heroStats[3][1]) ?></div></div><?php endif; ?>
        <div class="mgcard live" style="right:0;top:40%"><i class="fas fa-check"></i><div><b><?= e($about['cert_title'] ?? 'Experienced Surveyors') ?></b><small><?= e($about['cert_subtitle'] ?? 'Quality Assured') ?></small></div></div>
        <?php if (!empty($heroStats[1])): ?><div class="mgcard" style="left:8%;bottom:8%"><div class="k mgrad"><?= e($heroStats[1][0]) ?></div><div class="l"><?= e($heroStats[1][1]) ?></div></div><?php endif; ?>
      </div>
    </div>
    <?php if ($heroStats): ?>
    <div class="mstats">
      <?php foreach ($heroStats as $st): [$n, $suf] = $statSplit($st[0]); ?>
      <div class="mstat"><div class="v"><?= e($n) ?><sup><?= e($suf) ?></sup></div><div class="t"><?= e($st[1]) ?></div></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</header>

<?php if ($services): ?>
<div class="mmarq" aria-hidden="true"><div class="mmarq-track">
  <?php for ($r = 0; $r < 2; $r++): foreach ($services as $s): ?><b><?= e($s['title']) ?></b><em>✦</em><?php endforeach; endfor; ?>
</div></div>
<?php endif; ?>

<!-- ════════ ABOUT ════════ -->
<section class="mlight msec" id="about"><div class="mwrap mabout">
  <div class="reveal">
    <div class="meyebrow dark"><?= e($about['tag'] ?? 'Who We Are') ?></div>
    <?php
    $aTitle = (string)($about['title'] ?? 'Trusted marine expertise since 2006');
    $aTitleHtml = preg_replace('/(\d{4})(?!.*\d{4})/', '<span class="mgrad">$1</span>', e($aTitle), 1);
    ?>
    <h2><?= $aTitleHtml ?></h2>
    <?php
    $body1 = trim($about['body'] ?? '');
    if ($body1) echo '<p class="mmuted">' . nl2br(e(explode("\n\n", $body1)[0])) . '</p>';
    ?>
    <a href="about-us.php" class="mlink">Read More <i class="fas fa-arrow-right"></i></a>
  </div>
  <div class="mbento reveal reveal-delay-2">
    <div class="mb dark">
      <div class="mb-cap"><?= e(trim($about['body2'] ?? '') ?: ($about['cert_title'] ?? '')) ?></div>
      <div class="mb-who"><span class="mav"><i class="fas fa-check"></i></span><div><b><?= e($about['cert_title'] ?? 'Experienced Surveyors') ?></b><small><?= e($about['cert_subtitle'] ?? 'Quality Assured') ?></small></div></div>
    </div>
    <?php foreach ($aboutStats as $st): [$n, $suf] = $statSplit($st[0]); ?>
    <div class="mb"><div class="v"><?= e($n) ?><span><?= e($suf) ?></span></div><div class="t"><?= e($st[1]) ?></div></div>
    <?php endforeach; ?>
  </div>
</div></section>

<!-- ════════ SERVICES ════════ -->
<section class="mlight msec mtop0" id="services"><div class="mwrap">
  <div class="mhead reveal"><div><div class="meyebrow dark">What We Do</div><h2>Comprehensive survey<br>&amp; consultancy services</h2></div></div>
  <div class="msvc">
    <?php
    $svcCard = function (array $s, string $cls) { ?>
      <a href="<?= e(servicePageUrl($s)) ?>" class="ms <?= $cls ?> reveal">
        <?php if (!empty($s['badge'])): ?><span class="mbadge"><?= e($s['badge']) ?></span><?php endif; ?>
        <div class="code"><?= e($s['code']) ?></div>
        <div class="ic"><i class="fas <?= e($s['icon']) ?>"></i></div>
        <h3><?= e($s['title']) ?></h3>
        <p><?= e($s['description']) ?></p>
        <span class="go" aria-hidden="true"><i class="fas fa-arrow-right"></i></span>
      </a>
    <?php };
    foreach ($featured as $s) $svcCard($s, 'big');
    $nOthers = count($others);
    foreach ($others as $i => $s) $svcCard($s, ($nOthers % 3 === 1 && $i === $nOthers - 1) ? 'wide' : '');
    ?>
  </div>
</div></section>

<!-- ════════ WHY US ════════ -->
<section class="msec mdark" id="why-us"><div class="mwrap mwhy">
  <div class="reveal">
    <div class="meyebrow">Our Edge</div>
    <h2>Why shipowners &amp; traders choose <span class="mgrad">YMR</span></h2>
    <a href="#contact" class="mbtn mbtn-main mwhy-cta"><?= e($hero['btn1_text'] ?? 'Request a Survey') ?> <span class="marrow"><i class="fas fa-arrow-right"></i></span></a>
  </div>
  <div class="mwhy-list">
    <?php foreach ($whyUs as $i => $w): ?>
    <div class="mw reveal reveal-delay-<?= ($i % 2) + 1 ?>">
      <div class="n"><i class="fas <?= e($w['icon']) ?>"></i><span><?= sprintf('%02d', $i + 1) ?></span></div>
      <h3><?= e($w['title']) ?></h3>
      <p><?= e($w['body']) ?></p>
    </div>
    <?php endforeach; ?>
  </div>
</div></section>

<!-- ════════ PORTS ════════ -->
<section class="mlight msec" id="ports"><div class="mwrap mcov">
  <div class="reveal">
    <div class="meyebrow dark">Coverage</div>
    <h2>Major ports covered across the globe</h2>
    <p class="mmuted">From major ports across the globe, we mobilise quickly to deliver reliable marine surveying services wherever our clients operate.</p>
    <div class="mchips">
      <?php foreach ($ports as $p): ?><span class="mchip"><?= e($p['name']) ?> <small><?= e($p['state']) ?></small></span><?php endforeach; ?>
    </div>
    <div class="mnote"><i class="fas fa-ship"></i> Extended coverage — Europe on request</div>
    <a href="ports.php" class="mlink">Port Coverage <i class="fas fa-arrow-right"></i></a>
  </div>
  <div class="mmap reveal reveal-delay-2" aria-hidden="true">
    <div class="mdotgrid"></div>
    <div class="marc" style="left:30%;top:24%;width:30%;height:40%;transform:rotate(-10deg)"></div>
    <div class="marc" style="left:52%;top:40%;width:26%;height:30%;transform:rotate(20deg)"></div>
    <?php
    // Rough map positions for the regions the site lists; a name not in this list is just not pinned.
    $pinAt = ['usa' => [13, 30], 'middle east' => [44, 40], 'africa' => [42, 62], 'india' => [56, 46], 'china' => [70, 34],
        'singapore' => [66, 58], 'australia' => [76, 76], 'malaysia' => [63, 52], 'sri lanka' => [57, 55], 'bangladesh' => [60, 41],
        'indonesia' => [70, 64], 'philippines' => [76, 46]];
    $pinned = 0;
    foreach ($ports as $p) {
        $k = strtolower(trim($p['name']));
        if (!isset($pinAt[$k]) || $pinned >= 8) continue;
        $pinned++;
        echo '<div class="mpin" style="left:' . $pinAt[$k][0] . '%;top:' . $pinAt[$k][1] . '%"><i></i>' . e($p['name']) . '</div>';
    }
    ?>
  </div>
</div></section>

<!-- ════════ TEAM ════════ -->
<section class="mlight msec mtop0" id="team"><div class="mwrap">
  <div class="mhead reveal"><div><div class="meyebrow dark">The People Behind It</div><h2>Meet our team</h2></div>
  <p class="mmuted">A compact, experienced team keeping every survey accurate, on time, and dispute-proof.</p></div>
  <div class="mteam">
    <?php foreach ($team as $i => $m): ?>
    <div class="mtm reveal reveal-delay-<?= ($i % 4) + 1 ?>">
      <div class="ph"><?php if (!empty($m['photo'])): ?><img src="<?= e($m['photo']) ?>" alt="<?= e($m['name']) ?>" loading="lazy"><?php else: ?><span><?= e($m['avatar_initials']) ?></span><?php endif; ?></div>
      <div class="in"><h3><?= e($m['name']) ?></h3><div class="r"><?= e($m['role']) ?></div><p><?= e($m['bio']) ?></p></div>
    </div>
    <?php endforeach; ?>
  </div>
</div></section>

<!-- ════════ TESTIMONIALS ════════ -->
<?php if ($testimonials): ?>
<section class="msec mdark" id="testimonials"><div class="mwrap">
  <div class="reveal"><div class="meyebrow">Client Voice</div><h2>What our clients say</h2></div>
  <div class="mtgrid">
    <?php
    $stars = function ($r) {
        $r = (float)$r; $full = (int)floor($r); $o = str_repeat('<i class="fas fa-star"></i>', $full);
        if ($r - $full >= 0.5) $o .= '<i class="fas fa-star-half-alt"></i>';
        return $o;
    };
    $nT = count($testimonials);
    foreach ($testimonials as $i => $t):
        $cls = ($i === 0 && $nT >= 3) ? 'hl' : (($nT === 4 && $i === 3) ? 'wide' : ''); ?>
    <div class="mtq <?= $cls ?> reveal reveal-delay-<?= ($i % 3) + 1 ?>">
      <div><div class="stars"><?= $stars($t['rating']) ?></div><p><?= e($t['quote']) ?></p></div>
      <div class="who"><span class="mav" style="background:linear-gradient(135deg,<?= $avGrad[$i % 4] ?>)"><?= e($t['avatar_initials']) ?></span><div><b><?= e($t['author_name']) ?></b><small><?= e($t['author_role']) ?></small></div></div>
    </div>
    <?php endforeach; ?>
  </div>
</div></section>
<?php endif; ?>

<!-- ════════ CONTACT ════════ -->
<section class="msec mdark mtop0" id="contact"><div class="mwrap"><div class="mcta-box">
  <div class="l reveal">
    <div class="meyebrow ink">Get In Touch</div>
    <h2>Start your survey request today</h2>
    <div class="mcinfo">
      <?php if ($address): ?><div class="mci"><i class="fas fa-building"></i><span><?= e($address) ?></span></div><?php endif; ?>
      <?php if ($email): ?><a class="mci" href="mailto:<?= e($email) ?>"><i class="fas fa-envelope"></i><span><?= e($email) ?></span></a><?php endif; ?>
      <?php if ($phone): ?><a class="mci" href="tel:<?= e(preg_replace('/\s+/', '', $phone)) ?>"><i class="fas fa-phone"></i><span><?= e($phone) ?></span></a><?php endif; ?>
      <div class="mci"><i class="fas fa-clock"></i><span>24 / 7 — including weekends &amp; holidays</span></div>
    </div>
    <?php if ($mapEmbed): ?>
    <div class="mmap-frame"><iframe src="<?= e($mapEmbed) ?>" title="Office location" style="border:0;" allowfullscreen loading="lazy"></iframe></div>
    <?php endif; ?>
  </div>
  <form id="contactForm" class="mform reveal reveal-delay-2" method="POST" action="process_contact.php">
    <h3><?= e($hero['btn1_text'] ?? 'Request a Survey') ?></h3>
    <div class="mf">
      <label class="mfi"><span>Your Name</span><input type="text" name="name" placeholder="John Smith" required></label>
      <label class="mfi"><span>Company</span><input type="text" name="company" placeholder="ABC Shipping Ltd" required></label>
      <label class="mfi"><span>Email</span><input type="email" name="email" placeholder="you@company.com" required></label>
      <label class="mfi"><span>Phone</span><input type="tel" name="phone" placeholder="+91 00000 00000"></label>
      <label class="mfi"><span>Service Required</span><select name="service">
        <option value="">Select a service…</option>
        <?php foreach ($services as $s): ?><option><?= e($s['title']) ?></option><?php endforeach; ?>
        <option>Other</option>
      </select></label>
      <label class="mfi"><span>Port / Location</span><input type="text" name="port" placeholder="e.g. Visakhapatnam"></label>
      <label class="mfi full"><span>Message</span><textarea name="message" placeholder="Tell us more about your requirements, vessel name, ETA…"></textarea></label>
      <button type="submit" class="msend">Send Request <i class="fas fa-arrow-right"></i></button>
    </div>
  </form>
</div></div></section>

<?php
$footer = ['logo' => $logo, 'footerText' => $footerText, 'email' => $email, 'phone' => $phone, 'address' => $address, 'copyright' => $copyright];
$footerServices = $services;
require __DIR__ . '/includes/site_footer.php';
?>

<div class="toast" id="toast" role="status">
  <i class="fas fa-check-circle"></i>
  <span>Request sent! We'll be in touch within the hour.</span>
</div>
<script>
// process_contact.php comes back with ?sent=1
if (/[?&]sent=1/.test(location.search)) {
  var toastEl = document.getElementById('toast');
  toastEl.classList.add('show');
  setTimeout(function () { toastEl.classList.remove('show'); }, 5000);
}
</script>
<?php require_once __DIR__ . '/includes/callback_widget.php'; ?>
</body>
</html>
