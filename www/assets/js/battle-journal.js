(function () {
    "use strict";
    var script = document.currentScript, panel = document.getElementById("battle-journal");
    if (!script || !panel) return;
    var url = script.dataset.journalUrl;
    var scroll = panel.querySelector("[data-journal-scroll]"), list = panel.querySelector("[data-journal-entries]");
    var notice = panel.querySelector("[data-journal-notice]"), status = panel.querySelector("[data-journal-state]");
    var cursor = 0, busy = false, seen = new Set(), stopped = false, hasGaps = false;

    function place() {
        if (panel.hidden) return;
        if (panel.closest('.battle-secondary-view')) { scroll.style.maxHeight="none"; return; }
        var button = document.querySelector("[data-action='battle-log']");
        if (!button) return;
        button.setAttribute("aria-expanded", "true");
        var rect = button.getBoundingClientRect(), above = rect.top - 16, below = window.innerHeight - rect.bottom - 16;
        var useAbove = above >= below;
        scroll.style.maxHeight = Math.max(60, Math.min(window.innerHeight * 0.45, (useAbove ? above : below) - 130)) + "px";
        panel.style.bottom = "auto";
        panel.style.top = Math.max(8, useAbove ? rect.top - panel.offsetHeight - 12 : rect.bottom + 12) + "px";
    }

    function refresh() {
        if (panel.hidden || busy || stopped) return;
        busy = true;
        place();
        var controller = new AbortController();
        var timeout = window.setTimeout(function () { controller.abort(); }, 8000);
        var request = new URL(url, window.location.href);
        request.searchParams.set("after", String(cursor));
        fetch(request.toString(), {cache: "no-store", headers: {Accept: "application/json"}, signal: controller.signal})
            .then(function (response) {
                if (!response.ok) {
                    if (response.status === 403 || response.status === 404) stopped = true;
                    throw new Error("journal unavailable");
                }
                return response.json();
            })
            .then(function (data) {
                if (!Array.isArray(data.entries) || !Number.isSafeInteger(data.cursor) || data.cursor < cursor) throw new Error("invalid cursor");
                var atBottom = scroll.scrollHeight - scroll.scrollTop - scroll.clientHeight < 32;
                var first = list.children.length === 0, previousTop = scroll.scrollTop;
                data.entries.forEach(function (entry) {
                    if (!Number.isSafeInteger(entry.seq) || entry.seq <= cursor || entry.seq > data.cursor || typeof entry.text !== "string") throw new Error("invalid entry");
                });
                data.entries.forEach(function (entry) {
                    if (seen.has(entry.seq)) return;
                    if (entry.kind === "gap") { hasGaps = true; seen.add(entry.seq); return; }
                    var item = document.createElement("li");
                    // Keep the text API and journal history intact. Asset paths are a fixed 1..6 whitelist.
                    var dice = /^(Бросок|Изменение кубиков): атакующий (\d+), защитник (\d+|без броска)\.$/.exec(entry.text);
                    if (dice) {
                        function appendDie(value) {
                            if (!/^[1-6]$/.test(value)) { item.appendChild(document.createTextNode(value)); return; }
                            var icon = document.createElement("img");
                            icon.className = "battle-journal-die";
                            icon.src = "/assets/images/battle/dice/dice_" + value + ".svg";
                            icon.alt = value;
                            icon.setAttribute("aria-label", "Кубик: " + value);
                            icon.width = 24; icon.height = 24;
                            item.appendChild(icon);
                        }
                        item.appendChild(document.createTextNode((dice[1] === "Изменение кубиков" ? "Изменение кубиков: " : "") + "Атакующий "));
                        appendDie(dice[2]);
                        item.appendChild(document.createTextNode(", защитник "));
                        appendDie(dice[3]);
                        item.appendChild(document.createTextNode("."));
                    } else {
                        var positions = /(Свой|Вражеский) \[([1-3]):([1-5])\]/g, match, offset = 0;
                        while ((match = positions.exec(entry.text))) {
                            item.appendChild(document.createTextNode(entry.text.slice(offset, match.index)));
                            var badge = document.createElement("span");
                            badge.className = "coords coords--" + (match[1] === "Свой" ? "own" : "enemy");
                            var icon = document.createElement("img");
                            icon.src = "/assets/images/battle/coordinate.svg"; icon.alt = ""; icon.width = 12; icon.height = 12;
                            badge.setAttribute("aria-label", match[0]);
                            badge.append(icon, document.createTextNode("[" + ["", "I", "II", "III"][Number(match[2])] + ":" + match[3] + "]"));
                            item.appendChild(badge); offset = positions.lastIndex;
                        }
                        item.appendChild(document.createTextNode(entry.text.slice(offset)));
                    }
                    item.dataset.eventSeq = String(entry.seq);
                    list.appendChild(item); seen.add(entry.seq);
                });
                cursor = data.cursor;
                hasGaps = hasGaps || data.recording.has_gaps === true;
                notice.textContent = hasGaps
                    ? "История боя записана не полностью. Некоторые события могут отсутствовать"
                    : "Экспериментальная неполная история. Записываются только поддержанные события.";
                status.textContent = !data.recording.available ? "Запись этого боя ещё не начата."
                    : (list.children.length ? "" : "Доступных для вас событий пока нет.");
                if (atBottom || first) scroll.scrollTop = scroll.scrollHeight;
                else scroll.scrollTop = previousTop;
                place();
                return data.has_more;
            })
            .then(function (more) { busy = false; if (more) refresh(); })
            .catch(function () { busy = false; status.textContent = stopped ? "Доступ к журналу недоступен." : "Журнал временно недоступен. Повтор при следующем обновлении боя."; })
            .finally(function () { window.clearTimeout(timeout); });
    }
    document.addEventListener("click", function (event) {
        var button = event.target.closest("[data-action='battle-log']");
        if (button && !button.disabled) {
            var opening=panel.hidden, battle=document.querySelector("[data-battle-sync-root]");
            if(battle && battle.secondaryPanel) battle.secondaryPanel(opening?panel:null);
            panel.hidden = !opening;
            button.setAttribute("aria-expanded", String(!panel.hidden));
            if (!panel.hidden) { place(); refresh(); }
        } else if (event.target.closest("[data-journal-close]")) {
            var battle=document.querySelector("[data-battle-sync-root]");
            if(battle && battle.secondaryPanel) battle.secondaryPanel(null);
            panel.hidden = true;
        }
    });
    // Reuse the existing sync lifecycle; no second polling interval or gameplay request.
    document.addEventListener("berserk:battle-updated", refresh);
    window.addEventListener("online", refresh);
    window.addEventListener("resize", place);
}());
