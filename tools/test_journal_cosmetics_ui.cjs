const fs=require('fs'),path=require('path'),assert=require('assert');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
(async()=>{
 const root=path.resolve(__dirname,'..'),js=fs.readFileSync(path.join(root,'www/assets/js/battle-journal.js'),'utf8');
 const css=fs.readFileSync(path.join(root,'www/assets/css/battle-journal.css'),'utf8');
 const global=fs.readFileSync(path.join(root,'www/assets/css/global.css'),'utf8');
 const dice=global.match(/\.dice \{[^}]+\}/)[0];
 const browser=await chromium.launch({executablePath:process.env.BROWSER_EXECUTABLE,headless:true});const results=[];
 try {
 for(const viewport of [{width:1280,height:900},{width:390,height:844},{width:320,height:568}])for(const side of ['host','player']){
  const ctx=await browser.newContext({viewport}),page=await ctx.newPage();
  const movement=side==='host'?'Дверг перемещается: Вражеский [2:1] → Вражеский [3:2].':'Дверг перемещается: Свой [2:5] → Свой [3:4].';
  const entries=[movement,'Бросок: атакующий 6, защитник 3.','Тич получает 1 рану.','Тич получает 2 раны.','Тич получает 5 ран.','Имя <img src=x onerror="window.bad=1"> восстанавливает 1 HP.',('Оченьдлинноеимя'.repeat(25))+' погибает.'].map((text,i)=>({seq:i+1,kind:'event',text}));
  await ctx.route('https://journal.test/battle-journal.php**',route=>{
   const after=Number(new URL(route.request().url()).searchParams.get('after')),slice=entries.filter(e=>e.seq>after).slice(0,2),cursor=slice.length?slice.at(-1).seq:after;
   return route.fulfill({contentType:'application/json',body:JSON.stringify({entries:slice,cursor,has_more:cursor<entries.length,recording:{available:true,partial:true,has_gaps:false}})});
  });
  await page.setContent('<meta name="viewport" content="width=device-width,initial-scale=1"><style>'+dice+css+'</style><button style="position:fixed;bottom:20px;left:30px" aria-label="Журнал боя" data-action="battle-log">Журнал боя<span aria-hidden="true" class="battle-icon-tooltip">Лог игры</span></button><aside id="battle-journal" class="battle-journal" hidden aria-label="Журнал боя"><header><strong>Журнал боя</strong><button data-journal-close aria-label="Закрыть журнал">×</button></header><p data-journal-notice></p><div class="battle-journal-scroll" data-journal-scroll tabindex="0"><ol data-journal-entries></ol></div><p data-journal-state role="status"></p></aside>');
  await page.evaluate(source=>{const s=document.createElement('script');s.dataset.journalUrl='https://journal.test/battle-journal.php?game=1';s.textContent=source;document.body.append(s);},js);
  await page.locator('[data-action="battle-log"]').click();await page.waitForFunction(()=>document.querySelector('[data-journal-entries]').children.length===7);
  assert.equal(await page.locator('.battle-journal-dice').getAttribute('aria-label'),'Бросок кубиков');
  assert.equal(await page.locator('.battle-journal-dice').getAttribute('role'),'img');
  assert.equal(await page.locator('.battle-journal-dice').count(),1);
  const diceText=await page.locator('[data-event-seq="2"]').textContent();assert(!diceText.includes('Бросок:'));assert(diceText.includes('Атакующий 6, защитник 3.'));assert(!diceText.includes('🎲'));
  assert.equal(await page.locator('[data-event-seq="1"]').textContent(),movement);
  assert.equal(await page.locator('#battle-journal [title], [data-action="battle-log"][title]').count(),0);
  assert.equal(await page.locator('.battle-icon-tooltip').textContent(),'Лог игры');
  assert.equal(await page.locator('#battle-journal img').count(),0);assert.equal(await page.evaluate(()=>window.bad),undefined);
  await page.evaluate(()=>document.dispatchEvent(new Event('berserk:battle-updated')));await page.waitForTimeout(100);assert.equal(await page.locator('[data-event-seq]').count(),7);
  const bounds=await page.locator('#battle-journal').evaluate(e=>{const r=e.getBoundingClientRect();return {left:r.left,right:r.right,top:r.top,bottom:r.bottom,overflow:e.scrollWidth>e.clientWidth};});
  assert(bounds.left>=0&&bounds.right<=viewport.width&&bounds.top>=0&&bounds.bottom<=viewport.height,JSON.stringify(bounds));assert(!bounds.overflow);
  const evidence=process.env.JOURNAL_EVIDENCE;if(evidence){fs.mkdirSync(evidence,{recursive:true});await page.locator('[data-journal-scroll]').evaluate(e=>e.scrollTop=0);await page.screenshot({path:path.join(evidence,`journal-${side}-${viewport.width}.png`)});}
  results.push({side,viewport,status:'PASS',duplicates:0,overflow:false,dice_accessible:true,native_title_duplicates:0});await ctx.close();
 }
 }finally{await browser.close();}
 console.log(JSON.stringify(results,null,2));
})().catch(e=>{console.error(e);process.exitCode=1});
