'use strict';
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const fs=require('fs'),path=require('path'),assert=require('assert/strict');
const base=process.env.INTEGRATION_URL,game=process.env.JOURNAL_GAME,out=process.env.JOURNAL_EVIDENCE;
if(base!=='http://127.0.0.1:33672'||!game||!out)throw Error('Disposable lab only');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.BROWSER_EXECUTABLE});const results=[];
 try{
  for(const [login,side]of[['Creator','host'],['Candidate','player']]){
   const context=await browser.newContext(),p=await context.newPage();
   await p.goto(base+'/auth.php');await p.locator('[name=login]').fill(login);await p.locator('[name=password]').fill('LobbyFixture123');await p.getByRole('button',{name:'Войти',exact:true}).click();await p.waitForURL('**/lobby.php');
   const entries=[];let cursor=0;
   for(let i=0;i<100;i++){
    const response=await context.request.get(base+'/battle-journal.php?game='+game+'&after='+cursor+'&limit=2');assert.equal(response.status(),200);const data=await response.json();entries.push(...data.entries);cursor=data.cursor;if(!data.has_more)break;
   }
   const expected=entries.filter(e=>e.kind!=='gap').map(e=>/^Бросок: атакующий \d+, защитник (?:\d+|без броска)\.$/.test(e.text)?'⚄\uFE0E А'+e.text.slice('Бросок: а'.length):e.text);assert(expected.some(t=>t.includes('атакует'))&&expected.some(t=>t.includes('Атакующий')));
   await p.goto(base+'/index.php?game='+game);await p.locator('[data-action="battle-log"]').click();
   await p.waitForFunction(n=>document.querySelectorAll('[data-journal-entries] li').length===n,expected.length);
   assert.deepEqual(await p.locator('[data-journal-entries] li').allTextContents(),expected);
   await p.evaluate(()=>document.dispatchEvent(new Event('berserk:battle-updated')));await p.waitForTimeout(200);
   assert.equal(await p.locator('[data-journal-entries] li').count(),expected.length);
   await p.reload();await p.locator('[data-action="battle-log"]').click();await p.waitForFunction(n=>document.querySelectorAll('[data-journal-entries] li').length===n,expected.length);
   assert.deepEqual(await p.locator('[data-journal-entries] li').allTextContents(),expected);
   await context.setOffline(true);await p.evaluate(()=>document.dispatchEvent(new Event('berserk:battle-updated')));
   await p.waitForFunction(()=>document.querySelector('[data-journal-state]').textContent.includes('временно'));
   await context.setOffline(false);await p.evaluate(()=>window.dispatchEvent(new Event('online')));await p.waitForTimeout(300);
   const seq=await p.locator('[data-journal-entries] li').evaluateAll(es=>es.map(e=>e.dataset.eventSeq));assert.equal(new Set(seq).size,seq.length);
   await p.screenshot({path:path.join(out,'journal-'+side+'.png'),fullPage:true});results.push({side,status:'PASS',entries:expected.length,cursor,reload:'PASS',reconnect:'PASS',duplicates:0});await context.close();
  }
 }finally{await browser.close();fs.writeFileSync(path.join(out,'journal-browser-results.json'),JSON.stringify(results,null,2));}
})().catch(e=>{console.error(e);process.exit(1)});
