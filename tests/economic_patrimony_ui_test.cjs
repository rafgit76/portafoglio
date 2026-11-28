const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const http = require('node:http');
const os = require('node:os');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..');
const php = process.env.PHP_BIN || 'php';
const fixture = (mode) => execFileSync(php, ['-d', 'extension=pdo_sqlite', path.join(__dirname, 'economic_patrimony_ui_fixture.php'), mode]);
const output = fs.mkdtempSync(path.join(os.tmpdir(), 'portfolio-pne-'));
const pages = Object.fromEntries(['dashboard', 'dashboard-week', 'history', 'history-detail', 'account'].map(mode => [mode, fixture(mode)]));
const assets = new Set(['/assets/style.css', '/assets/economic-patrimony.js', '/assets/vendor/chart.umd.min.js']);
const server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (assets.has(url.pathname)) {
        res.setHeader('Content-Type', url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript');
        return res.end(fs.readFileSync(path.join(root, url.pathname)));
    }
    const mode = url.pathname.includes('account_history.php')
        ? url.searchParams.has('view') ? 'history-detail' : 'history'
        : url.pathname.endsWith('/account.php') ? 'account' : url.searchParams.has('pne_period') ? 'dashboard-week' : 'dashboard';
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    res.end(pages[mode]);
});

(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const url = `http://127.0.0.1:${server.address().port}`;
    let browser;
    try {
        assert.match(fixture('integration').toString(), /^OK:/);
        browser = await chromium.launch({ executablePath: process.env.BROWSER_BIN || undefined, headless: true });
        const errors = [];
        for (const width of [1600, 390, 320]) {
            const page = await browser.newPage({ viewport: { width, height: 1000 } });
            await page.route('**/*', route => route.request().url().startsWith(url) ? route.continue() : route.abort());
            page.on('pageerror', error => errors.push(error.message));
            await page.goto(url);
            assert.equal(await page.locator('.pne-headline-value').textContent(), 'EUR 154.570,35');
            await page.waitForFunction(() => window.Chart?.getChart('pne-dashboard-chart'));
            assert.deepEqual(await page.evaluate(() => Chart.getChart('pne-dashboard-chart').data.datasets[0].data), [149700, null, 154586.60, 154570.35]);
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `Dashboard deborda a ${width}px`);
            assert(await page.locator('#pne .actions-inline a').evaluateAll(links => links.every(link => link.getBoundingClientRect().right <= link.closest('#pne').getBoundingClientRect().right)), `Pulsanti tagliati a ${width}px`);
            await page.locator('#pne').screenshot({ path: path.join(output, `dashboard-${width}.png`) });
            if (width === 1600) await page.locator('#diversificazione-2027').screenshot({ path: path.join(output, 'diversificazione.png') });
            await page.getByRole('link', { name: '1 sett.' }).click();
            assert.equal(await page.locator('nav[aria-label="Periodo PNE"] [aria-current="true"]').textContent(), '1 sett.');
            await page.getByRole('link', { name: 'Storico patrimonio' }).click();
            assert.equal(await page.locator('.pne-compact-table thead th').count(), 8);
            assert.match(await page.locator('.pne-compact-table tbody tr').first().textContent(), /154\.570,35/);
            await page.locator('.pne-compact-table summary').filter({ hasText: 'DA VERIFICARE' }).click();
            assert.match(await page.locator('.pne-compact-table details[open]').textContent(), /0,63/);
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `Archivio deborda a ${width}px`);
            if (width === 1600) await page.locator('.pne-compact-table').screenshot({ path: path.join(output, 'archivio.png') });
            await page.locator('#pne-history-view').selectOption('detail');
            await page.locator('#pne-history-status').selectOption('DA VERIFICARE');
            await page.getByRole('button', { name: 'Applica' }).click();
            assert.equal(await page.locator('.account-history-table tbody tr').count(), 1);
            await page.goto(`${url}/account.php`);
            assert(await page.getByRole('heading', { name: 'Capacita operativa' }).isVisible());
            assert.equal(await page.locator('canvas[id^="pne-account-"]').count(), 4);
            await page.waitForFunction(() => window.Chart?.getChart('pne-lombard-collateral'));
            assert.match(await page.locator('#credit-lombard-monitor').textContent(), /14\.250,30/);
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `Conto deborda a ${width}px`);
            if (width === 1600) await page.screenshot({ path: path.join(output, 'conto.png'), fullPage: true });
            await page.close();
        }
        assert.deepEqual(errors, []);
        console.log(`OK: dashboard, grafici con lacune, periodi, progetto 2027, archivio e filtro stato, Conto e garanzie. Screenshot: ${output}`);
    } finally {
        if (browser) await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
