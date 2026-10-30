$(document).ready(function() {
    // Shared profile dropdown
    $(document).on('click', '.profile-menu-trigger', function(e) {
        e.stopPropagation();
        const wrap = $(this).closest('.profile-menu-wrap');
        $('.profile-menu-wrap').not(wrap).removeClass('open').find('.profile-menu-trigger').attr('aria-expanded', 'false');
        wrap.toggleClass('open');
        $(this).attr('aria-expanded', wrap.hasClass('open') ? 'true' : 'false');
    });
    $(document).on('click', function() {
        $('.profile-menu-wrap').removeClass('open').find('.profile-menu-trigger').attr('aria-expanded', 'false');
    });

    // Responsive existing navigation drawer
    // .drawer-mode is what actually switches .bottom-nav-bar from the fixed
    // bottom bar into the fixed/off-screen drawer layout (see header.php); it's
    // only ever present while opening/closing, never left on, so the same
    // element resting between opens is always the plain bottom bar.
    var bodyScrollLockedBy = null; // remembers body's own inline overflow so we restore it exactly
    var drawerCloseTimer = null;
    var DRAWER_TRANSITION_MS = 420; // matches the .4s transform transition + a little slack

    function closeSidebar() {
        var $bar = $('.bottom-nav-bar');
        if (!$bar.hasClass('drawer-mode')) return; // already closed (or never opened) — nothing to do
        clearTimeout(drawerCloseTimer);
        $bar.removeClass('open');
        $('.sidebar-screen-overlay').removeClass('open');
        $('.hamburger-menu-btn').attr('aria-expanded', 'false').attr('aria-label', 'Open navigation menu').removeClass('is-open');
        if (bodyScrollLockedBy !== null) {
            document.body.style.overflow = bodyScrollLockedBy;
            bodyScrollLockedBy = null;
        }
        drawerCloseTimer = setTimeout(function() {
            $bar.removeClass('drawer-mode');
        }, DRAWER_TRANSITION_MS);
    }
    function openSidebar() {
        var $bar = $('.bottom-nav-bar');
        clearTimeout(drawerCloseTimer);
        $bar.addClass('drawer-mode');
        // Force a reflow so the browser commits the off-screen transform from
        // .drawer-mode before .open is added — otherwise there's nothing to
        // animate FROM and the drawer just appears instead of sliding in.
        void $bar[0].offsetHeight;
        requestAnimationFrame(function() {
            $bar.addClass('open');
            $('.sidebar-screen-overlay').addClass('open');
        });
        $('.hamburger-menu-btn').attr('aria-expanded', 'true').attr('aria-label', 'Close navigation menu').addClass('is-open');
        bodyScrollLockedBy = document.body.style.overflow || '';
        document.body.style.overflow = 'hidden';
    }
    $(document).on('click', '.hamburger-menu-btn', function() {
        if ($('.bottom-nav-bar').hasClass('open')) {
            closeSidebar();
        } else {
            openSidebar();
        }
    });
    $(document).on('click', '.sidebar-screen-overlay, .bottom-nav-bar.drawer-mode .nav-item-btn, .drawer-close-btn', closeSidebar);
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && $('.bottom-nav-bar').hasClass('open')) {
            closeSidebar();
        }
    });

    // Global FAB Bottom Drawer Action Animation Engine Match
    $('#globalFabTrigger').on('click', function() {
        var $fab = $(this);
        $fab.addClass('fab-tap');
        setTimeout(function() { $fab.removeClass('fab-tap'); }, 380);

        $('#fabActionOverlay').css('display', 'flex');
        setTimeout(function() {
            $('#fabPopupSheet').css('transform', 'translateY(0)');
        }, 10);
    });

    function closeFab() {
        $('#fabPopupSheet').css('transform', 'translateY(100%)');
        setTimeout(function() {
            $('#fabActionOverlay').css('display', 'none');
        }, 300);
    }

    $('#closeFabBtn, #fabActionOverlay').on('click', function(e) {
        if (e.target === this || this.id === 'closeFabBtn') {
            closeFab();
        }
    });

    // Multi-select filters: <select multiple data-multi> shown as a dropdown with tick boxes.
    // The select stays the source of truth (its options' selected state + a real 'change' event),
    // so the filter code reads it with .val() as before.
    function ysmsMultiLabel(sel) {
        const picked = Array.prototype.filter.call(sel.options, function(o) { return o.selected && o.value !== ''; });
        const allText = sel.options[0] && sel.options[0].value === '' ? sel.options[0].textContent : 'All';
        if (!picked.length) return allText;
        if (picked.length === 1) return picked[0].textContent;
        return picked.length + ' ' + allText.replace(/^All\s+/i, '') + ' selected';
    }
    window.ysmsMultiSync = function(sel) {
        const ui = sel && sel._ysmsMulti;
        if (!ui) return;
        ui.label.textContent = ysmsMultiLabel(sel);
        ui.trigger.classList.toggle('has-value', Array.prototype.some.call(sel.options, function(o) { return o.selected && o.value !== ''; }));
        ui.list.querySelectorAll('input[type=checkbox]').forEach(function(cb) {
            cb.checked = !!(sel.options[cb.dataset.i] && sel.options[cb.dataset.i].selected);
        });
    };
    function ysmsMultiEnhance(sel) {
        if (sel._ysmsMulti || sel.closest('.vd-dd')) return;
        sel.multiple = true;
        Array.prototype.forEach.call(sel.options, function(o) { if (o.value === '') o.selected = false; });
        const wrap = document.createElement('div');
        wrap.className = 'ysms-ms';
        sel.parentNode.insertBefore(wrap, sel);
        wrap.appendChild(sel);
        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'ysms-ms-trigger';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        if (sel.getAttribute('aria-label')) trigger.setAttribute('aria-label', sel.getAttribute('aria-label'));
        const label = document.createElement('span');
        label.className = 'ysms-ms-label';
        trigger.appendChild(label);
        trigger.insertAdjacentHTML('beforeend', '<i class="fa-solid fa-chevron-down" aria-hidden="true"></i>');
        const panel = document.createElement('div');
        panel.className = 'ysms-ms-panel';
        const list = document.createElement('div');
        list.className = 'ysms-ms-list';
        Array.prototype.forEach.call(sel.options, function(o, i) {
            if (o.value === '') return;
            const row = document.createElement('label');
            row.className = 'ysms-ms-opt';
            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.dataset.i = i;
            row.appendChild(cb);
            row.appendChild(document.createTextNode(' ' + o.textContent));
            cb.addEventListener('change', function() {
                o.selected = cb.checked;
                window.ysmsMultiSync(sel);
                sel.dispatchEvent(new Event('change', { bubbles: true }));
            });
            list.appendChild(row);
        });
        const bar = document.createElement('div');
        bar.className = 'ysms-ms-bar';
        bar.innerHTML = '<button type="button" class="ysms-ms-clear">Clear</button><button type="button" class="ysms-ms-done">Done</button>';
        panel.appendChild(list);
        panel.appendChild(bar);
        wrap.appendChild(trigger);
        wrap.appendChild(panel);
        const close = function() { wrap.classList.remove('open'); trigger.setAttribute('aria-expanded', 'false'); };
        trigger.addEventListener('click', function(ev) {
            ev.stopPropagation();
            const open = !wrap.classList.contains('open');
            document.querySelectorAll('.ysms-ms.open').forEach(function(w) { w.classList.remove('open'); });
            wrap.classList.toggle('open', open);
            trigger.setAttribute('aria-expanded', String(open));
        });
        panel.addEventListener('click', function(ev) { ev.stopPropagation(); });
        bar.querySelector('.ysms-ms-done').addEventListener('click', close);
        bar.querySelector('.ysms-ms-clear').addEventListener('click', function() {
            Array.prototype.forEach.call(sel.options, function(o) { o.selected = false; });
            window.ysmsMultiSync(sel);
            sel.dispatchEvent(new Event('change', { bubbles: true }));
        });
        sel._ysmsMulti = { trigger: trigger, label: label, list: list };
        window.ysmsMultiSync(sel);
    }
    document.querySelectorAll('select[data-multi]').forEach(ysmsMultiEnhance);
    document.addEventListener('click', function() {
        document.querySelectorAll('.ysms-ms.open').forEach(function(w) {
            w.classList.remove('open');
            const t = w.querySelector('.ysms-ms-trigger'); if (t) t.setAttribute('aria-expanded', 'false');
        });
    });

    // Reliable local search, sort and filter controls
    // Use .attr() for string data-* values — jQuery .data() can mangle long strings / HTML entities
    function cardAttr($el, name) {
        return String($el.attr('data-' + name) || '').trim();
    }

    function updateSurveyList(scope) {
        const $scope = $(scope);
        const container = $scope.find('[data-list-container]');
        const query = String($scope.find('[data-list-search]').val() || '').toLowerCase().trim();
        // Survey type, place, client and surveyor are multi-select: a card matches any ticked value.
        const picked = function(sel) {
            const v = $scope.find(sel).val();
            return (Array.isArray(v) ? v : (v ? [v] : [])).map(String).filter(Boolean);
        };
        const types = picked('[data-filter-type]');
        const places = picked('[data-filter-place]');
        const clients = picked('[data-filter-client]');
        const status = String($scope.find('[data-filter-status]').val() || '');
        const surveyors = picked('[data-filter-surveyor]');
        // "Name1 + Name2" (surveyors, survey types): matches when any of the names is ticked.
        const anyName = function(value, list) {
            const names = String(value || '').split(' + ').map(function(x) { return x.trim(); });
            return list.some(function(p) { return names.indexOf(p) !== -1; });
        };
        const month = String($scope.find('[data-filter-month]').val() || '');
        const appointment = String($scope.find('[data-filter-appointment]').val() || '');
        const sort = String($scope.find('[data-list-sort]').val() || 'newest');
        const cards = container.find('[data-survey-card]').get();

        const queryParts = query ? query.split(/\s+/).filter(Boolean) : [];

        // Plain DOM here (no jQuery per card): with hundreds of vessels, jQuery's toggle()
        // read each card's computed style and the sort built jQuery objects per comparison,
        // which took seconds on phones.
        const attr = function(el, name) { return String(el.getAttribute('data-' + name) || '').trim(); };
        const keyed = cards.map(function(el) {
            return { el: el, name: attr(el, 'name').toLowerCase(), date: Number(attr(el, 'date') || 0) };
        });
        keyed.sort(function(a, b) {
            if (sort === 'name-asc') return a.name.localeCompare(b.name);
            if (sort === 'name-desc') return b.name.localeCompare(a.name);
            if (sort === 'oldest') return a.date - b.date;
            return b.date - a.date; // newest
        });
        const parent = container.get(0);
        if (parent && keyed.some(function(k, i) { return k.el !== cards[i]; })) {
            const frag = document.createDocumentFragment();
            keyed.forEach(function(k) { frag.appendChild(k.el); });
            parent.appendChild(frag);
        }

        let visible = 0;
        keyed.forEach(function(k) {
            const card = k.el;
            const haystack = attr(card, 'search').toLowerCase();
            const matchQuery = !queryParts.length || queryParts.every(function(part) {
                return haystack.indexOf(part) !== -1;
            });
            const show = matchQuery
                && (!types.length || anyName(attr(card, 'type'), types))
                && (!places.length || places.indexOf(attr(card, 'place')) !== -1)
                && (!clients.length || clients.indexOf(attr(card, 'client')) !== -1)
                && (!status || attr(card, 'status') === status)
                && (!surveyors.length || anyName(attr(card, 'surveyor'), surveyors))
                && (!month || attr(card, 'month') === month)
                && (!appointment || attr(card, 'appointment') === appointment);
            card.style.display = show ? '' : 'none';
            if (show) {
                visible++;
                // Sequential "#1, #2, ..." badge — recomputed on every
                // search/filter/sort so it always matches what's actually
                // on screen right now, same as the desktop table's numbering.
                const num = card.querySelector('[data-row-num]');
                if (num) num.textContent = visible;
            }
        });
        $scope.find('[data-list-empty]').toggle(visible === 0);
        $scope.find('[data-list-count]').text(visible);

        // 🌟 Keep the mobile "Export to Excel" link in sync with whatever's
        // currently filtered on screen — same idea as the desktop export
        // link each of these pages already wires up separately (desktop and
        // mobile filters are different DOM elements, so each needs its own
        // sync). Unknown params are harmless: an export endpoint that
        // doesn't read a given filter (e.g. "month" outside completed.php)
        // just ignores it.
        const $mobileExport = $scope.find('[data-mobile-export]');
        if ($mobileExport.length) {
            const base = $mobileExport.data('export-base') || String($mobileExport.attr('href') || '').split('?')[0];
            const exportParams = [];
            if (query) exportParams.push('q=' + encodeURIComponent(query));
            types.forEach(function(v) { exportParams.push('type[]=' + encodeURIComponent(v)); });
            places.forEach(function(v) { exportParams.push('place[]=' + encodeURIComponent(v)); });
            clients.forEach(function(v) { exportParams.push('client[]=' + encodeURIComponent(v)); });
            surveyors.forEach(function(v) { exportParams.push('surveyor[]=' + encodeURIComponent(v)); });
            if (month) exportParams.push('month=' + encodeURIComponent(month));
            if (appointment) exportParams.push('appointment=' + encodeURIComponent(appointment));
            $mobileExport.attr('href', base + (exportParams.length ? '?' + exportParams.join('&') : ''));
        }
    }

    $(document).on('input change', '[data-list-search], [data-filter-type], [data-filter-place], [data-filter-client], [data-filter-status], [data-filter-surveyor], [data-filter-month], [data-filter-appointment], [data-list-sort]', function() {
        updateSurveyList($(this).closest('[data-list-scope]'));
    });
    $(document).on('click', '[data-filter-toggle]', function() {
        const panel = $(this).closest('[data-list-scope]').find('[data-controls-panel]');
        panel.toggleClass('open');
        $(this).attr('aria-expanded', panel.hasClass('open') ? 'true' : 'false');
    });
    $(document).on('click', '[data-clear-filters]', function() {
        const scope = $(this).closest('[data-list-scope]');
        scope.find('[data-list-search]').val('');
        scope.find('[data-filter-type], [data-filter-place], [data-filter-client], [data-filter-status], [data-filter-surveyor], [data-filter-month], [data-filter-appointment]').val('');
        scope.find('select[data-multi]').each(function() { $(this).val([]); ysmsMultiSync(this); });
        scope.find('[data-list-sort]').val('newest');
        updateSurveyList(scope);
    });

    // Initial numbering/sort pass on every mobile list page (Pending
    // Vessels, Reports, Completed, Cancelled) — without this, the "#1, #2..."
    // badges stay blank until the user first touches a filter/sort control.
    $('[data-list-scope]').each(function() {
        updateSurveyList(this);
    });
});
