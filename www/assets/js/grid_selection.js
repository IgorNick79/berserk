(function () {
    "use strict";
    var grid = document.querySelector('[data-grid-selection-key]');
    if (!grid) return;
    var root = grid.closest('.pregame-layout');
    var form = root.querySelector('#grid-decision');
    var cards = Array.from(grid.querySelectorAll('[data-grid-position]'));
    var controls = Array.from(root.querySelectorAll('[data-grid-pick]'));
    var selection = null, submitted = false, historyReturn = null;
    var prefix = 'grid-selection:' + grid.dataset.gridSelectionKey + ':';
    var key = prefix + grid.dataset.gridVersion;
    try {
        var saved = sessionStorage.getItem(key);
        selection = controls.find(function (button) { return !button.disabled && button.dataset.command + ':' + (button.dataset.index || '') === saved; }) || null;
        Object.keys(sessionStorage).forEach(function (entry) {
            if (entry.indexOf(prefix) === 0 && entry !== key) sessionStorage.removeItem(entry);
        });
    } catch (_) {}
    function update() {
        var count = 0;
        cards.forEach(function (card) {
            var position = Number(card.dataset.gridPosition);
            var selected = selection && card.dataset.gridOccupied === '1' &&
                (selection.dataset.axis === 'row' ? Math.floor(position / 3) + 1 === Number(selection.dataset.index) :
                    selection.dataset.axis === 'col' && position % 3 + 1 === Number(selection.dataset.index));
            card.querySelector('.prepare-card').classList.toggle('prepare-card--selected', !!selected);
            if (selected) count++;
        });
        controls.forEach(function (button) { button.setAttribute('aria-pressed', String(button === selection)); });
        var countNode = root.querySelector('[data-grid-selected]');
        if (countNode) countNode.textContent = String(count);
        if (form) {
            var command = form.querySelector('[data-grid-command]');
            var coordinate = form.querySelector('[data-grid-coordinate]');
            command.disabled = !selection;
            command.value = selection ? selection.dataset.command : '';
            coordinate.disabled = !selection || !selection.dataset.axis;
            coordinate.name = selection && selection.dataset.axis || '';
            coordinate.value = selection && selection.dataset.index || '';
            form.querySelector('[data-grid-confirm]').disabled = !selection || submitted;
        }
        try {
            if (selection) sessionStorage.setItem(key, selection.dataset.command + ':' + (selection.dataset.index || ''));
            else sessionStorage.removeItem(key);
        } catch (_) {}
    }
    function showPreview(template) {
        var content = root.querySelector('.pregame-panel-content');
        content.replaceChildren(template.content.cloneNode(true));
        content.dataset.pregameContent = 'card';
        content.scrollTop = 0;
        root.querySelector('[data-grid-history]').setAttribute('aria-pressed', 'false');
        historyReturn = null;
    }
    root.addEventListener('click', function (event) {
        var choice = event.target.closest('[data-grid-pick]');
        if (choice && !choice.disabled && !submitted) {
            selection = selection === choice ? null : choice;
            update();
            return;
        }
        var preview = event.target.closest('.pregame-grid-card[data-grid-occupied="1"] .prepare-card-link');
        var historyEntry = event.target.closest('.pregame-history-entry .prepare-card-link');
        if ((preview || historyEntry) && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey) {
            event.preventDefault();
            showPreview(preview ? preview.closest('.pregame-grid-card').querySelector('[data-grid-preview]') :
                historyEntry.closest('.pregame-history-entry').querySelector('[data-history-card-preview]'));
            return;
        }
        var historyControl = event.target.closest('[data-grid-history]');
        if (!historyControl) return;
        var content = root.querySelector('.pregame-panel-content');
        if (historyControl.getAttribute('aria-pressed') === 'true' && historyReturn) {
            content.replaceChildren.apply(content, historyReturn.nodes);
            content.dataset.pregameContent = historyReturn.kind;
            content.scrollTop = historyReturn.scroll;
            historyControl.setAttribute('aria-pressed', 'false');
            historyReturn = null;
        } else {
            historyReturn = {nodes:Array.from(content.childNodes).map(function (node) { return node.cloneNode(true); }),
                kind:content.dataset.pregameContent, scroll:content.scrollTop};
            content.replaceChildren(root.querySelector('[data-grid-history-content]').content.cloneNode(true));
            content.dataset.pregameContent = 'history';
            content.scrollTop = 0;
            historyControl.setAttribute('aria-pressed', 'true');
        }
    });
    if (form) form.addEventListener('submit', function (event) {
        if (!selection || submitted) { event.preventDefault(); return; }
        submitted = true;
        form.querySelector('[data-grid-confirm]').disabled = true;
        try { sessionStorage.removeItem(key); } catch (_) {}
    });
    update();
})();
