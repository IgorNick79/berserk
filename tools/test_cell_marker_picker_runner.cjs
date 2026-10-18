// Runs the existing real DOM regression fixture without a database or HTTP server.
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '..');
const php = process.env.PHP_BINARY || 'php';
(async () => {
    const browser = await chromium.launch({ headless: true, ...(process.env.BROWSER_BINARY ? { executablePath: process.env.BROWSER_BINARY } : {}) });
    let checks = 0;
    try {
        for (const width of [375, 1600]) for (const viewer of ['host', 'player']) {
            for (const fixture of ['full', 'single', 'row', 'half', 'stale', 'observer', 'nojs']) {
                const page = await browser.newPage({ viewport: { width, height: 900 } });
                const errors = [];
                page.on('pageerror', e => errors.push(e.message));
                await page.route('http://picker.test/**', route => {
                    const url = new URL(route.request().url());
                    if (url.pathname.startsWith('/www/assets/') || url.pathname === '/tools/test_cell_marker_picker_browser.js') {
                        const file = path.join(root, url.pathname);
                        if (!fs.existsSync(file)) return route.fulfill({ status: 404, body: '' });
                        return route.fulfill({ contentType: file.endsWith('.js') ? 'text/javascript' : 'text/css', body: fs.readFileSync(file) });
                    }
                    const query = 'viewer=' + viewer + '&case=' + (fixture === 'nojs' ? 'full&js=0' : fixture);
                    const html = execFileSync(php, ['-r', 'parse_str($argv[1], $_GET); require $argv[2];', query,
                        path.join(root, 'tools/test_cell_marker_picker_browser.php')], { encoding: 'utf8' });
                    return route.fulfill({ contentType: 'text/html', body: html });
                });
                await page.goto('http://picker.test/fixture');
                await page.locator('#run-picker-tests').click();
                await page.waitForFunction(() => /^(ALL PASSED|FAILED)/.test(document.querySelector('#picker-test-results').textContent));
                const result = await page.locator('#picker-test-results').innerText();
                if (!result.startsWith('ALL PASSED') || errors.length) throw Error([width, viewer, fixture, result.split('\n').slice(0, 2).join(' '), ...errors].join('\n'));
                checks++;
                await page.close();
            }
        }
        console.log('Cell picker: ' + checks + ' browser scenarios passed (375/1600px, both viewers, full/sparse/stale/observer/no-JS).');
    } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
