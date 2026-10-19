"use strict";
const assert = require("node:assert/strict");
const fs = require("node:fs"), path = require("node:path"), vm = require("node:vm");
const source = fs.readFileSync(path.join(__dirname, "../www/assets/js/battle-match-pause.js"), "utf8");
let checks = 0;
function equal(actual, expected, message) { assert.equal(actual, expected, message); checks++; }
function sample(mode = "active", id = "mp:1:1", now = 100, deadline = 160) {
    return {mode, request_id:id, server_now:now, request_deadline_at:deadline, pause_deadline_at:deadline, allowed_commands:[]};
}
function harness(boot = null) {
    let now = 0, due = 0, sent = 0;
    const tasks = [], label = {textContent:""}, mask = {}, left = {setAttribute(){},removeAttribute(){}};
    const decisions = {dataset:{},replaceChildren(){},querySelectorAll(){return [];}};
    const section = {querySelector(s){return s === ".match-pause-decisions" ? decisions : s === "[data-pause-countdown]" ? label : {textContent:""};}};
    const attrs = new Map();
    const root = {querySelector(s){return s === "[data-match-pause-content]" ? section : s === ".battle-left" ? left : s === "[data-match-pause-mask]" ? mask : null;},
        querySelectorAll(){return [];},toggleAttribute(k,v){if(v)attrs.set(k,"");else attrs.delete(k);},setAttribute(k,v){attrs.set(k,v);},addEventListener(){},removeEventListener(){}};
    const picker = {querySelector(){return {addEventListener(){},value:"1"};},setAttribute(){},remove(){}};
    const window = {setInterval(){return 1;},clearInterval(){},setTimeout(fn){tasks.push(fn);}};
    const document = {hidden:false,createElement(){return picker;},body:{appendChild(){},classList:{toggle(){}}},
        querySelector(){return boot ? {textContent:JSON.stringify(boot)} : null;},querySelectorAll(){return [];}};
    vm.runInNewContext(source,{window,document,performance:{now:()=>now},URLSearchParams});
    const c = window.BerserkMatchPause.create(root,1,()=>sent++,()=>false,()=>due++);
    return {c, document, tick(ms){now+=ms;c.render();},flush(){while(tasks.length)tasks.shift()();},
        get text(){return label.textContent;},get due(){return due;},get sent(){return sent;},get blocked(){return attrs.get("data-match-paused")==="1" && left.inert && !mask.hidden;}};
}
for (const mode of ["requested","active"]) {
    const h=harness();h.c.update(sample(mode),1);h.tick(2200);
    equal(h.text,"00:58",mode+" elapsed");h.c.update(sample(mode),2);
    equal(h.text,"00:58",mode+" new version same second never refunds");h.tick(1000);
    equal(h.text,"00:57",mode+" fractional expiry anchor retained");
    h.c.update(sample(mode,"mp:1:1",101),2);equal(h.text,"00:57",mode+" delayed later sample");
    h.c.update(sample(mode,"mp:1:1",101),2);h.tick(1000);equal(h.text,"00:56",mode+" exact duplicate");
    h.c.update(sample(mode,"mp:1:1",102),2);equal(h.text,"00:56",mode+" equal version newer timestamp");
    h.c.update(sample(mode,"mp:1:1",90),2);equal(h.text,"00:56",mode+" old timestamp");
    h.c.update(sample(mode,"mp:1:1",150),1);equal(h.text,"00:56",mode+" old version ignored");
    h.c.update(sample(mode,"mp:1:1",90),3);h.tick(1000);equal(h.text,"00:55",mode+" newer version old timestamp preserves expiry");
    h.c.update(sample(mode,"mp:1:1",120),3);equal(h.text,"00:40",mode+" sample may shorten expiry");
    h.document.hidden=true;h.tick(10000);h.document.hidden=false;h.c.update(sample(mode,"mp:1:1",121),3);
    equal(h.text,"00:30",mode+" wake without timer callbacks cannot refund");
    h.tick(30000);h.flush();equal(h.text,"00:00",mode+" local zero");equal(h.blocked,true,mode+" zero retains barrier");
    equal(h.sent,0,mode+" zero never sends commands");equal(h.due,1,mode+" zero forces sync once");
    h.c.update(sample(mode,"mp:1:1",122),4);h.tick(1000);h.flush();equal(h.text,"00:00",mode+" zero cannot revive");equal(h.due,1,mode+" duplicate zero no sync loop");
    h.c.update({...sample(),mode:"idle"},5);equal(h.blocked,false,mode+" only authoritative idle removes barrier");
    h.c.update(sample(mode,"mp:1:2",200,260),5);equal(h.text,"01:00",mode+" independent request");
}
const transition=harness();transition.c.update(sample("requested","mp:1:1",100,120),1);transition.tick(3000);
transition.c.update(sample("active","mp:1:1",103,283),2);equal(transition.text,"03:00","accept starts active deadline");
transition.tick(1500);transition.c.update(sample("active","mp:1:1",103,300),3);equal(transition.text,"03:17","changed deadline defines new clock");
for(const mode of ["requested","active"]){const refresh=harness(sample(mode,"mp:1:1",130,160));equal(refresh.text,"00:30",mode+" refresh boots from authoritative sample");refresh.tick(1200);equal(refresh.text,"00:29",mode+" boot clock advances");}
console.log(`Match pause countdown tests passed (${checks} checks)`);
