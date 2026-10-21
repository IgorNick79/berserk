'use strict';
const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fs=require('fs'),path=require('path'),assert=require('assert/strict');
const base=process.env.LOBBY_TEST_URL,out=process.env.LOBBY_TEST_EVIDENCE;
if(!/^http:\/\/127\.0\.0\.1:33562$/.test(base||'')||!out)throw Error('Isolated lab URL/evidence required');
const results=[];
function check(name,condition){results.push({test:name,pass:!!condition});assert.ok(condition,name);}
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.BROWSER_EXECUTABLE});
 try{
  const contexts=await Promise.all([browser.newContext(),browser.newContext(),browser.newContext()]);
  const [a,b,c]=await Promise.all(contexts.map(ctx=>ctx.newPage()));const errors=[];
  for(const page of [a,b,c])page.on('pageerror',e=>errors.push(e.message));
  async function login(page,name){await page.goto(base+'/auth.php');await page.locator('[name=login]').fill(name);await page.locator('[name=password]').fill('LobbyFixture123');await page.getByRole('button',{name:'Войти',exact:true}).click();await page.waitForURL('**/lobby.php');await page.getByRole('heading',{name:'Игры',exact:true}).waitFor();}
  await login(a,'Creator');await login(b,'Candidate');await login(c,'Other');
  check('no separate join button',await b.getByRole('button',{name:'Присоединиться',exact:true}).count()===0);
  await a.getByRole('button',{name:'Создать игру',exact:true}).click();await a.waitForURL('**/lobby.php?room=*');
  const id=new URL(a.url()).searchParams.get('room'),row=b.locator('tr[data-room-id="'+id+'"]');
  await row.waitFor({timeout:7000});check('lobby table updates without reload',await row.count()===1);
  await row.click();await b.waitForURL('**/lobby.php?room='+id);await b.getByText('Ожидание подтверждения создателя',{exact:true}).waitFor();
  await a.getByRole('button',{name:'Отказать',exact:true}).waitFor({timeout:7000});
  check('creator sees request by polling',await a.getByText('Игрок Candidate хочет присоединиться',{exact:true}).count()===1);
  check('candidate no confirmation buttons',await b.getByRole('button',{name:'Подтвердить',exact:true}).count()===0);
  await a.screenshot({path:path.join(out,'room-request.png'),fullPage:true});
  await a.getByRole('button',{name:'Отказать',exact:true}).click();await b.waitForURL('**/lobby.php',{timeout:7000});
  check('rejected candidate automatically returns to lobby',new URL(b.url()).search==='');
  await b.goto(base+'/lobby.php?room='+id);check('old room URL rejected',await b.getByText('Доступ к комнате закрыт.',{exact:true}).count()===1);
  await b.goto(base+'/lobby.php');const row2=b.locator('tr[data-room-id="'+id+'"]');await row2.waitFor();await row2.click();await b.waitForURL('**/lobby.php?room='+id);
  await a.getByRole('button',{name:'Подтвердить',exact:true}).waitFor({timeout:7000});
  const oldCandidate=await b.evaluate(async()=>await(await fetch('lobby.php?api=1&room='+new URL(location.href).searchParams.get('room'))).json());
  let acceptPosts=0;
  await a.route('**/lobby.php?api=1&room='+id,async route=>{
   if(route.request().method()==='POST'){
    acceptPosts++;await route.fetch();await route.abort('connectionreset');
   }else await route.continue();
  });
  await a.getByRole('button',{name:'Подтвердить',exact:true}).click();
  const message='Игра создана. Игровой процесс будет подключён на следующем этапе.';
  await a.getByText(message,{exact:true}).waitFor();await b.getByText(message,{exact:true}).waitFor({timeout:7000});
  check('both see safe started placeholder',await a.getByText(message,{exact:true}).count()===1&&await b.getByText(message,{exact:true}).count()===1);
  check('lost committed HTTP reply recovers without repeating POST',acceptPosts===1);
  await a.unroute('**/lobby.php?api=1&room='+id);
  let staleReplies=0;
  await b.route('**/lobby.php?api=1&room='+id,async route=>{staleReplies++;await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify(oldCandidate)});});
  await b.waitForTimeout(2200);check('stale poll version cannot restore candidate',staleReplies>=1&&await b.getByText(message,{exact:true}).count()===1);
  await b.unroute('**/lobby.php?api=1&room='+id);
  check('no legacy game links',await a.locator('a[href*="first"],a[href*="second"],a[href*="index.php"]').count()===0);
  await a.screenshot({path:path.join(out,'room-started.png'),fullPage:true});
  await c.waitForTimeout(2000);const occupied=c.locator('tr[data-room-id="'+id+'"]');check('outsider started row disabled',await occupied.getAttribute('aria-disabled')==='true');
  await c.setViewportSize({width:390,height:844});await c.screenshot({path:path.join(out,'lobby-mobile.png'),fullPage:true});
  check('mobile table fits viewport',await c.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
  check('no browser script errors',errors.length===0);
  console.log(JSON.stringify({passed:results.length,results},null,2));
 }finally{fs.writeFileSync(path.join(out,'browser-results.json'),JSON.stringify(results,null,2));await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
