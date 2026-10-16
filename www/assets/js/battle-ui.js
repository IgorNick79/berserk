(function () {
    "use strict";
    var root = document.querySelector("[data-battle-sync-root]");
    if (!root || root.dataset.battleUiReady === "true") return;
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
