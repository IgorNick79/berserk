(function () {
    "use strict";

    var form = document.querySelector("[data-settings-form='draft']");
    if (!form) return;
    form.classList.add("settings-form--enhanced");

    function setVisible(selector, visible) {
        form.querySelectorAll(selector).forEach(function (node) {
            node.classList.toggle("is-hidden", !visible);
        });
    }

    function syncDependentSections() {
        var timerMode = form.querySelector("input[name='draft_timer_mode']:checked");
        var autoToggle = form.querySelector("[data-settings-toggle='auto-draft']");

        setVisible("[data-settings-dependent='timer-custom']", timerMode && timerMode.value === "custom");
        setVisible("[data-settings-dependent='auto-draft']", autoToggle && autoToggle.checked);
    }

    form.addEventListener("change", function (event) {
        if (event.target.matches("input[name='draft_timer_mode'], [data-settings-toggle='auto-draft']")) {
            syncDependentSections();
        }
    });

    form.querySelectorAll("[data-settings-stepper]").forEach(function (stepper) {
        var input = stepper.querySelector("[data-stepper-input]");
        var output = stepper.querySelector("[data-stepper-output]");
        var min = parseInt(stepper.dataset.min || "0", 10);
        var max = parseInt(stepper.dataset.max || "0", 10);
        var step = parseInt(stepper.dataset.step || "1", 10);
        var unit = input ? input.dataset.unit || "" : "";

        function render(value) {
            value = Math.max(min, Math.min(max, value));
            input.value = String(value);
            output.textContent = unit ? value + " " + unit : String(value);
        }

        stepper.addEventListener("click", function (event) {
            var button = event.target.closest("[data-stepper-action]");
            if (!button || !input) return;

            var value = parseInt(input.value || "0", 10);
            render(value + (button.dataset.stepperAction === "up" ? step : -step));
        });
    });

    syncDependentSections();
})();
