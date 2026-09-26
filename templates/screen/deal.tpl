{{message}}
<h1>Выбор отряда</h1>

<div class="resources">
    <span class="gold {{gold_class}}">Золото: <b>{{gold_left}}</b> / {{gold_total}}</span>
    <span class="silver">Серебро: <b>{{silver_left}}</b> / {{silver_total}}</span>
    <span class="penalty {{penalty_class}}">Стихий: {{elements_count}} (штраф −{{penalty}})</span>
</div>

<h2>Раздача</h2>
<div class="cards">
    {{hand_html}}
</div>

<h2>Отряд</h2>
<div class="cards">
    {{squad_html}}
</div>

<div class="actions">
    {{reshuffle_html}}
</div>

{{panel_html}}

<div class="confirm-block">
    {{confirm_html}}
</div>
