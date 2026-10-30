(function(){
    'use strict';
    // Battle Debug only: general menu and preference behavior live in unified-menu.js.
    window.BerserkBattleDebug={create:function(root){
        const trigger=document.querySelector('[data-unified-menu] [data-menu-debug]'),host=root.querySelector('[data-battle-fragment="info_panel_html"]');
        if(!trigger||!host)return null;let open=false,overlay=null,hadFocus=false;
        function render(){const old=overlay;overlay=host.querySelector('#battle-debug');const allowed=overlay&&overlay.querySelector('.debug-panel')&&root.dataset.matchPaused!=='1';trigger.disabled=!allowed;trigger.setAttribute('aria-disabled',String(!allowed));if(!allowed)open=false;if(overlay){overlay.hidden=!open;overlay.tabIndex=0;if(open&&old!==overlay&&hadFocus)overlay.focus({preventScroll:true});}}
        function close(){open=false;render();document.querySelector('.unified-menu-toggle')?.focus({preventScroll:true});}
        function click(e){if(e.target.closest('[data-menu-debug]')===trigger){e.preventDefault();if(trigger.disabled)return;window.BerserkMenu?.close();open=true;render();overlay?.focus({preventScroll:true});}else if(root.contains(e.target)&&e.target.closest('[data-menu-debug-close]')){e.preventDefault();close();}}
        function key(e){if(e.key==='Escape'&&open){e.preventDefault();close();}}
        function focus(e){hadFocus=!!overlay?.contains(e.target);}
        const observer=new MutationObserver(render);observer.observe(host,{childList:true,subtree:true});document.addEventListener('click',click);document.addEventListener('keydown',key);document.addEventListener('focusin',focus);render();
        if(location.hash==='#battle-debug'&&!trigger.disabled){open=true;render();overlay.focus({preventScroll:true});}
        return {render,destroy(){observer.disconnect();document.removeEventListener('click',click);document.removeEventListener('keydown',key);document.removeEventListener('focusin',focus);if(overlay)overlay.hidden=true;}};
    }};
})();
