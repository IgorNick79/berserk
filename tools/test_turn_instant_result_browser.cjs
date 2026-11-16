// Native server-rendered fixture only. Requests are fulfilled locally, no working game.
const fs=require('fs'),path=require('path'),assert=require('assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE);
const fixture=JSON.parse(fs.readFileSync(process.env.UI_RESULT_FIXTURE)),assets=path.resolve('www/assets');
(async()=>{
 const browser=await chromium.launch({executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless:true});let checks=0;const errors=[];
 const check=(v,m)=>{assert.ok(v,m);checks++};
 try{for(const width of [1600,800]){
  const ctx=await browser.newContext({viewport:{width,height:900}});let initial='before';
  await ctx.route('**/*',async route=>{
   const url=new URL(route.request().url());
   if(url.pathname.startsWith('/assets/')){
    const file=path.resolve('www','.'+url.pathname);if(!file.startsWith(assets+path.sep))throw Error('Outside assets');
    const mime={'.css':'text/css','.js':'application/javascript','.svg':'image/svg+xml','.png':'image/png','.webp':'image/webp'}[path.extname(file)]||'application/octet-stream';
    return route.fulfill({contentType:mime,body:fs.existsSync(file)?fs.readFileSync(file):Buffer.alloc(0)});
   }
   return route.fulfill({contentType:'text/html',body:`<link rel="stylesheet" href="/assets/css/global.css"><body class="battle-page"><div class="battle-shell" data-battle-sync-root><div><a class="card-link" href="?game=9919&inspect=2" id="inspect">Осмотреть</a></div><aside class="info-panel" data-battle-fragment="info_panel_html">${fixture[initial]}</aside></div><script src="/assets/js/panel-secondary-view.js"></script><script src="/assets/js/battle-ui.js"></script></body>`});
  });
  const page=await ctx.newPage();page.on('pageerror',e=>errors.push(e.message));await page.goto('http://instant-result.test/?game=9919');
  await page.locator('[data-battle-ui-ready="true"]').waitFor();
  await page.evaluate(()=>document.querySelector('[data-battle-sync-root]').addEventListener('click',e=>{if(e.target.closest('#inspect'))e.preventDefault()}));
  check(await page.locator('.battle-selected-card').isVisible(),'Initial retained inspection is visible');
  // Reproduce the old contract: same instant context, no new result identity.
  await page.evaluate(html=>document.querySelector('.info-panel').innerHTML=html.replace(/data-instant-result="[^"]*"/,'data-instant-result=""'),fixture.result);
  check(await page.locator('.info-panel-context').isHidden(),'Old contract hides result behind selected card');
  await page.evaluate(html=>document.querySelector('.info-panel').innerHTML=html,fixture.result);
  await page.locator('.info-panel-context').waitFor({state:'visible'});
  check(await page.locator('.battle-selected-card').isHidden(),'New result overrides stale inspection');
  check((await page.locator('.info-panel-context').innerText()).includes('HP 19 -> 18'),'Visible result explains exact HP change');
  check(await page.getByRole('link',{name:'Продолжить',exact:true}).isVisible(),'Required confirmation is reachable');
  await page.locator('#inspect').click();check(await page.locator('.battle-selected-card').isVisible(),'Explicit inspection after result still works');
  await page.evaluate(html=>document.querySelector('.info-panel').innerHTML=html,fixture.result);
  check(await page.locator('.battle-selected-card').isVisible(),'Same snapshot preserves explicit inspection');
  await page.locator('[data-panel-card-close]').click();await page.locator('.info-panel-context').waitFor({state:'visible'});
  check(await page.getByRole('link',{name:'Продолжить',exact:true}).isVisible(),'Close card returns to result');
  initial='result';await page.reload();await page.locator('[data-battle-ui-ready="true"]').waitFor();
  check(await page.locator('.info-panel-context').isVisible()&&await page.locator('.battle-selected-card').isHidden(),'F5 shows result instead of retained card');
  await ctx.close();
 }check(errors.length===0,'No JS errors: '+errors.join(';'));console.log(`Turn instant result browser: ${checks} checks passed`);}finally{await browser.close()}
})().catch(e=>{console.error(e);process.exitCode=1});
