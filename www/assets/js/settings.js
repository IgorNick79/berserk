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

    form.querySelectorAll("[data-booster-config]").forEach(function (config) {
        var total = parseInt(config.dataset.total || "12", 10);
        var slots = ["common", "uncommon", "rare_slots"];
        var inputs = {};

        slots.forEach(function (slot) {
            inputs[slot] = config.querySelector("[data-booster-slot='" + slot + "']");
        });

        function value(slot) {
            return Math.max(0, Math.min(total, parseInt(inputs[slot].value || "0", 10)));
        }

        function setValue(slot, val) {
            inputs[slot].value = String(Math.max(0, Math.min(total, val)));
        }

        function balance(changedSlot) {
            var sum = slots.reduce(function (acc, slot) { return acc + value(slot); }, 0);
            var diff = sum - total;
            var order = slots.filter(function (slot) { return slot !== changedSlot; }).reverse();

            if (diff > 0) {
                order.concat([changedSlot]).some(function (slot) {
                    var current = value(slot);
                    var take = Math.min(current, diff);
                    setValue(slot, current - take);
                    diff -= take;
                    return diff <= 0;
                });
            } else if (diff < 0) {
                diff = -diff;
                order.concat([changedSlot]).some(function (slot) {
                    var current = value(slot);
                    var add = Math.min(total - current, diff);
                    setValue(slot, current + add);
                    diff -= add;
                    return diff <= 0;
                });
            }
        }

        function render() {
            var sum = 0;
            slots.forEach(function (slot) {
                var val = value(slot);
                var input = inputs[slot];
                var unit = input.dataset.unit || "";
                var output = config.querySelector("[data-booster-output='" + slot + "']");
                var bar = config.querySelector("[data-booster-bar='" + slot + "']");
                sum += val;
                if (output) output.textContent = unit ? val + " " + unit : String(val);
                if (bar) bar.style.width = String(total > 0 ? (val / total) * 100 : 0) + "%";
            });
            var totalOutput = config.querySelector("[data-booster-total]");
            if (totalOutput) totalOutput.textContent = String(sum);
        }

        slots.forEach(function (slot) {
            inputs[slot].addEventListener("input", function () {
                balance(slot);
                render();
            });
        });

        balance("rare_slots");
        render();
    });

    form.querySelectorAll("[data-rarity-chance]").forEach(function (block) {
        var input = block.querySelector("[data-rarity-input]");
        var output = block.querySelector("[data-rarity-output]");
        if (!input || !output) return;

        function renderRarityChance() {
            var rare = Math.max(0, Math.min(100, parseInt(input.value || "0", 10)));
            var ultra = 100 - rare;
            output.textContent = rare + "% / " + ultra + "%";
        }

        input.addEventListener("input", renderRarityChance);
        renderRarityChance();
    });

    syncDependentSections();
})();
