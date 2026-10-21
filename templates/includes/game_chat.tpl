<div class="game-chat" data-game-chat data-game-id="{{chat_game_id}}" data-sender-id="{{chat_sender_id}}" data-csrf="{{chat_csrf}}">
    <button type="button" class="battle-icon-control game-chat-toggle" data-chat-toggle title="Сообщения" aria-label="Сообщения" aria-expanded="false" aria-controls="game-chat-panel">
        <img data-chat-icon hidden alt="" src="/assets/images/battle/chat_160.png"><span data-chat-label>Сообщения</span><span class="battle-icon-tooltip" aria-hidden="true">Сообщения</span>
        <img class="game-chat-unread" data-chat-indicator hidden alt="Новые сообщения" src="/assets/images/battle/chat_unread.png">
        <span class="game-chat-unread-text" data-chat-unread-text hidden>Новые</span>
    </button>
    <section id="game-chat-panel" class="game-chat-panel" aria-label="Чат боя" hidden>
        <div class="game-chat-header"><span>Чат боя</span><button type="button" class="battle-window-close game-chat-close" data-chat-close aria-label="Закрыть чат" title="Закрыть чат">×</button></div>
        <button type="button" class="button skip" data-chat-older hidden>Ранее</button>
        <div class="game-chat-history" data-chat-history role="log" aria-label="Сообщения" tabindex="0" aria-live="polite"></div>
        <form data-chat-form>
            <div class="game-chat-compose">
                <textarea data-chat-input rows="2" maxlength="2000" placeholder="Сообщение" aria-label="Сообщение" title="Enter — отправить; Ctrl+Enter — новая строка"></textarea>
                <button type="submit" class="game-chat-send" data-chat-send aria-label="Отправить сообщение" title="Отправить сообщение (Enter)"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg></button>
            </div>
            <p data-chat-status role="status"></p>
        </form>
    </section>
</div>
