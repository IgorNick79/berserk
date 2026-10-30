(() => {
    'use strict';
    const root = document.getElementById('lobby');
    if (!root) return;
    document.addEventListener('contextmenu', event => {
        if (event.target.closest('input, textarea, select, [contenteditable]:not([contenteditable="false"]), .debug-panel, [data-allow-copy]')) return;
        event.preventDefault();
    });
    let state = JSON.parse(root.dataset.state || 'null');
    if (!state || root.dataset.wizard === '1') return;
    const content = document.getElementById('content'), notice = document.getElementById('notice');
    const roomId = new URL(location.href).searchParams.get('room');
    let filter='all'; const expanded=new Set();
    let busy = false, polling = false, epoch = 0, delay = 1500, timer, withdrawing = false, ownRejection = null;
    const versions = new Map();
    const node = (tag, text) => { const el = document.createElement(tag); if (text !== undefined) el.textContent = text; return el; };
    const url = () => 'lobby.php?api=1' + (roomId ? '&room=' + encodeURIComponent(roomId) : '');
    function signal(key, navigate = false) {
        if (window.BerserkRoomSound) window.BerserkRoomSound.notify(key, navigate);
        else document.addEventListener('DOMContentLoaded', () => window.BerserkRoomSound?.notify(key, navigate), {once: true});
    }
    function adopt(next) {
        const rows = next.room ? [next.room] : next.rooms;
        if (rows.some(r => r.version < (versions.get(r.id) || 0))) return;
        if (next.room) {
            const r = next.room, previous = versions.has(r.id) ? state.room : null;
            if (withdrawing && r.state === 'open') { location.assign('lobby.php'); return; }
            withdrawing = false;
            if (previous?.can_decide && r.state === 'open' && ownRejection !== previous.candidate_request_id)
                signal('withdrawal:' + r.id + ':' + previous.candidate_request_id);
            if (r.can_decide && (!previous || r.candidate_request_id !== previous.candidate_request_id))
                signal('request:' + r.id + ':' + r.candidate_request_id);
            if (previous?.role === 'candidate' && previous.state === 'awaiting_confirmation' && r.state !== previous.state)
                signal('decision:' + r.id + ':' + previous.version, r.state === 'started');
            if (r.state === 'started') { location.assign('index.php?game=' + r.game_id); return; }
        }
        rows.forEach(r => versions.set(r.id, r.version));
        state = next; render();
    }
    function button(text, action) { const b = node('button', text); b.type = 'button'; b.disabled = busy; b.addEventListener('click', action); return b; }
    function render() {
        const scroll=content.scrollTop;
        const focused=document.activeElement;
        const focusRoom=focused?.closest('[data-room-id]')?.dataset.roomId;
        const focusTag=focused?.tagName;
        if (state.rooms) {
            content.replaceChildren();
            const actions=node('div'); actions.className='lobby-actions';
            actions.append(button('⚔ Создать игру',()=>location.assign(root.dataset.authenticated==='1'?'lobby.php?new=1':'auth.php?page=login')));
            const bot=node('button','♟ Играть с ботом · Скоро');bot.disabled=true;actions.append(bot);
            const tabs=node('div');tabs.className='lobby-tabs';tabs.setAttribute('aria-label','Фильтр партий');
            [['all','Все'],['mine','Мои'],['open','Открытые'],['history','История']].forEach(([key,label])=>{
                const tab=button(label,()=>{filter=key;render();});tab.setAttribute('aria-pressed',String(filter===key));tabs.append(tab);
            });
            const list=node('div');list.className='lobby-list';list.tabIndex=0;list.setAttribute('aria-label','Партии');
            const rooms=state.rooms.filter(r=>filter==='all'||filter==='mine'&&r.is_mine||filter==='open'&&r.state==='open');
            if(filter==='history')list.append(node('p','История партий пока недоступна'));
            else rooms.forEach(r=>{
                const card=node('details');card.className='room-card'+(r.is_mine?' mine':'');card.dataset.roomId=r.id;card.open=expanded.has(r.id);
                card.addEventListener('toggle',()=>{if(card.isConnected){if(card.open)expanded.add(r.id);else expanded.delete(r.id);}});
                const summary=node('summary'),title=node('strong','#'+r.id+' · '+r.kind),status=node('span',r.status),players=node('span',r.creator+'  ×  '+(r.second||'Ожидается соперник'));
                status.className='room-status';players.className='room-players';
                const date=node('time',new Date(r.created_at).toLocaleDateString('ru-RU'));date.title=new Date(r.created_at).toLocaleString('ru-RU');date.dateTime=r.created_at;
                summary.append(title,status,players,date);card.append(summary);
                const panel=node('div');panel.className='room-actions';
                if(r.can_open)panel.append(button(r.is_mine?'Продолжить':'Присоединиться',()=>open(r)));
                else panel.append(node('span','Доступных действий пока нет.'));card.append(panel);list.append(card);
            });
            if(filter!=='history'&&!rooms.length)list.append(node('p','Пока нет доступных партий. Создайте новую игру.'));
            const sticky=node('div');sticky.className='lobby-sticky';sticky.append(actions,tabs);
            content.append(sticky,list);content.scrollTop=scroll;
            if(focusRoom){const card=[...list.children].find(c=>c.dataset.roomId===focusRoom);card?.querySelector(focusTag==='BUTTON'?'button':'summary')?.focus({preventScroll:true});}

        } else {
            window.BerserkRoom.render(content, state.room, {busy, open, mutate});
        }
    }
    function open(room) { if (root.dataset.authenticated !== '1') location.assign('auth.php?page=login'); else mutate('open', room); }
    async function mutate(action, room) {
        if (busy) return;
        busy = true; ++epoch; clearTimeout(timer); render(); notice.textContent = '';
        withdrawing = action === 'withdraw';
        if (action === 'reject') ownRejection = room.candidate_request_id;
        const params = new URLSearchParams({action, csrf: root.dataset.csrf});
        const controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 10000);
        if (room) { params.set('version', room.version); if (room.candidate_request_id) params.set('candidate_request_id', room.candidate_request_id); }
        try {
            const response = await fetch('lobby.php?api=1' + (room ? '&room=' + room.id : ''), {method:'POST', body:params, credentials:'same-origin', cache:'no-store', signal:controller.signal});
            const data = await response.json();
            if (response.status === 401) { location.assign('auth.php?page=login'); return; }
            if (!response.ok) {
                if (action === 'reject' && response.status < 500) ownRejection = null;
                throw new Error(data.error || 'Действие не выполнено. Обновляю состояние.');
            }
            // No legacy game URL is ever used, including after accept.
            if (action === 'create' || action === 'open' || action === 'withdraw') { location.assign(data.url); return; }
            adopt(data); notice.textContent = '';
        } catch (error) {
            // Lost responses are recovered by READ; never automatically repeat the mutation.
            notice.textContent = error.message;
        } finally { clearTimeout(timeout); busy = false; render(); schedule(0); }
    }
    function schedule(ms = delay) { clearTimeout(timer); timer = setTimeout(poll, ms); }
    async function poll() {
        if (polling || busy) { schedule(); return; }
        polling = true; const generation = epoch;
        const controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 10000);
        try {
            const response = await fetch(url(), {credentials:'same-origin', cache:'no-store', signal:controller.signal});
            const data = await response.json();
            if (generation !== epoch) return;
            if (response.status === 401) { location.assign('auth.php?page=login'); return; }
            if (roomId && (response.status === 403 || response.status === 404)) {
                if (!withdrawing && response.status === 403 && state.room?.role === 'candidate') signal('decision:' + roomId + ':' + state.room.version, true);
                location.assign('lobby.php'); return;
            }
            if (!response.ok) throw new Error(data.error || 'Не удалось обновить состояние.');
            adopt(data); delay = 1500; notice.textContent = '';
        } catch (error) { if (generation === epoch) { notice.textContent = 'Связь прервана. Повторное обновление…'; delay = Math.min(delay * 2, 15000); } }
        finally { clearTimeout(timeout); polling = false; schedule(); }
    }
    adopt(state); schedule();
})();
