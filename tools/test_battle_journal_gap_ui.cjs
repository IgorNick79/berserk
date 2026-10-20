const fs=require('fs'),path=require('path'),assert=require('assert');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
(async()=>{
 const source=fs.readFileSync(process.env.JOURNAL_JS||path.join(__dirname,'../www/assets/js/battle-journal.js'),'utf8');
 const browser=await chromium.launch(process.env.BROWSER_EXECUTABLE?{executablePath:process.env.BROWSER_EXECUTABLE,headless:true}:{headless:true});
 const results=[];
 for(const side of ['host','player']){
  const context=await browser.newContext();const page=await context.newPage();let phase=1,failNext=false,requests=0,lastCursor=0;
  const raw=Array.from({length:13},(_,i)=>({seq:i+1,kind:'gap',text:'Repeated diagnostic gap'}));
  raw.push({seq:14,kind:'event',text:'Turn starts'});
  const later=[{seq:15,kind:'event',text:'Owner secret ability',owner:true},{seq:16,kind:'gap',text:'New gap'},
    {seq:17,kind:'event',text:'Card moves <img src=x onerror="window.bad=1">'},{seq:18,kind:'event',text:'Attack and dice'},
    {seq:19,kind:'event',text:'Turn ends'}];
  await context.route('https://journal.test/battle-journal.php**',async route=>{
   requests++;if(failNext){failNext=false;return route.abort();}
   const after=Number(new URL(route.request().url()).searchParams.get('after'));
   const all=phase===1?raw:raw.concat(later),slice=all.filter(e=>e.seq>after).slice(0,3);
   const cursor=slice.length?slice.at(-1).seq:after;lastCursor=cursor;
   await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({entries:slice.filter(e=>!e.owner||side==='host'),cursor,has_more:cursor<all.at(-1).seq,recording:{available:true,partial:true,has_gaps:true}})});
  });
  await page.setContent('<button data-action="battle-log">Log</button><aside id="battle-journal" hidden><p data-journal-notice></p><div data-journal-scroll><ol data-journal-entries></ol></div><p data-journal-state></p></aside>');
  await page.evaluate(source=>{const script=document.createElement('script');script.dataset.journalUrl='https://journal.test/battle-journal.php?game=1';script.textContent=source;document.body.append(script);},source);
  await page.locator('[data-action="battle-log"]').click();
  await page.waitForFunction(()=>document.querySelector('[data-journal-entries]').children.length===1);
  assert.strictEqual(await page.locator('[data-journal-notice]').textContent(),'История боя записана не полностью. Некоторые события могут отсутствовать');
  assert.strictEqual(await page.locator('.battle-journal-gap').count(),0);assert.strictEqual(lastCursor,14);
  const initial=await page.locator('[data-journal-entries] li').count();
  await page.evaluate(()=>document.dispatchEvent(new Event('berserk:battle-updated')));await page.waitForTimeout(150);
  assert.strictEqual(await page.locator('[data-journal-entries] li').count(),initial);
  failNext=true;await page.evaluate(()=>document.dispatchEvent(new Event('berserk:battle-updated')));
  await page.waitForFunction(()=>document.querySelector('[data-journal-state]').textContent.includes('временно'));
  phase=2;await page.evaluate(()=>window.dispatchEvent(new Event('online')));
  await page.waitForFunction(()=>document.querySelector('[data-journal-entries]').textContent.includes('Turn ends'));
  const text=await page.locator('[data-journal-entries]').textContent();
  assert.strictEqual(text.includes('Owner secret ability'),side==='host');assert(!text.includes('Repeated diagnostic gap'));assert(!text.includes('New gap'));
  assert.strictEqual(await page.locator('[data-journal-entries] img').count(),0);assert.strictEqual(await page.evaluate(()=>window.bad),undefined);
  const seq=await page.locator('[data-journal-entries] li').evaluateAll(es=>es.map(e=>Number(e.dataset.eventSeq)));
  assert.strictEqual(new Set(seq).size,seq.length);assert.deepStrictEqual([...seq].sort((a,b)=>a-b),seq);
  assert.strictEqual(lastCursor,19);assert.strictEqual(await page.locator('[data-journal-notice]').count(),1);
  await page.locator('[data-action="battle-log"]').click();assert(await page.locator('#battle-journal').evaluate(e=>e.hidden));
  await page.locator('[data-action="battle-log"]').click();await page.waitForTimeout(100);
  assert.strictEqual(await page.locator('[data-journal-entries] li').count(),seq.length);
  results.push({side,status:'PASS',stored_gaps:14,warning_elements:1,visible_events:seq.length,cursor:lastCursor,requests,reconnect:'PASS',duplicates:0});await context.close();
 }
 await browser.close();console.log(JSON.stringify({checks:results},null,2));
})().catch(e=>{console.error(e);process.exit(1)});
