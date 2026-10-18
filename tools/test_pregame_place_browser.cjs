const fs=require('fs'),path=require('path'),assert=require('assert/strict');
const {execFileSync}=require('child_process');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'..'),php=process.env.PHP_BINARY||'php';
const fixture=path.join(root,'tools/test_pregame_place_fixture.php');
(async()=>{const browser=await chromium.launch({headless:true,...(process.env.BROWSER_EXECUTABLE?{executablePath:process.env.BROWSER_EXECUTABLE}:{})});try{
 for(const role of ['host','player'])for(const width of [1600,1024]){
  const page=await browser.newPage({viewport:{width,height:width===1024?450:900}});
  let steps=[],requests=[],dropNext=false,delayNext=false,navigations=0;
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  page.on('request',request=>{if(request.isNavigationRequest()&&request.resourceType()==='document'&&request.frame()===page.mainFrame())navigations++;});
  const roleParam=role==='host'?'first':'second';
  const render=query=>JSON.parse(execFileSync(php,[fixture,role,JSON.stringify(steps),JSON.stringify(query)],{encoding:'utf8',maxBuffer:8*1024*1024}));
  await page.route('https://place.test/**',async route=>{
   const url=new URL(route.request().url());
   if(url.pathname.startsWith('/assets/')){
    const file=path.join(root,'www',url.pathname);if(!fs.existsSync(file))return route.fulfill({status:404,body:''});
    return route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.js')?'text/javascript':file.endsWith('.css')?'text/css':file.endsWith('.webp')?'image/webp':'image/png'});
   }
   const query=Object.fromEntries(url.searchParams),cmd=query.cmd;
   requests.push({query,type:route.request().resourceType()});
   if(cmd)steps.push({...query,role});
   const response=render(query);
   if(cmd&&delayNext){delayNext=false;await new Promise(resolve=>setTimeout(resolve,180));}
   if(cmd&&dropNext){dropNext=false;return route.abort('failed');}
   return route.fulfill({body:response.html,contentType:'text/html; charset=utf-8'});
  });
  await page.goto('https://place.test/index.php?'+roleParam+'&game=970003&card=0');
  await page.evaluate(()=>window.placementDocumentIdentity='same-document');
  const startNavigations=navigations;
  assert.equal(await page.locator('.pregame-place-list .prepare-card-link').count(),2);
  assert.deepEqual(await page.locator('.pregame-place-row-number').allTextContents(),['I','II','III']);
  assert.deepEqual(await page.locator('.pregame-place-columns').first().locator('span').allTextContents(),['1','2','3','4','5']);
  assert.ok(await page.locator('.pregame-main').evaluate(el=>el.scrollWidth<=el.clientWidth+1));
  const ownId=role==='host'?1:3,foreignId=role==='host'?3:1;
  const open=async id=>{
   await page.locator('.pregame-place-list .prepare-card-link[data-instance-id="'+id+'"]').click();
   await page.waitForSelector('[data-place-screen][data-place-view=card]');
   await page.waitForFunction(()=>!document.querySelector('.pregame-layout').hasAttribute('aria-busy'));
  };
  const close=async()=>{
   await page.locator('[data-place-close]').click();await page.waitForSelector('.pregame-place-list');
   await page.waitForFunction(()=>!document.querySelector('.pregame-layout').hasAttribute('aria-busy'));
  };
  await open(ownId);assert.equal(await page.locator('.pregame-place-list').count(),0);
  assert.ok(await page.locator('.field-cell.allowed .cell-link').count()>0);
  await close();assert.equal(await page.locator('.field-cell.allowed').count(),0);
  // A forged selection can only return the viewer's safe list.
  await page.locator('.pregame-place-list .prepare-card-link').first().evaluate((el,id)=>{const u=new URL(el.href);u.searchParams.set('card',id);el.href=u.href;},foreignId);
  await page.locator('.pregame-place-list .prepare-card-link').first().click();
  await page.waitForFunction(()=>!document.querySelector('.pregame-layout').hasAttribute('aria-busy'));
  assert.equal(await page.locator('.pregame-place-list').count(),1);
  assert.ok(!(await page.content()).includes('opponent-secret'));
  await open(ownId);
  // Invalid system coordinates go through the real server validator and leave the card unplaced.
  const target=page.locator('.field-cell.allowed .cell-link').first();
  await target.evaluate(el=>{const u=new URL(el.href);u.searchParams.set('row','0');el.href=u.href;});
  await target.click();await page.waitForFunction(()=>!document.querySelector('.pregame-layout').hasAttribute('aria-busy'));
  assert.match(await page.locator('.screen-prepare-place .flash').textContent(),/Клетка недоступна/);
  assert.equal(await page.locator('.pregame-place-field .placed').count(),0);
  // Two fast clicks must not duplicate a command.
  const before=steps.length;delayNext=true;
  await page.locator('.field-cell.allowed .cell-link').first().dblclick();
  await page.waitForSelector('.pregame-place-actions a[href*="cmd=unplace_card"]');
  await page.waitForFunction(()=>!document.querySelector('.pregame-layout').hasAttribute('aria-busy'));
  assert.equal(steps.length,before+1);
  assert.equal(await page.locator('.pregame-place-field .placed').count(),1);
  assert.equal(await page.evaluate(()=>window.placementDocumentIdentity),'same-document');
  assert.equal(navigations,startNavigations,'Selection and placement use fetch');
  // A lost response after committing removal must not replay it during recovery.
  dropNext=true;const removeBefore=steps.filter(step=>step.cmd==='unplace_card').length;
  await page.locator('.pregame-place-actions a[href*="cmd=unplace_card"]').click();
  await page.waitForSelector('[data-place-error]');
  assert.equal(steps.filter(step=>step.cmd==='unplace_card').length,removeBefore+1);
  await page.locator('[data-place-error] button').click();
  await page.waitForSelector('[data-place-error]',{state:'detached'});
  assert.equal(await page.locator('.pregame-place-field .placed').count(),0);
  assert.equal(steps.filter(step=>step.cmd==='unplace_card').length,removeBefore+1);
  await close();
  for(const id of [ownId,ownId+1]){
   await open(id);await page.locator('.field-cell.allowed .cell-link').first().click();
   await page.waitForSelector('.pregame-place-actions a[href*="cmd=unplace_card"]');
   await page.waitForFunction(()=>!document.querySelector('.pregame-layout').hasAttribute('aria-busy'));
   await close();
  }
  assert.equal(await page.locator('.pregame-panel-footer a[href*="cmd=confirm_place"]').count(),1);
  await page.locator('.pregame-panel-footer a[href*="cmd=confirm_place"]').click();
  await page.waitForSelector('[data-place-waiting="1"]');
  assert.equal(navigations,startNavigations,'Confirmation also uses fetch');
  const opponent=role==='host'?'player':'host',opponentId=role==='host'?3:1;
  const row=opponent==='host'?1:4,col=opponent==='host'?2:1;
  steps.push({role:opponent,cmd:'place_card',card_id:opponentId,row,col},
   {role:opponent,cmd:'place_card',card_id:opponentId+1,row,col:col+1},
   {role:opponent,cmd:'confirm_place'});
  await page.waitForSelector('[data-test-battle]',{timeout:12000});
  assert.equal(navigations,startNavigations+1,'Battle initializes through a clean navigation');
  assert.ok(!new URL(page.url()).searchParams.has('cmd'));
  assert.equal(requests.filter(request=>request.query.cmd==='confirm_place').length,1,'Polling never repeats confirmation');
  assert.ok(requests.filter(request=>request.query.cmd).every(request=>request.type==='fetch'));
  assert.deepEqual(errors,[]);
  await page.close();
 }
 console.log('Placement AJAX checks passed: both roles, compact coordinates, list/card/close, validation, double-click, lost response recovery, confirmation and battle transition.');
}finally{await browser.close();}})().catch(error=>{console.error(error);process.exit(1);});
