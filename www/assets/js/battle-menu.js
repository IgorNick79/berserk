(function () {
    "use strict";
    window.BerserkBattleMenu = {
        create: function (root, send, busy, soundEnabled) {
            var button = root.querySelector(".battle-menu"), menu = root.querySelector("#battle-menu-panel");
            var host = root.querySelector("[data-battle-fragment='info_panel_html']");
            if (!button || !menu || !host) return null;
            var sound = menu.querySelector("[data-menu-sound]"), debug = menu.querySelector("[data-menu-debug]");
            var debugUrl = debug.dataset.debugUrl || debug.getAttribute("href"), open = false, debugOpen = false, overlay = null;
            var focusInDebug = false, lastBusy = false, destroyed = false;
            button.disabled = false;
            function paused() { return root.getAttribute("data-match-paused") === "1"; }
            function items() {
                return Array.from(menu.querySelectorAll("[role^='menuitem']")).filter(function (node) {
                    return !node.disabled && node.getAttribute("aria-disabled") !== "true";
                });
            }
            function closeMenu(restore) {
                open = false; menu.hidden = true; button.setAttribute("aria-expanded", "false");
                if (restore) button.focus({preventScroll: true});
            }
            function closeDebug(restore) {
                debugOpen = false; focusInDebug = false;
                if (overlay) overlay.hidden = true;
                if (restore) button.focus({preventScroll: true});
            }
            function syncDebug() {
                var previous = overlay, active = document.activeElement;
                var restore = debugOpen && focusInDebug && previous && !previous.isConnected
                    && (active === document.body || active === document.documentElement || previous.contains(active));
                overlay = host.querySelector("#battle-debug");
                if (paused() || !overlay || !overlay.querySelector(".debug-panel")) debugOpen = false;
                if (overlay) { overlay.hidden = !debugOpen; overlay.tabIndex = 0; }
                if (!debugOpen) focusInDebug = false;
                if (debugOpen && restore) overlay.focus({preventScroll: true});
            }
            function render() {
                if (destroyed) return;
                var waiting = busy(), enabled = soundEnabled();
                sound.setAttribute("aria-checked", String(enabled));
                sound.querySelector("[data-sound-check]").hidden = !enabled;
                sound.disabled = waiting;
                menu.querySelector("[data-menu-saving]").hidden = !waiting;
                debug.setAttribute("aria-disabled", String(paused()));
                if (paused()) { debug.removeAttribute("href"); debug.tabIndex = -1; }
                else { if (debugUrl) debug.setAttribute("href", debugUrl); debug.removeAttribute("tabindex"); }
                syncDebug();
                if (lastBusy && !waiting && open && document.activeElement === document.body) sound.focus({preventScroll: true});
                lastBusy = waiting;
            }
            function showMenu(last) {
                closeDebug(false); render(); open = true; menu.hidden = false;
                button.setAttribute("aria-expanded", "true");
                var available = items();
                (available.length ? available[last ? available.length - 1 : 0] : menu).focus({preventScroll: true});
            }
            function click(event) {
                var node = event.target.closest && event.target.closest(".battle-menu, [data-menu-sound], [data-menu-debug]");
                if (!node || !root.contains(node)) return;
                if (node === button) {
                    event.preventDefault(); event.stopImmediatePropagation();
                    if (open) closeMenu(true); else showMenu(false);
                } else if (node === sound) {
                    event.preventDefault(); event.stopImmediatePropagation();
                    if (busy()) return;
                    send(new URLSearchParams({cmd: "set_sound_enabled", sound_enabled: soundEnabled() ? "0" : "1"}));
                    render(); // Checkbox remains authoritative; no optimistic toggle.
                } else if (node === debug) {
                    if (paused()) { event.preventDefault(); event.stopImmediatePropagation(); return; }
                    closeMenu(false);
                    if (overlay && overlay.querySelector(".debug-panel")) {
                        event.preventDefault(); event.stopImmediatePropagation();
                        debugOpen = true; syncDebug(); overlay.focus({preventScroll: true});
                    } // Otherwise follow the existing debug=1 URL and open its existing overlay on load.
                }
            }
            function outside(event) {
                if (open && !menu.contains(event.target) && !button.contains(event.target)) closeMenu(false);
            }
            function keydown(event) {
                if (event.key === "Escape" && (open || debugOpen)) {
                    event.preventDefault(); event.stopPropagation(); closeMenu(false); closeDebug(true); return;
                }
                if (event.target === button && ["ArrowDown", "ArrowUp"].indexOf(event.key) >= 0) {
                    event.preventDefault(); showMenu(event.key === "ArrowUp"); return;
                }
                if (!open || !menu.contains(event.target)) return;
                if (event.key === "Tab") { closeMenu(false); return; }
                if (["ArrowDown", "ArrowUp", "Home", "End"].indexOf(event.key) < 0) return;
                event.preventDefault(); var available = items();
                if (!available.length) return;
                var index = available.indexOf(document.activeElement);
                if (event.key === "Home") index = 0;
                else if (event.key === "End") index = available.length - 1;
                else index = (index + (event.key === "ArrowDown" ? 1 : -1) + available.length) % available.length;
                available[index].focus({preventScroll: true});
            }
            function focusin(event) { focusInDebug = !!(overlay && overlay.contains(event.target)); }
            function focusout(event) {
                if (!overlay || !overlay.contains(event.target)) return;
                if (event.relatedTarget) { focusInDebug = overlay.contains(event.relatedTarget); return; }
                var previous = overlay;
                queueMicrotask(function () {
                    if (overlay === previous && previous.isConnected && !previous.contains(document.activeElement)) focusInDebug = false;
                });
            }
            var observer = new MutationObserver(syncDebug);
            observer.observe(host, {childList: true, subtree: true});
            root.addEventListener("click", click, true);
            document.addEventListener("pointerdown", outside);
            document.addEventListener("keydown", keydown);
            document.addEventListener("focusin", focusin);
            document.addEventListener("focusout", focusout);
            render();
            if (window.location.hash === "#battle-debug" && overlay && overlay.querySelector(".debug-panel") && !paused()) {
                debugOpen = true; syncDebug(); overlay.focus({preventScroll: true});
            }
            return {render: render, destroy: function () {
                destroyed = true; observer.disconnect(); closeMenu(false); closeDebug(false);
                root.removeEventListener("click", click, true);
                document.removeEventListener("pointerdown", outside);
                document.removeEventListener("keydown", keydown);
                document.removeEventListener("focusin", focusin);
                document.removeEventListener("focusout", focusout);
            }};
        }
    };
})();
