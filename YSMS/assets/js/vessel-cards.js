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
    // a few questions per page view, only while the page is on screen; the rest on later visits
    if (!list.length || tries >= 6) return;
    if (document.hidden) { document.addEventListener('visibilitychange', function again() { if (!document.hidden) { document.removeEventListener('visibilitychange', again); lookupPictures(tries); } }); return; }
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
      if (j.more) setTimeout(function () { lookupPictures(tries + 1); }, j.busy ? 8000 : 1500);
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

  // Phones only open the share sheet while the tap is still "fresh" (Safari about a second,
  // Chrome five). Making the picture can take longer — the first time the html2canvas script
  // also has to load — so when the tap has gone stale the picture is offered in a small sheet
  // whose Share button is a new tap. Pictures are kept per card, so the next tap shares at once.
  var cache = typeof WeakMap === 'function' ? new WeakMap() : null;
  function tapFresh() { return !!(navigator.userActivation && navigator.userActivation.isActive); }
  function fileShareable(file) { try { return !!(navigator.canShare && navigator.canShare({ files: [file] })); } catch (err) { return false; } }
  function download(file) {
    var url = URL.createObjectURL(file), a = document.createElement('a');
    a.href = url; a.download = file.name; document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
  }
  function shareFile(file, name, text) {
    return navigator.share({ files: [file], title: name }).catch(function (err) {
      if (err && err.name === 'AbortError') return;          // the user closed the share sheet
      readySheet(file, name, text);                          // tap went stale: ask for one more tap
    });
  }
  var CSS_DONE = false;
  function sheetCss() {
    if (CSS_DONE) return; CSS_DONE = true;
    var st = document.createElement('style');
    st.textContent = '.pvs-ready{position:fixed;inset:0;z-index:2147483000;background:rgba(11,19,36,.55);display:flex;align-items:flex-end;justify-content:center}' +
      '.pvs-ready-box{background:#fff;width:100%;max-width:480px;border-radius:20px 20px 0 0;padding:16px 16px calc(16px + env(safe-area-inset-bottom));box-sizing:border-box;font-family:Lexend,system-ui,sans-serif}' +
      '.pvs-ready-box h3{margin:0 0 10px;font-size:16px;font-weight:600;color:#0b1324}' +
      '.pvs-ready-box img{display:block;width:100%;max-height:52vh;object-fit:contain;border-radius:12px;background:#f1f5f9}' +
      '.pvs-ready-acts{display:flex;gap:8px;margin-top:12px}' +
      '.pvs-ready-acts button{flex:1;height:44px;border-radius:12px;border:1px solid #cbd5e1;background:#fff;color:#0b1324;font:inherit;font-size:15px;font-weight:600;cursor:pointer}' +
      '.pvs-ready-acts button.pri{background:#123f86;border-color:#123f86;color:#fff}';
    document.head.appendChild(st);
  }
  /** One more tap: Share (picture, or the details as text when there is no picture), Save, Close. */
  function readySheet(file, name, text) {
    sheetCss();
    var old = document.querySelector('.pvs-ready'); if (old) old.remove();
    var canFile = file && navigator.share && fileShareable(file);
    var w = document.createElement('div'), url = file ? URL.createObjectURL(file) : '';
    w.className = 'pvs-ready'; w.setAttribute('role', 'dialog'); w.setAttribute('aria-modal', 'true'); w.setAttribute('data-testid', 'vessel-share-sheet');
    w.innerHTML = '<div class="pvs-ready-box"><h3>' + esc(name) + '</h3>' + (file ? '<img alt="" src="' + url + '">' : '') +
      '<div class="pvs-ready-acts">' +
      (canFile || !file ? '<button type="button" class="pri" data-pvs-go data-testid="vessel-share-go">Share</button>' : '') +
      (file ? '<button type="button" class="' + (canFile ? '' : 'pri') + '" data-pvs-save data-testid="vessel-share-save">Save picture</button>' : '') +
      '<button type="button" data-pvs-close data-testid="vessel-share-close">Close</button></div></div>';
    function close() { w.remove(); if (url) URL.revokeObjectURL(url); }
    w.addEventListener('click', function (e) {
      if (e.target === w || e.target.closest('[data-pvs-close]')) return close();
      if (e.target.closest('[data-pvs-save]')) { download(file); return close(); }
      if (e.target.closest('[data-pvs-go]')) {
        close();
        if (file) navigator.share({ files: [file], title: name }).catch(function (err) { if (err && err.name !== 'AbortError') download(file); });
        else shareText(text);
      }
    });
    document.body.appendChild(w);
    var go = w.querySelector('[data-pvs-go], [data-pvs-save]'); if (go) go.focus();
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
    var hit = cache && cache.get(card);
    if (hit && hit.text === text) {
      if (navigator.share && fileShareable(hit.file)) return shareFile(hit.file, name, text);
      return readySheet(hit.file, name, text);
    }
    btn.classList.add('busy');
    cardPng(card).then(function (blob) {
      var fname = name.replace(/[^A-Za-z0-9]+/g, '_').replace(/^_+|_+$/g, '') + '.png';
      var file = new File([blob], fname || 'vessel.png', { type: 'image/png' });
      if (cache) cache.set(card, { file: file, text: text });
      if (navigator.share && fileShareable(file) && tapFresh()) return shareFile(file, name, text);
      readySheet(file, name, text);
    }).catch(function () {
      // no picture (script blocked, offline): the details as text — now if the tap is fresh, else one more tap
      if (tapFresh() || !navigator.share) shareText(text); else readySheet(null, name, text);
    }).then(function () { btn.classList.remove('busy'); });
  }, true);

  function ready() {
    setTimeout(function () { lookupPictures(0); }, 2000); // after the page has settled
    if (document.querySelector('.vessel-list-container .cc-card.pvz') && window.matchMedia && matchMedia('(max-width: 991.98px)').matches) setTimeout(function () { loadH2C().catch(function () {}); }, 1500);
  }
  if (document.readyState === 'complete') ready(); else window.addEventListener('load', ready);
})();
