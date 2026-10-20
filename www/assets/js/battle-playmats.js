(function () {
    "use strict";
    window.BerserkPlaymats = {create: function (root, syncUrl, onSaved) {
        var config = JSON.parse(document.getElementById('battle-playmats').textContent);
        var version = -1, current = null, page = 0, saving = false;
        var ids = Object.keys(config.catalog), layout = root.querySelector('.battle-layout');
        var panel = document.createElement('section');
        panel.dataset.playmatPicker = ''; panel.className = 'playmat-picker'; panel.hidden = true;
        panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-label', 'Выбор коврика');
        panel.innerHTML = '<button type="button" data-mat-close aria-label="Закрыть">×</button><p data-mat-title></p><div class="playmat-options"></div><nav class="playmat-pagination" aria-label="Страницы ковриков"></nav><p data-mat-status role="status"></p>';
        root.appendChild(panel);
        ids.forEach(function (id, index) {
            var button = document.createElement('button'); button.type = 'button'; button.dataset.matId = id;
            button.setAttribute('aria-label', 'Коврик ' + (index + 1));
            button.style.backgroundImage = 'url("' + config.catalog[id] + '")';
            panel.querySelector('.playmat-options').appendChild(button);
        });
        for (var i = 0; i < Math.ceil(ids.length / 9); i++) {
            var chip = document.createElement('button'); chip.type = 'button'; chip.dataset.matPage = String(i);
            chip.setAttribute('aria-label', 'Страница ' + (i + 1)); chip.innerHTML = '<span></span>';
            panel.querySelector('nav').appendChild(chip);
        }
        function render() {
            panel.querySelector('[data-mat-title]').textContent = 'Страница ' + (page + 1) + ' из ' + Math.ceil(ids.length / 9);
            panel.querySelectorAll('[data-mat-id]').forEach(function (button, index) {
                button.hidden = Math.floor(index / 9) !== page;
                button.setAttribute('aria-pressed', String(button.dataset.matId === current)); button.disabled = saving;
            });
            panel.querySelectorAll('[data-mat-page]').forEach(function (button) { button.setAttribute('aria-current', Number(button.dataset.matPage) === page ? 'page' : 'false'); });
        }
        function update(data) {
            if (!data || !Number.isInteger(data.version) || data.version < version) return;
            version = data.version; current = data.own;
            [['--playmat-own', data.own_url], ['--playmat-opponent', data.opponent_url]].forEach(function (entry) {
                var value = 'url("' + entry[1] + '")';
                if (layout.style.getPropertyValue(entry[0]) !== value) layout.style.setProperty(entry[0], value);
            });
            render();
        }
        root.openPlaymats = function () { page = Math.max(0, Math.floor(ids.indexOf(current) / 9)); panel.hidden = false; render(); panel.querySelector('[data-mat-close]').focus(); };
        root.closePlaymats = function () { panel.hidden = true; };
        panel.addEventListener('click', async function (event) {
            var target = event.target.closest('button'); if (!target) return;
            if (target.hasAttribute('data-mat-close')) { panel.hidden = true; root.querySelector('[data-menu-playmat]').focus(); return; }
            if (target.hasAttribute('data-mat-page')) { page = Number(target.dataset.matPage); render(); return; }
            if (!target.dataset.matId || saving) return;
            saving = true; render(); var status = panel.querySelector('[data-mat-status]'); status.textContent = 'Сохраняем…';
            try {
                var url = new URL(syncUrl, window.location.href); url.searchParams.set('ajax', 'playmat');
                var response = await fetch(url, {method:'POST', credentials:'same-origin', body:new URLSearchParams({playmat_id:target.dataset.matId, csrf:config.csrf})});
                var data = await response.json(); if (!response.ok || !data.ok) throw new Error('save');
                update(data.playmats);
                if (onSaved) onSaved(data);
                status.textContent = 'Коврик выбран';
            } catch (_) { status.textContent = 'Не удалось сохранить коврик. Попробуйте ещё раз.'; }
            finally { saving = false; render(); }
        });
        update(config.state);
        return {update:update};
    }};
})();
