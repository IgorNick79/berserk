const fs=require('fs'),path=require('path'),{execFileSync}=require('child_process');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'C:/Users/mi/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const root=path.resolve(__dirname,'..'),read=p=>fs.readFileSync(path.join(root,p),'utf8');
const out=process.env.CREATURE_CHOICE_ARTIFACTS||path.resolve(root,'../king-priest-ui-validation');fs.mkdirSync(out,{recursive:true});
const check=(v,m)=>{if(!v)throw Error(m)};
function fixture(stage){const file=stage==='heal'?'test_druid_tovi_target_ui.php':['memory_notice','area_impact'].includes(stage)?'test_king_priest_main_merge.php':'test_creature_choice_followup.php';return JSON.parse(execFileSync(process.env.PHP_BINARY||'C:/OSPanel/modules/php/PHP_8.1/php.exe',[root+'/tools/'+file,'--fixture',stage],{encoding:'utf8'}));}
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.BROWSER_EXECUTABLE||'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless:true});
 try{for(const width of (process.env.CHOICE_WIDTH?[Number(process.env.CHOICE_WIDTH)]:[1600,800,375])){
  const page=await browser.newPage({viewport:{width,height:900}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.route('https://choice.test/**',r=>{const u=new URL(r.request().url()),f=path.join(root,'www',u.pathname);return r.fulfill({body:fs.existsSync(f)&&fs.statSync(f).isFile()?fs.readFileSync(f):'',contentType:u.pathname.endsWith('.svg')?'image/svg+xml':u.pathname.endsWith('.png')?'image/png':u.pathname.endsWith('.css')?'text/css':'text/html'});});
  await page.goto('https://choice.test/');
  async function show(stage){
   const d=fixture(stage),html=read('templates/screen/battle.tpl').replace(/<script[\s\S]*?<\/script>/g,'').replace(/<link[^>]*>/g,'').replace(/{{(\w+)}}/g,(_,k)=>d[k]||'');
   await page.setContent('<style>'+['global.css','battle-ui-skins.css','battle-mini-card.css','battle-mini-nameplate.css','battle-coordinates.css'].map(n=>read('www/assets/css/'+n)).join('\n')+'</style>'+html);
   await page.evaluate(()=>{document.body.className='battle-page';});
   await page.addScriptTag({content:read('www/assets/js/creature-choice.js')});
   await page.addScriptTag({content:read('www/assets/js/cell-marker-picker.js')});
   await page.waitForTimeout(70);
  }
  await show('action');
  const form=page.locator('form').filter({has:page.locator('[data-creature-sides]')});
  check(await form.count()===1,'one shared creature form');
  check(await form.locator('[data-creature-owner="enemy"]').first().isVisible(),'enemy list initially visible');
  check(await form.locator('[data-creature-owner="own"]').first().isHidden(),'own list initially hidden');
  check(!(await form.locator('[data-creature-owner]').allTextContents()).some(t=>t.includes('HP')),'no HP in creature rows');
  await form.locator('[data-creature-side="own"]').click();
  check(await form.locator('[data-creature-owner="own"]').first().isVisible(),'JS switches to own without navigation');
  check(await form.locator('[data-creature-owner="enemy"] input:checked').count()===0,'hidden enemy cannot submit');
  const boxes=await form.locator('[data-creature-owner="own"]').first().evaluate(row=>{const n=row.querySelector('.creature-choice-name').getBoundingClientRect(),c=row.querySelector('.coords').getBoundingClientRect();return {name:n.x,coords:c.x,right:c.right,rowRight:row.getBoundingClientRect().right};});
  await page.screenshot({path:path.join(out,`creatures-${width}.png`)});
  check(boxes.coords>boxes.name && boxes.right<=boxes.rowRight+1,'coordinates fit at right: '+JSON.stringify(boxes));
  // The same fragment replacement used by polling keeps the chosen side.
  const savedHtml=await form.evaluate(f=>f.outerHTML);await form.evaluate((f,h)=>f.outerHTML=h,savedHtml);await page.waitForTimeout(70);
  check(await page.locator('[data-creature-side="own"]').getAttribute('aria-pressed')==='true','polling retains side');
  await page.locator('[data-creature-side="enemy"]').click();
  await page.locator('[data-creature-owner="enemy"] input[value="4"]').check();
  await page.locator('form').filter({has:page.locator('[data-creature-sides]')}).evaluate(f=>f.outerHTML=f.outerHTML);
  await page.waitForTimeout(70);
  check(await page.locator('input[name="target_id"][value="4"]').isChecked(),'polling retains selected creature');
  await show('door');
  check(await page.locator('[data-cell-marker-compact]').isVisible(),'door uses enhanced Krondak cell picker');
  check(await page.locator('[data-cell-marker-fallback]:visible').count()===0,'long fallback cell list hidden with JS');
  await page.locator('[data-picker-side="enemy"]').click();await page.locator('[data-picker-row="3"]').click();
  check((await page.locator('[data-picker-group="enemy:3"] [data-picker-column]').first().getAttribute('href')).includes('cmd=choose_teleport_cell&cell=6_5'),'picker retains original system cell command');
  await page.screenshot({path:path.join(out,`door-${width}.png`)});
  for(const stage of ['instant','stack']){
   await show(stage);
   const b=await page.locator('.info-panel-instants').boundingBox(),f=await page.locator('.info-panel-content').boundingBox();
   check(b&&f&&b.y>=f.y && b.y+b.height<=f.y+f.height+1,'instant panel fits within shared scrollable content');
   check(await page.locator('.info-panel-instants').evaluate(e=>getComputedStyle(e).borderTopWidth)==='0px','no detached footer separator');
  }
  await show('impact');check(await page.locator('.info-panel .dice-value').count()===0,'impact has no dice');
  await show('death');check(await page.locator('input[type="radio"][name="target_id"]').count()===3,'King of Dead uses radio choice');
  await show('vampire');
  const colors=await page.locator('.vampire').evaluate(e=>({bg:getComputedStyle(e).backgroundColor,fg:getComputedStyle(e).color}));
  check(colors.bg==='rgb(58, 48, 40)'&&colors.fg==='rgb(244, 238, 228)','vampirism uses readable theme colors');
  await page.screenshot({path:path.join(out,`vampire-${width}.png`)});
  await show('memory_notice');
  check(await page.locator('.memory-loss-notice').isVisible(),'memory alert visible');
  const notice=await page.locator('.info-panel').innerText();
  check(notice.includes('Гидра')&&notice.includes('уникальные особенности отключены'),'actual memory impact names target and effect');
  check(await page.locator('.info-panel .dice-block,.info-panel .impact-targets').count()===0,'no dice or fake damage for memory');
  check(await page.locator('.info-panel a[href*="cmd=confirm_strike"]').innerText()==='Продолжить','memory alert keeps normal Continue ACK');
  await page.screenshot({path:path.join(out,`memory-notice-${width}.png`)});
  await show('area_impact');
  check(((await page.locator('.strike-box').innerText()).match(/Болотник/g)||[]).length===1,'area impact names source once');
  check(await page.locator('.impact-targets').evaluate(e=>getComputedStyle(e).borderTopWidth)==='1px','area target list has a divider');
  check(await page.locator('.impact-targets li').count()===2,'actual area victims remain separate rows');
  await page.screenshot({path:path.join(out,`area-impact-${width}.png`)});
  await show('heal');
  check(await page.locator('.creature-choice-health:visible').count()>0,'Druid healing shows current and maximum HP');
  check(await page.locator('.creature-choice-health').first().innerText()==='❤ 8/10 HP','Druid health values are actual');
  check(await page.locator('.info-panel .choice-radio').evaluateAll(rows=>rows.every(row=>row.scrollWidth<=row.clientWidth+1)),'Healing rows fit viewport');
  await page.screenshot({path:path.join(out,`healing-${width}.png`)});
  check(!errors.length,errors.join('\n'));console.log(`PASS ${width}px: side toggle/polling, compact rows, door grid, instant footer, no dice, death radios, vampire contrast`);
  await page.close();
 }}finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
