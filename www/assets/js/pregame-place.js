(function () {
    "use strict";
    var root = document.querySelector('.pregame-layout');
    if (!root || !root.querySelector('[data-place-screen]') || root.dataset.placeReady) return;
    root.dataset.placeReady = 'true';
    var busy = false, poll = null;
    var transport = document.querySelector('script[data-stage][data-version]');
    var model = {version:Number(transport.dataset.version),revision:0,selected:Number(root.querySelector('[data-battle-drag-state]')?.dataset.dragSelected || 0),mode:'place',busy:false};
    var drag = null, last = null, queuedSelection = null, commandQueued = false;
    var commands = ['place_card', 'unplace_card', 'confirm_place', 'pathfinder_choice'];
    function readUrl(value) {
        var url = new URL(value, location.href);
        ['cmd', 'card_id', 'row', 'col', 'ajax', 'prepare_version', 'prepare_token', 'csrf', 'first', 'second'].forEach(function (key) { url.searchParams.delete(key); });
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
            refresh.className = 'button';
            refresh.textContent = 'Обновить';
            refresh.addEventListener('click', function () { load(readUrl(location.href), true); });
            error.appendChild(refresh);
            host.prepend(error);
        }
    }
    async function load(url, keyboard, post) {
        if (busy) {if(!post)queuedSelection={url:url,keyboard:keyboard};return last;}
        busy = true;
        model.busy=!!post;
        clearTimeout(poll);
        root.setAttribute('aria-busy', 'true');
        var requestRevision=model.revision;
        var recover=false;
        var controller = new AbortController();
        var timeout = setTimeout(function () { controller.abort(); }, 15000);
        try {
            var response = await fetch(url.href, {
                method:post?'POST':'GET',body:post || undefined, signal: controller.signal, credentials: 'same-origin', cache: 'no-store', headers: {'X-Requested-With': 'XMLHttpRequest'}
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
            if(!post && requestRevision!==model.revision)return last;
            var nextTransport=documentNext.querySelector('script[data-stage][data-version]');
            var selected=Number(next.querySelector('[data-battle-drag-state]').dataset.dragSelected);
            var version=Number(nextTransport.dataset.version);
            var pauseNode=documentNext.querySelector('#match-pause-state');
            last={version:version,pause:pauseNode?JSON.parse(pauseNode.textContent):null};
            if(!post && version===model.version && selected===Number(root.querySelector('[data-battle-drag-state]').dataset.dragSelected)){
                if(window.BerserkPlaceScreen.afterSwap)window.BerserkPlaceScreen.afterSwap(last);
                if(drag)drag.observe({sync_version:version,match_pause:last.pause});
                return last;
            }
            if(drag)drag.beforeSnapshot({sync_version:version,ui:{sel:selected,mode:'place'}},requestRevision);
            if(window.BerserkPlaceScreen.beforeSwap)window.BerserkPlaceScreen.beforeSwap();
            next.querySelectorAll('link[rel="stylesheet"],script[src]').forEach(function(n){n.remove();});
            root.replaceChildren.apply(root, Array.from(next.childNodes).map(function (node) { return node.cloneNode(true); }));
            model.version=version;model.selected=selected;
            transport.dataset.version=String(version);
            if(window.BerserkPlaceScreen.afterSwap)window.BerserkPlaceScreen.afterSwap(last);
            if(drag)drag.afterSnapshot();
            root.querySelector('.pregame-panel-content').scrollTop = scroll;
            history.replaceState(null, '', clean.href);
            if (keyboard) {
                var focus = root.querySelector('[data-place-close], .pregame-place-list .card-link, .pregame-panel-footer a');
                if (focus) focus.focus({preventScroll: true});
            }
        } catch (_) {
            // A lost response may follow a committed command: recovery is a read, never a retry.
            recover=!!post;
            showError();
        } finally {
            clearTimeout(timeout);
            busy = false;
            model.busy=false;
            if(drag)drag.refresh();
            root.removeAttribute('aria-busy');
            schedule();
            if(recover)setTimeout(function(){load(readUrl(location.href),false);},0);
            else if(queuedSelection){var queued=queuedSelection;queuedSelection=null;load(queued.url,queued.keyboard);}
        }
        return last;
    }
    window.BerserkPlaceScreen = {
        state:function(){return {...model,reading:busy,commandQueued:commandQueued};},
        read: async function(){while(busy)await new Promise(function(resolve){setTimeout(resolve,20);});return load(readUrl(location.href),false);},
        submit: function(params){
            if(commandQueued || commands.indexOf(params.get('cmd'))===-1)return;
            if(busy){commandQueued=true;var held=new URLSearchParams(params),heldVersion=model.version;['first','second','game','ajax'].forEach(function(k){held.delete(k);});return (async function(){while(busy)await new Promise(function(r){setTimeout(r,20);});commandQueued=false;held.set('csrf',window.berserkGameCsrf);held.set('prepare_version',String(heldVersion));return load(readUrl(location.href),false,held);})();}
            params=new URLSearchParams(params);['first','second','game','ajax'].forEach(function(k){params.delete(k);});
            params.set('csrf',window.berserkGameCsrf);params.set('prepare_version',String(model.version));
            return load(readUrl(location.href),false,params);
        }
    };
    document.querySelectorAll('.pregame-layout link[rel="stylesheet"]').forEach(function(n){document.head.append(n);});
    if(window.BerserkBattleDrag)drag=window.BerserkBattleDrag.create(root.closest('.screen'),{
        context:'place',state:function(){return {...model,busy:model.busy||commandQueued};},
        select:function(id){model.selected=id;model.revision++;var url=readUrl(location.href);url.searchParams.set('card',id);load(url,false);return model.revision;},
        submit:function(url){window.BerserkPlaceScreen.submit(url.searchParams);}
    });
    root.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        if(event.target.closest('[data-view-close]')){event.preventDefault();model.selected=0;model.revision++;var closeUrl=readUrl(location.href);closeUrl.searchParams.set('card','0');load(closeUrl,false);return;}
        var link = event.target.closest('a[href]');
        if (!link || !root.contains(link) || link.target || link.hasAttribute('download')) return;
        var url = new URL(link.href);
        if (url.origin !== location.origin || url.pathname !== location.pathname) return;
        var command = url.searchParams.get('cmd');
        if (command ? commands.indexOf(command) === -1 : !url.searchParams.has('card')) return;
        event.preventDefault();
        if(command)window.BerserkPlaceScreen.submit(url.searchParams);else {model.selected=Number(url.searchParams.get('card'));model.revision++;load(url,event.detail===0);}
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
