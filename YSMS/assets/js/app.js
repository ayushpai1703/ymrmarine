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
        const sort = String($scope.find('[data-list-sort]').val() || 'newest');
        const cards = container.find('[data-survey-card]').get();

        const queryParts = query ? query.split(/\s+/).filter(Boolean) : [];

        cards.sort(function(a, b) {
            const $a = $(a), $b = $(b);
            const nameA = cardAttr($a, 'name').toLowerCase();
            const nameB = cardAttr($b, 'name').toLowerCase();
            const dateA = Number(cardAttr($a, 'date') || 0);
            const dateB = Number(cardAttr($b, 'date') || 0);
            if (sort === 'name-asc') return nameA.localeCompare(nameB);
            if (sort === 'name-desc') return nameB.localeCompare(nameA);
            if (sort === 'oldest') return dateA - dateB;
            return dateB - dateA; // newest
        });
        container.append(cards);

        let visible = 0;
        cards.forEach(function(card) {
            const $card = $(card);
            const haystack = cardAttr($card, 'search').toLowerCase();
            const matchQuery = !queryParts.length || queryParts.every(function(part) {
                return haystack.indexOf(part) !== -1;
            });
            const show = matchQuery
                && (!type || cardAttr($card, 'type') === type)
                && (!place || cardAttr($card, 'place') === place)
                && (!client || cardAttr($card, 'client') === client)
                && (!status || cardAttr($card, 'status') === status)
                && (!surveyor || cardAttr($card, 'surveyor') === surveyor);
            $card.toggle(show);
            if (show) {
                visible++;
                // Sequential "#1, #2, ..." badge — recomputed on every
                // search/filter/sort so it always matches what's actually
                // on screen right now, same as the desktop table's numbering.
                $card.find('[data-row-num]').text(visible);
            }
        });
        $scope.find('[data-list-empty]').toggle(visible === 0);
        $scope.find('[data-list-count]').text(visible);
    }

    $(document).on('input change', '[data-list-search], [data-filter-type], [data-filter-place], [data-filter-client], [data-filter-status], [data-filter-surveyor], [data-list-sort]', function() {
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
        scope.find('[data-filter-type], [data-filter-place], [data-filter-client], [data-filter-status], [data-filter-surveyor]').val('');
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
