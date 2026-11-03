<link rel="stylesheet" href="/assets/css/battle-fonts.css?v=1">
<link rel="stylesheet" href="/assets/css/battle-ui-skins.css?v=17">
<link rel="stylesheet" href="/assets/css/battle-mini-card.css?v=17">
<link rel="stylesheet" href="/assets/css/battle-mini-nameplate.css?v=2">
<link rel="stylesheet" href="/assets/css/view-deck.css?v=8">
<link rel="stylesheet" href="/assets/css/roll.css?v=4">
<link rel="stylesheet" href="/assets/css/draft.css?v=9">
<div style="--player-playmat: url('{{playmat_url}}')" class="screen-draft battle-left {{draft_class}}">
    <p class="flash">{{message}}</p>

    {{draft_info_html}}

    <div class="pregame-draft-grid">
        <div class="draft-grid" {{grid_attributes}}>
            {{grid_html}}
        </div>
    </div>

    {{draft_bottom_html}}
    {{history_html}}
    {{draft_script_html}}
</div>

{{match_pause_mask_html}}
<script src="/assets/js/panel-secondary-view.js?v=1" defer></script>
<script src="/assets/js/battle-match-pause.js?v=4" defer></script>
<script src="/assets/js/view-deck.js?v=12" defer></script>
<script src="/assets/js/card-image-popup.js?v=2" defer></script>
<script src="/assets/js/battle-mini-card.js?v=12" defer></script>
