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
 await page.route('https://battle.test/**',r=>r.fulfill({body:'<html></html>',contentType:'text/html'}));
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
 console.log('PASS '+width+'px: 0-6 cards, alignment, constant scroll, independent pages, actual snapshot replacement, shrink/regrowth.');
 if(process.env.FLY_SCREENSHOT_DIR)await page.screenshot({path:path.join(process.env.FLY_SCREENSHOT_DIR,'fly-pagination-'+width+'.png')});
 await page.close();
 }
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
