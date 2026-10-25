const fs=require('fs'),path=require('path'),{execFileSync}=require('child_process');
const {chromium}=require('C:/Users/mi/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const root=path.resolve(__dirname,'..'),read=f=>fs.readFileSync(path.join(root,f),'utf8'),check=(v,m)=>{if(!v)throw Error(m)};
const fixture=(stage,viewer,inspect=0)=>JSON.parse(execFileSync('C:/OSPanel/modules/php/PHP_8.1/php.exe',[root+'/tools/test_pending_card_inspection.php','--fixture',stage,viewer,String(inspect)],{encoding:'utf8'}));
const out=path.resolve(root,'../swamp-queen-validation/pending-inspection');fs.mkdirSync(out,{recursive:true});
(async()=>{const browser=await chromium.launch({executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless:true});
try{for(const width of [1600,800,375])for(const viewer of ['host','player']){
 const page=await browser.newPage({viewport:{width,height:900}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.route('https://inspection.test/**',r=>r.fulfill({body:'<html></html>',contentType:'text/html'}));await page.goto('https://inspection.test/');
 const d=fixture('action',viewer),html=read('templates/screen/battle.tpl').replace(/<script[\s\S]*?<\/script>/g,'').replace(/<link[^>]+>/g,'').replace(/{{(\w+)}}/g,(_,k)=>d[k]||'');
 await page.setContent('<style>'+['global.css','battle-ui-skins.css','battle-mini-card.css','battle-coordinates.css'].map(f=>read('www/assets/css/'+f)).join('\n')+'</style>'+html);
 await page.evaluate(()=>{document.body.className='battle-page';window.requests=[];window.fetch=(u,o)=>{requests.push({url:String(u),body:String(o?.body||'')});return new Promise(()=>{});};});
 await page.addScriptTag({content:read('www/assets/js/battle-ui.js')});
 await page.addScriptTag({content:read('www/assets/js/creature-choice.js')});
 let sync=read('www/assets/js/battle-sync.js'),last=sync.lastIndexOf('    requestSnapshot(false);');sync=sync.slice(0,last)+'    window.testSnapshot = data => applySnapshot(data, uiRevision);'+sync.slice(last+'    requestSnapshot(false);'.length);
 await page.addScriptTag({content:sync.replace('var syncUrl = script.dataset.syncUrl || "";','var syncUrl = "https://inspection.test/";')});
 let version=0;
 async function update(stage,inspect=0){const d=fixture(stage,viewer,inspect);await page.evaluate(({d,v})=>{const fragments={};for(const key of ['field_html','fly_zones_html','panel_html','piles_html','pile_reveal_html','info_panel_html'])fragments[key]=d[key]||'';if(!testSnapshot({sync_version:v,fragments,ui:d.ui}))throw Error('snapshot rejected');},{d,v:++version});await page.waitForTimeout(30);}
 const card=page.locator('.battle-selected-card'),context=page.locator('.info-panel-context');
 for(const stage of ['action','pending','prophecy','combat','instant']){
  await update(stage);check(await card.isHidden(),'source hidden '+stage);
  const eventPanel=stage==='instant'?page.locator('.info-panel-instants'):context;
  check(await eventPanel.isVisible(),'required information visible '+stage);
  await page.locator('.battle-field .card-link[href*="inspect=2"]').first().click();
  check(await page.evaluate(()=>requests.every(r=>!r.body)),'inspection never posts commands');
  const req=await page.evaluate(()=>requests.at(-1).url);check(req.includes('inspect=2')&&req.includes('sel=1'),'independent inspected/source state');
  await update(stage,2);check(await card.isVisible()&&await card.locator('[data-panel-card-id]').getAttribute('data-panel-card-id')==='2','requested card visible '+stage);
  check(await eventPanel.isHidden(),'card overlays required context '+stage);
  await update(stage,2);check(await card.isVisible(),'poll keeps inspection '+stage);
  await card.locator('[data-panel-card-close]').focus();await page.keyboard.press('Enter');
  check(await card.isHidden()&&await eventPanel.isVisible(),'cross restores required context '+stage);
  await update(stage,2);check(await card.isHidden()&&await eventPanel.isVisible(),'poll keeps closed '+stage);
  await page.locator('.fly-zone .card-link[href*="inspect=3"]').first().click();await update(stage,3);
  check(await card.isVisible(),'flying card inspectable '+stage);
  await card.locator('[data-panel-card-close]').focus();await page.keyboard.press('Enter');check(await eventPanel.isVisible(),'flying close restores context');

 }
 await update('action');check(await context.locator('input[name=target_id]').count()>0,'target list survives inspection');
 await page.screenshot({path:path.join(out,`targets-${viewer}-${width}.png`)});
 check(!errors.length,errors.join('\n'));console.log(`PASS ${width}px ${viewer}: action/pending/prophecy/combat/instant, field+fly, close+poll, no commands`);await page.close();
}}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1)});
