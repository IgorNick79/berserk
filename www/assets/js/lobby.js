(() => {
    'use strict';
    const root = document.getElementById('lobby');
    if (!root) return;
    const profile=document.querySelector('.profile-menu');
    document.addEventListener('click',event=>{if(profile && !profile.contains(event.target)) profile.open=false;});
    document.addEventListener('keydown',event=>{if(profile?.open && event.key==='Escape'){profile.open=false;profile.querySelector('summary').focus();}});
    let state = JSON.parse(root.dataset.state || 'null');
    if (!state || root.dataset.wizard === '1') return;
    const content = document.getElementById('content'), notice = document.getElementById('notice');
    const roomId = new URL(location.href).searchParams.get('room');
    let busy = false, polling = false, epoch = 0, delay = 1500, timer;
    const versions = new Map();
    const node = (tag, text) => { const el = document.createElement(tag); if (text !== undefined) el.textContent = text; return el; };
    const url = () => 'lobby.php?api=1' + (roomId ? '&room=' + encodeURIComponent(roomId) : '');
    function adopt(next) {
        if (next.room && next.room.state === 'started') { location.assign('index.php?game=' + next.room.game_id); return; }
        const rows = next.room ? [next.room] : next.rooms;
        if (rows.some(r => r.version < (versions.get(r.id) || 0))) return;
        rows.forEach(r => versions.set(r.id, r.version));
        state = next; render();
    }
    function button(text, action) { const b = node('button', text); b.type = 'button'; b.disabled = busy; b.addEventListener('click', action); return b; }
    function render() {
        content.replaceChildren();
        if (state.rooms) {
            content.append(node('h1', 'Игры'), button('Создать игру', () => location.assign(root.dataset.authenticated === '1' ? 'lobby.php?new=1' : 'auth.php?page=login')));
            const table = node('table'), head = node('thead'), header = node('tr');
            ['Игра', 'Вид игры', 'Создана', 'Создатель', 'Второй игрок', 'Статус'].forEach(t => header.append(node('th', t)));
            head.append(header); table.append(head); const body = node('tbody');
            state.rooms.forEach(r => {
                const row = node('tr'); row.dataset.roomId = r.id;
                ['#' + r.id, r.kind, new Date(r.created_at).toLocaleString('ru-RU'), r.creator, r.second || '—', r.status].forEach(t => row.append(node('td', t)));
                if (r.can_open) {
                    row.tabIndex = 0; row.setAttribute('aria-label', 'Открыть комнату ' + r.id);
                    row.addEventListener('click', () => open(r));
                    row.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(r); } });
                } else row.setAttribute('aria-disabled', 'true');
                body.append(row);
            });
            table.append(body); content.append(table);
            if (!state.rooms.length) content.append(node('p', 'Открытых и активных игр пока нет.'));
        } else {
            const r = state.room; content.append(node('h1', 'Комната #' + r.id), node('p', r.kind + ' · ' + new Date(r.created_at).toLocaleString('ru-RU')));
            if (r.state === 'started') {
                content.append(node('p', 'Игра создана. Игровой процесс будет подключён на следующем этапе.'), node('p', 'Номер игры: ' + r.game_id));
            } else if (r.role === 'candidate') content.append(node('p', 'Ожидание подтверждения создателя'));
            else if (r.state === 'open') content.append(node('p', 'Ожидание второго игрока'));
            else {
                content.append(node('p', 'Игрок ' + r.second + ' хочет присоединиться'));
                const decisions = node('div'); decisions.className = 'decisions';
                decisions.append(button('Подтвердить', () => mutate('accept', r)), button('Отказать', () => mutate('reject', r))); content.append(decisions);
            }
            const back = node('a', 'В лобби'); back.href = 'lobby.php'; content.append(back);
        }
    }
    function open(room) { if (root.dataset.authenticated !== '1') location.assign('auth.php?page=login'); else mutate('open', room); }
    async function mutate(action, room) {
        if (busy) return;
        busy = true; ++epoch; clearTimeout(timer); render(); notice.textContent = '';
        const params = new URLSearchParams({action, csrf: root.dataset.csrf});
        const controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 10000);
        if (room) { params.set('version', room.version); if (room.candidate_request_id) params.set('candidate_request_id', room.candidate_request_id); }
        try {
            const response = await fetch('lobby.php?api=1' + (room ? '&room=' + room.id : ''), {method:'POST', body:params, credentials:'same-origin', cache:'no-store', signal:controller.signal});
            const data = await response.json();
            if (response.status === 401) { location.assign('auth.php?page=login'); return; }
            if (!response.ok) throw new Error(data.error || 'Действие не выполнено. Обновляю состояние.');
            // No legacy game URL is ever used, including after accept.
            if (action === 'create' || action === 'open') { location.assign(data.url); return; }
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
            if (roomId && (response.status === 403 || response.status === 404)) { location.assign('lobby.php'); return; }
            if (!response.ok) throw new Error(data.error || 'Не удалось обновить состояние.');
            adopt(data); delay = 1500; notice.textContent = '';
        } catch (error) { if (generation === epoch) { notice.textContent = 'Связь прервана. Повторное обновление…'; delay = Math.min(delay * 2, 15000); } }
        finally { clearTimeout(timeout); polling = false; schedule(); }
    }
    adopt(state); schedule();
})();
