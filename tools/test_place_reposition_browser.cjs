const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'C:/Users/mi/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const {execFileSync}=require('child_process'),fs=require('fs'),path=require('path'),assert=require('assert/strict');
const root=path.resolve(__dirname,'..'),php=process.env.PHP_BINARY||'C:/OSPanel/modules/php/PHP_8.1/php.exe';
(async()=>{const browser=await chromium.launch({headless:true,executablePath:process.env.BROWSER_EXECUTABLE||'C:/Users/mi/AppData/Local/Yandex/YandexBrowser/Application/browser.exe'});let checks=0;try{
for(const width of [1600,1366]){
const page=await browser.newPage({viewport:{width,height:width===1366?768:900}});let posts=0,steps=[{cmd:'place_card',card_id:1,row:1,col:2}],latest;
const render=query=>(latest=JSON.parse(execFileSync(php,[path.join(root,'tools/test_place_reposition_fixture.php'),JSON.stringify(steps),JSON.stringify(query)],{encoding:'utf8',maxBuffer:4*1024*1024})));
await page.route('https://place-reposition.test/**',async route=>{const request=route.request(),url=new URL(request.url());if(url.pathname.startsWith('/assets/')){if(url.pathname.endsWith('view-deck.js'))return route.fulfill({body:''});const file=path.join(root,'www',url.pathname);return route.fulfill({body:fs.existsSync(file)?fs.readFileSync(file):'',contentType:file.endsWith('.js')?'text/javascript':file.endsWith('.css')?'text/css':file.endsWith('.svg')?'image/svg+xml':file.endsWith('.webp')?'image/webp':'image/png'});}if(request.method()==='POST'){posts++;const payload=Object.fromEntries(new URLSearchParams(request.postData()));steps.push(payload);}return route.fulfill({body:render(Object.fromEntries(url.searchParams)).html,contentType:'text/html; charset=utf-8'});});
await page.goto('https://place-reposition.test/index.php?game=970004');await page.waitForSelector('[data-battle-drag-ready]');
await page.locator('.battle-field [data-drag-card="1"]').click();await page.waitForSelector('.field-cell.allowed');assert.equal(await page.locator('.field-cell.allowed').count(),8);checks++;
const source=await page.locator('.battle-field [data-drag-card="1"]').boundingBox(),target=page.locator('[data-place-row="2"][data-place-col="3"]');
await page.mouse.move(source.x+40,source.y+40);await page.mouse.down();await page.mouse.move(source.x+52,source.y+40,{steps:3});await page.waitForSelector('.battle-drag-ghost');const rect=await target.boundingBox();await page.mouse.move(rect.x+40,rect.y+40,{steps:6});await page.mouse.up();
await page.waitForSelector('[data-place-row="2"][data-place-col="3"] [data-drag-card="1"]');assert.equal(posts,1);assert.equal(latest.state.cards[1].zone,'field');checks++;
const geometry=await page.locator('.battle-left').evaluate(n=>({overflowX:n.scrollWidth-n.clientWidth,overflowY:n.scrollHeight-n.clientHeight}));assert(geometry.overflowX<=1&&geometry.overflowY<=1,JSON.stringify(geometry));checks++;
const before=await page.locator('.battle-layout').boundingBox();await page.mouse.move(before.x+100,before.y+100);await page.mouse.wheel(180,180);await page.waitForTimeout(150);const after=await page.locator('.battle-layout').boundingBox();assert.equal(after.x,before.x);assert.equal(after.y,before.y);checks++;
const out=path.join(root,'docs/evidence/place');fs.mkdirSync(out,{recursive:true});await page.screenshot({path:path.join(out,'reposition-'+width+'.png'),fullPage:true});
await page.setViewportSize({width:390,height:844});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));checks++;
await page.close();}
console.log('PASS '+checks+' browser checks: real in-memory Engine and drag bridge; fixture transport, no DB/HTTP gameplay claims');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1);});
