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
        var side = p.querySelector('[data-picker-side][aria-pressed="true"]');
        var row = p.querySelector('[data-picker-row][aria-pressed="true"]');
        return side && row ? side.dataset.pickerSide + ":" + row.dataset.pickerRow : null;
    }
    function activeLinks() { return Array.from(field().querySelectorAll('[data-cell-marker-link][href]')); }
    function checkActive() {
        var group = current();
        var active = activeLinks();
        if (group === null) {
            assert(active.length === field().querySelectorAll('[data-cell-marker-link]').length, 'Unfiltered field keeps every legal action');
            assert(Array.from(panel().querySelectorAll('[data-picker-group]')).every(function(g) {return g.hidden;}), 'Unfiltered panel has no selected columns');
            return;
        }
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
                assert(current() === null, 'Initially no side or row selected');
                assert(activeLinks().length === field().querySelectorAll('[data-cell-marker-link]').length, 'Initially all legal field cells are active');
                assert(Array.from(p.querySelectorAll('[data-picker-group]')).every(function(g) {return g.hidden;}), 'Initially no column group displayed');
                assert(Array.from(p.querySelectorAll('[data-picker-row]')).every(function(b) {return b.disabled;}), 'Choose a side before a row');
                assert(p.querySelector('[data-cell-marker-compact]').hidden === false, "Compact picker initialized");
                assert(Array.from(p.querySelectorAll('[data-cell-marker-fallback]')).every(function (e) {return e.hidden;}), "Fallback cell list hidden only after enhancement");
                assert(Array.from(p.querySelectorAll('a')).some(function(e) {return e.getAttribute('href').endsWith('&cmd=cancel_pending') && !e.hidden;}), "Cancellation remains accessible");
                // Visit every available side/row, including disabled variants in sparse fixtures.
                for (var side of ['own', 'enemy']) {
                    var sideButton = panel().querySelector('[data-picker-side="' + side + '"]');
                    if (sideButton.disabled) continue;
                    sideButton.click();
                    var firstRow = Array.from(panel().querySelectorAll('[data-picker-row]')).find(function(b) {return !b.disabled;}).dataset.pickerRow;
                    assert(current() === side + ':' + firstRow, 'Choosing a side immediately selects its first available row');
                    checkActive();
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
                assert(current() === null && activeLinks().length === field().querySelectorAll('[data-cell-marker-link]').length, 'New snapshot resets to all legal cells');
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
                assert(current() === null, "New pending resets filters without stale nodes");
                panel().querySelector('[data-picker-side]:not([disabled])').click();
                checkActive();
                // Review regressions: validate before writes, and rollback after a fallible DOM write.
                async function restore() {
                    panel().replaceWith(sourcePanel.cloneNode(true));
                    field().replaceWith(sourceField.cloneNode(true));
                    await tick();
                    assert(panel().dataset.pickerEnhanced === 'true', 'Valid fragments recover without reloading the controller');
                }
                function safeFallback(message) {
                    assert(!panel().dataset.pickerEnhanced && panel().querySelector('[data-cell-marker-compact]').hidden
                        && activeLinks().length === 0
                        && Array.from(panel().querySelectorAll('[data-cell-marker-fallback]')).every(function(e) {return !e.hidden && e.hasAttribute('href');}), message);
                }
                var listenerAdds = 0;
                var originalAdd = document.addEventListener;
                document.addEventListener = function () { listenerAdds++; return originalAdd.apply(this, arguments); };
                try {
                    var badPanel = sourcePanel.cloneNode(true);
                    var columnWrites = 0;
                    var hiddenProperty = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'hidden');
                    badPanel.querySelectorAll('[data-picker-column]').forEach(function(link) {
                        Object.defineProperty(link, 'hidden', {
                            get: function() {return hiddenProperty.get.call(this);},
                            set: function(value) {columnWrites++; hiddenProperty.set.call(this, value);},
                            configurable: true
                        });
                    });
                    var badGroups = badPanel.querySelectorAll('[data-picker-group]');
                    badGroups[badGroups.length - 1].dataset.pickerGroup = 'invalid-late-group';
                    panel().replaceWith(badPanel);
                    field().replaceWith(sourceField.cloneNode(true));
                    await tick();
                    assert(columnWrites === 0, 'Late validation failure does not partially change column visibility');
                    safeFallback('Invalid group disables compact UI and all field links, preserving server actions');
                    await restore();

                    badPanel = sourcePanel.cloneNode(true);
                    badPanel.querySelector('[data-cell-marker-compact]').append(badPanel.querySelector('[data-picker-column]').cloneNode(true));
                    panel().replaceWith(badPanel);
                    await tick();
                    safeFallback('Column outside a validated group cannot remain an active compact command');
                    await restore();

                    // Same snapshot ID but a late inconsistent URL is rejected as a whole.
                    badPanel = sourcePanel.cloneNode(true);
                    var columns = badPanel.querySelectorAll('[data-picker-column]');
                    columns[columns.length - 1].setAttribute('href', columns[columns.length - 1].getAttribute('href').replace('game=77', 'game=88'));
                    panel().replaceWith(badPanel);
                    await tick();
                    safeFallback('Mixed URLs cannot partially enhance an otherwise matching snapshot');
                    await restore();

                    var badField = sourceField.cloneNode(true);
                    var faultTarget = badField.querySelector('[data-cell-marker-link][href]');
                    var toggle = faultTarget.closest('.field-cell').classList;
                    var originalToggle = toggle.toggle;
                    var faultInjected = false;
                    toggle.toggle = function () {
                        originalToggle.apply(this, arguments);
                        faultInjected = true;
                        throw new Error('Injected persistent field presentation error');
                    };
                    field().replaceWith(badField);
                    await tick();
                    assert(faultInjected, 'DOM error injected after a field update');
                    safeFallback('Partial application error disables compact UI and restores every server action');
                    await restore();
                    checkActive();

                    // An exception after fallback hiding has started must reveal the full original list again.
                    badPanel = sourcePanel.cloneNode(true);
                    var fallbackFault = false;
                    var fallbackLink = badPanel.querySelector('[data-cell-marker-fallback]');
                    Object.defineProperty(fallbackLink, 'hidden', {
                        get: function() {return hiddenProperty.get.call(this);},
                        set: function(value) {
                            hiddenProperty.set.call(this, value);
                            if (value && !fallbackFault) {fallbackFault = true; throw new Error('Injected during fallback hiding');}
                        }, configurable: true
                    });
                    panel().replaceWith(badPanel);
                    await tick();
                    assert(fallbackFault, 'DOM error injected after fallback hiding starts');
                    safeFallback('Late application error reveals original server actions and disables every field command');
                    await restore();

                    // Identical coordinate keys, but a different pending snapshot and different base URL.
                    var nextPanel = sourcePanel.cloneNode(true);
                    var nextField = sourceField.cloneNode(true);
                    nextPanel.dataset.cellMarkerPicker = nextField.dataset.cellMarkerField = 'different-pending-snapshot';
                    nextPanel.querySelectorAll('a[href]').forEach(function(link) {link.setAttribute('href', link.getAttribute('href').replace('game=77', 'game=88'));});
                    nextField.querySelectorAll('[data-cell-marker-link]').forEach(function(link) {
                        link.dataset.markerUrl = link.dataset.markerUrl.replace('game=77', 'game=88');
                        if (link.hasAttribute('href')) link.setAttribute('href', link.dataset.markerUrl);
                    });
                    var previousFieldAction = field().querySelector('[data-cell-marker-link][href]');
                    panel().replaceWith(nextPanel);
                    function assertImmediatelyBlocked(link, message) {
                        var blocked = false;
                        link.addEventListener('click', function(event) {blocked = event.defaultPrevented; event.preventDefault();}, {once: true});
                        link.dispatchEvent(new MouseEvent('click', {bubbles: true, cancelable: true}));
                        assert(blocked, message);
                    }
                    assertImmediatelyBlocked(previousFieldAction, 'Old field action blocked synchronously before MutationObserver');
                    assertImmediatelyBlocked(panel().querySelector('[data-picker-column]'), 'Unagreed compact action blocked before MutationObserver');
                    await tick();
                    safeFallback('New InfoPanel with identical coordinates cannot reactivate the old pending field');
                    field().replaceWith(nextField);
                    await tick();
                    assert(panel().dataset.pickerEnhanced === 'true' && activeLinks().every(function(link) {return link.getAttribute('href').includes('game=88');}), 'Only new pending URLs activate after snapshot agreement');
                    checkActive();
                    // Then update the field first for a third pending; no old URL may survive.
                    var thirdPanel = sourcePanel.cloneNode(true);
                    var thirdField = sourceField.cloneNode(true);
                    thirdPanel.dataset.cellMarkerPicker = thirdField.dataset.cellMarkerField = 'third-pending-snapshot';
                    field().replaceWith(thirdField);
                    await tick();
                    safeFallback('Field-first new pending replacement cannot activate old URLs');
                    panel().replaceWith(thirdPanel);
                    await tick();
                    assert(activeLinks().every(function(link) {return link.getAttribute('href').includes('game=77');}), 'Third pending restores only its own original URLs');
                    await restore();

                    // The event path still reaches document if window capture detaches its target.
                    // Use the same snapshot on purpose: identity equality alone must not authorize old nodes.
                    for (var firstFragment of ['panel', 'field']) {
                        for (var relocation of [false, true]) {
                            await restore();
                            if (firstFragment === 'panel') panel().replaceWith(sourcePanel.cloneNode(true));
                            else field().replaceWith(sourceField.cloneNode(true));
                            await tick();
                            panel().querySelector('[data-picker-side]:not([disabled])').click();
                            var oldRoot = firstFragment === 'panel' ? field() : panel();
                            var oldLink = firstFragment === 'panel'
                                ? oldRoot.querySelector('[data-cell-marker-link][href]')
                                : oldRoot.querySelector('[data-picker-group="' + current() + '"] [data-picker-column]:not([hidden])');
                            var replacement = firstFragment === 'panel' ? sourceField.cloneNode(true) : sourcePanel.cloneNode(true);
                            var wasBlocked = false;
                            oldLink.addEventListener('click', function(event) {wasBlocked = event.defaultPrevented; event.preventDefault();}, {once: true});
                            window.addEventListener('click', function() {
                                oldRoot.replaceWith(replacement);
                                if (relocation) document.body.append(oldRoot);
                            }, {capture: true, once: true});
                            oldLink.dispatchEvent(new MouseEvent('click', {bubbles: true, cancelable: true}));
                            assert(wasBlocked, 'Retained ' + (firstFragment === 'panel' ? 'field' : 'column')
                                + ' event rejected after ' + (relocation ? 'relocation outside current container' : 'detachment during capture'));
                            await tick();
                            assert(panel().dataset.pickerEnhanced === 'true', 'Current fragments remain enhanced after rejecting old node');
                            checkActive();
                            if (relocation) {
                                assertImmediatelyBlocked(oldLink, 'Reattached stale node cannot activate a command');
                                oldRoot.remove();
                                await tick();
                            }
                            assert(!oldLink.isConnected && !oldLink.hasAttribute('href'), 'Observer revokes detached command URLs');
                            oldLink.dispatchEvent(new MouseEvent('click', {bubbles: true, cancelable: true}));
                            assert(!oldLink.hasAttribute('href'), 'Clicking a detached reference cannot restore its command');

                            panel().querySelector('[data-picker-side]:not([disabled])').click();
                            var liveFieldLink = field().querySelector('[data-cell-marker-link][href]');
                            var liveColumnLink = panel().querySelector('[data-picker-group="' + current() + '"] [data-picker-column]:not([hidden])');
                            for (var liveLink of [liveFieldLink, liveColumnLink]) {
                                var liveUrl = liveLink.getAttribute('href');
                                var accepted = false;
                                liveLink.addEventListener('click', function(event) {accepted = !event.defaultPrevented; event.preventDefault();}, {once: true});
                                liveLink.dispatchEvent(new MouseEvent('click', {bubbles: true, cancelable: true}));
                                assert(accepted && liveLink.getAttribute('href') === liveUrl, 'Current link accepted with its original command URL');
                            }
                        }
                    }
                    await restore();

                    // On the destination side retain a later row only; switch from a row absent there.
                    var sparsePanel = sourcePanel.cloneNode(true);
                    var sparseField = sourceField.cloneNode(true);
                    var sourceGroups = Array.from(sparsePanel.querySelectorAll('[data-picker-group]'));
                    var ownGroup = sourceGroups.find(function(group) {return group.dataset.pickerGroup.startsWith('own:');});
                    var enemyGroup = sourceGroups.find(function(group) {return ownGroup && group.dataset.pickerGroup.startsWith('enemy:') && group.dataset.pickerGroup.split(':')[1] !== ownGroup.dataset.pickerGroup.split(':')[1];});
                    if (ownGroup && enemyGroup) {
                        var fromRow = ownGroup.dataset.pickerGroup.split(':')[1];
                        var toRow = enemyGroup.dataset.pickerGroup.split(':')[1];
                        var keepKeys = Array.from(ownGroup.querySelectorAll('[data-picker-column]')).concat(Array.from(enemyGroup.querySelectorAll('[data-picker-column]'))).map(function(link) {return link.dataset.pickerColumn;});
                        sourceGroups.forEach(function(group) {if (group !== ownGroup && group !== enemyGroup) group.remove();});
                        sparseField.querySelectorAll('[data-cell-marker-link]').forEach(function(link) {
                            if (!keepKeys.includes(link.dataset.cellMarkerLink)) link.remove();
                        });
                        panel().replaceWith(sparsePanel);
                        field().replaceWith(sparseField);
                        await tick();
                        panel().querySelector('[data-picker-side="own"]').click();
                        panel().querySelector('[data-picker-row="' + fromRow + '"]').click();
                        panel().querySelector('[data-picker-side="enemy"]').click();
                        assert(current() === 'enemy:' + toRow, 'Changing side selects its first available row when previous row is absent');
                        checkActive();
                        await restore();
                    }
                    assert(listenerAdds === 0, 'Failure/recovery and successive pending register no additional handlers');
                } finally { document.addEventListener = originalAdd; }
                assert(Array.from(panel().querySelectorAll('[data-picker-column]')).every(function(e, i) {return e.getAttribute('href') === originalUrls[i];}), "All original system-coordinate URLs unchanged");
            }
            results.textContent = 'ALL PASSED (' + report.length + ')\n' + report.join('\n');
        } catch (error) {
            results.textContent = 'FAILED: ' + error.message + '\n' + report.join('\n');
        } finally { run.disabled = false; }
    });
})();