'use strict';
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const fs=require('fs'),path=require('path'),assert=require('assert/strict');
const base=process.env.INTEGRATION_URL,out=process.env.INTEGRATION_EVIDENCE;
if(base!=='http://127.0.0.1:33572'||!out)throw Error('Isolated integration lab required');
const results=[];function check(test,ok){results.push({test,pass:!!ok});assert.ok(ok,test);}
(async()=>{const browser=await chromium.launch({headless:true,executablePath:process.env.BROWSER_EXECUTABLE});
try{
 const ca=await browser.newContext(),cb=await browser.newContext(),a=await ca.newPage(),b=await cb.newPage(),errors=[];
 for(const p of[a,b])p.on('pageerror',e=>errors.push(e.message));
 async function login(p,name){await p.goto(base+'/auth.php');await p.locator('[name=login]').fill(name);await p.locator('[name=password]').fill('LobbyFixture123');await p.getByRole('button',{name:'Войти',exact:true}).click();await p.waitForURL('**/lobby.php');}
 await login(a,'Creator');await login(b,'Candidate');
 await a.getByRole('button',{name:'Создать игру',exact:true}).click();await a.waitForURL('**/lobby.php?room=*');
 const room=new URL(a.url()).searchParams.get('room');await b.locator('tr[data-room-id="'+room+'"]').waitFor({timeout:7000});await b.locator('tr[data-room-id="'+room+'"]').click();await b.waitForURL('**/lobby.php?room='+room);
 await a.getByRole('button',{name:'Подтвердить',exact:true}).waitFor({timeout:7000});await a.getByRole('button',{name:'Подтвердить',exact:true}).click();
 await a.waitForURL('**/index.php?game=*');await b.waitForURL('**/index.php?game=*',{timeout:7000});
 const game=new URL(a.url()).searchParams.get('game');check('both automatically enter same existing game',new URL(b.url()).searchParams.get('game')===game&&!a.url().includes('first')&&!b.url().includes('second'));
 await a.locator('input[name=mode][value=system]').check();await a.locator('form.mode-actions button[type=submit]').click();
 await a.waitForLoadState();check('decorated mode form POST works',await a.locator('[name=cmd][value=select_deck]').count()===1);
 async function command(p,cmd,params={}){
  await p.reload();
  const next=await p.evaluate(async({cmd,params})=>{const s=document.querySelector('script[src*="game-transport.js"]');const body=new URLSearchParams({cmd,csrf:s.dataset.csrf,prepare_version:s.dataset.version,...params});const response=await fetch(location.href,{method:'POST',body});if(!response.ok)throw Error('preparation '+cmd+' '+response.status);return response.url;},{cmd,params});
  await p.goto(next);
 }
 await command(a,'select_deck',{deck_id:1,other_deck_id:1});await a.waitForLoadState();
 for(const p of[a,b]){await command(p,'confirm_view');await p.waitForLoadState();}
 for(const p of[a,b]){await command(p,'confirm_turn');await p.waitForLoadState();}
 await a.reload();await b.reload();
 const chooser=await a.locator('a[href*="cmd=choose_side"],form:has([name=cmd][value=choose_side])').count()?a:b;
 await command(chooser,'choose_side',{side:1});await chooser.waitForLoadState();
 for(const p of[a,b]){
  await p.reload();
  // Pick a visible hand entry to expose the existing recruit action.
  let recruit=p.locator('a[href*="cmd=pick_card"]');
  if(!await recruit.count()){
   const firstCard=p.locator('a[href*="card="]').first();await firstCard.click();await p.waitForLoadState();
  }
  recruit=p.locator('a[href*="cmd=pick_card"]').first();
  await recruit.waitFor();await recruit.click();await p.waitForLoadState();
  await command(p,'confirm_deal');await p.waitForLoadState();
 }
 for(const p of[a,b]){
  await p.reload();
  const select=p.locator('a[href*="card="]').first();await select.click();await p.waitForLoadState();
  const place=p.locator('a[href*="cmd=place_card"]').first();await place.waitFor();await place.click();await p.waitForLoadState();
  await command(p,'confirm_place');await p.waitForLoadState();
 }
 await a.reload();await b.reload();
 check('both browser sessions reach battle',await a.locator('script[src*="game-transport.js"][data-stage=battle]').count()===1&&await b.locator('script[src*="game-transport.js"][data-stage=battle]').count()===1);
 await a.screenshot({path:path.join(out,'battle-two-accounts.png'),fullPage:true});
 check('no role-authority links or fields',await a.locator('a[href*="?first"],a[href*="?second"],input[name=first],input[name=second]').count()===0);
 await a.reload();check('reload preserves game',new URL(a.url()).searchParams.get('game')===game);
 a.once('dialog',dialog=>dialog.accept());
 await a.getByRole('link',{name:'Сдаться',exact:true}).click();
 await a.locator('script[src*="game-transport.js"][data-stage=game_over]').waitFor({state:'attached',timeout:15000});
 await b.locator('script[src*="game-transport.js"][data-stage=game_over]').waitFor({state:'attached',timeout:15000});
 check('both browsers reach terminal via existing resign',await b.locator('script[data-stage=game_over]').count()===1);
 check('no browser script exceptions',errors.length===0);
 console.log(JSON.stringify({passed:results.length,results},null,2));
}finally{fs.writeFileSync(path.join(out,'browser-results.json'),JSON.stringify(results,null,2));await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
