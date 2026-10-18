(function () {
    "use strict";
    var view = document.querySelector('[data-view-pager]');
    if (!view) return;
    var main = view.closest('.pregame-main');
    var navigation = view.querySelector('[data-view-pagination]');
    var heading = view.querySelector('[data-view-heading]');
    var groups = Array.from(view.querySelectorAll('[data-view-group]')).map(function (node) {
        return {node: node, label: node.dataset.viewLabel, cards: Array.from(node.querySelectorAll('.prepare-card-link'))};
    });
    var selected = view.querySelector('.prepare-card--selected');
    var anchor = selected ? selected.closest('.prepare-card-link') : null;
    var pages = [], current = 0;
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
            group.cards.forEach(function (card) { card.hidden = !page || page.cards.indexOf(card) === -1; });
        });
        if (page) {
            anchor = page.cards.indexOf(anchor) === -1 ? page.cards[0] : anchor;
            heading.textContent = page.group.label + ' · ' + (page.number + 1) + ' / ' + page.count;
        }
        Array.from(navigation.children).forEach(function (button, position) {
            if (position === index) button.setAttribute('aria-current', 'page');
            else button.removeAttribute('aria-current');
        });
        if (focusPage && navigation.children[index]) navigation.children[index].focus();
    }
    function build(size) {
        pages = [];
        groups.forEach(function (group) {
            var count = Math.ceil(group.cards.length / size);
            for (var number = 0; number < count; number++) {
                pages.push({group: group, number: number, count: count, cards: group.cards.slice(number * size, (number + 1) * size)});
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
        groups.forEach(function (group) {
            group.node.hidden = false;
            group.cards.forEach(function (card) { card.hidden = false; });
        });
        var width = view.querySelector('.pregame-view-pages').clientWidth;
        var columns = Math.max(1, Math.min(3, Math.floor((width + 12) / 172)));
        view.style.setProperty('--view-columns', columns);
        view.style.setProperty('--view-card-height', 'auto');
        var cardHeight = 144;
        groups.forEach(function (group) {
            group.cards.forEach(function (card) { cardHeight = Math.max(cardHeight, card.querySelector('.prepare-card').scrollHeight + 2); });
        });
        var mainStyle = getComputedStyle(main), viewStyle = getComputedStyle(view);
        var budget = main.clientHeight - parseFloat(mainStyle.paddingTop) - parseFloat(mainStyle.paddingBottom)
            - parseFloat(viewStyle.paddingTop) - parseFloat(viewStyle.paddingBottom) - 2
            - outerHeight(heading) - outerHeight(view.querySelector('.flash'))
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
    }
    navigation.addEventListener('keydown', function (event) {
        if (!pages.length || ['ArrowLeft', 'ArrowRight', 'Home', 'End'].indexOf(event.key) === -1) return;
        event.preventDefault();
        var next = event.key === 'Home' ? 0 : event.key === 'End' ? pages.length - 1
            : (current + (event.key === 'ArrowRight' ? 1 : -1) + pages.length) % pages.length;
        show(next, true);
    });
    var scheduled = false;
    new ResizeObserver(function () {
        if (scheduled) return;
        scheduled = true;
        requestAnimationFrame(function () { scheduled = false; layout(); });
    }).observe(main);
    layout();
})();
