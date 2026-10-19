<div class="battle-sync-root battle-shell" data-battle-sync-root {{pause_root_attr}}>
    <div class="battle-ajax-message" data-battle-message hidden></div>
    <button class="battle-menu" type="button" {{debug_disabled}} aria-expanded="false" aria-controls="battle-debug" aria-label="Отладка боя" title="Отладка боя">
        <span aria-hidden="true">☰</span>
    </button>
    <div class="battle-main">
        <div class="battle-left" {{pause_field_attr}} tabindex="0" aria-label="Игровое поле и игровые зоны">
            <div class="battle-layout">
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
<script src="/assets/js/battle-ui.js?v=8" defer></script>
<script src="/assets/js/card-image-popup.js?v=1" defer></script>
