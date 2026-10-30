const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fs=require('fs'),path=require('path'),assert=require('assert');
(async()=>{const base=process.cwd(),out=path.join(base,'docs/evidence/lobby-sticky-review');fs.mkdirSync(out,{recursive:true});
let php=fs.readFileSync('www/lobby.php','utf8');let html=php.slice(php.indexOf('<!doctype html>'));html=html.replace(/<\?php[\s\S]*?\?>/g,'').replace(/<\?=[\s\S]*?\?>/g,'');
// Explicit visual fixture: production PHP/auth/DB are not executed by this harness.
html=html.slice(0,html.indexOf('<details'))+`<details class="lobby-menu"><summary aria-label="Меню">☰</summary><nav aria-label="Меню игры"><div class="lobby-menu-settings"><span class="menu-caption">МЕНЮ ИГРЫ</span>${['Звук','Коврик','Тема InfoPanel','Debug'].map(t=>`<button disabled>${t} <small>В партии</small></button>`).join('')}</div><div class="lobby-account"><strong>♙ Тестовый игрок</strong><a href="auth.php?page=account">Мой профиль</a><form><button>Выйти из аккаунта</button></form></div></nav></details><main id="lobby" class="screen lobby" data-authenticated="1"><p id="notice"></p><section id="content"></section></main></body></html>`;
const rooms=Array.from({length:24},(_,i)=>({id:240-i,version:1,kind:i%2?'Solomon Draft':'LuckTest',created_at:'2026-10-30T09:00:00Z',creator:'Тестовый игрок',second:i%2?'Соперник':null,status:i%2?'Игра идёт':'Открытая',state:i%2?'started':'open',is_mine:i%3===0,can_open:i%3===0||i%2===0}));
const browser=await chromium.launch({channel:'msedge',headless:true});const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));await page.route('http://lobby.test/**',async route=>{const u=new URL(route.request().url());if(u.pathname==='/lobby.php'){if(u.searchParams.has('api'))return route.fulfill({json:{rooms}});return route.fulfill({contentType:'text/html',body:html.replace('<main id="lobby"',`<main data-state='${JSON.stringify({rooms})}' id="lobby"`)});}const file=path.join(base,'www',u.pathname);if(fs.existsSync(file))return route.fulfill({path:file});return route.fulfill({status:404,body:''});});
await page.setViewportSize({width:1600,height:900});await page.goto('http://lobby.test/lobby.php');await page.locator('.room-card').first().waitFor();await page.screenshot({path:path.join(out,'lobby.png')});await page.locator('.room-card summary').first().click();assert(await page.locator('.room-card').first().getAttribute('open')!==null);await page.screenshot({path:path.join(out,'expanded.png')});await page.locator('.lobby-menu summary').click();await page.screenshot({path:path.join(out,'menu.png')});await page.keyboard.press('Escape');assert(!await page.locator('.lobby-menu').evaluate(e=>e.open));
await page.getByRole('button',{name:'История',exact:true}).click();assert(await page.getByText('История партий пока недоступна').isVisible());await page.getByRole('button',{name:'Мои',exact:true}).click();assert.equal(await page.locator('.room-card').count(),8);await page.getByRole('button',{name:'Открытые',exact:true}).click();assert.equal(await page.locator('.room-card').count(),12);await page.getByRole('button',{name:'Все',exact:true}).click();
for(const [width,height] of [[1600,900],[1366,768],[390,844],[360,800]]){
 await page.setViewportSize({width,height});
 const content=page.locator('#content'),sticky=page.locator('.lobby-sticky');await content.evaluate(e=>e.scrollTop=0);
 assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
 assert.equal(await sticky.evaluate(e=>getComputedStyle(e).position),'sticky');
 const before=await sticky.boundingBox();await content.evaluate(e=>e.scrollTop=420);const after=await sticky.boundingBox();assert(Math.abs(before.y-after.y)<1);
 assert(await content.evaluate(e=>e.scrollTop>0));
 assert(await page.evaluate(()=>{const s=document.querySelector('.lobby-sticky').getBoundingClientRect();return document.elementFromPoint(s.x+10,s.y+10).closest('.lobby-sticky')!==null;}));
 await page.locator('.room-card').last().evaluate(e=>e.open=false);await content.evaluate(e=>e.scrollTop=e.scrollHeight);await page.locator('.room-card summary').last().click();assert(await page.locator('.room-card').last().evaluate(e=>e.open));assert.equal(page.url(),'http://lobby.test/lobby.php');
 const burger=await page.locator('.lobby-menu summary').boundingBox(),panel=await page.locator('#lobby').boundingBox();assert(burger.x+burger.width<=panel.x||burger.y+burger.height<=panel.y);
 await page.screenshot({path:path.join(out,`lobby-${width}.png`)});
 await page.locator('.lobby-menu summary').click();const nav=await page.locator('.lobby-menu nav').boundingBox();assert(nav.x>=0&&nav.y>=0&&nav.x+nav.width<=width&&nav.y+nav.height<=height);
 assert(await page.evaluate(()=>{const r=document.querySelector('.lobby-menu nav').getBoundingClientRect();return !!document.elementFromPoint(r.x+20,r.y+20).closest('.lobby-menu');}));
 await page.screenshot({path:path.join(out,`menu-${width}.png`)});await page.keyboard.press('Escape');assert(!await page.locator('.lobby-menu').evaluate(e=>e.open));
}
assert.deepEqual(errors,[]);fs.writeFileSync(path.join(out,'checks.json'),JSON.stringify({fixture:true,checks:['expansion without navigation','menu Escape','mine/open/history tabs','1600/1366/390/360 widths','sticky position stable after actual scroll','opaque sticky hit test','first and last expansion without navigation','menu viewport bounds and stacking','burger outside panel','no JavaScript errors'],errors},null,2));await browser.close();console.log('Fixture browser checks passed');})();


