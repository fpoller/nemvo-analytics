/* Nemvo Analytics: the dashboard and realtime screens (one app, two views), drawn with Chart.js. */
(function (Chart, ChartGeo, wp) {
	'use strict';

	const cfg = window.nvaDashboard;
	const app = document.getElementById('nva-app');
	if (!cfg || !app || !Chart) {
		return;
	}
	const { __, _n, sprintf } = wp.i18n;

	if (ChartGeo) {
		Chart.register(ChartGeo.ChoroplethController, ChartGeo.GeoFeature, ChartGeo.ProjectionScale, ChartGeo.ColorScale, ChartGeo.ColorLogarithmicScale);
	}

	/* ------------------------------------------------------------------ *
	 * Small helpers
	 * ------------------------------------------------------------------ */

	const sum = (rows, fn) => rows.reduce((t, r) => t + (fn ? fn(r) : r), 0);
	const max = (rows, fn) => rows.reduce((m, r) => Math.max(m, fn ? fn(r) : r), 0);
	const range = (start, end, step = 1) => Array.from({ length: Math.max(0, Math.ceil((end - start) / step)) }, (v, i) => start + i * step);

	/* ------------------------------------------------------------------ *
	 * Formatting
	 * ------------------------------------------------------------------ */

	const locale = cfg.locale || undefined;
	const nf = new Intl.NumberFormat(locale);
	const nfCompact = new Intl.NumberFormat(locale, { notation: 'compact', maximumFractionDigits: 1 });
	const nfDec = new Intl.NumberFormat(locale, { maximumFractionDigits: 2 });
	const nfDec1 = new Intl.NumberFormat(locale, { maximumFractionDigits: 1 });
	const nfPct = new Intl.NumberFormat(locale, { style: 'percent', maximumFractionDigits: 1 });
	const nfPct0 = new Intl.NumberFormat(locale, { style: 'percent', maximumFractionDigits: 0 });
	const pad = (n) => String(n).padStart(2, '0');

	const fmt = {
		number: (v) => (Math.abs(v) >= 10000 ? nfCompact.format(v) : nf.format(v || 0)),
		full: (v) => nf.format(v || 0),
		decimal: (v) => nfDec.format(v || 0),
		percent: (v) => nfPct.format(v || 0),
		percent0: (v) => nfPct0.format(v || 0),
		duration: (s) => {
			s = Math.round(s || 0);
			if (s < 60) {
				/* translators: %s: seconds */
				return sprintf(__('%ss', 'nemvo-analytics'), s);
			}
			if (s < 3600) {
				/* translators: 1: minutes, 2: seconds */
				return sprintf(__('%1$sm %2$ss', 'nemvo-analytics'), Math.floor(s / 60), pad(s % 60));
			}
			/* translators: 1: hours, 2: minutes */
			return sprintf(__('%1$sh %2$sm', 'nemvo-analytics'), Math.floor(s / 3600), pad(Math.floor((s % 3600) / 60)));
		},
	};

	/* ------------------------------------------------------------------ *
	 * Dates. Days are YYYY-MM-DD strings in the site's time zone; they are
	 * parsed as UTC and formatted in UTC so nothing shifts.
	 * ------------------------------------------------------------------ */

	const DAY_MS = 86400000;
	const parseDay = (s) => new Date(s.slice(0, 10) + 'T' + (s.length > 10 ? s.slice(11, 16) : '00:00') + ':00Z');
	const isoDay = (date) => date.toISOString().slice(0, 10);
	const addDays = (s, n) => isoDay(new Date(parseDay(s).getTime() + n * DAY_MS));
	const daysBetween = (a, b) => Math.round((parseDay(b) - parseDay(a)) / DAY_MS) + 1;
	const df = (opts) => new Intl.DateTimeFormat(locale, Object.assign({ timeZone: 'UTC' }, opts));
	const fDay = df({ month: 'short', day: 'numeric' });
	const fDayYear = df({ month: 'short', day: 'numeric', year: 'numeric' });
	const fWeekday = df({ weekday: 'short', month: 'short', day: 'numeric' });
	const fMonth = df({ month: 'long', year: 'numeric' });
	const fMonthShort = df({ month: 'short' });
	const fTime = df({ hour: '2-digit', minute: '2-digit' });
	const fTimeSec = df({ hour: '2-digit', minute: '2-digit', second: '2-digit' });
	const fHour = df({ hour: 'numeric' });
	const fWeekdayLong = df({ weekday: 'long' });
	const fWeekdayShort = df({ weekday: 'short' });
	const dayName = (js, long) => (long ? fWeekdayLong : fWeekdayShort).format(new Date(Date.UTC(2023, 0, 1 + js)));
	const hourName = (hr) => fHour.format(new Date(Date.UTC(2023, 0, 1, hr)));
	const hourRange = (hr) => fTime.format(new Date(Date.UTC(2023, 0, 1, hr))) + '–' + fTime.format(new Date(Date.UTC(2023, 0, 1, (hr + 1) % 24)));

	function rangeText(from, to) {
		const thisYear = cfg.today.slice(0, 4);
		const f = from.slice(0, 4) === thisYear && to.slice(0, 4) === thisYear ? fDay : fDayYear;
		if (from === to) {
			return f.format(parseDay(from));
		}
		return f.formatRange ? f.formatRange(parseDay(from), parseDay(to)) : f.format(parseDay(from)) + ' – ' + f.format(parseDay(to));
	}

	function bucketLabel(key, interval, long) {
		const date = parseDay(key);
		switch (interval) {
			case 'hour':
				return long ? fWeekday.format(date) + ', ' + fTime.format(date) : fTime.format(date);
			case 'week':
				/* translators: %s: first day of a week */
				return long ? sprintf(__('Week of %s', 'nemvo-analytics'), fDay.format(date)) : fDay.format(date);
			case 'month':
				return long ? fMonth.format(date) : fMonthShort.format(date);
			default:
				return long ? fWeekday.format(date) : fDay.format(date);
		}
	}

	const RANGES = {
		today: __('Today', 'nemvo-analytics'),
		yesterday: __('Yesterday', 'nemvo-analytics'),
		'7d': __('Last 7 days', 'nemvo-analytics'),
		'30d': __('Last 30 days', 'nemvo-analytics'),
		'90d': __('Last 90 days', 'nemvo-analytics'),
		mtd: __('This month', 'nemvo-analytics'),
		lastmonth: __('Last month', 'nemvo-analytics'),
		ytd: __('This year', 'nemvo-analytics'),
		'12mo': __('Last 12 months', 'nemvo-analytics'),
	};

	function rangeDates(key) {
		const t = cfg.today;
		const month = t.slice(0, 7);
		switch (key) {
			case 'today':
				return [t, t];
			case 'yesterday':
				return [addDays(t, -1), addDays(t, -1)];
			case '7d':
				return [addDays(t, -6), t];
			case '90d':
				return [addDays(t, -89), t];
			case 'mtd':
				return [month + '-01', t];
			case 'lastmonth': {
				const end = addDays(month + '-01', -1);
				return [end.slice(0, 7) + '-01', end];
			}
			case 'ytd':
				return [t.slice(0, 4) + '-01-01', t];
			case '12mo':
				return [addDays(t, -364), t];
			case 'custom':
				return [state.from, state.to];
			default:
				return [addDays(t, -29), t];
		}
	}

	/* ------------------------------------------------------------------ *
	 * DOM helpers
	 * ------------------------------------------------------------------ */

	function h(tag, props, ...children) {
		const el = document.createElement(tag);
		for (const [k, v] of Object.entries(props || {})) {
			if (v === null || v === undefined || v === false) {
				continue;
			}
			if (k === 'class') {
				el.className = v;
			} else if (k === 'text') {
				el.textContent = v;
			} else if (k === 'style') {
				for (const [sk, sv] of Object.entries(v)) {
					el.style.setProperty(sk, sv);
				}
			} else if (k.startsWith('on')) {
				el.addEventListener(k.slice(2), v);
			} else {
				el.setAttribute(k, v === true ? '' : v);
			}
		}
		children.flat(Infinity).forEach((c) => {
			if (c !== null && c !== undefined && c !== false) {
				el.append(c instanceof Node ? c : String(c));
			}
		});
		return el;
	}

	/** replaceChildren() that skips empty children (it would insert the text "null"). */
	function put(el, ...children) {
		el.replaceChildren(...children.flat(Infinity).filter((c) => c !== null && c !== undefined && c !== false));
	}

	const ICONS = {
		left: '<path d="M10 3 5 8l5 5"/>',
		right: '<path d="m6 3 5 5-5 5"/>',
		down: '<path d="m4 6 4 4 4-4"/>',
		check: '<path d="m3.5 8.5 3 3 6-7"/>',
		x: '<path d="m4 4 8 8M12 4l-8 8"/>',
		external: '<path d="M9.5 2.5h4v4M13.5 2.5 7 9M11.5 9.5v4h-9v-9h4"/>',
		route: '<circle cx="3.5" cy="12.5" r="1.5"/><circle cx="12.5" cy="3.5" r="1.5"/><path d="M5 12.5h3.5a2 2 0 0 0 2-2v-5a2 2 0 0 1 .5-1.3"/>',
		download: '<path d="M8 2.5v8M4.5 7 8 10.5 11.5 7M3 13.5h10"/>',
		table: '<rect x="2.5" y="3" width="11" height="10" rx="1.5"/><path d="M2.5 6.5h11M6.5 6.5V13"/>',
		chart: '<path d="m2.5 12 3.5-4 3 2.5 4.5-6"/>',
		filter: '<path d="M2.5 3.5h11L9.5 8.5v4l-3 1.5v-5.5z"/>',
		sun: '<circle cx="8" cy="8" r="3"/><path d="M8 1.5v1.5M8 13v1.5M1.5 8H3M13 8h1.5M3.4 3.4l1 1M11.6 11.6l1 1M3.4 12.6l1-1M11.6 4.4l1-1"/>',
		moon: '<path d="M13.5 9.5A5.5 5.5 0 1 1 6.5 2.5a4.5 4.5 0 0 0 7 7z"/>',
		auto: '<circle cx="8" cy="8" r="5.5"/><path d="M8 2.5v11a5.5 5.5 0 0 0 0-11z" fill="currentColor" stroke="none"/>',
		up: '<path d="M8 4.5 12 10H4z" fill="currentColor" stroke="none"/>',
		dn: '<path d="M8 11.5 4 6h8z" fill="currentColor" stroke="none"/>',
		eye: '<path d="M1.5 8S4 3.5 8 3.5 14.5 8 14.5 8 12 12.5 8 12.5 1.5 8 1.5 8z"/><circle cx="8" cy="8" r="2"/>',
		bolt: '<path d="M9 1.5 3.5 9H8l-1 5.5L12.5 7H8z"/>',
		robot: '<rect x="3" y="5.5" width="10" height="8" rx="2"/><path d="M8 5.5V3M6 9.5h.01M10 9.5h.01M1.5 9v2M14.5 9v2"/>',
		building: '<path d="M3 14V3.5l5-1.5v12M8 6h5v8M5.5 5.5v.01M5.5 8v.01M5.5 10.5v.01M10.5 8.5v.01M10.5 11v.01M2 14h12"/>',
		goal: '<circle cx="8" cy="8" r="5.5"/><circle cx="8" cy="8" r="2.5"/>',
	};

	function icon(name) {
		const span = document.createElement('span');
		span.className = 'nva-icon';
		span.setAttribute('aria-hidden', 'true');
		// Static markup from ICONS only.
		span.innerHTML = '<svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' + ICONS[name] + '</svg>';
		return span;
	}

	const store = {
		get(key, fallback) {
			try {
				const v = localStorage.getItem('nva-' + key);
				return v === null ? fallback : JSON.parse(v);
			} catch (e) {
				return fallback;
			}
		},
		set(key, value) {
			try {
				localStorage.setItem('nva-' + key, JSON.stringify(value));
			} catch (e) {}
		},
	};

	/* ------------------------------------------------------------------ *
	 * Labels
	 * ------------------------------------------------------------------ */

	const DIMS = {
		channel: __('Channel', 'nemvo-analytics'),
		source: __('Source', 'nemvo-analytics'),
		referrer: __('Referrer', 'nemvo-analytics'),
		utm_source: __('UTM source', 'nemvo-analytics'),
		utm_medium: __('UTM medium', 'nemvo-analytics'),
		utm_campaign: __('UTM campaign', 'nemvo-analytics'),
		utm_term: __('UTM term', 'nemvo-analytics'),
		utm_content: __('UTM content', 'nemvo-analytics'),
		page: __('Page', 'nemvo-analytics'),
		entry_page: __('Entry page', 'nemvo-analytics'),
		exit_page: __('Exit page', 'nemvo-analytics'),
		content_type: __('Content type', 'nemvo-analytics'),
		country: __('Country', 'nemvo-analytics'),
		region: __('Region', 'nemvo-analytics'),
		city: __('City', 'nemvo-analytics'),
		language: __('Language', 'nemvo-analytics'),
		company: __('Company', 'nemvo-analytics'),
		network: __('Network type', 'nemvo-analytics'),
		org: __('Network owner', 'nemvo-analytics'),
		device: __('Device', 'nemvo-analytics'),
		os: __('Operating system', 'nemvo-analytics'),
		browser: __('Browser', 'nemvo-analytics'),
		screen: __('Screen size', 'nemvo-analytics'),
		weekday: __('Weekday', 'nemvo-analytics'),
		hour: __('Hour', 'nemvo-analytics'),
		goal: __('Goal', 'nemvo-analytics'),
		event: __('Event', 'nemvo-analytics'),
		bot_flag: __('Bot signal', 'nemvo-analytics'),
		visitor: __('Visitor', 'nemvo-analytics'),
	};

	const DIM_GROUPS = [
		[__('Acquisition', 'nemvo-analytics'), ['channel', 'source', 'referrer', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content']],
		[__('Content', 'nemvo-analytics'), ['page', 'entry_page', 'exit_page', 'content_type']],
		[__('Location', 'nemvo-analytics'), ['country', 'region', 'city', 'language']],
		[__('Organisation', 'nemvo-analytics'), ['company', 'network', 'org']],
		[__('Technology', 'nemvo-analytics'), ['device', 'os', 'browser', 'screen']],
		[__('Time', 'nemvo-analytics'), ['weekday', 'hour']],
		[__('Conversions', 'nemvo-analytics'), ['goal', 'event']],
		[__('Automated traffic', 'nemvo-analytics'), ['bot_flag']],
	];

	const OPS = {
		is: __('is', 'nemvo-analytics'),
		is_not: __('is not', 'nemvo-analytics'),
		contains: __('contains', 'nemvo-analytics'),
	};

	const CHANNELS = {
		'Organic Search': __('Organic search', 'nemvo-analytics'),
		Direct: __('Direct', 'nemvo-analytics'),
		'Organic Social': __('Organic social', 'nemvo-analytics'),
		Referral: __('Referral', 'nemvo-analytics'),
		'AI Assistants': __('AI assistants', 'nemvo-analytics'),
		Email: __('Email', 'nemvo-analytics'),
		'Paid Search': __('Paid search', 'nemvo-analytics'),
		'Paid Social': __('Paid social', 'nemvo-analytics'),
		Display: __('Display', 'nemvo-analytics'),
		Affiliates: __('Affiliates', 'nemvo-analytics'),
		'Other Campaigns': __('Other campaigns', 'nemvo-analytics'),
	};

	const DEVICES = {
		desktop: __('Desktop', 'nemvo-analytics'),
		mobile: __('Mobile', 'nemvo-analytics'),
		tablet: __('Tablet', 'nemvo-analytics'),
	};

	const SCREENS = {
		xs: __('Phone (under 576 px)', 'nemvo-analytics'),
		sm: __('Large phone (576–767 px)', 'nemvo-analytics'),
		md: __('Tablet (768–1023 px)', 'nemvo-analytics'),
		lg: __('Laptop (1024–1439 px)', 'nemvo-analytics'),
		xl: __('Desktop (1440–1919 px)', 'nemvo-analytics'),
		xxl: __('Large desktop (1920 px and up)', 'nemvo-analytics'),
	};

	const TYPES = {
		home: __('Home page', 'nemvo-analytics'),
		page: __('Page', 'nemvo-analytics'),
		post: __('Post', 'nemvo-analytics'),
		'404': __('Not found (404)', 'nemvo-analytics'),
		search: __('Search results', 'nemvo-analytics'),
		taxonomy: __('Category or tag', 'nemvo-analytics'),
		author: __('Author archive', 'nemvo-analytics'),
		archive: __('Archive', 'nemvo-analytics'),
		other: __('Other', 'nemvo-analytics'),
	};

	const NETWORKS = cfg.networks || {};
	const SIGNALS = cfg.botSignals || {};
	const UNKNOWN = __('Unknown', 'nemvo-analytics');
	let regionNames = null;
	let languageNames = null;
	try {
		regionNames = new Intl.DisplayNames([locale, 'en'], { type: 'region' });
		languageNames = new Intl.DisplayNames([locale, 'en'], { type: 'language' });
	} catch (e) {}

	function countryName(code) {
		if (!code) {
			return UNKNOWN;
		}
		try {
			return regionNames ? regionNames.of(code) : cfg.countries[code] || code;
		} catch (e) {
			return cfg.countries[code] || code;
		}
	}

	/** Country code badge. Not an emoji flag: those render as letters on Windows, and WordPress swaps them for images from its CDN. */
	function flag(code) {
		return code && /^[A-Z]{2}$/.test(code) ? code : '';
	}

	/** Display text (and optional flag/title) for a stored dimension value. */
	function display(dim, value) {
		const v = value === null || value === undefined ? '' : String(value);
		switch (dim) {
			case 'country':
				return { text: countryName(v), flag: flag(v) };
			case 'region':
			case 'city': {
				const [cc, name] = v.split('|');
				return { text: name || UNKNOWN, flag: flag(cc), title: (name || UNKNOWN) + ', ' + countryName(cc) };
			}
			case 'language': {
				let text = v || UNKNOWN;
				try {
					text = v && languageNames ? languageNames.of(v) : text;
				} catch (e) {}
				return { text };
			}
			case 'device':
				return { text: DEVICES[v] || v || UNKNOWN };
			case 'screen':
				return { text: SCREENS[v] || v || UNKNOWN };
			case 'channel':
				return { text: CHANNELS[v] || v || UNKNOWN };
			case 'content_type':
				return { text: TYPES[v] || v || UNKNOWN };
			case 'network':
				return { text: NETWORKS[v] || v || UNKNOWN };
			case 'weekday':
				return { text: v === '' ? UNKNOWN : dayName((Number(v) + 1) % 7, true) };
			case 'hour':
				return { text: v === '' ? UNKNOWN : hourRange(Number(v)) };
			case 'bot_flag':
				return { text: SIGNALS[v] ? SIGNALS[v][0] : v };
			case 'visitor':
				/* translators: %s: short anonymous visitor id */
				return { text: sprintf(__('Visitor %s', 'nemvo-analytics'), v.slice(0, 6)) };
			case 'goal': {
				const goal = cfg.goals.find((g) => String(g.id) === v);
				return { text: goal ? goal.name : v };
			}
			default:
				return { text: v === '' ? __('(none)', 'nemvo-analytics') : v };
		}
	}

	function labelNode(info) {
		return [info.flag ? h('span', { class: 'nva-flag', 'aria-hidden': 'true', text: info.flag }) : null, h('span', { class: 'nva-ellipsis', text: info.text })];
	}

	const siteOrigin = new URL(cfg.home).origin;
	const siteUrl = (path) => siteOrigin + path;

	/* ------------------------------------------------------------------ *
	 * Data: reports are batched into one request per refresh.
	 * ------------------------------------------------------------------ */

	const cache = new Map();
	let queue = [];
	let queued = 0;

	function report(route, params, fresh) {
		const clean = {};
		Object.keys(params || {})
			.sort()
			.forEach((k) => {
				if (params[k] !== undefined && params[k] !== null && params[k] !== '') {
					clean[k] = String(params[k]);
				}
			});
		const key = route + '?' + new URLSearchParams(clean).toString();
		const hit = cache.get(key);
		if (!fresh && hit && Date.now() - hit.t < 60000) {
			return hit.p;
		}
		const p = new Promise((resolve, reject) => queue.push({ route, params: clean, resolve, reject }));
		cache.set(key, { t: Date.now(), p });
		p.catch(() => cache.delete(key));
		if (!queued) {
			queued = setTimeout(sendQueue, 0);
		}
		return p;
	}

	function sendQueue() {
		const items = queue;
		queue = [];
		queued = 0;
		for (let i = 0; i < items.length; i += 25) {
			const chunk = items.slice(i, i + 25);
			fetch(cfg.root.replace(/\/$/, '') + '/stats/batch', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
				body: JSON.stringify({ requests: chunk.map((c) => ({ route: c.route, params: c.params })) }),
			})
				.then(async (res) => {
					const data = await res.json().catch(() => null);
					if (!res.ok || !Array.isArray(data)) {
						throw new Error((data && data.message) || res.statusText || __('The request failed.', 'nemvo-analytics'));
					}
					data.forEach((r, j) => (200 === r.status ? chunk[j].resolve(r.data) : chunk[j].reject(new Error(r.error))));
				})
				.catch((err) => chunk.forEach((c) => c.reject(err)));
		}
	}

	/* ------------------------------------------------------------------ *
	 * Tooltip, popovers, dialogs
	 * ------------------------------------------------------------------ */

	const tip = h('div', { class: 'nva-tip', role: 'tooltip', hidden: true });
	document.body.append(tip);

	function showTip(content, x, y) {
		const host = document.querySelector('dialog[open]') || document.body;
		if (tip.parentNode !== host) {
			host.append(tip);
		}
		put(tip, ...[].concat(content).filter(Boolean));
		tip.hidden = false;
		const r = tip.getBoundingClientRect();
		let left = x + 14;
		let top = y + 14;
		if (left + r.width > window.innerWidth - 8) {
			left = x - r.width - 14;
		}
		if (top + r.height > window.innerHeight - 8) {
			top = y - r.height - 14;
		}
		tip.style.left = Math.max(8, left) + 'px';
		tip.style.top = Math.max(8, top) + 'px';
	}

	function hideTip() {
		tip.hidden = true;
	}

	function tipTitle(text) {
		return h('div', { class: 'nva-tip__title', text });
	}

	function tipRow(key, value, label) {
		return h('div', { class: 'nva-tip__row' }, key ? h('span', { class: 'nva-key ' + key }) : null, h('strong', { text: value }), label ? h('span', { text: label }) : null);
	}

	function tipHint(text) {
		return h('div', { class: 'nva-tip__hint', text });
	}

	let openPop = null;

	function popover(anchor, content, align) {
		closePopover();
		const pop = h('div', { class: 'nva-pop' }, content);
		document.body.append(pop);
		const r = anchor.getBoundingClientRect();
		const width = pop.offsetWidth;
		let left = align === 'right' ? r.right - width : r.left;
		left = Math.max(8, Math.min(left, document.documentElement.clientWidth - width - 8));
		pop.style.left = left + window.scrollX + 'px';
		pop.style.top = r.bottom + window.scrollY + 6 + 'px';
		anchor.setAttribute('aria-expanded', 'true');

		const onDown = (e) => {
			if (!pop.contains(e.target) && !anchor.contains(e.target)) {
				closePopover();
			}
		};
		const onKey = (e) => {
			if (e.key === 'Escape') {
				closePopover();
				anchor.focus();
			}
		};
		setTimeout(() => document.addEventListener('pointerdown', onDown));
		document.addEventListener('keydown', onKey);
		openPop = {
			close() {
				pop.remove();
				anchor.setAttribute('aria-expanded', 'false');
				document.removeEventListener('pointerdown', onDown);
				document.removeEventListener('keydown', onKey);
			},
		};
		const first = pop.querySelector('[aria-checked="true"], input, select, button');
		if (first) {
			first.focus();
		}
		return pop;
	}

	function closePopover() {
		if (openPop) {
			const p = openPop;
			openPop = null;
			p.close();
		}
	}

	function dialog(title, wide) {
		const titleEl = h('h2', { text: title });
		const body = h('div', { class: 'nva-dialog__body' });
		const dlg = h(
			'dialog',
			{ class: 'nva-dialog' + (wide ? ' nva-dialog--wide' : '') },
			h(
				'div',
				{ class: 'nva-dialog__inner' },
				h('header', { class: 'nva-dialog__head' }, titleEl, h('button', { type: 'button', class: 'nva-icon-btn', 'aria-label': __('Close', 'nemvo-analytics'), onclick: () => dlg.close() }, icon('x'))),
				body
			)
		);
		dlg.addEventListener('close', () => {
			hideTip();
			document.body.append(tip);
			dlg.remove();
		});
		dlg.addEventListener('click', (e) => {
			if (e.target === dlg) {
				dlg.close();
			}
		});
		document.body.append(dlg);
		dlg.showModal();
		return { dlg, body, titleEl };
	}

	function errorNote(err) {
		return h('p', { class: 'nva-error', text: err && err.message ? err.message : String(err) });
	}

	function emptyNote(text) {
		return h('p', { class: 'nva-empty', text: text || __('No data for this period.', 'nemvo-analytics') });
	}

	/* ------------------------------------------------------------------ *
	 * Theme and Chart.js defaults
	 * ------------------------------------------------------------------ */

	const THEMES = {
		auto: [__('Theme: automatic', 'nemvo-analytics'), 'auto'],
		light: [__('Theme: light', 'nemvo-analytics'), 'sun'],
		dark: [__('Theme: dark', 'nemvo-analytics'), 'moon'],
	};
	let theme = store.get('theme', 'auto');
	const redraws = new Set();

	function applyTheme() {
		if ('auto' === theme) {
			document.body.removeAttribute('data-nva-theme');
		} else {
			document.body.setAttribute('data-nva-theme', theme);
		}
		redraws.forEach((fn) => fn());
	}

	function themeButton() {
		const button = h('button', { type: 'button', class: 'nva-icon-btn' });
		const paint = () => {
			put(button, icon(THEMES[theme][1]));
			button.setAttribute('aria-label', THEMES[theme][0]);
			button.title = THEMES[theme][0];
		};
		button.addEventListener('click', () => {
			const keys = Object.keys(THEMES);
			theme = keys[(keys.indexOf(theme) + 1) % keys.length];
			store.set('theme', theme);
			applyTheme();
			paint();
		});
		paint();
		return button;
	}

	if (window.matchMedia) {
		window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => 'auto' === theme && redraws.forEach((fn) => fn()));
	}

	/** Colors from the CSS tokens, so charts follow light and dark mode. */
	function tokens() {
		const cs = getComputedStyle(document.body);
		const v = (n) => cs.getPropertyValue('--nva-' + n).trim();
		return {
			surface: v('surface'),
			ink: v('ink'),
			muted: v('muted'),
			grid: v('grid'),
			axis: v('axis'),
			c1: v('c1'),
			c2: v('c2'),
			c3: v('c3'),
			compare: v('compare'),
			wash: v('wash'),
			seq: range(0, 7).map((i) => v('seq-' + i)),
		};
	}

	const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	Chart.defaults.font.family = getComputedStyle(document.body).getPropertyValue('--nva-font') || 'system-ui, sans-serif';
	Chart.defaults.font.size = 11;
	Chart.defaults.maintainAspectRatio = false;
	Chart.defaults.animation.duration = reducedMotion ? 0 : 250;
	Chart.defaults.plugins.legend.display = false;
	Chart.defaults.plugins.tooltip.enabled = false;

	/** Chart.js tooltip rendered as the shared HTML tooltip. */
	function externalTip(build) {
		return (context) => {
			const t = context.tooltip;
			if (!t || t.opacity === 0 || !t.dataPoints || !t.dataPoints.length) {
				hideTip();
				return;
			}
			const rect = context.chart.canvas.getBoundingClientRect();
			const content = build(t.dataPoints, context.chart);
			if (content) {
				showTip(content, rect.left + t.caretX, rect.top + t.caretY);
			} else {
				hideTip();
			}
		};
	}

	/** A vertical hairline at the hovered index (drawn under the lines). */
	const crosshair = {
		id: 'nvaCrosshair',
		beforeDatasetsDraw(chart) {
			const active = chart.tooltip && chart.tooltip.getActiveElements();
			if (!active || !active.length) {
				return;
			}
			const x = Math.round(active[0].element.x) + 0.5;
			const { top, bottom } = chart.chartArea;
			const ctx = chart.ctx;
			ctx.save();
			ctx.strokeStyle = tokens().axis;
			ctx.lineWidth = 1;
			ctx.beginPath();
			ctx.moveTo(x, top);
			ctx.lineTo(x, bottom);
			ctx.stroke();
			ctx.restore();
		},
	};

	/** Arrow keys move the tooltip through the points; Enter acts like a click. */
	function keyboard(chart, count, onEnter) {
		const canvas = chart.canvas;
		let at = -1;
		canvas.tabIndex = 0;
		const focusPoint = (i) => {
			at = Math.max(0, Math.min(count() - 1, i));
			const els = chart.data.datasets.map((d, di) => ({ datasetIndex: di, index: at })).filter((e) => chart.getDatasetMeta(e.datasetIndex).data[at]);
			chart.setActiveElements(els);
			chart.tooltip.setActiveElements(els, { x: 0, y: 0 });
			chart.update('none');
		};
		canvas.addEventListener('keydown', (e) => {
			if ('ArrowRight' === e.key || 'ArrowDown' === e.key) {
				e.preventDefault();
				focusPoint(at + 1);
			} else if ('ArrowLeft' === e.key || 'ArrowUp' === e.key) {
				e.preventDefault();
				focusPoint(at < 0 ? count() - 1 : at - 1);
			} else if ('Enter' === e.key && at >= 0 && onEnter) {
				onEnter(at);
			}
		});
		canvas.addEventListener('focus', () => focusPoint(at < 0 ? count() - 1 : at));
		canvas.addEventListener('blur', () => {
			chart.setActiveElements([]);
			chart.tooltip.setActiveElements([], { x: 0, y: 0 });
			chart.update('none');
			hideTip();
		});
	}

	/** Replace the chart in a container (keeps the box size, so nothing jumps). */
	function mountChart(box, label, config) {
		if (box._chart) {
			box._chart.destroy();
		}
		const canvas = h('canvas', { role: 'img', 'aria-label': label });
		put(box, canvas);
		box._chart = new Chart(canvas, config);
		canvas.addEventListener('mouseleave', hideTip);
		return box._chart;
	}

	/* ------------------------------------------------------------------ *
	 * Shell shared by both views
	 * ------------------------------------------------------------------ */

	const liveText = h('span', { text: '' });

	function header(title) {
		const view = cfg.view;
		const link = (href, text, current) => h('a', { href, 'aria-current': current ? 'page' : null, text });
		return h(
			'header',
			{ class: 'nva-top' },
			h('div', { class: 'nva-top__title' }, h('span', { class: 'nva-top__site', text: cfg.siteName }), h('h1', { text: title })),
			h('a', { class: 'nva-live', href: cfg.realtimeUrl, title: __('People active in the last 5 minutes', 'nemvo-analytics') }, h('span', { class: 'nva-live__dot', 'aria-hidden': 'true' }), liveText),
			h(
				'nav',
				{ class: 'nva-top__nav', 'aria-label': __('Analytics', 'nemvo-analytics') },
				link(cfg.dashboardUrl, __('Dashboard', 'nemvo-analytics'), 'dashboard' === view),
				link(cfg.realtimeUrl, __('Realtime', 'nemvo-analytics'), 'realtime' === view),
				cfg.canManage ? link(cfg.settingsUrl, __('Settings', 'nemvo-analytics'), false) : null
			),
			themeButton()
		);
	}

	function setLive(active) {
		/* translators: %s: number of visitors */
		liveText.textContent = sprintf(_n('%s visitor now', '%s visitors now', active, 'nemvo-analytics'), fmt.full(active));
	}

	function footer() {
		const where = cfg.db.external ? __('a separate database', 'nemvo-analytics') : __('the WordPress database', 'nemvo-analytics');
		return h(
			'footer',
			{ class: 'nva-foot' },
			/* translators: %s: where statistics are stored */
			h('span', { text: sprintf(__('Cookieless, self-hosted analytics. Statistics are stored in %s and never leave your server.', 'nemvo-analytics'), where) }),
			cfg.geoNotice ? h('a', { href: 'https://db-ip.com', target: '_blank', rel: 'noopener', text: __('IP geolocation by DB-IP', 'nemvo-analytics') }) : null
		);
	}

	/* ------------------------------------------------------------------ *
	 * Ranked lists
	 * ------------------------------------------------------------------ */

	/**
	 * A ranked list with a bar behind the label.
	 *
	 * opts: rows, dim, cols [[key, label, format]], bar (key), head, onRow(row), actions(row), label(row), clickable(row), empty
	 */
	function rankList(opts) {
		const rows = opts.rows;
		if (!rows.length) {
			return emptyNote(opts.empty);
		}
		const top = max(rows, (r) => r[opts.bar]) || 1;
		const list = h(
			'div',
			{ class: 'nva-rows', role: 'table', style: { '--cols': opts.cols.length } },
			h('div', { class: 'nva-rows__head', role: 'row' }, h('span', { role: 'columnheader', text: opts.head }), opts.cols.map((c) => h('span', { role: 'columnheader', class: 'nva-num', text: c[1] })))
		);
		rows.forEach((r) => {
			const info = opts.label ? opts.label(r) : display(opts.dim, r.label);
			const clickable = opts.onRow && (!opts.clickable || opts.clickable(r));
			const label = clickable
				? h('button', { type: 'button', class: 'nva-row__label', title: info.title || info.text, onclick: () => opts.onRow(r) }, labelNode(info))
				: h('span', { class: 'nva-row__label is-static', title: info.title || info.text }, labelNode(info));
			list.append(
				h(
					'div',
					{ class: 'nva-row', role: 'row', style: { '--w': ((Math.max(0, r[opts.bar]) / top) * 100).toFixed(2) + '%' } },
					h('span', { class: 'nva-row__cell', role: 'cell' }, label, opts.actions ? opts.actions(r) : null),
					opts.cols.map((c) => h('span', { class: 'nva-num', role: 'cell', text: c[2](r[c[0]], r) }))
				)
			);
		});
		return list;
	}

	/* ================================================================== *
	 * Dashboard view
	 * ================================================================== */

	const METRICS = {
		visitors: { label: __('Visitors', 'nemvo-analytics'), format: fmt.number, chart: true },
		visits: { label: __('Visits', 'nemvo-analytics'), format: fmt.number, chart: true },
		pageviews: { label: __('Pageviews', 'nemvo-analytics'), format: fmt.number, chart: true },
		views_per_visit: { label: __('Views per visit', 'nemvo-analytics'), format: fmt.decimal, chart: true },
		avg_duration: { label: __('Visit duration', 'nemvo-analytics'), format: fmt.duration, chart: true },
		bounce_rate: { label: __('Bounce rate', 'nemvo-analytics'), format: fmt.percent, chart: true, lowerIsBetter: true, points: true },
		conversions: { label: __('Conversions', 'nemvo-analytics'), format: fmt.number, chart: true },
		conversion_rate: { label: __('Conversion rate', 'nemvo-analytics'), format: fmt.percent, chart: true, points: true },
		avg_time: { label: __('Time on page', 'nemvo-analytics'), format: fmt.duration },
	};
	const TILES = ['visitors', 'visits', 'pageviews', 'views_per_visit', 'avg_duration', 'bounce_rate', 'conversions', 'avg_time'];

	const COLS = {
		visitors: [__('Visitors', 'nemvo-analytics'), (v) => fmt.number(v)],
		visits: [__('Visits', 'nemvo-analytics'), (v) => fmt.number(v)],
		pageviews: [__('Views', 'nemvo-analytics'), (v) => fmt.number(v)],
		bounce_rate: [__('Bounce', 'nemvo-analytics'), (v) => fmt.percent0(v)],
		avg_duration: [__('Duration', 'nemvo-analytics'), (v) => fmt.duration(v)],
		conversions: [__('Conv.', 'nemvo-analytics'), (v) => fmt.number(v)],
		cr: [__('Conv. rate', 'nemvo-analytics'), (v) => fmt.percent(v)],
		avg_time: [__('Time', 'nemvo-analytics'), (v) => (v ? fmt.duration(v) : '–')],
		scroll: [__('Scroll', 'nemvo-analytics'), (v) => (v ? fmt.percent0(v / 100) : '–')],
		entries: [__('Entrances', 'nemvo-analytics'), (v) => fmt.number(v)],
		exits: [__('Exits', 'nemvo-analytics'), (v) => fmt.number(v)],
		exit_rate: [__('Exit rate', 'nemvo-analytics'), (v) => fmt.percent0(v)],
		events: [__('Events', 'nemvo-analytics'), (v) => fmt.number(v)],
		share: [__('Share', 'nemvo-analytics'), (v) => fmt.percent(v)],
	};
	const col = (key) => [key, COLS[key][0], COLS[key][1]];

	const TRAFFIC = {
		human: __('People', 'nemvo-analytics'),
		bot: __('Automated', 'nemvo-analytics'),
		all: __('All traffic', 'nemvo-analytics'),
	};

	const state = { range: '30d', from: '', to: '', compare: 'previous', metric: 'visitors', interval: '', traffic: 'human', filters: [] };
	const cur = { from: '', to: '' };
	let overview = null;
	let overviewPromise = Promise.resolve(null);
	let overviewGen = 0;
	let lastKey = '';

	function readHash() {
		const p = new URLSearchParams(location.hash.slice(1));
		const re = /^\d{4}-\d{2}-\d{2}$/;
		state.from = re.test(p.get('from') || '') ? p.get('from') : '';
		state.to = re.test(p.get('to') || '') ? p.get('to') : '';
		const range = p.get('range');
		state.range = RANGES[range] ? range : state.from && state.to ? 'custom' : '30d';
		state.compare = ['previous', 'year', 'none'].includes(p.get('compare')) ? p.get('compare') : 'previous';
		const metric = p.get('metric');
		state.metric = METRICS[metric] && METRICS[metric].chart ? metric : 'visitors';
		state.interval = ['hour', 'day', 'week', 'month'].includes(p.get('interval')) ? p.get('interval') : '';
		state.traffic = TRAFFIC[p.get('traffic')] ? p.get('traffic') : 'human';
		let filters = [];
		try {
			filters = JSON.parse(p.get('f') || '[]');
		} catch (e) {}
		state.filters = (Array.isArray(filters) ? filters : [])
			.filter((f) => f && DIMS[f.d] && (OPS[f.o] || !f.o) && f.v !== undefined)
			.map((f) => ({ d: f.d, o: f.o || 'is', v: String(f.v) }))
			.slice(0, 10);
	}

	function hashFor(s) {
		const p = new URLSearchParams();
		if (s.range === 'custom') {
			p.set('from', s.from);
			p.set('to', s.to);
		} else {
			p.set('range', s.range);
		}
		if (s.compare !== 'previous') {
			p.set('compare', s.compare);
		}
		if (s.metric !== 'visitors') {
			p.set('metric', s.metric);
		}
		if (s.interval) {
			p.set('interval', s.interval);
		}
		if (s.traffic !== 'human') {
			p.set('traffic', s.traffic);
		}
		if (s.filters.length) {
			p.set('f', JSON.stringify(s.filters));
		}
		return p.toString();
	}

	function navigate(patch, soft) {
		Object.assign(state, patch);
		const hash = hashFor(state);
		if (hash !== location.hash.slice(1)) {
			history[soft ? 'replaceState' : 'pushState'](null, '', '#' + hash);
		}
		if (soft) {
			renderTiles();
			renderChart();
		} else {
			refresh();
		}
	}

	function params(extra) {
		const out = { from: cur.from, to: cur.to, traffic: state.traffic };
		if (state.filters.length) {
			out.filters = JSON.stringify(state.filters);
		}
		return Object.assign(out, extra || {});
	}

	/** Add filters (a drill-down replaces an "is" filter on the same dimension). */
	function addFilter(...filters) {
		closePopover();
		let next = state.filters.slice();
		filters.forEach((filter) => {
			next = next.filter((f) => !(f.d === filter.d && 'is' === filter.o));
			next.push(Object.assign({ o: 'is' }, filter));
		});
		navigate({ filters: next });
		window.scrollTo({ top: 0, behavior: reducedMotion ? 'auto' : 'smooth' });
	}

	function intervalsFor(days) {
		return {
			hour: days <= 14,
			day: days <= 400,
			week: days >= 14,
			month: days >= 60,
		};
	}

	/* ---------------- Layout ---------------- */

	const ui = {};

	function dashboardView() {
		ui.notices = h('div', { class: 'nva-notices' });
		ui.toolbar = h('div', { class: 'nva-toolbar', role: 'toolbar', 'aria-label': __('Date range and filters', 'nemvo-analytics') });
		ui.tiles = h('section', { class: 'nva-tiles', 'aria-label': __('Key metrics', 'nemvo-analytics') });
		ui.chart = chartCard();
		ui.panels = [
			makePanel({
				id: 'sources',
				title: __('Traffic sources', 'nemvo-analytics'),
				tabs: [
					{ key: 'channel', label: __('Channels', 'nemvo-analytics'), dim: 'channel', cols: ['visitors', 'cr'] },
					{ key: 'source', label: __('Sources', 'nemvo-analytics'), dim: 'source', cols: ['visitors', 'cr'] },
					{ key: 'referrer', label: __('Referrers', 'nemvo-analytics'), dim: 'referrer', cols: ['visitors', 'cr'], link: (r) => 'https://' + r.label },
					{ key: 'utm_campaign', label: __('Campaigns', 'nemvo-analytics'), dim: 'utm_campaign', cols: ['visitors', 'cr'] },
				],
			}),
			makePanel({
				id: 'pages',
				title: __('Pages', 'nemvo-analytics'),
				tabs: [
					{ key: 'page', label: __('Top pages', 'nemvo-analytics'), dim: 'page', cols: ['visitors', 'pageviews'], page: true },
					{ key: 'entry_page', label: __('Entry pages', 'nemvo-analytics'), dim: 'entry_page', cols: ['visits', 'bounce_rate'], bar: 'visits', page: true },
					{ key: 'exits', label: __('Exit pages', 'nemvo-analytics'), dim: 'exits', filter: 'exit_page', cols: ['exits', 'exit_rate'], bar: 'exits', page: true },
					{ key: 'time', label: __('Time on page', 'nemvo-analytics'), dim: 'page', cols: ['avg_time', 'scroll'], bar: 'avg_time', page: true, limit: 30, sortBy: 'avg_time', note: __('Average engaged time on your 30 most visited pages.', 'nemvo-analytics') },
				],
			}),
		];
		ui.locations = locationsPanel();
		ui.panels2 = [
			makePanel({
				id: 'companies',
				title: __('Companies & networks', 'nemvo-analytics'),
				tabs: [
					{ key: 'company', label: __('Companies', 'nemvo-analytics'), dim: 'company', cols: ['visits', 'pageviews'], bar: 'visits', note: cfg.asn ? __('Organisations visiting from their own network or an office line. Click one to see its visits.', 'nemvo-analytics') : null },
					{ key: 'network', label: __('Network types', 'nemvo-analytics'), dim: 'network', cols: ['visits', 'share'], bar: 'visits' },
					{ key: 'org', label: __('Providers', 'nemvo-analytics'), dim: 'org', cols: ['visits', 'share'], bar: 'visits' },
				],
				empty: cfg.asn ? __('No companies identified in this period.', 'nemvo-analytics') : __('Install the free network database under Settings › Companies & networks to see who visits.', 'nemvo-analytics'),
			}),
			makePanel({
				id: 'goals',
				title: __('Goals & events', 'nemvo-analytics'),
				tabs: [
					{ key: 'goal', label: __('Goals', 'nemvo-analytics'), dim: 'goal', cols: ['conversions', 'cr'], bar: 'conversions' },
					{ key: 'event', label: __('Events', 'nemvo-analytics'), dim: 'event', cols: ['events', 'visitors'], bar: 'events', drill: true },
				],
			}),
		];
		ui.panels3 = [
			makePanel({
				id: 'devices',
				title: __('Devices', 'nemvo-analytics'),
				tabs: [
					{ key: 'device', label: __('Device', 'nemvo-analytics'), dim: 'device', cols: ['visitors', 'share'], segmented: true },
					{ key: 'os', label: __('OS', 'nemvo-analytics'), dim: 'os', cols: ['visitors', 'share'] },
					{ key: 'browser', label: __('Browser', 'nemvo-analytics'), dim: 'browser', cols: ['visitors', 'share'] },
					{ key: 'screen', label: __('Screen', 'nemvo-analytics'), dim: 'screen', cols: ['visitors', 'share'] },
					{ key: 'language', label: __('Language', 'nemvo-analytics'), dim: 'language', cols: ['visitors', 'share'] },
				],
			}),
		];
		ui.heatmap = heatmapPanel();
		ui.visits = visitsPanel();
		ui.bots = botsPanel();

		put(app, 
			header(__('Analytics', 'nemvo-analytics')),
			ui.toolbar,
			ui.notices,
			ui.tiles,
			ui.chart.el,
			h('div', { class: 'nva-grid' }, ui.panels.map((p) => p.el)),
			ui.locations.el,
			h('div', { class: 'nva-grid' }, ui.panels2.map((p) => p.el)),
			h('div', { class: 'nva-grid' }, ui.panels3[0].el, ui.heatmap.el),
			ui.visits.el,
			ui.bots.el,
			footer()
		);

		readHash();
		window.addEventListener('popstate', () => {
			readHash();
			refresh();
		});
		window.addEventListener('hashchange', () => {
			readHash();
			refresh();
		});
		refresh();
		pollLive();
	}

	function allPanels() {
		return ui.panels.concat(ui.panels2, ui.panels3, [ui.locations, ui.heatmap, ui.visits, ui.bots]);
	}

	function refresh() {
		let [from, to] = rangeDates(state.range);
		if (to > cfg.today) {
			to = cfg.today;
		}
		if (from > to) {
			[from, to] = [to, from];
		}
		cur.from = from;
		cur.to = to;
		const allowed = intervalsFor(daysBetween(from, to));
		if (state.interval && !allowed[state.interval]) {
			state.interval = '';
		}
		renderToolbar();

		const key = JSON.stringify([from, to, state.compare, state.interval, state.traffic, state.filters]);
		if (key === lastKey) {
			renderTiles();
			renderChart();
			return;
		}
		lastKey = key;
		loadOverview();
		allPanels().forEach((p) => p.load());
	}

	function pollLive() {
		const tick = () => {
			if (!document.hidden) {
				report('realtime', {}, true)
					.then((d) => setLive(d.active))
					.catch(() => {});
			}
		};
		tick();
		setInterval(tick, 60000);
	}

	/* ---------------- Toolbar: date range, traffic, comparison, filters ---------------- */

	function renderToolbar() {
		const days = daysBetween(cur.from, cur.to);
		const shift = (dir) => {
			const from = addDays(cur.from, dir * days);
			let to = addDays(cur.to, dir * days);
			if (from > cfg.today) {
				return;
			}
			to = to > cfg.today ? cfg.today : to;
			navigate({ range: 'custom', from, to });
		};

		const rangeButton = h(
			'button',
			{ type: 'button', class: 'nva-btn nva-range', 'aria-haspopup': 'true', 'aria-expanded': 'false', onclick: (e) => openRangeMenu(e.currentTarget) },
			h('span', { class: 'nva-range__name', text: RANGES[state.range] || __('Custom range', 'nemvo-analytics') }),
			h('span', { class: 'nva-range__dates', text: rangeText(cur.from, cur.to) }),
			icon('down')
		);

		const compare = h(
			'select',
			{ class: 'nva-select', 'aria-label': __('Compare with', 'nemvo-analytics'), onchange: (e) => navigate({ compare: e.target.value }) },
			[
				['previous', __('vs. previous period', 'nemvo-analytics')],
				['year', __('vs. same period last year', 'nemvo-analytics')],
				['none', __('No comparison', 'nemvo-analytics')],
			].map(([value, text]) => h('option', { value, text, selected: value === state.compare }))
		);

		const traffic = h(
			'div',
			{ class: 'nva-seg-control', role: 'group', 'aria-label': __('Traffic', 'nemvo-analytics') },
			Object.keys(TRAFFIC).map((k) => h('button', { type: 'button', 'aria-pressed': String(k === state.traffic), text: TRAFFIC[k], title: 'bot' === k ? __('Crawlers, scrapers, spam and fake hits', 'nemvo-analytics') : null, onclick: () => navigate({ traffic: k }) }))
		);

		const chips = state.filters.map((f, i) => {
			const info = display(f.d, f.v);
			return h(
				'span',
				{ class: 'nva-chip' },
				h(
					'button',
					{ type: 'button', class: 'nva-chip__body', 'aria-haspopup': 'true', title: __('Edit filter', 'nemvo-analytics'), onclick: (e) => openFilterMenu(e.currentTarget, i) },
					h('span', { class: 'nva-chip__dim', text: DIMS[f.d] + ' ' + OPS[f.o] }),
					' ',
					labelNode(info)
				),
				h(
					'button',
					{
						type: 'button',
						class: 'nva-chip__x',
						/* translators: %s: filter description */
						'aria-label': sprintf(__('Remove filter %s', 'nemvo-analytics'), DIMS[f.d] + ' ' + OPS[f.o] + ' ' + info.text),
						onclick: () => navigate({ filters: state.filters.filter((x, j) => j !== i) }),
					},
					icon('x')
				)
			);
		});

		put(ui.toolbar, 
			h(
				'div',
				{ class: 'nva-toolbar__group' },
				h('button', { type: 'button', class: 'nva-btn nva-btn--icon', 'aria-label': __('Previous period', 'nemvo-analytics'), onclick: () => shift(-1) }, icon('left')),
				rangeButton,
				h('button', { type: 'button', class: 'nva-btn nva-btn--icon', 'aria-label': __('Next period', 'nemvo-analytics'), disabled: cur.to >= cfg.today, onclick: () => shift(1) }, icon('right'))
			),
			compare,
			traffic,
			h(
				'div',
				{ class: 'nva-toolbar__filters' },
				chips,
				h('button', { type: 'button', class: 'nva-btn nva-btn--ghost', 'aria-haspopup': 'true', onclick: (e) => openFilterMenu(e.currentTarget, -1) }, icon('filter'), __('Filter', 'nemvo-analytics')),
				state.filters.length > 1 ? h('button', { type: 'button', class: 'nva-link-btn', onclick: () => navigate({ filters: [] }), text: __('Clear all', 'nemvo-analytics') }) : null
			),
			h('a', { class: 'nva-btn nva-btn--ghost nva-toolbar__end', href: cfg.exportUrl + '&from=' + cur.from + '&to=' + cur.to, title: __('Raw pageviews for this period as CSV', 'nemvo-analytics') }, icon('download'), __('Export', 'nemvo-analytics'))
		);
	}

	function openRangeMenu(anchor) {
		const items = Object.keys(RANGES).map((key) => {
			const [from, to] = rangeDates(key);
			const selected = key === state.range;
			return h(
				'button',
				{ type: 'button', role: 'menuitemradio', 'aria-checked': String(selected), class: 'nva-menu__item', onclick: () => (closePopover(), navigate({ range: key, from: '', to: '' })) },
				h('span', { class: 'nva-menu__check' }, selected ? icon('check') : null),
				h('span', { class: 'nva-menu__label', text: RANGES[key] }),
				h('span', { class: 'nva-menu__hint', text: rangeText(from, to > cfg.today ? cfg.today : to) })
			);
		});
		const fromInput = h('input', { type: 'date', value: cur.from, max: cfg.today, required: true });
		const toInput = h('input', { type: 'date', value: cur.to, max: cfg.today, required: true });
		const custom = h(
			'form',
			{
				class: 'nva-menu__custom',
				onsubmit: (e) => {
					e.preventDefault();
					if (fromInput.value && toInput.value) {
						closePopover();
						const [from, to] = [fromInput.value, toInput.value].sort();
						navigate({ range: 'custom', from, to });
					}
				},
			},
			h('label', null, h('span', { text: __('From', 'nemvo-analytics') }), fromInput),
			h('label', null, h('span', { text: __('To', 'nemvo-analytics') }), toInput),
			h('button', { type: 'submit', class: 'button button-primary', text: __('Apply', 'nemvo-analytics') })
		);
		popover(anchor, h('div', { class: 'nva-menu', role: 'menu' }, items, custom));
	}

	/** Fixed value lists for dimensions that have one. */
	function choicesFor(dim) {
		const sortByText = (entries) => entries.sort((a, b) => a[1].localeCompare(b[1], locale));
		switch (dim) {
			case 'country':
				return sortByText(Object.keys(cfg.countries).map((c) => [c, countryName(c)]));
			case 'device':
				return Object.entries(DEVICES);
			case 'screen':
				return Object.entries(SCREENS);
			case 'channel':
				return Object.entries(CHANNELS);
			case 'content_type':
				return Object.entries(TYPES);
			case 'network':
				return Object.entries(NETWORKS);
			case 'weekday':
				return range(0, 7).map((d) => [String(d), dayName((d + 1) % 7, true)]);
			case 'hour':
				return range(0, 24).map((hr) => [String(hr), hourRange(hr)]);
			case 'bot_flag':
				return Object.entries(SIGNALS)
					.filter(([, s]) => s[1] > 0)
					.map(([f, s]) => [f, s[0]]);
			case 'goal':
				return cfg.goals.map((g) => [String(g.id), g.name]);
		}
		return null;
	}

	function openFilterMenu(anchor, index) {
		const editing = index >= 0 ? state.filters[index] : null;
		const dimSelect = h(
			'select',
			{ name: 'd' },
			DIM_GROUPS.map(([label, dims]) => h('optgroup', { label }, dims.filter((d) => d !== 'goal' || cfg.goals.length).map((d) => h('option', { value: d, text: DIMS[d], selected: editing ? editing.d === d : d === 'page' }))))
		);
		const opSelect = h('select', { name: 'o' });
		const valueWrap = h('span', { class: 'nva-filter-form__value' });
		const list = h('datalist', { id: 'nva-suggest' });
		let valueInput = null;
		let timer = 0;

		const suggest = () => {
			clearTimeout(timer);
			timer = setTimeout(() => {
				const dim = dimSelect.value;
				report('breakdown', params({ dim, limit: 20, q: valueInput.value, traffic: 'all' }))
					.then((data) => {
						put(list, ...data.rows.map((r) => h('option', { value: r.label, label: display(dim, r.label).title || display(dim, r.label).text })));
					})
					.catch(() => {});
			}, 200);
		};

		const build = () => {
			const dim = dimSelect.value;
			const choices = choicesFor(dim);
			const ops = choices ? ['is', 'is_not'] : ['is', 'is_not', 'contains'];
			const op = editing && editing.d === dim && ops.includes(editing.o) ? editing.o : opSelect.value && ops.includes(opSelect.value) ? opSelect.value : 'is';
			put(opSelect, ...ops.map((o) => h('option', { value: o, text: OPS[o], selected: o === op })));
			const value = editing && editing.d === dim ? editing.v : '';
			if (choices) {
				valueInput = h('select', { name: 'v', required: true }, choices.map(([v, text]) => h('option', { value: v, text, selected: v === value })));
			} else {
				valueInput = h('input', { type: 'text', name: 'v', required: true, list: 'nva-suggest', autocomplete: 'off', value, placeholder: __('Start typing…', 'nemvo-analytics'), oninput: suggest });
				suggest();
			}
			put(valueWrap, valueInput, list);
		};
		dimSelect.addEventListener('change', build);
		build();

		const form = h(
			'form',
			{
				class: 'nva-filter-form',
				onsubmit: (e) => {
					e.preventDefault();
					const filter = { d: dimSelect.value, o: opSelect.value, v: valueInput.value.trim() };
					if (!filter.v) {
						return;
					}
					closePopover();
					const filters = state.filters.slice();
					if (index >= 0) {
						filters[index] = filter;
					} else {
						filters.push(filter);
					}
					navigate({ filters });
				},
			},
			h('label', null, h('span', { text: __('Dimension', 'nemvo-analytics') }), dimSelect),
			h('label', null, h('span', { text: __('Condition', 'nemvo-analytics') }), opSelect),
			h('label', null, h('span', { text: __('Value', 'nemvo-analytics') }), valueWrap),
			h('div', { class: 'nva-filter-form__actions' }, h('button', { type: 'submit', class: 'button button-primary', text: index >= 0 ? __('Update filter', 'nemvo-analytics') : __('Add filter', 'nemvo-analytics') }))
		);
		popover(anchor, form);
	}

	/* ---------------- Notices ---------------- */

	function renderNotices() {
		const notes = [];
		if (!cfg.tracking) {
			notes.push(['warning', __('Tracking is paused, so no new visits are recorded.', 'nemvo-analytics'), cfg.canManage ? [cfg.settingsUrl + '#tracking', __('Turn it back on', 'nemvo-analytics')] : null]);
		}
		if ('bot' === state.traffic) {
			notes.push(['info', __('Showing automated traffic only: crawlers, scrapers, spam and fake hits. Open a visit in the visit log to see why it was flagged.', 'nemvo-analytics'), null]);
		}
		if (overview && !overview.current.visits && !state.filters.length && 'human' === state.traffic) {
			notes.push(['info', __('No visits in this period yet. Logged-in administrators and editors are not counted by default: open the site in a private window to see your own visit under Realtime.', 'nemvo-analytics'), null]);
		}
		const issues = (cfg.warnings || []).filter((w) => 'tracking' !== w[3] || cfg.tracking);
		const issueKey = issues.map((w) => w[1]).join('|');
		if (cfg.canManage && issues.length && store.get('hide-setup', '') !== issueKey) {
			/* translators: %s: number of setup issues */
			const text = 1 === issues.length ? issues[0][1] : sprintf(_n('%s setup issue', '%s setup issues', issues.length, 'nemvo-analytics'), fmt.full(issues.length)) + ': ' + issues.map((w) => w[1]).join(' · ');
			notes.push([issues.some((w) => 'critical' === w[0]) ? 'warning' : 'info', text, [cfg.settingsUrl + '#setup', __('Setup checklist', 'nemvo-analytics')], issueKey]);
		}
		put(ui.notices, 
			...notes.map(([type, text, link, dismiss]) =>
				h(
					'div',
					{ class: 'nva-notice nva-notice--' + type, role: 'status' },
					h('span', { text }),
					link ? h('a', { href: link[0], text: link[1] }) : null,
					dismiss
						? h('button', {
								type: 'button',
								class: 'nva-link-btn nva-notice__close',
								text: __('Dismiss', 'nemvo-analytics'),
								onclick: (e) => {
									store.set('hide-setup', dismiss);
									e.currentTarget.parentNode.remove();
								},
						  })
						: null
				)
			)
		);
	}

	/* ---------------- Tiles ---------------- */

	function delta(key, value, prev) {
		if (prev === null || prev === undefined) {
			return null;
		}
		const m = METRICS[key];
		let change;
		let text;
		if (m.points) {
			change = value - prev;
			/* translators: %s: change in percentage points */
			text = sprintf(__('%s pp', 'nemvo-analytics'), nfDec1.format(Math.abs(change * 100)));
		} else {
			if (!prev) {
				return null;
			}
			change = (value - prev) / prev;
			text = nfPct0.format(Math.abs(change));
		}
		/* translators: %s: value in the comparison period */
		const title = sprintf(__('Comparison period: %s', 'nemvo-analytics'), m.format(prev));
		if (Math.abs(change) < 0.005) {
			return h('span', { class: 'nva-delta is-flat', title, text: '±0' });
		}
		const good = change > 0 !== !!m.lowerIsBetter;
		return h('span', { class: 'nva-delta ' + (good ? 'is-good' : 'is-bad'), title }, icon(change > 0 ? 'up' : 'dn'), h('span', { class: 'screen-reader-text', text: change > 0 ? __('up', 'nemvo-analytics') : __('down', 'nemvo-analytics') }), text);
	}

	function renderTiles() {
		if (!overview) {
			return;
		}
		const now = overview.current;
		const prev = overview.compare ? overview.compare.current : null;
		put(ui.tiles, 
			...TILES.map((key) => {
				const m = METRICS[key];
				let sub = null;
				if ('conversions' === key) {
					/* translators: %s: conversion rate */
					sub = sprintf(__('%s of visits', 'nemvo-analytics'), fmt.percent(now.conversion_rate));
				} else if ('avg_time' === key) {
					/* translators: %s: average scroll depth */
					sub = now.scroll ? sprintf(__('%s scrolled', 'nemvo-analytics'), fmt.percent0(now.scroll / 100)) : null;
				}
				const active = state.metric === key || ('conversions' === key && 'conversion_rate' === state.metric);
				return h(
					m.chart ? 'button' : 'div',
					{
						class: 'nva-tile' + (active ? ' is-active' : '') + (m.chart ? ' is-button' : ''),
						type: m.chart ? 'button' : null,
						'aria-pressed': m.chart ? String(active) : null,
						onclick: m.chart ? () => navigate({ metric: key }, true) : null,
					},
					h('span', { class: 'nva-tile__label', text: m.label }),
					h('span', { class: 'nva-tile__value', text: m.format(now[key]) }),
					h('span', { class: 'nva-tile__foot' }, delta(key, now[key], prev ? prev[key] : null), sub ? h('span', { class: 'nva-tile__sub', text: sub }) : null)
				);
			})
		);
	}

	/* ---------------- Main chart ---------------- */

	function chartCard() {
		const body = h('div', { class: 'nva-canvas nva-canvas--main' });
		const legend = h('div', { class: 'nva-chart__legend' });
		const controls = h('div', { class: 'nva-chart__controls' });
		const el = h('section', { class: 'nva-card nva-chart' }, h('header', { class: 'nva-panel__head' }, controls), legend, body);
		const card = { el, body, legend, controls, table: store.get('chart-table', false) };
		redraws.add(() => renderChart());
		return card;
	}

	function loadOverview() {
		const gen = ++overviewGen;
		ui.chart.el.classList.add('is-loading');
		ui.tiles.classList.add('is-loading');
		overviewPromise = report('overview', params({ compare: 'none' === state.compare ? '' : state.compare, interval: state.interval }));
		overviewPromise
			.then((data) => {
				if (gen !== overviewGen) {
					return;
				}
				overview = data;
				renderTiles();
				renderChart();
				renderNotices();
			})
			.catch((err) => {
				if (gen === overviewGen) {
					put(ui.chart.body, errorNote(err));
				}
			})
			.finally(() => {
				if (gen === overviewGen) {
					ui.chart.el.classList.remove('is-loading');
					ui.tiles.classList.remove('is-loading');
					app.setAttribute('aria-busy', 'false');
				}
			});
	}

	function seriesValues(series, metric) {
		return series.map((b) => {
			if ('views_per_visit' === metric) {
				return b.visits ? b.pageviews / b.visits : null;
			}
			return b[metric] === undefined ? null : b[metric];
		});
	}

	function axisFormat(m, top) {
		if (m.format === fmt.percent) {
			return (v) => fmt.percent0(v);
		}
		if (m.format === fmt.duration) {
			/* translators: %s: minutes (chart axis) */
			return (v) => (top >= 120 ? sprintf(__('%sm', 'nemvo-analytics'), nfDec1.format(v / 60)) : fmt.duration(v));
		}
		if (m.format === fmt.decimal) {
			return (v) => nfDec1.format(v);
		}
		return (v) => (top >= 10000 ? nfCompact.format(v) : nf.format(v));
	}

	function tableToggle(target, key, redraw) {
		return h(
			'button',
			{
				type: 'button',
				class: 'nva-icon-btn',
				'aria-pressed': String(target.table),
				'aria-label': target.table ? __('Show chart', 'nemvo-analytics') : __('Show as table', 'nemvo-analytics'),
				title: target.table ? __('Show chart', 'nemvo-analytics') : __('Show as table', 'nemvo-analytics'),
				onclick: () => {
					target.table = !target.table;
					store.set(key, target.table);
					redraw();
				},
			},
			icon(target.table ? 'chart' : 'table')
		);
	}

	function renderChart() {
		const card = ui.chart;
		if (!overview) {
			return;
		}
		const metric = state.metric;
		const m = METRICS[metric];
		const interval = overview.interval;
		const allowed = intervalsFor(daysBetween(overview.from, overview.to));

		const metricSelect = h(
			'select',
			{ class: 'nva-select nva-select--title', 'aria-label': __('Metric', 'nemvo-analytics'), onchange: (e) => navigate({ metric: e.target.value }, true) },
			Object.keys(METRICS)
				.filter((k) => METRICS[k].chart)
				.map((k) => h('option', { value: k, text: METRICS[k].label, selected: k === metric }))
		);
		const intervals = { hour: __('Hours', 'nemvo-analytics'), day: __('Days', 'nemvo-analytics'), week: __('Weeks', 'nemvo-analytics'), month: __('Months', 'nemvo-analytics') };
		const segmented = h(
			'div',
			{ class: 'nva-seg-control', role: 'group', 'aria-label': __('Interval', 'nemvo-analytics') },
			Object.keys(intervals)
				.filter((k) => allowed[k])
				.map((k) => h('button', { type: 'button', 'aria-pressed': String(k === interval), text: intervals[k], onclick: () => navigate({ interval: k }) }))
		);
		put(card.controls, metricSelect, h('div', { class: 'nva-panel__tools' }, segmented, tableToggle(card, 'chart-table', renderChart)));

		const keys = overview.series.map((b) => b.date);
		const values = seriesValues(overview.series, metric);
		const cmp = overview.compare;
		const cmpValues = cmp ? seriesValues(cmp.series, metric).slice(0, keys.length) : null;
		const cmpKeys = cmp ? cmp.series.map((b) => b.date) : [];

		put(card.legend, 
			cmp
				? h(
						'ul',
						{ class: 'nva-legend' },
						h('li', null, h('span', { class: 'nva-key nva-key--line' }), rangeText(overview.from, overview.to)),
						h('li', null, h('span', { class: 'nva-key nva-key--line nva-key--compare' }), rangeText(cmp.from, cmp.to))
				  )
				: null
		);

		if (card.table) {
			if (card.body._chart) {
				card.body._chart.destroy();
				card.body._chart = null;
			}
			card.body.classList.add('is-table');
			put(card.body, 
				h(
					'div',
					{ class: 'nva-table-wrap' },
					h(
						'table',
						{ class: 'nva-table' },
						h('thead', null, h('tr', null, h('th', { scope: 'col', text: __('Period', 'nemvo-analytics') }), h('th', { scope: 'col', class: 'nva-num', text: m.label }), cmp ? h('th', { scope: 'col', class: 'nva-num', text: __('Comparison', 'nemvo-analytics') }) : null)),
						h(
							'tbody',
							null,
							keys.map((k, i) =>
								h(
									'tr',
									null,
									h('td', { text: bucketLabel(k, interval, true) }),
									h('td', { class: 'nva-num', text: values[i] === null ? '–' : m.format(values[i]) }),
									cmp ? h('td', { class: 'nva-num', text: cmpValues[i] === null || cmpValues[i] === undefined ? '–' : m.format(cmpValues[i]) }) : null
								)
							)
						)
					)
				)
			);
			return;
		}
		card.body.classList.remove('is-table');

		const drill =
			interval !== 'hour'
				? (i) => {
						const from = keys[i];
						let to = from;
						if ('week' === interval) {
							to = addDays(from, 6);
						} else if ('month' === interval) {
							to = addDays(addDays(from, 32).slice(0, 7) + '-01', -1);
						}
						hideTip();
						navigate({ range: 'custom', from: from < overview.from ? overview.from : from, to: to > cfg.today ? cfg.today : to, interval: '' });
				  }
				: null;

		const t = tokens();
		const defined = (v) => v !== null && v !== undefined;
		let last = values.length - 1;
		while (last > 0 && !defined(values[last])) {
			last--;
		}
		const top = Math.max(...values.concat(cmpValues || []).filter(defined), 0);
		const tickFmt = axisFormat(m, top);
		const datasets = [
			{
				data: values,
				borderColor: t.c1,
				backgroundColor: t.wash,
				fill: 'origin',
				borderWidth: 2,
				cubicInterpolationMode: 'monotone',
				pointRadius: values.map((v, i) => (i === last ? 4 : 0)),
				pointHoverRadius: 5,
				pointBackgroundColor: t.c1,
				pointBorderColor: t.surface,
				pointBorderWidth: 2,
				pointHitRadius: 12,
				order: 1,
			},
		];
		if (cmpValues) {
			datasets.push({
				data: cmpValues,
				borderColor: t.compare,
				borderWidth: 2,
				cubicInterpolationMode: 'monotone',
				pointRadius: 0,
				pointHoverRadius: 4,
				pointBackgroundColor: t.compare,
				pointBorderColor: t.surface,
				pointBorderWidth: 2,
				fill: false,
				order: 2,
			});
		}
		const chart = mountChart(card.body, m.label + ', ' + rangeText(overview.from, overview.to) + '. ' + __('Use the arrow keys to read values.', 'nemvo-analytics'), {
			type: 'line',
			data: { labels: keys.map((k) => bucketLabel(k, interval, false)), datasets },
			options: {
				interaction: { mode: 'index', intersect: false },
				layout: { padding: { top: 8, right: 8 } },
				scales: {
					x: { grid: { display: false }, border: { color: t.axis }, ticks: { color: t.muted, maxRotation: 0, autoSkipPadding: 24 } },
					y: { beginAtZero: true, grid: { color: t.grid }, border: { display: false }, ticks: { color: t.muted, padding: 8, maxTicksLimit: 5, callback: tickFmt } },
				},
				plugins: {
					tooltip: {
						external: externalTip((points) => {
							const i = points[0].dataIndex;
							const rows = [tipTitle(bucketLabel(keys[i], interval, true)), tipRow('nva-key--line', defined(values[i]) ? m.format(values[i]) : '–', m.label)];
							if (cmpValues && cmpKeys[i]) {
								rows.push(tipRow('nva-key--line nva-key--compare', defined(cmpValues[i]) ? m.format(cmpValues[i]) : '–', bucketLabel(cmpKeys[i], interval, true)));
							}
							if (drill) {
								rows.push(tipHint(__('Click to zoom in', 'nemvo-analytics')));
							}
							return rows;
						}),
					},
				},
				onClick: (e, els, c) => {
					const pts = c.getElementsAtEventForMode(e, 'index', { intersect: false }, false);
					if (pts.length && drill) {
						drill(pts[0].index);
					}
				},
				onHover: (e, els, c) => {
					c.canvas.style.cursor = drill && els.length ? 'pointer' : 'default';
				},
			},
			plugins: [crosshair],
		});
		keyboard(chart, () => keys.length, drill);
	}

	/* ---------------- Breakdown panels ---------------- */

	function panelShell(id, title, tabs, current, onTab) {
		const tabButtons = tabs
			? h(
					'div',
					{ class: 'nva-tabs', role: 'tablist', 'aria-label': title },
					tabs.map((t) =>
						h('button', {
							type: 'button',
							role: 'tab',
							'aria-selected': String(t.key === current),
							'data-key': t.key,
							text: t.label,
							onclick: () => onTab(t.key),
						})
					)
			  )
			: null;
		const tools = h('div', { class: 'nva-panel__tools' });
		const body = h('div', { class: 'nva-panel__body' });
		const foot = h('footer', { class: 'nva-panel__foot' });
		const el = h('section', { class: 'nva-card nva-panel', id: 'nva-' + id }, h('header', { class: 'nva-panel__head' }, h('h2', { text: title }), tools), tabButtons, body, foot);
		const select = (key) => {
			if (tabButtons) {
				tabButtons.querySelectorAll('[role="tab"]').forEach((b) => b.setAttribute('aria-selected', String(b.getAttribute('data-key') === key)));
			}
		};
		return { el, body, foot, tools, select };
	}

	function pageActions(path) {
		return h(
			'span',
			{ class: 'nva-row__actions' },
			h(
				'button',
				{
					type: 'button',
					class: 'nva-icon-btn nva-icon-btn--sm',
					title: __('Where visitors came from and went next', 'nemvo-analytics'),
					/* translators: %s: page path */
					'aria-label': sprintf(__('Page journey for %s', 'nemvo-analytics'), path),
					onclick: () => openJourney(path),
				},
				icon('route')
			),
			h('a', { class: 'nva-icon-btn nva-icon-btn--sm', href: siteUrl(path), target: '_blank', rel: 'noopener', title: __('Open the page', 'nemvo-analytics'), 'aria-label': __('Open the page', 'nemvo-analytics') }, icon('external'))
		);
	}

	function makePanel(def) {
		const panel = { def, tab: store.get('tab-' + def.id, def.tabs[0].key), drill: null, gen: 0 };
		if (!def.tabs.some((t) => t.key === panel.tab)) {
			panel.tab = def.tabs[0].key;
		}
		const shell = panelShell(def.id, def.title, def.tabs, panel.tab, (key) => {
			panel.tab = key;
			panel.drill = null;
			store.set('tab-' + def.id, key);
			shell.select(key);
			panel.load();
		});
		panel.el = shell.el;
		const tabDef = () => def.tabs.find((t) => t.key === panel.tab);

		panel.load = () => {
			const tab = tabDef();
			const gen = ++panel.gen;
			shell.el.classList.add('is-loading');
			const query = params({ dim: tab.dim, limit: tab.limit || 10 });
			if (panel.drill !== null) {
				Object.assign(query, { dim: 'event_label', event: panel.drill });
			}
			Promise.all([overviewPromise.catch(() => null), report('breakdown', query)])
				.then(([ov, data]) => {
					if (gen === panel.gen) {
						render(tab, data, ov);
					}
				})
				.catch((err) => gen === panel.gen && put(shell.body, errorNote(err)))
				.finally(() => gen === panel.gen && shell.el.classList.remove('is-loading'));
		};

		const render = (tab, data, ov) => {
			const visits = ov ? ov.current.visits : 0;
			let rows = data.rows.map((r) => Object.assign({ share: visits ? r.visits / visits : 0 }, r));
			if (tab.sortBy) {
				rows = rows
					.filter((r) => r[tab.sortBy] > 0)
					.sort((a, b) => b[tab.sortBy] - a[tab.sortBy])
					.slice(0, 10);
			}
			const parts = [];

			if (panel.drill !== null) {
				const eventName = panel.drill;
				parts.push(
					h(
						'div',
						{ class: 'nva-drill' },
						h('button', { type: 'button', class: 'nva-link-btn', onclick: () => ((panel.drill = null), panel.load()) }, icon('left'), __('All events', 'nemvo-analytics')),
						h('strong', { text: eventName }),
						h('button', { type: 'button', class: 'nva-link-btn', onclick: () => addFilter({ d: 'event', v: eventName }) }, icon('filter'), __('Only visits with this event', 'nemvo-analytics'))
					)
				);
				parts.push(
					rankList({
						rows,
						head: __('Label', 'nemvo-analytics'),
						cols: [col('events'), col('visitors')],
						bar: 'events',
						label: (r) => ({ text: r.label || __('(none)', 'nemvo-analytics'), title: r.meta ? r.label + ' ← ' + r.meta : r.label }),
					})
				);
			} else {
				if (tab.note) {
					parts.push(h('p', { class: 'nva-panel__note', text: tab.note }));
				}
				if (tab.segmented && rows.length) {
					parts.push(deviceSplit(rows));
				}
				if ('goal' === tab.dim && !cfg.goals.length) {
					parts.push(emptyNote(__('No goals are set up yet.', 'nemvo-analytics')));
					if (cfg.canManage) {
						parts.push(h('a', { class: 'button', href: cfg.settingsUrl + '#goals', text: __('Add a goal', 'nemvo-analytics') }));
					}
				} else {
					parts.push(
						rankList({
							rows,
							dim: 'exits' === tab.dim ? 'page' : tab.dim,
							head: tab.label,
							cols: tab.cols.map(col),
							bar: tab.bar || 'visitors',
							empty: def.empty,
							label: tab.page ? (r) => ({ text: r.label, title: r.title ? r.title + '\n' + r.label : r.label }) : null,
							onRow: (r) => {
								if (tab.drill) {
									panel.drill = r.label;
									panel.load();
								} else if ('goal' === tab.dim) {
									addFilter({ d: 'goal', v: String(r.goal) });
								} else {
									addFilter({ d: tab.filter || tab.dim, v: r.label });
								}
							},
							actions: tab.page
								? (r) => pageActions(r.label)
								: tab.link
								? (r) => h('span', { class: 'nva-row__actions' }, h('a', { class: 'nva-icon-btn nva-icon-btn--sm', href: tab.link(r), target: '_blank', rel: 'noopener noreferrer', 'aria-label': __('Open', 'nemvo-analytics') }, icon('external')))
								: null,
						})
					);
				}
			}
			put(shell.body, ...parts);

			const shown = rows.length;
			put(shell.foot, 
				/* translators: 1: rows shown, 2: total rows */
				h('span', { class: 'nva-muted', text: data.total > shown && !tab.sortBy ? sprintf(__('%1$s of %2$s', 'nemvo-analytics'), fmt.full(shown), fmt.full(data.total)) : '' }),
				'goal' === tab.dim && panel.drill === null ? null : h('button', { type: 'button', class: 'nva-link-btn', onclick: () => openDetails(tab, panel.drill), text: __('Details', 'nemvo-analytics') }, icon('right'))
			);
		};

		return panel;
	}

	/** Device split as one segmented bar (desktop, mobile, tablet keep fixed colors). */
	function deviceSplit(rows) {
		const order = ['desktop', 'mobile', 'tablet'];
		const parts = order.map((k) => rows.find((r) => r.label === k)).filter((r) => r && r.visits > 0);
		const total = sum(parts, (r) => r.visits);
		if (!total) {
			return null;
		}
		const box = h('div', { class: 'nva-canvas nva-canvas--seg' });
		const legend = h(
			'ul',
			{ class: 'nva-legend' },
			parts.map((r) => h('li', null, h('span', { class: 'nva-key nva-key--box nva-c' + (order.indexOf(r.label) + 1) }), display('device', r.label).text + ' ' + fmt.percent0(r.visits / total)))
		);
		const draw = () => {
			if (!box.isConnected) {
				redraws.delete(draw);
				return;
			}
			const t = tokens();
			// Share labels inside the parts that are wide enough for them.
			const inside = {
				id: 'nvaInside',
				afterDatasetsDraw(chart) {
					const ctx = chart.ctx;
					ctx.save();
					ctx.font = '600 12px ' + Chart.defaults.font.family;
					ctx.fillStyle = '#ffffff';
					ctx.textBaseline = 'middle';
					chart.data.datasets.forEach((d, i) => {
						const bar = chart.getDatasetMeta(i).data[0];
						const label = fmt.percent0(d.data[0] / total);
						if (bar && bar.width > ctx.measureText(label).width + 20) {
							ctx.fillText(label, bar.base + 8, bar.y);
						}
					});
					ctx.restore();
				},
			};
			mountChart(box, parts.map((r) => display('device', r.label).text + ' ' + fmt.percent(r.visits / total)).join(', '), {
				type: 'bar',
				data: {
					labels: [''],
					datasets: parts.map((r) => ({
						label: display('device', r.label).text,
						data: [r.visits],
						backgroundColor: t['c' + (order.indexOf(r.label) + 1)],
						borderRadius: 4,
						borderSkipped: false,
						borderWidth: { right: 2 },
						borderColor: t.surface,
						barPercentage: 1,
						categoryPercentage: 1,
						device: r.label,
					})),
				},
				options: {
					indexAxis: 'y',
					animation: false,
					layout: { padding: 0 },
					scales: { x: { stacked: true, display: false, max: total }, y: { stacked: true, display: false } },
					plugins: {
						tooltip: {
							mode: 'nearest',
							intersect: true,
							external: externalTip((points) => {
								const d = points[0].dataset;
								return [tipTitle(d.label), tipRow('nva-key--box nva-c' + (order.indexOf(d.device) + 1), fmt.percent(d.data[0] / total), sprintf(/* translators: %s: number of visits */ _n('%s visit', '%s visits', d.data[0], 'nemvo-analytics'), fmt.full(d.data[0]))), tipHint(__('Click to filter', 'nemvo-analytics'))];
							}),
						},
					},
					onClick: (e, els, c) => els.length && addFilter({ d: 'device', v: c.data.datasets[els[0].datasetIndex].device }),
					onHover: (e, els, c) => {
						c.canvas.style.cursor = els.length ? 'pointer' : 'default';
					},
				},
				plugins: [inside],
			});
		};
		requestAnimationFrame(draw);
		redraws.add(draw);
		return h('div', { class: 'nva-seg-wrap' }, box, legend);
	}

	/* ---------------- Locations: map + ranked list ---------------- */

	let worldPromise = null;

	function loadWorld() {
		if (!worldPromise) {
			worldPromise = fetch(cfg.mapUrl)
				.then((r) => r.json())
				.then((topo) => ChartGeo.topojson.feature(topo, topo.objects.countries).features.filter((f) => f.id !== 'AQ'));
		}
		return worldPromise;
	}

	/** Map views: a lon/lat box the projection is fitted to. */
	const MAP_VIEWS = {
		world: [__('World', 'nemvo-analytics'), null],
		europe: [__('Europe', 'nemvo-analytics'), [-24, 34, 44, 72.5]],
		nordics: [__('Nordics', 'nemvo-analytics'), [2, 53, 33, 72.5]],
		americas: [__('Americas', 'nemvo-analytics'), [-168, -56, -34, 72]],
		asia: [__('Asia-Pacific', 'nemvo-analytics'), [62, -47, 180, 55]],
	};

	function viewOutline(box) {
		const [w, s, e, n] = box;
		const points = [];
		for (let lon = w; lon <= e; lon += Math.max(2, (e - w) / 12)) {
			points.push([lon, s], [lon, n]);
		}
		points.push([e, s], [e, n], [w, (s + n) / 2], [e, (s + n) / 2]);
		return { type: 'Feature', properties: {}, geometry: { type: 'MultiPoint', coordinates: points } };
	}

	function locationsPanel() {
		const tabs = [
			{ key: 'country', label: __('Countries', 'nemvo-analytics'), dim: 'country' },
			{ key: 'region', label: __('Regions', 'nemvo-analytics'), dim: 'region' },
			{ key: 'city', label: __('Cities', 'nemvo-analytics'), dim: 'city' },
		];
		const panel = { tab: store.get('tab-locations', 'country'), view: store.get('map-view', ''), gen: 0, rows: null };
		const shell = panelShell('locations', __('Locations', 'nemvo-analytics'), null);
		const tabBar = h(
			'div',
			{ class: 'nva-tabs', role: 'tablist', 'aria-label': __('Locations', 'nemvo-analytics') },
			tabs.map((t) =>
				h('button', {
					type: 'button',
					role: 'tab',
					'aria-selected': String(t.key === panel.tab),
					'data-key': t.key,
					text: t.label,
					onclick: () => {
						panel.tab = t.key;
						store.set('tab-locations', t.key);
						tabBar.querySelectorAll('[role="tab"]').forEach((b) => b.setAttribute('aria-selected', String(b.getAttribute('data-key') === t.key)));
						panel.load();
					},
				})
			)
		);
		const viewControl = h('div', { class: 'nva-seg-control nva-map__views', role: 'group', 'aria-label': __('Map view', 'nemvo-analytics') });
		const map = h('div', { class: 'nva-canvas nva-canvas--map' });
		const foot = h('div', { class: 'nva-geo__foot' });
		const list = h('div', { class: 'nva-geo__list' }, tabBar, h('div', { class: 'nva-geo__rows' }));
		shell.el.classList.add('nva-panel--wide');
		shell.tools.append(viewControl);
		put(shell.body, h('div', { class: 'nva-geo' }, h('figure', { class: 'nva-map' }, map, foot), list));
		panel.el = shell.el;

		const draw = () => panel.rows && loadWorld().then((world) => drawMap(map, foot, world, panel));
		redraws.add(draw);

		const paintViews = () => {
			put(viewControl, 
				...Object.keys(MAP_VIEWS).map((k) =>
					h('button', {
						type: 'button',
						'aria-pressed': String(k === panel.current),
						text: MAP_VIEWS[k][0],
						onclick: () => {
							panel.view = k;
							store.set('map-view', k);
							draw();
							paintViews();
						},
					})
				)
			);
		};

		panel.load = () => {
			const gen = ++panel.gen;
			const tab = tabs.find((t) => t.key === panel.tab) || tabs[0];
			shell.el.classList.add('is-loading');
			const countries = report('breakdown', params({ dim: 'country', limit: 300 }));
			const listReq = 'country' === tab.key ? countries : report('breakdown', params({ dim: tab.dim, limit: 10 }));
			Promise.all([overviewPromise.catch(() => null), countries, listReq, loadWorld()])
				.then(([ov, cdata, ldata, world]) => {
					if (gen !== panel.gen) {
						return;
					}
					panel.rows = cdata.rows;
					panel.visits = ov ? ov.current.visits : sum(cdata.rows, (r) => r.visits);
					drawMap(map, foot, world, panel);
					paintViews();
					const rows = ldata.rows.slice(0, 10).map((r) => Object.assign({ share: panel.visits ? r.visits / panel.visits : 0 }, r));
					put(list.lastChild, 
						rankList({
							rows,
							dim: tab.dim,
							head: tab.label,
							cols: [col('visitors'), col('share')],
							bar: 'visitors',
							onRow: (r) => addFilter({ d: tab.dim, v: r.label }),
							empty: 'country' === tab.key ? null : __('No regions or cities yet. They need the city edition of the IP database or a CDN that sends location headers.', 'nemvo-analytics'),
						})
					);
					put(shell.foot, 
						h('span', { class: 'nva-muted', text: ldata.total > rows.length ? sprintf(__('%1$s of %2$s', 'nemvo-analytics'), fmt.full(rows.length), fmt.full(ldata.total)) : '' }),
						h('button', { type: 'button', class: 'nva-link-btn', onclick: () => openDetails(tab, null), text: __('Details', 'nemvo-analytics') }, icon('right'))
					);
				})
				.catch((err) => gen === panel.gen && put(list.lastChild, errorNote(err)))
				.finally(() => gen === panel.gen && shell.el.classList.remove('is-loading'));
		};
		return panel;
	}

	function drawMap(box, foot, features, panel) {
		const rows = panel.rows;
		const byCode = new Map(rows.filter((r) => r.label).map((r) => [r.label, r]));
		const values = [...byCode.values()].map((r) => r.visitors).filter((v) => v > 0);
		// Default view: Europe when most visitors are there.
		let view = panel.view && MAP_VIEWS[panel.view] ? panel.view : '';
		if (!view) {
			const inEurope = sum([...byCode.keys()].filter((c) => /^(AD|AL|AT|BA|BE|BG|BY|CH|CY|CZ|DE|DK|EE|ES|FI|FO|FR|GB|GR|HR|HU|IE|IS|IT|LI|LT|LU|LV|MC|MD|ME|MK|MT|NL|NO|PL|PT|RO|RS|SE|SI|SK|SM|UA|XK)$/.test(c)), (c) => byCode.get(c).visitors);
			view = values.length && inEurope / sum(values) >= 0.75 ? 'europe' : 'world';
		}
		panel.current = view;
		const box4 = MAP_VIEWS[view][1];
		const t = tokens();
		const ramp = t.seq.slice(1);
		const top = values.length ? Math.max(...values) : 1;
		const low = values.length ? Math.min(...values) : 1;

		mountChart(box, __('Visitors by country. The list next to the map has the same numbers.', 'nemvo-analytics'), {
			type: 'choropleth',
			data: {
				labels: features.map((f) => countryName(f.id)),
				datasets: [
					{
						outline: box4 ? viewOutline(box4) : features,
						showOutline: false,
						data: features.map((f) => ({ feature: f, value: byCode.has(f.id) && byCode.get(f.id).visitors > 0 ? byCode.get(f.id).visitors : NaN })),
						borderColor: t.surface,
						borderWidth: 0.6,
						hoverBorderColor: t.ink,
						hoverBorderWidth: 1.2,
					},
				],
			},
			options: {
				animation: false,
				showOutline: false,
				showGraticule: false,
				layout: { padding: 0 },
				scales: {
					projection: { axis: 'x', projection: 'equalEarth' },
					color: {
						axis: 'x',
						type: top / Math.max(1, low) > 20 ? 'colorLogarithmic' : 'color',
						quantize: 6,
						interpolate: (v) => ramp[Math.max(0, Math.min(5, Math.floor(v * 6)))],
						missing: t.seq[0],
						display: false,
					},
				},
				plugins: {
					tooltip: {
						external: externalTip((points) => {
							const f = points[0].raw.feature;
							const r = byCode.get(f.id);
							return [
								h('div', { class: 'nva-tip__title' }, labelNode(display('country', f.id))),
								r ? tipRow(null, fmt.full(r.visitors), _n('visitor', 'visitors', r.visitors, 'nemvo-analytics')) : h('div', { class: 'nva-tip__row', text: __('No visits', 'nemvo-analytics') }),
								/* translators: %s: share of visits */
								r && panel.visits ? tipHint(sprintf(__('%s of visits · click to filter', 'nemvo-analytics'), fmt.percent(r.visits / panel.visits))) : null,
							];
						}),
					},
				},
				onClick: (e, els, c) => {
					if (els.length) {
						const f = c.data.datasets[0].data[els[0].index].feature;
						if (byCode.has(f.id)) {
							addFilter({ d: 'country', v: f.id });
						}
					}
				},
				onHover: (e, els, c) => {
					c.canvas.style.cursor = els.length && byCode.has(c.data.datasets[0].data[els[0].index].feature.id) ? 'pointer' : 'default';
				},
			},
		});
		put(foot, 
			values.length
				? h(
						'div',
						{ class: 'nva-scale' },
						h('span', { text: fmt.number(low) }),
						h('span', { class: 'nva-scale__ramp', 'aria-hidden': 'true' }, range(1, 7).map((i) => h('span', { class: 'nva-swatch nva-seq-' + i }))),
						h('span', { text: fmt.number(top) })
				  )
				: null,
			h('span', { class: 'nva-scale__none' }, h('span', { class: 'nva-swatch nva-seq-0' }), __('No visits', 'nemvo-analytics')),
			h('span', { class: 'nva-muted nva-map__hint', text: __('Click a country to filter', 'nemvo-analytics') })
		);
	}

	/* ---------------- Heatmap ---------------- */

	function heatmapPanel() {
		const panel = { gen: 0, data: null, table: store.get('heatmap-table', false) };
		const shell = panelShell('heatmap', __('When people visit', 'nemvo-analytics'), null);
		panel.el = shell.el;

		const order = range(0, 7).map((i) => (cfg.startOfWeek + i) % 7); // JS weekdays, 0 = Sunday.
		const value = (js, hr) => panel.data[(js + 6) % 7][hr];

		const draw = () => {
			if (!panel.data) {
				return;
			}
			put(shell.tools, tableToggle(panel, 'heatmap-table', draw));
			const total = sum(panel.data.flat());
			if (!total) {
				put(shell.body, emptyNote());
				put(shell.foot);
				return;
			}
			let best = [0, 0, -1];
			order.forEach((js) => range(0, 24).forEach((hr) => value(js, hr) > best[2] && (best = [js, hr, value(js, hr)])));
			/* translators: 1: weekday, 2: hour */
			const insight = h('p', { class: 'nva-panel__note', text: sprintf(__('Busiest: %1$s around %2$s. Times are in the site’s time zone; click a cell to filter.', 'nemvo-analytics'), dayName(best[0], true), hourName(best[1])) });

			if (panel.table) {
				put(shell.body, 
					insight,
					h(
						'div',
						{ class: 'nva-table-wrap' },
						h(
							'table',
							{ class: 'nva-table nva-table--dense' },
							h('thead', null, h('tr', null, h('th', { scope: 'col', text: '' }), range(0, 24).map((hr) => h('th', { scope: 'col', class: 'nva-num', text: hourName(hr) })))),
							h('tbody', null, order.map((js) => h('tr', null, h('th', { scope: 'row', text: dayName(js, false) }), range(0, 24).map((hr) => h('td', { class: 'nva-num', text: fmt.full(value(js, hr)) })))))
						)
					)
				);
				put(shell.foot);
				return;
			}

			const box = h('div', { class: 'nva-canvas nva-canvas--heatmap' });
			put(shell.body, insight, box);
			const t = tokens();
			const top = Math.max(...panel.data.flat()) || 1;
			const bin = (v) => (v > 0 ? Math.min(6, 1 + Math.floor((v / top) * 6 - 1e-9)) : 0);
			const cells = [];
			order.forEach((js, row) => range(0, 24).forEach((hr) => cells.push({ x: hr, y: row, js, v: value(js, hr) })));
			mountChart(box, __('Visits by weekday and hour. A table view is available.', 'nemvo-analytics'), {
				type: 'matrix',
				data: {
					datasets: [
						{
							data: cells,
							backgroundColor: (c) => (c.raw ? t.seq[bin(c.raw.v)] : t.seq[0]),
							borderWidth: 0,
							borderRadius: 3,
							hoverBorderColor: t.ink,
							hoverBorderWidth: 1.5,
							width: ({ chart }) => Math.max(1, (chart.chartArea || {}).width / 24 - 2),
							height: ({ chart }) => Math.max(1, (chart.chartArea || {}).height / 7 - 2),
						},
					],
				},
				options: {
					animation: false,
					layout: { padding: { right: 2 } },
					scales: {
						x: { type: 'linear', min: -0.5, max: 23.5, position: 'top', offset: false, grid: { display: false }, border: { display: false }, ticks: { color: t.muted, stepSize: 1, autoSkip: false, maxRotation: 0, callback: (v) => (Number.isInteger(v) && v % 3 === 0 ? hourName(v) : '') } },
						y: { type: 'linear', min: -0.5, max: 6.5, reverse: true, offset: false, grid: { display: false }, border: { display: false }, ticks: { color: t.muted, stepSize: 1, autoSkip: false, callback: (v) => (Number.isInteger(v) && order[v] !== undefined ? dayName(order[v], false) : '') } },
					},
					plugins: {
						tooltip: {
							mode: 'nearest',
							intersect: true,
							external: externalTip((points) => {
								const c = points[0].raw;
								/* translators: %s: share of all visits */
								return [tipTitle(dayName(c.js, true) + ', ' + hourRange(c.x)), tipRow(null, fmt.full(c.v), _n('visit', 'visits', c.v, 'nemvo-analytics')), tipHint(sprintf(/* translators: %s: share of all visits */ __('%s of all visits · click to filter', 'nemvo-analytics'), fmt.percent(c.v / total)))];
							}),
						},
					},
					onClick: (e, els, chart) => {
						if (els.length) {
							const c = chart.data.datasets[0].data[els[0].index];
							addFilter({ d: 'weekday', v: String((c.js + 6) % 7) }, { d: 'hour', v: String(c.x) });
						}
					},
					onHover: (e, els, chart) => {
						chart.canvas.style.cursor = els.length ? 'pointer' : 'default';
					},
				},
			});
			put(shell.foot, 
				h(
					'div',
					{ class: 'nva-scale' },
					h('span', { text: __('Fewer', 'nemvo-analytics') }),
					h('span', { class: 'nva-scale__ramp', 'aria-hidden': 'true' }, range(1, 7).map((i) => h('span', { class: 'nva-swatch nva-seq-' + i }))),
					h('span', { text: __('More visits', 'nemvo-analytics') })
				)
			);
		};
		redraws.add(draw);

		panel.load = () => {
			const gen = ++panel.gen;
			shell.el.classList.add('is-loading');
			report('heatmap', params())
				.then((data) => {
					if (gen === panel.gen) {
						panel.data = data;
						draw();
					}
				})
				.catch((err) => gen === panel.gen && put(shell.body, errorNote(err)))
				.finally(() => gen === panel.gen && shell.el.classList.remove('is-loading'));
		};
		return panel;
	}

	/* ---------------- Visit log ---------------- */

	function botBadge(score) {
		return score >= cfg.botThreshold ? h('span', { class: 'nva-pill is-bot', title: sprintf(/* translators: %s: bot score from 0 to 100 */ __('Bot score %s', 'nemvo-analytics'), score) }, icon('robot'), __('Automated', 'nemvo-analytics')) : null;
	}

	function networkCell(r) {
		if (r.company) {
			return [h('strong', { class: 'nva-ellipsis', text: r.company }), h('span', { class: 'nva-table__sub', text: NETWORKS[r.network] || r.org })];
		}
		return [h('span', { class: 'nva-ellipsis', text: NETWORKS[r.network] || UNKNOWN }), r.org ? h('span', { class: 'nva-table__sub', text: r.org }) : null];
	}

	function visitsPanel() {
		const panel = { gen: 0, rows: [], total: 0 };
		const shell = panelShell('visits', __('Visit log', 'nemvo-analytics'), null);
		shell.el.classList.add('nva-panel--wide');
		panel.el = shell.el;
		const tbody = h('tbody');
		const more = h('button', { type: 'button', class: 'button', text: __('Load more', 'nemvo-analytics'), onclick: () => fetchRows(true) });
		const status = h('span', { class: 'nva-muted' });
		put(shell.body, 
			h('p', { class: 'nva-panel__note', text: __('Individual visits, newest first. Click one to see its pages, time on each page, events and how it was classified.', 'nemvo-analytics') }),
			h(
				'div',
				{ class: 'nva-table-wrap nva-table-wrap--log' },
				h(
					'table',
					{ class: 'nva-table nva-table--log' },
					h(
						'thead',
						null,
						h(
							'tr',
							null,
							[__('Started', 'nemvo-analytics'), __('Location', 'nemvo-analytics'), __('Organisation', 'nemvo-analytics'), __('Source', 'nemvo-analytics'), __('Pages', 'nemvo-analytics'), __('Duration', 'nemvo-analytics'), __('Device', 'nemvo-analytics')].map((t, i) => h('th', { scope: 'col', class: 4 === i || 5 === i ? 'nva-num' : null, text: t }))
						)
					),
					tbody
				)
			)
		);
		put(shell.foot, status, more);

		const row = (r) => {
			const single = cur.from === cur.to;
			const started = parseDay(r.started_at);
			const place = r.city || countryName(r.country);
			const open = () => openSession(r.id);
			return h(
				'tr',
				{ class: 'is-clickable', tabindex: '0', onclick: open, onkeydown: (e) => 'Enter' === e.key && open() },
				h('td', { class: 'nva-nowrap' }, h('span', { text: (single ? '' : fDay.format(started) + ' ') + fTime.format(started) }), r.converted ? h('span', { class: 'nva-pill is-goal', title: __('Reached a goal', 'nemvo-analytics') }, icon('goal')) : null, botBadge(r.bot_score)),
				h('td', null, h('span', { class: 'nva-cell-flex' }, flag(r.country) ? h('span', { class: 'nva-flag', 'aria-hidden': 'true', text: flag(r.country) }) : null, h('span', { class: 'nva-ellipsis', text: place }))),
				h('td', { class: 'nva-table__label' }, networkCell(r)),
				h('td', { class: 'nva-table__label' }, h('span', { class: 'nva-ellipsis', text: CHANNELS[r.channel] || r.channel }), r.source && r.source !== r.channel ? h('span', { class: 'nva-table__sub', text: r.source }) : null),
				h('td', { class: 'nva-num' }, h('span', { text: fmt.full(r.pageviews) }), h('span', { class: 'nva-table__sub', title: r.entry_path, text: r.entry_path })),
				h('td', { class: 'nva-num', text: fmt.duration(r.duration) }),
				h('td', { class: 'nva-table__label' }, h('span', { text: DEVICES[r.device] || r.device }), h('span', { class: 'nva-table__sub', text: [r.os, r.browser].filter(Boolean).join(' · ') }))
			);
		};

		const fetchRows = (append) => {
			const gen = ++panel.gen;
			shell.el.classList.add('is-loading');
			report('sessions', params({ limit: 15, offset: append ? panel.rows.length : 0 }))
				.then((data) => {
					if (gen !== panel.gen) {
						return;
					}
					panel.rows = append ? panel.rows.concat(data.rows) : data.rows;
					panel.total = data.total;
					put(tbody, ...panel.rows.map(row));
					if (!panel.rows.length) {
						put(tbody, h('tr', null, h('td', { colspan: '7' }, emptyNote())));
					}
					/* translators: 1: visits shown, 2: total visits */
					status.textContent = panel.rows.length ? sprintf(__('Showing %1$s of %2$s visits', 'nemvo-analytics'), fmt.full(panel.rows.length), fmt.full(panel.total)) : '';
					more.hidden = panel.rows.length >= panel.total;
				})
				.catch((err) => gen === panel.gen && put(tbody, h('tr', null, h('td', { colspan: '7' }, errorNote(err)))))
				.finally(() => gen === panel.gen && shell.el.classList.remove('is-loading'));
		};
		panel.load = () => fetchRows(false);
		return panel;
	}

	/* ---------------- One visit ---------------- */

	function openSession(id) {
		hideTip();
		const { body, titleEl, dlg } = dialog(__('Visit', 'nemvo-analytics'), true);
		body.classList.add('is-loading');
		report('session', { id }, true)
			.then((v) => {
				const s = v.session;
				const started = parseDay(s.started_at);
				titleEl.textContent = fWeekday.format(started) + ', ' + fTime.format(started);
				const filterTo = (filter, label) =>
					h(
						'button',
						{
							type: 'button',
							class: 'nva-link-btn',
							onclick: () => {
								dlg.close();
								addFilter(filter);
							},
						},
						icon('filter'),
						label
					);
				const facts = [
					[__('Duration', 'nemvo-analytics'), fmt.duration(s.duration)],
					[__('Pages', 'nemvo-analytics'), fmt.full(s.pageviews)],
					[__('Events', 'nemvo-analytics'), fmt.full(s.events)],
					[__('Source', 'nemvo-analytics'), [CHANNELS[s.channel] || s.channel, s.source !== s.channel ? s.source : '', s.utm_campaign].filter(Boolean).join(' · ')],
					[__('Location', 'nemvo-analytics'), [s.city, s.region, countryName(s.country)].filter(Boolean).join(', ')],
					[__('Organisation', 'nemvo-analytics'), [s.company, NETWORKS[s.network] || '', s.org !== s.company ? s.org : '', s.asn > 0 ? 'AS' + s.asn : ''].filter(Boolean).join(' · ') || UNKNOWN],
					[__('Device', 'nemvo-analytics'), [DEVICES[s.device] || s.device, s.os, s.browser, ((SCREENS[s.screen] || '').match(/\(([^)]+)\)/) || [])[1]].filter(Boolean).join(' · ')],
					[__('Language', 'nemvo-analytics'), display('language', (s.language || '').split('-')[0]).text],
				];
				const pageMax = Math.max(1, ...v.pages.map((p) => p.engaged_ms));
				const eventsByPv = {};
				v.events.forEach((e) => (eventsByPv[e.pv] = eventsByPv[e.pv] || []).push(e));
				const timeline = h(
					'ol',
					{ class: 'nva-timeline' },
					v.pages.map((p) =>
						h(
							'li',
							{ class: 'nva-timeline__item' + (404 === p.status ? ' is-404' : '') },
							h('time', { class: 'nva-timeline__time', text: fTimeSec.format(new Date(p.created_at.replace(' ', 'T') + 'Z')) }),
							h(
								'div',
								{ class: 'nva-timeline__main' },
								h('div', { class: 'nva-timeline__page' }, h('strong', { class: 'nva-ellipsis', text: p.path, title: p.title }), 404 === p.status ? h('span', { class: 'nva-pill', text: '404' }) : null, h('a', { class: 'nva-icon-btn nva-icon-btn--sm', href: siteUrl(p.path), target: '_blank', rel: 'noopener', 'aria-label': __('Open the page', 'nemvo-analytics') }, icon('external'))),
								p.title ? h('span', { class: 'nva-table__sub', text: p.title }) : null,
								h(
									'div',
									{ class: 'nva-timeline__bar', title: __('Time on page', 'nemvo-analytics') },
									h('span', { style: { width: ((p.engaged_ms / pageMax) * 100).toFixed(1) + '%' } }),
									/* translators: 1: time on page, 2: scroll depth */
									h('em', { text: sprintf(__('%1$s · scrolled %2$s', 'nemvo-analytics'), fmt.duration(p.engaged_ms / 1000), fmt.percent0(p.scroll / 100)) })
								),
								(eventsByPv[p.pv] || []).map((e) => h('div', { class: 'nva-timeline__event' }, icon('bolt'), h('strong', { text: e.name }), e.label ? h('span', { class: 'nva-ellipsis', text: e.label }) : null))
							)
						)
					)
				);
				const signals = v.signals.length
					? h(
							'ul',
							{ class: 'nva-signal-list' },
							v.signals.map((sig) => h('li', { class: sig.weight > 0 ? 'is-bad' : 'is-good' }, h('span', { text: sig.label }), h('strong', { text: (sig.weight > 0 ? '+' : '') + sig.weight })))
					  )
					: h('p', { class: 'nva-muted', text: __('No signals either way.', 'nemvo-analytics') });
				put(body, 
					h(
						'div',
						{ class: 'nva-visit' },
						h(
							'div',
							{ class: 'nva-visit__main' },
							h('dl', { class: 'nva-stats nva-stats--visit' }, facts.map(([dt, dd]) => h('div', null, h('dt', { text: dt }), h('dd', { text: dd || '–' })))),
							h('h3', { text: __('Pages and events', 'nemvo-analytics') }),
							timeline
						),
						h(
							'aside',
							{ class: 'nva-visit__side' },
							h('h3', { text: __('Classification', 'nemvo-analytics') }),
							h(
								'p',
								{ class: 'nva-score' },
								h('strong', { text: s.bot_score }),
								' / 100 · ',
								s.bot_score >= v.threshold ? __('automated', 'nemvo-analytics') : __('a person', 'nemvo-analytics')
							),
							signals,
							h('h3', { text: __('Drill down', 'nemvo-analytics') }),
							h(
								'div',
								{ class: 'nva-visit__actions' },
								s.company ? filterTo({ d: 'company', v: s.company }, sprintf(/* translators: %s: company name */ __('All visits from %s', 'nemvo-analytics'), s.company)) : null,
								filterTo({ d: 'visitor', v: s.visitor }, __('All visits by this visitor', 'nemvo-analytics')),
								s.org ? filterTo({ d: 'org', v: s.org }, sprintf(/* translators: %s: network owner, e.g. an internet provider */ __('All visits via %s', 'nemvo-analytics'), s.org)) : null,
								filterTo({ d: 'entry_page', v: s.entry_path }, __('Visits that started on the same page', 'nemvo-analytics'))
							),
							v.others.length
								? [
										h('h3', { text: __('Same visitor, other visits', 'nemvo-analytics') }),
										h(
											'ul',
											{ class: 'nva-others' },
											v.others.map((o) =>
												h(
													'li',
													null,
													h(
														'button',
														{
															type: 'button',
															class: 'nva-link-btn',
															onclick: () => {
																dlg.close();
																openSession(o.id);
															},
														},
														fWeekday.format(parseDay(o.started_at)) + ' ' + fTime.format(parseDay(o.started_at)) + ' · ' + sprintf(/* translators: %s: number of pages */ _n('%s page', '%s pages', Number(o.pageviews), 'nemvo-analytics'), o.pageviews)
													)
												)
											)
										),
										h('p', { class: 'nva-muted', text: __('Visitors are only recognised within one salt period (a day by default).', 'nemvo-analytics') }),
								  ]
								: null
						)
					)
				);
			})
			.catch((err) => put(body, errorNote(err)))
			.finally(() => body.classList.remove('is-loading'));
	}

	/* ---------------- Automated traffic ---------------- */

	function botsPanel() {
		const panel = { gen: 0 };
		const shell = panelShell('bots', __('Automated traffic', 'nemvo-analytics'), null);
		shell.el.classList.add('nva-panel--wide');
		panel.el = shell.el;
		let last = null;

		const draw = () => {
			if (!last) {
				return;
			}
			const [data, names, nets] = last;
			const all = data.humans + data.bots;
			const box = h('div', { class: 'nva-canvas nva-canvas--bots' });
			const show = h(
				'button',
				{ type: 'button', class: 'nva-btn', 'aria-pressed': String('bot' === state.traffic), onclick: () => navigate({ traffic: 'bot' === state.traffic ? 'human' : 'bot' }) },
				icon('robot'),
				'bot' === state.traffic ? __('Back to people', 'nemvo-analytics') : __('Show automated traffic on the dashboard', 'nemvo-analytics')
			);
			put(shell.tools, show);
			put(shell.body, 
				h(
					'div',
					{ class: 'nva-bots' },
					h(
						'div',
						{ class: 'nva-bots__summary' },
						h('dl', { class: 'nva-stats' }, h('div', null, h('dt', { text: __('People', 'nemvo-analytics') }), h('dd', { text: fmt.full(data.humans) })), h('div', null, h('dt', { text: __('Automated', 'nemvo-analytics') }), h('dd', { text: fmt.full(data.bots) }), h('small', { text: all ? sprintf(/* translators: %s: share of all visits */ __('%s of all visits', 'nemvo-analytics'), fmt.percent(data.bots / all)) : '' }))),
						h('p', { class: 'nva-panel__note', text: __('Automated visits per day. They are kept out of every other number on this dashboard.', 'nemvo-analytics') }),
						box
					),
					h(
						'div',
						{ class: 'nva-bots__lists' },
						h('h3', { text: __('Why', 'nemvo-analytics') }),
						rankList({
							rows: data.reasons.map((r) => Object.assign({ label: String(r.flag) }, r)),
							dim: 'bot_flag',
							head: __('Signal', 'nemvo-analytics'),
							cols: [col('visits')],
							bar: 'visits',
							empty: __('No automated visits in this period.', 'nemvo-analytics'),
							onRow: (r) => navigate({ traffic: 'bot', filters: state.filters.filter((f) => 'bot_flag' !== f.d).concat([{ d: 'bot_flag', o: 'is', v: String(r.flag) }]) }),
						})
					),
					h(
						'div',
						{ class: 'nva-bots__lists' },
						h('h3', { text: __('Who', 'nemvo-analytics') }),
						rankList({
							rows: names.rows,
							dim: 'browser',
							head: __('User agent', 'nemvo-analytics'),
							cols: [col('visits')],
							bar: 'visits',
							empty: __('Nothing yet.', 'nemvo-analytics'),
							onRow: (r) => navigate({ traffic: 'bot', filters: state.filters.filter((f) => 'browser' !== f.d).concat([{ d: 'browser', o: 'is', v: r.label }]) }),
						}),
						h('h3', { text: __('From', 'nemvo-analytics') }),
						rankList({
							rows: nets.rows,
							dim: 'org',
							head: __('Network owner', 'nemvo-analytics'),
							cols: [col('visits')],
							bar: 'visits',
							empty: __('Nothing yet.', 'nemvo-analytics'),
							onRow: (r) => navigate({ traffic: 'bot', filters: state.filters.filter((f) => 'org' !== f.d).concat([{ d: 'org', o: 'is', v: r.label }]) }),
						})
					)
				)
			);
			const t = tokens();
			const series = data.series;
			if (!series.length) {
				put(box, emptyNote(__('No automated visits in this period.', 'nemvo-analytics')));
				return;
			}
			const chart = mountChart(box, __('Automated visits per day', 'nemvo-analytics'), {
				type: 'bar',
				data: {
					labels: series.map((d) => fDay.format(parseDay(d.date))),
					datasets: [{ data: series.map((d) => d.bots), backgroundColor: t.c1, borderRadius: { topLeft: 4, topRight: 4 }, borderSkipped: 'bottom', maxBarThickness: 24, categoryPercentage: 0.9, barPercentage: 0.9 }],
				},
				options: {
					scales: {
						x: { grid: { display: false }, border: { color: t.axis }, ticks: { color: t.muted, maxRotation: 0, autoSkipPadding: 16 } },
						y: { beginAtZero: true, grid: { color: t.grid }, border: { display: false }, ticks: { color: t.muted, maxTicksLimit: 4, precision: 0 } },
					},
					plugins: {
						tooltip: {
							mode: 'index',
							intersect: false,
							external: externalTip((points) => {
								const d = series[points[0].dataIndex];
								return [tipTitle(fWeekday.format(parseDay(d.date))), tipRow(null, fmt.full(d.bots), __('automated', 'nemvo-analytics')), tipRow(null, fmt.full(d.humans), __('people', 'nemvo-analytics')), tipHint(__('Click to see that day', 'nemvo-analytics'))];
							}),
						},
					},
					onClick: (e, els, c) => {
						const pts = c.getElementsAtEventForMode(e, 'index', { intersect: false }, false);
						if (pts.length) {
							navigate({ range: 'custom', from: series[pts[0].index].date, to: series[pts[0].index].date, traffic: 'bot', interval: '' });
						}
					},
					onHover: (e, els, c) => {
						c.canvas.style.cursor = 'pointer';
					},
				},
			});
			keyboard(chart, () => series.length, null);
		};
		redraws.add(draw);

		panel.load = () => {
			const gen = ++panel.gen;
			shell.el.classList.add('is-loading');
			const base = params();
			delete base.traffic;
			Promise.all([report('bots', base), report('breakdown', Object.assign({}, base, { traffic: 'bot', dim: 'browser', limit: 6, sort: 'visits' })), report('breakdown', Object.assign({}, base, { traffic: 'bot', dim: 'org', limit: 6, sort: 'visits' }))])
				.then((res) => {
					if (gen === panel.gen) {
						last = res;
						draw();
					}
				})
				.catch((err) => gen === panel.gen && put(shell.body, errorNote(err)))
				.finally(() => gen === panel.gen && shell.el.classList.remove('is-loading'));
		};
		return panel;
	}

	/* ---------------- Page journey ---------------- */

	function statStrip(items) {
		return h(
			'dl',
			{ class: 'nva-stats' },
			items.map(([label, value]) => h('div', null, h('dt', { text: label }), h('dd', { text: value })))
		);
	}

	function openJourney(path) {
		hideTip();
		const { body, titleEl, dlg } = dialog(__('Page journey', 'nemvo-analytics'), true);
		const load = (p) => {
			body.classList.add('is-loading');
			report('journey', params({ path: p }))
				.then((j) => {
					titleEl.textContent = j.title || p;
					const special = {
						'(entrance)': __('Entered the site here', 'nemvo-analytics'),
						'(exit)': __('Left the site', 'nemvo-analytics'),
					};
					const column = (title, rows) =>
						h(
							'div',
							{ class: 'nva-journey__col' },
							h('h3', { text: title }),
							rankList({
								rows,
								head: __('Page', 'nemvo-analytics'),
								cols: [['n', __('Visits', 'nemvo-analytics'), (v) => fmt.full(v)]],
								bar: 'n',
								label: (r) => ({ text: special[r.label] || r.label }),
								clickable: (r) => !special[r.label],
								onRow: (r) => load(r.label),
								empty: __('No data', 'nemvo-analytics'),
							})
						);
					put(body, 
						h('p', { class: 'nva-journey__path' }, h('code', { text: p }), h('a', { class: 'nva-link-btn', href: siteUrl(p), target: '_blank', rel: 'noopener' }, __('Open the page', 'nemvo-analytics'), icon('external'))),
						statStrip([
							[__('Pageviews', 'nemvo-analytics'), fmt.full(j.summary.pageviews)],
							[__('Visitors', 'nemvo-analytics'), fmt.full(j.summary.visitors)],
							[__('Entrances', 'nemvo-analytics'), fmt.full(j.summary.entries)],
							[__('Exit rate', 'nemvo-analytics'), fmt.percent(j.summary.exit_rate)],
							[__('Time on page', 'nemvo-analytics'), fmt.duration(j.summary.avg_time)],
							[__('Scroll depth', 'nemvo-analytics'), j.summary.scroll ? fmt.percent0(j.summary.scroll / 100) : '–'],
						]),
						h('div', { class: 'nva-journey' }, column(__('Came from', 'nemvo-analytics'), j.prev), h('div', { class: 'nva-journey__node', 'aria-hidden': 'true' }, icon('right')), column(__('Went to', 'nemvo-analytics'), j.next)),
						h(
							'div',
							{ class: 'nva-dialog__actions' },
							h(
								'button',
								{
									type: 'button',
									class: 'button',
									onclick: () => {
										dlg.close();
										addFilter({ d: 'page', v: p });
									},
								},
								__('Filter the dashboard by this page', 'nemvo-analytics')
							)
						)
					);
				})
				.catch((err) => put(body, errorNote(err)))
				.finally(() => body.classList.remove('is-loading'));
		};
		load(path);
	}

	/* ---------------- Details table ---------------- */

	function openDetails(tab, eventName) {
		hideTip();
		const dim = eventName !== null && eventName !== undefined ? 'event_label' : tab.dim;
		const kind = 'goal' === dim ? 'goal' : 'event' === dim || 'event_label' === dim ? 'event' : ['page', 'exits', 'content_type'].includes(dim) ? 'page' : 'session';
		const columns = {
			session: ['visitors', 'visits', 'pageviews', 'bounce_rate', 'avg_duration', 'conversions', 'cr'],
			page: ['visitors', 'pageviews', 'avg_time', 'scroll', 'entries', 'exits', 'exit_rate'],
			event: ['events', 'visitors'],
			goal: ['conversions', 'visitors', 'cr'],
		}[kind];
		const ds = { sort: 'exits' === dim ? 'exits' : 'event' === kind ? 'events' : 'goal' === kind ? 'conversions' : 'visitors', dir: 'desc', q: '', rows: [], total: 0, gen: 0 };
		const title = eventName ? DIMS.event + ': ' + eventName : tab.label;
		const { body, dlg } = dialog(title, true);
		const filterDim = tab.filter || ('exits' === dim ? 'exit_page' : dim);

		const search = h('input', {
			type: 'search',
			class: 'nva-search',
			placeholder: __('Search', 'nemvo-analytics'),
			'aria-label': __('Search', 'nemvo-analytics'),
			oninput: () => {
				clearTimeout(ds.timer);
				ds.timer = setTimeout(() => {
					ds.q = search.value;
					fetchRows(false);
				}, 250);
			},
		});
		const thead = h('thead');
		const tbody = h('tbody');
		const status = h('span', { class: 'nva-muted' });
		const more = h('button', { type: 'button', class: 'button', text: __('Load more', 'nemvo-analytics'), onclick: () => fetchRows(true) });
		const csv = h('button', { type: 'button', class: 'button' }, icon('download'), __('Download CSV', 'nemvo-analytics'));
		csv.addEventListener('click', () => {
			const head = [DIMS[tab.filter || tab.dim] || tab.label].concat(columns.map((c) => COLS[c][0]));
			const lines = [head].concat(ds.rows.map((r) => [display(kind === 'page' ? 'page' : dim, r.label).text].concat(columns.map((c) => r[c]))));
			const text = lines.map((l) => l.map((v) => '"' + String(v === undefined ? '' : v).replace(/"/g, '""') + '"').join(',')).join('\n');
			const a = h('a', { href: URL.createObjectURL(new Blob([text], { type: 'text/csv' })), download: (tab.key || dim) + '-' + cur.from + '-' + cur.to + '.csv' });
			document.body.append(a);
			a.click();
			a.remove();
		});

		const drawHead = () => {
			const th = (key, label, num) => {
				const sorted = ds.sort === key;
				return h(
					'th',
					{ scope: 'col', class: num ? 'nva-num' : null, 'aria-sort': sorted ? ('asc' === ds.dir ? 'ascending' : 'descending') : null },
					'goal' === kind
						? label
						: h(
								'button',
								{
									type: 'button',
									class: 'nva-sort',
									onclick: () => {
										ds.dir = sorted && 'desc' === ds.dir ? 'asc' : 'desc';
										ds.sort = key;
										fetchRows(false);
									},
								},
								label,
								sorted ? icon('asc' === ds.dir ? 'up' : 'dn') : null
						  )
				);
			};
			put(thead, h('tr', null, th('label', DIMS[tab.filter || tab.dim] || tab.label, false), columns.map((c) => th(c, COLS[c][0], true))));
		};

		const drawRows = () => {
			put(tbody, 
				...ds.rows.map((r) => {
					const info = 'page' === kind || 'goal' === kind ? { text: r.label } : 'event' === kind ? { text: r.label || __('(none)', 'nemvo-analytics') } : display(dim, r.label);
					const pick = () => {
						dlg.close();
						if ('goal' === kind) {
							addFilter({ d: 'goal', v: String(r.goal) });
						} else if ('event_label' === dim) {
							addFilter({ d: 'event', v: eventName });
						} else {
							addFilter({ d: filterDim, v: r.label });
						}
					};
					return h(
						'tr',
						null,
						h(
							'td',
							{ class: 'nva-table__label' },
							h('button', { type: 'button', class: 'nva-row__label', title: r.title || r.meta || info.title || info.text, onclick: pick }, labelNode(info)),
							'page' === kind && r.title ? h('span', { class: 'nva-table__sub', text: r.title }) : null,
							'event' === kind && r.meta ? h('span', { class: 'nva-table__sub', text: r.meta }) : null
						),
						columns.map((c) => h('td', { class: 'nva-num', text: COLS[c][1](r[c], r) }))
					);
				})
			);
			/* translators: 1: rows shown, 2: total rows */
			status.textContent = ds.rows.length ? sprintf(__('Showing %1$s of %2$s', 'nemvo-analytics'), fmt.full(ds.rows.length), fmt.full(ds.total)) : __('Nothing found.', 'nemvo-analytics');
			more.hidden = ds.rows.length >= ds.total;
		};

		const fetchRows = (append) => {
			const gen = ++ds.gen;
			body.classList.add('is-loading');
			const query = params({ dim, limit: 50, offset: append ? ds.rows.length : 0, q: ds.q, sort: ds.sort, dir: ds.dir });
			if (eventName) {
				query.event = eventName;
			}
			report('breakdown', query)
				.then((data) => {
					if (gen !== ds.gen) {
						return;
					}
					ds.rows = append ? ds.rows.concat(data.rows) : data.rows;
					ds.total = data.total;
					drawHead();
					drawRows();
				})
				.catch((err) => put(tbody, h('tr', null, h('td', { colspan: columns.length + 1 }, errorNote(err)))))
				.finally(() => gen === ds.gen && body.classList.remove('is-loading'));
		};

		body.append(
			h('div', { class: 'nva-dialog__bar' }, 'goal' === kind ? null : search, h('span', { class: 'nva-muted', text: rangeText(cur.from, cur.to) + ' · ' + TRAFFIC[state.traffic] })),
			h('div', { class: 'nva-table-wrap' }, h('table', { class: 'nva-table nva-table--details' }, thead, tbody)),
			h('div', { class: 'nva-dialog__actions' }, status, more, csv)
		);
		fetchRows(false);
	}

	/* ================================================================== *
	 * Realtime view
	 * ================================================================== */

	function realtimeView() {
		const hero = h('strong', { class: 'nva-hero__value', text: '–' });
		const updated = h('span', { class: 'nva-muted' });
		const columns = h('div', { class: 'nva-canvas nva-canvas--rt' });
		const lists = {
			pages: panelShell('rt-pages', __('Active pages', 'nemvo-analytics'), null),
			sources: panelShell('rt-sources', __('Sources', 'nemvo-analytics'), null),
			countries: panelShell('rt-countries', __('Countries', 'nemvo-analytics'), null),
			companies: panelShell('rt-companies', __('Organisations', 'nemvo-analytics'), null),
		};
		const feed = panelShell('rt-feed', __('Latest activity', 'nemvo-analytics'), null);
		feed.el.classList.add('nva-panel--wide');
		let last = null;
		let chart = null;

		put(app, 
			header(__('Realtime', 'nemvo-analytics')),
			h(
				'section',
				{ class: 'nva-card nva-hero' },
				h(
					'div',
					{ class: 'nva-hero__figure' },
					h('span', { class: 'nva-hero__label' }, h('span', { class: 'nva-live__dot', 'aria-hidden': 'true' }), __('People on the site right now', 'nemvo-analytics')),
					hero,
					h('span', { class: 'nva-muted', text: __('Active in the last 5 minutes. Automated traffic is left out.', 'nemvo-analytics') }),
					updated
				),
				h('div', { class: 'nva-hero__chart' }, h('h2', { class: 'nva-hero__chart-title', text: __('Pageviews per minute, last 30 minutes', 'nemvo-analytics') }), columns)
			),
			h('div', { class: 'nva-grid nva-grid--4' }, Object.values(lists).map((l) => l.el)),
			feed.el,
			footer()
		);

		const minuteLabel = (key) => fTime.format(parseDay(key));

		const drawColumns = (force) => {
			if (!last) {
				return;
			}
			const data = last.minutes;
			if (chart && !force) {
				chart.data.labels = data.map((d) => minuteLabel(d.minute));
				chart.data.datasets[0].data = data.map((d) => d.pageviews);
				chart.update('none');
				return;
			}
			const t = tokens();
			chart = mountChart(columns, __('Pageviews per minute', 'nemvo-analytics'), {
				type: 'bar',
				data: {
					labels: data.map((d) => minuteLabel(d.minute)),
					datasets: [{ data: data.map((d) => d.pageviews), backgroundColor: t.c1, borderRadius: { topLeft: 4, topRight: 4 }, borderSkipped: 'bottom', maxBarThickness: 24, categoryPercentage: 0.9, barPercentage: 0.9 }],
				},
				options: {
					scales: {
						x: { grid: { display: false }, border: { color: t.axis }, ticks: { color: t.muted, maxRotation: 0, autoSkip: false, callback: (v, i) => (0 === i ? __('30 min ago', 'nemvo-analytics') : 15 === i ? __('15 min ago', 'nemvo-analytics') : 29 === i ? __('now', 'nemvo-analytics') : '') } },
						y: { beginAtZero: true, suggestedMax: 4, grid: { color: t.grid }, border: { display: false }, ticks: { color: t.muted, maxTicksLimit: 4, precision: 0 } },
					},
					plugins: {
						tooltip: {
							mode: 'index',
							intersect: false,
							external: externalTip((points) => {
								const d = last.minutes[points[0].dataIndex];
								return [tipTitle(minuteLabel(d.minute)), tipRow(null, fmt.full(d.pageviews), _n('pageview', 'pageviews', d.pageviews, 'nemvo-analytics')), tipRow(null, fmt.full(d.visitors), _n('visitor', 'visitors', d.visitors, 'nemvo-analytics'))];
							}),
						},
					},
				},
			});
			keyboard(chart, () => last.minutes.length, null);
		};
		redraws.add(() => drawColumns(true));

		const ago = (at) => {
			const secs = Math.max(0, Math.round((parseSeconds(last.now) - parseSeconds(at)) / 1000));
			if (secs < 60) {
				return __('just now', 'nemvo-analytics');
			}
			/* translators: %s: minutes */
			return sprintf(__('%s min ago', 'nemvo-analytics'), Math.floor(secs / 60));
		};

		const render = () => {
			hero.textContent = fmt.full(last.active);
			setLive(last.active);
			/* translators: %s: time of the last update */
			updated.textContent = sprintf(__('Updated %s', 'nemvo-analytics'), fTimeSec.format(new Date(last.now.replace(' ', 'T') + 'Z')));
			drawColumns(false);
			const simple = (shell, rows, dim, label) => {
				put(shell.body, 
					rankList({
						rows: rows.map((r) => Object.assign({}, r, { visitors: Number(r.visitors) })),
						dim,
						head: DIMS[dim] || __('Page', 'nemvo-analytics'),
						cols: [col('visitors')],
						bar: 'visitors',
						empty: __('Nobody right now.', 'nemvo-analytics'),
						label,
					})
				);
			};
			simple(lists.pages, last.pages, 'page', (r) => ({ text: r.label }));
			simple(lists.sources, last.sources, 'source');
			simple(lists.countries, last.countries, 'country');
			simple(lists.companies, last.companies, 'company', (r) => ({ text: NETWORKS[r.label] || r.label || UNKNOWN }));

			put(feed.body, 
				last.feed.length
					? h(
							'ol',
							{ class: 'nva-feed' },
							last.feed.map((f) => {
								const isEvent = 'event' === f.kind;
								const place = [f.city, countryName(f.country)].filter(Boolean).join(', ');
								const open = () => openSession(f.session_id);
								return h(
									'li',
									{ class: 'nva-feed__item is-clickable' + (isEvent ? ' is-event' : ''), tabindex: '0', onclick: open, onkeydown: (e) => 'Enter' === e.key && open() },
									h('span', { class: 'nva-feed__icon' }, icon(isEvent ? 'bolt' : 'eye')),
									h(
										'div',
										{ class: 'nva-feed__main' },
										isEvent ? h('span', null, h('strong', { text: f.name }), f.label ? ' · ' + f.label : '') : h('span', { title: f.title }, h('strong', { text: f.path }), f.title ? h('span', { class: 'nva-muted', text: ' ' + f.title }) : null),
										h('span', { class: 'nva-feed__meta' }, flag(f.country) ? h('span', { class: 'nva-flag', 'aria-hidden': 'true', text: flag(f.country) }) : null, [place, f.company || NETWORKS[f.network] || '', DEVICES[f.device] || f.device, f.browser, isEvent ? f.path : CHANNELS[f.channel] || f.source].filter(Boolean).join(' · '))
									),
									h('time', { class: 'nva-feed__time', datetime: f.at.replace(' ', 'T'), title: f.at, text: ago(f.at) })
								);
							})
					  )
					: emptyNote(__('No activity in the last 30 minutes.', 'nemvo-analytics'))
			);
		};

		const tick = () => {
			if (document.hidden) {
				return;
			}
			report('realtime', {}, true)
				.then((data) => {
					last = data;
					render();
				})
				.catch((err) => put(updated, errorNote(err)))
				.finally(() => app.setAttribute('aria-busy', 'false'));
		};
		tick();
		setInterval(tick, 10000);
		document.addEventListener('visibilitychange', tick);
	}

	function parseSeconds(s) {
		return new Date(s.replace(' ', 'T') + 'Z').getTime();
	}

	/* ================================================================== *
	 * Boot
	 * ================================================================== */

	applyTheme();
	if (!cfg.db.ok) {
		put(app, 
			header(__('Analytics', 'nemvo-analytics')),
			h(
				'section',
				{ class: 'nva-card nva-fatal' },
				h('h2', { text: __('The statistics database is not reachable', 'nemvo-analytics') }),
				h('p', { text: cfg.db.error }),
				cfg.canManage ? h('a', { class: 'button button-primary', href: cfg.settingsUrl + '#database', text: __('Check the database settings', 'nemvo-analytics') }) : null
			)
		);
		app.setAttribute('aria-busy', 'false');
		return;
	}
	if ('realtime' === cfg.view) {
		realtimeView();
	} else {
		dashboardView();
	}
})(window.Chart, window.ChartGeo, window.wp);
