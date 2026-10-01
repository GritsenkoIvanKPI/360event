// Usage: node screenshot.mjs <url> [label] [width]
// Full-page capture with the locally installed Google Chrome (puppeteer-core).
// Saves to ./temporary screenshots/screenshot-N[-label].png (auto-incremented, never overwritten).
import puppeteer from 'puppeteer-core';
import { mkdirSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const [url = 'http://localhost:3000', label, width = '1440'] = process.argv.slice(2);
const dir = join(fileURLToPath(new URL('.', import.meta.url)), 'temporary screenshots');
mkdirSync(dir, { recursive: true });
const n = readdirSync(dir).filter(f => /^screenshot-\d+/.test(f))
  .map(f => parseInt(f.match(/^screenshot-(\d+)/)[1], 10)).reduce((a, b) => Math.max(a, b), 0) + 1;
const out = join(dir, `screenshot-${n}${label ? '-' + label : ''}.png`);

const browser = await puppeteer.launch({
  executablePath: process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  headless: true,
});
const page = await browser.newPage();
const w = parseInt(width, 10);
await page.setViewport({ width: w, height: w < 700 ? 844 : 900, deviceScaleFactor: 1 });
await page.goto(url, { waitUntil: 'networkidle0' });
// Scroll through the page so scroll-triggered reveals fire, then return to top.
await page.evaluate(async () => {
  for (let y = 0; y < document.body.scrollHeight; y += 400) { window.scrollTo({ top: y, behavior: 'instant' }); await new Promise(r => setTimeout(r, 60)); }
  window.scrollTo({ top: 0, behavior: 'instant' });
  await new Promise(r => setTimeout(r, 1500));
});
await page.screenshot({ path: out, fullPage: true });
await browser.close();
console.log(out);
