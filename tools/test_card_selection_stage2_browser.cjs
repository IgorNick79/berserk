const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE ||
    'C:/Users/mi/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');

const read = file => fs.readFileSync(path.join(__dirname, '..', file), 'utf8');
const fixture = JSON.parse(execFileSync(process.env.PHP_BINARY || 'C:/OSPanel/modules/php/PHP_8.1/php.exe',
    [path.join(__dirname, 'test_card_selection_stage1.php'), '--field'], {encoding: 'utf8'}));
const styles = ['global.css', 'battle-ui-skins.css', 'battle-mini-card.css'].map(file => read('www/assets/css/' + file)).join('\n');
assert.ok(!fixture.waiting.info_panel_html.includes('data-card-instance'));
const scripts = ['battle-drag', 'battle-sync', 'creature-choice', 'panel-secondary-view', 'battle-ui'];

(async () => {
    const browser = await chromium.launch({executablePath: process.env.BROWSER_EXECUTABLE ||
        'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true});
    try {
        for (const width of [1600, 800, 375]) {
            const page = await browser.newPage({viewport: {width, height: 900}, hasTouch: width < 1000});
            const errors = [], posts = [], inspections = [];
            let fragments = fixture.active, version = 1, navigations = 0;
            page.on('pageerror', error => errors.push(error.message));
            page.on('request', request => { if (request.isNavigationRequest()) navigations++; });
            await page.route('https://choice.test/**', async route => {
                const request = route.request();
                const url = new URL(request.url());
                if (request.isNavigationRequest()) return route.fulfill({contentType: 'text/html', body: ''});
                if (!url.searchParams.has('ajax')) return route.fulfill({body: ''});
                let commandId = null;
                if (request.method() === 'POST') {
                    const params = new URLSearchParams(request.postData());
                    posts.push(params);
                    commandId = params.get('command_id');
                    fragments = params.has('cancel') ? fixture.cancelled : fixture.next;
                    version++;
                } else if (Number(url.searchParams.get('inspect'))) {
                    inspections.push(url.searchParams.get('inspect'));
                }
                if (version === 3 && url.searchParams.get('sel') === '1') {
                    return route.fulfill({json: {type: 'snapshot', ok: true, sync_version: 1,
                        ui: {sel: 0, mode: 'strike'}, fragments: fixture.active}});
                }
                const inspecting = version === 1 && url.searchParams.get('inspect') === '2';
                return route.fulfill({json: {type: 'snapshot', ok: true, terminal: true,
                    command_id: commandId, command_status: 'committed', sync_version: version,
                    ui: {sel: 0, mode: 'strike', inspect: inspecting ? 2 : 0},
                    fragments: inspecting ? fixture.inspected : fragments}});
            });
            await page.goto('https://choice.test/?first&game=970');
            await page.setContent('<style>' + styles + '</style><body class="battle-page"><main data-battle-sync-root data-panel-theme="grimoire">' +
                Object.entries(fragments).filter(([key]) => key !== 'panel_html').map(([key, html]) =>
                    '<div data-battle-fragment="' + key + '">' + html + '</div>').join('') + '</main>' +
                scripts.map(name => '<script data-viewer-key="host" data-version="1" data-sync-url="/?first&amp;game=970&amp;ajax=battle_sync">' +
                    read('www/assets/js/' + name + '.js') + '</script>').join(''));
            const card = id => page.locator('[data-drag-card="' + id + '"]');
            const row = id => page.locator('[data-card-instance="' + id + '"]');
            const selected = () => page.locator('[data-card-selection-highlight]').evaluateAll(nodes => nodes.map(node => node.dataset.dragCard));
            const cdp = width < 1000 ? await page.context().newCDPSession(page) : null;
            async function touchCard(id, release = 'touchEnd') {
                await card(id).scrollIntoViewIfNeeded();
                const bounds = await card(id).boundingBox();
                await cdp.send('Input.dispatchTouchEvent', {type: 'touchStart', touchPoints: [
                    {x: bounds.x + bounds.width / 2, y: bounds.y + bounds.height / 2}
                ]});
                if (release === 'touchCancel') {
                    await cdp.send('Input.dispatchTouchEvent', {type: release, touchPoints: []});
                    await page.waitForTimeout(550);
                    return;
                }
                await page.waitForTimeout(550);
                await cdp.send('Input.dispatchTouchEvent', {type: release, touchPoints: []});
            }
            const selectCard = id => cdp ? touchCard(id) : card(id).dblclick();
            await row(2).click();
            assert.deepEqual(await selected(), ['2']);
            const openInfo = page.waitForResponse(response => new URL(response.url()).searchParams.get('inspect') === '2');
            await (cdp ? card(2).tap() : card(2).click());
            await openInfo;
            const close = page.locator('[data-panel-card-close]:visible').first();
            await close.waitFor();
            assert.deepEqual(await selected(), ['2']);
            await close.click();
            assert.equal(posts.length, 0);
            assert.equal(await row(2).isVisible(), true);
            if (cdp) {
                await touchCard(3, 'touchCancel');
                assert.deepEqual(await selected(), ['2']);
            }
            const inspectionsBeforeGesture = inspections.length;
            await selectCard(3);
            assert.equal(await row(3).locator('input').isChecked(), true);
            assert.deepEqual(await selected(), ['3']);
            assert.equal(await row(3).isHidden(), true);
            assert.equal(await page.locator('[data-card-choice-status]').isVisible(), true);
            assert.equal(posts.length, 0);
            await page.waitForTimeout(550);
            assert.equal(inspections.length, inspectionsBeforeGesture);
            assert.equal(await page.locator('[data-battle-drag-state]').getAttribute('data-drag-enabled'), '0');
            const sourceLink = page.locator('a.card-link:has([data-drag-card="1"])');
            const inspect = page.waitForRequest(request => new URL(request.url()).searchParams.get('inspect') === '1');
            await sourceLink.click();
            await inspect;
            await page.waitForFunction(() => document.querySelector('[data-card-instance="3"] input')?.checked);
            assert.deepEqual(await selected(), ['3']);
            await row(14).click();
            assert.deepEqual(await selected(), ['14']);
            assert.equal(await card(14).isHidden(), true);
            await page.locator('[data-fly-go="1"]').first().press('Enter');
            assert.equal(await card(14).isVisible(), true);
            await page.locator('[data-fly-go="0"]').first().press('Enter');
            await selectCard(11);
            assert.equal(await row(11).locator('input').isChecked(), true);
            assert.deepEqual(await selected(), ['11']);
            await selectCard(2);
            const sync = page.waitForResponse(response => response.request().method() === 'GET'
                && new URL(response.url()).searchParams.get('force_snapshot') === '1'
                && new URL(response.url()).searchParams.get('since_version') === '2');
            await Promise.all([page.waitForResponse(response => response.request().method() === 'POST'), page.locator('[data-card-selection] button[type=submit]').click()]);
            await sync;
            await page.waitForFunction(() => document.querySelector('[data-card-selection] input[name=stage]')?.value === '1' &&
                !document.querySelector('[data-card-selection-highlight]'));
            assert.equal(posts[0].get('target_id'), '2');
            assert.equal(await page.locator('[data-card-selection] input:checked').count(), 0);
            await Promise.all([page.waitForResponse(response => response.request().method() === 'POST'), page.locator('[data-card-selection] a.skip').click()]);
            await page.waitForFunction(() => !document.querySelector('[data-card-selection]'));
            assert.equal(posts[1].get('cancel'), '1');
            assert.equal(posts[1].has('target_id'), false);
            assert.deepEqual(await selected(), []);
            const normalCard = card(1);
            const normalSelection = page.waitForResponse(response => new URL(response.url()).searchParams.get('sel') === '1');
            await normalCard.click();
            await (await normalSelection).finished();
            assert.equal(await page.locator('[data-card-selection]').count(), 0);
            assert.deepEqual(await selected(), []);
            assert.equal(posts.length, 2);
            assert.equal(navigations, 1);
            assert.deepEqual(errors, []);
            console.log('PASS Stage 2.1 at ' + width + 'px');
            await page.close();
        }
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
