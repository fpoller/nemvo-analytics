// Browser tests against the dev container: a visitor browses the demo site (the tracker must
// record a human visit with interaction and a solved proof of work), then an administrator opens
// every analytics screen. Any console error, uncaught exception or failed request fails the run.
// Screenshots go to tests/e2e/screenshots/.
//
//   cd tests/e2e && npm install && npm test
//
// BASE (default http://localhost:8090) and CHROME (path to Chrome) can be set in the environment.
import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import puppeteer from 'puppeteer-core';

const here = dirname(fileURLToPath(import.meta.url));
const BASE = process.env.BASE || 'http://localhost:8090';
const CHROME = process.env.CHROME || ['/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/google-chrome', '/usr/bin/chromium'].find((p) => existsSync(p));
const SHOTS = join(here, 'screenshots');
const RUN = Date.now();
// A user agent of its own per run, so each run is a new visitor rather than a continued visit.
const UA = `Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.${RUN % 10000}.0 Safari/537.36`;
mkdirSync(SHOTS, { recursive: true });

let failed = 0;
const ok = (cond, msg) => {
	console.log((cond ? 'ok   ' : 'FAIL ') + msg);
	if (!cond) {
		failed++;
	}
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const wp = (code) => execFileSync('docker', ['compose', '-f', join(here, '../../.devcontainer/docker-compose.yml'), 'exec', '-T', 'wordpress', 'wp', 'eval', code], { encoding: 'utf8' }).trim();

function watch(page, label, problems) {
	page.on('console', (m) => m.type() === 'error' && problems.push(`${label}: console: ${m.text()}`));
	page.on('pageerror', (e) => problems.push(`${label}: exception: ${e.message}`));
	// Beacons still in flight when the page navigates away are reported as aborted; they arrive anyway.
	page.on('requestfailed', (r) => !/favicon/.test(r.url()) && !(/\/nemvo\/v1\/e$/.test(r.url()) && /ABORTED/.test(r.failure() && r.failure().errorText)) && problems.push(`${label}: request failed: ${r.url()} ${r.failure() && r.failure().errorText}`));
	page.on('response', (r) => r.status() >= 400 && !/favicon/.test(r.url()) && problems.push(`${label}: HTTP ${r.status()} ${r.url()}`));
	page.on('request', (r) => {
		const u = new URL(r.url());
		// The admin bar avatar comes from Gravatar: that is WordPress itself, not this plugin.
		if (!/^(localhost|127\.0\.0\.1)$/.test(u.hostname) && !/gravatar\.com$/.test(u.hostname) && !u.protocol.startsWith('data') && !u.protocol.startsWith('blob')) {
			problems.push(`${label}: external request: ${r.url()}`);
		}
	});
}

const browser = await puppeteer.launch({ executablePath: CHROME, headless: true, args: ['--window-size=1440,1000', '--no-first-run'] });
try {
	/* ---------------- A visitor ---------------- */
	const visitorCtx = await browser.createBrowserContext();
	const visitor = await visitorCtx.newPage();
	const vProblems = [];
	watch(visitor, 'site', vProblems);
	await visitor.setUserAgent(UA);
	// This is a real browser driven by a test; present it like one so the visit counts as a person.
	await visitor.evaluateOnNewDocument(() => Object.defineProperty(Navigator.prototype, 'webdriver', { get: () => false }));
	await visitor.setViewport({ width: 1280, height: 900 });
	const marker = 'e2e-' + RUN;
	await visitor.goto(`${BASE}/?utm_source=${marker}&utm_medium=email&utm_campaign=e2e`, { waitUntil: 'networkidle0' });
	ok(await visitor.$('#nva-tracker'), 'tracker is printed on the page');
	const attrs = await visitor.$eval('#nva-tracker', (s) => [s.getAttribute('data-no-optimize'), s.getAttribute('data-cfasync'), s.hasAttribute('nowprocket')]);
	ok(attrs[0] === '1' && attrs[1] === 'false' && attrs[2], 'tracker carries the optimizer opt-out attributes');
	for (let i = 0; i < 6; i++) {
		await visitor.mouse.move(200 + i * 40, 300 + i * 20);
		await visitor.mouse.wheel({ deltaY: 400 });
		await sleep(250);
	}
	await sleep(1500); // Let the proof of work finish.
	await visitor.goto(`${BASE}/services/cloud-migration/`, { waitUntil: 'networkidle0' });
	await visitor.mouse.move(400, 400);
	await visitor.mouse.wheel({ deltaY: 800 });
	await sleep(1200);
	await visitor.goto(`${BASE}/contact/`, { waitUntil: 'networkidle0' });
	await visitor.mouse.move(300, 300);
	await visitor.type('input[name="your-name"]', 'Test Person');
	await visitor.type('input[name="your-email"]', 'test@example.com');
	await Promise.all([visitor.waitForNavigation({ waitUntil: 'networkidle0' }), visitor.click('form[data-nva-label] button[type="submit"]')]);
	await visitor.mouse.move(310, 310);
	await sleep(500);
	await visitor.goto(`${BASE}/this-page-does-not-exist/`, { waitUntil: 'networkidle0' }).catch(() => {});
	await sleep(500);
	await visitorCtx.close(); // pagehide sends the last engagement ping
	await sleep(1500);
	// The 404 page answers 404 on purpose.
	const real = vProblems.filter((p) => !/this-page-does-not-exist|status of 404/.test(p));
	real.forEach((p) => console.log('     ' + p));
	ok(!real.length, 'no console errors or external requests on the site');

	const visit = JSON.parse(
		wp(`$db = NemvoAnalytics\\DB::conn(); echo wp_json_encode( $db->get_row( $db->prepare( 'SELECT id, pageviews, events, duration, bot_score, bot_flags, channel, is_bounce FROM ' . NemvoAnalytics\\DB::table( 'sessions' ) . ' WHERE utm_source = %s', '${marker}' ), ARRAY_A ) );`) || 'null'
	);
	ok(visit && Number(visit.pageviews) === 5, `visit recorded with 5 pages (${visit && visit.pageviews})`);
	ok(visit && visit.channel === 'Email', `attributed to the email campaign (${visit && visit.channel})`);
	ok(visit && Number(visit.events) >= 1, `form submission recorded (${visit && visit.events} events)`);
	ok(visit && Number(visit.bot_flags) & 1024, 'interaction recorded');
	ok(visit && Number(visit.bot_score) < 50, `scored as a person (${visit && visit.bot_score}, flags ${visit && visit.bot_flags})`);
	ok(visit && Number(visit.duration) > 0, `engaged time recorded (${visit && visit.duration}s)`);
	ok(visit && Number(visit.bot_flags) & 2048, 'proof of work solved in the browser (localhost is a secure context)');

	/* ---------------- An administrator ---------------- */
	const page = await browser.newPage();
	const problems = [];
	watch(page, 'admin', problems);
	await page.setViewport({ width: 1440, height: 1000 });
	await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'networkidle0' });
	await page.type('#user_login', 'admin');
	await page.type('#user_pass', 'admin');
	await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#wp-submit')]);

	await page.goto(`${BASE}/wp-admin/admin.php?page=nemvo-analytics`, { waitUntil: 'networkidle0' });
	await page.waitForFunction(() => document.querySelectorAll('#nva-app canvas').length >= 5 && !document.querySelector('#nva-app .is-loading'), { timeout: 30000 });
	await sleep(800);
	const counts = await page.evaluate(() => ({ canvases: document.querySelectorAll('#nva-app canvas').length, rows: document.querySelectorAll('.nva-row').length, tiles: document.querySelectorAll('.nva-tile').length, log: document.querySelectorAll('.nva-table--log tbody tr').length }));
	ok(counts.canvases >= 5 && counts.tiles === 8 && counts.rows > 40 && counts.log >= 10, `dashboard renders: ${JSON.stringify(counts)}`);
	ok(!(await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)), 'no horizontal overflow at 1440 px');
	await page.screenshot({ path: join(SHOTS, 'dashboard.png'), fullPage: true });

	// Drill down from the map: click the country with the most visitors.
	const before = await page.evaluate(() => location.hash);
	await page.click('#nva-companies .nva-row__label');
	await page.waitForFunction((b) => location.hash !== b, {}, before);
	await page.waitForFunction(() => !document.querySelector('#nva-app .is-loading'));
	ok(/company/.test(decodeURIComponent(await page.evaluate(() => location.hash))), 'clicking a company filters the dashboard');
	await page.click('.nva-table--log tbody tr.is-clickable');
	await page.waitForSelector('dialog[open] .nva-timeline');
	await sleep(400);
	await page.screenshot({ path: join(SHOTS, 'visit.png') });
	ok(await page.$('dialog[open] .nva-signal-list, dialog[open] .nva-score'), 'visit detail opens with pages and classification');
	await page.keyboard.press('Escape');
	await page.goBack();
	await page.waitForFunction(() => !document.querySelector('#nva-app .is-loading'));

	// Automated traffic view.
	await page.goto(`${BASE}/wp-admin/admin.php?page=nemvo-analytics#range=30d&traffic=bot`, { waitUntil: 'networkidle0' });
	await page.waitForFunction(() => document.querySelectorAll('#nva-app canvas').length >= 3 && !document.querySelector('#nva-app .is-loading'), { timeout: 30000 });
	await sleep(500);
	await page.screenshot({ path: join(SHOTS, 'dashboard-bots.png'), fullPage: true });

	// Dark mode.
	await page.goto(`${BASE}/wp-admin/admin.php?page=nemvo-analytics#range=30d`, { waitUntil: 'networkidle0' });
	await page.evaluate(() => localStorage.setItem('nva-theme', '"dark"'));
	await page.reload({ waitUntil: 'networkidle0' });
	await page.waitForFunction(() => document.querySelectorAll('#nva-app canvas').length >= 5 && !document.querySelector('#nva-app .is-loading'), { timeout: 30000 });
	await sleep(800);
	await page.screenshot({ path: join(SHOTS, 'dashboard-dark.png'), fullPage: true });
	await page.evaluate(() => localStorage.setItem('nva-theme', '"auto"'));

	// Realtime.
	await page.goto(`${BASE}/wp-admin/admin.php?page=nemvo-analytics-realtime`, { waitUntil: 'networkidle0' });
	await page.waitForSelector('#nva-app canvas');
	await sleep(800);
	await page.screenshot({ path: join(SHOTS, 'realtime.png'), fullPage: true });

	// Settings: checklist, lookup and connection tests.
	await page.goto(`${BASE}/wp-admin/admin.php?page=nemvo-analytics-settings`, { waitUntil: 'networkidle0' });
	ok((await page.$$('.nva-checklist li')).length >= 3, 'settings show the setup checklist');
	await page.type('#nva-lookup-ip', '8.8.8.8');
	await page.click('[data-nva-action="lookup"]');
	await page.waitForSelector('#nva-lookup-result:not([hidden])', { timeout: 15000 });
	ok(/Google/.test(await page.$eval('#nva-lookup-result', (e) => e.textContent)), 'IP lookup works from the settings page');
	await page.click('[data-nva-action="queue-test"]');
	await page.waitForFunction(() => /Valkey/.test(document.querySelector('[data-nva-action="queue-test"]').parentNode.textContent), { timeout: 15000 });
	ok(true, 'queue connection test answers');
	await page.click('[data-nva-action="cap-test"]');
	await page.waitForFunction(() => /accepts the keys/.test(document.querySelector('[data-nva-action="cap-test"]').parentNode.textContent), { timeout: 15000 });
	ok(true, 'Cap connection test answers');
	await page.screenshot({ path: join(SHOTS, 'settings.png'), fullPage: false });

	// Move statistics to the external database and back with the settings page buttons.
	page.on('dialog', (d) => d.accept());
	const count = (target) => Number(wp(`echo array_sum( (array) NemvoAnalytics\\DB::counts( '${target}', true ) );`));
	const rows = count('local');
	for (const [to, label] of [['external', 'to the external database'], ['local', 'back to the WordPress database']]) {
		await page.goto(`${BASE}/wp-admin/admin.php?page=nemvo-analytics-settings#database`, { waitUntil: 'networkidle0' });
		const started = Date.now();
		await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0', timeout: 180000 }), page.click(`[data-nva-action="migrate"][data-to="${to}"]`)]);
		const active = wp('echo NemvoAnalytics\\DB::active();');
		ok(active === to && count(to) >= rows, `moved ${label} in ${((Date.now() - started) / 1000).toFixed(1)} s (${count(to)} rows)`);
	}
	await page.goto(`${BASE}/wp-admin/admin.php?page=nemvo-analytics-settings#database`, { waitUntil: 'networkidle0' });
	await page.click('[data-nva-action="purge-inactive"]');
	await page.waitForFunction(() => /deleted/.test(document.querySelector('[data-nva-action="purge-inactive"]').parentNode.textContent), { timeout: 30000 });
	ok(count('external') === 0, 'old copy deleted from the external database');

	// Phone width.
	await page.setViewport({ width: 390, height: 844, isMobile: true });
	await page.goto(`${BASE}/wp-admin/admin.php?page=nemvo-analytics`, { waitUntil: 'networkidle0' });
	await page.waitForFunction(() => document.querySelectorAll('#nva-app canvas').length >= 5 && !document.querySelector('#nva-app .is-loading'), { timeout: 30000 });
	await sleep(500);
	ok(!(await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)), 'no horizontal overflow at 390 px');
	await page.screenshot({ path: join(SHOTS, 'dashboard-phone.png'), fullPage: true });

	problems.forEach((p) => console.log('     ' + p));
	ok(!problems.length, 'no console errors, failed or external requests in wp-admin');
} finally {
	await browser.close();
}
console.log(failed ? `${failed} failed` : 'all passed');
process.exit(failed ? 1 : 0);
