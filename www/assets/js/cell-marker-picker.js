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
        if (compact) compact.setAttribute("hidden", "");
        panel.querySelectorAll("[data-cell-marker-fallback]").forEach(function (link) { link.removeAttribute("hidden"); });
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
        var cell = link.closest(".field-cell");
        if (cell) cell.classList.toggle("cell-marker-target", active);
    }

    function deactivate(links) {
        links.forEach(function (link) {
            // Remove the command before fallible presentation updates; keep cleaning other links.
            link.removeAttribute("href");
            try { setField(link, false); } catch (error) { link.setAttribute("hidden", ""); }
        });
    }

    function sync(request) {
        var panel = document.querySelector(".info-panel [data-cell-marker-picker]");
        var field = document.querySelector(".battle-field [data-cell-marker-field]");
        var fieldLinks = field ? Array.from(field.querySelectorAll("[data-cell-marker-link]")) : [];
        try {
            if (!panel || !field || !panel.dataset.cellMarkerPicker || panel.dataset.cellMarkerPicker !== field.dataset.cellMarkerField) {
                fallback(panel);
                deactivate(fieldLinks);
                if (!panel && !field) selection = null;
                return;
            }
            var compact = panel.querySelector("[data-cell-marker-compact]");
            if (!compact || compact.querySelectorAll("[data-picker-side]").length !== 2
                || compact.querySelectorAll("[data-picker-row]").length !== 3) throw new Error("Missing picker controls");
            // Read/validate the whole snapshot before changing any visibility, controls or URLs.
            var sideButtons = Array.from(compact.querySelectorAll("[data-picker-side]"));
            var rowButtons = Array.from(compact.querySelectorAll("[data-picker-row]"));
            if (sideButtons.map(function (b) { return b.dataset.pickerSide; }).sort().join() !== "enemy,own"
                || rowButtons.map(function (b) { return b.dataset.pickerRow; }).sort().join() !== "1,2,3") throw new Error("Invalid controls");
            var byKey = new Map();
            fieldLinks.forEach(function (link) {
                var key = link.dataset.cellMarkerLink;
                if (byKey.has(key) || !link.closest(".field-cell") || order.indexOf(link.dataset.pickerGroup) === -1
                    || !link.dataset.markerUrl || (link.hasAttribute("href") && link.getAttribute("href") !== link.dataset.markerUrl)) throw new Error("Invalid field target");
                byKey.set(key, link);
            });
            var groups = new Map();
            var seenGroups = new Set();
            var matchedKeys = new Set();
            var columnUpdates = [];
            compact.querySelectorAll("[data-picker-group]").forEach(function (group) {
                var name = group.dataset.pickerGroup;
                if (order.indexOf(name) === -1 || seenGroups.has(name)) throw new Error("Invalid group");
                seenGroups.add(name);
                var valid = [];
                group.querySelectorAll("[data-picker-column]").forEach(function (link) {
                    var key = link.dataset.pickerColumn;
                    var fieldLink = byKey.get(key);
                    if (!link.getAttribute("href")) throw new Error("Missing column URL");
                    // Missing field targets may be occupied cells in an outdated pending list.
                    // An existing target with a different group/URL is a mixed snapshot, not a sparse group.
                    if (fieldLink && (fieldLink.dataset.pickerGroup !== name || fieldLink.dataset.markerUrl !== link.getAttribute("href")
                        || matchedKeys.has(key))) throw new Error("Inconsistent target URL/group");
                    columnUpdates.push({link: link, hidden: !fieldLink});
                    if (fieldLink) { valid.push(key); matchedKeys.add(key); }
                });
                if (valid.length) groups.set(name, valid);
            });
            if (matchedKeys.size !== fieldLinks.length || columnUpdates.length !== compact.querySelectorAll("[data-picker-column]").length)
                throw new Error("Unmatched targets");
            var available = order.filter(function (name) { return groups.has(name); });
            if (!available.length) throw new Error("No matching targets");
            var id = panel.dataset.cellMarkerPicker;
            var next = selection && selection.id === id
                ? {id: id, side: selection.side, row: selection.row} : {id: id, side: "own", row: "1"};
            if (request && request.side) next.side = request.side;
            if (request && request.row) next.row = request.row;
            var current = next.side + ":" + next.row;
            if (!groups.has(current)) {
                current = available.find(function (name) { return name.split(":")[0] === next.side; }) || available[0];
                next.side = current.split(":")[0];
                next.row = current.split(":")[1];
            }
            // Apply only the fully validated plan. Any exception below restores server actions.
            columnUpdates.forEach(function (update) { update.link.hidden = update.hidden; });
            compact.querySelectorAll("[data-picker-side]").forEach(function (button) {
                var side = button.dataset.pickerSide;
                button.disabled = !available.some(function (name) { return name.split(":")[0] === side; });
                button.setAttribute("aria-pressed", String(side === next.side));
            });
            compact.querySelectorAll("[data-picker-row]").forEach(function (button) {
                var row = button.dataset.pickerRow;
                button.disabled = !groups.has(next.side + ":" + row);
                button.setAttribute("aria-pressed", String(row === next.row));
            });
            compact.querySelectorAll("[data-picker-group]").forEach(function (group) { group.hidden = group.dataset.pickerGroup !== current; });
            var keys = groups.get(current);
            fieldLinks.forEach(function (link) { setField(link, keys.indexOf(link.dataset.cellMarkerLink) !== -1); });
            // Commit enhancement only after controls and matching field targets have been updated.
            compact.hidden = false;
            panel.querySelectorAll("[data-cell-marker-fallback]").forEach(function (link) { link.hidden = true; });
            panel.dataset.pickerEnhanced = "true";
            selection = next;
        } catch (error) {
            fallback(panel);
            deactivate(fieldLinks);
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
        // Validate final actions synchronously too: a fragment may have changed before the observer runs.
        if (target.matches("[data-cell-marker-link], [data-picker-column]")) sync();
        if (target.matches("[data-cell-marker-link]")) {
            var activeField = document.querySelector(".battle-field [data-cell-marker-field]");
            if (!target.isConnected || !activeField || target.closest("[data-cell-marker-field]") !== activeField
                || !activeField.contains(target) || target.hidden || !target.hasAttribute("href") || target.getAttribute("aria-disabled") === "true") event.preventDefault();
            return;
        }
        var panel = target.closest("[data-cell-marker-picker]");
        var activePanel = document.querySelector(".info-panel [data-cell-marker-picker]");
        if (!target.isConnected || !activePanel || panel !== activePanel || !activePanel.contains(target)) {
            event.preventDefault();
            return;
        }
        if (target.matches("[data-picker-column]")) {
            var group = target.closest("[data-picker-group]");
            if (!panel || panel.dataset.pickerEnhanced !== "true" || target.hidden || !group || group.hidden) event.preventDefault();
            return;
        }
        if (!panel || panel.dataset.pickerEnhanced !== "true") return;
        event.preventDefault();
        if (target.disabled || !selection || selection.id !== panel.dataset.cellMarkerPicker) return;
        sync({side: target.dataset.pickerSide, row: target.dataset.pickerRow});
    }, true);

    var observer = new MutationObserver(function (records) {
        // Detached events do not reach document delegation. Revoke commands on removed nodes
        // before another task can activate retained references; connected/reparented nodes stay intact.
        records.forEach(function (record) {
            record.removedNodes.forEach(function (node) {
                if (node.nodeType !== 1 || node.isConnected) return;
                var selector = "[data-cell-marker-link], [data-picker-column]";
                var links = Array.from(node.querySelectorAll(selector));
                if (node.matches(selector)) links.push(node);
                links.forEach(function (link) {
                    link.removeAttribute("href");
                    link.setAttribute("hidden", "");
                    link.setAttribute("aria-disabled", "true");
                });
            });
        });
        schedule();
    });
    observer.observe(document.body, {childList: true, subtree: true, attributes: true,
        attributeFilter: ["data-cell-marker-picker", "data-cell-marker-field"]});
    document.documentElement.dataset.cellMarkerController = "ready";
    sync();
})();