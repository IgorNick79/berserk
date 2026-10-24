const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const repo = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(repo, file), 'utf8');
const assert = (condition, message) => { if (!condition) throw new Error(message); };

(async () => {
    const browser = await chromium.launch({
        headless: true,
        ...(process.env.BROWSER_EXECUTABLE ? { executablePath: process.env.BROWSER_EXECUTABLE } : {}),
    });
    try {
        for (const width of [1600, 800, 375]) {
            for (const viewer of ['host', 'player']) {
                const data = JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', [
                    path.join(repo, 'tools/test_battle_defender_ui.php'), '--fixture', viewer,
                ], { encoding: 'utf8' }));
                const page = await browser.newPage({ viewport: { width, height: 900 } });
                await page.route('https://defender.test/**', route => route.fulfill({
                    contentType: 'text/html', body: '<html><body>Command received</body></html>',
                }));
                await page.goto('https://defender.test/');
                const html = read('templates/screen/battle.tpl')
                    .replace(/<script[\s\S]*?<\/script>/g, '').replace(/<link[^>]*>/g, '')
                    .replace(/{{(\w+)}}/g, (_, key) => data[key] || '');
                const show = () => page.setContent('<html class="battle-page"><head><meta charset="utf-8">'
                    + '<style>' + read('www/assets/css/global.css') + read('www/assets/css/battle-ui-skins.css').replace(/^@import[^;]+;/,'') + read('www/assets/css/battle-coordinates.css') + '</style></head>'
                    + '<body class="battle-page">' + html + '</body></html>');
                await show();
                const choices = page.locator('.defender-radio-list input[type=radio]');
                assert(await choices.count() === 2, 'Only creatures are radio choices');
                const badges = page.locator('.defender-radio-list .coords');
                assert(await badges.count() === 2, 'Every defender has a coordinate badge');
                assert((await badges.allTextContents()).every(text => /\[I{1,3}:\d\]/.test(text)), 'Roman row labels');
                assert(await badges.first().evaluate(el => el.classList.contains('coords--own')), 'Own field color');
                assert(await badges.nth(1).evaluate(el => el.classList.contains('coords--enemy')), 'Crossed own creature uses enemy field color');
                assert((await badges.nth(1).textContent()).includes(viewer==='host'?'[I:4]':'[I:2]'), 'Viewer-relative columns after crossing');
                const edges=await badges.evaluateAll(nodes=>nodes.map(node=>node.getBoundingClientRect().right));
                assert(Math.abs(edges[0]-edges[1])<1, 'Coordinate badges align at the right edge '+JSON.stringify(await badges.evaluateAll(nodes=>nodes.map(e=>[e.getBoundingClientRect().right,e.parentNode.clientWidth,getComputedStyle(e.parentNode).display,getComputedStyle(e.parentNode).gridTemplateColumns,e.closest('.info-panel').clientWidth]))));
                const style = await badges.first().evaluate(el => ({
                    fg: getComputedStyle(el).color, bg: getComputedStyle(el).backgroundImage,
                }));
                assert(style.fg !== style.bg && style.bg !== 'none', 'Coordinate colors are applied');
                await choices.nth(1).check();
                assert(await choices.nth(1).isChecked(), 'Second creature is selectable');
                const skip = page.getByRole('link', { name: 'Пропустить', exact: true });
                assert(await skip.count() === 1 && await skip.isVisible(), 'One visible dedicated skip button');
                assert(await skip.evaluate(el => getComputedStyle(el).whiteSpace === 'nowrap'
                    && el.scrollWidth <= el.clientWidth + 1), 'Skip text fits without broken words');
                if (width === 375 || width === 1600) {
                    const dir = path.join(repo, 'tmp/defender-ui');
                    fs.mkdirSync(dir, { recursive: true });
                    await page.locator('.info-panel').screenshot({ path: path.join(dir, `${width}-${viewer}.png`) });
                }
                await page.getByRole('button', { name: 'Выбрать', exact: true }).click();
                await page.waitForURL(url => url.searchParams.get('cmd') === 'choose_defender_option');
                assert(new URL(page.url()).searchParams.get('choice') === 'defender:4', 'Choose submits selected creature');
                await show();
                await choices.nth(1).check();
                await page.getByRole('link', { name: 'Пропустить', exact: true }).click();
                await page.waitForURL(url => url.searchParams.get('cmd') === 'choose_defender');
                const url = new URL(page.url());
                assert(url.searchParams.get('defender_id') === '0' && !url.searchParams.has('choice'), 'Skip ignores selected radio');
                console.log(`PASS ${width}px ${viewer}: creature radios, colored Roman coordinates, select and direct skip`);
                await page.close();
            }
        }
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exit(1); });
