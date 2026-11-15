(() => {
    'use strict';
    const dialog = document.createElement('dialog');
    dialog.className = 'berserk-dialog';
    dialog.setAttribute('aria-label', 'Berserk');
    const message = document.createElement('p'), actions = document.createElement('div');
    const cancel = document.createElement('button'), ok = document.createElement('button');
    cancel.type = ok.type = 'button';
    cancel.className = ok.className = 'button';
    actions.append(cancel, ok); dialog.append(message, actions); document.body.append(dialog);
    const queue = [], seen = new Set();
    let active = null;
    let leaving = false;
    function pump() {
        if (leaving || active || !queue.length || document.querySelector('dialog[open]')) return;
        active = queue.shift(); active.focus = document.activeElement;
        message.textContent = active.message;
        cancel.textContent = 'Отмена'; cancel.hidden = active.kind !== 'confirm';
        ok.textContent = active.kind === 'confirm' ? 'Подтвердить' : 'ОК';
        dialog.showModal(); (cancel.hidden ? ok : cancel).focus();
    }
    function finish(value) {
        if (!active) return;
        const item = active; active = null;
        if (dialog.open) dialog.close();
        if (item.focus?.isConnected) item.focus.focus({preventScroll:true});
        item.resolve(item.kind === 'confirm' ? value : undefined);
        queueMicrotask(pump);
    }
    cancel.onclick = () => finish(false); ok.onclick = () => finish(true);
    dialog.addEventListener('cancel', e => {e.preventDefault(); finish(false);});
    dialog.addEventListener('close', () => {if (active && !dialog.open) finish(false);});
    document.addEventListener('close', pump, true);
    function open(kind, text) {
        const message = String(text), key = kind + ':' + message;
        if (active?.key === key || queue.some(item => item.key === key)) return Promise.resolve(kind === 'confirm' ? false : undefined);
        return new Promise(resolve => {queue.push({kind,message,key,resolve}); pump();});
    }
    window.BerserkDialogs = {confirm: text => open('confirm', text), alert: text => open('alert', text)};
    function errors() {
        if (!document.body.matches('.deal-page,.place-page,.draft-page')) return;
        document.querySelectorAll('.flash').forEach(node => {
            const text = node.textContent.trim();
            if (!/^Ошибка:/.test(text) || seen.has(text)) return;
            seen.add(text); window.BerserkDialogs.alert(text);
        });
    }
    new MutationObserver(errors).observe(document.querySelector('.screen') || document.body, {childList:true,subtree:true,characterData:true});
    errors();
    window.addEventListener('pagehide', () => {leaving = true;queue.splice(0).forEach(item=>item.resolve(false)); finish(false);});
})();
