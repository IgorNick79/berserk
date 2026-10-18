(function () {
    "use strict";
    var root = document.querySelector('.pregame-layout');
    if (!root || root.dataset.hintsReady) return;
    root.dataset.hintsReady = 'true';
    // A single viewport tooltip avoids clipping inside the scrolling InfoPanel.
    var hintNode = document.createElement('div');
    hintNode.id = 'pregame-stat-tooltip';
    hintNode.className = 'pregame-stat-tooltip';
    hintNode.setAttribute('role', 'tooltip');
    hintNode.hidden = true;
    document.body.appendChild(hintNode);
    var hintSource = null, hintPinned = false, hintSuppressed = null;
    function hideHint() {
        if (hintSource) {
            hintSource.removeAttribute('aria-describedby');
            hintSource.setAttribute('aria-expanded', 'false');
            hintSource.classList.remove('is-hint-open');
        }
        hintNode.hidden = true;
        hintNode.innerHTML = '';
        hintSource = null;
        hintPinned = false;
    }
    function positionHint() {
        if (!hintSource || !hintSource.isConnected) { hideHint(); return; }
        var card = hintSource.closest('.pregame-card-info');
        var media = card && card.querySelector('.pregame-card-media');
        if (!media) { hideHint(); return; }
        var area = media.getBoundingClientRect();
        hintNode.style.width = Math.min(area.width - 8, window.innerWidth - 8) + 'px';
        hintNode.style.maxHeight = Math.max(40, Math.min(area.height - 8, window.innerHeight - 16)) + 'px';
        var box = hintNode.getBoundingClientRect();
        var top = hintSource.closest('.pregame-card-heading-icons') ? area.top + 4 : area.bottom - box.height - 4;
        hintNode.style.left = Math.max(0, Math.min(area.left + 4, window.innerWidth - box.width - 4)) + 'px';
        hintNode.style.top = Math.max(8, Math.min(top, window.innerHeight - box.height - 8)) + 'px';
    }
    function showHint(source) {
        var content = source.querySelector('[data-pregame-hint]');
        if (!content) return;
        hideHint();
        hintSource = source;
        hintNode.innerHTML = content.innerHTML;
        hintNode.hidden = false;
        source.setAttribute('aria-describedby', hintNode.id);
        source.setAttribute('aria-expanded', 'true');
        source.classList.add('is-hint-open');
        positionHint();
    }
    function statHint(target) { return target && target.closest ? target.closest('.pregame-hint') : null; }
    root.addEventListener('mouseover', function (event) {
        var source = statHint(event.target);
        if (!hintPinned && source && source !== hintSuppressed && source !== hintSource) showHint(source);
    });
    root.addEventListener('mouseout', function (event) {
        var source = statHint(event.target);
        if (source && !source.contains(event.relatedTarget)) {
            if (hintSuppressed === source) hintSuppressed = null;
            if (!hintPinned && !hintNode.contains(event.relatedTarget)) hideHint();
        }
    });
    root.addEventListener('focusin', function (event) {
        var source = statHint(event.target);
        if (!hintPinned && source && source !== hintSuppressed) showHint(source);
    });
    root.addEventListener('focusout', function (event) {
        if (!hintPinned && statHint(event.target)) hideHint();
    });
    hintNode.addEventListener('mouseleave', function () { if (!hintPinned) hideHint(); });
    document.addEventListener('click', function (event) {
        var source = statHint(event.target);
        if (source && !root.contains(source)) source = null;
        if (hintPinned) {
            hintSuppressed = source;
            hideHint();
            if (source) { event.preventDefault(); event.stopImmediatePropagation(); }
            return;
        }
        if (source) {
            event.preventDefault(); event.stopImmediatePropagation();
            hintSuppressed = null;
            showHint(source); hintPinned = true;
        } else if (hintSource) hideHint();
    }, true);
    root.addEventListener('keydown', function (event) {
        var source = statHint(event.target);
        if (!source || (event.key !== 'Enter' && event.key !== ' ')) return;
        event.preventDefault(); event.stopPropagation();
        if (hintPinned) { hintSuppressed = source; hideHint(); }
        else { hintSuppressed = null; showHint(source); hintPinned = true; }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && hintSource) { hintSuppressed = hintSource; hideHint(); }
    });
    window.addEventListener('resize', function () { if (hintSource) positionHint(); });
    document.addEventListener('scroll', function () { if (hintSource) positionHint(); }, true);
    new MutationObserver(function () { if (hintSource && !hintSource.isConnected) hideHint(); })
        .observe(root, {childList: true, subtree: true});
})();
