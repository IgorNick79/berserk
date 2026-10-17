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
    function update() {
        var positions = [];
        cards.forEach(function (card) {
            var checkbox = card.querySelector("input[type=checkbox]");
            if (!checkbox) return;
            card.querySelector(".prepare-card").classList.toggle("prepare-card--selected", checkbox.checked);
            if (checkbox.checked) positions.push(checkbox.value);
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
            if (checkbox && !checkbox.disabled) checkbox.checked = !checkbox.checked;
            var preview = document.querySelector(".pregame-panel-content");
            if (preview) {
                preview.replaceChildren(card.querySelector("[data-solomon-preview]").content.cloneNode(true));
                preview.dataset.pregameContent = "card";
                preview.scrollTop = 0;
            }
            update();
        });
    });
    if (form) form.addEventListener("submit", function () {
        form.querySelectorAll("button").forEach(function (button) { button.setAttribute("aria-disabled", "true"); });
    });
    update();
})();
