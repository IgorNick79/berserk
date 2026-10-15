<div class="battle-shell">
    <button class="battle-menu-placeholder" type="button" disabled aria-label="Меню (скоро)" title="Меню (скоро)">
        <span aria-hidden="true">☰</span>
    </button>
    <div class="battle-main">
        <div class="battle-left" tabindex="0" aria-label="Игровое поле и игровые зоны">
            <div class="battle-layout">
                <div class="fly-zone-side">
                    {{fly_zones_html}}
                </div>
                <div class="battle-field">
                    {{field_html}}
                </div>
                <div class="grave-zone-side">
                    {{piles_html}}
                </div>
            </div>
            {{pile_reveal_html}}
            {{debug_html}}
        </div>
        <div class="battle-panel-side" tabindex="0" aria-label="Выбранная карта и действия">
            {{panel_html}}
        </div>
    </div>
    <div class="info-panel" tabindex="0" aria-label="Информация о бое и действия">{{info_panel_html}}</div>
</div>
<script src="/assets/js/cell-marker-picker.js?v=1" defer></script>
