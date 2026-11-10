const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE ||
    'C:/Users/mi/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');

const read = file => fs.readFileSync(path.join(__dirname, '..', file), 'utf8');
const fixtures = JSON.parse(execFileSync(process.env.PHP_BINARY || 'C:/OSPanel/modules/php/PHP_8.1/php.exe',
    [path.join(__dirname, 'test_card_selection_stage3.php')], {encoding: 'utf8'}));
const styles = ['global.css', 'battle-ui-skins.css', 'battle-mini-card.css'].map(file => read('www/assets/css/' + file)).join('\n');
const scripts = ['battle-drag', 'battle-sync', 'creature-choice', 'panel-secondary-view', 'battle-ui'];

(async () => {
    const browser = await chromium.launch({executablePath: process.env.BROWSER_EXECUTABLE ||
        'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true});
    try {
        for (const width of [1600, 800, 375]) {
            const page = await browser.newPage({viewport: {width, height: 900}, hasTouch: width < 1000});
            const errors = [], posts = [];
            let snapshot, version, navigations = 0;
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
                    snapshot = params.get('cmd') === 'grezy_pick' && params.has('enemy_id') ? fixtures.grezyAlly : fixtures.cancelled;
                    version++;
                }
                if (snapshot === fixtures.intrigue && url.searchParams.get('in_side') === 'all') snapshot = fixtures.intrigueAll;
                if (request.method() === 'GET' && !url.searchParams.has('force_snapshot')
                    && Number(url.searchParams.get('since_version')) === version) {
                    return route.fulfill({json: {type: 'no_change', ok: true, sync_version: version}});
                }
                return route.fulfill({json: {type: 'snapshot', ok: true, terminal: true,
                    command_id: commandId, command_status: 'committed', sync_version: version,
                    ui: {sel: 0, mode: 'strike'}, fragments: snapshot}});
            });
            async function show(fixture) {
                snapshot = fixtures[fixture];
                version = 1;
                await page.goto('https://choice.test/?first&game=970');
                await page.setContent('<style>' + styles + '</style><body class="battle-page"><main data-battle-sync-root data-panel-theme="grimoire">' +
                    Object.entries(snapshot).filter(([key]) => key !== 'panel_html').map(([key, html]) =>
                        '<div data-battle-fragment="' + key + '">' + html + '</div>').join('') + '</main>' +
                    scripts.map(name => '<script data-viewer-key="host" data-version="1" data-mode="action:stage3" data-mode-explicit="1" data-sync-url="/?first&amp;game=970&amp;ajax=battle_sync">' +
                        read('www/assets/js/' + name + '.js') + '</script>').join(''));
            }
            const row = id => page.locator('[data-card-instance="' + id + '"]');
            const card = id => page.locator('[data-drag-card="' + id + '"]');
            const submit = page.locator('[data-card-selection] button[type=submit]');
            const selected = () => page.locator('[data-card-selection-highlight]').evaluateAll(nodes =>
                nodes.map(node => node.dataset.dragCard).sort());
            const cdp = width < 1000 ? await page.context().newCDPSession(page) : null;
            async function gesture(id) {
                if (!cdp) return card(id).dblclick();
                await card(id).scrollIntoViewIfNeeded();
                const bounds = await card(id).boundingBox();
                await cdp.send('Input.dispatchTouchEvent', {type: 'touchStart', touchPoints: [
                    {x: bounds.x + bounds.width / 2, y: bounds.y + bounds.height / 2}
                ]});
                await page.waitForTimeout(550);
                await cdp.send('Input.dispatchTouchEvent', {type: 'touchEnd', touchPoints: []});
            }
            async function command(locator) {
                const before = navigations;
                await Promise.all([page.waitForResponse(response => response.request().method() === 'POST'), locator.click()]);
                await page.waitForFunction(() => !document.querySelector('[data-card-selection]'));
                assert.equal(navigations, before);
                assert.deepEqual(await selected(), []);
            }

            await show('heal');
            const counter = page.locator('[data-selection-count]');
            assert.equal(await counter.textContent(), 'Выбрано: 0 / 2');
            assert.equal(await submit.isDisabled(), true);
            await row(3).scrollIntoViewIfNeeded();
            const bounds = await row(3).boundingBox();
            await row(3).click({position: {x: bounds.width - 4, y: bounds.height / 2}});
            assert.deepEqual(await row(3).boundingBox(), bounds);
            assert.equal(await row(3).locator('input').getAttribute('type'), 'checkbox');
            assert.equal(await submit.isEnabled(), true);
            await gesture(2);
            assert.deepEqual(await selected(), ['2', '3']);
            assert.equal(await counter.textContent(), 'Выбрано: 2 / 2');
            assert.equal(await row(1).getAttribute('data-selection-at-limit'), '');
            assert.equal(await row(2).getAttribute('data-selection-at-limit'), null);
            await gesture(1);
            assert.deepEqual(await selected(), ['2', '3']);
            await row(1).click();
            assert.deepEqual(await selected(), ['2', '3']);
            await gesture(3);
            assert.deepEqual(await selected(), ['2']);
            assert.equal(await counter.textContent(), 'Выбрано: 1 / 2');
            assert.equal(await row(1).getAttribute('data-selection-at-limit'), null);
            await row(3).click();
            assert.deepEqual(await selected(), ['2', '3']);
            snapshot = fixtures.reduced;
            version++;
            await row(3).waitFor({state: 'detached'});
            assert.deepEqual(await selected(), ['2']);
            snapshot = fixtures.heal;
            version++;
            await row(3).waitFor({state: 'attached'});
            assert.equal(await row(3).locator('input').isChecked(), false);
            await row(3).click();
            assert.equal(posts.length, 0);
            await command(submit);
            assert.equal(posts[0].get('cmd'), 'choose_multi_heal');
            assert.deepEqual(posts[0].getAll('target_ids[]'), ['2', '3']);

            await show('heal');
            await row(3).click();
            await command(page.locator('[data-card-selection] a.skip'));
            assert.equal(posts[1].get('cmd'), 'cancel_pending');
            assert.equal(posts[1].get('mode'), '');
            assert.equal(await page.locator('.card-choice-form').count(), 0);
            assert.equal(posts[1].has('target_ids[]'), false);

            await show('gift');
            assert.equal(await submit.isDisabled(), true);
            await page.locator('input[name=amount][value="2"]').check();
            await row(4).click();
            assert.equal(await submit.isDisabled(), true);
            await gesture(3);
            assert.deepEqual(await selected(), ['3', '4']);
            assert.equal(await row(4).isHidden(), true);
            assert.equal(await submit.isEnabled(), true);
            await row(2).click();
            assert.deepEqual(await selected(), ['2', '4']);
            await page.locator('[data-creature-side=enemy]').click();
            assert.equal(await row(4).locator('input').isChecked(), true);
            assert.equal(await row(2).locator('input').isChecked(), true);
            snapshot = fixtures.giftRefresh;
            version++;
            const poll = page.waitForResponse(response => response.request().method() === 'GET'
                && new URL(response.url()).searchParams.has('ajax'));
            await (await poll).finished();
            await page.waitForFunction(() => document.querySelector('input[name=amount][value="2"]').checked &&
                document.querySelector('input[name=ally_id][value="2"]').checked);
            await command(submit);
            assert.equal(posts[2].get('cmd'), 'choose_life_gift');
            assert.equal(posts[2].get('amount'), '2');
            assert.equal(posts[2].get('enemy_id'), '4');
            assert.equal(posts[2].get('ally_id'), '2');

            await show('ambiguous');
            await gesture(4);
            assert.deepEqual(await selected(), []);
            assert.equal(posts.length, 3);
            await page.locator('[data-selection-group=enemy] [data-card-instance="4"]').click();
            await page.locator('[data-selection-group=ally] [data-card-instance="4"]').click();
            assert.equal(await page.locator('input[name=enemy_id][value="4"]').isChecked(), true);
            assert.equal(await page.locator('input[name=ally_id][value="4"]').isChecked(), true);
            assert.deepEqual(await selected(), ['4']);

            snapshot = fixtures.waiting;
            version++;
            await page.locator('[data-card-selection]').waitFor({state: 'detached'});
            assert.deepEqual(await selected(), []);
            await show('grezy');
            const grezyNav = navigations;
            assert.equal(await page.locator('[data-card-selection] a.skip').count(), 0);
            await gesture(4);
            assert.equal(await row(4).locator('input').isChecked(), true);
            assert.equal(posts.length, 3);
            const grezySync = page.waitForResponse(response => new URL(response.url()).searchParams.get('force_snapshot') === '1'
                && new URL(response.url()).searchParams.get('since_version') === '2');
            await Promise.all([page.waitForResponse(response => response.request().method() === 'POST'), submit.click()]);
            await grezySync;
            await page.locator('input[name=own_id]').first().waitFor();
            assert.equal(posts[3].get('enemy_id'), '4');
            assert.deepEqual(await selected(), []);
            assert.equal(await page.locator('[data-card-selection] a.skip').count(), 0);
            await gesture(2);
            await command(submit);
            assert.equal(posts[4].get('own_id'), '2');
            assert.equal(navigations, grezyNav);
            await show('discharge');
            const dischargePosts = posts.length;
            await gesture(4);
            assert.deepEqual(await selected(), ['4']);
            await command(submit);
            assert.deepEqual(posts[dischargePosts].getAll('target_ids[]'), ['4']);
            assert.equal(posts[dischargePosts].get('cmd'), 'choose_multi_discharge');

            await show('rowSpell');
            await row(4).click();
            assert.equal(await submit.isDisabled(), true);
            await row(5).click();
            assert.equal(await submit.isEnabled(), true);
            await command(submit);
            assert.deepEqual(posts[dischargePosts + 1].getAll('target_ids[]'), ['4', '5']);
            assert.equal(posts[dischargePosts + 1].get('cmd'), 'choose_row_spell_targets');

            await show('grave');
            await row(3).click();
            assert.deepEqual(await selected(), []);
            await command(submit);
            assert.equal(posts[dischargePosts + 2].get('cmd'), 'choose_grave_ritual');
            assert.equal(posts[dischargePosts + 2].get('operation_id'), 'ritual-test');
            assert.equal(posts[dischargePosts + 2].get('target_id'), '3');

            await show('forced');
            const immediate = page.locator('a[href*="cmd=choose_forced_strike"][href*="target_id=4"]');
            assert.equal(await page.locator('[data-card-selection]').count(), 0);
            await command(immediate);
            assert.equal(posts[dischargePosts + 3].get('cmd'), 'choose_forced_strike');
            assert.equal(posts[dischargePosts + 3].get('target_id'), '4');
            await show('intrigue');
            const intrigueNav = navigations;
            const intriguePost = posts.length;
            assert.equal(await row(2).count(), 0);
            const allSide = page.waitForResponse(response => new URL(response.url()).searchParams.get('in_side') === 'all');
            await page.getByRole('link', {name:'Все существа'}).click();
            await allSide;
            await row(2).waitFor();
            assert.equal(await page.locator('[data-creature-sides]').count(), 0);
            await row(2).click();
            assert.equal(await submit.isDisabled(), true);
            await row(4).click();
            await command(submit);
            assert.equal(posts[intriguePost].get('cmd'), 'choose_intrigue_start');
            assert.equal(posts[intriguePost].get('in_side'), 'all');
            assert.deepEqual(posts[intriguePost].getAll('target_ids[]'), ['2', '4']);
            assert.equal(navigations, intrigueNav);

            await show('revive');
            await row(2).click();
            assert.deepEqual(await selected(), []);
            assert.equal(posts.length, intriguePost + 1);
            await command(submit);
            assert.equal(posts[intriguePost + 1].get('cmd'), 'choose_revive_target');
            assert.equal(posts[intriguePost + 1].get('target_id'), '2');

            await show('combatPick');
            await row(4).click();
            assert.equal(posts.length, intriguePost + 2);
            await command(page.locator('[data-card-selection] a.skip'));
            assert.equal(posts[intriguePost + 2].get('cmd'), 'cancel_pending');
            assert.equal(posts[intriguePost + 2].get('pick_token'), 'pick-stage42');
            assert.deepEqual(errors, []);
            console.log('PASS Stage 3 at ' + width + 'px');
            await page.close();
        }
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
