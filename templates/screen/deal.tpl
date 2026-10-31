<div class="screen-prepare screen-prepare-view screen-prepare-deal" data-view-pager data-deal-pager>
    <p class="flash">{{message}}</p>
    <div class="pregame-stage-content">
        <div class="pregame-stage-cards pregame-view-pages">
            <p class="pregame-view-empty" data-view-empty hidden>Нет карт по выбранным условиям.</p>
            <section class="pregame-view-group" data-view-group="deck" data-view-label="Карты">
                <div class="cards">{{cards_html}}</div>
            </section>

        </div>
    </div>
    <nav class="pregame-view-pagination" data-view-pagination aria-label="Страницы карт"></nav>

</div>
<script src="/assets/js/pregame-view.js?v=9" defer></script>
