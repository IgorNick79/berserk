(function () {
    "use strict";
    var root = document.querySelector("[data-battle-sync-root]");
    if (!root || root.dataset.battleUiReady === "true") return;
    // A single viewport tooltip avoids clipping inside the scrolling InfoPanel.
    var hintNode = document.createElement('div');
    hintNode.id = 'battle-stat-tooltip';
    hintNode.className = 'battle-stat-tooltip';
    hintNode.setAttribute('role', 'tooltip');
    hintNode.hidden = true;
    document.body.appendChild(hintNode);
    var hintSource = null, hintPinned = false, hintSuppressed = null;
    function hideHint() {
        if (hintSource) {
            hintSource.removeAttribute('aria-describedby');
            hintSource.setAttribute('aria-expanded', 'false');
            hintSource.classList.remove('is-hint-open');
        }
        hintNode.hidden = true;
        hintNode.innerHTML = '';
        hintSource = null;
        hintPinned = false;
    }
    function positionHint() {
        if (!hintSource || !hintSource.isConnected) { hideHint(); return; }
        var card = hintSource.closest('.battle-selected-card');
        var media = card && card.querySelector('.panel-card-media');
        if (!media) { hideHint(); return; }
        var area = media.getBoundingClientRect();
        hintNode.style.width = Math.min(area.width - 8, window.innerWidth - 8) + 'px';
        hintNode.style.maxHeight = Math.max(40, Math.min(area.height - 8, window.innerHeight - 16)) + 'px';
        var box = hintNode.getBoundingClientRect();
        var top = hintSource.closest('.panel-card-heading-icons') ? area.top + 4 : area.bottom - box.height - 4;
        hintNode.style.left = Math.max(0, Math.min(area.left + 4, window.innerWidth - box.width - 4)) + 'px';
        hintNode.style.top = Math.max(8, Math.min(top, window.innerHeight - box.height - 8)) + 'px';
    }
    function showHint(source) {
        var content = source.querySelector('.battle-icon-tooltip');
        if (!content) return;
        hideHint();
        hintSource = source;
        hintNode.innerHTML = content.innerHTML;
        hintNode.hidden = false;
        source.setAttribute('aria-describedby', hintNode.id);
        source.setAttribute('aria-expanded', 'true');
        source.classList.add('is-hint-open');
        positionHint();
    }
    function statHint(target) { return target && target.closest ? target.closest('.panel-stat-hint') : null; }
    root.addEventListener('mouseover', function (event) {
        var source = statHint(event.target);
        if (!hintPinned && source && source !== hintSuppressed && source !== hintSource) showHint(source);
    });
    root.addEventListener('mouseout', function (event) {
        var source = statHint(event.target);
        if (source && !source.contains(event.relatedTarget)) {
            if (hintSuppressed === source) hintSuppressed = null;
            if (!hintPinned && !hintNode.contains(event.relatedTarget)) hideHint();
        }
    });
    root.addEventListener('focusin', function (event) {
        var source = statHint(event.target);
        if (!hintPinned && source && source !== hintSuppressed) showHint(source);
    });
    root.addEventListener('focusout', function (event) {
        if (!hintPinned && statHint(event.target)) hideHint();
    });
    hintNode.addEventListener('mouseleave', function () { if (!hintPinned) hideHint(); });
    document.addEventListener('click', function (event) {
        var source = statHint(event.target);
        if (source && !root.contains(source)) source = null;
        if (hintPinned) {
            hintSuppressed = source;
            hideHint();
            if (source) { event.preventDefault(); event.stopImmediatePropagation(); }
            return;
        }
        if (source) {
            event.preventDefault(); event.stopImmediatePropagation();
            hintSuppressed = null;
            showHint(source); hintPinned = true;
        } else if (hintSource) hideHint();
    }, true);
    root.addEventListener('keydown', function (event) {
        var source = statHint(event.target);
        if (!source || (event.key !== 'Enter' && event.key !== ' ')) return;
        event.preventDefault(); event.stopPropagation();
        if (hintPinned) { hintSuppressed = source; hideHint(); }
        else { hintSuppressed = null; showHint(source); hintPinned = true; }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && hintSource) { hintSuppressed = hintSource; hideHint(); }
    });
    window.addEventListener('resize', function () { if (hintSource) positionHint(); });
    document.addEventListener('scroll', function () { if (hintSource) positionHint(); }, true);
    new MutationObserver(function () { if (hintSource && !hintSource.isConnected) hideHint(); })
        .observe(root, {childList: true, subtree: true});
    root.addEventListener('contextmenu', function (event) {
        if (event.target.closest('input, textarea, select, [contenteditable]:not([contenteditable="false"]), .debug-panel, [data-allow-copy]')) return;
        event.preventDefault();
    });
    // Client-only pages survive replacement of fly_zones_html. This does not
    // change the selected card, command URLs, or server UI revision.
    var flyPages = {own: 0, opp: 0};
    var flyHost = root.querySelector("[data-battle-fragment='fly_zones_html']");
    function restoreFlyPages() {
        if (!flyHost) return;
        flyHost.querySelectorAll('[data-fly-side]').forEach(function (zone) {
            var side = zone.dataset.flySide;
            if (!Object.prototype.hasOwnProperty.call(flyPages, side)) return;
            if (Number(zone.dataset.flyCount) <= 3) flyPages[side] = 0;
            zone.querySelectorAll('[data-fly-page]').forEach(function (card) {
                card.hidden = Number(card.dataset.flyPage) !== flyPages[side];
            });
            zone.querySelectorAll('[data-fly-go]').forEach(function (control) {
                control.setAttribute('aria-pressed', String(Number(control.dataset.flyGo) === flyPages[side]));
            });
        });
    }
    if (flyHost) {
        flyHost.addEventListener('click', function (event) {
            var control = event.target.closest('[data-fly-go]');
            if (!control || !flyHost.contains(control)) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            var zone = control.closest('[data-fly-side]');
            var page = Number(control.dataset.flyGo);
            if (zone && Number(zone.dataset.flyCount) > 3 && (page === 0 || page === 1)) {
                flyPages[zone.dataset.flySide] = page;
                restoreFlyPages();
            }
        }, true);
        // The existing sync replaces this host's children, never the host itself.
        new MutationObserver(restoreFlyPages).observe(flyHost, {childList: true});
        restoreFlyPages();
    }
    // Local combat/card view survives snapshots without changing game/UI URLs.
    var combatHost = root.querySelector("[data-battle-fragment='info_panel_html']");
    var combatKey = null, inspectedCombatCard = 0, combatCardViewOpen = false;
    function syncCombatPanel() {
        if (!combatHost) return;
        var content = combatHost.querySelector('.info-panel-content');
        if (!content) return;
        var key = content.dataset.combatKey || '';
        if (key !== combatKey) { combatKey = key; inspectedCombatCard = 0; combatCardViewOpen = false; hideHint(); }
        var selected = content.querySelector("[data-battle-fragment='panel_html']");
        var context = content.querySelector('.info-panel-context');
        var card = selected && selected.querySelector('[data-panel-card-id]');
        var inspect = !!(key && inspectedCombatCard && card && (Number(card.dataset.panelCardId) === inspectedCombatCard || combatCardViewOpen));
        combatCardViewOpen = inspect;
        if (selected) selected.hidden = !!key && !inspect;
        if (context) context.hidden = inspect;
        var footer = combatHost.querySelector('.info-panel-footer');
        if (footer) footer.hidden = inspect;
        content.dataset.combatView = key ? (inspect ? 'card' : 'combat') : 'normal';
    }
    if (combatHost) {
        root.addEventListener('click', function (event) {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            var close = event.target.closest('[data-combat-back]');
            if (close) {
                event.preventDefault(); event.stopImmediatePropagation();
                inspectedCombatCard = 0; hideHint(); syncCombatPanel();
                var heading = combatHost.querySelector('.combat-panel-heading h3');
                if (heading) heading.focus({preventScroll: true});
                return;
            }
            if (!combatKey) return;
            var link = event.target.closest('a[href]');
            if (!link || !root.contains(link)) return;
            var url;
            try { url = new URL(link.href, window.location.href); } catch (error) { return; }
            if (url.origin !== location.origin || url.pathname !== location.pathname) return;
            if (url.searchParams.has('cmd')) {
                inspectedCombatCard = 0; hideHint(); syncCombatPanel();
            } else if (link.classList.contains('card-link') && url.searchParams.has('sel')) {
                inspectedCombatCard = Number(url.searchParams.get('sel')) || 0;
                hideHint(); syncCombatPanel();
                // Existing battle-sync handles selection and retrieves fresh card HTML.
            }
        }, true);
        new MutationObserver(syncCombatPanel).observe(combatHost, {childList: true, subtree: true});
        syncCombatPanel();
    }
    // Pile pages are local presentation state; snapshots must not reset the page.
    var pilePanel = null, pileResize = null, pilePages = {}, paintPile = function () {};
    function mountPilePager() {
        var next = combatHost && combatHost.querySelector('[data-pile-list]');
        if (next === pilePanel) return;
        if (pileResize) pileResize.disconnect();
        pilePanel = next;
        if (!next || next.hidden) { paintPile = function () {}; return; }
        var grid = next.querySelector('[data-pile-grid]'), nav = next.querySelector('[data-pile-pagination]');
        var entries = Array.from(grid.children), key = next.dataset.pileList;
        paintPile = function () {
            if (!next.isConnected || !grid.clientWidth || !grid.clientHeight) return;
            var restoreFocus = nav.contains(document.activeElement);
            entries.forEach(function (entry) { entry.hidden = false; });
            var columns = getComputedStyle(grid).gridTemplateColumns.split(' ').length;
            var gap = parseFloat(getComputedStyle(grid).rowGap) || 0;
            var height = entries.reduce(function (value, entry) { return Math.max(value, entry.getBoundingClientRect().height); }, 1);
            var capacity = Math.max(1, Math.floor((grid.clientHeight + gap) / (height + gap))) * columns;
            var pages = Math.max(1, Math.ceil(entries.length / capacity));
            var page = Math.min(pilePages[key] || 0, pages - 1); pilePages[key] = page;
            entries.forEach(function (entry, index) { entry.hidden = Math.floor(index / capacity) !== page; });
            nav.replaceChildren();
            if (pages > 1) {
                for (var i = 0; i < pages; i++) {
                    if (pages > 7 && i !== 0 && i !== pages - 1 && Math.abs(i - page) > 1) continue;
                    var chip = document.createElement('button'); chip.type = 'button'; chip.dataset.pilePage = String(i);
                    chip.setAttribute('aria-label', 'Страница ' + (i + 1) + ' из ' + pages);
                    chip.setAttribute('aria-current', i === page ? 'page' : 'false'); chip.innerHTML = '<span></span>';
                    nav.appendChild(chip);
                }
            }
            if (restoreFocus) {
                var active = nav.querySelector('[aria-current="page"]'); if (active) active.focus({preventScroll:true});
            }
        };
        nav.addEventListener('click', function (event) {
            var chip = event.target.closest('[data-pile-page]'); if (!chip) return;
            pilePages[key] = Number(chip.dataset.pilePage); paintPile();
        });
        if (window.ResizeObserver) { pileResize = new ResizeObserver(paintPile); pileResize.observe(grid); }
        requestAnimationFrame(paintPile);
    }
    if (combatHost) { new MutationObserver(mountPilePager).observe(combatHost, {childList:true}); mountPilePager(); }
    window.addEventListener('resize', function () { paintPile(); });
    root.dataset.battleUiReady = "true";
}());
