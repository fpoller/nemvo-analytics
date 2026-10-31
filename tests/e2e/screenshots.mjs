// Takes the WordPress.org screenshots and renders the icon and banners into .wordpress-org/,
// from the dev container with its demo data.
//
//   cd tests/e2e && node screenshots.mjs
import { existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import puppeteer from 'puppeteer-core';

const here = dirname(fileURLToPath(import.meta.url));
const BASE = process.env.BASE || 'http://localhost:8090';
const CHROME = process.env.CHROME || ['/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/google-chrome', '/usr/bin/chromium'].find((p) => existsSync(p));
const OUT = join(here, '../../.wordpress-org');
mkdirSync(OUT, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const browser = await puppeteer.launch({ executablePath: CHROME, headless: true, args: ['--no-first-run', '--force-color-profile=srgb'] });

// A few people on the site right now, for the realtime screenshot.
for (const [i, path] of ['/services/cybersecurity/', '/blog/nis2-checklist-for-it-leaders/', '/contact/'].entries()) {
	const ctx = await browser.createBrowserContext();
	const p = await ctx.newPage();
	await p.setUserAgent(`Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.${Date.now() % 1000 + i}.0 Safari/537.36`);
	await p.evaluateOnNewDocument(() => Object.defineProperty(Navigator.prototype, 'webdriver', { get: () => false }));
	await p.goto(BASE + '/', { waitUntil: 'networkidle0' });
	await p.mouse.move(100, 100);
	await p.goto(BASE + path, { waitUntil: 'networkidle0' });
	await p.mouse.move(200, 200);
	await sleep(300);
	await ctx.close();
}

const page = await browser.newPage();
await page.setViewport({ width: 1440, height: 1000, deviceScaleFactor: 1 });
await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: 'light' }]);
await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'networkidle0' });
await page.type('#user_login', 'admin');
await page.type('#user_pass', 'admin');
await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#wp-submit')]);
await page.evaluate(() => localStorage.setItem('nva-theme', '"light"'));

const ready = () => page.waitForFunction(() => document.querySelectorAll('#nva-app canvas').length >= 5 && !document.querySelector('#nva-app .is-loading'), { timeout: 30000 });
const scrollTo = async (selector, offset = 90) => {
	await page.evaluate((s, o) => window.scrollTo(0, document.querySelector(s).getBoundingClientRect().top + window.scrollY - o), selector, offset);
	await sleep(400);
};
const shot = (n) => page.screenshot({ path: join(OUT, `screenshot-${n}.png`) });

await page.goto(`${BASE}/wp-admin/admin.php?page=nemvo-analytics#range=30d`, { waitUntil: 'networkidle0' });
await ready();
await sleep(800);
await page.mouse.move(900, 470); // a tooltip on the chart
await sleep(400);
await shot(1);
await page.mouse.move(10, 990);
await scrollTo('#nva-locations');
await shot(2);

await page.click('.nva-table--log tbody tr.is-clickable');
await page.waitForSelector('dialog[open] .nva-timeline');
await sleep(500);
await shot(3);
await page.keyboard.press('Escape');

await scrollTo('#nva-bots');
await shot(4);

await page.goto(`${BASE}/wp-admin/admin.php?page=nemvo-analytics-realtime`, { waitUntil: 'networkidle0' });
await page.waitForSelector('#nva-app canvas');
await sleep(1000);
await shot(5);

await page.evaluate(() => localStorage.setItem('nva-theme', '"dark"'));
await page.goto(`${BASE}/wp-admin/admin.php?page=nemvo-analytics#range=30d`, { waitUntil: 'networkidle0' });
await ready();
await sleep(800);
await shot(6);
await page.evaluate(() => localStorage.setItem('nva-theme', '"auto"'));

await page.goto(`${BASE}/wp-admin/admin.php?page=nemvo-analytics-settings`, { waitUntil: 'networkidle0' });
await sleep(300);
await shot(7);

/* Icon and banners. */
const icon = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256">
  <rect width="256" height="256" rx="56" fill="#2a78d6"/>
  <path d="M44 184 92 128l40 30 76-86" fill="none" stroke="#fff" stroke-width="18" stroke-linecap="round" stroke-linejoin="round"/>
  <circle cx="208" cy="72" r="16" fill="#fff"/>
  <path d="M44 212h168" stroke="#cde2fb" stroke-width="10" stroke-linecap="round"/>
</svg>`;
writeFileSync(join(OUT, 'icon.svg'), icon + '\n');
for (const size of [256, 128]) {
	await page.setViewport({ width: size, height: size, deviceScaleFactor: 1 });
	await page.setContent(`<html><body style="margin:0;background:transparent">${icon.replace('<svg ', `<svg width="${size}" height="${size}" `)}</body></html>`);
	await page.screenshot({ path: join(OUT, `icon-${size}x${size}.png`), omitBackground: true });
}
const banner = (w, h) => `<html><body style="margin:0">
<div style="width:${w}px;height:${h}px;box-sizing:border-box;padding:${h * 0.16}px ${w * 0.06}px;background:#f9f9f7;font-family:system-ui,-apple-system,'Segoe UI',sans-serif;position:relative;overflow:hidden">
  <svg viewBox="0 0 600 220" style="position:absolute;right:${w * 0.03}px;bottom:${h * 0.08}px;width:${w * 0.46}px" xmlns="http://www.w3.org/2000/svg">
    <path d="M0 200 C60 180 90 120 150 130 S240 60 300 90 S420 40 470 60 S560 20 600 10 L600 220 L0 220Z" fill="rgba(42,120,214,0.1)"/>
    <path d="M0 200 C60 180 90 120 150 130 S240 60 300 90 S420 40 470 60 S560 20 600 10" fill="none" stroke="#2a78d6" stroke-width="6" stroke-linecap="round"/>
    <path d="M0 210 C70 200 110 170 160 175 S250 130 310 140 S430 110 480 120 S560 95 600 90" fill="none" stroke="#a8a69e" stroke-width="5" stroke-linecap="round"/>
    <circle cx="600" cy="10" r="10" fill="#2a78d6" stroke="#f9f9f7" stroke-width="5"/>
  </svg>
  <div style="display:flex;align-items:center;gap:${h * 0.06}px">
    <div style="width:${h * 0.2}px;height:${h * 0.2}px">${icon.replace('<svg ', `<svg width="${h * 0.2}" height="${h * 0.2}" `)}</div>
    <div style="font-size:${h * 0.13}px;font-weight:650;color:#0b0b0b;letter-spacing:-0.01em">Nemvo Analytics</div>
  </div>
  <div style="margin-top:${h * 0.07}px;max-width:${w * 0.46}px;font-size:${h * 0.058}px;line-height:1.35;color:#52514e">Self-hosted, cookieless web analytics. See which companies visit and keep bots out of your numbers.</div>
</div></body></html>`;
for (const [w, h] of [[1544, 500], [772, 250]]) {
	await page.setViewport({ width: w, height: h, deviceScaleFactor: 1 });
	await page.setContent(banner(w, h));
	await page.screenshot({ path: join(OUT, `banner-${w}x${h}.png`) });
}

await browser.close();
console.log('Wrote', OUT);
