'use strict';
const fs=require('fs'),path=require('path'),{execFileSync}=require('child_process');
const assert=require('assert/strict'),{chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const base=process.env.CHAT_TEST_URL,php=process.env.PHP_BINARY;
if(!/^http:\/\/127\.0\.0\.1:\d+\/index\.php$/.test(base||'') || !php) throw Error('Disposable loopback URL and PHP_BINARY required');
if(fs.existsSync(path.join(__dirname,'..','.git'))) throw Error('Disposable source copy required');
const fixture=(...args)=>JSON.parse(execFileSync(php,[path.join(__dirname,'test_game_chat_fixture.php'),...args.map(String)],{encoding:'utf8'}));
let checks=0;function ok(value,label){checks++;assert.ok(value,label);}
function equal(a,b,label){checks++;assert.deepEqual(a,b,label);}
const freshId=()=>require('crypto').randomBytes(16).toString('hex');
(async()=>{
 fixture('verify');
 const probe='chat-probe-'+freshId()+'.txt',token=freshId(),probePath=path.join(__dirname,'..','www',probe);
 fs.writeFileSync(probePath,token);try{equal(await (await fetch(new URL(probe,base))).text(),token,'Exact disposable HTTP source');}finally{fs.unlinkSync(probePath);}
 const browser=await chromium.launch({headless:true,executablePath:process.env.BROWSER_EXECUTABLE});
 try{
  const game=fixture('create').game_id,foreign=fixture('foreign').game_id;
  const ca=await browser.newContext({viewport:{width:1600,height:900}}),cb=await browser.newContext({viewport:{width:1600,height:900}});
  const a=await ca.newPage(),b=await cb.newPage(),errors=[];
  for(const page of [a,b]){
   page.on('pageerror',e=>errors.push(e.message));
   // Isolate the chat from ordinary game progress. Real chat HTTP and UI remain live.
   await page.route('**/*',route=>new URL(route.request().url()).searchParams.get('ajax')==='battle_sync'?route.abort():route.continue());
   await page.addInitScript(()=>{
    window.__chatSounds=0;let module;
    Object.defineProperty(window,'BerserkTurnTimer',{get:()=>module,set:value=>{
     const create=value.create;value.create=function(...args){const c=create(...args),notify=c.notify;c.notify=function(){window.__chatSounds++;return notify.apply(c,arguments);};return c;};module=value;
    }});
   });
  }
  await a.goto(base+'?first&game='+game);await b.goto(base+'?second&game='+game);
  await a.waitForFunction(()=>!document.querySelector('[data-chat-send]').disabled);await b.waitForFunction(()=>!document.querySelector('[data-chat-send]').disabled);
  const csrfA=await a.locator('[data-game-chat]').getAttribute('data-csrf'),csrfB=await b.locator('[data-game-chat]').getAttribute('data-csrf');
  const url=(role,action,id=game,query='')=>base+'?'+role+'&game='+id+'&ajax='+action+query;
  const request=async(ctx,role,action,form,id=game,query='')=>{
   const res=await ctx.request[form?'post':'get'](url(role,action,id,query),form?{form}:{});return {status:res.status(),data:await res.json()};
  };
  const send=(ctx,role,csrf,text,id=freshId())=>request(ctx,role,'chat_send',{csrf,message:text,request_id:id,sender_id:999,turn_number:999});
  const before=fixture('inspect',game),id=freshId();
  let r=await send(ca,'first',csrfA,'<b>Привет</b> 😀 <img src=x onerror=alert(1)>',id);
  equal(r.status,200,'Send own turn');equal(r.data.message.message,'Привет 😀','HTML stripped and emoji preserved');equal(r.data.message.sender_id,1,'Sender ignores forged field');equal(r.data.message.turn_number,7,'Turn ignores forged field');
  const sent=r.data.message.id;
  r=await send(ca,'first',csrfA,'<b>Привет</b> 😀 <img src=x onerror=alert(1)>',id);equal(r.data.message.id,sent,'Network replay returns same ID');
  r=await send(ca,'first',csrfA,'other',id);equal(r.status,400,'Request-ID payload conflict');
  r=await send(ca,'first',csrfA,'too fast');equal(r.status,429,'Server rate limit');
  r=await send(ca,'first',csrfA,'<img src=x>');equal(r.status,400,'Empty after strip rejected');
  r=await send(ca,'first',csrfA,'😀'.repeat(1001));equal(r.status,400,'Unicode length limit');
  r=await send(ca,'first','invalid','csrf');equal(r.status,403,'CSRF required');
  r=await request(ca,'first','chat_messages',null,foreign);equal(r.status,403,'Foreign participants cannot read');
  r=await request(ca,'first','chat_send',{csrf:csrfA,message:'bad',request_id:freshId()},foreign);equal(r.status,403,'Foreign participants cannot send');
  r=await request(ca,'first','chat_read',{csrf:csrfA,last_read_id:'99999999'});equal(r.status,400,'Read cursor belongs to game');
  r=await request(ca,'first','chat_messages',null,game,'&after_id[]=1');equal(r.status,400,'Malformed cursor rejected');
  await b.waitForFunction(()=>!!document.querySelector('[data-game-chat]').classList.contains('has-unread'));
  equal(await b.evaluate(()=>window.__chatSounds),1,'Closed opponent chat receives one sound');
  equal(await a.evaluate(()=>window.__chatSounds),0,'Own message has no sound');
  const iconStyle=await b.locator('[data-chat-indicator]').evaluate(el=>{const s=getComputedStyle(el);return {width:s.width,height:s.height,opacity:s.opacity,hidden:el.hidden};});
  equal(iconStyle.width,'16px','Unread image width');equal(iconStyle.height,'16px','Unread image height');equal(iconStyle.hidden,false,'Loaded indicator stays in layout');
  await b.waitForFunction(()=>getComputedStyle(document.querySelector('[data-chat-indicator]')).opacity==='1');
  equal(await a.locator('[data-chat-indicator]').evaluate(el=>getComputedStyle(el).opacity),'0','Own message leaves indicator transparent');
  await b.bringToFront();await b.locator('[data-chat-toggle]').click();
  await b.waitForFunction(()=>!document.querySelector('[data-game-chat]').classList.contains('has-unread'));
  await b.waitForFunction(()=>getComputedStyle(document.querySelector('[data-chat-indicator]')).opacity==='0');
  equal(await b.locator('.game-chat-message span').last().textContent(),'Привет 😀','Safe text rendered');
  equal(await b.locator('.game-chat-message img').count(),0,'No injected HTML');
  ok(fixture('inspect',game).reads.some(x=>x.player_id==='2' && x.last_read_id===sent),'Read state persisted');
  fixture('age',game);fixture('turn',game);
  r=await send(ca,'first',csrfA,'Ход соперника');equal(r.status,200,'Send during opponent turn');equal(r.data.message.turn_number,8,'Fresh server turn');
  await b.waitForFunction(()=>document.querySelectorAll('.game-chat-message').length===2);
  await b.waitForFunction(()=>!document.querySelector('[data-game-chat]').classList.contains('has-unread'));
  equal(await b.evaluate(()=>window.__chatSounds),2,'Open chat incoming notification');
  fixture('age',game);fixture('pending',game);const pendingBefore=fixture('inspect',game);
  r=await send(cb,'second',csrfB,'Во время pending 😀');equal(r.status,200,'Send with pending and expired anchor');
  await request(ca,'first','chat_messages');await request(ca,'first','chat_read',{csrf:csrfA,last_read_id:r.data.message.id});
  equal(fixture('inspect',game).state,pendingBefore.state,'Send/read/poll preserve complete game state including timer/pending');
  equal(fixture('inspect',game).version,pendingBefore.version,'No game version changes');
  await a.waitForFunction(()=>document.querySelectorAll('.game-chat-message').length===3);
  await a.reload();await a.waitForFunction(()=>document.querySelectorAll('.game-chat-message').length===3);
  equal(await a.evaluate(()=>window.__chatSounds),0,'Reload history does not replay sounds');
  await b.locator('[data-chat-input]').fill('Не отправлять');const count=fixture('inspect',game).messages.length;
  await b.locator('[data-chat-close]').click();ok(await b.locator('#game-chat-panel').isHidden(),'Cross closes panel');equal(fixture('inspect',game).messages.length,count,'Cross does not send');
  fixture('finish',game);fixture('age',game);
  r=await send(ca,'first',csrfA,'после боя');equal(r.status,403,'Completed game forbids send');
  r=await send(ca,'first',csrfA,'<b>Привет</b> 😀 <img src=x onerror=alert(1)>',id);equal(r.data.message.id,sent,'Committed retry still recovers after finish');
  await a.reload();await a.waitForFunction(()=>document.querySelectorAll('.game-chat-message').length===3);
  ok(await a.locator('[data-chat-send]').isDisabled(),'Finished screen history is read-only');equal(fixture('inspect',game).messages[0].turn_number,'7','Historical turn immutable');
  const historyGame=fixture('create').game_id;fixture('seed',historyGame);
  await a.goto(base+'?first&game='+historyGame);await a.waitForFunction(()=>document.querySelectorAll('.game-chat-message').length===50);
  equal(await a.evaluate(()=>window.__chatSounds),0,'Initial unread history is silent');
  ok(await a.locator('[data-game-chat]').evaluate(el=>el.classList.contains('has-unread')),'Unread survives new page');
  await a.reload();await a.waitForFunction(()=>document.querySelectorAll('.game-chat-message').length===50);
  ok(await a.locator('[data-game-chat]').evaluate(el=>el.classList.contains('has-unread')),'Unread survives refresh');
  await a.bringToFront();await a.locator('[data-chat-toggle]').click();await a.locator('[data-chat-older]').click();await a.waitForFunction(()=>document.querySelectorAll('.game-chat-message').length===100);
  await a.locator('[data-chat-older]').click();await a.waitForFunction(()=>document.querySelectorAll('.game-chat-message').length===121);
  equal(await a.locator('.game-chat-message span').first().textContent(),'История 0 😀','Old pages ordered ascending');
  equal(await a.locator('.game-chat-message span').last().textContent(),'История 120 😀','Latest message at bottom');ok(await a.locator('[data-chat-older]').isHidden(),'History exhausted');
  equal(await a.evaluate(()=>window.__chatSounds),0,'Older pages never sound');
  for(const width of [1600,800,375]){
   await a.setViewportSize({width,height:900});const box=await a.locator('#game-chat-panel').boundingBox();ok(box.x>=0 && box.x+box.width<=width,'Panel fits '+width);
   equal(box.width,Math.min(480,width-16),'Slightly larger responsive panel '+width);equal(box.y+box.height,892,'Panel sits near bottom '+width);equal(box.height,360,'Slightly taller panel '+width);
   const close=a.locator('[data-chat-close]'),closeBox=await close.boundingBox();
   ok(closeBox.x+closeBox.width>box.x+box.width-50 && closeBox.y<box.y+20,'Cross at upper right '+width);
   equal(await close.evaluate(el=>getComputedStyle(el).cursor),'pointer','Close pointer '+width);
   ok(await a.locator('[data-chat-cancel]').count()===0,'No cancel button '+width);
   equal(await a.locator('[data-chat-toggle]').getAttribute('title'),'Сообщения','Standard tooltip '+width);
   const iconBox=await a.locator('[data-chat-icon]').boundingBox(),pauseBox=await a.locator('[data-open-pause] img').boundingBox();equal(iconBox.width,pauseBox.width,'Same icon width as pause '+width);equal(iconBox.height,pauseBox.height,'Same icon height as pause '+width);
   ok(await a.locator('[data-chat-anchor]').evaluate(el=>el.previousElementSibling.hasAttribute('data-open-pause') && el.nextElementSibling.dataset.action==='battle-log'),'Chat between pause and log '+width);
   if(process.env.CHAT_SCREENSHOT_DIR) await a.screenshot({path:path.join(process.env.CHAT_SCREENSHOT_DIR,'chat-'+width+'.png')});
  }
  // Lost response after a real COMMIT: UI keeps text and reuses its request identity.
  const retryGame=fixture('create').game_id;
  await a.goto(base+'?first&game='+retryGame);await b.goto(base+'?second&game='+retryGame);
  await a.waitForFunction(()=>!document.querySelector('[data-chat-send]').disabled);await b.waitForFunction(()=>!document.querySelector('[data-chat-send]').disabled);
  await a.bringToFront();await a.locator('[data-chat-toggle]').click();await a.locator('[data-chat-input]').fill('Без дубля 😀');
  await a.route(u=>u.searchParams.get('ajax')==='chat_send',async route=>{await route.fetch();await route.abort('failed');},{times:1});
  await a.locator('[data-chat-send]').click();await a.waitForFunction(()=>document.querySelector('[data-chat-status]').textContent.includes('не подтверждена'));
  equal(fixture('inspect',retryGame).messages.length,1,'Lost response actually committed');equal(await a.locator('[data-chat-input]').inputValue(),'Без дубля 😀','Unconfirmed send retains draft');
  await a.reload();await a.waitForFunction(()=>!document.querySelector('[data-chat-send]').disabled);await a.locator('[data-chat-toggle]').click();
  equal(await a.locator('[data-chat-input]').inputValue(),'Без дубля 😀','Pending identity and text survive reload');
  await a.locator('[data-chat-send]').click();await a.waitForFunction(()=>document.querySelector('[data-chat-input]').value==='');
  equal(fixture('inspect',retryGame).messages.length,1,'Retry after reload never duplicates');
  await b.waitForFunction(()=>!!document.querySelector('[data-game-chat]').classList.contains('has-unread'));
  const soundsBefore=await b.evaluate(()=>window.__chatSounds);
  await cb.setOffline(true);fixture('age',retryGame);
  const newCsrf=await a.locator('[data-game-chat]').getAttribute('data-csrf');
  r=await request(ca,'first','chat_send',{csrf:newCsrf,message:'После переподключения',request_id:freshId()},retryGame);equal(r.status,200,'Other player sends while client offline');
  await cb.setOffline(false);await b.evaluate(()=>window.dispatchEvent(new Event('online')));
  await b.waitForFunction(()=>document.querySelectorAll('.game-chat-message').length===2);
  equal(await b.evaluate(()=>window.__chatSounds),soundsBefore+1,'Reconnect notifies only the new incoming message');
  await b.waitForResponse(res=>new URL(res.url()).searchParams.get('ajax')==='chat_messages');
  equal(await b.evaluate(()=>window.__chatSounds),soundsBefore+1,'Repeated poll does not replay sound');
  // Three real PHP processes overlap on InnoDB: two retries and the opponent.
  const concurrent=fixture('create').game_id,requestId=freshId();
  function worker(sender){return new Promise((resolve,reject)=>{
   const child=require('child_process').spawn(php,[path.join(__dirname,'test_game_chat_fixture.php'),'worker',String(concurrent),String(sender),'Параллельно 😀',requestId],{env:process.env});
   let out='',err='',ready=false;child.stdout.on('data',chunk=>{out+=chunk;if(!ready && out.includes('READY\n')){ready=true;child.stdin.end('go\n');}});child.stderr.on('data',x=>err+=x);
   child.on('error',reject);child.on('exit',code=>{if(code)reject(Error(err||out));else resolve(JSON.parse(out.trim().split('\n').pop()));});
  });}
  const concurrentBefore=fixture('inspect',concurrent),results=await Promise.all([worker(1),worker(1),worker(2)]);
  equal(results[0].id,results[1].id,'Concurrent retries share one message');ok(results[2].id!==results[0].id,'Opponent gets separate message');
  const concurrentAfter=fixture('inspect',concurrent);equal(concurrentAfter.messages.length,2,'No lost or duplicate concurrent messages');equal(concurrentAfter.state,concurrentBefore.state,'Concurrent sends preserve engine state');equal(concurrentAfter.version,concurrentBefore.version,'Concurrent sends preserve persistence version');
  const concurrentPage=await request(ca,'first','chat_messages',null,concurrent,'&after_id=0');equal(concurrentPage.data.messages.map(x=>x.id),concurrentAfter.messages.map(x=>x.id),'API order follows committed IDs');
  const normalGame=fixture('create').game_id,normalContext=await browser.newContext(),normal=await normalContext.newPage();
  normal.on('pageerror',e=>errors.push(e.message));
  const ordinarySync=normal.waitForResponse(res=>new URL(res.url()).searchParams.get('ajax')==='battle_sync' && res.status()===200);
  await normal.goto(base+'?first&game='+normalGame);await ordinarySync;
  await normal.waitForFunction(()=>!document.querySelector('[data-chat-send]').disabled);
  await normal.locator('[data-chat-toggle]').click();await normal.locator('[data-chat-input]').fill('С обычной синхронизацией');
  await normal.evaluate(()=>{window.__oldChatAnchor=document.querySelector('[data-chat-anchor]');});
  fixture('turn',normalGame);
  await normal.waitForFunction(()=>document.querySelector('[data-chat-anchor]')!==window.__oldChatAnchor && document.querySelector('[data-chat-anchor] [data-chat-toggle]'));
  equal(await normal.locator('[data-chat-toggle]').count(),1,'One live icon after server snapshot replacement');
  equal(await normal.locator('[data-chat-input]').inputValue(),'С обычной синхронизацией','Draft survives footer replacement');
  ok(await normal.locator('#game-chat-panel').isVisible(),'Open popup survives footer replacement');
  equal(await normal.locator('[data-chat-input]').getAttribute('placeholder'),'Сообщение','Input uses placeholder');
  equal(await normal.locator('[data-chat-send]').evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(68, 170, 68)','Send reuses existing green button');
  equal(await normal.locator('[data-chat-send]').evaluate(el=>{const s=getComputedStyle(el);return {width:s.width,height:s.height,radius:s.borderRadius,cursor:s.cursor};}),{width:'40px',height:'40px',radius:'50%',cursor:'pointer'},'Round green send control');
  equal(await normal.locator('[data-chat-send] svg').count(),1,'Send arrow icon');
  await normal.locator('[data-chat-send]').click();await normal.waitForFunction(()=>document.querySelector('[data-chat-input]').value==='');
  await normal.waitForFunction(()=>document.querySelectorAll('.game-chat-message').length===1);
  equal(fixture('inspect',normalGame).messages[0].message,'С обычной синхронизацией','Chat coexists with live ordinary battle polling');
  fixture('age',normalGame);
  const editor=normal.locator('[data-chat-input]'),countBefore=fixture('inspect',normalGame).messages.length;
  await editor.fill('abcdef');await editor.evaluate(el=>el.setSelectionRange(2,4));await editor.press('Control+Enter');
  equal(await editor.inputValue(),'ab\nef','Ctrl+Enter replaces selection with a newline');
  equal(fixture('inspect',normalGame).messages.length,countBefore,'Ctrl+Enter does not send');
  await editor.fill('Первая строка');await editor.press('Control+Enter');await editor.pressSequentially('Вторая строка');
  await editor.dispatchEvent('keydown',{key:'Enter',isComposing:true});
  equal(fixture('inspect',normalGame).messages.length,countBefore,'IME Enter does not send');
  await editor.press('Enter');await normal.waitForFunction(()=>document.querySelector('[data-chat-input]').value==='');
  equal(fixture('inspect',normalGame).messages.at(-1).message,'Первая строка\nВторая строка','Enter sends multiline message');
  await editor.fill('Черновик остаётся');await editor.dispatchEvent('keydown',{key:'Enter',repeat:true});
  equal(await editor.inputValue(),'Черновик остаётся','Held Enter does not send again');
  await normal.locator('[data-chat-close]').click();ok(await normal.locator('#game-chat-panel').isHidden(),'Header cross closes');
  equal(await normal.locator('[data-chat-toggle]').evaluate(el=>el===document.activeElement),true,'Closing restores toggle focus');
  await normal.locator('[data-chat-toggle]').click();equal(await editor.inputValue(),'Черновик остаётся','Cross preserves draft');
  await editor.fill('   ');await editor.press('Enter');equal(await normal.locator('[data-chat-status]').textContent(),'Введите сообщение.','Empty Enter validation');
  await normalContext.close();
  const readRows=fixture('inspect',concurrent).messages;
  r=await request(ca,'first','chat_read',{csrf:newCsrf,last_read_id:readRows[1].id},concurrent);equal(r.status,200,'Read newest message');
  await request(ca,'first','chat_read',{csrf:newCsrf,last_read_id:readRows[0].id},concurrent);
  equal(fixture('inspect',concurrent).reads[0].last_read_id,readRows[1].id,'Late older read request cannot move cursor backwards');
  equal(errors,[],'No client runtime errors');
  ok(before.version<fixture('inspect',game).version,'Fixture actually changed game separately');
  console.log('Game chat HTTP/two-session browser: '+checks+' checks passed');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
