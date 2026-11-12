// Vessel cards (Pending Vessels vessels.php, Pending Reports reports.php) — mobile cards .cc-card.pvz
// The page sets window.VC_CFG = { csrf, def }; a card may set data-pv-badge / data-pv-theme for the shared picture.
// 1) Pictures: vessels not looked up yet are asked of the AI (2 at a time, ajax/vessel_image.php);
//    a found picture replaces the YMR ship on the mobile card and in the desktop table.
// 2) Share (mobile card): a designed picture of the vessel (drawn on a canvas) — YMR header, photo, details, latest
//    update — through the phone's share sheet; downloaded where pictures cannot be shared; the
//    details as text if the picture cannot be made.
(function () {
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

  function shareText(text) {
    if (navigator.share) { navigator.share({ text: text }).catch(function () {}); return; }
    window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank', 'noopener');
  }
  function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  // ---- The shared picture, drawn straight onto a canvas (no html2canvas) -------------------------
  // html2canvas copied the whole page and waited for every vessel photo on it, so on a long list the
  // picture took 15–50 s and the tap went stale. Drawing it ourselves takes a few milliseconds, needs
  // no outside script, and pictures are made ahead while the card is on screen.
  var THEMES = {
    '':     { bg: ['#0b2a5b', '#123f86', '#1b6fd1'], line: '#1D63ED', badge: ['#fde4d2', '#c2410c'], soft: '#bcd3f5' },
    red:    { bg: ['#8f1d28', '#b02a37', '#dc3545'], line: '#dc3545', badge: ['#fdf0f1', '#b02a37'], soft: '#fbdfe2' },
    yellow: { bg: ['#b37f00', '#e0a800', '#ffc107'], line: '#ffc107', badge: ['#fff8e1', '#9a6b00'], soft: '#fff6d6' },
    green:  { bg: ['#146c2e', '#1e9e3a', '#28a745'], line: '#28a745', badge: ['#e9f8ec', '#15803d'], soft: '#d9f5df' }
  };
  var FONT = "Lexend, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif";
  var logo = new Image(); logo.src = DEF;
  function usable(im) { return !!(im && im.complete && im.naturalWidth); }
  function font(c, w, px) { c.font = w + ' ' + px + 'px ' + FONT; }
  function rr(c, x, y, w, h, r) {
    c.beginPath(); c.moveTo(x + r, y); c.arcTo(x + w, y, x + w, y + h, r); c.arcTo(x + w, y + h, x, y + h, r);
    c.arcTo(x, y + h, x, y, r); c.arcTo(x, y, x + w, y, r); c.closePath();
  }
  /** Lines of text that fit in width w (breaks long words too), at most max lines. */
  function wrap(c, text, w, max) {
    var out = [], line = '';
    String(text || '').split(/\s+/).filter(Boolean).forEach(function (word) {
      while (c.measureText(word).width > w && word.length > 1) {
        var n = word.length; while (n > 1 && c.measureText(word.slice(0, n)).width > w) n--;
        if (line) { out.push(line); line = ''; }
        out.push(word.slice(0, n)); word = word.slice(n);
      }
      var t = line ? line + ' ' + word : word;
      if (c.measureText(t).width > w && line) { out.push(line); line = word; } else line = t;
    });
    if (line) out.push(line);
    if (!out.length) out.push('');
    if (out.length > max) { out = out.slice(0, max); out[max - 1] = out[max - 1].replace(/.?$/, '…'); }
    return out;
  }
  function imgIn(c, im, x, y, w, h, cover) {
    var iw = im.naturalWidth, ih = im.naturalHeight, k = cover ? Math.max(w / iw, h / ih) : Math.min(w / iw, h / ih);
    var dw = iw * k, dh = ih * k;
    c.save(); c.beginPath(); c.rect(x, y, w, h); c.clip();
    c.drawImage(im, x + (w - dw) / 2, y + (h - dh) / 2, dw, dh); c.restore();
  }
  /** What the picture shows, read from the card. */
  function cardData(card) {
    var q = function (s) { var e = card.querySelector(s); return e ? e.textContent.trim() : ''; };
    var photo = card.querySelector('.pvz-photo img'), real = !!card.querySelector('.pvz-photo.real') && usable(photo);
    var upd = card.querySelector('.pvz-upd-text'), recEl = card.querySelector('.pvz-rec');
    return {
      name: q('.pvz-name') || 'Vessel',
      badge: card.getAttribute('data-pv-badge') || 'Pending Survey',
      theme: THEMES[card.getAttribute('data-pv-theme') || ''] || THEMES[''],
      rows: [].slice.call(card.querySelectorAll('.pvz-i')).map(function (i) {
        var l = i.querySelector('.pvz-l'), b = i.querySelector('.pvz-t b');
        return [l ? l.textContent.trim() : '', b ? b.textContent.trim() : ''];
      }),
      photo: real ? photo : null,
      upd: card.querySelector('.pvz-upd') ? (upd ? upd.innerHTML.split(/<br\s*\/?>/i).map(function (h) {
        var d = document.createElement('div'); d.innerHTML = h; return d.textContent.trim(); }).filter(Boolean) : []) : null,
      rec: recEl ? { tot: (recEl.querySelector('.pvz-rec-tot') || {}).textContent || '',
        parts: [].slice.call(recEl.querySelectorAll('.pvz-rec-parts span')).map(function (x) { return x.textContent.trim(); }).join('   |   ') } : null
    };
  }
  function cardKey(d) { return JSON.stringify([d.name, d.badge, d.rows, d.upd, d.rec, d.photo ? d.photo.currentSrc || d.photo.src : '', usable(logo)]); }
  /** Draws the picture (720 px wide, at 2×). Two passes: the first only measures the height. */
  function drawSheet(d, noPhoto) {
    var W = 720, P = 26, S = 2, T = d.theme, IW = W - 2 * P;
    var cv = document.createElement('canvas'), c = cv.getContext('2d');
    var now = new Date(), when = now.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) + ', ' + now.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
    function pass(draw) {
      var y = P;
      // header: logo, YMR MARINE, badge
      if (draw) {
        c.fillStyle = '#fff'; rr(c, P, y, 74, 37, 10); c.fill();
        if (usable(logo)) imgIn(c, logo, P + 6, y + 3, 62, 31, false);
        c.fillStyle = '#fff'; font(c, 700, 22); c.textBaseline = 'alphabetic'; c.fillText('Y M R  M A R I N E', P + 86, y + 18);
        c.fillStyle = T.soft; font(c, 400, 13); c.fillText('Survey Status', P + 86, y + 36);
        font(c, 800, 15); var bw = c.measureText(d.badge).width + 36;
        c.fillStyle = T.badge[0]; rr(c, W - P - bw, y + 1, bw, 35, 17.5); c.fill();
        c.fillStyle = T.badge[1]; c.fillText(d.badge, W - P - bw + 18, y + 24);
      }
      y += 37 + 18;
      // hero: photo + vessel name
      font(c, 800, 30); var tx = P + 16 + 190 + 18, nameLines = wrap(c, d.name.toUpperCase(), W - P - 16 - tx, 3);
      var hh = Math.max(132, 22 + nameLines.length * 34) + 32;
      if (draw) {
        c.fillStyle = '#fff'; rr(c, P, y, IW, hh, 22); c.fill();
        var px = P + 16, py = y + (hh - 132) / 2;
        c.save(); rr(c, px, py, 190, 132, 16); c.clip(); c.fillStyle = '#eaf2fc'; c.fillRect(px, py, 190, 132);
        if (d.photo && !noPhoto) imgIn(c, d.photo, px, py, 190, 132, true);
        else if (usable(logo)) imgIn(c, logo, px + 8, py + 8, 174, 116, false);
        c.restore();
        var ty = y + hh / 2 - (22 + nameLines.length * 34) / 2;
        c.fillStyle = '#64748b'; font(c, 600, 14); c.fillText('Vessel', tx, ty + 14);
        c.fillStyle = '#0b1324'; font(c, 800, 30);
        nameLines.forEach(function (l, i) { c.fillText(l, tx, ty + 22 + 28 + i * 34); });
      }
      y += hh + 14;
      // details, two per row
      var cw = (IW - 12) / 2;
      for (var i = 0; i < d.rows.length; i += 2) {
        var pair = d.rows.slice(i, i + 2);
        font(c, 700, 18);
        var lines = pair.map(function (r) { return wrap(c, r[1], cw - 16 - 21, 3); });
        var bh = 12 + 17 + 2 + Math.max.apply(null, lines.map(function (l) { return l.length; })) * 24 + 10;
        if (draw) pair.forEach(function (r, k) {
          var x = P + k * (cw + 12);
          c.save(); rr(c, x, y, cw, bh, 16); c.clip(); c.fillStyle = '#fff'; c.fillRect(x, y, cw, bh);
          c.fillStyle = T.line; c.fillRect(x, y, 5, bh); c.restore();
          c.fillStyle = '#64748b'; font(c, 600, 13); c.fillText(r[0], x + 21, y + 12 + 13);
          c.fillStyle = '#0b1324'; font(c, 700, 18);
          lines[k].forEach(function (l, j) { c.fillText(l, x + 21, y + 12 + 17 + 2 + 18 + j * 24); });
        });
        y += bh + 12;
      }
      y += 2;
      // latest updates
      if (d.upd) {
        font(c, 400, 16);
        var ul = [];
        (d.upd.length ? d.upd : ['No updates recorded yet.']).forEach(function (t) { ul = ul.concat(wrap(c, t, IW - 36, 4)); });
        ul = ul.slice(0, 10);
        var uh = 14 + 18 + 4 + ul.length * 24 + 12;
        if (draw) {
          c.fillStyle = '#e8f0fd'; rr(c, P, y, IW, uh, 16); c.fill();
          c.fillStyle = '#1d4ed8'; font(c, 800, 14); c.fillText('Latest Updates', P + 18, y + 14 + 14);
          c.fillStyle = d.upd.length ? '#0b1324' : '#64748b'; font(c, 400, 16);
          ul.forEach(function (l, j) { c.fillText(l, P + 18, y + 14 + 18 + 4 + 17 + j * 24); });
        }
        y += uh + 14;
      }
      // recovery
      if (d.rec) {
        font(c, 600, 15); var rl = d.rec.parts ? wrap(c, d.rec.parts, IW - 41, 3) : [];
        var rh = 14 + 26 + (rl.length ? 6 + rl.length * 21 : 0) + 14;
        if (draw) {
          c.save(); rr(c, P, y, IW, rh, 16); c.clip(); c.fillStyle = '#fff'; c.fillRect(P, y, IW, rh);
          c.fillStyle = '#28a745'; c.fillRect(P, y, 5, rh); c.restore();
          c.fillStyle = '#15803d'; font(c, 800, 16); c.fillText('Recovery', P + 23, y + 14 + 19);
          c.fillStyle = '#0b1324'; font(c, 800, 22); var tw = c.measureText(d.rec.tot.trim()).width; c.fillText(d.rec.tot.trim(), W - P - 18 - tw, y + 14 + 20);
          c.fillStyle = '#334155'; font(c, 600, 15); rl.forEach(function (l, j) { c.fillText(l, P + 23, y + 14 + 26 + 6 + 15 + j * 21); });
        }
        y += rh + 14;
      }
      // footer
      y += 2;
      if (draw) {
        c.fillStyle = T.soft; font(c, 400, 13); c.fillText('Shared from YSMS', P, y + 13);
        var ww = c.measureText(when).width; c.fillText(when, W - P - ww, y + 13);
      }
      return y + 13 + P;
    }
    var H = Math.ceil(pass(false));
    cv.width = W * S; cv.height = H * S; c = cv.getContext('2d'); c.scale(S, S);
    // rounded card with the theme's gradient (160°)
    var g = c.createLinearGradient(W * 0.33, 0, W * 0.67, H);
    g.addColorStop(0, T.bg[0]); g.addColorStop(0.55, T.bg[1]); g.addColorStop(1, T.bg[2]);
    rr(c, 0, 0, W, H, 28); c.fillStyle = g; c.fill();
    pass(true);
    return cv;
  }
  function toBlob(cv) {
    return new Promise(function (res, rej) {
      try { cv.toBlob(function (b) { b ? res(b) : rej(new Error('png')); }, 'image/png'); } catch (err) { rej(err); }
    });
  }
  /** The card's picture as a PNG file; kept per card until something on the card changes. */
  function cardFile(card) {
    var d = cardData(card), key = cardKey(d), hit = cache && cache.get(card);
    if (hit && hit.key === key) return hit.p;
    var fname = (d.name.replace(/[^A-Za-z0-9]+/g, '_').replace(/^_+|_+$/g, '') || 'vessel') + '.png';
    var p = toBlob(drawSheet(d, false)).catch(function () { return toBlob(drawSheet(d, true)); })   // photo not drawable: without it
      .then(function (blob) { var f = new File([blob], fname, { type: 'image/png' }); if (cache) { var h = cache.get(card); if (h && h.p === p) h.file = f; } return f; });
    p.catch(function () { if (cache && cache.get(card) && cache.get(card).p === p) cache.delete(card); });
    if (cache) cache.set(card, { key: key, p: p, file: null });
    return p;
  }
  function readyFile(card) { var h = cache && cache.get(card); return h && h.file && h.key === cardKey(cardData(card)) ? h.file : null; }

  // Phones only open the share sheet while the tap is still "fresh" (Safari about a second,
  // Chrome five). The picture is made ahead (card on screen, finger down), so a tap shares at
  // once; if it is still not fresh, a small sheet whose Share button is a new tap is shown.
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
    var ready = readyFile(card);
    if (ready) {   // made ahead: straight to the share sheet, inside the tap
      if (navigator.share && fileShareable(ready)) return shareFile(ready, name, text);
      return readySheet(ready, name, text);
    }
    btn.classList.add('busy');
    cardFile(card).then(function (file) {
      if (navigator.share && fileShareable(file) && tapFresh()) return shareFile(file, name, text);
      readySheet(file, name, text);
    }).catch(function () {
      // no picture: the details as text — now if the tap is fresh, else one more tap
      if (tapFresh() || !navigator.share) shareText(text); else readySheet(null, name, text);
    }).then(function () { btn.classList.remove('busy'); });
  }, true);
  // finger down on Share: start the picture now, so it is ready by the time the tap ends
  document.addEventListener('pointerdown', function (e) {
    var btn = e.target.closest && e.target.closest('[data-pv-share]'), card = btn && btn.closest('.cc-card');
    if (card) cardFile(card).catch(function () {});
  }, true);

  // a vessel photo that arrives later: remake that card's picture with it
  document.addEventListener('load', function (e) {
    var im = e.target, card = im && im.closest && im.closest('.pvz-photo') && im.closest('.cc-card');
    if (card && cache && cache.get(card)) setTimeout(function () { cardFile(card).catch(function () {}); }, 50);
  }, true);

  /** Make the pictures of the cards on screen ahead, one at a time when the phone is idle. */
  function prepareVisible() {
    if (!cache || !('IntersectionObserver' in window)) return;
    var cards = [].slice.call(document.querySelectorAll('.cc-card.pvz')).filter(function (c) { return c.querySelector('[data-pv-share]'); });
    if (!cards.length) return;
    var queue = [], busy = false, idle = window.requestIdleCallback || function (f) { return setTimeout(f, 60); };
    function next() {
      if (busy || !queue.length) return;
      var card = queue.shift(); busy = true;
      idle(function () { cardFile(card).catch(function () {}).then(function () { busy = false; next(); }); });
    }
    var io = new IntersectionObserver(function (es) {
      es.forEach(function (en) { if (en.isIntersecting && en.target.offsetParent !== null && queue.indexOf(en.target) < 0) { queue.push(en.target); } });
      next();
    }, { rootMargin: '200px 0px' });
    cards.forEach(function (c) { io.observe(c); });
  }

  function start() {
    if (window.matchMedia && matchMedia('(max-width: 991.98px)').matches) {
      if (document.fonts && document.fonts.ready) document.fonts.ready.then(prepareVisible, prepareVisible); else prepareVisible();
    }
  }
  // Do not wait for the page's "load": that waits for every vessel photo (15–50 s on a long list).
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
  function ready() { setTimeout(function () { lookupPictures(0); }, 2000); } // after the page has settled
  if (document.readyState === 'complete') ready(); else window.addEventListener('load', ready);
})();
