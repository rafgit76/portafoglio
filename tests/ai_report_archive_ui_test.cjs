// Run with Node + Playwright and PHP_BIN. All requests stay on an isolated test server.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..');
const fixture = path.join(__dirname, 'ai_report_archive_fixture.php');
const php = process.env.PHP_BIN || 'php';
const html = execFileSync(php, [fixture]);
const emptyHtml = execFileSync(php, [fixture, 'empty']);
const screenshots = fs.mkdtempSync(path.join(os.tmpdir(), 'portfolio-delete-ui-'));
const posts = [];
const errors = [];
const server = http.createServer((req, res) => {
    if (req.method === 'POST') {
        let body = '';
        req.on('data', (chunk) => { body += chunk; });
        req.on('end', () => {
            posts.push({ body, headers: req.headers });
            if (req.headers['x-requested-with']) {
                res.writeHead(503, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ ok: false, error: 'Errore simulato' }));
            } else {
                res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
                res.end('<p>POST di prova ricevuto, nessun report eliminato.</p>');
            }
        });
        return;
    }
    const asset = { '/assets/style.css': 'text/css', '/assets/ai-report.js': 'text/javascript' }[req.url];
    res.writeHead(200, { 'Content-Type': asset || 'text/html; charset=utf-8' });
    res.end(asset ? fs.readFileSync(path.join(root, req.url)) : req.url === '/empty' ? emptyHtml : html);
});

(async () => {
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const url = `http://127.0.0.1:${server.address().port}`;
    const browser = await chromium.launch({ headless: true, executablePath: process.env.BROWSER_BIN || undefined });
    try {
        for (const viewport of [{ width: 1280, height: 900 }, { width: 390, height: 844 }, { width: 320, height: 568 }]) {
            const context = await browser.newContext({ viewport });
            await context.route('**/*', (route) => route.request().url().startsWith(url) ? route.continue() : route.abort());
            const page = await context.newPage();
            page.on('pageerror', (error) => errors.push(error.message));
            await page.goto(url);
            const modal = page.locator('#ai-delete-dialog');
            const summaries = page.locator('.ai-archive-delete summary');
            const baseline = posts.length;
            await summaries.first().click();
            assert(await modal.isVisible());
            assert.equal(await page.locator('#ai-delete-form input[name="report_id"]').inputValue(), 'test-report-0');
            assert.equal(await page.locator('.ai-archive-delete[open]').count(), 0);
            assert.equal(await page.evaluate(() => document.activeElement.id), 'ai-delete-cancel');
            await page.keyboard.press('Tab');
            assert(await page.locator('#ai-delete-form button[type="submit"]').evaluate((element) => document.activeElement === element));
            await page.keyboard.press('Shift+Tab');
            assert.equal(await page.evaluate(() => document.activeElement.id), 'ai-delete-cancel');
            // A second trigger cannot stack a second confirmation or change the selected report.
            await summaries.nth(1).evaluate((element) => element.click());
            assert.equal(await page.locator('dialog[open]').count(), 1);
            assert.equal(await page.locator('#ai-delete-form input[name="report_id"]').inputValue(), 'test-report-0');
            await page.keyboard.press('Escape');
            assert(!(await modal.isVisible()));
            assert(await summaries.first().evaluate((element) => document.activeElement === element));
            await summaries.nth(1).click();
            await page.locator('#ai-delete-cancel').click();
            assert(!(await modal.isVisible()));
            assert.equal(posts.length, baseline);
            await summaries.last().click();
            assert((await page.locator('#ai-delete-report').textContent()).includes('<speciale> "test"'));
            const bounds = await modal.boundingBox();
            assert(bounds.x >= 0 && bounds.y >= 0 && bounds.x + bounds.width <= viewport.width && bounds.y + bounds.height <= viewport.height);
            assert(await modal.evaluate((element) => element.scrollWidth <= element.clientWidth));
            const confirm = page.locator('#ai-delete-form button[type="submit"]');
            await confirm.scrollIntoViewIfNeeded();
            const buttonBounds = await confirm.boundingBox();
            assert(buttonBounds.y >= bounds.y && buttonBounds.y + buttonBounds.height <= bounds.y + bounds.height);
            await page.screenshot({ path: path.join(screenshots, `${viewport.width}-dialog.png`), fullPage: true });
            // Click the backdrop, then reopen to exercise focus and selected-id reset.
            await page.mouse.click(2, 2);
            assert(!(await modal.isVisible()));
            await summaries.last().click();
            await page.evaluate(() => {
                const form = document.getElementById('ai-delete-form');
                form.requestSubmit();
                form.requestSubmit();
            });
            await page.waitForURL(url + '/');
            await page.getByText('POST di prova ricevuto, nessun report eliminato.').waitFor();
            assert.equal(posts.length, baseline + 1);
            assert.deepEqual(Object.fromEntries(new URLSearchParams(posts.at(-1).body)), {
                csrf_token: 'test-csrf-token', action: 'delete_report', report_id: 'test-report-5', confirmed: '1',
            });
            assert.equal(posts.at(-1).headers['x-requested-with'], undefined);
            await page.goto(url + '/empty');
            assert.equal(await page.locator('.ai-archive-delete').count(), 0);
            // Generation must still use its own overlay, never the deletion dialog.
            await page.getByRole('button', { name: 'Genera prova' }).click();
            await page.getByText('Errore simulato', { exact: true }).waitFor();
            assert(await page.locator('#ai-generation-overlay').isVisible());
            assert(!(await modal.isVisible()));
            assert.equal(posts.at(-1).headers['x-requested-with'], 'XMLHttpRequest');
            await context.close();
        }
        const context = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 390, height: 844 } });
        await context.route('**/*', (route) => route.request().url().startsWith(url) ? route.continue() : route.abort());
        const page = await context.newPage();
        await page.goto(url);
        await page.locator('.ai-archive-delete summary').first().click();
        await page.locator('.ai-archive-delete summary').last().click();
        assert.equal(await page.locator('.ai-archive-delete[open]').count(), 1);
        assert.equal(await page.locator('.ai-archive-delete[open] form').evaluate((element) => getComputedStyle(element).position), 'static');
        const fallbackConfirm = page.locator('.ai-archive-delete[open] button');
        await fallbackConfirm.scrollIntoViewIfNeeded();
        assert(await fallbackConfirm.isVisible());
        await page.screenshot({ path: path.join(screenshots, '390-no-js.png'), fullPage: true });
        await fallbackConfirm.click();
        await page.getByText('POST di prova ricevuto, nessun report eliminato.').waitFor();
        assert.equal(new URLSearchParams(posts.at(-1).body).get('report_id'), 'test-report-5');
        assert.equal(new URLSearchParams(posts.at(-1).body).get('confirmed'), '1');
        await context.close();
        assert.deepEqual(errors, []);
        console.log('OK: desktop/mobile, annulla, Esc, backdrop, focus, ID/CSRF, doppio invio, fallback senza JS e overlay generazione.');
        console.log('Screenshot: ' + screenshots);
    } finally {
        await browser.close();
        await new Promise((resolve) => server.close(resolve));
    }
})().catch((error) => { console.error(error); server.close(); process.exitCode = 1; });
