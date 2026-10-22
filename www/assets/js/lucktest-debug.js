(function () {
    function update(form) {
        var checkbox = form.querySelector('input[type="checkbox"][name="lucktest_quick_game"]');
        var group = form.querySelector('[data-lucktest-scenario]');
        if (!checkbox || !group) return;
        group.hidden = !checkbox.checked;
        group.querySelector('textarea').disabled = !checkbox.checked;
    }
    document.querySelectorAll('form').forEach(function (form) {
        update(form);
        form.addEventListener('change', function () { update(form); });
    });
}());
