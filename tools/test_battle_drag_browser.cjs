const fs = require('fs'), path = require('path'), {execFileSync} = require('child_process');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/mi/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const repo = path.resolve(__dirname, '..'), read = f => fs.readFileSync(path.join(repo, f), 'utf8');
const fixture = JSON.parse(execFileSync(process.env.PHP_BINARY || 'C:/OSPanel/modules/php/PHP_8.1/php.exe', [path.join(repo,'tools/test_battle_drag.php'),'--fixture'], {encoding:'utf8'}));
const out = process.env.DRAG_ARTIFACT_DIR || path.join(repo,'tmp','drag-stage1');
fs.mkdirSync(out, {recursive:true});
const assert = (ok,msg) => {if (!ok) throw Error(msg);};
(async () => {
 const browser = await chromium.launch({headless:true, executablePath:process.env.BROWSER_EXECUTABLE || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
 const results = [];
 async function setup(kind='selected',options={}) {
  const page = await browser.newPage({viewport:{width:1600,height:1000}});
  let requests=[], errors=[], version=1, current=fixture.cases[kind], posts=0, delay=options.delay||0;
  let last=null, recovery=!!options.fail, releaseRecovery=false, selections=0;
  page.on('pageerror', e=>errors.push(e.message));
  const snapshot = d => ({...Object.fromEntries(Object.entries(fixture.snapshots[String(d.ui.sel)] || fixture.snapshots['1']).filter(([k])=>k!=='ui')), ui:d.ui,
   fragments:Object.fromEntries(['field_html','fly_zones_html','panel_html','piles_html','pile_reveal_html','info_panel_html'].map(k=>[k,d[k]||'']))});
  const envelope = (d=current) => ({...snapshot(d),ok:true,type:'snapshot',sync_version:version,status:'battle'});
  await page.route('https://drag.test/**', async r=>{
   const u=new URL(r.request().url()), ajax=u.searchParams.get('ajax');
   if (ajax==='battle_command') {
    last=new URLSearchParams(r.request().postData());requests.push(Object.fromEntries(last));posts++;version++;
    if(recovery){await r.abort('failed');return;}
    await r.fulfill({json:{...envelope(),ok:!options.reject, error:options.reject?'Rejected test command':undefined,
      command_id:last.get('command_id'),command_status:options.reject?'rejected':'committed',terminal:true}});return;
   }
   if(ajax==='battle_command_status') {
    await r.fulfill({json:{ok:true,type:'command_status',sync_version:version,command_id:last.get('command_id'),command_status:releaseRecovery?'committed':'processing',terminal:releaseRecovery}});return;
   }
   if(ajax==='battle_sync') {
    if(u.searchParams.get('force_snapshot')==='1') {
     selections++;
     if(delay) await new Promise(resolve=>setTimeout(resolve,delay));
     const sel=u.searchParams.get('sel')||'0', mode=u.searchParams.get('mode');
     if(mode==='action:aimed_shot') current=fixture.cases.special;
     else if(kind==='pending'||kind==='inactive') current=fixture.cases[kind];
     else current={...fixture.cases.selected, ...fixture.snapshots[sel]?.fragments,ui:fixture.snapshots[sel]?.ui||fixture.cases.selected.ui};
     await r.fulfill({json:envelope()});
    } else await r.fulfill({json:{ok:true,type:'no_change',sync_version:version}});
    return;
   }
   if(u.pathname.startsWith('/assets/')) {
    const f=path.join(repo,'www',u.pathname);
    if(fs.existsSync(f)) return r.fulfill({body:fs.readFileSync(f),contentType:f.endsWith('.svg')?'image/svg+xml':f.endsWith('.webp')?'image/webp':f.endsWith('.css')?'text/css':'image/png'});
    return r.fulfill({status:404,body:''});
   }
   await r.fulfill({body:'<html></html>',contentType:'text/html'});
  });
  await page.goto('https://drag.test/?first&game=981');
  const template=read('templates/screen/battle.tpl').replace(/<script[\s\S]*?<\/script>/g,'').replace(/<link[^>]+>/g,'');
  await page.setContent('<html class="battle-page"><head><style>'+read('www/assets/css/global.css')+'\n'+read('www/assets/css/battle-mini-card.css')+'\n'+read('www/assets/css/battle-mini-nameplate.css')+'</style></head><body class="battle-page"><div class="screen">'+template.replace(/{{(\w+)}}/g,(_,k)=>current[k]||'')+'</div></body></html>');
  await page.addScriptTag({content:read('www/assets/js/battle-mini-card.js')});
  await page.addScriptTag({content:read('www/assets/js/battle-drag.js')});
  let sync=read('www/assets/js/battle-sync.js');
  const seam=sync.lastIndexOf('    requestSnapshot(false);');
  sync=sync.slice(0,seam)+`    window.testDragSnapshot = function(data) { return applySnapshot(data, uiRevision); };
    window.testDragHeartbeat = updateTurnTimer;\n`+sync.slice(seam);
  await page.evaluate(({sync,ui})=>{
   const script=document.createElement('script');
   script.dataset.syncUrl='https://drag.test/?first&game=981&ajax=battle_sync';
   script.dataset.version='1';script.dataset.sel=String(ui.sel);script.dataset.mode=ui.mode;
   script.dataset.modeExplicit=ui.mode_explicit?'1':'0';script.textContent=sync;document.body.append(script);
  },{sync,ui:current.ui});
  await page.waitForTimeout(60);
  const card=id=>page.locator('[data-drag-card="'+id+'"]').first();
  async function point(locator) {const r=await locator.boundingBox();assert(r,'Visible target');return {x:r.x+r.width/2,y:r.y+r.height*.68};}
  async function begin(id=1) {const p=await point(card(id));await page.mouse.move(p.x,p.y);await page.mouse.down();await page.mouse.move(p.x+7,p.y,{steps:2});return p;}
  async function to(locator) {const p=await point(locator);await page.mouse.move(p.x,p.y,{steps:8});return p;}
  async function end() {await page.mouse.up();await page.waitForTimeout(160);}
  async function close(label) {assert(!errors.length, errors.join('\n'));results.push(label);console.log('PASS '+label);await page.close();}
  return {page,card,point,begin,to,end,close,requests,envelope,posts:()=>posts,selections:()=>selections,
    release:()=>{releaseRecovery=true;},setDelay:n=>{delay=n;}};
 }
 try {
  let t=await setup();
  assert(await t.card(1).evaluate(e=>getComputedStyle(e).cursor==='grab'),'Grab cursor');
  const original=await t.card(1).evaluate(e=>e.outerHTML);await t.begin();
  await t.to(t.page.locator('.move-target').first());
  assert(await t.page.locator('.battle-drag-target').count()===1,'Move target highlight');
  assert(await t.card(1).evaluate(e=>e.outerHTML)===original,'Original unchanged before response');
  assert(await t.page.locator('.battle-drag-ghost').evaluate(e=>getComputedStyle(e).pointerEvents==='none'),'Ghost transparent to hit testing');
  await t.page.screenshot({path:path.join(out,'drag-move.png')});
  await t.end();assert(t.requests.length===1&&t.requests[0].cmd==='move','Move through journal pipeline');
  assert(t.requests[0].card_id==='1'&&t.requests[0].expected_version==='1'&&t.requests[0].command_id,'Version/source/journal preserved');
  await t.close('1 movement + original DOM + pointer-events + one command');

  t=await setup();await t.begin();await t.to(t.card(2));await t.page.screenshot({path:path.join(out,'drag-strike.png')});await t.end();
  assert(t.requests.length===1&&t.requests[0].cmd==='strike'&&t.requests[0].target_id==='2','Enemy strike');
  await t.close('2 ordinary strike against hidden enemy without hidden data');

  for(const which of ['ally','invalid','outside']) {
   t=await setup();await t.begin();
   if(which==='ally')await t.to(t.card(5));else await t.page.mouse.move(which==='outside'?1550:800,which==='outside'?950:100);
   await t.end();assert(!t.requests.length,'No action on '+which);assert(!await t.page.locator('.battle-drag-ghost').count(),'Clean ghost');
   await t.close('3 invalid drop: '+which);
  }
  t=await setup('unselected',{delay:180});await t.begin();await t.page.waitForTimeout(240);await t.to(t.page.locator('.move-target').first());await t.end();
  assert(t.requests.length===1&&t.requests[0].cmd==='move','Unselected awaits targets');await t.close('4 unselected source waits for matching snapshot');
  t=await setup('unselected',{delay:350});await t.begin();await t.page.mouse.move(600,200);await t.end();await t.page.waitForTimeout(400);
  assert(!t.requests.length&&!await t.page.locator('.battle-drag-ghost').count(),'Early release no command');await t.close('5 release before snapshot cancels');

  for(const kind of ['special','pending','inactive','closed','combat','pause','expired']) {
   t=await setup(kind);await t.begin();assert(!await t.page.locator('.battle-drag-ghost').count(),'Drag disabled '+kind);await t.end();
   assert(!t.requests.length,'No command '+kind);await t.close('6 disabled '+kind);
  }
  t=await setup();await t.begin();await t.page.evaluate(data=>window.testDragSnapshot(data),t.envelope());
  assert(!await t.page.locator('.battle-drag-ghost').count(),'Replacement cancels');await t.end();assert(!t.requests.length,'Stale drop no command');await t.close('7 snapshot replacement cancels');
  t=await setup();await t.begin();await t.page.evaluate(()=>window.testDragHeartbeat({sync_version:1}));
  assert(await t.page.locator('.battle-drag-ghost').count()===1,'Timer heartbeat survives');await t.to(t.page.locator('.move-target').first());await t.end();assert(t.requests.length===1,'Heartbeat retains targets');await t.close('8 unchanged timer preserves drag');
  t=await setup();await t.begin();await t.page.evaluate(()=>window.testDragHeartbeat({sync_version:2}));await t.end();assert(!t.requests.length,'Version change cancels');await t.close('9 state change cancels');
  t=await setup();await t.begin();await t.page.evaluate(()=>window.testDragHeartbeat({sync_version:1,match_pause:{mode:'requested'}}));await t.end();assert(!t.requests.length,'Pause barrier cancels');await t.close('10 pause cancels');

  t=await setup('unselected');await t.card(1).locator('.mini-hp').click();await t.page.waitForTimeout(140);
  assert(t.selections()===1&&!t.requests.length,'First click only selects');await t.card(2).click();await t.page.waitForTimeout(140);
  assert(t.requests.length===1&&t.requests[0].cmd==='strike','Second click unchanged');await t.close('11 two-click control preserved');
  t=await setup('unselected');await t.card(5).locator('.mini-hp').click();await t.page.waitForTimeout(140);
  await t.card(1).locator('.mini-hp').click();await t.page.waitForTimeout(140);
  assert(t.selections()===2&&!t.requests.length,'Independent subsequent clicks work');await t.close('12 independent clicks not suppressed');

  t=await setup();await t.begin();const dest=await t.to(t.card(2));await t.end();
  await t.page.evaluate(p=>document.elementFromPoint(p.x,p.y).dispatchEvent(new MouseEvent('click',{bubbles:true,cancelable:true,detail:1,clientX:p.x,clientY:p.y})),dest);
  assert(t.requests.length===1,'Trailing click cannot duplicate');
  await t.page.mouse.move(1400,50);await t.card(1).locator('.mini-hp').hover();await t.page.waitForTimeout(340);
  assert(await t.page.locator('.battle-mini-zoom.is-open').count()===1,'Zoom restored');assert(await t.page.locator('#battle-mini-tooltip').isVisible(),'Tooltip restored');
  await t.close('13 trailing click suppressed; hover + tooltip restored');

  t=await setup('fly');await t.begin(3);await t.to(t.card(6));await t.end();assert(t.requests.length===1&&t.requests[0].cmd==='strike','Fly enemy strike uses existing link');await t.close('14 flying creature strike');
  t=await setup();await t.begin();await t.to(t.page.locator('.fly-slot').first());await t.end();assert(!t.requests.length,'No movement into unsupported flying slot');await t.close('15 empty flying zone has no movement action');
  t=await setup('selected',{fail:true});await t.begin();await t.to(t.page.locator('.move-target').first());await t.end();
  await t.begin();assert(!await t.page.locator('.battle-drag-ghost').count(),'Recovery blocks drag');await t.end();assert(t.posts()===1,'No duplicate POST');
  t.release();await t.page.waitForFunction(()=>document.querySelector('[data-battle-drag-ready] [data-drag-card="1"]'),null,{timeout:10000});assert(t.posts()===1,'Recovery never resends command');
  await t.begin();assert(await t.page.locator('.battle-drag-ghost').count()===1,'Drag returns after journal recovery');await t.page.keyboard.press('Escape');await t.end();await t.close('16 network failure, journal recovery, single POST');
  t=await setup('selected',{reject:true});await t.begin();await t.to(t.page.locator('.move-target').first());await t.end();
  assert(!await t.page.locator('.battle-drag-ghost').count(),'Rejected command cleans drag');await t.close('17 server rejection cleanup');
  t=await setup('unselected');const p=await t.point(t.card(1));await t.page.mouse.move(p.x,p.y);await t.page.mouse.down();await t.page.mouse.move(p.x+3,p.y);await t.end();
  assert(!t.requests.length&&t.selections()===1,'Subthreshold movement remains a normal click');await t.close('18 drag threshold preserves ordinary click');
  t=await setup();await t.begin();await t.page.evaluate(()=>{const f=document.querySelector('[data-battle-fragment=field_html]');f.innerHTML=f.innerHTML;});await t.page.waitForTimeout(30);await t.end();assert(!t.requests.length,'External replacement cancels');await t.close('19 unexpected DOM replacement');
  t=await setup();await t.begin();await t.page.evaluate(()=>document.querySelector('a[href*="mode=action"]').click());await t.page.waitForTimeout(120);
  assert(!await t.page.locator('.battle-drag-ghost').count()&&!await t.page.locator('[data-battle-drag-ready]').count(),'Special selection cancels and disables');await t.end();assert(!t.requests.length,'No special command from drag');await t.close('20 selecting special action preserves mode');
  t=await setup();await t.begin();await t.page.evaluate(()=>{const a=document.querySelector('.move-target a');const u=new URL(a.href);u.searchParams.set('card_id','5');a.href=u;});await t.to(t.page.locator('.move-target').first());await t.end();assert(!t.requests.length,'Wrong source link refused');await t.close('21 matching card_id required');
  t=await setup();const icon=t.card(1).locator('[data-mini-hint]');const ip=await t.point(icon);await t.page.mouse.move(ip.x,ip.y);await t.page.mouse.down();await t.page.mouse.move(ip.x+7,ip.y);assert(!await t.page.locator('.battle-drag-ghost').count(),'Indicator never starts drag');await t.end();await t.page.keyboard.press('Escape');await icon.hover();await t.page.waitForTimeout(300);await icon.click();assert(await t.page.locator('#battle-mini-tooltip[data-kind=indicator]').isVisible(),'Effect tooltip still opens');assert(!t.requests.length,'Indicator never sends command');await t.close('22 interactive indicator and effects tooltip');
  t=await setup();await t.page.evaluate(()=>{const b=document.createElement('button');b.id='outside-test';b.textContent='Outside';b.style.cssText='position:fixed;left:1450px;top:950px;z-index:2000';b.onclick=()=>window.outsideClicks=(window.outsideClicks||0)+1;document.body.append(b);});await t.begin();await t.page.mouse.move(900,50);await t.end();await t.page.locator('#outside-test').click();assert(await t.page.evaluate(()=>window.outsideClicks)===1,'Independent outside click allowed');await t.close('23 independent click outside battle root');
  fs.writeFileSync(path.join(out,'results.json'),JSON.stringify(results,null,2));console.log(results.join('\n')+'\nPASS '+results.length+' browser scenarios');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
