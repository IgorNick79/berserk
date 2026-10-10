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

    var containers = {};
    for (var i = 0; i < fragmentKeys.length; i++) {
        var key = fragmentKeys[i];
        var node = root.querySelector("[data-battle-fragment='" + key + "']");
        if (!node) return;
        containers[key] = node;
    }

    var syncVersion = parseInt(script.dataset.version || "-1", 10);
    if (!Number.isFinite(syncVersion)) syncVersion = -1;

    var uiState = {
        sel: parseInt(script.dataset.sel || "0", 10) || 0,
        mode: script.dataset.mode || "strike",
        pile: script.dataset.pile || ""
    };

    var uiRevision = 0;
    var requestSequence = 0;
    var activeRequestSequence = 0;
    var inFlight = false;
    var pendingForce = false;
    var pollTimer = 0;
    var stopped = false;
    var controller = null;

    function clearPollTimer() {
        if (pollTimer) {
            window.clearTimeout(pollTimer);
            pollTimer = 0;
        }
    }

    function scheduleNext() {
        clearPollTimer();
        if (stopped || document.hidden) return;
        pollTimer = window.setTimeout(function () {
            requestSnapshot(false);
        }, 2000);
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

    function buildUrl(forceSnapshot) {
        var url = new URL(syncUrl, window.location.href);
        url.searchParams.set("since_version", String(Math.max(0, syncVersion)));
        url.searchParams.set("sel", String(uiState.sel || 0));
        url.searchParams.set("mode", uiState.mode || "strike");
        url.searchParams.set("pile", uiState.pile || "");
        if (forceSnapshot) {
            url.searchParams.set("force_snapshot", "1");
        } else {
            url.searchParams.delete("force_snapshot");
        }
        return url;
    }

    function validSnapshot(data) {
        if (!data || data.ok !== true || data.type !== "snapshot" || !data.fragments) {
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
            mode: typeof ui.mode === "string" && ui.mode !== "" ? ui.mode : "strike",
            pile: typeof ui.pile === "string" ? ui.pile : ""
        };
    }

    function applySnapshot(data, requestUiRevision) {
        var incomingVersion = parseInt(data.sync_version, 10);
        if (!Number.isFinite(incomingVersion)) return;
        if (incomingVersion < syncVersion) return;

        if (requestUiRevision !== uiRevision) {
            syncVersion = Math.max(syncVersion, incomingVersion);
            pendingForce = true;
            return;
        }

        for (var i = 0; i < fragmentKeys.length; i++) {
            var key = fragmentKeys[i];
            containers[key].innerHTML = data.fragments[key];
        }

        syncVersion = incomingVersion;
        uiState = normalizeUi(data.ui);
    }

    function navigateToCurrentPage() {
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
        if (!data || data.ok !== true || typeof data.type !== "string") return;

        var incomingVersion = parseInt(data.sync_version, 10);
        if (Number.isFinite(incomingVersion) && incomingVersion < syncVersion) return;

        if (data.type === "no_change") {
            if (Number.isFinite(incomingVersion)) {
                syncVersion = Math.max(syncVersion, incomingVersion);
            }
            return;
        }

        if (data.type === "status") {
            if (Number.isFinite(incomingVersion)) {
                syncVersion = Math.max(syncVersion, incomingVersion);
            }
            if (data.status === "game_over") {
                navigateToCurrentPage();
            }
            return;
        }

        if (!validSnapshot(data)) return;
        applySnapshot(data, requestUiRevision);
    }

    function requestSnapshot(forceSnapshot) {
        if (stopped || document.hidden) return;

        if (inFlight) {
            pendingForce = pendingForce || !!forceSnapshot;
            return;
        }

        inFlight = true;
        var requestUiRevision = uiRevision;
        var sequence = ++requestSequence;
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
                if (stopped || document.hidden || sequence !== activeRequestSequence) return;
                handleResponse(data, requestUiRevision);
            })
            .catch(function () {})
            .finally(function () {
                if (sequence !== activeRequestSequence) return;
                inFlight = false;
                controller = null;
                activeRequestSequence = 0;
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

    function updateUiFromUrl(url) {
        uiState = {
            sel: url.searchParams.has("sel") ? (parseInt(url.searchParams.get("sel") || "0", 10) || 0) : 0,
            mode: url.searchParams.has("mode") && url.searchParams.get("mode") !== ""
                ? url.searchParams.get("mode")
                : "strike",
            pile: url.searchParams.has("pile") ? (url.searchParams.get("pile") || "") : ""
        };
        uiRevision++;
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

    window.addEventListener("popstate", function () {
        window.location.reload();
    });

    document.addEventListener("visibilitychange", function () {
        if (document.hidden) {
            clearPollTimer();
            cancelActiveRequest();
            return;
        }

        var forceSnapshot = pendingForce;
        pendingForce = false;
        requestSnapshot(forceSnapshot);
    });

    window.addEventListener("beforeunload", function () {
        stopped = true;
        clearPollTimer();
        cancelActiveRequest();
    });

    requestSnapshot(false);
})();
