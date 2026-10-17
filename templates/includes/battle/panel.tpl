<h3 class="panel-card-title">{{name}}</h3>
{{image_html}}
<div class="panel-card-stats" aria-label="Характеристики карты">
    {{stat_icons_html}}
    <span><b>HP</b> {{hp}}/{{hp_max}}</span>
    <span><b>Move</b> {{move}}/{{move_max}}</span>
    <span><b>Strike</b> {{strike}}</span>
</div>
<div class="panel-card-details">
    {{description_html}}
    <div class="battle-mode-buttons">{{mode_buttons}}</div>
    <p>{{coins_line}}</p>
    {{status_html}}
</div>
