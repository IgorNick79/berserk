{{message}}
<div class="screen-prepare screen-prepare-view">
    <h1>Твоя дека: {{deck_name}}</h1>
    {{view_stats_html}}

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
        <div class="prepare-bottom-summary"></div>
        <div class="prepare-bottom-actions">
            {{confirm_html}}
        </div>
    </div>
</div>
