(function () {
    "use strict";

    // Presentation only: this module never fetches or sends a game command.
    window.BerserkTurnTimer = {
        create: function (root, viewerKey, initialVersion, soundUrl) {
            if (root.berserkTurnTimer) return root.berserkTurnTimer;
            var model = null;
            var receivedAt = 0;
            var version = -1;
            var baselinePeriod = null;
            var notifiedPeriod = null;
            var interval = null;
            var destroyed = false;
            var audioContext = null;
            var audioFile = null;

            function remaining() {
                if (!model) return null;
                if (model.status === "expired") return 0;
                var elapsed = model.status === "running" ? Math.max(0, performance.now() - receivedAt) / 1000 : 0;
                return Math.max(0, Math.ceil(model.remaining_seconds - elapsed));
            }

            function render() {
                if (destroyed) return;
                // InfoPanel is replaced by AJAX; always locate its current node.
                var node = root.querySelector("[data-turn-timer]");
                if (!node) return;
                var seconds = remaining();
                var mine = model && model.owner === viewerKey;
                var label = model ? (mine ? "Ваше время" : "Соперник") : "Нет таймера";
                if (model && model.status === "paused") label += " · Пауза";
                if (model && model.status === "expired") label += " · Истекло";
                node.dataset.status = model ? model.status : "unavailable";
                node.dataset.mine = mine ? "1" : "0";
                node.setAttribute("aria-label", label);
                var valueNode = node.querySelector("[data-turn-timer-value]");
                var labelNode = node.querySelector("[data-turn-timer-label]");
                var text = seconds === null ? "—:—"
                    : String(Math.floor(seconds / 60)).padStart(2, "0") + ":" + String(seconds % 60).padStart(2, "0");
                if (valueNode && valueNode.textContent !== text) valueNode.textContent = text;
                if (labelNode && labelNode.textContent !== label) labelNode.textContent = label;
            }

            function armAudio() {
                if (destroyed) return;
                try {
                    var Context = window.AudioContext || window.webkitAudioContext;
                    if (Context && !audioContext) audioContext = new Context();
                    if (audioContext && audioContext.state === "suspended") audioContext.resume().catch(function () {});
                    if (soundUrl && !audioFile) {
                        audioFile = new window.Audio(soundUrl);
                        audioFile.volume = 0.45;
                        audioFile.preload = "auto";
                    }
                } catch (e) { /* Browser audio restrictions must not affect the battle. */ }
            }

            function beep() {
                if (!audioContext || audioContext.state !== "running") return;
                var start = audioContext.currentTime;
                [660, 880].forEach(function (frequency, i) {
                    var oscillator = audioContext.createOscillator();
                    var gain = audioContext.createGain();
                    var at = start + i * 0.14;
                    oscillator.type = "sine";
                    oscillator.frequency.value = frequency;
                    gain.gain.setValueAtTime(0, at);
                    gain.gain.linearRampToValueAtTime(0.07, at + 0.015);
                    gain.gain.exponentialRampToValueAtTime(0.001, at + 0.12);
                    oscillator.connect(gain);
                    gain.connect(audioContext.destination);
                    oscillator.start(at);
                    oscillator.stop(at + 0.13);
                    oscillator.onended = function () { oscillator.disconnect(); gain.disconnect(); };
                });
            }

            function notify() {
                if (destroyed) return;
                try {
                    if (audioFile) {
                        audioFile.currentTime = 0;
                        audioFile.play().catch(beep);
                    } else beep();
                } catch (e) { /* Sound is best effort and cannot block a command. */ }
            }

            function update(next, nextVersion, resumeNotification) {
                if (destroyed || !Number.isInteger(nextVersion) || nextVersion < version) return;
                if (next === null) {
                    // A legacy battle has no clock. Never invent a period on the client.
                    if (model) return;
                    version = nextVersion;
                    render();
                    return;
                }
                if (!next || !Number.isInteger(next.period_id) || next.period_id < 1
                    || ["host", "player"].indexOf(next.owner) === -1
                    || ["running", "paused", "expired"].indexOf(next.status) === -1
                    || !Number.isFinite(next.remaining_seconds) || next.remaining_seconds < 0
                    || !Number.isFinite(next.server_now) || !Number.isFinite(next.limit_seconds)
                    || next.limit_seconds <= 0 || next.remaining_seconds > next.limit_seconds) return;
                if (model && next.period_id < model.period_id) return;
                var previousVersion = version;
                // Persistence order and wall-clock projection order are independent.
                // Remember the newer state version even if its clock sample is stale.
                version = nextVersion;
                if (model && next.period_id === model.period_id && next.server_now < model.server_now) return;
                if (model && next.period_id === model.period_id && next.server_now === model.server_now
                    && (nextVersion === previousVersion || (next.status === model.status
                        && next.remaining_seconds === model.remaining_seconds && next.anchor_at === model.anchor_at
                        && next.pause_reason === model.pause_reason && next.owner === model.owner))) {
                    render(); // Duplicate second: do not refund the time since reception.
                    return;
                }
                if (baselinePeriod === null) baselinePeriod = next.period_id;
                model = Object.assign({}, next);
                version = nextVersion;
                receivedAt = performance.now();
                // A resume alert also covers an own new period in this same server sample,
                // including a period whose start resolution has not finished yet.
                if (resumeNotification && model.period_id > baselinePeriod && model.owner === viewerKey) {
                    notifiedPeriod = model.period_id;
                }
                if (model.period_id > baselinePeriod && model.owner === viewerKey && model.status === "running"
                    && model.remaining_seconds > 0 && notifiedPeriod !== model.period_id) {
                    notifiedPeriod = model.period_id;
                    notify();
                }
                render();
            }

            function suspend() {
                if (interval !== null) window.clearInterval(interval);
                interval = null;
            }

            function resume() {
                if (destroyed) return;
                render();
                if (interval === null && !document.hidden) interval = window.setInterval(render, 250);
            }

            var controller = {update: update, render: render, suspend: suspend, resume: resume, notify: notify,
                destroy: function () {
                    if (destroyed) return;
                    destroyed = true;
                    suspend();
                    document.removeEventListener("pointerdown", armAudio);
                    document.removeEventListener("keydown", armAudio);
                    if (audioContext) audioContext.close().catch(function () {});
                    if (audioFile) audioFile.pause();
                    root.berserkTurnTimer = null;
                }};
            root.berserkTurnTimer = controller;
            document.addEventListener("pointerdown", armAudio);
            document.addEventListener("keydown", armAudio);
            var boot = root.querySelector("[data-turn-timer]");
            if (boot && boot.dataset.turnTimerState) {
                try { update(JSON.parse(boot.dataset.turnTimerState), initialVersion); } catch (e) {}
            }
            resume();
            return controller;
        }
    };
})();
