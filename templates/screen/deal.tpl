{{message}}
<div class="screen-prepare screen-prepare-deal">
    <h1>Выбор отряда</h1>

    <div class="resources">
        <span class="gold {{gold_class}}">Золото: <b>{{gold_left}}</b> / {{gold_total}}</span>
        <span class="silver">Серебро: <b>{{silver_left}}</b> / {{silver_total}}</span>
        <span class="penalty {{penalty_class}}">Стихий: {{elements_count}} (штраф −{{penalty}})</span>
    </div>

    <div class="prepare-layout">
        <div class="prepare-content">
            <h2>Раздача</h2>
            <div class="cards">
                {{hand_html}}
            </div>

            <h2>Отряд</h2>
            <div class="cards">
                {{squad_html}}
            </div>
        </div>

        <div class="prepare-preview-slot">
            {{preview_html}}
        </div>
    </div>

    <div class="prepare-bottom-bar">
        <div class="prepare-bottom-summary"></div>
        <div class="prepare-bottom-actions">
            {{confirm_html}}
            {{reshuffle_html}}
            {{card_actions_html}}
        </div>
    </div>
</div>
