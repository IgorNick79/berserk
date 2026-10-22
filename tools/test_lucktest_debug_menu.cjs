'use strict';
// No browser or npm dependencies: exercise the real menu controller with absent/present Debug nodes.
const assert = require('assert');
const vm = require('vm');
const source = process.env.BERSERK_MENU_SOURCE
    ? Buffer.from(process.env.BERSERK_MENU_SOURCE, 'base64').toString('utf8')
    : require('fs').readFileSync(require('path').join(__dirname, '../www/assets/js/battle-menu.js'), 'utf8');
let checks = 0;
for (const authorized of [false, true]) {
    const document = {activeElement: null, body: {}, documentElement: {}, addEventListener() {}, removeEventListener() {}};
    function node(attributes = {}) {
        return {dataset: {}, hidden: false, disabled: false, isConnected: true,
            classList: {toggle() {}}, attrs: attributes,
            setAttribute(key, value) { this.attrs[key] = value; }, getAttribute(key) { return this.attrs[key] || null; },
            removeAttribute(key) { delete this.attrs[key]; }, hasAttribute(key) { return key in this.attrs; },
            contains() { return false; }, focus() { document.activeElement = this; }};
    }
    const button = node(), sound = node({'data-menu-sound': ''}), debug = authorized ? node({'data-menu-debug': ''}) : null;
    const saving = node(), soundCheck = node(), menu = node(), overlay = authorized ? node() : null;
    sound.querySelector = () => soundCheck;
    menu.querySelector = selector => ({'[data-menu-sound]': sound, '[data-menu-debug]': debug, '[data-menu-saving]': saving})[selector];
    menu.querySelectorAll = () => debug ? [sound, debug] : [sound];
    if (overlay) overlay.querySelector = () => ({});
    let currentOverlay = overlay;
    const host = {querySelector: () => currentOverlay};
    const root = node(); root.listeners = {};
    root.querySelector = selector => ({'.battle-menu': button, '#battle-menu-panel': menu, "[data-battle-fragment='info_panel_html']": host})[selector];
    root.contains = () => true; root.addEventListener = (name, handler) => { root.listeners[name] = handler; }; root.removeEventListener = () => {};
    const window = {location: {hash: ''}};
    const sandbox = {window, document, URLSearchParams, queueMicrotask, MutationObserver: class { observe() {} disconnect() {} }};
    vm.runInNewContext(source, sandbox);
    let sent;
    const controller = window.BerserkBattleMenu.create(root, payload => { sent = payload; }, () => false, () => true);
    assert(controller); checks++;
    function click(target) { root.listeners.click({target: {closest: () => target}, preventDefault() {}, stopImmediatePropagation() {}}); }
    click(button); assert.strictEqual(menu.hidden, false); checks++;
    click(sound); assert.strictEqual(sent.get('cmd'), 'set_sound_enabled'); assert.strictEqual(menu.hidden, true); checks++;
    if (debug) {
        click(debug); assert.strictEqual(overlay.hidden, false); checks++;
        currentOverlay = null; controller.render(); assert.strictEqual(debug.hidden, true); checks++;
    }
    controller.destroy();
}
console.log('LuckTest Debug menu: ' + checks + ' checks passed.');
