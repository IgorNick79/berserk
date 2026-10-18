(function () {
    "use strict";
    var stack = document.querySelector("[data-solomon-stack]");
    var form = document.getElementById("solomon-decision");
    if (!stack) return;
    var prefix = "solomon-selection:" + stack.dataset.selectionKey + ":";
    var key = prefix + stack.dataset.decision;
    var selected = [];
    try {
        selected = JSON.parse(sessionStorage.getItem(key) || "[]");
        if (!Array.isArray(selected)) selected = [];
        Object.keys(sessionStorage).forEach(function (entry) {
            if (entry.indexOf(prefix) === 0 && entry !== key) sessionStorage.removeItem(entry);
        });
    } catch (_) { selected = []; }
    var cards = Array.from(stack.querySelectorAll("[data-solomon-position]"));
    var previewPosition = stack.dataset.previewPosition || "-1";
    function update() {
        var positions = [];
        cards.forEach(function (card) {
            var checkbox = card.querySelector("input[type=checkbox]");
            if (!checkbox) return;
            card.querySelector(".prepare-card").classList.toggle("prepare-card--selected", checkbox.checked);
            var label = card.querySelector('.solomon-stack__select span');
            if (label) label.textContent = checkbox.checked ? 'Выбрано' : 'Выбрать';
            if (checkbox.checked) positions.push(checkbox.value);
        });
        var current = cards.find(function (card) { return card.dataset.solomonPosition === previewPosition; });
        var currentInput = current && current.querySelector('input[type=checkbox]');
        document.querySelectorAll('[data-solomon-panel-action]').forEach(function (button) {
            button.disabled = !currentInput || (button.dataset.solomonPanelAction === 'select' ? currentInput.checked : !currentInput.checked);
        });
        var count = document.querySelector("[data-solomon-selected]");
        if (count) count.textContent = String(positions.length);
        try { sessionStorage.setItem(key, JSON.stringify(positions)); } catch (_) {}
    }
    cards.forEach(function (card) {
        var checkbox = card.querySelector("input[type=checkbox]");
        if (checkbox) {
            checkbox.checked = !checkbox.disabled && selected.indexOf(checkbox.value) !== -1;
            checkbox.addEventListener("change", update);
        }
        card.querySelector(".prepare-card-link").addEventListener("click", function (event) {
            event.preventDefault();
            previewPosition = card.dataset.solomonPosition;
            var preview = document.querySelector(".pregame-panel-content");
            if (preview) {
                preview.replaceChildren(card.querySelector("[data-solomon-preview]").content.cloneNode(true));
                preview.dataset.pregameContent = "card";
                preview.scrollTop = 0;
            }
            update();
        });
    });
    document.querySelectorAll('[data-solomon-panel-action]').forEach(function (button) {
        button.addEventListener('click', function () {
            var card = cards.find(function (entry) { return entry.dataset.solomonPosition === previewPosition; });
            var checkbox = card && card.querySelector('input[type=checkbox]');
            if (!checkbox || checkbox.disabled) return;
            checkbox.checked = button.dataset.solomonPanelAction === 'select';
            update();
        });
    });
    if (form) form.addEventListener("submit", function () {
        form.querySelectorAll("button").forEach(function (button) { button.setAttribute("aria-disabled", "true"); });
    });
    update();
})();
