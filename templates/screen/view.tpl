<div class="screen-prepare screen-prepare-view" data-view-pager>
    <p class="flash">{{message}}</p>
    <div class="pregame-stage-content">
        <div class="pregame-stage-cards pregame-view-pages">
            <p class="pregame-view-empty" data-view-empty hidden>Нет карт по выбранным условиям.</p>
            <section class="pregame-view-group" data-view-group="deck" data-view-label="Колода">
                <div class="cards">{{cards_html}}</div>
            </section>
            {{sideboard_html}}
        </div>
    </div>
    <nav class="pregame-view-pagination" data-view-pagination aria-label="Страницы колоды и сайдборда"></nav>
    <div class="pregame-view-actions">{{preview_html}}</div>
</div>
<script src="/assets/js/pregame-view.js?v=2" defer></script>
