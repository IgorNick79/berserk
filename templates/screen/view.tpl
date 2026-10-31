<link rel="stylesheet" href="/assets/css/battle-ui-skins.css?v=12">
<link rel="stylesheet" href="/assets/css/battle-mini-card.css?v=15">
<link rel="stylesheet" href="/assets/css/battle-mini-nameplate.css?v=1">
<link rel="stylesheet" href="/assets/css/battle-chat.css?v=1">
<link rel="stylesheet" href="/assets/css/view-deck.css?v=2">
<div style="--player-playmat: url('{{playmat_url}}')" class="screen-prepare screen-prepare-view" data-view-pager>
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
</div>
<script src="/assets/js/pregame-view.js?v=6" defer></script>

<script src="/assets/js/panel-secondary-view.js?v=1" defer></script>
<script src="/assets/js/battle-match-pause.js?v=1" defer></script>
<script src="/assets/js/view-deck.js?v=2" defer></script>

<script src="/assets/js/card-image-popup.js?v=2" defer></script>
<script src="/assets/js/battle-mini-card.js?v=9" defer></script>
