<div class="pregame-layout">
    <button class="pregame-menu" type="button" aria-label="Меню" title="Меню"><span aria-hidden="true">☰</span></button>
    <main class="pregame-main" tabindex="0" aria-label="Подготовка к игре">{{main_html}}</main>
    <aside class="pregame-info-panel" aria-label="Информация о подготовке">
        <header class="pregame-panel-header">
            <div class="pregame-panel-heading"><h2 class="pregame-panel-title">{{title}}</h2><span class="pregame-panel-phase">{{phase}}</span></div>
            <div class="pregame-panel-timer">{{timer_html}}</div>
        </header>
        <div class="pregame-panel-content" data-pregame-content="{{content_kind}}" tabindex="0" aria-label="Содержимое информационной панели">{{content_html}}</div>
        <div class="pregame-panel-summary">{{summary_html}}</div>
        <footer class="pregame-panel-footer">{{footer_html}}</footer>
    </aside>
</div>
<script src="/assets/js/pregame-ui.js?v=4" defer></script>
