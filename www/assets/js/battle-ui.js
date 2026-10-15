(function () {
    "use strict";
    var root = document.querySelector("[data-battle-sync-root]");
    if (!root || root.dataset.battleUiReady === "true") return;
    root.dataset.battleUiReady = "true";
    var button = root.querySelector(".battle-menu");
    if (!button) return;
    var open = false;
    function sync() {
        var overlay = root.querySelector("#battle-debug");
        var available = !!(overlay && overlay.querySelector(".debug-panel"));
        if (!available) open = false;
        button.disabled = !available;
        button.setAttribute("aria-expanded", String(open));
        if (overlay) {
            overlay.hidden = !open;
            overlay.tabIndex = 0;
        }
    }
    root.addEventListener("click", function (event) {
        if (!event.target.closest || event.target.closest(".battle-menu") !== button) return;
        open = !open;
        sync();
        if (open) root.querySelector("#battle-debug").focus();
    });
    document.addEventListener("keydown", function (event) {
        if (event.key !== "Escape" || !open) return;
        open = false;
        sync();
        button.focus();
    });
    new MutationObserver(sync).observe(root, {childList: true, subtree: true});
    sync();
}());
