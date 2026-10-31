(window.berserkInitViewPager = function () {
    "use strict";
    var view = document.querySelector('[data-view-pager]');
    if (!view || view.dataset.pagerReady) return;
    view.dataset.pagerReady = "1";
    var isDeal = view.hasAttribute('data-deal-pager');
    var main = view.closest('.pregame-main');
    var navigation = view.querySelector('[data-view-pagination]');
    var root = view.closest('.pregame-layout');
    var empty = view.querySelector('[data-view-empty]');
    var groups = Array.from(view.querySelectorAll('[data-view-group]')).map(function (node) {
        return {node: node, label: node.dataset.viewLabel, cards: Array.from(node.querySelectorAll('.prepare-card-link'))};
    });
    var selected = view.querySelector('.prepare-card--selected');
    var anchor = selected ? selected.closest('.prepare-card-link') : null;
    var params = new URLSearchParams(window.location.search);
    var squadOnly = isDeal && params.get('deal_selected') === '1';
    var activeZone = !isDeal && params.get('view_zone') === 'sideboard' ? 'sideboard'
        : anchor ? anchor.closest('[data-view-group]').dataset.viewGroup : 'deck';
    var filters = {element: params.get('view_element') || '', crystal: params.get('view_crystal') || ''};
    ['element', 'crystal'].forEach(function (type) {
        if (!root.querySelector('[data-view-filter="' + type + ':' + filters[type].replace(/[^a-z]/g, '') + '"]')) filters[type] = '';
    });
    if (document.body.classList.contains('view-deck-page') && !groups.some(function (g) { return g.node.dataset.viewGroup === activeZone && g.cards.length; })) activeZone='deck';
    var pages = [], current = 0;
    function matches(card) {
        return (!squadOnly || card.classList.contains('deal-in-squad')) && (!filters.element || card.classList.contains('view-element-' + filters.element))
            && (!filters.crystal || card.classList.contains('view-crystal-' + filters.crystal));
    }
    function syncControls() {
        if (document.body.classList.contains('view-deck-page')) {
            var address=new URL(location.href);address.searchParams.set('view_zone',activeZone);
            ['element','crystal'].forEach(function(type){if(filters[type])address.searchParams.set('view_'+type,filters[type]);else address.searchParams.delete('view_'+type);});
            history.replaceState(null,'',address);
        }
        var group = groups.find(function (entry) { return entry.node.dataset.viewGroup === activeZone; });
        var cards = group ? group.cards : [];
        root.querySelectorAll('[data-view-zone]').forEach(function (button) {
            button.setAttribute('aria-pressed', String(button.dataset.viewZone === activeZone));
        });
        root.querySelectorAll('[data-deal-selected]').forEach(function (button) { button.setAttribute('aria-pressed', String(squadOnly)); });
        root.querySelectorAll('[data-view-filter]').forEach(function (button) {
            var filter = button.dataset.viewFilter.split(':');
            if (button.hasAttribute('data-view-stat-static')) {
                button.setAttribute('aria-pressed', String(filters[filter[0]] === filter[1]));
                return;
            }
            var count = cards.reduce(function (sum, card) {
                if (!card.classList.contains('view-' + filter[0] + '-' + filter[1])) return sum;
                var amount = card.querySelector('.prepare-card__count');
                return sum + (amount ? Number(amount.textContent.replace(/[^0-9]/g, '')) : 1);
            }, 0);
            var hint = button.dataset.viewStatLabel + ': ' + count;
            button.querySelector('span').textContent = count;
            button.querySelector('template').content.textContent = hint;
            button.setAttribute('aria-label', hint);
            button.setAttribute('aria-pressed', String(filters[filter[0]] === filter[1]));
        });
        groups.forEach(function (entry) {
            entry.cards.forEach(function (card) {
                var url = new URL(card.href);
                url.searchParams.set('view_zone', entry.node.dataset.viewGroup);
                ['element', 'crystal'].forEach(function (type) {
                    if (filters[type]) url.searchParams.set('view_' + type, filters[type]);
                    else url.searchParams.delete('view_' + type);
                });
                if (isDeal && squadOnly) url.searchParams.set('deal_selected', '1');
                else url.searchParams.delete('deal_selected');
                card.href = url.href;
            });
        });
        root.querySelectorAll('[data-deal-actions] a, [data-deal-quick]').forEach(function (link) {
            var url = new URL(link.href);
            ['element', 'crystal'].forEach(function (type) {
                if (filters[type]) url.searchParams.set('view_' + type, filters[type]);
                else url.searchParams.delete('view_' + type);
            });
            if (squadOnly) url.searchParams.set('deal_selected', '1');
            else url.searchParams.delete('deal_selected');
            link.href = url.href;
        });
        var actions = view.querySelector('.pregame-view-actions');
        if (actions) actions.style.visibility = selected && group && group.cards.indexOf(selected.closest('.prepare-card-link')) !== -1
            && matches(selected.closest('.prepare-card-link')) ? 'visible' : 'hidden';
    }
    function outerHeight(node) {
        if (!node || !node.getClientRects().length) return 0;
        var style = getComputedStyle(node);
        return node.getBoundingClientRect().height + parseFloat(style.marginTop) + parseFloat(style.marginBottom);
    }
    function show(index, focusPage) {
        current = index;
        var page = pages[index];
        groups.forEach(function (group) {
            group.node.hidden = !page || group !== page.group;
            group.cards.forEach(function (card) {
                card.hidden = !page || page.cards.indexOf(card) === -1;
                if (isDeal) card.closest('.pregame-deal-tile').hidden = card.hidden;
            });
        });
        if (page) {
            anchor = page.cards.indexOf(anchor) === -1 ? page.cards[0] : anchor;

        }
        empty.hidden = !!page;
        Array.from(navigation.children).forEach(function (button, position) {
            if (position === index) button.setAttribute('aria-current', 'page');
            else button.removeAttribute('aria-current');
        });
        if (focusPage && navigation.children[index]) navigation.children[index].focus();
    }
    function build(size) {
        pages = [];
        groups.forEach(function (group) {
            if (group.node.dataset.viewGroup !== activeZone) return;
            var filtered = group.cards.filter(matches);
            var count = Math.ceil(filtered.length / size);
            for (var number = 0; number < count; number++) {
                pages.push({group: group, number: number, count: count, cards: filtered.slice(number * size, (number + 1) * size)});
            }
        });
        navigation.replaceChildren();
        pages.forEach(function (page, index) {
            var button = document.createElement('button');
            button.type = 'button';
            button.setAttribute('aria-label', page.group.label + ', страница ' + (page.number + 1) + ' из ' + page.count);
            var dot = document.createElement('span');
            dot.setAttribute('aria-hidden', 'true');
            button.appendChild(dot);
            button.addEventListener('click', function () { show(index, false); });
            navigation.appendChild(button);
        });
    }
    function layout() {
        if (!isDeal && document.body.classList.contains('view-deck-page')) {
            navigation.remove();
            var address=new URL(location.href);address.searchParams.delete('view_page');history.replaceState(null,'',address);
            pages=[];
            var group=groups.find(function(entry){return entry.node.dataset.viewGroup===activeZone;});
            if(group){var visible=group.cards.filter(matches);if(visible.length)pages=[{group:group,cards:visible}];}
            show(0,false);syncControls();return;
        }
        groups.forEach(function (group) {
            group.node.hidden = false;
            group.cards.forEach(function (card) {
                card.hidden = false;
                if (isDeal) card.closest('.pregame-deal-tile').hidden = false;
            });
        });
        var width = view.querySelector('.pregame-view-pages').clientWidth;
        var columns = Math.max(1, Math.min(3, Math.floor((width + 12) / 172)));
        view.style.setProperty('--view-columns', columns);
        view.style.setProperty('--view-card-height', 'auto');
        var cardHeight = 144;
        groups.forEach(function (group) {
            group.cards.forEach(function (card) { cardHeight = Math.max(cardHeight, Math.max(144, card.querySelector('.prepare-card').scrollHeight + 2) + (isDeal ? 48 : 0)); });
        });
        var mainStyle = getComputedStyle(main), viewStyle = getComputedStyle(view);
        var budget = main.clientHeight - parseFloat(mainStyle.paddingTop) - parseFloat(mainStyle.paddingBottom)
            - parseFloat(viewStyle.paddingTop) - parseFloat(viewStyle.paddingBottom) - 2
            - outerHeight(view.querySelector('.flash'))
            - outerHeight(view.querySelector('.pregame-view-actions')) - 14;
        var rows = Math.max(1, Math.min(3, Math.floor((budget - 24 + 12) / (cardHeight + 12))));
        for (var attempt = 0; attempt < 3; attempt++) {
            build(columns * rows);
            var fit = Math.max(1, Math.min(3, Math.floor((budget - navigation.clientHeight + 12) / (cardHeight + 12))));
            if (fit === rows) break;
            rows = fit;
        }
        view.style.setProperty('--view-rows', rows);
        view.style.setProperty('--view-card-height', cardHeight + 'px');
        var selectedPage = pages.findIndex(function (page) { return page.cards.indexOf(anchor) !== -1; });
        show(Math.max(0, selectedPage), false);
        syncControls();
    }
    navigation.addEventListener('keydown', function (event) {
        if (!pages.length || ['ArrowLeft', 'ArrowRight', 'Home', 'End'].indexOf(event.key) === -1) return;
        event.preventDefault();
        var next = event.key === 'Home' ? 0 : event.key === 'End' ? pages.length - 1
            : (current + (event.key === 'ArrowRight' ? 1 : -1) + pages.length) % pages.length;
        show(next, true);
    });
    root.addEventListener('click', function (event) {
        var zone = event.target.closest('[data-view-zone]');
        var filter = event.target.closest('[data-view-filter]');
        var selectedControl = event.target.closest('[data-deal-selected]');
        if (!zone && !filter && !selectedControl) return;
        if (selectedControl) {
            squadOnly = !squadOnly;
            filters.element = '';
            filters.crystal = '';
        } else if (zone) {
            activeZone = zone.dataset.viewZone;
            filters.element = '';
            filters.crystal = '';
        } else {
            var value = filter.dataset.viewFilter.split(':');
            filters[value[0]] = filters[value[0]] === value[1] ? '' : value[1];
        }
        anchor = null;
        layout();
    });
    var scheduled = false;
    var observer = new ResizeObserver(function () {
        if (!main.isConnected) { observer.disconnect(); return; }
        if (scheduled) return;
        scheduled = true;
        requestAnimationFrame(function () { scheduled = false; layout(); });
    });
    observer.observe(main);
    layout();
})();
