<div class="screen-draft">
    <p class="flash">{{message}}</p>

    <div class="draft-info">
        <div class="draft-info__turn">{{turn_label}}</div>
        <div class="draft-info__counts">
            <span>host: {{host_picked}}</span>
            <span>player: {{player_picked}}</span>
            <span>Пул: {{pool_left}}</span>
        </div>
    </div>
    {{timer_html}}

    <div class="draft-main">
        <div class="draft-grid">
            {{grid_html}}
        </div>
        <div class="draft-preview-slot">
            {{preview_html}}
        </div>
    </div>

    <div class="prepare-bottom-bar">
        <div class="prepare-bottom-summary prepare-bottom-summary--stack">
            <div class="prepare-bottom-row">
                <span>Выбрано: <b>{{my_total}}</b></span>
                <span>Золотых: <b>{{my_gold}}</b></span>
                <span>Серебряных: <b>{{my_silver}}</b></span>
                <span>Средняя цена: <b>{{my_avg_price}}</b></span>
            </div>
            <div class="prepare-elements">
                {{my_elements_html}}
            </div>
        </div>
        <div class="prepare-bottom-actions">
            {{actions_html}}
        </div>
    </div>
    {{history_html}}
    {{draft_script_html}}
</div>
