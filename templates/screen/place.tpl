{{message}}
<div class="screen-prepare screen-prepare-place">
    <h1>Расстановка</h1>

    <div class="prepare-layout">
        <div class="prepare-content">
            <div class="place-field">
                {{field_html}}
            </div>

            <div class="place-squad">
                <h2>Нерасставлено: {{squad_count}}</h2>
                <div class="squad-cards">
                    {{squad_html}}
                </div>
            </div>
        </div>

        <div class="prepare-preview-slot">
            {{preview_html}}
        </div>
    </div>

    <div class="prepare-bottom-bar">
        <div class="prepare-bottom-summary"></div>
        <div class="prepare-bottom-actions">
            {{confirm_html}}
            {{card_actions_html}}
        </div>
    </div>
</div>
