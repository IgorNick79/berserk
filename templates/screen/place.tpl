<link rel="stylesheet" href="/assets/css/battle-fonts.css?v=1">
<link rel="stylesheet" href="/assets/css/battle-ui-skins.css?v=16">
<link rel="stylesheet" href="/assets/css/battle-mini-card.css?v=16">
<link rel="stylesheet" href="/assets/css/battle-mini-nameplate.css?v=2">
<link rel="stylesheet" href="/assets/css/view-deck.css?v=7">
<link rel="stylesheet" href="/assets/css/place.css?v=2">
<div class="screen-prepare-place battle-left" data-place-screen data-place-view="{{place_view}}" data-place-waiting="{{place_waiting}}">
<p class="flash">{{message}}</p>
<div class="battle-layout" style="--playmat-own:url('{{own_playmat}}');--playmat-opponent:url('{{own_playmat}}')">
<div class="battle-playmat-half opponent" aria-hidden="true"></div><div class="battle-playmat-half own" aria-hidden="true"></div>
<div class="battle-field" role="group" aria-label="Поле расстановки">{{field_html}}</div></div>
<!-- Match Battle's trailing pile-reveal slot so the shared flex spacing stays identical. -->
<div data-battle-drag-state data-drag-enabled="{{place_editable}}" data-drag-selected="{{place_selected}}" aria-hidden="true"></div>
</div>
{{match_pause_mask_html}}
<script src="/assets/js/panel-secondary-view.js?v=1" defer></script>
<script src="/assets/js/battle-match-pause.js?v=2" defer></script>
<script src="/assets/js/battle-drag.js?v=2" defer></script>
<script src="/assets/js/pregame-place.js?v=4" defer></script>
<script src="/assets/js/view-deck.js?v=10" defer></script>
<script src="/assets/js/card-image-popup.js?v=2" defer></script>
<script src="/assets/js/battle-mini-card.js?v=12" defer></script>
