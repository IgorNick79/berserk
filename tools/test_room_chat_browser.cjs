const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const fs=require('fs'),path=require('path'),assert=require('assert/strict');
(async()=>{
 const base=process.env.UNIFIED_MENU_URL;assert.equal(base,'http://127.0.0.1:33572');
 const out=path.join(process.env.UNIFIED_MENU_EVIDENCE,'chat');fs.mkdirSync(out,{recursive:true});
 const browser=await chromium.launch({headless:true,executablePath:process.env.BROWSER_EXECUTABLE});
 const checks=[],errors=[];const ok=(name,value)=>{assert(value,name);checks.push(name);};
 try{
  const pages=[];
  for(const name of ['Creator','Candidate','Other']){
   const context=await browser.newContext({viewport:{width:1600,height:900}}),page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
   await page.goto(base+'/auth.php');await page.locator('[name=login]').fill(name);await page.locator('[name=password]').fill('LobbyFixture123');await page.getByRole('button',{name:'Войти',exact:true}).click();await page.waitForURL('**/lobby.php');pages.push(page);
  }
  const [a,b,c]=pages;
  const api=(page,id,data)=>page.evaluate(async({id,data})=>{
   const csrf=document.querySelector('#lobby').dataset.csrf;
   const r=await fetch('/lobby.php?api=1'+(id?'&room='+id:''),{method:data?'POST':'GET',body:data?new URLSearchParams({...data,csrf}):undefined});
   return {code:r.status,data:await r.json()};
  },{id,data});
  const create=async(access)=>{const r=await api(a,null,{action:'create',setup_mode:'system',turn_seconds:'0',access,invited_login:'Candidate',creation_key:require('crypto').randomBytes(16).toString('hex')});assert.equal(r.code,200);return r.data.room;};
  const open=async(page,writable=true)=>{await page.bringToFront();if(await page.locator('#game-chat-panel').isHidden()){await page.locator('[data-chat-toggle]').scrollIntoViewIfNeeded();await page.locator('[data-chat-toggle]').click();}await page.locator('#game-chat-panel').waitFor({state:'visible'});if(writable)await page.waitForFunction(()=>!document.querySelector('[data-chat-input]').disabled);};
  const send=async(page,text)=>{await open(page);await page.locator('[data-chat-input]').fill(text);await page.locator('[data-chat-input]').press('Enter');await page.waitForFunction(()=>document.querySelector('[data-chat-input]').value==='');};
  const seen=async(page,text)=>{await page.locator('.game-chat-message span').filter({hasText:text}).waitFor();};
  let r=await create('open');await a.goto(base+'/lobby.php?room='+r.id);await open(a,false);await a.locator('[data-chat-status]').filter({hasText:'появится второй участник'}).waitFor();
  ok('public empty room chat is read only',await a.locator('[data-chat-input]').isDisabled());await a.keyboard.press('Escape');
  r=await create('closed');await a.goto(base+'/lobby.php?room='+r.id);await b.goto(base+'/lobby.php?room='+r.id);
  await send(a,'Привет <b>мир</b> 😀');await b.waitForFunction(()=>document.querySelector('[data-chat-toggle]').classList.contains('has-unread'));
  ok('room unread notification',true);await open(b);await seen(b,'Привет мир 😀');
  await b.waitForFunction(()=>!document.querySelector('[data-chat-toggle]').classList.contains('has-unread'));ok('room visible message marked read',true);
  ok('room messages have no invented turn',!(await b.locator('.game-chat-message small').textContent()).includes('ход'));
  await send(b,'Ответ до заявки 🐉');await open(a);await seen(a,'Ответ до заявки 🐉');
  ok('two way chat before application',true);ok('shared renderer uses safe text',await a.locator('.game-chat-message b,.game-chat-message img,.game-chat-message script').count()===0);
  await a.reload();await open(a);await seen(a,'Привет мир 😀');await seen(a,'Ответ до заявки 🐉');ok('history survives reload',true);
  await a.screenshot({path:path.join(out,'desktop-chat.png')});
  for(const width of [390,360]){
   await a.setViewportSize({width,height:844});await open(a);const box=await a.locator('#game-chat-panel').boundingBox();
   ok('chat fits mobile '+width,box.x>=0&&box.x+box.width<=width&&box.y>=0&&box.y+box.height<=844);
   ok('chat no horizontal scroll '+width,await a.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
   await a.screenshot({path:path.join(out,'mobile-chat-'+width+'.png')});await a.keyboard.press('Escape');ok('chat Escape closes '+width,await a.locator('#game-chat-panel').isHidden());
  }
  await a.setViewportSize({width:1600,height:900});r=await create('open');await a.goto(base+'/lobby.php?room='+r.id);
  await api(b,r.id,{action:'open',version:r.version});await b.goto(base+'/lobby.php?room='+r.id);await a.getByRole('button',{name:'Принять',exact:true}).waitFor();
  await send(a,'Только для Candidate');await open(b);await seen(b,'Только для Candidate');
  await a.route('**/lobby-chat.php*',route=>new URLSearchParams(route.request().postData()||'').get('message')==='Старый черновик'?route.abort('failed'):route.continue());
  await open(a);await a.locator('[data-chat-input]').fill('Старый черновик');await a.locator('[data-chat-input]').press('Enter');await a.locator('[data-chat-status]').filter({hasText:'Отправка не подтверждена'}).waitFor();
  ok('unconfirmed draft retains retry identity',await a.evaluate(()=>Object.keys(sessionStorage).some(k=>k.startsWith('berserk-chat:room:')&&JSON.parse(sessionStorage.getItem(k)).text==='Старый черновик')));
  await a.keyboard.press('Escape');await a.getByRole('button',{name:'Отказать',exact:true}).click();await b.waitForURL('**/lobby.php');
  const reopened=(await api(a,r.id)).data.room;await api(c,r.id,{action:'open',version:reopened.version});await c.goto(base+'/lobby.php?room='+r.id);await open(c);
  ok('replacement sees no prior history',await c.locator('.game-chat-message').count()===0);
  await open(a);await a.waitForFunction(()=>document.querySelector('[data-chat-input]').value===''&&document.querySelectorAll('.game-chat-message').length===0);
  ok('creator clears old history and pending draft',await a.evaluate(()=>!Object.keys(sessionStorage).some(k=>k.startsWith('berserk-chat:room:')&&sessionStorage.getItem(k)?.includes('Старый черновик'))));
  await send(a,'Для нового собеседника');await open(c);await seen(c,'Для нового собеседника');ok('new peer chat works without old candidate messages',await c.locator('.game-chat-message span').count()===1);
  await a.unroute('**/lobby-chat.php*');
  for(const page of [a,b])await page.goto(base+'/index.php?game='+process.env.UNIFIED_MENU_GAME);
  await send(a,'Боевой чат по-прежнему работает 😀');await open(b);await seen(b,'Боевой чат по-прежнему работает 😀');
  ok('battle keeps existing game context',await b.locator('[data-game-chat]').getAttribute('data-room-id')===null);
  ok('battle keeps turn metadata',(await b.locator('.game-chat-message small').last().textContent()).includes('ход'));
  ok('room history not silently merged into battle',!(await b.locator('[data-chat-history]').textContent()).includes('Для нового собеседника'));
  await b.screenshot({path:path.join(out,'battle-chat-regression.png')});
  ok('no JavaScript errors',errors.length===0);fs.writeFileSync(path.join(out,'browser-checks.json'),JSON.stringify({checks,errors},null,2));
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
