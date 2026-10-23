'use strict';
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const fs=require('fs'),path=require('path'),assert=require('assert/strict');
const base=process.env.INTEGRATION_URL,game=process.env.JOURNAL_GAME,out=process.env.JOURNAL_EVIDENCE,first=process.env.JOURNAL_ACTIVE;
if(base!=='http://127.0.0.1:33672'||!out||!game||!['host','player'].includes(first))throw Error('Disposable live lab only');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.BROWSER_EXECUTABLE});const contexts={},pages={};let navigations=0;
 try{
  for(const [login,side]of[['Creator','host'],['Candidate','player']]){
   const context=contexts[side]=await browser.newContext(),p=pages[side]=await context.newPage();
   await p.goto(base+'/auth.php');await p.locator('[name=login]').fill(login);await p.locator('[name=password]').fill('LobbyFixture123');await p.getByRole('button',{name:'Войти',exact:true}).click();await p.waitForURL('**/lobby.php');
   await p.goto(base+'/index.php?game='+game);await p.locator('[data-action="battle-log"]').click();await p.locator('[data-journal-entries] li').first().waitFor();
   await p.evaluate(()=>window.savedJournal=document.getElementById('battle-journal'));p.on('framenavigated',f=>{if(f===p.mainFrame())navigations++;});
  }
  const before={};for(const side of ['host','player'])before[side]=await pages[side].locator('[data-journal-entries] li').count();
  for(const side of [first,first==='host'?'player':'host']){
   const snapshot=await (await contexts[side].request.get(base+'/index.php?game='+game+'&ajax=battle_sync')).json();
   assert(Number.isSafeInteger(snapshot.sync_version));const csrf=await pages[side].evaluate(()=>window.berserkGameCsrf);assert.equal(typeof csrf,'string');
   const result=await (await contexts[side].request.post(base+'/index.php?game='+game+'&ajax=battle_command',{form:{csrf,cmd:'end_turn',expected_version:String(snapshot.sync_version),command_id:'live-journal-'+side+'-'+Date.now()}})).json();assert.equal(result.ok,true);
  }
  const results=[];
  for(const side of ['host','player']){
   await pages[side].waitForFunction(n=>document.querySelectorAll('[data-journal-entries] li').length>=n+4,before[side],{timeout:10000});
   assert(await pages[side].evaluate(()=>window.savedJournal===document.getElementById('battle-journal')));
   const seq=await pages[side].locator('[data-journal-entries] li').evaluateAll(es=>es.map(e=>e.dataset.eventSeq));assert.equal(seq.length,new Set(seq).size);
   const full=await (await contexts[side].request.get(base+'/battle-journal.php?game='+game+'&limit=200')).json();assert.equal(full.has_more,false);assert.deepEqual(seq,full.entries.filter(e=>e.kind!=='gap').map(e=>String(e.seq)));
   await pages[side].screenshot({path:path.join(out,'journal-live-'+side+'.png'),fullPage:true});results.push({side,status:'PASS',before:before[side],after:seq.length,panel_preserved:true,duplicates:0});
  }
  assert.equal(navigations,0);fs.writeFileSync(path.join(out,'journal-live-browser-results.json'),JSON.stringify({results,reloads:navigations},null,2));
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
