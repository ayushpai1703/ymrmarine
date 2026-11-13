/* Missed Vessels: Mark as Missed / Edit Remarks / Restore modals (vessels.php, vessel_detail.php,
   missed.php). Any element with data-mv-act="mark|edit|restore" + data-mv-id (and data-mv-vessel /
   -client / -port / -surveyor / -remarks for the summary) opens the modal. The server decides
   (ajax/missed_vessels.php); this only collects the reason and shows the result.
   After success: elements with data-mv-row="<id>" fade out, [data-mv-count=pending|missed] update,
   and a page with <body data-mv-reload> (or the button's data-mv-reload) reloads. */
(function () {
  'use strict';
  var C = window.MV_CFG || {};
  var EXAMPLES = [
    'Surveyor could not attend due to scheduling conflict.',
    'Vessel arrived earlier than the expected schedule.',
    'Communication was not received from the agent.',
    'Surveyor was unavailable at the time of vessel arrival.',
    'Vessel operation was completed before our surveyor could attend.',
    'Due to operational or logistical issues, the survey could not be attended.'
  ];
  var TXT = {
    mark: { title: 'Mark Vessel as Missed', icon: 'fa-triangle-exclamation', ok: 'Confirm Move to Missed Vessels',
      note: 'The vessel moves from Pending Vessels to Missed Vessels. All its details, documents and assignments are kept.' },
    edit: { title: 'Edit Missed Remarks', icon: 'fa-pen', ok: 'Save Remarks', note: 'The status stays Missed. The previous remark is kept in the history.' },
    restore: { title: 'Restore to Pending Vessels', icon: 'fa-rotate-left', ok: 'Confirm Restore',
      note: 'The vessel moves back to Pending Vessels with all its details and assignments. The missed history is kept.' }
  };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function key() {
    var a = new Uint8Array(16);
    try { (window.crypto || window.msCrypto).getRandomValues(a); } catch (e) { for (var i = 0; i < 16; i++) a[i] = Math.floor(Math.random() * 256); }
    return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }

  var box, cur = null, busy = false;
  function build() {
    box = document.createElement('div');
    box.className = 'mv-modal';
    box.hidden = true;
    box.setAttribute('data-testid', 'mv-modal');
    box.innerHTML = '<div class="mv-back" data-mv-close></div>' +
      '<div class="mv-dlg" role="dialog" aria-modal="true" aria-labelledby="mvTitle">' +
      '<div class="mv-h"><span class="mv-ic"><i class="fa-solid"></i></span><h3 id="mvTitle" data-testid="mv-title"></h3>' +
      '<button type="button" class="mv-x" data-mv-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>' +
      '<div class="mv-b"><div class="mv-sum" data-testid="mv-summary"></div>' +
      '<div class="mv-fld"><label for="mvRemarks">Reason / Remarks <b>*</b></label>' +
      '<textarea id="mvRemarks" rows="4" maxlength="2000" placeholder="Enter the reason why this vessel was missed..." data-testid="mv-remarks"></textarea>' +
      '<div class="mv-err" role="alert" data-testid="mv-error" hidden></div>' +
      '<div class="mv-ex" data-testid="mv-examples"><span>Examples:</span></div></div>' +
      '<p class="mv-note"></p></div>' +
      '<div class="mv-f"><button type="button" class="mv-btn mv-cancel" data-mv-close data-testid="mv-cancel">Cancel</button>' +
      '<button type="button" class="mv-btn mv-ok" data-testid="mv-confirm"><span></span></button></div></div>';
    document.body.appendChild(box);
    var ex = box.querySelector('.mv-ex');
    EXAMPLES.forEach(function (t) {
      var b = document.createElement('button'); b.type = 'button'; b.textContent = t;
      b.addEventListener('click', function () { var ta = box.querySelector('textarea'); ta.value = t; ta.focus(); err(''); });
      ex.appendChild(b);
    });
    box.addEventListener('click', function (e) { if (e.target.closest('[data-mv-close]') && !busy) close(); });
    box.querySelector('.mv-ok').addEventListener('click', submit);
    box.querySelector('textarea').addEventListener('input', function () { err(''); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !box.hidden && !busy) close(); });
  }
  function err(m) { var e = box.querySelector('.mv-err'); e.textContent = m; e.hidden = !m; box.querySelector('textarea').classList.toggle('bad', !!m); }
  function open(btn) {
    if (!box) build();
    var d = btn.dataset, act = d.mvAct, t = TXT[act];
    if (!t) return;
    cur = { act: act, id: d.mvId, rk: key(), reload: btn.hasAttribute('data-mv-reload') || document.body.hasAttribute('data-mv-reload') };
    box.className = 'mv-modal mv-' + act;
    box.querySelector('.mv-ic i').className = 'fa-solid ' + t.icon;
    box.querySelector('h3').textContent = t.title;
    box.querySelector('.mv-ok span').textContent = t.ok;
    box.querySelector('.mv-note').textContent = t.note;
    var rows = [['Vessel Name', d.mvVessel], ['Client Name', d.mvClient], ['Port', d.mvPort], ['Assigned Surveyor', d.mvSurveyor]];
    if (act === 'restore') rows = [['Vessel Name', d.mvVessel]];
    box.querySelector('.mv-sum').innerHTML = rows.map(function (r) { return '<div><span>' + esc(r[0]) + '</span><b>' + (r[1] ? esc(r[1]) : '—') + '</b></div>'; }).join('') +
      (act === 'restore' ? '<p class="mv-q">Restore <b>' + esc(d.mvVessel || 'this vessel') + '</b> to Pending Vessels?</p>' : '');
    var ta = box.querySelector('textarea');
    ta.value = act === 'edit' ? (d.mvRemarks || '') : '';
    box.querySelector('.mv-fld').hidden = act === 'restore';
    box.querySelector('.mv-ex').hidden = act !== 'mark';
    err(''); setBusy(false);
    box.hidden = false;
    document.documentElement.classList.add('mv-lock');
    setTimeout(function () { (act === 'restore' ? box.querySelector('.mv-ok') : ta).focus(); }, 30);
  }
  function close() { box.hidden = true; cur = null; document.documentElement.classList.remove('mv-lock'); }
  function setBusy(b) {
    busy = b;
    var ok = box.querySelector('.mv-ok');
    ok.disabled = b; box.querySelector('.mv-cancel').disabled = b; box.querySelector('textarea').readOnly = b;
    ok.classList.toggle('loading', b);
  }
  function submit() {
    if (!cur || busy) return;
    var ta = box.querySelector('textarea'), rem = ta.value.trim();
    if (cur.act !== 'restore') {
      if (!rem) { err('Please enter the reason why this vessel was missed.'); ta.focus(); return; }
      if (rem.length < 5) { err('Please enter a little more detail (at least 5 characters).'); ta.focus(); return; }
    }
    var f = new FormData();
    f.append('a', cur.act); f.append('id', cur.id); f.append('rk', cur.rk); f.append('csrf_token', C.csrf || '');
    if (cur.act !== 'restore') f.append('remarks', rem);
    var c = cur;
    setBusy(true);
    fetch(C.api || 'ajax/missed_vessels.php', { method: 'POST', body: f, credentials: 'same-origin', headers: { 'X-CSRF-Token': C.csrf || '' } })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: r.status === 403 ? 'Your session expired. Please refresh the page.' : 'Server error (' + r.status + ').' }; }); })
      .then(function (j) {
        setBusy(false);
        if (!j.ok) { if (c.act === 'restore') { close(); toast(j.error || 'Could not save.', true); } else err(j.error || 'Could not save.'); return; }
        close();
        toast(j.message || 'Saved.');
        counts(j);
        if (c.act !== 'edit') gone(c.id);
        else { var rr = document.querySelectorAll('[data-mv-remarks-of="' + c.id + '"]'); Array.prototype.forEach.call(rr, function (el) { el.textContent = rem; });
               Array.prototype.forEach.call(document.querySelectorAll('[data-mv-act="edit"][data-mv-id="' + c.id + '"]'), function (b) { b.dataset.mvRemarks = rem; }); }
        if (c.reload) setTimeout(function () { location.reload(); }, 700);
        document.dispatchEvent(new CustomEvent('mv:done', { detail: { act: c.act, id: c.id, res: j } }));
      })
      .catch(function () { setBusy(false); err('Network error. Please check the connection and try again.'); });
  }
  function counts(j) {
    ['pending', 'missed'].forEach(function (k) {
      if (typeof j[k] !== 'number') return;
      Array.prototype.forEach.call(document.querySelectorAll('[data-mv-count="' + k + '"]'), function (el) { el.textContent = j[k]; });
    });
  }
  function gone(id) {
    Array.prototype.forEach.call(document.querySelectorAll('[data-mv-row="' + id + '"]'), function (el) {
      el.classList.add('mv-gone');
      setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); document.dispatchEvent(new CustomEvent('mv:removed', { detail: { id: id } })); }, 350);
    });
  }
  var tbox;
  function toast(m, bad) {
    if (!tbox) { tbox = document.createElement('div'); tbox.className = 'mv-toast'; tbox.setAttribute('role', 'status'); tbox.setAttribute('data-testid', 'mv-toast'); document.body.appendChild(tbox); }
    tbox.textContent = m; tbox.className = 'mv-toast show' + (bad ? ' bad' : '');
    clearTimeout(tbox._t); tbox._t = setTimeout(function () { tbox.className = 'mv-toast'; }, 3200);
  }
  // capture phase: the Pending Vessels cards open the vessel on click, so stop that here
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-mv-act]');
    if (!b) return;
    e.preventDefault(); e.stopPropagation();
    open(b);
  }, true);
  window.MV = { open: open, toast: toast, refreshCounts: function () {
    fetch((C.api || 'ajax/missed_vessels.php') + '?a=counts', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) { if (j.ok) counts(j); }).catch(function () {});
  } };
})();
