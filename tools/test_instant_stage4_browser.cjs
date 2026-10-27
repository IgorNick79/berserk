// Runs only against the disposable Stage 4 HTTP fixture; no working DB or browser profile.
const fs=require('fs'),path=require('path'),assert=require('assert/strict');
const root=process.env.IR_TEST_ROOT;
if(!root||!root.replaceAll('\\','/').endsWith('/instant-stage4-validation'))throw Error('Stage 4 fixture root required');
const {chromium}=require(path.join(root,'runtime/node_modules/playwright'));
const e=JSON.parse(fs.readFileSync(path.join(root,'sql-evidence.json'))),http=JSON.parse(fs.readFileSync(path.join(root,'stage4-http-evidence.json'))),fixtures=http.ui_fixtures;
if(!http.created_at||Date.now()/1000-http.created_at>120)throw Error('Fresh running-timer fixtures required: run test_instant_stage4_http.php first');
(async()=>{
 const browser=await chromium.launch({executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless:true});
 const results=[],errors=[];let checks=0;
 const check=(v,msg)=>{assert.ok(v,msg);checks++};
 async function open(actor,game,width=1280,inspect=false){
  const ctx=await browser.newContext({viewport:{width,height:width<500?844:900}});
  const token=e.http_fixture.sessions[actor].cookie.split('=')[1];await ctx.addCookies([{name:'BERSERKAUTH5A',value:token,domain:'127.0.0.1',path:'/'}]);
  const page=await ctx.newPage();page.on('pageerror',err=>errors.push(err.message));
  await page.goto(`http://127.0.0.1:18881/index.php?game=${game}${inspect?'&inspect=4':''}`);await page.locator('[data-battle-sync-root]').waitFor();
  return {ctx,page};
 }
 async function footer(page){
  const f=page.locator('.info-panel-footer');await f.waitFor({state:'visible'});
  const b=await f.boundingBox(),viewport=page.viewportSize();check(b.width<=viewport.width&&b.x>=-1,'Footer fits viewport');
  check(await page.locator('.info-panel-footer [data-instant-control]').count()===1,'Exactly one footer instant block');
  const main=page.locator('.instant-control-main');check(await main.count()===1,'Exactly one main instant action');
  check((await main.boundingBox()).width>=80,'Primary label has enough width to avoid character-by-character wrapping');
  check(await page.locator('.info-panel-content .instant-control-main').count()===0,'No scroll-area duplicate');
  const c=await page.locator('[data-instant-control]').boundingBox(),end=await page.getByText('Завершить ход',{exact:true}).first().boundingBox();
  check(c&&end&&c.y+c.height<=end.y+1,'Instant block directly precedes end turn');
 }
 try{
  for(const width of [1280,390]){
   const {ctx,page}=await open('player',fixtures.normal,width);await footer(page);
   await page.getByRole('link',{name:'Резерв',exact:true}).click();await page.getByRole('link',{name:'Отменить резерв',exact:true}).waitFor();check(await page.locator('.instant-control-cost').count()===0,'Waiting reserve has no false price');
   await page.reload();await page.getByRole('link',{name:'Отменить резерв',exact:true}).waitFor();await footer(page);
   await page.getByRole('link',{name:'Отменить резерв',exact:true}).click();await page.getByRole('link',{name:'Резерв',exact:true}).waitFor();
   check(await page.locator('.instant-control-cost').innerText()==='−1','Paid cost is server rendered');
   await page.screenshot({path:path.join(root,`ui-${width}-paid.png`),fullPage:true});await ctx.close();
   const free=await open('player',fixtures.free,width);await footer(free.page);check(await free.page.locator('.instant-control-main .instant-control-cost').innerText()==='Бесплатно','Free pending cost appears on the primary button');
   await free.page.screenshot({path:path.join(root,`ui-${width}-free.png`),fullPage:true});await free.ctx.close();
   const combat=await open('player',fixtures.combat,width,true);await footer(combat.page);
   check(await combat.page.locator('.instant-control-cost').count()===0,'Combat has no reservation cost');
   check(await combat.page.locator('.instant-control-main').innerText()==='Инстант','Combat single main action');
   check(await combat.page.locator('[data-panel-card-id="4"]').count()>0,'Inspected card fixture exists');
   check(await combat.page.locator('.info-panel-footer').isVisible(),'Inspection preserves footer');
   await combat.page.locator('.instant-control-main').click();await combat.page.locator('.instant-control-options').waitFor({state:'visible'});
   check(await combat.page.locator('[data-instant-pass]').isVisible(),'Picker keeps mandatory pass');
   await combat.page.screenshot({path:path.join(root,`ui-${width}-combat-inspect.png`),fullPage:true});
   await combat.page.reload();check(await combat.page.locator('.instant-control-main').innerText()==='Инстант','F5 recovers granted combat priority');await combat.ctx.close();
   results.push({width,result:'passed'});
  }
  const active=await open('host',fixtures.normal);await footer(active.page);await active.page.getByRole('link',{name:'Сыграть',exact:true}).click();
  await active.page.locator('.instant-control-picker').waitFor();await active.page.locator('.instant-control-main').click();check(await active.page.locator('.instant-control-options .task-card').count()>0,'Active stack uses native ability options');
  await active.page.reload();check(await active.page.locator('[data-instant-pass]').isVisible(),'F5 recovers open stack and pass');await active.ctx.close();
  check(errors.length===0,'No browser JavaScript errors: '+errors.join(';'));
  fs.writeFileSync(path.join(root,'browser-evidence.json'),JSON.stringify({checks,results,errors},null,2));console.log(`Stage4 browser: ${checks} checks passed`);
 }finally{await browser.close()}
})().catch(e=>{console.error(e);process.exitCode=1});
