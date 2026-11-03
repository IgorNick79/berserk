(function () {
    "use strict";
    function update(event) {
        var form = document.getElementById('glimpse-decision');
        if (!form) return;
        var picks = Array.from(document.querySelectorAll('input[form="glimpse-decision"][name="pick"]'));
        var burns = Array.from(document.querySelectorAll('input[form="glimpse-decision"][name="positions[]"]'));
        var pick = picks.find(function (input) { return input.checked; });
        if (event && event.target.name === 'pick') burns.forEach(function (input) { if (input.value === event.target.value) input.checked = false; });
        if (event && event.target.name === 'positions[]' && event.target.checked && pick && pick.value === event.target.value) pick.checked = false;
        pick = picks.find(function (input) { return input.checked; });
        var limit = Number(form.dataset.required);
        var selected = burns.filter(function (input) { return input.checked; });
        if (selected.length > limit && event && event.target.name === 'positions[]') {
            event.target.checked = false;
            selected = burns.filter(function (input) { return input.checked; });
        }
        burns.forEach(function (input) {
            input.disabled = !input.checked && selected.length >= limit;
            input.closest('label').setAttribute('aria-disabled', String(input.disabled));
        });
        document.querySelector('[data-glimpse-picked]').textContent = pick ? '1' : '0';
        document.querySelector('[data-glimpse-burned]').textContent = selected.length;
        form.querySelector('button[type="submit"]').disabled = !pick || selected.length !== Number(form.dataset.required);
        document.querySelectorAll('[data-glimpse-card]').forEach(function (card) {
            card.classList.toggle('glimpse-take', card.querySelector('[name="pick"]').checked);
            card.classList.toggle('glimpse-burn', card.querySelector('[name="positions[]"]').checked);
        });
    }
    document.addEventListener('submit', function (event) {
        if (event.target.id !== 'glimpse-decision') return;
        update();
        if (event.target.querySelector('button[type="submit"]').disabled) { event.preventDefault(); return; }
        if (window.berserkDraftCommand) {
            event.preventDefault();
            window.berserkDraftCommand(new URLSearchParams(new FormData(event.target)));
        }
    });
    document.addEventListener('click', function (event) {
        var finish = event.target.closest('.screen-draft--glimpse a[href*="cmd=finish_draft"]');
        if (finish && window.berserkDraftCommand) { event.preventDefault(); window.berserkDraftCommand(new URL(finish.href).searchParams); }
        if (event.target.closest('[data-glimpse-card] .prepare-card-link')) event.preventDefault();
    });
    document.addEventListener('change', update);
    update();
}());
