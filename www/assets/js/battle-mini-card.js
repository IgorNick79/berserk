(function () {
    'use strict';
    if (document.documentElement.dataset.miniCardsReady) return;
    document.documentElement.dataset.miniCardsReady = '1';

    var tip = null, trigger = null, describedNode = null, kind = '', pinned = false;
    var hoverCard = null, zoom = null, timer = 0, hoverReady = false;
    var nameTimer = 0, fadeTimer = 0, dismissedName = null;
    var pointerX = -1, pointerY = -1, reconcileFrame = 0;
    var lastMoveX = -1, lastMoveY = -1;
    var hover = matchMedia('(hover:hover) and (pointer:fine)');
    var reduced = matchMedia('(prefers-reduced-motion:reduce)');

    function cardAt(node) {
        if (!node || !node.closest) return null;
        return node.closest('[data-mini-card]') ||
            (node.matches('.card-link') ? node.querySelector('[data-mini-card]') : null);
    }
    function tooltip() {
        if (tip) return tip;
        tip = document.createElement('div');
        tip.className = 'battle-mini-tooltip';
        tip.id = 'battle-mini-tooltip';
        tip.setAttribute('role', 'tooltip');
        tip.hidden = true;
        document.body.append(tip);
        tip.addEventListener('pointerleave', function (event) {
            if (event.pointerType !== 'touch' && !cardAt(event.relatedTarget)) { dismissedName = null; closeHint(); stopZoom(false); }
        });
        return tip;
    }
    function clearDescription() {
        if (trigger && kind === 'indicator') trigger.setAttribute('aria-expanded', 'false');
        if (describedNode) describedNode.removeAttribute('aria-describedby');
        describedNode = null;
    }
    function closeHint() {
        clearTimeout(nameTimer);
        clearTimeout(fadeTimer);
        if (tip) tip.classList.remove('is-fading', 'is-entering');
        clearDescription();
        trigger = null;
        kind = '';
        pinned = false;
        if (tip) tip.hidden = true;
    }
    function placeHint(node, hintKind) {
        var card = cardAt(node) || node;
        var r = card.getBoundingClientRect();
        tip.style.left = Math.max(8, Math.min(r.left + r.width / 2 - tip.offsetWidth / 2, innerWidth - tip.offsetWidth - 8)) + 'px';
        var top = hintKind === 'name' ? r.bottom + 8 : r.top + r.height / 2 - tip.offsetHeight / 2;
        var indicators = card.querySelector('.mini-indicators');
        if (hintKind === 'indicator' && indicators) top = Math.max(top, indicators.getBoundingClientRect().bottom + 4);
        tip.style.top = Math.max(8, Math.min(top, innerHeight - tip.offsetHeight - 8)) + 'px';
    }
    function show(node, hintKind) {
        if (!node || !node.isConnected) return;
        var text = hintKind === 'name' ? node.dataset.miniName : node.querySelector('.mini-hint-text');
        if (!text) { closeHint(); return; }
        if (trigger === node && kind === hintKind && tip && !tip.hidden) return;
        clearTimeout(nameTimer);
        clearTimeout(fadeTimer);
        clearDescription();
        var t = tooltip();
        t.classList.remove('is-fading');
        trigger = node;
        kind = hintKind;
        t.dataset.kind = hintKind;
        if (hintKind === 'name') {
            var plate = document.createElement('span');
            plate.className = 'mini-nameplate';
            ['left', 'text', 'right'].forEach(function (part) {
                var span = document.createElement('span');
                span.className = 'mini-nameplate__' + part;
                if (part === 'text') span.textContent = text;
                else span.setAttribute('aria-hidden', 'true');
                plate.append(span);
            });
            t.replaceChildren(plate);
        }
        else {
            t.replaceChildren(text.cloneNode(true));
            t.firstChild.hidden = false;
            node.setAttribute('aria-expanded', 'true');
        }
        describedNode = hintKind === 'name' ? (node.closest('.card-link') || node) : node;
        describedNode.setAttribute('aria-describedby', t.id);
        t.hidden = false;
        placeHint(node, hintKind);
        if (hintKind === 'name' && hover.matches && !reduced.matches) {
            t.classList.add('is-entering');
            void t.offsetWidth;
            t.classList.remove('is-entering');
        }
        if (hintKind === 'name' && !(node.closest('.card-link') || node).matches(':focus-visible')) {
            nameTimer = setTimeout(function () {
                if (trigger !== node || kind !== 'name') return;
                dismissedName = node;
                t.classList.add('is-fading');
                fadeTimer = setTimeout(closeHint, reduced.matches ? 0 : 250);
            }, 1500);
        }
    }
    function contextHint(node) {
        if (pinned || !hoverReady) return;
        if (tip && tip.contains(node)) return;
        var indicator = node.closest ? node.closest('[data-mini-hint]') : null;
        var card = cardAt(node);
        if (indicator) show(indicator, 'indicator');
        else if (card && !card.classList.contains('mini-hidden')) { if (dismissedName !== card && (reduced.matches || !hover.matches || zoom && hoverCard === card)) show(card, 'name'); }
        else closeHint();
    }
    function stopZoom(immediate) {
        clearTimeout(timer);
        timer = 0;
        if (hoverCard) hoverCard.classList.remove('mini-zoom-source');
        hoverCard = null;
        if (immediate) document.querySelectorAll('.mini-zoom-source').forEach(function (node) { node.classList.remove('mini-zoom-source'); });
        if (immediate) document.querySelectorAll('.battle-mini-zoom').forEach(function (node) { node.remove(); });
        if (!zoom) return;
        var old = zoom;
        zoom = null;
        old.classList.remove('is-open');
        if (immediate || reduced.matches) old.remove();
        else setTimeout(function () { old.remove(); }, 250);
    }
    function paintZoom(z, card) {
            var r = card.getBoundingClientRect();
            var clone = card.cloneNode(true);
            clone.classList.remove('mini-zoom-source');
            var appearance = getComputedStyle(card);
            clone.style.outline = appearance.outline;
            clone.style.outlineOffset = appearance.outlineOffset;
            clone.style.boxShadow = appearance.boxShadow;
            clone.querySelectorAll('[tabindex]').forEach(function (e) { e.removeAttribute('tabindex'); });
            clone.querySelectorAll('[id]').forEach(function (e) { e.removeAttribute('id'); });
            clone.removeAttribute('data-mini-card');
            clone.removeAttribute('data-mini-name');
            z.replaceChildren(clone);
            // The visual copy never owns hit testing. Keep its 1.25 scale inside the viewport.
            z.style.left = Math.max(13, Math.min(r.left, innerWidth - r.width - 13)) + 'px';
            z.style.top = Math.max(12, Math.min(r.top, innerHeight - r.height - 12)) + 'px';
            z.style.width = r.width + 'px';
            z.style.height = r.height + 'px';
            z.style.opacity = appearance.opacity;
            card.classList.add('mini-zoom-source');
    }
    function startZoom(card) {
        if (!hover.matches || reduced.matches || card.classList.contains('mini-hidden')) return;
        if (hoverCard === card) return;
        stopZoom(true);
        hoverCard = card;
        timer = setTimeout(function () {
            if (!card.isConnected || hoverCard !== card) return;
            var z = document.createElement('div');
            z.className = 'battle-mini-zoom';
            z.setAttribute('aria-hidden', 'true');
            z.inert = true;
            paintZoom(z, card);
            document.body.append(z);
            zoom = z;
            void z.offsetWidth;
            z.classList.add('is-open');
            if (!pinned && hoverReady) contextHint(document.elementFromPoint(pointerX, pointerY) || card);
        }, 200);
    }
    // Capture before battle-sync: only indicator/tooltip clicks are consumed.
    document.addEventListener('click', function (e) {
        var node = e.target.closest('[data-mini-hint]');
        if (node && !node.closest('.battle-mini-zoom')) {
            e.preventDefault();
            e.stopImmediatePropagation();
            if (trigger === node && pinned) closeHint();
            else { pinned = true; show(node, 'indicator'); }
            return;
        }
        if (e.target.closest('.mini-indicator')) {
            e.preventDefault();
            e.stopImmediatePropagation();
            closeHint();
            return;
        }
        if (tip && tip.contains(e.target)) {
            e.preventDefault();
            e.stopImmediatePropagation();
            return;
        }
        closeHint();
    }, true);
    document.addEventListener('keydown', function (e) {
        var node = e.target.closest('[data-mini-hint]');
        if (e.key === 'Escape') { hoverReady = false; closeHint(); stopZoom(true); }
        if (node && (e.key === 'Enter' || e.key === ' ')) {
            e.preventDefault();
            e.stopImmediatePropagation();
            node.click();
        }
    }, true);
    document.addEventListener('pointerover', function (e) {
        if (e.pointerType === 'touch' || !hover.matches) return;
        pointerX = e.clientX; pointerY = e.clientY;
        var card = cardAt(e.target);
        if (hoverCard && !hoverCard.isConnected) { queueReconcile(); return; }
        if (card) startZoom(card);
        contextHint(e.target);
    });
    document.addEventListener('pointermove', function (e) {
        if (e.pointerType === 'touch' || !hover.matches) return;
        pointerX = e.clientX; pointerY = e.clientY;
        if (e.clientX !== lastMoveX || e.clientY !== lastMoveY) hoverReady = true;
        lastMoveX = e.clientX; lastMoveY = e.clientY;
        if (hoverCard && !hoverCard.isConnected) { queueReconcile(); return; }
        contextHint(e.target);
    });
    document.addEventListener('pointerout', function (e) {
        if (e.pointerType === 'touch' || !hover.matches) return;
        var card = cardAt(e.target);
        if (card && !card.isConnected) { queueReconcile(); return; }
        if (card && !card.contains(e.relatedTarget)) {
            if (tip && tip.contains(e.relatedTarget)) return;
            dismissedName = null;
            stopZoom(false);
            if (!(tip && tip.contains(e.relatedTarget))) closeHint();
        } else if (card && e.relatedTarget && !pinned) contextHint(e.relatedTarget);
    });
    document.addEventListener('focusin', function (e) {
        var indicator = e.target.closest('[data-mini-hint]');
        var card = cardAt(e.target);
        if (indicator) show(indicator, 'indicator');
        else if (card && !card.classList.contains('mini-hidden')) { pinned = false; show(card, 'name'); }
    });
    document.addEventListener('focusout', function (e) {
        if (cardAt(e.target) && !cardAt(e.relatedTarget)) closeHint();
    });
    function refresh() {
        dismissedName = null;
        hoverReady = false;
        closeHint();
        stopZoom(true);
    }
    function queueReconcile() {
        if (reconcileFrame) return;
        reconcileFrame = requestAnimationFrame(function () {
            reconcileFrame = 0;
            if (trigger && !trigger.isConnected) { hoverReady = false; closeHint(); }
            if (!hoverCard || hoverCard.isConnected) return;
            var previous = hoverCard;
            var id = previous.getAttribute('data-mini-card');
            var fresh = document.querySelector('[data-mini-card="' + id + '"]');
            var hit = document.elementFromPoint(pointerX, pointerY);
            var rect = fresh && fresh.getBoundingClientRect();
            if (!fresh || fresh.classList.contains('mini-hidden') || !rect.width ||
                pointerX < rect.left || pointerX > rect.right || pointerY < rect.top || pointerY > rect.bottom ||
                (cardAt(hit) !== fresh && !(tip && tip.contains(hit)))) {
                dismissedName = null;
                stopZoom(true);
                return;
            }
            if (dismissedName === previous) dismissedName = fresh;
            if (zoom) {
                hoverCard = fresh;
                paintZoom(zoom, fresh);
            } else {
                hoverCard = null;
                startZoom(fresh);
            }
        });
    }
    document.addEventListener('berserk:battle-updated', queueReconcile);
    window.addEventListener('scroll', function (e) {
        if (tip && tip.contains(e.target)) return;
        refresh();
    }, true);
    window.addEventListener('resize', refresh);
    hover.addEventListener('change', refresh);
    reduced.addEventListener('change', refresh);
    new MutationObserver(function () {
        if ((trigger && !trigger.isConnected) || (hoverCard && !hoverCard.isConnected)) queueReconcile();
    }).observe(document.querySelector('[data-battle-sync-root]') || document.body, {childList:true, subtree:true});
    refresh();
})();
