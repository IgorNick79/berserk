const fs=require('fs'),path=require('path'),{execFileSync}=require('child_process');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'C:/Users/mi/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const repo=path.resolve(__dirname,'..'),read=f=>fs.readFileSync(path.join(repo,f),'utf8');
const fixture=(stage,viewer,sel)=>JSON.parse(execFileSync(process.env.PHP_BINARY||'C:/OSPanel/modules/php/PHP_8.1/php.exe',[repo+'/tools/test_combat_panel_ui.php','--fixture',stage,viewer,String(sel)],{encoding:'utf8'}));
const assert=(ok,msg)=>{if(!ok)throw Error(msg)};
const output=process.env.PANEL_ARTIFACT_DIR||path.join(repo,'tmp','panel-priority');fs.mkdirSync(output,{recursive:true});
(async()=>{const browser=await chromium.launch({executablePath:process.env.BROWSER_EXECUTABLE||'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless:true});try{
for(const width of [1600,800,375])for(const viewer of ['host','player']){
 const page=await browser.newPage({viewport:{width,height:900}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.route('https://combat.test/**',r=>{const u=new URL(r.request().url()),file=path.join(repo,'www',u.pathname);return r.fulfill({body:u.pathname.startsWith('/assets/')&&fs.existsSync(file)&&fs.statSync(file).isFile()?fs.readFileSync(file):'<html></html>',contentType:u.pathname.endsWith('.svg')?'image/svg+xml':u.pathname.endsWith('.png')?'image/png':u.pathname.endsWith('.webp')?'image/webp':'text/html'});});
 await page.goto('https://combat.test/');
 const initial=fixture('normal',viewer,1);
 const html=read('templates/screen/battle.tpl').replace(/<script[\s\S]*?<\/script>/g,'').replace(/<link[^>]+>/g,'').replace(/{{(\w+)}}/g,(_,k)=>initial[k]||'');
 await page.setContent('<style>'+read('www/assets/css/global.css')+'\n'+read('www/assets/css/battle-mini-card.css')+'</style>'+html);
 await page.evaluate(()=>{document.documentElement.classList.add('battle-page');document.body.classList.add('battle-page');window.uiRequests=[];window.fetch=(url,opts)=>{uiRequests.push({url:String(url),body:opts?.body||''});return new Promise(()=>{});};});
 await page.addScriptTag({content:read('www/assets/js/battle-ui.js')});
 let sync=read('www/assets/js/battle-sync.js');const last=sync.lastIndexOf('    requestSnapshot(false);');sync=sync.slice(0,last)+'    window.testSnapshot = data => applySnapshot(data, uiRevision);'+sync.slice(last+'    requestSnapshot(false);'.length);
 await page.addScriptTag({content:sync.replace('var syncUrl = script.dataset.syncUrl || "";','var syncUrl = "https://combat.test/";')});
 let version=0;
 async function update(stage,sel){const d=fixture(stage,viewer,sel);await page.evaluate(({d,v})=>{const fragments={};for(const key of ['field_html','fly_zones_html','panel_html','piles_html','pile_reveal_html','info_panel_html'])fragments[key]=d[key]||'';if(!testSnapshot({sync_version:v,fragments,ui:d.ui}))throw Error('Snapshot failed');},{d,v:++version});await page.waitForTimeout(20);}
 const card=page.locator('.battle-selected-card'),context=page.locator('.info-panel-context');
 const clickCard=id=>page.locator('.battle-field .card-link[href*="sel='+id+'"]').first().click();
 assert(await card.isVisible(),'Normal card visible');
 await clickCard(5);assert(await card.isHidden(),'Previous card hidden immediately during selection request');
 await page.waitForTimeout(120);assert(await card.isHidden(),'Previous card stays hidden while network is delayed');
 await update('normal',1);assert(await card.isHidden(),'Old selection fragment cannot reopen previous creature');
 await update('normal',5);assert(await card.isVisible()&&await card.locator('[data-panel-card-id]').getAttribute('data-panel-card-id')==='5','Matching snapshot displays only requested creature');
 await update('normal',1);assert(await card.isVisible(),'Completed request does not block later selection by drag/snapshot');
 await update('normal',5);
 await card.locator('.panel-card-media').hover();await card.locator('[data-panel-card-close]').click();
 await clickCard(1);assert(await card.isHidden(),'Closed previous card never flashes on next selection');
 await update('normal',1);assert(await card.isVisible()&&await card.locator('[data-panel-card-id]').getAttribute('data-panel-card-id')==='1','New creature opens after response');
 const close=card.locator('[data-panel-card-close]');
 await page.mouse.move(20,20);await page.waitForTimeout(220);
 assert(await close.evaluate(e=>getComputedStyle(e).opacity==='0'),'Cross hidden at rest');
 await card.locator('.panel-card-media').hover();await page.waitForTimeout(220);
 assert(await close.evaluate(e=>getComputedStyle(e).opacity==='1'&&getComputedStyle(e).transitionProperty==='opacity'),'Cross fades in');
 const requests=await page.evaluate(()=>uiRequests.length);await close.click();
 assert(await card.isHidden(),'Cross hides full creature panel');assert(await page.evaluate(()=>uiRequests.length)===requests,'Cross makes no command or UI request');
 await update('normal',1);assert(await card.isHidden(),'Closed state survives polling');
 await clickCard(1);await update('normal',1);assert(await card.isVisible(),'Same card can be reopened');
 await close.focus();await page.keyboard.press('Enter');assert(await card.isHidden(),'Keyboard close');
 for(const stage of ['combat','pending','instant','instant_result','message']){
  await clickCard(1);await update('normal',1);assert(await card.isVisible(),'Normal inspection before '+stage);
  await update(stage,1);
  const eventPanel=(stage==='instant'||stage==='instant_result')?page.locator('.info-panel-instants'):context;
  assert(await card.isHidden()&&await eventPanel.isVisible(),'Priority closes old creature: '+stage);
  if(stage!=='instant'&&stage!=='instant_result'){
   assert(await context.locator('.panel-card-heading').innerText()==='Информация','Information heading');
   assert(await context.locator('.panel-information-icon').evaluate(e=>e.complete&&e.naturalWidth>0),'Provided icon loads');
  }
  assert(await page.locator('.info-panel-footer').isVisible(),'Priority never hides footer');
  await clickCard(1);await update(stage,1);assert(await card.isHidden()&&await eventPanel.isVisible(),'Card cannot hide required UI: '+stage);
  if(stage==='instant_result')assert(await eventPanel.locator('a[href*="cmd=turn_instant_result_ok"]').isVisible(),'OK is visible');
  if(stage==='combat')assert(await context.locator('a[href*="cmd=confirm_strike"]').isVisible(),'Combat confirmation visible');
  if(stage==='instant'&&viewer==='host')assert(await eventPanel.locator('a[href*="cmd=pass_turn_instant"]').isVisible(),'Instant pass visible');
  await update(stage,1);assert(await card.isHidden(),'Polling preserves priority');
  if(stage==='instant_result'&&viewer==='host'&&(width===1600||width===375))await page.screenshot({path:path.join(output,'information-'+width+'.png')});
  await update('normal',1);assert(await card.isHidden(),'Completed pending does not reopen old image');
 }
 await update('closed',1);
 assert(await page.locator('.field-cell.closed,.fly-card.closed').count()===0,'No closed class on live cells');
 const mini=page.locator('[data-mini-card="1"]').first();
 assert(await mini.evaluate(e=>getComputedStyle(e).opacity==='1'&&getComputedStyle(e.querySelector('.mini-art')||e.appendChild(Object.assign(document.createElement('img'),{className:'mini-art'}))).filter==='saturate(0.3) brightness(0.88)'&&getComputedStyle(e,'::after').backgroundColor==='rgb(255, 255, 255)'&&getComputedStyle(e,'::after').opacity==='0.3'&&getComputedStyle(e,'::after').pointerEvents==='none'),'Closed overlay only, no dimmed HP/frame');
 await update('normal',1);
 if(viewer==='host'){
  const enemy=page.locator('.field-cell.attack-target').first();assert(await enemy.evaluate(e=>getComputedStyle(e).backgroundColor==='rgba(205, 48, 43, 0.45)'),'Enemy target filled red');
  await update('friendly_targets',1);const ally=page.locator('.field-cell.attack-target').filter({has:page.locator('[data-drag-owner="own"]')}).first();assert(await ally.evaluate(e=>getComputedStyle(e).backgroundColor==='rgba(224, 174, 40, 0.46)'),'Own target filled yellow');
 }
 assert(!errors.length,errors.join('\n'));
 console.log('PASS '+width+'px '+viewer+': hover/keyboard close, polling, all pending + OK, instant priority, closed overlay and target fills');await page.close();
}
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1)});
