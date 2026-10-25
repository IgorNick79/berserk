(function () {
    "use strict";
    var root = document.querySelector("[data-battle-sync-root]");
    if (!root) return;
    var memory = Object.create(null);
    function read(scope) {
        if (memory[scope]) return memory[scope];
        try {
            var saved = JSON.parse(window.localStorage.getItem("berserk:ochi-notices:" + scope) || "[]");
            if (Array.isArray(saved)) return (memory[scope] = saved.filter(function (id) { return typeof id === "string"; }).slice(-100));
        } catch (_) {}
        return (memory[scope] = []);
    }
    function restore() {
        root.querySelectorAll("[data-ochi-notice]").forEach(function (box) {
            var dismissed = read(box.dataset.ochiNotice), visible = false;
            box.querySelectorAll("[data-ochi-opening]").forEach(function (item) {
                item.hidden = dismissed.indexOf(item.dataset.ochiOpening) !== -1;
                if (!item.hidden) visible = true;
            });
            box.hidden = !visible;
        });
    }
    root.addEventListener("click", function (event) {
        var button = event.target.closest && event.target.closest("[data-dismiss-ochi]");
        if (!button || !root.contains(button)) return;
        event.preventDefault();
        var box = button.closest("[data-ochi-notice]");
        if (!box) return;
        var scope = box.dataset.ochiNotice, dismissed = read(scope);
        box.querySelectorAll("[data-ochi-opening]").forEach(function (item) {
            if (dismissed.indexOf(item.dataset.ochiOpening) === -1) dismissed.push(item.dataset.ochiOpening);
        });
        memory[scope] = dismissed.slice(-100);
        try { window.localStorage.setItem("berserk:ochi-notices:" + scope, JSON.stringify(memory[scope])); } catch (_) {}
        restore();
    });
    document.addEventListener("berserk:battle-updated", restore);
    window.addEventListener("storage", function (event) {
        if (event.key && event.key.indexOf("berserk:ochi-notices:") === 0) { memory = Object.create(null); restore(); }
    });
    restore();
}());
