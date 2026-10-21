(() => {
    'use strict';
    const menu=document.querySelector('[data-game-menu]');
    if (!menu) return;
    const cfg=JSON.parse(document.getElementById('preparation-menu-config').textContent);
    const root=menu.closest('.pregame-layout') || menu.parentElement;
    let saving=false;
    const picker=window.BerserkPlaymats?.create(root,'index.php?game='+cfg.game,()=>{});
    menu.querySelector('[data-preparation-playmat]').addEventListener('click',()=>{menu.open=false;root.openPlaymats?.();});
    menu.querySelector('[data-preparation-sound]').addEventListener('click',async event=>{
        if (saving) return;
        const button=event.currentTarget, next=button.getAttribute('aria-checked')!=='true';
        const notice=menu.querySelector('[data-preparation-menu-notice]');
        saving=true;button.disabled=true;
        try {
            const response=await fetch('index.php?game='+cfg.game+'&ajax=preparation_preference',{method:'POST',credentials:'same-origin',body:new URLSearchParams({csrf:cfg.csrf,sound_enabled:next?'1':'0'})});
            const data=await response.json();
            if (!response.ok || !data.ok) throw Error('save');
            button.setAttribute('aria-checked',String(data.sound_enabled));
            button.querySelector('[data-preparation-sound-check]').textContent=data.sound_enabled?'✓':'';
            notice.textContent='';
        } catch {notice.textContent='Не удалось сохранить настройку.';}
        finally {saving=false;button.disabled=false;}
    });
    document.addEventListener('click',event=>{if(!menu.contains(event.target)) menu.open=false;});
    document.addEventListener('keydown',event=>{if(event.key==='Escape'){menu.open=false;root.closePlaymats?.();menu.querySelector('summary').focus();}});
})();
