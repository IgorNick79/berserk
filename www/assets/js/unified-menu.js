(function () {
    'use strict';
    const menu=document.querySelector('[data-unified-menu]');if(!menu)return;
    // Portal prevents game field overflow/isolation from clipping menu or dialogs.
    document.body.append(menu);
    const config=JSON.parse(menu.dataset.config),toggle=menu.querySelector('.unified-menu-toggle'),panel=menu.querySelector('nav'),notice=menu.querySelector('[data-menu-notice]');
    let open=false,current=null,opponent=null,csrf='',saving=false,loading=null,requestEpoch=0,dialog=null,returnFocus=null;
    const endpoint='/ui-preferences.php'+(config.game?'?game='+encodeURIComponent(config.game):'');
    window.BerserkPreferences={current:null,refresh:refresh};
    function controls(){return [...panel.querySelectorAll('a[href],button:not(:disabled),summary')].filter(e=>!e.hidden);}
    function show(value,focus=true){open=value;menu.classList.toggle('is-open',open);toggle.setAttribute('aria-expanded',String(open));panel.inert=!open;if(open){if(config.authenticated)refresh();if(focus)controls()[0]?.focus();}else if(focus)toggle.focus();}
    function status(text){notice.textContent=text;if(dialog)dialog.querySelector('[data-picker-notice]').textContent=text;}
    function paint(){menu.querySelectorAll('[data-preference]').forEach(b=>{b.disabled=!current||saving;if(b.dataset.preference==='sound')b.setAttribute('aria-pressed',String(!!current?.sound_enabled));});
        if(!current)return;
        const values={sound:current.sound_enabled?'Вкл.':'Выкл.',playmat:'№ '+String(Object.keys(config.playmats).indexOf(current.playmat_id)+1),battle_theme:config.themes[current.theme]};
        for(const [key,value] of Object.entries(values)){const node=menu.querySelector('[data-preference-value="'+key+'"]');if(node)node.textContent=value;}
        if(dialog)dialog.querySelectorAll('[data-choice]').forEach(b=>{b.disabled=saving;b.setAttribute('aria-pressed',String(b.dataset.choice===(dialog.dataset.kind==='playmat'?current.playmat_id:current.theme)));});
    }
    function apply(data){
        if(data.preferences && (!current||data.preferences.revision>=current.revision)){
            current=data.preferences;window.BerserkPreferences.current=current;
            document.querySelectorAll('[data-panel-theme]').forEach(root=>root.dataset.panelTheme=current.theme);
            document.querySelectorAll('.battle-layout').forEach(root=>root.style.setProperty('--playmat-own','url("'+config.playmats[current.playmat_id]+'")'));
            document.querySelectorAll('.screen-draft,.screen-prepare-view').forEach(root=>root.style.setProperty('--player-playmat','url("'+config.playmats[current.playmat_id]+'")'));
            document.dispatchEvent(new CustomEvent('berserk:preferences',{detail:current}));
        }
        if(data.opponent&&(!opponent||data.opponent.revision>=opponent.revision))opponent=data.opponent;
        if(opponent)document.querySelectorAll('.battle-layout').forEach(root=>root.style.setProperty('--playmat-opponent','url("'+config.playmats[opponent.playmat_id]+'")'));
        csrf=data.csrf||csrf;paint();
    }
    async function request(url,options={}){const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),10000);try{const response=await fetch(url,{credentials:'same-origin',cache:'no-store',...options,signal:controller.signal});const data=await response.json();if(!response.ok||!data.ok)throw Error('preferences');return data;}finally{clearTimeout(timeout);}}
    async function refresh(){if(!config.authenticated||saving)return;if(loading)return loading;const epoch=requestEpoch;loading=(async()=>{try{const data=await request(endpoint);if(epoch!==requestEpoch)return;apply(data);status('');}catch{if(epoch===requestEpoch)status('Не удалось загрузить настройки аккаунта. Повторите открытие меню.');}finally{loading=null;}})();return loading;}
    async function save(key,value){if(saving||!current)return;saving=true;++requestEpoch;paint();status('Сохраняем…');try{
        if(key==='theme') {await request('/panel-theme.php',{method:'POST',body:new URLSearchParams({theme:value,csrf})});apply(await request(endpoint));}
        else apply(await request(endpoint,{method:'POST',body:new URLSearchParams({key,value,csrf})}));
        status('Настройка сохранена в аккаунте.');
    }catch{status('Не удалось сохранить настройку. Повторите выбор.');}finally{saving=false;paint();}}
    function closePicker(){if(!dialog)return;dialog.remove();dialog=null;if(returnFocus?.isConnected)returnFocus.focus({preventScroll:true});returnFocus=null;}
    function picker(kind){if(!current)return;closePicker();returnFocus=document.activeElement;dialog=document.createElement('section');dialog.className='unified-preference-picker '+(kind==='playmat'?'playmat-picker':'panel-theme-picker');dialog.dataset.kind=kind;dialog.setAttribute('role','dialog');dialog.setAttribute('aria-modal','true');dialog.setAttribute('aria-label',kind==='playmat'?'Выбор коврика':'Тема боя');
        const close=document.createElement('button');close.type='button';close.textContent='×';close.className='unified-picker-close';close.setAttribute('aria-label','Закрыть выбор');close.onclick=closePicker;
        const title=document.createElement('h2');title.textContent=kind==='playmat'?'Коврик':'Тема боя';const options=document.createElement('div');options.className=kind==='playmat'?'playmat-options':'panel-theme-options';
        Object.entries(kind==='playmat'?config.playmats:config.themes).forEach(([id,label],index)=>{const b=document.createElement('button');b.type='button';b.dataset.choice=id;if(kind==='playmat'){b.style.backgroundImage='url("'+label+'")';b.setAttribute('aria-label','Коврик '+(index+1));}else{b.className='panel-theme-option';b.dataset.themeChoice=id;b.textContent=label;}b.onclick=()=>save(kind==='playmat'?'playmat_id':'theme',id);options.append(b);});
        const message=document.createElement('p');message.dataset.pickerNotice='';message.setAttribute('role','status');dialog.append(close,title,options,message);document.body.append(dialog);paint();close.focus();
    }
    toggle.addEventListener('click',()=>{if(dialog)closePicker();show(!open);});
    menu.addEventListener('click',e=>{const b=e.target.closest('[data-preference]');if(!b||b.disabled)return;if(b.dataset.preference==='sound')save('sound_enabled',current.sound_enabled?'0':'1');else picker(b.dataset.preference==='playmat'?'playmat':'theme');});
    document.addEventListener('pointerdown',e=>{if(open&&!dialog&&!menu.contains(e.target))show(false,false);});
    document.addEventListener('keydown',e=>{if(e.key==='Escape'){if(dialog){e.preventDefault();closePicker();}else if(open){e.preventDefault();show(false);}return;}
        if(dialog&&e.key==='Tab'){const items=[...dialog.querySelectorAll('button:not(:disabled)')],first=items[0],last=items.at(-1);if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}return;}
        if(e.target===toggle&&['ArrowDown','ArrowUp'].includes(e.key)){e.preventDefault();show(true,false);const items=controls();(e.key==='ArrowUp'?items.at(-1):items[0])?.focus();}
        else if(open&&panel.contains(e.target)&&['ArrowDown','ArrowUp','Home','End'].includes(e.key)&&!e.target.closest('[data-menu-group="developer"] details')){e.preventDefault();const items=controls(),i=items.indexOf(document.activeElement);(e.key==='Home'?items[0]:e.key==='End'?items.at(-1):items[(i+(e.key==='ArrowDown'?1:-1)+items.length)%items.length])?.focus();}
    });
    document.addEventListener('focusin',e=>{if(open&&!dialog&&!menu.contains(e.target))show(false,false);});
    window.addEventListener('focus',refresh);window.addEventListener('pageshow',refresh);document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});
    if(config.authenticated){refresh();setInterval(()=>{if(!document.hidden)refresh();},config.game?3000:15000);}
    window.BerserkMenu={close:()=>{closePicker();show(false);}};
})();
