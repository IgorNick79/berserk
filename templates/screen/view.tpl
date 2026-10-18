<div class="screen-prepare screen-prepare-view" data-view-pager>
    <p class="flash">{{message}}</p>
    <h2 class="pregame-view-heading" data-view-heading>Колода</h2>
    <div class="pregame-stage-content">
        <div class="pregame-stage-cards pregame-view-pages">
            <section class="pregame-view-group" data-view-group="deck" data-view-label="Колода">
                <div class="cards">{{cards_html}}</div>
            </section>
            {{sideboard_html}}
        </div>
    </div>
    <nav class="pregame-view-pagination" data-view-pagination aria-label="Страницы колоды и сайдборда"></nav>
    <div class="pregame-view-actions">{{preview_html}}</div>
</div>
<script src="/assets/js/pregame-view.js?v=1" defer></script>
