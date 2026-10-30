/* Room presentation only. Polling, commands, CAS and redirects belong to lobby.js. */
(() => {
    'use strict';
    const make = (tag, className, text) => {
        const el = document.createElement(tag);
        if (className) el.className = className;
        if (text !== undefined) el.textContent = text;
        return el;
    };
    function initialise(content) {
        const shell = make('div', 'room-shell'); shell.dataset.roomScreen = '';
        shell.dataset.panelTheme = window.BerserkPreferences?.current?.theme || 'grimoire';
        const art = make('figure', 'room-illustration');
        const image = make('img'); image.src = 'assets/images/battle/waiting-war-table.png';
        image.alt = 'Военный стол с картой, свечой и двумя свободными местами';
        image.width = 1536; image.height = 1024; art.append(image);
        const panel = make('aside', 'info-panel room-info'); panel.setAttribute('aria-labelledby', 'room-heading');
        const header = make('header', 'info-panel-header room-header');
        const title = make('h1'); title.id = 'room-heading';
        const kind = make('p', 'room-kind'); kind.dataset.roomField = 'kind';
        header.append(title, kind);
        const body = make('div', 'room-info-scroll');
        const status = make('p', 'room-state'); status.dataset.roomField = 'status';
        const participants = make('section', 'room-participants'); participants.setAttribute('aria-label', 'Участники');
        for (const [key, label] of [['creator', 'Создатель'], ['second', 'Второй игрок']]) {
            const seat = make('div', 'room-seat');
            const name = make('strong'); name.dataset.roomField = key;
            const state = make('p', 'room-seat-status'); state.dataset.roomField = key + 'Status';
            seat.append(make('span', 'room-seat-label', label), name, state); participants.append(seat);
        }
        const settings = make('section', 'room-settings'); settings.append(make('h2', '', 'Параметры партии'));
        const list = make('dl');
        for (const [key, label] of [['mode', 'Режим'], ['access', 'Доступ'], ['timer', 'Таймер хода']]) {
            const row = make('div'); const value = make('dd'); value.dataset.roomField = key;
            row.append(make('dt', '', label), value); list.append(row);
        }
        settings.append(list); body.append(status, participants, settings);
        const footer = make('footer', 'info-panel-footer room-footer');
        const hint = make('p', 'room-action-status'); hint.dataset.roomField = 'hint';
        const actions = make('div', 'info-actions room-controls room-controls--battle'); actions.dataset.roomActions = '';
        const bar = make('div', 'room-footer-bar');
        const chat = make('span', 'battle-chat-anchor'); chat.dataset.chatAnchor = '';
        const middle = make('div', 'room-panel-content');
        const secondary = make('section', 'battle-secondary-view game-chat'); secondary.hidden = true;
        middle.append(body, secondary);
        content.closest('#lobby').secondaryPanel = view => {
            if (view) { secondary.append(view); secondary.hidden = false; body.hidden = true; }
            else { secondary.hidden = true; body.hidden = false; }
            panel.classList.toggle('has-secondary', !!view);
            if (view && matchMedia('(max-width:760px)').matches) panel.scrollIntoView({block: 'nearest'});
        };
        window.addEventListener('resize', () => {
            if (panel.classList.contains('has-secondary') && matchMedia('(max-width:760px)').matches)
                requestAnimationFrame(() => panel.scrollIntoView({block: 'nearest'}));
        });
        bar.append(actions, chat); footer.append(hint, bar); panel.append(header, middle, footer); shell.append(art, panel);
        content.replaceChildren(shell); return shell;
    }
    function render(content, r, commands) {
        const shell = content.querySelector('[data-room-screen]') || initialise(content);
        const closed = r.access === 'closed';
        const awaiting = r.state === 'awaiting_confirmation';
        const started = r.state === 'started';
        const creator = r.role === 'creator';
        const values = {
            kind: r.kind,
            mode: r.kind,
            access: closed ? 'Закрытая партия' : 'Открытая партия',
            timer: r.turn_seconds === 0 ? 'Без ограничения' : r.turn_seconds === 180 ? '180 секунд на каждый ход' : 'Не указан',
            creator: r.creator,
            second: r.second || (closed ? 'Ожидается приглашённый игрок' : 'Место свободно'),
            creatorStatus: started ? 'Участник партии' : awaiting ? 'Подтверждает заявку' : 'Ожидает соперника',
            secondStatus: started ? 'Участник партии' : awaiting ? 'Заявка подана' : 'Заявка ещё не подана',
            status: started ? 'Партия создана' : '',
            hint: started ? 'Можно перейти к подготовке партии.' : creator && awaiting ? 'Примите заявку или откажите игроку.' : creator ? (closed ? 'Приглашённый игрок сможет подать заявку.' : 'Комната доступна для заявки второго игрока.') : awaiting ? 'Ожидаем подтверждения создателя.' : 'Отправьте заявку на участие в партии.'
        };
        shell.querySelector('#room-heading').textContent = 'Комната #' + r.id;
        shell.querySelectorAll('[data-room-field]').forEach(el => {
            const value = values[el.dataset.roomField]; if (el.textContent !== value) el.textContent = value;
            if (el.dataset.roomField === 'status') el.hidden = !value;
        });
        const actions = shell.querySelector('[data-room-actions]');
        const signature = [r.id, r.version, r.state, r.role, r.can_decide, commands.busy].join(':');
        shell.setAttribute('aria-busy', String(commands.busy));
        if (actions.dataset.signature === signature) return;
        const focused = actions.contains(document.activeElement) ? document.activeElement.dataset.roomAction : null;
        actions.replaceChildren(); actions.dataset.signature = signature;
        function action(label, key, callback) {
            const button = make('button', 'button wide room-action room-action--' + key + (key === 'reject' || key === 'withdraw' ? ' red' : ''), label);
            button.type = 'button'; button.dataset.roomAction = key; button.disabled = commands.busy;
            button.addEventListener('click', callback); actions.append(button);
        }
        if (started && r.game_id) {
            const link = make('a', 'button wide room-action', 'Перейти к игре');
            link.href = 'index.php?game=' + encodeURIComponent(r.game_id); actions.append(link);
        } else if (r.can_decide) {
            action('Принять', 'accept', () => commands.mutate('accept', r));
            action('Отказать', 'reject', () => commands.mutate('reject', r));
        } else if (r.role === 'candidate' && r.state === 'awaiting_confirmation') {
            action('Отмена', 'withdraw', () => commands.mutate('withdraw', r));
        } else if (r.role === 'invited' && r.state === 'open') {
            action('Присоединиться', 'open', () => commands.open(r));
        }
        actions.closest('.room-footer').classList.toggle('has-room-actions', actions.childElementCount > 0);
        if (focused) actions.querySelector('[data-room-action="' + focused + '"]:not(:disabled)')?.focus({preventScroll: true});
    }
    window.BerserkRoom = {render};
})();
