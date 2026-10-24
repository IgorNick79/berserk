const fs=require('fs'),path=require('path'),assert=require('assert'),{execFileSync}=require('child_process');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'C:/Users/mi/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const repo=path.resolve(__dirname,'..'),read=f=>fs.readFileSync(path.join(repo,f),'utf8');
const cards=JSON.parse(execFileSync(process.env.PHP_BINARY||'C:/OSPanel/modules/php/PHP_8.1/php.exe',[path.join(repo,'tools/test_silver_dice.php'),'--fixture'],{encoding:'utf8'}));
const out=process.env.SILVER_DICE_ARTIFACT_DIR||path.join(repo,'tmp','silver-dice');fs.mkdirSync(out,{recursive:true});
(async()=>{const browser=await chromium.launch({headless:true,executablePath:process.env.BROWSER_EXECUTABLE||'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});try{
const page=await browser.newPage({viewport:{width:1100,height:800}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
const texts=['Бросок: атакующий 2, защитник 4.','Бросок: атакующий 6, защитник без броска.','Бросок: атакующий 7, защитник 0.','Изменение кубиков: атакующий 3, защитник 6.','<img src=x onerror="window.bad=1">',...[1,2,3,4,5,6].map(n=>'Бросок: атакующий '+n+', защитник '+(7-n)+'.')];
await page.route('https://dice.test/**',async r=>{const url=new URL(r.request().url());if(url.pathname==='/journal')return r.fulfill({json:{entries:texts.map((text,i)=>({seq:i+1,kind:'event',text})),cursor:texts.length,has_more:false,recording:{available:true,partial:true,has_gaps:false}}});
 const f=path.join(repo,'www',url.pathname);return r.fulfill(fs.existsSync(f)&&fs.statSync(f).isFile()?{body:fs.readFileSync(f),contentType:'image/svg+xml'}:{body:'<html></html>',contentType:'text/html'});});
await page.goto('https://dice.test/');
await page.setContent('<style>'+read('www/assets/css/battle-mini-card.css')+'\n'+read('www/assets/css/battle-journal.css')+'</style><body class="battle-page" style="background:#333"><button data-action="battle-log">Журнал</button><main style="display:grid;grid-template-columns:repeat(2,140px);gap:24px;padding:24px;color:white">'+Object.entries(cards).map(([key,html])=>'<div><p>'+key+'</p>'+html+'</div>').join('')+'</main><div style="padding:24px;background:#eee;width:max-content">'+[1,2,3,4,5,6].map(n=>'<img width="48" height="48" src="/assets/images/battle/dice/dice_'+n+'.svg">').join('')+'</div><aside class="battle-journal" id="battle-journal" hidden><header>Журнал</header><p data-journal-notice></p><div class="battle-journal-scroll" data-journal-scroll><ol data-journal-entries></ol></div><p data-journal-state></p></aside>');
await page.evaluate(source=>{const script=document.createElement('script');script.dataset.journalUrl='https://dice.test/journal';script.textContent=source;document.body.append(script);},read('www/assets/js/battle-journal.js'));
await page.locator('[data-action=battle-log]').click();await page.waitForFunction(()=>document.querySelectorAll('[data-journal-entries] li').length===11);
const first=page.locator('[data-event-seq="1"]');assert.strictEqual(await first.locator('.battle-journal-die').count(),2);
assert((await first.locator('img').nth(0).getAttribute('src')).endsWith('dice_2.svg'));assert((await first.locator('img').nth(1).getAttribute('src')).endsWith('dice_4.svg'));
assert(!(await first.textContent()).includes('⚄'));assert.strictEqual(await page.locator('[data-event-seq="2"] img').count(),1);assert((await page.locator('[data-event-seq="2"]').textContent()).includes('без броска'));
assert.strictEqual(await page.locator('[data-event-seq="3"] img').count(),0);assert((await page.locator('[data-event-seq="3"]').textContent()).includes('7, защитник 0'));
assert.strictEqual(await page.locator('[data-event-seq="4"] img').count(),2);assert((await page.locator('[data-event-seq="4"]').textContent()).startsWith('Изменение кубиков:'));
assert.strictEqual(await page.locator('[data-event-seq="5"] img').count(),0);assert.strictEqual(await page.evaluate(()=>window.bad),undefined);
await page.waitForFunction(()=>[...document.images].every(img=>img.complete&&img.naturalWidth>0));
assert(await page.locator('.battle-journal-die').evaluateAll(es=>es.every(e=>e.width===24&&e.height===24)));
for(let n=1;n<=6;n++){const doc=await page.evaluate(s=>new DOMParser().parseFromString(s,'image/svg+xml').documentElement.outerHTML,read('www/assets/images/battle/dice/dice_'+n+'.svg'));assert.strictEqual((doc.match(/<circle /g)||[]).length,n);assert(!doc.includes('M9 7H39'));}
await page.screenshot({path:path.join(out,'silver-frames-dice.png')});assert(!errors.length,errors.join('\n'));
console.log('PASS: all 6 SVG faces, both owners/metals, inline dice values, no-roll/out-of-range fallback, changed dice, no prefix, escaping, actual image loading and 24px size.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1)});
