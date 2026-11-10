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
    var boardSelector = '[data-battle-fragment="field_html"], [data-battle-fragment="fly_zones_html"]';
    var selectedCardSelector = '[data-drag-card][data-card-selection-highlight]';

    function fieldChoice(root, event, viewer) {
        if (event.target.closest('button, input, select, textarea, [role="button"], [data-mini-hint], .mini-indicator, [data-card-original]')) return null;
        var card = event.target.closest('[data-drag-card]');
        if (!card || !card.closest(boardSelector) || card.closest('[hidden]') || !root.contains(card)) return null;
        var link = event.target.closest('a[href]');
        if (link) {
            var url = new URL(link.href);
            if (!link.matches('.card-link') || url.origin !== location.origin || url.pathname !== location.pathname
                || url.searchParams.has('cmd') || url.searchParams.get('inspect') !== card.dataset.dragCard) return null;
        }
        var choice = pendingCandidate(root, card.dataset.dragCard, viewer);
        return choice ? {...choice, card: card, link: link} : null;
    }

    function pendingCandidate(root, id, viewer) {
        var form = root.querySelector('form[data-card-selection][data-card-field-owner]');
        if (!form || (viewer && form.dataset.cardFieldOwner !== viewer)) return null;
        var rows = Array.from(form.querySelectorAll('[data-card-instance]')).filter(function (row) {
            return row.dataset.cardInstance === id;
        });
        return rows.length === 1 ? {form: form, id: id, group: rows[0].closest('[data-selection-group]').dataset.selectionGroup} : null;
    }

    function paintField(form, selected) {
        var root = document.querySelector('[data-battle-sync-root]');
        if (!root) return;
        root.querySelectorAll(selectedCardSelector).forEach(function (card) {
            card.removeAttribute('data-card-selection-highlight');
        });
        if (!form?.dataset.cardFieldOwner || !selected.size) return;
        root.querySelectorAll(boardSelector).forEach(function (board) {
            board.querySelectorAll('[data-drag-card]').forEach(function (card) {
                if (selected.has(card.dataset.dragCard)) card.setAttribute('data-card-selection-highlight', '');
            });
        });
    }

    function selectionGroups(form) {
        return Array.from(form.querySelectorAll('[data-selection-group]')).map(function (node) {
            return {
                id: node.dataset.selectionGroup,
                min: Number(node.dataset.selectionMin),
                max: Number(node.dataset.selectionMax),
                options: Array.from(node.querySelectorAll('.choice-radio')).map(function (row) {
                    var input = row.querySelector('input');
                    return {row: row, input: input, key: row.dataset.cardInstance ?? input.value};
                })
            };
        });
    }

    function selectCard(form, id, groupId, checked) {
        var groups = selectionGroups(form);
        var group = groups.find(function (group) { return group.id === groupId; });
        var option = group?.options.find(function (option) { return option.key === id; });
        if (!option) return false;
        updateSelection(form, groups);
        var values = activeSelection.groups.get(groupId);
        var radio = option.input.type === 'radio';
        var add = checked ?? (radio || !values.has(id));
        if (!add) {
            values.delete(id);
        } else if (radio) {
            values.clear();
            values.add(id);
        } else if (!values.has(id) && values.size >= group.max) {
            return false;
        } else {
            values.add(id);
        }
        if (add && option.row.dataset.cardInstance) {
            activeSelection.side = option.row.dataset.creatureOwner;
            if (activeSelection.row !== 'all' && activeSelection.row !== option.row.dataset.cardRow) {
                activeSelection.row = option.row.dataset.cardRow || 'all';
            }
        }
        updateSelection(form, groups);
        return true;
    }

    window.BerserkCardSelection = {
        fieldChoice: fieldChoice,
        pendingCandidate: pendingCandidate,
        select: selectCard
    };

    function updateSelection(form, groups = selectionGroups(form)) {
        var context = form.dataset.cardSelection;
        if (!activeSelection || activeSelection.context !== context) {
            activeSelection = {context: context, groups: new Map(), side: 'enemy', row: 'all'};
        }
        var state = activeSelection;
        var sides = form.querySelector('[data-creature-sides]');
        var rowControls = form.querySelector('[data-card-rows]');
        var rows = Array.from(form.querySelectorAll('[data-card-instance]'));
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

        var selected = new Set();
        var valid = true;
        var currentGroups = new Map();
        groups.forEach(function (group) {
            var previous = state.groups.get(group.id);
            var values = new Set(group.options.filter(function (option) {
                return previous ? previous.has(option.key) : option.input.checked;
            }).slice(0, group.max).map(function (option) { return option.key; }));
            currentGroups.set(group.id, values);
            if (values.size < group.min || values.size > group.max) valid = false;
            group.options.forEach(function (option) {
                var row = option.row;
                if (row.dataset.cardInstance) {
                    row.hidden = Boolean(sides && row.dataset.creatureOwner !== state.side)
                        || (state.row !== 'all' && row.dataset.cardRow !== state.row);
                }
                option.input.checked = values.has(option.key);
                if (option.input.checked && row.dataset.cardInstance) selected.add(row.dataset.cardInstance);
            });
        });
        state.groups = currentGroups;
        form.querySelector('button[type="submit"]').disabled = !valid;
        paintField(form, selected);
    }

    // Display order is alphabetical. Some effects consume the submitted list
    // sequentially, so preserve the original order for explicitly ranked rows.
    document.addEventListener('formdata', function (event) {
        var form = event.target;
        if (!form.matches('form[data-card-selection]')) return;
        var ordered = new Map();
        form.querySelectorAll('[data-selection-order] input:checked:not(:disabled)').forEach(function (input) {
            if (!ordered.has(input.name)) ordered.set(input.name, []);
            ordered.get(input.name).push(input);
        });
        ordered.forEach(function (inputs, name) {
            inputs.sort(function (a, b) {
                return Number(a.parentNode.dataset.selectionOrder) - Number(b.parentNode.dataset.selectionOrder);
            });
            event.formData.delete(name);
            inputs.forEach(function (input) { event.formData.append(name, input.value); });
        });
    }, true);
    function refresh() {
        queued = false;
        var selection = document.querySelector('[data-card-selection]');
        if (selection) updateSelection(selection);
        else {
            activeSelection = null;
            paintField(null, null);
        }
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
        if (!e.target.matches('input[type="radio"], input[type="checkbox"]')) return;
        var form = e.target.closest('.card-choice-form');
        if (!form) return;
        if (!form.hasAttribute('data-card-selection')) {
            if (e.target.type === 'radio') update(form, null, e.target.value);
            return;
        }
        var row = e.target.closest('.choice-radio');
        var group = row?.closest('[data-selection-group]');
        if (!group) return;
        selectCard(form, row.dataset.cardInstance ?? e.target.value, group.dataset.selectionGroup, e.target.checked);
    });
    new MutationObserver(function (records) {
        if (records.some(function (r) { return r.type === 'childList'; }) && !queued) { queued = true; requestAnimationFrame(refresh); }
    }).observe(document.documentElement, { childList: true, subtree: true });
    refresh();
}());
