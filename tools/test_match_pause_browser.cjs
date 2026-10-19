const fs=require('fs'),path=require('path'),{execFileSync}=require('child_process');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const base=process.env.TURN_TIMER_TEST_URL;if(!base||!/^http:\/\/127\.0\.0\.1:\d+\/index\.php$/.test(base))throw Error('Disposable loopback server required');
const php=process.env.PHP_BINARY||'php';
const fixture=(...args)=>JSON.parse(execFileSync(php,[path.join(__dirname,'test_match_pause_ui_fixture.php'),...args.map(String)],{encoding:'utf8'}));
let checks=0;function ok(x,m){checks++;if(!x)throw Error(m);}
(async()=>{
 fixture('verify'); // Exact disposable database and InnoDB guards run before HTTP writes.
 if(fs.existsSync(path.join(__dirname,'..','.git')))throw Error('Disposable source tree required');
 const token=require('crypto').randomBytes(16).toString('hex'),name='pause-ui-probe-'+token+'.txt',probe=path.join(__dirname,'..','www',name);
 fs.writeFileSync(probe,token);
 try {const response=await fetch(new URL(name,base));ok(await response.text()===token,'HTTP serves the verified disposable source tree');}
 finally {fs.unlinkSync(probe);}
 const browser=await chromium.launch({headless:true,...(process.env.BROWSER_EXECUTABLE?{executablePath:process.env.BROWSER_EXECUTABLE}:{})});
 async function checkNoJs(game,mode,role="first",surrender=false){
  const context=await browser.newContext({javaScriptEnabled:false,viewport:{width:375,height:800}}),page=await context.newPage();
  try{
   await page.goto(base+'?'+role+'&game='+game);
   ok(await page.locator('.pause-noscript-notice').isVisible(),'No-JS notice '+mode);
   ok(await page.locator('[data-open-pause]:visible,[data-pause-command]:visible').count()===0,'No dead pause controls '+mode);
   ok(await page.locator('[data-http-resign]').isVisible(),'HTTP surrender available '+mode);
   if(mode!=='idle'){
    ok(await page.locator('[data-match-pause-mask]').isVisible()&&await page.locator('.battle-left').getAttribute('inert')!==null,'No-JS opaque inert board '+mode);
    ok(await page.locator('.battle-left a:visible,.battle-selected-card:visible').count()===0,'No card interaction '+mode);
   }
   if(surrender){
    const unknownId='nojs-unknown-'+game;
    const unknown=await page.request.get(base+'?first&game='+game+'&ajax=battle_command_status&html_resign=1&command_id='+unknownId);
    const html=await unknown.text();
    ok(html.includes('Результат сдачи не подтверждён')&&html.includes('command_id='+unknownId),'No-JS unknown keeps same-ID status recovery');
    ok(!html.includes('<form'),'No-JS unknown never offers automatic resubmit');
    await page.locator('input[name=expected_version]').evaluate(n=>n.value='0');
    await page.locator('[data-http-resign]').click();
    ok((await page.locator('body').innerText()).includes('Сдача не выполнена'),'No-JS CAS conflict is explicit');
    ok(fixture('read',game).state.status==='battle','No-JS conflict never executes surrender');
    await page.goto(base+'?'+role+'&game='+game);
    await page.locator('[data-http-resign]').click();
    ok(fixture('read',game).state.status==='game_over','No-JS HTTP surrender commits');
   }
  }finally{await context.close();}
 }
 try{
 for(const width of [1600,800,375]){
  const game=fixture('create').game_id;
  const a=await browser.newContext({viewport:{width,height:800}}),b=await browser.newContext({viewport:{width,height:800}});
  const host=await a.newPage(),guest=await b.newPage();
  const errors=[];[host,guest].forEach(p=>p.on('pageerror',e=>errors.push(e.message)));
  await Promise.all([host.goto(base+'?first&game='+game),guest.goto(base+'?second&game='+game)]);
  await host.locator('[data-open-pause]').waitFor({state:'visible'});
  if(width===375)await checkNoJs(game,'idle');
  ok(await host.locator('.pause-noscript-notice:visible').count()===0,'No fallback notice with JS');
  await host.locator('[data-open-pause]').click();
  await host.locator('#pause-minutes').fill('3');await host.locator('#pause-minutes').dispatchEvent('input');
  await host.waitForTimeout(2200);ok(await host.locator('#pause-minutes').inputValue()==='3','Slider survives polling');
  await host.locator('[data-picker-send]').click();
  await guest.locator('[data-pause-command=accept_pause]').waitFor({state:'visible'});
  await host.locator('[data-pause-command=cancel_pause_request]').waitFor({state:'visible'});
  ok((await guest.locator('[data-pause-description]').innerText()).includes('3 мин'),'Recipient sees duration');
  if(width===375){await checkNoJs(game,'requested');await checkNoJs(game,'requested','second');}
  ok(await host.locator('[data-open-pause]').isDisabled(),'Second request blocked');
  await guest.locator('[data-pause-command=reject_pause]').click();
  await host.waitForSelector('[data-battle-sync-root]:not([data-match-paused])');
  ok(await host.locator('[data-open-pause]').isDisabled(),'Initiator cooldown reflected');
  if(width===1600){
   await host.goto(base+'?first&game='+game+'&sel=1');
   // Exercise the real popup handler without requiring optional card-art files in fixtures.
   await host.evaluate(()=>{const button=document.createElement('button');button.className='panel-card-preview';button.dataset.cardOriginal='/assets/images/battle/surrender_160.png';button.innerHTML='<img src="/assets/images/battle/surrender_160.png" alt="Fixture card">';document.querySelector('.battle-selected-card').appendChild(button);});
   await host.locator('.panel-card-preview').click();
   ok(await host.locator('dialog.card-image-popup').evaluate(d=>d.open),'Card preview open before remote pause');
  }
  // Other player has an independent quota/cooldown.
  await guest.locator('[data-open-pause]').click();await guest.locator('[data-picker-send]').click();
  await host.locator('[data-pause-command=accept_pause]').waitFor({state:'visible'});
  if(width===1600)ok(!(await host.locator('dialog.card-image-popup').evaluate(d=>d.open)),'Remote pause closes top-layer card preview');
  // Drop the accept response AFTER the server committed it: recover through command_status.
  let dropped=false;
  await host.route('**/*',async route=>{
   const req=route.request();if(!dropped&&req.method()==='POST'&&new URLSearchParams(req.postData()).get('cmd')==='accept_pause'){
    dropped=true;await route.fetch();return route.abort();
   }return route.continue();
  });
  await host.locator('[data-pause-command=accept_pause]').click();
  await host.locator('[data-pause-command=pause_ready]').waitFor({state:'visible'});
  await guest.locator('[data-pause-command=pause_ready]').waitFor({state:'visible'});
  ok(dropped,'Lost command response exercised');
  ok(fixture('read',game).state.battle.match_pause.used.player===1,'Lost response did not charge twice');
  await Promise.all([host.reload(),guest.reload()]);
  await host.locator('[data-pause-command=pause_ready]').waitFor({state:'visible'});
  for(const p of [host,guest]){
   const metrics=await p.evaluate(()=>{
    const root=document.querySelector('[data-battle-sync-root]'),left=root.querySelector('.battle-left'),mask=root.querySelector('[data-match-pause-mask]');
    const m=mask.getBoundingClientRect(),board=root.querySelector('.battle-main').getBoundingClientRect();
    const controls=[root.querySelector('a.resign'),root.querySelector('[data-pause-command=pause_ready]')].map(n=>{const r=n.getBoundingClientRect();return r.width>0&&r.height>0&&r.top>=0&&r.bottom<=innerHeight&&r.right<=innerWidth;});
    return {inert:left.inert,hidden:getComputedStyle(left).visibility,opaque:getComputedStyle(mask).backgroundColor,cover:m.left<=board.left&&m.top<=board.top&&m.right>=board.right&&m.bottom>=board.bottom,controls,overflow:document.documentElement.scrollWidth>innerWidth};
   });
   ok(metrics.inert&&metrics.hidden==='hidden'&&metrics.opaque==='rgb(23, 23, 23)'&&metrics.cover,'Opaque complete board barrier');
   ok(metrics.controls.every(Boolean)&&!metrics.overflow,'Ready/surrender reachable at '+width);
   ok(await p.locator('[data-action=battle-log]').isDisabled(),'Log remains inactive');
   ok((await p.locator('.info-phase').innerText())==='Пауза','Pause phase visible');
  }
  const paused=fixture('read',game);
  const denied=await a.request.post(base+'?first&game='+game+'&ajax=battle_command',{form:{cmd:'end_turn',expected_version:String(paused.version),command_id:'ui-blocked-'+width}});
  ok((await denied.json()).command_status==='rejected','Direct API cannot bypass UI pause');
  if(width===375){
   await checkNoJs(game,'active');
  }
  // Stale polling state cannot reveal the board.
  let stale=false;
  await guest.route('**/*',async route=>{
   const req=route.request();if(!stale&&req.method()==='GET'&&req.url().includes('ajax=battle_sync')){
    stale=true;const response=await route.fetch();const data=await response.json();data.sync_version=0;if(data.match_pause)data.match_pause.mode='idle';
    return route.fulfill({json:data});
   }return route.continue();
  });
  await guest.waitForTimeout(2400);ok(stale&&await guest.locator('[data-match-paused="1"]').count()===1,'Stale sync cannot unlock board');
  if(process.env.PAUSE_SCREENSHOT_DIR)await host.screenshot({path:path.join(process.env.PAUSE_SCREENSHOT_DIR,'pause-active-'+width+'.png')});
  await host.locator('[data-pause-command=pause_ready]').click();
  await host.waitForFunction(()=>document.querySelector('[data-pause-command=pause_ready]')?.disabled&&document.body.textContent.includes('Вы готовы, ожидаем соперника'));
  ok(await host.locator('[data-match-paused="1"]').count()===1,'One ready cannot resume');
  await guest.locator('[data-pause-command=pause_ready]').click();
  await Promise.all([host.waitForSelector('[data-battle-sync-root]:not([data-match-paused])'),guest.waitForSelector('[data-battle-sync-root]:not([data-match-paused])')]);
  ok(fixture('read',game).state.battle.match_pause.last_resume.reason==='both_ready','Both ready resume');
  ok(errors.length===0,'No browser errors: '+errors.join('; '));
  await a.close();await b.close();console.log('PASS pause two-player UI '+width+'px');
 }
 // Request cancellation, lazy request timeout, active deadline and terminal surrender.
 for(const outcome of ['cancel','request_timeout','deadline','resign']){
  const game=fixture('create').game_id,context=await browser.newContext({viewport:{width:375,height:800}});
  const p=await context.newPage();await p.goto(base+'?first&game='+game);
  await p.locator('[data-open-pause]').click();await p.locator('[data-picker-send]').click();
  await p.locator('[data-pause-command=cancel_pause_request]').waitFor({state:'visible'});
  if(outcome==='cancel')await p.locator('[data-pause-command=cancel_pause_request]').click();
  if(outcome==='request_timeout')fixture('expire',game);
  if(outcome==='deadline'||outcome==='resign'){
   const other=await context.newPage();await other.goto(base+'?second&game='+game);await other.locator('[data-pause-command=accept_pause]').click();
   await p.locator('[data-pause-command=pause_ready]').waitFor({state:'visible'});
   if(outcome==='deadline')fixture('expire',game);
   else{await checkNoJs(game,'active','first',true);await p.reload();await p.waitForFunction(()=>document.body.textContent.includes('Поражение'));}
  }
  if(outcome!=='resign')await p.waitForSelector('[data-battle-sync-root]:not([data-match-paused])');
  const s=fixture('read',game).state;
  ok(s.battle.match_pause.mode==='idle','Exit '+outcome);
  if(outcome==='resign')ok(s.status==='game_over'&&s.battle.match_pause.last_resume===null,'Terminal surrender no resume');
  await context.close();
 }
 console.log('Match pause browser tests passed ('+checks+' checks)');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
