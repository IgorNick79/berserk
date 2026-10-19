const fs = require('fs');
const path = require('path');
const assert = require('assert/strict');
const {execFileSync} = require('child_process');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '..');
const php = process.env.PHP_BINARY || 'php';
const testFile = path.join(root, 'tools/test_pregame_screen_integration.php');
const fixtures = JSON.parse(execFileSync(php, [testFile, '--fixtures'], {encoding:'utf8', maxBuffer:16*1024*1024}));
(async () => {
 const browser = await chromium.launch({headless:true, ...(process.env.BROWSER_EXECUTABLE ? {executablePath:process.env.BROWSER_EXECUTABLE} : {})});
 const commands=[];
 try {
  for (const width of [1600,1280,1024]) {
   const page = await browser.newPage({viewport:{width,height:900}});
   const errors=[]; page.on('pageerror', e=>errors.push(e.message));
   let currentKey='mode-host', transition=false, nextKey='', syncCount=0;
   await page.route('https://pregame.test/**', async route=>{
    const url = new URL(route.request().url());
    if (url.pathname.startsWith('/assets/')) {
     const file=path.join(root,'www',url.pathname);
     if (!fs.existsSync(file)) return route.fulfill({status:404,body:''});
     return route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.js')?'text/javascript':file.endsWith('.css')?'text/css':file.endsWith('.webp')?'image/webp':'image/png'});
    }
    const cmd=url.searchParams.get('cmd');
    if(cmd==='draft_sync') {
     syncCount++;
     return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,version:transition?8:7,status:transition?'view':'draft',changed:transition})});
    }
    const key=fixtures[url.pathname.slice(1)]?url.pathname.slice(1):currentKey;
    if (fixtures[url.pathname.slice(1)]) currentKey=key;
    if (transition && nextKey) { currentKey=nextKey; transition=false; nextKey=''; }
    const fixture=fixtures[currentKey];
    if(cmd) { commands.push({cmd, query:Object.fromEntries(url.searchParams), positions:url.searchParams.getAll('positions[]')}); return route.fulfill({body:fixture.html,contentType:'text/html; charset=utf-8'}); }
    if (url.searchParams.has('card')) {
     const body=execFileSync(php,[testFile,'--request',fixture.stage,fixture.role,fixture.variant,JSON.stringify(Object.fromEntries(url.searchParams))],{encoding:'utf8'});
     return route.fulfill({body,contentType:'text/html; charset=utf-8'});
    }
    return route.fulfill({body:fixture.html,contentType:'text/html; charset=utf-8'});
   });
   let solomonGeometry = null;
   for(const [key,fixture] of Object.entries(fixtures)) {
    await page.goto('https://pregame.test/'+key);
    const css = await page.locator('link[href*="/assets/css/pregame.css"]').getAttribute('href');
    assert.match(css, /^\/assets\/css\/pregame\.css\?v=[a-f0-9]{64}$/i, key+' resolved CSS version');
    assert.ok(!/[{}]|%7b|%7d|rkey/i.test(css), key+' no CSS placeholders');
    const cssStatus = await page.evaluate(href=>fetch(href).then(response=>response.status), css);
    assert.equal(cssStatus, 200, key+' versioned CSS loads');
    assert.equal(await page.locator('.pregame-layout').count(),1,key);
    assert.equal(await page.locator('.pregame-menu[type="button"]').count(),1,key+' menu button');
    assert.equal(await page.locator('.pregame-panel-timer').evaluate(el=>getComputedStyle(el).flexBasis),'auto',key+' natural timer width');

    if(fixture.stage==='draft' && fixture.variant.includes('solomon')) {
     assert.equal(await page.locator('.pregame-main .draft-info, .pregame-main .prepare-bottom-bar').count(),0);
     assert.equal(await page.locator('.pregame-panel-summary .pregame-pool-stat').count(),8);
     assert.equal(await page.locator('.pregame-panel-footer [data-solomon-panel-action]').count(),0);
     assert.match(await page.locator('.pregame-panel-phase').textContent(), /^Соломон драфт · Пул: /);
     const stats = page.locator('.pregame-pool-stats');
     const layoutPolish = await page.evaluate(()=>{
      const content=document.querySelector('.pregame-panel-content');
      const main=document.querySelector('.pregame-main').getBoundingClientRect();
      const mat=document.querySelector('.screen-draft--solomon');
      const rect=mat.getBoundingClientRect();
      const count=document.querySelector('.pregame-selection-count');
      const decisions=document.querySelector('.pregame-footer-decisions');
      const tooltip=document.querySelector('.pregame-history-tooltip').getBoundingClientRect();
      const footer=document.querySelector('.pregame-history-control').getBoundingClientRect();
      return {
       hidden:getComputedStyle(content).scrollbarWidth === 'none',
       gap:getComputedStyle(document.querySelector('.pregame-pool-stat')).gap,
       groups:getComputedStyle(document.querySelector('.pregame-pool-icons')).justifyContent,
       after:!count || decisions.getBoundingClientRect().right <= count.getBoundingClientRect().left,
       centered:Math.abs(rect.x+rect.width/2-main.x-main.width/2)<1,
       compact:rect.width<=720 && rect.height<main.height,
       mat:getComputedStyle(mat).backgroundImage,
       hintCentered:Math.abs(tooltip.x+tooltip.width/2-footer.x-footer.width/2)<1
      };
     });
     assert.ok(layoutPolish.hidden && layoutPolish.after && layoutPolish.centered && layoutPolish.compact && layoutPolish.hintCentered);
     assert.equal(layoutPolish.gap, '4px');
     assert.equal(layoutPolish.groups, 'space-between');
     assert.ok(layoutPolish.mat.includes('playmat_default.webp'));
     if (fixture.variant === 'solomon') {
      const geometry = await page.evaluate(()=>{
       const mat=document.querySelector('.screen-draft--solomon').getBoundingClientRect();
       const card=document.querySelector('.solomon-stack__card').getBoundingClientRect();
       const link=document.querySelector('.solomon-stack__card .prepare-card-link').getBoundingClientRect();
       return [mat.height, card.height, link.y-card.y];
      });
      if (solomonGeometry) assert.deepEqual(geometry, solomonGeometry, 'Turn change preserves card and selection-slot heights');
      solomonGeometry = geometry;
     }


     assert.equal(await stats.locator(':scope > :first-child').getAttribute('class'), 'pregame-pool-totals');
     assert.match(await stats.locator('.pregame-pool-stat').nth(0).getAttribute('aria-label'), /^Золотые/);
     assert.match(await stats.locator('.pregame-pool-stat').nth(1).getAttribute('aria-label'), /^Серебряные/);
     assert.equal(await stats.locator('.pregame-pool-totals').evaluate(el=>getComputedStyle(el).fontSize), '13px');
     const contentBox = await page.locator('.pregame-panel-content').boundingBox();
     const summaryBox = await stats.boundingBox();
     const footerBox = await page.locator('.pregame-panel-footer').boundingBox();
     assert.ok(summaryBox.y >= contentBox.y + contentBox.height - 1 && summaryBox.y + summaryBox.height <= footerBox.y + 1);
     const decisions = page.locator('#solomon-decision');
     if (await decisions.count()) {
      const count = await page.locator('[data-solomon-selected]').boundingBox();
      for (const button of await decisions.locator('button').all()) {
       const box = await button.boundingBox();
       assert.ok(count.y >= box.y && count.y + count.height <= box.y + box.height);
       assert.equal(await button.evaluate(el=>getComputedStyle(el).flexGrow), '0');
       assert.equal(await button.evaluate(el=>getComputedStyle(el).paddingTop), '10px');
      }
     } else {
      assert.equal(await page.locator('.pregame-panel-footer [data-solomon-selected], .pregame-panel-footer .wait').count(), 0);
     }
     if (fixture.variant.includes('unlimited')) assert.equal(await page.locator('.draft-timer--unlimited').textContent(), '—:—');

    }
    if (fixture.stage === 'view') {
     assert.equal(await page.locator('.pregame-main a[href*="cmd=view_to_"]').count(),0,key+' View moves are in InfoPanel');
     if(!['empty','waiting','denied','system'].includes(fixture.variant))assert.equal(await page.locator('.pregame-panel-content [data-view-card-actions] a').count(),1,key+' action belongs to selected card');
     const dimensions = await page.locator('.pregame-main').evaluate(el=>({height:el.clientHeight, scroll:el.scrollHeight}));
     assert.ok(dimensions.scroll <= dimensions.height + 1, key+' View has no central overflow');
     assert.equal(await page.locator('.pregame-main .prepare-bottom-bar, .pregame-main a[href*="cmd=confirm_view"]').count(),0);
     if (fixture.variant !== 'empty') {
      assert.equal(await page.locator('.pregame-panel-summary .pregame-pool-stat').count(),8);
      assert.equal(await page.locator('.pregame-panel-footer a[href*="cmd=confirm_view"]').count(),fixture.variant==='waiting'?0:1);
     }
    }
    if (['turn','side'].includes(fixture.stage)) {
     assert.equal(await page.locator('[data-view-pager]').count(),1,key+' shared deck');
     assert.equal(await page.locator('.pregame-main a[href*="cmd="]').count(),0,key+' no View commands');
     assert.equal(await page.locator('.pregame-panel-summary .pregame-pool-stat').count(),8);
     assert.ok(await page.locator('.pregame-main').evaluate(el=>el.scrollHeight<=el.clientHeight+1),key+' no central scroll');
     if(fixture.stage==='turn') {
      assert.equal(await page.locator('.pregame-panel-content .dice-column').count(),2);
      assert.equal(await page.locator('.pregame-panel-footer a[href*="cmd=confirm_turn"]').count(),fixture.variant==='waiting'?0:1);
     } else {
      const chooser=fixture.role==='host'||fixture.variant==='chooser';
      assert.equal(await page.locator('.pregame-panel-content input[name=side]').count(),chooser?2:0);
      assert.equal(await page.locator('.pregame-panel-footer button[form=pregame-side-choice]').count(),chooser?1:0);
     }
    }
    if (fixture.stage === 'deal') {
     assert.equal(await page.locator('[data-deal-pager]').count(),1);
     assert.equal(await page.locator('.pregame-main .prepare-bottom-bar, .pregame-main h2, .pregame-main a[href*="cmd="]:not([data-deal-quick])').count(),0);
     assert.equal(await page.locator('.pregame-panel-summary .pregame-pool-stat').count(),['mixed','flying-hand','penalty'].includes(fixture.variant)?9:8);
     assert.equal(await page.locator('.pregame-panel-footer [data-deal-selected]').count(),1);
     assert.equal(await page.locator('.pregame-deal-stats img[src$="/fly.png"]').count(),['mixed','flying-hand','penalty'].includes(fixture.variant)?1:0);
     const element=page.locator('.pregame-deal-elements [data-view-filter="element:dark"]');
     const ratio=await element.locator('span').textContent();
     assert.match(ratio,/^\d+\/\d+$/);
     assert.ok((await element.getAttribute('aria-label')).includes('выбрано '+ratio.split('/')[0]+', всего '+ratio.split('/')[1]));

     assert.equal(await page.locator('.pregame-panel-footer a[href*="cmd=confirm_deal"]').count(),['waiting','pending'].includes(fixture.variant)?0:1);
     assert.equal(await page.locator('.pregame-reshuffle').count(),['waiting','pending','exhausted'].includes(fixture.variant)?0:1);
     assert.ok(await page.locator('.pregame-main').evaluate(el=>el.scrollHeight<=el.clientHeight+1),key+' bounded Deal pages');
    }
    if (fixture.stage === 'place') {
     assert.equal(await page.locator('.pregame-main .place-squad, .pregame-main .prepare-bottom-bar').count(),0);
     assert.equal(await page.locator('.pregame-place-row-number').allTextContents().then(values=>values.join(',')),'I,II,III');
     assert.deepEqual(await page.locator('.pregame-place-columns').first().locator('span').allTextContents(),['1','2','3','4','5']);
     const list=await page.locator('.pregame-place-list').count();
     assert.equal(list,await page.locator('[data-place-close]').count()?0:1,'Placement list and card view are exclusive');
     assert.equal(await page.locator('.pregame-main a[href*="cmd=confirm_place"], .pregame-main a[href*="cmd=unplace_card"]').count(),0);
    }
    const panel=page.locator('.pregame-info-panel');
    assert.equal((await panel.boundingBox()).width,440,key+' panel width at '+width);
    assert.equal(await page.locator('.pregame-main h1').count(),0,key+' duplicate heading');
    assert.equal(await page.locator('[data-solomon-panel-action]').count(), fixture.stage==='draft' && fixture.variant.includes('solomon') && !((fixture.role==='player' && !fixture.variant.includes('player-active')) || (fixture.role==='host' && fixture.variant.includes('player-active'))) ? 2 : 0);
    assert.equal(await page.locator('.toolbar, a[href="/reset"]').count(),0,'Service toolbar removed');
    const dims=await page.evaluate(()=>({height:document.documentElement.scrollHeight, viewport:innerHeight, width:document.documentElement.scrollWidth,vw:innerWidth}));
    assert.ok(dims.height<=dims.viewport+1,key+' page height');
    assert.ok(dims.width<=dims.vw+1,key+' page width');
    if(fixture.stage==='draft') {
     assert.equal(await page.locator('.pregame-panel-header .draft-timer').count(),1,key+' header timer');
     assert.equal(await page.locator('.pregame-main .draft-timer').count(),0,key+' duplicate timer');
    }
    const info = page.getByRole('button', {name:'Описание карты',exact:true});
    if (await info.count()) {
     const heading=await page.locator('.pregame-card-heading').boundingBox(),media=await page.locator('.pregame-card-media').boundingBox();
     assert.ok(heading.y+heading.height<=media.y+1, key+' heading above image');
     await info.click();
     assert.ok(await page.locator('.pregame-stat-tooltip').isVisible(), key+' description tooltip');
     assert.ok((await page.locator('.pregame-stat-tooltip').textContent()).length>0);
     await page.keyboard.press('Escape');
     assert.ok(await page.locator('.pregame-stat-tooltip').isHidden());
    }
    const summary=page.locator('.pregame-panel-content summary');
    if(await summary.count()) {
     await summary.click();
     const headerBefore=await page.locator('.pregame-panel-header').boundingBox(), footerBefore=await page.locator('.pregame-panel-footer').boundingBox();
     const content=page.locator('.pregame-panel-content');
     const overflows=await content.evaluate(el=>el.scrollHeight>el.clientHeight);
     if(fixture.variant!=='system') assert.ok(overflows,key+' long description scroll');
     await content.evaluate(el=>el.scrollTop=300);
     if(overflows) assert.ok(await content.evaluate(el=>el.scrollTop)>0);
     assert.deepEqual(await page.locator('.pregame-panel-header').boundingBox(),headerBefore,key+' header fixed');
     assert.deepEqual(await page.locator('.pregame-panel-footer').boundingBox(),footerBefore,key+' footer fixed');
     assert.equal(await page.locator('.pregame-main').evaluate(el=>el.scrollTop),0,key+' independent Main');
     await content.focus();
     assert.ok(await content.evaluate(el=>el===document.activeElement),key+' focus');
    }
    const main=page.locator('.pregame-main');
    if(await main.evaluate(el=>el.scrollHeight>el.clientHeight)) {
     const before=await page.locator('.pregame-panel-content').evaluate(el=>el.scrollTop);
     await main.evaluate(el=>el.scrollTop=300);
     assert.ok(await main.evaluate(el=>el.scrollTop)>0,key+' Main scroll');
     assert.equal(await page.locator('.pregame-panel-content').evaluate(el=>el.scrollTop),before,key+' independent content');
    }
   }
   // Real server presentation is re-run for navigation-only card clicks.
   await page.goto('https://pregame.test/view-host');
   const viewLink=page.locator('.pregame-main .cards a').nth(1), viewName=await viewLink.locator('.prepare-card__name').textContent();
   await viewLink.click();
   assert.equal(await page.locator('.pregame-card-title').textContent(),viewName);
   await page.goto('https://pregame.test/view-sideboard');
   assert.equal(await page.locator('.pregame-main a[href*="cmd=view_to_deck"]').count(),0);
   const toDeck=page.locator('.pregame-panel-content [data-view-card-actions] a[href*="cmd=view_to_deck"]');
   assert.equal(await toDeck.count(),1);
   assert.equal(await toDeck.textContent(),'В колоду');
   const beforeMove=commands.length;
   await toDeck.click();
   assert.equal(commands.length,beforeMove+1);
   assert.equal(commands.at(-1).cmd,'view_to_deck');
   assert.ok(Number(commands.at(-1).query.card_id)>0);
   // All View pages remain reachable without scrolling, including on a short desktop.
   for (const height of [900,450]) {
    await page.setViewportSize({width,height});
    await page.goto('https://pregame.test/view-host');
    const bullets=page.locator('[data-view-pagination] button');
    assert.ok(await bullets.count()>1,'View has multiple bullet pages');
    const seen=new Set();
    for (let i=0;i<await bullets.count();i++) {
     await bullets.nth(i).click();
     assert.equal(await bullets.nth(i).getAttribute('aria-current'),'page');
     const geometry=await page.evaluate(()=>{
      const main=document.querySelector('.pregame-main'), box=main.getBoundingClientRect();
      const links=Array.from(document.querySelectorAll('.pregame-view-pages .prepare-card-link')).filter(el=>el.getClientRects().length);
      return {overflow:main.scrollHeight>main.clientHeight+1,
       cards:links.map(el=>({id:el.dataset.ukid,top:el.getBoundingClientRect().top,bottom:el.getBoundingClientRect().bottom})),
       top:box.top,bottom:box.bottom,nav:document.querySelector('[data-view-pagination]').getBoundingClientRect().bottom};
     });
     assert.equal(geometry.overflow,false,'View does not scroll at '+width+'x'+height);
     assert.ok(geometry.nav<=geometry.bottom && geometry.cards.length>0);
     for (const card of geometry.cards) {
      assert.ok(card.top>=geometry.top && card.bottom<=geometry.bottom,'Card fits without clipping');
      assert.ok(!seen.has(card.id),'Card occurs on exactly one page');
      seen.add(card.id);
     }
    }
    assert.equal(seen.size,32,'All 32 deck cards are reachable');
    await page.locator('[data-view-zone="sideboard"]').click();
    const sideId=await page.locator('.pregame-view-pages .prepare-card-link:visible').getAttribute('data-ukid');
    seen.add(sideId);
    assert.equal(seen.size,33,'Sideboard is directly reachable through its section control');
    assert.equal(await page.getByRole('button',{name:'Тьма: 1',exact:true}).count(),1,'Statistics follow the active section');
    await page.locator('[data-view-zone="deck"]').click();
    await bullets.last().press('Home');
    assert.equal(await bullets.first().getAttribute('aria-current'),'page');
    await bullets.first().press('End');
    assert.equal(await bullets.last().getAttribute('aria-current'),'page');
    await page.getByRole('button',{name:'Тьма: 32',exact:true}).hover();
    assert.ok(await page.locator('.pregame-stat-tooltip').isVisible(),'View element hint');
    await page.keyboard.press('Escape');
    await bullets.nth(1).click();
    const laterCard=page.locator('.pregame-view-pages .prepare-card-link:visible').first();
    const laterName=await laterCard.locator('.prepare-card__name').textContent();
    await laterCard.click();
    assert.equal(await page.locator('.pregame-card-title').textContent(),laterName);
    assert.equal(await page.locator('.pregame-view-pages .prepare-card--selected:visible').count(),1,'Selected card retains its page after navigation');
   }
   await page.setViewportSize({width,height:900});
   await page.goto('https://pregame.test/view-filters');
   assert.equal(await page.locator('[data-view-heading]').count(),0,'No redundant card-list heading');
   async function visibleViewIds() {
    const result=new Set(), controls=page.locator('[data-view-pagination] button');
    for(let i=0;i<await controls.count();i++) {
     await controls.nth(i).click();
     for(const id of await page.locator('.pregame-view-pages .prepare-card-link:visible').evaluateAll(nodes=>nodes.map(el=>el.dataset.ukid))) result.add(id);
    }
    return result;
   }
   await page.locator('[data-view-filter="element:plains"]').click();
   assert.equal((await visibleViewIds()).size,16,'Element filter');
   await page.locator('[data-view-filter="crystal:gold"]').click();
   assert.equal((await visibleViewIds()).size,8,'Element and crystal filters combine');
   await page.locator('[data-view-filter="crystal:gold"]').click();
   assert.equal((await visibleViewIds()).size,16,'Repeated click removes only the crystal filter');
   await page.locator('[data-view-filter="element:plains"]').press('Enter');
   assert.equal((await visibleViewIds()).size,32,'Keyboard toggles element filter off');
   await page.locator('[data-view-filter="element:forests"]').click();
   assert.ok(await page.locator('[data-view-empty]').isVisible(),'Empty filter result is explicit');
   assert.equal(await page.locator('[data-view-pagination] button').count(),0);
   await page.locator('[data-view-zone="sideboard"]').click();
   assert.equal((await visibleViewIds()).size,1,'Section switch clears filters');
   assert.equal(await page.getByRole('button',{name:'Леса: 1',exact:true}).count(),1);
   assert.equal(await page.getByRole('button',{name:'Золотые карты: 1',exact:true}).count(),1);
   await page.locator('[data-view-filter="element:forests"]').click();
   await page.locator('.pregame-view-pages .prepare-card-link:visible').click();
   assert.equal(await page.locator('[data-view-zone="sideboard"]').getAttribute('aria-pressed'),'true');
   assert.equal(await page.locator('[data-view-filter="element:forests"]').getAttribute('aria-pressed'),'true','Filter survives card navigation');
   assert.equal(await page.locator('.pregame-card-title').textContent(),'host-sideboard');
   await page.locator('[data-view-zone="deck"]').click();
   assert.equal((await visibleViewIds()).size,32,'Clicking deck restores the full deck');
   assert.equal(await page.locator('[data-view-filter][aria-pressed="true"]').count(),0);
   await page.goto('https://pregame.test/deal-host');
   const dealLink=page.locator('.pregame-main .cards .prepare-card-link').nth(2),dealName=await dealLink.locator('.prepare-card__name').textContent();
   await dealLink.click();
   assert.equal(await page.locator('.pregame-card-title').textContent(),dealName);
   assert.equal(await page.locator('.pregame-panel-content a[href*="cmd=pick_card"]').count(),1);
   const quick=page.locator('.pregame-deal-tile:visible [data-deal-quick]').first();
   const quickCard=await quick.locator('..').locator('..').locator('.prepare-card-link').getAttribute('data-ukid');
   await quick.click();
   assert.equal(commands.at(-1).cmd,'pick_card','Quick choice recruits without first opening its card');
   assert.equal(commands.at(-1).query.ukid,quickCard);

   await page.goto('https://pregame.test/deal-mixed');
   assert.equal((await visibleViewIds()).size,30,'Hand and squad share one list with merged duplicates');
   assert.equal(await page.locator('.pregame-view-pages [data-ukid="host-squad"]').count(),1);
   await page.locator('[data-deal-selected]').click();
   assert.equal(await page.locator('[data-deal-selected]').getAttribute('aria-pressed'),'true');
   assert.equal(await page.locator('.pregame-view-pages .prepare-card-link:visible').count(),1);
   await page.locator('.pregame-view-pages .prepare-card-link:visible').click();
   assert.equal(await page.locator('[data-deal-selected]').getAttribute('aria-pressed'),'true','Squad filter persists after card navigation');
   assert.equal(await page.locator('[data-deal-actions] a[href*="cmd=pick_card"]').count(),1,'Remaining copy can be recruited');
   assert.equal(await page.locator('[data-deal-actions] a[href*="cmd=unpick_card"]').count(),1,'Squad copy can be returned');
   await page.locator('[data-deal-actions] a[href*="cmd=unpick_card"]').click();
   assert.equal(commands.at(-1).cmd,'unpick_card');
   assert.equal(commands.at(-1).query.ukid,'host-squad');
   assert.equal(commands.at(-1).query.deal_selected,'1');
   await page.locator('[data-deal-selected]').click();
   assert.equal((await visibleViewIds()).size,30,'Repeat selected click restores the whole list without an All control');
   await page.locator('[data-view-filter="element:plains"]').click();
   await page.locator('[data-view-filter="crystal:gold"]').click();
   assert.equal((await visibleViewIds()).size,1,'Deal element and crystal filters combine');
   assert.equal(await page.locator('[data-view-filter="crystal:gold"]').getAttribute('aria-label'),'Золото: 30/30','Filtering does not overwrite resource balances');
   await page.getByRole('button',{name:'Стоимость летающих: 2/15',exact:true}).hover();
   assert.ok(await page.locator('.pregame-stat-tooltip').isVisible(),'Flying budget hint');
   await page.keyboard.press('Escape');
   await page.locator('[data-deal-selected]').click();
   assert.equal(await page.locator('[data-view-filter][aria-pressed="true"]').count(),0,'Selected control clears card filters');
   await page.goto('https://pregame.test/deal-empty-squad');
   await page.locator('[data-deal-selected]').click();
   assert.ok(await page.locator('[data-view-empty]').isVisible());
   await page.locator('[data-deal-selected]').click();
   assert.ok((await visibleViewIds()).size>0,'Empty squad can return to full list');
   await page.goto('https://pregame.test/deal-host');
   const reshuffle=page.locator('.pregame-reshuffle');
   await reshuffle.hover();
   assert.match(await page.locator('.pregame-stat-tooltip').textContent(),/Пересдать колоду.*штраф: 1 золото, осталось: 3/);
   assert.ok((await page.locator('.pregame-stat-tooltip').boundingBox()).y+(await page.locator('.pregame-stat-tooltip').boundingBox()).height<=(await reshuffle.boundingBox()).y,'Reshuffle hint does not cover the action');
   const beforeReshuffle=commands.length;
   let cancelSeen=false;
   page.once('dialog',async dialog=>{cancelSeen=true;await dialog.dismiss();});
   await reshuffle.click();
   assert.ok(cancelSeen,'Reshuffle still asks for confirmation');
   assert.equal(commands.length,beforeReshuffle,'Cancel does not send reshuffle');
   page.once('dialog',dialog=>dialog.accept());
   await reshuffle.click();
   assert.equal(commands.at(-1).cmd,'reshuffle','Confirm sends the existing reshuffle command');
   page.once('dialog',dialog=>dialog.accept());
   await page.locator('.pregame-panel-footer a[href*="cmd=confirm_deal"]').click();
   assert.equal(commands.at(-1).cmd,'confirm_deal');
   await page.goto('https://pregame.test/deal-pending');
   assert.equal(await page.locator('.pregame-panel-content .card-choice-form').count(),1,'Special recruit dialog stays available in InfoPanel');
   await page.locator('.card-choice-form input[name=x][value="4"]').check();
   await page.locator('.card-choice-form button[type=submit]').click();
   assert.equal(commands.at(-1).cmd,'choose_deal_variable_recruit');
   assert.equal(commands.at(-1).query.x,'4');
   await page.setViewportSize({width,height:450});
   await page.goto('https://pregame.test/deal-mixed');
   for (let i=0;i<await page.locator('[data-view-pagination] button').count();i++) {
    await page.locator('[data-view-pagination] button').nth(i).click();
    assert.ok(await page.locator('.pregame-main').evaluate(el=>el.scrollHeight<=el.clientHeight+1),'Deal pages fit short desktop');
   }
   await page.locator('[data-deal-selected]').click();
   assert.equal(await page.locator('.pregame-view-pages .prepare-card-link:visible').count(),1);
   await page.setViewportSize({width,height:900});

   await page.goto('https://pregame.test/turn-host');
   assert.ok(await page.locator('[data-view-pagination] button').count()>1);
   await page.locator('[data-view-pagination] button').nth(1).click();
   await page.locator('.pregame-view-pages .prepare-card-link:visible').first().click();
   assert.equal(await page.locator('.pregame-turn-info .dice-column').count(),2,'Card navigation preserves dice information');
   assert.equal(await page.locator('.pregame-main a[href*="view_to_"]').count(),0);
   await page.locator('.pregame-panel-footer a[href*="cmd=confirm_turn"]').click();
   assert.equal(commands.at(-1).cmd,'confirm_turn');
   assert.equal(commands.at(-1).query.game,'970001');
   assert.ok(Object.hasOwn(commands.at(-1).query,'first'));
   for (const [key,roleParam] of [['side-host','first'],['side-player-chooser','second']]) {
    for (const side of ['1','2']) {
     await page.goto('https://pregame.test/'+key);
     await page.locator('input[name=side][value="'+side+'"]').check();
     await page.locator('.pregame-panel-footer button[form=pregame-side-choice]').click();
     assert.equal(commands.at(-1).cmd,'choose_side');
     assert.equal(commands.at(-1).query.side,side);
     assert.equal(commands.at(-1).query.game,'970001');
     assert.equal(commands.at(-1).query[roleParam],'1');
    }
   }
   await page.goto('https://pregame.test/side-player');
   assert.equal(await page.locator('#pregame-side-choice').count(),0,'Waiting player cannot choose');
   await page.locator('[data-view-zone=sideboard]').click();
   assert.equal(await page.locator('.pregame-view-pages .prepare-card-link:visible').count(),1,'Waiting player can inspect their own deck and sideboard');
   await page.goto('https://pregame.test/place-placed');
   assert.equal(await page.locator('.pregame-panel-content a[href*="cmd=unplace_card"]').count(),1);
   await page.goto('https://pregame.test/draft-host');
   await page.locator('.draft-grid .prepare-card-link').nth(1).click();
   assert.equal(await page.locator('.pregame-card-title').textContent(),'grid-visible-1');
   // Countdown and polling use the unchanged draft_timer.js.
   const timer=page.locator('[data-draft-action-time]'), beforeTime=await timer.textContent();
   await page.waitForFunction(previous=>document.querySelector('[data-draft-action-time]').textContent!==previous,beforeTime,{timeout:4000});
   const previousSync=syncCount;
   await new Promise(resolve=>setTimeout(resolve,2100));
   assert.ok(syncCount>previousSync,'Existing polling runs');
   transition=true; nextKey='view-host';
   await page.waitForFunction(()=>document.querySelector('.screen-prepare-view'),null,{timeout:6000});
   assert.equal(await page.locator('[data-draft-timer]').count(),0,'Transition removes timer');
   // Active Solomon: preview and local selection stay separate from the footer decision form.
   await page.goto('https://pregame.test/draft-solomon-host');
   const cards=page.locator('[data-solomon-position]');
   await cards.nth(0).locator('.prepare-card-link').click();
   assert.equal(await page.locator('[data-solomon-selected]').textContent(),'0','Preview does not select');
   await page.locator('[data-solomon-panel-action=select]').click();
   await cards.nth(1).locator('input').check();
   assert.equal(await page.locator('[data-solomon-selected]').textContent(),'2');
   assert.equal(await page.locator('.pregame-card-title').textContent(),'grid-visible-0');
   await page.locator('[data-solomon-panel-action=cancel]').click();
   assert.equal(await page.locator('[data-solomon-selected]').textContent(),'1','Cancel deselects only previewed card');
   await page.locator('[data-solomon-panel-action=select]').click();
   await page.reload();
   assert.equal(await page.locator('[data-solomon-selected]').textContent(),'2','Selection survives reload');
   await page.getByRole('button',{name:'История выбора',exact:true}).click();
   assert.equal(await page.locator('.pregame-panel-content').getAttribute('data-pregame-content'),'history');
   await page.locator('[data-solomon-history]').click();
   assert.equal(await page.locator('.pregame-panel-content').getAttribute('data-pregame-content'),'card');
   assert.equal(await page.locator('[data-solomon-history]').getAttribute('aria-pressed'),'false');
   await page.locator('[data-solomon-history]').click();
   assert.equal(await page.locator('.pregame-panel-summary .pregame-pool-stat').count(),8,'Statistics remain visible');
   const historyLink=page.locator('.pregame-history-entry .prepare-card-link').first();
   const historyName=await historyLink.locator('.prepare-card__name').textContent();
   await historyLink.click();
   assert.equal(await page.locator('.pregame-card-title').textContent(),historyName);
   assert.equal(await page.locator('.pregame-panel-content [data-solomon-panel-action]').count(),0,'History cards are read-only');
   await page.getByRole('button',{name:'История выбора',exact:true}).click();
   assert.equal(await page.locator('.pregame-draft-history').count(),1);
   await cards.nth(0).locator('.prepare-card-link').click();
   assert.equal(await page.locator('.pregame-panel-content [data-solomon-panel-action]').count(),2);
   assert.equal(await page.locator('[data-solomon-selected]').textContent(),'2');
   await page.getByRole('button',{name:'Тьма: 1',exact:true}).click();
   assert.ok(await page.locator('.pregame-stat-tooltip').isVisible());
   await page.keyboard.press('Escape');
   const confirmRequest=page.waitForRequest(r=>new URL(r.url()).searchParams.get('cmd')==='draft_confirm');
   await page.locator('button[value="draft_confirm"]').click(); await confirmRequest; await page.waitForLoadState();
   const confirm=commands.at(-1); assert.equal(confirm.cmd,'draft_confirm'); assert.deepEqual(confirm.positions,['0','1']); assert.equal(confirm.query.decision_id,'1'); assert.equal(confirm.query.expected_version,'7');
   const passRequest=page.waitForRequest(r=>new URL(r.url()).searchParams.get('cmd')==='draft_pass');
   await page.locator('button[value="draft_pass"]').click(); await passRequest; await page.waitForLoadState();
   assert.equal(commands.at(-1).cmd,'draft_pass');
   // No form is required to inspect the public stack, for either waiting role.
   for(const key of ['draft-solomon-player','draft-solomon-host-waiting']) {
    await page.goto('https://pregame.test/'+key);
    assert.equal(await page.locator('#solomon-decision').count(),0);
    await page.locator('[data-solomon-position] .prepare-card-link').nth(2).click();
    assert.equal(await page.locator('.pregame-card-title').textContent(),'grid-visible-2');
    assert.equal(await page.locator('[name="positions[]"]').count(),0);
   }
   if(width===1600 && process.env.PREGAME_SCREENSHOT) await page.screenshot({path:process.env.PREGAME_SCREENSHOT});
   assert.deepEqual(errors,[],'No JS errors');
   await page.close();
  }
  const context=await browser.newContext({hasTouch:true,viewport:{width:1280,height:900}}),page=await context.newPage();
  await page.route('https://touch.test/**',route=>{
   const url=new URL(route.request().url());
   if(url.pathname.startsWith('/assets/')) { const file=path.join(root,'www',url.pathname); return fs.existsSync(file)?route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.js')?'text/javascript':file.endsWith('.css')?'text/css':'image/png'}):route.fulfill({status:404}); }
   return route.fulfill({body:fixtures['draft-solomon-host'].html,contentType:'text/html; charset=utf-8'});
  });
  await page.goto('https://touch.test/');
  await page.locator('[data-solomon-position] .prepare-card-link').first().tap();
  assert.equal(await page.locator('[data-solomon-selected]').textContent(),'0');
  await page.locator('[data-solomon-panel-action=select]').tap();
  assert.equal(await page.locator('[data-solomon-selected]').textContent(),'1');
  await page.getByRole('button', {name:'Описание карты',exact:true}).tap();
  assert.ok(await page.locator('.pregame-stat-tooltip').isVisible());
  await page.locator('[data-solomon-panel-action=cancel]').tap();
  assert.equal(await page.locator('[data-solomon-selected]').textContent(),'0');
  await context.close();
  console.log('PreGame screen browser tests passed: '+Object.keys(fixtures).length+' fixtures x 1600/1280/1024; cards, actions, scrolling, timer/poll transition, Solomon payloads, keyboard and touch.');
 } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
