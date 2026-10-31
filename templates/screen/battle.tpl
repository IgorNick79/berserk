<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="/assets/css/battle-fonts.css?v=1">
<link rel="stylesheet" href="/assets/css/battle-ui-skins.css?v=14">
<link rel="stylesheet" href="/assets/css/battle-mini-nameplate.css?v=2">
<link rel="stylesheet" href="/assets/css/battle-mini-card.css?v=16">
<div class="battle-sync-root battle-shell" data-battle-sync-root data-panel-theme="grimoire" data-mini-card-design="{{mini_card_design}}" {{pause_root_attr}}>
    <div class="battle-ajax-message" data-battle-message hidden></div>
    <div class="battle-main">
        <div class="battle-left" {{pause_field_attr}} tabindex="0" aria-label="Игровое поле и игровые зоны">
            <div class="battle-layout" style="--playmat-own: url('{{own_playmat}}'); --playmat-opponent: url('{{opponent_playmat}}')">
                <div class="battle-playmat-half opponent" aria-hidden="true"></div><div class="battle-playmat-half own" aria-hidden="true"></div>
                <div class="fly-zone-side" data-battle-fragment="fly_zones_html">{{fly_zones_html}}</div>
                <div class="battle-field" data-battle-fragment="field_html">{{field_html}}</div>
                <div class="grave-zone-side" data-battle-fragment="piles_html">{{piles_html}}</div>
            </div>
            <div data-battle-fragment="pile_reveal_html">{{pile_reveal_html}}</div>
        </div>
        <div class="battle-pause-mask" data-match-pause-mask {{pause_mask_hidden}}><div><span class="battle-pause-symbol" aria-hidden="true"><img src="/assets/images/battle/pause_160.png" width="70" height="70" alt=""></span><h2>Матч приостановлен</h2><p>Управление паузой — в панели матча.</p></div></div>
    </div>
    <aside class="info-panel" data-battle-fragment="info_panel_html" aria-label="Сражение и действия">{{info_panel_html}}</aside>
</div>
<script src="/assets/js/cell-marker-picker.js?v=1" defer></script>
<script src="/assets/js/creature-choice.js?v=2" defer></script>
<script src="/assets/js/panel-secondary-view.js?v=1" defer></script>
<script src="/assets/js/battle-ui.js?v=18" defer></script>
<script src="/assets/js/card-image-popup.js?v=2" defer></script>

<script src="/assets/js/battle-mini-card.js?v=12" defer></script>
<script src="/assets/js/battle-drag.js?v=1" defer></script>

<script src="/assets/js/battle-notices.js?v=1" defer></script>
