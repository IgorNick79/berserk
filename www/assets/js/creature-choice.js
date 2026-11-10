(function () {
    'use strict';
    if (document.documentElement.dataset.creatureChoiceReady) return;
    document.documentElement.dataset.creatureChoiceReady = '1';
    var saved = new Map(), queued = false;
    function update(form, requested, chosen) {
        var controls = form.querySelector('[data-creature-sides]');
        if (!controls) return;
        var rows = Array.from(form.querySelectorAll('[data-creature-owner]'));
        if (!rows.length) return;
        var key = Array.from(form.querySelectorAll('input[type="hidden"]')).map(function (i) { return i.name + ':' + i.value; }).join('|');
        var previous = saved.get(key) || {};
        var side = requested || previous.side || controls.dataset.defaultSide || 'enemy';
        if (!rows.some(function (r) { return r.dataset.creatureOwner === side; })) side = rows[0].dataset.creatureOwner;
        controls.hidden = false;
        controls.querySelectorAll('[data-creature-side]').forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.creatureSide === side ? 'true' : 'false'); });
        rows.forEach(function (row) {
            row.hidden = row.dataset.creatureOwner !== side;
            var input = row.querySelector('input');
            if (input) {
                if (input.dataset.choiceDisabled === undefined) input.dataset.choiceDisabled = input.disabled ? '1' : '0';
                // Checked multi-target choices remain submitted across side changes.
                input.disabled = (row.hidden && !(input.type === 'checkbox' && input.checked)) || input.dataset.choiceDisabled === '1';
                if (row.hidden && input.type !== 'checkbox') input.checked = false;
            }
        });
        var visible = rows.filter(function (r) { return !r.hidden; }).map(function (r) { return r.querySelector('input'); }).filter(function (i) { return i && !i.disabled; });
        var selected = visible.find(function (i) { return i.value === (chosen || previous.value); });
        if (selected) selected.checked = true;
        if (visible.length && visible[0].type === 'radio' && !visible.some(function (i) { return i.checked; })) visible[0].checked = true;
        selected = visible.find(function (i) { return i.checked; });
        saved.set(key, {side: side, value: selected ? selected.value : ''});
        if (saved.size > 40) saved.delete(saved.keys().next().value);
    }
    var activeSelection = null;
    function updateSelection(form) {
        var context = form.dataset.cardSelection;
        var rows = Array.from(form.querySelectorAll('[data-card-instance]'));
        if (!activeSelection || activeSelection.context !== context) {
            activeSelection = {
                context: context,
                selected: rows.find(function (row) { return row.querySelector('input').checked; })?.dataset.cardInstance,
                side: 'enemy',
                row: 'all'
            };
        }
        var state = activeSelection;
        var sides = form.querySelector('[data-creature-sides]');
        var rowControls = form.querySelector('[data-card-rows]');
        if (sides) sides.hidden = false;
        if (rowControls) rowControls.hidden = false;
        if (!rows.some(function (row) { return row.dataset.creatureOwner === state.side; })) {
            state.side = rows[0]?.dataset.creatureOwner;
        }
        form.querySelectorAll('[data-creature-side], [data-card-row-filter]').forEach(function (button) {
            var pressed = button.dataset.creatureSide
                ? button.dataset.creatureSide === state.side
                : button.dataset.cardRowFilter === state.row;
            button.setAttribute('aria-pressed', String(pressed));
        });
        var selected = rows.find(function (row) { return row.dataset.cardInstance === state.selected; });
        if (!selected) state.selected = undefined;
        rows.forEach(function (row) {
            row.hidden = Boolean(sides && row.dataset.creatureOwner !== state.side)
                || (state.row !== 'all' && row.dataset.cardRow !== state.row);
            row.querySelector('input').checked = row === selected;
        });
        form.querySelector('[data-card-choice-status]').hidden = !selected || !selected.hidden;
        var submit = form.querySelector('button[type="submit"]');
        submit.disabled = !selected && form.dataset.cardMinSelect === '1';
    }
    function refresh() {
        queued = false;
        var selection = document.querySelector('[data-card-selection]');
        if (selection) updateSelection(selection);
        else activeSelection = null;
        document.querySelectorAll('.card-choice-form:not([data-card-selection])').forEach(function (f) { update(f); });
    }
    document.addEventListener('click', function (e) {
        var button = e.target.closest('[data-creature-side], [data-card-row-filter]');
        if (!button) return;
        var form = button.closest('form');
        if (!form) return;
        if (!form.hasAttribute('data-card-selection')) {
            update(form, button.dataset.creatureSide);
            return;
        }
        updateSelection(form);
        if (button.dataset.creatureSide) activeSelection.side = button.dataset.creatureSide;
        else activeSelection.row = button.dataset.cardRowFilter;
        updateSelection(form);
    });
    document.addEventListener('change', function (e) {
        if (!e.target.matches('input[type="radio"]')) return;
        var form = e.target.closest('.card-choice-form');
        if (!form) return;
        if (!form.hasAttribute('data-card-selection')) {
            update(form, null, e.target.value);
            return;
        }
        var row = e.target.closest('[data-card-instance]');
        if (!row) return;
        updateSelection(form);
        activeSelection.selected = row.dataset.cardInstance;
        updateSelection(form);
    });
    new MutationObserver(function (records) {
        if (records.some(function (r) { return r.type === 'childList'; }) && !queued) { queued = true; requestAnimationFrame(refresh); }
    }).observe(document.documentElement, { childList: true, subtree: true });
    refresh();
}());
