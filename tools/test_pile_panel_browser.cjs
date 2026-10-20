const fs=require('fs'),path=require('path'),{execFileSync}=require('child_process');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const repo=process.env.PILE_TEST_REPO||path.resolve(__dirname,'..'),read=f=>fs.readFileSync(path.join(repo,f),'utf8');
const fixture=(stage,viewer,sel,pile)=>JSON.parse(execFileSync(process.env.PHP_BINARY||'php',[repo+'/tools/test_pile_panel_ui.php','--fixture',stage,viewer,String(sel),pile],{encoding:'utf8'}));
let checks=0;function ok(value,label){checks++;if(!value)throw Error(label);}
(async()=>{const browser=await chromium.launch({headless:true,...(process.env.BROWSER_EXECUTABLE?{executablePath:process.env.BROWSER_EXECUTABLE}:{})});try{
for(const width of [1600,800,375])for(const viewer of ['host','player']){
 const page=await browser.newPage({viewport:{width,height:900}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.route('https://piles.test/**',route=>{const u=new URL(route.request().url()),file=path.join(repo,'www',u.pathname);return route.fulfill({body:u.pathname.startsWith('/assets/')&&fs.existsSync(file)&&fs.statSync(file).isFile()?fs.readFileSync(file):'<html></html>',contentType:u.pathname.endsWith('.png')?'image/png':u.pathname.endsWith('.webp')?'image/webp':'text/html'});});
 await page.goto('https://piles.test/');const d=fixture('normal',viewer,1,'');const html=read('templates/screen/battle.tpl').replace(/<script[\s\S]*?<\/script>/g,'').replace(/{{(\w+)}}/g,(_,k)=>d[k]||'');
 await page.setContent('<style>'+read('www/assets/css/global.css')+'</style>'+html);
 await page.evaluate(()=>{document.documentElement.classList.add('battle-page');document.body.classList.add('battle-page');window.uiRequests=[];window.fetch=(url,options)=>{uiRequests.push({url:String(url),body:options?.body||''});return new Promise(()=>{});};});
 await page.addScriptTag({content:read('www/assets/js/battle-ui.js')});
 let sync=read('www/assets/js/battle-sync.js'),last=sync.lastIndexOf('    requestSnapshot(false);');sync=sync.slice(0,last)+'    window.testSnapshot = data => applySnapshot(data, uiRevision);'+sync.slice(last+'    requestSnapshot(false);'.length);
 await page.addScriptTag({content:sync.replace('var syncUrl = script.dataset.syncUrl || "";','var syncUrl = "https://piles.test/";')});
 let version=0;
 async function update(stage,selection=0,pile='own_grave') {const data=fixture(stage,viewer,selection,pile);await page.evaluate(({d,v})=>{const fragments={};for(const key of ['field_html','fly_zones_html','panel_html','piles_html','pile_reveal_html','info_panel_html'])fragments[key]=d[key]||'';if(!testSnapshot({sync_version:v,fragments,ui:d.ui}))throw Error('Snapshot rejected');},{d:data,v:++version});await page.waitForTimeout(80);}
 for(const pile of ['own_grave','own_exile','opp_grave','opp_exile']){
  await page.locator('.pile-open[href*="pile='+pile+'"]').click();await update('pile',0,pile);
  ok(await page.locator('.info-panel [data-pile-list]').isVisible(),'List in InfoPanel '+width+viewer+pile);
  ok(await page.locator('[data-battle-fragment="pile_reveal_html"]').innerHTML()==='','No pile below board');
  ok(await page.locator('.battle-selected-card').innerHTML()==='','Old card closed');
  const metrics=await page.locator('[data-pile-grid]').evaluate(grid=>({height:grid.clientHeight,scroll:grid.scrollHeight,rect:grid.getBoundingClientRect().toJSON(),items:Array.from(grid.children).filter(x=>!x.hidden).map(x=>x.getBoundingClientRect().toJSON())}));
  ok(metrics.items.length>0&&metrics.items.every(x=>x.bottom<=metrics.rect.bottom+1),'Cards fit without clipped rows '+width+JSON.stringify(metrics));
  ok(await page.locator('[data-pile-pagination] button').count()>1,'Long list has chips');
  const beforeIds=await page.locator('.battle-pile-entry:not([hidden]) .prepare-card-link').evaluateAll(nodes=>nodes.map(x=>x.dataset.instanceId));
  await page.locator('[data-pile-page="1"]').click();const afterIds=await page.locator('.battle-pile-entry:not([hidden]) .prepare-card-link').evaluateAll(nodes=>nodes.map(x=>x.dataset.instanceId));
  ok(JSON.stringify(beforeIds)!==JSON.stringify(afterIds),'Page chip changes cards');
  await update('pile',0,pile);ok(await page.locator('[data-pile-page="1"]').getAttribute('aria-current')==='page','Snapshot retains page');
  const target=page.locator('.battle-pile-entry:not([hidden]) .prepare-card-link').first(),id=Number(await target.getAttribute('data-instance-id'));await target.click();await update('pile',id,pile);
  ok(await page.locator('.battle-selected-card').isVisible()&&await page.locator('[data-pile-list]').isHidden(),'Tile opens selected card');
  await page.locator('.battle-selected-card a:has-text("К списку")').click();await update('pile',0,pile);
  ok(await page.locator('[data-pile-list]').isVisible(),'Back restores list');
 }
 await update('tasks',0,'own_grave');ok(await page.locator('.info-panel-context a[href*="incarnation-task"]').isVisible(),'Incarnation available beside list');ok(await page.locator('.info-panel-context a[href*="valhalla-task"]').isVisible(),'Valhalla available beside list');
 await update('normal',1,'');ok(await page.locator('.battle-selected-card').isVisible(),'Card before stack visible');
 await update('instant',1,'');ok(await page.locator('.battle-selected-card').isHidden(),'Stack closes card');ok(await page.locator('.info-panel-context a[href*="pass_turn_instant"]').isVisible(),'Stack pass available');
 await page.locator('.battle-field .card-link[href*="sel=2"]').click();await update('instant',2,'');ok(await page.locator('.battle-selected-card').isVisible(),'Can inspect a card during stack');
 await page.locator('[data-combat-back]').click();ok(await page.locator('.battle-selected-card').isHidden()&&await page.locator('.info-panel-context').isVisible(),'Return to stack');
 await update('combat',1,'own_grave');ok(await page.locator('.combat-panel-heading').innerText()==='Сражение','Combat remains unchanged');
 ok(errors.length===0,'No browser errors '+errors.join(';'));
 if(viewer==='host'&&process.env.PILE_SCREENSHOT_DIR){await update('pile',0,'own_grave');await page.screenshot({path:path.join(process.env.PILE_SCREENSHOT_DIR,'pile-panel-'+width+'.png')});}
 await page.close();
}
console.log('Pile/instant browser: '+checks+' checks passed');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1)});
