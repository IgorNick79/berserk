/* Integration checks running against the actual browser DOM. No game commands are sent. */
(function () {
    "use strict";
    var results = document.getElementById("picker-test-results");
    var run = document.getElementById("run-picker-tests");
    var report = [];
    function assert(value, message) { if (!value) throw new Error(message); report.push("PASS " + message); }
    function tick() { return new Promise(function (resolve) { setTimeout(resolve, 0); }); }
    function panel() { return document.querySelector("[data-cell-marker-picker]"); }
    function field() { return document.querySelector("[data-cell-marker-field]"); }
    function current() {
        var p = panel();
        return p.querySelector('[data-picker-side][aria-pressed="true"]').dataset.pickerSide + ":"
            + p.querySelector('[data-picker-row][aria-pressed="true"]').dataset.pickerRow;
    }
    function activeLinks() { return Array.from(field().querySelectorAll('[data-cell-marker-link][href]')); }
    function checkActive() {
        var group = current();
        var active = activeLinks();
        assert(active.length > 0 && active.every(function (e) { return e.dataset.pickerGroup === group && !e.hidden; }), "Only selected group has active field URLs");
        field().querySelectorAll('[data-cell-marker-link]').forEach(function (e) {
            if (e.dataset.pickerGroup !== group) assert(!e.hasAttribute("href") && e.hidden && e.getAttribute("tabindex") === "-1", "Inactive field target disabled");
        });
        var columns = Array.from(panel().querySelector('[data-picker-group="' + group + '"]').querySelectorAll('[data-picker-column]')).filter(function(e) {return !e.hidden;});
        assert(columns.length === active.length && columns.every(function (e) {
            return active.some(function (f) { return f.dataset.cellMarkerLink === e.dataset.pickerColumn && f.getAttribute("href") === e.getAttribute("href"); });
        }), "Column and field URLs match exactly");
        assert(Array.from(document.querySelectorAll('.battle-field .card-link')).length === 2
            && Array.from(document.querySelectorAll('.battle-field .card-link')).every(function(e) {return !e.parentElement.querySelector('[data-cell-marker-link]');}), "Occupied card actions preserved");
    }
    run.addEventListener("click", async function () {
        run.disabled = true;
        report = [];
        try {
            var p = panel();
            if (!p) {
                assert(!field() && !document.querySelector('[data-picker-column]'), "Observer has no marker choice UI or field targets");
            } else if (p.dataset.pickerEnhanced !== "true") {
                assert(p.querySelector('[data-cell-marker-compact]').hidden, "No-JS compact controls hidden");
                assert(Array.from(p.querySelectorAll('[data-cell-marker-fallback]')).every(function (e) {return !e.hidden && e.hasAttribute('href');}), "No-JS fallback actions remain accessible");
            } else {
                var sourcePanel = p.cloneNode(true);
                var sourceField = field().cloneNode(true);
                var originalUrls = Array.from(sourcePanel.querySelectorAll('[data-picker-column]')).map(function(e) {return e.getAttribute('href');});
                var firstGroup = Array.from(sourcePanel.querySelectorAll('[data-picker-group]')).find(function(group) {
                    return Array.from(group.querySelectorAll('[data-picker-column]')).some(function(column) {
                        return Array.from(sourceField.querySelectorAll('[data-cell-marker-link]')).some(function(link) {return link.dataset.cellMarkerLink === column.dataset.pickerColumn;});
                    });
                }).dataset.pickerGroup;
                assert(current() === firstGroup, 'Initial group is the first available side and row');
                assert(p.querySelector('[data-cell-marker-compact]').hidden === false, "Compact picker initialized");
                assert(Array.from(p.querySelectorAll('[data-cell-marker-fallback]')).every(function (e) {return e.hidden;}), "Fallback cell list hidden only after enhancement");
                assert(Array.from(p.querySelectorAll('a')).some(function(e) {return e.getAttribute('href').endsWith('&cmd=cancel_pending') && !e.hidden;}), "Cancellation remains accessible");
                checkActive();
                // Visit every available side/row, including disabled variants in sparse fixtures.
                for (var side of ['own', 'enemy']) {
                    var sideButton = panel().querySelector('[data-picker-side="' + side + '"]');
                    if (sideButton.disabled) continue;
                    sideButton.click();
                    for (var row of ['1', '2', '3']) {
                        var rowButton = panel().querySelector('[data-picker-row="' + row + '"]');
                        if (rowButton.disabled) continue;
                        rowButton.focus();
                        rowButton.click();
                        assert(current() === side + ':' + row, "Side/row switching updates pressed state");
                        checkActive();
                    }
                }
                for (var n = 0; n < 20; n++) {
                    panel().querySelector('[data-picker-row][aria-pressed="true"]').click();
                }
                checkActive();
                var selected = current();
                field().replaceWith(sourceField.cloneNode(true));
                await tick();
                assert(current() === selected, "Independent field replacement preserves selection");
                checkActive();
                panel().replaceWith(sourcePanel.cloneNode(true));
                await tick();
                assert(current() === selected, "Independent InfoPanel replacement preserves selection");
                checkActive();
                // Changing snapshot IDs prevents interaction between stale fragments.
                panel().dataset.cellMarkerPicker = 'next-snapshot';
                await tick();
                assert(!panel().dataset.pickerEnhanced && activeLinks().length === 0, "Mismatched snapshots disable field and restore fallback");
                assert(Array.from(panel().querySelectorAll('[data-cell-marker-fallback]')).every(function(e) {return !e.hidden;}), "Mismatched snapshot keeps server actions available");
                field().dataset.cellMarkerField = 'next-snapshot';
                await tick();
                assert(panel().dataset.pickerEnhanced === 'true', "Matching independently updated fragments reinitialize");
                checkActive();
                // Failed initialization restores fallback rather than hiding permitted actions.
                panel().querySelector('[data-picker-row]').remove();
                await tick();
                assert(!panel().dataset.pickerEnhanced && Array.from(panel().querySelectorAll('[data-cell-marker-fallback]')).every(function(e) {return !e.hidden;}), "Initialization failure restores fallback");
                panel().replaceWith(sourcePanel.cloneNode(true));
                field().replaceWith(sourceField.cloneNode(true));
                await tick();
                var calls = 0;
                var addListener = document.addEventListener;
                document.addEventListener = function () { calls++; return addListener.apply(this, arguments); };
                await new Promise(function(resolve, reject) {
                    var script = document.createElement('script');
                    script.src = '/www/assets/js/cell-marker-picker.js?repeat=1';
                    script.onload = resolve; script.onerror = reject;
                    document.body.append(script);
                });
                document.addEventListener = addListener;
                assert(calls === 0, "Repeated script initialization adds no delegated handlers");
                var removed = panel();
                removed.remove();
                await tick();
                assert(activeLinks().length === 0, "Completed/cancelled pending disables remaining stale field targets");
                field().remove();
                await tick();
                document.querySelector('.battle-field').append(sourceField.cloneNode(true));
                document.querySelector('.info-panel').append(sourcePanel.cloneNode(true));
                await tick();
                assert(current() === firstGroup, "New pending reinitializes deterministically without stale nodes");
                checkActive();
                assert(Array.from(panel().querySelectorAll('[data-picker-column]')).every(function(e, i) {return e.getAttribute('href') === originalUrls[i];}), "All original system-coordinate URLs unchanged");
            }
            results.textContent = 'ALL PASSED (' + report.length + ')\n' + report.join('\n');
        } catch (error) {
            results.textContent = 'FAILED: ' + error.message + '\n' + report.join('\n');
        } finally { run.disabled = false; }
    });
})();