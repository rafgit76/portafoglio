const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..');
const fixture = path.join(__dirname, 'andamento_history_ui_fixture.php');
const html = Object.fromEntries(['extremes', 'quantity', 'quantity-filter'].map((mode) => [mode, execFileSync(process.env.PHP_BIN || 'php', [fixture, mode])]));
const server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://127.0.0.1');
    if (url.pathname === '/assets/style.css' || url.pathname === '/assets/andamento-extremes.js') {
        res.writeHead(200, { 'Content-Type': url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript' });
        res.end(fs.readFileSync(path.join(root, url.pathname)));
    } else {
        const mode = url.pathname === '/quantity' ? (url.searchParams.get('asset') === '1' ? 'quantity-filter' : 'quantity') : 'extremes';
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        res.end(html[mode]);
    }
});
(async () => {
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const base = `http://127.0.0.1:${server.address().port}`;
    const browser = await chromium.launch({ headless: true, executablePath: process.env.BROWSER_BIN || undefined });
    try {
        for (const width of [1280, 390, 320]) {
            const page = await browser.newPage({ viewport: { width, height: 900 } });
            await page.route('**/*', (route) => route.request().url().startsWith(base) ? route.continue() : route.abort());
            await page.goto(base);
            assert(await page.getByRole('heading', { name: 'Massimi e minimi per titolo' }).isVisible());
            assert(await page.getByText('AZIONE & TEST').isVisible());
            assert.equal(await page.locator('.andamento-extremes-table tbody tr').count(), 2);
            const first = page.locator('.andamento-extremes-table tbody tr').first().locator('.andamento-extreme-detail').first();
            await first.locator(':scope > summary').click();
            const popup = first.locator('.andamento-extreme-popover');
            assert(await popup.isVisible());
            assert((await popup.innerText()).includes('2026-11-18'));
            assert((await popup.innerText()).includes('Quantità nello snapshot: 12,5'));
            assert((await popup.innerText()).includes('Scarto'));
            const dates = popup.locator('.andamento-extreme-dates');
            await dates.locator('summary').click();
            assert((await dates.innerText()).includes('2026-11-16'));
            assert((await dates.innerText()).includes('2026-11-18'));
            assert((await dates.innerText()).includes('2026-11-16 · quantità: 10'));
            assert((await dates.innerText()).includes('2026-11-18 · quantità: 12,5'));
            const withinViewport = await popup.evaluate((element) => {
                const box = element.getBoundingClientRect();
                const atCenter = document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2);
                return box.left >= 0 && box.right <= innerWidth && box.top >= 0 && box.bottom <= innerHeight && element.contains(atCenter);
            });
            assert(withinViewport, 'tooltip visibile e non tagliato sui bordi');
            await page.keyboard.press('Escape');
            assert(!(await popup.isVisible()));
            const zero = page.locator('.andamento-extremes-table tbody tr').first().locator('.andamento-extreme-detail').nth(2);
            await zero.locator(':scope > summary').click();
            assert((await zero.locator('.andamento-extreme-popover').innerText()).includes('N/D %'));
            await zero.getByRole('button', { name: 'Chiudi' }).click();
            assert(!(await zero.locator('.andamento-extreme-popover').isVisible()));
            const missing = page.locator('.andamento-extremes-table tbody tr').nth(1).locator('.andamento-extreme-detail').first();
            await missing.locator(':scope > summary').click();
            assert((await missing.locator('.andamento-extreme-popover').innerText()).includes('N/D: titolo non presente'));
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
            if (process.env.ANDAMENTO_SCREENSHOT_DIR) {
                fs.mkdirSync(process.env.ANDAMENTO_SCREENSHOT_DIR, { recursive: true });
                await page.screenshot({ path: path.join(process.env.ANDAMENTO_SCREENSHOT_DIR, `extremes-${width}.png`) });
            }
            await page.goto(base + '/quantity');
            assert(await page.getByRole('heading', { name: 'Storico quantità' }).isVisible());
            assert((await page.locator('tbody').innerText()).includes('BTP TEST'));
            assert((await page.locator('tbody').innerText()).includes('snapshot senza dato intermedio'));
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
            if (process.env.ANDAMENTO_SCREENSHOT_DIR) {
                await page.screenshot({ path: path.join(process.env.ANDAMENTO_SCREENSHOT_DIR, `quantity-${width}.png`) });
            }
            await page.goto(base + '/quantity?asset=1');
            assert((await page.locator('tbody').innerText()).includes('AZIONE & TEST'));
            assert(!(await page.locator('tbody').innerText()).includes('BTP TEST'));
            await page.close();
        }
        const fallback = await browser.newPage({ javaScriptEnabled: false, viewport: { width: 1280, height: 900 } });
        await fallback.goto(base);
        const details = fallback.locator('.andamento-extreme-detail').first();
        await details.locator(':scope > summary').click();
        assert(await details.locator('.andamento-extreme-popover').isVisible());
        assert((await details.locator('.andamento-extreme-popover').innerText()).includes('2026-11-18'));
        await fallback.close();
        console.log('OK: riepilogo e storico quantita desktop/mobile, filtri, record ripetuti e lacune.');
    } finally {
        await browser.close();
        await new Promise((resolve) => server.close(resolve));
    }
})().catch((error) => { console.error(error); server.close(); process.exitCode = 1; });
