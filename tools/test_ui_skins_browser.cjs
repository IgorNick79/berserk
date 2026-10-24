const fs=require('fs'),path=require('path'),assert=require('assert'),{execFileSync}=require('child_process');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'C:/Users/mi/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const repo=path.resolve(__dirname,'..'),read=f=>fs.readFileSync(path.join(repo,f),'utf8');
const data=JSON.parse(execFileSync(process.env.PHP_BINARY||'C:/OSPanel/modules/php/PHP_8.1/php.exe',[path.join(repo,'tools/test_ui_skins.php'),'--fixture'],{encoding:'utf8'}));
const out=process.env.SKIN_ARTIFACT_DIR||path.join(repo,'tmp','ui-skins');fs.mkdirSync(out,{recursive:true});
(async()=>{const browser=await chromium.launch({headless:true,executablePath:process.env.BROWSER_EXECUTABLE||'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});try{
let saved='grimoire',fail=false,posts=[],errors=[];
async function setup(width){const page=await browser.newPage({viewport:{width,height:1000}});page.on('pageerror',e=>errors.push(e.message));
 await page.route('https://skins.test/**',async r=>{const u=new URL(r.request().url());if(u.pathname==='/panel-theme.php'){
  if(r.request().method()==='POST'){const p=new URLSearchParams(r.request().postData());posts.push(Object.fromEntries(p));if(fail)return r.fulfill({status:503,json:{ok:false,error:'theme_storage_unavailable'}});assert.strictEqual(p.get('csrf'),'test-csrf');assert(!p.has('account_id'));saved=p.get('theme');}
  return r.fulfill({json:{ok:true,theme:saved,csrf:'test-csrf'}});
 }const f=path.join(repo,'www',u.pathname);return r.fulfill(fs.existsSync(f)&&fs.statSync(f).isFile()?{body:fs.readFileSync(f),contentType:f.endsWith('.svg')?'image/svg+xml':f.endsWith('.css')?'text/css':f.endsWith('.webp')?'image/webp':f.endsWith('.png')?'image/png':'text/javascript'}:{body:'<html></html>',contentType:'text/html'});});
 await page.goto('https://skins.test/');
 const html=read('templates/screen/battle.tpl').replace(/<script[\s\S]*?<\/script>/g,'').replace(/<link[^>]*>/g,'').replace(/{{(\w+)}}/g,(_,k)=>data.normal[k]||'');
 await page.setContent('<html class="battle-page"><head><style>'+read('www/assets/css/global.css')+'\n'+read('www/assets/css/battle-mini-card.css')+'\n'+read('www/assets/css/battle-ui-skins.css').replace(/^@import[^;]+;/,'')+'\n'+read('www/assets/css/battle-coordinates.css')+'</style></head><body class="battle-page">'+html+'</body></html>');
 for(const script of ['battle-ui.js','battle-mini-card.js','battle-panel-themes.js','battle-menu.js'])await page.addScriptTag({content:read('www/assets/js/'+script)});
 await page.evaluate(()=>{window.testMenu=BerserkBattleMenu.create(document.querySelector('[data-battle-sync-root]'),()=>{throw Error('Cosmetic menu sent a game command');},()=>false,()=>true);});
 await page.waitForFunction(()=>!document.querySelector('[data-theme-choice]').disabled);
 return page;
}
const page=await setup(1600);
const replace=async(d,field=false)=>{await page.evaluate(({d,field})=>{document.querySelector('[data-battle-fragment=info_panel_html]').innerHTML=d.info_panel_html;if(field){document.querySelector('[data-battle-fragment=field_html]').innerHTML=d.field_html;document.querySelector('[data-battle-fragment=fly_zones_html]').innerHTML=d.fly_zones_html;}document.dispatchEvent(new Event('berserk:battle-updated'));},{d,field});await page.waitForTimeout(40);};
await replace(data.dice);
assert.strictEqual(await page.locator('.info-panel').evaluate(e=>e.offsetWidth),440);
assert.strictEqual(await page.locator('.panel-dice-face').count(),2);assert((await page.locator('.panel-dice-value').first().textContent()).includes('+2= 6'));assert((await page.locator('.panel-dice-value').nth(1).textContent()).includes('-1= 2'));
await page.locator('.battle-menu').click();assert(await page.locator('.battle-menu').evaluate(e=>e.classList.contains('is-open')));
await page.locator('[data-menu-panel-theme]').click();assert(await page.locator('[data-panel-theme-picker]').isVisible());
const menuColor=await page.locator('.battle-menu').evaluate(e=>getComputedStyle(e).backgroundImage),endBackground=await page.locator('.green-btn').evaluate(e=>getComputedStyle(e).backgroundImage);
const expected={grimoire:'rgb(41, 35, 31)',stone:'rgb(36, 42, 41)',leather:'rgb(70, 49, 38)',parchment:'rgb(52, 48, 41)'};
for(const id of Object.keys(expected)){
 await page.locator('[data-theme-choice='+id+']').click();await page.waitForFunction(id=>document.querySelector('[data-battle-sync-root]').dataset.panelTheme===id,id);
 assert.strictEqual(await page.locator('.info-panel').evaluate(e=>getComputedStyle(e).backgroundColor),expected[id]);
 assert.strictEqual(await page.locator('.battle-menu').evaluate(e=>getComputedStyle(e).backgroundImage),menuColor);
 assert.strictEqual(await page.locator('.green-btn').evaluate(e=>getComputedStyle(e).backgroundImage),endBackground);
 await page.keyboard.press('Escape');assert(await page.locator('[data-panel-theme-picker]').isHidden());assert(await page.locator('#battle-menu-panel').isVisible());
 await page.keyboard.press('Escape');
 await page.screenshot({path:path.join(out,'theme-'+id+'.png')});
 await replace(data.dice);assert.strictEqual(await page.locator('[data-battle-sync-root]').getAttribute('data-panel-theme'),id);
 await page.locator('.battle-menu').click();await page.locator('[data-menu-panel-theme]').click();
}
fail=true;await page.locator('[data-theme-choice=stone]').click();await page.waitForFunction(()=>document.querySelector('[data-theme-status]').textContent.includes('Не удалось'));assert.strictEqual(await page.locator('[data-battle-sync-root]').getAttribute('data-panel-theme'),'parchment');fail=false;
await page.locator('[data-theme-choice=stone]').click();await page.waitForFunction(()=>document.querySelector('[data-battle-sync-root]').dataset.panelTheme==='stone');await page.keyboard.press('Escape');await page.keyboard.press('Escape');
const second=await setup(375);assert.strictEqual(await second.locator('[data-battle-sync-root]').getAttribute('data-panel-theme'),'stone');
await second.evaluate(d=>{document.querySelector('[data-battle-fragment=info_panel_html]').innerHTML=d.info_panel_html;},data.dice);
await second.locator('.info-panel').scrollIntoViewIfNeeded();await second.screenshot({path:path.join(out,'theme-mobile.png')});
await second.locator('.battle-menu').click();await second.locator('[data-menu-panel-theme]').click();assert(await second.locator('[data-panel-theme-picker]').evaluate(e=>e.getBoundingClientRect().right<=innerWidth));await second.keyboard.press('Escape');await second.keyboard.press('Escape');await second.close();
await replace(data.normal,true);const mini=page.locator('[data-mini-card="1"]').first();await mini.hover();await page.waitForTimeout(300);assert.strictEqual(await page.locator('.battle-mini-zoom.is-open').count(),1);
await replace(data.closed,true);await mini.hover();await page.waitForTimeout(350);assert.strictEqual(await page.locator('.battle-mini-zoom').count(),0);assert(await mini.evaluate(e=>getComputedStyle(e).transform!=='none'&&getComputedStyle(e,'::after').backgroundColor==='rgb(255, 255, 255)'&&getComputedStyle(e,'::after').opacity==='0.3'));
// Secondary panels survive fragment replacement, yield to new events, and retain drafts.
await replace(data.normal,true);
await page.evaluate(()=>{
 const root=document.querySelector('[data-battle-sync-root]');
 const chat=document.createElement('section');chat.id='game-chat-panel';chat.className='game-chat-panel';chat.innerHTML='<div class="game-chat-header">Сообщения</div><textarea></textarea>';window.secondaryChat=chat;
 window.notices=0;root.berserkTurnTimer={notify(){window.notices++;}};
 root.secondaryPanel(chat);
});
assert(await page.locator('.battle-secondary-view').isVisible());
assert(await page.locator('.battle-selected-card').isHidden());
await page.locator('#game-chat-panel textarea').fill('Неотправленный текст');
await replace(data.dice,false);
assert(await page.locator('#game-chat-panel').isVisible());
assert.strictEqual(await page.locator('#game-chat-panel textarea').inputValue(),'Неотправленный текст');
assert.strictEqual(await page.evaluate(()=>window.notices),1);
await page.locator('#game-chat-panel textarea').fill('');
await replace(data.normal,false);await replace(data.dice,false);
assert(await page.locator('#game-chat-panel').isHidden());
await page.evaluate(()=>document.querySelector('[data-battle-sync-root]').secondaryPanel(window.secondaryChat));
assert(await page.locator('#game-chat-panel').isVisible());
await page.evaluate(()=>document.querySelector('[data-battle-sync-root]').secondaryPanel(null));
assert(await page.locator('.battle-secondary-view').isHidden());
// Exercise actual chat and journal handlers after docking, including sending and reopening.
await replace(data.normal,false);
await page.evaluate(()=>window.secondaryChat.remove());
await page.addStyleTag({content:read('www/assets/css/battle-chat.css')+'\n'+read('www/assets/css/battle-journal.css').replace(/^@import[^;]+;/,'')});
await page.evaluate(html=>document.body.insertAdjacentHTML('beforeend',html),read('templates/includes/game_chat.tpl').replace(/{{chat_game_id}}/g,'1').replace(/{{chat_sender_id}}/g,'2').replace(/{{chat_csrf}}/g,'csrf'));
let sent=[];
await page.route('https://skins.test/?**',async r=>{
 const u=new URL(r.request().url()),action=u.searchParams.get('ajax');
 if(action==='chat_send'){sent.push(new URLSearchParams(r.request().postData()).get('message'));return r.fulfill({json:{ok:true}});}
 return r.fulfill({json:{ok:true,messages:[],latest_id:'0',last_read_id:'0',can_send:true,has_more:false,unread:false}});
});
await page.addScriptTag({content:read('www/assets/js/battle-chat.js')});
await page.waitForFunction(()=>!document.querySelector('[data-chat-input]').disabled);
await page.locator('[data-chat-toggle]').click();
assert(await page.locator('.info-panel #game-chat-panel').isVisible());
await page.locator('[data-chat-input]').fill('Проверка переноса');await page.locator('[data-chat-input]').press('Enter');
await page.waitForFunction(()=>document.querySelector('[data-chat-input]').value==='');
assert.deepStrictEqual(sent,['Проверка переноса']);
await replace(data.normal,false);assert(await page.locator('.info-panel #game-chat-panel').isVisible());
await page.locator('[data-chat-close]').click();assert(await page.locator('#game-chat-panel').isHidden());
await page.locator('[data-chat-toggle]').click();assert(await page.locator('#game-chat-panel').isVisible());
await page.route('https://skins.test/battle-journal.php**',r=>r.fulfill({json:{entries:[{seq:1,kind:'event',text:'Проверка журнала'}],cursor:1,recording:{available:true,has_gaps:false},has_more:false}}));
await page.evaluate(code=>{document.body.insertAdjacentHTML('beforeend','<section id="battle-journal" class="battle-journal" hidden><header>Лог игры<button data-journal-close>×</button></header><p data-journal-notice></p><p data-journal-state></p><div data-journal-scroll class="battle-journal-scroll"><ol data-journal-entries></ol></div></section>');const script=document.createElement('script');script.dataset.journalUrl='/battle-journal.php';script.textContent=code;document.body.appendChild(script);},read('www/assets/js/battle-journal.js'));
await page.locator('[data-action=battle-log]').evaluate(b=>{b.disabled=false;b.removeAttribute('aria-disabled');b.click();});
await page.waitForFunction(()=>document.querySelector('[data-journal-entries]').textContent.includes('Проверка журнала'));
assert(await page.locator('.info-panel #battle-journal').isVisible());assert(await page.locator('#game-chat-panel').isHidden());
await page.screenshot({path:path.join(out,'journal-infopanel.png')});
await page.setViewportSize({width:375,height:900});
assert(await page.locator('#battle-journal').evaluate(e=>{const r=e.getBoundingClientRect(),p=e.closest('.info-panel').getBoundingClientRect();return r.left>=p.left&&r.right<=p.right+1;}));
await page.screenshot({path:path.join(out,'journal-infopanel-mobile.png')});
await page.setViewportSize({width:1600,height:1000});
await page.locator('[data-journal-close]').click();assert(await page.locator('#battle-journal').isHidden());
assert(!errors.length,errors.join('\n'));assert(posts.every(p=>Object.keys(p).sort().join(',')==='csrf,theme'));
console.log('PASS: four real themes + screenshots, 440px panel, themed buttons/dice, unchanged end-turn/menu, save/error/reload/AJAX, mobile picker, closed zoom disabled, real chat send/reopen, journal docking, pending priority and draft notification.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1)});
