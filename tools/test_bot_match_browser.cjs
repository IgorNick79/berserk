const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE);

(async () => {
    const origin = process.env.INTEGRATION_URL;
    assert.equal(origin, 'http://127.0.0.1:33572');
    const browser = await chromium.launch({headless: true, executablePath: process.env.BROWSER_EXECUTABLE});
    const evidence = process.env.INTEGRATION_EVIDENCE;
    try {
        const page = await browser.newPage({viewport: {width: 1440, height: 900}});
        await page.goto(origin + '/auth.php');
        await page.locator('[name=login]').fill('Creator');
        await page.locator('[name=password]').fill('LobbyFixture123');
        await page.getByRole('button', {name:'Войти', exact:true}).click();
        await page.waitForURL('**/lobby.php');
        await page.getByRole('button', {name: /Играть с ботом/}).click();
        await page.waitForURL('**/index.php?game=*');
        await page.locator('[data-battle-sync-root]').waitFor();
        assert.match(await page.locator('#bot-status').innerText(), /Бот/);
        const game = new URL(page.url()).searchParams.get('game');
        await page.evaluate(() => { window.botTestMarker = true; });
        let acted = false, answered = false, ack = false, mandatoryCommand = null;
        const sync = [];
        let dropped = false;
        await page.route('**/*ajax=battle_sync*', async route => {
            if (!dropped) { dropped = true; await route.abort(); }
            else await route.continue();
        });
        let humanVersion = null;
        let lostCommit = null, recoveryObserved = false;
        await page.route('**/*ajax=battle_command*', async route => {
            const body = new URLSearchParams(route.request().postData() || '');
            if (route.request().method() !== 'POST' || body.get('cmd') !== 'end_turn' || lostCommit) {
                await route.continue();
                return;
            }
            const response = await route.fetch();
            lostCommit = await response.json();
            assert.equal(response.status(), 200);
            assert.equal(lostCommit.command_status, 'committed');
            await route.abort();
        });
        page.on('response', async r => {
            if (r.url().includes('ajax=battle_command_status')) {
                try { recoveryObserved ||= (await r.json()).command_status === 'committed'; } catch {}
                return;
            }
            if (!r.url().includes('ajax=battle_sync')) return;
            try { sync.push(await r.json()); } catch {}
        });
        for (let i = 0; i < 25 && !ack; i++) {
            const command = page.locator('a[href*="cmd=confirm_strike"], a[href*="cmd=choose_defender"], a[href*="cmd=choose_strike_mode"], a[href*="cmd=end_turn"]').filter({visible:true}).first();
            await command.waitFor({timeout: 12000});
            const href = await command.getAttribute('href');
            if (href.includes('cmd=end_turn') && !lostCommit) {
                await command.click();
                for (let tries=0; tries<100 && !recoveryObserved; tries++) await page.waitForTimeout(100);
                assert(recoveryObserved && lostCommit);
                await page.locator('[data-battle-message]').waitFor({state:'hidden', timeout:15000});
                humanVersion = lostCommit.after_version;
                acted = true;
            } else {
                const response = page.waitForResponse(r => r.url().includes('ajax=battle_command') && r.request().method()==='POST');
                await command.click();
                assert.equal((await response).status(), 200);
                if (!href.includes('cmd=end_turn') && acted) {
                    ack = true;
                    mandatoryCommand = new URL(href, page.url()).searchParams.get('cmd');
                }
            }
            await page.waitForTimeout(700);
            answered = answered || sync.some(x => x.type === 'snapshot' && x.sync_version > humanVersion && x.fragments?.field_html);
        }
        assert(acted && answered && ack && dropped);
        assert.equal(await page.evaluate(() => window.botTestMarker), true);
        await page.screenshot({path: path.join(evidence, 'bot-battle.png')});
        await page.reload();
        assert.equal(new URL(page.url()).searchParams.get('game'), game);
        await page.locator('[data-battle-sync-root]').waitFor();
        await page.evaluate(() => { window.botTestMarker = true; });
        page.once('dialog', dialog => dialog.accept());
        await page.getByRole('link', {name: 'Сдаться'}).click();
        await page.locator('[data-result="defeat"]').waitFor();
        assert.equal(await page.evaluate(() => window.botTestMarker), true);
        assert.equal(new URL(page.url()).searchParams.get('game'), game);
        await page.screenshot({path: path.join(evidence, 'bot-result.png')});
        fs.writeFileSync(path.join(evidence, 'bot-browser.json'), JSON.stringify({game:Number(game), acted, answered, ack, mandatoryCommand, end:'resign', reloadPreservedId:true, resultWithoutReload:true, droppedPollRecovered:true, lostCommittedCommandRecovered:true}));
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
