const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const os = require('node:os');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..');
const html = execFileSync(process.env.PHP_BIN || 'php', [path.join(__dirname, 'credit_lombard_ui_fixture.php')]);
const server = http.createServer((req, res) => {
    const pathname = new URL(req.url, 'http://127.0.0.1').pathname;
    const assets = { '/assets/style.css': 'text/css', '/assets/credit-lombard.js': 'text/javascript', '/assets/vendor/chart.umd.min.js': 'text/javascript' };
    res.writeHead(200, { 'Content-Type': assets[pathname] || 'text/html; charset=utf-8' });
    res.end(assets[pathname] ? fs.readFileSync(path.join(root, pathname)) : html);
});

(async () => {
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const url = `http://127.0.0.1:${server.address().port}`;
    const browser = await chromium.launch({ headless: true, executablePath: process.env.BROWSER_BIN || undefined });
    const screenshotDir = fs.mkdtempSync(path.join(os.tmpdir(), 'lombard-ui-'));
    try {
        for (const width of [1280, 390, 320]) {
            const page = await browser.newPage({ viewport: { width, height: 900 } });
            const errors = [];
            page.on('pageerror', (error) => errors.push(error.message));
            await page.route('**/*', (route) => route.request().url().startsWith(url) ? route.continue() : route.abort());
            await page.goto(url);
            await page.waitForFunction(() => typeof Chart !== 'undefined' && Chart.getChart('lombard-chart'));
            assert(await page.getByText('ATTENZIONE: UTILIZZO STIMATO OLTRE PLAFOND.', { exact: false }).isVisible());
            assert((await page.locator('.lombard-monitor').innerText()).includes('Storico incompleto'));
            const data = JSON.parse(await page.locator('#lombard-chart-data').textContent());
            assert.equal(data.find((row) => row.date === '2026-11-03').usage, null);
            assert.equal(data.find((row) => row.date === '2026-11-04').usage, 13000);
            assert.equal(await page.evaluate(() => Chart.getChart('lombard-chart').data.datasets[0].spanGaps), false);
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
            await page.screenshot({ path: path.join(screenshotDir, `${width}.png`), fullPage: true });
            assert.deepEqual(errors, []);
            await page.close();
        }
        const page = await browser.newPage({ javaScriptEnabled: false, viewport: { width: 390, height: 900 } });
        await page.goto(url);
        await page.getByText('Mostra storico giornaliero osservato').click();
        assert(await page.getByText('EUR 13.000,00', { exact: false }).first().isVisible());
        assert.equal(await page.getByRole('link', { name: 'Esporta Excel' }).getAttribute('href'), '/account.php?lombard_export=xlsx');
        assert.equal(await page.getByRole('link', { name: 'Stampa PDF' }).getAttribute('href'), '/account.php?lombard_export=pdf');
        await page.getByText('Condizioni e storico tassi').click();
        assert(await page.locator('input[name="confirm_rate"]').isVisible());
        await page.close();
        console.log('OK: UI desktop/mobile, lacune, sconfinamento, grafico e fallback senza JS.');
        console.log('Screenshot: ' + screenshotDir);
    } finally {
        await browser.close();
        await new Promise((resolve) => server.close(resolve));
    }
})().catch((error) => { console.error(error); server.close(); process.exitCode = 1; });
