const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const fs=require('fs'),path=require('path'),assert=require('assert/strict');
(async()=>{
 const base=process.env.UNIFIED_MENU_URL;assert.equal(base,'http://127.0.0.1:33572');
 const out=path.join(process.env.UNIFIED_MENU_EVIDENCE,'room');fs.mkdirSync(out,{recursive:true});
 const browser=await chromium.launch({headless:true,executablePath:process.env.BROWSER_EXECUTABLE});
 const checks=[],errors=[];const ok=(name,value)=>{assert(value,name);checks.push(name);};
 try{
  const pages=[];for(const name of ['Creator','Candidate','Other']){
   const context=await browser.newContext({viewport:{width:1600,height:900}});const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
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
  await variants(a,'open');
  const image=await a.locator('.room-illustration img').elementHandle();await a.waitForTimeout(1800);ok('polling retains illustration DOM',await image.evaluate(e=>e.isConnected));
  await a.locator('.unified-menu-toggle').click();await a.getByRole('link',{name:'В лобби',exact:true}).waitFor();await save(a,'desktop-menu');await a.keyboard.press('Escape');
  ok('shared menu closes',await a.locator('.unified-menu-toggle').getAttribute('aria-expanded')==='false');
  r=await create('closed',180);await a.goto(base+'/lobby.php?room='+r.id);await b.goto(base+'/lobby.php?room='+r.id);await b.getByRole('button',{name:'Присоединиться',exact:true}).waitFor();
  ok('private saved access',await b.locator('[data-room-field=access]').textContent()==='Закрытая партия');ok('saved 180 seconds',await b.locator('[data-room-field=timer]').textContent()==='180 секунд на каждый ход');
  ok('private outsider details denied',(await api(c,r.id)).code===403);ok('private outsider omitted',!(await api(c)).data.rooms.some(x=>x.id===r.id));
  await variants(b,'invited');await b.getByRole('button',{name:'Присоединиться',exact:true}).click();await a.getByRole('button',{name:'Принять',exact:true}).waitFor();
  await b.locator('[data-room-field=hint]').filter({hasText:'Ожидаем подтверждения создателя'}).waitFor();
  ok('two browsers synchronize candidate',await a.locator('[data-room-field=second]').textContent()==='Candidate');
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
  await a.setViewportSize({width:1600,height:900});const stale=(await api(a,r.id)).data.room;
  await a.getByRole('button',{name:'Отказать',exact:true}).click();await b.getByRole('button',{name:'Присоединиться',exact:true}).waitFor();
  await a.locator('[data-room-field=status]').filter({hasText:'Ожидаем второго игрока'}).waitFor();
  ok('rejection preserves invitation',(await api(b,r.id)).data.room.role==='invited');
  await b.goto(base+'/lobby.php?room='+r.id);await b.getByRole('button',{name:'Присоединиться',exact:true}).click();await a.getByRole('button',{name:'Принять',exact:true}).waitFor();
  ok('stale accept rejected',(await api(a,r.id,{action:'accept',version:stale.version,candidate_request_id:stale.candidate_request_id})).code===409);
  let accepts=0;a.on('request',request=>{if(request.method()==='POST'&&request.url().includes('lobby.php')&&request.postData()?.includes('action=accept'))accepts++;});
  await a.getByRole('button',{name:'Принять',exact:true}).click();await a.waitForURL('**/index.php?game=*');await b.waitForURL('**/index.php?game=*');ok('single accept request',accepts===1);ok('same created game in both browsers',a.url()===b.url());
  ok('no JavaScript errors',errors.length===0);fs.writeFileSync(path.join(out,'browser-checks.json'),JSON.stringify({checks,errors},null,2));
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
