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
        hintNode.style.width = Math.min(area.width, window.innerWidth) + 'px';
        hintNode.style.maxHeight = Math.max(40, Math.min(area.height, window.innerHeight - 16)) + 'px';
        var box = hintNode.getBoundingClientRect();
        var top = hintSource.closest('.panel-card-heading-icons') ? area.top : area.bottom - box.height;
        hintNode.style.left = Math.max(0, Math.min(area.left, window.innerWidth - box.width)) + 'px';
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
    var button = root.querySelector(".battle-menu");
    var host = root.querySelector("[data-battle-fragment='info_panel_html']");
    if (!button || !host) return;
    root.dataset.battleUiReady = "true";
    var open = false;
    var overlay = null;
    var focusInOverlay = false;
    var observer = new MutationObserver(function (records) {
        if (host.querySelector("#battle-debug") !== overlay || records.some(function (record) {
            return overlay && overlay.contains(record.target);
        })) sync();
    });
    function sync() {
        var previous = overlay;
        var active = document.activeElement;
        var restoreFocus = open && focusInOverlay && previous && !previous.isConnected
            && (active === document.body || active === document.documentElement || previous.contains(active));
        overlay = host.querySelector("#battle-debug");
        if (overlay !== previous) {
            observer.disconnect();
            observer.observe(host, {childList: true});
            if (overlay) observer.observe(overlay, {childList: true, subtree: true});
        }
        var available = !!(overlay && overlay.querySelector(".debug-panel"));
        if (!available) open = false;
        button.disabled = !available;
        button.setAttribute("aria-expanded", String(open));
        if (overlay) {
            overlay.hidden = !open;
            overlay.tabIndex = 0;
        }
        if (!open) focusInOverlay = false;
        if (open && restoreFocus) overlay.focus({preventScroll: true});
    }
    document.addEventListener("focusin", function (event) {
        focusInOverlay = !!(overlay && overlay.contains(event.target));
    });
    document.addEventListener("focusout", function (event) {
        if (!overlay || !overlay.contains(event.target)) return;
        if (event.relatedTarget) {
            focusInOverlay = overlay.contains(event.relatedTarget);
            return;
        }
        // Chromium can emit focusout before innerHTML finishes detaching the
        // old overlay. Distinguish that removal from an explicit blur afterward.
        var previous = overlay;
        queueMicrotask(function () {
            if (overlay === previous && previous.isConnected && !previous.contains(document.activeElement)) {
                focusInOverlay = false;
            }
        });
    });
    root.addEventListener("click", function (event) {
        if (!event.target.closest || event.target.closest(".battle-menu") !== button) return;
        open = !open;
        sync();
        if (open) overlay.focus({preventScroll: true});
    });
    document.addEventListener("keydown", function (event) {
        if (event.key !== "Escape" || !open) return;
        open = false;
        sync();
        button.focus({preventScroll: true});
    });
    // Also observe an empty host: debug may become available in a later snapshot.
    observer.observe(host, {childList: true});
    sync();
}());
