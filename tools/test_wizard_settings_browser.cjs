const assert=require('assert/strict'),fs=require('fs'),path=require('path');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE);
(async()=>{const out=process.env.UNIFIED_MENU_EVIDENCE,base=process.env.UNIFIED_MENU_URL;
assert.equal(base,'http://127.0.0.1:33572');const browser=await chromium.launch({headless:true,executablePath:process.env.BROWSER_EXECUTABLE});const checks=[],errors=[];
const ok=(name,value)=>{assert(value,name);checks.push(name);};
try{const page=await browser.newPage({viewport:{width:1600,height:900}});page.on('pageerror',e=>errors.push(e.message));
await page.goto(base+'/auth.php');await page.locator('[name=login]').fill('Creator');await page.locator('[name=password]').fill('LobbyFixture123');await page.getByRole('button',{name:'Войти',exact:true}).click();await page.waitForURL('**/lobby.php');
for(const theme of ['grimoire','stone','parchment','leather']){
 await page.evaluate(async theme=>{const prefs=await(await fetch('ui-preferences.php')).json();const r=await fetch('panel-theme.php',{method:'POST',body:new URLSearchParams({theme,csrf:prefs.csrf})});if(!r.ok)throw Error('theme save');},theme);
 await page.goto(base+'/lobby.php?new=1');const form=page.locator('#create-wizard');
 for(const mode of ['draft','solomon','sealed','system','lucktest']){
  await form.locator('[data-step="0"]').click();await form.locator('[name=setup_mode][value='+mode+']').check();await form.locator('[data-step="2"]').click();const panel=form.locator('[data-mode='+mode+']');await panel.waitFor({state:'visible'});
  ok(theme+' '+mode+' no redundant subtitle',mode==='lucktest'||await panel.locator('.settings-mode-title').count()===0);
  const names=await panel.locator('.settings-section>h2').allTextContents();
  if(['draft','solomon','sealed'].includes(mode)){
   ok(theme+' '+mode+' booster order',mode==='sealed'?names.join('|')==='Бустеры':names[0]==='Основные настройки'&&names[1]==='Бустеры');
   const details=panel.locator('.wizard-booster-composition');ok(theme+' '+mode+' collapsed default',!await details.evaluate(e=>e.open));
   const count=await panel.locator('input[name="'+(mode==='sealed'?'sealed_boosters':'boosters')+'"]:checked').inputValue();
   await details.locator('summary').click();ok('composition visible '+theme+mode,await details.locator('[name=booster_common]').isVisible());
   const input=details.locator('[name=booster_rare_chance]');await input.evaluate(e=>{e.value='70';e.dispatchEvent(new Event('input',{bubbles:true}));});await details.locator('summary').click();await details.locator('summary').click();ok('collapse keeps value '+theme+mode,await input.inputValue()==='70');
   const values=await page.evaluate(mode=>[...new FormData(document.getElementById('wizard-fields-'+mode))],mode);
   ok('collapsed fields remain submitted '+theme+mode,values.filter(v=>v[0]==='booster_common').length===1&&values.some(v=>v[0]===(mode==='sealed'?'sealed_boosters':'boosters')&&v[1]===count));
   await details.locator('summary').click();
  }
  if(mode==='system'){
   ok('existing selector reused '+theme,await panel.locator('.deck-select-form [name=deck_id]').count()>0);
   await panel.locator('[name=deck_id][value="1"]').check();await panel.locator('[name=other_deck_id][value="1"]').check();
   await panel.locator('[name=deck_mode][value=random]').check();ok('random hides manual choices '+theme,!await panel.locator('.deck-select-section--host .deck-select-manual').isVisible());await panel.locator('[name=deck_mode][value=manual]').check();ok('manual choice retained '+theme,await panel.locator('[name=deck_id][value="1"]').isChecked());
  }
  if(mode!=='lucktest'){await page.screenshot({path:path.join(out,'step3-'+mode+'-'+theme+'.png')});}
 }
}
ok('no browser errors',errors.length===0);fs.writeFileSync(path.join(out,'settings-browser-checks.json'),JSON.stringify({checks,errors},null,2));
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1)});
