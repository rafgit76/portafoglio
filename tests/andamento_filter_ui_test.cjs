const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..');
const fixture = path.join(__dirname, 'andamento_route_fixture.php');
const php = process.env.PHP_BIN || 'php';
const modes = { all: 'main', ETF: 'etf', BTP: 'btp', AZIONE: 'azione', PAC: 'pac' };
const rendered = Object.fromEntries(Object.entries(modes).map(([type, mode]) => [type,
    execFileSync(php, ['-d', 'extension=pdo_sqlite', fixture, mode], { encoding: 'utf8' }),
]));
const server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://127.0.0.1');
    if (url.pathname === '/assets/style.css' || url.pathname === '/assets/andamento-extremes.js') {
        res.writeHead(200, { 'Content-Type': url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript' });
        res.end(fs.readFileSync(path.join(root, url.pathname)));
        return;
    }
    const type = url.searchParams.get('asset_type') || 'all';
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end('<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/style.css"></head><body><main class="container">' + (rendered[type] || rendered.all) + '</main></body></html>');
});

(async () => {
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const base = `http://127.0.0.1:${server.address().port}`;
    const browser = await chromium.launch({ headless: true, executablePath: process.env.BROWSER_BIN || undefined });
    try {
        for (const width of [1280, 375]) {
            const page = await browser.newPage({ viewport: { width, height: 900 } });
            await page.goto(base + '/andamento.php');
            assert.equal(await page.locator('.andamento-extremes-table tbody tr').count(), 5);
            await Promise.all([
                page.waitForURL('**/andamento.php?asset_type=ETF'),
                page.locator('#andamentoAssetType').selectOption('ETF'),
            ]);
            assert.equal(await page.locator('.andamento-extremes-table tbody tr').count(), 1);
            assert((await page.locator('.andamento-extremes-table tbody').textContent()).includes('ETF test'));
            assert.equal(await page.locator('#andamentoTable thead tr:first-child th').count(), 2);
            assert((await page.locator('.andamento-export-actions a').getAttribute('href')).includes('asset_type=ETF'));
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
            assert(await page.locator('.andamento-table-wrap').evaluate((element) => element.scrollWidth <= element.clientWidth), 'un solo titolo non richiede scorrimento orizzontale');
            if (process.env.ANDAMENTO_FILTER_SCREENSHOT_DIR) {
                fs.mkdirSync(process.env.ANDAMENTO_FILTER_SCREENSHOT_DIR, { recursive: true });
                await page.screenshot({ path: path.join(process.env.ANDAMENTO_FILTER_SCREENSHOT_DIR, `filtro-${width}.png`) });
            }
            await Promise.all([
                page.waitForURL('**/andamento.php?asset_type=PAC'),
                page.locator('#andamentoAssetType').selectOption('PAC'),
            ]);
            assert(await page.getByText('Nessun titolo della categoria selezionata negli snapshot disponibili.').isVisible());
            await Promise.all([
                page.waitForURL('**/andamento.php?asset_type=BTP'),
                page.locator('#andamentoAssetType').selectOption('BTP'),
            ]);
            const bonds = page.locator('#andamentoTable .andamento-heading-bond');
            assert.equal(await bonds.count(), 3);
            assert.deepEqual(await bonds.allTextContents(), ['BTP test', 'BTP-13GN27 VALORE SU', 'BTP-13GN27 VALSU CUM']);
            if (process.env.ANDAMENTO_FILTER_SCREENSHOT_DIR) {
                await page.screenshot({ path: path.join(process.env.ANDAMENTO_FILTER_SCREENSHOT_DIR, `obbligazioni-${width}.png`) });
            }
            await page.close();
        }
        const fallback = await browser.newPage({ javaScriptEnabled: false });
        await fallback.goto(base + '/andamento.php');
        await fallback.locator('#andamentoAssetType').selectOption('BTP');
        await Promise.all([
            fallback.waitForURL('**/andamento.php?asset_type=BTP'),
            fallback.getByRole('button', { name: 'Applica' }).click(),
        ]);
        assert((await fallback.locator('.andamento-extremes-table tbody').textContent()).includes('BTP test'));
        await fallback.close();
        console.log('OK: filtro desktop/mobile, categorie vuote ed uso senza JavaScript.');
    } finally {
        await browser.close();
        await new Promise((resolve) => server.close(resolve));
    }
})().catch((error) => { console.error(error); server.close(); process.exitCode = 1; });
