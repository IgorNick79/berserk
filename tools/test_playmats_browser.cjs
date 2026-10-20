const fs=require('fs'),assert=require('assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const repo=require('path').resolve(__dirname,'..'),root=process.env.PLAYMAT_ARTIFACT_DIR||repo+'/tmp';
fs.mkdirSync(root,{recursive:true});
const fixture=JSON.parse(fs.readFileSync(repo+'/tmp/playmat-fixture.json','utf8'));
const base=process.env.PLAYMAT_TEST_URL||'http://127.0.0.1:18920/index.php';
assert(/^http:\/\/127\.0\.0\.1:\d+\/index\.php$/.test(base),'Loopback test server required');
let checks=0;function ok(value,message){assert(value,message);checks++;}
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.BROWSER_EXECUTABLE?{executablePath:process.env.BROWSER_EXECUTABLE}:{})});
 try{
 for(const width of [1600,800,375]){
  const page=await browser.newPage({viewport:{width,height:900}});
  await page.goto(base+'?first&game='+fixture.game);await page.waitForFunction(()=>!!document.querySelector('[data-battle-sync-root]').openPlaymats);
  const geometry=()=>page.evaluate(()=>{const rect=n=>{const r=n.getBoundingClientRect();return [r.x,r.y,r.width,r.height]};return ['.battle-layout','.battle-field','.fly-zone-side','.grave-zone-side'].map(s=>rect(document.querySelector(s)));});
  const before=await geometry();
  await page.evaluate(()=>{window.fieldNode=document.querySelector('[data-battle-fragment="field_html"]').firstElementChild;window.timerNode=document.querySelector('[data-turn-timer-value]');});
  await page.locator('.battle-menu').click();await page.locator('[data-menu-playmat]').click();
  ok(await page.locator('[data-playmat-picker]').isVisible(),'picker opens '+width);
  ok(await page.locator('[data-mat-id]:visible').count()===9,'nine visible '+width);
  for(let n=0;n<8;n++)await page.locator('[data-mat-page="'+n%2+'"]').click();
  ok(await page.locator('[data-mat-id]:visible').count()===9,'rapid paging '+width);
  const box=await page.locator('[data-playmat-picker]').boundingBox();ok(box.x>=0&&box.x+box.width<=width,'picker fits '+width);
  await page.locator('[data-mat-page="0"]').click();
  await page.locator('[data-mat-id="mat-002"]').click();await page.waitForFunction(()=>document.querySelector('[data-mat-status]').textContent==='Коврик выбран');
  await page.waitForTimeout(1500);
  ok(await page.evaluate(()=>window.fieldNode===document.querySelector('[data-battle-fragment="field_html"]').firstElementChild),'field nodes retained '+width);
  ok(await page.evaluate(()=>window.timerNode===document.querySelector('[data-turn-timer-value]')),'timer nodes retained '+width);
  assert.deepEqual(await geometry(),before);checks++;
  ok(await page.locator('[data-mat-id="mat-002"]').getAttribute('aria-pressed')==='true','selection distinct '+width);
  const bg=await page.evaluate(()=>{const own=getComputedStyle(document.querySelector('.battle-playmat-half.own')),opp=getComputedStyle(document.querySelector('.battle-playmat-half.opponent'));return {own:own.backgroundImage,opp:opp.backgroundImage,events:own.pointerEvents,transform:own.transform}});
  ok(bg.own.includes('002.webp')&&bg.opp.includes('016.webp')&&bg.events==='none'&&bg.transform==='none','layers correct '+width);
  await page.screenshot({path:root+'/picker-'+width+'.png'});
  await page.keyboard.press('Escape');ok(!await page.locator('[data-playmat-picker]').isVisible(),'Escape closes '+width);
  await page.locator('.battle-menu').click();await page.locator('[data-menu-playmat]').click();await page.locator('[data-mat-close]').click();ok(!await page.locator('[data-playmat-picker]').isVisible(),'close button '+width);
  await page.reload();ok(await page.evaluate(()=>getComputedStyle(document.querySelector('.battle-playmat-half.own')).backgroundImage.includes('002.webp')),'refresh '+width);
  await page.close();
 }
 // Delay an old poll past a new cosmetic response: it cannot restore an earlier mat.
 const page=await browser.newPage({viewport:{width:1600,height:900}});await page.goto(base+'?first&game='+fixture.game);await page.waitForFunction(()=>!!document.querySelector('[data-battle-sync-root]').openPlaymats);
 let release,held=false;const gate=new Promise(r=>release=r);
 await page.route('**/*ajax=battle_sync*',async route=>{if(!held){held=true;const response=await route.fetch();await gate;await route.fulfill({response});}else await route.continue();});
 await page.waitForFunction(()=>true);for(let n=0;n<30&&!held;n++)await page.waitForTimeout(100);ok(held,'old poll held');
 await page.locator('.battle-menu').click();await page.locator('[data-menu-playmat]').click();await page.locator('[data-mat-id="mat-003"]').click();await page.waitForFunction(()=>document.querySelector('[data-mat-status]').textContent==='Коврик выбран');release();await page.waitForTimeout(1200);
 ok(await page.evaluate(()=>getComputedStyle(document.querySelector('.battle-playmat-half.own')).backgroundImage.includes('003.webp')),'old poll cannot regress mat');
 const opponent=await browser.newPage();await opponent.goto(base+'?second&game='+fixture.game);
 ok(await opponent.evaluate(()=>getComputedStyle(document.querySelector('.battle-playmat-half.own')).backgroundImage.includes('016.webp')&&getComputedStyle(document.querySelector('.battle-playmat-half.opponent')).backgroundImage.includes('003.webp')),'second viewer perspective');
 // Hold the gameplay cursor at boot to verify the cosmetic acknowledgement advances CAS itself.
 const ackPage=await browser.newPage();await ackPage.goto(base+'?first&game='+fixture.game);await ackPage.waitForFunction(()=>!!document.querySelector('[data-battle-sync-root]').openPlaymats);
 const boot=await ackPage.evaluate(()=>JSON.parse(document.getElementById('battle-playmats').textContent));
 await ackPage.route('**/*ajax=battle_sync*',route=>route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({ok:true,type:'no_change',sync_version:boot.state.version})}));
 await opponent.evaluate(()=>window.retainedField=document.querySelector('[data-battle-fragment="field_html"]').firstElementChild);
 await ackPage.locator('.battle-menu').click();await ackPage.locator('[data-menu-playmat]').click();await ackPage.locator('[data-mat-page="0"]').click();await ackPage.locator('[data-mat-id="mat-004"]').click();await ackPage.waitForFunction(()=>document.querySelector('[data-mat-status]').textContent==='Коврик выбран');
 await opponent.waitForFunction(()=>getComputedStyle(document.querySelector('.battle-playmat-half.opponent')).backgroundImage.includes('004.webp'));
 ok(await opponent.evaluate(()=>window.retainedField===document.querySelector('[data-battle-fragment="field_html"]').firstElementChild),'live opponent mat does not render field');
 await ackPage.keyboard.press('Escape');await ackPage.locator('.battle-menu').click();
 const commandResponse=ackPage.waitForResponse(r=>r.url().includes('ajax=battle_command')&&r.request().method()==='POST');await ackPage.locator('[data-menu-sound]').click();
 const command=await (await commandResponse).json();ok(command.command_status==='committed','immediate command uses acknowledged cosmetic CAS version');
 await ackPage.close();await page.close();await opponent.close();
 console.log('Playmat browser: '+checks+' checks passed');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
