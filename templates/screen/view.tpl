{{message}}
<div class="screen-prepare screen-prepare-view">
    <h1>Твоя дека: {{deck_name}}</h1>

    <div class="prepare-layout">
        <div class="prepare-content">
            <div class="cards">
                {{cards_html}}
            </div>
            {{sideboard_html}}
        </div>

        <div class="prepare-preview-slot">
            {{preview_html}}
        </div>
    </div>

    <div class="prepare-bottom-bar">
        {{bottom_panel_html}}
    </div>
</div>
