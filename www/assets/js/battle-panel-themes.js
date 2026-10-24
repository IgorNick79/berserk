(function () {
    'use strict';
    var root=document.querySelector('[data-battle-sync-root]');
    if (!root || root.openPanelThemes) return;
    var trigger=root.querySelector('[data-menu-panel-theme]'), ready=false, saving=false, loading=false, csrf='', current='grimoire', focusReturn=null;
    if (!trigger) return;
    var labels={grimoire:'Бронзовый гримуар',stone:'Каменный архив',leather:'Старая кожа',parchment:'Тёмный пергамент'};
    var panel=document.createElement('section');panel.className='panel-theme-picker';panel.dataset.panelThemePicker='';panel.hidden=true;
    panel.setAttribute('role','dialog');panel.setAttribute('aria-label','Тема InfoPanel');
    panel.innerHTML='<button type="button" class="battle-window-close" data-theme-close aria-label="Закрыть выбор темы">×</button><h2>Тема InfoPanel</h2><div class="panel-theme-options"></div><p data-theme-status role="status"></p>';
    root.appendChild(panel);var status=panel.querySelector('[data-theme-status]');
    Object.keys(labels).forEach(function(id){var b=document.createElement('button');b.type='button';b.dataset.themeChoice=id;b.className='panel-theme-option';b.textContent=labels[id];panel.querySelector('.panel-theme-options').appendChild(b);});
    function render(){panel.querySelectorAll('[data-theme-choice]').forEach(function(b){b.disabled=!ready||saving;b.setAttribute('aria-pressed',String(b.dataset.themeChoice===current));});}
    function apply(id){if(!Object.prototype.hasOwnProperty.call(labels,id))throw Error('invalid_theme');current=id;root.dataset.panelTheme=id;render();}
    function close(restore){panel.hidden=true;if(restore&&focusReturn&&focusReturn.isConnected)focusReturn.focus({preventScroll:true});}
    async function request(options){var controller=new AbortController(), timer=setTimeout(function(){controller.abort();},10000);try{var response=await fetch('/panel-theme.php',Object.assign({credentials:'same-origin',cache:'no-store',signal:controller.signal},options));var data=await response.json();if(!response.ok||data.ok!==true||!Object.prototype.hasOwnProperty.call(labels,data.theme))throw Error(data.error||'theme_save_failed');return data;}finally{clearTimeout(timer);}}
    async function load(){if(loading)return;loading=true;status.textContent='Загружаем настройки аккаунта…';render();try{var data=await request();csrf=data.csrf;ready=true;apply(data.theme);status.textContent='Выбор сохраняется в вашем аккаунте.';}catch(error){ready=false;status.textContent=error.message==='theme_schema_missing'?'Настройки темы ещё не подключены: требуется миграция БД авторизации.':'Не удалось загрузить настройки. Проверьте соединение и откройте выбор темы снова.';render();}finally{loading=false;}}
    root.openPanelThemes=function(){focusReturn=document.activeElement;panel.hidden=false;render();panel.querySelector('[data-theme-close]').focus({preventScroll:true});if(!ready)load();};
    root.closePanelThemes=function(){close(false);};
    panel.addEventListener('click',async function(e){if(e.target.closest('[data-theme-close]')){close(true);return;}var button=e.target.closest('[data-theme-choice]');if(!button||!ready||saving)return;saving=true;render();status.textContent='Сохраняем…';try{var data=await request({method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({theme:button.dataset.themeChoice,csrf:csrf}).toString()});apply(data.theme);status.textContent='Тема сохранена в аккаунте.';}catch(error){status.textContent='Не удалось сохранить тему. Повторите выбор.';}finally{saving=false;render();if(!panel.hidden&&button.isConnected)button.focus({preventScroll:true});}});
    panel.addEventListener('keydown',function(e){if(e.key==='Escape'){e.preventDefault();e.stopPropagation();close(true);}});
    load();
})();
