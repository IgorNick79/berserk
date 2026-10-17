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
    {{stat_values_html}}
    {{stat_icons_html}}
</div>
<div class="panel-card-details">
    <div class="battle-mode-buttons">{{mode_buttons}}</div>
    {{status_html}}
</div>
