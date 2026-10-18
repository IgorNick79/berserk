(function () {
    "use strict";
    var root = document.querySelector('.pregame-layout');
    if (!root || !root.querySelector('[data-place-screen]') || root.dataset.placeReady) return;
    root.dataset.placeReady = 'true';
    var busy = false, poll = null;
    var commands = ['place_card', 'unplace_card', 'confirm_place'];
    function readUrl(value) {
        var url = new URL(value, location.href);
        ['cmd', 'card_id', 'row', 'col', 'ajax'].forEach(function (key) { url.searchParams.delete(key); });
        return url;
    }
    function isWaiting() {
        var screen = root.querySelector('[data-place-screen]');
        return screen && screen.dataset.placeWaiting === '1';
    }
    function schedule() {
        clearTimeout(poll);
        if (isWaiting()) {
            poll = setTimeout(function () {
                if (!document.hidden && !busy) load(readUrl(location.href), false);
                else schedule();
            }, 3000);
        }
    }
    function showError() {
        var host = root.querySelector('[data-place-screen]');
        if (!host) return;
        var error = host.querySelector('[data-place-error]');
        if (!error) {
            error = document.createElement('div');
            error.className = 'pregame-place-error';
            error.dataset.placeError = '';
            error.setAttribute('role', 'alert');
            error.appendChild(document.createTextNode('Не удалось обновить расстановку. '));
            var refresh = document.createElement('button');
            refresh.type = 'button';
            refresh.textContent = 'Обновить';
            refresh.addEventListener('click', function () { load(readUrl(location.href), true); });
            error.appendChild(refresh);
            host.prepend(error);
        }
    }
    async function load(url, keyboard) {
        if (busy) return;
        busy = true;
        clearTimeout(poll);
        root.setAttribute('aria-busy', 'true');
        var controller = new AbortController();
        var timeout = setTimeout(function () { controller.abort(); }, 15000);
        try {
            var response = await fetch(url.href, {
                signal: controller.signal, credentials: 'same-origin', cache: 'no-store', headers: {'X-Requested-With': 'XMLHttpRequest'}
            });
            if (!response.ok) throw new Error('HTTP ' + response.status);
            var documentNext = new DOMParser().parseFromString(await response.text(), 'text/html');
            var next = documentNext.querySelector('.pregame-layout');
            var screen = next && next.querySelector('[data-place-screen]');
            var clean = readUrl(response.url || url.href);
            if (!screen) {
                // A phase change needs the destination screen's own scripts. Never replay a command.
                clean.searchParams.delete('card');
                location.assign(clean.href);
                return;
            }
            var oldScreen = root.querySelector('[data-place-screen]');
            var content = root.querySelector('.pregame-panel-content');
            var scroll = oldScreen && oldScreen.dataset.placeView === 'list' && screen.dataset.placeView === 'list'
                ? content.scrollTop : 0;
            root.replaceChildren.apply(root, Array.from(next.childNodes).map(function (node) { return node.cloneNode(true); }));
            root.querySelector('.pregame-panel-content').scrollTop = scroll;
            history.replaceState(null, '', clean.href);
            if (keyboard) {
                var focus = root.querySelector('[data-place-close], .pregame-place-list .prepare-card-link, .pregame-panel-footer a');
                if (focus) focus.focus({preventScroll: true});
            }
        } catch (_) {
            // A lost response may follow a committed command: recovery is a read, never a retry.
            showError();
        } finally {
            clearTimeout(timeout);
            busy = false;
            root.removeAttribute('aria-busy');
            schedule();
        }
    }
    root.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        var link = event.target.closest('a[href]');
        if (!link || !root.contains(link) || link.target || link.hasAttribute('download')) return;
        var url = new URL(link.href);
        if (url.origin !== location.origin || url.pathname !== location.pathname) return;
        var command = url.searchParams.get('cmd');
        if (command ? commands.indexOf(command) === -1 : !url.searchParams.has('card')) return;
        event.preventDefault();
        load(url, event.detail === 0);
    });
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && !busy && isWaiting()) load(readUrl(location.href), false);
    });
    window.addEventListener('pageshow', function (event) {
        if (event.persisted && !busy && isWaiting()) load(readUrl(location.href), false);
    });
    history.replaceState(null, '', readUrl(location.href).href);
    schedule();
})();
