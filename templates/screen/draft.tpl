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

    <div class="draft-main">
        <div class="draft-grid">
            {{grid_html}}
        </div>
        <div class="draft-panel-slot">
            {{panel_html}}
        </div>
    </div>

    <div class="draft-actions-bar">
    <div class="draft-stats">
        <div class="draft-stats-row">
            <span>Выбрано: <b>{{my_total}}</b></span>
            <span>Золотых: <b>{{my_gold}}</b></span>
            <span>Серебряных: <b>{{my_silver}}</b></span>
            <span>Средняя цена: <b>{{my_avg_price}}</b></span>
        </div>
        <div class="draft-elements">
            {{my_elements_html}}
        </div>
    </div>
    <div class="draft-actions">
        {{actions_html}}
    </div>
</div>
</div>