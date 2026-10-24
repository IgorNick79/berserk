(function () {
    'use strict';
    if (window.BerserkBattleDrag) return;
    window.BerserkBattleDrag = { create: function (root, bridge) {
        var gesture = null, ghost = null, target = null, suppression = null, swallow = false;
        var blocked = false, barrier = false, accepting = false;
        var renderedVersion = bridge.state().version;
        var cardSelector = '[data-drag-card]';
        function metadata() { return root.querySelector('[data-battle-drag-state]'); }
        function available() {
            var s = bridge.state(), m = metadata();
            return !blocked && !barrier && !s.busy && s.version === renderedVersion && s.mode === 'strike'
                && !root.hasAttribute('data-match-paused') && m && m.dataset.dragEnabled === '1';
        }
        function refresh() { root.toggleAttribute('data-battle-drag-ready', !!available()); }
        function cardAt(node) { return node && node.closest ? node.closest(cardSelector) : null; }
        function source(id) { return root.querySelector('[data-drag-card="' + id + '"]'); }
        function clearTarget() { if (target) target.classList.remove('battle-drag-target'); target = null; }
        function cancel() {
            if (gesture && gesture.active) swallow = true;
            var pointer = gesture && gesture.pointer;
            gesture = null;
            accepting = false;
            if (ghost) ghost.remove();
            ghost = null;
            clearTarget();
            document.documentElement.removeAttribute('data-battle-dragging');
            if (pointer !== null && pointer !== undefined && root.hasPointerCapture(pointer)) root.releasePointerCapture(pointer);
            refresh();
        }
        function validGesture() {
            if (!gesture || !available()) return false;
            var s = bridge.state();
            return s.version === gesture.version && s.revision === gesture.revision
                && s.selected === gesture.id;
        }
        function destination(x, y) {
            if (!gesture || !gesture.ready || !validGesture()) return null;
            var node = document.elementFromPoint(x, y);
            if (!node || !root.contains(node)) return null;
            var card = cardAt(node), area, link;
            if (card) {
                if (card.dataset.dragOwner !== 'enemy') return null;
                link = card.closest('a.card-link[href]');
                area = card;
            } else {
                // Empty flying slots have no move link today. Do not invent one.
                area = node.closest('.field-cell, .fly-slot');
                if (!area || area.querySelector(cardSelector)) return null;
                link = area.querySelector('a.cell-link[href]');
            }
            if (!link || link.hasAttribute('data-friendly-attack-confirm')) return null;
            var url = new URL(link.href, location.href), p = url.searchParams;
            if (url.origin !== location.origin || url.pathname !== location.pathname
                || p.has('ajax') || p.get('card_id') !== String(gesture.id)) return null;
            if (card) {
                if (p.get('cmd') !== 'strike' || p.get('target_id') !== card.dataset.dragCard
                    || (p.has('mode') && p.get('mode') !== 'strike')) return null;
            } else if (p.get('cmd') !== 'move') return null;
            return { url: url, area: area };
        }
        function paint(x, y) {
            if (!ghost) return;
            ghost.style.left = (x - gesture.offsetX) + 'px';
            ghost.style.top = (y - gesture.offsetY) + 'px';
            clearTarget();
            var hit = destination(x, y);
            if (hit) { target = hit.area; target.classList.add('battle-drag-target'); }
        }
        document.addEventListener('pointerdown', function () {
            if (!gesture) { suppression = null; swallow = false; }
        }, true);
        root.addEventListener('pointerdown', function (e) {
            // A new physical press is an independent click, never the tail of an old drag.
            if (gesture) cancel();
            suppression = null;
            swallow = false;
            if (e.pointerType !== 'mouse' || e.button !== 0 || !e.isPrimary
                || e.ctrlKey || e.shiftKey || e.altKey || e.metaKey || !available()) return;
            if (e.target.closest('.mini-indicator,[data-mini-hint],button,input,select,textarea,[role="button"]')) return;
            var card = cardAt(e.target);
            if (!card || card.dataset.dragSource !== '1' || card.dataset.dragOwner !== 'own'
                || !card.closest('.battle-field,.fly-zone-side')) return;
            gesture = { id: Number(card.dataset.dragCard), card: card, pointer: e.pointerId,
                x: e.clientX, y: e.clientY, active: false };
        });
        document.addEventListener('pointermove', function (e) {
            if (!gesture || e.pointerId !== gesture.pointer) return;
            if (!e.buttons) { cancel(); return; }
            if (!gesture.active) {
                if (Math.hypot(e.clientX - gesture.x, e.clientY - gesture.y) < 5) return;
                if (!available() || !gesture.card.isConnected) { cancel(); return; }
                var s = bridge.state(), r = gesture.card.getBoundingClientRect();
                gesture.active = true;
                gesture.version = s.version;
                gesture.revision = s.revision;
                gesture.offsetX = gesture.x - r.left;
                gesture.offsetY = gesture.y - r.top;
                document.documentElement.setAttribute('data-battle-dragging', '');
                document.dispatchEvent(new Event('berserk:battle-drag-start'));
                ghost = document.createElement('div');
                ghost.className = 'battle-drag-ghost';
                ghost.setAttribute('aria-hidden', 'true');
                ghost.inert = true;
                ghost.style.width = r.width + 'px';
                ghost.style.height = r.height + 'px';
                var copy = gesture.card.cloneNode(true);
                copy.classList.remove('mini-zoom-source');
                copy.removeAttribute('id');
                copy.removeAttribute('data-mini-card');
                copy.removeAttribute('data-mini-name');
                copy.removeAttribute('data-drag-card');
                copy.querySelectorAll('[id],[tabindex]').forEach(function (n) { n.removeAttribute('id'); n.removeAttribute('tabindex'); });
                ghost.append(copy);
                document.body.append(ghost);
                root.setPointerCapture(e.pointerId);
                var m = metadata();
                gesture.ready = s.selected === gesture.id && Number(m.dataset.dragSelected) === gesture.id;
                if (!gesture.ready) {
                    gesture.waiting = true;
                    gesture.revision = bridge.select(gesture.id);
                }
            }
            e.preventDefault();
            if (!validGesture()) { cancel(); return; }
            gesture.lastX = e.clientX;
            gesture.lastY = e.clientY;
            paint(e.clientX, e.clientY);
        }, { capture: true, passive: false });
        document.addEventListener('pointerup', function (e) {
            if (gesture && e.pointerId !== gesture.pointer) return;
            var active = gesture && gesture.active;
            var hit = active ? destination(e.clientX, e.clientY) : null;
            if (active || swallow) {
                suppression = { pointer: e.pointerId, x: e.clientX, y: e.clientY, until: performance.now() + 800 };
                e.preventDefault();
            }
            cancel();
            swallow = false;
            if (hit) bridge.submit(hit.url);
        }, true);
        document.addEventListener('click', function (e) {
            if (!suppression || !e.detail || performance.now() > suppression.until) return;
            var samePointer = typeof e.pointerId === 'number' && e.pointerId === suppression.pointer;
            if (samePointer || Math.hypot(e.clientX - suppression.x, e.clientY - suppression.y) < 8) {
                suppression = null;
                e.preventDefault();
                e.stopImmediatePropagation();
            }
        }, true);
        root.addEventListener('dragstart', function (e) {
            var link = e.target.closest('.card-link');
            if (cardAt(e.target) || link && link.querySelector(cardSelector)) e.preventDefault();
        });
        root.addEventListener('lostpointercapture', function () { if (gesture) cancel(); });
        document.addEventListener('pointercancel', cancel);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') cancel(); }, true);
        window.addEventListener('blur', cancel);
        window.addEventListener('pagehide', cancel);
        document.addEventListener('visibilitychange', function () { if (document.hidden) cancel(); });
        window.addEventListener('resize', cancel);
        window.addEventListener('scroll', function (e) { if (root.contains(e.target) || e.target === document) cancel(); }, true);
        // Unexpected field replacement outside battle-sync is also a cancellation boundary.
        new MutationObserver(function () {
            if (gesture && !gesture.card.isConnected) cancel();
        }).observe(root, { childList: true, subtree: true });
        refresh();
        return {
            cancel: cancel,
            refresh: refresh,
            observe: function (data) {
                if (!data) return;
                if (data.match_pause) barrier = data.match_pause.mode !== 'idle';
                if (Object.prototype.hasOwnProperty.call(data, 'turn_timer')) {
                    barrier = !!(data.match_pause && data.match_pause.mode !== 'idle')
                        || !!(data.turn_timer && data.turn_timer.status === 'expired');
                }
                var version = Number(data.sync_version);
                if (Number.isFinite(version) && version !== renderedVersion) blocked = true;
                if (gesture && gesture.active && Number.isFinite(version) && version !== gesture.version) {
                    blocked = true;
                    cancel();
                }
                if (barrier && gesture) cancel();
                refresh();
            },
            beforeSnapshot: function (data, revision) {
                accepting = false;
                if (!gesture) return;
                accepting = !!(gesture.active && gesture.waiting && revision === gesture.revision
                    && Number(data.sync_version) === gesture.version && data.ui
                    && Number(data.ui.sel) === gesture.id && data.ui.mode === 'strike');
                if (!accepting) cancel();
            },
            afterSnapshot: function () {
                renderedVersion = bridge.state().version;
                blocked = false;
                if (accepting && gesture) {
                    gesture.card = source(gesture.id);
                    gesture.waiting = false;
                    gesture.ready = true;
                    if (!gesture.card || !validGesture()) cancel();
                    else paint(gesture.lastX, gesture.lastY);
                }
                accepting = false;
                refresh();
            }
        };
    }};
    window.dispatchEvent(new Event('berserk:battle-drag-ready'));
})();
