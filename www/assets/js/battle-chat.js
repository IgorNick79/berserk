(function () {
    "use strict";
    var root = document.querySelector('[data-game-chat]');
    if (!root) return;
    var q = function (name) { return root.querySelector('[data-chat-' + name + ']'); };
    var toggle=q('toggle'), panel=document.getElementById('game-chat-panel'), history=q('history');
    var input=q('input'), send=q('send'), status=q('status'), older=q('older');
    var cursor=null, oldest=null, baseline=null, lastRead='0', latest='0', unread=false;
    var polling=false, sending=false, reading=false, loadingOlder=false, timer=0, stopped=false;
    var messages=new Map(), pending=null, writable=false, initialized=false;
    var storageKey='berserk-chat:'+root.dataset.gameId+':'+root.dataset.senderId;
    function compare(a,b) { a=String(a); b=String(b); return a.length-b.length || (a<b?-1:a>b?1:0); }
    function max(a,b) { return compare(a,b)>0?a:b; }
    function atBottom() { return history.scrollHeight-history.scrollTop-history.clientHeight < 24; }
    function visible() { return !panel.hidden && !document.hidden && document.hasFocus() && atBottom(); }
    function badge(value) {
        unread=value;
        root.classList.toggle('has-unread',value);
        q('indicator').hidden=!q('indicator').dataset.loaded;
        q('indicator').setAttribute('aria-hidden',String(!value));
        q('unread-text').hidden=!value || !!q('indicator').dataset.loaded;
        toggle.setAttribute('aria-label',value?'Чат боя: новые сообщения':'Чат боя');
    }
    function imageReady(node, fallback) {
        function loaded() { node.dataset.loaded='1'; if (fallback) {node.hidden=false; fallback.hidden=true;} else badge(unread); }
        node.addEventListener('load',loaded);
        node.addEventListener('error',function () { delete node.dataset.loaded; node.hidden=true; if(fallback) fallback.hidden=false; else badge(unread); });
        if(node.complete && node.naturalWidth) loaded();
    }
    imageReady(q('icon'),q('label')); imageReady(q('indicator'),null);
    function api(action, params, post) {
        var url=new URL(window.location.href);
        url.search=''; url.searchParams.set(root.dataset.role,'');
        url.searchParams.set('game',root.dataset.gameId); url.searchParams.set('ajax',action);
        var options={headers:{Accept:'application/json'},cache:'no-store',credentials:'same-origin'};
        if(post) { options.method='POST'; options.body=new URLSearchParams(Object.assign({csrf:root.dataset.csrf},params)); }
        else Object.keys(params).forEach(function(k){url.searchParams.set(k,params[k]);});
        var controller=new AbortController(), timeout=setTimeout(function(){controller.abort();},10000);
        options.signal=controller.signal;
        return fetch(url,options).then(function(response){return response.json().then(function(data){
            if(!response.ok || !data.ok) { var e=new Error(data.error || 'network'); e.code=data.error; throw e; }
            return data;
        });}).finally(function(){clearTimeout(timeout);});
    }
    function draw(rows, prepend) {
        var bottom=atBottom(), height=history.scrollHeight, fragment=document.createDocumentFragment();
        rows.forEach(function(row){
            if(messages.has(row.id)) return;
            messages.set(row.id,row);
            var node=document.createElement('div'); node.className='game-chat-message';
            if(String(row.sender_id)===root.dataset.senderId) node.classList.add('is-own');
            var meta=document.createElement('small');
            meta.textContent=(String(row.sender_id)===root.dataset.senderId?'Вы':'Соперник')+' · ход '+row.turn_number;
            var text=document.createElement('span'); text.textContent=row.message;
            node.appendChild(meta); node.appendChild(text); fragment.appendChild(node);
            if(oldest===null || compare(row.id,oldest)<0) oldest=row.id;
        });
        if(prepend) {history.prepend(fragment); history.scrollTop+=history.scrollHeight-height;}
        else {history.appendChild(fragment); if(bottom) history.scrollTop=history.scrollHeight;}
    }
    function markRead() {
        if(!initialized || reading || !visible() || !cursor || compare(cursor,lastRead)<=0) return;
        var id=cursor; reading=true;
        api('chat_read',{last_read_id:id},true).then(function(){
            lastRead=max(lastRead,id);
            if(compare(lastRead,latest)>=0) badge(false);
        }).catch(function(){ /* Keep the badge and retry on the next sample. */ })
          .finally(function(){reading=false; if(cursor && compare(cursor,id)>0) markRead();});
    }
    function schedule(delay) { clearTimeout(timer); if(!stopped) timer=setTimeout(poll,delay); }
    function poll() {
        if(polling || stopped) return;
        polling=true; var delay=document.hidden?5000:1500;
        api('chat_messages',cursor===null?{}:{after_id:cursor},false).then(function(data){
            var first=baseline===null;
            if(first) baseline=data.latest_id;
            lastRead=max(lastRead,data.last_read_id); latest=max(latest,data.latest_id);
            writable=data.can_send; input.disabled=!writable; send.disabled=!writable || sending;
            var incoming=data.messages.some(function(row){return String(row.sender_id)!==root.dataset.senderId && compare(row.id,baseline)>0 && !messages.has(row.id);});
            draw(data.messages,false);
            if(data.messages.length) cursor=data.messages[data.messages.length-1].id;
            else if(cursor===null) cursor='0';
            if(first) { older.hidden=!data.has_more; initialized=true; }
            badge(data.unread && compare(lastRead,latest)<0);
            if(incoming) {
                var battleRoot=document.querySelector('[data-battle-sync-root]');
                if(battleRoot && battleRoot.berserkTurnTimer) battleRoot.berserkTurnTimer.notify();
            }
            markRead();
            if(!writable) status.textContent='Бой завершён. Чат доступен для чтения.';
            else if(status.dataset.offline) {status.textContent=''; delete status.dataset.offline;}
            // Drain ascending pages before waiting. Initial latest-page has_more means older history.
            if(!first && data.has_more) delay=0;
        }).catch(function(){status.textContent='Нет связи с чатом. Повторяем подключение…'; status.dataset.offline='1'; delay=5000;})
          .finally(function(){polling=false; schedule(delay);});
    }
    older.addEventListener('click',function(){
        if(loadingOlder || !oldest) return;
        loadingOlder=true; older.disabled=true;
        api('chat_messages',{before_id:oldest},false).then(function(data){draw(data.messages,true);older.hidden=!data.has_more;})
            .catch(function(){status.textContent='Не удалось загрузить историю. Повторите попытку.';})
            .finally(function(){loadingOlder=false;older.disabled=false;});
    });
    function open(value) {
        panel.hidden=!value; toggle.setAttribute('aria-expanded',String(value));
        if(value) {history.scrollTop=history.scrollHeight; if(writable) input.focus(); else history.focus(); markRead();}
        else toggle.focus();
    }
    toggle.addEventListener('click',function(){open(panel.hidden);});
    q('cancel').addEventListener('click',function(){open(false);});
    root.addEventListener('keydown',function(e){if(e.key==='Escape' && !panel.hidden){e.preventDefault();open(false);}});
    history.addEventListener('scroll',markRead);
    document.addEventListener('visibilitychange',function(){if(!document.hidden){markRead();schedule(0);}});
    window.addEventListener('focus',function(){markRead();schedule(0);});
    window.addEventListener('online',function(){schedule(0);});
    try { pending=JSON.parse(sessionStorage.getItem(storageKey)); if(pending && pending.text) input.value=pending.text; } catch(e) {pending=null;}
    function persist() {try {if(pending) sessionStorage.setItem(storageKey,JSON.stringify(pending)); else sessionStorage.removeItem(storageKey);}catch(e){ /* In-memory identity still handles retries. */ }}
    q('form').addEventListener('submit',function(e){
        e.preventDefault(); if(sending || !writable) return;
        var text=input.value;
        if(!text.trim()) {status.textContent='Введите сообщение.';return;}
        if(Array.from(text).length>1000) {status.textContent='Не более 1000 символов.';return;}
        if(pending && pending.text!==text) {status.textContent='Сначала повторите неподтверждённое сообщение или восстановите его текст.';input.value=pending.text;return;}
        if(!pending) {
            var bytes=new Uint8Array(16); crypto.getRandomValues(bytes);
            pending={id:Array.from(bytes,function(x){return x.toString(16).padStart(2,'0');}).join(''),text:text}; persist();
        }
        sending=true; send.disabled=true; input.readOnly=true; status.textContent='Отправляем…';
        api('chat_send',{message:pending.text,request_id:pending.id},true).then(function(){
            pending=null;persist();input.value='';status.textContent='';schedule(0);
        }).catch(function(error){
            if(['empty_message','invalid_message','message_too_long','rate_limited','chat_read_only'].includes(error.code)) {pending=null;persist();}
            if(error.code==='chat_read_only') {writable=false;status.textContent='Бой завершён. Чат доступен для чтения.';}
            else if(error.code==='rate_limited') status.textContent='Подождите секунду перед следующим сообщением.';
            else if(error.code==='message_too_long') status.textContent='Не более 1000 символов.';
            else if(error.code==='empty_message' || error.code==='invalid_message') status.textContent='Введите непустое текстовое сообщение.';
            else status.textContent='Отправка не подтверждена. Нажмите «Отправить» для безопасного повтора.';
        }).finally(function(){sending=false;send.disabled=!writable;input.readOnly=false;input.disabled=!writable;});
    });
    window.addEventListener('beforeunload',function(){stopped=true;clearTimeout(timer);});
    send.disabled=true; poll();
})();
