const fs = require('fs');
const path = require('path');
const {execFileSync} = require('child_process');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const repo = path.resolve(__dirname, '..');
const html = execFileSync(process.env.PHP_BINARY || 'php', [path.join(repo,'tools/test_pregame_foundation.php'),'--fixture'], {encoding:'utf8'});
function check(ok, text) { if (!ok) throw Error(text); }
(async () => {
 const browser = await chromium.launch({headless:true, ...(process.env.BROWSER_EXECUTABLE ? {executablePath:process.env.BROWSER_EXECUTABLE} : {})});
 try {
  for (const width of [1600, 1280, 1024]) {
   const page = await browser.newPage({viewport:{width,height:900}});
   const errors=[]; page.on('pageerror',e=>errors.push(e.message));
   await page.route('https://pregame.test/**', route => {
    const url = new URL(route.request().url());
    if (url.pathname === '/') return route.fulfill({body:html, contentType:'text/html; charset=utf-8'});
    const file = path.join(repo, 'www', url.pathname);
    if (fs.existsSync(file)) return route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.js')?'text/javascript':file.endsWith('.css')?'text/css':file.endsWith('.webp')?'image/webp':'image/png'});
    return route.fulfill({status:404,body:''});
   });
   await page.goto('https://pregame.test/');
   await page.getByRole('button',{name:'Описание карты',exact:true}).click();
   await page.keyboard.press('Escape');
   const measure = () => page.evaluate(() => {
    const panel=document.querySelector('.pregame-info-panel'), content=document.querySelector('.pregame-panel-content');
    return {width:panel.getBoundingClientRect().width, header:document.querySelector('.pregame-panel-header').getBoundingClientRect().top,
     footer:document.querySelector('.pregame-panel-footer').getBoundingClientRect().top, max:content.scrollHeight-content.clientHeight,
     background:getComputedStyle(document.body).backgroundImage, body:document.documentElement.scrollHeight,
     viewport:innerHeight, main:document.querySelector('.pregame-main').scrollHeight-document.querySelector('.pregame-main').clientHeight};
   });
   const before=await measure();
   if (width===1600 && process.env.PREGAME_SCREENSHOT) await page.screenshot({path:process.env.PREGAME_SCREENSHOT});
   check(before.width===440, 'Panel width at '+width);
   check(before.max>0 && before.main>0, 'Both areas overflow independently');
   check(before.body<=before.viewport+1,'No vertical page growth');
   check(before.background.includes('berserk_background.webp'),'Background');
   await page.locator('.pregame-panel-content').evaluate(el=>el.scrollTop=500);
   const after=await measure();
   check(before.header===after.header && before.footer===after.footer,'Header/footer fixed');
   check(await page.locator('.pregame-panel-content').evaluate(el=>el.scrollTop)>0,'Panel scroll');
   check(await page.locator('.pregame-main').evaluate(el=>el.scrollTop)===0,'Main unaffected');
   await page.locator('.pregame-main').evaluate(el=>el.scrollTop=500);
   check(await page.locator('.pregame-panel-content').evaluate(el=>el.scrollTop)===500,'Main does not scroll panel');
   await page.locator('.pregame-panel-content').focus();
   check(await page.locator('.pregame-panel-content').evaluate(el=>el===document.activeElement),'Keyboard focus');
   await page.keyboard.press('Home');
   check(await page.locator('.toolbar').count()===0,'Service toolbar removed');
   check(errors.length===0,'No JS errors');
   await page.close();
  }
  const touch=await browser.newContext({viewport:{width:1280,height:900},hasTouch:true});
  const page=await touch.newPage();
  await page.setContent(html.replace(/<link[^>]+>/g,''));
  await page.addStyleTag({content:fs.readFileSync(path.join(repo,'www/assets/css/pregame.css'),'utf8')});
  await page.addScriptTag({content:fs.readFileSync(path.join(repo,'www/assets/js/pregame-ui.js'),'utf8')});
  await page.getByRole('button',{name:'Описание карты',exact:true}).tap();
  check(await page.locator('.pregame-stat-tooltip').isVisible(),'Touch description');
  await touch.close();
  console.log('PreGame browser tests passed: 1600/1280/1024, scroll, keyboard, touch.');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
