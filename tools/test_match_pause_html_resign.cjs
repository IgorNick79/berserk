"use strict";
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const base=process.env.TURN_TIMER_TEST_URL;
if(!base||!/^http:\/\/127\.0\.0\.1:\d+\/index\.php$/.test(base))throw Error('Disposable loopback server required');
const fixture=(...args)=>JSON.parse(execFileSync(process.env.PHP_BINARY||'php',[path.join(__dirname,'test_match_pause_ui_fixture.php'),...args.map(String)],{encoding:'utf8'}));
let checks=0;function ok(x,m){assert.ok(x,m);checks++;}
const id=()=>crypto.randomBytes(16).toString('hex');
async function status(game,actor,commandId,html=true){return fetch(base+'?'+actor+'&game='+game+'&ajax=battle_command_status&command_id='+commandId+(html?'&html_resign=1':''),{redirect:'manual'});}
async function post(game,actor,cmd,commandId,version,html=true){return fetch(base+'?'+actor+'&game='+game+'&ajax=battle_command',{method:'POST',redirect:'manual',body:new URLSearchParams({cmd,command_id:commandId,expected_version:String(version),...(html?{html_resign:'1'}:{})})});}
(async()=>{
 fixture('verify');
 if(fs.existsSync(path.join(__dirname,'..','.git')))throw Error('Disposable source required');
 const token=id(),name='pause-resign-probe-'+token+'.txt',probe=path.join(__dirname,'../www',name);
 fs.writeFileSync(probe,token);
 try{ok(await(await fetch(new URL(name,base))).text()===token,'HTTP uses guarded source copy');}finally{fs.unlinkSync(probe);}
 const ordinary=fixture('create'),endId=id();
 let response=await post(ordinary.game_id,'first','end_turn',endId,ordinary.version,false);
 const json=await response.json();
 ok(json.command_status==='committed'&&json.terminal===true&&json.ok===true,'Real end_turn committed via unchanged JSON path');
 ok(response.headers.get('content-type').includes('application/json'),'Normal AJAX is JSON');
 response=await status(ordinary.game_id,'first',endId);
 ok(response.status===409&&!response.headers.has('location'),'Committed end_turn never redirects as surrender');
 ok((await response.text()).includes('Сдача не выполнена'),'Wrong command gets explicit safe refusal');
 response=await post(ordinary.game_id,'first','resign',endId,json.sync_version);
 ok(response.status!==303&&!response.headers.has('location'),'Reusing other command ID cannot confirm surrender');
 response=await status(ordinary.game_id,'first',endId);
 ok(response.status===409,'Recovery of reused non-resign ID also refuses');
 ok(fixture('read',ordinary.game_id).state.status==='battle','False-confirmation attempts do not end match');
 const game=fixture('create');
 for(const kind of ['processing','rejected','conflict','failed']){
  const commandId=id();fixture('seed_resign_status',game.game_id,kind,commandId);
  response=await status(game.game_id,'first',commandId);const html=await response.text();
  ok(response.status!==303&&!response.headers.has('location'),kind+' does not confirm');
  ok(!html.includes('<form'),kind+' never resubmits');
  if(kind==='processing')ok(html.includes('Проверить результат')&&html.includes(commandId),'Processing recovers same ID');
  else ok(html.includes('Сдача не выполнена')&&!html.includes('Проверить результат'),kind+' is terminal refusal');
  const plain=await(await status(game.game_id,'first',commandId,false)).json();
  ok(plain.command_status===kind&&plain.terminal===(kind!=='processing'),'Unchanged JSON '+kind);
 }
 const unknown=id();response=await status(game.game_id,'first',unknown);const html=await response.text();
 ok(response.status!==303&&html.includes(unknown)&&html.includes('Проверить результат')&&!html.includes('<form'),'Unknown only offers same-ID recovery');
 ok(fixture('read',game.game_id).version===game.version,'Status recovery does not execute or repeat surrender');
 for(const kind of ['committed_no_result','committed_nonterminal_result','committed_wrong_winner']){
  const inconsistent=fixture('create'),inconsistentId=id();
  fixture('seed_resign_status',inconsistent.game_id,kind,inconsistentId);
  const before=fixture('read',inconsistent.game_id);
  const inconsistentJson=await(await status(inconsistent.game_id,'first',inconsistentId,false)).json();
  ok(inconsistentJson.ok===true&&inconsistentJson.command_status==='committed'&&inconsistentJson.terminal===true,kind+': payload flags alone claim success');
  response=await status(inconsistent.game_id,'first',inconsistentId);
  ok(response.status!==303&&!response.headers.has('location'),kind+': no successful terminal result means no 303');
  const inconsistentHtml=await response.text();
  ok(inconsistentHtml.includes('Проверить результат')&&inconsistentHtml.includes(inconsistentId)&&!inconsistentHtml.includes('<form'),kind+': preserves original-ID recovery');
  const after=fixture('read',inconsistent.game_id);
  ok(after.version===before.version&&after.state.status===before.state.status&&after.state.winner===before.state.winner,kind+': status read never executes or repairs surrender');
 }
 const surrender=fixture('create'),resignId=id();
 response=await post(surrender.game_id,'second','resign',resignId,surrender.version);
 ok(response.status===303,'Committed resign POST redirects 303');
 ok(response.headers.get('location')==='?second&game='+surrender.game_id,'303 points to current actor and game');
 ok(fixture('read',surrender.game_id).state.status==='game_over','Real surrender finished match');
 response=await status(surrender.game_id,'second',resignId);ok(response.status===303,'Committed resign status confirms');
 response=await status(surrender.game_id,'first',resignId);ok(response.status!==303&&!response.headers.has('location'),'Other player command ID cannot confirm');
 response=await status(game.game_id,'second',resignId);ok(response.status!==303&&!response.headers.has('location'),'Other game command ID cannot confirm');
 response=await post(game.game_id,'first','resign',resignId,game.version-1);
 ok(response.status!==303,'Reusing foreign ID with stale CAS cannot confirm');
 ok(fixture('read',game.game_id).state.status==='battle','Foreign ID cannot bypass CAS');
 const uncertain=fixture('create'),uncertainId=id();
 const fault=execFileSync(process.env.PHP_BINARY||'php',[path.join(__dirname,'test_match_pause_fault.php'),String(uncertain.game_id),'html-resign-commit-unknown',uncertainId],{encoding:'utf8'});
 ok(!fault.endsWith('__RESIGN_STATUS__303')&&fault.includes('Результат сдачи не подтверждён'),'Lost COMMIT acknowledgement is not prematurely confirmed');
 ok(fault.includes(uncertainId)&&fault.includes('Проверить результат')&&!fault.includes('<form'),'Unknown COMMIT keeps same-ID recovery without resend');
 ok(fixture('read',uncertain.game_id).state.status==='game_over','Lost acknowledgement fixture actually committed surrender');
 const recovered=await(await status(uncertain.game_id,'first',uncertainId,false)).json();
 ok(recovered.command_status==='committed'&&recovered.terminal===true,'Journal resolves unknown COMMIT');
 response=await status(uncertain.game_id,'first',uncertainId);
 ok(response.status===303,'Only later confirmed journal recovery redirects');
 console.log(`HTML surrender journal tests passed (${checks} checks)`);
})().catch(e=>{console.error(e);process.exitCode=1;});
