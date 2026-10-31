const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const fs=require('fs'),path=require('path'),assert=require('assert/strict');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.BROWSER_EXECUTABLE});
 const base=process.env.UNIFIED_MENU_URL,game=process.env.UNIFIED_MENU_GAME,out=process.env.UNIFIED_MENU_EVIDENCE;
 const checks=[],errors=[]; const ok=(name,value)=>{assert(value,name);checks.push(name);};
 const pages=[];
 try {
  for(const name of ['Creator','Candidate']){
   const context=await browser.newContext({viewport:{width:1600,height:900}});const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
   await page.goto(base+'/auth.php');await page.locator('[name=login]').fill(name);await page.locator('[name=password]').fill('LobbyFixture123');await page.getByRole('button',{name:'Войти',exact:true}).click();await page.waitForURL('**/lobby.php');await page.goto(base+'/index.php?game='+game);pages.push(page);
  }
  const [a,b]=pages;await a.waitForSelector('.view-thumbnail');
  ok('nine thumbnails on first page',await a.locator('.prepare-card-link:visible').count()===9);
  assert.deepEqual(await a.locator('.prepare-card-link:visible .view-cost img').evaluateAll(nodes=>nodes.slice(0,4).map(n=>n.getAttribute('src').split('/').pop())),['ordinary-unique.png','elite.png','ordinary.png','elite-unique.png']);
  ok('all four cost crystals',await a.locator('.view-cost img').evaluateAll(nodes=>new Set(nodes.map(n=>n.getAttribute('src'))).size===4));
  ok('horizontal WebP illustration',await a.locator('.view-art').first().evaluate(n=>n.tagName==='IMG' && n.getAttribute('src').endsWith('.webp')));
  await a.screenshot({path:path.join(out,'desktop-1600.png'),fullPage:true});
  await a.locator('.prepare-card-link:visible').first().hover();await a.waitForSelector('.battle-mini-zoom.is-open');ok('shared battle miniature hover',true);await a.screenshot({path:path.join(out,'desktop-hover.png'),fullPage:true});
  let loads=0;a.on('load',()=>loads++);
  await a.locator('.prepare-card-link:visible').first().click();await a.waitForSelector('[data-card-original]');ok('selection without navigation',loads===0);
  await a.screenshot({path:path.join(out,'desktop-selected.png'),fullPage:true});
  await a.locator('[data-card-original]').click();await a.waitForSelector('dialog.card-image-popup[open]');await a.screenshot({path:path.join(out,'view-image-popup.png'),fullPage:true});await a.locator('.card-image-popup-close').click();await a.waitForFunction(()=>!document.querySelector('dialog.card-image-popup[open]'));ok('shared image popup closes without navigation',loads===0);
  const lastToken=await a.locator('.prepare-card-link:visible').nth(1).getAttribute('href');
  await a.evaluate(()=>{const cards=[...document.querySelectorAll('.prepare-card-link')].filter(n=>!n.hidden);cards[0].click();cards[1].click();});
  await a.waitForFunction(token=>document.querySelector('.prepare-card--selected')?.closest('a').getAttribute('href').includes(token),new URL(lastToken,base).searchParams.get('card').replace(':','%3A'));
  ok('rapid selection keeps latest card',true);
  await a.locator('[data-card-original]').click();await a.waitForSelector('dialog.card-image-popup[open]');await a.locator('.card-image-popup-close').click();await a.waitForFunction(()=>!document.querySelector('dialog.card-image-popup[open]'));
  await a.locator('[data-view-pagination] button').nth(2).click();ok('last incomplete page',await a.locator('.prepare-card-link:visible').count()===1);
  await a.locator('[data-view-pagination] button').first().click();await a.locator('.prepare-card-link:visible').first().click();await a.waitForSelector('[data-view-card-actions] a');
  const initial=await a.locator('[data-view-zone=deck] b').textContent();
  let lost=false,resolveLost;const lostResponse=new Promise(resolve=>resolveLost=resolve);await a.route('**/*ajax=battle_command',async route=>{if(!lost){lost=true;await route.fetch();await route.abort();resolveLost();}else await route.continue();});
  await a.locator('[data-view-card-actions] a').click();await lostResponse;await a.reload();await a.waitForFunction(n=>document.querySelector('[data-view-zone=deck] b').textContent===String(Number(n)-1),initial,{timeout:30000});
  ok('lost command response recovered once',lost);await a.waitForTimeout(2500);ok('no repeated transfer',await a.locator('[data-view-zone=deck] b').textContent()===String(Number(initial)-1));await a.unroute('**/*ajax=battle_command');
  await a.locator('[data-view-zone=sideboard]').click();await a.locator('.prepare-card-link:visible').first().click();await a.locator('[data-view-card-actions] a').filter({hasText:'В колоду'}).waitFor();await a.locator('[data-view-card-actions] a').click();await a.waitForFunction(n=>document.querySelector('[data-view-zone=deck] b').textContent===n,initial);ok('return from sideboard',true);
  await a.locator('[data-open-pause]').click();await a.screenshot({path:path.join(out,'view-pause-picker.png'),fullPage:true});await a.locator('[data-picker-send]').click();await b.waitForSelector('[data-pause-command=accept_pause]',{timeout:15000});
  await b.locator('[data-pause-command=accept_pause]').click();await a.waitForSelector('[data-pause-command=pause_ready]',{timeout:15000});ok('shared pause accepted',true);await a.screenshot({path:path.join(out,'view-pause-active.png'),fullPage:true});
  const previousB=await b.locator('.pregame-layout').getAttribute('data-view-version');await a.locator('[data-pause-command=pause_ready]').click();await b.waitForFunction(v=>Number(document.querySelector('.pregame-layout').dataset.viewVersion)>Number(v),previousB);await b.locator('[data-pause-command=pause_ready]').click();await a.waitForFunction(()=>!document.querySelector('[data-match-pause-content]'),{timeout:15000});ok('pause resumes',true);
  await a.locator('[data-chat-toggle]').click();await a.locator('[data-chat-input]').fill('View chat integration');await a.locator('[data-chat-send]').click();await b.locator('[data-chat-toggle]').click();await b.getByText('View chat integration',{exact:true}).waitFor({timeout:15000});ok('existing game chat on View',true);ok('shared chat dock in InfoPanel',await a.locator('.info-panel .battle-secondary-view #game-chat-panel').count()===1);ok('chat header and history visible',await a.locator('#game-chat-panel .game-chat-header').isVisible() && await a.locator('#game-chat-panel .game-chat-history').evaluate(n=>n.getBoundingClientRect().height>100));await a.screenshot({path:path.join(out,'desktop-chat.png'),fullPage:true});await a.locator('[data-chat-close]').click();
  for(const theme of ['stone','leather','parchment','grimoire']) {
   await a.evaluate(async theme=>{const p=await fetch('/ui-preferences.php').then(r=>r.json());const response=await fetch('/panel-theme.php',{method:'POST',body:new URLSearchParams({theme,csrf:p.csrf})});if(!response.ok)throw Error('theme save');},theme);
   await a.reload();await a.waitForSelector('.view-thumbnail');ok('account theme '+theme,await a.locator('.pregame-layout').getAttribute('data-panel-theme')===theme);await a.screenshot({path:path.join(out,'theme-'+theme+'.png'),fullPage:true});
  }
  await a.locator('[data-view-zone=deck]').click();await a.locator('.prepare-card-link:visible').first().click();await a.waitForSelector('[data-card-original]');
  for(const width of [1366,390,360]){await a.setViewportSize({width,height:width>600?768:844});await a.waitForTimeout(300);ok('no horizontal overflow '+width,await a.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));if(width<600){ok('panel content does not overlap summary '+width,await a.evaluate(()=>document.querySelector('.pregame-panel-content').getBoundingClientRect().bottom<=document.querySelector('.pregame-panel-summary').getBoundingClientRect().top+1));ok('all thumbnail ratios 8:5 '+width,await a.locator('.prepare-card-link:visible .view-thumbnail').evaluateAll(nodes=>nodes.every(n=>{const b=n.getBoundingClientRect();return Math.abs(b.width/b.height-1.6)<0.04;})));}await a.screenshot({path:path.join(out,'view-'+width+'.png'),fullPage:true});}
  if(process.env.BATTLE_COMPARE_GAME){
   const battle=await a.context().newPage();battle.on('pageerror',e=>errors.push(e.message));
   await battle.goto(base+'/index.php?game='+process.env.BATTLE_COMPARE_GAME);await battle.waitForSelector('[data-mini-card]');
   await battle.locator('[data-mini-card]').first().hover();await battle.waitForSelector('.battle-mini-zoom.is-open');await battle.screenshot({path:path.join(out,'battle-hover.png'),fullPage:true});
   await battle.locator('[data-mini-card]').first().click();await battle.waitForTimeout(500);await battle.screenshot({path:path.join(out,'battle-selected.png'),fullPage:true});
   await battle.locator('[data-chat-toggle]').click();await battle.waitForSelector('.battle-secondary-view #game-chat-panel:not([hidden])');await battle.screenshot({path:path.join(out,'battle-chat.png'),fullPage:true});await battle.locator('[data-chat-close]').click();
   await battle.locator('[data-open-pause]').click();await battle.waitForSelector('.battle-pause-picker[open]');await battle.screenshot({path:path.join(out,'battle-pause-picker.png'),fullPage:true});await battle.locator('[data-picker-cancel]').click();
   const opponent=await b.context().newPage();await opponent.goto(base+'/index.php?game='+process.env.BATTLE_COMPARE_GAME);await battle.locator('[data-open-pause]').click();await battle.locator('[data-picker-send]').click();await opponent.waitForSelector('[data-pause-command=accept_pause]');await opponent.locator('[data-pause-command=accept_pause]').click();await battle.waitForSelector('[data-pause-command=pause_ready]');await battle.screenshot({path:path.join(out,'battle-pause-active.png'),fullPage:true});await opponent.close();
   const probe=page=>page.evaluate(()=>{const parent=document.querySelector('.info-panel'),result={};for(const variant of ['','yellow','skip','cancel']){const button=document.createElement('button');button.className='button '+variant;button.textContent='Test';parent.append(button);const css=getComputedStyle(button);result[variant]=[css.backgroundImage,css.borderRadius,css.borderColor,css.minHeight,css.boxShadow,css.fontFamily];button.remove();}return result;});
   for(const theme of ['grimoire','stone','leather','parchment']){
    await a.evaluate(async theme=>{const p=await fetch('/ui-preferences.php').then(r=>r.json());await fetch('/panel-theme.php',{method:'POST',body:new URLSearchParams({theme,csrf:p.csrf})});},theme);
    await a.reload();await battle.reload();await a.waitForSelector('.info-panel');await battle.waitForSelector('.info-panel');
    assert.deepEqual(await probe(a),await probe(battle));ok('same four button variants in View and battle '+theme,true);
   }
   await battle.close();
  }
  ok('no JavaScript errors',errors.length===0);
  fs.writeFileSync(path.join(out,'browser-checks.json'),JSON.stringify({checks,errors},null,2));console.log('PASS '+checks.length+' checks');
 }finally{if(pages[0]){await pages[0].screenshot({path:path.join(out,'last-state.png'),fullPage:true});console.log(await pages[0].locator('[data-view-notice]').textContent().catch(()=>''));}await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
