// Vessel cards (Pending Vessels vessels.php, Pending Reports reports.php) — mobile cards .cc-card.pvz
// The page sets window.VC_CFG = { csrf, def }; a card may set data-pv-badge / data-pv-theme for the shared picture.
// 1) Pictures: vessels not looked up yet are asked of the AI (2 at a time, ajax/vessel_image.php);
//    a found picture replaces the YMR ship on the mobile card and in the desktop table.
// 2) Share (mobile card): a designed picture of the vessel — YMR header, photo, details, latest
//    update — through the phone's share sheet; downloaded where pictures cannot be shared; the
//    details as text if the picture cannot be made.
(function () {
  var H2C = 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
  var CFG = window.VC_CFG || {}, DEF = CFG.def || 'assets/images/ymr-ship.png';

  function lookupPictures(tries) {
    var keys = {};
    document.querySelectorAll('img[data-vimg]').forEach(function (im) { var st = im.getAttribute('data-vimg-st'); if (st === 'pending' || st === 'unknown') keys[im.getAttribute('data-vimg')] = 1; });
    var list = Object.keys(keys).slice(0, 40);
    if (!list.length || tries > 20) return;
    var f = new FormData(); f.append('a', 'lookup'); f.append('csrf_token', CFG.csrf || ''); list.forEach(function (k) { f.append('names[]', k); });
    fetch('ajax/vessel_image.php', { method: 'POST', body: f, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j || !j.ok) return;
      Object.keys(j.states || {}).forEach(function (k) {
        var st = j.states[k];
        document.querySelectorAll('img[data-vimg]').forEach(function (im) {
          if (im.getAttribute('data-vimg') !== k) return;
          im.setAttribute('data-vimg-st', st.status);
          if (st.status === 'found' && im.getAttribute('src') !== st.img) { im.src = st.img; im.parentNode.classList.add('real'); }
        });
      });
      if (j.more) setTimeout(function () { lookupPictures(tries + 1); }, 400);
    }).catch(function () {});
  }

  var h2cP = null;
  function loadH2C() {
    if (window.html2canvas) return Promise.resolve(window.html2canvas);
    if (!h2cP) h2cP = new Promise(function (res, rej) {
      var sc = document.createElement('script'); sc.src = H2C; sc.async = true;
      sc.onload = function () { window.html2canvas ? res(window.html2canvas) : rej(new Error('html2canvas')); };
      sc.onerror = function () { h2cP = null; rej(new Error('html2canvas')); };
      document.head.appendChild(sc);
    });
    return h2cP;
  }
  function shareText(text) {
    if (navigator.share) { navigator.share({ text: text }).catch(function () {}); return; }
    window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank', 'noopener');
  }
  function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function waitImages(root) {
    return Promise.all([].slice.call(root.querySelectorAll('img')).map(function (im) {
      return im.complete && im.naturalWidth ? null : new Promise(function (r) { im.onload = im.onerror = function () { r(); }; setTimeout(r, 4000); });
    }));
  }
  /** The picture that is shared: built from the card's own values, 720 px wide, drawn at 2×. */
  function shareSheet(card) {
    var q = function (s) { var e = card.querySelector(s); return e ? e.textContent.trim() : ''; };
    var rows = [].slice.call(card.querySelectorAll('.pvz-i')).map(function (i) { return [i.querySelector('.pvz-l').textContent.trim(), i.querySelector('.pvz-t b').textContent.trim()]; });
    var photo = card.querySelector('.pvz-photo img'), real = card.querySelector('.pvz-photo.real');
    var upd = card.querySelector('.pvz-upd-text'), hasUpd = !!card.querySelector('.pvz-upd'), updHtml = upd ? upd.innerHTML : '<span style="color:#64748b">No updates recorded yet.</span>';
    var badge = card.getAttribute('data-pv-badge') || 'Pending Survey', theme = card.getAttribute('data-pv-theme') || '';
    var recEl = card.querySelector('.pvz-rec'), rec = recEl ? { tot: recEl.querySelector('.pvz-rec-tot').textContent.trim(),
      parts: [].slice.call(recEl.querySelectorAll('.pvz-rec-parts span')).map(function (x) { return x.textContent.trim(); }).join('   |   ') } : null;
    var now = new Date(), when = now.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) + ', ' + now.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
    var el = document.createElement('div');
    el.className = 'pvs-sheet' + (theme ? ' pvs-' + theme : '');
    el.innerHTML =
      '<div class="pvs-top"><div class="pvs-brand"><img src="' + DEF + '" alt=""><div><b>YMR MARINE</b><span>Survey Status</span></div></div><span class="pvs-badge">' + esc(badge) + '</span></div>' +
      '<div class="pvs-hero"><div class="pvs-photo' + (real ? ' real' : '') + '"><img src="' + esc(photo ? photo.getAttribute('src') : DEF) + '" alt=""></div>' +
        '<div class="pvs-hero-t"><span>Vessel</span><b>' + esc(q('.pvz-name')) + '</b></div></div>' +
      '<div class="pvs-grid">' + rows.map(function (r) { return '<div class="pvs-f"><span>' + esc(r[0]) + '</span><b>' + esc(r[1]) + '</b></div>'; }).join('') + '</div>' +
      (hasUpd ? '<div class="pvs-upd"><span>Latest Updates</span><div>' + updHtml + '</div></div>' : '') +
      (rec ? '<div class="pvs-rec"><div class="pvs-rec-top"><span>Recovery</span><b>' + esc(rec.tot) + '</b></div>' + (rec.parts ? '<div class="pvs-rec-parts">' + esc(rec.parts) + '</div>' : '') + '</div>' : '') +
      '<div class="pvs-foot"><span>Shared from YSMS</span><span>' + esc(when) + '</span></div>';
    document.body.appendChild(el);
    return el;
  }
  function cardPng(card) {
    return loadH2C().then(function (h2c) {
      var el = shareSheet(card);
      return waitImages(el).then(function () {
        return h2c(el, { scale: 2, backgroundColor: null, useCORS: true, logging: false });
      }).then(function (canvas) {
        el.remove();
        return new Promise(function (res, rej) { canvas.toBlob(function (b) { b ? res(b) : rej(new Error('png')); }, 'image/png'); });
      }, function (err) { el.remove(); throw err; });
    });
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('[data-pv-share]');
    if (!btn) return;
    e.preventDefault(); e.stopPropagation();
    if (btn.classList.contains('busy')) return;
    var text = btn.getAttribute('data-share-text') || '';
    var card = btn.closest('.cc-card');
    var name = ((card && card.querySelector('.pvz-name') || {}).textContent || 'vessel').trim();
    if (!card) return shareText(text);
    btn.classList.add('busy');
    cardPng(card).then(function (blob) {
      var fname = name.replace(/[^A-Za-z0-9]+/g, '_').replace(/^_+|_+$/g, '') + '.png';
      var file = new File([blob], fname || 'vessel.png', { type: 'image/png' });
      if (navigator.canShare && navigator.canShare({ files: [file] })) {
        return navigator.share({ files: [file], title: name }).catch(function (err) { if (err && err.name !== 'AbortError') throw err; });
      }
      var url = URL.createObjectURL(blob), a = document.createElement('a');
      a.href = url; a.download = file.name; document.body.appendChild(a); a.click(); a.remove();
      setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
    }).catch(function () { shareText(text); }).then(function () { btn.classList.remove('busy'); });
  }, true);

  function ready() {
    lookupPictures(0);
    if (document.querySelector('.vessel-list-container .cc-card.pvz') && window.matchMedia && matchMedia('(max-width: 991.98px)').matches) setTimeout(function () { loadH2C().catch(function () {}); }, 1500);
  }
  if (document.readyState === 'complete') ready(); else window.addEventListener('load', ready);
})();
