<div class="battle-sync-root battle-shell" data-battle-sync-root>
    <div class="battle-ajax-message" data-battle-message hidden></div>
    <button class="battle-menu-placeholder" type="button" disabled aria-label="Меню (скоро)" title="Меню (скоро)">
        <span aria-hidden="true">☰</span>
    </button>
    <div class="battle-main">
        <div class="battle-left" tabindex="0" aria-label="Игровое поле и игровые зоны">
            <div class="battle-layout">
                <div class="fly-zone-side" data-battle-fragment="fly_zones_html">{{fly_zones_html}}</div>
                <div class="battle-field" data-battle-fragment="field_html">{{field_html}}</div>
                <div class="grave-zone-side" data-battle-fragment="piles_html">{{piles_html}}</div>
            </div>
            <div data-battle-fragment="pile_reveal_html">{{pile_reveal_html}}</div>
            {{debug_html}}
        </div>
        <div class="battle-panel-side" data-battle-fragment="panel_html" tabindex="0" aria-label="Выбранная карта и действия">{{panel_html}}</div>
    </div>
    <div class="info-panel" data-battle-fragment="info_panel_html" tabindex="0" aria-label="Информация о бое и действия">{{info_panel_html}}</div>
</div>
<script src="/assets/js/cell-marker-picker.js?v=1" defer></script>
