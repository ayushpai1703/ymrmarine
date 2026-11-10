/* Live search for list pages whose search runs on the server (GET form with name="q").
   Mark the form with data-live-search and the parts of the page that change with
   data-live-region="key". While typing (short pause), the page is fetched with the
   new query and only those regions are swapped, so the search box keeps focus and the
   phone keyboard stays open. The address bar follows (refresh / back keep the search).
   Enter still submits the form normally if JavaScript fails. */
(function () {
  'use strict';
  var DELAY = 250, timer = null, ctrl = null, seq = 0;

  // wrappers around swapped parts take no space of their own; dim while loading
  var css = document.createElement('style');
  css.textContent = '.ls-region{display:contents}html.ls-busy [data-live-region]{opacity:.65;transition:opacity .15s}';
  document.head.appendChild(css);

  function formUrl(form) {
    var url = new URL(form.getAttribute('action') || location.pathname, location.href);
    var params = new URLSearchParams();
    new FormData(form).forEach(function (v, k) {
      if (k === 'page' || typeof v !== 'string') return;
      if (k === 'q') v = v.trim();
      if (v !== '') params.append(k, v);
    });
    url.search = params.toString();
    return url;
  }

  function run(form, input) {
    var url = formUrl(form), my = ++seq;
    if (ctrl) ctrl.abort();
    ctrl = window.AbortController ? new AbortController() : null;
    document.documentElement.classList.add('ls-busy');
    fetch(url.toString(), { credentials: 'same-origin', headers: { 'X-Live-Search': '1' }, signal: ctrl ? ctrl.signal : undefined })
      .then(function (r) {
        if (!r.ok || r.redirected) throw new Error('HTTP ' + r.status);
        return r.text();
      })
      .then(function (html) {
        if (my !== seq) return;
        var doc = new DOMParser().parseFromString(html, 'text/html');
        document.querySelectorAll('[data-live-region]').forEach(function (old) {
          var key = old.getAttribute('data-live-region');
          var fresh = doc.querySelector('[data-live-region="' + key + '"]');
          if (!fresh || old.contains(input)) return;
          old.replaceWith(document.importNode(fresh, true));
        });
        // the other search box on the page (phone / desktop copies) shows the same text
        document.querySelectorAll('form[data-live-search] input[name="q"]').forEach(function (el) {
          if (el !== input) el.value = input.value;
        });
        // filter/sort links and forms elsewhere carry q as a hidden field
        document.querySelectorAll('input[type="hidden"][name="q"]').forEach(function (el) { el.value = input.value.trim(); });
        try { history.replaceState(history.state, '', url.pathname + url.search); } catch (e) {}
        document.dispatchEvent(new CustomEvent('ysms:live-search', { detail: { url: url.toString() } }));
      })
      .catch(function (e) {
        if (e && e.name === 'AbortError') return;
        // session expired or server error: fall back to a normal page load
        if (my === seq) location.href = url.toString();
      })
      .then(function () { if (my === seq) document.documentElement.classList.remove('ls-busy'); });
  }

  document.addEventListener('input', function (ev) {
    var input = ev.target;
    if (!input || input.name !== 'q') return;
    var form = input.closest('form[data-live-search]');
    if (!form) return;
    clearTimeout(timer);
    timer = setTimeout(function () { run(form, input); }, DELAY);
  });

  // Enter: run now instead of reloading the page
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!form.matches || !form.matches('form[data-live-search]')) return;
    var input = document.activeElement;
    if (!input || input.name !== 'q' || !form.contains(input)) return;
    ev.preventDefault();
    clearTimeout(timer);
    run(form, input);
  });
})();
