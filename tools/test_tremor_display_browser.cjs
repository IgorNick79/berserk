const fs=require('fs'),{execFileSync}=require('child_process');
const {chromium}=require('C:/Users/mi/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const fixture=JSON.parse(execFileSync('C:/OSPanel/modules/php/PHP_8.1/php.exe',['tools/test_tremor_display.php','--fixture'],{encoding:'utf8'}));
(async()=>{const browser=await chromium.launch({headless:true,executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});let n=0;
try{for(const width of [375,1600]){const page=await browser.newPage({viewport:{width,height:700}});
await page.route('**/tremor.svg',r=>r.fulfill({contentType:'image/svg+xml',body:fs.readFileSync('www/assets/images/battle/tremor.svg')}));
await page.setContent('<base href="http://fixture.test"><style>'+fs.readFileSync('www/assets/css/battle-mini-card.css','utf8')+'</style><div class="battle-page"><div class="cell-presentation" style="width:102px;height:100px"><a id="target" href="#chosen" style="position:absolute;inset:0">Card</a><div id="overlay"></div></div></div>');
for(const kind of ['empty','occupied','multi']){await page.locator('#overlay').evaluate((e,html)=>e.innerHTML=html,fixture[kind]);
const markers=page.locator('[data-tremor-id]');if(await markers.count()!==(kind==='multi'?3:1))throw Error('marker count');n++;
const hit=await markers.first().evaluate(e=>{const r=e.getBoundingClientRect();return {width:r.width,height:r.height,hit:document.elementFromPoint(r.x+r.width/2,r.y+r.height/2)?.id,color:getComputedStyle(e).borderColor}});
if(hit.width!==14||hit.height!==14||hit.hit!=='target')throw Error(JSON.stringify(hit));n++;
if(kind==='multi'){const color=await markers.last().evaluate(e=>getComputedStyle(e).borderColor);if(color===hit.color)throw Error('owner colors identical');n++;}}
await page.locator('#overlay').evaluate(e=>e.innerHTML='');if(await page.locator('[data-tremor-id]').count())throw Error('stale marker');n++;await page.close();}
console.log(`PASS ${n} browser checks; FAIL 0`);}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1)});
