'use strict';
const assert=require('node:assert/strict');
const {setup,sample}=require('./test_turn_timer_client.js');
let checks=0;function eq(a,b,m){assert.equal(a,b,m);checks++;}
async function fixture(){
 const t=setup(sample(),'player',undefined,'file');await t.flush();const pending=[];
 t.window.Audio.prototype.play=function(){return new Promise((resolve,reject)=>pending.push({resolve,reject}));};
 t.fire('pointerdown');return {t,pending,notify:()=>t.root.berserkTurnTimer.notify(),setting:(enabled,v)=>t.root.berserkTurnTimer.updateSettings({sound_enabled:enabled},v)};
}
(async()=>{
 {const {t,pending,notify,setting}=await fixture();notify();setting(true,2);pending[0].reject(Error('current'));await t.flush();eq(t.sounds,2,'Current fallback works across unchanged samples');t.unload();}
 {const {t,pending,notify,setting}=await fixture();notify();setting(false,2);setting(true,3);pending[0].reject(Error('old'));await t.flush();eq(t.sounds,0,'Mute/unmute invalidates old rejection');
  notify();pending[1].reject(Error('new'));await t.flush();eq(t.sounds,2,'New event after unmute works');t.unload();}
 {const {t,pending,notify,setting}=await fixture();setting(false,2);notify();setting(false,3);eq(pending.length,0,'Always muted cannot start play');eq(t.sounds,0,'Always muted cannot beep');setting(true,4);await t.flush();eq(t.sounds,0,'Unmute cannot replay missed events');t.unload();}
 for(const order of [[0,1,2],[2,1,0]]){
  const {t,pending,notify}=await fixture();notify();notify();notify();
  let expected=0;for(const i of order){pending[i].reject(Error('attempt '+i));await t.flush();if(i===2)expected=2;eq(t.sounds,expected,'Only latest competing attempt can fallback');}
  eq(t.sounds,2,'Concurrent attempts produce only one fallback');t.unload();
 }
 {const {t,pending,notify,setting}=await fixture();notify();setting(false,2);pending[0].reject(Error('muted'));await t.flush();eq(t.sounds,0,'Rejection while muted silent');setting(true,3);await t.flush();eq(t.sounds,0,'Enable does not revive rejected attempt');t.unload();}
 console.log(`Sound fallback generation: ${checks} checks passed`);
})().catch(e=>{console.error(e);process.exitCode=1;});
