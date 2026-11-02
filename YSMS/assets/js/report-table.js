/* Pending Reports / Completed Vessels table menus (includes/report_table.php renders them).
   Sorting, filtering and paging happen on the server: the menus only hold links and GET forms. */
(function () {
  'use strict';
  var open = null, opener = null;

  function place(menu, btn) {
    if (window.matchMedia('(max-width: 640px)').matches) { // bottom sheet on phones
      menu.style.left = menu.style.top = menu.style.maxHeight = '';
      return;
    }
    var r = btn.getBoundingClientRect(), vw = document.documentElement.clientWidth, vh = window.innerHeight, edge = 8;
    menu.style.maxHeight = '';
    var w = menu.offsetWidth, h = menu.offsetHeight;
    var below = vh - r.bottom - 6 - edge, above = r.top - 6 - edge;
    var up = h > below && above > below;
    menu.style.maxHeight = Math.max(200, up ? above : below) + 'px';
    h = menu.offsetHeight;
    menu.style.top = (up ? Math.max(edge, r.top - 6 - h) : r.bottom + 6) + 'px';
    menu.style.left = Math.max(edge, Math.min(r.left, vw - edge - w)) + 'px';
  }
  function close() {
    if (!open) return;
    open.hidden = true;
    if (opener) opener.setAttribute('aria-expanded', 'false');
    document.documentElement.classList.remove('rt-sheet-open');
    open = opener = null;
  }
  function show(key, btn) {
    var menu = document.querySelector('[data-rt-menu="' + key + '"]');
    if (!menu) return;
    var same = open === menu;
    close();
    if (same) return;
    menu.hidden = false;
    open = menu; opener = btn;
    btn.setAttribute('aria-expanded', 'true');
    if (window.matchMedia('(max-width: 640px)').matches) document.documentElement.classList.add('rt-sheet-open');
    place(menu, btn);
    var find = menu.querySelector('[data-rt-find]');
    if (find && !window.matchMedia('(pointer: coarse)').matches) find.focus();
  }

  document.addEventListener('click', function (ev) {
    var t = ev.target;
    var btn = t.closest('[data-rt-open]');
    if (btn) { ev.preventDefault(); ev.stopPropagation(); show(btn.getAttribute('data-rt-open'), btn); return; }
    if (t.closest('[data-rt-close]')) { close(); return; }
    var menu = t.closest('[data-rt-menu]');
    if (!menu) { close(); return; }
    var form = t.closest('[data-rt-filter]');
    if (t.closest('[data-rt-all]') && form) {
      form.querySelectorAll('.rt-opt').forEach(function (l) { if (!l.hidden) l.querySelector('input').checked = true; });
    } else if (t.closest('[data-rt-none]') && form) {
      form.querySelectorAll('.rt-opt input').forEach(function (cb) { cb.checked = false; });
    } else if (t.closest('[data-rt-month-toggle]')) {
      var b = t.closest('[data-rt-month-toggle]'), box = menu.querySelector('[data-rt-months]');
      box.hidden = !box.hidden;
      b.setAttribute('aria-expanded', String(!box.hidden));
      if (opener) place(menu, opener);
    } else if (t.closest('[data-rt-year]')) {
      var y = t.closest('[data-rt-year]'), grid = menu.querySelector('[data-rt-year-months="' + y.getAttribute('data-rt-year') + '"]');
      var wasOpen = !grid.hidden;
      menu.querySelectorAll('[data-rt-year-months]').forEach(function (g) { g.hidden = true; });
      menu.querySelectorAll('[data-rt-year]').forEach(function (g) { g.classList.remove('open'); });
      grid.hidden = wasOpen;
      y.classList.toggle('open', !wasOpen);
      if (opener) place(menu, opener);
    }
  });

  // Select all on the whole list = no filter (shorter link, same result).
  document.addEventListener('submit', function (ev) {
    var form = ev.target.closest('[data-rt-filter]');
    if (!form) return;
    var boxes = form.querySelectorAll('.rt-opt input');
    var all = boxes.length > 0 && Array.prototype.every.call(boxes, function (cb) { return cb.checked; });
    if (all) boxes.forEach(function (cb) { cb.disabled = true; });
  });

  document.addEventListener('input', function (ev) {
    var f = ev.target.closest('[data-rt-find]');
    if (!f) return;
    var q = f.value.toLowerCase().trim();
    f.closest('[data-rt-filter]').querySelectorAll('.rt-opt').forEach(function (l) {
      l.hidden = q !== '' && l.textContent.toLowerCase().indexOf(q) === -1;
    });
  });

  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') close(); });
  window.addEventListener('resize', function () { if (open && opener) place(open, opener); });
  window.addEventListener('scroll', function (ev) {
    if (!open || !opener || (ev.target && ev.target.closest && ev.target.closest('[data-rt-menu]'))) return;
    place(open, opener);
  }, true);

  // Search box: the × clears the search.
  document.querySelectorAll('[data-rt-search]').forEach(function (form) {
    var input = form.querySelector('input[name="q"]');
    var x = form.querySelector('[data-rt-search-clear]');
    if (x && input) x.addEventListener('click', function () { input.value = ''; form.submit(); });
  });
})();
