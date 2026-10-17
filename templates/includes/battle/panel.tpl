<div class="panel-card-heading" data-panel-card-id="{{card_id}}">
    <span class="panel-card-cost" aria-label="Стоимость: {{price}}">
        <img src="{{cost_icon_url}}" alt="{{cost_icon_label}}" width="32" height="32">
        <span>{{price}}</span>
    </span>
    <h3 class="panel-card-title">{{name}}</h3>
    <div class="panel-card-heading-icons">{{stat_icons_html}}</div>
</div>
<div class="panel-card-media">{{image_html}}</div>
<div class="panel-card-stats" aria-label="Характеристики карты">
    <div class="panel-stats-main">{{stat_values_html}}</div>
    <div class="panel-stats-extra">{{class_html}}{{info_icon_html}}</div>
</div>
<div class="panel-card-details">
    <div class="battle-mode-buttons">{{mode_buttons}}</div>
    {{status_html}}
</div>
