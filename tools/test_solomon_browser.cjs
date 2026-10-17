// Optional browser regression; uses an existing Playwright installation, never installs packages.
const fs = require('fs');
const path = require('path');
const assert = require('assert/strict');
const { execFileSync } = require('child_process');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '..');
const php = process.env.PHP_BINARY || 'php';
const html = execFileSync(php, [path.join(__dirname, 'test_solomon_draft.php'), '--render'], { encoding: 'utf8' });
const waiting = execFileSync(php, [path.join(__dirname, 'test_solomon_draft.php'), '--render-player'], { encoding: 'utf8' });
const modeHtml = execFileSync(php, [path.join(__dirname, 'test_solomon_draft.php'), '--render-mode'], { encoding: 'utf8' });
(async () => {
    const browser = await chromium.launch({ headless: true, ...(process.env.BROWSER_BINARY ? { executablePath: process.env.BROWSER_BINARY } : {}) });
    try {
        // Keep the former 800px regression as well as the Stage 2 desktop sizes.
        // The former 375px no-horizontal-overflow requirement conflicts with the
        // approved fixed 440px panel; mobile layout is outside Stage 2 scope.
        for (const width of [800, 1024, 1280, 1600]) {
            const page = await browser.newPage({ viewport: { width, height: 900 } });
            const errors = [];
            let nextDecision = false;
            page.on('pageerror', error => errors.push(error.message));
            await page.route('http://solomon.test/**', async route => {
                const url = new URL(route.request().url());
                if (url.pathname.startsWith('/assets/js/') || url.pathname.startsWith('/assets/css/')) {
                    const file = path.join(root, 'www', url.pathname);
                    return route.fulfill({ contentType: url.pathname.endsWith('.js') ? 'text/javascript' : 'text/css', body: fs.readFileSync(file, 'utf8') });
                }
                if (url.pathname.includes('/assets/cards/')) return route.fulfill({ status: 404 });
                if (url.searchParams.get('cmd') === 'draft_sync') {
                    return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ version: 2, status: 'draft', changed: false, timer: { mode: 'unlimited' } }) });
                }
                let body = url.pathname === '/mode' ? modeHtml : (url.pathname === '/waiting' ? waiting : html);
                if (nextDecision) body = body.replace('data-decision="1"', 'data-decision="2"').replace('name="decision_id" value="1"', 'name="decision_id" value="2"');
                return route.fulfill({ contentType: 'text/html', body });
            });
            await page.goto('http://solomon.test/game');
            const cards = page.locator('[data-solomon-position]');
            assert.equal(await cards.count(), 8);
            assert.equal(await page.locator('#solomon-decision button').count(), 2);
            await cards.nth(0).locator('.prepare-card-link').click();
            await cards.nth(1).locator('input').check();
            assert.equal(await page.locator('[data-solomon-selected]').innerText(), '2');
            assert.equal(await page.locator('.pregame-panel-content .pregame-card-info').count(), 1);
            assert.equal(await cards.nth(0).locator('.prepare-card--selected').count(), 1);
            const payload = await page.evaluate(() => Array.from(new FormData(document.getElementById('solomon-decision')).entries()));
            assert.deepEqual(payload.filter(([key]) => key === 'positions[]').map(([, value]) => value), ['0', '1']);
            assert.ok(payload.some(([key]) => key === 'decision_id'));
            assert.ok(payload.some(([key]) => key === 'expected_version'));
            await page.reload();
            assert.equal(await page.locator('[data-solomon-selected]').innerText(), '2');
            await cards.nth(0).locator('.prepare-card-link').click();
            assert.equal(await page.locator('[data-solomon-selected]').innerText(), '1');
            nextDecision = true;
            await page.reload();
            assert.equal(await page.locator('[data-solomon-selected]').innerText(), '0');
            const submitted = page.waitForRequest(request => new URL(request.url()).searchParams.get('cmd') === 'draft_confirm');
            await page.locator('button[value="draft_confirm"]').click();
            const submittedUrl = new URL((await submitted).url());
            assert.equal(submittedUrl.searchParams.get('decision_id'), '2');
            assert.equal(submittedUrl.searchParams.get('expected_version'), '2');
            assert.equal(submittedUrl.searchParams.getAll('positions[]').length, 0);
            await page.waitForLoadState();
            const dimensions = await page.evaluate(() => ({ width: innerWidth, scroll: document.documentElement.scrollWidth }));
            assert.ok(dimensions.scroll <= dimensions.width, 'No horizontal overflow at ' + width + ': ' + JSON.stringify(dimensions));
            await page.goto('http://solomon.test/waiting');
            assert.equal(await page.locator('[name="positions[]"]').count(), 0);
            assert.equal(await page.locator('#solomon-decision').count(), 0);
            await page.locator('[data-solomon-position] .prepare-card-link').first().click();
            assert.equal(await page.locator('.pregame-panel-content .pregame-card-info').count(), 1);
            await page.goto('http://solomon.test/mode');
            const modes = page.locator('.mode-actions .settings-option');
            assert.deepEqual(await modes.allTextContents(), ['Draft — сетка', 'Solomon Draft', 'Sealed', 'Системные колоды']);
            const gridRadio = page.getByRole('radio', { name: 'Draft — сетка', exact: true });
            const solomonRadio = page.getByRole('radio', { name: 'Solomon Draft', exact: true });
            assert.equal(await gridRadio.isChecked(), true);
            const rects = await modes.evaluateAll(labels => labels.map(label => {
                const r = label.getBoundingClientRect();
                const radio = label.querySelector('input').getBoundingClientRect();
                const text = label.querySelector('span').getBoundingClientRect();
                return { x: r.x, y: r.y, width: r.width, bottom: r.bottom, radioRight: radio.right, textLeft: text.left };
            }));
            rects.forEach((rect, i) => {
                assert.equal(rect.x, rects[0].x);
                assert.equal(rect.width, rects[0].width);
                assert.ok(rect.radioRight < rect.textLeft);
                if (i) assert.ok(rect.y > rects[i - 1].bottom, 'Modes remain vertical with spacing');
            });
            const confirm = page.getByRole('button', { name: 'Подтвердить', exact: true });
            assert.ok((await confirm.boundingBox()).y > rects[3].bottom, 'Confirm is below list');
            const selectedBackground = await modes.nth(0).evaluate(label => getComputedStyle(label).backgroundColor);
            assert.notEqual(selectedBackground, await modes.nth(1).evaluate(label => getComputedStyle(label).backgroundColor));
            await gridRadio.focus();
            await page.keyboard.press('ArrowDown');
            assert.equal(await solomonRadio.isChecked(), true);
            assert.equal(await solomonRadio.evaluate(radio => document.activeElement === radio), true);
            assert.notEqual(await modes.nth(1).evaluate(label => getComputedStyle(label).outlineStyle), 'none');
            await page.keyboard.press('Tab');
            assert.equal(await confirm.evaluate(button => document.activeElement === button), true);
            const systemBox = await modes.nth(3).boundingBox();
            await modes.nth(3).click({ position: { x: systemBox.width - 10, y: systemBox.height / 2 } });
            assert.equal(await page.getByRole('radio', { name: 'Системные колоды', exact: true }).isChecked(), true, 'Entire row is clickable');
            const beforeHover = await modes.nth(1).evaluate(label => getComputedStyle(label).borderColor);
            await modes.nth(1).hover();
            assert.notEqual(await modes.nth(1).evaluate(label => getComputedStyle(label).borderColor), beforeHover);
            const modeDimensions = await page.evaluate(() => ({ width: innerWidth, scroll: document.documentElement.scrollWidth }));
            assert.ok(modeDimensions.scroll <= modeDimensions.width, 'Mode selector does not overflow');
            assert.deepEqual(errors, []);
            await page.close();
        }
        console.log('Solomon browser checks passed: 800/1024/1280/1600, selection/preview/reload/tokens, vertical mode list, default selection, whole-row click, keyboard, focus, hover, no overflow.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
