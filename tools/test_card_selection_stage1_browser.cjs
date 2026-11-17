const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE ||
    'C:/Users/mi/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');

const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const fixture = JSON.parse(execFileSync(process.env.PHP_BINARY || 'C:/OSPanel/modules/php/PHP_8.1/php.exe',
    [path.join(__dirname, 'test_card_selection_stage1.php')], {encoding: 'utf8'}));
const keys = ['field_html', 'fly_zones_html', 'panel_html', 'piles_html', 'pile_reveal_html', 'info_panel_html'];
const styles = ['global.css', 'battle-ui-skins.css', 'battle-coordinates.css'].map(name => read('www/assets/css/' + name)).join('\n');

(async () => {
    const browser = await chromium.launch({executablePath: process.env.BROWSER_EXECUTABLE ||
        'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true});
    try {
        const page = await browser.newPage();
        const errors = [];
        const posts = [];
        let navigations = 0;
        page.on('pageerror', error => errors.push(error.message));
        page.on('framenavigated', frame => { if (frame === page.mainFrame()) navigations++; });
        await page.route('https://choice.test/**', route => {
            const request = route.request();
            if (request.method() === 'POST') {
                posts.push(new URLSearchParams(request.postData()));
                return route.fulfill({json: {}});
            }
            if (request.isNavigationRequest()) return route.fulfill({contentType: 'text/html', body: '<html></html>'});
            return route.fulfill({json: {}});
        });
        async function show(panel) {
            await page.goto('https://choice.test/?first&game=970');
            await page.setContent('<style>' + styles + '</style><body class="battle-page"><main data-battle-sync-root data-panel-theme="grimoire">' +
                keys.map(key => '<div data-battle-fragment="' + key + '">' + (key === 'info_panel_html' ? '<aside class="info-panel">' + panel + '</aside>' : '') + '</div>').join('') +
                '</main><script data-sync-url="/?first&amp;game=970" data-version="1" data-mode="action:pair" data-mode-explicit="1">' +
                read('www/assets/js/battle-sync.js') + '</script></body>');
            await page.addScriptTag({content: read('www/assets/js/creature-choice.js')});
        }

        await show(fixture.extended);
        const initialNavigations = navigations;
        const row = page.locator('[data-card-instance="2"]');
        assert.equal(await page.locator('input:checked').count(), 0);
        assert.equal(await page.locator('[data-card-instance] .coords').count(), 2);
        const before = await row.boundingBox();
        const outline = await row.evaluate(el => getComputedStyle(el).outlineStyle);
        await row.click({position: {x: before.width - 4, y: before.height / 2}});
        assert.equal(await row.locator('input').isChecked(), true);
        assert.deepEqual(await row.boundingBox(), before);
        assert.notEqual(await row.evaluate(el => getComputedStyle(el).outlineStyle), outline);
        await page.locator('[data-card-row-filter="1"]').click();
        assert.equal(await row.isHidden(), true);
        await page.locator('[data-creature-side="own"]').click();
        await page.locator('[data-card-row-filter="all"]').click();
        assert.equal(await row.locator('input').isChecked(), true);

        await page.locator('form').evaluate((form, html) => { form.outerHTML = html; }, fixture.extended);
        await page.waitForFunction(() => document.querySelector('[data-card-instance="2"] input').checked);
        assert.equal(await row.isHidden(), true);
        await Promise.all([page.waitForResponse(response => response.request().method() === 'POST'), page.locator('button[type="submit"]').click()]);
        assert.equal(posts[0].get('cmd'), 'choose_paired_ranged');
        assert.equal(posts[0].get('operation_id'), 'paired-1');
        assert.equal(posts[0].get('stage'), '0');
        assert.equal(posts[0].get('target_id'), '2');
        assert.equal(navigations, initialNavigations);

        await show(fixture.panel);
        const beforeCancel = navigations;
        await Promise.all([page.waitForResponse(response => response.request().method() === 'POST'), page.locator('a.skip').click()]);
        assert.equal(posts[1].get('cmd'), 'choose_paired_ranged');
        assert.equal(posts[1].get('cancel'), '1');
        assert.equal(posts[1].get('mode'), '');
        assert.equal(posts[1].has('target_id'), false);
        assert.equal(navigations, beforeCancel);
        assert.deepEqual(errors, []);
        console.log('PASS Stage 1 browser and AJAX request checks');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
