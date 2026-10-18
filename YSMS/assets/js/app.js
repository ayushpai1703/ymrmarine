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
    function closeSidebar() {
        $('.bottom-nav-bar, .sidebar-screen-overlay').removeClass('open');
        $('.hamburger-menu-btn').attr('aria-expanded', 'false');
    }
    $(document).on('click', '.hamburger-menu-btn', function() {
        $('.bottom-nav-bar, .sidebar-screen-overlay').toggleClass('open');
        $(this).attr('aria-expanded', $('.bottom-nav-bar').hasClass('open') ? 'true' : 'false');
    });
    $(document).on('click', '.sidebar-screen-overlay, .bottom-nav-bar .nav-item-btn', closeSidebar);

    // Global FAB Bottom Drawer Action Animation Engine Match
    $('#globalFabTrigger').on('click', function() {
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

    // Reliable local search, sort and filter controls
    // Use .attr() for string data-* values — jQuery .data() can mangle long strings / HTML entities
    function cardAttr($el, name) {
        return String($el.attr('data-' + name) || '').trim();
    }

    function updateSurveyList(scope) {
        const $scope = $(scope);
        const container = $scope.find('[data-list-container]');
        const query = String($scope.find('[data-list-search]').val() || '').toLowerCase().trim();
        const type = String($scope.find('[data-filter-type]').val() || '');
        const place = String($scope.find('[data-filter-place]').val() || '');
        const client = String($scope.find('[data-filter-client]').val() || '');
        const status = String($scope.find('[data-filter-status]').val() || '');
        const surveyor = String($scope.find('[data-filter-surveyor]').val() || '');
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
                && (!type || attr(card, 'type') === type)
                && (!place || attr(card, 'place') === place)
                && (!client || attr(card, 'client') === client)
                && (!status || attr(card, 'status') === status)
                && (!surveyor || attr(card, 'surveyor') === surveyor)
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
            if (type) exportParams.push('type=' + encodeURIComponent(type));
            if (place) exportParams.push('place=' + encodeURIComponent(place));
            if (client) exportParams.push('client=' + encodeURIComponent(client));
            if (surveyor) exportParams.push('surveyor=' + encodeURIComponent(surveyor));
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
