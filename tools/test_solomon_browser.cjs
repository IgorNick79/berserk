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
(async () => {
    const browser = await chromium.launch({ headless: true, ...(process.env.BROWSER_BINARY ? { executablePath: process.env.BROWSER_BINARY } : {}) });
    try {
        for (const width of [375, 800, 1600]) {
            const page = await browser.newPage({ viewport: { width, height: 900 } });
            const errors = [];
            let nextDecision = false;
            page.on('pageerror', error => errors.push(error.message));
            await page.route('http://solomon.test/**', async route => {
                const url = new URL(route.request().url());
                if (url.pathname.startsWith('/assets/js/') || url.pathname === '/assets/css/global.css') {
                    const file = path.join(root, 'www', url.pathname);
                    return route.fulfill({ contentType: url.pathname.endsWith('.js') ? 'text/javascript' : 'text/css', body: fs.readFileSync(file, 'utf8') });
                }
                if (url.pathname.includes('/assets/cards/')) return route.fulfill({ status: 404 });
                if (url.searchParams.get('cmd') === 'draft_sync') {
                    return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ version: 2, status: 'draft', changed: false, timer: { mode: 'unlimited' } }) });
                }
                let body = url.pathname === '/waiting' ? waiting : html;
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
            assert.equal(await page.locator('.draft-preview-slot img').count(), 1);
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
            assert.equal(await page.locator('[name="positions[]"]:disabled').count(), 8);
            assert.equal(await page.locator('#solomon-decision button:disabled').count(), 2);
            await page.locator('[data-solomon-position] .prepare-card-link').first().click();
            assert.equal(await page.locator('[data-solomon-selected]').innerText(), '0');
            assert.deepEqual(errors, []);
            await page.close();
        }
        console.log('Solomon browser checks passed: 375/800/1600, selection, preview, reload, decision reset, form tokens, inactive player.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
