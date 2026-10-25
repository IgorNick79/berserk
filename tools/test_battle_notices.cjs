const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');
const source = fs.readFileSync(__dirname + '/../www/assets/js/battle-notices.js', 'utf8');
const storage = new Map();
function page(scope, ids, unavailable = false) {
    const handlers = {}, docHandlers = {};
    const box = {dataset: {ochiNotice: scope}, hidden: false};
    let items = ids.map(id => ({dataset: {ochiOpening: id}, hidden: false}));
    box.querySelectorAll = () => items;
    const button = {closest: () => box};
    const root = {querySelectorAll: () => [box], contains: b => b === button, addEventListener: (n, f) => handlers[n] = f};
    const localStorage = {getItem: k => {if(unavailable)throw Error(); return storage.get(k) || null;}, setItem: (k,v) => {if(unavailable)throw Error(); storage.set(k,v);}};
    vm.runInNewContext(source, {document: {querySelector: () => root, addEventListener: (n,f) => docHandlers[n]=f}, window: {localStorage, addEventListener: () => {}}});
    return {box, dismiss() {handlers.click({target: {closest: () => button}, preventDefault() {}});}, snapshot(next) {items=next.map(id=>({dataset:{ochiOpening:id},hidden:false})); docHandlers['berserk:battle-updated']();}, items: () => items};
}
let p=page('7:host',['9:host:timer:1:1:15']);
assert.equal(p.box.hidden,false); p.dismiss(); assert.equal(p.box.hidden,true);
p.snapshot(['9:host:timer:1:1:15']); assert.equal(p.box.hidden,true,'Polling cannot reopen dismissed notice');
p=page('7:host',['9:host:timer:1:1:15']); assert.equal(p.box.hidden,true,'Reload remembers dismissal');
assert.equal(page('7:player',['9:host:timer:1:1:15']).box.hidden,false,'Opponent dismisses independently');
assert.equal(page('8:host',['9:host:timer:1:1:15']).box.hidden,false,'Other game unaffected');
p.snapshot(['9:host:timer:1:1:15','9:host:timer:1:2:18']); assert.equal(p.box.hidden,false,'New opening shown'); assert.equal(p.items()[0].hidden,true);
p.dismiss(); p.snapshot(['9:host:timer:1:1:23']); assert.equal(p.box.hidden,false,'Exit/return same use gets fresh notice identity');
p=page('9:host',['new'],true); p.dismiss(); p.snapshot(['new']); assert.equal(p.box.hidden,true,'In-memory fallback when storage unavailable');
console.log('Battle notices: dismiss, poll, reload, new opening, independent viewers and storage fallback passed');
