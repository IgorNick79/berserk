const fs = require('fs');
const path = require('path');
const {execFileSync} = require('child_process');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const repo = path.resolve(__dirname, '..');
const read = f => fs.readFileSync(path.join(repo,f),'utf8');
const fixture = (own,opp) => {const raw=execFileSync(process.env.PHP_BINARY || 'php',[repo+'/tools/test_fly_pagination.php','--fixture',String(own),String(opp)],{encoding:'utf8'});return JSON.parse(raw.slice(raw.indexOf('{')));};
const data = new Map();for(let i=0;i<=6;i++)data.set(i,fixture(i,6));
function assert(ok,msg){if(!ok)throw Error(msg);}
(async()=>{
 const browser=await chromium.launch({...(process.env.BROWSER_EXECUTABLE ? {executablePath:process.env.BROWSER_EXECUTABLE} : {}),headless:true});
 try {
 for(const width of [1600,800,375]) {
 const page=await browser.newPage({viewport:{width,height:800}});
 await page.route('https://battle.test/**',r=>{
 const url = new URL(r.request().url());
 if(url.pathname.startsWith('/assets/images/battle/'))return r.fulfill({body:fs.readFileSync(path.join(repo,'www',url.pathname)),contentType:url.pathname.endsWith('.webp')?'image/webp':'image/png'});
 return r.fulfill({body:'<html></html>',contentType:'text/html'});
 });
 await page.goto('https://battle.test/');
 let html=read('templates/screen/battle.tpl').replace(/<script[\s\S]*?<\/script>/g,'');
 const initial=data.get(3);html=html.replace(/{{(\w+)}}/g,(_,k)=>initial[k]||'');
 await page.setContent('<style>'+read('www/assets/css/global.css')+'</style>'+html);
 await page.evaluate(()=>{document.documentElement.classList.add('battle-page');document.body.classList.add('battle-page');});
 await page.addScriptTag({content:read('www/assets/js/battle-ui.js')});
 let sync=read('www/assets/js/battle-sync.js');
 const last=sync.lastIndexOf('    requestSnapshot(false);');
 sync=sync.slice(0,last)+'    window.testSnapshot = function(data) { return applySnapshot(data, uiRevision); };'+sync.slice(last+'    requestSnapshot(false);'.length);
 await page.addScriptTag({content:sync.replace('var syncUrl = script.dataset.syncUrl || "";','var syncUrl = "https://battle.test/";')});
 let version=0;
 async function update(n) {
 const d=data.get(n);
 await page.evaluate(({d,v})=>{const fragments={};for(const key of ['field_html','fly_zones_html','panel_html','piles_html','pile_reveal_html','info_panel_html'])fragments[key]=d[key]||'';if(!window.testSnapshot({sync_version:v,fragments,ui:d.ui}))throw Error('Snapshot rejected');},{d,v:++version});
 await page.waitForTimeout(20);
 }
 async function metrics(){return page.evaluate(()=>{const rows=[...document.querySelectorAll('.battle-numbered-row')].map(e=>e.querySelector('.field-cell').getBoundingClientRect());const slots=[...document.querySelectorAll('.fly-slot')].map(e=>e.getBoundingClientRect());return {tops:slots.map(r=>r.top),rowtops:rows.map(r=>r.top),aligned:slots.every((r,i)=>Math.abs(r.top-rows[i].top)<.1&&r.height===rows[i].height),pageHeight:document.documentElement.scrollHeight,boardHeight:document.querySelector('.battle-left').scrollHeight,zoneHeights:[...document.querySelectorAll('.fly-zone')].map(e=>e.getBoundingClientRect().height),slots:slots.length,visible:[...document.querySelectorAll('.fly-zone.own .fly-page-card')].filter(e=>!e.hidden).map(e=>e.querySelector('a')?.getAttribute('href')||'')};});}
 const base=await metrics();assert(base.aligned,'Initial alignment '+width+JSON.stringify(base));
 assert(await page.evaluate(()=>{const l=document.querySelector('.battle-layout').getBoundingClientRect(),c=document.querySelector('.battle-left').getBoundingClientRect();return l.width>c.width||Math.abs(l.x+l.width/2-c.x-c.width/2)<1;}),'Playmat horizontally centered when it fits');
 assert(await page.evaluate(()=>{const l=document.querySelector('.battle-layout').getBoundingClientRect(),c=document.querySelector('.battle-left').getBoundingClientRect();return l.height>c.height||Math.abs(l.y+l.height/2-c.y-c.height/2)<1;}),'Playmat vertically centered when it fits');
 assert(await page.evaluate(()=>{const rows=[...document.querySelectorAll('.battle-numbered-row')];return [...document.querySelectorAll('.battle-side-slot')].every((slot,i)=>Math.abs(slot.getBoundingClientRect().top-rows[i].getBoundingClientRect().top)<.1);}), 'Terrain/grave/exile align with six field rows');
 assert(await page.locator('.battle-terrain img').count()===2,'Two empty terrain icons');
 assert(await page.locator('.battle-grave .pile-open').count()===2&&await page.locator('.battle-exile .pile-open').count()===2,'Grave/exile details for each side');
 const end=page.locator('a[href*="cmd=end_turn"]');
 assert(await end.evaluate(e=>getComputedStyle(e).backgroundImage==='none'&&e.textContent==='Завершить ход'),'Standard end-turn button restored');
 await update(0);
 assert(await page.evaluate(()=>{const empty=document.querySelector('.fly-zone.own [data-fly-page="0"]:empty');return !!empty&&getComputedStyle(empty,'::before').backgroundImage.includes('flying_empty.png');}),'Empty flying icon');

 for(let n=0;n<=6;n++){await update(n);const m=await metrics();assert(m.slots===6&&m.aligned,'Fixed aligned slots '+n);assert(m.pageHeight===base.pageHeight&&m.boardHeight===base.boardHeight,'No added scroll '+n);}
 await update(4);
 await page.locator('.fly-zone.own [data-fly-go="1"]').click();
 assert((await metrics()).visible.filter(Boolean).length===1,'Second page has one card');
 assert(await page.locator('.fly-zone.opp [data-fly-go="0"]').getAttribute('aria-pressed')==='true','Independent sides');
 await page.locator('.fly-zone.opp [data-fly-go="1"]').click();
 await update(4);assert(await page.locator('.fly-zone.own [data-fly-go="1"]').getAttribute('aria-pressed')==='true','Page survives snapshot');
 assert(await page.locator('.fly-zone.opp [data-fly-go="1"]').getAttribute('aria-pressed')==='true','Opp page survives snapshot');
 const beforeHp=data.get(4).fly_zones_html;
 data.get(4).fly_zones_html=beforeHp.replaceAll('3/3','2/3');
 await update(4);
 assert(await page.locator('.fly-zone.own [data-fly-go="1"]').getAttribute('aria-pressed')==='true','HP update preserves page');
 assert((await page.locator('.fly-zone.own [data-fly-page="1"]:not([hidden])').allTextContents()).join('').includes('2/3'),'Visible HP updated');
 data.get(4).fly_zones_html=beforeHp;
 await update(5);assert((await metrics()).visible.filter(Boolean).length===2,'Two cards on second page');
 await update(3);assert(await page.locator('.fly-zone.own [data-fly-go]').count()===0,'Controls removed');
 await update(4);assert(await page.locator('.fly-zone.own [data-fly-go="0"]').getAttribute('aria-pressed')==='true','Reset persists after regrowth');
 assert(await page.locator('.fly-zone.opp [data-fly-go="1"]').getAttribute('aria-pressed')==='true','Own reset leaves opponent alone');
 // Upper/lower columns use identical ordering and horizontal coordinates.
 assert(await page.evaluate(()=>{const groups=[...document.querySelectorAll('.battle-columns')];return groups.length===2&&groups[0].textContent===groups[1].textContent&&[...groups[0].children].every((e,i)=>Math.abs(e.getBoundingClientRect().x-groups[1].children[i].getBoundingClientRect().x)<.1);}), 'Lower column labels match upper labels');
 // Landscape popup uses the PNG URL, preserves 8:5 and stays within 800x500.
 await page.route('https://battle.test/assets/cards/s1/original/ui-test.png',r=>r.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="1600" height="1000"/>'}));
 await page.evaluate(html=>document.querySelector('.info-panel-content').insertAdjacentHTML('afterbegin',html),read('templates/includes/battle/card_preview.tpl').replaceAll('{{original_url}}','/assets/cards/s1/original/ui-test.png').replaceAll('{{preview_url}}','/assets/cards/s1/ui-test.webp').replaceAll('{{name}}','UI test'));
 await page.addScriptTag({content:read('www/assets/js/card-image-popup.js')});
 const preview=page.locator('.panel-card-preview');
 assert(await preview.evaluate(e=>getComputedStyle(e).cursor)==='pointer','Preview pointer cursor');
 for(const method of ['escape','button','outside']) {
 await preview.click();
 await page.waitForFunction(()=>document.querySelector('.card-image-popup img').naturalWidth===1600);
 assert(await page.locator('.card-image-popup img').evaluate(img=>{const r=img.getBoundingClientRect();return img.src.endsWith('/original/ui-test.png')&&r.width<=800&&r.height<=500&&r.width<=innerWidth-32&&r.height<=innerHeight-96&&Math.abs(r.width/r.height-1.6)<.01;}),'Landscape popup limits and proportions');
 if(method==='escape')await page.keyboard.press('Escape');
 if(method==='button')await page.locator('.card-image-popup-close').click();
 if(method==='outside')await page.mouse.click(2,400);
 assert(await page.locator('.card-image-popup').evaluate(e=>!e.open),'Popup closes '+method);
 }
 await preview.evaluate(e=>e.remove());
 // Both pagination groups must be centered on their own flying zone.
 assert(await page.evaluate(()=>[...document.querySelectorAll('.fly-zone')].every(z=>{const c=z.querySelector('.fly-pagination');if(!c)return true;const a=z.getBoundingClientRect(),b=c.getBoundingClientRect();return Math.abs(a.x+a.width/2-b.x-b.width/2)<.1;})),'Centered bullets');
 await page.waitForFunction(()=>[...document.querySelectorAll('.battle-icon-control img')].every(img=>img.complete&&img.naturalWidth===160));
 const icons=page.locator('.battle-icon-control');
 const baseline=await page.locator('.info-actions').boundingBox();
 for(const icon of await icons.all()) {
 const old=await icon.boundingBox();
 assert(old.width===40 && old.height===40,'40px icon box');
 await icon.hover();await page.waitForTimeout(250);
 const m=await icon.evaluate(e=>{const i=e.querySelector('img').getBoundingClientRect(),t=e.querySelector('.battle-icon-tooltip'),r=t.getBoundingClientRect(),f=e.closest('footer').getBoundingClientRect();return {width:i.width,height:i.height,opacity:getComputedStyle(t).opacity,visible:getComputedStyle(t).visibility,inViewport:r.x>=0&&r.right<=innerWidth&&r.y>=0&&r.bottom<=innerHeight,footerOverflow:getComputedStyle(e.closest('footer')).overflow};});
 assert(m.width===48&&m.height===48,'Hover scales image to 48px');
 assert(m.opacity==='1'&&m.visible==='visible'&&m.inViewport&&m.footerOverflow==='visible','Tooltip is visible and not clipped '+JSON.stringify(m));
 const now=await page.locator('.info-actions').boundingBox();
 assert(now.x===baseline.x&&now.y===baseline.y&&now.width===baseline.width&&now.height===baseline.height,'No hover layout shift');
 }
 await page.mouse.move(0,0);
 const bg=await page.evaluate(()=>{const s=getComputedStyle(document.body);return s.backgroundImage.includes('berserk_background.webp')&&s.backgroundSize==='cover'&&s.backgroundRepeat==='no-repeat'&&s.backgroundPosition==='50% 50%';});
 assert(bg,'Battle background cover');
 // Input/copy exceptions live inside the same permanent root as the cards.
 await page.evaluate(()=>{const p=document.querySelector('.info-panel-content');p.insertAdjacentHTML('beforeend','<div id="edit-check"><input value="copy me"><textarea>test</textarea><select><option>A</option></select><div contenteditable="true"><span>editable</span></div><pre data-allow-copy>copy</pre><div class="debug-panel">debug</div></div>');});
 for(const selector of ['.battle-card','#edit-check input','#edit-check textarea','#edit-check select','#edit-check [contenteditable] span','#edit-check [data-allow-copy]','#edit-check .debug-panel']){
 const editable=selector.startsWith('#');
 const result=await page.locator(selector).first().evaluate(e=>{const event=new MouseEvent('contextmenu',{bubbles:true,cancelable:true});return {allowed:e.dispatchEvent(event),selection:getComputedStyle(e).userSelect};});
 assert(result.allowed===editable,'Context menu exception '+selector);
 assert(result.selection===(editable?'text':'none'),'Selection exception '+selector);
 }
 await page.locator('#edit-check input').fill('Changed');assert(await page.locator('#edit-check input').inputValue()==='Changed','Input remains editable');
 await page.evaluate(()=>document.querySelector('#edit-check').remove());
 // Exercise existing command handlers with mocked fetch only, never a live game.
 await page.evaluate(()=>{window.uiRequests=[];window.fetch=(url,opts)=>{window.uiRequests.push({url:String(url),body:opts&&opts.body||''});return new Promise(()=>{});};});
 await page.locator('[data-action="battle-log"]').click({force:true});
 assert(await page.evaluate(()=>uiRequests.length)===0,'Log icon sends no requests');
 await page.locator('[data-action="battle-log"]').focus();await page.keyboard.press('Enter');
 assert(await page.evaluate(()=>uiRequests.length)===0,'Log keyboard activation is inert');
 // Cancel the original confirm: no command may be sent.
 page.once('dialog',d=>d.dismiss());
 await page.locator('a.resign').click();
 assert(await page.evaluate(()=>uiRequests.length)===0,'Cancel surrender confirmation sends no command');
 page.once('dialog',d=>d.accept());
 await page.locator('a.resign').click();
 await page.waitForFunction(()=>uiRequests.length===1);
 assert(await page.evaluate(()=>new URLSearchParams(uiRequests[0].body).get('cmd')==='resign'),'Original surrender command preserved');
 if(process.env.POLISH_SCREENSHOT_DIR)await page.screenshot({path:path.join(process.env.POLISH_SCREENSHOT_DIR,'battle-polish-'+width+'.png')});
 console.log('PASS '+width+'px: playmat centering, side zones, empty flying icon, standard end-turn button, lower columns, landscape PNG popup, pagination, centered bullets, 40/48px icons, tooltips, stable layout, background, editable exceptions, inert log and original surrender confirmation/command.');
 await page.close();
 }
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
