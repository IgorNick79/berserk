'use strict';
const fs=require('fs'),path=require('path'),{execFileSync}=require('child_process');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const base=process.env.TURN_TIMER_TEST_URL,php=process.env.PHP_BINARY;
if(!/^http:\/\/127\.0\.0\.1:\d+\/index\.php$/.test(base||''))throw Error('Disposable loopback required');
const fixture=(...args)=>JSON.parse(execFileSync(php,[path.join(__dirname,'test_match_pause_ui_fixture.php'),...args.map(String)],{encoding:'utf8'}));
let checks=0;function ok(x,m){checks++;if(!x)throw Error(m);}
(async()=>{
 fixture('verify');if(fs.existsSync(path.join(__dirname,'..','.git')))throw Error('Disposable tree required');
 const token=require('crypto').randomBytes(16).toString('hex'),name='settings-probe-'+token+'.txt',probe=path.join(__dirname,'..','www',name);
 fs.writeFileSync(probe,token);try{ok(await (await fetch(new URL(name,base))).text()===token,'Verified HTTP source');}finally{fs.unlinkSync(probe);}
 const browser=await chromium.launch({headless:true,executablePath:process.env.BROWSER_EXECUTABLE});
 try{for(const width of [1600,800,375]){
  const game=fixture('create').game_id,context=await browser.newContext({viewport:{width,height:900}}),page=await context.newPage();
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(base+'?first&game='+game+'&debug=1');
  const burger=page.locator('.battle-menu'),menu=page.locator('#battle-menu-panel'),sound=page.locator('[data-menu-sound]');
  await burger.click();ok(await menu.isVisible(),'Open '+width);
  ok(await sound.getAttribute('aria-checked')==='true','Default checked');
  ok(await menu.locator('button:disabled').count()===2,'Two disabled Soon items');
  const box=await menu.boundingBox();ok(box.x>=0&&box.x+box.width<=width,'Fits '+width);
  await page.keyboard.press('End');ok(await page.locator('[data-menu-debug]').evaluate(n=>n===document.activeElement),'Keyboard End debug');
  await page.keyboard.press('Home');ok(await sound.evaluate(n=>n===document.activeElement),'Keyboard Home sound');
  await page.keyboard.press('Escape');ok(!await menu.isVisible()&&await burger.evaluate(n=>n===document.activeElement),'Escape restores focus');
  await burger.click();await page.mouse.click(width-2,850);ok(!await menu.isVisible(),'Outside closes');
  // Hold response after server execution: checkbox must wait for authoritative reply.
  let release;const gate=new Promise(r=>release=r);let arrived;const received=new Promise(r=>arrived=r);
  await page.route('**/*ajax=battle_command',async route=>{const response=await route.fetch();arrived();await gate;await route.fulfill({response});});
  await burger.click();await sound.click();await received;
  ok(await sound.getAttribute('aria-checked')==='true'&&await sound.isDisabled(),'No optimistic save');release();
  await page.waitForFunction(()=>document.querySelector('[data-menu-sound]').getAttribute('aria-checked')==='false'&&!document.querySelector('[data-menu-sound]').disabled);
  await page.unroute('**/*ajax=battle_command');
  ok(fixture('read',game).state.players.host.sound_enabled===false,'Persisted false');
  ok(fixture('read',game).state.players.player.sound_enabled===true,'Opponent unaffected');
  await page.reload();await burger.click();ok(await sound.getAttribute('aria-checked')==='false','Refresh restores false');
  await page.locator('[data-menu-debug]').click();ok(await page.locator('#battle-debug').isVisible(),'Existing Debug opens');
  const oldOverlay=await page.locator('#battle-debug').elementHandle();
  await page.locator('#battle-debug .debug-panel').evaluate(n=>{n.tabIndex=0;n.focus();});
  await page.locator('.battle-field .card-link').nth(1).evaluate(n=>n.click());
  await page.waitForFunction(old=>!old.isConnected,oldOverlay);
  await page.waitForFunction(()=>document.querySelector('#battle-debug').contains(document.activeElement));
  ok(await page.locator('#battle-debug').isVisible(),'Debug survives snapshot with focus restored');
  await page.keyboard.press('Escape');ok(!await page.locator('#battle-debug').isVisible(),'Debug closes');
  await page.locator('[data-open-pause]').click();await page.locator('[data-picker-send]').click();
  await page.waitForFunction(()=>document.querySelector('[data-battle-sync-root]').getAttribute('data-match-paused')==='1');
  await burger.click();ok(await menu.isVisible(),'Menu during requested');ok(await page.locator('[data-menu-debug]').getAttribute('aria-disabled')==='true','Debug blocked');
  const before=fixture('read',game).state.battle;
  await sound.click();await page.waitForFunction(()=>document.querySelector('[data-menu-sound]').getAttribute('aria-checked')==='true'&&!document.querySelector('[data-menu-sound]').disabled);
  ok(JSON.stringify(fixture('read',game).state.battle)===JSON.stringify(before),'Requested preference leaves battle unchanged');
  ok(await page.locator('[data-match-pause-mask]').isVisible(),'Mask remains');
  const guest=await context.newPage();await guest.goto(base+'?second&game='+game);await guest.locator('[data-pause-command=accept_pause]').click();
  await page.waitForFunction(()=>document.querySelector('[data-match-pause-content]').textContent.includes('Пауза'));
  await page.waitForFunction(()=>document.querySelector('[data-pause-command=pause_ready]')!==null);
  const active=fixture('read',game).state.battle;
  await sound.click();await page.waitForFunction(()=>document.querySelector('[data-menu-sound]').getAttribute('aria-checked')==='false'&&!document.querySelector('[data-menu-sound]').disabled);
  ok(JSON.stringify(fixture('read',game).state.battle)===JSON.stringify(active),'Active preference leaves battle unchanged');
  ok(await page.locator('.battle-left').getAttribute('inert')!==null,'Board inert');
  ok(errors.length===0,'No client exceptions '+errors.join(','));await context.close();
 }}finally{await browser.close();}
 console.log(`Battle menu browser: ${checks} checks passed`);
})().catch(e=>{console.error(e);process.exitCode=1;});
