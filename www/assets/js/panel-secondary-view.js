/* Shared InfoPanel secondary surface. No commands, polling or game state. */
window.BerserkPanelDock = {
    open: function (root, dock, previous, panel) {
        if (previous && previous !== panel) previous.hidden = true;
        dock.hidden = !panel;
        if (panel) { dock.appendChild(panel); panel.hidden = false; }
        root.querySelectorAll('[data-chat-toggle],[data-action="battle-log"]').forEach(function (button) {
            button.setAttribute('aria-expanded', String(!!panel && panel.id === (button.hasAttribute('data-chat-toggle') ? 'game-chat-panel' : 'battle-journal')));
        });
        return panel;
    },
    mount: function (content, dock, panel) {
        if (dock.parentNode !== content) content.appendChild(dock);
        Array.from(content.children).forEach(function (child) {
            if (child === dock) return;
            if (panel) { if (!child.hasAttribute('data-secondary-hidden')) child.dataset.secondaryHidden = String(child.hidden); child.hidden = true; }
            else if (child.hasAttribute('data-secondary-hidden')) { child.hidden = child.dataset.secondaryHidden === 'true'; delete child.dataset.secondaryHidden; }
        });
    }
};
