const fs = require('fs');
const path = require('path');
const http = require('http');
const {execFileSync} = require('child_process');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '..');
const repo = process.env.BERSERK_RESULT_REPO || root;
const fixture = execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'test_battle_result_screen.php'), '--fixture'], {encoding:'utf8'});
const types = {'.css':'text/css', '.js':'text/javascript', '.svg':'image/svg+xml', '.png':'image/png', '.jpg':'image/jpeg', '.webp':'image/webp', '.mp4':'video/mp4'};
const output = path.join(root, 'tmp', 'battle-result-ui');
fs.mkdirSync(output, {recursive:true});
const assert = (ok, message) => { if (!ok) throw Error(message); };
(async () => {
    const server = http.createServer((req,res) => {
        const url = new URL(req.url, 'http://localhost');
        if (url.searchParams.has('ajax')) {res.setHeader('Content-Type','application/json');res.end(JSON.stringify({ok:true,latest_id:'0',last_read_id:'0',can_send:true,messages:[],has_more:false,unread:false}));return;}
        const pathname = url.pathname;
        if (pathname === '/battle-journal.php') {res.setHeader('Content-Type','application/json');res.end(JSON.stringify({entries:[],cursor:0}));return;}
        if (pathname === '/') {res.setHeader('Content-Type','text/html; charset=utf-8');res.end(fixture);return;}
        for (const dir of [root, repo]) {
            const file = path.join(dir, 'www', pathname);
            if (fs.existsSync(file) && fs.statSync(file).isFile()) {res.setHeader('Content-Type',types[path.extname(file)] || 'application/octet-stream');res.end(fs.readFileSync(file));return;}
        }
        res.statusCode=404;res.end();
    });
    await new Promise(r => server.listen(0, '127.0.0.1',r));
    const browser = await chromium.launch({headless:true, executablePath:process.env.BROWSER_EXECUTABLE});
    try {
        const page = await browser.newPage({reducedMotion:'reduce'});
        const errors=[];page.on('pageerror',e=>errors.push(e.message));
        await page.route('https://fonts.googleapis.com/**',route=>route.abort());
        for (const [width,height] of [[1600,900],[1024,768],[800,600],[375,812],[375,500]]) {
            await page.setViewportSize({width,height});
            await page.goto(`http://127.0.0.1:${server.address().port}/`);
            await page.locator('.result-video').waitFor();
            assert(await page.locator('.info-status').textContent()==='Результат боя','Heading');
            assert(await page.locator('.result-stats dd').first().textContent()==='12:45','Static duration');
            assert(await page.locator('.result-video').evaluate(v=>v.paused && !v.loop && v.muted),'Reduced motion poster and muted playback');
            assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth && document.documentElement.scrollHeight<=innerHeight),'No page overflow '+width+'x'+height);
            const footer=await page.locator('.info-panel-footer').boundingBox();
            assert(footer.x>=0 && footer.y>=0 && footer.x+footer.width<=width && footer.y+footer.height<=height+1,'Footer visible '+width+'x'+height);
            assert(await page.locator('[data-open-pause],.resign,.turn-timer').count()===0,'No obsolete controls');
            await page.locator('.game-menu summary').click();
            assert(await page.locator('.game-menu-panel').isVisible(),'Burger opens');
            await page.waitForFunction(()=>document.querySelector('.game-menu summary').getAttribute('aria-expanded')==='true' && getComputedStyle(document.querySelector('.battle-menu-icon i:nth-child(2)')).opacity==='0');
            assert(await page.locator('.game-menu').evaluate(m=>getComputedStyle(m.querySelector('.battle-menu-icon i:nth-child(2)')).opacity==='0'),'Burger animates to cross');
            for (const label of ['В лобби','Профиль','Выйти']) {
                const item=page.locator('.game-menu-panel [role="menuitem"]').filter({hasText:label});
                assert(await item.isVisible(),'Menu item visible: '+label);
                assert(await item.evaluate(e=>getComputedStyle(e).color===getComputedStyle(e.closest('.game-menu-panel')).color),'Readable menu item: '+label);
            }
            assert(await page.locator('.game-menu-panel').getByText('Отложить игру').count()===0,'No postpone after battle');
            await page.locator('.game-menu summary').click();
            assert(!(await page.locator('.game-menu-panel').isVisible()),'Burger closes');
            assert(await page.locator('[data-chat-anchor] [data-chat-toggle]').count()===1,'Chat mounted in footer');
            await page.locator('[data-chat-toggle]').click();
            assert(await page.locator('#game-chat-panel').isVisible(),'Chat opens');
            assert(await page.locator('.info-panel-content .battle-secondary-view #game-chat-panel').count()===1,'Chat docks in right column');
            assert(!(await page.locator('.result-summary').isVisible()),'Result replaced by chat');
            await page.locator('[data-action="battle-log"]').click();
            assert(await page.locator('.info-panel-content #battle-journal').isVisible(),'History docks in right column');
            assert(!(await page.locator('#game-chat-panel').isVisible()),'History replaces chat');
            await page.locator('[data-journal-close]').click();
            assert(await page.locator('.result-summary').isVisible(),'History close restores result');
            await page.locator('[data-chat-toggle]').click();
            await page.locator('[data-chat-close]').click();
            assert(await page.locator('.result-summary').isVisible(),'Chat close restores result');
            await page.keyboard.press('Tab');
            await page.locator('.result-share').focus();
            await page.waitForFunction(()=>getComputedStyle(document.querySelector('.result-share .battle-icon-tooltip')).visibility==='visible');
            assert(await page.locator('.result-share .battle-icon-tooltip').evaluate(e=>getComputedStyle(e).visibility==='visible'),'Share tooltip');
            const size=await page.locator('.result-video').evaluate(v=>v.getBoundingClientRect().width / v.parentElement.getBoundingClientRect().width);
            assert(size<0.95 && size>0.85,'Smaller video');
            await page.screenshot({path:path.join(output,`result-${width}x${height}.png`)});
        }
        assert(!errors.length,errors.join('\n'));
        await page.emulateMedia({reducedMotion:'no-preference'});
        await page.reload();
        await page.waitForFunction(()=>document.querySelector('video').currentTime>0);
        await page.locator('video').evaluate(v=>{v.currentTime=v.duration-0.15;});
        await page.waitForFunction(()=>{const v=document.querySelector('video');return v.paused && v.currentTime===0;});
        assert(await page.locator('video').getAttribute('poster')==='/assets/images/results/victory.jpg','Poster after video');
        console.log('PASS: desktop/mobile geometry, fixed footer, burger, reduced motion, video playback and final poster');
    } finally { await browser.close(); await new Promise(r=>server.close(r)); }
})().catch(e=>{console.error(e);process.exitCode=1;});
