// Only disposable HTTP fixtures. No working game, account or browser profile.
const fs=require('fs'),path=require('path'),assert=require('assert/strict');
const root=process.env.IR_TEST_ROOT;
if(!root||!root.replaceAll('\\','/').match(/\/(instant-stage4-validation|instant-reservation-stage2-validation)$/))throw Error('Isolated fixture root required');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||path.join(root,'runtime/node_modules/playwright'));
const e=JSON.parse(fs.readFileSync(path.join(root,'sql-evidence.json'))),http=JSON.parse(fs.readFileSync(path.join(root,'stage4-http-evidence.json'))),fixtures=http.ui_fixtures;
if(!http.created_at||Date.now()/1000-http.created_at>120)throw Error('Run test_instant_stage4_http.php first for fresh timers');
(async()=>{
 const browser=await chromium.launch({executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless:true});
 let checks=0;const results=[],errors=[];
 const check=(v,msg)=>{assert.ok(v,msg);checks++};
 async function open(actor,game,width,inspect=false){
  const ctx=await browser.newContext({viewport:{width,height:900}});
  await ctx.addCookies([{name:'BERSERKAUTH5A',value:e.http_fixture.sessions[actor].cookie.split('=')[1],domain:'127.0.0.1',path:'/'}]);
  const page=await ctx.newPage();page.on('pageerror',err=>errors.push(err.message));
  let commands=0;page.on('request',r=>{if(r.method()==='POST'&&r.url().includes('battle_command'))commands++});
  await page.goto(`http://127.0.0.1:18881/index.php?game=${game}${inspect?'&inspect=4':''}`);
  await page.locator('[data-battle-ui-ready="true"]').waitFor();return {ctx,page,commands:()=>commands};
 }
 async function refreshPanel(page){
  await page.evaluate(async()=>{
   const url=new URL(location.href);url.searchParams.set('ajax','battle_sync');url.searchParams.set('force_snapshot','1');
   const data=await (await fetch(url)).json();if(!data.fragments)throw Error('Fresh real snapshot required');
   document.querySelector('[data-battle-fragment="info_panel_html"]').innerHTML=data.fragments.info_panel_html;
  });await page.waitForTimeout(70);
 }
 async function layout(page){
  const d=await page.evaluate(()=>{
   const host=document.querySelector('.info-panel'),c=host.querySelector('.info-panel-content'),f=host.querySelector('.info-panel-footer'),i=host.querySelector('[data-instant-control]');
   const r=n=>{const b=n.getBoundingClientRect();return{x:b.x,y:b.y,height:b.height,right:b.right,bottom:b.bottom}};
   return{open:i.open,c:r(c),f:r(f),m:r(host.querySelector('.info-panel-main')),i:r(i.querySelector('summary')),old:!!f.querySelector('[data-instant-control]'),inScroll:c.contains(i),count:host.querySelectorAll('[data-instant-control]').length};
  });
  check(d.count===1&&!d.old&&!d.inScroll,'One floating control outside footer and scrollport');
  check(d.open ? d.c.height<d.m.height : Math.abs(d.c.height-d.m.height)<2,'Only expanded bottom block takes content height');
  check(d.open ? d.i.height===0 : d.i.bottom<d.f.y&&d.i.right<=d.c.right,'Lightning disappears while bottom controls are open');
  const height=await page.evaluate(()=>{const footer=document.querySelector('.info-panel-footer'),layer=document.querySelector('.instant-control-layer');const before=footer.getBoundingClientRect().height;layer.hidden=true;const after=footer.getBoundingClientRect().height;layer.hidden=false;return{before,after}});
  check(height.before===height.after,'Floating control consumes zero footer height, including wrapped tablet controls');
 }
 async function fits(page){
  await page.locator('.instant-control-panel').waitFor({state:'visible'});
  const r=await page.locator('.instant-control-panel').boundingBox(),v=page.viewportSize(),f=await page.locator('.info-panel-footer').boundingBox(),c=await page.locator('.info-panel-content').boundingBox();
  check(r&&r.x>=0&&r.y>=0&&r.x+r.width<=v.width&&r.y+r.height<=v.height,'Bottom block fits viewport');
  check(r.y+r.height<f.y&&c.y+c.height<=r.y+1,'Bottom block is below content and above footer, without overlapping either');
  check(!await page.locator('.instant-control-toggle').isVisible(),'Open controls replace the lightning');
  check(Math.abs(r.width-(c.width-24))<2,'Bottom controls fill panel width with original margins');
 }
 try{
  for(const width of [1600,800]){
   const passive=await open('player',fixtures.normal,width),page=passive.page;await layout(page);
   check(!await page.locator('[data-instant-control]').evaluate(n=>n.open),'Optional reserve starts collapsed');
   check((await page.locator('.instant-control-toggle img').getAttribute('src')).endsWith('/instant.svg'),'Lightning asset replaces hourglass');
   await page.screenshot({path:path.join(root,`instant-${width}-collapsed.png`),fullPage:true});
   await page.locator('.info-panel').screenshot({path:path.join(root,`instant-${width}-panel-collapsed.png`)});
   const availableHeight=await page.locator('.info-panel-content').evaluate(n=>n.clientHeight);
   const count=passive.commands();await page.locator('.instant-control-toggle').click();await fits(page);
   check(await page.locator('.info-panel-content').evaluate(n=>n.clientHeight)<availableHeight,'Expanded controls dock in layout instead of floating over content');
   check(passive.commands()===count,'Opening icon sends no command');check(await page.locator('[data-instant-pass]').count()===0,'No pass during ordinary reserve');
   check((await page.locator('.instant-control-cost').innerText())==='−1','Paid cost from server');
   await refreshPanel(page);check(await page.locator('[data-instant-control]').evaluate(n=>n.open),'Snapshot preserves voluntary opening');
   await page.locator('[data-instant-close]').click();await refreshPanel(page);check(!await page.locator('[data-instant-control]').evaluate(n=>n.open),'Polling respects close');
   check(await page.locator('.info-panel-content').evaluate(n=>n.clientHeight)===availableHeight,'Collapsing returns all useful height');
   await page.locator('.instant-control-toggle').click();await page.getByRole('link',{name:'Резерв',exact:true}).click();await page.locator('[data-instant-reserved="1"]').waitFor();
   check(!await page.locator('[data-instant-control]').evaluate(n=>n.open),'Reserve does not auto-open');
   await page.locator('.instant-control-toggle').click();await page.getByRole('link',{name:'Отменить резерв',exact:true}).waitFor();
   await page.screenshot({path:path.join(root,`instant-${width}-reserved.png`),fullPage:true});
   await page.getByRole('link',{name:'Отменить резерв',exact:true}).click();await page.locator('.instant-control-main[href*="cmd=reserve_instant"]').waitFor({state:'attached'});
   await page.locator('.instant-control-toggle').click();await fits(page);await page.screenshot({path:path.join(root,`instant-${width}-paid.png`),fullPage:true});await passive.ctx.close();
   const free=await open('player',fixtures.free,width);await layout(free.page);
   check(!await free.page.locator('[data-instant-control]').evaluate(n=>n.open),'Free reserve never steals pending choice');
   await free.page.locator('.instant-control-toggle').click();check((await free.page.locator('.instant-control-cost').innerText())==='Бесплатно','Free cost shown');
   await free.page.keyboard.press('Escape');check(!await free.page.locator('[data-instant-control]').evaluate(n=>n.open),'Escape closes');await free.ctx.close();
   const combat=await open('player',fixtures.combat,width,true);await layout(combat.page);await fits(combat.page);
   check(await combat.page.locator('[data-instant-required="1"]').evaluate(n=>n.open),'Mandatory priority auto-opens');
   check(await combat.page.locator('[data-instant-pass]').isVisible(),'Real combat pass visible');
   check(await combat.page.locator('[data-panel-card-id="4"]').count()>0,'Inspection keeps control');
   await combat.page.locator('.instant-control-main').click();await combat.page.locator('.instant-control-options').waitFor({state:'visible'});
   await refreshPanel(combat.page);check(await combat.page.locator('.instant-control-picker').evaluate(n=>n.open),'Snapshot preserves ability list');
   await combat.page.screenshot({path:path.join(root,`instant-${width}-combat.png`),fullPage:true});
   await combat.page.locator('.info-panel').screenshot({path:path.join(root,`instant-${width}-panel-open.png`)});
   // Browser-only long-list stress: duplicate real rendered entries, never send them.
   await combat.page.evaluate(()=>{const l=document.querySelector('.instant-control-options'),c=l.firstElementChild;for(let i=0;i<25;i++)l.appendChild(c.cloneNode(true));});await fits(combat.page);
   check(await combat.page.locator('.instant-control-options').evaluate(n=>{n.scrollTop=n.scrollHeight;return n.scrollTop>0}),'Long list scrolls independently');
   await combat.page.screenshot({path:path.join(root,`instant-${width}-long-list.png`),fullPage:true});
   await combat.page.locator('[data-instant-close]').click();
   await combat.page.evaluate(()=>{const t=document.createElement('div');t.style.cssText='height:1400px;display:flex;align-items:end;justify-content:end';const b=document.createElement('button');b.id='instant-bottom-reach-probe';b.textContent='Выбрать';b.onclick=()=>{window.instantBottomReached=true};t.appendChild(b);document.querySelector('.info-panel-content').appendChild(t);});await combat.page.waitForTimeout(70);
   const before=await combat.page.locator('.instant-control-toggle').boundingBox();await combat.page.locator('.info-panel-content').evaluate(n=>{n.scrollTop=n.scrollHeight});
   const after=await combat.page.locator('.instant-control-toggle').boundingBox();check(Math.abs(before.y-after.y)<1,'Icon fixed over scrolling content');
   await combat.page.locator('#instant-bottom-reach-probe').click();
   check(await combat.page.evaluate(()=>window.instantBottomReached),'Last right-aligned choice remains reachable beneath floating icon');
   await refreshPanel(combat.page);
   check(!await combat.page.locator('[data-instant-control]').evaluate(n=>n.open),'Same mandatory snapshot respects collapse');
   check(await combat.page.locator('[data-instant-required="1"] .instant-control-toggle').isVisible(),'Collapsed required decision remains reachable');
   await combat.page.reload();await combat.page.locator('[data-instant-control][open]').waitFor();check(await combat.page.locator('[data-instant-pass]').isVisible(),'F5 restores mandatory decision');await combat.ctx.close();
   results.push({width,result:'passed'});
  }
  const active=await open('host',fixtures.normal,1600);await active.page.locator('.instant-control-toggle').click();check(await active.page.locator('[data-instant-pass]').count()===0,'No fake pass for active turn');
  await active.page.locator('.instant-control-main').click();await active.page.locator('.instant-control-options .task-card').first().click();
  await active.page.locator('a[href*="cmd=cancel_pending"]').first().waitFor();check(await active.page.locator('[data-instant-control][open]').count()===0,'Target choice not covered');
  await active.page.locator('a[href*="cmd=cancel_pending"]').first().click();await active.page.locator('[data-instant-pass]').waitFor();check(await active.page.locator('[data-instant-control]').evaluate(n=>n.open),'Return to stack reveals mandatory pass');await active.ctx.close();
  check(errors.length===0,'No JS errors: '+errors.join(';'));fs.writeFileSync(path.join(root,'instant-browser-evidence.json'),JSON.stringify({checks,results,errors},null,2));console.log(`Floating instant browser: ${checks} checks passed`);
 }finally{await browser.close()}
})().catch(err=>{console.error(err);process.exitCode=1});
