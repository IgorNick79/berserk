(function () {
    "use strict";

    var script = document.currentScript;
    if (!script) return;

    var root = document.querySelector("[data-battle-sync-root]");
    if (!root) return;

    var syncUrl = script.dataset.syncUrl || "";
    if (!syncUrl) return;

    var fragmentKeys = [
        "field_html",
        "fly_zones_html",
        "panel_html",
        "piles_html",
        "pile_reveal_html",
        "info_panel_html"
    ];

    for (var i = 0; i < fragmentKeys.length; i++) {
        var key = fragmentKeys[i];
        var node = root.querySelector("[data-battle-fragment='" + key + "']");
        if (!node) return;
    }

    var renderToken = script.dataset.renderToken || "";
    var syncVersion = parseInt(script.dataset.version || "-1", 10);
    if (!Number.isFinite(syncVersion)) syncVersion = -1;
    var turnTimer = window.BerserkTurnTimer
        ? window.BerserkTurnTimer.create(root, script.dataset.viewerKey, syncVersion, script.dataset.turnSoundUrl || "")
        : null;

    var matchPause = null, battleDebug = null, drag = null;

    function updateTurnTimer(data) {
        document.dispatchEvent(new Event("berserk:battle-updated"));
        if (pendingCommand && data && data.command_id === pendingCommand.id && data.command_status === "conflict"
            && pendingCommand.type === "pause_ready") pendingCommand.readyConflict = true;
        var resumeEvent = matchPause && data && data.match_pause
            ? matchPause.update(data.match_pause, data.sync_version) : null;
        if (turnTimer && data && Object.prototype.hasOwnProperty.call(data, "turn_timer")) {
            turnTimer.update(data.turn_timer, data.sync_version, !!resumeEvent);
        }
        if (resumeEvent && turnTimer) turnTimer.notify();
        if (battleDebug) battleDebug.render();
        if (drag) drag.observe(data);
    }

    var uiState = {
        sel: parseInt(script.dataset.sel || "0", 10) || 0,
        inspect: parseInt(script.dataset.inspect || "0", 10) || 0,
        mode: script.dataset.mode || "strike",
        modeExplicit: script.dataset.modeExplicit === "1",
        pile: script.dataset.pile || ""
    };

    var uiRevision = 0;
    var requestSequence = 0;
    var activeRequestSequence = 0;
    var inFlight = false;
    var commandState = "idle";
    var pendingCommand = null;
    var statusInFlight = false;
    var statusTimer = 0;
    var statusController = null;
    var statusRequestGeneration = 0;
    var forcedSyncTimer = 0;
    var forcedSyncInFlight = false;
    var forcedSyncGeneration = 0;
    var pendingForce = false;
    var pollTimer = 0;
    var stopped = false;
    var controller = null;
    var messageNode = root.querySelector("[data-battle-message]");
    var terminalCommandStatuses = {
        committed: true,
        rejected: true,
        conflict: true,
        failed: true
    };

    matchPause = window.BerserkMatchPause
        ? window.BerserkMatchPause.create(root, syncVersion, submitCommand, commandInFlight, function () { requestSnapshot(true); })
        : null;

    battleDebug = window.BerserkBattleDebug
        ? window.BerserkBattleDebug.create(root)
        : null;

    function commandInFlight() {
        return commandState !== "idle";
    }

    function clearPollTimer() {
        if (pollTimer) {
            window.clearTimeout(pollTimer);
            pollTimer = 0;
        }
    }

    function clearStatusTimer() {
        if (statusTimer) {
            window.clearTimeout(statusTimer);
            statusTimer = 0;
        }
    }

    function clearForcedSyncTimer() {
        if (forcedSyncTimer) {
            window.clearTimeout(forcedSyncTimer);
            forcedSyncTimer = 0;
        }
    }

    function scheduleNext() {
        clearPollTimer();
        if (stopped || commandInFlight()) return;
        pollTimer = window.setTimeout(function () {
            requestSnapshot(false);
        }, document.hidden ? 10000 : 2000);
    }

    function cancelActiveRequest() {
        if (!inFlight && !controller) return;

        activeRequestSequence = 0;
        inFlight = false;
        if (controller) {
            controller.abort();
            controller = null;
        }
    }

    function cancelStatusRequest() {
        clearStatusTimer();
        statusRequestGeneration++;
        if (statusController) {
            statusController.abort();
            statusController = null;
        }
        statusInFlight = false;
    }

    function buildUrl(forceSnapshot) {
        var url = new URL(syncUrl, window.location.href);
        url.searchParams.set("render_token", renderToken);
        url.searchParams.set("since_version", String(Math.max(0, syncVersion)));
        url.searchParams.delete("settings_sync_id");
        url.searchParams.set("sel", String(uiState.sel || 0));
        url.searchParams.set("inspect", String(uiState.inspect || 0));
        if (uiState.modeExplicit) {
            url.searchParams.set("mode", uiState.mode || "strike");
        } else {
            url.searchParams.delete("mode");
        }
        url.searchParams.set("pile", uiState.pile || "");
        if (forceSnapshot) {
            url.searchParams.set("force_snapshot", "1");
        } else {
            url.searchParams.delete("force_snapshot");
        }
        return url;
    }

    function buildCommandUrl() {
        var url = new URL(syncUrl, window.location.href);
        url.searchParams.set("ajax", "battle_command");
        url.searchParams.delete("since_version");
        url.searchParams.delete("force_snapshot");
        url.searchParams.delete("sel");
        url.searchParams.delete("inspect");
        url.searchParams.delete("mode");
        url.searchParams.delete("pile");
        return url;
    }

    function buildCommandStatusUrl(commandId) {
        var url = new URL(syncUrl, window.location.href);
        url.searchParams.set("ajax", "battle_command_status");
        url.searchParams.set("command_id", commandId);
        url.searchParams.delete("since_version");
        url.searchParams.delete("force_snapshot");
        url.searchParams.delete("sel");
        url.searchParams.delete("inspect");
        url.searchParams.delete("mode");
        url.searchParams.delete("pile");
        return url;
    }

    function addUiAndVersion(params) {
        params.set("expected_version", String(Math.max(0, syncVersion)));
        params.set("sel", String(uiState.sel || 0));
        params.set("inspect", String(uiState.inspect || 0));
        if (!params.has("mode") && uiState.modeExplicit) {
            params.set("mode", uiState.mode || "strike");
        }
        params.set("pile", uiState.pile || "");
    }

    function showMessage(text, tone) {
        if (battleDebug) battleDebug.render();
        if (!messageNode) return;

        messageNode.textContent = (window.BerserkMatchPause ? window.BerserkMatchPause.message(text) : text) || "";
        messageNode.hidden = !text;
        messageNode.classList.toggle("battle-ajax-message--error", tone === "error");
        messageNode.classList.toggle("battle-ajax-message--info", tone !== "error");
    }

    function clearMessage() {
        showMessage("", "info");
    }

    function generateCommandId() {
        if (window.crypto && typeof window.crypto.randomUUID === "function") {
            return window.crypto.randomUUID();
        }

        if (window.crypto && typeof window.crypto.getRandomValues === "function") {
            var bytes = new Uint8Array(16);
            window.crypto.getRandomValues(bytes);
            var hex = "";
            for (var i = 0; i < bytes.length; i++) {
                hex += bytes[i].toString(16).padStart(2, "0");
            }
            return "cmd-" + Date.now().toString(36) + "-" + hex;
        }

        return "";
    }

    function validCommandStatus(data, commandId) {
        if (!data || typeof data !== "object") return false;
        if (data.command_id !== commandId) return false;
        if (typeof data.command_status !== "string") return false;
        if (typeof data.terminal !== "boolean") return false;
        return true;
    }

    function isTerminalCommandStatus(data) {
        return data
            && data.terminal === true
            && !!terminalCommandStatuses[data.command_status];
    }

    function isNonTerminalCommandStatus(data) {
        return data
            && (data.terminal === false
                || data.command_status === "processing"
                || data.command_status === "unknown");
    }

    function validSnapshot(data) {
        if (!data || (data.type !== "snapshot" && data.type !== "conflict") || !data.fragments) {
            return false;
        }
        for (var i = 0; i < fragmentKeys.length; i++) {
            if (typeof data.fragments[fragmentKeys[i]] !== "string") {
                return false;
            }
        }
        return data.ui && typeof data.ui === "object";
    }

    function normalizeUi(ui) {
        return {
            sel: parseInt(ui.sel || "0", 10) || 0,
            inspect: parseInt(ui.inspect || "0", 10) || 0,
            mode: typeof ui.mode === "string" && ui.mode !== "" ? ui.mode : "strike",
            modeExplicit: ui.mode_explicit === true || ui.mode_explicit === 1 || ui.mode_explicit === "1",
            pile: typeof ui.pile === "string" ? ui.pile : ""
        };
    }

    function applySnapshot(data, requestUiRevision) {
        var incomingVersion = parseInt(data.sync_version, 10);
        if (!Number.isFinite(incomingVersion)) return false;
        if (incomingVersion < syncVersion) return false;

        updateTurnTimer(data); // Apply the server pause barrier even when local selection changed.
        if (requestUiRevision !== uiRevision) {
            syncVersion = Math.max(syncVersion, incomingVersion);
            pendingForce = true;
            return false;
        }

        if (drag) drag.beforeSnapshot(data, requestUiRevision);

        // panel_html now belongs to the replaceable InfoPanel content. Compose
        // both fragments before replacement, never write into its detached node.
        var info = document.createElement("template");
        info.innerHTML = data.fragments.info_panel_html;
        var selectedCard = info.content.querySelector("[data-battle-fragment='panel_html']");
        if (!selectedCard) return false;
        selectedCard.innerHTML = data.fragments.panel_html;
        var targets = {};
        for (var i = 0; i < fragmentKeys.length; i++) {
            var key = fragmentKeys[i];
            if (key === "panel_html") continue;
            targets[key] = root.querySelector("[data-battle-fragment='" + key + "']");
            if (!targets[key]) return false;
        }
        for (var j = 0; j < fragmentKeys.length; j++) {
            var fragment = fragmentKeys[j];
            if (fragment === "panel_html") continue;
            targets[fragment].innerHTML = fragment === "info_panel_html" ? info.innerHTML : data.fragments[fragment];
        }

        syncVersion = incomingVersion;
        if (data.render_token) renderToken = data.render_token;
        uiState = normalizeUi(data.ui);
        if (drag) drag.afterSnapshot();
        updateTurnTimer(data);
        if (turnTimer) turnTimer.render();
        return true;
    }

    function navigateToCurrentPage() {
        if (battleDebug) battleDebug.destroy();
        if (matchPause) matchPause.destroy();
        if (turnTimer) turnTimer.destroy();
        stopped = true;
        clearPollTimer();
        cancelActiveRequest();
        var url = new URL(window.location.href);
        url.searchParams.delete("ajax");
        url.searchParams.delete("since_version");
        url.searchParams.delete("force_snapshot");
        window.location.href = url.pathname + url.search;
    }

    function handleResponse(data, requestUiRevision) {
        if (!data || data.ok !== true || typeof data.type !== "string") return false;

        var incomingVersion = parseInt(data.sync_version, 10);
        if (Number.isFinite(incomingVersion) && incomingVersion < syncVersion) return false;

        if (data.type === "no_change") {
            if (Number.isFinite(incomingVersion)) {
                syncVersion = Math.max(syncVersion, incomingVersion);
            }
            updateTurnTimer(data);
            return "unchanged";
        }

        if (data.type === "status") {
            if (Number.isFinite(incomingVersion)) {
                syncVersion = Math.max(syncVersion, incomingVersion);
            }
            if (data.status === "game_over") {
                navigateToCurrentPage();
                return "game_over";
            }
            return "status";
        }

        if (!validSnapshot(data)) return false;
        return applySnapshot(data, requestUiRevision);
    }

    function handleCommandResponse(data, status, requestUiRevision) {
        var isConflict = status === 409 || (data && data.type === "conflict");

        if (!data || typeof data !== "object") {
            showMessage("Не удалось подтвердить результат действия. Обновляю состояние партии.", "error");
            pendingForce = true;
            return;
        }

        if (data.status === "game_over") {
            navigateToCurrentPage();
            return;
        }

        if (data.type === "status") {
            if (status >= 500) {
                showMessage("Не удалось подтвердить результат действия. Обновляю состояние партии.", "error");
                pendingForce = true;
                return;
            }
            if (typeof data.error === "string" && data.error !== "") {
                showMessage(data.error, "error");
            }
            return;
        }

        if (data.type === "conflict") {
            if (validSnapshot(data)) {
                applySnapshot(data, requestUiRevision);
            } else {
                pendingForce = true;
            }
            showMessage(data.error || "Состояние партии изменилось. Действие не выполнено.", "error");
            return;
        }

        if (!validSnapshot(data)) {
            showMessage("Не удалось подтвердить результат действия. Обновляю состояние партии.", "error");
            pendingForce = true;
            return;
        }

        applySnapshot(data, requestUiRevision);

        if (data.ok === true && !isConflict) {
            clearMessage();
            return;
        }

        showMessage(data.error || "Действие отклонено.", "error");
    }

    function completeCommand() {
        var finalError = pendingCommand && pendingCommand.finalError;
        var readyRetry = pendingCommand && pendingCommand.readyConflict && pendingCommand.readyRetryCount < 1
            ? pendingCommand.pauseRequestId : null;
        if (readyRetry) finalError = null;
        commandState = "idle";
        if (drag) drag.refresh();
        pendingCommand = null;
        statusInFlight = false;
        statusController = null;
        forcedSyncInFlight = false;
        forcedSyncGeneration++;
        clearStatusTimer();
        clearForcedSyncTimer();
        if (finalError) {
            showMessage(finalError, "error");
        } else {
            clearMessage();
        }
        scheduleNext();
        if (readyRetry && matchPause) window.setTimeout(function () { matchPause.retryReady(readyRetry); }, 0);
    }

    function scheduleForcedSyncRetry() {
        if (!pendingCommand || stopped || commandState !== "syncing") return;

        clearForcedSyncTimer();
        if (document.hidden) return;

        forcedSyncTimer = window.setTimeout(function () {
            forcedSyncTimer = 0;
            startForcedCommandSync();
        }, 2000);
    }

    function startForcedCommandSync() {
        if (!pendingCommand || stopped) return;
        if (forcedSyncInFlight) return;

        commandState = "syncing";
        clearStatusTimer();
        clearForcedSyncTimer();
        showMessage("Синхронизирую состояние партии.", "info");

        if (document.hidden) {
            return;
        }

        forcedSyncInFlight = true;
        var syncGeneration = ++forcedSyncGeneration;
        requestSnapshot(true, function (ok) {
            if (syncGeneration !== forcedSyncGeneration) return;
            forcedSyncInFlight = false;
            if (!pendingCommand || commandState !== "syncing") return;

            if (ok) {
                completeCommand();
                return;
            }

            showMessage("Не удалось синхронизировать состояние. Повторяю попытку.", "error");
            scheduleForcedSyncRetry();
        });
    }

    function scheduleCommandRecovery(delay) {
        if (!pendingCommand || stopped || commandState !== "recovering") return;

        clearStatusTimer();
        if (document.hidden) return;

        statusTimer = window.setTimeout(function () {
            requestCommandStatus();
        }, Math.max(0, delay));
    }

    function enterCommandRecovery(message) {
        if (!pendingCommand || stopped) return;

        commandState = "recovering";
        pendingCommand.recoveryDelay = pendingCommand.recoveryDelay || 2000;
        if (message) showMessage(message, "error");
        scheduleCommandRecovery(0);
    }

    function continueCommandRecovery() {
        if (!pendingCommand || stopped) return;

        pendingCommand.recoveryDelay = Math.min((pendingCommand.recoveryDelay || 2000) * 1.5, 10000);
        scheduleCommandRecovery(pendingCommand.recoveryDelay);
    }

    function requestCommandStatus() {
        if (!pendingCommand || stopped || commandState !== "recovering") return;
        if (statusInFlight || document.hidden) return;

        statusInFlight = true;
        var statusGeneration = ++statusRequestGeneration;
        var requestController = new AbortController();
        statusController = requestController;
        var commandId = pendingCommand.id;

        fetch(buildCommandStatusUrl(commandId), {
            headers: {"Accept": "application/json"},
            cache: "no-store",
            signal: requestController.signal
        })
            .then(function (response) {
                return response.json().catch(function () { return null; });
            })
            .then(function (data) {
                if (statusGeneration !== statusRequestGeneration || !pendingCommand || commandState !== "recovering" || commandId !== pendingCommand.id) return;

                if (!validCommandStatus(data, commandId)) {
                    showMessage("Восстанавливаю результат действия.", "info");
                    continueCommandRecovery();
                    return;
                }
                updateTurnTimer(data);

                if (isTerminalCommandStatus(data)) {
                    if (pendingCommand && data.command_status !== "committed") {
                        pendingCommand.finalError = data.error || "Действие не выполнено.";
                    }
                    showMessage("Результат действия подтверждён. Обновляю поле.", "info");
                    startForcedCommandSync();
                    return;
                }

                if (isNonTerminalCommandStatus(data)) {
                    showMessage("Действие ещё обрабатывается. Жду подтверждения.", "info");
                    continueCommandRecovery();
                    return;
                }

                continueCommandRecovery();
            })
            .catch(function () {
                if (statusGeneration !== statusRequestGeneration || !pendingCommand || commandState !== "recovering" || commandId !== pendingCommand.id) return;
                showMessage("Связь нестабильна. Продолжаю проверять результат действия.", "error");
                continueCommandRecovery();
            })
            .finally(function () {
                if (statusGeneration !== statusRequestGeneration || !pendingCommand || commandId !== pendingCommand.id) return;
                statusInFlight = false;
                statusController = null;
            });
    }

    function handleJournalCommandResponse(data, status, commandId, requestUiRevision) {
        // This request was definitively rejected before journal registration.
        // Unknown/processing journal outcomes still use recovery below.
        if (status >= 400 && status < 500 && data && data.command_id === commandId
            && data.request_status === "rejected_before_registration") {
            pendingCommand.finalError = data.error || "Действие не выполнено.";
            startForcedCommandSync();
            return;
        }
        if (!validCommandStatus(data, commandId)) {
            enterCommandRecovery("Не удалось подтвердить результат действия. Проверяю журнал команды.");
            return;
        }

        updateTurnTimer(data);

        if (isTerminalCommandStatus(data)) {
            if (pendingCommand && data.command_status !== "committed") {
                pendingCommand.finalError = data.error || "Действие не выполнено.";
            }
            if (data.type === "snapshot" || data.type === "conflict" || data.type === "status") {
                handleCommandResponse(data, status, requestUiRevision);
            }
            startForcedCommandSync();
            return;
        }

        if (isNonTerminalCommandStatus(data)) {
            enterCommandRecovery("Действие отправлено. Жду подтверждения сервера.");
            return;
        }

        enterCommandRecovery("Не удалось подтвердить результат действия. Проверяю журнал команды.");
    }

    function requestSnapshot(forceSnapshot, onComplete) {
        if (stopped || (commandInFlight() && commandState !== "syncing")) {
            if (typeof onComplete === "function") onComplete(false);
            return;
        }

        if (inFlight) {
            pendingForce = pendingForce || !!forceSnapshot;
            if (typeof onComplete === "function") onComplete(false);
            return;
        }

        inFlight = true;
        var requestUiRevision = uiRevision;
        var sequence = ++requestSequence;
        var completionResult = false;
        activeRequestSequence = sequence;
        controller = new AbortController();

        fetch(buildUrl(!!forceSnapshot), {
            headers: {"Accept": "application/json"},
            cache: "no-store",
            signal: controller.signal
        })
            .then(function (response) {
                if (!response.ok) return null;
                return response.json().catch(function () { return null; });
            })
            .then(function (data) {
                if (stopped || sequence !== activeRequestSequence) {
                    completionResult = false;
                    return;
                }
                var handled = handleResponse(data, requestUiRevision);
                completionResult = handled === true || handled === "game_over";
            })
            .catch(function () {
                completionResult = false;
            })
            .finally(function () {
                if (sequence !== activeRequestSequence) return;
                inFlight = false;
                controller = null;
                activeRequestSequence = 0;
                if (typeof onComplete === "function") {
                    onComplete(completionResult);
                }
                if (stopped) return;

                if (pendingForce && !document.hidden) {
                    pendingForce = false;
                    requestSnapshot(true);
                    return;
                }

                scheduleNext();
            });
    }

    function isLocalUiLink(url) {
        if (url.origin !== window.location.origin || url.pathname !== window.location.pathname) {
            return false;
        }
        if (url.searchParams.has("cmd") || url.searchParams.has("ajax")) {
            return false;
        }
        return url.searchParams.has("sel")
            || url.searchParams.has("mode")
            || url.searchParams.has("pile");
    }

    function isCommandUrl(url) {
        if (url.origin !== window.location.origin || url.pathname !== window.location.pathname) {
            return false;
        }
        return url.searchParams.has("cmd") && !url.searchParams.has("ajax");
    }

    function appendCommandParamsFromUrl(params, url) {
        url.searchParams.forEach(function (value, key) {
            if (key === "first" || key === "second" || key === "game" || key === "ajax") {
                return;
            }
            params.append(key, value);
        });
    }

    function applyFormFieldsToUrl(url, form, submitter) {
        var formData;
        try {
            formData = submitter ? new FormData(form, submitter) : new FormData(form);
        } catch (e) {
            formData = new FormData(form);
        }

        // Clear old query values once, including unchecked checkbox fields.
        var names = Object.create(null);
        Array.prototype.forEach.call(form.elements, function (element) {
            if (element.name) names[element.name] = true;
        });
        formData.forEach(function (value, key) { names[key] = true; });
        Object.keys(names).forEach(function (key) {
            url.searchParams.delete(key);
            if (key.slice(-2) === "[]") {
                var base = key.slice(0, -2);
                var oldNames = [];
                url.searchParams.forEach(function (value, name) {
                    if (name === base || name.indexOf(base + "[") === 0) oldNames.push(name);
                });
                oldNames.forEach(function (name) { url.searchParams.delete(name); });
            }
        });
        formData.forEach(function (value, key) {
            if (typeof value === "string") {
                url.searchParams.append(key, value);
            }
        });

        if (submitter && submitter.name && !submitter.disabled) {
            url.searchParams.set(submitter.name, submitter.value || "");
        }
    }

    function submitCommand(params, readyRetryCount) {
        if (stopped || commandInFlight()) return;

        var commandId = generateCommandId();
        if (!commandId) {
            showMessage("Браузер не может создать безопасный идентификатор команды.", "error");
            return;
        }

        uiState.inspect = 0;
        var commandUiRevision = uiRevision;
        if (drag) drag.cancel();
        commandState = "sending";
        if (drag) drag.refresh();
        pendingCommand = {
            id: commandId,
            type: params.get("cmd"), pauseRequestId: params.get("request_id"), readyRetryCount: readyRetryCount || 0,
            uiRevision: commandUiRevision,
            recoveryDelay: 2000
        };
        clearPollTimer();
        cancelActiveRequest();
        cancelStatusRequest();
        addUiAndVersion(params);
        params.set("command_id", commandId);
        params.set("csrf", window.berserkGameCsrf || "");
        showMessage("Отправляю действие.", "info");

        var commandController = new AbortController();
        var commandTimeout = window.setTimeout(function () {
            commandController.abort();
        }, 20000);

        fetch(buildCommandUrl(), {
            method: "POST",
            headers: {
                "Accept": "application/json",
                "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8"
            },
            cache: "no-store",
            body: params.toString(),
            signal: commandController.signal
        })
            .then(function (response) {
                return response.json()
                    .catch(function () { return null; })
                    .then(function (data) {
                        if (!pendingCommand || pendingCommand.id !== commandId
                            || (commandState !== "sending" && commandState !== "recovering")) return;
                        handleJournalCommandResponse(data, response.status, commandId, commandUiRevision);
                    });
            })
            .catch(function () {
                if (!pendingCommand || pendingCommand.id !== commandId || commandState !== "sending") return;
                enterCommandRecovery("Связь прервалась. Проверяю результат действия.");
            })
            .finally(function () {
                window.clearTimeout(commandTimeout);
            });
    }

    function updateUiFromUrl(url, fromDrag) {
        if (drag && !fromDrag) drag.cancel();
        uiState = {
            sel: url.searchParams.has("sel") ? (parseInt(url.searchParams.get("sel") || "0", 10) || 0) : 0,
            inspect: parseInt(url.searchParams.get("inspect") || "0", 10) || 0,
            mode: url.searchParams.has("mode") && url.searchParams.get("mode") !== ""
                ? url.searchParams.get("mode")
                : "strike",
            modeExplicit: url.searchParams.has("mode") && url.searchParams.get("mode") !== "",
            pile: url.searchParams.has("pile") ? (url.searchParams.get("pile") || "") : ""
        };
        uiRevision++;
        if (drag) drag.refresh();
    }

    root.addEventListener("click", function (event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        var link = event.target.closest ? event.target.closest("a[href]") : null;
        if (!link || !root.contains(link) || (link.target && link.target !== "_self")) return;

        var url;
        try {
            url = new URL(link.href, window.location.href);
        } catch (e) {
            return;
        }

        if (!isLocalUiLink(url)) return;

        event.preventDefault();
        updateUiFromUrl(url);
        window.history.pushState(null, "", url.pathname + url.search);
        requestSnapshot(true);
    });

    root.addEventListener("click", function (event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        var link = event.target.closest ? event.target.closest("a[href]") : null;
        if (!link || !root.contains(link) || (link.target && link.target !== "_self")) return;

        var url;
        try {
            url = new URL(link.href, window.location.href);
        } catch (e) {
            return;
        }

        if (!isCommandUrl(url)) return;
        if (commandInFlight()) {
            event.preventDefault();
            return;
        }

        event.preventDefault();
        var params = new URLSearchParams();
        appendCommandParamsFromUrl(params, url);
        submitCommand(params);
    });

    root.addEventListener("submit", function (event) {
        if (event.defaultPrevented) return;

        var form = event.target;
        if (!form || !form.matches || !form.matches("form")) return;

        var method = (form.getAttribute("method") || "get").toLowerCase();
        if (method !== "get") return;

        var action = form.getAttribute("action") || window.location.href;
        var url;
        try {
            url = new URL(action, window.location.href);
        } catch (e) {
            return;
        }

        applyFormFieldsToUrl(url, form, event.submitter || null);

        if (!isCommandUrl(url)) return;
        if (commandInFlight()) {
            event.preventDefault();
            return;
        }

        event.preventDefault();
        var params = new URLSearchParams();
        appendCommandParamsFromUrl(params, url);
        submitCommand(params);
    });

    window.addEventListener("popstate", function () {
        window.location.reload();
    });

    document.addEventListener("visibilitychange", function () {
        if (document.hidden) {
            if (turnTimer) turnTimer.suspend();
            clearPollTimer();
            clearForcedSyncTimer();
            if (commandState === "syncing") {
                forcedSyncGeneration++;
                forcedSyncInFlight = false;
            }
            cancelActiveRequest();
            if (commandState === "recovering") {
                cancelStatusRequest();
            }
            // Reuse normal sync at a lower rate so an armed turn alert can sound in a background tab.
            scheduleNext();
            return;
        }

        if (turnTimer) turnTimer.resume();

        if (commandInFlight()) {
            if (commandState === "sending") {
                // The POST may still be pending or its response may have been lost.
                // Read its journal entry; never send the game command again.
                enterCommandRecovery("Проверяю результат отправленного действия.");
            } else if (commandState === "recovering") {
                scheduleCommandRecovery(0);
            } else if (commandState === "syncing") {
                startForcedCommandSync();
            }
            return;
        }

        var forceSnapshot = pendingForce;
        pendingForce = false;
        requestSnapshot(forceSnapshot);
    });

    window.addEventListener("beforeunload", function () {
        if (battleDebug) battleDebug.destroy();
        if (matchPause) matchPause.destroy();
        if (turnTimer) turnTimer.destroy();
        stopped = true;
        clearPollTimer();
        clearForcedSyncTimer();
        forcedSyncGeneration++;
        forcedSyncInFlight = false;
        cancelActiveRequest();
        cancelStatusRequest();
    });

    function initDrag() {
        if (drag || !window.BerserkBattleDrag) return;
        drag = window.BerserkBattleDrag.create(root, {
            state: function () { return { selected: uiState.sel, mode: uiState.mode,
                revision: uiRevision, version: syncVersion, busy: stopped || commandInFlight() }; },
            select: function (id) {
                var url = new URL(window.location.href);
                Array.from(url.searchParams.keys()).forEach(function (key) {
                    if (key !== 'first' && key !== 'second' && key !== 'game') url.searchParams.delete(key);
                });
                url.searchParams.set('sel', id);
                updateUiFromUrl(url, true);
                window.history.pushState(null, '', url.pathname + url.search);
                requestSnapshot(true);
                return uiRevision;
            },
            submit: function (url) {
                if (!isCommandUrl(url) || commandInFlight() || stopped) return;
                var params = new URLSearchParams();
                appendCommandParamsFromUrl(params, url);
                submitCommand(params);
            }
        });
    }
    initDrag();
    window.addEventListener('berserk:battle-drag-ready', initDrag);
    requestSnapshot(false);
})();
