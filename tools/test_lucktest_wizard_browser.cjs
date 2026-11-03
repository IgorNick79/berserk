const assert=require('assert/strict'),fs=require('fs'),path=require('path');const {chromium}=require(process.env.PLAYWRIGHT_MODULE);
(async()=>{const browser=await chromium.launch({headless:true,executablePath:process.env.BROWSER_EXECUTABLE});const checks=[];const ok=(n,v)=>{assert(v,n);checks.push(n)};
try{const page=await browser.newPage({viewport:{width:1600,height:900}}),errors=[];page.on('pageerror',e=>errors.push(e.message));const base=process.env.UNIFIED_MENU_URL,out=process.env.UNIFIED_MENU_EVIDENCE;
await page.goto(base+'/auth.php');await page.locator('[name=login]').fill('Creator');await page.locator('[name=password]').fill('LobbyFixture123');await page.getByRole('button',{name:'Войти',exact:true}).click();await page.waitForURL('**/lobby.php');
for(const theme of ['grimoire','stone','parchment','leather']){
await page.evaluate(async theme=>{const p=await(await fetch('ui-preferences.php')).json();await fetch('panel-theme.php',{method:'POST',body:new URLSearchParams({theme,csrf:p.csrf})})},theme);
await page.goto(base+'/lobby.php?new=1');await page.locator('[name=setup_mode][value=lucktest]').check();await page.locator('[data-step="2"]').click();const panel=page.locator('[data-mode=lucktest]');
ok('battle default '+theme,await panel.locator('[name=lucktest_start_stage][value=battle]').isChecked());ok('two flags default '+theme,await panel.locator('[name=lucktest_balanced][type=checkbox]').isChecked()&&await panel.locator('[name=lucktest_balance_metals][type=checkbox]').isChecked());
await page.screenshot({path:path.join(out,'lucktest-'+theme+'-closed.png')});
for(const help of await panel.locator('.wizard-setting-help summary').all())await help.click();ok('help leaves flags unchanged '+theme,await panel.locator('[name=lucktest_balanced][type=checkbox]').isChecked()&&await panel.locator('[name=lucktest_balance_metals][type=checkbox]').isChecked());await page.screenshot({path:path.join(out,'lucktest-'+theme+'-help.png')});
ok('debug uses section '+theme,await panel.locator('section.wizard-debug').count()===1);await panel.locator('.wizard-debug').scrollIntoViewIfNeeded();await page.screenshot({path:path.join(out,'lucktest-'+theme+'-debug.png')});
for(const start of ['turn','place','battle']){await panel.locator('[name=lucktest_start_stage][value='+start+']').check();ok('debug scenario stage '+start+theme,await panel.locator('[data-lucktest-scenario]').isVisible()===(start==='battle'));}
}
ok('no JS errors',errors.length===0);fs.writeFileSync(path.join(process.env.UNIFIED_MENU_EVIDENCE,'lucktest-browser-checks.json'),JSON.stringify({checks,errors},null,2));}finally{await browser.close()}})().catch(e=>{console.error(e);process.exit(1)});
