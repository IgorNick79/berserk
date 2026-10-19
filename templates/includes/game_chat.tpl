<div class="game-chat" data-game-chat data-game-id="{{chat_game_id}}" data-sender-id="{{chat_sender_id}}" data-role="{{chat_role}}" data-csrf="{{chat_csrf}}">
    <button type="button" class="battle-icon-control game-chat-toggle" data-chat-toggle title="Сообщения" aria-label="Сообщения" aria-expanded="false" aria-controls="game-chat-panel">
        <img data-chat-icon hidden alt="" src="/assets/images/battle/chat_160.png"><span data-chat-label>Сообщения</span><span class="battle-icon-tooltip" aria-hidden="true">Сообщения</span>
        <img class="game-chat-unread" data-chat-indicator hidden alt="Новые сообщения" src="/assets/images/battle/chat_unread.png">
        <span class="game-chat-unread-text" data-chat-unread-text hidden>Новые</span>
    </button>
    <section id="game-chat-panel" class="game-chat-panel" aria-label="Чат боя" hidden>
        <button type="button" class="button skip" data-chat-older hidden>Ранее</button>
        <div class="game-chat-history" data-chat-history role="log" aria-label="Сообщения" tabindex="0" aria-live="polite"></div>
        <form data-chat-form>
            <textarea data-chat-input rows="2" maxlength="2000" placeholder="Сообщение" aria-label="Сообщение"></textarea>
            <p data-chat-status role="status"></p>
            <div class="game-chat-actions"><button type="submit" class="button" data-chat-send>Отправить</button><button type="button" class="button skip" data-chat-cancel>Отмена</button></div>
        </form>
    </section>
</div>
