'use strict';
const assert=require('node:assert/strict');
const {setup,sample}=require('./test_turn_timer_client.js');
let checks=0;function eq(a,b,m){assert.equal(a,b,m);checks++;}
(async()=>{
 for(const mode of ['beep','file','reject','blocked']){
  const t=setup(sample(),'player',undefined,mode,{sound_enabled:false});await t.flush();t.fire('pointerdown');await t.flush();
  eq(t.root.berserkTurnTimer.isSoundEnabled(),false,'Boot false '+mode);
  await t.accept(sample({period_id:2,owner:'player'}),2);
  eq(t.plays+t.sounds,0,'Muted turn '+mode);
  t.root.berserkTurnTimer.notify();await t.flush();eq(t.plays+t.sounds,0,'Muted shared resume '+mode);
  t.enqueue({ok:true,type:'no_change',sync_version:3,turn_timer:sample({period_id:2,owner:'player'}),player_settings:{sound_enabled:true}});await t.poll();
  eq(t.plays+t.sounds,0,'No replay on enable '+mode);
  t.root.berserkTurnTimer.updateSettings({sound_enabled:false},2);
  eq(t.root.berserkTurnTimer.isSoundEnabled(),true,'Stale setting ignored '+mode);
  await t.accept(sample({period_id:3,owner:'player'}),4);await t.flush();
  eq(mode==='blocked'?t.sounds===0:t.plays+t.sounds>0,true,'Future event uses original audio rules '+mode);t.unload();
 }
 const pause={mode:'active',request_id:'mp:sound',server_now:1000,request_deadline_at:1020,pause_deadline_at:1060,duration_seconds:60,allowed_commands:[],last_resume:null,last_result:null};
 for(const viewer of ['host','player']){
  const t=setup(sample({status:'paused',pause_reason:'match_pause'}),viewer,pause,'file',{sound_enabled:false});await t.flush();t.fire('pointerdown');
  const resumed={...pause,mode:'idle',request_id:null,last_resume:{request_id:'mp:sound',resume_event_id:'mp:sound:resume',resumed_at:1060,reason:'both_ready'}};
  await t.accept(sample({server_now:1060}),2,true,resumed);eq(t.plays,0,'Muted actual resume '+viewer);
  t.root.berserkTurnTimer.updateSettings({sound_enabled:true},3);
  await t.accept(sample({server_now:1061}),3,false,resumed);eq(t.plays,0,'Unmute never replays resume '+viewer);t.unload();
 }
 const asyncFallback=setup(sample(),'player',undefined,'reject');await asyncFallback.flush();asyncFallback.fire('pointerdown');
 asyncFallback.root.berserkTurnTimer.notify();asyncFallback.root.berserkTurnTimer.updateSettings({sound_enabled:false},2);
 await asyncFallback.flush();eq(asyncFallback.plays,1,'Original play attempted');eq(asyncFallback.sounds,0,'Late rejected play cannot bypass mute through fallback');asyncFallback.unload();
 const a=setup(),b=setup();await a.flush();await b.flush();a.fire('pointerdown');b.fire('pointerdown');
 a.root.berserkTurnTimer.updateSettings({sound_enabled:false},2);
 await a.accept(sample({period_id:2,owner:'player'}),2);await b.accept(sample({period_id:2,owner:'player'}),2);
 eq(a.sounds,0,'One player muted');eq(b.sounds,2,'Other player unaffected');
 a.root.berserkTurnTimer.updateSettings({sound_enabled:true},3);eq(a.sounds,0,'No catch-up');
 a.unload();b.unload();
 const recovery=setup();await recovery.flush();recovery.command('cmd=set_sound_enabled&sound_enabled=0');await recovery.flush();
 recovery.hold('battle_command_status');await recovery.poll();
 recovery.release('battle_command_status',{command_id:'timer-command',command_status:'committed',terminal:true,sync_version:2,turn_timer:sample(),player_settings:{sound_enabled:false}});await recovery.flush();
 eq(recovery.root.berserkTurnTimer.isSoundEnabled(),false,'Journal recovery updates audio flag');
 eq(recovery.requests.filter(r=>r.route==='battle_command').length,1,'Recovery never repeats preference POST');
 eq(recovery.requests.filter(r=>r.route==='battle_sync').every(r=>!new URL(r.url).searchParams.has('settings_sync_id')),true,'Recovery uses ordinary sync without bypass parameter');recovery.unload();
 console.log(`Player sound client: ${checks} checks passed`);
})().catch(e=>{console.error(e);process.exitCode=1;});
