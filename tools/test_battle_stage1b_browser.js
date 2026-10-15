/* Browser regression checks against actual index.php snapshots; no game commands. */
(function () {
    "use strict";
    var originalFetch = window.fetch.bind(window);
    var staleNext = false, staleDelivered = false, staleSeen = false;
    var marker = "STALE_STAGE1B_RESULT";
    window.fetch = async function (input, options) {
        var response = await originalFetch(input, options);
        var url = new URL(String(input), location.href);
        if (url.searchParams.get("ajax") !== "battle_sync") return response;
        var data = await response.clone().json();
        if (!data.fragments) return response;
        // Exercise independent fragment composition, not the incidental duplicate
        // selected-card HTML carried by the server's full InfoPanel rendering.
        var info = document.createElement("template");
        info.innerHTML = data.fragments.info_panel_html;
        var card = info.content.querySelector("[data-battle-fragment='panel_html']");
        if (card) card.innerHTML = "";
        data.fragments.info_panel_html = info.innerHTML;
        if (staleNext) {
            staleNext = false;
            data.sync_version = -1;
            data.fragments.panel_html = '<p>' + marker + '</p>';
            data.fragments.info_panel_html = data.fragments.info_panel_html.replace('info-status">', 'info-status">' + marker);
            staleDelivered = true;
        }
        return new Response(JSON.stringify(data), {status: response.status, headers: response.headers});
    };
    new MutationObserver(function () {
        if (document.querySelector(".info-panel").textContent.includes(marker)) staleSeen = true;
    }).observe(document.querySelector("[data-battle-sync-root]"), {childList:true,subtree:true});
    var output = document.getElementById("stage1b-results");
    var lines = [];
    function assert(ok, text) { if (!ok) throw new Error(text); lines.push("PASS " + text); }
    async function until(fn) {
        for (var i=0;i<100;i++) { if (fn()) return; await new Promise(function(resolve){setTimeout(resolve,50);}); }
        throw new Error("Timed out waiting for snapshot");
    }
    function selectedName() { var h=document.querySelector('.battle-selected-card h3'); return h ? h.textContent : ''; }
    document.getElementById("run-stage1b-checks").addEventListener("click", async function () {
        this.disabled = true; lines=[];
        try {
            var links=Array.from(document.querySelectorAll('.battle-field .card-link'));
            assert(links.length >= 2, 'Two field cards available');
            var names=links.slice(0,2).map(function(a){return a.querySelector('.bname').textContent;});
            // Choose two distinct cards through existing delegated AJAX handlers.
            links[1].click(); await until(function(){return selectedName()===names[1];});
            assert(true,'Selected-card fragment composed inside new InfoPanel');
            staleNext=true;
            document.querySelectorAll('.battle-field .card-link')[0].click();
            await until(function(){return staleDelivered;});
            assert(!staleSeen && selectedName()===names[1], 'Stale snapshot does not replace current content');
            document.querySelectorAll('.battle-field .card-link')[0].click();
            await until(function(){return selectedName()===names[0];});
            assert(!staleSeen,'Current snapshot recovers after stale response');
            var menu=document.querySelector('.battle-menu');
            assert(!menu.disabled,'Server debug is available');
            menu.click(); await until(function(){return menu.getAttribute('aria-expanded')==='true';});
            var duplicate=document.createElement('script');duplicate.src='/assets/js/battle-ui.js';
            await new Promise(function(resolve,reject){duplicate.onload=resolve;duplicate.onerror=reject;document.head.appendChild(duplicate);});
            menu.click(); assert(menu.getAttribute('aria-expanded')==='false','Repeated initialization does not duplicate handlers');
            menu.click();
            var rect=document.querySelector('.battle-field').getBoundingClientRect();
            var oldOverlay=document.querySelector('#battle-debug');
            var inner=oldOverlay.querySelector('.debug-panel');inner.tabIndex=0;inner.focus();
            assert(oldOverlay.contains(document.activeElement),'Focus is inside old overlay before AJAX');
            document.querySelectorAll('.battle-field .card-link')[1].click();
            await until(function(){return selectedName()===names[1] && document.querySelector('#battle-debug')!==oldOverlay;});
            var newOverlay=document.querySelector('#battle-debug');
            assert(newOverlay.contains(document.activeElement),'Focus restored inside replacement overlay');
            assert(menu.getAttribute('aria-expanded')==='true' && !newOverlay.hidden,'Debug stays open after AJAX');
            menu.focus();
            document.querySelectorAll('.battle-field .card-link')[0].click();
            await until(function(){return selectedName()===names[0] && document.querySelector('#battle-debug')!==newOverlay;});
            assert(document.activeElement===menu,'AJAX does not steal focus from outside overlay');
            assert(menu.getAttribute('aria-expanded')==='true','Outside focus does not close debug');
            var blurredOverlay=document.querySelector('#battle-debug');
            blurredOverlay.focus();blurredOverlay.blur();
            await new Promise(function(resolve){setTimeout(resolve,0);});
            document.querySelectorAll('.battle-field .card-link')[1].click();
            await until(function(){return selectedName()===names[1] && document.querySelector('#battle-debug')!==blurredOverlay;});
            assert(!document.querySelector('#battle-debug').contains(document.activeElement),'Explicit blur is not mistaken for focus lost through replacement');
            var ariaWrites=0, originalSetAttribute=menu.setAttribute;
            menu.setAttribute=function(name,value){if(name==='aria-expanded') ariaWrites++;return originalSetAttribute.call(this,name,value);};
            try {
                var probes=['.battle-field','.battle-selected-card','.info-panel-header'].map(function(selector){
                    var node=document.createElement('span');document.querySelector(selector).appendChild(node);return node;
                });
                await new Promise(function(resolve){setTimeout(resolve,0);});
                probes.forEach(function(node){node.remove();});
                await new Promise(function(resolve){setTimeout(resolve,0);});
                assert(ariaWrites===0,'Unrelated field/card/header mutations do not synchronize debug');
            } finally { menu.setAttribute=originalSetAttribute; }
            assert(document.querySelectorAll('#battle-debug').length===1 && document.querySelectorAll('.debug-panel').length===1,'Debug is not duplicated');
            var next=document.querySelector('.battle-field').getBoundingClientRect();
            assert(rect.width===next.width && rect.height===next.height,'Debug does not resize battlefield');
            document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}));
            assert(menu.getAttribute('aria-expanded')==='false','Escape closes debug');
            assert(document.activeElement===menu,'Escape returns focus to burger');
            var debugPanel=document.querySelector('#battle-debug .debug-panel');
            debugPanel.remove();await until(function(){return menu.disabled;});
            assert(menu.getAttribute('aria-expanded')==='false','Missing server debug disables burger');
            document.querySelector('#battle-debug').appendChild(debugPanel);
            await until(function(){return !menu.disabled;});
            assert(menu.getAttribute('aria-expanded')==='false','Restored server debug enables burger without opening it');
            [document.documentElement,document.body].forEach(function(e){
                assert(e.scrollWidth===e.clientWidth && e.scrollHeight===e.clientHeight,'No page scroll: '+e.tagName);
            });
            assert(document.querySelectorAll('[data-battle-fragment]').length===6,'Six current fragment containers remain');
            assert(document.querySelectorAll('.info-panel-footer a[href*="cmd=resign"]').length===1,'Single persistent resign command');
            lines.push('ALL STAGE 1B BROWSER CHECKS PASSED');
        } catch(e) { lines.push('FAIL '+e.message); }
        output.textContent=lines.join('\n'); output.hidden=false;
    });
}());
