"use strict";
const assert=require('node:assert/strict');
const {setup,sample,keys}=require('./test_turn_timer_client.js');
let checks=0;
function equal(actual,expected,message){assert.equal(actual,expected,message);checks++;}
const request='mp:1:1';
function pause(mode='active',patch={}){return {mode,request_id:mode==='idle'?null:request,server_now:1000,
    request_deadline_at:1020,pause_deadline_at:1060,duration_seconds:60,allowed_commands:[],last_resume:null,last_result:null,...patch};}
function resumed(reason='both_ready',id=request){return pause('idle',{last_resume:{request_id:id,resume_event_id:id+':resume',resumed_at:1060,reason}});}
const paused=()=>sample({status:'paused',pause_reason:'match_pause'});
async function armed(boot=pause(),viewer='host',mode='file'){const t=setup(paused(),viewer,boot,mode);await t.flush();t.fire('pointerdown');await t.flush();return t;}
(async()=>{
 for(const viewer of ['host','player'])for(const reason of ['both_ready','deadline']){
  const t=await armed(pause('idle'),viewer);
  equal(t.plays,0,viewer+': no initial sound');
  await t.accept(paused(),2,false,pause('requested'));equal(t.plays,0,'Request has no sound');
  await t.accept(paused(),3,false,pause());equal(t.plays,0,'Accept has no sound');
  t.tick(100000);await t.flush();equal(t.plays,0,'Local zero has no sound');equal(t.blocked,true,'Zero keeps mask');
  await t.accept(sample({server_now:1060}),4,true,resumed(reason));
  equal(t.plays,1,viewer+': one shared sound for '+reason);equal(t.blocked,false,'Server resume removes mask');
  equal(t.audioUrls.join(','),'/assets/sound/turn.mp3','Uses exactly the existing turn sound file');
  await t.accept(sample({server_now:1061}),4,false,resumed(reason));
  await t.accept(sample({server_now:1062}),5,true,resumed(reason));
  await t.accept(paused(),3,false,pause());
  equal(t.plays,1,'Repeated snapshot/no_change and stale active cannot replay');
  await t.accept(paused(),6,false,pause('active',{request_id:'mp:1:2',pause_deadline_at:1120,last_resume:resumed(reason).last_resume}));
  await t.accept(sample({server_now:1120}),7,false,resumed('deadline','mp:1:2'));
  equal(t.plays,2,'Independent later resume event can notify once');
  equal(t.requests.filter(r=>r.route==='battle_command').length,0,'Sound never sends a game command');t.unload();
 }
 const reload=await armed(resumed());await reload.accept(sample(),2,true,resumed());equal(reload.plays,0,'Refresh with historical event is silent');reload.unload();
 const during=await armed(pause());await during.accept(sample(),2,false,resumed());equal(during.plays,1,'Refresh during active pause observes later resume');during.unload();
 for(const outcome of ['rejected','cancelled','request_timeout']){
  const t=await armed(pause('requested'));
  await t.accept(sample(),2,false,pause('idle',{last_result:{outcome}}));equal(t.plays,0,outcome+' does not notify');t.unload();
 }
 const firstSnapshot=await armed(null);
 await firstSnapshot.accept(sample(),2,false,resumed());equal(firstSnapshot.plays,0,'First authoritative snapshot without boot is only a baseline');
 await firstSnapshot.accept(paused(),3,false,pause('active',{request_id:'mp:1:2'}));
 await firstSnapshot.accept(sample(),4,false,resumed('deadline','mp:1:2'));equal(firstSnapshot.plays,1,'Later observed pause still notifies after first snapshot baseline');firstSnapshot.unload();
 const missing=await armed();await missing.accept(sample(),2,false,pause('idle'));equal(missing.plays,0,'Mode transition without server event is silent');missing.unload();
 const terminal=await armed();
 await terminal.accept(sample(),2,false,pause('idle',{last_resume:resumed().last_resume,last_result:{outcome:'battle_finished'}}));
 equal(terminal.plays,0,'Terminal surrender never announces resume');terminal.unload();
 for(const newStatus of ['running','paused']){
  const t=await armed(pause(),'host');
  await t.accept(sample({period_id:2,status:newStatus,pause_reason:newStatus==='paused'?'start_resolution':null,server_now:1060}),2,true,resumed());
  equal(t.plays,1,'Resume and own new '+newStatus+' period coalesce');
  await t.accept(sample({period_id:2,server_now:1061}),3,false,resumed());equal(t.plays,1,'Resolving coincident turn start cannot play twice');
  await t.accept(sample({period_id:3,server_now:1080}),4,false,resumed());equal(t.plays,2,'Later ordinary own turn still alerts');
  equal(t.audioUrls.length,1,'Shared controller creates only one Audio instance');t.unload();
 }
 const hidden=await armed();hidden.document.hidden=true;hidden.fire('visibilitychange');hidden.tick(70000);
 equal(hidden.plays,0,'Hidden local expiry is silent');hidden.state(sample({server_now:1070}),2,resumed('deadline'));
 hidden.document.hidden=false;hidden.fire('visibilitychange');await hidden.flush();
 equal(hidden.plays,1,'Wake after missed polling gets one authoritative resume');
 await hidden.accept(sample({server_now:1071}),3,true,resumed('deadline'));equal(hidden.plays,1,'Wake refresh does not replay');hidden.unload();
 const background=await armed();background.document.hidden=true;background.fire('visibilitychange');
 await background.accept(sample(),2,false,resumed('deadline'));equal(background.plays,1,'Existing background polling can alert an armed hidden tab');
 background.document.hidden=false;background.fire('visibilitychange');await background.flush();equal(background.plays,1,'Returning after hidden alert never replays');background.unload();
 const offline=await armed();offline.offline(true);await offline.poll();offline.tick(70000);equal(offline.plays,0,'Disconnect cannot locally resume');
 offline.offline(false);await offline.accept(sample({server_now:1070}),2,false,resumed('deadline'));equal(offline.plays,1,'Reconnect receives authoritative event');offline.unload();
 const recovery=await armed();recovery.hold('battle_command');recovery.command('cmd=pause_ready&request_id='+request);await recovery.flush();
 recovery.document.hidden=true;recovery.fire('visibilitychange');recovery.hold('battle_command_status');
 recovery.document.hidden=false;recovery.fire('visibilitychange');await recovery.poll();
 recovery.state(sample({server_now:1060}),2,resumed());
 recovery.enqueue({ok:true,type:'snapshot',sync_version:2,turn_timer:sample({server_now:1060}),match_pause:resumed(),ui:{},fragments:Object.fromEntries(keys.map(k=>[k,'']))});
 recovery.release('battle_command_status',{ok:true,command_id:'timer-command',command_status:'committed',terminal:true,sync_version:2,turn_timer:sample({server_now:1060}),match_pause:resumed()});await recovery.flush();
 equal(recovery.plays,1,'Command status and forced snapshot share event deduplication');
 equal(recovery.requests.filter(r=>r.route==='battle_command').length,1,'Lost response never repeats ready POST');
 await recovery.accept(sample({server_now:1061}),2,false,resumed());equal(recovery.plays,1,'Polling after recovery stays silent');recovery.unload();
 for(const mode of ['reject','throw','blocked']){
  const t=await armed(pause(),'host',mode);await t.accept(sample(),2,false,resumed());
  equal(t.plays,1,mode+': one attempt even if playback fails');equal(t.blocked,false,mode+': sync completes');
  await t.accept(sample(),3,true,resumed());equal(t.plays,1,mode+': event not retried');
  if(mode==='reject')equal(t.sounds,2,'Rejected file play uses existing two-tone fallback');
  if(mode==='blocked')equal(t.sounds,0,'Browser may block all audio without breaking sync');t.unload();
 }
 const noGesture=setup(paused(),'host',pause(),'file');await noGesture.flush();await noGesture.accept(sample(),2,false,resumed());
 equal(noGesture.plays,0,'No gesture uses existing autoplay policy');noGesture.fire('pointerdown');await noGesture.accept(sample(),3,false,resumed());
 equal(noGesture.plays,0,'Unlocking audio does not replay consumed event');noGesture.unload();
 console.log(`Match pause shared resume sound tests passed (${checks} checks)`);
})().catch(e=>{console.error(e);process.exitCode=1;});
