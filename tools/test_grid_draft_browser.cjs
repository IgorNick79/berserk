const fs=require('fs'),path=require('path'),assert=require('assert/strict');
const {execFileSync}=require('child_process');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'..'),php=process.env.PHP_BINARY||'php';
const fixtures=JSON.parse(execFileSync(php,[root+'/tools/test_pregame_screen_integration.php','--fixtures'],{encoding:'utf8',maxBuffer:20*1024*1024}));
(async()=>{const browser=await chromium.launch({headless:true,...(process.env.BROWSER_EXECUTABLE?{executablePath:process.env.BROWSER_EXECUTABLE}:{})});try{
 for(const width of [1600,1024]){
  const page=await browser.newPage({viewport:{width,height:900}});let key='draft-host',commands=[],syncCount=0;
  const errors=[];page.on('pageerror',error=>errors.push(error.message));
  await page.route('https://grid.test/**',async route=>{
   const url=new URL(route.request().url());
   if(url.pathname.startsWith('/assets/')){
    const file=path.join(root,'www',url.pathname);
    return fs.existsSync(file)?route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.js')?'text/javascript':file.endsWith('.css')?'text/css':file.endsWith('.webp')?'image/webp':'image/png'}):route.fulfill({status:404});
   }
   if(fixtures[url.pathname.slice(1)])key=url.pathname.slice(1);
   if(url.searchParams.get('cmd')==='draft_sync'){syncCount++;return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,version:7,status:'draft',changed:false})});}
   if(url.searchParams.has('cmd'))commands.push(Object.fromEntries(url.searchParams));
   return route.fulfill({body:fixtures[key].html,contentType:'text/html; charset=utf-8'});
  });
  const positions=()=>page.locator('.pregame-grid-card:has(.prepare-card--selected)').evaluateAll(cards=>cards.map(card=>Number(card.dataset.gridPosition)));
  for(const activeKey of ['draft-host','draft-grid-player-active-player']){
   await page.goto('https://grid.test/'+activeKey);await page.evaluate(()=>sessionStorage.clear());await page.reload();
   const confirm=page.locator('[data-grid-confirm]'),start=commands.length;
   assert.equal(await confirm.isDisabled(),true);
   assert.equal(await page.locator('.screen-draft--grid .pregame-grid-controls').evaluate(el=>el.getBoundingClientRect().height),136,'Controls reserve stable space on either turn');
   assert.equal(await page.locator('.screen-draft--grid .prepare-card--selected').count(),0,'Preview does not select a group');
   const rows=page.locator('[data-grid-pick][data-axis=row]'),cols=page.locator('[data-grid-pick][data-axis=col]');
   assert.deepEqual(await rows.allTextContents(),['Строка I','Строка II','Строка III']);
   assert.deepEqual(await cols.allTextContents(),['Колонка I','Колонка II','Колонка III']);
   for(let i=0;i<3;i++){
    await rows.nth(i).click();assert.deepEqual(await positions(),[i*3,i*3+1,i*3+2]);
    assert.equal(await confirm.isEnabled(),true);assert.equal(commands.length,start,'Selection sends no game command');
    await cols.nth(i).click();assert.deepEqual(await positions(),[i,i+3,i+6]);
   }
   await cols.nth(2).click();assert.deepEqual(await positions(),[]);assert.equal(await confirm.isDisabled(),true);
   await rows.nth(1).focus();await page.keyboard.press('Enter');assert.deepEqual(await positions(),[3,4,5]);
   const urlBefore=page.url();await page.locator('[data-grid-position="8"] .prepare-card-link').click();
   assert.equal(page.url(),urlBefore,'Preview stays local');assert.equal(await page.locator('.pregame-card-title').textContent(),'grid-visible-8');
   assert.deepEqual(await positions(),[3,4,5]);
   const history=page.locator('[data-grid-history]');await history.hover();
   assert.equal(await page.locator('.pregame-history-tooltip').evaluate(el=>getComputedStyle(el).visibility),'visible');
   const buttonBox=await history.boundingBox(),tipBox=await page.locator('.pregame-history-tooltip').boundingBox();
   assert.ok(Math.abs(buttonBox.x+buttonBox.width/2-tipBox.x-tipBox.width/2)<1,'History hint centered');
   await history.click();assert.equal(await page.locator('.pregame-draft-history').count(),1);
   await history.click();assert.equal(await page.locator('.pregame-card-title').textContent(),'grid-visible-8');assert.deepEqual(await positions(),[3,4,5]);
   await history.click();await page.locator('.pregame-history-entry .prepare-card-link').first().click();
   assert.deepEqual(await positions(),[3,4,5]);assert.equal(commands.length,start);
   await page.reload();assert.deepEqual(await positions(),[3,4,5],'Same-version reload retains selection');
   await page.evaluate(()=>{const grid=document.querySelector('[data-grid-selection-key]'),prefix='grid-selection:'+grid.dataset.gridSelectionKey+':';sessionStorage.removeItem(prefix+grid.dataset.gridVersion);sessionStorage.setItem(prefix+'6','draft_row:1');});
   await page.reload();assert.equal(await confirm.isDisabled(),true,'Previous version selection is not restored');
   assert.equal(await page.evaluate(()=>Object.keys(sessionStorage).some(key=>key.startsWith('grid-selection:')&&key.endsWith(':6'))),false,'Stale selection is discarded');
   for(const axis of ['row','col'])for(let index=1;index<=3;index++){
    const before=commands.length;
    await page.locator('[data-grid-pick][data-axis='+axis+'][data-index="'+index+'"]').click();
    // The first retained row can toggle off only when it matches; choose it again.
    if(await confirm.isDisabled())await page.locator('[data-grid-pick][data-axis='+axis+'][data-index="'+index+'"]').click();
    await Promise.all([page.waitForNavigation(),confirm.click()]);
    assert.equal(commands.length,before+1);const query=commands.at(-1);
    assert.equal(query.cmd,'draft_'+axis);assert.equal(query[axis],String(index));assert.ok(!((axis==='row'?'col':'row') in query));
    assert.equal(query.game,'970001');assert.equal(query[activeKey==='draft-host'?'first':'second'],'1');
    assert.equal(await confirm.isDisabled(),true,'Sent selection is cleared');
   }
   await page.locator('[data-grid-pick][data-command=draft_pass]').click();assert.deepEqual(await positions(),[]);
   await Promise.all([page.waitForNavigation(),confirm.click()]);assert.equal(commands.at(-1).cmd,'draft_pass');
   assert.ok(!('row' in commands.at(-1))&&!('col' in commands.at(-1)));
   assert.ok(await page.locator('.pregame-main').evaluate(el=>el.scrollHeight<=el.clientHeight+1),'Grid fits the central column');
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'No page overflow');
   if(process.env.GRID_SCREENSHOT_DIR)await page.screenshot({path:process.env.GRID_SCREENSHOT_DIR+'/grid-'+width+'-'+fixtures[activeKey].role+'.png'});
  }
  for(const waitingKey of ['draft-player','draft-grid-player-active-host']){
   await page.goto('https://grid.test/'+waitingKey);assert.equal(await page.locator('[data-grid-pick],#grid-decision').count(),0);
   assert.equal(await page.locator('.screen-draft--grid .pregame-grid-controls').evaluate(el=>el.getBoundingClientRect().height),136,'Waiting mat keeps its height');
   await page.locator('[data-grid-position="2"] .prepare-card-link').click();assert.equal(await page.locator('.pregame-card-title').textContent(),'grid-visible-2');
   await page.locator('[data-grid-history]').click();assert.equal(await page.locator('.pregame-draft-history').count(),1);
  }
  await page.goto('https://grid.test/draft-grid-partial-host');assert.equal(await page.locator('[data-grid-pick][data-axis=row]:disabled').count(),2);
  await page.locator('[data-grid-pick][data-axis=col][data-index="1"]').click();assert.deepEqual(await positions(),[6]);
  await page.locator('[data-grid-pick][data-axis=row][data-index="3"]').click();assert.deepEqual(await positions(),[6,7,8]);
  await page.evaluate(()=>sessionStorage.clear());
  await page.goto('https://grid.test/draft-grid-discrete-host');assert.equal(await page.locator('[data-command=draft_pass]').count(),0);
  assert.equal(await page.locator('[data-grid-confirm]').isDisabled(),true,'Fresh discrete decision starts disabled');
  await page.goto('https://grid.test/draft-grid-finish-host');await page.locator('[data-grid-pick][data-command=finish_draft]').click();
  await Promise.all([page.waitForNavigation(),page.locator('[data-grid-confirm]').click()]);assert.equal(commands.at(-1).cmd,'finish_draft');
  await page.goto('https://grid.test/draft-grid-empty-history-host');await page.locator('[data-grid-history]').click();assert.match(await page.locator('.pregame-draft-history').textContent(),/Пока нет/);
  await page.goto('https://grid.test/draft-grid-unlimited-player');const before=syncCount;
  await page.waitForFunction(()=>document.querySelector('.draft-timer--unlimited').textContent==='—:—');
  await new Promise(resolve=>setTimeout(resolve,2300));assert.ok(syncCount>before,'Inactive unlimited Grid still polls for the next turn');
  assert.deepEqual(errors,[]);await page.close();
 }
 console.log('Grid Draft browser checks passed: both players, row/column selection and command payloads, confirmation, preview, history, keyboard, partial grid, modes, polling and layout at 1600/1024.');
}finally{await browser.close();}})().catch(error=>{console.error(error);process.exit(1);});
