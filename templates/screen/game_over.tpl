<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Philosopher:wght@400;700&amp;family=Golos+Text:wght@400;500;600;700&amp;display=swap">
<link rel="stylesheet" href="/assets/css/battle-ui-skins.css?v=6">
<link rel="stylesheet" href="/assets/css/battle-result.css?v=2">
<div class="battle-shell battle-result" data-battle-sync-root data-panel-theme="grimoire" data-result="{{outcome}}">
    <main class="battle-main result-media" aria-label="{{text}}">
        <video class="result-video" poster="/assets/images/results/{{outcome}}.jpg" muted playsinline preload="metadata" aria-label="{{text}} — итог боя">
            <source src="/assets/images/results/{{outcome}}.mp4" type="video/mp4">
            <img src="/assets/images/results/{{outcome}}.jpg" alt="{{text}}">
        </video>
    </main>
    <aside class="info-panel" data-battle-fragment="info_panel_html" aria-label="Результат боя и действия">
        <header class="info-panel-header"><h1 class="info-status">Результат боя</h1></header>
        <div class="info-panel-content" tabindex="0" aria-label="Итог и статистика боя">
            <section class="result-summary">
                <h2 class="result-outcome"><img src="/assets/images/battle/cell_info.svg" width="28" height="28" alt="">{{text}}</h2>
                <p class="result-reason">{{reason}}</p>
                <dl class="result-stats" aria-label="Статистика боя">
                    <div><dt>Длительность боя</dt><dd>{{duration}}</dd></div>
                    <div><dt>Количество ходов</dt><dd>{{turns}}</dd></div>
                </dl>
                <p class="result-stat-note">Ходы обоих игроков. Общее время включает паузы.</p>
            </section>
        </div>
        <footer class="info-panel-footer"><div class="info-actions">
            <a class="button wide green-btn" href="/lobby.php"><span>В лобби</span></a>
            <div class="battle-icon-actions">
                <button class="battle-icon-control" type="button" data-action="battle-log" aria-label="История боя" title="История боя" aria-controls="battle-journal" {{history_disabled}}>{{history_icon}}</button>
                <span class="battle-chat-anchor" data-chat-anchor></span>
                <button class="battle-icon-control result-share" type="button" aria-label="Поделиться" title="Поделиться"><img src="/assets/images/results/share.png" width="40" height="40" alt=""><span class="battle-icon-tooltip" aria-hidden="true">Поделиться</span></button>
            </div>
        </div></footer>
    </aside>
</div>
<script src="/assets/js/battle-ui.js?v=14" defer></script>
<script src="/assets/js/battle-result.js?v=2" defer></script>
