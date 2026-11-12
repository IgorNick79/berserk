const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE ||
    'C:/Users/mi/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root,file),'utf8');
const fixtures = JSON.parse(execFileSync(process.env.PHP_BINARY || 'C:/OSPanel/modules/php/PHP_8.1/php.exe',
    [path.join(__dirname,'test_active_effects_panel.php'),'--fixtures'],{encoding:'utf8'}));
(async () => {
    const browser = await chromium.launch({headless:true,executablePath:process.env.BROWSER_EXECUTABLE ||
        'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
    const output = process.env.EFFECT_SCREENSHOTS || 'C:/Users/mi/Documents/Codex-active-effects-52';
    fs.mkdirSync(output,{recursive:true});
    try {
        for (const width of [1600,800]) {
            const page = await browser.newPage({viewport:{width,height:1000},hasTouch:width===800});
            const errors=[];
            page.on('pageerror',error=>errors.push(error.message));
            await page.route('https://effects.test/**',route=>{
                const file=path.join(root,'www'+new URL(route.request().url()).pathname);
                return fs.existsSync(file) && fs.statSync(file).isFile()
                    ? route.fulfill({path:file}) : route.fulfill({body:'',contentType:'text/html'});
            });
            await page.goto('https://effects.test/');
            const css = ['global','game-menu','battle-ui-skins','battle-mini-card','battle-coordinates']
                .map(name=>read('www/assets/css/'+name+'.css').replace(/^@import[^;]+;/gm,'')).join('');
            const html = read('templates/screen/battle.tpl').replace(/<script[\s\S]*?<\/script>|<link[^>]*>/g,'')
                .replace(/{{(\w+)}}/g,(_,key)=>fixtures.none[key]||'');
            await page.setContent('<meta charset="utf-8"><style>'+css+'</style><body class="battle-page">'+html);
            await page.addScriptTag({content:read('www/assets/js/panel-secondary-view.js')});
            await page.addScriptTag({content:read('www/assets/js/battle-ui.js')});
            let sync=read('www/assets/js/battle-sync.js');
            const last=sync.lastIndexOf('    requestSnapshot(false);');
            assert(last>=0);
            sync=sync.slice(0,last)+'    window.testEffectsSnapshot=data=>applySnapshot(data,uiRevision);'
                +sync.slice(last+'    requestSnapshot(false);'.length);
            sync=sync.replace('var syncUrl = script.dataset.syncUrl || "";','var syncUrl = "https://effects.test/";');
            await page.addScriptTag({content:sync});
            let version=1;
            async function show(name,count) {
                const fragments={};
                for (const key of ['field_html','fly_zones_html','panel_html','piles_html','pile_reveal_html','info_panel_html'])
                    fragments[key]=fixtures[name][key]||'';
                await page.evaluate(({fragments,version})=>{
                    if(!window.testEffectsSnapshot({sync_version:version,fragments,ui:{sel:0,mode:'strike'}}))
                        throw Error('Snapshot rejected');
                },{fragments,version:++version});
                assert.equal(await page.locator('.active-card-effect').count(),count);
                assert.equal(await page.locator('.active-card-effects ul,.active-card-effects li').count(),0);
                assert.equal(await page.locator('.active-card-effect').evaluateAll(es=>es.filter(e=>e.scrollWidth>e.clientWidth).length),0);
            }
            await show('one',1);
            assert((await page.locator('.active-card-effects').textContent()).includes('снимается после удара'));
            await page.locator('.info-panel').screenshot({path:path.join(output,width+'-one.png')});
            await show('many',3);
            assert((await page.locator('.active-card-effects').textContent()).includes('удар через ряд'));
            await page.locator('.info-panel').screenshot({path:path.join(output,width+'-many.png')});
            await page.locator('.panel-stat-info').click();
            assert((await page.locator('#battle-stat-tooltip').textContent()).includes('Описание через информационный значок'));
            await page.mouse.click(1,1);
            await show('changed',1);
            assert((await page.locator('.active-card-effects').textContent()).includes('на 3'));
            await show('other',0);
            await show('enemy',3);
            await show('hidden',0);
            await show('none',0);
            await page.locator('.info-panel').screenshot({path:path.join(output,width+'-none.png')});
            assert.deepEqual(errors,[]);
            console.log('PASS effects '+width+'px: snapshots, card switch, value changes, hidden enemy, info control, layout');
            await page.close();
        }
    } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
