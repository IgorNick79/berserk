(function () {
    "use strict";
    var errors = {
        pause_busy: "Запрос или пауза уже действуют.", pause_cooldown: "После отказа, отмены или истечения запроса нужно подождать 5 минут.",
        pause_limit: "Вы уже использовали две паузы в этом матче.", pause_deadline_due: "Время ожидания истекло. Обновляю матч.",
        pause_stale_request: "Этот запрос уже завершён. Обновляю матч.", pause_turn_expired: "Время хода истекло. Сначала завершите обязательные действия.",
        pause_unsafe_wait: "На этом этапе запрос паузы недоступен.", pause_unsafe_informational_wait: "На этом этапе запрос паузы недоступен.",
        pause_invalid_timer: "Пауза недоступна: нет корректного таймера хода.", pause_not_active: "Пауза уже завершена.",
        pause_not_requested: "Запрос уже завершён.", pause_wrong_actor: "Этот ответ доступен другому игроку.",
        pause_invalid_duration: "Выберите от 1 до 10 минут с шагом в одну минуту.", match_paused: "Матч приостановлен. Дождитесь возобновления."
    };
    window.BerserkMatchPause = {
        message: function (text) { return errors[text] || text; },
        create: function (root, initialVersion, send, busy, onDue) {
            var model = null, version = -1, countdownKey = null, expiresAt = 0, dueKey = null;
            var interval = null, destroyed = false;
            var observedActiveRequest = null, seenResumes = new Set();
            var picker = document.createElement("dialog");
            picker.className = "battle-pause-picker";
            picker.setAttribute("aria-labelledby", "pause-picker-title");
            picker.innerHTML = '<h2 id="pause-picker-title">Пауза матча</h2><p>Соперник получит запрос. Пока он отвечает, время хода остановится.</p>'
                + '<label for="pause-minutes">Продолжительность: <output for="pause-minutes">1 мин</output></label>'
                + '<input id="pause-minutes" type="range" min="1" max="10" step="1" value="1">'
                + '<p>До двух принятых пауз на игрока. На ответ — 20 секунд.</p>'
                + '<div class="pause-picker-actions"><button type="button" class="button" data-picker-cancel>Отмена</button>'
                + '<button type="button" class="button" data-picker-send>Отправить запрос</button></div>';
            document.body.appendChild(picker);
            var slider = picker.querySelector("input");
            slider.addEventListener("input", function () { picker.querySelector("output").textContent = slider.value + " мин"; });
            picker.querySelector("[data-picker-cancel]").addEventListener("click", function () { picker.close(); });
            picker.querySelector("[data-picker-send]").addEventListener("click", function () {
                if (!model || busy() || model.allowed_commands.indexOf("request_pause") < 0) return;
                var duration = Number(slider.value) * 60;
                if (!Number.isInteger(duration) || duration < 60 || duration > 600 || duration % 60) return;
                picker.close(); send(new URLSearchParams({cmd: "request_pause", duration_seconds: String(duration)}));
            });
            function seconds() {
                if (!model || model.mode === "idle") return null;
                return Math.max(0, Math.ceil((expiresAt - performance.now()) / 1000));
            }
            function render() {
                if (!model || destroyed) return;
                var blocked = model.mode !== "idle", left = root.querySelector(".battle-left");
                var mask = root.querySelector("[data-match-pause-mask]");
                root.toggleAttribute("data-match-paused", blocked);
                if (blocked) root.setAttribute("data-match-paused", "1");
                document.body.classList.toggle("match-pause-active", blocked);
                if (left) { left.inert = blocked; if (blocked) left.setAttribute("aria-hidden", "true"); else left.removeAttribute("aria-hidden"); }
                if (mask) mask.hidden = !blocked;
                if (blocked) {
                    if (picker.open) picker.close();
                    document.querySelectorAll("dialog.card-image-popup[open]").forEach(function (node) { node.close(); });
                    var debug = root.querySelector("#battle-debug"), menu = root.querySelector(".battle-menu");
                    if (debug) debug.hidden = true;
                    if (menu) { menu.disabled = true; menu.setAttribute("aria-expanded", "false"); }
                }
                root.querySelectorAll("[data-open-pause]").forEach(function (node) {
                    node.disabled = busy() || model.allowed_commands.indexOf("request_pause") < 0;
                    node.title = model.used >= 2 ? "Лимит двух пауз исчерпан" : model.cooldown_until > model.server_now
                        ? "Повторный запрос доступен после ожидания" : "Пауза игры";
                });
                if (!blocked) return; // Normal controls are restored by the authoritative HTML snapshot.
                var content = root.querySelector(".info-panel-content");
                var section = root.querySelector("[data-match-pause-content]");
                if (!section && content) {
                    section = document.createElement("section"); section.className = "match-pause-content"; section.dataset.matchPauseContent = "";
                    section.innerHTML = '<h2></h2><div class="match-pause-countdown" data-pause-countdown role="timer"></div><p data-pause-description></p><div class="match-pause-decisions"></div>';
                    content.appendChild(section);
                }
                var remaining = seconds();
                if (section) {
                    section.querySelector("h2").textContent = model.mode === "active" ? "Пауза" : "Запрос паузы";
                    section.querySelector("[data-pause-countdown]").textContent = String(Math.floor(remaining / 60)).padStart(2, "0") + ":" + String(remaining % 60).padStart(2, "0");
                    var text = model.mode === "requested"
                        ? (model.allowed_commands.indexOf("cancel_pause_request") >= 0 ? "Ожидаем ответа соперника." : "Соперник просит паузу на " + model.duration_seconds / 60 + " мин.")
                        : (model.ready_self ? "Вы готовы, ожидаем соперника" : "Когда вернётесь, нажмите «Вернулся / Готов».");
                    if (model.mode === "active" && model.ready_opponent && !model.ready_self) text += " Соперник уже готов.";
                    if (remaining === 0) text = "Проверяем завершение паузы на сервере…";
                    section.querySelector("[data-pause-description]").textContent = text;
                    var decisions = section.querySelector(".match-pause-decisions");
                    var identity = model.mode + ":" + model.request_id + ":" + model.allowed_commands.join(",");
                    if (decisions.dataset.identity !== identity) {
                        decisions.replaceChildren(); decisions.dataset.identity = identity;
                        Object.entries({accept_pause: "Принять", reject_pause: "Отклонить", cancel_pause_request: "Отменить запрос"}).forEach(function (entry) {
                            if (model.allowed_commands.indexOf(entry[0]) < 0) return;
                            var button = document.createElement("button"); button.type = "button"; button.className = "button";
                            button.dataset.pauseCommand = entry[0]; button.textContent = entry[1]; decisions.appendChild(button);
                        });
                    }
                    decisions.querySelectorAll("button").forEach(function (node) { node.disabled = busy() || remaining === 0; });
                }
                var phase = root.querySelector(".info-phase");
                if (phase) phase.textContent = model.mode === "active" ? "Пауза" : "Запрос паузы";
                var primary = root.querySelector(".info-actions > .button");
                if (primary) {
                    if (primary.tagName !== "BUTTON") {
                        var replacement = document.createElement("button"); replacement.type = "button"; replacement.className = primary.className;
                        primary.replaceWith(replacement); primary = replacement;
                    }
                    var label = model.mode === "active" ? (model.ready_self ? "Вы готовы" : "Вернулся / Готов") : "Ожидаем ответа";
                    if (primary.textContent !== label || !primary.querySelector("span")) {
                        var span = document.createElement("span"); span.textContent = label; primary.replaceChildren(span);
                    }
                    if (model.mode === "active") primary.dataset.pauseCommand = "pause_ready"; else delete primary.dataset.pauseCommand;
                    primary.disabled = busy() || remaining === 0 || model.mode !== "active" || model.ready_self;
                }
                var key = model.request_id + ":" + model.mode;
                if (remaining === 0 && dueKey !== key) { dueKey = key; window.setTimeout(onDue, 0); }
            }
            function update(next, nextVersion) {
                if (destroyed || !next || !Number.isInteger(nextVersion) || nextVersion < version
                    || ["idle", "requested", "active"].indexOf(next.mode) < 0 || !Number.isFinite(next.server_now)
                    || !Array.isArray(next.allowed_commands)) return;
                if (next.mode !== "idle" && (typeof next.request_id !== "string"
                    || !Number.isFinite(next.mode === "requested" ? next.request_deadline_at : next.pause_deadline_at))) return;
                if (model && nextVersion === version && next.server_now < model.server_now) return;
                // A countdown is identified by its request, phase and deadline, not persistence version.
                // Keep its monotonic local expiry: a sample may move it earlier, never later.
                var deadline = next.mode === "requested" ? next.request_deadline_at : next.pause_deadline_at;
                var nextKey = next.mode === "idle" ? null : JSON.stringify([next.request_id, next.mode, deadline]);
                if (nextKey !== null) {
                    var candidate = performance.now() + Math.max(0, deadline - next.server_now) * 1000;
                    expiresAt = nextKey === countdownKey ? Math.min(expiresAt, candidate) : candidate;
                }
                if (nextKey !== countdownKey) dueKey = null;
                countdownKey = nextKey;
                var resumeEvent = null, lastResume = next.last_resume;
                if (lastResume && typeof lastResume.resume_event_id === "string" && lastResume.resume_event_id !== ""
                    && !seenResumes.has(lastResume.resume_event_id)) {
                    seenResumes.add(lastResume.resume_event_id); // Consume even if audio is blocked by the browser.
                    if (model && next.mode === "idle" && observedActiveRequest !== null
                        && lastResume.request_id === observedActiveRequest && Number.isFinite(lastResume.resumed_at)
                        && ["both_ready", "deadline"].indexOf(lastResume.reason) >= 0
                        && (!next.last_result || next.last_result.outcome !== "battle_finished")) {
                        resumeEvent = lastResume.resume_event_id;
                    }
                }
                observedActiveRequest = next.mode === "active" ? next.request_id : null;
                var wasBlocked = model && model.mode !== "idle";
                model = next; version = nextVersion; render();
                if (wasBlocked && next.mode === "idle" && !root.querySelector("a[href*='cmd=end_turn']")) window.setTimeout(onDue, 0);
                return resumeEvent;
            }
            function click(event) {
                var node = event.target.closest && event.target.closest("[data-open-pause], [data-pause-command]");
                if (!node || !root.contains(node)) return;
                event.preventDefault(); event.stopImmediatePropagation();
                if (node.disabled || busy() || !model) return;
                if (node.hasAttribute("data-open-pause")) {
                    if (model.allowed_commands.indexOf("request_pause") >= 0) picker.showModal();
                    return;
                }
                var cmd = node.dataset.pauseCommand;
                if (model.allowed_commands.indexOf(cmd) < 0 || seconds() === 0) return;
                send(new URLSearchParams({cmd: cmd, request_id: model.request_id}));
                render();
            }
            root.addEventListener("click", click, true);
            interval = window.setInterval(render, 250);
            var boot = document.querySelector("#match-pause-state");
            if (boot) { try { update(JSON.parse(boot.textContent), initialVersion); } catch (e) { /* Keep the server-rendered mask if boot data is malformed. */ } }
            return {update: update, render: render, retryReady: function (requestId) {
                // Retry only a definitely conflicted SET-ready, after the normal forced snapshot.
                if (!model || model.mode !== "active" || model.request_id !== requestId || model.ready_self
                    || model.allowed_commands.indexOf("pause_ready") < 0 || seconds() === 0 || busy()) return false;
                send(new URLSearchParams({cmd: "pause_ready", request_id: requestId}), 1); return true;
            }, destroy: function () {
                destroyed = true; window.clearInterval(interval); root.removeEventListener("click", click, true); picker.remove();
            }};
        }
    };
})();
