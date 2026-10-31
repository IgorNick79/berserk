(function () {
    'use strict';
    if (!document.body.classList.contains('view-deck-page') || window.berserkViewReady) return;
    window.berserkViewReady = true;
    const config = window.berserkViewTransport;
    if (!config) return;
    let version = Number(config.version), busy = false, refreshing = false, selection = 0, pause = null, forceNext = false;
    const key = 'berserk-view-command:' + config.game;
    let pending = null;
    try { pending = JSON.parse(sessionStorage.getItem(key)); } catch (_) {}
    const url = action => 'index.php?game=' + encodeURIComponent(config.game) + '&ajax=' + action;
    function notice(text) { const node = document.querySelector('[data-view-notice]'); if (node) node.textContent = text || ''; }
    function persist() { if (pending) sessionStorage.setItem(key, JSON.stringify(pending)); else sessionStorage.removeItem(key); }
    async function request(address, options) {
        const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 10000);
        try { const r = await fetch(address, {...options, credentials:'same-origin', cache:'no-store', signal:controller.signal});
            if (r.status === 401 || r.status === 403) throw Error('Нет доступа. Обновите страницу.');
            const data = await r.json(); if (!r.ok && !data.command_status) throw Error(data.error || 'Ошибка запроса'); return data;
        } finally { clearTimeout(timer); }
    }
    async function refresh(force) {
        if (refreshing) { forceNext = forceNext || force; return; }
        refreshing = true;
        const refreshAbort=new AbortController(),refreshTimeout=setTimeout(()=>refreshAbort.abort(),10000);
        const epoch = selection, address = new URL(location.href); address.searchParams.delete('cmd');
        try {
            const response = await fetch(address, {credentials:'same-origin',cache:'no-store',signal:refreshAbort.signal});
            if (!response.ok) throw Error('Не удалось обновить состояние');
            const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
            const script = doc.querySelector('script[data-stage][data-version][data-csrf]');
            if (!script) throw Error('Не удалось загрузить состояние');
            if (script.dataset.stage !== 'view') { location.assign(address); return; }
            if (epoch !== selection) return;
            const nextVersion = Number(script.dataset.version);
            if (force || nextVersion !== version) {
                const next = doc.querySelector('.pregame-layout'), current = document.querySelector('.pregame-layout');
                const oldSelected=current.querySelector('.prepare-card--selected')?.closest('.prepare-card-link');
                if (address.searchParams.has('card') && oldSelected && !next.querySelector('.prepare-card--selected')) {
                    const zone=oldSelected.closest('[data-view-group]').dataset.viewGroup;
                    const replacement=Array.from(next.querySelectorAll('.prepare-card-link')).find(n=>n.dataset.ukid===oldSelected.dataset.ukid && n.closest('[data-view-group]').dataset.viewGroup===zone);
                    if (replacement) {
                        const target=new URL(location.href);target.searchParams.set('card',new URL(replacement.getAttribute('href'),location.href).searchParams.get('card'));
                        history.replaceState(null,'',target);selection++;forceNext=true;return;
                    }
                }
                const scrollY=window.scrollY, mainScroll=current.querySelector('.pregame-main').scrollTop, panelScroll=current.querySelector('.pregame-panel-content').scrollTop;
                const toggle = document.querySelector('[data-chat-toggle]'); if (toggle) document.querySelector('[data-game-chat]').append(toggle);
                if(secondary)dock.remove();
                current.replaceWith(next); version = nextVersion;
                const anchor = next.querySelector('[data-chat-anchor]'); if (anchor && toggle) anchor.append(toggle);
                window.berserkInitViewPager();
                next.querySelector('.pregame-main').scrollTop=mainScroll;next.querySelector('.pregame-panel-content').scrollTop=panelScroll;window.scrollTo(0,scrollY);
            }
            const state = doc.querySelector('#match-pause-state'); pause = state ? JSON.parse(state.textContent) : null;
            renderPause();
        } finally { clearTimeout(refreshTimeout); refreshing = false; if (forceNext || epoch !== selection) { forceNext=false; setTimeout(() => refresh(true).catch(error=>notice(error.message)),0); } }
    }
    const root=document.querySelector('.screen');
    root.dataset.cardPopupRoot='';root.dataset.panelDockRoot='';root.dataset.pauseContext='preparation';
    const dock=document.createElement('div');dock.className='battle-secondary-view game-chat';dock.hidden=true;
    let secondary=null;
    root.secondaryPanel=function(panel){secondary=window.BerserkPanelDock.open(root,dock,secondary,panel);window.BerserkPanelDock.mount(root.querySelector('.info-panel-content'),dock,secondary);root.querySelectorAll('[data-view-card-actions]').forEach(node=>node.hidden=!!secondary);};
    const pauseUi=window.BerserkMatchPause.create(root,version,params=>{const type=params.get('cmd');params.delete('cmd');command(type,Object.fromEntries(params));},()=>busy||!!pending,()=>refresh(true).catch(error=>notice(error.message)));
    function renderPause() {
        document.querySelector('.pregame-layout').dataset.viewVersion=String(version);
        if(pause)pauseUi.update(pause,version);
        pauseUi.render();
        window.BerserkPanelDock.mount(root.querySelector('.info-panel-content'),dock,secondary);root.querySelectorAll('[data-view-card-actions]').forEach(node=>node.hidden=!!secondary);
    }
    async function settle() {
        if (!pending || busy) return;
        busy=true; renderPause(); notice('Проверяем результат…');
        try {
            let data = await request(url('battle_command_status') + '&command_id=' + encodeURIComponent(pending.command_id));
            if (!data.command_status || data.command_status === 'unknown') data = await request(url('battle_command'), {method:'POST',body:new URLSearchParams(pending)});
            if (['committed','conflict','rejected','failed'].includes(data.command_status)) {
                const result=data.command_status; pending=null;persist(); await refresh(true);
                notice(result==='committed' ? '' : result==='conflict' ? 'Состояние изменилось. Данные обновлены; повторите действие.' : 'Действие не выполнено. Данные обновлены.');
            } else notice('Результат ещё уточняется. Повторного переноса не будет.');
        } catch (error) { notice('Связь прервана. Результат будет проверен автоматически.'); }
        finally { busy=false; renderPause(); }
    }
    function command(type, payload) {
        if (busy || pending) return;
        if (pause && ['requested','active'].includes(pause.mode) && ['confirm_view','view_to_sideboard','view_to_deck'].includes(type)) {notice('Игра на паузе. Дождитесь возобновления.');return;}
        const id = crypto.randomUUID();
        pending={...payload,cmd:type,command_id:id,expected_version:String(version),csrf:config.csrf};
        try {persist();} catch (_) {pending=null;notice('Не удалось сохранить команду для безопасного восстановления.');return;}
        settle();
    }
    document.addEventListener('click', async event => {
        const card = event.target.closest('.view-deck-page .prepare-card-link');
        const close = event.target.closest('[data-view-close]');
        const action = event.target.closest('[data-view-card-actions] a,.pregame-view-confirm a');
        if (card || close) {
            event.preventDefault(); const address=new URL(card ? card.href : location.href);
            address.searchParams.delete('cmd');if(close)address.searchParams.delete('card');
            history.replaceState(null,'',address); selection++;
            document.querySelector('.pregame-panel-content').textContent='Загрузка карты…';
            try { await refresh(true); } catch (error) { notice(error.message); } return;
        }
        if (action) { event.preventDefault();const params=new URL(action.href).searchParams;command(params.get('cmd'),{card_id:params.get('card_id')||''}); }
    });
    async function poll() { try { if (pending) await settle(); else if (!busy) await refresh(false); } catch (_) { notice('Нет связи. Повторяем подключение…'); } finally { setTimeout(poll,2000); } }
    const state=document.querySelector('#match-pause-state');pause=state?JSON.parse(state.textContent):null; renderPause(); poll();
})();
