(function () {
    "use strict";

    var script = document.currentScript;
    if (!script) return;

    var syncUrl = script.dataset.syncUrl || "";
    var loadedVersion = parseInt(script.dataset.version || "0", 10);
    var serverNow = parseInt(script.dataset.serverNow || "0", 10);
    var deadlineAt = parseInt(script.dataset.deadlineAt || "0", 10);
    var hostTotal = parseInt(script.dataset.hostTotal || "0", 10);
    var playerTotal = parseInt(script.dataset.playerTotal || "0", 10);
    var loadedAt = Math.floor(Date.now() / 1000);

    function fmt(seconds) {
        seconds = Math.max(0, Math.floor(seconds));
        var minutes = Math.floor(seconds / 60);
        var rest = seconds % 60;
        return minutes + ":" + (rest < 10 ? "0" : "") + rest;
    }

    function approxNow() {
        return serverNow + (Math.floor(Date.now() / 1000) - loadedAt);
    }

    function render() {
        var now = approxNow();
        var actionEl = document.querySelector("[data-draft-action-time]");
        if (actionEl && deadlineAt > 0) {
            actionEl.textContent = fmt(deadlineAt - now);
        }

        var hostEl = document.querySelector("[data-draft-total-host]");
        if (hostEl) hostEl.textContent = fmt(hostTotal);

        var playerEl = document.querySelector("[data-draft-total-player]");
        if (playerEl) playerEl.textContent = fmt(playerTotal);
    }

    function sync() {
        if (!syncUrl) return;

        fetch(syncUrl, {
            headers: {"Accept": "application/json"},
            cache: "no-store"
        })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (data) {
                if (!data) return;
                if (data.version !== loadedVersion || data.changed || data.status !== "draft") {
                    window.location.reload();
                    return;
                }

                if (data.timer) {
                    serverNow = parseInt(data.timer.server_now || serverNow, 10);
                    deadlineAt = parseInt(data.timer.deadline_at || deadlineAt, 10);
                    hostTotal = parseInt((data.timer.remaining_total || {}).host || hostTotal, 10);
                    playerTotal = parseInt((data.timer.remaining_total || {}).player || playerTotal, 10);
                    loadedAt = Math.floor(Date.now() / 1000);
                    render();
                }
            })
            .catch(function () {});
    }

    render();
    window.setInterval(render, 1000);
    window.setInterval(sync, 2000);
})();
