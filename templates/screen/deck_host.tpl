{{message}}
<div class="screen-settings screen-deck-select">
    <h1>Выбор колод</h1>
    <p class="settings-mode-title">Системные колоды</p>
    {{empty_decks_html}}
    <form method="get" class="settings-form deck-select-form">
        <input type="hidden" name="{{role_param}}" value="1">
        <input type="hidden" name="game" value="{{game_id}}">
        <input type="hidden" name="cmd" value="select_deck">

        <section class="settings-section deck-select-section deck-select-section--host">
            <h2>Моя колода</h2>
            <fieldset class="settings-fieldset">
                <legend>Способ выбора</legend>
                <div class="settings-option-grid">
                    <label class="settings-option">
                        <input type="radio" name="deck_mode" value="manual" {{host_manual_checked}}>
                        <span><b>Выбрать вручную</b><small>Выберите одну системную колоду.</small></span>
                    </label>
                    <label class="settings-option">
                        <input type="radio" name="deck_mode" value="random" {{host_random_checked}}>
                        <span><b>Случайная колода</b><small>Будет зафиксирована при подтверждении.</small></span>
                    </label>
                </div>
            </fieldset>
            <fieldset class="settings-fieldset deck-select-manual">
                <legend>Доступные колоды</legend>
                <div class="settings-option-grid deck-select-grid">
                    {{host_decks_html}}
                </div>
            </fieldset>
        </section>

        <section class="settings-section deck-select-section deck-select-section--player">
            <h2>Колода соперника</h2>
            <fieldset class="settings-fieldset">
                <legend>Способ выбора</legend>
                <div class="settings-option-grid">
                    <label class="settings-option">
                        <input type="radio" name="other_deck_mode" value="manual" {{player_manual_checked}}>
                        <span><b>Выбрать вручную</b><small>Можно выбрать ту же колоду.</small></span>
                    </label>
                    <label class="settings-option">
                        <input type="radio" name="other_deck_mode" value="random" {{player_random_checked}}>
                        <span><b>Случайная колода</b><small>Выбирается независимо от моей колоды.</small></span>
                    </label>
                </div>
            </fieldset>
            <fieldset class="settings-fieldset deck-select-manual">
                <legend>Доступные колоды</legend>
                <div class="settings-option-grid deck-select-grid">
                    {{player_decks_html}}
                </div>
            </fieldset>
        </section>

        <div class="settings-submit-row">
            <button class="button wide settings-submit" type="submit">Перейти к просмотру</button>
        </div>
    </form>
</div>
