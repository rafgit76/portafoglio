// Real browser/PDF rendering against synthetic data only. No app credentials or database.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');
const root = path.resolve(__dirname, '..');
const php = process.env.PHP_BIN || 'php';
const phpFlags = process.env.PHP_TEST_EXT_DIR ? ['-d', `extension_dir=${process.env.PHP_TEST_EXT_DIR}`, '-d', 'extension=mbstring', '-d', 'extension=zip', '-d', 'extension=gd'] : [];
const output = fs.mkdtempSync(path.join(os.tmpdir(), 'portfolio-wealth-export-'));
const fixtures = Object.fromEntries(['full', 'single', 'partial', 'nullable', 'xlsx', 'header', 'header-empty'].map(mode => [mode, execFileSync(php, [...phpFlags, path.join(__dirname, 'account_history_fixture.php'), mode], { maxBuffer: 10 * 1024 * 1024 })]));
fs.writeFileSync(path.join(output, 'andamento-patrimonio-demo.xlsx'), fixtures.xlsx);
const assets = {
    '/assets/style.css': 'text/css',
    '/assets/account-history-export.css': 'text/css',
    '/assets/account-history-export.js': 'text/javascript',
    '/assets/vendor/chart.umd.min.js': 'text/javascript',
};
const server = http.createServer((req, res) => {
    if (req.method !== 'GET') { res.writeHead(405); return res.end(); }
    const asset = assets[req.url];
    if (asset) {
        res.writeHead(200, { 'Content-Type': asset });
        return res.end(fs.readFileSync(path.join(root, req.url)));
    }
    const mode = new URL(req.url, 'http://localhost').searchParams.get('mode') || 'full';
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    const html = fixtures[mode] || fixtures.full;
    res.end(process.argv.includes('--serve') ? html.toString().replaceAll('PORTFOLIO INVESTIMENTI', 'DEMO - DATI SIMULATI').replace('<title>', '<title>DEMO - ') : html);
});

(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const url = `http://127.0.0.1:${server.address().port}`;
    if (process.argv.includes('--serve')) {
        console.log(`Anteprima locale con SOLI DATI SIMULATI: ${url}`);
        return;
    }
    let browser;
    const errors = [];
    try {
        browser = await chromium.launch({ executablePath: process.env.BROWSER_BIN || undefined, headless: true });
        for (const width of [1280, 390, 320]) {
            const context = await browser.newContext({ viewport: { width, height: 1000 } });
            await context.route('**/*', route => route.request().url().startsWith(url) ? route.continue() : route.abort());
            const page = await context.newPage();
            page.on('pageerror', error => errors.push(error.message));
            await page.goto(url);
            assert(await page.evaluate(() => window.accountHistoryPrintReady));
            assert(await page.locator('#print-report').isEnabled());
            assert.equal(await page.locator('.report-page').count(), 8);
            assert.equal(await page.locator('.history-print-table tbody tr').count(), 98);
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `No horizontal overflow at ${width}`);
            const visibleOverflow = await page.locator('h1,h2,h3,strong,td,th,.net-value,button').evaluateAll(elements => elements.filter(el => el.scrollWidth > el.clientWidth + 1 && getComputedStyle(el).display !== 'inline').map(el => el.textContent));
            assert.deepEqual(visibleOverflow, [], `Text fits at ${width}`);
            for (const canvas of await page.locator('canvas').all()) {
                assert(await canvas.evaluate(el => {
                    const pixels = el.getContext('2d').getImageData(0, 0, el.width, el.height).data;
                    let colored = 0;
                    for (let i = 0; i < pixels.length; i += 4) if (pixels[i + 3] > 0 && Math.max(pixels[i], pixels[i + 1], pixels[i + 2]) - Math.min(pixels[i], pixels[i + 1], pixels[i + 2]) > 30) colored++;
                    return colored > 200;
                }), 'Chart has colored data pixels');
            }
            await page.locator('.report-page').first().screenshot({ path: path.join(output, `${width}-overview.png`) });
            if (width === 1280) {
                await page.emulateMedia({ media: 'print' });
                await page.evaluate(() => { dispatchEvent(new Event('beforeprint')); });
                await page.waitForTimeout(150);
                const collisions = await page.locator('.report-page').evaluateAll(pages => pages.flatMap((section, index) => {
                    const footer = section.querySelector('.report-footer').getBoundingClientRect();
                    return [...section.children].filter(el => !el.classList.contains('report-footer') && el.getBoundingClientRect().bottom > footer.top - 4).map(el => ({ page: index + 1, element: el.className, bottom: el.getBoundingClientRect().bottom, footer: footer.top }));
                }));
                assert.deepEqual(collisions, [], 'No content collides with footer in print');
                await page.pdf({ path: path.join(output, 'andamento-patrimonio-demo.pdf'), preferCSSPageSize: true, printBackground: true, displayHeaderFooter: false });
                for (let n = 0; n < 8; n++) await page.locator('.report-page').nth(n).screenshot({ path: path.join(output, `print-${n + 1}.png`) });
            }
            let printCalls = 0;
            await page.emulateMedia({ media: 'screen' });
            await page.exposeFunction('recordPrint', () => { printCalls++; });
            await page.evaluate(() => { window.print = () => window.recordPrint(); });
            await page.locator('#print-report').click();
            assert.equal(printCalls, 1, 'Print button works');
            await page.goto(`${url}?mode=header`);
            assert.equal(await page.locator('.export-btn').count(), 2);
            assert.equal(await page.locator('.export-btn-excel').getAttribute('href'), '/account_history.php?export=xlsx');
            assert.equal(await page.locator('.export-btn-pdf').getAttribute('href'), '/account_history.php?export=pdf');
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `Archive toolbar fits at ${width}`);
            await page.screenshot({ path: path.join(output, `${width}-archive-toolbar.png`), fullPage: true });
            await page.goto(`${url}?mode=header-empty`);
            assert.equal(await page.locator('.export-btn').count(), 0, 'No empty-history export');
            await context.close();
        }
        const page = await browser.newPage();
        for (const [mode, chartCount, pageCount] of [['single', 3, 4], ['partial', 1, 3], ['nullable', 0, 3]]) {
            await page.goto(`${url}?mode=${mode}`);
            assert(await page.evaluate(() => window.accountHistoryPrintReady));
            assert.equal(await page.locator('canvas').count(), chartCount);
            assert.equal(await page.locator('.report-page').count(), pageCount);
            assert(!(await page.locator('.net-value').textContent()).includes('NaN'));
            if (mode === 'single') {
                await page.pdf({ path: path.join(output, 'singolo-snapshot.pdf'), preferCSSPageSize: true, printBackground: true });
            }
        }
        await page.route('**/chart.umd.min.js', route => route.abort());
        await page.goto(url);
        assert.equal(await page.evaluate(() => window.accountHistoryPrintReady), false);
        assert(await page.locator('#print-report').isDisabled(), 'No silent blank-chart print on asset error');
        assert.deepEqual(errors, []);
        console.log(`OK: Excel fixture, PDF 8 pages, single/partial/missing data, charts, print, desktop/mobile. Artifacts: ${output}`);
    } finally {
        if (browser) await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
