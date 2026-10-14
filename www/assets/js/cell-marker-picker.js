(function () {
    "use strict";
    // One delegated listener/observer, even if a fragment loader executes this script again.
    if (document.documentElement.dataset.cellMarkerController === "ready") return;
    var selection = null; // Snapshot ID, side and numeric game row only; never retain replaced nodes.
    var queued = false;
    var order = ["own:1", "own:2", "own:3", "enemy:1", "enemy:2", "enemy:3"];

    function fallback(panel) {
        if (!panel) return;
        var compact = panel.querySelector("[data-cell-marker-compact]");
        if (compact) compact.hidden = true;
        panel.querySelectorAll("[data-cell-marker-fallback]").forEach(function (link) { link.hidden = false; });
        delete panel.dataset.pickerEnhanced;
    }

    function setField(link, active) {
        link.hidden = !active;
        link.setAttribute("aria-disabled", active ? "false" : "true");
        if (active) {
            link.setAttribute("href", link.dataset.markerUrl);
            link.removeAttribute("tabindex");
        } else {
            link.removeAttribute("href");
            link.setAttribute("tabindex", "-1");
        }
        link.closest(".field-cell").classList.toggle("cell-marker-target", active);
    }

    function sync() {
        var panel = document.querySelector(".info-panel [data-cell-marker-picker]");
        var field = document.querySelector(".battle-field [data-cell-marker-field]");
        var fieldLinks = field ? Array.from(field.querySelectorAll("[data-cell-marker-link]")) : [];
        try {
            if (!panel || !field || panel.dataset.cellMarkerPicker !== field.dataset.cellMarkerField) {
                fallback(panel);
                fieldLinks.forEach(function (link) { setField(link, false); });
                if (!panel && !field) selection = null;
                return;
            }
            var compact = panel.querySelector("[data-cell-marker-compact]");
            if (!compact || compact.querySelectorAll("[data-picker-side]").length !== 2
                || compact.querySelectorAll("[data-picker-row]").length !== 3) throw new Error("Missing picker controls");
            var byKey = new Map();
            fieldLinks.forEach(function (link) { byKey.set(link.dataset.cellMarkerLink, link); });
            var groups = new Map();
            compact.querySelectorAll("[data-picker-group]").forEach(function (group) {
                var name = group.dataset.pickerGroup;
                if (order.indexOf(name) === -1) throw new Error("Invalid group");
                var valid = [];
                group.querySelectorAll("[data-picker-column]").forEach(function (link) {
                    var fieldLink = byKey.get(link.dataset.pickerColumn);
                    // All eligibility/grouping comes from View, including occupied-cell protection.
                    var matches = fieldLink && fieldLink.dataset.pickerGroup === name
                        && fieldLink.dataset.markerUrl === link.getAttribute("href");
                    link.hidden = !matches;
                    if (matches) valid.push(link.dataset.pickerColumn);
                });
                if (valid.length) groups.set(name, valid);
            });
            var available = order.filter(function (name) { return groups.has(name); });
            if (!available.length) throw new Error("No matching targets");
            var id = panel.dataset.cellMarkerPicker;
            if (!selection || selection.id !== id) selection = {id: id, side: "own", row: "1"};
            var current = selection.side + ":" + selection.row;
            if (!groups.has(current)) {
                current = available.find(function (name) { return name.split(":")[0] === selection.side; }) || available[0];
                selection.side = current.split(":")[0];
                selection.row = current.split(":")[1];
            }
            compact.querySelectorAll("[data-picker-side]").forEach(function (button) {
                var side = button.dataset.pickerSide;
                button.disabled = !available.some(function (name) { return name.split(":")[0] === side; });
                button.setAttribute("aria-pressed", String(side === selection.side));
            });
            compact.querySelectorAll("[data-picker-row]").forEach(function (button) {
                var row = button.dataset.pickerRow;
                button.disabled = !groups.has(selection.side + ":" + row);
                button.setAttribute("aria-pressed", String(row === selection.row));
            });
            compact.querySelectorAll("[data-picker-group]").forEach(function (group) { group.hidden = group.dataset.pickerGroup !== current; });
            var keys = groups.get(current);
            fieldLinks.forEach(function (link) { setField(link, keys.indexOf(link.dataset.cellMarkerLink) !== -1); });
            // Commit enhancement only after controls and matching field targets have been updated.
            compact.hidden = false;
            panel.querySelectorAll("[data-cell-marker-fallback]").forEach(function (link) { link.hidden = true; });
            panel.dataset.pickerEnhanced = "true";
        } catch (error) {
            fallback(panel);
            fieldLinks.forEach(function (link) { setField(link, false); });
        }
    }

    function schedule() {
        if (queued) return;
        queued = true;
        queueMicrotask(function () { queued = false; sync(); });
    }

    document.addEventListener("click", function (event) {
        if (!(event.target instanceof Element)) return;
        var target = event.target.closest("[data-picker-side], [data-picker-row], [data-cell-marker-link], [data-picker-column]");
        if (!target) return;
        if (target.matches("[data-cell-marker-link]")) {
            if (target.hidden || !target.hasAttribute("href") || target.getAttribute("aria-disabled") === "true") event.preventDefault();
            return;
        }
        var panel = target.closest("[data-cell-marker-picker]");
        if (!panel || panel.dataset.pickerEnhanced !== "true") return;
        if (target.matches("[data-picker-column]")) {
            if (target.hidden || target.closest("[data-picker-group]").hidden) event.preventDefault();
            return;
        }
        event.preventDefault();
        if (target.disabled || !selection || selection.id !== panel.dataset.cellMarkerPicker) return;
        if (target.hasAttribute("data-picker-side")) selection.side = target.dataset.pickerSide;
        if (target.hasAttribute("data-picker-row")) selection.row = target.dataset.pickerRow;
        sync();
    }, true);

    var observer = new MutationObserver(schedule);
    observer.observe(document.body, {childList: true, subtree: true, attributes: true,
        attributeFilter: ["data-cell-marker-picker", "data-cell-marker-field"]});
    document.documentElement.dataset.cellMarkerController = "ready";
    sync();
})();