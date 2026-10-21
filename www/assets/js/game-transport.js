(() => {
    'use strict';
    const script = document.currentScript;
    if (!script) return;
    const csrf = script.dataset.csrf, game = script.dataset.game;
    // Shared CSRF for the existing battle POST API, not a second command transport.
    window.berserkGameCsrf = csrf;
    if (script.dataset.stage === 'battle' || script.dataset.stage === 'game_over') return;
    let sending = false;
    const confirmations={confirm_deal:'Подтвердить отряд?',reshuffle:'Пересдать карты?',finish_draft:'Закончить драфт?'};
    function confirmed(params, message) { const text=message || confirmations[params.get('cmd')]; return !text || window.confirm(text); }
    // Existing non-draft waiting screens had no cross-browser stage notification.
    // This endpoint is metadata-only: no Engine, timers or automatic progression.
    if (script.dataset.stage !== 'draft') {
        let inFlight = false, delay = 2000;
        async function poll() {
            if (sending || inFlight) return;
            inFlight = true;
            const controller = new AbortController(), timeout = setTimeout(()=>controller.abort(),10000);
            try {
                const response = await fetch('index.php?game='+game+'&ajax=prepare_sync',{credentials:'same-origin',cache:'no-store',signal:controller.signal});
                if(response.status===401||response.status===403){location.assign('/lobby.php');return;}
                if(!response.ok)throw Error('sync');
                const data=await response.json();
                if(data.status!==script.dataset.stage||data.version!==Number(script.dataset.version)){location.reload();return;}
                delay=2000;
            }catch{delay=Math.min(delay*2,15000);}
            finally{clearTimeout(timeout);inFlight=false;if(!sending)setTimeout(poll,delay);}
        }
        setTimeout(poll,delay);
    }
    function submit(params) {
        if (sending) return;
        sending = true;
        const form = document.createElement('form'); form.method = 'post'; form.action = 'index.php?game=' + game;
        ['first','second','game','ajax'].forEach(k => params.delete(k));
        params.set('csrf',csrf); params.set('prepare_version',script.dataset.version);
        params.forEach((value,key) => {const input=document.createElement('input');input.type='hidden';input.name=key;input.value=value;form.append(input);});
        document.body.append(form); form.submit();
    }
    document.addEventListener('click',event=>{
        const a=event.target.closest('a[href]');if(!a)return;
        const url=new URL(a.href,location.href);
        if(url.origin!==location.origin||url.pathname!==location.pathname||!url.searchParams.has('cmd'))return;
        event.preventDefault();event.stopImmediatePropagation();
        if (!confirmed(url.searchParams,a.dataset.confirm)) return;
        submit(url.searchParams);
    },true);
    document.addEventListener('submit',event=>{
        const form=event.target;if(!form.querySelector('[name="cmd"]'))return;
        event.preventDefault();event.stopImmediatePropagation();
        const data=new FormData(form);
        if(event.submitter?.name)data.set(event.submitter.name,event.submitter.value);
        if (!confirmed(data,form.dataset.confirm)) return;
        submit(new URLSearchParams(data));
    },true);
})();
