<div class="panel-card-heading">
    <span class="panel-card-cost" aria-label="Стоимость: {{price}}">
        <img src="{{cost_icon_url}}" alt="{{cost_icon_label}}" width="32" height="32">
        <span>{{price}}</span>
    </span>
    <h3 class="panel-card-title">{{name}}</h3>
    {{class_html}}
</div>
{{image_html}}
<div class="panel-card-stats" aria-label="Характеристики карты">
    <span class="panel-stat-item" tabindex="0" role="img">
        <img class="panel-stat-icon" src="/assets/images/card/aef97d1e-fb9d-45b6-87ff-56bbe75b296e.png" alt="" width="24" height="24" >
        <span>{{hp}}/{{hp_max}}</span>
    </span>

    <span><b>HP</b> {{hp}}/{{hp_max}}</span>
    <span><b>Move</b> {{move}}/{{move_max}}</span>
    <span><b>Strike</b> {{strike}}</span>
    {{stat_icons_html}}
</div>
<div class="panel-card-details">
    {{description_html}}
    <div class="battle-mode-buttons">{{mode_buttons}}</div>
    <p>{{coins_line}}</p>
    {{status_html}}
</div>
