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
                input.disabled = row.hidden || input.dataset.choiceDisabled === '1';
                if (row.hidden) input.checked = false;
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
    function refresh() { queued = false; document.querySelectorAll('.card-choice-form').forEach(function (f) { update(f); }); }
    document.addEventListener('click', function (e) {
        var button = e.target.closest('[data-creature-side]');
        if (button) update(button.closest('form'), button.dataset.creatureSide);
    });
    document.addEventListener('change', function (e) {
        if (e.target.matches('input[type="radio"]')) {
            var form = e.target.closest('.card-choice-form');
            if (form) update(form, null, e.target.value);
        }
    });
    new MutationObserver(function (records) {
        if (records.some(function (r) { return r.type === 'childList'; }) && !queued) { queued = true; requestAnimationFrame(refresh); }
    }).observe(document.documentElement, { childList: true, subtree: true });
    refresh();
}());
