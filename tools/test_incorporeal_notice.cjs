const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const assert = require('node:assert/strict');
const {chromium} = require('C:/Users/mi/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const fixture = JSON.parse(execFileSync('C:/OSPanel_new/modules/PHP-8.3/php.exe', [path.join(__dirname, 'test_incorporeal.php'), '--fixture'], {encoding:'utf8'}));
(async () => {
 const browser = await chromium.launch({executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless:true});
 try {
  const page = await browser.newPage();
  const errors=[];page.on('pageerror', e=>errors.push(e.message));
  await page.route('https://incorporeal.test/**', r=>r.fulfill({contentType:'text/html', body:'<html></html>'}));
  await page.goto('https://incorporeal.test/');
  await page.setContent('<div data-battle-sync-root>'+fixture.notice+'</div>');
  await page.addScriptTag({content:fs.readFileSync(path.join(__dirname,'../www/assets/js/battle-notices.js'),'utf8')});
  const notice = page.locator('[data-battle-notice^="incorporeal:"]');
  assert(await notice.isVisible());
  assert((await notice.innerText()).includes('ghost изгнан'));
  await notice.locator('[data-dismiss-battle-notice]').click();
  assert(await notice.isHidden());
  await page.evaluate(html=>{document.querySelector('[data-battle-sync-root]').innerHTML=html;document.dispatchEvent(new Event('berserk:battle-updated'));},fixture.notice);
  assert(await notice.isHidden(), 'AJAX refresh preserves dismissal');
  await page.evaluate(()=>{const item=document.querySelector('[data-battle-notice-id]');item.dataset.battleNoticeId='2';document.dispatchEvent(new Event('berserk:battle-updated'));});
  assert(await notice.isVisible(), 'next exile remains visible');
  assert.deepEqual(errors,[]);
  console.log('PASS headless Edge: actual exile notice, dismiss, AJAX refresh, next notice, no JS errors');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
