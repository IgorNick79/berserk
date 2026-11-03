(function () {
    function update() {
        document.querySelectorAll('[data-glimpse-progressive]').forEach(function (field) {
            var form = field.closest('form') || field.closest('[data-mode]');
            var selected = form && form.querySelector('[name="draft_burn_mode"]:checked');
            field.hidden = !selected || selected.value !== 'progressive';
        });
    }
    document.addEventListener('change', update); update();
}());
