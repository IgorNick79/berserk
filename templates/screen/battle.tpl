<link rel="stylesheet" href="/assets/css/battle-mini-card.css?v=3">
<div class="battle-sync-root battle-shell" data-battle-sync-root data-mini-card-design="{{mini_card_design}}" {{pause_root_attr}}>
    <div class="battle-ajax-message" data-battle-message hidden></div>
    <button class="battle-menu" type="button" disabled aria-expanded="false" aria-haspopup="menu" aria-controls="battle-menu-panel" aria-label="Меню матча" title="Меню матча">
        <span class="battle-menu-icon" aria-hidden="true"><i></i><i></i><i></i></span>
    </button>
    <div id="battle-menu-panel" class="battle-menu-panel" role="menu" aria-label="Меню матча" tabindex="-1" hidden>
        <button type="button" role="menuitemcheckbox" data-menu-sound aria-checked="{{sound_checked}}"><span>Звук</span><span data-sound-check aria-hidden="true">✓</span></button>
        <button type="button" role="menuitem" data-menu-playmat><span>Коврик</span></button>
        <button type="button" role="menuitem" disabled aria-disabled="true"><span>Отложить игру</span><small>Скоро</small></button>
        {{debug_menu_html}}
        <span class="battle-menu-saving" data-menu-saving role="status" hidden>Сохраняем…</span>
    </div>
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
        <div class="battle-pause-mask" data-match-pause-mask {{pause_mask_hidden}}><div><span class="battle-pause-symbol" aria-hidden="true">Ⅱ</span><h2>Матч приостановлен</h2><p>Управление паузой — в панели матча.</p></div></div>
    </div>
    <aside class="info-panel" data-battle-fragment="info_panel_html" aria-label="Информация о бое и действия">{{info_panel_html}}</aside>
</div>
<script src="/assets/js/cell-marker-picker.js?v=1" defer></script>
<script src="/assets/js/battle-ui.js?v=10" defer></script>
<script src="/assets/js/card-image-popup.js?v=1" defer></script>

<script src="/assets/js/battle-mini-card.js?v=3" defer></script>
