/*! Nemvo Analytics tracker. Cookieless: sets no cookies and stores no identifiers. */
(function (w, d, n) {
	'use strict';

	var cfg = w.nvaConfig;
	if (!cfg || w.nvaLoaded) {
		return;
	}
	w.nvaLoaded = true;

	// While an administrator runs "Check tracking", say why the tracker stops (no identifiers are sent).
	function stopped(reason, detail) {
		if (!cfg.diag) {
			return;
		}
		try {
			var body = JSON.stringify({ t: 'x', r: reason, m: String(detail || '').slice(0, 120) });
			if (!(n.sendBeacon && n.sendBeacon(cfg.endpoint, new Blob([body], { type: 'text/plain' })))) {
				fetch(cfg.endpoint, { method: 'POST', body: body, keepalive: true, credentials: 'omit', headers: { 'Content-Type': 'text/plain' } }).catch(function () {});
			}
		} catch (e) {}
	}

	// Site owners can exclude their own browser by visiting any page with #nva-ignore.
	try {
		if (location.hash === '#nva-ignore') {
			localStorage.setItem('nva_ignore', '1');
		} else if (location.hash === '#nva-track') {
			localStorage.removeItem('nva_ignore');
		}
		if (localStorage.getItem('nva_ignore') === '1') {
			stopped('ignore', location.pathname);
			return;
		}
	} catch (e) {}

	if (cfg.dnt && (n.doNotTrack === '1' || w.doNotTrack === '1' || n.globalPrivacyControl === true)) {
		stopped(n.globalPrivacyControl === true ? 'gpc' : 'dnt', location.pathname);
		return;
	}

	var IDLE_MS = 5 * 60 * 1000;
	var ctx = cfg.ctx || {};
	var pv = null;
	var engaged = 0;
	var visibleSince = null;
	var lastActive = 0;
	var maxScroll = 0;
	var sentMs = -1;
	var sentScroll = -1;
	var interacted = false;
	var sentInteraction = false;
	var lastPath = location.pathname;
	// The server already recorded this page view; the tracker's hit confirms that one.
	var serverPv = /^[a-f0-9]{16}$/.test(cfg.pv || '') ? cfg.pv : '';
	var verifying = false;

	function rid() {
		var a = new Uint8Array(8);
		w.crypto.getRandomValues(a);
		var s = '';
		for (var i = 0; i < a.length; i++) {
			s += (a[i] + 256).toString(16).slice(1);
		}
		return s;
	}

	function send(data) {
		var body = JSON.stringify(data);
		try {
			if (n.sendBeacon && n.sendBeacon(cfg.endpoint, new Blob([body], { type: 'text/plain' }))) {
				return;
			}
		} catch (e) {}
		try {
			fetch(cfg.endpoint, { method: 'POST', body: body, keepalive: true, credentials: 'omit', headers: { 'Content-Type': 'text/plain' } }).catch(function () {});
		} catch (e) {}
	}

	function idle(fn) {
		if (w.requestIdleCallback) {
			w.requestIdleCallback(fn, { timeout: 3000 });
		} else {
			setTimeout(fn, 200);
		}
	}

	/* Cheap checks for automated browsers. Bits: 1 webdriver, 2 no window size, 4 no languages, 8 a Chrome user agent without Chrome, 16 automation globals. */
	function checks() {
		var b = 0;
		try {
			if (n.webdriver) {
				b |= 1;
			}
			if (!w.outerWidth || !w.outerHeight) {
				b |= 2;
			}
			if (!n.languages || !n.languages.length) {
				b |= 4;
			}
			if (/Chrome\/\d/.test(n.userAgent) && !/CriOS|EdgiOS/.test(n.userAgent) && !w.chrome) {
				b |= 8;
			}
			if (w._phantom || w.callPhantom || w.__nightmare || w.domAutomation || w.domAutomationController || d.documentElement.getAttribute('webdriver') !== null) {
				b |= 16;
			}
		} catch (e) {}
		return b;
	}

	/* Proof of work: find a nonce whose SHA-256 with the challenge starts with `bits` zero bits. */
	function solvePow(challenge, bits, id) {
		if (!w.crypto || !w.crypto.subtle || !w.TextEncoder) {
			return;
		}
		var enc = new TextEncoder();
		var i = 0;
		var full = bits >> 3;
		var rest = bits & 7;
		function good(buf) {
			var a = new Uint8Array(buf);
			for (var k = 0; k < full; k++) {
				if (a[k]) {
					return false;
				}
			}
			return !rest || !(a[full] >> (8 - rest));
		}
		function attempt(x) {
			var s = x.toString(36);
			return w.crypto.subtle.digest('SHA-256', enc.encode(challenge + s)).then(function (h) {
				return good(h) ? s : null;
			});
		}
		function batch() {
			var jobs = [];
			for (var k = 0; k < 128; k++) {
				jobs.push(attempt(i++));
			}
			Promise.all(jobs).then(function (found) {
				for (var k = 0; k < found.length; k++) {
					if (found[k] !== null) {
						send({ t: 'v', id: id, c: challenge, n: found[k] });
						return;
					}
				}
				if (i < 1048576) {
					setTimeout(batch, 0);
				}
			}).catch(function () {});
		}
		batch();
	}

	/* Cap: load the bundled widget only when the server asks for it, then solve invisibly. */
	function solveCap(id) {
		var c = cfg.cap;
		if (!c) {
			return;
		}
		function run() {
			try {
				new w.Cap({ apiEndpoint: c.endpoint }).solve().then(function (r) {
					if (r && r.token) {
						send({ t: 'v', id: id, k: r.token });
					}
				}).catch(function () {});
			} catch (e) {}
		}
		if (typeof w.Cap === 'function') {
			run();
			return;
		}
		w.CAP_CUSTOM_WASM_URL = c.wasm;
		w.CAP_CUSTOM_HASHWX_URL = c.hashwx;
		w.CAP_PAKO_URL = c.pako;
		var s = d.createElement('script');
		s.src = c.script;
		s.async = true;
		s.onload = run;
		d.head.appendChild(s);
	}

	function verify(reply, id) {
		if (verifying || !reply) {
			return;
		}
		verifying = true;
		idle(function () {
			if (reply.v === 'pow' && reply.c) {
				solvePow(String(reply.c), Math.min(20, reply.b | 0), id);
			} else if (reply.v === 'cap') {
				solveCap(id);
			}
		});
	}

	/* Engaged time: counts only while the tab is visible and the reader was active in the last five minutes. */
	function activeEnd(t) {
		return Math.min(t, lastActive + IDLE_MS);
	}

	function engagedMs() {
		return engaged + (visibleSince === null ? 0 : Math.max(0, activeEnd(Date.now()) - visibleSince));
	}

	function onActivity(e) {
		if (e && e.isTrusted && e.type !== 'scroll') {
			interacted = true;
		}
		var t = Date.now();
		if (t - lastActive < 1000) {
			return;
		}
		if (visibleSince !== null && t - lastActive > IDLE_MS) {
			engaged += Math.max(0, lastActive + IDLE_MS - visibleSince);
			visibleSince = t;
		} else if (visibleSince === null && d.visibilityState === 'visible') {
			visibleSince = t;
		}
		lastActive = t;
	}

	function pause() {
		if (visibleSince !== null) {
			engaged += Math.max(0, activeEnd(Date.now()) - visibleSince);
			visibleSince = null;
		}
	}

	function scrollDepth() {
		var root = d.documentElement;
		var height = Math.max(root.scrollHeight, d.body ? d.body.scrollHeight : 0);
		var view = w.innerHeight || root.clientHeight;
		if (height <= view) {
			return 100;
		}
		return Math.min(100, Math.round(((w.pageYOffset || root.scrollTop || 0) + view) / height * 100));
	}

	function flush() {
		if (!pv) {
			return;
		}
		var ms = Math.round(engagedMs());
		var s = cfg.scroll ? maxScroll : 0;
		if (ms - sentMs < 1000 && s <= sentScroll && interacted === sentInteraction) {
			return;
		}
		sentMs = ms;
		sentScroll = s;
		sentInteraction = interacted;
		send({ t: 'e', id: pv, d: ms, s: s, i: interacted ? 1 : 0 });
	}

	function pageview(referrer) {
		if (pv) {
			pause();
			flush();
		}
		pv = serverPv || rid();
		serverPv = '';
		engaged = 0;
		sentMs = -1;
		sentScroll = -1;
		sentInteraction = false;
		lastActive = Date.now();
		visibleSince = d.visibilityState === 'visible' ? lastActive : null;

		var tz = '';
		try {
			tz = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
		} catch (e) {}

		var id = pv;
		var body = JSON.stringify({
			t: 'pv',
			id: id,
			u: location.href,
			r: referrer || '',
			ti: d.title,
			w: (w.screen && w.screen.width) || 0,
			tz: tz,
			l: n.language || '',
			tp: n.maxTouchPoints > 1 ? 1 : 0,
			b: checks(),
			c: ctx
		});
		// fetch rather than a beacon: the reply may ask for a proof of work.
		try {
			fetch(cfg.endpoint, { method: 'POST', body: body, keepalive: true, credentials: 'omit', headers: { 'Content-Type': 'text/plain' } })
				.then(function (res) {
					return res.status === 200 ? res.json() : null;
				})
				.then(function (reply) {
					if (reply && /^[a-f0-9]{16}$/.test(reply.id || '') && pv === id) {
						pv = id = reply.id;
					}
					verify(reply, id);
				})
				.catch(function () {});
		} catch (e) {
			send(JSON.parse(body));
		}
		maxScroll = 0;
		setTimeout(function () {
			maxScroll = Math.max(maxScroll, scrollDepth());
		}, 500);
	}

	function track(name, label) {
		if (!pv || !name) {
			return;
		}
		send({ t: 'ev', id: pv, n: String(name).slice(0, 64), l: label ? String(label).slice(0, 255) : '' });
	}

	/* Automatic events: outbound links, downloads, email/phone links and custom data attributes. */
	function onClick(e) {
		var target = e.target && e.target.closest ? e.target : null;
		if (!target) {
			return;
		}
		var custom = target.closest('[data-nva-event]');
		if (custom) {
			track(custom.getAttribute('data-nva-event'), custom.getAttribute('data-nva-label') || (custom.textContent || '').trim().slice(0, 80));
		}
		var a = target.closest('a[href]');
		if (!a || a === custom) {
			return;
		}
		var href = a.href || '';
		if (/^mailto:/i.test(href)) {
			if (cfg.contact) {
				track('Email click', decodeURIComponent(href.slice(7).split('?')[0]));
			}
			return;
		}
		if (/^tel:/i.test(href)) {
			if (cfg.contact) {
				track('Phone click', decodeURIComponent(href.slice(4)));
			}
			return;
		}
		var url;
		try {
			url = new URL(href, location.href);
		} catch (err) {
			return;
		}
		if (!/^https?:$/.test(url.protocol)) {
			return;
		}
		var ext = (url.pathname.split('.').pop() || '').toLowerCase();
		var external = url.hostname.replace(/^www\./, '') !== location.hostname.replace(/^www\./, '');
		if (cfg.downloads && cfg.downloads.indexOf(ext) !== -1) {
			track('File download', external ? url.hostname + url.pathname : url.pathname);
		} else if (external && cfg.outbound) {
			track('Outbound link', (url.hostname + url.pathname).replace(/\/$/, ''));
		}
	}

	function formLabel(f) {
		var action = '';
		try {
			action = new URL(f.getAttribute('action') || location.href, location.href).pathname;
		} catch (e) {}
		return f.getAttribute('data-nva-label') || f.getAttribute('aria-label') || f.id || f.getAttribute('name') || action;
	}

	function onSubmit(e) {
		var f = e.target;
		if (!f || f.nodeName !== 'FORM') {
			return;
		}
		// Contact Form 7 is reported on successful delivery instead (wpcf7mailsent).
		if (f.classList.contains('wpcf7-form')) {
			return;
		}
		// Site searches are recorded by the server.
		if (f.getAttribute('role') === 'search' || f.querySelector('input[name="s"]')) {
			return;
		}
		if (f.id === 'commentform') {
			track('Comment', location.pathname);
			return;
		}
		track('Form submit', formLabel(f));
	}

	function start() {
		pageview(d.referrer);

		var throttled = false;
		w.addEventListener('scroll', function (e) {
			onActivity(e);
			if (throttled) {
				return;
			}
			throttled = true;
			w.requestAnimationFrame(function () {
				maxScroll = Math.max(maxScroll, scrollDepth());
				throttled = false;
			});
		}, { passive: true });

		['mousemove', 'pointerdown', 'keydown', 'touchstart', 'wheel'].forEach(function (type) {
			w.addEventListener(type, onActivity, { passive: true });
		});

		d.addEventListener('visibilitychange', function () {
			if (d.visibilityState === 'hidden') {
				pause();
				flush();
			} else {
				lastActive = 0;
				onActivity();
			}
		});
		w.addEventListener('pagehide', function () {
			pause();
			flush();
		});
		w.addEventListener('pageshow', function (e) {
			if (e.persisted) {
				pageview(d.referrer);
			}
		});

		d.addEventListener('click', onClick, true);
		d.addEventListener('auxclick', function (e) {
			if (e.button === 1) {
				onClick(e);
			}
		}, true);

		if (cfg.forms) {
			d.addEventListener('submit', onSubmit, true);
			d.addEventListener('wpcf7mailsent', function (e) {
				track('Form submit', 'Contact Form 7 #' + ((e.detail && e.detail.contactFormId) || ''));
			});
			if (w.jQuery) {
				w.jQuery(d).on('wpformsAjaxSubmitSuccess', function (e) {
					track('Form submit', (e.target && e.target.id) || 'WPForms');
				});
				w.jQuery(d).on('gform_confirmation_loaded', function (e, id) {
					track('Form submit', 'Gravity Forms #' + id);
				});
			}
		}

		// Single-page navigation (themes or blocks using the History API).
		var onRoute = function () {
			if (location.pathname === lastPath) {
				return;
			}
			var ref = location.origin + lastPath;
			lastPath = location.pathname;
			ctx = { type: 'other', id: 0 };
			pageview(ref);
		};
		if (w.history && history.pushState) {
			var push = history.pushState;
			history.pushState = function () {
				push.apply(this, arguments);
				onRoute();
			};
			w.addEventListener('popstate', onRoute);
		}
	}

	/** Public API: nvaTrack('Booked a call', 'Hero CTA'). */
	w.nvaTrack = track;

	function safeStart() {
		try {
			start();
		} catch (e) {
			stopped('error', e && e.message);
		}
	}

	if (d.prerendering) {
		d.addEventListener('prerenderingchange', safeStart, { once: true });
	} else {
		safeStart();
	}
})(window, document, navigator);
