const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { pathToFileURL } = require('node:url');
const { chromium } = require('playwright');
const { PDFDocument } = require('pdf-lib');

const root = path.resolve(__dirname, '..');
const html = execFileSync(process.env.PHP_BIN || 'php', [path.join(__dirname, 'credit_lombard_print_fixture.php')]);
const server = http.createServer((req, res) => {
    if (req.url === '/assets/credit-lombard-print.css') {
        res.writeHead(200, { 'Content-Type': 'text/css' });
        res.end(fs.readFileSync(path.join(root, 'assets', 'credit-lombard-print.css')));
    } else {
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        res.end(html);
    }
});
(async () => {
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const browser = await chromium.launch({ headless: true, executablePath: process.env.BROWSER_BIN || undefined });
    try {
        const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
        await page.goto(`http://127.0.0.1:${server.address().port}`);
        assert.equal(await page.locator('tbody tr').count(), 99);
        assert.equal((await page.locator('tbody tr').first().locator('td').first().textContent()).trim(), '10/04/2026');
        assert.equal((await page.locator('tbody tr').last().locator('td').first().textContent()).trim(), '01/01/2026');
        assert((await page.locator('aside').innerText()).includes('Giorni senza saldo: 2'));
        assert((await page.locator('tbody').innerText()).includes('N/D'));
        await page.evaluate(() => { window.print = () => { window.__printCalled = true; }; });
        await page.getByRole('button', { name: 'Stampa / Salva PDF' }).click();
        assert(await page.evaluate(() => window.__printCalled));
        const pdf = await page.pdf({ format: 'A4', landscape: true, printBackground: true });
        assert.equal(pdf.subarray(0, 4).toString(), '%PDF');
        assert((await PDFDocument.load(pdf)).getPageCount() > 1);
        const pdfjs = await import(pathToFileURL(require.resolve('pdfjs-dist/legacy/build/pdf.mjs')).href);
        const document = await pdfjs.getDocument({ data: new Uint8Array(pdf), disableFontFace: true }).promise;
        const text = [];
        for (let i = 1; i <= document.numPages; i++) {
            const content = await (await document.getPage(i)).getTextContent();
            text.push(content.items.map((item) => item.str).join(' '));
        }
        assert(text[0].includes('10/04/2026'));
        assert(text.at(-1).includes('01/01/2026'));
        assert(text.join(' ').includes('N/D'));
        await document.destroy();
        await page.close();
        console.log('OK: stampa PDF multipagina, archivio completo, lacune e comando di stampa.');
    } finally {
        await browser.close();
        await new Promise((resolve) => server.close(resolve));
    }
})().catch((error) => { console.error(error); server.close(); process.exitCode = 1; });
