(function () {
    'use strict';
    var root = document.querySelector('[data-battle-sync-root], [data-card-popup-root]');
    if (!root || root.dataset.cardPopupReady) return;
    root.dataset.cardPopupReady = 'true';
    var dialog = document.createElement('dialog');
    dialog.className = 'card-image-popup';
    dialog.setAttribute('aria-label', 'Оригинал карты');
    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'card-image-popup-close';
    close.setAttribute('aria-label', 'Закрыть изображение');
    close.textContent = '×';
    var image = document.createElement('img');
    dialog.append(close, image);
    document.body.appendChild(dialog);
    var trigger = null;
    function dismiss() { if (dialog.open) dialog.close(); }
    close.addEventListener('click', dismiss);
    dialog.addEventListener('click', function (event) {
        event.stopPropagation();
        if (event.target === dialog) dismiss();
    });
    dialog.addEventListener('close', function () {
        image.removeAttribute('src');
        if (trigger && trigger.isConnected) trigger.focus({preventScroll: true});
        trigger = null;
    });
    // Capture before game/AJAX handlers; a preview never issues a command.
    root.addEventListener('click', function (event) {
        var button = event.target.closest('[data-card-original]');
        if (!button || !root.contains(button)) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        trigger = button;
        image.src = button.dataset.cardOriginal;
        image.alt = button.querySelector('img').alt;
        dialog.showModal();
        close.focus();
    }, true);
    // AJAX may replace the selected card while the original is open.
    new MutationObserver(function () {
        if (dialog.open && trigger && !trigger.isConnected) dismiss();
    }).observe(root, {childList: true, subtree: true});
    // Native dialog supplies Escape dismissal, focus containment and top layer.
})();
