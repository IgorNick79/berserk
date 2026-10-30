const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const fs=require('fs'),path=require('path'),assert=require('assert/strict');
(async()=>{
 const base=process.env.UNIFIED_MENU_URL;assert.equal(base,'http://127.0.0.1:33572');
 const out=path.join(process.env.UNIFIED_MENU_EVIDENCE,'room');fs.mkdirSync(out,{recursive:true});
 const browser=await chromium.launch({headless:true,executablePath:process.env.BROWSER_EXECUTABLE});
 const checks=[],errors=[];const ok=(name,value)=>{assert(value,name);checks.push(name);};
 try{
  const pages=[];for(const name of ['Creator','Candidate','Other']){
   const context=await browser.newContext({viewport:{width:1600,height:900}});
   await context.addInitScript(()=>{window.roomToneCount=0;window.AudioContext=class{constructor(){this.state='running';this.currentTime=0;this.destination={};}resume(){this.state='running';return Promise.resolve();}createOscillator(){return {frequency:{value:0},connect(){},disconnect(){},start(){window.roomToneCount++;},stop(){}};}createGain(){return {gain:{setValueAtTime(){},linearRampToValueAtTime(){},exponentialRampToValueAtTime(){}},connect(){},disconnect(){}};}};});
   const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
   await page.goto(base+'/auth.php');await page.locator('[name=login]').fill(name);await page.locator('[name=password]').fill('LobbyFixture123');await page.getByRole('button',{name:'Войти',exact:true}).click();await page.waitForURL('**/lobby.php');pages.push(page);
  }
  const [a,b,c]=pages;
  const api=async(page,id,data)=>page.evaluate(async({id,data})=>{
   if(data)data.csrf=document.querySelector('#lobby').dataset.csrf;
   const response=await fetch('lobby.php?api=1'+(id?'&room='+id:''),{method:data?'POST':'GET',body:data?new URLSearchParams(data):undefined});return {code:response.status,data:await response.json()};
  },{id,data});
  const create=async(access,seconds)=>{
   const r=await api(a,null,{action:'create',setup_mode:'system',access,turn_seconds:String(seconds),invited_login:'Candidate',creation_key:require('crypto').randomBytes(16).toString('hex')});assert.equal(r.code,200);return r.data.room;
  };
  const save=async(page,name)=>{
   await page.evaluate(()=>Promise.all(document.getAnimations().map(a=>a.finished.catch(()=>{}))));
   if(page.viewportSize().width<500&&!name.includes('menu')){
    await page.evaluate(()=>window.scrollTo(0,0));await page.screenshot({path:path.join(out,name+'.png')});
    await page.evaluate(()=>window.scrollTo(0,document.documentElement.scrollHeight));await page.screenshot({path:path.join(out,name+'-actions.png')});
   }else await page.screenshot({path:path.join(out,name+'.png'),fullPage:page.viewportSize().width>=500});
  };
  const variants=async(page,name)=>{
   await save(page,'desktop-'+name);
   for(const width of [390,360]){await page.setViewportSize({width,height:844});ok('no overflow '+name+width,await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));await page.evaluate(()=>window.scrollTo(0,document.documentElement.scrollHeight));await save(page,'mobile-'+name+'-'+width);}
   await page.setViewportSize({width:1600,height:900});
  };
  let r=await create('open',0);await a.goto(base+'/lobby.php?room='+r.id);await a.locator('[data-room-screen]').waitFor();
  ok('public saved access',await a.locator('[data-room-field=access]').textContent()==='Открытая партия');
  ok('explicit untimed saved value',await a.locator('[data-room-field=timer]').textContent()==='Без ограничения');
  ok('waiting creator has no fake action',await a.locator('[data-room-actions] button').count()===0);
  ok('shared chat mounted in footer',await a.locator('.room-footer [data-chat-toggle]').count()===1);
  ok('extra room labels removed',await a.getByText('Перед началом партии',{exact:true}).count()===0&&await a.getByText('Ожидаем второго игрока',{exact:true}).count()===0);
  ok('room scrollbar hidden',await a.locator('.room-info-scroll').evaluate(e=>getComputedStyle(e).scrollbarWidth==='none'));
  ok('room text selection disabled',await a.locator('#room-heading').evaluate(e=>getComputedStyle(e).userSelect==='none'));
  ok('room context menu suppressed',await a.locator('#room-heading').evaluate(e=>!e.dispatchEvent(new MouseEvent('contextmenu',{bubbles:true,cancelable:true}))));
  const hint=await a.locator('.room-action-status').boundingBox(),toggle=await a.locator('[data-chat-toggle]').boundingBox();ok('waiting hint centered with icon',Math.abs(hint.y+hint.height/2-toggle.y-toggle.height/2)<2);
  await a.locator('[data-chat-toggle]').hover();await a.locator('[data-chat-toggle] .battle-icon-tooltip').waitFor({state:'visible'});ok('chat tooltip available',await a.locator('[data-chat-toggle] .battle-icon-tooltip').isVisible());
  await a.waitForFunction(()=>getComputedStyle(document.querySelector('[data-chat-toggle] .battle-icon-tooltip')).opacity==='1');const tip=await a.locator('[data-chat-toggle] .battle-icon-tooltip').boundingBox(),icon=await a.locator('[data-chat-toggle]').boundingBox();ok('message tooltip centered',Math.abs(tip.x+tip.width/2-icon.x-icon.width/2)<2);
  await variants(a,'open');
  const image=await a.locator('.room-illustration img').elementHandle();await a.waitForTimeout(1800);ok('polling retains illustration DOM',await image.evaluate(e=>e.isConnected));
  await a.locator('.unified-menu-toggle').click();await a.getByRole('link',{name:'В лобби',exact:true}).waitFor();await save(a,'desktop-menu');await a.keyboard.press('Escape');
  ok('shared menu closes',await a.locator('.unified-menu-toggle').getAttribute('aria-expanded')==='false');
  await api(b,r.id,{action:'open',version:r.version});await b.goto(base+'/lobby.php?room='+r.id);await b.getByRole('button',{name:'Отмена',exact:true}).waitFor();await a.getByRole('button',{name:'Принять',exact:true}).waitFor();
  const cancel=await b.locator('[data-room-action=withdraw]').boundingBox(),cancelRow=await b.locator('[data-room-actions]').boundingBox();ok('withdraw battle size and left alignment',cancel.height>=40&&Math.abs(cancel.x-cancelRow.x)<1);
  await variants(b,'candidate-cancel');
  let cancellations=0;
  await b.route('**/lobby.php?api=1&room=*',async route=>{if(route.request().method()==='POST'&&new URLSearchParams(route.request().postData()).get('action')==='withdraw'){cancellations++;await route.fetch();await route.abort('failed');}else await route.continue();});
  await b.getByRole('button',{name:'Отмена',exact:true}).click();await b.waitForURL('**/lobby.php');await a.locator('[data-room-field=creatorStatus]').filter({hasText:'Ожидает соперника'}).waitFor();
  ok('cancel survives lost response without retry',cancellations===1&&(await api(a,r.id)).data.room.state==='open');ok('own cancellation emits no decision signal',await b.evaluate(()=>window.roomToneCount===0));
  await b.unroute('**/lobby.php?api=1&room=*');
  r=await create('closed',180);await a.goto(base+'/lobby.php?room='+r.id);await b.goto(base+'/lobby.php?room='+r.id);await b.getByRole('button',{name:'Присоединиться',exact:true}).waitFor();
  ok('private saved access',await b.locator('[data-room-field=access]').textContent()==='Закрытая партия');ok('saved 180 seconds',await b.locator('[data-room-field=timer]').textContent()==='180 секунд на каждый ход');
  ok('private outsider details denied',(await api(c,r.id)).code===403);ok('private outsider omitted',!(await api(c)).data.rooms.some(x=>x.id===r.id));
  await variants(b,'invited');await b.getByRole('button',{name:'Присоединиться',exact:true}).click();await a.getByRole('button',{name:'Принять',exact:true}).waitFor();
  await a.waitForFunction(()=>window.roomToneCount===2);ok('host hears new request once',true);await a.waitForTimeout(1700);ok('polling does not repeat host signal',await a.evaluate(()=>window.roomToneCount===2));
  await a.reload();await a.getByRole('button',{name:'Принять',exact:true}).waitFor();await a.waitForTimeout(500);ok('reload does not repeat request signal',await a.evaluate(()=>window.roomToneCount===0));
  await b.locator('[data-room-field=hint]').filter({hasText:'Ожидаем подтверждения создателя'}).waitFor();
  ok('two browsers synchronize candidate',await a.locator('[data-room-field=second]').textContent()==='Candidate');
  ok('battle green accept',await a.locator('[data-room-action=accept]').evaluate(e=>getComputedStyle(e).getPropertyValue('--btn-top').trim()==='#50ad62'));
  ok('battle red reject',await a.locator('[data-room-action=reject]').evaluate(e=>getComputedStyle(e).getPropertyValue('--btn-top').trim()==='#c66a63'));
  ok('battle-sized room actions',(await a.locator('[data-room-action=accept]').boundingBox()).height>=40);
  ok('decision status removed',await a.getByText('Ожидаем решения создателя',{exact:true}).count()===0);
  const firstAction=await a.locator('[data-room-action=accept]').boundingBox(),actionRow=await a.locator('[data-room-actions]').boundingBox();ok('actions aligned left',Math.abs(firstAction.x-actionRow.x)<1);
  await variants(a,'request');await variants(b,'candidate');
  for(const width of [1366,1024,390,360]){
   await a.setViewportSize({width,height:width<500?844:768});
   ok('no horizontal scroll '+width,await a.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
   await a.getByRole('button',{name:'Принять',exact:true}).scrollIntoViewIfNeeded();
   ok('accept reachable '+width,await a.getByRole('button',{name:'Принять',exact:true}).isVisible());
   ok('single mobile document scroll '+width,width>760||await a.locator('.room-info-scroll').evaluate(e=>getComputedStyle(e).overflowY==='visible'));
   if(width<500){
    await a.evaluate(()=>window.scrollTo(0,document.documentElement.scrollHeight));
    ok('last parameter clear of footer '+width,await a.locator('[data-room-field=timer]').evaluate(e=>e.getBoundingClientRect().bottom<=document.querySelector('.room-footer').getBoundingClientRect().top));
    const accept=await a.getByRole('button',{name:'Принять',exact:true}).boundingBox(),reject=await a.getByRole('button',{name:'Отказать',exact:true}).boundingBox();
    ok('mobile actions fit side by side '+width,Math.abs(accept.y-reject.y)<1&&accept.width>0&&reject.x+reject.width<=width);
   }
   await save(a,'request-'+width);
   if(width===360){await a.locator('.unified-menu-toggle').click();await a.getByRole('link',{name:'В лобби',exact:true}).waitFor();await save(a,'mobile-menu');ok('menu fits mobile',await a.locator('.unified-menu-panel').evaluate(e=>{const r=e.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth&&r.bottom<=innerHeight;}));await a.keyboard.press('Escape');}
  }
  await a.setViewportSize({width:1600,height:900});
  const sound=async enabled=>{await a.evaluate(async value=>{await fetch('/ui-preferences.php',{method:'POST',body:new URLSearchParams({csrf:document.querySelector('#lobby').dataset.csrf,key:'sound_enabled',value})});await window.BerserkPreferences.refresh();},enabled?'1':'0');await a.waitForFunction(expected=>window.BerserkPreferences.current.sound_enabled===expected,enabled);};
  await sound(false);const stale=(await api(a,r.id)).data.room;
  await a.getByRole('button',{name:'Отказать',exact:true}).click();await b.getByRole('button',{name:'Присоединиться',exact:true}).waitFor();
  await a.locator('[data-room-field=creatorStatus]').filter({hasText:'Ожидает соперника'}).waitFor();
  await b.waitForFunction(()=>window.roomToneCount===2);ok('candidate hears rejection once',true);
  ok('rejection preserves invitation',(await api(b,r.id)).data.room.role==='invited');
  await b.goto(base+'/lobby.php?room='+r.id);await b.getByRole('button',{name:'Присоединиться',exact:true}).click();await a.getByRole('button',{name:'Принять',exact:true}).waitFor();
  await a.waitForTimeout(1700);ok('muted host receives no sound',await a.evaluate(()=>window.roomToneCount===0));await sound(true);ok('enabling sound does not replay old request',await a.evaluate(()=>window.roomToneCount===0));
  ok('stale accept rejected',(await api(a,r.id,{action:'accept',version:stale.version,candidate_request_id:stale.candidate_request_id})).code===409);
  let accepts=0;a.on('request',request=>{if(request.method()==='POST'&&request.url().includes('lobby.php')&&request.postData()?.includes('action=accept'))accepts++;});
  await a.getByRole('button',{name:'Принять',exact:true}).click();await a.waitForURL('**/index.php?game=*');await b.waitForURL('**/index.php?game=*');await b.waitForFunction(()=>window.roomToneCount===2);ok('candidate hears acceptance after navigation',true);ok('single accept request',accepts===1);ok('same created game in both browsers',a.url()===b.url());
  ok('no JavaScript errors',errors.length===0);fs.writeFileSync(path.join(out,'browser-checks.json'),JSON.stringify({checks,errors},null,2));
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
