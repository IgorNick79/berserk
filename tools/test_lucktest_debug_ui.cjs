const fs=require('fs'),path=require('path'),assert=require('assert'),{execFileSync}=require('child_process');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'C:/Users/mi/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const repo=path.resolve(__dirname,'..'),read=f=>fs.readFileSync(path.join(repo,f),'utf8');
if(fs.existsSync(path.join(repo,'.git')))throw Error('Disposable copy required: fixture config is test-only');
const html=execFileSync(process.env.PHP_BINARY||'C:/OSPanel/modules/php/PHP_8.1/php.exe',[path.join(repo,'tools/test_lucktest_url_roll.php'),'--fixture'],{encoding:'utf8'});
(async()=>{const browser=await chromium.launch({headless:true,executablePath:process.env.BROWSER_EXECUTABLE||'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});try{
const context=await browser.newContext({permissions:['clipboard-read','clipboard-write']}),page=await context.newPage();let submits=0;
await page.route('https://luckui.test/**',r=>r.fulfill({contentType:'text/html',body:'<html></html>'}));await page.goto('https://luckui.test/');
await page.setContent('<html><head><style>'+read('www/assets/css/global.css')+read('www/assets/css/pregame.css')+'</style></head><body style="margin:0;padding:20px;box-sizing:border-box"><main class="screen-settings" style="max-width:780px;margin:auto">'+html+'</main></body></html>');
await page.addScriptTag({content:read('www/assets/js/lucktest-debug.js')});
await page.exposeFunction('testSubmit',()=>submits++);await page.evaluate(()=>document.querySelector('form').addEventListener('submit',e=>{e.preventDefault();window.testSubmit();}));
assert.strictEqual(await page.locator('input[type=checkbox][name=lucktest_url_roll]').isChecked(),false);assert.strictEqual(await page.locator('input[name=debug_roll]').count(),0);
await page.locator('input[type=checkbox][name=lucktest_url_roll]').check();assert(await page.locator('input[type=checkbox][name=lucktest_url_roll]').isChecked());
await page.locator('.debug-json-help summary').click();await page.locator('[data-debug-copy]').click();await page.waitForFunction(()=>document.querySelector('.debug-copy-status').textContent==='Скопировано');
const copied=(await page.evaluate(()=>navigator.clipboard.readText())).replace(/\r\n/g,'\n');assert.strictEqual(JSON.parse(copied).cards.length,2);assert.strictEqual(await page.locator('[name=lucktest_scenario]').inputValue(),'');assert.strictEqual(submits,0);
await page.locator('[name=lucktest_scenario]').fill(copied);await page.locator('input[type=checkbox][name=lucktest_quick_game]').uncheck();assert(await page.locator('[data-lucktest-scenario]').isHidden());assert(await page.locator('[name=lucktest_scenario]').isDisabled());
await page.locator('input[type=checkbox][name=lucktest_quick_game]').check();assert.strictEqual(await page.locator('[name=lucktest_scenario]').inputValue(),copied);
const out=path.join(repo,'tmp/lucktest-debug-ui');fs.mkdirSync(out,{recursive:true});
for(const width of [1100,375]){await page.setViewportSize({width,height:1000});await page.screenshot({path:path.join(out,'debug-'+width+'.png'),fullPage:true});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'No horizontal overflow');}
console.log('PASS: real Settings fieldset, default-off checkbox, whole JSON clipboard copy, no accidental submit, draft retained on quick toggle, desktop/mobile fit.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1)});
